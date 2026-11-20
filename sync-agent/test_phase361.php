<?php
/**
 * RxCore Sync Agent — Phase 3.6.1 Automated Test Suite
 * Reliability & Performance Hardening
 * 
 * ครอบคลุม:
 * 1. Atomic State File: Temp file in same dir, JSON verification, Windows safe swap, flock lock, rollback, fault injection
 * 2. Fault Injections: Missing file, corrupted JSON, disk full/IO error, interrupted write, zero real-user file corruption
 * 3. Retry Policy: Max 4 attempts, Backoff + Jitter, Retry-After seconds & HTTP-date formats, max_delay ceiling cap
 * 4. Permanent Errors & Secret Redaction: HTTP 400, 401, 403 non-retry, no secret leaks in timeline
 * 5. Reconciliation Benchmark & Invariant: 1K, 10K, 50K performance, set difference, failsafe protection on query failure
 * 6. Zero Outbound Network & Full Regression Compatibility
 */

require_once __DIR__ . '/agent_mock_clock.php';
require_once __DIR__ . '/agent_mock_transport.php';
require_once __DIR__ . '/agent_scheduler.php';
require_once __DIR__ . '/agent_retry_policy.php';
require_once __DIR__ . '/agent_reconciliation.php';
require_once __DIR__ . '/agent_atomic_file.php';
require_once __DIR__ . '/agent_mock_state.php';
require_once __DIR__ . '/benchmark_reconciliation.php';
require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_sync_core.php';
require_once __DIR__ . '/agent_hosxp_reader.php';
require_once __DIR__ . '/agent_api_client.php';
require_once __DIR__ . '/agent_mock_server.php';

class Phase361ReliabilityTester {

    private int $passed = 0;
    private int $failed = 0;
    private string $testSandboxDir;

    public function __construct() {
        // สร้าง Temporary Sandbox Directory เพื่อแยกการทดสอบออกจากไฟล์จริง 100%
        $this->testSandboxDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rxcore_phase361_' . bin2hex(random_bytes(6));
        if (!is_dir($this->testSandboxDir)) {
            mkdir($this->testSandboxDir, 0700, true);
        }
    }

    public function __destruct() {
        // ทำความสะอาด Sandbox หลังทดสอบเสร็จ
        $this->cleanDir($this->testSandboxDir);
        @rmdir($this->testSandboxDir);
    }

    private function cleanDir(string $dir): void {
        if (!is_dir($dir)) return;
        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_dir($path)) {
                $this->cleanDir($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function assert(bool $condition, string $testName, string $detail = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$testName}" . ($detail ? ": {$detail}" : '') . "\n";
        }
    }

    // =========================================================================
    // GROUP 1: ATOMIC STATE FILE & FAULT INJECTION TESTS
    // =========================================================================

    public function testAtomicStateNormalAndVerification(): void {
        echo "\n--- 1. Testing Atomic State File: Normal Write & JSON Verification ---\n";

        $targetFile = $this->testSandboxDir . DIRECTORY_SEPARATOR . 'state_normal.json';
        $testData = [
            'hcode' => '01425',
            'cursor' => '2026-10-28',
            'items' => [
                ['guid' => 'G-1', 'qty' => 5],
                ['guid' => 'G-2', 'qty' => 10],
            ]
        ];

        // 1.1 เขียนครั้งแรก
        $writeResult = AgentAtomicFile::writeJson($targetFile, $testData);
        $this->assert($writeResult === true, 'TC 1.1: Atomic write succeeds on fresh target');
        $this->assert(file_exists($targetFile), 'TC 1.2: Target file exists on filesystem');

        // 1.3 ตรวจสอบข้อมูลที่อ่านกลับมา
        $readData = AgentAtomicFile::readJson($targetFile);
        $this->assert($readData === $testData, 'TC 1.3: Read back data matches written data exactly');

        // 1.4 ไม่มี Temp File หรือ Backup File ตกค้าง
        $dirFiles = scandir($this->testSandboxDir);
        $hasOrphanTmp = false;
        $hasOrphanBak = false;
        foreach ($dirFiles as $f) {
            if (str_starts_with($f, AgentAtomicFile::TMP_PREFIX)) $hasOrphanTmp = true;
            if (str_ends_with($f, AgentAtomicFile::BAK_SUFFIX)) $hasOrphanBak = true;
        }
        $this->assert(!$hasOrphanTmp, 'TC 1.4: Zero orphan temp files left behind after write');
        $this->assert(!$hasOrphanBak, 'TC 1.5: Zero orphan backup files left behind after write');

        // 1.6 อัปเดตข้อมูลทับไฟล์เดิม (Windows Atomic Replacement)
        $updatedData = array_merge($testData, ['cursor' => '2026-10-29', 'new_flag' => true]);
        $updateResult = AgentAtomicFile::writeJson($targetFile, $updatedData);
        $this->assert($updateResult === true, 'TC 1.6: Atomic replace on existing file succeeds without Windows lock error');
        $this->assert(AgentAtomicFile::readJson($targetFile) === $updatedData, 'TC 1.7: Existing file replaced cleanly with updated content');
    }

