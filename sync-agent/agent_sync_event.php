<?php
/**
 * RxCore Sync Agent — Sync Event Model & Event Log
 * Phase 3.7: MOPH Notify & Sync Status Monitoring (Mock Mode)
 * 
 * หน้าที่และข้อกำหนด:
 * 1. Event Model สำหรับบันทึกเหตุการณ์ต่างๆ ใน Sync Pipeline:
 *    - SYNC_STARTED, EXTRACTION_COMPLETED, API_SEND_SUCCEEDED, API_SEND_FAILED
 *    - RETRY_SCHEDULED, RECONCILIATION_COMPLETED, RECONCILIATION_FAILED, NOTIFY_SIMULATED
 *    - PREVIEW_STARTED, PREVIEW_COMPLETED, SECURITY_ALERT
 * 2. บันทึก: เวลา (ISO8601), ระดับความรุนแรง (DEBUG, INFO, WARNING, ERROR, CRITICAL),
 *    ประเภทเหตุการณ์, ข้อความสรุป, และบริบทเสริม (Details)
 * 3. มาตรการรักษาความลับและความปลอดภัย:
 *    - ห้ามบันทึก API Key, Password, Bearer Token หรือ Credential ใดๆ เด็ดขาด (Strict Redaction)
 *    - ห้ามบันทึก HN, CID, ข้อมูลส่วนบุคคลของผู้ป่วย (PII) หรือ Payload จ่ายยาเต็มชุดเด็ดขาด
 * 4. การจำกัดขนาด Log (Ring Buffer / FIFO Eviction):
 *    - จำกัดจำนวน Event สูงสุดใน State ป้องกันหน่วยความจำหรือไฟล์โตเกินขอบเขต
 * 5. Mock State Only: ทำงานผ่าน AgentAtomicFile หรือ In-Memory เท่านั้น
 */

require_once __DIR__ . '/agent_atomic_file.php';
require_once __DIR__ . '/agent_mock_clock.php';

class AgentSyncEvent {

    // Event Types
    public const SYNC_STARTED             = 'SYNC_STARTED';
    public const EXTRACTION_COMPLETED      = 'EXTRACTION_COMPLETED';
    public const API_SEND_SUCCEEDED       = 'API_SEND_SUCCEEDED';
    public const API_SEND_FAILED          = 'API_SEND_FAILED';
    public const RETRY_SCHEDULED          = 'RETRY_SCHEDULED';
    public const RECONCILIATION_COMPLETED = 'RECONCILIATION_COMPLETED';
    public const RECONCILIATION_FAILED    = 'RECONCILIATION_FAILED';
    public const NOTIFY_SIMULATED         = 'NOTIFY_SIMULATED';
    public const PREVIEW_STARTED          = 'PREVIEW_STARTED';
    public const PREVIEW_COMPLETED        = 'PREVIEW_COMPLETED';
    public const SECURITY_ALERT           = 'SECURITY_ALERT';

    // Severities
    public const SEVERITY_DEBUG    = 'DEBUG';
    public const SEVERITY_INFO     = 'INFO';
    public const SEVERITY_WARNING  = 'WARNING';
    public const SEVERITY_ERROR    = 'ERROR';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    public const VALID_TYPES = [
        self::SYNC_STARTED,
        self::EXTRACTION_COMPLETED,
        self::API_SEND_SUCCEEDED,
        self::API_SEND_FAILED,
        self::RETRY_SCHEDULED,
        self::RECONCILIATION_COMPLETED,
        self::RECONCILIATION_FAILED,
        self::NOTIFY_SIMULATED,
        self::PREVIEW_STARTED,
        self::PREVIEW_COMPLETED,
        self::SECURITY_ALERT,
    ];

    public const VALID_SEVERITIES = [
        self::SEVERITY_DEBUG,
        self::SEVERITY_INFO,
        self::SEVERITY_WARNING,
        self::SEVERITY_ERROR,
        self::SEVERITY_CRITICAL,
    ];

    private string $id;
    private string $timestamp;
    private string $eventType;
    private string $severity;
    private string $summary;
    private array $details;
    private ?string $sessionId;
    private ?string $slotKey;
    private bool $isSimulated;

