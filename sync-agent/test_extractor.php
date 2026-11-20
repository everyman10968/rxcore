<?php
/**
 * RxCore Sync Agent — Automated Test Suite for Data Extractor
 * Phase 3.2: Read-only Data Extraction
 * 
 * ครอบคลุมทั้ง 10 กรณีทดสอบตามข้อกำหนด Phase 3.2:
 * 1. ข้อมูลปกติที่ผ่านทุกเงื่อนไข
 * 2. รหัสยาที่ไม่มีในทะเบียน (ต้องข้ามโดยไม่บันทึก Log)
 * 3. hos_guid ซ้ำภายในสถานพยาบาลเดียวกัน (ตัดออก)
 * 4. hos_guid เดียวกันแต่คนละ hcode (ต้องไม่ถูกตัดว่าเป็นรายการซ้ำ)
 * 5. hos_guid ว่าง (แจ้งผลตรวจสอบว่าผิดรูปแบบ)
 * 6. qty เป็น 0 หรือติดลบ (แจ้งผลตรวจสอบว่าผิดรูปแบบ)
 * 7. วันที่อยู่นอกช่วงที่เลือก (กรองออกทาง SQL)
 * 8. ไม่มีข้อมูลในช่วงที่ระบุ (คืนค่าว่างอย่างถูกต้อง)
 * 9. Adapter หรือ Query เกิดข้อผิดพลาด (จัดการข้อผิดพลาดอย่างปลอดภัย)
 * 10. ข้อมูลที่มี vn หรือ hcode ไม่ครบ/ผิดรูปแบบ (แจ้งผลตรวจสอบว่าผิดรูปแบบ)
 * 
 * พร้อมการทดสอบด้านความปลอดภัยและความเป็นส่วนตัว (Privacy & Security):
 * 11. ตรวจสอบว่าไม่มี HN, CID, หรือข้อมูลส่วนบุคคลหลุดออกมาในผลลัพธ์
 * 12. ตรวจสอบว่า Query สร้าง Dynamic Placeholders ผ่าน PDO Parameter Binding ปลอดภัยจาก SQL Injection
 * 13. ตรวจสอบกรณีวันที่ไม่ถูกต้อง (start_date > end_date หรือ malformed date)
 */

require_once __DIR__ . '/agent_drug_registry.php';
require_once __DIR__ . '/mock_dataset.php';
require_once __DIR__ . '/agent_extractor.php';

class ExtractorTestSuite {

    private int $passed = 0;
    private int $failed = 0;
    private array $results = [];

    public function run(): int {
        echo "\n=======================================================\n";
        echo "   RxCore Sync Agent — Phase 3.2 Automated Test Suite   \n";
        echo "=======================================================\n\n";

        // Group 1: Required 10 Scenarios
        $this->testCase1_NormalValidData();
        $this->testCase2_UnregisteredDrugCodes();
        $this->testCase3_DuplicateHosGuidWithinSameHcode();
        $this->testCase4_SameHosGuidAcrossDifferentHcode();
        $this->testCase5_EmptyHosGuid();
        $this->testCase6_ZeroOrNegativeQty();
        $this->testCase7_DatesOutOfRange();
        $this->testCase8_EmptyDataset();
        $this->testCase9_QueryOrAdapterError();
        $this->testCase10_MissingOrInvalidVnAndHcode();

        // Group 2: Privacy, Security & Edge Cases
        $this->testCase11_NoPiiLeakageStrictFields();
        $this->testCase12_PdoSafeQueryBuilder();
        $this->testCase13_InvalidDateRangeHandling();

        $this->printSummary();
        return $this->failed > 0 ? 1 : 0;
    }

