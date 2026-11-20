<?php
/**
 * RxCore Sync Agent — Windows Service Host & Worker Daemon
 * Phase 3.8.1: Windows Agent Packaging & Deployment Readiness
 * 
 * ข้อกำหนดและมาตรการความปลอดภัย:
 * 1. CLI Only: ทำงานผ่าน Command Line Interface เท่านั้น
 * 2. Decoupled Architecture: แยก Service Host ออกจาก Web UI ชัดเจน
 * 3. Strict Mock Mode Only:
 *    - ห้ามเชื่อมต่อ HOSxP Production
 *    - ห้ามส่งข้อมูลไปยัง Supabase Cloud
 *    - ห้ามยิง MOPH Notify API จริง
 * 4. Single Instance: ป้องกันการทำงานซ้ำซ้อนด้วย AgentLifecycle
 * 5. Graceful Shutdown: หยุดการทำงานอย่างปลอดภัยเมื่อได้รับ Shutdown Signal
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: agent_service_host.php can only be executed via CLI.\n");
}

// โหมดความปลอดภัยสูงสุด: บังคับ Mock Mode ตลอด Phase 3.8.1
define('RXCORE_STRICT_MOCK_MODE', true);

require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_lifecycle.php';
require_once __DIR__ . '/agent_env_check.php';
require_once __DIR__ . '/agent_sync_event.php';
require_once __DIR__ . '/agent_moph_notify.php';
require_once __DIR__ . '/agent_sync_monitor.php';

class AgentServiceHost {

    private AgentLifecycle $lifecycle;
    private AgentSyncMonitor $monitor;
    private AgentConfig $configManager;
    private string $baseDir;

    public function __construct(?string $baseDir = null) {
        $this->baseDir = $baseDir ?? __DIR__;
        $configDir = $this->baseDir . DIRECTORY_SEPARATOR . 'config';
        $this->lifecycle = new AgentLifecycle($configDir);
        $this->configManager = new AgentConfig($configDir);

        // โหมดทดสอบปลอดภัย: ใช้ Mock Notify Adapter เสมอ
        $eventsFile = $configDir . DIRECTORY_SEPARATOR . 'mock_sync_events.json';
        $stateFile  = $configDir . DIRECTORY_SEPARATOR . 'mock_sync_monitor_state.json';
        $eventLog   = new AgentSyncEventLog($eventsFile);
        $this->monitor = new AgentSyncMonitor($stateFile, $eventLog);
    }

    public function getLifecycle(): AgentLifecycle {
        return $this->lifecycle;
    }

    public function getMonitor(): AgentSyncMonitor {
        return $this->monitor;
    }

    /**
     * เริ่มการทำงานของ Service Host Worker Loop
     */
    public function start(int $intervalSeconds = 5, int $maxCycles = 0, ?callable $onCycle = null): int {
        echo "====================================================\n";
        echo "  RxCore Sync Agent — Service Host (Phase 3.8.1)\n";
        echo "  Mode: MOCK TEST ONLY (No External Connections)\n";
        echo "====================================================\n";

        // 1. ตรวจสอบ Configuration ความปลอดภัย
        $config = $this->configManager->load();
        $hcode = $config['hcode'] ?? 'MOCK_HOSPITAL';
        echo "[INFO] Hospital Code: {$hcode}\n";
        echo "[INFO] Agent Mode: MOCK ONLY\n";
        echo "[INFO] Process ID: " . getmypid() . "\n";

        // 2. ขอ Single Instance Lock
        if (!$this->lifecycle->acquireLock([
            'hcode'     => $hcode,
            'service'   => 'RxCoreSyncAgentService',
            'started_by'=> 'CLI Host',
        ])) {
            $status = $this->lifecycle->getStatus();
            echo "[ERROR] Another instance is already running with PID {$status['pid']}.\n";
            echo "        Only one background service instance is permitted.\n";
            return 1;
        }

        echo "[SUCCESS] Single instance lock acquired successfully.\n";
        echo "[INFO] Service Host started. Press Ctrl+C or run 'agent_service_host.php --stop' to terminate.\n\n";

        // บันทึก Event เริ่มต้น Service
        $this->monitor->getEventLog()->record(
            'AGENT_SERVICE_STARTED',
            'RxCore Agent Service Host started in Mock Mode',
            AgentSyncEvent::SEVERITY_INFO,
            ['pid' => getmypid(), 'hcode' => $hcode]
        );

        $cycle = 0;
        $shouldExit = false;

        while (!$shouldExit) {
            $cycle++;

            // ตรวจสอบ Graceful Shutdown Signal ก่อนเริ่มรอบ
            if ($this->lifecycle->isShutdownRequested()) {
                echo "\n[INFO] Graceful shutdown signal detected before Cycle #{$cycle}. Initiating shutdown...\n";
                break;
            }

            $cycleStart = microtime(true);
            $timestampStr = date('Y-m-d H:i:s');
            echo "[{$timestampStr}] [Cycle #{$cycle}] Executing mock sync audit... ";

            // ทำ Mock Task แบบปลอดภัย (ไม่เชื่อมต่อฐานข้อมูลภายนอก)
            $sessionId = 'mock_sess_' . date('Ymd_His') . '_' . $cycle;
            $this->monitor->startSession('SCHEDULED', $sessionId, false);

            // จำลองการประมวลผลงานย่อย
            usleep(50000); // 50 ms

            // ตรวจสอบ Shutdown Signal ระหว่างงาน (Mid-task check)
            $shutdownDuringTask = $this->lifecycle->isShutdownRequested();

            // จบ Mock Session
            $this->monitor->completeSession(
                AgentSyncMonitor::STATUS_SUCCESS,
                [
                    'records_extracted' => 15,
                    'records_synced'    => 15,
                    'records_failed'    => 0,
                    'status_message'    => 'Mock sync cycle completed safely in local sandbox',
                ]
            );

            $duration = round((microtime(true) - $cycleStart) * 1000, 2);
            echo "DONE ({$duration} ms)\n";

            // อัปเดต Heartbeat
            $this->lifecycle->updateHeartbeat([
                'last_cycle'       => $cycle,
                'last_cycle_ms'    => $duration,
                'last_session_id'  => $sessionId,
            ]);

            if ($onCycle) {
                call_user_func($onCycle, $cycle, $sessionId);
            }

            if ($shutdownDuringTask) {
                echo "[INFO] Shutdown signal detected during task execution. Completing current cycle safely.\n";
                break;
            }

            if ($maxCycles > 0 && $cycle >= $maxCycles) {
                echo "[INFO] Reached maximum cycles limit ({$maxCycles}). Exiting normally.\n";
                break;
            }

            // หลับตามระยะเวลาที่กำหนด โดยตรวจ signal ย่อยทุก 500ms
            $sleepRemaining = $intervalSeconds;
            while ($sleepRemaining > 0) {
                if ($this->lifecycle->isShutdownRequested()) {
                    $shouldExit = true;
                    break;
                }
                $slice = min(0.5, $sleepRemaining);
                usleep((int)($slice * 1000000));
                $sleepRemaining -= $slice;
            }
        }

        // ปิดการทำงานและปลด Lock อย่างปลอดภัย
        echo "\n[INFO] Performing graceful cleanup...\n";
        $this->monitor->getEventLog()->record(
            'AGENT_SERVICE_STOPPED',
            'RxCore Agent Service Host stopped gracefully',
            AgentSyncEvent::SEVERITY_INFO,
            ['pid' => getmypid(), 'cycles_completed' => $cycle]
        );

        $this->lifecycle->releaseLock();
        echo "[SUCCESS] Service stopped gracefully and lock released.\n";
        return 0;
    }

    /**
     * สั่งรันรอบเดียว (One-off Mock Cycle)
     */
    public function runOnce(): int {
        echo "--- RxCore Sync Agent: Run-Once Mock Cycle ---\n";
        $config = $this->configManager->load();
        $hcode = $config['hcode'] ?? 'MOCK_HOSPITAL';

        $sessionId = 'mock_oneoff_' . date('Ymd_His');
        echo "[INFO] Starting Mock Session: {$sessionId} (HCODE: {$hcode})\n";

        $this->monitor->startSession('MANUAL_SYNC', $sessionId, false);
        usleep(30000);
        $this->monitor->completeSession(
            AgentSyncMonitor::STATUS_SUCCESS,
            [
                'records_extracted' => 10,
                'records_synced'    => 10,
                'records_failed'    => 0,
                'status_message'    => 'One-off mock cycle completed successfully',
            ]
        );

        echo "[SUCCESS] Run-once cycle finished without errors.\n";
        return 0;
    }

    /**
     * สั่งหยุด Service Host ที่กำลังทำงานอยู่ (Graceful Stop)
     */
    public function stop(): int {
        echo "--- RxCore Sync Agent: Requesting Graceful Stop ---\n";
        $status = $this->lifecycle->getStatus();

        if (!$status['is_running']) {
            echo "[INFO] No running agent instance found.\n";
            $this->lifecycle->clearShutdownSignal();
            return 0;
        }

        $pid = $status['pid'];
        echo "[INFO] Sending shutdown signal to running agent PID {$pid}...\n";
        $this->lifecycle->requestShutdown('CLI stop command invoked');

        // รอให้ Process ยอมปิดตัวไม่เกิน 5 วินาที
        $waitMax = 10;
        $waited = 0;
        while ($this->lifecycle->isPidAlive((int)$pid) && $waited < $waitMax) {
            usleep(500000); // 0.5 sec
            $waited += 0.5;
            echo ".";
        }
        echo "\n";

        if ($this->lifecycle->isPidAlive((int)$pid)) {
            echo "[WARNING] Agent PID {$pid} is still finishing tasks. Signal is set and it will terminate shortly.\n";
            return 2;
        }

        // ปลด Lock หากกระบวนการเสร็จสิ้น
        $this->lifecycle->releaseLock();
        echo "[SUCCESS] Agent PID {$pid} has stopped successfully.\n";
        return 0;
    }

    /**
     * แสดงสถานะปัจจุบันของ Service Host
     */
    public function showStatus(): int {
        echo "====================================================\n";
        echo "  RxCore Sync Agent — Status Report\n";
        echo "====================================================\n";

        $status = $this->lifecycle->getStatus();
        $config = $this->configManager->load();

        echo "Agent Mode:          " . ($config['agent_mode'] ?? 'mock') . " (Strict Mock Mode Active)\n";
        echo "Hospital Code:       " . ($config['hcode'] ?? 'N/A') . "\n";
        echo "Service Running:     " . ($status['is_running'] ? 'YES [ACTIVE]' : 'NO [STOPPED]') . "\n";

        if ($status['is_running']) {
            echo "Process ID (PID):    " . ($status['pid'] ?? 'N/A') . "\n";
            echo "Started At:          " . ($status['started_at'] ?? 'N/A') . "\n";
            echo "Last Heartbeat:      " . ($status['heartbeat'] ?? 'N/A') . " (" . ($status['seconds_since_beat'] ?? 0) . "s ago)\n";
            if (!empty($status['metadata']['memory_usage'])) {
                echo "Memory Usage:        " . round($status['metadata']['memory_usage'] / 1024 / 1024, 2) . " MB\n";
            }
        }

        echo "Shutdown Requested:  " . ($status['shutdown_requested'] ? 'YES' : 'NO') . "\n";

        // แสดง Monitor Summary ล่าสุด
        $monStatus = $this->monitor->getCurrentStatus();
        $cards = $this->monitor->getSummaryCards();
        $lastCompleted = $monStatus['last_completed_session'] ?? null;

        echo "\n--- Sync Monitor State ---\n";
        echo "Current State:       " . ($monStatus['status'] ?? 'IDLE') . "\n";
        echo "Total Synced Today:  " . ($cards['total_synced_today']['value'] ?? 0) . "\n";
        echo "Last Sync Status:    " . ($lastCompleted['status'] ?? 'NONE') . "\n";
        echo "Last Sync Message:   " . ($lastCompleted['status_message'] ?? 'N/A') . "\n";

        return 0;
    }

    /**
     * ตรวจสอบสภาพแวดล้อมระบบ (Environment Audit)
     */
    public function checkEnvironment(): int {
        echo "====================================================\n";
        echo "  RxCore Sync Agent — Environment Audit\n";
        echo "====================================================\n";

        $audit = AgentEnvironmentChecker::checkAll($this->baseDir);

        echo "PHP Version:         " . $audit['php_version']['current_version'] . " (" . ($audit['php_version']['passed'] ? 'PASS' : 'FAIL') . ")\n";
        echo "Operating System:    " . $audit['operating_system']['os_name'] . " (" . $audit['operating_system']['os_version'] . ")\n";
        echo "\nRequired Extensions:\n";
        foreach ($audit['extensions']['details'] as $ext => $item) {
            $badge = $item['loaded'] ? '[OK]  ' : '[FAIL]';
            echo "  {$badge} {$ext}: {$item['description']}\n";
        }

        echo "\nPath & File Compatibility:\n";
        $pDetails = $audit['path_compatibility']['details'];
        echo "  Space in Path:       " . ($pDetails['space_path_io']['passed'] ? 'PASS' : 'FAIL') . "\n";
        echo "  Thai (Unicode) Path: " . ($pDetails['thai_unicode_path_io']['passed'] ? 'PASS' : 'FAIL') . "\n";

        echo "\nDirectory Permissions:\n";
        $dDetails = $audit['directory_permissions']['details'];
        echo "  config/ Writable:    " . ($dDetails['config']['writable'] ? 'PASS' : 'FAIL') . "\n";
        echo "  logs/ Writable:      " . ($dDetails['logs']['writable'] ? 'PASS' : 'FAIL') . "\n";

        echo "\nOverall Audit Result: " . ($audit['success'] ? '>>> READY FOR DEPLOYMENT <<<' : '>>> ISSUES DETECTED <<<') . "\n";
        return $audit['success'] ? 0 : 1;
    }
}

