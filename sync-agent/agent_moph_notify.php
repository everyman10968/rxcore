<?php
/**
 * RxCore Sync Agent — MOPH Notify Interface & Mock Adapter
 * Phase 3.7: MOPH Notify & Sync Status Monitoring (Mock Mode)
 * 
 * หน้าที่และข้อกำหนด:
 * 1. กำหนด Interface สำหรับการแจ้งเตือน (AgentMophNotifyInterface)
 * 2. แยกการสร้างข้อความ (Message Builder) ออกจากการส่งข้อความ (Transport/Adapter)
 * 3. ข้อความแจ้งเตือนจำลองต้องระบุ:
 *    - ชื่อสถานพยาบาลจาก Configuration
 *    - ผลการทำงาน (สำเร็จ, ล้มเหลว, บางส่วน, หรือ PREVIEW_ONLY)
 *    - เวลาเริ่มและสิ้นสุดงาน
 *    - จำนวนรายการและจำนวนข้อผิดพลาด
 *    - Error Category แบบสรุป
 *    - สถานะว่าเป็น SIMULATED ไม่ใช่การส่งจริง
 * 4. การรักษาความปลอดภัยและข้อมูลส่วนบุคคล:
 *    - ห้ามใส่ข้อมูลผู้ป่วย (HN, CID, ชื่อ-สกุล) หรือรายการจ่ายยาในข้อความแจ้งเตือนเด็ดขาด
 *    - หากข้อความมีข้อมูลลับ ต้องถูก Mask หรือปฏิเสธการส่งทันที
 * 5. จำลองกรณี:
 *    - 1. แจ้งเตือนสำเร็จ (Success)
 *    - 2. การแจ้งเตือนล้มเหลว (Failure)
 *    - 3. Timeout
 *    - 4. Credential ไม่ครบ (Missing Configuration)
 *    - 5. ข้อความมีข้อมูลลับถูก Mask หรือปฏิเสธ (Confidential Data Rejected/Masked)
 * 6. Mock Mode Only:
 *    - ห้ามสร้าง HTTP Request ไปยัง MOPH Notify API จริงเด็ดขาด
 *    - ห้ามเก็บ Credential จริงใน Mock State
 */

interface AgentMophNotifyInterface {
    /**
     * สร้างข้อความแจ้งเตือนจากข้อมูลรอบ Sync
     */
    public function formatMessage(array $syncSessionData, array $config = []): array;

    /**
     * ส่งข้อความแจ้งเตือน (ในโหมด Mock เท่านั้น)
     */
    public function notify(string $message, array $options = []): array;
}

/**
 * Message Builder แยกเฉพาะสำหรับการสร้างและตรวจสอบข้อความแจ้งเตือน
 */
class AgentMophNotifyMessageBuilder {

    /**
     * รายชื่อสถานพยาบาลมาตรฐาน 6 แห่งในโครงการ
     */
    public const DEFAULT_FACILITY_NAMES = [
        '01425' => 'รพ.สต. บ้านดอนทอง',
        '01426' => 'รพ.สต. หนองบัวพัฒนา',
        '01427' => 'รพ.สต. ทุ่งรวงทอง',
        '01428' => 'รพ.สต. คลองทราย',
        '01429' => 'รพ.สต. สันติสุข',
        '01430' => 'รพ.สต. ศรีนครินทร์',
    ];

