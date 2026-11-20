<?php
/**
 * RxCore Sync Agent — Retry Policy Module (Mock Mode)
 * Phase 3.6: Scheduler, Retry & Reconciliation
 * 
 * หน้าที่และข้อกำหนด:
 * 1. ออกแบบกลไก Retry สำหรับข้อผิดพลาดชั่วคราว (Transient Errors):
 *    - Timeout (cURL Timeout, HTTP 504 Gateway Timeout)
 *    - Connection Refused (Port ปิด, Host ไม่พร้อม)
 *    - Rate Limit (HTTP 429) พร้อมเคารพ Retry-After Header
 *    - Server Errors (HTTP 500, 502, 503)
 * 2. ใช้อัลกอริทึม Exponential Backoff พร้อม Random Jitter ป้องกัน Thundering Herd
 * 3. ไม่ Retry โดยอัตโนมัติสำหรับข้อผิดพลาดถาวร (Permanent Errors):
 *    - HTTP 400 Bad Request
 *    - HTTP 401 Unauthorized (API Key ผิด)
 *    - HTTP 403 Forbidden (Cross-tenant violation)
 * 4. จำกัดจำนวนครั้งการ Retry สูงสุด (Max Attempts) และเวลารอสูงสุด (Max Delay)
 * 5. ป้องกันการส่งข้อมูลซ้ำจนเกิดรายการเบิ้ล (Composite Key Idempotence)
 * 6. บันทึก Attempt Timeline, ผลลัพธ์ และ Error Category โดยไม่มี Plaintext Key หรือ Full Payload หลุดรอด
 * 7. ใช้ AgentMockClock สำหรับก้าวเวลาจำลอง โดยไม่มีการ sleep() จริงในการทดสอบ
 */

require_once __DIR__ . '/agent_mock_clock.php';

class AgentRetryPolicy {

    public const DEFAULT_MAX_ATTEMPTS = 4;     // รวมรอบแรก + retry สูงสุด 3 ครั้ง
    public const DEFAULT_BASE_DELAY_SEC = 1.0; // หน่วงเวลาเริ่มต้น 1 วินาที
    public const DEFAULT_MAX_DELAY_SEC = 30.0; // เพดานหน่วงเวลาสูงสุด 30 วินาที
    public const DEFAULT_MULTIPLIER = 2.0;     // ตัวคูณ Exponential
    public const DEFAULT_JITTER_PCT = 0.15;    // Jitter สุ่มบวกลบ 15%

    // หมวดหมู่ข้อผิดพลาด
    public const CATEGORY_SUCCESS                   = 'SUCCESS';
    public const CATEGORY_TRANSIENT_TIMEOUT         = 'TRANSIENT_TIMEOUT';
    public const CATEGORY_TRANSIENT_CONN_REFUSED    = 'TRANSIENT_CONNECTION_REFUSED';
    public const CATEGORY_TRANSIENT_RATE_LIMIT      = 'TRANSIENT_RATE_LIMIT';
    public const CATEGORY_TRANSIENT_SERVER_ERROR    = 'TRANSIENT_SERVER_ERROR';
    public const CATEGORY_PERMANENT_BAD_REQUEST     = 'PERMANENT_BAD_REQUEST';
    public const CATEGORY_PERMANENT_UNAUTHORIZED    = 'PERMANENT_UNAUTHORIZED';
    public const CATEGORY_PERMANENT_FORBIDDEN       = 'PERMANENT_FORBIDDEN';
    public const CATEGORY_PERMANENT_PAYLOAD_TOO_LARGE = 'PERMANENT_PAYLOAD_TOO_LARGE';
    public const CATEGORY_UNKNOWN                   = 'UNKNOWN_ERROR';

    private int $maxAttempts;
    private float $baseDelaySec;
    private float $maxDelaySec;
    private float $multiplier;
    private float $jitterPct;
    private ?AgentMockClock $clock;

    public function __construct(
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        float $baseDelaySec = self::DEFAULT_BASE_DELAY_SEC,
        float $maxDelaySec = self::DEFAULT_MAX_DELAY_SEC,
        float $multiplier = self::DEFAULT_MULTIPLIER,
        float $jitterPct = self::DEFAULT_JITTER_PCT,
        ?AgentMockClock $clock = null
    ) {
        $this->maxAttempts  = max(1, $maxAttempts);
        $this->baseDelaySec = max(0.1, $baseDelaySec);
        $this->maxDelaySec  = max($this->baseDelaySec, $maxDelaySec);
        $this->multiplier   = max(1.0, $multiplier);
        $this->jitterPct    = max(0.0, min(1.0, $jitterPct));
        $this->clock        = $clock;
    }

