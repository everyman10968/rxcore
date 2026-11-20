<?php
/**
 * RxCore — MOPH Notify (หมอพร้อม) Integration Service
 * รองรับ:
 *  1. แจ้งเตือนผลการ Sync จากหน่วยบริการ (รพ.แม่ข่าย / รพ.สต.) ผ่าน rx_sync_log
 *  2. แจ้งเตือนสรุปประจำวันทั้งเครือข่าย (เช่น 17:30 น.)
 *
 * อ้างอิงสเปค: moph_notify_api_guide.txt
 * Endpoint: POST https://morpromt2f.moph.go.th/api/notify/send
 */

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/app.php';
}
require_once __DIR__ . '/supabase.php';

class MophNotifyService {

    public const API_ENDPOINT = 'https://morpromt2f.moph.go.th/api/notify/send';
    private const CONFIG_FILE = __DIR__ . '/../config/moph_notify.json';
    private const DB_KEY = 'moph_notify';

    /**
     * โหลดการตั้งค่า MOPH Notify (รวม fallback จาก Supabase และ .env)
     */
    public static function getConfig(): array {
        $defaults = [
            'enabled'           => false,
            'client_key'        => (string)env('MOPH_CLIENT_KEY', ''),
            'secret_key'        => (string)env('MOPH_SECRET_KEY', ''),
            'notify_on_success' => true,
            'notify_on_failure' => true,
            'daily_summary'     => true,
            'updated_at'        => null,
        ];

        $decoded = null;
        if (SupabaseService::isConfigured()) {
            $res = SupabaseService::request('GET', 'rx_app_settings?select=value&key=eq.' . self::DB_KEY . '&limit=1');
            if ($res['ok'] && !empty($res['data'][0]['value']) && is_array($res['data'][0]['value'])) {
                $decoded = $res['data'][0]['value'];
            }
        }

        if ($decoded === null && file_exists(self::CONFIG_FILE)) {
            $raw = @file_get_contents(self::CONFIG_FILE);
            $fileDecoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($fileDecoded)) {
                $decoded = $fileDecoded;
            }
        }

        if (is_array($decoded)) {
            foreach ($defaults as $k => $v) {
                if (array_key_exists($k, $decoded)) {
                    $defaults[$k] = $decoded[$k];
                }
            }
        }

        if ($defaults['client_key'] === '') {
            $defaults['client_key'] = (string)env('MOPH_CLIENT_KEY', '');
        }
        if ($defaults['secret_key'] === '') {
            $defaults['secret_key'] = (string)env('MOPH_SECRET_KEY', '');
        }

