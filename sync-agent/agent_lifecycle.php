<?php
/**
 * RxCore Sync Agent — Process Lifecycle & Single Instance Manager
 * Phase 3.8.1: Windows Agent Packaging & Deployment Readiness
 * 
 * หน้าที่และข้อกำหนด:
 * 1. Single Instance Protection:
 *    - ป้องกันการเปิด Service Host ซ้ำซ้อนโดยใช้ Lock File (PID + Timestamp + Heartbeat)
 *    - ตรวจสอบว่า Process ID เก่ายังทำงานอยู่จริงบน Windows หรือไม่ (Zombie / Crash detection)
 * 2. Graceful Shutdown Management:
 *    - รองรับการสั่งปิดโปรแกรมอย่างปลอดภัย (Graceful Shutdown Token / Signal)
 *    - รอให้ Mock Task ที่กำลังทำงานในรอบปัจจุบันเสร็จสิ้นก่อนหยุดโปรแกรม
 * 3. Process Status & Health Check:
 *    - รายงานสถานะ PID, Uptime, Memory Usage, Heartbeat, และ Health State
 * 4. File-based IPC (Inter-Process Communication):
 *    - สื่อสารระหว่าง CLI Host, Background Process, และ Web UI โดยไม่พึ่งพา Socket หรือ Extension พิเศษ
 */

class AgentLifecycle {

    public const LOCK_FILE_NAME    = 'agent_service.lock';
    public const SIGNAL_FILE_NAME  = 'agent_service.signal';
    public const HEARTBEAT_TIMEOUT = 60; // วินาทีที่ถือว่า Process ค้าง (Stale lock)

    private string $configDir;
    private string $lockFile;
    private string $signalFile;
    private ?int $acquiredPid = null;

    public function __construct(?string $configDir = null) {
        $this->configDir = $configDir ?? (__DIR__ . DIRECTORY_SEPARATOR . 'config');
        if (!is_dir($this->configDir)) {
            @mkdir($this->configDir, 0755, true);
        }
        $this->lockFile   = $this->configDir . DIRECTORY_SEPARATOR . self::LOCK_FILE_NAME;
        $this->signalFile = $this->configDir . DIRECTORY_SEPARATOR . self::SIGNAL_FILE_NAME;
    }

    /**
     * ดึง Path ของ Lock File
     */
    public function getLockFilePath(): string {
        return $this->lockFile;
    }

    /**
     * ดึง Path ของ Signal File
     */
    public function getSignalFilePath(): string {
        return $this->signalFile;
    }

    /**
     * พยายามขอจอง Single-Instance Lock
     * คืนค่า true หากจองสำเร็จ (ไม่มี Process อื่นทำงานอยู่)
     * คืนค่า false หากมี Process อื่นทำงานอยู่แล้ว
     */
    public function acquireLock(array $metadata = []): bool {
        if ($this->isAnotherInstanceRunning()) {
            return false;
        }

        // ล้าง Signal ตกค้างก่อนเริ่ม
        $this->clearShutdownSignal();

        $pid = getmypid();
        $payload = array_merge([
            'pid'        => $pid,
            'started_at' => date('Y-m-d H:i:s'),
            'heartbeat'  => time(),
            'status'     => 'RUNNING',
            'mode'       => 'mock',
            'php_version'=> PHP_VERSION,
            'os'         => PHP_OS_FAMILY,
            'hostname'   => gethostname(),
        ], $metadata);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $written = @file_put_contents($this->lockFile, $json, LOCK_EX);

        if ($written !== false) {
            $this->acquiredPid = $pid;
            return true;
        }

        return false;
    }

    /**
     * ตรวจสอบว่ามี Instance อื่นกำลังทำงานอยู่หรือไม่
     */
    public function isAnotherInstanceRunning(): bool {
        if (!file_exists($this->lockFile)) {
            return false;
        }

        $raw = @file_get_contents($this->lockFile);
        if ($raw === false || empty($raw)) {
            return false;
        }

        $data = @json_decode($raw, true);
        if (!is_array($data) || empty($data['pid'])) {
            return false;
        }

        $lockedPid = (int)$data['pid'];
        $currentPid = getmypid();

        // หากเป็น Process ปัจจุบันเอง
        if ($lockedPid === $currentPid && $this->acquiredPid === $currentPid) {
            return false;
        }

        // ตรวจสอบว่า PID ใน Lock File ยังมีชีวิตอยู่จริงหรือไม่
        if ($this->isPidAlive($lockedPid)) {
            // Process ยังมีชีวิตอยู่จริง
            return true;
        }

        // หาก PID ไม่อยู่แล้ว แสดงว่า Process เดิม Crash หรือถูก Kill กะทันหัน (Stale Lock)
        // สามารถเคลียร์ Lock เก่าทิ้งได้
        @unlink($this->lockFile);
        return false;
    }

