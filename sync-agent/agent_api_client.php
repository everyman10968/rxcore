<?php
/**
 * RxCore Sync Agent — API Client Module
 * Phase 3.5: Supabase API Integration (Mock Endpoint Only)
 * 
 * โมดูล API Client สำหรับส่งข้อมูลการจ่ายยาไปยัง RxCore Server API / Mock Endpoint
 * 
 * ความปลอดภัยและข้อกำหนด:
 * 1. MOCK ENDPOINT ONLY: ใน Phase นี้เชื่อมต่อกับ Mock Endpoint ภายในเครื่องเท่านั้น ห้ามส่งข้อมูลออกอินเทอร์เน็ต
 * 2. TLS Security: ในการออกแบบสำหรับการใช้งานจริง จะไม่ปิดการตรวจสอบ TLS Certificate (SSL_VERIFYPEER = true)
 * 3. Secret Protection: ไม่มีการ Hardcode API Key ใน Source Code, Mask ค่า Key ใน Log และ Error ทั้งหมด
 * 4. Data Whitelist & Privacy: ตรวจสอบและอนุญาตเฉพาะ 6 ฟิลด์ (hos_guid, vn, hcode, vstdate, tpu_code_list, qty)
 *    และปฏิเสธข้อมูล PII (HN, CID, ชื่อผู้ป่วย) ทันทีตั้งแต่ฝั่ง Client (Pre-flight Validation)
 * 5. Composite Key Idempotence: ส่งข้อมูลแบบ Upsert เพื่อป้องกันรายการซ้ำ
 * 6. Multi-tenant Isolation: ส่งข้อมูลเฉพาะ hcode ของสถานพยาบาลที่ตนเองได้รับอนุญาต
 */

require_once __DIR__ . '/agent_mock_server.php';

class AgentApiClient {

    private string $endpointUrl;
    private string $apiKey;
    private int $timeout;
    private bool $tlsVerify;
    private $localAdapter; // ?callable

    /**
     * ขีดจำกัดความปลอดภัยของ Payload และ Batch
     */
    public const MAX_PAYLOAD_BYTES = 5242880; // 5 MB
    public const MAX_BATCH_RECORDS = 1000;    // 1,000 records per request

    /**
     * Data Whitelist 6 ฟิลด์ที่อนุญาตให้ส่งผ่าน API
     */
    public const ALLOWED_FIELDS = [
        'hos_guid',
        'vn',
        'hcode',
        'vstdate',
        'tpu_code_list',
        'qty',
    ];

    /**
     * ฟิลด์ข้อมูลส่วนบุคคล (PII) ที่ห้ามส่งออกเด็ดขาด
     */
    public const FORBIDDEN_PII_FIELDS = [
        'hn', 'cid', 'patient_name', 'name', 'fname', 'lname',
        'idcard', 'citizen_id', 'id_card', 'national_id', 'address', 'tel', 'phone'
    ];

    /**
     * @param string $endpointUrl URL ของ Endpoint (เช่น http://localhost/... หรือ mock://local)
     * @param string $apiKey Plaintext API Key สำหรับยืนยันตัวตน
     * @param int $timeout Timeout เป็นวินาที (ค่าเริ่มต้น 15 วินาที)
     * @param callable|null $localAdapter Adapter เสริมสำหรับทดสอบ Mock ภายในโปรเซสโดยตรง
     */
    public function __construct(
        string $endpointUrl = 'mock://local',
        string $apiKey = '',
        int $timeout = 15,
        ?callable $localAdapter = null
    ) {
        $this->endpointUrl  = $endpointUrl;
        $this->apiKey       = $apiKey;
        $this->timeout      = ($timeout > 0) ? $timeout : 15;
        $this->tlsVerify    = true; // เคารพข้อกำหนด: ห้ามปิด TLS Verification ในการออกแบบสำหรับใช้งานจริง
        $this->localAdapter = $localAdapter;
    }

    public function setEndpointUrl(string $url): void {
        $this->endpointUrl = $url;
    }

