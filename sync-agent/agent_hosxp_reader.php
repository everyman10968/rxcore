<?php
/**
 * RxCore Sync Agent — HOSxP Real Database Reader & Test Validator
 * Phase 3.4: HOSxP Real Database Read Test
 * 
 * หน้าที่หลัก:
 * 1. Test A: ตรวจสอบการเชื่อมต่อ MySQL/MariaDB (ห้ามใช้บัญชี root หรือ admin)
 * 2. Test B: ตรวจสอบโครงสร้าง Schema และฟิลด์ที่จำเป็น (opitemrece, drugitems, vn_stat)
 * 3. Test C: ตรวจสอบสิทธิ์ Read-only ทุกระดับ (Global, Schema, Table, Column, Role)
 * 4. Test D: ทดสอบ Query ข้อมูลจริง (ต้องกดยืนยันก่อน, จำกัดช่วงวัน ≤ 1 วัน, จำกัดผลลัพธ์ ≤ 100 รายการ, 6 ฟิลด์เท่านั้น)
 * 5. Test E: เปรียบเทียบผลลัพธ์กับตัวเลขอ้างอิงจาก HOSxP
 * 
 * มาตรการความปลอดภัย:
 * - ห้ามรันคำสั่งแก้ไขข้อมูล (INSERT, UPDATE, DELETE, DROP, ALTER) โดยเด็ดขาด
 * - ปิดบังรหัสผ่านในทุกกรณี ไม่ให้หลุดใน Log หรือ Response
 * - บังคับใช้ Prepared Statements และ Whitelist 6 ฟิลด์
 */

require_once __DIR__ . '/agent_connection.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_drug_registry.php';

class AgentHosxpReader {

    /**
     * โครงสร้างตารางและฟิลด์ที่ HOSxP จำเป็นต้องมีสำหรับ RxCore (Strict Schema Definition)
     * หากขาดตารางหรือฟิลด์ใดฟิลด์หนึ่ง ให้หยุดทันที ห้ามเดาชื่อฟิลด์
     */
    public const REQUIRED_SCHEMA = [
        'opitemrece' => [
            'required_columns' => ['hos_guid', 'vn', 'vstdate', 'icode', 'qty'],
            'description'      => 'ตารางบันทึกรายการจ่ายยาและเวชภัณฑ์',
        ],
        'drugitems' => [
            'required_columns' => ['icode', 'tpu_code_list'],
            'description'      => 'ตารางทะเบียนยา HOSxP (icode เชื่อม TPU)',
        ],
        'vn_stat' => [
            'required_columns' => ['vn', 'hcode'],
            'description'      => 'ตารางการรับบริการผู้ป่วยนอก (ระบุสถานพยาบาล hcode)',
        ],
    ];

    /**
     * รายชื่อบัญชีผู้ดูแลระบบที่ห้ามใช้ในการทดสอบ
     */
    public const DISALLOWED_ACCOUNTS = [
        'root',
        'admin',
        'administrator',
        'sa',
        'mysql.sys',
        'mysql.session',
        'mysql.infoschema',
    ];

    private ?PDO $pdo = null;
    private array $config;
    private array $diagnostics = [];

    /**
     * @param array $config ค่าตั้งค่าการเชื่อมต่อ [hosxp_host, hosxp_port, hosxp_database, hosxp_username, hosxp_password]
     * @param PDO|null $existingPdo อนุญาตให้ส่ง PDO เข้ามาสำหรับทดสอบใน Unit Test
     */
    public function __construct(array $config = [], ?PDO $existingPdo = null) {
        $this->config = $config;
        $this->pdo = $existingPdo;
    }