    /**
     * อัปเดต Heartbeat เพื่อบอกว่า Process ยังมีชีวิตอยู่
     */
    public function updateHeartbeat(array $extraData = []): bool {
        if (!file_exists($this->lockFile)) {
            return false;
        }

        $raw = @file_get_contents($this->lockFile);
        $data = $raw ? @json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $data['heartbeat']     = time();
        $data['last_active']   = date('Y-m-d H:i:s');
        $data['memory_usage']  = memory_get_usage(true);
        $data['memory_peak']   = memory_get_peak_usage(true);

        foreach ($extraData as $k => $v) {
            $data[$k] = $v;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return (@file_put_contents($this->lockFile, $json, LOCK_EX) !== false);
    }

    /**
     * ปลด Lock เมื่อปิดโปรแกรมอย่างสมบูรณ์
     */
    public function releaseLock(): bool {
        if (!file_exists($this->lockFile)) {
            return true;
        }

        $raw = @file_get_contents($this->lockFile);
        $data = $raw ? @json_decode($raw, true) : null;

        // ปลด Lock เฉพาะเมื่อเราเป็นเจ้าของ PID หรือเจ้าของ PID ตายไปแล้ว
        if (is_array($data) && isset($data['pid'])) {
            $lockedPid = (int)$data['pid'];
            if ($lockedPid === getmypid() || !$this->isPidAlive($lockedPid)) {
                $this->clearShutdownSignal();
                $this->acquiredPid = null;
                return @unlink($this->lockFile);
            }
            return false;
        }

        return @unlink($this->lockFile);
    }

    /**
     * สั่งขอให้ Service Host ปิดการทำงานอย่างปลอดภัย (Graceful Shutdown Request)
     */
    public function requestShutdown(string $reason = 'Manual shutdown requested'): bool {
        $payload = [
            'signal'     => 'SHUTDOWN',
            'requested_at' => date('Y-m-d H:i:s'),
            'timestamp'  => time(),
            'reason'     => $reason,
            'requester_pid' => getmypid(),
        ];

        return (@file_put_contents($this->signalFile, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX) !== false);
    }

    /**
     * ตรวจสอบว่ามีคำสั่งขอให้ปิดโปรแกรมเข้ามาหรือไม่
     */
    public function isShutdownRequested(): bool {
        if (!file_exists($this->signalFile)) {
            return false;
        }

        $raw = @file_get_contents($this->signalFile);
        if (!$raw) {
            return false;
        }

        $data = @json_decode($raw, true);
        return is_array($data) && ($data['signal'] ?? '') === 'SHUTDOWN';
    }

    /**
     * ล้าง Signal File
     */
    public function clearShutdownSignal(): bool {
        if (file_exists($this->signalFile)) {
            return @unlink($this->signalFile);
        }
        return true;
    }

    /**
     * ดึงข้อมูลสถานะโดยรวมของ Agent Lifecycle
     */
    public function getStatus(): array {
        $isRunning = $this->isAnotherInstanceRunning();
        $lockData  = null;

        if (file_exists($this->lockFile)) {
            $raw = @file_get_contents($this->lockFile);
            $lockData = $raw ? @json_decode($raw, true) : null;
        }

        $pid = $lockData['pid'] ?? null;
        $startedAt = $lockData['started_at'] ?? null;
        $heartbeat = $lockData['heartbeat'] ?? null;
        $uptimeSeconds = $heartbeat ? (time() - ($lockData['started_timestamp'] ?? $heartbeat)) : 0;

        return [
            'is_running'         => $isRunning,
            'pid'                => $pid,
            'is_pid_alive'       => $pid ? $this->isPidAlive((int)$pid) : false,
            'started_at'         => $startedAt,
            'heartbeat'          => $heartbeat ? date('Y-m-d H:i:s', $heartbeat) : null,
            'seconds_since_beat' => $heartbeat ? (time() - $heartbeat) : null,
            'shutdown_requested' => $this->isShutdownRequested(),
            'metadata'           => $lockData,
            'lock_file'          => $this->lockFile,
            'signal_file'        => $this->signalFile,
        ];
    }

    /**
     * ตรวจสอบว่า PID ที่ระบุยังมี Process ทำงานอยู่จริงบนระบบหรือไม่
     * รองรับทั้ง Windows (tasklist) และ Unix (posix/kill)
     */
    public function isPidAlive(int $pid): bool {
        if ($pid <= 0) {
            return false;
        }

        // ตรวจสอบตนเอง
        if ($pid === getmypid()) {
            return true;
        }

        // บน Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = sprintf('tasklist /FI "PID eq %d" /NH 2>NUL', $pid);
            $output = [];
            @exec($cmd, $output);
            $combined = implode("\n", $output);
            
            // หาก PID ยังอยู่ tasklist จะแสดงบรรทัดที่มี PID นั้น
            // หากไม่อยู่ tasklist จะแสดง "INFO: No tasks are running which match the specified criteria."
            if (stripos($combined, (string)$pid) !== false && stripos($combined, 'No tasks are running') === false) {
                return true;
            }
            return false;
        }

        // บน Unix/Linux
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return file_exists("/proc/{$pid}");
    }
}
