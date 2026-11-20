<?php
/**
 * RxCore Sync Agent — Mock API Server & Local Test Adapter
 * Phase 3.5 & 3.5.1: Supabase API Integration & Security Audit
 * 
 * จำลองการทำงานของ RxCore Server API และ Supabase RPC fn_sync_dispense_batch
 * สำหรับการทดสอบความปลอดภัยและการทำงานภายในเครื่องเท่านั้น
 * 
 * ข้อกำหนดและเงื่อนไขความปลอดภัย (Phase 3.5.1 Security Audit):
 * 1. MOCK ONLY: ทำงานภายในเครื่องเท่านั้น ไม่มีการเชื่อมต่อ Supabase จริง
 * 2. Multi-tenant Isolation: ตรวจสอบ SHA-256 API Key Hash ผูกกับสถานพยาบาล
 * 3. ห้ามข้ามเขต: รพ.สต. หนึ่ง (01425) ไม่สามารถส่งหรือเขียนข้อมูลของอีกแห่ง (01426)
 * 4. Composite Key: ใช้ (hcode, hos_guid) ป้องกันข้อมูลซ้ำ (Upsert Idempotent)
 * 5. Data Whitelist & Privacy: รองรับเฉพาะ 6 ฟิลด์ และปฏิเสธข้อมูล PII (HN, CID)
 * 6. รองรับการจำลอง HTTP Status: 200, 400, 401, 403, 409, 413, 429, 500, Timeout, Malformed JSON
 * 7. Rate Limit & Request Size Defense (5 MB / 1,000 records per batch)
 */

class AgentMockApiServer {

    /**
     * ขีดจำกัดความปลอดภัยของ Payload และ Batch
     */
    public const MAX_PAYLOAD_BYTES = 5242880; // 5 MB
    public const MAX_BATCH_RECORDS = 1000;    // 1,000 records per request

    /**
     * ทะเบียนสถานพยาบาลจำลอง พร้อม SHA-256 API Key Hash (อิงตาม schema.sql และ test_schema.sql)
     */
    private array $healthCenters = [
        '01425' => [
            'name'           => 'รพ.สต. ทดสอบ ดอนทอง',
            'api_key_plain'  => 'key_secret_01425_abc123',
            'api_key_hash'   => '44b94f6f4369dae1329c424072bf4eaee24f74fe1db26116826189917329486c', // hash('sha256', 'key_secret_01425_abc123')
            'api_key_prefix' => 'rxk_test_01425_',
            'is_active'      => true,
        ],
        '01426' => [
            'name'           => 'รพ.สต. ทดสอบ หนองบัว',
            'api_key_plain'  => 'key_secret_01426_xyz789',
            'api_key_hash'   => '5f71e16f3942fcb0d5c079db89a7bb918fe4ec3c22fc4747eb1052608c028292', // hash('sha256', 'key_secret_01426_xyz789')
            'api_key_prefix' => 'rxk_test_01426_',
            'is_active'      => true,
        ],
    ];

    /**
     * ทะเบียนยาจำลอง (อิงตาม rx_drug_lookup ใน test_schema.sql)
     */
    private array $drugLookup = [
        '100789000012345678901001' => [
            'drug_name'    => 'Paracetamol 500 mg tab',
            'generic_name' => 'Paracetamol',
            'trade_name'   => 'Sara',
            'unit'         => 'เม็ด',
            'is_active'    => true,
        ],
        '100789000012345678901002' => [
            'drug_name'    => 'Free Vaccine Dose',
            'generic_name' => 'Influenza Vaccine',
            'trade_name'   => 'Gov-Free',
            'unit'         => 'ขวด',
            'is_active'    => true,
        ],
        '100789000012345678901003' => [
            'drug_name'    => 'Future Cost Drug 10mg',
            'generic_name' => 'Amlodipine',
            'trade_name'   => 'Amlopin',
            'unit'         => 'เม็ด',
            'is_active'    => true,
        ],
        '100789000012345678901004' => [
            'drug_name'    => 'Banned / Inactive Medicine',
            'generic_name' => 'Old Drug',
            'trade_name'   => 'Discontinued',
            'unit'         => 'เม็ด',
            'is_active'    => false, // ปิดการใช้งานเพื่อทดสอบการบล็อก
        ],
    ];