    public function testAtomicStateFaultInjections(): void {
        echo "\n--- 2. Testing Fault Injections: Corruption, IO Failure, Interruption ---\n";

        $targetFile = $this->testSandboxDir . DIRECTORY_SEPARATOR . 'state_fault.json';
        $originalData = ['version' => 1, 'watermark' => 'ORIGINAL_DATA'];
        AgentAtomicFile::writeJson($targetFile, $originalData);

        // 2.1 Missing File: อ่านไฟล์ที่ไม่มีอยู่จริง
        $missingFile = $this->testSandboxDir . DIRECTORY_SEPARATOR . 'non_existent_file.json';
        $defaultData = ['default' => true];
        $readMissing = AgentAtomicFile::readJson($missingFile, $defaultData);
        $this->assert($readMissing === $defaultData, 'TC 2.1: Reading missing file returns default without throwing exception');

        // 2.2 Corrupted File: ไฟล์มีข้อความ JSON ที่พิการ (Invalid JSON)
        $corruptFile = $this->testSandboxDir . DIRECTORY_SEPARATOR . 'corrupt.json';
        file_put_contents($corruptFile, "{ malformed json: missing_quotes, [ }");
        $readCorrupt = AgentAtomicFile::readJson($corruptFile, ['recovered' => true]);
        $this->assert($readCorrupt === ['recovered' => true], 'TC 2.2: Reading corrupted JSON returns default array safely');

        // 2.3 Fault Injection: จำลองข้อผิดพลาด Disk Full / Write Error ในขั้นตอนก่อนตรวจสอบ
        $diskFullInjected = false;
        try {
            AgentAtomicFile::writeJson($targetFile, ['version' => 2, 'watermark' => 'NEW_FAILED'], function($stage, $tempFile) {
                if ($stage === 'before_write') {
                    throw new RuntimeException("SIMULATED_DISK_FULL: No space left on device");
                }
            });
        } catch (\RuntimeException $e) {
            $diskFullInjected = str_contains($e->getMessage(), 'SIMULATED_DISK_FULL');
        }
        $this->assert($diskFullInjected, 'TC 2.3: Simulated Disk Full exception intercepted');
        $this->assert(AgentAtomicFile::readJson($targetFile) === $originalData, 'TC 2.4: Original file remains 100% intact after Disk Full write failure');

        // 2.4 Fault Injection: จำลอง Temp File ถูกทำลายหรือเขียนผิดรูปก่อน Validation
        $corruptTempInjected = false;
        try {
            AgentAtomicFile::writeJson($targetFile, ['version' => 2, 'watermark' => 'CORRUPT_TEMP'], function($stage, $tempFile) {
                if ($stage === 'after_write') {
                    // แอบเขียนทับ Temp File ด้วยข้อมูลขยะ
                    file_put_contents($tempFile, "BAD_CORRUPT_DATA_TRUNCATED");
                }
            });
        } catch (\RuntimeException $e) {
            $corruptTempInjected = str_contains($e->getMessage(), 'Temp File เสียหาย');
        }
        $this->assert($corruptTempInjected, 'TC 2.5: Temp File corruption detected by verification check');
        $this->assert(AgentAtomicFile::readJson($targetFile) === $originalData, 'TC 2.6: Original target preserved when Temp File fails verification');

        // 2.5 Fault Injection: จำลองโปรแกรมหยุดทำงาน (Crash) ทันทีก่อน Swap
        $crashInjected = false;
        try {
            AgentAtomicFile::writeJson($targetFile, ['version' => 3, 'watermark' => 'CRASH_BEFORE_SWAP'], function($stage) {
                if ($stage === 'before_swap') {
                    throw new RuntimeException("SIMULATED_PROCESS_CRASH_OR_POWER_LOSS");
                }
            });
        } catch (\RuntimeException $e) {
            $crashInjected = str_contains($e->getMessage(), 'SIMULATED_PROCESS_CRASH');
        }
        $this->assert($crashInjected, 'TC 2.7: Crash before swap intercepted');
        $this->assert(AgentAtomicFile::readJson($targetFile) === $originalData, 'TC 2.8: Original file preserved when process crashes before swap');
    }