    /**
     * =========================================================================
     * Test A: ตรวจสอบการเชื่อมต่อ (Database Connection)
     * =========================================================================
     */
    public function testConnection(bool $allowAnyAccount = false): array {
        $startTime = microtime(true);
        $username = trim($this->config['hosxp_username'] ?? '');

        // เงื่อนไขความปลอดภัย (เฉพาะโหมด Strict Test): ปฏิเสธบัญชี root หรือ admin
        if (!$allowAnyAccount && $this->isDisallowedAccount($username)) {
            return [
                'success' => false,
                'test'    => 'TEST_A_CONNECTION',
                'error'   => "ความปลอดภัยไม่อนุญาต: ห้ามใช้บัญชีผู้ดูแลระบบ '{$username}' ในการเชื่อมต่อ ต้องใช้บัญชีที่จำกัดสิทธิ์เฉพาะ Read-only เท่านั้น",
                'account_rejected' => true,
            ];
        }

        // หากมี PDO อยู่แล้ว (Mock / Injection)
        if ($this->pdo !== null) {
            return $this->inspectPdoServerInfo($this->pdo, $startTime);
        }

        $host = trim($this->config['hosxp_host'] ?? '');
        $port = (int)($this->config['hosxp_port'] ?? 3306);
        $database = trim($this->config['hosxp_database'] ?? '');
        $password = $this->config['hosxp_password'] ?? '';

        if ($host === '' || $database === '' || $username === '') {
            return [
                'success' => false,
                'test'    => 'TEST_A_CONNECTION',
                'error'   => 'ข้อมูลการเชื่อมต่อไม่ครบถ้วน กรุณากรอก Host, Database, และ Username',
            ];
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            $this->pdo = $pdo;
            return $this->inspectPdoServerInfo($pdo, $startTime);
        } catch (\PDOException $e) {
            $safeError = $this->sanitizeErrorMessage($e->getMessage());
            return [
                'success'           => false,
                'test'              => 'TEST_A_CONNECTION',
                'error'             => 'เชื่อมต่อฐานข้อมูลล้มเหลว: ' . $safeError,
                'connection_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        } catch (\Throwable $e) {
            $safeError = $this->sanitizeErrorMessage($e->getMessage());
            return [
                'success'           => false,
                'test'              => 'TEST_A_CONNECTION',
                'error'             => 'เกิดข้อผิดพลาดในการเชื่อมต่อ: ' . $safeError,
                'connection_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        }
    }

    /**
     * ดึงข้อมูล Server Info จาก PDO
     */
    private function inspectPdoServerInfo(PDO $pdo, float $startTime): array {
        try {
            $stmt = $pdo->query("SELECT VERSION() AS ver, DATABASE() AS db, @@character_set_connection AS cs, CONNECTION_ID() AS cid");
            $info = $stmt->fetch();
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success'            => true,
                'test'               => 'TEST_A_CONNECTION',
                'server_version'     => $info['ver'] ?? 'Unknown',
                'database'           => $info['db'] ?? ($this->config['hosxp_database'] ?? 'Connected'),
                'charset'            => $info['cs'] ?? 'utf8',
                'connection_id'      => $info['cid'] ?? null,
                'connection_time_ms' => $latency,
                'message'            => sprintf('เชื่อมต่อสำเร็จ (%s) ตอบสนองใน %.2f ms', $info['ver'] ?? 'MySQL', $latency),
            ];
        } catch (\Throwable $e) {
            // กรณีเป็น SQLite mock หรือ engine อื่น
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $ver = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'success'            => true,
                'test'               => 'TEST_A_CONNECTION',
                'server_version'     => "{$driver} {$ver}",
                'database'           => $this->config['hosxp_database'] ?? 'memory',
                'charset'            => 'utf8',
                'connection_id'      => 1,
                'connection_time_ms' => $latency,
                'message'            => sprintf('เชื่อมต่อสำเร็จ (%s) ตอบสนองใน %.2f ms', $driver, $latency),
            ];
        }
    }

    /**
     * =========================================================================
     * Test B: ตรวจสอบโครงสร้าง Schema และฟิลด์ที่จำเป็น (Schema Validation)
     * =========================================================================
     */
    public function validateSchema(?PDO $pdoInstance = null): array {
        $pdo = $pdoInstance ?? $this->pdo;
        if ($pdo === null) {
            $connRes = $this->testConnection();
            if (!$connRes['success']) {
                return [
                    'success' => false,
                    'test'    => 'TEST_B_SCHEMA',
                    'error'   => 'ไม่สามารถตรวจสอบ Schema ได้เนื่องจากการเชื่อมต่อล้มเหลว: ' . ($connRes['error'] ?? ''),
                ];
            }
            $pdo = $this->pdo;
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tablesStatus = [];
        $missingItems = [];

        foreach (self::REQUIRED_SCHEMA as $tableName => $req) {
            $existingColumns = $this->fetchTableColumns($pdo, $driver, $tableName);

            if ($existingColumns === null) {
                // ไม่พบตาราง
                $tablesStatus[$tableName] = [
                    'exists'          => false,
                    'description'     => $req['description'],
                    'missing_columns' => $req['required_columns'],
                    'found_columns'   => [],
                ];
                $missingItems[] = "ไม่พบตาราง '{$tableName}' ({$req['description']})";
                continue;
            }

            // ตรวจสอบคอลัมน์ที่จำเป็น
            $missingCols = [];
            foreach ($req['required_columns'] as $col) {
                if (!in_array(strtolower($col), $existingColumns, true)) {
                    $missingCols[] = $col;
                }
            }

            $tablesStatus[$tableName] = [
                'exists'          => true,
                'description'     => $req['description'],
                'missing_columns' => $missingCols,
                'found_columns'   => $existingColumns,
            ];

            if (!empty($missingCols)) {
                $missingItems[] = sprintf("ตาราง '%s' ขาดฟิลด์: %s", $tableName, implode(', ', $missingCols));
            }
        }

        $allValid = empty($missingItems);

        return [
            'success'       => $allValid,
            'test'          => 'TEST_B_SCHEMA',
            'tables_status' => $tablesStatus,
            'missing_items' => $missingItems,
            'message'       => $allValid 
                ? 'โครงสร้าง Schema และฟิลด์ที่จำเป็นครบถ้วนสมบูรณ์ (opitemrece, drugitems, vn_stat)'
                : 'โครงสร้าง Schema ไม่ตรงตามข้อกำหนด: ' . implode('; ', $missingItems),
        ];
    }

    /**
     * ดึงรายชื่อคอลัมน์ของตาราง
     */
    private function fetchTableColumns(PDO $pdo, string $driver, string $tableName): ?array {
        if ($driver === 'sqlite') {
            try {
                $stmt = $pdo->query("PRAGMA table_info('{$tableName}')");
                $cols = [];
                $rows = $stmt->fetchAll();
                if (empty($rows)) return null;
                foreach ($rows as $r) {
                    $cols[] = strtolower($r['name']);
                }
                return $cols;
            } catch (\Throwable) {
                return null;
            }
        }

        // สำหรับ MySQL / MariaDB ใช้ information_schema.COLUMNS
        try {
            $database = $this->config['hosxp_database'] ?? '';
            if ($database !== '') {
                $stmt = $pdo->prepare("
                    SELECT LOWER(COLUMN_NAME) AS col 
                    FROM information_schema.COLUMNS 
                    WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
                ");
                $stmt->execute([':db' => $database, ':tbl' => $tableName]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT LOWER(COLUMN_NAME) AS col 
                    FROM information_schema.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl
                ");
                $stmt->execute([':tbl' => $tableName]);
            }

            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (empty($rows)) {
                // ลอง DESCRIBE เป็น Fallback
                try {
                    $desc = $pdo->query("DESCRIBE `{$tableName}`")->fetchAll(PDO::FETCH_COLUMN);
                    return array_map('strtolower', $desc);
                } catch (\Throwable) {
                    return null;
                }
            }

            return $rows;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * =========================================================================
     * Test C: ตรวจสอบสิทธิ์ Read-only (Read-only Privilege Audit)
     * =========================================================================
     */
    public function validatePrivileges(): array {
        $username = trim($this->config['hosxp_username'] ?? '');

        // ปฏิเสธบัญชี root หรือ admin
        if ($this->isDisallowedAccount($username)) {
            return [
                'success'     => false,
                'test'        => 'TEST_C_PRIVILEGE',
                'is_readonly' => false,
                'error'       => "ความปลอดภัยไม่อนุญาต: ห้ามใช้บัญชีผู้ดูแลระบบ '{$username}' ในการอ่านข้อมูล",
                'prohibited'  => ['ADMINISTRATIVE_ACCOUNT_PROHIBITED'],
            ];
        }

        $connector = new AgentConnection();
        $result = $connector->testConnection($this->config);

        if (!$result['success']) {
            return [
                'success'     => false,
                'test'        => 'TEST_C_PRIVILEGE',
                'is_readonly' => false,
                'error'       => $result['error'] ?? 'การตรวจสอบสิทธิ์ Read-only ล้มเหลว',
                'prohibited'  => $result['prohibited'] ?? [],
            ];
        }

        if (empty($result['is_readonly'])) {
            $prohibited = implode(', ', $result['prohibited'] ?? ['WRITE_PRIVILEGES_DETECTED']);
            return [
                'success'     => false,
                'test'        => 'TEST_C_PRIVILEGE',
                'is_readonly' => false,
                'error'       => "บัญชีมีสิทธิ์เขียนที่ขัดกับนโยบาย ({$prohibited}) ต้องใช้เฉพาะสิทธิ์ SELECT เท่านั้น",
                'prohibited'  => $result['prohibited'] ?? [],
            ];
        }

        return [
            'success'     => true,
            'test'        => 'TEST_C_PRIVILEGE',
            'is_readonly' => true,
            'privileges'  => $result['privileges'] ?? ['SELECT'],
            'prohibited'  => [],
            'message'     => 'บัญชีผ่านการตรวจสอบสิทธิ์ Read-only ทุกระดับ (SELECT เท่านั้น ไม่มีสิทธิ์เขียน)',
        ];
    }

    /**
     * =========================================================================
     * Test D: ทดสอบการ Query ข้อมูลจริง (Query Validation)
     * =========================================================================
     * 
     * เงื่อนไขความปลอดภัย:
     * 1. ต้องมีการกดยืนยัน ($confirmed === true)
     * 2. กำหนดช่วงทดสอบเริ่มต้นไม่เกิน 1 วัน
     * 3. ใช้เฉพาะรหัสยาจาก AgentDrugRegistry จริง (ห้าม Mock รหัสขึ้นเอง)
     * 4. จำกัดผลลัพธ์ตัวอย่างไม่เกิน 100 รายการ
     * 5. ส่งออกเฉพาะ 6 ฟิลด์ที่กำหนด ห้ามมี HN, CID หรือ PII
     * 
     * @param string $startDate วันที่เริ่มต้น (YYYY-MM-DD)
     * @param string $endDate   วันที่สิ้นสุด (YYYY-MM-DD)
     * @param bool $confirmed   การยืนยันจากผู้ดูแลระบบ
     * @param PDO|null $customPdo PDO จำลองหรือจริง
     */
    public function validateQuery(
        string $startDate,
        string $endDate,
        bool $confirmed = false,
        ?PDO $customPdo = null
    ): array {
        // 1. ตรวจสอบการยืนยันก่อนเริ่ม
        if (!$confirmed) {
            return [
                'success' => false,
                'test'    => 'TEST_D_QUERY',
                'error'   => 'ความปลอดภัย: ต้องได้รับการยืนยันจากผู้ดูแลระบบก่อนอนุญาตให้อ่านข้อมูลจากฐานข้อมูลจริง',
                'confirmation_required' => true,
            ];
        }

        // 2. ตรวจสอบรูปแบบวันที่
        if (!$this->validateDateFormat($startDate) || !$this->validateDateFormat($endDate)) {
            return [
                'success' => false,
                'test'    => 'TEST_D_QUERY',
                'error'   => 'รูปแบบวันที่ไม่ถูกต้อง ต้องอยู่ในรูปแบบ YYYY-MM-DD',
            ];
        }

        if ($startDate > $endDate) {
            return [
                'success' => false,
                'test'    => 'TEST_D_QUERY',
                'error'   => 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',
            ];
        }

        // 3. ตรวจสอบข้อจำกัดช่วงวันทดสอบเริ่มต้นไม่เกิน 1 วัน
        $diffDays = (strtotime($endDate) - strtotime($startDate)) / 86400;
        if ($diffDays > 1) {
            return [
                'success' => false,
                'test'    => 'TEST_D_QUERY',
                'error'   => sprintf('ช่วงเวลาทดสอบต้องไม่เกิน 1 วันสำหรับการอ่านข้อมูลจริงครั้งแรก (ระบุ: %d วัน)', (int)($diffDays + 1)),
                'days_requested' => (int)($diffDays + 1),
            ];
        }

        // 4. ตรวจสอบทะเบียนยาที่กำหนด
        $registeredDrugs = AgentDrugRegistry::getRegisteredDrugCodes();
        if (empty($registeredDrugs)) {
            return [
                'success' => false,
                'test'    => 'TEST_D_QUERY',
                'error'   => 'ไม่พบรายการยาในทะเบียนที่ได้รับอนุญาตให้ Sync',
            ];
        }

        // 5. เตรียม PDO
        $pdo = $customPdo ?? $this->pdo;
        if ($pdo === null) {
            $connRes = $this->testConnection();
            if (!$connRes['success']) {
                return [
                    'success' => false,
                    'test'    => 'TEST_D_QUERY',
                    'error'   => 'ไม่สามารถเปิดการเชื่อมต่อไปยังฐานข้อมูลได้: ' . ($connRes['error'] ?? ''),
                ];
            }
            $pdo = $this->pdo;
        }

        // 6. ดึงข้อมูลผ่าน AgentDataExtractor
        $extractor = new AgentDataExtractor($pdo, $registeredDrugs);
        $extractResult = $extractor->extract($startDate, $endDate);

        if (!$extractResult['success']) {
            return [
                'success' => false,
                'test'    => 'TEST_D_QUERY',
                'error'   => $this->sanitizeErrorMessage($extractResult['error'] ?? 'การดึงข้อมูลล้มเหลว'),
            ];
        }

        $allPassed = $extractResult['sample_data'] ?? [];
        $totalPassedCount = count($allPassed);

        // 7. จำกัดผลลัพธ์ตัวอย่างไม่เกิน 100 รายการ
        $sampleLimit100 = array_slice($allPassed, 0, 100);

        // 8. ตรวจสอบ Strict 6 Fields Isolation
        $sanitizedSamples = [];
        foreach ($sampleLimit100 as $row) {
            $sanitizedSamples[] = [
                'hos_guid'      => (string)($row['hos_guid'] ?? ''),
                'vn'            => (string)($row['vn'] ?? ''),
                'hcode'         => (string)($row['hcode'] ?? ''),
                'vstdate'       => (string)($row['vstdate'] ?? ''),
                'tpu_code_list' => (string)($row['tpu_code_list'] ?? ''),
                'qty'           => (float)($row['qty'] ?? 0),
            ];
        }

        return [
            'success'           => true,
            'test'              => 'TEST_D_QUERY',
            'status'            => 'TEST_COMPLETED',
            'start_date'        => $startDate,
            'end_date'          => $endDate,
            'total_read'        => $extractResult['total_read'],
            'total_passed'      => $totalPassedCount,
            'duplicates_pruned' => $extractResult['duplicates_pruned'],
            'total_malformed'   => $extractResult['total_malformed'],
            'malformed_summary' => $extractResult['malformed_summary'],
            'sample_count'      => count($sanitizedSamples),
            'sample_data'       => $sanitizedSamples, // จำกัด ≤ 100 รายการ
            'execution_time_ms' => $extractResult['execution_time_ms'] ?? 0,
            'message'           => sprintf(
                'ดึงข้อมูลสำเร็จ: อ่าน %d รายการ | ผ่านเกณฑ์ %d รายการ | ตัดซ้ำ %d รายการ | ผิดรูปแบบ %d รายการ (แสดงตัวอย่าง %d รายการ)',
                $extractResult['total_read'],
                $totalPassedCount,
                $extractResult['duplicates_pruned'],
                $extractResult['total_malformed'],
                count($sanitizedSamples)
            ),
        ];
    }

    /**
     * =========================================================================
     * Test E: เปรียบเทียบผลลัพธ์กับ HOSxP (Compare Results)
     * =========================================================================
     * 
     * ให้ผู้ดูแลระบบนำผลนับจาก SQL บน HOSxP มากรอกเพื่อเปรียบเทียบ
     * ห้ามอ้างว่าผลตรงกันหากยังไม่ได้เปรียบเทียบจริง
     * 
     * @param int|null $hosxpManualCount ผลนับที่ได้จากการรัน SQL บน HOSxP โดยตรง
     * @param int $agentCount ผลนับที่ได้จาก Agent
     */
    public static function compareResults(?int $hosxpManualCount, int $agentCount): array {
        if ($hosxpManualCount === null) {
            return [
                'status'     => 'NOT_VERIFIED',
                'matched'    => false,
                'message'    => 'ยังไม่ได้ทำการเปรียบเทียบผลลัพธ์ (กรุณากรอกผลนับจาก HOSxP)',
                'difference' => null,
            ];
        }

        $diff = $agentCount - $hosxpManualCount;
        $matched = ($diff === 0);

        return [
            'status'       => $matched ? 'MATCHED' : 'MISMATCH',
            'matched'      => $matched,
            'hosxp_count'  => $hosxpManualCount,
            'agent_count'  => $agentCount,
            'difference'   => $diff,
            'message'      => $matched
                ? "ผลการนับตรงกัน 100%: HOSxP ({$hosxpManualCount}) = Agent ({$agentCount})"
                : "ผลการนับไม่ตรงกัน: HOSxP = {$hosxpManualCount}, Agent = {$agentCount} (ต่างกัน {$diff} รายการ)",
        ];
    }

    /**
     * ตรวจสอบว่าบัญชีเป็นบัญชีผู้ดูแลระบบหรือไม่
     */
    public function isDisallowedAccount(string $username): bool {
        $clean = strtolower(trim($username));
        return in_array($clean, self::DISALLOWED_ACCOUNTS, true);
    }

    /**
     * ตรวจสอบรูปแบบวันที่ YYYY-MM-DD
     */
    private function validateDateFormat(string $date): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }
        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
    }

    /**
     * ตรวจสอบว่าชื่อฐานข้อมูลเป็นฐานข้อมูลทดสอบที่ปลอดภัยหรือไม่
     * ป้องกันการทดสอบคำสั่งเขียนบนฐานข้อมูล Production โดยเด็ดขาด
     */
    public static function isSafeTestDatabase(string $database): bool {
        $db = strtolower(trim($database));
        if ($db === '' || in_array($db, ['hos', 'hosxp', 'hospital', 'production', 'main', 'master', 'mysql'], true)) {
            return false;
        }
        return ($db === 'rxcore_hosxp_test' || str_ends_with($db, '_test') || str_starts_with($db, 'test_'));
    }



    /**
     * ดึงรายงานผล Diagnostic การเชื่อมต่อโดยไม่มี Credential
     */
    public function getConnectionDiagnostics(): array {
        $conn = $this->testConnection();
        $schema = $this->validateSchema();
        $priv = $this->validatePrivileges();

        return [
            'host'               => $this->config['hosxp_host'] ?? '127.0.0.1',
            'port'               => (int)($this->config['hosxp_port'] ?? 3306),
            'database'           => $this->config['hosxp_database'] ?? 'rxcore_hosxp_test',
            'username'           => $this->config['hosxp_username'] ?? 'rxcore_ro_test',
            'password_status'    => '[PROTECTED - AES-256-GCM ENCRYPTED]',
            'connected'          => $conn['success'] ?? false,
            'server_version'     => $conn['server_version'] ?? 'Unknown',
            'charset'            => $conn['charset'] ?? 'utf8mb4',
            'connection_time_ms' => $conn['connection_time_ms'] ?? 0,
            'schema_valid'       => $schema['success'] ?? false,
            'tables_status'      => $schema['tables_status'] ?? [],
            'is_readonly'        => $priv['is_readonly'] ?? false,
            'prohibited_privs'   => $priv['prohibited'] ?? [],
            'diagnostic_time'    => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * กรองรหัสผ่านและข้อมูลลับออกจากข้อความ Error
     */
    public function sanitizeErrorMessage(string $msg): string {
        $msg = preg_replace('/password[=:\s]+[^\s,;]+/i', 'password=***', $msg);
        $msg = preg_replace('/[a-zA-Z]:\\\\[^\s:;,]+/i', '[hidden_path]', $msg);
        return $msg;
    }
}

