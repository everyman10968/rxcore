<?php
/**
 * RxCore Sync Agent — Real Production Service
 *
 * จัดการการทำงานจริง (Production Mode):
 * 1. ดึงข้อมูลแบบ Read-only (SELECT เท่านั้น) จาก HOSxP / HOSxP_PCU MySQL จริง
 * 2. กรองเฉพาะรหัสยาในทะเบียนกลาง (drug_registry_production.json / rx_drug_lookup)
 * 3. บังคับใช้ Whitelist 6 ฟิลด์ (hos_guid, vn, hcode, vstdate, tpu_code_list, qty) ปราศจาก PII และต้นทุนยา
 * 4. ส่งข้อมูลขึ้น Supabase Cloud ผ่าน RPC fn_sync_dispense_batch (HTTPS + TLS Verification)
 * 5. บันทึกประวัติการซิงค์จริงลง config/sync_history.json
 */

require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_connection.php';
require_once __DIR__ . '/agent_hosxp_reader.php';
require_once __DIR__ . '/agent_drug_registry.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_api_client.php';

class AgentProductionService {

    private AgentConfig $configManager;
    private string $historyFile;

    public function __construct(?AgentConfig $configManager = null, ?string $historyFile = null) {
        $this->configManager = $configManager ?? new AgentConfig();
        $this->historyFile   = $historyFile ?? (__DIR__ . '/config/sync_history.json');
    }

