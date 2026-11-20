<?php
/**
 * RxCore Sync Agent — Mock Transport Module
 * Phase 3.6: Scheduler, Retry & Reconciliation (Mock Mode)
 * 
 * หน้าที่:
 * 1. จำลองการส่งข้อมูลระดับ Network Transport ภายในโปรเซส 100%
 * 2. ปราศจากการเชื่อมต่ออินเทอร์เน็ตภายนอก หรือเครือข่ายจริง (Zero Outbound Network)
 * 3. สามารถกำหนดลำดับ Response (Response Sequence) เพื่อทดสอบ:
 *    - Transient Failures (Timeout, Connection Refused, 500, 429) -> แล้วตามด้วย 200 OK
 *    - Permanent Failures (400, 401, 403)
 *    - Retry Exhaustion (ล้มเหลวติดต่อกันจนครบโควตา)
 * 4. บันทึกประวัติและนับจำนวนการเรียก Request
 */

class AgentMockTransport {

    private array $responseQueue = [];
    private array $requestHistory = [];
    private int $callCount = 0;
    private ?array $defaultResponse = null;

    /**
     * @param array $initialQueue ลำดับ Response จำลองที่จะส่งกลับทีละรอบ
     */
    public function __construct(array $initialQueue = []) {
        $this->responseQueue = $initialQueue;
        $this->defaultResponse = [
            'http_code' => 200,
            'success'   => true,
            'body'      => ['success' => true, 'mock_mode' => true],
            'headers'   => ['Content-Type' => 'application/json'],
        ];
    }

    /**
     * กำหนดคิวคำตอบจำลอง
     */
    public function setResponseQueue(array $queue): self {
        $this->responseQueue = $queue;
        return $this;
    }

    /**
     * เพิ่ม Response เข้าคิว
     */
    public function queueResponse(array $response): self {
        $this->responseQueue[] = $response;
        return $this;
    }

    /**
     * กำหนด Response เริ่มต้นเมื่อคิวหมด
     */
    public function setDefaultResponse(array $response): self {
        $this->defaultResponse = $response;
        return $this;
    }

    /**
     * ส่ง Request จำลอง (จำลอง Client Request)
     * 
     * @param string $endpoint
     * @param array $headers
     * @param string|null $bodyJson
     * @return array [int $httpCode, array|string $body, array $resHeaders]
     */
    public function send(string $endpoint, array $headers = [], ?string $bodyJson = null): array {
        $this->callCount++;

        // บันทึก Request History โดย Redact ข้อมูลที่เป็นความลับเสมอ
        $sanitizedHeaders = [];
        foreach ($headers as $k => $v) {
            if (stripos($k, 'auth') !== false || stripos($k, 'key') !== false) {
                $sanitizedHeaders[$k] = '[REDACTED_SECRET]';
            } else {
                $sanitizedHeaders[$k] = $v;
            }
        }

        $decodedBody = $bodyJson ? json_decode($bodyJson, true) : null;
        $recordCount = is_array($decodedBody) && isset($decodedBody['records']) && is_array($decodedBody['records'])
            ? count($decodedBody['records'])
            : 0;

        $this->requestHistory[] = [
            'call_index'    => $this->callCount,
            'endpoint'      => $endpoint,
            'headers'       => $sanitizedHeaders,
            'record_count'  => $recordCount,
            'payload_bytes' => strlen($bodyJson ?? ''),
            'timestamp'     => microtime(true),
        ];

        // ดึง Response จากคิว
        if (!empty($this->responseQueue)) {
            $next = array_shift($this->responseQueue);
        } else {
            $next = $this->defaultResponse;
        }

        $httpCode   = $next['http_code'] ?? 200;
        $body       = $next['body'] ?? ['success' => ($httpCode === 200)];
        $resHeaders = $next['headers'] ?? ['Content-Type' => 'application/json'];

        return [$httpCode, $body, $resHeaders];
    }

    /**
     * ดึงจำนวนครั้งที่ถูกเรียกใช้งาน
     */
    public function getCallCount(): int {
        return $this->callCount;
    }

    /**
     * ดึงประวัติการเรียก Request
     */
    public function getRequestHistory(): array {
        return $this->requestHistory;
    }

    /**
     * ล้างประวัติและคิว
     */
    public function reset(): void {
        $this->responseQueue = [];
        $this->requestHistory = [];
        $this->callCount = 0;
    }
}
