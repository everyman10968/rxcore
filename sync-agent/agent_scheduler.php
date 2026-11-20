<?php
/**
 * RxCore Sync Agent — Scheduler Module (Mock Mode)
 * Phase 3.6: Scheduler, Retry & Reconciliation
 * 
 * ข้อกำหนดและหน้าที่:
 * 1. กำหนดรอบ Sync ตามเวลาประเทศไทย (Asia/Bangkok):
 *    - รอบเช้า:     09:00 น.
 *    - รอบเที่ยง:    12:00 น.
 *    - รอบบ่ายเย็น:  16:00 น.
 * 2. แยก Scheduler Logic ออกจากการทำงานจริง (Pure Domain Logic)
 * 3. ใช้ AgentMockClock สำหรับทดสอบโดยไม่ต้องรอเวลาจริง
 * 4. ป้องกันการรันงานซ้ำในรอบเวลาเดียวกัน (Slot Idempotency: YYYY-MM-DD@HH:MM)
 * 5. บันทึกประวัติรอบการทำงานลงใน Mock State
 * 6. ตรวจจับรอบที่พลาด (Missed Slots) หาก Agent ปิดตัวลงหรือเริ่มทำงานช้ากว่าเวลา โดยไม่รันงานซ้อนกัน
 * 7. ห้ามสร้าง Windows Service หรือเปิด Scheduled Task ที่ทำงานจริงใน Phase นี้
 */

require_once __DIR__ . '/agent_mock_clock.php';

class AgentScheduler {

    /**
     * รอบเวลา Sync ประจำวัน (เวลาประเทศไทย)
     */
    public const SCHEDULED_SLOTS = ['09:00', '12:00', '16:00'];

    /**
     * กรอบเวลาที่อนุญาตให้เริ่มงานรอบนั้นได้ (Grace Window เป็นนาที เช่น +/- 15 นาที)
     */
    public const DEFAULT_GRACE_MINUTES = 15;

    private AgentMockClock $clock;
    private array $history = []; // บันทึกประวัติการรันรอบ
    private array $executedSlots = []; // บันทึกรอบที่รันไปแล้ว: "YYYY-MM-DD@09:00" => timestamp

    /**
     * @param AgentMockClock|null $clock Virtual Clock (หากไม่ระบุจะสร้างใหม่ตามเวลาปัจจุบัน)
     * @param array $initialHistory ประวัติรอบจำลองเริ่มต้น
     */
    public function __construct(?AgentMockClock $clock = null, array $initialHistory = []) {
        $this->clock = $clock ?? new AgentMockClock();
        $this->history = $initialHistory;
        foreach ($this->history as $item) {
            if (!empty($item['slot_key'])) {
                $this->executedSlots[$item['slot_key']] = $item['executed_at'] ?? time();
            }
        }
    }

    /**
     * ดึง Instance ของ Mock Clock
     */
    public function getClock(): AgentMockClock {
        return $this->clock;
    }

    /**
     * ตั้งค่า Mock Clock ใหม่
     */
    public function setClock(AgentMockClock $clock): self {
        $this->clock = $clock;
        return $this;
    }

    /**
     * ดึงรายการรอบเวลาประจำวันทั้งหมด
     */
    public static function getScheduledSlots(): array {
        return self::SCHEDULED_SLOTS;
    }

    /**
     * สร้าง Slot Key เอกลักษณ์ประจำวันและรอบ เช่น "2026-10-28@09:00"
     */
    public static function buildSlotKey(string $date, string $slotTime): string {
        return trim($date) . '@' . trim($slotTime);
    }

