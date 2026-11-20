<?php
/**
 * RxCore Sync Agent — Sync Status Monitor Module
 * Phase 3.7: MOPH Notify & Sync Status Monitoring (Mock Mode)
 * 
 * หน้าที่และข้อกำหนด:
 * 1. ติดตามและบันทึกสถานะของระบบ Sync แยกจาก Scheduler และ Sync Core
 * 2. แสดงสถานะล่าสุด:
 *    - IDLE, RUNNING, SUCCESS, PARTIAL_SUCCESS, FAILED
 *    - เวลาเริ่ม, เวลาสิ้นสุด, ระยะเวลาทำงาน (duration_sec)
 *    - จำนวนรายการ: อ่านได้, ผ่านการตรวจสอบ, ส่งสำเร็จ, ซ้ำ, ผิดพลาด
 *    - จำนวน Retry และ Error Category
 *    - รอบเวลา Scheduler ที่เกี่ยวข้อง (slot_key)
 *    - สถานะ Reconciliation และ Source Completeness
 *    - สถานะ MOPH Notify
 * 3. มาตรการสำคัญ:
 *    - ต้องแยก PREVIEW_ONLY ออกจาก SUCCESS อย่างชัดเจน
 *    - ห้ามแสดงว่ามีการ Sync จริงเมื่อเป็นเพียงการจำลอง (Invariant Protection)
 * 4. จัดการประวัติการทำงานย้อนหลัง (History) และรองรับการกรองตามวันที่/สถานะ
 * 5. บันทึกและดึงข้อมูลผ่าน Mock State เท่านั้น (AgentAtomicFile / In-Memory)
 */

require_once __DIR__ . '/agent_atomic_file.php';
require_once __DIR__ . '/agent_sync_event.php';
require_once __DIR__ . '/agent_moph_notify.php';
require_once __DIR__ . '/agent_mock_clock.php';

class AgentSyncMonitor {

    // Status Constants
    public const STATUS_IDLE            = 'IDLE';
    public const STATUS_RUNNING         = 'RUNNING';
    public const STATUS_SUCCESS         = 'SUCCESS';
    public const STATUS_PARTIAL_SUCCESS = 'PARTIAL_SUCCESS';
    public const STATUS_FAILED          = 'FAILED';
    public const STATUS_PREVIEW_ONLY    = 'PREVIEW_ONLY';

    public const VALID_STATUSES = [
        self::STATUS_IDLE,
        self::STATUS_RUNNING,
        self::STATUS_SUCCESS,
        self::STATUS_PARTIAL_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_PREVIEW_ONLY,
    ];

    private ?string $filePath;
    private AgentSyncEventLog $eventLog;
    private AgentMockClock $clock;
    private array $state;

    /**
     * @param string|null $filePath ที่อยู่ไฟล์เก็บ Mock Monitor State (null = In-Memory)
     * @param AgentSyncEventLog|null $eventLog Instance ของ Event Log
     * @param AgentMockClock|null $clock Virtual Clock
     * @param bool $autoLoad โหลดค่าเดิมจากไฟล์
     */
    public function __construct(
        ?string $filePath = null,
        ?AgentSyncEventLog $eventLog = null,
        ?AgentMockClock $clock = null,
        bool $autoLoad = true
    ) {
        $this->filePath = $filePath;
        $this->clock = $clock ?? new AgentMockClock();
        $this->eventLog = $eventLog ?? new AgentSyncEventLog(
            $filePath ? dirname($filePath) . '/mock_sync_events.json' : null
        );

        $this->state = $this->getDefaultState();

        if ($this->filePath !== null && $autoLoad && file_exists($this->filePath)) {
            $this->loadState();
        }
    }

    public function getEventLog(): AgentSyncEventLog {
        return $this->eventLog;
    }

    public function getClock(): AgentMockClock {
        return $this->clock;
    }

    public function setClock(AgentMockClock $clock): self {
        $this->clock = $clock;
        return $this;
    }

    /**
     * โครงสร้างเริ่มต้นของ Monitor State
     */
    private function getDefaultState(): array {
        return [
            'current_session' => null, // ข้อมูลของรอบที่กำลังรัน หรือล่าสุด
            'last_completed_session' => null,
            'status' => self::STATUS_IDLE,
            'is_preview_mode' => true,
            'last_sync_time' => null,
            'total_lifetime_synced' => 0,
            'history' => [], // รายการรอบย้อนหลัง
            'summary_kpis' => [
                'total_runs' => 0,
                'total_success' => 0,
                'total_partial' => 0,
                'total_failed' => 0,
                'total_preview_only' => 0,
            ],
        ];
    }

