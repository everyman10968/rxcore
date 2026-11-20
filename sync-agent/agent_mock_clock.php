<?php
/**
 * RxCore Sync Agent — Mock Clock Module
 * Phase 3.6: Scheduler, Retry & Reconciliation (Mock Mode)
 * 
 * หน้าที่:
 * 1. ควบคุมและจำลองเวลา (Virtual Clock) สำหรับทดสอบระบบตารางเวลาและ Retry โดยไม่ต้องรอเวลาจริง
 * 2. บังคับใช้ Timezone 'Asia/Bangkok' (+07:00) ตามข้อกำหนดของประเทศไทย
 * 3. รองรับการก้าวเดินเวลาไปข้างหน้า (advance) และการกำหนดเวลาเฉพาะเจาะจง (setTime)
 * 4. รับประกันว่าจะไม่มีการเรียก sleep() จริงในระหว่างการทดสอบ Automated Tests
 */

class AgentMockClock {

    public const TIMEZONE = 'Asia/Bangkok';

    private DateTimeImmutable $currentTime;
    private DateTimeZone $tz;

    /**
     * @param string|DateTimeInterface|null $initialTime เวลาเริ่มต้น (หากไม่ระบุจะใช้วันที่ปัจจุบัน เวลา 00:00:00 ใน Asia/Bangkok)
     */
    public function __construct($initialTime = null) {
        $this->tz = new DateTimeZone(self::TIMEZONE);
        if ($initialTime instanceof DateTimeInterface) {
            $this->currentTime = DateTimeImmutable::createFromInterface($initialTime)->setTimezone($this->tz);
        } elseif (is_string($initialTime) && trim($initialTime) !== '') {
            $this->currentTime = new DateTimeImmutable($initialTime, $this->tz);
        } else {
            $this->currentTime = new DateTimeImmutable('now', $this->tz);
        }
    }

    /**
     * ดึง TimeZone Object
     */
    public function getTimeZone(): DateTimeZone {
        return $this->tz;
    }

    /**
     * ดึงเวลาจำลองปัจจุบัน
     */
    public function now(): DateTimeImmutable {
        return $this->currentTime;
    }

    /**
     * กำหนดเวลาจำลองใหม่
     * 
     * @param string|DateTimeInterface $time
     */
    public function setTime($time): self {
        if ($time instanceof DateTimeInterface) {
            $this->currentTime = DateTimeImmutable::createFromInterface($time)->setTimezone($this->tz);
        } else {
            $this->currentTime = new DateTimeImmutable((string)$time, $this->tz);
        }
        return $this;
    }

    /**
     * ก้าวเวลาไปข้างหน้าเป็นวินาที
     * 
     * @param int|float $seconds
     */
    public function advanceSeconds(float $seconds): self {
        $sec = (int)floor($seconds);
        $micro = (int)round(($seconds - $sec) * 1000000);
        $intervalSpec = "PT{$sec}S";
        $this->currentTime = $this->currentTime->modify("+{$sec} seconds");
        if ($micro > 0) {
            $this->currentTime = $this->currentTime->modify("+{$micro} microseconds");
        }
        return $this;
    }

    /**
     * ก้าวเวลาไปข้างหน้าเป็นนาที
     */
    public function advanceMinutes(int $minutes): self {
        $this->currentTime = $this->currentTime->modify("+{$minutes} minutes");
        return $this;
    }

    /**
     * ก้าวเวลาไปข้างหน้าเป็นชั่วโมง
     */
    public function advanceHours(int $hours): self {
        $this->currentTime = $this->currentTime->modify("+{$hours} hours");
        return $this;
    }

    /**
     * ก้าวเวลาไปข้างหน้าเป็นวัน
     */
    public function advanceDays(int $days): self {
        $this->currentTime = $this->currentTime->modify("+{$days} days");
        return $this;
    }

    /**
     * คืนค่าเวลาในรูปแบบ ISO 8601 หรือรูปแบบที่กำหนด
     */
    public function format(string $format = 'Y-m-d H:i:s'): string {
        return $this->currentTime->format($format);
    }

    /**
     * ดึง Timestamp ปัจจุบัน (Unix Epoch Seconds)
     */
    public function getTimestamp(): int {
        return $this->currentTime->getTimestamp();
    }
}