    /**
     * ฐานข้อมูลการจ่ายยาจำลองในหน่วยความจำ เก็บด้วย Composite Key (hcode:hos_guid)
     */
    private array $dispenseStore = [];

    /**
     * บันทึก Sync Log จำลอง
     */
    private array $syncLogs = [];
    private int $nextLogId = 1000;

    /**
     * ฟิลด์ต้องห้าม (PII / Privacy Violation)
     */
    public const FORBIDDEN_PII_FIELDS = [
        'hn', 'cid', 'patient_name', 'name', 'fname', 'lname',
        'idcard', 'citizen_id', 'id_card', 'national_id', 'address', 'tel', 'phone'
    ];

    /**
     * ฟิลด์ที่ได้รับอนุญาตเท่านั้น (Data Whitelist 6 ฟิลด์)
     */
    public const ALLOWED_FIELDS = [
        'hos_guid', 'vn', 'hcode', 'vstdate', 'tpu_code_list', 'qty'
    ];

    public function __construct() {
        // คำนวณ SHA-256 hash สำหรับทะเบียนสถานพยาบาลอัตโนมัติ
        foreach ($this->healthCenters as $code => &$info) {
            $info['api_key_hash'] = hash('sha256', $info['api_key_plain']);
        }
    }

    /**
     * คืนค่ารายการยาจำลอง
     */
    public function getDrugLookup(): array {
        return $this->drugLookup;
    }

    /**
     * คืนค่าฐานข้อมูลจ่ายยาจำลอง
     */
    public function getDispenseStore(): array {
        return $this->dispenseStore;
    }

    /**
     * ล้างฐานข้อมูลจำลอง (สำหรับ Reset ในการทดสอบ)
     */
    public function clearStore(): void {
        $this->dispenseStore = [];
        $this->syncLogs = [];
    }

    /**
     * ยืนยันตัวตน API Key ด้วย SHA-256 เทียบกับทะเบียนสถานพยาบาล
     * เทียบเท่ากับ public.fn_verify_health_center_api_key(p_hcode, p_plain_api_key)
     * 
     * @return array [bool $authenticated, ?string $authenticatedHcode, ?string $error]
     */
    public function verifyApiKey(?string $plainApiKey, ?string $requestedHcode = null): array {
        if (empty($plainApiKey)) {
            return [false, null, 'Missing API Key in Authorization header or payload'];
        }

        // ตัด 'Bearer ' prefix ออกหากมี
        if (str_starts_with($plainApiKey, 'Bearer ')) {
            $plainApiKey = substr($plainApiKey, 7);
        }

        $incomingHash = hash('sha256', trim($plainApiKey));

        // ตรวจสอบว่า Hash ตรงกับสถานพยาบาลใดในระบบ
        $matchedHcode = null;
        foreach ($this->healthCenters as $hcode => $center) {
            if ($center['is_active'] && hash_equals($center['api_key_hash'], $incomingHash)) {
                $matchedHcode = $hcode;
                break;
            }
        }

        if ($matchedHcode === null) {
            return [false, null, 'Authentication Failed: Invalid API Key or inactive health center'];
        }

        // ตรวจสอบ Multi-tenant Isolation: หากมีการระบุ requestedHcode ต้องตรงกัน
        if ($requestedHcode !== null && $requestedHcode !== '' && $requestedHcode !== $matchedHcode) {
            return [false, $matchedHcode, "Forbidden: API Key belongs to facility '{$matchedHcode}', but request attempts to access facility '{$requestedHcode}' (Cross-tenant violation)"];
        }

        return [true, $matchedHcode, null];
    }