    /**
     * โหลด State จากไฟล์
     */
    public function loadState(): array {
        if ($this->filePath === null || !file_exists($this->filePath)) {
            return $this->state;
        }

        $decoded = AgentAtomicFile::readJson($this->filePath, $this->getDefaultState());
        if (is_array($decoded)) {
            $this->state = array_merge($this->getDefaultState(), $decoded);
            $this->state['summary_kpis'] = array_merge(
                $this->getDefaultState()['summary_kpis'],
                $this->state['summary_kpis'] ?? []
            );
        }
        return $this->state;
    }

    /**
     * บันทึก State ลงไฟล์แบบ Atomic
     */
    public function saveState(): bool {
        if ($this->filePath === null) {
            return true;
        }

        try {
            return AgentAtomicFile::writeJson($this->filePath, $this->state);
        } catch (\Throwable $e) {
            error_log("[AgentSyncMonitor] Save State Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * เริ่มต้นรอบการทำงาน (Start Sync Session)
     * 
     * @param string $syncType 'INCREMENTAL_SYNC' | 'INITIAL_SYNC' | 'RECONCILIATION'
     * @param string|null $slotKey รอบเวลา Scheduler เช่น "2026-10-28@09:00"
     * @param bool $isPreview ระบุว่าเป็นโหมดจำลอง (PREVIEW_ONLY) หรือไม่
     * @return string Session ID ที่สร้างขึ้น
     */
    public function startSession(
        string $syncType = 'INCREMENTAL_SYNC',
        ?string $slotKey = null,
        bool $isPreview = true
    ): string {
        $now = $this->clock->format('Y-m-d H:i:s');
        $sessionId = 'sync_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

        $sessionData = [
            'session_id'            => $sessionId,
            'sync_type'             => $syncType,
            'slot_key'              => $slotKey,
            'is_preview'            => $isPreview,
            'status'                => self::STATUS_RUNNING,
            'start_time'            => $now,
            'end_time'              => null,
            'duration_sec'          => null,
            'items_read'            => 0,
            'items_validated'       => 0,
            'items_sent_success'    => 0,
            'items_duplicate'       => 0,
            'items_failed'          => 0,
            'retry_count'           => 0,
            'error_category'        => null,
            'reconciliation_status' => 'NOT_APPLICABLE',
            'source_completeness'   => 'NOT_APPLICABLE',
            'moph_notify_status'    => 'PENDING',
            'moph_notify_result'    => null,
        ];

        $this->state['current_session'] = $sessionData;
        $this->state['status'] = self::STATUS_RUNNING;
        $this->state['is_preview_mode'] = $isPreview;

        // บันทึก Event: SYNC_STARTED หรือ PREVIEW_STARTED
        $eventType = $isPreview ? AgentSyncEvent::PREVIEW_STARTED : AgentSyncEvent::SYNC_STARTED;
        $this->eventLog->record(
            $eventType,
            "เริ่มรอบการทำงาน {$syncType} " . ($isPreview ? '[PREVIEW ONLY]' : '') . ($slotKey ? " [รอบ {$slotKey}]" : ''),
            AgentSyncEvent::SEVERITY_INFO,
            [
                'session_id' => $sessionId,
                'slot_key'   => $slotKey,
                'is_preview' => $isPreview,
                'sync_type'  => $syncType,
            ],
            $sessionId,
            $slotKey,
            $now
        );

        $this->saveState();
        return $sessionId;
    }

    /**
     * อัปเดตความคืบหน้าระหว่างทำงาน
     */
    public function recordProgress(array $metrics): void {
        if ($this->state['current_session'] === null) {
            return;
        }

        foreach ($metrics as $k => $v) {
            if (array_key_exists($k, $this->state['current_session'])) {
                $this->state['current_session'][$k] = $v;
            }
        }

        $this->saveState();
    }

    /**
     * บันทึกข้อมูลการสกัดข้อมูล (Extraction Completed)
     */
    public function recordExtractionCompleted(int $totalRead, int $totalPassed, int $malformed, int $duplicates): void {
        if ($this->state['current_session'] === null) {
            return;
        }

        $this->state['current_session']['items_read'] = $totalRead;
        $this->state['current_session']['items_validated'] = $totalPassed;
        $this->state['current_session']['items_failed'] = $malformed;
        $this->state['current_session']['items_duplicate'] = $duplicates;

        $sess = $this->state['current_session'];
        $this->eventLog->record(
            AgentSyncEvent::EXTRACTION_COMPLETED,
            "สกัดข้อมูลเสร็จสิ้น: อ่านได้ {$totalRead}, ผ่าน {$totalPassed}, ตัดซ้ำ {$duplicates}, ผิดรูปแบบ {$malformed}",
            $malformed > 0 ? AgentSyncEvent::SEVERITY_WARNING : AgentSyncEvent::SEVERITY_INFO,
            [
                'total_read'   => $totalRead,
                'total_passed' => $totalPassed,
                'duplicates'   => $duplicates,
                'malformed'    => $malformed,
            ],
            $sess['session_id'],
            $sess['slot_key'],
            $this->clock->format('Y-m-d H:i:s')
        );

        $this->saveState();
    }

    /**
     * บันทึกสถานะการ Retry และ Error Category
     */
    public function recordRetry(int $retryCount, ?string $errorCategory = null): void {
        if ($this->state['current_session'] === null) {
            return;
        }

        $this->state['current_session']['retry_count'] = $retryCount;
        if ($errorCategory !== null) {
            $this->state['current_session']['error_category'] = $errorCategory;
        }

        $sess = $this->state['current_session'];
        $this->eventLog->record(
            AgentSyncEvent::RETRY_SCHEDULED,
            "ดำเนินการ Retry ครั้งที่ {$retryCount}" . ($errorCategory ? " (หมวดข้อผิดพลาด: {$errorCategory})" : ''),
            AgentSyncEvent::SEVERITY_WARNING,
            [
                'retry_count'    => $retryCount,
                'error_category' => $errorCategory,
            ],
            $sess['session_id'],
            $sess['slot_key'],
            $this->clock->format('Y-m-d H:i:s')
        );

        $this->saveState();
    }

    /**
     * บันทึกสถานะ Reconciliation และ Source Completeness
     */
    public function recordReconciliation(string $status, string $completeness, array $details = []): void {
        if ($this->state['current_session'] === null) {
            return;
        }

        $this->state['current_session']['reconciliation_status'] = $status;
        $this->state['current_session']['source_completeness'] = $completeness;

        $sess = $this->state['current_session'];
        $isSuccess = ($status === 'COMPLETED' || $status === 'PREVIEW_ONLY');
        $eventType = $isSuccess ? AgentSyncEvent::RECONCILIATION_COMPLETED : AgentSyncEvent::RECONCILIATION_FAILED;
        $severity = $isSuccess ? AgentSyncEvent::SEVERITY_INFO : AgentSyncEvent::SEVERITY_ERROR;

        $this->eventLog->record(
            $eventType,
            "การกระทบยอดข้อมูล (Reconciliation): สถานะ {$status}, Source Completeness: {$completeness}",
            $severity,
            $details,
            $sess['session_id'],
            $sess['slot_key'],
            $this->clock->format('Y-m-d H:i:s')
        );

        $this->saveState();
    }

    /**
     * บันทึกผลการแจ้งเตือน MOPH Notify
     */
    public function recordNotification(array $notifyResult): void {
        $sess = $this->state['current_session'] ?? $this->state['last_completed_session'];
        if ($sess === null) {
            return;
        }

        $statusStr = $notifyResult['status'] ?? ($notifyResult['success'] ? 'SIMULATED_SUCCESS' : 'SIMULATED_FAILURE');
        
        if ($this->state['current_session'] !== null) {
            $this->state['current_session']['moph_notify_status'] = $statusStr;
            $this->state['current_session']['moph_notify_result'] = $notifyResult;
        }

        if ($this->state['last_completed_session'] !== null) {
            $this->state['last_completed_session']['moph_notify_status'] = $statusStr;
            $this->state['last_completed_session']['moph_notify_result'] = $notifyResult;
            if (!empty($this->state['history'])) {
                $this->state['history'][0]['moph_notify_status'] = $statusStr;
                $this->state['history'][0]['moph_notify_result'] = $notifyResult;
            }
        }

        $severity = !empty($notifyResult['success']) ? AgentSyncEvent::SEVERITY_INFO : AgentSyncEvent::SEVERITY_WARNING;

        $this->eventLog->record(
            AgentSyncEvent::NOTIFY_SIMULATED,
            "จำลองการแจ้งเตือน MOPH Notify: สถานะ {$statusStr}" . (!empty($notifyResult['error']) ? " ({$notifyResult['error']})" : ''),
            $severity,
            $notifyResult,
            $sess['session_id'],
            $sess['slot_key'],
            $this->clock->format('Y-m-d H:i:s')
        );

        $this->saveState();
    }


    /**
     * จบรอบการทำงาน (Complete Session)
     * 
     * **CRITICAL INVARIANT:**
     * หากเป็นโหมด Preview หรือจำลอง (is_preview = true):
     * สถานะห้ามแสดงเป็น SUCCESS โดยเด็ดขาด ต้องแปลงเป็น PREVIEW_ONLY เพื่อไม่ให้เข้าใจผิดว่ามีการ Sync จริง!
     * 
     * @param string $finalStatus สถานะที่ต้องการบันทึก
     * @param array $summary ข้อมูลสรุปเพิ่มเติม
     * @return array ผลลัพธ์ของรอบที่ปิด
     */
    public function completeSession(string $finalStatus, array $summary = []): array {
        if ($this->state['current_session'] === null) {
            $this->startSession('MANUAL_SYNC', null, true);
        }

        $session = &$this->state['current_session'];
        $isPreview = (bool)($session['is_preview'] ?? true);

        // รวมค่าจาก Summary
        foreach ($summary as $k => $v) {
            if (array_key_exists($k, $session)) {
                $session[$k] = $v;
            }
        }

        // =====================================================================
        // CRITICAL INVARIANT: PREVIEW_ONLY ต้องไม่แสดงเป็น SUCCESS
        // =====================================================================
        $enforcedStatus = $finalStatus;
        if ($isPreview && $finalStatus === self::STATUS_SUCCESS) {
            $enforcedStatus = self::STATUS_PREVIEW_ONLY;
        }

        $now = $this->clock->format('Y-m-d H:i:s');
        $session['end_time'] = $now;
        $session['status'] = $enforcedStatus;

        // คำนวณ Duration
        $startTs = strtotime($session['start_time']);
        $endTs = strtotime($now);
        $session['duration_sec'] = max(0, $endTs - $startTs);

        // บันทึก Event สรุป
        $eventType = ($enforcedStatus === self::STATUS_SUCCESS || $enforcedStatus === self::STATUS_PREVIEW_ONLY)
            ? ($isPreview ? AgentSyncEvent::PREVIEW_COMPLETED : AgentSyncEvent::API_SEND_SUCCEEDED)
            : AgentSyncEvent::API_SEND_FAILED;

        $severity = match ($enforcedStatus) {
            self::STATUS_SUCCESS, self::STATUS_PREVIEW_ONLY => AgentSyncEvent::SEVERITY_INFO,
            self::STATUS_PARTIAL_SUCCESS                    => AgentSyncEvent::SEVERITY_WARNING,
            default                                         => AgentSyncEvent::SEVERITY_ERROR,
        };

        $this->eventLog->record(
            $eventType,
            "จบรอบการทำงาน: สถานะ {$enforcedStatus} (ใช้เวลา {$session['duration_sec']} วินาที, ส่งสำเร็จ {$session['items_sent_success']}, ผิดพลาด {$session['items_failed']})",
            $severity,
            [
                'final_status'       => $enforcedStatus,
                'items_sent_success' => $session['items_sent_success'],
                'items_failed'       => $session['items_failed'],
                'duration_sec'       => $session['duration_sec'],
                'is_preview'         => $isPreview,
            ],
            $session['session_id'],
            $session['slot_key'],
            $now
        );

        // อัปเดต KPIs รวม
        $this->state['summary_kpis']['total_runs']++;
        if ($enforcedStatus === self::STATUS_SUCCESS) {
            $this->state['summary_kpis']['total_success']++;
            $this->state['total_lifetime_synced'] += $session['items_sent_success'];
        } elseif ($enforcedStatus === self::STATUS_PREVIEW_ONLY) {
            $this->state['summary_kpis']['total_preview_only']++;
        } elseif ($enforcedStatus === self::STATUS_PARTIAL_SUCCESS) {
            $this->state['summary_kpis']['total_partial']++;
            $this->state['total_lifetime_synced'] += $session['items_sent_success'];
        } else {
            $this->state['summary_kpis']['total_failed']++;
        }

        $this->state['last_sync_time'] = $now;
        $this->state['status'] = $enforcedStatus;
        $this->state['last_completed_session'] = $session;

        // นำเข้าประวัติย้อนหลัง (เก็บ 50 รายการล่าสุด)
        array_unshift($this->state['history'], $session);
        if (count($this->state['history']) > 50) {
            $this->state['history'] = array_slice($this->state['history'], 0, 50);
        }

        $completedCopy = $session;
        $this->state['current_session'] = null; // คืนสู่สถานะไม่มีงานค้าง

        $this->saveState();
        return $completedCopy;
    }

    /**
     * ดึงข้อมูลสถานะภาพรวมปัจจุบัน
     */
    public function getCurrentStatus(): array {
        return [
            'status'                 => $this->state['status'],
            'is_running'             => ($this->state['status'] === self::STATUS_RUNNING),
            'is_preview_mode'        => $this->state['is_preview_mode'],
            'current_session'        => $this->state['current_session'],
            'last_completed_session' => $this->state['last_completed_session'],
            'last_sync_time'         => $this->state['last_sync_time'],
            'total_lifetime_synced'  => $this->state['total_lifetime_synced'],
            'summary_kpis'           => $this->state['summary_kpis'],
            'current_clock'          => $this->clock->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * ดึงข้อมูลการ์ดสรุปสำหรับ Web UI (Dashboard Summary Cards)
     */
    public function getSummaryCards(): array {
        $last = $this->state['last_completed_session'] ?? $this->state['current_session'] ?? [];
        $status = $this->state['status'];

        return [
            'current_status' => [
                'status'     => $status,
                'label'      => $this->formatStatusLabel($status),
                'badge_cls'  => $this->getStatusBadgeClass($status),
                'is_preview' => (bool)($last['is_preview'] ?? true),
            ],
            'last_run' => [
                'start_time'   => $last['start_time'] ?? '-',
                'end_time'     => $last['end_time'] ?? '-',
                'duration_sec' => isset($last['duration_sec']) ? $last['duration_sec'] . 's' : '-',
                'slot_key'     => $last['slot_key'] ?? 'Manual',
            ],
            'item_metrics' => [
                'read'      => $last['items_read'] ?? 0,
                'validated' => $last['items_validated'] ?? 0,
                'success'   => $last['items_sent_success'] ?? 0,
                'duplicate' => $last['items_duplicate'] ?? 0,
                'failed'    => $last['items_failed'] ?? 0,
            ],
            'reliability' => [
                'retry_count'    => $last['retry_count'] ?? 0,
                'error_category' => $last['error_category'] ?? 'NONE',
            ],
            'reconciliation' => [
                'status'       => $last['reconciliation_status'] ?? 'NOT_APPLICABLE',
                'completeness' => $last['source_completeness'] ?? 'NOT_APPLICABLE',
            ],
            'notify' => [
                'status'    => $last['moph_notify_status'] ?? 'NOT_TRIGGERED',
                'badge_cls' => ($last['moph_notify_status'] ?? '') === 'SIMULATED_SUCCESS' ? 'bg-success' : 'bg-secondary',
            ],
        ];
    }

    /**
     * ดึงประวัติรอบการทำงานพร้อมตัวกรอง
     */
    public function getHistory(int $limit = 20, ?string $filterStatus = null, ?string $filterDate = null): array {
        $results = [];

        foreach ($this->state['history'] as $item) {
            if ($filterStatus !== null && $filterStatus !== 'ALL' && ($item['status'] ?? '') !== $filterStatus) {
                continue;
            }
            if ($filterDate !== null && substr($item['start_time'] ?? '', 0, 10) !== $filterDate) {
                continue;
            }
            $results[] = $item;
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * ล้างข้อมูลจำลอง Monitor State
     */
    public function resetState(): bool {
        $this->state = $this->getDefaultState();
        $this->eventLog->clear();
        return $this->saveState();
    }

    private function formatStatusLabel(string $status): string {
        return match ($status) {
            self::STATUS_IDLE            => 'พร้อมทำงาน (IDLE)',
            self::STATUS_RUNNING         => 'กำลังประมวลผล (RUNNING)',
            self::STATUS_SUCCESS         => 'ส่งสำเร็จสมบูรณ์ (SUCCESS)',
            self::STATUS_PARTIAL_SUCCESS => 'สำเร็จบางส่วน (PARTIAL_SUCCESS)',
            self::STATUS_FAILED          => 'ล้มเหลว (FAILED)',
            self::STATUS_PREVIEW_ONLY    => 'จำลองสถานะ (PREVIEW_ONLY)',
            default                      => $status,
        };
    }

    private function getStatusBadgeClass(string $status): string {
        return match ($status) {
            self::STATUS_IDLE            => 'bg-secondary text-light',
            self::STATUS_RUNNING         => 'bg-info text-dark',
            self::STATUS_SUCCESS         => 'bg-success text-light',
            self::STATUS_PARTIAL_SUCCESS => 'bg-warning text-dark',
            self::STATUS_FAILED          => 'bg-danger text-light',
            self::STATUS_PREVIEW_ONLY    => 'bg-primary text-light',
            default                      => 'bg-secondary text-light',
        };
    }
}