    /**
     * คำนวณรอบเวลาถัดไป (Next Run Time) จากเวลาปัจจุบันของ Clock
     * 
     * @param DateTimeImmutable|null $fromTime เวลาอ้างอิง (ถ้า null ใช้ clock->now())
     * @return array [
     *   'next_run_time'   => string (YYYY-MM-DD HH:MM:SS),
     *   'slot'            => string (HH:MM),
     *   'slot_key'        => string,
     *   'seconds_until'   => int,
     *   'formatted_local' => string
     * ]
     */
    public function getNextRunTime(?DateTimeImmutable $fromTime = null): array {
        $base = $fromTime ?? $this->clock->now();
        $tz = $this->clock->getTimeZone();
        $base = $base->setTimezone($tz);

        $currentDate = $base->format('Y-m-d');
        $currentTimeStr = $base->format('H:i');

        // ค้นหารอบที่เหลือในวันนี้
        foreach (self::SCHEDULED_SLOTS as $slot) {
            if ($slot > $currentTimeStr) {
                $target = new DateTimeImmutable("{$currentDate} {$slot}:00", $tz);
                $diff = $target->getTimestamp() - $base->getTimestamp();
                return [
                    'next_run_time'   => $target->format('Y-m-d H:i:s'),
                    'slot'            => $slot,
                    'slot_key'        => self::buildSlotKey($currentDate, $slot),
                    'seconds_until'   => max(0, $diff),
                    'is_today'        => true,
                    'formatted_local' => "วันนี้ เวลา {$slot} น.",
                ];
            }
        }

        // หากผ่านรอบทั้งหมดของวันนี้ไปแล้ว ให้ไปรอบแรก (09:00) ของวันพรุ่งนี้
        $tomorrow = $base->modify('+1 day')->format('Y-m-d');
        $firstSlot = self::SCHEDULED_SLOTS[0];
        $target = new DateTimeImmutable("{$tomorrow} {$firstSlot}:00", $tz);
        $diff = $target->getTimestamp() - $base->getTimestamp();

        return [
            'next_run_time'   => $target->format('Y-m-d H:i:s'),
            'slot'            => $firstSlot,
            'slot_key'        => self::buildSlotKey($tomorrow, $firstSlot),
            'seconds_until'   => max(0, $diff),
            'is_today'        => false,
            'formatted_local' => "วันพรุ่งนี้ ({$tomorrow}) เวลา {$firstSlot} น.",
        ];
    }

    /**
     * ตรวจสอบว่าเวลาปัจจุบันตรงกับรอบเวลาใดรอบหนึ่งหรือไม่ (Within Grace Window)
     * 
     * @param int $graceMinutes ค่าคลาดเคลื่อนที่อนุญาตเป็นนาที (ค่าเริ่มต้น 15 นาที)
     * @return array [bool $isDue, ?string $slot, ?string $slotKey, ?string $reason]
     */
    public function evaluateCurrentSlot(int $graceMinutes = self::DEFAULT_GRACE_MINUTES): array {
        $now = $this->clock->now();
        $dateStr = $now->format('Y-m-d');
        $currentTs = $now->getTimestamp();
        $tz = $this->clock->getTimeZone();

        foreach (self::SCHEDULED_SLOTS as $slot) {
            $slotDt = new DateTimeImmutable("{$dateStr} {$slot}:00", $tz);
            $slotTs = $slotDt->getTimestamp();
            $slotKey = self::buildSlotKey($dateStr, $slot);

            // คำนวณช่วงเวลาที่อนุญาต: [slotTs - graceSec, slotTs + graceSec]
            $graceSec = $graceMinutes * 60;
            if ($currentTs >= ($slotTs - $graceSec) && $currentTs <= ($slotTs + $graceSec)) {
                // ตรวจสอบว่ารอบนี้เคยรันไปแล้วหรือไม่
                if ($this->isSlotExecuted($slotKey)) {
                    return [
                        'is_due'   => false,
                        'slot'     => $slot,
                        'slot_key' => $slotKey,
                        'reason'   => "รอบเวลา {$slot} น. ประจำวันที่ {$dateStr} ได้ดำเนินการไปแล้ว (Duplicate Run Blocked)",
                        'already_executed' => true,
                    ];
                }

                return [
                    'is_due'   => true,
                    'slot'     => $slot,
                    'slot_key' => $slotKey,
                    'reason'   => "ถึงรอบเวลา Sync ประจำรอบ {$slot} น.",
                    'already_executed' => false,
                ];
            }
        }

        return [
            'is_due'   => false,
            'slot'     => null,
            'slot_key' => null,
            'reason'   => "ไม่อยู่ในช่วงเวลาของรอบ Sync (รอบเวลา: " . implode(', ', self::SCHEDULED_SLOTS) . " น.)",
            'already_executed' => false,
        ];
    }

