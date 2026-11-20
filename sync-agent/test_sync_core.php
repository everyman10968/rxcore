<?php
/**
 * RxCore Sync Agent — Phase 3.3 Automated Test Suite
 * Test Suite for AgentSyncCore & AgentMockSyncState
 * 
 * ครอบคลุม Test Cases ทั้งหมดตามข้อกำหนด Phase 3.3:
 * 1. Initial Sync ย้อนหลัง 1 ปี (1-year window calculation & extraction)
 * 2. Incremental Sync ที่มีรายการใหม่ในวันเดิม (Same-date new records & watermark)
 * 3. รายการที่มีอยู่แล้วไม่ถูกนับเป็นรายการใหม่ (Already-synced detection)
 * 4. hos_guid เดียวกันแต่ต่าง hcode (Composite key isolation)
 * 5. hos_guid ซ้ำใน hcode เดียวกัน (Deduplication within same provider)
 * 6. รหัสยานอกทะเบียนต้องถูกข้ามโดยไม่บันทึก Log
 * 7. ข้อมูลผิดรูปแบบต้องไม่ผ่านเข้าสู่ Preview ของรายการที่จะ Sync
 * 8. ไม่มีข้อมูลและไม่มีรายการใหม่ (Empty dataset handling)
 * 9. รันซ้ำสองครั้งแล้วไม่เพิ่มรายการใหม่ซ้ำ (Idempotence)
 * 10. วันเริ่มต้นและสิ้นสุดไม่ถูกต้อง (Date validation & error handling)
 * 11. ข้อผิดพลาดระหว่าง Pipeline ต้องไม่เปิดเผยข้อมูลลับ (Error sanitization)
 * 12. Strict Field Isolation (เฉพาะ 6 ฟิลด์ ห้ามมี HN, CID หรือ PII)
 */

require_once __DIR__ . '/agent_sync_core.php';
require_once __DIR__ . '/agent_mock_state.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/mock_dataset.php';
require_once __DIR__ . '/agent_drug_registry.php';