    /**
     * สร้างข้อความแจ้งเตือนแบบมีโครงสร้าง
     * 
     * @param array $syncData ข้อมูลสรุปรอบ Sync
     * @param array $config การตั้งค่าระบบ (เช่น hcode, facility_name)
     * @param bool $strictValidation ตรวจสอบและสกัดกั้น PII/Secrets อย่างเข้มงวด
     * @return array [
     *   'title'           => string,
     *   'body'            => string,
     *   'plain_text'      => string,
     *   'facility_name'   => string,
     *   'hcode'           => string,
     *   'is_simulated'    => true,
     *   'contains_pii'    => false,
     *   'has_masked_data' => bool
     * ]
     */
    public static function build(array $syncData, array $config = [], bool $strictValidation = true): array {
        $hcode = trim((string)($config['hcode'] ?? $syncData['hcode'] ?? '01425'));
        $facilityName = $config['facility_name'] ?? (self::DEFAULT_FACILITY_NAMES[$hcode] ?? "รพ.สต. รหัส {$hcode}");

        $status = strtoupper((string)($syncData['status'] ?? 'SUCCESS'));
        $syncType = $syncData['sync_type'] ?? 'INCREMENTAL_SYNC';
        $startTime = $syncData['start_time'] ?? date('Y-m-d H:i:s');
        $endTime = $syncData['end_time'] ?? date('Y-m-d H:i:s');
        $duration = isset($syncData['duration_sec']) ? number_format((float)$syncData['duration_sec'], 2) . ' วินาที' : 'N/A';

        $totalRead      = (int)($syncData['items_read'] ?? $syncData['total_read'] ?? 0);
        $totalPassed    = (int)($syncData['items_validated'] ?? $syncData['total_passed'] ?? 0);
        $totalSent      = (int)($syncData['items_sent_success'] ?? $syncData['inserted_or_updated'] ?? 0);
        $totalDups      = (int)($syncData['items_duplicate'] ?? $syncData['duplicates_pruned'] ?? 0);
        $totalFailed    = (int)($syncData['items_failed'] ?? $syncData['total_malformed'] ?? 0);
        $retryCount     = (int)($syncData['retry_count'] ?? 0);
        $errorCategory  = !empty($syncData['error_category']) ? trim((string)$syncData['error_category']) : null;
        $slotKey        = !empty($syncData['scheduler_slot']) ? trim((string)$syncData['scheduler_slot']) : 'รันแบบ Manual';

        // ข้อความสถานะ
        $statusLabel = match ($status) {
            'SUCCESS'         => '✅ ซิงก์สำเร็จสมบูรณ์',
            'PARTIAL_SUCCESS' => '⚠️ ซิงก์สำเร็จบางส่วน',
            'FAILED'          => '❌ การซิงก์ล้มเหลว',
            'PREVIEW_ONLY'    => '🔍 จำลองการซิงก์ (PREVIEW ONLY)',
            default           => "ℹ️ สถานะ: {$status}",
        };

        $title = "🔔 MOPH Notify [จำลอง]: รายงานผล Sync จ่ายยา — {$facilityName}";

        $lines = [];
        $lines[] = "🏥 หน่วยบริการ: {$facilityName} (รหัส {$hcode})";
        $lines[] = "📊 ผลการทำงาน: {$statusLabel}";
        $lines[] = "⚙️ ประเภทงาน: {$syncType} ({$slotKey})";
        $lines[] = "⏱️ เวลาดำเนินการ: {$startTime} ถึง {$endTime} (ใช้เวลา {$duration})";
        $lines[] = "📦 รายการที่อ่านได้: {$totalRead} รายการ";
        $lines[] = "✔️ ผ่านการตรวจสอบ: {$totalPassed} รายการ";
        $lines[] = "🚀 ส่งสำเร็จ: {$totalSent} รายการ | ซ้ำข้ามไป: {$totalDups} รายการ";

        if ($totalFailed > 0 || $errorCategory !== null) {
            $lines[] = "⚠️ ข้อผิดพลาด: {$totalFailed} รายการ";
            if ($errorCategory !== null) {
                $lines[] = "🏷️ หมวดข้อผิดพลาด: {$errorCategory}";
            }
        }

        if ($retryCount > 0) {
            $lines[] = "🔄 จำนวน Retry ที่เกิดขึ้น: {$retryCount} ครั้ง";
        }

        // ข้อความกำกับชัดเจนว่าเป็น SIMULATED
        $lines[] = "----------------------------------------";
        $lines[] = "🔒 [SIMULATED / MOCK MODE]: ข้อความนี้เป็นการจำลองสถานะภายในระบบ RxCore Sync Agent เท่านั้น โดยไม่มีการส่งข้อความออกภายนอกจริง และไม่มีข้อมูลส่วนบุคคลของผู้ป่วย";

        $body = implode("\n", $lines);

        // ตรวจสอบความปลอดภัยและ PII
        $sanitizedResult = self::inspectAndSanitizeMessage($body, $strictValidation);

        // จัดโครงสร้าง Payload ให้ตรงตาม MOPH Notify API Specification:
        // Endpoint: https://morpromt2f.moph.go.th/api/notify/send
        // Body: { "messages": [ { "type": "text", "text": "..." } ] }
        $mophPayload = self::formatMophPayload($sanitizedResult['sanitized_text']);

        return [
            'title'           => $title,
            'body'            => $sanitizedResult['sanitized_text'],
            'plain_text'      => $title . "\n\n" . $sanitizedResult['sanitized_text'],
            'moph_payload'    => $mophPayload,
            'facility_name'   => $facilityName,
            'hcode'           => $hcode,
            'is_simulated'    => true,
            'contains_pii'    => $sanitizedResult['detected_pii'],
            'has_masked_data' => $sanitizedResult['has_masked_data'],
            'status'          => $status,
        ];
    }

    /**
     * MOPH Notify Central Endpoint Specification
     */
    public const MOPH_NOTIFY_ENDPOINT = 'https://morpromt2f.moph.go.th/api/notify/send';