    /**
     * ตรวจสอบว่า Slot Key เคยถูกประมวลผลไปแล้วหรือไม่
     */
    public function isSlotExecuted(string $slotKey): bool {
        return isset($this->executedSlots[$slotKey]);
    }

    /**
     * ดำเนินการ Trigger รอบ Sync (หากเข้าเงื่อนไข) พร้อมป้องกันการรันซ้ำ
     * 
     * @param callable|null $jobAction ฟังก์ชันการทำงานของ Sync Job (ถ้ามี)
     * @param bool $force ข้ามการตรวจเงื่อนไขเวลา (สำหรับทดสอบ)
     * @return array ผลลัพธ์การประมวลผลรอบ
     */
    public function triggerSlotRun(?callable $jobAction = null, bool $force = false): array {
        $now = $this->clock->now();
        $dateStr = $now->format('Y-m-d');
        $eval = $this->evaluateCurrentSlot();

        if (!$force && !$eval['is_due']) {
            $status = !empty($eval['already_executed']) ? 'BLOCKED_DUPLICATE' : 'SKIPPED';
            return [
                'success'           => false,
                'executed'          => false,
                'status'            => $status,
                'reason'            => $eval['reason'],
                'slot'              => $eval['slot'],
                'slot_key'          => $eval['slot_key'],
                'timestamp'         => $now->format('Y-m-d H:i:s'),
                'already_executed'  => !empty($eval['already_executed']),
            ];
        }

        $slot = $eval['slot'] ?? $now->format('H:i');
        $slotKey = $eval['slot_key'] ?? self::buildSlotKey($dateStr, $slot);

        // ป้องกันการรันซ้ำรอบเดิม
        if (!$force && $this->isSlotExecuted($slotKey)) {
            return [
                'success'          => false,
                'executed'         => false,
                'status'           => 'BLOCKED_DUPLICATE',
                'reason'           => "รอบ {$slotKey} เคยถูกประมวลผลไปแล้ว ป้องกันการรันซ้ำในรอบเดียวกัน",
                'slot'             => $slot,
                'slot_key'         => $slotKey,
                'timestamp'        => $now->format('Y-m-d H:i:s'),
                'already_executed' => true,
            ];
        }

        // ประมวลผล Action (ถ้ามี)
        $jobResult = null;
        if ($jobAction !== null) {
            try {
                $jobResult = $jobAction($slot, $slotKey, $now);
            } catch (\Throwable $e) {
                $jobResult = ['error' => $e->getMessage()];
            }
        }

        // บันทึกสถานะว่ารันรอบนี้สำเร็จแล้ว
        $executionRecord = [
            'slot'        => $slot,
            'slot_key'    => $slotKey,
            'executed_at' => $now->format('Y-m-d H:i:s'),
            'timestamp'   => $now->getTimestamp(),
            'status'      => 'SUCCESS',
            'job_result'  => $jobResult,
            'mock_mode'   => true,
        ];

        $this->executedSlots[$slotKey] = $now->getTimestamp();
        $this->history[] = $executionRecord;

        return [
            'success'   => true,
            'executed'  => true,
            'status'    => 'COMPLETED',
            'slot'      => $slot,
            'slot_key'  => $slotKey,
            'timestamp' => $now->format('Y-m-d H:i:s'),
            'job_result'=> $jobResult,
            'message'   => "รอบ Sync {$slot} น. ({$slotKey}) ดำเนินการสำเร็จเรียบร้อย",
        ];
    }