    /**
     * ประมวลผลคำขอ Sync ข้อมูลจ่ายยา (จำลอง Supabase RPC fn_sync_dispense_batch)
     * 
     * @param string|null $plainApiKey API Key แบบ plaintext
     * @param string|null $payloadJson ข้อความ JSON ที่ส่งมา
     * @param array $headers Headers เช่น X-Mock-Scenario
     * @return array [int $httpCode, array $responseBody, array $responseHeaders]
     */
    public function handleRequest(?string $plainApiKey, ?string $payloadJson, array $headers = []): array {
        $scenario = $headers['X-Mock-Scenario'] ?? $headers['x-mock-scenario'] ?? null;

        // 1. จำลองสถานการณ์บังคับ (Forced Scenarios) สำหรับ Automated Tests
        if ($scenario === 'force_500') {
            return [500, [
                'success'    => false,
                'error_code' => 'INTERNAL_SERVER_ERROR',
                'message'    => 'Mock Server Error: Database connection deadlock in Supabase backend (Simulated)',
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        if ($scenario === 'force_429') {
            return [429, [
                'success'     => false,
                'error_code'  => 'RATE_LIMIT_EXCEEDED',
                'message'     => 'Too Many Requests: Rate limit exceeded (Max 60 requests/minute). Please retry after 30 seconds.',
                'retry_after' => 30,
                'mock_mode'   => true,
            ], ['Content-Type' => 'application/json', 'Retry-After' => '30']];
        }

        if ($scenario === 'force_409') {
            return [409, [
                'success'    => false,
                'error_code' => 'CONFLICT',
                'message'    => 'Conflict: Sync batch version mismatch or concurrent sync operation in progress for facility.',
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        if ($scenario === 'force_timeout') {
            // จำลอง Server ไม่ตอบสนอง (Timeout)
            return [504, [
                'success'    => false,
                'error_code' => 'GATEWAY_TIMEOUT',
                'message'    => 'Gateway Timeout: Mock upstream service did not respond within timeout window.',
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        if ($scenario === 'force_connection_refused') {
            // จำลอง Connection Refused (Port ปิดหรือไม่สามารถเชื่อมต่อได้)
            return [0, [
                'success'               => false,
                'error_code'            => 'CONNECTION_REFUSED',
                'message'               => 'Connection Refused: Target server port is closed or service is down (Simulated)',
                'is_connection_refused' => true,
                'is_timeout'            => false,
                'mock_mode'             => true,
            ], []];
        }

        if ($scenario === 'force_malformed_json') {
            // ส่ง Response ที่ไม่ใช่ JSON
            return [200, "<html><body>502 Bad Gateway - Cloudflare Mock Gateway Error</body></html>", ['Content-Type' => 'text/html']];
        }

        // 2. ตรวจสอบขนาด Payload (Payload Size Limit: 5 MB)
        if ($payloadJson !== null && strlen($payloadJson) > self::MAX_PAYLOAD_BYTES) {
            return [413, [
                'success'    => false,
                'error_code' => 'PAYLOAD_TOO_LARGE',
                'message'    => 'Payload Too Large: Request body exceeds maximum allowed size of 5 MB (5,242,880 bytes)',
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        // 3. ตรวจสอบความถูกต้องของ JSON Syntax
        if ($payloadJson === null || trim($payloadJson) === '') {
            return [400, [
                'success'    => false,
                'error_code' => 'EMPTY_PAYLOAD',
                'message'    => 'Bad Request: Payload body is empty',
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        $data = json_decode($payloadJson, true);
        if (!is_array($data)) {
            return [400, [
                'success'    => false,
                'error_code' => 'INVALID_JSON',
                'message'    => 'Bad Request: Malformed JSON syntax in request body',
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        // 4. ตรวจสอบ API Key Authentication ฝั่ง Server (Multi-tenant Primary Anchor)
        [$authOk, $authenticatedHcode, $authError] = $this->verifyApiKey($plainApiKey);
        if (!$authOk) {
            return [401, [
                'success'    => false,
                'error_code' => 'UNAUTHORIZED',
                'message'    => $authError ?: 'Authentication Failed: Invalid API Key',
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        // 5. ตรวจสอบ Root Payload hcode (ไม่ส่ง / ส่งว่าง / รูปแบบผิด / ปลอม hcode)
        if (!array_key_exists('hcode', $data) || $data['hcode'] === null || !is_scalar($data['hcode']) || trim((string)$data['hcode']) === '') {
            return [400, [
                'success'    => false,
                'error_code' => 'MISSING_OR_EMPTY_HCODE',
                'message'    => "Bad Request: Root payload field 'hcode' is missing or empty",
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        $targetHcode = trim((string)$data['hcode']);
        if (strlen($targetHcode) !== 5 || !ctype_digit($targetHcode)) {
            return [400, [
                'success'    => false,
                'error_code' => 'INVALID_HCODE_FORMAT',
                'message'    => "Bad Request: Root 'hcode' must be exactly 5 numeric digits (chk_hcode_len constraint)",
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        // Multi-tenant Isolation Check: hcode ของ Root Payload ต้องตรงกับสถานพยาบาลที่ผูกกับ API Key
        if ($targetHcode !== $authenticatedHcode) {
            return [403, [
                'success'    => false,
                'error_code' => 'FORBIDDEN_CROSS_TENANT',
                'message'    => "Forbidden: API Key belongs to facility '{$authenticatedHcode}', but request specifies facility '{$targetHcode}' (Cross-tenant violation / Spoofed hcode)",
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        $records = $data['records'] ?? null;

        // 6. ตรวจสอบโครงสร้าง records และขีดจำกัดขนาด Batch (Batch Record Limit: 1,000)
        if (!is_array($records)) {
            return [400, [
                'success'    => false,
                'error_code' => 'INVALID_RECORDS_ARRAY',
                'message'    => "Bad Request: 'records' field must be a valid array of dispense items",
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        if (count($records) > self::MAX_BATCH_RECORDS) {
            return [413, [
                'success'    => false,
                'error_code' => 'BATCH_SIZE_EXCEEDED',
                'message'    => "Batch Size Exceeded: Number of records (" . count($records) . ") exceeds maximum limit of " . self::MAX_BATCH_RECORDS . " records per request",
                'mock_mode'  => true,
            ], ['Content-Type' => 'application/json']];
        }

        // 7. ตรวจสอบ Privacy, Whitelist และ Data Integrity ในแต่ละ Record
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        foreach ($records as $idx => $rec) {
            if (!is_array($rec)) {
                return [400, [
                    'success'    => false,
                    'error_code' => 'INVALID_RECORD_FORMAT',
                    'message'    => "Bad Request: Record at index {$idx} is not an object",
                    'mock_mode'  => true,
                ], ['Content-Type' => 'application/json']];
            }

            // 7.1 ตรวจจับฟิลด์ต้องห้าม (PII Detection)
            foreach (self::FORBIDDEN_PII_FIELDS as $forbidden) {
                if (array_key_exists($forbidden, $rec)) {
                    return [400, [
                        'success'    => false,
                        'error_code' => 'PRIVACY_VIOLATION',
                        'message'    => "Privacy Security Violation: Record at index {$idx} contains forbidden PII field '{$forbidden}'. Only 6 whitelisted fields permitted.",
                        'mock_mode'  => true,
                    ], ['Content-Type' => 'application/json']];
                }
            }

            // 7.2 ตรวจจับฟิลด์เกินข้อกำหนด (Extra Unauthorized Fields)
            foreach (array_keys($rec) as $field) {
                if (!in_array($field, self::ALLOWED_FIELDS, true)) {
                    return [400, [
                        'success'    => false,
                        'error_code' => 'UNAUTHORIZED_FIELD',
                        'message'    => "Schema Violation: Record at index {$idx} contains unrecognized field '{$field}'. Allowed fields: " . implode(', ', self::ALLOWED_FIELDS),
                        'mock_mode'  => true,
                    ], ['Content-Type' => 'application/json']];
                }
            }

            // 7.3 ตรวจสอบความครบถ้วนของ 6 ฟิลด์จำเป็น (Missing Required Fields)
            foreach (self::ALLOWED_FIELDS as $reqField) {
                if (!array_key_exists($reqField, $rec) || $rec[$reqField] === null || (is_scalar($rec[$reqField]) && trim((string)$rec[$reqField]) === '')) {
                    return [400, [
                        'success'    => false,
                        'error_code' => 'MISSING_REQUIRED_FIELD',
                        'message'    => "Validation Error: Record at index {$idx} is missing required field '{$reqField}'",
                        'mock_mode'  => true,
                    ], ['Content-Type' => 'application/json']];
                }
            }

            // 7.4 ตรวจสอบ Multi-tenant hcode ในระดับ Record (ห้ามปลอมแปลง hcode ภายใน Record)
            if ($rec['hcode'] !== $authenticatedHcode) {
                $recHcodeDisplay = is_scalar($rec['hcode']) ? (string)$rec['hcode'] : gettype($rec['hcode']);
                return [403, [
                    'success'    => false,
                    'error_code' => 'FORBIDDEN_CROSS_TENANT',
                    'message'    => "Security Violation: Record at index {$idx} has spoofed hcode '{$recHcodeDisplay}' which does not match authenticated facility '{$authenticatedHcode}'",
                    'mock_mode'  => true,
                ], ['Content-Type' => 'application/json']];
            }

            // 7.5 ตรวจสอบชนิดข้อมูลและค่าของ qty (ต้อง > 0 และเป็นตัวเลขเท่านั้น)
            if (is_bool($rec['qty']) || !is_numeric($rec['qty']) || (float)$rec['qty'] <= 0 || (is_string($rec['qty']) && !preg_match('/^\d+(\.\d+)?$/', trim($rec['qty'])))) {
                $qtyDisplay = is_scalar($rec['qty']) ? (string)$rec['qty'] : gettype($rec['qty']);
                return [400, [
                    'success'    => false,
                    'error_code' => 'INVALID_QTY',
                    'message'    => "Validation Error: Record at index {$idx} has invalid qty '{$qtyDisplay}'. Qty must be numeric and > 0 (CHECK constraint)",
                    'mock_mode'  => true,
                ], ['Content-Type' => 'application/json']];
            }

            // 7.6 ตรวจสอบรูปแบบและความถูกต้องของ vstdate (YYYY-MM-DD + Calendar Date + Future Date Check)
            if (!is_string($rec['vstdate']) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rec['vstdate'], $dMatches)) {
                $vstdateDisplay = is_scalar($rec['vstdate']) ? (string)$rec['vstdate'] : gettype($rec['vstdate']);
                return [400, [
                    'success'    => false,
                    'error_code' => 'INVALID_VSTDATE',
                    'message'    => "Validation Error: Record at index {$idx} has invalid vstdate format '{$vstdateDisplay}'. Expected format: YYYY-MM-DD",
                    'mock_mode'  => true,
                ], ['Content-Type' => 'application/json']];
            }

            $year  = (int)$dMatches[1];
            $month = (int)$dMatches[2];
            $day   = (int)$dMatches[3];

            if (!checkdate($month, $day, $year)) {
                return [400, [
                    'success'    => false,
                    'error_code' => 'INVALID_VSTDATE',
                    'message'    => "Validation Error: Record at index {$idx} has non-existent calendar date '{$rec['vstdate']}'",
                    'mock_mode'  => true,
                ], ['Content-Type' => 'application/json']];
            }

            if ((string)$rec['vstdate'] > $tomorrow) {
                return [400, [
                    'success'    => false,
                    'error_code' => 'FUTURE_DATE_EXCEEDED',
                    'message'    => "Validation Error: Record at index {$idx} has vstdate '{$rec['vstdate']}' exceeding allowable future date threshold (+1 day) per chk_dispense_vstdate_valid constraint",
                    'mock_mode'  => true,
                ], ['Content-Type' => 'application/json']];
            }
        }

        // 8. ประมวลผล RPC fn_sync_dispense_batch (Upsert + ตรวจสอบ is_active ของยา)
        $logId = ++$this->nextLogId;
        $insertedOrUpdated = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($records as $rec) {
            $hosGuid = $rec['hos_guid'];
            $tpuCode = $rec['tpu_code_list'];
            // ใช้ Composite Key (hcode:hos_guid) เพื่อป้องกันรายการซ้ำและแยกข้อมูลตามสถานพยาบาล
            $compKey = $authenticatedHcode . ':' . $hosGuid;

            // 8.1 ตรวจสอบสถานะยาในทะเบียนกลาง (rx_drug_lookup)
            if (!isset($this->drugLookup[$tpuCode])) {
                $failedCount++;
                $errors[] = [
                    'hos_guid'      => $hosGuid,
                    'tpu_code_list' => $tpuCode,
                    'error_code'    => 'DRUG_NOT_FOUND',
                    'reason'        => 'ไม่พบรหัสยาในทะเบียนกลาง (Foreign Key / Drug Lookup Failure)',
                ];
                continue;
            }

            if ($this->drugLookup[$tpuCode]['is_active'] === false) {
                $failedCount++;
                $errors[] = [
                    'hos_guid'      => $hosGuid,
                    'tpu_code_list' => $tpuCode,
                    'error_code'    => 'DRUG_INACTIVE',
                    'reason'        => 'รหัสยานี้ถูกปิดใช้งานในทะเบียนกลาง (is_active = FALSE) ไม่อนุญาตให้จ่ายยา',
                ];
                continue;
            }

            // 8.2 Upsert ลงในฐานข้อมูลจำลอง (Composite Key hcode, hos_guid)
            // บันทึกโดยอ้างอิง $authenticatedHcode ฝั่ง Server เสมอ (ไม่เชื่อ hcode จาก Client โดยตรง)
            $isUpdate = isset($this->dispenseStore[$compKey]);
            $this->dispenseStore[$compKey] = [
                'hcode'         => $authenticatedHcode,
                'hos_guid'      => $hosGuid,
                'vn'            => $rec['vn'],
                'vstdate'       => $rec['vstdate'],
                'tpu_code_list' => $tpuCode,
                'qty'           => (float)$rec['qty'],
                'is_deleted'    => false,
                'synced_at'     => date('Y-m-d H:i:s'),
                'is_update'     => $isUpdate,
            ];
            $insertedOrUpdated++;
        }

        // 9. บันทึกผลลัพธ์ลง Sync Log จำลอง
        $syncStatus = ($failedCount === 0) ? 'success' : (($insertedOrUpdated > 0) ? 'partial' : 'failed');
        $this->syncLogs[$logId] = [
            'id'                  => $logId,
            'hcode'               => $authenticatedHcode,
            'sync_status'         => $syncStatus,
            'total_records'       => count($records),
            'inserted_or_updated' => $insertedOrUpdated,
            'failed'              => $failedCount,
            'errors'              => $errors,
            'created_at'          => date('Y-m-d H:i:s'),
        ];

        // 10. ตอบกลับโครงสร้าง JSON ที่สอดคล้องกับ RPC fn_sync_dispense_batch
        return [200, [
            'success'             => true,
            'hcode'               => $authenticatedHcode,
            'inserted_or_updated' => $insertedOrUpdated,
            'deleted'             => 0,
            'failed'              => $failedCount,
            'errors'              => $errors,
            'sync_log_id'         => $logId,
            'mock_mode'           => true,
        ], ['Content-Type' => 'application/json']];
    }
}

// ------------------------------------------------------------------------------
// HTTP Request Dispatcher: หากเรียกไฟล์นี้โดยตรงผ่าน HTTP Web Server
// ------------------------------------------------------------------------------
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'agent_mock_server.php') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Mock-Scenario');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'    => false,
            'error_code' => 'METHOD_NOT_ALLOWED',
            'message'    => 'Method Not Allowed. Sync endpoint accepts POST only.',
            'mock_mode'  => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ดึง API Key จาก Header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $scenarioHeader = $_SERVER['HTTP_X_MOCK_SCENARIO'] ?? '';

    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (empty($authHeader)) {
            $authHeader = $all['Authorization'] ?? $all['authorization'] ?? $all['X-API-Key'] ?? $all['x-api-key'] ?? '';
        }
        if (empty($scenarioHeader)) {
            $scenarioHeader = $all['X-Mock-Scenario'] ?? $all['x-mock-scenario'] ?? '';
        }
    }

    $rawInput = file_get_contents('php://input');
    $server = new AgentMockApiServer();

    // รวบรวม Headers
    $headers = [
        'X-Mock-Scenario' => $scenarioHeader,
    ];

    [$httpCode, $body, $resHeaders] = $server->handleRequest($authHeader, $rawInput, $headers);

    http_response_code($httpCode);
    foreach ($resHeaders as $k => $v) {
        header("{$k}: {$v}");
    }

    if (is_array($body)) {
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo (string)$body;
    }
    exit;
}