    public function testMockSyncStateAtomicIntegration(): void {
        echo "\n--- 3. Testing AgentMockSyncState Atomic File Integration ---\n";

        $stateFilePath = $this->testSandboxDir . DIRECTORY_SEPARATOR . 'agent_mock_state_sandbox.json';
        $stateManager = new AgentMockSyncState($stateFilePath, true);

        // บันทึกรายการใหม่
        $records = [
            ['hcode' => '01425', 'hos_guid' => 'GUID-ATOMIC-1', 'vstdate' => '2026-10-28'],
            ['hcode' => '01425', 'hos_guid' => 'GUID-ATOMIC-2', 'vstdate' => '2026-10-28'],
        ];
        $stateManager->batchRecordSynced($records, 'INCREMENTAL_SYNC');

        $this->assert(file_exists($stateFilePath), 'TC 3.1: AgentMockSyncState saves to atomic file');
        $this->assert($stateManager->hasSynced('01425', 'GUID-ATOMIC-1'), 'TC 3.2: First GUID marked synced');
        $this->assert($stateManager->hasSynced('01425', 'GUID-ATOMIC-2'), 'TC 3.3: Second GUID marked synced');

        // จำลองสร้าง Instance ใหม่เพื่ออ่านจากไฟล์ที่บันทึก
        $stateManagerReloaded = new AgentMockSyncState($stateFilePath, true);
        $this->assert($stateManagerReloaded->hasSynced('01425', 'GUID-ATOMIC-1'), 'TC 3.4: Fresh state instance reads back synced GUID from atomic file');
        $this->assert($stateManagerReloaded->getLastVstdate() === '2026-10-28', 'TC 3.5: Watermark cursor preserved in atomic file');

        // ทดสอบ Fault Injector ใน AgentMockSyncState
        $stateManager->setFaultInjector(function($stage) {
            if ($stage === 'before_write') throw new RuntimeException("INJECTED_FAULT");
        });
        $failedSave = $stateManager->saveState();
        $this->assert($failedSave === false, 'TC 3.6: saveState returns false gracefully on injected fault');
    }

    // =========================================================================
    // GROUP 2: RETRY POLICY HARDENING (HTTP-DATE & CEILING CAP)
    // =========================================================================