    /**
     * @param string $eventType ประเภทเหตุการณ์
     * @param string $summary ข้อความสรุป
     * @param string $severity ระดับความรุนแรง
     * @param array $details ข้อมูลประกอบ (จะถูก Redact อัตโนมัติ)
     * @param string|null $sessionId รหัสรอบ Sync
     * @param string|null $slotKey รอบเวลา Scheduler (ถ้ามี)
     * @param string|null $timestamp เวลาเกิดเหตุการณ์ (ถ้า null จะใช้นาฬิกาปัจจุบัน)
     * @param bool $isSimulated เป็นเหตุการณ์จำลอง (ค่าเริ่มต้น true)
     */
    public function __construct(
        string $eventType,
        string $summary,
        string $severity = self::SEVERITY_INFO,
        array $details = [],
        ?string $sessionId = null,
        ?string $slotKey = null,
        ?string $timestamp = null,
        bool $isSimulated = true
    ) {
        $this->id = 'evt_' . bin2hex(random_bytes(6));
        $this->eventType = in_array($eventType, self::VALID_TYPES, true) ? $eventType : self::SYNC_STARTED;
        $this->severity = in_array($severity, self::VALID_SEVERITIES, true) ? $severity : self::SEVERITY_INFO;
        $this->timestamp = $timestamp ?? date('Y-m-d H:i:s');
        $this->sessionId = $sessionId;
        $this->slotKey = $slotKey;
        $this->isSimulated = $isSimulated;

        // ดำเนินการ Redact ข้อความและ Details เพื่อป้องกันการรั่วไหลของ Secret และ PII
        $this->summary = self::redactString($summary);
        $this->details = self::sanitizeDetails($details);
    }

    public function getId(): string {
        return $this->id;
    }

    public function getTimestamp(): string {
        return $this->timestamp;
    }

    public function getEventType(): string {
        return $this->eventType;
    }

    public function getSeverity(): string {
        return $this->severity;
    }

    public function getSummary(): string {
        return $this->summary;
    }

    public function getDetails(): array {
        return $this->details;
    }

    public function getSessionId(): ?string {
        return $this->sessionId;
    }

    public function getSlotKey(): ?string {
        return $this->slotKey;
    }

    public function isSimulated(): bool {
        return $this->isSimulated;
    }

    public function toArray(): array {
        return [
            'id'           => $this->id,
            'timestamp'    => $this->timestamp,
            'event_type'   => $this->eventType,
            'severity'     => $this->severity,
            'summary'      => $this->summary,
            'details'      => $this->details,
            'session_id'   => $this->sessionId,
            'slot_key'     => $this->slotKey,
            'is_simulated' => $this->isSimulated,
        ];
    }

    public static function fromArray(array $data): self {
        $event = new self(
            $data['event_type'] ?? self::SYNC_STARTED,
            $data['summary'] ?? '',
            $data['severity'] ?? self::SEVERITY_INFO,
            $data['details'] ?? [],
            $data['session_id'] ?? null,
            $data['slot_key'] ?? null,
            $data['timestamp'] ?? null,
            $data['is_simulated'] ?? true
        );
        if (!empty($data['id'])) {
            $event->id = (string)$data['id'];
        }
        return $event;
    }

    /**
     * ฟังก์ชันฟอกข้อความ: ตัดและ Mask ข้อมูลที่เป็นความลับ (Password, API Key, Token, CID)
     */
    public static function redactString(string $input): string {
        // Redact Password
        $input = preg_replace('/(password|pwd|secret|pass)=([^\s&]+)/i', '$1=[REDACTED_SECRET]', $input);
        
        // Redact Bearer Token
        $input = preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.]{8,}/i', '$1[REDACTED_TOKEN]', $input);

        // Redact API Key format
        $input = preg_replace('/(key_secret_[a-zA-Z0-9_\-]+)/i', '[REDACTED_API_KEY]', $input);
        $input = preg_replace('/(api[_\-]?key[:=]\s*["\']?)[a-zA-Z0-9_\-]{8,}(["\']?)/i', '$1[REDACTED_KEY]$2', $input);

        // Redact Thai National CID (13 digits)
        $input = preg_replace('/\b(\d{13})\b/', '[REDACTED_CID]', $input);
        $input = preg_replace('/\b(\d{1}-\d{4}-\d{5}-\d{2}-\d{1})\b/', '[REDACTED_CID]', $input);