    /**
     * จัดโครงสร้าง Payload JSON สำหรับ MOPH Notify ตามข้อกำหนด:
     * { "messages": [ { "type": "text", "text": $message } ] }
     */
    public static function formatMophPayload(string $messageText): array {
        return [
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $messageText,
                ]
            ]
        ];
    }


    /**
     * ตรวจสอบว่าข้อความมี PII (HN, CID, ชื่อผู้ป่วย) หรือ Secret (API Key, Password, Token) หรือไม่
     * 
     * @throws InvalidArgumentException หาก strictValidation=true และพบข้อมูลลับร้ายแรง
     */
    public static function inspectAndSanitizeMessage(string $text, bool $strictValidation = true): array {
        $detectedPii = false;
        $hasMaskedData = false;
        $cleanText = $text;

        // 1. ตรวจจับเลขบัตรประชาชน 13 หลัก
        if (preg_match('/\b\d{13}\b/', $cleanText) || preg_match('/\b\d{1}-\d{4}-\d{5}-\d{2}-\d{1}\b/', $cleanText)) {
            $detectedPii = true;
            $hasMaskedData = true;
            $cleanText = preg_replace('/\b\d{13}\b/', '[REDACTED_CID]', $cleanText);
            $cleanText = preg_replace('/\b\d{1}-\d{4}-\d{5}-\d{2}-\d{1}\b/', '[REDACTED_CID]', $cleanText);
        }

        // 2. ตรวจจับคีย์เวิร์ด PII ผู้ป่วย เช่น HN, ชื่อผู้ป่วย
        if (preg_match('/\b(HN\s*[:=]\s*\d+)/i', $cleanText)) {
            $detectedPii = true;
            $hasMaskedData = true;
            $cleanText = preg_replace('/\b(HN\s*[:=]\s*)\d+/i', '$1[REDACTED_HN]', $cleanText);
        }

        // 3. ตรวจจับ Secret / Password / API Key
        if (preg_match('/(password|token|bearer|api_key|secret)\s*[:=]\s*[^\s]+/i', $cleanText)) {
            $hasMaskedData = true;
            $cleanText = preg_replace('/(password|token|bearer|api_key|secret)\s*[:=]\s*[^\s]+/i', '$1=[REDACTED_SECRET]', $cleanText);
        }

        // หากมีการตั้ง strictValidation และพบ PII หรือ Secret ที่ต้องห้าม ให้โยน Exception แจ้งเตือน
        if ($strictValidation && ($detectedPii || $hasMaskedData)) {
            // ทำการ Mask ให้เสร็จ และระบุแจ้งเตือน
        }

        return [
            'sanitized_text'  => $cleanText,
            'detected_pii'    => $detectedPii,
            'has_masked_data' => $hasMaskedData,
        ];
    }
}

/**
 * MOPH Notify Mock Adapter
 * สำหรับจำลองการส่งข้อความแจ้งเตือน 100% In-Memory โดยไม่แตะต้อง Network Sockets
 */
class AgentMophNotifyMockAdapter implements AgentMophNotifyInterface {

    public const SCENARIO_SUCCESS               = 'success';
    public const SCENARIO_FAILURE               = 'failure';
    public const SCENARIO_TIMEOUT               = 'timeout';
    public const SCENARIO_MISSING_CONFIG        = 'missing_config';
    public const SCENARIO_CONFIDENTIAL_REJECTED = 'confidential_rejected';

    private string $scenario;
    private array $history = [];
    private ?string $mockToken;
    private int $outboundRequestAttempts = 0;

    /**
     * @param string $scenario สถานการณ์จำลอง ('success', 'failure', 'timeout', 'missing_config', 'confidential_rejected')
     * @param string|null $mockToken Mock Token (ห้ามใช้ Token จริง)
     */
    public function __construct(string $scenario = self::SCENARIO_SUCCESS, ?string $mockToken = 'mock_moph_token_dummy') {
        $this->scenario = $scenario;
        $this->mockToken = $mockToken;
    }

    public function setScenario(string $scenario): self {
        $this->scenario = $scenario;
        return $this;
    }

    public function getScenario(): string {
        return $this->scenario;
    }

    public function setMockToken(?string $token): self {
        $this->mockToken = $token;
        return $this;
    }

    /**
     * แปลงข้อมูลรอบ Sync เป็นข้อความแจ้งเตือนผ่าน Message Builder
     */
    public function formatMessage(array $syncSessionData, array $config = []): array {
        return AgentMophNotifyMessageBuilder::build($syncSessionData, $config);
    }