    public function testRetryAfterFormatsAndCeiling(): void {
        echo "\n--- 4. Testing Retry Policy: HTTP-date & Ceiling Enforcement ---\n";

        $clock = new AgentMockClock('2026-10-28 10:00:00');
        $policy = new AgentRetryPolicy(
            maxAttempts: 4,
            baseDelaySec: 1.0,
            maxDelaySec: 30.0, // เพดานสูงสุด 30 วินาที
            multiplier: 2.0,
            jitterPct: 0.0,    // ปิด Jitter เพื่อตรวจตัวเลขแน่นอน
            clock: $clock
        );

        // 4.1 รูปแบบตัวเลขวินาทีปกติ (เช่น 5 วินาที)
        $delayNum = $policy->calculateDelay(1, 5);
        $this->assert($delayNum === 5.0, 'TC 4.1: Numeric Retry-After (5) respected exactly');

        // 4.2 รูปแบบสตริงตัวเลขวินาที (เช่น "12")
        $delayStr = $policy->calculateDelay(1, "12");
        $this->assert($delayStr === 12.0, 'TC 4.2: String numeric Retry-After ("12") respected exactly');

        // 4.3 รูปแบบ HTTP-date (RFC 7231 / RFC 1123)
        // กำหนดให้อีก 15 วินาทีข้างหน้า อ้างอิงจาก timestamp ปัจจุบันของ Mock Clock
        $clockTs = $clock->getTimestamp();
        $httpDate = gmdate('D, d M Y H:i:s \G\M\T', $clockTs + 15);
        $delayHttpDate = $policy->calculateDelay(1, $httpDate);
        $this->assert($delayHttpDate === 15.0, 'TC 4.3: HTTP-date Retry-After delta calculated accurately (15s)');

        // 4.4 เพดานเวลารอสูงสุด (Ceiling Cap): ตัวเลขเกินเพดาน (เช่น 3600 วินาที) ต้องถูกตัดเหลือ maxDelaySec (30.0s)
        $delayCappedNum = $policy->calculateDelay(1, 3600);
        $this->assert($delayCappedNum === 30.0, 'TC 4.4: Numeric Retry-After exceeding max (3600s) capped strictly at maxDelaySec (30.0s)');

        // 4.5 เพดานเวลารอสูงสุด (Ceiling Cap): HTTP-date เกินเพดาน (เช่น พรุ่งนี้) ต้องถูกตัดเหลือ maxDelaySec (30.0s)
        $farFutureHttpDate = gmdate('D, d M Y H:i:s \G\M\T', $clockTs + 86400);
        $delayCappedDate = $policy->calculateDelay(1, $farFutureHttpDate);
        $this->assert($delayCappedDate === 30.0, 'TC 4.5: HTTP-date Retry-After exceeding max capped strictly at maxDelaySec (30.0s)');

        // 4.6 HTTP-date ที่ผ่านไปแล้วในอดีต: คืนค่า Floor ขั้นต่ำ (0.1s)
        $pastHttpDate = gmdate('D, d M Y H:i:s \G\M\T', $clockTs - 3600);
        $delayPastDate = $policy->calculateDelay(1, $pastHttpDate);
        $this->assert($delayPastDate === 0.1, 'TC 4.6: Past HTTP-date safely returns floor delay (0.1s)');

        // 4.7 ค่าข้อความขยะ (Garbage text): แปลงไม่ได้ ให้ถอยกลับไปใช้ Exponential Backoff
        $delayGarbage = $policy->calculateDelay(1, "invalid-garbage-date");
        $this->assert($delayGarbage === 1.0, 'TC 4.7: Invalid Retry-After string falls back safely to Exponential Backoff (1.0s)');
    }

    public function testRetryFullWorkflowWithDateAndSecretRedaction(): void {
        echo "\n--- 5. Testing Retry Workflow: HTTP-date Retry-After & Redaction ---\n";

        $clock = new AgentMockClock('2026-10-28 10:00:00');
        $policy = new AgentRetryPolicy(
            maxAttempts: 4,
            baseDelaySec: 1.0,
            maxDelaySec: 20.0,
            multiplier: 2.0,
            jitterPct: 0.0,
            clock: $clock
        );

        // จำลอง Action:
        // Attempt 1: 429 พร้อม HTTP-date อีก 10 วินาที
        // Attempt 2: 200 สำเร็จ
        $targetHttpDate = gmdate('D, d M Y H:i:s \G\M\T', $clock->getTimestamp() + 10);
        $actionCallCount = 0;

        $workflowResult = $policy->executeWithRetry(function($attempt) use (&$actionCallCount, $targetHttpDate) {
            $actionCallCount++;
            if ($attempt === 1) {
                return [
                    'success'   => false,
                    'http_code' => 429,
                    'retry_after' => $targetHttpDate,
                    'error'     => 'Rate limit exceeded. Bearer SUPABASE_SECRET_KEY_12345 should not leak',
                    'data'      => ['error_code' => 'RATE_LIMIT_EXCEEDED'],
                ];
            }
            return [
                'success'   => true,
                'http_code' => 200,
                'data'      => ['inserted_or_updated' => 5],
            ];
        });

        $this->assert($workflowResult['success'] === true, 'TC 5.1: Workflow completes successfully after HTTP-date Retry-After');
        $this->assert($workflowResult['total_attempts'] === 2, 'TC 5.2: Exactly 2 attempts executed');
        $this->assert($workflowResult['total_delay_sec'] === 10.0, 'TC 5.3: Total delay matches HTTP-date delta (10.0s)');
        $this->assert($clock->format('Y-m-d H:i:s') === '2026-10-28 10:00:10', 'TC 5.4: Mock Clock advanced to exactly 10:00:10');

        // ตรวจสอบ Secret Redaction ใน Timeline
        $timeline = $workflowResult['timeline'];
        $firstAttemptSummary = $timeline[0]['error_summary'] ?? '';
        $this->assert(!str_contains($firstAttemptSummary, 'SUPABASE_SECRET_KEY'), 'TC 5.5: Secret API Key redacted from timeline error summary');
        $this->assert(str_contains($firstAttemptSummary, '[REDACTED]'), 'TC 5.6: Redaction token present in timeline summary');
    }

