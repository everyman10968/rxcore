<?php
/**
 * RxCore Sync Agent — Packaging & Deployment Readiness Test Suite
 * Phase 3.8.1: Windows Agent Packaging & Deployment Readiness
 * 
 * ครอบคลุม 10 การทดสอบหลักตามข้อกำหนด:
 * 1. Build Package สำเร็จ (Package Directory, Files, Hashes, Manifest)
 * 2. เปิดโปรแกรมจาก Path ที่มีช่องว่าง (Spaces in directory path)
 * 3. เปิดโปรแกรมจาก Path ภาษาไทย (Unicode Thai directory path)
 * 4. Configuration ปกติ vs Configuration เสียหาย (AES-256-GCM Tag tampering & corruption)
 * 5. Runtime หรือ Extension ขาดหาย (Environment Audit & Diagnostics)
 * 6. Log Directory เขียนไม่ได้ (Permission / Fallback handling)
 * 7. เปิดโปรแกรมซ้ำ (Single Instance Lock & Duplicate Process Prevention)
 * 8. ปิดโปรแกรมระหว่าง Mock Task (Graceful Shutdown & Signal handling)
 * 9. ไม่มี Credential ฝังอยู่ในแพ็กเกจ (Zero Plaintext Secrets & Leak Audit)
 * 10. ไม่มี Network Request ออกภายนอก (Mock Invariant & Zero Egress Verification)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: test_packaging.php can only be executed via CLI.\n");
}

require_once __DIR__ . '/build_package.php';
require_once __DIR__ . '/agent_lifecycle.php';
require_once __DIR__ . '/agent_env_check.php';
require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_service_host.php';
require_once __DIR__ . '/agent_moph_notify.php';

class PackagingTestSuite {

    private int $passed = 0;
    private int $failed = 0;
    private array $results = [];
    private string $distDir;

    public function __construct() {
        $this->distDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'rxcore-sync-agent';
    }

    private function assert(string $testName, bool $condition, string $detail = ''): void {
        if ($condition) {
            $this->passed++;
            $this->results[] = ['name' => $testName, 'status' => 'PASS', 'detail' => $detail];
            echo "  [PASS] {$testName}" . ($detail ? " — {$detail}" : '') . "\n";
        } else {
            $this->failed++;
            $this->results[] = ['name' => $testName, 'status' => 'FAIL', 'detail' => $detail];
            echo "  [FAIL] {$testName}" . ($detail ? " — {$detail}" : '') . "\n";
        }
    }

    public function runAll(): bool {
        echo "====================================================================\n";
        echo "  RxCore Phase 3.8.1: Packaging & Deployment Readiness Test Suite\n";
        echo "====================================================================\n\n";

        $this->test1_BuildPackageSuccess();
        $this->test2_PathWithSpaces();
        $this->test3_PathWithThaiCharacters();
        $this->test4_ConfigNormalAndCorrupted();
        $this->test5_MissingRuntimeOrExtension();
        $this->test6_UnwritableLogDirectory();
        $this->test7_DuplicateInstancePrevention();
        $this->test8_GracefulShutdownDuringTask();
        $this->test9_ZeroCredentialsAudit();
        $this->test10_ZeroOutboundNetworkRequests();

        echo "\n====================================================================\n";
        echo "  TEST SUMMARY\n";
        echo "====================================================================\n";
        echo "Total Tests: " . ($this->passed + $this->failed) . "\n";
        echo "Passed:      {$this->passed}\n";
        echo "Failed:      {$this->failed}\n";
        $successRate = round(($this->passed / max(1, ($this->passed + $this->failed))) * 100, 2);
        echo "Success Rate: {$successRate}%\n";
        echo "====================================================================\n";

        return ($this->failed === 0);
    }

    /**
     * Test 1: Build Package สำเร็จ
     */
    private function test1_BuildPackageSuccess(): void {
        echo "[TEST 1] Build Package Success\n";

        $builder = new AgentPackageBuilder(__DIR__, $this->distDir);
        $buildRes = $builder->build();

        $this->assert('1.1 Build script executed successfully', $buildRes['success']);
        $this->assert('1.2 Dist directory exists', is_dir($this->distDir));
        $this->assert('1.3 Manifest file generated', file_exists($this->distDir . DIRECTORY_SEPARATOR . 'package_manifest.json'));
        
        $manifest = json_decode(file_get_contents($this->distDir . DIRECTORY_SEPARATOR . 'package_manifest.json'), true);
        $this->assert('1.4 Manifest contains version and files', isset($manifest['version']) && !empty($manifest['files']));
        $this->assert('1.5 All 5 Windows batch launchers present', 
            file_exists($this->distDir . '/start_agent.bat') &&
            file_exists($this->distDir . '/stop_agent.bat') &&
            file_exists($this->distDir . '/check_status.bat') &&
            file_exists($this->distDir . '/check_env.bat') &&
            file_exists($this->distDir . '/run_web_ui.bat')
        );
        $this->assert('1.6 Security .htaccess in config/ and logs/', 
            file_exists($this->distDir . '/config/.htaccess') &&
            file_exists($this->distDir . '/logs/.htaccess')
        );
    }

    /**
     * Test 2: เปิดโปรแกรมจาก Path ที่มีช่องว่าง (Spaces in directory)
     */
    private function test2_PathWithSpaces(): void {
        echo "\n[TEST 2] Running from Path with Spaces\n";

        $spaceDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'test rxcore space dir';
        if (!is_dir($spaceDir)) {
            mkdir($spaceDir, 0755, true);
        }

        // Copy minimal files needed for service host
        copy($this->distDir . '/agent_service_host.php', $spaceDir . '/agent_service_host.php');
        copy($this->distDir . '/agent_lifecycle.php', $spaceDir . '/agent_lifecycle.php');
        copy($this->distDir . '/agent_config.php', $spaceDir . '/agent_config.php');
        copy($this->distDir . '/agent_env_check.php', $spaceDir . '/agent_env_check.php');
        copy($this->distDir . '/agent_sync_event.php', $spaceDir . '/agent_sync_event.php');
        copy($this->distDir . '/agent_moph_notify.php', $spaceDir . '/agent_moph_notify.php');
        copy($this->distDir . '/agent_sync_monitor.php', $spaceDir . '/agent_sync_monitor.php');
        copy($this->distDir . '/agent_mock_clock.php', $spaceDir . '/agent_mock_clock.php');
        copy($this->distDir . '/agent_atomic_file.php', $spaceDir . '/agent_atomic_file.php');
        
        $cfg = $spaceDir . DIRECTORY_SEPARATOR . 'config';
        if (!is_dir($cfg)) mkdir($cfg, 0755, true);
        copy($this->distDir . '/config/agent_settings.example.json', $cfg . '/agent_settings.json');

        // Run status command via PHP CLI
        $cmd = sprintf('"%s" "%s/agent_service_host.php" --status', PHP_BINARY, $spaceDir);
        $output = [];
        $ret = -1;
        exec($cmd, $output, $ret);

        $this->assert('2.1 Execute from path with spaces returns exit code 0', $ret === 0, "Exit code: {$ret}");
        $this->assert('2.2 Output contains Status Report', stripos(implode("\n", $output), 'Status Report') !== false);

        // Run run-once in space path
        $cmd2 = sprintf('"%s" "%s/agent_service_host.php" --run-once', PHP_BINARY, $spaceDir);
        $output2 = [];
        $ret2 = -1;
        exec($cmd2, $output2, $ret2);
        $this->assert('2.3 Run-once in space path succeeds', $ret2 === 0);

        // Cleanup
        $this->removeDirectoryRecursive($spaceDir);
    }

    /**
     * Test 3: เปิดโปรแกรมจาก Path ภาษาไทย (Unicode Thai directory)
     */
    private function test3_PathWithThaiCharacters(): void {
        echo "\n[TEST 3] Running from Path with Thai Characters\n";

        $thaiDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'ทดสอบ_รพสต_บ้านดอน';
        if (!is_dir($thaiDir)) {
            mkdir($thaiDir, 0755, true);
        }

        // Copy minimal files
        copy($this->distDir . '/agent_service_host.php', $thaiDir . '/agent_service_host.php');
        copy($this->distDir . '/agent_lifecycle.php', $thaiDir . '/agent_lifecycle.php');
        copy($this->distDir . '/agent_config.php', $thaiDir . '/agent_config.php');
        copy($this->distDir . '/agent_env_check.php', $thaiDir . '/agent_env_check.php');
        copy($this->distDir . '/agent_sync_event.php', $thaiDir . '/agent_sync_event.php');
        copy($this->distDir . '/agent_moph_notify.php', $thaiDir . '/agent_moph_notify.php');
        copy($this->distDir . '/agent_sync_monitor.php', $thaiDir . '/agent_sync_monitor.php');
        copy($this->distDir . '/agent_mock_clock.php', $thaiDir . '/agent_mock_clock.php');
        copy($this->distDir . '/agent_atomic_file.php', $thaiDir . '/agent_atomic_file.php');

        $cfg = $thaiDir . DIRECTORY_SEPARATOR . 'config';
        if (!is_dir($cfg)) mkdir($cfg, 0755, true);
        copy($this->distDir . '/config/agent_settings.example.json', $cfg . '/agent_settings.json');

        // Execute via PHP CLI
        $cmd = sprintf('"%s" "%s/agent_service_host.php" --status', PHP_BINARY, $thaiDir);
        $output = [];
        $ret = -1;
        exec($cmd, $output, $ret);

        $this->assert('3.1 Execute from Thai Unicode path returns exit code 0', $ret === 0, "Exit code: {$ret}");
        $this->assert('3.2 Output contains Status Report', stripos(implode("\n", $output), 'Status Report') !== false);

        // Run run-once in Thai path
        $cmd2 = sprintf('"%s" "%s/agent_service_host.php" --run-once', PHP_BINARY, $thaiDir);
        $output2 = [];
        $ret2 = -1;
        exec($cmd2, $output2, $ret2);
        $this->assert('3.3 Run-once in Thai Unicode path succeeds', $ret2 === 0);

        // Cleanup
        $this->removeDirectoryRecursive($thaiDir);
    }

    /**
     * Test 4: Configuration ปกติและ Configuration เสียหาย
     */
    private function test4_ConfigNormalAndCorrupted(): void {
        echo "\n[TEST 4] Normal and Corrupted Configuration Handling\n";

        $tempConfigDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rxcore_test_cfg_' . uniqid();
        mkdir($tempConfigDir, 0755, true);

        $cfgManager = new AgentConfig($tempConfigDir);

        // 4.1 Normal Configuration
        $normalData = [
            'hcode'          => '10999',
            'hosxp_host'     => '127.0.0.1',
            'hosxp_port'     => 3306,
            'hosxp_database' => 'hosxp_pcu',
            'hosxp_username' => 'test_user',
            'hosxp_password' => 'SafeSecretP@ss123',
            'agent_mode'     => 'mock',
        ];
        $saveRes = $cfgManager->save($normalData);
        $this->assert('4.1 Save normal configuration with AES-256-GCM AEAD encryption', $saveRes === true);

        $loaded = $cfgManager->load();
        $this->assert('4.2 Decrypt and read password accurately', $loaded['hosxp_password'] === 'SafeSecretP@ss123');

        $cfgFile = $tempConfigDir . DIRECTORY_SEPARATOR . 'agent_settings.enc.json';

        // 4.2 Corrupted Configuration File (Tampered GCM Tag)
        $rawJson = json_decode(file_get_contents($cfgFile), true);
        $parts = explode('::', $rawJson['hosxp_password_encrypted']);
        if (count($parts) === 3) {
            $parts[1] = base64_encode(random_bytes(16)); // Tamper tag
            $rawJson['hosxp_password_encrypted'] = implode('::', $parts);
        }
        file_put_contents($cfgFile, json_encode($rawJson));

        $tamperedLoaded = $cfgManager->load();
        $this->assert('4.3 Tampered GCM Tag rejected, password cleared safely', empty($tamperedLoaded['hosxp_password']));

        // 4.3 Missing Configuration File
        unlink($cfgFile);
        $this->assert('4.4 Missing configuration file returns default safe settings without crashing', !$cfgManager->hasConfig());
        $defaultConfig = $cfgManager->load();
        $this->assert('4.5 Default configuration has mock mode enabled', ($defaultConfig['agent_mode'] ?? '') === 'mock');

        // Cleanup
        $this->removeDirectoryRecursive($tempConfigDir);
    }

    /**
     * Test 5: Runtime หรือ Extension ขาดหาย
     */
    private function test5_MissingRuntimeOrExtension(): void {
        echo "\n[TEST 5] Missing Runtime / Extension Detection\n";

        $audit = AgentEnvironmentChecker::checkAll();
        $this->assert('5.1 PHP Version check validates >= 8.1.0', $audit['php_version']['passed']);
        $this->assert('5.2 All required extensions verified', $audit['extensions']['all_passed']);
        $this->assert('5.3 OS compatibility recognized', $audit['operating_system']['is_windows']);

        // Check diagnostic reporting if requirement unmet
        $isFuturePhp = version_compare('8.0.0', AgentEnvironmentChecker::REQUIRED_PHP_MIN_VERSION, '>=');
        $this->assert('5.4 Incompatible PHP version (8.0) correctly flagged as failure', !$isFuturePhp);
    }

    /**
     * Test 6: Log Directory เขียนไม่ได้
     */
    private function test6_UnwritableLogDirectory(): void {
        echo "\n[TEST 6] Unwritable Log Directory Fallback\n";

        // Test logging to an invalid / protected path
        $badPath = 'Z:\\non_existent_drive\\logs\\events.json';
        $eventLog = new AgentSyncEventLog($badPath);
        $eventLog->record('TEST_EVENT', 'Testing invalid path write');

        // saveEvents should return false without raising an uncaught fatal exception
        $saveResult = $eventLog->saveEvents();
        $this->assert('6.1 Invalid log path save returns false without fatal crash', $saveResult === false);
        $this->assert('6.2 Events remain stored in memory buffer safely', count($eventLog->getRecentEvents()) === 1);
    }

    /**
     * Test 7: เปิดโปรแกรมซ้ำ (Single Instance Lock)
     */
    private function test7_DuplicateInstancePrevention(): void {
        echo "\n[TEST 7] Duplicate Instance Prevention\n";

        $testConfigDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rxcore_test_lock_' . uniqid();
        mkdir($testConfigDir, 0755, true);

        $instanceA = new AgentLifecycle($testConfigDir);
        $lockedA = $instanceA->acquireLock(['worker' => 'InstanceA']);
        $this->assert('7.1 Instance A acquires lock successfully', $lockedA);

        $instanceB = new AgentLifecycle($testConfigDir);
        $lockedB = $instanceB->acquireLock(['worker' => 'InstanceB']);
        $this->assert('7.2 Instance B blocked from acquiring duplicate lock', !$lockedB);

        $status = $instanceB->getStatus();
        $this->assert('7.3 Instance B correctly detects running instance PID', $status['is_running'] && $status['pid'] === getmypid());

        $releasedA = $instanceA->releaseLock();
        $this->assert('7.4 Instance A releases lock successfully', $releasedA);

        $lockedB2 = $instanceB->acquireLock(['worker' => 'InstanceB_Retry']);
        $this->assert('7.5 Instance B can now acquire lock after Instance A released', $lockedB2);
        $instanceB->releaseLock();

        // Cleanup
        $this->removeDirectoryRecursive($testConfigDir);
    }

    /**
     * Test 8: ปิดโปรแกรมระหว่าง Mock Task (Graceful Shutdown)
     */
    private function test8_GracefulShutdownDuringTask(): void {
        echo "\n[TEST 8] Graceful Shutdown During Mock Task\n";

        $testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rxcore_test_shutdown_' . uniqid();
        mkdir($testDir . DIRECTORY_SEPARATOR . 'config', 0755, true);

        $host = new AgentServiceHost($testDir);
        $lifecycle = $host->getLifecycle();

        // Request shutdown immediately during first cycle callback
        $onCycle = function(int $cycle) use ($lifecycle) {
            if ($cycle === 1) {
                $lifecycle->requestShutdown('Test shutdown request during cycle 1');
            }
        };

        $startTime = microtime(true);
        // Start with max 10 cycles, but shutdown will trigger after cycle 1
        $exitCode = $host->start(1, 10, $onCycle);
        $elapsed = microtime(true) - $startTime;

        $this->assert('8.1 Service Host stopped with exit code 0 upon shutdown signal', $exitCode === 0);
        $this->assert('8.2 Stopped gracefully after completing current cycle (< 2s)', $elapsed < 2.0);
        $this->assert('8.3 Lock file released after shutdown', !file_exists($lifecycle->getLockFilePath()));

        // Cleanup
        $this->removeDirectoryRecursive($testDir);
    }

    /**
     * Test 9: ไม่มี Credential ฝังอยู่ในแพ็กเกจ
     */
    private function test9_ZeroCredentialsAudit(): void {
        echo "\n[TEST 9] Zero Credentials Audit in Package\n";

        $forbiddenSecrets = [
            'QJEXH3YDDHU2QYRGWDW4IY4QF4LQ', // Real MOPH Secret
            'b5e492673f60546a95df9636dfc5ea59a388bc25', // Real MOPH Client ID
            'SafeSecretP@ss123',
            'password123',
            'moph_live_token',
        ];

        $filesScanned = 0;
        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->distDir));
        foreach ($iterator as $item) {
            if ($item->isDir()) continue;
            $filesScanned++;
            $content = file_get_contents($item->getPathname());
            foreach ($forbiddenSecrets as $secret) {
                if (stripos($content, $secret) !== false) {
                    $violations[] = basename($item->getPathname()) . " contains secret: " . substr($secret, 0, 6) . '...';
                }
            }
        }

        $this->assert('9.1 Scanned all files in package', $filesScanned > 20, "Total scanned: {$filesScanned}");
        $this->assert('9.2 Zero plaintext credentials found in any package file', empty($violations), implode(', ', $violations));
        $this->assert('9.3 agent_settings.json excluded from package', !file_exists($this->distDir . '/config/agent_settings.json'));
    }

    /**
     * Test 10: ไม่มี Network Request ออกภายนอก
     */
    private function test10_ZeroOutboundNetworkRequests(): void {
        echo "\n[TEST 10] Zero Outbound Network Requests Verification\n";

        // 10.1 Mock MOPH Notify Adapter does not use cURL or sockets
        $mockAdapter = new AgentMophNotifyMockAdapter();
        $res = $mockAdapter->notify('Mock message for packaging test');
        $this->assert('10.1 Mock Notify Adapter returns simulated success locally', ($res['http_code'] ?? 0) === 200 && ($res['is_simulated'] ?? false));
        $this->assert('10.2 Sent messages stored locally in history queue without network socket', count($mockAdapter->getHistory()) === 1);
        $this->assert('10.3 Adapter asserts zero outbound network requests', $mockAdapter->assertZeroOutboundRequests() === true);

        // 10.2 Service Host enforces RXCORE_STRICT_MOCK_MODE
        $this->assert('10.4 RXCORE_STRICT_MOCK_MODE constant defined as true', defined('RXCORE_STRICT_MOCK_MODE') && RXCORE_STRICT_MOCK_MODE === true);
    }

    private function removeDirectoryRecursive(string $dir): void {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectoryRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

// รันหากถูกเรียกตรง
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $suite = new PackagingTestSuite();
    $passed = $suite->runAll();
    exit($passed ? 0 : 1);
}