        return $input;
    }

    /**
     * ฟังก์ชันฟอกและกำกับ Details ป้องกันไม่ให้มี Payload เต็มชุดหรือ PII
     */
    public static function sanitizeDetails(array $details): array {
        $sanitized = [];
        $prohibitedPiiKeys = ['hn', 'cid', 'patient_name', 'first_name', 'last_name', 'birthdate', 'address', 'tel'];

        foreach ($details as $key => $val) {
            $lowerKey = strtolower((string)$key);

            // หากเป็น Key ที่มีโอกาสเป็น Secret
            if (in_array($lowerKey, ['password', 'secret', 'api_key', 'token', 'authorization', 'auth_header'], true)) {
                $sanitized[$key] = '[REDACTED_SECRET]';
                continue;
            }

            // หากเป็น Key ที่เป็น PII ของผู้ป่วย
            if (in_array($lowerKey, $prohibitedPiiKeys, true)) {
                $sanitized[$key] = '[REDACTED_PII]';
                continue;
            }

            // ห้ามบันทึก Payload เต็มชุดของรายการจ่ายยา (records batch array)
            if (in_array($lowerKey, ['records', 'dispense_payload', 'raw_records', 'batch_data'], true)) {
                if (is_array($val)) {
                    $sanitized[$key] = '[REDACTED_BATCH_PAYLOAD: count=' . count($val) . ']';
                } else {
                    $sanitized[$key] = '[REDACTED_PAYLOAD]';
                }
                continue;
            }

            // Recursive sanitize array
            if (is_array($val)) {
                $sanitized[$key] = self::sanitizeDetails($val);
            } elseif (is_string($val)) {
                $sanitized[$key] = self::redactString($val);
            } else {
                $sanitized[$key] = $val;
            }
        }

        return $sanitized;
    }
}

class AgentSyncEventLog {

    public const DEFAULT_MAX_EVENTS = 500;

    private ?string $filePath;
    private int $maxEvents;
    private array $events = [];

    /**
     * @param string|null $filePath ที่อยู่ไฟล์เก็บ Mock Event Log (null = In-Memory)
     * @param int $maxEvents จำนวนเหตุการณ์สูงสุด (FIFO Ring Buffer)
     * @param bool $autoLoad โหลดเหตุการณ์เดิมจากไฟล์
     */
    public function __construct(
        ?string $filePath = null,
        int $maxEvents = self::DEFAULT_MAX_EVENTS,
        bool $autoLoad = true
    ) {
        $this->filePath = $filePath;
        $this->maxEvents = max(10, $maxEvents);

        if ($this->filePath !== null && $autoLoad && file_exists($this->filePath)) {
            $this->loadEvents();
        }
    }

    /**
     * กำหนดขีดจำกัดจำนวน Event ใน Log
     */
    public function setMaxEvents(int $max): self {
        $this->maxEvents = max(10, $max);
        $this->prune();
        $this->saveEvents();
        return $this;
    }

    public function getMaxEvents(): int {
        return $this->maxEvents;
    }

    /**
     * บันทึก Event ใหม่ลงใน Log
     */
    public function record(
        string $eventType,
        string $summary,
        string $severity = AgentSyncEvent::SEVERITY_INFO,
        array $details = [],
        ?string $sessionId = null,
        ?string $slotKey = null,
        ?string $timestamp = null
    ): AgentSyncEvent {
        $event = new AgentSyncEvent(
            $eventType,
            $summary,
            $severity,
            $details,
            $sessionId,
            $slotKey,
            $timestamp,
            true
        );

        $this->addEvent($event);
        return $event;
    }

    /**
     * เพิ่ม AgentSyncEvent Object
     */
    public function addEvent(AgentSyncEvent $event): void {
        $this->events[] = $event->toArray();
        $this->prune();
        $this->saveEvents();
    }

    /**
     * จำกัดขนาด Ring Buffer (ตัดรายการเก่าสุดทิ้งเมื่อเกินโควตา)
     */
    private function prune(): void {
        if (count($this->events) > $this->maxEvents) {
            $overflow = count($this->events) - $this->maxEvents;
            $this->events = array_slice($this->events, $overflow);
        }
    }

    /**
     * โหลด Events จากไฟล์ (Atomic File Read)
     */
    public function loadEvents(): array {
        if ($this->filePath === null || !file_exists($this->filePath)) {
            return $this->events;
        }

        $decoded = AgentAtomicFile::readJson($this->filePath, []);
        if (is_array($decoded)) {
            $this->events = $decoded;
            $this->prune();
        }
        return $this->events;
    }

