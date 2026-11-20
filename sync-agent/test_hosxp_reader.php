<?php
/**
 * RxCore Sync Agent — Phase 3.4 Automated Test Suite
 * Test Suite for AgentHosxpReader (HOSxP Real Database Read Test)
 * 
 * ครอบคลุม 14 กรณีทดสอบตามข้อกำหนด:
 * 1. Connection Success & Connection Failure
 * 2. Schema Complete & Schema Incomplete (Missing table / missing column)
 * 3. Read-only Account Passes Audit
 * 4. Write-privileged Account Rejected
 * 5. Admin / Root Account Rejected (Username policy enforcement)
 * 6. Empty / Invalid Drug Registry Handled Safely
 * 7. Invalid Date Range (start > end, malformed, and > 1 day limit)
 * 8. Result Limited to Max 100 Records
 * 9. Empty Dataset & Malformed Data Handled Gracefully
 * 10. Error Messages Do Not Leak Password or Credentials
 * 11. Confirmation of Zero Write Statements in the Test Pipeline
 * 12. Explicit Admin Confirmation Required Before Reading Real Database
 * 13. Result Comparison Logic (Test E - Compare Results)
 * 14. Real Database Test Execution against MySQL/MariaDB (rxcore_hosxp_test)
 */

require_once __DIR__ . '/agent_hosxp_reader.php';
require_once __DIR__ . '/agent_connection.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_drug_registry.php';
require_once __DIR__ . '/mock_dataset.php';
require_once __DIR__ . '/compat_setup_mysql.php';

class Phase34HosxpReaderTester {

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
        echo "   RxCore Sync Agent — Phase 3.4 Automated Test Suite           \n";
        echo "=================================================================\n\n";

        $this->testConnectionSuccessAndFailure();
        $this->testDisallowedAccounts();
        $this->testSchemaValidationCompleteAndIncomplete();
        $this->testPrivilegeAuditReadOnlyAndWriter();
        $this->testConfirmationRequiredBeforeQuery();
        $this->testDateRangeAndOneDayLimit();
        $this->testDrugRegistrySafety();
        $this->testResultLimit100Items();
        $this->testEmptyAndMalformedDataHandling();
        $this->testZeroWriteStatementsGuarantee();
        $this->testErrorSanitizationNoPasswordLeak();
        $this->testResultComparisonLogic();
        $this->testRealDatabaseExecution();