    public function getEndpointUrl(): string {
        return $this->endpointUrl;
    }

    public function setApiKey(string $apiKey): void {
        $this->apiKey = $apiKey;
    }

    public function setTimeout(int $seconds): void {
        $this->timeout = max(1, $seconds);
    }

    public function getTimeout(): int {
        return $this->timeout;
    }

    public function setLocalAdapter(?callable $adapter): void {
        $this->localAdapter = $adapter;
    }

    /**
     * Mask API Key ป้องกันการรั่วไหลลง Error หรือ Log
     * เช่น "rxk_test_01425_abc123" -> "rxk_...c123"
     */
    public static function maskApiKey(string $key): string {
        if ($key === '') {
            return '[EMPTY]';
        }
        $len = strlen($key);
        if ($len <= 8) {
            return '***' . substr($key, -2);
        }
        $prefix = substr($key, 0, 4);
        $suffix = substr($key, -4);
        return $prefix . '...' . $suffix;
    }

    /**
     * ตรวจสอบความถูกต้องและปลอดภัยของ Payload ก่อนส่ง (Client-Side Pre-Flight Validation)
     * 
     * @param array $records รายการข้อมูลที่ต้องการตรวจสอบ
     * @param string $expectedHcode รหัสสถานพยาบาลที่คาดหวัง
     * @return array [bool $valid, array $errors, array $sanitizedRecords]
     */
    public function validatePayload(array $records, string $expectedHcode, array $extraAllowedFields = []): array {
        $errors = [];
        $sanitized = [];

        if (count($records) > self::MAX_BATCH_RECORDS) {
            $errors[] = "Batch Size Exceeded: Number of records (" . count($records) . ") exceeds maximum limit of " . self::MAX_BATCH_RECORDS . " records per request.";
            return [false, $errors, []];
        }

        if (empty($records)) {
            return [true, [], []];
        }

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $allowedFields = array_merge(self::ALLOWED_FIELDS, $extraAllowedFields);
        $forbiddenFields = array_diff(self::FORBIDDEN_PII_FIELDS, $extraAllowedFields);

        foreach ($records as $index => $rec) {
            if (!is_array($rec)) {
                $errors[] = "Record at index {$index} must be an associative array";
                continue;
            }

            // 1. ตรวจสอบฟิลด์ต้องห้าม (PII Detection)
            foreach ($forbiddenFields as $piiField) {
                if (array_key_exists($piiField, $rec)) {
                    $errors[] = "PII Security Violation at record index {$index}: Forbidden field '{$piiField}' detected. Transmission strictly blocked.";
                }
            }

            // 2. ตรวจสอบฟิลด์เกินข้อกำหนด (Unknown Fields)
            foreach (array_keys($rec) as $f) {
                if (!in_array($f, $allowedFields, true)) {
                    $errors[] = "Schema Discrepancy at record index {$index}: Unauthorized field '{$f}' detected.";
                }
            }

            // 3. ตรวจสอบฟิลด์จำเป็น 6 ฟิลด์
            foreach (self::ALLOWED_FIELDS as $req) {
                if (!array_key_exists($req, $rec) || $rec[$req] === null || (is_scalar($rec[$req]) && trim((string)$rec[$req]) === '')) {
                    $errors[] = "Validation Error at record index {$index}: Required field '{$req}' is missing or empty.";
                }
            }

            // 4. ตรวจสอบ Multi-tenant hcode
            if (isset($rec['hcode']) && $rec['hcode'] !== $expectedHcode) {
                $hDisplay = is_scalar($rec['hcode']) ? (string)$rec['hcode'] : gettype($rec['hcode']);
                $errors[] = "Multi-Tenant Isolation Violation at record index {$index}: Record hcode '{$hDisplay}' does not match target facility '{$expectedHcode}'.";
            }

            // 5. ตรวจสอบชนิดข้อมูล (Data Types)
            if (isset($rec['qty'])) {
                if (is_bool($rec['qty']) || !is_numeric($rec['qty']) || (float)$rec['qty'] <= 0 || (is_string($rec['qty']) && !preg_match('/^\d+(\.\d+)?$/', trim($rec['qty'])))) {
                    $errors[] = "Validation Error at record index {$index}: Field 'qty' must be a numeric value greater than zero (CHECK constraint).";
                }
            }

            if (isset($rec['vstdate'])) {
                if (!is_string($rec['vstdate']) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rec['vstdate'], $dm)) {
                    $errors[] = "Validation Error at record index {$index}: Field 'vstdate' format must be YYYY-MM-DD.";
                } else {
                    $year  = (int)$dm[1];
                    $month = (int)$dm[2];
                    $day   = (int)$dm[3];
                    if (!checkdate($month, $day, $year)) {
                        $errors[] = "Validation Error at record index {$index}: Field 'vstdate' has non-existent calendar date '{$rec['vstdate']}'.";
                    } elseif ((string)$rec['vstdate'] > $tomorrow) {
                        $errors[] = "Validation Error at record index {$index}: Field 'vstdate' exceeds allowable future date threshold (+1 day).";
                    }
                }
            }

            // หากไม่มีข้อผิดพลาด ให้เก็บฟิลด์ที่ได้รับอนุญาต
            if (empty($errors)) {
                $cleanRow = [];
                foreach ($allowedFields as $f) {
                    if (array_key_exists($f, $rec)) {
                        $cleanRow[$f] = $rec[$f];
                    }
                }
                $cleanRow['qty'] = (float)$cleanRow['qty'];
                $sanitized[] = $cleanRow;
            }
        }