    private function assert(string $testName, bool $condition, string $detail = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$testName}\n";
            $this->results[] = ['name' => $testName, 'status' => 'PASS', 'detail' => $detail];
        } else {
            $this->failed++;
            echo "  [FAIL] {$testName}" . ($detail ? " — {$detail}" : "") . "\n";
            $this->results[] = ['name' => $testName, 'status' => 'FAIL', 'detail' => $detail];
        }
    }

    /**
     * Test Case 1: ข้อมูลปกติที่ผ่านทุกเงื่อนไข
     */
    private function testCase1_NormalValidData(): void {
        echo "--- Group 1: Primary Required Test Cases (1 - 10) ---\n";

        $customOp = [
            ['hos_guid' => '{TEST-VALID-01}', 'vn' => '6910250001', 'icode' => '1000001', 'vstdate' => '2026-10-25', 'qty' => 10.0],
            ['hos_guid' => '{TEST-VALID-02}', 'vn' => '6910250002', 'icode' => '1000002', 'vstdate' => '2026-10-25', 'qty' => 20.0],
        ];
        $customVn = [
            ['vn' => '6910250001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
            ['vn' => '6910250002', 'hn' => 'HN002', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
        ];
        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-25', '2026-10-25');

        $this->assert(
            "TC 1.1: Normal data extraction succeeds",
            $result['success'] === true,
            "Expected success=true"
        );
        $this->assert(
            "TC 1.2: All valid records passed validation",
            $result['total_read'] === 2 && $result['total_passed'] === 2 && $result['total_malformed'] === 0 && $result['duplicates_pruned'] === 0,
            "total_read=2, total_passed=2"
        );
        $this->assert(
            "TC 1.3: Output contains strictly the 6 required fields",
            !empty($result['sample_data']) && array_keys($result['sample_data'][0]) === ['hos_guid', 'vn', 'hcode', 'vstdate', 'tpu_code_list', 'qty'],
            "Keys match: " . json_encode(array_keys($result['sample_data'][0] ?? []))
        );
    }

    /**
     * Test Case 2: รหัสยาที่ไม่มีในทะเบียน ต้องข้ามโดยไม่บันทึก Log
     */
    private function testCase2_UnregisteredDrugCodes(): void {
        $customOp = [
            ['hos_guid' => '{VALID-DRUG-01}', 'vn' => '6910250001', 'icode' => '1000001', 'vstdate' => '2026-10-25', 'qty' => 10.0], // TPU 227514 (ในทะเบียน)
            ['hos_guid' => '{UNREG-DRUG-02}', 'vn' => '6910250001', 'icode' => '1999999', 'vstdate' => '2026-10-25', 'qty' => 10.0], // TPU 999999 (นอกทะเบียน)
        ];
        $customVn = [
            ['vn' => '6910250001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
        ];
        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-25', '2026-10-25');

        // ตรวจสอบว่ายานอกทะเบียนถูกกรองออก ไม่ผ่านออกมาใน sample_data
        $passedTpuCodes = array_column($result['sample_data'], 'tpu_code_list');
        $this->assert(
            "TC 2.1: Unregistered drug is excluded and only registered drug is returned",
            count($result['sample_data']) === 1 && !in_array('999999', $passedTpuCodes, true),
            "Returned TPU codes: " . json_encode($passedTpuCodes)
        );
        $this->assert(
            "TC 2.2: Skipping unregistered drug does NOT generate malformed error",
            $result['total_malformed'] === 0,
            "total_malformed should be 0"
        );
    }

    /**
     * Test Case 3: hos_guid ซ้ำภายในสถานพยาบาลเดียวกัน
     */
    private function testCase3_DuplicateHosGuidWithinSameHcode(): void {
        $customOp = [
            ['hos_guid' => '{SAME-GUID-SAME-HCODE}', 'vn' => '6910250001', 'icode' => '1000001', 'vstdate' => '2026-10-25', 'qty' => 10.0],
            ['hos_guid' => '{SAME-GUID-SAME-HCODE}', 'vn' => '6910250002', 'icode' => '1000002', 'vstdate' => '2026-10-25', 'qty' => 15.0],
        ];
        $customVn = [
            ['vn' => '6910250001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
            ['vn' => '6910250002', 'hn' => 'HN002', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
        ];
        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-25', '2026-10-25');

        $this->assert(
            "TC 3.1: Duplicate hos_guid in same hcode has duplicates_pruned = 1",
            $result['duplicates_pruned'] === 1,
            "Expected duplicates_pruned=1, got " . $result['duplicates_pruned']
        );
        $this->assert(
            "TC 3.2: Exactly 1 record passed out of 2 duplicate candidates",
            $result['total_passed'] === 1 && count($result['sample_data']) === 1,
            "total_passed=" . $result['total_passed']
        );
    }

    /**
     * Test Case 4: hos_guid เดียวกันแต่คนละ hcode ต้องไม่ถูกตัดว่าเป็นรายการซ้ำ
     */
    private function testCase4_SameHosGuidAcrossDifferentHcode(): void {
        $customOp = [
            ['hos_guid' => '{SHARED-GUID-DIFF-CLINIC}', 'vn' => '6910250001', 'icode' => '1000001', 'vstdate' => '2026-10-25', 'qty' => 10.0],
            ['hos_guid' => '{SHARED-GUID-DIFF-CLINIC}', 'vn' => '6910250002', 'icode' => '1000001', 'vstdate' => '2026-10-25', 'qty' => 10.0],
        ];
        $customVn = [
            ['vn' => '6910250001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-25'], // รพ.สต. 1
            ['vn' => '6910250002', 'hn' => 'HN002', 'hcode' => '01426', 'vstdate' => '2026-10-25'], // รพ.สต. 2
        ];
        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-25', '2026-10-25');

        $this->assert(
            "TC 4.1: Same hos_guid across different hcodes is NOT pruned as duplicate",
            $result['duplicates_pruned'] === 0,
            "Expected duplicates_pruned=0, got " . $result['duplicates_pruned']
        );
        $this->assert(
            "TC 4.2: Both records pass with respective distinct hcodes",
            $result['total_passed'] === 2 && count($result['sample_data']) === 2,
            "total_passed=" . $result['total_passed']
        );
    }

    /**
     * Test Case 5: hos_guid ว่าง (empty string หรือ whitespace)
     */
    private function testCase5_EmptyHosGuid(): void {
        $customOp = [
            ['hos_guid' => '',     'vn' => '6910250001', 'icode' => '1000001', 'vstdate' => '2026-10-25', 'qty' => 10.0],
            ['hos_guid' => '   ',  'vn' => '6910250002', 'icode' => '1000002', 'vstdate' => '2026-10-25', 'qty' => 10.0],
        ];
        $customVn = [
            ['vn' => '6910250001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
            ['vn' => '6910250002', 'hn' => 'HN002', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
        ];
        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-25', '2026-10-25');

        $this->assert(
            "TC 5.1: Empty and whitespace hos_guid flagged as malformed",
            $result['malformed_summary']['missing_hos_guid'] === 2,
            "missing_hos_guid=" . $result['malformed_summary']['missing_hos_guid']
        );
        $this->assert(
            "TC 5.2: Zero records passed validation when hos_guid is empty",
            $result['total_passed'] === 0,
            "total_passed=" . $result['total_passed']
        );
    }

    /**
     * Test Case 6: qty เป็น 0 หรือติดลบ
     */
    private function testCase6_ZeroOrNegativeQty(): void {
        $customOp = [
            ['hos_guid' => '{QTY-ZERO}', 'vn' => '6910250001', 'icode' => '1000001', 'vstdate' => '2026-10-25', 'qty' => 0.0],
            ['hos_guid' => '{QTY-NEG}',  'vn' => '6910250002', 'icode' => '1000002', 'vstdate' => '2026-10-25', 'qty' => -10.0],
        ];
        $customVn = [
            ['vn' => '6910250001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
            ['vn' => '6910250002', 'hn' => 'HN002', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
        ];
        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-25', '2026-10-25');

        $this->assert(
            "TC 6.1: Zero and negative qty flagged as invalid_qty in malformed summary",
            $result['malformed_summary']['invalid_qty'] === 2,
            "invalid_qty=" . $result['malformed_summary']['invalid_qty']
        );
        $this->assert(
            "TC 6.2: Records with invalid qty do not pass to sample_data",
            $result['total_passed'] === 0,
            "total_passed=" . $result['total_passed']
        );
    }

    /**
     * Test Case 7: วันที่อยู่นอกช่วงที่เลือก
     */
    private function testCase7_DatesOutOfRange(): void {
        $customOp = [
            ['hos_guid' => '{OUT-PAST}',   'vn' => '6908150001', 'icode' => '1000001', 'vstdate' => '2026-08-15', 'qty' => 10.0],
            ['hos_guid' => '{IN-RANGE}',   'vn' => '6910250001', 'icode' => '1000001', 'vstdate' => '2026-10-25', 'qty' => 10.0],
            ['hos_guid' => '{OUT-FUTURE}', 'vn' => '6911200001', 'icode' => '1000001', 'vstdate' => '2026-11-20', 'qty' => 10.0],
        ];
        $customVn = [
            ['vn' => '6908150001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-08-15'],
            ['vn' => '6910250001', 'hn' => 'HN002', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
            ['vn' => '6911200001', 'hn' => 'HN003', 'hcode' => '01425', 'vstdate' => '2026-11-20'],
        ];
        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-01', '2026-10-31');

        $this->assert(
            "TC 7.1: Query filters out records before start_date and after end_date",
            $result['total_read'] === 1 && $result['total_passed'] === 1,
            "total_read=" . $result['total_read']
        );
        $this->assert(
            "TC 7.2: Only record within 2026-10-25 returned",
            ($result['sample_data'][0]['hos_guid'] ?? '') === '{IN-RANGE}',
            "Matched guid: " . ($result['sample_data'][0]['hos_guid'] ?? '')
        );
    }

    /**
     * Test Case 8: ไม่มีข้อมูล (Empty dataset)
     */
    private function testCase8_EmptyDataset(): void {
        $pdo = MockDatasetPhase32::createMockPdo([], [], []);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-01', '2026-10-31');

        $this->assert(
            "TC 8.1: Empty dataset handled gracefully with success=true",
            $result['success'] === true,
            "success should be true"
        );
        $this->assert(
            "TC 8.2: All counters zero and sample_data empty",
            $result['total_read'] === 0 && $result['total_passed'] === 0 && $result['duplicates_pruned'] === 0 && $result['total_malformed'] === 0 && empty($result['sample_data']),
            "Counts: read=" . $result['total_read'] . ", passed=" . $result['total_passed']
        );
    }

    /**
     * Test Case 9: Query หรือ Mock Adapter เกิดข้อผิดพลาด
     */
    private function testCase9_QueryOrAdapterError(): void {
        // สร้าง In-memory PDO ว่างเปล่าที่ไม่มี table opitemrece/vn_stat/drugitems
        $brokenPdo = new PDO('sqlite::memory:');
        $brokenPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $extractor = new AgentDataExtractor($brokenPdo);

        $result = $extractor->extract('2026-10-01', '2026-10-31');

        $this->assert(
            "TC 9.1: Database error returns success=false gracefully without throwing uncaught exception",
            $result['success'] === false,
            "success should be false"
        );
        $this->assert(
            "TC 9.2: Error message is sanitized and does not leak credentials or passwords",
            !empty($result['error']) && stripos($result['error'], 'password') === false,
            "Error: " . ($result['error'] ?? '')
        );
    }

    /**
     * Test Case 10: ข้อมูลที่มี vn หรือ hcode ไม่ครบ
     */
    private function testCase10_MissingOrInvalidVnAndHcode(): void {
        $customOp = [
            ['hos_guid' => '{TEST-EMPTY-VN}',    'vn' => '',             'icode' => '1000001', 'vstdate' => '2026-10-26', 'qty' => 10.0],
            ['hos_guid' => '{TEST-EMPTY-HCODE}', 'vn' => '6910260001',   'icode' => '1000001', 'vstdate' => '2026-10-26', 'qty' => 10.0],
            ['hos_guid' => '{TEST-SHORT-HCODE}', 'vn' => '6910260002',   'icode' => '1000001', 'vstdate' => '2026-10-26', 'qty' => 10.0],
        ];
        $customVn = [
            ['vn' => '',           'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-26'],
            ['vn' => '6910260001', 'hn' => 'HN002', 'hcode' => '',      'vstdate' => '2026-10-26'],
            ['vn' => '6910260002', 'hn' => 'HN003', 'hcode' => '123',   'vstdate' => '2026-10-26'],
        ];
        $pdo = MockDatasetPhase32::createMockPdo($customOp, $customVn);
        $extractor = new AgentDataExtractor($pdo);

        $result = $extractor->extract('2026-10-26', '2026-10-26');

        $this->assert(
            "TC 10.1: Empty vn is flagged in malformed summary (missing_vn = 1)",
            $result['malformed_summary']['missing_vn'] === 1,
            "missing_vn=" . $result['malformed_summary']['missing_vn']
        );
        $this->assert(
            "TC 10.2: Empty or non-5-digit hcode is flagged (missing_or_invalid_hcode = 2)",
            $result['malformed_summary']['missing_or_invalid_hcode'] === 2,
            "missing_or_invalid_hcode=" . $result['malformed_summary']['missing_or_invalid_hcode']
        );
        $this->assert(
            "TC 10.3: None of the malformed vn/hcode records pass validation",
            $result['total_passed'] === 0,
            "total_passed=" . $result['total_passed']
        );
    }

    /**
     * Test Case 11: Privacy & Security - No HN, CID, or PII Leakage
     */
    private function testCase11_NoPiiLeakageStrictFields(): void {
        echo "\n--- Group 2: Privacy, Security & Edge Cases (11 - 13) ---\n";

        $extractor = new AgentDataExtractor();
        $result = $extractor->extract('2026-10-01', '2026-10-31');

        $forbiddenKeys = ['hn', 'cid', 'patient_name', 'pname', 'fname', 'lname', 'address', 'telephone'];
        $hasForbidden = false;
        $forbiddenFound = [];

        foreach ($result['sample_data'] as $row) {
            foreach ($forbiddenKeys as $fKey) {
                if (array_key_exists($fKey, $row)) {
                    $hasForbidden = true;
                    $forbiddenFound[] = $fKey;
                }
            }
        }

        $this->assert(
            "TC 11.1: Strict field isolation: No HN, CID, or PII in output records",
            $hasForbidden === false,
            "Forbidden keys found: " . implode(', ', array_unique($forbiddenFound))
        );
    }

    /**
     * Test Case 12: PDO Dynamic Parameter Binding in Query Builder
     */
    private function testCase12_PdoSafeQueryBuilder(): void {
        $sampleCodes = ['227514', '189422', '304192'];
        $queryInfo = AgentDataExtractor::buildQuery($sampleCodes);

        $this->assert(
            "TC 12.1: Query uses named placeholders (:drug_0, :drug_1, :drug_2)",
            strpos($queryInfo['sql'], ':drug_0, :drug_1, :drug_2') !== false,
            "SQL: " . $queryInfo['sql']
        );
        $this->assert(
            "TC 12.2: Placeholders count matches input drug codes",
            count($queryInfo['placeholders']) === 3 && $queryInfo['placeholders'][':drug_0'] === '227514',
            "Placeholders: " . json_encode($queryInfo['placeholders'])
        );
    }

    /**
     * Test Case 13: Invalid Date Range Handling
     */
    private function testCase13_InvalidDateRangeHandling(): void {
        $extractor = new AgentDataExtractor();

        // 13.1 Start date > End date
        $resReversed = $extractor->extract('2026-10-31', '2026-10-01');
        $this->assert(
            "TC 13.1: Start date > End date returns safe error",
            $resReversed['success'] === false && strpos($resReversed['error'], 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด') !== false,
            "Error: " . ($resReversed['error'] ?? '')
        );

        // 13.2 Malformed date format
        $resMalformedDate = $extractor->extract('invalid-date', '2026-10-31');
        $this->assert(
            "TC 13.2: Malformed date string returns validation error",
            $resMalformedDate['success'] === false && strpos($resMalformedDate['error'], 'รูปแบบวันที่ไม่ถูกต้อง') !== false,
            "Error: " . ($resMalformedDate['error'] ?? '')
        );
    }

    private function printSummary(): void {
        $total = $this->passed + $this->failed;
        $successRate = $total > 0 ? round(($this->passed / $total) * 100, 1) : 0;

        echo "\n=======================================================\n";
        echo "   TEST SUMMARY: {$this->passed}/{$total} PASSED ({$successRate}%)\n";
        if ($this->failed === 0) {
            echo "   STATUS: ALL TESTS PASSED SUCCESSFULLY! \n";
        } else {
            echo "   STATUS: {$this->failed} TEST(S) FAILED!\n";
        }
        echo "=======================================================\n\n";
    }
}

// Execute tests if run directly
$suite = new ExtractorTestSuite();
exit($suite->run());