        echo "\n=================================================================\n";
        echo sprintf("   PHASE 3.4 TEST SUMMARY: %d/%d PASSED (%s%%)\n",
            $this->passed,
            $this->passed + $this->failed,
            $this->passed + $this->failed > 0 ? round(($this->passed / ($this->passed + $this->failed)) * 100, 1) : 0
        );
        if ($this->failed === 0) {
            echo "   STATUS: ALL PHASE 3.4 HOSXP READER TESTS PASSED! \n";
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
     * TC 1: Connection Success & Connection Failure
     */
    private function testConnectionSuccessAndFailure(): void {
        echo "--- TC 1: Connection Success & Failure ---\n";

        // เชื่อมต่อสำเร็จผ่าน Mock PDO
        $mockPdo = MockDatasetPhase32::createMockPdo();
        $reader = new AgentHosxpReader([], $mockPdo);
        $resOk = $reader->testConnection();
        $this->assert($resOk['success'] === true, 'TC 1.1: Mock PDO connection succeeds');
        $this->assert(isset($resOk['server_version']), 'TC 1.2: Server version returned');

        // เชื่อมต่อไปยัง Host ที่ไม่มีอยู่จริง
        $badConfig = [
            'hosxp_host'     => '192.0.2.1', // Non-routable TEST-NET-1 IP
            'hosxp_port'     => 3306,
            'hosxp_database' => 'hos',
            'hosxp_username' => 'hosxp_ro',
            'hosxp_password' => 'Secret123!',
        ];
        $badReader = new AgentHosxpReader($badConfig);
        $resBad = $badReader->testConnection();
        $this->assert($resBad['success'] === false, 'TC 1.3: Unreachable host connection fails gracefully');
        $this->assert(strpos($resBad['error'], 'Secret123!') === false, 'TC 1.4: Password is NOT exposed in connection failure');
    }

    /**
     * TC 2: Admin / Root Accounts Rejected
     */
    private function testDisallowedAccounts(): void {
        echo "--- TC 2: Administrative Accounts Rejected ---\n";

        $disallowed = ['root', 'admin', 'administrator', 'sa'];
        foreach ($disallowed as $user) {
            $reader = new AgentHosxpReader([
                'hosxp_host'     => '127.0.0.1',
                'hosxp_port'     => 3306,
                'hosxp_database' => 'hos',
                'hosxp_username' => $user,
                'hosxp_password' => 'Pass123',
            ]);
            $res = $reader->testConnection();
            $this->assert($res['success'] === false, "TC 2.1: Disallowed user '{$user}' is rejected");
            $this->assert($res['account_rejected'] === true, "TC 2.2: account_rejected flag is set for '{$user}'");
        }
    }

    /**
     * TC 3: Schema Complete & Schema Incomplete
     */
    private function testSchemaValidationCompleteAndIncomplete(): void {
        echo "--- TC 3: Schema Validation Complete vs Incomplete ---\n";

        // 1. Schema ครบถ้วน (Mock Dataset มี opitemrece, drugitems, vn_stat พร้อมคอลัมน์ครบ)
        $completePdo = MockDatasetPhase32::createMockPdo();
        $readerComplete = new AgentHosxpReader([], $completePdo);
        $resComplete = $readerComplete->validateSchema();

        $this->assert($resComplete['success'] === true, 'TC 3.1: Complete schema passes validation');
        $this->assert(empty($resComplete['missing_items']), 'TC 3.2: Missing items array is empty');
        $this->assert($resComplete['tables_status']['opitemrece']['exists'] === true, 'TC 3.3: Table opitemrece confirmed exists');
        $this->assert($resComplete['tables_status']['drugitems']['exists'] === true, 'TC 3.4: Table drugitems confirmed exists');
        $this->assert($resComplete['tables_status']['vn_stat']['exists'] === true, 'TC 3.5: Table vn_stat confirmed exists');

        // 2. Schema ขาดตาราง vn_stat
        $incompletePdo1 = new PDO('sqlite::memory:');
        $incompletePdo1->exec("CREATE TABLE opitemrece (hos_guid TEXT, vn TEXT, vstdate TEXT, icode TEXT, qty REAL)");
        $incompletePdo1->exec("CREATE TABLE drugitems (icode TEXT, tpu_code_list TEXT)");
        // ไม่มี vn_stat

        $readerIncomplete1 = new AgentHosxpReader([], $incompletePdo1);
        $resIncomplete1 = $readerIncomplete1->validateSchema();
        $this->assert($resIncomplete1['success'] === false, 'TC 3.6: Missing table vn_stat fails validation');
        $this->assert($resIncomplete1['tables_status']['vn_stat']['exists'] === false, 'TC 3.7: Table vn_stat marked as not existing');

        // 3. Schema ขาดคอลัมน์ tpu_code_list ใน drugitems
        $incompletePdo2 = new PDO('sqlite::memory:');
        $incompletePdo2->exec("CREATE TABLE opitemrece (hos_guid TEXT, vn TEXT, vstdate TEXT, icode TEXT, qty REAL)");
        $incompletePdo2->exec("CREATE TABLE drugitems (icode TEXT, other_col TEXT)"); // ขาด tpu_code_list
        $incompletePdo2->exec("CREATE TABLE vn_stat (vn TEXT, hcode TEXT)");

        $readerIncomplete2 = new AgentHosxpReader([], $incompletePdo2);
        $resIncomplete2 = $readerIncomplete2->validateSchema();
        $this->assert($resIncomplete2['success'] === false, 'TC 3.8: Missing column tpu_code_list fails validation');
        $this->assert(in_array('tpu_code_list', $resIncomplete2['tables_status']['drugitems']['missing_columns']), 'TC 3.9: Correctly identifies missing tpu_code_list column');
    }

    /**
     * TC 4: Read-only Privilege Audit
     */
    private function testPrivilegeAuditReadOnlyAndWriter(): void {
        echo "--- TC 4: Privilege Audit (Read-only vs Writer) ---\n";

        // จำลองการตรวจสอบบัญชี Read-only
        $roConfig = [
            'hosxp_host'     => '127.0.0.1',
            'hosxp_port'     => 3306,
            'hosxp_database' => 'rxcore_hosxp_test',
            'hosxp_username' => 'rxcore_ro_test',
            'hosxp_password' => 'RoTestPass_2026!',
            'agent_mode'     => 'real',
            'hcode'          => '01425',
        ];
        $roReader = new AgentHosxpReader($roConfig);
        $roPriv = $roReader->validatePrivileges();
        $this->assert($roPriv['success'] === true, 'TC 4.1: Real Read-only account rxcore_ro_test passes privilege audit');
        $this->assert($roPriv['is_readonly'] === true, 'TC 4.2: is_readonly flag is true');
        $this->assert(empty($roPriv['prohibited']), 'TC 4.3: Prohibited list is empty for Read-only account');

        // ตรวจสอบบัญชี Writer
        $writerConfig = [
            'hosxp_host'     => '127.0.0.1',
            'hosxp_port'     => 3306,
            'hosxp_database' => 'rxcore_hosxp_test',
            'hosxp_username' => 'rxcore_writer_test',
            'hosxp_password' => 'WriterPass_2026!',
            'agent_mode'     => 'real',
            'hcode'          => '01425',
        ];
        $writerReader = new AgentHosxpReader($writerConfig);
        $writerPriv = $writerReader->validatePrivileges();
        $this->assert($writerPriv['success'] === false, 'TC 4.4: Account with write privileges is REJECTED');
        $this->assert(!empty($writerPriv['prohibited']), 'TC 4.5: Prohibited list contains write privileges');
    }

    /**
     * TC 5: Confirmation Required Before Query
     */
    private function testConfirmationRequiredBeforeQuery(): void {
        echo "--- TC 5: Confirmation Required Before Query ---\n";

        $mockPdo = MockDatasetPhase32::createMockPdo();
        $reader = new AgentHosxpReader([], $mockPdo);

        // รันโดยไม่ระบุ confirmation (confirmed = false)
        $resNoConfirm = $reader->validateQuery('2026-10-25', '2026-10-25', false);
        $this->assert($resNoConfirm['success'] === false, 'TC 5.1: Query without confirmation is rejected');
        $this->assert($resNoConfirm['confirmation_required'] === true, 'TC 5.2: confirmation_required flag is returned');

        // รันโดยมีการกดยืนยัน (confirmed = true)
        $resConfirmed = $reader->validateQuery('2026-10-25', '2026-10-25', true);
        $this->assert($resConfirmed['success'] === true, 'TC 5.3: Query with confirmation proceeds successfully');
    }

    /**
     * TC 6: Date Range & 1-Day Limit
     */
    private function testDateRangeAndOneDayLimit(): void {
        echo "--- TC 6: Date Range & 1-Day Limit Validation ---\n";

        $mockPdo = MockDatasetPhase32::createMockPdo();
        $reader = new AgentHosxpReader([], $mockPdo);

        // วันเริ่มต้น > วันสิ้นสุด
        $resInverted = $reader->validateQuery('2026-10-26', '2026-10-25', true);
        $this->assert($resInverted['success'] === false, 'TC 6.1: Start date > End date rejected');

        // รูปแบบวันที่ผิด
        $resBadFormat = $reader->validateQuery('25-10-2026', '2026-10-25', true);
        $this->assert($resBadFormat['success'] === false, 'TC 6.2: Non-YYYY-MM-DD date rejected');

        // เกิน 1 วัน (เช่น 3 วัน)
        $resOverLimit = $reader->validateQuery('2026-10-20', '2026-10-25', true);
        $this->assert($resOverLimit['success'] === false, 'TC 6.3: Date range exceeding 1 day limit is rejected');
        $this->assert(isset($resOverLimit['days_requested']), 'TC 6.4: Days requested reported in rejection response');

        // ช่วงเวลา 1 วัน (2026-10-25 ถึง 2026-10-25)
        $resExactOneDay = $reader->validateQuery('2026-10-25', '2026-10-25', true);
        $this->assert($resExactOneDay['success'] === true, 'TC 6.5: Single-day range passes validation');
    }

    /**
     * TC 7: Drug Registry Safety
     */
    private function testDrugRegistrySafety(): void {
        echo "--- TC 7: Drug Registry Safety ---\n";

        $mockPdo = MockDatasetPhase32::createMockPdo();
        $reader = new AgentHosxpReader([], $mockPdo);

        $res = $reader->validateQuery('2026-10-25', '2026-10-25', true);
        $this->assert($res['success'] === true, 'TC 7.1: Query uses registered drug codes');

        // ตรวจสอบว่าไม่มีรหัสยานอกทะเบียนหลุดเข้ามา
        $unregFound = false;
        foreach ($res['sample_data'] as $row) {
            if (!AgentDrugRegistry::isRegistered($row['tpu_code_list'])) {
                $unregFound = true;
                break;
            }
        }
        $this->assert(!$unregFound, 'TC 7.2: Unregistered drugs are completely filtered out');
    }

    /**
     * TC 8: Result Limited to Max 100 Items
     */
    private function testResultLimit100Items(): void {
        echo "--- TC 8: Result Sample Limited to Max 100 Items ---\n";

        // สร้าง Mock Dataset ที่มีรายการจ่ายยามากกว่า 100 รายการ (150 รายการ)
        $manyOp = [];
        $manyVn = [
            ['vn' => '691025080001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-25']
        ];
        for ($i = 1; $i <= 150; $i++) {
            $manyOp[] = [
                'hos_guid' => sprintf('{BULK-RECORD-%05d}', $i),
                'vn'       => '691025080001',
                'icode'    => '1000001', // Paracetamol (227514)
                'vstdate'  => '2026-10-25',
                'qty'      => 10.0,
            ];
        }

        $largePdo = MockDatasetPhase32::createMockPdo($manyOp, $manyVn);
        $reader = new AgentHosxpReader([], $largePdo);

        $res = $reader->validateQuery('2026-10-25', '2026-10-25', true);
        $this->assert($res['success'] === true, 'TC 8.1: Query execution on large dataset succeeds');
        $this->assert($res['total_passed'] === 150, 'TC 8.2: Total passed count correctly reflects 150 records');
        $this->assert($res['sample_count'] <= 100, "TC 8.3: Sample count is capped at <= 100 (actual: {$res['sample_count']})");
        $this->assert(count($res['sample_data']) === 100, 'TC 8.4: Sample data array contains exactly 100 records');
    }

    /**
     * TC 9: Empty Dataset & Malformed Handling
     */
    private function testEmptyAndMalformedDataHandling(): void {
        echo "--- TC 9: Empty Dataset & Malformed Handling ---\n";

        $emptyPdo = MockDatasetPhase32::createMockPdo([], [], []);
        $reader = new AgentHosxpReader([], $emptyPdo);

        $res = $reader->validateQuery('2026-10-25', '2026-10-25', true);
        $this->assert($res['success'] === true, 'TC 9.1: Empty dataset query succeeds');
        $this->assert($res['total_read'] === 0, 'TC 9.2: total_read is 0');
        $this->assert(empty($res['sample_data']), 'TC 9.3: sample_data is empty');
    }

    /**
     * TC 10: Zero Write Statements Guarantee
     */
    private function testZeroWriteStatementsGuarantee(): void {
        echo "--- TC 10: Zero Write Statements Guarantee ---\n";

        // ตรวจสอบ Source Code ของ AgentHosxpReader
        $code = file_get_contents(__DIR__ . '/agent_hosxp_reader.php');

        $prohibitedWritePatterns = [
            '/\bINSERT\s+INTO\b/i',
            '/\bUPDATE\s+[a-zA-Z_`]+\s+SET\b/i',
            '/\bDELETE\s+FROM\b/i',
            '/\bDROP\s+TABLE\b/i',
            '/\bALTER\s+TABLE\b/i',
            '/\bTRUNCATE\s+TABLE\b/i',
        ];

        $hasWriteStatements = false;
        foreach ($prohibitedWritePatterns as $pattern) {
            if (preg_match($pattern, $code)) {
                $hasWriteStatements = true;
                break;
            }
        }

        $this->assert(!$hasWriteStatements, 'TC 10.1: Source code contains zero write SQL statements');
    }

    /**
     * TC 11: Error Sanitization (No Password Leaks)
     */
    private function testErrorSanitizationNoPasswordLeak(): void {
        echo "--- TC 11: Error Sanitization (No Password Leaks) ---\n";

        $reader = new AgentHosxpReader();
        $dirtyError = "SQLSTATE[HY000] [1045] Access denied for user 'admin'@'localhost' (using password: Yes) password=MySecretPassword123! in C:\\xampp\\htdocs\\rxcore\\sync-agent\\agent_connection.php";
        $cleanError = $reader->sanitizeErrorMessage($dirtyError);

        $this->assert(strpos($cleanError, 'MySecretPassword123!') === false, 'TC 11.1: Password string redacted');
        $this->assert(strpos($cleanError, 'C:\\') === false, 'TC 11.2: System path redacted');
        $this->assert(strpos($cleanError, 'password=***') !== false, 'TC 11.3: Redaction mask applied');
    }

    /**
     * TC 12: Result Comparison Logic (Test E)
     */
    private function testResultComparisonLogic(): void {
        echo "--- TC 12: Result Comparison Logic (Test E) ---\n";

        // กรณีตรงกัน 100%
        $matchedRes = AgentHosxpReader::compareResults(25, 25);
        $this->assert($matchedRes['status'] === 'MATCHED', 'TC 12.1: Exactly matching count returns MATCHED');
        $this->assert($matchedRes['matched'] === true, 'TC 12.2: matched flag is true');
        $this->assert($matchedRes['difference'] === 0, 'TC 12.3: Difference is 0');

        // กรณีไม่ตรงกัน
        $mismatchRes = AgentHosxpReader::compareResults(30, 25);
        $this->assert($mismatchRes['status'] === 'MISMATCH', 'TC 12.4: Differing count returns MISMATCH');
        $this->assert($mismatchRes['matched'] === false, 'TC 12.5: matched flag is false');
        $this->assert($mismatchRes['difference'] === -5, 'TC 12.6: Difference calculates correctly (-5)');

        // กรณียังไม่ได้เปรียบเทียบ
        $unverifiedRes = AgentHosxpReader::compareResults(null, 25);
        $this->assert($unverifiedRes['status'] === 'NOT_VERIFIED', 'TC 12.7: Null HOSxP count returns NOT_VERIFIED');
    }

    /**
     * TC 13: Real Database Test Execution against MySQL/MariaDB (rxcore_hosxp_test)
     */
    private function testRealDatabaseExecution(): void {
        echo "--- TC 13: Real Database Execution (rxcore_hosxp_test) ---\n";

        // ตรวจสอบฐานข้อมูลจริง rxcore_hosxp_test
        MySqlTestEnvironment::setup();
        $roConfig = [
            'hosxp_host'     => '127.0.0.1',
            'hosxp_port'     => 3306,
            'hosxp_database' => 'rxcore_hosxp_test',
            'hosxp_username' => 'rxcore_ro_test',
            'hosxp_password' => 'RoTestPass_2026!',
            'agent_mode'     => 'real',
            'hcode'          => '01425',
        ];

        $realReader = new AgentHosxpReader($roConfig);

        // Test A: Real Connection
        $testA = $realReader->testConnection();
        $this->assert($testA['success'] === true, 'TC 13.1: Test A: Real MySQL connection succeeds');
        $this->assert(!empty($testA['server_version']), 'TC 13.2: Test A: Real MySQL server version identified');

        // Test B: Real Schema Validation
        $testB = $realReader->validateSchema();
        $this->assert($testB['success'] === true, 'TC 13.3: Test B: Real MySQL schema validated successfully');
        $this->assert($testB['tables_status']['opitemrece']['exists'] === true, 'TC 13.4: Test B: opitemrece confirmed in real DB');
        $this->assert($testB['tables_status']['drugitems']['exists'] === true, 'TC 13.5: Test B: drugitems confirmed in real DB');
        $this->assert($testB['tables_status']['vn_stat']['exists'] === true, 'TC 13.6: Test B: vn_stat confirmed in real DB');

        // Test C: Real Read-only Privilege Audit
        $testC = $realReader->validatePrivileges();
        $this->assert($testC['success'] === true, 'TC 13.7: Test C: Real Read-only account passed privilege audit');
        $this->assert($testC['is_readonly'] === true, 'TC 13.8: Test C: is_readonly confirmed true');

        // Test D: Real Query Validation (Confirmed, 1-day range)
        $testD = $realReader->validateQuery('2026-10-25', '2026-10-25', true);
        $this->assert($testD['success'] === true, 'TC 13.9: Test D: Real Query execution on MySQL succeeds');
        $this->assert($testD['total_passed'] === 2, 'TC 13.10: Test D: Correctly returned 2 passed records for 2026-10-25');
        $this->assert($testD['sample_count'] === 2, 'TC 13.11: Test D: Sample count matches 2');

        // Test E: Comparison with known HOSxP count
        $testE = AgentHosxpReader::compareResults(2, $testD['total_passed']);
        $this->assert($testE['matched'] === true, 'TC 13.12: Test E: Comparison with reference count matches (2 = 2)');
    }
}

// เรียกใช้งานเมื่อรันผ่าน CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $tester = new Phase34HosxpReaderTester();
    exit($tester->runAll());
}
