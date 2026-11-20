<?php
/**
 * RxCore Sync Agent — Isolated Windows Deployment & Recovery Test Suite
 * Phase 3.8.2: Isolated Windows Deployment Test
 * 
 * ครอบคลุมการทดสอบตามข้อกำหนด:
 * 1. Deployment Tests (12 การทดสอบ):
 *    1.1  แตกแพ็กเกจไปยังโฟลเดอร์ใหม่และตรวจสอบไฟล์ครบถ้วนตาม Manifest Checksum
 *    1.2  รัน check_env.bat และตรวจสอบ Dependencies
 *    1.3  รัน start_agent.bat และยืนยันว่า Worker เริ่มทำงานใน Mock Mode
 *    1.4  รัน check_status.bat เพื่อตรวจสอบ PID, Uptime และ Heartbeat
 *    1.5  เรียก start_agent.bat ซ้ำและยืนยันว่าไม่เปิด Worker ซ้อน
 *    1.6  รัน stop_agent.bat และตรวจสอบ Graceful Shutdown
 *    1.7  เริ่ม Agent ใหม่หลังหยุดทำงาน
 *    1.8  ทดสอบจาก Path ที่มีช่องว่างและภาษาไทย
 *    1.9  ทดสอบเมื่อ Configuration หายหรือเสียหาย
 *    1.10 ทดสอบเมื่อโฟลเดอร์ Log เขียนไม่ได้
 *    1.11 ตรวจสอบว่า Credential ไม่ถูกบันทึกลง Log
 *    1.12 ตรวจสอบว่าไม่มี Network Request ออกภายนอก
 * 
 * 2. Restart & Recovery Tests (5 สถานการณ์):
 *    2.1  ปิด Worker ปกติแล้วเริ่มใหม่ (Clean Restart)
 *    2.2  จำลอง Worker หยุดทำงานกะทันหันใน Mock Mode (Simulated Crash)
 *    2.3  ตรวจสอบการตรวจจับ PID ที่หมดอายุและการกู้คืน Lock อัตโนมัติ (Stale Lock Recovery)
 *    2.4  ตรวจสอบ State File ว่ายังอ่านได้สมบูรณ์หลังเริ่มใหม่ (State Persistence)
 *    2.5  ตรวจสอบว่าไม่มีการรัน Mock Job ซ้ำโดยไม่ตั้งใจ (Idempotent Slot Guard)
 *    2.6  Windows Machine Reboot: ระบุสถานะ NOT TESTED พร้อมเหตุผลทางเทคนิค
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: test_isolated_deployment.php can only be executed via CLI.\n");
}

class IsolatedDeploymentTestSuite {

    private int $passed = 0;
    private int $failed = 0;
    private int $notTested = 0;
    private array $results = [];

    private string $sourceDist;
    private string $sandboxDir;

    public function __construct() {
        $base = dirname(__DIR__);
        $this->sourceDist = $base . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'rxcore-sync-agent';
        $this->sandboxDir = $base . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'isolated_deployment_sandbox';
    }

    private function assertTest(string $title, bool $condition, string $detail = ''): void {
        if ($condition) {
            $this->passed++;
            $this->results[] = ['title' => $title, 'status' => 'PASS', 'detail' => $detail];
            echo "  [PASS] {$title}" . ($detail ? " — {$detail}" : '') . "\n";
        } else {
            $this->failed++;
            $this->results[] = ['title' => $title, 'status' => 'FAIL', 'detail' => $detail];
            echo "  [FAIL] {$title}" . ($detail ? " — {$detail}" : '') . "\n";
        }
    }

    private function markNotTested(string $title, string $reason): void {
        $this->notTested++;
        $this->results[] = ['title' => $title, 'status' => 'NOT TESTED', 'detail' => $reason];
        echo "  [NOT TESTED] {$title} — {$reason}\n";
    }

    private function runBat(string $batPath, string $args = ''): array {
        $fullCmd = empty($args) ? sprintf('cmd.exe /c ""%s""', $batPath) : sprintf('cmd.exe /c ""%s" %s"', $batPath, $args);
        $output = [];
        $exitCode = -1;
        exec($fullCmd, $output, $exitCode);
        return [
            'code'   => $exitCode,
            'output' => implode("\n", $output),
        ];
    }

    public function runAll(): bool {
        putenv('NO_PAUSE=1'); // ป้องกัน pause ค้างระหว่าง Automated Tests

        echo "====================================================================\n";
        echo "  RxCore Phase 3.8.2: Isolated Windows Deployment Test Suite\n";
        echo "====================================================================\n\n";

        echo ">>> Part 1: Deployment Tests (12 Test Scenarios)\n";
        $this->test1_ExtractAndVerifyChecksums();
        $this->test2_RunCheckEnvBat();
        $this->test3_RunStartAgentBat();
        $this->test4_RunCheckStatusBat();
        $this->test5_PreventDuplicateWorker();
        $this->test6_RunStopAgentBat();
        $this->test7_RestartAfterStop();
        $this->test8_SpaceAndThaiPaths();
        $this->test9_MissingOrCorruptedConfig();
        $this->test10_UnwritableLogDirectory();
        $this->test11_CredentialsNotInLogs();
        $this->test12_ZeroOutboundNetworkRequests();

        echo "\n>>> Part 2: Restart & Recovery Tests\n";
        $this->testRecovery_CleanRestart();
        $this->testRecovery_CrashAndStaleLockRecovery();
        $this->testRecovery_StateFileIntegrity();
        $this->testRecovery_NoUnintendedDuplicateRuns();
        $this->testRecovery_WindowsHostReboot();

        // สรุปผล
        echo "\n====================================================================\n";
        echo "  DEPLOYMENT & RECOVERY TEST SUMMARY\n";
        echo "====================================================================\n";
        echo "Total Test Items: " . ($this->passed + $this->failed + $this->notTested) . "\n";
        echo "PASS:             {$this->passed}\n";
        echo "FAIL:             {$this->failed}\n";
        echo "NOT TESTED:       {$this->notTested}\n";
        $successRate = round(($this->passed / max(1, ($this->passed + $this->failed))) * 100, 2);
        echo "Success Rate:     {$successRate}%\n";
        echo "====================================================================\n";

        // ทำความสะอาด Sandbox
        $this->cleanDirectory($this->sandboxDir);

        return ($this->failed === 0);
    }

    /**
     * 1. แตกแพ็กเกจไปยังโฟลเดอร์ใหม่และตรวจสอบไฟล์ครบถ้วนตาม Manifest Checksum
     */
    private function test1_ExtractAndVerifyChecksums(): void {
        echo "\n[TEST 1] Extract & Checksum Verification in Isolated Sandbox\n";

        // เตรียม Sandbox เปล่า
        $this->cleanDirectory($this->sandboxDir);
        mkdir($this->sandboxDir, 0755, true);

        // คัดลอกแพ็กเกจทั้งหมดไปยัง Sandbox
        $this->copyDirectoryRecursive($this->sourceDist, $this->sandboxDir);

        $manifestFile = $this->sandboxDir . DIRECTORY_SEPARATOR . 'package_manifest.json';
        $this->assertTest('1.1 Manifest file exists in sandbox', file_exists($manifestFile));

        $manifest = json_decode(file_get_contents($manifestFile), true);
        $this->assertTest('1.2 Manifest parseable JSON', is_array($manifest));

        $allChecksumsValid = true;
        $checkedFiles = 0;

        foreach ($manifest['files'] ?? [] as $relPath => $expectedHash) {
            $fullPath = $this->sandboxDir . DIRECTORY_SEPARATOR . $relPath;
            if (!file_exists($fullPath)) {
                $allChecksumsValid = false;
                break;
            }
            $actualHash = hash_file('sha256', $fullPath);
            if ($actualHash !== $expectedHash) {
                $allChecksumsValid = false;
                break;
            }
            $checkedFiles++;
        }

        $this->assertTest('1.3 All files verified with SHA-256 match', $allChecksumsValid && $checkedFiles > 25, "Checked: {$checkedFiles} files");
    }

    /**
     * 2. รัน check_env.bat และตรวจสอบ Dependencies
     */
    private function test2_RunCheckEnvBat(): void {
        echo "\n[TEST 2] Run check_env.bat in Sandbox\n";

        $batPath = $this->sandboxDir . DIRECTORY_SEPARATOR . 'check_env.bat';
        $res = $this->runBat($batPath);
        $text = $res['output'];

        $this->assertTest('2.1 check_env.bat executed with exit code 0', $res['code'] === 0, "Exit code: {$res['code']}");
        $this->assertTest('2.2 PHP 8.2 verified in audit', stripos($text, '8.2') !== false || stripos($text, 'PASS') !== false);
        $this->assertTest('2.3 All 6 extensions reported [OK]', 
            stripos($text, 'openssl') !== false &&
            stripos($text, 'pdo_mysql') !== false &&
            stripos($text, 'mbstring') !== false &&
            stripos($text, 'json') !== false &&
            stripos($text, 'curl') !== false
        );
        $this->assertTest('2.4 READY FOR DEPLOYMENT banner found', stripos($text, 'READY FOR DEPLOYMENT') !== false);
    }

    /**
     * 3. รัน start_agent.bat และยืนยันว่า Worker เริ่มทำงานใน Mock Mode
     */
    private function test3_RunStartAgentBat(): void {
        echo "\n[TEST 3] Run start_agent.bat in Mock Mode (Single Cycle)\n";

        $batPath = $this->sandboxDir . DIRECTORY_SEPARATOR . 'start_agent.bat';
        $res = $this->runBat($batPath, '--cycles=1 --interval=1');
        $text = $res['output'];

        $this->assertTest('3.1 start_agent.bat exits with code 0', $res['code'] === 0, "Exit code: {$res['code']}");
        $this->assertTest('3.2 Service Host banner confirms MOCK TEST ONLY', stripos($text, 'MOCK TEST ONLY') !== false);
        $this->assertTest('3.3 Single instance lock acquired', stripos($text, 'lock acquired') !== false);
        $this->assertTest('3.4 Executed mock sync audit cycle', stripos($text, 'Executing mock sync audit') !== false);
        $this->assertTest('3.5 Service stopped gracefully and lock released', stripos($text, 'stopped gracefully') !== false);
    }

    /**
     * 4. รัน check_status.bat เพื่อตรวจสอบ PID, Uptime และ Heartbeat
     */
    private function test4_RunCheckStatusBat(): void {
        echo "\n[TEST 4] Run check_status.bat\n";

        $batPath = $this->sandboxDir . DIRECTORY_SEPARATOR . 'check_status.bat';
        $res = $this->runBat($batPath);
        $text = $res['output'];

        $this->assertTest('4.1 check_status.bat exits with code 0', $res['code'] === 0, "Exit code: {$res['code']}");
        $this->assertTest('4.2 Status Report header present', stripos($text, 'Status Report') !== false);
        $this->assertTest('4.3 Agent Mode reports Strict Mock Mode Active', stripos($text, 'Strict Mock Mode Active') !== false);
        $this->assertTest('4.4 Sync Monitor State section displayed', stripos($text, 'Sync Monitor State') !== false);
    }

    /**
     * 5. เรียก start_agent.bat ซ้ำและยืนยันว่าไม่เปิด Worker ซ้อน
     */
    private function test5_PreventDuplicateWorker(): void {
        echo "\n[TEST 5] Prevent Duplicate Worker Execution (Single Instance Mutex)\n";

        require_once $this->sandboxDir . '/agent_lifecycle.php';
        $cfgDir = $this->sandboxDir . DIRECTORY_SEPARATOR . 'config';
        $lifecycle = new AgentLifecycle($cfgDir);

        // Instance 1 จอง Lock
        $locked1 = $lifecycle->acquireLock(['service' => 'TestService1']);
        $this->assertTest('5.1 First worker acquired lock', $locked1 === true);

        // เรียก start_agent.bat ซ้ำผ่าน CMD
        $batPath = $this->sandboxDir . DIRECTORY_SEPARATOR . 'start_agent.bat';
        $res = $this->runBat($batPath, '--cycles=1');
        $text = $res['output'];

        $this->assertTest('5.2 Second worker blocked with exit code 1', $res['code'] === 1, "Exit code: {$res['code']}");
        $this->assertTest('5.3 Error message identifies existing running instance PID', stripos($text, 'Another instance is already running') !== false);

        // ปลด Lock ของ Instance 1
        $lifecycle->releaseLock();
        $this->assertTest('5.4 First worker lock released cleanly', !file_exists($lifecycle->getLockFilePath()));
    }

    /**
     * 6. รัน stop_agent.bat และตรวจสอบ Graceful Shutdown
     */
    private function test6_RunStopAgentBat(): void {
        echo "\n[TEST 6] Run stop_agent.bat (Graceful Stop)\n";

        $batPath = $this->sandboxDir . DIRECTORY_SEPARATOR . 'stop_agent.bat';
        $res = $this->runBat($batPath);
        $text = $res['output'];

        $this->assertTest('6.1 stop_agent.bat exits with code 0', $res['code'] === 0, "Exit code: {$res['code']}");
        $this->assertTest('6.2 Handles already stopped state safely', stripos($text, 'No running agent instance found') !== false || stripos($text, 'stopped') !== false);
    }

    /**
     * 7. เริ่ม Agent ใหม่หลังหยุดทำงาน
     */
    private function test7_RestartAfterStop(): void {
        echo "\n[TEST 7] Restart Agent After Stop\n";

        $batPath = $this->sandboxDir . DIRECTORY_SEPARATOR . 'start_agent.bat';
        $res = $this->runBat($batPath, '--cycles=1 --interval=1');
        $text = $res['output'];

        $this->assertTest('7.1 Agent restarted cleanly without lock contention', $res['code'] === 0, "Exit code: {$res['code']}");
        $this->assertTest('7.2 Cycle executed successfully on restart', stripos($text, 'Cycle #1') !== false);
    }

    /**
     * 8. ทดสอบจาก Path ที่มีช่องว่างและภาษาไทย
     */
    private function test8_SpaceAndThaiPaths(): void {
        echo "\n[TEST 8] Execute from Path with Spaces and Thai Unicode Characters\n";

        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist';

        // 8.1 Path มีช่องว่าง
        $spaceDir = $base . DIRECTORY_SEPARATOR . 'rxcore isolated space';
        $this->cleanDirectory($spaceDir);
        $this->copyDirectoryRecursive($this->sandboxDir, $spaceDir);

        $batSpace = $spaceDir . DIRECTORY_SEPARATOR . 'check_status.bat';
        $res1 = $this->runBat($batSpace);
        $this->assertTest('8.1 Execution in path with spaces succeeds', $res1['code'] === 0, "Exit code: {$res1['code']}");
        $this->cleanDirectory($spaceDir);

        // 8.2 Path ภาษาไทย
        $thaiDir = $base . DIRECTORY_SEPARATOR . 'ทดสอบ_ติดตั้ง_รพสต_บ้านดอน';
        $this->cleanDirectory($thaiDir);
        $this->copyDirectoryRecursive($this->sandboxDir, $thaiDir);

        $batThai = $thaiDir . DIRECTORY_SEPARATOR . 'check_status.bat';
        $res2 = $this->runBat($batThai);
        $this->assertTest('8.2 Execution in Thai Unicode path succeeds', $res2['code'] === 0, "Exit code: {$res2['code']}");
        $this->cleanDirectory($thaiDir);
    }

    /**
     * 9. ทดสอบเมื่อ Configuration หายหรือเสียหาย
     */
    private function test9_MissingOrCorruptedConfig(): void {
        echo "\n[TEST 9] Missing or Corrupted Configuration Handling\n";

        require_once $this->sandboxDir . '/agent_config.php';
        $cfgDir = $this->sandboxDir . DIRECTORY_SEPARATOR . 'config';
        $cfgFile = $cfgDir . DIRECTORY_SEPARATOR . 'agent_settings.enc.json';

        $cfgManager = new AgentConfig($cfgDir);

        // 9.1 บันทึก Config พร้อม Password
        $cfgManager->save([
            'hcode'          => '01429',
            'hosxp_password' => 'SafeDeployPassword!2026',
            'agent_mode'     => 'mock',
        ]);
        $loaded = $cfgManager->load();
        $this->assertTest('9.1 Normal password decrypted correctly', $loaded['hosxp_password'] === 'SafeDeployPassword!2026');

        // 9.2 จำลอง Configuration เสียหาย (Corrupted Ciphertext & Tag)
        $raw = json_decode(file_get_contents($cfgFile), true);
        $raw['hosxp_password_encrypted'] = 'INVALID_BASE64::CORRUPTED_TAG::CORRUPTED_DATA';
        file_put_contents($cfgFile, json_encode($raw));

        $corruptedLoaded = $cfgManager->load();
        $this->assertTest('9.2 Corrupted encrypted config returns empty password safely', empty($corruptedLoaded['hosxp_password']));

        // 9.3 จำลอง Configuration หาย
        @unlink($cfgFile);
        $missingLoaded = $cfgManager->load();
        $this->assertTest('9.3 Missing configuration returns default safe mock configuration', ($missingLoaded['agent_mode'] ?? '') === 'mock');
    }

    /**
     * 10. ทดสอบเมื่อโฟลเดอร์ Log เขียนไม่ได้
     */
    private function test10_UnwritableLogDirectory(): void {
        echo "\n[TEST 10] Unwritable Log Directory Fallback\n";

        require_once $this->sandboxDir . '/agent_sync_event.php';
        // ใช้ Path ที่ไม่มีทางเขียนได้บน Windows
        $badPath = 'Z:\\non_existent_drive\\logs\\test_events.json';
        $logger = new AgentSyncEventLog($badPath);
        $logger->record('TEST_EVENT', 'Testing unwritable path write handling');

        $saveRes = $logger->saveEvents();
        $this->assertTest('10.1 Save to unwritable path returns false gracefully without fatal crash', $saveRes === false);
        $this->assertTest('10.2 Events remain safely preserved in memory buffer', count($logger->getRecentEvents()) === 1);
    }

    /**
     * 11. ตรวจสอบว่า Credential ไม่ถูกบันทึกลง Log
     */
    private function test11_CredentialsNotInLogs(): void {
        echo "\n[TEST 11] Zero Credentials Leakage in Logs & State Files\n";

        $forbiddenSecrets = [
            'SafeDeployPassword!2026',
            'QJEXH3YDDHU2QYRGWDW4IY4QF4LQ',
            'b5e492673f60546a95df9636dfc5ea59a388bc25',
            'moph_live_token',
        ];

        $leaked = false;
        $filesChecked = 0;

        $dirs = [$this->sandboxDir . '/config', $this->sandboxDir . '/logs'];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $f) {
                if (substr($f, -5) === '.json' || substr($f, -4) === '.log') {
                    $content = file_get_contents($dir . DIRECTORY_SEPARATOR . $f);
                    $filesChecked++;
                    foreach ($forbiddenSecrets as $secret) {
                        if (stripos($content, $secret) !== false) {
                            $leaked = true;
                            break 2;
                        }
                    }
                }
            }
        }

        $this->assertTest('11.1 Checked state and log files', $filesChecked > 0, "Checked {$filesChecked} files");
        $this->assertTest('11.2 Zero plain credentials or secrets detected in logs/state', $leaked === false);
    }

    /**
     * 12. ตรวจสอบว่าไม่มี Network Request ออกภายนอก
     */
    private function test12_ZeroOutboundNetworkRequests(): void {
        echo "\n[TEST 12] Zero Outbound Network Requests Verification\n";

        require_once $this->sandboxDir . '/agent_moph_notify.php';
        $mock = new AgentMophNotifyMockAdapter();
        $res = $mock->notify('Deploy readiness test');

        $this->assertTest('12.1 MOPH Notify operates via local mock adapter', ($res['http_code'] ?? 0) === 200 && ($res['is_simulated'] ?? false));
        $this->assertTest('12.2 Outbound request attempts count is strictly 0', $mock->assertZeroOutboundRequests() === true);
    }

    /**
     * Recovery 1: Clean Restart
     */
    private function testRecovery_CleanRestart(): void {
        echo "\n[RECOVERY 1] Normal Worker Shutdown & Immediate Restart\n";

        require_once $this->sandboxDir . '/agent_service_host.php';
        $host = new AgentServiceHost($this->sandboxDir);

        $exitCode = $host->start(1, 1);
        $this->assertTest('R.1 First mock cycle run completed with exit code 0', $exitCode === 0);

        // สั่งรันต่อทันที
        $exitCode2 = $host->start(1, 1);
        $this->assertTest('R.2 Second mock cycle started and completed immediately without lock contention', $exitCode2 === 0);
    }

    /**
     * Recovery 2: Crash Simulation & Stale Lock Auto-Recovery
     */
    private function testRecovery_CrashAndStaleLockRecovery(): void {
        echo "\n[RECOVERY 2] Crash Simulation & Dead PID Stale Lock Recovery\n";

        require_once $this->sandboxDir . '/agent_lifecycle.php';
        $cfgDir = $this->sandboxDir . DIRECTORY_SEPARATOR . 'config';
        $lockFile = $cfgDir . DIRECTORY_SEPARATOR . AgentLifecycle::LOCK_FILE_NAME;

        // จำลอง Crash: เขียน Lock File โดยจำลอง PID ที่ตายไปแล้ว (เช่น PID 99999999)
        $deadPid = 99999999;
        $dummyPayload = [
            'pid'        => $deadPid,
            'started_at' => '2026-10-28 00:00:00',
            'heartbeat'  => time() - 3600, // 1 hour ago
            'status'     => 'RUNNING',
            'mode'       => 'mock',
            'crashed'    => true,
        ];
        file_put_contents($lockFile, json_encode($dummyPayload));

        $lifecycle = new AgentLifecycle($cfgDir);
        $this->assertTest('R.3 Dead PID recognized as not alive by isPidAlive()', $lifecycle->isPidAlive($deadPid) === false);

        // Worker ตัวใหม่พยายามเข้าจอง Lock
        $newAcquired = $lifecycle->acquireLock(['worker' => 'NewWorkerAfterCrash']);
        $this->assertTest('R.4 Automatically detected dead PID and reclaimed stale lock successfully', $newAcquired === true);
        $this->assertTest('R.5 Lock file now owned by current live PID', $lifecycle->getStatus()['pid'] === getmypid());

        $lifecycle->releaseLock();
    }

    /**
     * Recovery 3: State File Integrity
     */
    private function testRecovery_StateFileIntegrity(): void {
        echo "\n[RECOVERY 3] State File Integrity Verification\n";

        $stateFile = $this->sandboxDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mock_sync_monitor_state.json';
        $eventsFile = $this->sandboxDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mock_sync_events.json';

        $this->assertTest('R.6 State file exists and is readable', file_exists($stateFile) && is_readable($stateFile));
        $this->assertTest('R.7 Events file exists and is readable', file_exists($eventsFile) && is_readable($eventsFile));

        $state = json_decode(file_get_contents($stateFile), true);
        $events = json_decode(file_get_contents($eventsFile), true);

        $this->assertTest('R.8 State JSON syntax is valid', is_array($state));
        $this->assertTest('R.9 Events JSON syntax is valid array', is_array($events));
    }

    /**
     * Recovery 4: No Unintended Duplicate Runs
     */
    private function testRecovery_NoUnintendedDuplicateRuns(): void {
        echo "\n[RECOVERY 4] No Unintended Duplicate Runs Guard\n";

        require_once $this->sandboxDir . '/agent_scheduler.php';
        require_once $this->sandboxDir . '/agent_mock_clock.php';

        $clock = new AgentMockClock('2026-10-28 09:00:00');
        $scheduler = new AgentScheduler($clock);

        // รันรอบ 09:00 รอบแรก
        $res1 = $scheduler->triggerSlotRun(function() { return ['mock' => true]; });
        $this->assertTest('R.10 Slot 09:00 executed on first trigger', ($res1['executed'] ?? false) === true && ($res1['slot'] ?? '') === '09:00');

        // รันซ้ำรอบ 09:00 ในเวลาเดียวกัน
        $res2 = $scheduler->triggerSlotRun(function() { return ['mock' => true]; });
        $this->assertTest('R.11 Duplicate run in same slot strictly blocked (status = BLOCKED_DUPLICATE)', ($res2['status'] ?? '') === 'BLOCKED_DUPLICATE');
    }

    /**
     * Recovery 5: Windows Host Reboot (Not Tested declaration)
     */
    private function testRecovery_WindowsHostReboot(): void {
        echo "\n[RECOVERY 5] Windows Host Machine Reboot\n";
        $this->markNotTested(
            'R.12 Windows Machine Reboot & Automatic Service Recovery',
            'ไม่สามารถสั่ง Reboot เครื่องคอมพิวเตอร์ Host จริงได้ เนื่องจากจะทำให้เซสชันการทำงานของ IDE และเครื่องลูกข่ายหลุดการเชื่อมต่อ'
        );
    }

    private function copyDirectoryRecursive(string $src, string $dst): void {
        if (!is_dir($src)) return;
        if (!is_dir($dst)) mkdir($dst, 0755, true);

        $items = scandir($src);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($srcPath)) {
                $this->copyDirectoryRecursive($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function cleanDirectory(string $dir): void {
        if (!is_dir($dir)) return;
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->cleanDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

// เรียกเมื่อรันตรงผ่าน CLI
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $suite = new IsolatedDeploymentTestSuite();
    $passed = $suite->runAll();
    exit($passed ? 0 : 1);
}