    /**
     * บันทึก Events ลงไฟล์ (Atomic File Write)
     */
    public function saveEvents(): bool {
        if ($this->filePath === null) {
            return true;
        }

        try {
            return AgentAtomicFile::writeJson($this->filePath, $this->events);
        } catch (\Throwable $e) {
            error_log("[AgentSyncEventLog] Save Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ดึงรายการ Events พร้อมตัวกรอง (Date, Severity, Event Type, Search)
     * 
     * @param array $filter [
     *   'date_from'  => 'YYYY-MM-DD',
     *   'date_to'    => 'YYYY-MM-DD',
     *   'severity'   => 'INFO|WARNING|ERROR|...',
     *   'event_type' => 'SYNC_STARTED|...',
     *   'session_id' => '...',
     *   'search'     => 'keyword'
     * ]
     * @param int $limit จำนวนที่ต้องการ (ค่าเริ่มต้น 50)
     * @param int $offset ค่าเริ่มต้นแถว
     * @return array รายการ Events ล่าสุดอยู่บนสุด
     */
    public function getEvents(array $filter = [], int $limit = 50, int $offset = 0): array {
        $filtered = array_reverse($this->events); // รายการล่าสุดอยู่บนสุด

        $dateFrom  = !empty($filter['date_from']) ? trim($filter['date_from']) : null;
        $dateTo    = !empty($filter['date_to']) ? trim($filter['date_to']) : null;
        $severity  = !empty($filter['severity']) ? strtoupper(trim($filter['severity'])) : null;
        $eventType = !empty($filter['event_type']) ? trim($filter['event_type']) : null;
        $sessionId = !empty($filter['session_id']) ? trim($filter['session_id']) : null;
        $search    = !empty($filter['search']) ? mb_strtolower(trim($filter['search'])) : null;

        $results = [];
        foreach ($filtered as $evt) {
            $evtDate = substr($evt['timestamp'] ?? '', 0, 10);

            // Filter Date From
            if ($dateFrom !== null && $evtDate < $dateFrom) {
                continue;
            }
            // Filter Date To
            if ($dateTo !== null && $evtDate > $dateTo) {
                continue;
            }
            // Filter Severity
            if ($severity !== null && $severity !== 'ALL' && ($evt['severity'] ?? '') !== $severity) {
                continue;
            }
            // Filter Event Type
            if ($eventType !== null && $eventType !== 'ALL' && ($evt['event_type'] ?? '') !== $eventType) {
                continue;
            }
            // Filter Session ID
            if ($sessionId !== null && ($evt['session_id'] ?? '') !== $sessionId) {
                continue;
            }
            // Filter Search Text in summary
            if ($search !== null && mb_strpos(mb_strtolower($evt['summary'] ?? ''), $search) === false) {
                continue;
            }

            $results[] = $evt;
        }

        $totalFiltered = count($results);
        $sliced = array_slice($results, $offset, $limit);

        return [
            'total'     => $totalFiltered,
            'limit'     => $limit,
            'offset'    => $offset,
            'events'    => $sliced,
        ];
    }

    /**
     * ดึง Event ล่าสุด N รายการ
     */
    public function getRecentEvents(int $limit = 10): array {
        $reversed = array_reverse($this->events);
        return array_slice($reversed, 0, $limit);
    }

    /**
     * ดึงสรุปสถิติตาม Severity และ Event Type
     */
    public function getStats(): array {
        $bySeverity = [
            AgentSyncEvent::SEVERITY_DEBUG    => 0,
            AgentSyncEvent::SEVERITY_INFO     => 0,
            AgentSyncEvent::SEVERITY_WARNING  => 0,
            AgentSyncEvent::SEVERITY_ERROR    => 0,
            AgentSyncEvent::SEVERITY_CRITICAL => 0,
        ];
        $byType = [];

        foreach ($this->events as $evt) {
            $sev = $evt['severity'] ?? AgentSyncEvent::SEVERITY_INFO;
            if (isset($bySeverity[$sev])) {
                $bySeverity[$sev]++;
            }
            $type = $evt['event_type'] ?? 'OTHER';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'total_events' => count($this->events),
            'max_capacity' => $this->maxEvents,
            'by_severity'  => $bySeverity,
            'by_type'      => $byType,
        ];
    }

    /**
     * ล้าง Event ทั้งหมด
     */
    public function clear(): bool {
        $this->events = [];
        return $this->saveEvents();
    }
}