    public function getMaxAttempts(): int {
        return $this->maxAttempts;
    }

    public function getBaseDelaySec(): float {
        return $this->baseDelaySec;
    }

    public function getMaxDelaySec(): float {
        return $this->maxDelaySec;
    }

    public function setClock(?AgentMockClock $clock): self {
        $this->clock = $clock;
        return $this;
    }

    public function getClock(): ?AgentMockClock {
        return $this->clock;
    }

    /**
     * วิเคราะห์และจำแนกหมวดหมู่ข้อผิดพลาด
     * 
     * @param int $httpCode รหัส HTTP Status
     * @param array $responseBody ข้อมูล Response จากเซิร์ฟเวอร์
     * @param bool $isTimeout แฟล็ก Timeout
     * @param bool $isConnRefused แฟล็ก Connection Refused
     * @return string Error Category
     */
    public function classifyError(
        int $httpCode,
        array $responseBody = [],
        bool $isTimeout = false,
        bool $isConnRefused = false
    ): string {
        if ($httpCode === 200 && (!isset($responseBody['success']) || $responseBody['success'] === true)) {
            return self::CATEGORY_SUCCESS;
        }

        if ($isTimeout || $httpCode === 504 || ($responseBody['error_code'] ?? '') === 'TIMEOUT' || ($responseBody['error_code'] ?? '') === 'GATEWAY_TIMEOUT') {
            return self::CATEGORY_TRANSIENT_TIMEOUT;
        }

        if ($isConnRefused || $httpCode === 0 || ($responseBody['error_code'] ?? '') === 'CONNECTION_REFUSED') {
            return self::CATEGORY_TRANSIENT_CONN_REFUSED;
        }

        if ($httpCode === 429 || ($responseBody['error_code'] ?? '') === 'RATE_LIMIT_EXCEEDED') {
            return self::CATEGORY_TRANSIENT_RATE_LIMIT;
        }

        if ($httpCode >= 500 && $httpCode <= 599) {
            return self::CATEGORY_TRANSIENT_SERVER_ERROR;
        }

        if ($httpCode === 401 || ($responseBody['error_code'] ?? '') === 'UNAUTHORIZED') {
            return self::CATEGORY_PERMANENT_UNAUTHORIZED;
        }

        if ($httpCode === 403 || ($responseBody['error_code'] ?? '') === 'FORBIDDEN' || ($responseBody['error_code'] ?? '') === 'FORBIDDEN_CROSS_TENANT') {
            return self::CATEGORY_PERMANENT_FORBIDDEN;
        }

        if ($httpCode === 413 || ($responseBody['error_code'] ?? '') === 'PAYLOAD_TOO_LARGE' || ($responseBody['error_code'] ?? '') === 'BATCH_SIZE_EXCEEDED') {
            return self::CATEGORY_PERMANENT_PAYLOAD_TOO_LARGE;
        }

        if ($httpCode === 400 || ($responseBody['error_code'] ?? '') === 'BAD_REQUEST') {
            return self::CATEGORY_PERMANENT_BAD_REQUEST;
        }

        return self::CATEGORY_UNKNOWN;
    }

    /**
     * ตรวจสอบว่าหมวดหมู่ข้อผิดพลาดนี้อนุญาตให้ Retry หรือไม่
     */
    public function isRetryable(string $category): bool {
        return in_array($category, [
            self::CATEGORY_TRANSIENT_TIMEOUT,
            self::CATEGORY_TRANSIENT_CONN_REFUSED,
            self::CATEGORY_TRANSIENT_RATE_LIMIT,
            self::CATEGORY_TRANSIENT_SERVER_ERROR,
        ], true);
    }