class Phase33SyncCoreTester {

    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    private function assert(bool $condition, string $testName, string $failMessage = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failed++;
            $msg = "  [FAIL] {$testName}: {$failMessage}\n";
            $this->errors[] = $msg;
            echo $msg;
        }
    }

    public function runAll(): int {
        echo "\n=================================================================\n";
        echo "   RxCore Sync Agent — Phase 3.3 Automated Test Suite           \n";
        echo "=================================================================\n\n";

        $this->testInitialSyncOneYear();
        $this->testIncrementalSyncSameDateNewRecords();
        $this->testAlreadySyncedItemsNotCountedAsNew();
        $this->testSameGuidDifferentHcode();
        $this->testDuplicateGuidSameHcode();
        $this->testUnregisteredDrugSkippedSilently();
        $this->testMalformedDataExcludedFromPreview();
        $this->testEmptyDatasetAndNoNewItems();
        $this->testIdempotenceDoubleRun();
        $this->testInvalidDatesSafeHandling();
        $this->testPipelineErrorDoesNotRevealSecrets();
        $this->testStrictFieldIsolationNoPii();

        echo "\n=================================================================\n";
        echo sprintf("   PHASE 3.3 TEST SUMMARY: %d/%d PASSED (%s%%)\n",
            $this->passed,
            $this->passed + $this->failed,
            $this->passed + $this->failed > 0 ? round(($this->passed / ($this->passed + $this->failed)) * 100, 1) : 0
        );
        if ($this->failed === 0) {
            echo "   STATUS: ALL PHASE 3.3 SYNC CORE TESTS PASSED! \n";
        } else {
            echo "   STATUS: SOME TESTS FAILED!\n";
            foreach ($this->errors as $err) {
                echo $err;
            }
        }
        echo "=================================================================\n\n";
        return $this->failed > 0 ? 1 : 0;
    }

    /**
     * TC 1: Initial Sync ย้อนหลัง 1 ปี
     */
    private function testInitialSyncOneYear(): void {
        echo "--- TC 1: Initial Sync 1 Year Backward ---\n";

        $mockState = new AgentMockSyncState(null); // In-memory
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        // รัน Initial Sync โดยระบุเฉพาะ end_date (2026-10-31) เพื่อให้ระบบคำนวณย้อนหลัง 1 ปี (2025-10-31)
        $endDate = '2026-10-31';
        $result = $syncCore->runInitialSyncPreview(null, $endDate);

        $expectedStartDate = date('Y-m-d', strtotime('-1 year', strtotime($endDate)));
        $this->assert($result['success'] === true, 'TC 1.1: Initial Sync succeeds');
        $this->assert($result['status'] === 'PREVIEW_ONLY', 'TC 1.2: Status is strictly PREVIEW_ONLY');
        $this->assert($result['sync_type'] === 'INITIAL_SYNC', 'TC 1.3: Sync type is INITIAL_SYNC');
        $this->assert($result['start_date'] === $expectedStartDate, "TC 1.4: Start date defaults to 1 year backward ({$expectedStartDate})");
        $this->assert($result['end_date'] === $endDate, 'TC 1.5: End date matches requested target');
        $this->assert($result['total_passed'] === 9, "TC 1.6: Total valid records passed in 1-year window is 9 (including past record 2026-08-15, found {$result['total_passed']})");
        $this->assert($result['new_records_count'] === 9, 'TC 1.7: All 9 valid records are classified as new_records in clean state');
        $this->assert($result['already_synced_count'] === 0, 'TC 1.8: Already synced count is 0 in fresh state');
    }

    /**
     * TC 2: Incremental Sync ที่มีรายการใหม่ในวันเดิม
     * ทดสอบว่าหากเคย Sync วันที่ 2026-10-26 ไปแล้ว และมีรายการใหม่เกิดขึ้นในวันที่ 2026-10-26 เดียวกัน
     * ระบบต้องตรวจพบเฉพาะรายการใหม่ โดยไม่ข้ามและไม่นำรายการเดิมมานับซ้ำ
     */
    private function testIncrementalSyncSameDateNewRecords(): void {
        echo "--- TC 2: Incremental Sync with New Records on Same Date ---\n";

        $mockState = new AgentMockSyncState(null); // In-memory

        // สมมติว่ามีรายการจ่ายยา 2 รายการเดิมในวันที่ 2026-10-26 ถูก Sync ไปแล้ว
        $previouslySynced = [
            [
                'hcode'    => '01425',
                'hos_guid' => '{A1B2C3D4-0003-4000-8000-000000000003}',
                'vstdate'  => '2026-10-26',
            ],
            [
                'hcode'    => '01425',
                'hos_guid' => '{A1B2C3D4-0004-4000-8000-000000000004}',
                'vstdate'  => '2026-10-26',
            ]
        ];
        $mockState->batchRecordSynced($previouslySynced, 'INITIAL_SYNC');

        // ตรวจสอบ Cursor ก่อนหน้า
        $this->assert($mockState->getLastVstdate() === '2026-10-26', 'TC 2.1: Initial cursor last_vstdate is 2026-10-26');

        // สร้าง Custom Mock Dataset ที่มีรายการใหม่เพิ่มเข้ามาในวันเดียวกัน (2026-10-26)
        $customOp = [
            // รายการเก่าเดิม 2 รายการ
            [
                'hos_guid' => '{A1B2C3D4-0003-4000-8000-000000000003}',
                'vn'       => '691026080001',
                'icode'    => '1000003',
                'vstdate'  => '2026-10-26',
                'qty'      => 60.0,
            ],
            [
                'hos_guid' => '{A1B2C3D4-0004-4000-8000-000000000004}',
                'vn'       => '691026080002',
                'icode'    => '1000004',
                'vstdate'  => '2026-10-26',
                'qty'      => 30.0,
            ],
            // รายการใหม่ล่าสุดที่เพิ่งบันทึกเพิ่มเข้ามาในวันเดียวกัน!
            [
                'hos_guid' => '{NEW-SAME-DAY-RECORD-9999}',
                'vn'       => '691026080099',
                'icode'    => '1000001', // Paracetamol
                'vstdate'  => '2026-10-26', // วันเดียวกันกับ Cursor!
                'qty'      => 20.0,
            ]
        ];
        $customVn = [
            ['vn' => '691026080001', 'hn' => 'HN003', 'hcode' => '01425', 'vstdate' => '2026-10-26'],
            ['vn' => '691026080002', 'hn' => 'HN004', 'hcode' => '01425', 'vstdate' => '2026-10-26'],
            ['vn' => '691026080099', 'hn' => 'HN099', 'hcode' => '01425', 'vstdate' => '2026-10-26'],
        ];

        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);
        $syncCore = new AgentSyncCore($extractor, $mockState);

        // รัน Incremental Sync โดยระบบจะดึงจุดอ้างอิงจาก last_vstdate (2026-10-26)
        $incResult = $syncCore->runIncrementalSyncPreview(null, '2026-10-26');

        $this->assert($incResult['success'] === true, 'TC 2.2: Incremental sync execution succeeds');
        $this->assert($incResult['sync_type'] === 'INCREMENTAL_SYNC', 'TC 2.3: Type is INCREMENTAL_SYNC');
        $this->assert($incResult['new_records_count'] === 1, "TC 2.4: Exactly 1 new record identified on same date (found {$incResult['new_records_count']})");
        $this->assert($incResult['already_synced_count'] === 2, "TC 2.5: Exactly 2 previously synced records separated (found {$incResult['already_synced_count']})");
        $this->assert($incResult['new_records'][0]['hos_guid'] === '{NEW-SAME-DAY-RECORD-9999}', 'TC 2.6: New record matches the added same-day GUID');
    }

    /**
     * TC 3: รายการที่มีอยู่แล้วไม่ถูกนับเป็นรายการใหม่
     */
    private function testAlreadySyncedItemsNotCountedAsNew(): void {
        echo "--- TC 3: Previously Synced Items Not Counted as New ---\n";

        $mockState = new AgentMockSyncState(null);
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        // รัน Initial Sync และจำลองการ Commit ทั้งหมดเข้า Mock State
        $firstRun = $syncCore->runInitialSyncPreview('2026-10-25', '2026-10-27');
        $syncCore->simulateMockCommit($firstRun['new_records'], 'INITIAL_SYNC');

        // รันอีกครั้งในช่วงเวลาเดียวกัน
        $secondRun = $syncCore->runInitialSyncPreview('2026-10-25', '2026-10-27');

        $this->assert($secondRun['success'] === true, 'TC 3.1: Second run succeeds');
        $this->assert($secondRun['new_records_count'] === 0, 'TC 3.2: new_records_count is 0 because all were synced');
        $this->assert($secondRun['already_synced_count'] === 8, "TC 3.3: already_synced_count is 8 (found {$secondRun['already_synced_count']})");
        $this->assert(empty($secondRun['new_records']), 'TC 3.4: new_records array is completely empty');
    }

    /**
     * TC 4: hos_guid เดียวกันแต่ต่าง hcode
     */
    private function testSameGuidDifferentHcode(): void {
        echo "--- TC 4: Same hos_guid Across Different hcode ---\n";

        $mockState = new AgentMockSyncState(null);
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        $result = $syncCore->runInitialSyncPreview('2026-10-26', '2026-10-26');

        // ใน Mock Dataset มี {SHARED-GUID-DIFF-HCODE} อยู่ทั้งใน 01425 และ 01426
        $sharedRecords = array_filter($result['new_records'], function($r) {
            return $r['hos_guid'] === '{SHARED-GUID-DIFF-HCODE}';
        });

        $this->assert(count($sharedRecords) === 2, 'TC 4.1: Exactly 2 records for {SHARED-GUID-DIFF-HCODE} found');
        $hcodes = array_column(array_values($sharedRecords), 'hcode');
        sort($hcodes);
        $this->assert($hcodes === ['01425', '01426'], 'TC 4.2: Records belong to distinct hcodes (01425 and 01426)');
    }

    /**
     * TC 5: hos_guid ซ้ำใน hcode เดียวกัน
     */
    private function testDuplicateGuidSameHcode(): void {
        echo "--- TC 5: Duplicate hos_guid in Same hcode ---\n";

        $mockState = new AgentMockSyncState(null);
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        $result = $syncCore->runInitialSyncPreview('2026-10-26', '2026-10-26');

        // {DUP-HCODE-01425} ปรากฏ 2 ครั้งใน hcode 01425
        $dupGuidRecords = array_filter($result['new_records'], function($r) {
            return $r['hos_guid'] === '{DUP-HCODE-01425}';
        });

        $this->assert(count($dupGuidRecords) === 1, 'TC 5.1: Duplicate hos_guid in same hcode pruned to exactly 1 record');
        $this->assert($result['duplicates_pruned'] === 1, 'TC 5.2: duplicates_pruned count is exactly 1');
    }

    /**
     * TC 6: รหัสยานอกทะเบียนต้องถูกข้ามโดยไม่บันทึก Log
     */
    private function testUnregisteredDrugSkippedSilently(): void {
        echo "--- TC 6: Unregistered Drug Skipped Without Logging ---\n";

        $mockState = new AgentMockSyncState(null);
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        $result = $syncCore->runInitialSyncPreview('2026-10-25', '2026-10-27');

        // ตรวจสอบว่าไม่มี icode 1999999 หรือ tpu 999999 หลุดเข้ามา
        $unregistered = array_filter($result['new_records'], function($r) {
            return ($r['tpu_code_list'] ?? '') === '999999';
        });

        $this->assert(empty($unregistered), 'TC 6.1: Unregistered drug 999999 is completely absent from new_records');
        $this->assert($result['malformed_summary']['missing_hos_guid'] === 2, 'TC 6.2: Skipping unregistered drug does not skew malformed summary');
    }

    /**
     * TC 7: ข้อมูลผิดรูปแบบต้องไม่ผ่านเข้าสู่ Preview ของรายการที่จะ Sync
     */
    private function testMalformedDataExcludedFromPreview(): void {
        echo "--- TC 7: Malformed Data Excluded from Preview ---\n";

        $mockState = new AgentMockSyncState(null);
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        $result = $syncCore->runInitialSyncPreview('2026-10-25', '2026-10-27');

        $this->assert($result['total_malformed'] === 7, "TC 7.1: Exactly 7 malformed records detected (found {$result['total_malformed']})");

        // ตรวจสอบทุกลิสต์ใน new_records ต้องไม่มีค่าว่างในฟิลด์สำคัญ และ qty > 0
        $hasInvalidInPreview = false;
        foreach ($result['new_records'] as $r) {
            if (empty($r['hos_guid']) || empty($r['vn']) || empty($r['hcode']) || (float)$r['qty'] <= 0) {
                $hasInvalidInPreview = true;
                break;
            }
        }
        $this->assert(!$hasInvalidInPreview, 'TC 7.2: Zero malformed records entered new_records preview list');
    }

    /**
     * TC 8: ไม่มีข้อมูลและไม่มีรายการใหม่
     */
    private function testEmptyDatasetAndNoNewItems(): void {
        echo "--- TC 8: Empty Dataset & No New Items Handling ---\n";

        $emptyPdo = MockDatasetPhase32::createMockPdo([], [], []);
        $extractor = new AgentDataExtractor($emptyPdo);
        $mockState = new AgentMockSyncState(null);
        $syncCore = new AgentSyncCore($extractor, $mockState);

        $result = $syncCore->runInitialSyncPreview('2026-10-01', '2026-10-31');

        $this->assert($result['success'] === true, 'TC 8.1: Empty dataset handled gracefully with success=true');
        $this->assert($result['total_read'] === 0, 'TC 8.2: total_read is 0');
        $this->assert($result['new_records_count'] === 0, 'TC 8.3: new_records_count is 0');
        $this->assert(empty($result['new_records']), 'TC 8.4: new_records is empty array');
        $this->assert($result['status'] === 'PREVIEW_ONLY', 'TC 8.5: Status remains PREVIEW_ONLY');
    }

    /**
     * TC 9: รันซ้ำสองครั้งแล้วไม่เพิ่มรายการใหม่ซ้ำ (Idempotence)
     */
    private function testIdempotenceDoubleRun(): void {
        echo "--- TC 9: Idempotence Double Run ---\n";

        $mockState = new AgentMockSyncState(null);
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        // ครั้งที่ 1
        $res1 = $syncCore->runInitialSyncPreview('2026-10-25', '2026-10-25');
        $this->assert($res1['new_records_count'] === 2, 'TC 9.1: First run identifies 2 new records');

        // จำลอง Commit เข้า Mock State
        $commit = $syncCore->simulateMockCommit($res1['new_records'], 'INITIAL_SYNC');
        $this->assert($commit['success'] === true, 'TC 9.2: Simulate commit succeeds');
        $this->assert($commit['status'] === 'MOCK_COMMITTED', 'TC 9.3: Commit status is MOCK_COMMITTED');

        // ครั้งที่ 2
        $res2 = $syncCore->runInitialSyncPreview('2026-10-25', '2026-10-25');
        $this->assert($res2['new_records_count'] === 0, 'TC 9.4: Second run identifies 0 new records (Idempotence confirmed)');
        $this->assert($res2['already_synced_count'] === 2, 'TC 9.5: Second run shows both 2 records in already_synced_records');
    }

    /**
     * TC 10: วันเริ่มต้นและสิ้นสุดไม่ถูกต้อง
     */
    private function testInvalidDatesSafeHandling(): void {
        echo "--- TC 10: Invalid Date Parameters Handling ---\n";

        $mockState = new AgentMockSyncState(null);
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        // start_date > end_date
        $res1 = $syncCore->runInitialSyncPreview('2026-10-31', '2026-10-01');
        $this->assert($res1['success'] === false, 'TC 10.1: start_date > end_date rejected');
        $this->assert(strpos($res1['error'], 'เริ่มต้นต้องไม่มากกว่า') !== false, 'TC 10.2: Safe Thai error message returned');

        // รูปแบบวันที่ผิด
        $res2 = $syncCore->runInitialSyncPreview('invalid-date', '2026-10-31');
        $this->assert($res2['success'] === false, 'TC 10.3: Malformed date string rejected');
        $this->assert(strpos($res2['error'], 'YYYY-MM-DD') !== false, 'TC 10.4: Error specifies expected YYYY-MM-DD format');
    }

    /**
     * TC 11: ข้อผิดพลาดระหว่าง Pipeline ต้องไม่เปิดเผยข้อมูลลับ
     */
    private function testPipelineErrorDoesNotRevealSecrets(): void {
        echo "--- TC 11: Safe Error Handling Without Secret Leaks ---\n";

        // จำลอง PDO ที่เกิด Error โดยไม่มีตาราง
        $errorPdo = new PDO('sqlite::memory:');
        $errorPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $extractor = new AgentDataExtractor($errorPdo);
        $mockState = new AgentMockSyncState(null);
        $syncCore = new AgentSyncCore($extractor, $mockState);

        $res = $syncCore->runInitialSyncPreview('2026-10-01', '2026-10-31');

        $this->assert($res['success'] === false, 'TC 11.1: Database error handled gracefully with success=false');
        $this->assert(isset($res['error']), 'TC 11.2: Error message exists');
        $this->assert(stripos($res['error'], 'password') === false || stripos($res['error'], 'password=***') !== false, 'TC 11.3: Password patterns are redacted or absent');
        $this->assert(strpos($res['error'], 'C:\\') === false, 'TC 11.4: System directory paths are hidden');
    }

    /**
     * TC 12: Strict Field Isolation (เฉพาะ 6 ฟิลด์ ห้ามมี HN, CID หรือ PII)
     */
    private function testStrictFieldIsolationNoPii(): void {
        echo "--- TC 12: Strict Field Isolation (No HN, CID, PII) ---\n";

        $mockState = new AgentMockSyncState(null);
        $extractor = new AgentDataExtractor();
        $syncCore = new AgentSyncCore($extractor, $mockState);

        $result = $syncCore->runInitialSyncPreview('2026-10-25', '2026-10-27');
        $allowedSet = array_flip(AgentSyncCore::ALLOWED_FIELDS);

        $violations = 0;
        foreach ($result['new_records'] as $record) {
            foreach (array_keys($record) as $key) {
                if (!isset($allowedSet[$key])) {
                    $violations++;
                }
            }
            if (isset($record['hn']) || isset($record['cid']) || isset($record['fname']) || isset($record['lname'])) {
                $violations++;
            }
        }

        $this->assert($violations === 0, 'TC 12.1: Strictly 0 privacy or non-whitelisted fields in new_records');
        $this->assert(count($result['new_records'][0]) === 6, 'TC 12.2: Each record has exactly 6 fields');
    }
}

// รันการทดสอบหากเรียกจาก CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $tester = new Phase33SyncCoreTester();
    exit($tester->runAll());
}