// ตรวจสอบการเรียกตรงผ่าน CLI
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $options = getopt('', ['start', 'stop', 'status', 'run-once', 'check-env', 'help', 'interval:', 'cycles:']);
    $host = new AgentServiceHost();

    if (isset($options['help']) || empty($options)) {
        echo "Usage: php agent_service_host.php [options]\n\n";
        echo "Options:\n";
        echo "  --start              Start the agent worker loop in Mock Mode\n";
        echo "  --run-once           Execute a single mock cycle and exit\n";
        echo "  --stop               Signal running service instance to shut down gracefully\n";
        echo "  --status             Display current agent status and monitor state\n";
        echo "  --check-env          Run full Windows environment and dependency audit\n";
        echo "  --interval=<sec>     Interval between mock cycles in seconds (default: 5)\n";
        echo "  --cycles=<num>       Stop after N cycles (default: 0 = infinite)\n";
        echo "  --help               Display this help guide\n\n";
        exit(0);
    }

    if (isset($options['check-env'])) {
        exit($host->checkEnvironment());
    }

    if (isset($options['status'])) {
        exit($host->showStatus());
    }

    if (isset($options['stop'])) {
        exit($host->stop());
    }

    if (isset($options['run-once'])) {
        exit($host->runOnce());
    }

    if (isset($options['start'])) {
        $interval = isset($options['interval']) ? (int)$options['interval'] : 5;
        $cycles   = isset($options['cycles']) ? (int)$options['cycles'] : 0;
        exit($host->start($interval, $cycles));
    }
}