    /**
     * แปลงค่า Retry-After (ทั้งแบบจำนวนวินาที และแบบ HTTP-date) เป็นวินาที
     * พร้อมจำกัดเพดานไม่ให้เกิน maxDelaySec ตามนโยบายความปลอดภัย
     * 
     * @param mixed $retryAfter ค่าจาก Header (ตัวเลข หรือ HTTP-date สตริง เช่น "Wed, 28 Oct 2026 10:20:00 GMT")
     * @return float|null จำนวนวินาทีที่คำนวณและจำกัดเพดานแล้ว หรือ null หากไม่มีค่าหรือแปลงไม่ได้
     */
    public function parseRetryAfter($retryAfter): ?float {
        if ($retryAfter === null) {
            return null;
        }

        // กรณีระบุเป็นตัวเลขวินาที เช่น 5 หรือ "120"
        if (is_numeric($retryAfter)) {
            $val = (float)$retryAfter;
            if ($val <= 0) {
                return 0.1;
            }
            return min($val, $this->maxDelaySec);
        }

        // กรณีระบุเป็นสตริง HTTP-date (RFC 7231 / RFC 1123 / RFC 850 / ANSI C)
        if (is_string($retryAfter)) {
            $trimmed = trim($retryAfter);
            if ($trimmed === '') {
                return null;
            }

            $targetTs = strtotime($trimmed);
            if ($targetTs !== false) {
                $nowTs = $this->clock ? $this->clock->getTimestamp() : time();
                $diff = $targetTs - $nowTs;
                if ($diff <= 0) {
                    return 0.1; // ถึงกำหนดเวลาแล้ว
                }
                // จำกัดเพดานเวลารอไม่ให้เกิน maxDelaySec ตามนโยบาย
                return min((float)$diff, $this->maxDelaySec);
            }
        }

        return null;
    }

    /**
     * คำนวณระยะเวลาหน่วงก่อนการ Retry (Exponential Backoff + Jitter หรือเคารพ Retry-After)
     * 
     * @param int $attempt หมายเลขรอบการ Retry (เริ่มจาก 1 สำหรับการ Retry ครั้งแรก)
     * @param int|float|string|null $retryAfter ค่าจาก Retry-After Header (ตัวเลขวินาที หรือ HTTP-date)
     * @return float ระยะเวลาหน่วงเป็นวินาที (ไม่เกิน maxDelaySec)
     */
    public function calculateDelay(int $attempt, $retryAfter = null): float {
        // หากเซิร์ฟเวอร์ส่ง Retry-After (ทั้งแบบวินาทีหรือ HTTP-date) และแปลงได้ ให้เคารพค่านั้น
        $parsedRetryAfter = $this->parseRetryAfter($retryAfter);
        if ($parsedRetryAfter !== null) {
            return round(max(0.1, min($parsedRetryAfter, $this->maxDelaySec)), 3);
        }

        // คำนวณแบบ Exponential: base * (multiplier ^ (attempt - 1))
        $exponent = max(0, $attempt - 1);
        $backoff = $this->baseDelaySec * pow($this->multiplier, $exponent);
        $cappedBackoff = min($backoff, $this->maxDelaySec);

        // คำนวณ Random Jitter: สุ่มในช่วง [-jitterPct, +jitterPct]
        if ($this->jitterPct > 0) {
            $jitterRange = $cappedBackoff * $this->jitterPct;
            $randomFraction = (mt_rand(0, 1000) / 1000.0) * 2 - 1; // สุ่มระหว่าง -1.0 ถึง +1.0
            $finalDelay = $cappedBackoff + ($jitterRange * $randomFraction);
        } else {
            $finalDelay = $cappedBackoff;
        }

        return round(max(0.1, min($finalDelay, $this->maxDelaySec)), 3);
    }