        return [
            empty($errors),
            $errors,
            $sanitized,
        ];
    }

    /**
     * ส่งชุดข้อมูลการจ่ายยา (Batch) ไปยัง API Endpoint
     * 
     * @param string $hcode รหัสสถานพยาบาล 5 หลัก
     * @param array $records รายการจ่ายยา
     * @param array $options ตัวเลือกเพิ่มเติม เช่น:
     *                       - 'skip_client_validation': ข้ามการตรวจฝั่งไคลเอนต์ (สำหรับทดสอบ 400 จาก Server)
     *                       - 'mock_scenario': จำลองสถานการณ์ เช่น 'force_500', 'force_429', 'force_timeout'
     *                       - 'custom_headers': Header พิเศษ
     *                       - 'raw_json': ส่ง Raw JSON String โดยตรง
     *                       - 'override_payload': ส่ง Payload Object จำเพาะโดยตรง
     * @return array โครงสร้างผลลัพธ์มาตรฐาน
     */
    public function sendDispenseBatch(string $hcode, array $records, array $options = []): array {
        $skipClientValidation = !empty($options['skip_client_validation']);
        $mockScenario = $options['mock_scenario'] ?? null;
        $customHeaders = $options['custom_headers'] ?? [];
        $rawJson = $options['raw_json'] ?? null;
        $overridePayload = $options['override_payload'] ?? null;

        if ($rawJson !== null) {
            $payloadJson = $rawJson;
        } elseif ($overridePayload !== null) {
            $payloadJson = json_encode($overridePayload, JSON_UNESCAPED_UNICODE);
        } else {
            // 1. Client-side Pre-flight Validation
            if (!$skipClientValidation) {
                [$valid, $valErrors, $sanitizedRecords] = $this->validatePayload($records, $hcode);
                if (!$valid) {
                    return [
                        'success'           => false,
                        'http_code'         => 400,
                        'client_rejected'   => true,
                        'error'             => 'Client Pre-Flight Validation Failed: ' . implode('; ', array_slice($valErrors, 0, 3)),
                        'validation_errors' => $valErrors,
                        'data'              => null,
                    ];
                }
                $payloadRecords = $sanitizedRecords;
            } else {
                $payloadRecords = $records;
            }

            // 2. สร้าง JSON Payload
            $payloadData = [
                'hcode'   => $hcode,
                'records' => $payloadRecords,
            ];
            $payloadJson = json_encode($payloadData, JSON_UNESCAPED_UNICODE);
        }

        // 3. กำหนด Headers (Masked ใน Debug)
        $headers = array_merge([
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
            'X-API-Key'     => $this->apiKey,
        ], $customHeaders);

        if ($mockScenario !== null) {
            $headers['X-Mock-Scenario'] = $mockScenario;
        }

        // 4. ส่งข้อมูลผ่าน Local Test Adapter (หากระบุ หรือ Endpoint คือ mock://)
        if ($this->localAdapter !== null || str_starts_with($this->endpointUrl, 'mock://')) {
            return $this->executeViaLocalAdapter($payloadJson, $headers);
        }

        // 5. ส่งข้อมูลผ่าน cURL HTTP/HTTPS Request
        return $this->executeViaCurl($payloadJson, $headers);
    }

    /**
     * ดำเนินการผ่าน Local Test Adapter โดยตรงภายในโปรเซส (High-speed & Zero Network Exposure)
     */
    private function executeViaLocalAdapter(string $payloadJson, array $headers): array {
        try {
            if ($this->localAdapter !== null) {
                $callable = $this->localAdapter;
                [$httpCode, $body, $resHeaders] = $callable($this->apiKey, $payloadJson, $headers);
            } else {
                // ค่าเริ่มต้น: ใช้ AgentMockApiServer แบบ In-Memory
                $server = new AgentMockApiServer();
                [$httpCode, $body, $resHeaders] = $server->handleRequest($this->apiKey, $payloadJson, $headers);
            }

            return $this->parseResponse($httpCode, $body, $resHeaders);
        } catch (\Throwable $e) {
            return [
                'success'   => false,
                'http_code' => 500,
                'error'     => 'Local Adapter Exception: ' . $this->sanitizeErrorMessage($e->getMessage()),
                'data'      => null,
            ];
        }
    }

    /**
     * ดำเนินการผ่าน cURL HTTP Request (รองรับ Mock Server บน localhost หรือ HTTPS ในอนาคต)
     */
    private function executeViaCurl(string $payloadJson, array $headers): array {
        if (!extension_loaded('curl')) {
            return [
                'success'   => false,
                'http_code' => 0,
                'error'     => 'cURL extension is not loaded in PHP environment.',
                'data'      => null,
            ];
        }

        $ch = curl_init();

        $formattedHeaders = [];
        foreach ($headers as $k => $v) {
            $formattedHeaders[] = "{$k}: {$v}";
        }

        curl_setopt($ch, CURLOPT_URL, $this->endpointUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $this->timeout));
        curl_setopt($ch, CURLOPT_HEADER, true);

        // ความปลอดภัยด้าน TLS: ตรวจสอบความถูกต้องของ Certificate เสมอ
        if (str_starts_with(strtolower($this->endpointUrl), 'https://')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }

        $rawResponse = curl_exec($ch);
        $curlErrno   = curl_errno($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        // จัดการกรณีเกิด Error ที่ระดับ Transport / Network
        if ($curlErrno !== 0) {
            return $this->handleCurlError($curlErrno, $curlError);
        }

        $headerText = substr($rawResponse, 0, $headerSize);
        $bodyText   = substr($rawResponse, $headerSize);

        $parsedHeaders = $this->parseHeaders($headerText);
        $decodedBody   = json_decode($bodyText, true);

        return $this->parseResponse($httpCode, $decodedBody ?? $bodyText, $parsedHeaders);
    }

    /**
     * แปลง Header string เป็น associative array
     */
    private function parseHeaders(string $headerText): array {
        $headers = [];
        foreach (explode("\r\n", $headerText) as $line) {
            if (strpos($line, ': ') !== false) {
                [$k, $v] = explode(': ', $line, 2);
                $headers[trim($k)] = trim($v);
            }
        }
        return $headers;
    }

    /**
     * จัดการ cURL Network Errors อย่างรัดกุมและปลอดภัย
     */
    private function handleCurlError(int $errno, string $error): array {
        $sanitizedError = $this->sanitizeErrorMessage($error);

        // CURLE_OPERATION_TIMEDOUT = 28
        if ($errno === 28) {
            return [
                'success'               => false,
                'http_code'             => 0,
                'error_code'            => 'TIMEOUT',
                'error'                 => "Connection Timeout: Server did not respond within {$this->timeout} seconds.",
                'is_timeout'            => true,
                'is_connection_refused' => false,
                'data'                  => null,
            ];
        }

        // CURLE_COULDNT_CONNECT = 7
        if ($errno === 7) {
            return [
                'success'               => false,
                'http_code'             => 0,
                'error_code'            => 'CONNECTION_REFUSED',
                'error'                 => "Connection Refused: Unable to connect to host '{$this->endpointUrl}'.",
                'is_connection_refused' => true,
                'is_timeout'            => false,
                'data'                  => null,
            ];
        }

        return [
            'success'               => false,
            'http_code'             => 0,
            'error_code'            => 'NETWORK_ERROR',
            'error'                 => "Network Error (cURL #{$errno}): {$sanitizedError}",
            'is_connection_refused' => false,
            'is_timeout'            => false,
            'data'                  => null,
        ];
    }

    /**
     * ถอดรหัสและตีความ Response Body ตาม HTTP Status Code
     */
    private function parseResponse(int $httpCode, $body, array $headers = []): array {
        $isJson = is_array($body);

        // กรณี HTTP Code 0 (Connection Refused หรือ Transport Error จาก Mock Adapter)
        if ($httpCode === 0 && $isJson) {
            return [
                'success'               => false,
                'http_code'             => 0,
                'error_code'            => $body['error_code'] ?? 'CONNECTION_REFUSED',
                'error'                 => 'Connection Failed: ' . ($body['message'] ?? 'Unable to connect'),
                'is_connection_refused' => !empty($body['is_connection_refused']),
                'is_timeout'            => !empty($body['is_timeout']),
                'data'                  => $body,
            ];
        }

        // ตรวจสอบกรณี Response ไม่ใช่ JSON
        if (!$isJson) {
            $preview = is_string($body) ? substr(trim(strip_tags($body)), 0, 150) : 'Non-JSON content';
            return [
                'success'           => false,
                'http_code'         => $httpCode,
                'error_code'        => 'MALFORMED_RESPONSE_JSON',
                'error'             => "Invalid JSON response from server (HTTP {$httpCode}): {$preview}",
                'raw_response'      => $preview,
                'data'              => null,
            ];
        }

        // กรณี HTTP 200 OK
        if ($httpCode === 200) {
            $isSuccess = !empty($body['success']);
            return [
                'success'             => $isSuccess,
                'http_code'           => 200,
                'hcode'               => $body['hcode'] ?? null,
                'inserted_or_updated' => (int)($body['inserted_or_updated'] ?? 0),
                'deleted'             => (int)($body['deleted'] ?? 0),
                'failed'              => (int)($body['failed'] ?? 0),
                'errors'              => $body['errors'] ?? [],
                'sync_log_id'         => $body['sync_log_id'] ?? null,
                'mock_mode'           => !empty($body['mock_mode']),
                'data'                => $body,
            ];
        }

        // กรณี HTTP 400 Bad Request
        if ($httpCode === 400) {
            return [
                'success'    => false,
                'http_code'  => 400,
                'error_code' => $body['error_code'] ?? 'BAD_REQUEST',
                'error'      => 'Bad Request (HTTP 400): ' . ($body['message'] ?? 'Malformed request or payload'),
                'data'       => $body,
            ];
        }

        // กรณี HTTP 401 Unauthorized
        if ($httpCode === 401) {
            return [
                'success'    => false,
                'http_code'  => 401,
                'error_code' => $body['error_code'] ?? 'UNAUTHORIZED',
                'error'      => 'Authentication Failed (HTTP 401): ' . ($body['message'] ?? 'Invalid API Key'),
                'data'       => $body,
            ];
        }

        // กรณี HTTP 403 Forbidden (Cross-facility tenant isolation violation)
        if ($httpCode === 403) {
            return [
                'success'    => false,
                'http_code'  => 403,
                'error_code' => $body['error_code'] ?? 'FORBIDDEN',
                'error'      => 'Forbidden Access (HTTP 403): ' . ($body['message'] ?? 'Tenant isolation policy violation'),
                'data'       => $body,
            ];
        }

        // กรณี HTTP 409 Conflict
        if ($httpCode === 409) {
            return [
                'success'    => false,
                'http_code'  => 409,
                'error_code' => $body['error_code'] ?? 'CONFLICT',
                'error'      => 'Conflict (HTTP 409): ' . ($body['message'] ?? 'Resource conflict or concurrent sync operation'),
                'data'       => $body,
            ];
        }

        // กรณี HTTP 429 Rate Limit
        if ($httpCode === 429) {
            $retryAfter = $headers['Retry-After'] ?? $body['retry_after'] ?? 60;
            return [
                'success'     => false,
                'http_code'   => 429,
                'error_code'  => $body['error_code'] ?? 'RATE_LIMIT_EXCEEDED',
                'error'       => 'Rate Limit Exceeded (HTTP 429): ' . ($body['message'] ?? 'Too many requests'),
                'retry_after' => (int)$retryAfter,
                'data'        => $body,
            ];
        }

        // กรณี HTTP 500 / 502 / 503 / 504 Server Error
        if ($httpCode >= 500) {
            return [
                'success'    => false,
                'http_code'  => $httpCode,
                'error_code' => $body['error_code'] ?? 'SERVER_ERROR',
                'error'      => "Server Error (HTTP {$httpCode}): " . ($body['message'] ?? 'Internal backend error in Supabase/API server'),
                'data'       => $body,
            ];
        }

        // กรณี HTTP 413 Payload Too Large
        if ($httpCode === 413) {
            return [
                'success'    => false,
                'http_code'  => 413,
                'error_code' => $body['error_code'] ?? 'PAYLOAD_TOO_LARGE',
                'error'      => 'Payload Too Large (HTTP 413): ' . ($body['message'] ?? 'Request exceeds allowable size limit'),
                'data'       => $body,
            ];
        }

        // กรณี HTTP Status อื่นๆ
        return [
            'success'    => false,
            'http_code'  => $httpCode,
            'error_code' => $body['error_code'] ?? 'UNEXPECTED_STATUS',
            'error'      => "HTTP Error {$httpCode}: " . ($body['message'] ?? 'Unknown status'),
            'data'       => $body,
        ];
    }

    /**
     * ตรวจสอบและ Redact API Key หรือ Password ออกจากข้อความ Error เสมอ
     */
    private function sanitizeErrorMessage(string $message): string {
        if ($this->apiKey !== '') {
            $message = str_replace($this->apiKey, self::maskApiKey($this->apiKey), $message);
        }
        // Redact pattern ทั่วไปของ token/key
        $message = preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.]{8,}/i', '$1[REDACTED_TOKEN]', $message);
        $message = preg_replace('/(api[-_]?key=)[^&\s]+/i', '$1[REDACTED_KEY]', $message);
        return $message;
    }

    /**
     * Security Audit: ตรวจสอบสถานะการบังคับใช้ TLS Certificate Verification
     */
    public function isTlsVerificationEnforced(): bool {
        return $this->tlsVerify;
    }

    /**
     * Security Audit: ตรวจสอบว่า URL เป็น External Address หรือไม่
     */
    public static function isExternalUrl(string $url): bool {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = strtolower($parsed['host'] ?? '');

        if ($scheme === 'mock') {
            return false;
        }
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return false;
        }
        return true;
    }

    /**
     * Security Audit: ยืนยันว่าไม่มีการส่งข้อมูลออกอินเทอร์เน็ต / ภายนอกเครื่อง
     */
    public function assertNoExternalConnection(): bool {
        return !self::isExternalUrl($this->endpointUrl);
    }
}