    /**
     * อ่านค่าเริ่มต้นของ Supabase URL / Anon Key จากไฟล์ .env หลัก (กรณีรันบนเครื่องแม่ข่าย)
     */
    public function readParentEnvDefaults(): array {
        $envPath = dirname(__DIR__) . '/.env';
        $result = [
            'supabase_url'      => '',
            'supabase_anon_key' => '',
            'hospital_name'     => '',
            'moph_client_key'   => '',
            'moph_secret_key'   => '',
            'has_parent_env'    => false,
        ];
        if (!file_exists($envPath)) {
            return $result;
        }
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return $result;
        }
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $trimmed, 2);
            $key = trim($k);
            $val = trim($v, " \t\n\r\0\x0B\"'");
            if ($key === 'SUPABASE_URL') {
                $result['supabase_url'] = rtrim($val, '/');
            } elseif ($key === 'SUPABASE_ANON_KEY') {
                $result['supabase_anon_key'] = $val;
            } elseif ($key === 'HOSPITAL_NAME') {
                $result['hospital_name'] = $val;
            } elseif ($key === 'MOPH_CLIENT_KEY') {
                $result['moph_client_key'] = $val;
            } elseif ($key === 'MOPH_SECRET_KEY') {
                $result['moph_secret_key'] = $val;
            }
        }
        $result['has_parent_env'] = ($result['supabase_url'] !== '' && $result['supabase_anon_key'] !== '');
        return $result;
    }

    /**
     * รวมค่า Config ปัจจุบันเข้ากับค่า Fallback จาก .env (หากยังไม่ได้ตั้งค่าแยก)
     */
    public function resolveEffectiveConfig(?array $override = null): array {
        $cfg = $this->configManager->load();
        if (is_array($override)) {
            foreach ($override as $k => $v) {
                if ($v !== null && $v !== '') {
                    $cfg[$k] = $v;
                }
            }
        }
        $envDefaults = $this->readParentEnvDefaults();
        if (empty($cfg['supabase_url']) && $envDefaults['supabase_url'] !== '') {
            $cfg['supabase_url'] = $envDefaults['supabase_url'];
        }
        if (empty($cfg['supabase_anon_key']) && $envDefaults['supabase_anon_key'] !== '') {
            $cfg['supabase_anon_key'] = $envDefaults['supabase_anon_key'];
        }
        if (empty($cfg['moph_client_key']) && $envDefaults['moph_client_key'] !== '') {
            $cfg['moph_client_key'] = $envDefaults['moph_client_key'];
        }
        if (empty($cfg['moph_secret_key']) && $envDefaults['moph_secret_key'] !== '') {
            $cfg['moph_secret_key'] = $envDefaults['moph_secret_key'];
        }
        $cfg['supabase_url'] = rtrim(trim((string)($cfg['supabase_url'] ?? '')), '/');
        return $cfg;
    }

    /**
     * โหลดข้อมูลเริ่มต้นทั้งหมดสำหรับหน้า UI ใช้งานจริง
     */
    public function getBootstrapData(): array {
        $safe = $this->configManager->getSafeConfig();
        $envDefaults = $this->readParentEnvDefaults();

        if (empty($safe['supabase_url']) && $envDefaults['supabase_url'] !== '') {
            $safe['supabase_url'] = $envDefaults['supabase_url'];
        }
        $safe['has_parent_env']    = $envDefaults['has_parent_env'];
        $safe['has_effective_anon'] = $safe['has_saved_anon_key'] || ($envDefaults['supabase_anon_key'] !== '');
        $safe['hospital_name']     = $envDefaults['hospital_name'] ?: 'ระบบคลังข้อมูลยา รพ.แม่ข่าย - รพ.สต.';

        $drugs = AgentDrugRegistry::loadProductionDrugs();
        $groups = [];
        foreach ($drugs as $d) {
            $g = trim((string)($d['drug_type_name'] ?? ''));
            if ($g !== '') {
                $groups[$g] = ($groups[$g] ?? 0) + 1;
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        // ดึงรายชื่อหน่วยบริการจริงจาก Supabase (เฉพาะกรณีรันบนเครื่องแม่ข่ายที่มี includes/supabase.php)
        $healthCenters = [];
        $supabaseInclude = dirname(__DIR__) . '/includes/supabase.php';
        $appConfigInclude = dirname(__DIR__) . '/config/app.php';
        if (file_exists($appConfigInclude) && file_exists($supabaseInclude)) {
            require_once $appConfigInclude;
            require_once $supabaseInclude;
            if (class_exists('SupabaseService') && SupabaseService::isConfigured()) {
                $hcRes = SupabaseService::request(
                    'GET',
                    'rx_health_centers?select=hcode,name,subdistrict,district,is_active,last_sync_at&is_active=eq.true&order=hcode.asc&limit=200'
                );
                if (!empty($hcRes['ok']) && is_array($hcRes['data'])) {
                    $healthCenters = $hcRes['data'];
                }
            }
        }

        return [
            'config'         => $safe,
            'drugs'          => $drugs,
            'drug_count'     => count($drugs),
            'drug_groups'    => $groups,
            'health_centers' => $healthCenters,
            'history'        => $this->getSyncHistory(30),
        ];
    }

    /**
     * ทดสอบการเชื่อมต่อ HOSxP MySQL จริง + ตรวจ Schema (รองรับทุกบัญชีผู้ใช้ เช่น sa, root, rxcore_ro)
     */
    public function testHosxpConnection(array $config): array {
        $config['agent_mode'] = 'real';
        $reader = new AgentHosxpReader($config);

        $testA = $reader->testConnection(true);
        if (!$testA['success']) {
            return [
                'success' => false,
                'error'   => $testA['error'] ?? 'ไม่สามารถเชื่อมต่อฐานข้อมูล HOSxP ได้',
                'test_a'  => $testA,
            ];
        }

        $testB = $reader->validateSchema();
        $allPassed = $testA['success'] && $testB['success'];

        $errorMsg = '';
        if (!$testB['success']) {
            $errorMsg = $testB['message'] ?? 'โครงสร้างตาราง HOSxP ไม่ครบถ้วน';
        }

        $username = trim((string)($config['hosxp_username'] ?? ''));
        return [
            'success'        => $allPassed,
            'server_version' => $testA['server_version'] ?? '',
            'database'       => $testA['database'] ?? ($config['hosxp_database'] ?? ''),
            'latency_ms'     => $testA['connection_time_ms'] ?? 0,
            'is_readonly'    => true,
            'schema_ok'      => !empty($testB['success']),
            'message'        => $allPassed
                ? sprintf('เชื่อมต่อ %s สำเร็จ (ผู้ใช้: %s • %.1f ms)', $testA['database'] ?? 'HOSxP', $username !== '' ? $username : '-', $testA['connection_time_ms'] ?? 0)
                : $errorMsg,
            'error'          => $errorMsg,
            'test_a'         => $testA,
            'test_b'         => $testB,
        ];
    }

    /**
     * ทดสอบการเชื่อมต่อ Supabase Cloud และตรวจสอบ Sync API Key ของหน่วยบริการ
     */
    public function testCloudConnection(array $config): array {
        $supabaseUrl = rtrim(trim((string)($config['supabase_url'] ?? '')), '/');
        $anonKey     = trim((string)($config['supabase_anon_key'] ?? ''));
        $hcode       = trim((string)($config['hcode'] ?? ''));
        $syncApiKey  = trim((string)($config['sync_api_key'] ?? ''));

        if ($supabaseUrl === '' || !str_starts_with(strtolower($supabaseUrl), 'https://')) {
            return [
                'success' => false,
                'error'   => 'กรุณาระบุ Supabase Project URL ที่ขึ้นต้นด้วย https://',
            ];
        }
        if ($anonKey === '') {
            return [
                'success' => false,
                'error'   => 'กรุณาระบุ Supabase Anon Key',
            ];
        }

        // ทดสอบยืนยัน Sync API Key ผ่าน RPC fn_verify_health_center_api_key (ไม่มีผลข้างเคียงต่อข้อมูล)
        $rpcUrl = $supabaseUrl . '/rest/v1/rpc/fn_verify_health_center_api_key';
        $checkHcode = preg_match('/^\d{5}$/', $hcode) ? $hcode : '00000';
        $checkKey   = $syncApiKey !== '' ? $syncApiKey : '__ping_only__';

        $res = $this->postSupabaseJson($rpcUrl, $anonKey, [
            'p_hcode'         => $checkHcode,
            'p_plain_api_key' => $checkKey,
        ]);

        if (!$res['transport_ok']) {
            return [
                'success'   => false,
                'http_code' => $res['http_code'],
                'error'     => $res['error'] ?? 'ไม่สามารถเชื่อมต่อ Supabase Cloud ได้',
            ];
        }

        if ($res['http_code'] === 401 || $res['http_code'] === 403) {
            return [
                'success'   => false,
                'http_code' => $res['http_code'],
                'error'     => 'Supabase Anon Key ไม่ถูกต้อง หรือถูกปฏิเสธสิทธิ์ (HTTP ' . $res['http_code'] . ')',
            ];
        }

        if ($res['http_code'] >= 400) {
            $errDetail = is_array($res['body']) ? ($res['body']['message'] ?? json_encode($res['body'])) : (string)$res['body'];
            return [
                'success'   => false,
                'http_code' => $res['http_code'],
                'error'     => 'Supabase ตอบกลับข้อผิดพลาด: ' . $errDetail,
            ];
        }

        $keyVerified = ($res['body'] === true);

        if ($syncApiKey === '' || !preg_match('/^\d{5}$/', $hcode)) {
            return [
                'success'         => true,
                'cloud_reachable' => true,
                'key_verified'    => false,
                'message'         => 'เชื่อมต่อ Supabase Cloud สำเร็จ (กรุณาระบุรหัสหน่วยบริการ 5 หลัก และ Sync API Key ก่อนส่งข้อมูลจริง)',
            ];
        }

        if (!$keyVerified) {
            return [
                'success'         => false,
                'cloud_reachable' => true,
                'key_verified'    => false,
                'error'           => "เชื่อมต่อ Supabase ได้ แต่ Sync API Key ของหน่วยบริการ {$hcode} ไม่ถูกต้อง หรือยังไม่ได้ลงทะเบียนในหน้าเว็บหลัก",
            ];
        }

        return [
            'success'         => true,
            'cloud_reachable' => true,
            'key_verified'    => true,
            'message'         => "เชื่อมต่อ Supabase Cloud และยืนยันสิทธิ์หน่วยบริการ {$hcode} สำเร็จ พร้อมซิงค์ข้อมูลจริง",
        ];
    }

    /**
     * อัปเดตทะเบียนยาจริงจากเซิร์ฟเวอร์กลาง (ไม่ดึงข้อมูลต้นทุนยา)
     */
    public function refreshDrugRegistry(array $config): array {
        $supabaseInclude  = dirname(__DIR__) . '/includes/supabase.php';
        $appConfigInclude = dirname(__DIR__) . '/config/app.php';

        // กรณีที่ 1: รันอยู่บนเซิร์ฟเวอร์แม่ข่ายเดียวกัน
        if (file_exists($appConfigInclude) && file_exists($supabaseInclude)) {
            require_once $appConfigInclude;
            require_once $supabaseInclude;
            if (class_exists('SupabaseService') && SupabaseService::isConfigured()) {
                $res = SupabaseService::request(
                    'GET',
                    'rx_drug_lookup?select=icode,tpu_code_list,drug_name,drug_type_name,unit,is_active&is_active=eq.true&order=icode.asc.nullslast&limit=2000'
                );
                if (!empty($res['ok']) && is_array($res['data'])) {
                    AgentDrugRegistry::saveProductionDrugs($res['data']);
                    $loaded = AgentDrugRegistry::loadProductionDrugs();
                    return [
                        'success' => true,
                        'count'   => count($loaded),
                        'drugs'   => $loaded,
                        'message' => 'อัปเดตทะเบียนยาจากฐานข้อมูลกลางสำเร็จ (' . count($loaded) . ' รายการ)',
                    ];
                }
            }
        }

        // กรณีที่ 2: รันแยกเครื่องที่ รพ.สต. เรียกผ่าน Central Web URL + Sync API Key
        $centralUrl = rtrim(trim((string)($config['central_web_url'] ?? '')), '/');
        $hcode      = trim((string)($config['hcode'] ?? ''));
        $syncApiKey = trim((string)($config['sync_api_key'] ?? ''));

        if ($centralUrl !== '' && preg_match('/^\d{5}$/', $hcode) && $syncApiKey !== '') {
            $endpoint = $centralUrl . '/api/index.php?action=drug_registry';
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['hcode' => $hcode, 'api_key' => $syncApiKey]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => str_starts_with(strtolower($endpoint), 'https://'),
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if ($code === 200 && is_array($decoded) && !empty($decoded['success']) && is_array($decoded['drugs'] ?? null)) {
                AgentDrugRegistry::saveProductionDrugs($decoded['drugs']);
                $loaded = AgentDrugRegistry::loadProductionDrugs();
                return [
                    'success' => true,
                    'count'   => count($loaded),
                    'drugs'   => $loaded,
                    'message' => 'อัปเดตทะเบียนยาจากเซิร์ฟเวอร์แม่ข่ายสำเร็จ (' . count($loaded) . ' รายการ)',
                ];
            }
        }

        $loaded = AgentDrugRegistry::loadProductionDrugs();
        return [
            'success' => !empty($loaded),
            'count'   => count($loaded),
            'drugs'   => $loaded,
            'message' => !empty($loaded)
                ? 'ใช้ทะเบียนยามาตรฐานในเครื่อง (' . count($loaded) . ' รายการ)'
                : 'ไม่พบทะเบียนยา กรุณาตรวจสอบไฟล์ drug_registry_production.json',
        ];
    }

    /**
     * ดึงข้อมูลการจ่ายยาจาก HOSxP จริงแบบ Read-only และตรวจสอบความถูกต้อง (ไม่ส่งออก PII)
     */
    public function extractRealRecords(array $config, string $startDate, string $endDate): array {
        $targetHcode = trim((string)($config['hcode'] ?? ''));
        if (!preg_match('/^\d{5}$/', $targetHcode)) {
            return [
                'success' => false,
                'error'   => 'กรุณาระบุรหัสหน่วยบริการ 5 หลัก (HCODE) ในแท็บตั้งค่าการเชื่อมต่อก่อนดึงข้อมูล',
            ];
        }

        // 1. ตรวจสอบการเชื่อมต่อและสิทธิ์ Read-only ก่อนทุกครั้ง
        $diag = $this->testHosxpConnection($config);
        if (!$diag['success']) {
            return [
                'success' => false,
                'error'   => $diag['error'] ?: 'การตรวจสอบฐานข้อมูล HOSxP ไม่ผ่าน',
            ];
        }

        // 2. โหลดทะเบียนยาจริง (112 รายการ)
        $prodDrugs = AgentDrugRegistry::activateProductionRegistry();
        if (empty($prodDrugs)) {
            return [
                'success' => false,
                'error'   => 'ไม่พบรายการยาในทะเบียนกลางสำหรับกรองข้อมูล',
            ];
        }

        $drugLookupMap = [];
        foreach ($prodDrugs as $d) {
            $drugLookupMap[$d['tpu_code_list']] = $d;
        }

        // 3. เปิด Read-only PDO และดึงเฉพาะ 6 ฟิลด์
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                trim((string)$config['hosxp_host']),
                (int)($config['hosxp_port'] ?? 3306),
                trim((string)$config['hosxp_database'])
            );
            $pdo = new PDO($dsn, trim((string)$config['hosxp_username']), (string)($config['hosxp_password'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 15,
            ]);
            $pdo->exec('SET NAMES utf8mb4');
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => 'เชื่อมต่อฐานข้อมูล HOSxP ไม่สำเร็จ กรุณาตรวจสอบค่าตั้งค่า',
            ];
        }

        $extractor = new AgentDataExtractor($pdo, AgentDrugRegistry::getRegisteredDrugCodes());
        $extracted = $extractor->extract($startDate, $endDate);

        if (!$extracted['success']) {
            return [
                'success' => false,
                'error'   => $extracted['error'] ?? 'ดึงข้อมูลจาก HOSxP ไม่สำเร็จ',
            ];
        }

        // กรองเฉพาะ hcode ของหน่วยบริการตนเองเท่านั้น (Multi-tenant Isolation)
        $validRecords = [];
        $skippedOtherHcode = 0;
        foreach (($extracted['sample_data'] ?? []) as $rec) {
            if (($rec['hcode'] ?? '') !== $targetHcode) {
                $skippedOtherHcode++;
                continue;
            }
            $validRecords[] = $rec;
        }

        // สร้างรายการสำหรับแสดงผลตาราง UI (สูงสุด 300 แถว เพื่อความลื่นไหล ไม่ลายตา)
        $displayRows = [];
        foreach (array_slice($validRecords, 0, 300) as $rec) {
            $tpu = $rec['tpu_code_list'];
            $meta = $drugLookupMap[$tpu] ?? null;
            $displayRows[] = [
                'vstdate'        => $rec['vstdate'],
                'vn'             => $rec['vn'],
                'hn'             => $rec['hn'] ?? '-',
                'hcode'          => $rec['hcode'],
                'tpu_code_list'  => $tpu,
                'drugname'       => $meta['drugname'] ?? '-',
                'drug_type_name' => $meta['drug_type_name'] ?? '',
                'units'          => $meta['units'] ?? '',
                'qty'            => $rec['qty'],
                'hos_guid'       => $rec['hos_guid'],
            ];
        }

        return [
            'success'             => true,
            'hcode'               => $targetHcode,
            'start_date'          => $startDate,
            'end_date'            => $endDate,
            'total_read'          => (int)$extracted['total_read'],
            'total_passed'        => count($validRecords),
            'duplicates_pruned'   => (int)$extracted['duplicates_pruned'],
            'total_malformed'     => (int)$extracted['total_malformed'],
            'skipped_other_hcode' => $skippedOtherHcode,
            'execution_time_ms'   => $extracted['execution_time_ms'] ?? 0,
            'records'             => $validRecords,
            'display_rows'        => $displayRows,
            'display_truncated'   => count($validRecords) > 300,
        ];
    }

    /**
     * ดึงข้อมูลจริงจาก HOSxP และส่งขึ้น Supabase Cloud RPC fn_sync_dispense_batch ทันที
     */
    public function syncNow(array $config, string $startDate, string $endDate, bool $reconcile = false): array {
        $startedAt = microtime(true);
        $hcode       = trim((string)($config['hcode'] ?? ''));
        $supabaseUrl = rtrim(trim((string)($config['supabase_url'] ?? '')), '/');
        $anonKey     = trim((string)($config['supabase_anon_key'] ?? ''));
        $syncApiKey  = trim((string)($config['sync_api_key'] ?? ''));

        if ($supabaseUrl === '' || $anonKey === '') {
            return [
                'success' => false,
                'error'   => 'ยังไม่ได้ตั้งค่า Supabase URL หรือ Anon Key ในแท็บตั้งค่าการเชื่อมต่อ',
            ];
        }
        if ($syncApiKey === '') {
            return [
                'success' => false,
                'error'   => 'ยังไม่ได้ระบุ Sync API Key ของหน่วยบริการ (สร้างคีย์ได้ที่เว็บหลัก เมนู หน่วยบริการ รพ.สต.)',
            ];
        }

        // 1. สกัดข้อมูลจริงจาก HOSxP
        $extraction = $this->extractRealRecords($config, $startDate, $endDate);
        if (!$extraction['success']) {
            $this->appendHistory([
                'timestamp'    => date('Y-m-d H:i:s'),
                'hcode'        => $hcode ?: '-',
                'range'        => "{$startDate} ถึง {$endDate}",
                'mode'         => $reconcile ? 'RECONCILE_30D' : 'SYNC',
                'status'       => 'failed',
                'read_count'   => 0,
                'synced_count' => 0,
                'deleted_count'=> 0,
                'failed_count' => 0,
                'duration_ms'  => (int)round((microtime(true) - $startedAt) * 1000),
                'message'      => $extraction['error'] ?? 'ดึงข้อมูล HOSxP ไม่สำเร็จ',
            ]);
            return $extraction;
        }

        $records = $extraction['records'];

        // 2. Client-side Pre-flight Validation
        $validator = new AgentApiClient();
        $batches = empty($records) ? [[]] : array_chunk($records, 500);
        $sanitizedBatches = [];
        foreach ($batches as $chunk) {
            if (empty($chunk)) {
                $sanitizedBatches[] = [];
                continue;
            }
            // อนุญาตให้ฟิลด์ hn ผ่านการส่งข้อมูลได้
            [$valid, $valErrors, $cleanChunk] = $validator->validatePayload($chunk, $hcode, ['hn']);
            if (!$valid) {
                return [
                    'success' => false,
                    'error'   => 'ข้อมูลไม่ผ่านการตรวจสอบความปลอดภัยก่อนส่ง: ' . implode('; ', array_slice($valErrors, 0, 2)),
                ];
            }
            $sanitizedBatches[] = $cleanChunk;
        }

        // เตรียม Handshake สำหรับการกระทบยอด (Soft Delete รายการที่ถูกยกเลิกใน HOSxP)
        $reconcilePayload = null;
        if ($reconcile) {
            $activePairs = [];
            foreach ($records as $r) {
                $activePairs[] = [
                    'hcode'    => $hcode,
                    'hos_guid' => $r['hos_guid'],
                ];
            }
            $reconcilePayload = [
                'reconcile_hcode'       => $hcode,
                'start_date'            => $startDate,
                'end_date'              => $endDate,
                'source_query_status'   => 'COMPLETED',
                'expected_source_count' => count($activePairs),
                'source_active_pairs'   => $activePairs,
            ];
        }

        // กรณีไม่มีรายการจ่ายยาในช่วงวันที่ และไม่ได้สั่ง Reconcile -> ไม่ต้องยิง RPC เปล่า
        if (empty($records) && !$reconcile) {
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            $this->appendHistory([
                'timestamp'     => date('Y-m-d H:i:s'),
                'hcode'         => $hcode,
                'range'         => "{$startDate} ถึง {$endDate}",
                'mode'          => 'SYNC',
                'status'        => 'success',
                'read_count'    => $extraction['total_read'],
                'synced_count'  => 0,
                'deleted_count' => 0,
                'failed_count'  => 0,
                'duration_ms'   => $durationMs,
                'message'       => 'ไม่พบรายการจ่ายยาในทะเบียนตามช่วงวันที่เลือก',
            ]);
            return [
                'success'             => true,
                'hcode'               => $hcode,
                'start_date'          => $startDate,
                'end_date'            => $endDate,
                'total_read'          => $extraction['total_read'],
                'total_passed'        => 0,
                'inserted_or_updated' => 0,
                'deleted'             => 0,
                'failed'              => 0,
                'batches_sent'        => 0,
                'duration_ms'         => $durationMs,
                'display_rows'        => [],
                'message'             => 'ตรวจสอบเรียบร้อย ไม่พบรายการจ่ายยาในทะเบียนสำหรับช่วงวันที่เลือก',
            ];
        }

        // 3. ส่งแต่ละ Batch ขึ้น Supabase RPC fn_sync_dispense_batch
        $rpcUrl       = $supabaseUrl . '/rest/v1/rpc/fn_sync_dispense_batch';
        $totalSynced  = 0;
        $totalDeleted = 0;
        $totalFailed  = 0;
        $rpcErrors    = [];
        $syncLogIds   = [];
        $batchCount   = count($sanitizedBatches);

        foreach ($sanitizedBatches as $idx => $batchRows) {
            $isLastBatch = ($idx === $batchCount - 1);
            $rpcBody = [
                'p_hcode'               => $hcode,
                'p_plain_api_key'       => $syncApiKey,
                'p_records'             => $batchRows,
                'p_deleted_hos_guids'   => [],
                'p_reconcile_handshake' => $isLastBatch ? $reconcilePayload : null,
            ];

            $res = $this->postSupabaseJson($rpcUrl, $anonKey, $rpcBody, 30);

            if (!$res['transport_ok'] || $res['http_code'] >= 400 || !is_array($res['body'])) {
                $errMsg = $res['error']
                    ?: (is_array($res['body']) ? ($res['body']['message'] ?? json_encode($res['body'])) : 'HTTP ' . $res['http_code']);
                $this->appendHistory([
                    'timestamp'     => date('Y-m-d H:i:s'),
                    'hcode'         => $hcode,
                    'range'         => "{$startDate} ถึง {$endDate}",
                    'mode'          => $reconcile ? 'RECONCILE_30D' : 'SYNC',
                    'status'        => 'failed',
                    'read_count'    => $extraction['total_read'],
                    'synced_count'  => $totalSynced,
                    'deleted_count' => $totalDeleted,
                    'failed_count'  => count($records) - $totalSynced,
                    'duration_ms'   => (int)round((microtime(true) - $startedAt) * 1000),
                    'message'       => 'ส่งข้อมูลขึ้น Supabase ล้มเหลว: ' . $errMsg,
                ]);
                return [
                    'success' => false,
                    'error'   => 'ส่งข้อมูลขึ้น Supabase ล้มเหลว: ' . $errMsg,
                ];
            }

            $rpcData = $res['body'];
            if (empty($rpcData['success'])) {
                $authErr = $rpcData['error'] ?? 'การยืนยันตัวตนหรือบันทึกข้อมูลถูกปฏิเสธจากเซิร์ฟเวอร์กลาง';
                $this->appendHistory([
                    'timestamp'     => date('Y-m-d H:i:s'),
                    'hcode'         => $hcode,
                    'range'         => "{$startDate} ถึง {$endDate}",
                    'mode'          => $reconcile ? 'RECONCILE_30D' : 'SYNC',
                    'status'        => 'failed',
                    'read_count'    => $extraction['total_read'],
                    'synced_count'  => $totalSynced,
                    'deleted_count' => $totalDeleted,
                    'failed_count'  => count($records),
                    'duration_ms'   => (int)round((microtime(true) - $startedAt) * 1000),
                    'message'       => $authErr,
                ]);
                return [
                    'success' => false,
                    'error'   => $authErr,
                ];
            }

            $totalSynced  += (int)($rpcData['inserted_or_updated'] ?? 0);
            $totalDeleted += (int)($rpcData['deleted'] ?? 0);
            $totalFailed  += (int)($rpcData['failed'] ?? 0);
            if (!empty($rpcData['sync_log_id'])) {
                $syncLogIds[] = $rpcData['sync_log_id'];
            }
            if (!empty($rpcData['errors']) && is_array($rpcData['errors'])) {
                $rpcErrors = array_merge($rpcErrors, $rpcData['errors']);
            }
        }

        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        $status = ($totalFailed === 0) ? 'success' : 'partial';
        $summaryMsg = sprintf(
            'ซิงค์สำเร็จ %s รายการ%s%s (%s ms)',
            number_format($totalSynced),
            $totalDeleted > 0 ? " • ปรับยกเลิก {$totalDeleted} รายการ" : '',
            $totalFailed > 0 ? " • ไม่สำเร็จ {$totalFailed} รายการ" : '',
            number_format($durationMs)
        );

        $this->appendHistory([
            'timestamp'     => date('Y-m-d H:i:s'),
            'hcode'         => $hcode,
            'range'         => "{$startDate} ถึง {$endDate}",
            'mode'          => $reconcile ? 'RECONCILE_30D' : 'SYNC',
            'status'        => $status,
            'read_count'    => $extraction['total_read'],
            'synced_count'  => $totalSynced,
            'deleted_count' => $totalDeleted,
            'failed_count'  => $totalFailed,
            'duration_ms'   => $durationMs,
            'sync_log_id'   => $syncLogIds[0] ?? null,
            'message'       => $summaryMsg,
        ]);

        return [
            'success'             => true,
            'status'              => $status,
            'hcode'               => $hcode,
            'start_date'          => $startDate,
            'end_date'            => $endDate,
            'total_read'          => $extraction['total_read'],
            'total_passed'        => $extraction['total_passed'],
            'duplicates_pruned'   => $extraction['duplicates_pruned'],
            'inserted_or_updated' => $totalSynced,
            'deleted'             => $totalDeleted,
            'failed'              => $totalFailed,
            'batches_sent'        => $batchCount,
            'sync_log_ids'        => $syncLogIds,
            'errors'              => array_slice($rpcErrors, 0, 20),
            'duration_ms'         => $durationMs,
            'display_rows'        => $extraction['display_rows'],
            'display_truncated'   => $extraction['display_truncated'],
            'message'             => $summaryMsg,
        ];
    }

    /**
     * อ่านประวัติการซิงค์จริง
     */
    public function getSyncHistory(int $limit = 30): array {
        if (!file_exists($this->historyFile)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($this->historyFile), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_slice($decoded, 0, max(1, $limit));
    }

    /**
     * ล้างประวัติการซิงค์ในเครื่อง
     */
    public function clearSyncHistory(): bool {
        if (file_exists($this->historyFile)) {
            return @unlink($this->historyFile);
        }
        return true;
    }

    private function appendHistory(array $entry): void {
        $dir = dirname($this->historyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $existing = $this->getSyncHistory(100);
        array_unshift($existing, $entry);
        $trimmed = array_slice($existing, 0, 100);
        @file_put_contents(
            $this->historyFile,
            json_encode($trimmed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * ส่ง HTTPS POST ไปยัง Supabase REST/RPC พร้อมตรวจสอบ TLS Certificate เสมอ
     */
    private function postSupabaseJson(string $url, string $anonKey, array $payload, int $timeout = 20): array {
        if (!extension_loaded('curl')) {
            return [
                'transport_ok' => false,
                'http_code'    => 0,
                'body'         => null,
                'error'        => 'ไม่พบส่วนขยาย PHP cURL',
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'apikey: ' . $anonKey,
                'Authorization: Bearer ' . $anonKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'transport_ok' => false,
                'http_code'    => 0,
                'body'         => null,
                'error'        => 'การเชื่อมต่อเครือข่ายขัดข้อง: ' . $err,
            ];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'transport_ok' => true,
            'http_code'    => $code,
            'body'         => ($decoded !== null || trim((string)$raw) === 'null') ? $decoded : $raw,
            'error'        => '',
        ];
    }

    /**
     * ส่งข้อความแจ้งเตือนผ่าน MOPH Notify (หมอพร้อม) ตามสเปค moph_notify_api_guide.txt
     *
     * @return array{ok: bool, status: int, response: string, error: string}
     */
    public function sendMophNotify(string $message, ?string $clientKey = null, ?string $secretKey = null): array {
        $cfg = $this->resolveEffectiveConfig();
        $cKey = trim((string)($clientKey ?? ($cfg['moph_client_key'] ?? '')));
        $sKey = trim((string)($secretKey ?? ($cfg['moph_secret_key'] ?? '')));

        if ($cKey === '' || $sKey === '') {
            return [
                'ok'       => false,
                'status'   => 0,
                'response' => '',
                'error'    => 'ยังไม่ได้ระบุ Client Key หรือ Secret Key ของ MOPH Notify',
            ];
        }

        if (!extension_loaded('curl')) {
            return [
                'ok'       => false,
                'status'   => 0,
                'response' => '',
                'error'    => 'ไม่พบส่วนขยาย PHP cURL',
            ];
        }

        $payload = json_encode([
            'messages' => [
                [
                    'type' => 'text',
                    'text' => trim($message),
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init('https://morpromt2f.moph.go.th/api/notify/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'client-key: ' . $cKey,
                'secret-key: ' . $sKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'ok'       => false,
                'status'   => $httpCode,
                'response' => '',
                'error'    => $curlErr !== '' ? $curlErr : 'ไม่สามารถเชื่อมต่อ MOPH Notify API ได้',
            ];
        }

        $ok = in_array($httpCode, [200, 201], true);
        return [
            'ok'       => $ok,
            'status'   => $httpCode,
            'response' => (string)$response,
            'error'    => $ok ? '' : "MOPH Notify ตอบกลับ HTTP {$httpCode}: " . mb_substr((string)$response, 0, 200),
        ];
    }

    /**
     * สร้างข้อความสรุปผลการ Sync (ปราศจาก PII) และส่งเข้า MOPH Notify
     *
     * @return array{skipped: bool, ok: bool, status: int, error: string}
     */
    public function notifySyncResult(array $config, string $startDate, string $endDate, array $syncResult, bool $isAutoCli = false): array {
        if (empty($config['moph_notify_enabled'])) {
            return ['skipped' => true, 'ok' => true, 'status' => 0, 'error' => 'MOPH Notify disabled'];
        }
        if (!$isAutoCli && empty($config['moph_notify_on_manual'])) {
            return ['skipped' => true, 'ok' => true, 'status' => 0, 'error' => 'Manual sync notification disabled'];
        }

        $hcode = trim((string)($config['hcode'] ?? '-'));
        $facilityName = trim((string)($config['facility_name'] ?? ''));
        if ($facilityName === '') {
            $facilityName = "หน่วยบริการ {$hcode}";
        }

        $fmtThaiDate = static function (string $iso): string {
            $ts = strtotime($iso);
            if ($ts === false) return $iso;
            return date('d/m/', $ts) . ((int)date('Y', $ts) + 543);
        };

        $rangeTxt = $fmtThaiDate($startDate) . ' – ' . $fmtThaiDate($endDate);
        $nowTxt = date('d/m/') . ((int)date('Y') + 543) . ' ' . date('H:i') . ' น.';

        if (!empty($syncResult['success'])) {
            $readCount    = (int)($syncResult['total_read'] ?? 0);
            $syncedCount  = (int)($syncResult['inserted_or_updated'] ?? 0);
            $deletedCount = (int)($syncResult['deleted'] ?? 0);
            $failedCount  = (int)($syncResult['failed'] ?? 0);
            $durSec       = round(((int)($syncResult['duration_ms'] ?? 0)) / 1000, 1);

            $lines = [
                "✅ RxCore Sync: {$facilityName} ({$hcode})",
                "ช่วงวันที่: {$rangeTxt}",
            ];

            if ($syncedCount === 0 && $deletedCount === 0 && $readCount === 0) {
                $lines[] = 'สถานะ: ไม่พบรายการจ่ายยาในทะเบียนตามช่วงวันที่';
            } else {
                $lines[] = 'อ่านจาก HOSxP: ' . number_format($readCount) . ' รายการ';
                $detail = 'นำเข้าสำเร็จ: ' . number_format($syncedCount) . ' รายการ';
                if ($deletedCount > 0) {
                    $detail .= ' | ยกเลิก: ' . number_format($deletedCount) . ' รายการ';
                }
                if ($failedCount > 0) {
                    $detail .= ' | ไม่ผ่าน: ' . number_format($failedCount) . ' รายการ';
                }
                $lines[] = $detail;
            }
            $lines[] = "เวลา: {$nowTxt} ({$durSec} วินาที)";
        } else {
            $errReason = trim((string)($syncResult['error'] ?? 'ไม่ทราบสาเหตุ'));
            $errReason = preg_replace('/password.*/i', 'password [REDACTED]', $errReason);
            $lines = [
                "❌ RxCore Sync ล้มเหลว: {$facilityName} ({$hcode})",
                "ช่วงวันที่: {$rangeTxt}",
                'สาเหตุ: ' . mb_substr($errReason, 0, 180),
                "เวลา: {$nowTxt}",
            ];
        }

        $res = $this->sendMophNotify(
            implode("\n", $lines),
            $config['moph_client_key'] ?? null,
            $config['moph_secret_key'] ?? null
        );

        return [
            'skipped' => false,
            'ok'      => $res['ok'],
            'status'  => $res['status'],
            'error'   => $res['error'],
        ];
    }
}