    /**
     * รัน Action ภายใต้ Retry Policy แบบเต็มรูปแบบ
     * บันทึก Attempt Timeline ทุกรอบ พร้อมก้าว Mock Clock (Zero Real Waiting)
     * 
     * @param callable $action ฟังก์ชันที่คืนค่า Response Array: ['http_code', 'success', 'data', ...]
     * @param string $actionName ชื่อเพื่อใช้ใน Timeline Logging
     * @return array [
     *   'success'          => bool,
     *   'final_http_code'  => int,
     *   'total_attempts'   => int,
     *   'status'           => string (COMPLETED | EXHAUSTED | PERMANENT_FAILURE),
     *   'timeline'         => array,
     *   'final_response'   => array,
     *   'total_delay_sec'  => float,
     *   'mock_mode'        => true
     * ]
     */
    public function executeWithRetry(callable $action, string $actionName = 'dispense_batch_sync'): array {
        $timeline = [];
        $attempt = 1;
        $totalDelay = 0.0;
        $finalResponse = null;
        $status = 'IN_PROGRESS';

        while ($attempt <= $this->maxAttempts) {
            $attemptStartTime = $this->clock ? $this->clock->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
            $attemptStartTs   = $this->clock ? $this->clock->getTimestamp() : time();

            // ดำเนินการ Action
            try {
                $response = $action($attempt);
            } catch (\Throwable $e) {
                $response = [
                    'success'   => false,
                    'http_code' => 500,
                    'error'     => 'Exception during execution: ' . $e->getMessage(),
                    'data'      => ['error_code' => 'EXCEPTION', 'message' => $e->getMessage()],
                ];
            }

            $finalResponse = $response;
            $httpCode      = (int)($response['http_code'] ?? 0);
            $respData      = is_array($response['data'] ?? null) ? $response['data'] : [];
            $isTimeout     = !empty($response['is_timeout']);
            $isConnRefused = !empty($response['is_connection_refused']);
            $isSuccess     = !empty($response['success']) && $httpCode === 200;

            // ตรวจสอบ Retry-After (ทั้งจาก root, data payload, และ response headers)
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $retryAfter = $response['retry_after'] 
                ?? $respData['retry_after'] 
                ?? $headers['Retry-After'] 
                ?? $headers['retry-after'] 
                ?? null;

            // จำแนกหมวดหมู่ข้อผิดพลาด
            $category = $this->classifyError($httpCode, $respData, $isTimeout, $isConnRefused);
            $retryable = $this->isRetryable($category);

            // บันทึก Timeline
            $timelineEntry = [
                'attempt'          => $attempt,
                'action_name'      => $actionName,
                'timestamp'        => $attemptStartTime,
                'epoch'            => $attemptStartTs,
                'http_code'        => $httpCode,
                'category'         => $category,
                'is_retryable'     => $retryable,
                'is_success'       => $isSuccess,
                'error_summary'    => $isSuccess ? null : $this->sanitizeSummary($response['error'] ?? $respData['message'] ?? 'Error occurred'),
                'delay_to_next'    => 0.0,
            ];

            // 1. กรณีสำเร็จ (Success)
            if ($isSuccess) {
                $timelineEntry['outcome'] = 'SUCCESS';
                $timeline[] = $timelineEntry;
                $status = 'COMPLETED';
                break;
            }

            // 2. กรณีเป็น Permanent Error (400, 401, 403) -> หยุดทันที ห้าม Retry
            if (!$retryable) {
                $timelineEntry['outcome'] = 'STOPPED_PERMANENT_ERROR';
                $timeline[] = $timelineEntry;
                $status = 'PERMANENT_FAILURE';
                break;
            }

            // 3. กรณีเป็น Transient Error แต่ครบโควตา Attempt แล้ว -> หมดโควตา (Exhausted)
            if ($attempt >= $this->maxAttempts) {
                $timelineEntry['outcome'] = 'EXHAUSTED_MAX_ATTEMPTS';
                $timeline[] = $timelineEntry;
                $status = 'EXHAUSTED';
                break;
            }

            // 4. กรณีเป็น Transient Error และยังมีโควตา Retry เหลือ
            $delay = $this->calculateDelay($attempt, $retryAfter);
            $totalDelay += $delay;
            $timelineEntry['delay_to_next'] = $delay;
            $timelineEntry['outcome'] = "RETRYING_IN_{$delay}S";
            $timeline[] = $timelineEntry;

            // ก้าวเวลาจำลองบน Mock Clock ไปข้างหน้าตาม delay (Zero Sleep!)
            if ($this->clock !== null) {
                $this->clock->advanceSeconds($delay);
            }

            $attempt++;
        }

        return [
            'success'          => ($status === 'COMPLETED'),
            'status'           => $status,
            'final_http_code'  => (int)($finalResponse['http_code'] ?? 0),
            'total_attempts'   => count($timeline),
            'max_allowed'      => $this->maxAttempts,
            'total_delay_sec'  => round($totalDelay, 3),
            'timeline'         => $timeline,
            'final_response'   => $finalResponse,
            'mock_mode'        => true,
            'clock_time_final' => $this->clock ? $this->clock->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
        ];
    }

    /**
     * ล้างข้อมูลส่วนตัว / ความลับออกจากสรุปข้อผิดพลาด
     */
    private function sanitizeSummary(?string $msg): string {
        if ($msg === null) {
            return '';
        }
        $msg = preg_replace('/(key|token|password)[=:\s]+[A-Za-z0-9_\-\.]{6,}/i', '$1=[REDACTED]', $msg);
        $msg = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]{6,}/i', 'Bearer [REDACTED]', $msg);
        return substr(strip_tags($msg), 0, 180);
    }
}