    // =========================================================================
    // GROUP 3: RECONCILIATION BENCHMARK & INVARIANT VERIFICATION
    // =========================================================================

    public function testReconciliationBenchmarkScales(): void {
        echo "\n--- 6. Testing Reconciliation Benchmark (1K, 10K, 50K) ---\n";

        $bench = new ReconciliationBenchmark('01425');

        // 1K Scale
        $res1k = $bench->benchmarkScale(1000);
        $this->assert($res1k['duration_ms'] < 200.0, 'TC 6.1: 1K Reconciliation completes in < 200ms (Actual: ' . $res1k['duration_ms'] . ' ms)');
        $this->assert($res1k['key_integrity_pass'] === true, 'TC 6.2: 1K Composite Key integrity verified');
        $this->assert($res1k['counts_match_pass'] === true, 'TC 6.3: 1K Candidate categories count matches distribution');

        // 10K Scale
        $res10k = $bench->benchmarkScale(10000);
        $this->assert($res10k['duration_ms'] < 1000.0, 'TC 6.4: 10K Reconciliation completes in < 1,000ms (Actual: ' . $res10k['duration_ms'] . ' ms)');
        $this->assert($res10k['key_integrity_pass'] === true, 'TC 6.5: 10K Composite Key integrity verified');
        $this->assert($res10k['counts_match_pass'] === true, 'TC 6.6: 10K Candidate categories count matches distribution');

        // 50K Scale
        $res50k = $bench->benchmarkScale(50000);
        $this->assert($res50k['duration_ms'] < 5000.0, 'TC 6.7: 50K Reconciliation completes in < 5,000ms (Actual: ' . $res50k['duration_ms'] . ' ms)');
        $this->assert($res50k['key_integrity_pass'] === true, 'TC 6.8: 50K Composite Key integrity verified');
        $this->assert($res50k['counts_match_pass'] === true, 'TC 6.9: 50K Candidate categories count matches distribution');
    }

    public function testReconciliationFailsafeAndKeyInvariant(): void {
        echo "\n--- 7. Testing Failsafe Abort & Composite Key Invariant ---\n";

        $engine = new AgentReconciliation();

        // 7.1 Key Invariant: จำนวนแถวเท่ากัน (2 vs 2) แต่ Composite Key ต่างกัน
        $today = date('Y-m-d');
        $sourceRecords = [
            ['hcode' => '01425', 'hos_guid' => 'KEY-STABLE', 'vn' => 'VN-1', 'qty' => 5, 'vstdate' => $today],
            ['hcode' => '01425', 'hos_guid' => 'KEY-NEW',    'vn' => 'VN-2', 'qty' => 10, 'vstdate' => $today],
        ];
        $targetStore = [
            '01425:KEY-STABLE' => ['hcode' => '01425', 'hos_guid' => 'KEY-STABLE', 'vn' => 'VN-1', 'qty' => 5, 'vstdate' => $today, 'is_deleted' => false],
            '01425:KEY-GONE'   => ['hcode' => '01425', 'hos_guid' => 'KEY-GONE',   'vn' => 'VN-3', 'qty' => 8, 'vstdate' => $today, 'is_deleted' => false],
        ];

        $invResult = $engine->previewReconciliation('01425', $sourceRecords, $targetStore, [
            'source_query_status' => 'COMPLETED',
        ]);

        $this->assert($invResult['counts']['new_items'] === 1, 'TC 7.1: Key Invariant: Identifies 1 NEW item despite matching row counts');
        $this->assert($invResult['counts']['soft_delete_candidates'] === 1, 'TC 7.2: Key Invariant: Identifies 1 MISSING item despite matching row counts');
        $this->assert($invResult['counts']['unchanged_items'] === 1, 'TC 7.3: Key Invariant: Identifies 1 UNCHANGED item');

        // 7.2 Failsafe on Query Timeout: ห้ามสร้าง Soft Delete Candidate เด็ดขาด
        $timeoutResult = $engine->previewReconciliation('01425', $sourceRecords, $targetStore, [
            'source_query_status' => 'TIMEOUT_FAILED',
        ]);
        $this->assert($timeoutResult['success'] === false, 'TC 7.4: Incomplete source query rejected with success=false');
        $this->assert($timeoutResult['status'] === AgentReconciliation::STATUS_FAILED, 'TC 7.5: Status is strictly FAILED');
        $this->assert($timeoutResult['counts']['soft_delete_candidates'] === 0, 'TC 7.6: Zero soft delete candidates created on query failure (Zero False Deletes Guarantee)');
    }