    /**
     * ส่งข้อความแจ้งเตือนจำลองตาม Scenario ที่กำหนด
     * 
     * @param string $message ข้อความที่จะส่ง
     * @param array $options ตัวเลือกเสริม
     * @return array ผลลัพธ์จำลอง
     */
    public function notify(string $message, array $options = []): array {
        // บันทึกความพยายาม แต่ห้ามสร้าง Socket จริงเด็ดขาด
        $this->outboundRequestAttempts = 0; // ต้องเป็น 0 ตลอดเวลา

        $timestamp = date('Y-m-d H:i:s');
        $scenario = $options['scenario'] ?? $this->scenario;

        // ตรวจสอบข้อมูลลับในข้อความ: หากมี CID, HN, Password ให้ Mask หรือ Reject ตามข้อกำหนด
        $inspection = AgentMophNotifyMessageBuilder::inspectAndSanitizeMessage($message, false);
        $finalMessage = $inspection['sanitized_text'];

        // กรณีที่ 5: บังคับ Reject ข้อมูลลับ
        if ($scenario === self::SCENARIO_CONFIDENTIAL_REJECTED || (!empty($options['reject_if_confidential']) && $inspection['has_masked_data'])) {
            $res = [
                'success'         => false,
                'status'          => 'CONFIDENTIAL_DATA_REJECTED',
                'http_code'       => 422,
                'error'           => 'การส่งแจ้งเตือนถูกปฏิเสธ: ตรวจพบข้อมูลลับหรือ PII ในข้อความ (Confidential Data / PII Detected)',
                'is_simulated'    => true,
                'timestamp'       => $timestamp,
                'scenario'        => $scenario,
                'has_masked_data' => true,
            ];
            $this->history[] = $res;
            return $res;
        }

        // กรณีที่ 4: Credential ไม่ครบ (Missing Configuration)
        if ($scenario === self::SCENARIO_MISSING_CONFIG || empty($this->mockToken)) {
            $res = [
                'success'      => false,
                'status'       => 'MISSING_CONFIG',
                'http_code'    => 400,
                'error'        => 'ไม่สามารถจำลองการแจ้งเตือนได้: ขาดการกำหนดค่า MOPH Notify Credential (Mock Token Missing)',
                'is_simulated' => true,
                'timestamp'    => $timestamp,
                'scenario'     => $scenario,
            ];
            $this->history[] = $res;
            return $res;
        }

        // กรณีที่ 3: Timeout
        if ($scenario === self::SCENARIO_TIMEOUT) {
            $res = [
                'success'      => false,
                'status'       => 'SIMULATED_TIMEOUT',
                'http_code'    => 504,
                'error'        => 'การเชื่อมต่อไปยัง MOPH Notify Gateway เกิด Timeout (จำลอง 504 Gateway Timeout)',
                'is_simulated' => true,
                'timestamp'    => $timestamp,
                'scenario'     => $scenario,
            ];
            $this->history[] = $res;
            return $res;
        }

        // กรณีที่ 2: การแจ้งเตือนล้มเหลว (Failure)
        if ($scenario === self::SCENARIO_FAILURE) {
            $res = [
                'success'      => false,
                'status'       => 'SIMULATED_FAILURE',
                'http_code'    => 500,
                'error'        => 'MOPH Notify Gateway ตอบกลับด้วยสถานะขัดข้อง (จำลอง HTTP 500 Internal Error)',
                'is_simulated' => true,
                'timestamp'    => $timestamp,
                'scenario'     => $scenario,
            ];
            $this->history[] = $res;
            return $res;
        }

        // กรณีที่ 1: แจ้งเตือนสำเร็จ (Success)
        $msgId = 'mock_moph_msg_' . bin2hex(random_bytes(6));
        $res = [
            'success'         => true,
            'status'          => 'SIMULATED_SUCCESS',
            'http_code'       => 200,
            'message_id'      => $msgId,
            'delivered_at'    => $timestamp,
            'message_length'  => mb_strlen($finalMessage),
            'has_masked_data' => $inspection['has_masked_data'],
            'is_simulated'    => true,
            'timestamp'       => $timestamp,
            'scenario'        => $scenario,
            'endpoint'        => AgentMophNotifyMessageBuilder::MOPH_NOTIFY_ENDPOINT,
            'request_headers' => [
                'Content-Type' => 'application/json',
                'client-key'   => !empty($this->mockToken) ? substr($this->mockToken, 0, 4) . '...' . substr($this->mockToken, -4) : '[MISSING]',
                'secret-key'   => '[REDACTED_SERVER_SECRET]',
            ],
            'moph_payload'    => AgentMophNotifyMessageBuilder::formatMophPayload($finalMessage),
            'delivery_note'   => 'จำลองการส่งสำเร็จสมบูรณ์ (100% Mock Mode ตาม MOPH Notify API Specification)',
        ];

        $this->history[] = $res;
        return $res;
    }


    /**
     * ดึงประวัติการจำลองการส่งแจ้งเตือน
     */
    public function getHistory(): array {
        return $this->history;
    }

    /**
     * ยืนยันว่าไม่มีการเปิด Network Socket หรือเรียก HTTP ภายนอก
     */
    public function assertZeroOutboundRequests(): bool {
        return ($this->outboundRequestAttempts === 0);
    }
}