    /**
     * ตรวจสอบรอบที่พลาด (Missed Slots Detection)
     * หาก Agent ไม่ได้ทำงานตามเวลาที่กำหนด ให้ตรวจสอบและรายงานรอบที่ตกค้าง
     * โดยรายงานเพื่อทราบและเตรียมพร้อม แต่ไม่รันย้อนหลังซ้อนกันหลายงานพร้อมกัน
     * 
     * @param DateTimeImmutable $sinceTime เวลาเริ่มต้นที่เริ่มหยุดทำงาน
     * @param DateTimeImmutable|null $untilTime เวลาสิ้นสุดที่ตรวจสอบ (ค่าเริ่มต้นคือเวลาปัจจุบันของ Clock)
     * @return array รายการรอบที่พลาดไป พร้อมสถานะ
     */
    public function detectMissedSlots(DateTimeImmutable $sinceTime, ?DateTimeImmutable $untilTime = null): array {
        $tz = $this->clock->getTimeZone();
        $since = $sinceTime->setTimezone($tz);
        $until = ($untilTime ?? $this->clock->now())->setTimezone($tz);

        if ($since >= $until) {
            return [];
        }

        $missed = [];
        $cursorDay = new DateTimeImmutable($since->format('Y-m-d'), $tz);
        $endDay = new DateTimeImmutable($until->format('Y-m-d'), $tz);

        while ($cursorDay <= $endDay) {
            $dateStr = $cursorDay->format('Y-m-d');
            foreach (self::SCHEDULED_SLOTS as $slot) {
                $slotDt = new DateTimeImmutable("{$dateStr} {$slot}:00", $tz);
                if ($slotDt > $since && $slotDt <= $until) {
                    $slotKey = self::buildSlotKey($dateStr, $slot);
                    $executed = $this->isSlotExecuted($slotKey);

                    if (!$executed) {
                        $missed[] = [
                            'date'        => $dateStr,
                            'slot'        => $slot,
                            'slot_key'    => $slotKey,
                            'scheduled_at'=> $slotDt->format('Y-m-d H:i:s'),
                            'status'      => 'MISSED',
                            'action_plan' => 'QUEUE_NEXT_ONLY_NO_OVERLAP', // ไม่รันย้อนหลังซ้อนทับกัน รอรอบถัดไปหรือรายงานสรุป
                        ];
                    }
                }
            }
            $cursorDay = $cursorDay->modify('+1 day');
        }

        return $missed;
    }

    /**
     * ดึงประวัติการรันทั้งหมด
     */
    public function getHistory(): array {
        return $this->history;
    }

    /**
     * ล้างประวัติ (สำหรับเริ่มการทดสอบใหม่)
     */
    public function clearHistory(): void {
        $this->history = [];
        $this->executedSlots = [];
    }

    /**
     * ดึงภาพรวมสถานะ Scheduler ปัจจุบัน
     */
    public function getStatusSummary(): array {
        $now = $this->clock->now();
        $next = $this->getNextRunTime();
        $eval = $this->evaluateCurrentSlot();

        return [
            'timezone'          => self::clockTzName(),
            'current_mock_time' => $now->format('Y-m-d H:i:s'),
            'scheduled_slots'   => self::SCHEDULED_SLOTS,
            'is_due_now'        => $eval['is_due'],
            'current_slot'      => $eval['slot'],
            'next_run'          => $next,
            'total_executed'    => count($this->executedSlots),
            'executed_slot_keys'=> array_keys($this->executedSlots),
            'mock_mode'         => true,
            'live_daemon'       => false, // รับรองว่าไม่มี background daemon จริง
            'status_badge'      => 'PREVIEW_ONLY',
        ];
    }

    private static function clockTzName(): string {
        return 'Asia/Bangkok (+07:00)';
    }
}