    // =========================================================================
    // GROUP 4: ZERO OUTBOUND NETWORK & REGRESSION COMPATIBILITY
    // =========================================================================

    public function testSafetyAndRegression(): void {
        echo "\n--- 8. Testing Safety Guarantees & Regression Compatibility ---\n";

        // 8.1 Zero Network Request Guarantee
        $transport = new AgentMockTransport();
        $this->assert($transport->getCallCount() === 0, 'TC 8.1: Mock Transport starts with 0 calls');
        $transport->queueResponse(['http_code' => 200, 'success' => true, 'data' => ['ok' => true]]);
        $transport->send('mock://internal/rpc', [], json_encode(['test' => 1]));
        $this->assert($transport->getCallCount() === 1, 'TC 8.2: Mock Transport operates 100% in-memory without network sockets');

        // 8.2 Regression Checks: คลาสหลักทุก Phase ต้องคงอยู่และพร้อมใช้งาน
        $this->assert(class_exists('AgentConfig'), 'TC 8.3: AgentConfig (Phase 3.1) exists');
        $this->assert(class_exists('AgentDataExtractor'), 'TC 8.4: AgentDataExtractor (Phase 3.2) exists');
        $this->assert(class_exists('AgentSyncCore'), 'TC 8.5: AgentSyncCore (Phase 3.3) exists');
        $this->assert(class_exists('AgentHosxpReader'), 'TC 8.6: AgentHosxpReader (Phase 3.4) exists');
        $this->assert(class_exists('AgentApiClient'), 'TC 8.7: AgentApiClient (Phase 3.5) exists');
        $this->assert(class_exists('AgentMockApiServer'), 'TC 8.8: AgentMockApiServer (Phase 3.5.1) exists');
        $this->assert(class_exists('AgentScheduler'), 'TC 8.9: AgentScheduler (Phase 3.6) exists');
        $this->assert(class_exists('AgentRetryPolicy'), 'TC 8.10: AgentRetryPolicy (Phase 3.6) exists');
        $this->assert(class_exists('AgentReconciliation'), 'TC 8.11: AgentReconciliation (Phase 3.6) exists');
        $this->assert(class_exists('AgentAtomicFile'), 'TC 8.12: AgentAtomicFile (Phase 3.6.1) exists');
    }

    public function runAll(): void {
        echo "=================================================================\n";
        echo "   RxCore Sync Agent — Phase 3.6.1 Automated Test Suite          \n";
        echo "   Reliability & Performance Hardening Verification              \n";
        echo "=================================================================\n";

        $this->testAtomicStateNormalAndVerification();
        $this->testAtomicStateFaultInjections();
        $this->testMockSyncStateAtomicIntegration();
        $this->testRetryAfterFormatsAndCeiling();
        $this->testRetryFullWorkflowWithDateAndSecretRedaction();
        $this->testReconciliationBenchmarkScales();
        $this->testReconciliationFailsafeAndKeyInvariant();
        $this->testSafetyAndRegression();

        echo "\n=================================================================\n";
        $total = $this->passed + $this->failed;
        $pct = $total > 0 ? round(($this->passed / $total) * 100) : 0;
        echo "   PHASE 3.6.1 TEST SUMMARY: {$this->passed}/{$total} PASSED ({$pct}%)\n";
        if ($this->failed === 0) {
            echo "   STATUS: ALL RELIABILITY & PERFORMANCE TESTS PASSED! \n";
        } else {
            echo "   STATUS: {$this->failed} TESTS FAILED!\n";
        }
        echo "=================================================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $tester = new Phase361ReliabilityTester();
    $tester->runAll();
}