        return $defaults;
    }

    /**
     * คืนค่าการตั้งค่าแบบ Mask Key สำหรับแสดงผลบนหน้า UI
     */
    public static function getSafeConfig(): array {
        $cfg = self::getConfig();
        return [
            'enabled'            => !empty($cfg['enabled']),
            'has_client_key'     => trim((string)$cfg['client_key']) !== '',
            'has_secret_key'     => trim((string)$cfg['secret_key']) !== '',
            'client_key_masked'  => self::maskKey((string)$cfg['client_key']),
            'secret_key_masked'  => self::maskKey((string)$cfg['secret_key']),
            'notify_on_success'  => !empty($cfg['notify_on_success']),
            'notify_on_failure'  => !empty($cfg['notify_on_failure']),
            'daily_summary'      => !empty($cfg['daily_summary']),
            'updated_at'         => $cfg['updated_at'],
        ];
    }

    /**
     * บันทึกการตั้งค่า MOPH Notify ลงไฟล์ config/moph_notify.json
     */
    public static function saveConfig(array $input): bool {
        $current = self::getConfig();

        $newClientKey = trim((string)($input['client_key'] ?? ''));
        $newSecretKey = trim((string)($input['secret_key'] ?? ''));

        $toSave = [
            'enabled'           => !empty($input['enabled']),
            'client_key'        => $newClientKey !== '' ? $newClientKey : $current['client_key'],
            'secret_key'        => $newSecretKey !== '' ? $newSecretKey : $current['secret_key'],
            'notify_on_success' => !empty($input['notify_on_success']),
            'notify_on_failure' => !empty($input['notify_on_failure']),
            'daily_summary'     => !empty($input['daily_summary']),
            'updated_at'        => date('Y-m-d H:i:s'),
        ];

        $dbSaved = false;
        if (SupabaseService::isConfigured()) {
            $res = SupabaseService::insert('rx_app_settings', [
                'key'        => self::DB_KEY,
                'value'      => $toSave,
                'updated_at' => gmdate('c'),
            ], 'key');
            $dbSaved = !empty($res['ok']);
        }

        $fileSaved = false;
        $dir = dirname(self::CONFIG_FILE);
        if (is_writable($dir) || (!is_dir($dir) && @mkdir($dir, 0755, true))) {
            $fileSaved = @file_put_contents(
                self::CONFIG_FILE,
                json_encode($toSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ) !== false;
        }

        return $dbSaved || $fileSaved;
    }

    public static function maskKey(string $key): string {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('*', max(4, $len));
        }
        return substr($key, 0, 4) . '••••••••' . substr($key, -4);
    }

    /**
     * ส่งข้อความแจ้งเตือนผ่าน MOPH Notify API
     *
     * @return array{ok: bool, status: int, response: string, error: string}
     */
    public static function send(string $message, ?string $clientKey = null, ?string $secretKey = null): array {
        $cfg = self::getConfig();
        $cKey = trim((string)($clientKey ?? $cfg['client_key']));
        $sKey = trim((string)($secretKey ?? $cfg['secret_key']));

        if ($cKey === '' || $sKey === '') {
            return [
                'ok'       => false,
                'status'   => 0,
                'response' => '',
                'error'    => 'ยังไม่ได้ตั้งค่า Client Key หรือ Secret Key ของ MOPH Notify',
            ];
        }

        $message = trim($message);
        if ($message === '') {
            return [
                'ok'       => false,
                'status'   => 0,
                'response' => '',
                'error'    => 'ข้อความแจ้งเตือนต้องไม่เป็นค่าว่าง',
            ];
        }

        $payload = json_encode([
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::API_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false, // ตามคู่มือ moph_notify_api_guide.txt สำหรับเซิร์ฟเวอร์ สธ.
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
     * 1. ตรวจสอบ rx_sync_log ที่ยังไม่ได้แจ้งเตือน (pending) และส่งสรุปผลแยกรายหน่วยบริการ
     *    รวมหลาย batch ที่ส่งติดกันจากหน่วยบริการเดียวกันเป็น 1 ข้อความ เพื่อไม่ให้แจ้งเตือนถี่เกินไป
     *
     * @return array{ok: bool, processed: int, sent_groups: int, skipped_groups: int, failed_groups: int, message: string}
     */
    public static function processPendingSyncLogs(int $limit = 100): array {
        $cfg = self::getConfig();
        if (empty($cfg['enabled'])) {
            return [
                'ok'             => false,
                'processed'      => 0,
                'sent_groups'    => 0,
                'skipped_groups' => 0,
                'failed_groups'  => 0,
                'message'        => 'ระบบแจ้งเตือน MOPH Notify ปิดใช้งานอยู่',
            ];
        }

        $logs = SupabaseService::select(
            'rx_sync_log',
            'select=id,hcode,sync_status,total_records,success_records,failed_records,sync_started_at,sync_completed_at'
            . '&moph_notify_status=eq.pending&sync_status=neq.running&order=id.asc&limit=' . $limit
        );

        if (empty($logs)) {
            return [
                'ok'             => true,
                'processed'      => 0,
                'sent_groups'    => 0,
                'skipped_groups' => 0,
                'failed_groups'  => 0,
                'message'        => 'ไม่มีรายการ Sync ใหม่ที่รอแจ้งเตือน',
            ];
        }

        // ดึงชื่อหน่วยบริการ
        $hcs = SupabaseService::select('rx_health_centers', 'select=hcode,name');
        $hcMap = [];
        foreach ($hcs as $hc) {
            $hcMap[$hc['hcode']] = $hc['name'];
        }

        // จัดกลุ่มตาม hcode เพื่อรวม batch ที่มาพร้อมกันเป็น 1 ข้อความต่อหน่วยบริการ
        $grouped = [];
        foreach ($logs as $log) {
            $h = (string)($log['hcode'] ?? '');
            if ($h === '') {
                continue;
            }
            if (!isset($grouped[$h])) {
                $grouped[$h] = [
                    'hcode'       => $h,
                    'name'        => $hcMap[$h] ?? "หน่วยบริการ {$h}",
                    'ids'         => [],
                    'total'       => 0,
                    'success'     => 0,
                    'failed'      => 0,
                    'has_failure' => false,
                    'last_at'     => $log['sync_completed_at'] ?? $log['sync_started_at'],
                ];
            }
            $grouped[$h]['ids'][]    = (int)$log['id'];
            $grouped[$h]['total']   += (int)($log['total_records'] ?? 0);
            $grouped[$h]['success'] += (int)($log['success_records'] ?? 0);
            $grouped[$h]['failed']  += (int)($log['failed_records'] ?? 0);
            if (($log['sync_status'] ?? '') !== 'success' || (int)($log['failed_records'] ?? 0) > 0) {
                $grouped[$h]['has_failure'] = true;
            }
            if (!empty($log['sync_completed_at'])) {
                $grouped[$h]['last_at'] = $log['sync_completed_at'];
            }
        }

        $sentGroups = 0;
        $skippedGroups = 0;
        $failedGroups = 0;
        $processed = 0;

        foreach ($grouped as $g) {
            $processed += count($g['ids']);
            $shouldSend = $g['has_failure'] ? !empty($cfg['notify_on_failure']) : !empty($cfg['notify_on_success']);

            if (!$shouldSend) {
                self::updateSyncLogNotifyStatus($g['ids'], 'skipped', null);
                $skippedGroups++;
                continue;
            }

            $timeTh = !empty($g['last_at']) ? date('d/m/Y H:i', strtotime($g['last_at'])) : date('d/m/Y H:i');
            $icon = $g['has_failure'] ? '⚠️' : '✅';
            $statusTxt = $g['has_failure'] ? 'ซิงก์มีข้อผิดพลาดบางส่วน/ล้มเหลว' : 'ซิงก์ข้อมูล OPD สำเร็จ';

            $lines = [
                "{$icon} RxCore Sync: {$g['name']} ({$g['hcode']})",
                "สถานะ: {$statusTxt}",
                'นำเข้าสำเร็จ: ' . number_format($g['success']) . ' รายการ'
                    . ($g['failed'] > 0 ? ' | ไม่ผ่าน: ' . number_format($g['failed']) . ' รายการ' : ''),
                "เวลา: {$timeTh} น.",
            ];
            $msg = implode("\n", $lines);

            $res = self::send($msg);
            if ($res['ok']) {
                self::updateSyncLogNotifyStatus($g['ids'], 'sent', $msg);
                $sentGroups++;
            } else {
                self::updateSyncLogNotifyStatus($g['ids'], 'failed', $res['error']);
                $failedGroups++;
            }
        }

        return [
            'ok'             => ($failedGroups === 0),
            'processed'      => $processed,
            'sent_groups'    => $sentGroups,
            'skipped_groups' => $skippedGroups,
            'failed_groups'  => $failedGroups,
            'message'        => "ตรวจ {$processed} รอบ: ส่งสำเร็จ {$sentGroups} กลุ่ม, ข้าม {$skippedGroups} กลุ่ม, ล้มเหลว {$failedGroups} กลุ่ม",
        ];
    }

    /**
     * อัปเดตสถานะ moph_notify_status ใน rx_sync_log ตามชุด ID
     *
     * @param int[] $ids
     */
    private static function updateSyncLogNotifyStatus(array $ids, string $status, ?string $message): void {
        if (empty($ids)) {
            return;
        }
        $idList = implode(',', array_map('intval', $ids));
        $payload = [
            'moph_notify_status'  => $status,
            'moph_notify_message' => $message,
        ];
        if ($status === 'sent') {
            $payload['moph_notify_sent_at'] = gmdate('c');
        }
        SupabaseService::update('rx_sync_log', 'id=in.(' . $idList . ')', $payload);
    }

    /**
     * 2. สร้างและส่งข้อความสรุปประจำวันทั้งเครือข่าย (เช่น 17:30 น.)
     *
     * @return array{ok: bool, text: string, error: string}
     */
    public static function sendDailySummary(?string $targetDate = null, bool $forceSend = false): array {
        $cfg = self::getConfig();
        if (!$forceSend && (empty($cfg['enabled']) || empty($cfg['daily_summary']))) {
            return [
                'ok'    => false,
                'text'  => '',
                'error' => 'การแจ้งเตือนสรุปประจำวันปิดใช้งานอยู่',
            ];
        }

        $today = $targetDate ?: date('Y-m-d');
        $thaiDate = date('d/m/', strtotime($today)) . ((int)date('Y', strtotime($today)) + 543);

        $hcs = SupabaseService::select('rx_health_centers', 'select=hcode,name,last_sync_at&is_active=eq.true&order=hcode.asc');
        $syncedToday = [];
        $notSyncedToday = [];

        foreach ($hcs as $hc) {
            $last = $hc['last_sync_at'] ?? null;
            $lastDateLocal = $last ? date('Y-m-d', strtotime($last)) : '';
            if ($lastDateLocal === $today) {
                $syncedToday[] = $hc['name'];
            } else {
                $notSyncedToday[] = $hc['name'];
            }
        }

        // ดึงข้อมูลจ่ายยาของวันนี้ (vstdate = today) แบบ paginate เผื่อเกิน 1,000 แถว
        $rows = [];
        $offset = 0;
        while (true) {
            $res = SupabaseService::request(
                'GET',
                'rx_dispense?select=hcode,vn,total_cost_applied&is_deleted=eq.false&vstdate=eq.' . urlencode($today),
                null,
                ['Range-Unit: items', "Range: {$offset}-" . ($offset + 999)]
            );
            if (!$res['ok'] || !is_array($res['data']) || empty($res['data'])) {
                break;
            }
            foreach ($res['data'] as $r) {
                $rows[] = $r;
            }
            if (count($res['data']) < 1000) {
                break;
            }
            $offset += 1000;
        }

        $vnSet = [];
        $totalCost = 0.0;
        foreach ($rows as $r) {
            $vnSet[($r['hcode'] ?? '') . '|' . ($r['vn'] ?? '')] = true;
            if ($r['total_cost_applied'] !== null) {
                $totalCost += (float)$r['total_cost_applied'];
            }
        }

        $totalCenters = count($hcs);
        $syncedCount = count($syncedToday);

        $lines = [
            "📊 สรุประบบจ่ายยา OPD เครือข่าย " . HOSPITAL_NAME,
            "📅 วันที่ {$thaiDate} (ข้อมูล ณ " . date('H:i') . " น.)",
            "🏥 หน่วยบริการซิงก์วันนี้: {$syncedCount}/{$totalCenters} แห่ง",
            "🧾 ใบสั่งยาวันนี้: " . number_format(count($vnSet)) . " ใบ (" . number_format(count($rows)) . " รายการ)",
            "💰 มูลค่าต้นทุนวันนี้: " . number_format($totalCost, 2) . " บาท",
        ];

        if (!empty($notSyncedToday)) {
            $lines[] = "⏳ ยังไม่ซิงก์วันนี้: " . implode(', ', $notSyncedToday);
        } else {
            $lines[] = "✅ ทุกหน่วยบริการซิงก์ข้อมูลครบแล้ว";
        }

        $text = implode("\n", $lines);
        $sendRes = self::send($text);

        return [
            'ok'    => $sendRes['ok'],
            'text'  => $text,
            'error' => $sendRes['error'],
        ];
    }
}
