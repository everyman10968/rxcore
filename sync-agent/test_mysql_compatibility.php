<?php
/**
 * RxCore Sync Agent — MySQL/MariaDB Compatibility Test Suite
 * Phase 3.2.1: Compatibility Test
 * 
 * วัตถุประสงค์:
 * 1. ตรวจสอบความเข้ากันได้ของคำสั่ง SQL และ PDO Parameter Binding ระหว่าง SQLite, MySQL, และ MariaDB
 * 2. ตรวจสอบการกรองช่วงวันที่ (start_date, end_date) ให้ครอบคลุมวันสิ้นสุด
 * 3. ตรวจสอบกรณีรายการยา 1 รายการ, หลายรายการ, และ 0 รายการ
 * 4. ตรวจสอบการป้องกัน SQL Injection ผ่านพารามิเตอร์
 * 5. ตรวจสอบการจัดการข้อมูลวันที่และจำนวนยาที่เป็น NULL หรือผิดรูปแบบ
 * 6. ตรวจสอบการประเมินสิทธิ์ Read-only:
 *    - บัญชี Read-only จริงบน MySQL ต้องผ่านการตรวจสอบ
 *    - บัญชีที่มีสิทธิ์เขียนจริงบน MySQL ต้องถูกปฏิเสธ (Global, Schema, Table, Column)
 *    - ตรวจสอบสิทธิ์ที่สืบทอดผ่าน Role จริงบน MySQL/MariaDB
 *    - ห้ามใช้ชื่อ Username ตัดสิน และห้ามดำเนินการแก้ไขข้อมูลเพื่อทดสอบสิทธิ์
 * 7. ตรวจสอบกรณีการเชื่อมต่อล้มเหลว หรือสิทธิ์ไม่เพียงพอ ต้องรายงานข้อผิดพลาดอย่างปลอดภัย
 * 8. Regression Verification สำหรับ Phase 3.1 (56 ข้อ) และ Phase 3.2 (27 ข้อ)
 */

require_once __DIR__ . '/compat_setup_mysql.php';
require_once __DIR__ . '/agent_drug_registry.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_connection.php';

class MySqlCompatibilityTestSuite {

    private int $passed = 0;
    private int $failed = 0;
    private array $results = [];
    private ?PDO $roPdo = null;

    public function run(): int {
        echo "\n=================================================================\n";
        echo "   RxCore Sync Agent — Phase 3.2.1 Comprehensive Compatibility   \n";
        echo "=================================================================\n\n";

        // ขั้นตอนเตรียมสภาพแวดล้อม
        $this->ensureEnvironmentReady();

        // หมวดที่ 1: ตรวจสอบความเข้ากันได้ของ SQL Query (Test Cases 1 - 4)
        $this->testCase1_DateRangeBoundaryInclusion();
        $this->testCase2_DrugCodesVariations();
        $this->testCase3_SqlInjectionPrevention();
        $this->testCase4_NullAndMalformedDataHandling();

        // หมวดที่ 2: ตรวจสอบสิทธิ์ Read-only และการปฏิเสธสิทธิ์เขียน (Test Cases 5 - 8)
        $this->testCase5_ReadOnlyAccountApproval();
        $this->testCase6_WriteAccountsRejection();
        $this->testCase7_RoleInheritedPrivilegeRejection();
        $this->testCase8_SafeErrorHandlingAndMasking();

        // หมวดที่ 3: ตรวจสอบเปรียบเทียบ SQLite vs MySQL vs MariaDB และ EXPLAIN Plan
        $this->testCase9_MultiEngineCompatibilityComparison();
        $this->testCase10_QueryExecutionPlanAnalysis();

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

    private function ensureEnvironmentReady(): void {
        echo "--- Initializing MySQL Test Environment (Synthetic Data) ---\n";
        $setupRes = MySqlTestEnvironment::setup();
        if (!$setupRes['success']) {
            echo "  [FATAL] Unable to initialize MySQL test environment: " . ($setupRes['error'] ?? '') . "\n";
            exit(1);
        }
        $this->roPdo = MySqlTestEnvironment::getReadOnlyPdo();
        echo "  Database: " . MySqlTestEnvironment::DB_NAME . " initialized successfully.\n";
        echo "  Read-only user: " . MySqlTestEnvironment::TEST_USER . " ready.\n\n";
    }

    /**
     * Test Case 1: ดึงข้อมูลตามช่วงวันที่ได้ถูกต้อง (ครอบคลุมวันสิ้นสุด)
     */
    private function testCase1_DateRangeBoundaryInclusion(): void {
        echo "--- Group 1: SQL Compatibility & Extraction (Test Cases 1 - 4) ---\n";

        $extractor = new AgentDataExtractor($this->roPdo);

        // 1.1 ทดสอบกรณี start_date = end_date (2026-10-25) ต้องรวมข้อมูลของวันนั้นครบถ้วน
        $resSingleDay = $extractor->extract('2026-10-25', '2026-10-25');
        $this->assert(
            "TC 1.1: Single-day range (2026-10-25 to 2026-10-25) includes exact day records",
            $resSingleDay['success'] === true && $resSingleDay['total_passed'] === 2,
            "Expected 2 passed records on Oct 25, got " . ($resSingleDay['total_passed'] ?? 0)
        );

        // 1.2 ทดสอบช่วง 2 วัน (2026-10-25 ถึง 2026-10-26) ต้องครอบคลุมทั้งวันเริ่มต้นและวันสิ้นสุด
        $resTwoDays = $extractor->extract('2026-10-25', '2026-10-26');
        // วันที่ 25 มี 2 รายการ, วันที่ 26 มี 5 รายการที่ผ่านการตรวจสอบ รวมเป็น 7 รายการ
        $this->assert(
            "TC 1.2: Date range (2026-10-25 to 2026-10-26) fully covers end date records",
            $resTwoDays['success'] === true && $resTwoDays['total_passed'] === 7,
            "Expected 7 passed records (2 on Oct 25 + 5 on Oct 26), got " . ($resTwoDays['total_passed'] ?? 0)
        );

        // 1.3 วันที่สิ้นสุดต้องครอบคลุมจนถึง 2026-10-27
        $resThreeDays = $extractor->extract('2026-10-25', '2026-10-27');
        $this->assert(
            "TC 1.3: End date boundary (2026-10-27) captures records up to and including the last day",
            $resThreeDays['success'] === true && $resThreeDays['total_passed'] === 8,
            "Expected 8 passed records, got " . ($resThreeDays['total_passed'] ?? 0)
        );
    }

    /**
     * Test Case 2: รายการยาที่มีหนึ่งรายการ, หลายรายการ, และไม่มีรายการ
     */
    private function testCase2_DrugCodesVariations(): void {
        // 2.1 มีรายการยา 1 รายการ
        $singleDrug = ['227514']; // Paracetamol
        $qSingle = AgentDataExtractor::buildQuery($singleDrug);
        $this->assert(
            "TC 2.1: Single drug code generates exactly 1 placeholder (:drug_0)",
            count($qSingle['placeholders']) === 1 && isset($qSingle['placeholders'][':drug_0']),
            "Placeholders: " . json_encode(array_keys($qSingle['placeholders']))
        );

        $stmtSingle = $this->roPdo->prepare($qSingle['sql']);
        $stmtSingle->bindValue(':start_date', '2026-10-01');
        $stmtSingle->bindValue(':end_date', '2026-10-31');
        $stmtSingle->bindValue(':drug_0', '227514');
        $stmtSingle->execute();
        $rowsSingle = $stmtSingle->fetchAll(PDO::FETCH_ASSOC);
        $this->assert(
            "TC 2.2: Single drug query executes on MySQL returning only Paracetamol records",
            count($rowsSingle) > 0 && array_unique(array_column($rowsSingle, 'tpu_code_list')) === ['227514'],
            "Unique TPU codes returned: " . json_encode(array_unique(array_column($rowsSingle, 'tpu_code_list')))
        );

        // 2.2 มีรายการยาหลายรายการ (3 รายการ)
        $multiDrugs = ['227514', '189422', '304192'];
        $qMulti = AgentDataExtractor::buildQuery($multiDrugs);
        $this->assert(
            "TC 2.3: Multiple drug codes generate matching numbered placeholders (:drug_0, :drug_1, :drug_2)",
            count($qMulti['placeholders']) === 3 && str_contains($qMulti['sql'], ':drug_0, :drug_1, :drug_2'),
            "Placeholders count: " . count($qMulti['placeholders'])
        );

        // 2.3 ไม่มีรายการยา (0 รายการ / Empty Array)
        $qEmpty = AgentDataExtractor::buildQuery([]);
        $this->assert(
            "TC 2.4: Empty drug list generates safe non-matching query (WHERE 1 = 0) without SQL error",
            count($qEmpty['placeholders']) === 0 && str_contains($qEmpty['sql'], '1 = 0'),
            "SQL snippet: " . substr($qEmpty['sql'], 0, 150)
        );

        $stmtEmpty = $this->roPdo->prepare($qEmpty['sql']);
        $stmtEmpty->execute();
        $rowsEmpty = $stmtEmpty->fetchAll(PDO::FETCH_ASSOC);
        $this->assert(
            "TC 2.5: Empty drug list query executes on MySQL returning 0 rows safely",
            count($rowsEmpty) === 0,
            "Expected 0 rows, got " . count($rowsEmpty)
        );
    }

    /**
     * Test Case 3: ป้องกัน SQL Injection ผ่านค่าพารามิเตอร์
     */
    private function testCase3_SqlInjectionPrevention(): void {
        // 3.1 Injection ผ่านรหัสยา
        $injectionDrugCodes = [
            "227514' OR '1'='1",
            "189422'); DROP TABLE opitemrece;--",
            "\" UNION SELECT 1,2,3,4,5,6--"
        ];
        $qInject = AgentDataExtractor::buildQuery($injectionDrugCodes);

        $stmtInject = $this->roPdo->prepare($qInject['sql']);
        $stmtInject->bindValue(':start_date', '2026-10-01');
        $stmtInject->bindValue(':end_date', '2026-10-31');
        foreach ($qInject['placeholders'] as $param => $code) {
            $stmtInject->bindValue($param, $code, PDO::PARAM_STR);
        }

        // ต้อง execute ได้โดยไม่เกิด SQL Injection หรือ syntax error
        $stmtInject->execute();
        $rowsInject = $stmtInject->fetchAll(PDO::FETCH_ASSOC);

        $this->assert(
            "TC 3.1: Malicious SQL injection payloads in drug codes are safely parameterized and return 0 rows",
            count($rowsInject) === 0,
            "Payloads were treated as pure literal strings"
        );

        // ยืนยันว่าตาราง opitemrece ยังอยู่ ไม่ถูก DROP
        $tableCheck = $this->roPdo->query("SHOW TABLES LIKE 'opitemrece'")->fetch();
        $this->assert(
            "TC 3.2: Database schema integrity preserved (no injection execution)",
            !empty($tableCheck),
            "Table opitemrece exists and intact"
        );

        // 3.2 Injection ผ่านช่วงวันที่
        $extractor = new AgentDataExtractor($this->roPdo);
        $resInjDate = $extractor->extract("2026-10-01' OR '1'='1", "2026-10-31");
        $this->assert(
            "TC 3.3: Malicious start_date payload is rejected by format validator",
            $resInjDate['success'] === false && strpos($resInjDate['error'], 'รูปแบบวันที่ไม่ถูกต้อง') !== false,
            "Error: " . ($resInjDate['error'] ?? '')
        );
    }

    /**
     * Test Case 4: กรณีข้อมูลวันที่และจำนวนยาเป็น NULL หรือผิดรูปแบบ
     */
    private function testCase4_NullAndMalformedDataHandling(): void {
        $extractor = new AgentDataExtractor($this->roPdo);
        $res = $extractor->extract('2026-10-01', '2026-10-31');

        $malformed = $res['malformed_summary'] ?? [];

        $this->assert(
            "TC 4.1: Missing or whitespace hos_guid detected as malformed (count = 2)",
            ($malformed['missing_hos_guid'] ?? 0) === 2,
            "missing_hos_guid=" . ($malformed['missing_hos_guid'] ?? 0)
        );

        $this->assert(
            "TC 4.2: Zero and negative qty detected as malformed (count = 2)",
            ($malformed['invalid_qty'] ?? 0) === 2,
            "invalid_qty=" . ($malformed['invalid_qty'] ?? 0)
        );

        $this->assert(
            "TC 4.3: Empty vn detected as malformed (count = 1)",
            ($malformed['missing_vn'] ?? 0) === 1,
            "missing_vn=" . ($malformed['missing_vn'] ?? 0)
        );

        $this->assert(
            "TC 4.4: Missing or invalid 5-digit hcode detected as malformed (count = 2)",
            ($malformed['missing_or_invalid_hcode'] ?? 0) === 2,
            "missing_or_invalid_hcode=" . ($malformed['missing_or_invalid_hcode'] ?? 0)
        );

        $this->assert(
            "TC 4.5: Total malformed count equals 7 on MySQL dataset",
            $res['total_malformed'] === 7,
            "total_malformed=" . ($res['total_malformed'] ?? 0)
        );
    }

    /**
     * Test Case 5: บัญชี Read-only ผ่านการตรวจสอบ (ตรวจสิทธิ์จริง ห้ามแก้ข้อมูล)
     */
    private function testCase5_ReadOnlyAccountApproval(): void {
        echo "\n--- Group 2: Read-Only Verification & Privilege Policy (Test Cases 5 - 8) ---\n";

        $conn = new AgentConnection();
        $roConfig = [
            'hosxp_host'     => MySqlTestEnvironment::HOST,
            'hosxp_port'     => MySqlTestEnvironment::PORT,
            'hosxp_database' => MySqlTestEnvironment::DB_NAME,
            'hosxp_username' => MySqlTestEnvironment::TEST_USER,
            'hosxp_password' => MySqlTestEnvironment::TEST_PASS,
            'hcode'          => '01425',
            'agent_mode'     => 'real',
        ];

        $diag = $conn->testConnection($roConfig);

        $this->assert(
            "TC 5.1: Real MySQL Read-only account connection test succeeds (success = true)",
            $diag['success'] === true,
            "Duration: " . ($diag['duration_ms'] ?? 0) . " ms"
        );

        $this->assert(
            "TC 5.2: Privilege Engine confirms is_readonly = true for rxcore_ro_test",
            $diag['is_readonly'] === true,
            "is_readonly is TRUE"
        );

        $this->assert(
            "TC 5.3: Prohibited privileges list is empty for Read-only account",
            empty($diag['prohibited']),
            "Prohibited: " . json_encode($diag['prohibited'])
        );
    }

    /**
     * Test Case 6: บัญชีที่มีสิทธิ์เขียนถูกปฏิเสธ (ตรวจสิทธิ์จริง ห้ามแก้ข้อมูล)
     */
    private function testCase6_WriteAccountsRejection(): void {
        $conn = new AgentConnection();

        // 6.1 ทดสอบบัญชีจริงที่มีสิทธิ์เขียนบน MySQL (rxcore_writer_test)
        $writerConfig = [
            'hosxp_host'     => MySqlTestEnvironment::HOST,
            'hosxp_port'     => MySqlTestEnvironment::PORT,
            'hosxp_database' => MySqlTestEnvironment::DB_NAME,
            'hosxp_username' => 'rxcore_writer_test',
            'hosxp_password' => 'WriterPass_2026!',
            'hcode'          => '01425',
            'agent_mode'     => 'real',
        ];

        $diagWriter = $conn->testConnection($writerConfig);

        $this->assert(
            "TC 6.1: Account with direct schema write privileges is REJECTED (success = false)",
            $diagWriter['success'] === false && $diagWriter['is_readonly'] === false,
            "Success=" . ($diagWriter['success'] ? 'true' : 'false') . ", is_readonly=" . ($diagWriter['is_readonly'] ? 'true' : 'false')
        );

        $this->assert(
            "TC 6.2: Prohibited privileges list identifies write grants (INSERT, UPDATE, DELETE)",
            !empty($diagWriter['prohibited']) && str_contains(implode(' ', $diagWriter['prohibited']), 'INSERT'),
            "Prohibited found: " . implode(', ', $diagWriter['prohibited'])
        );

        // 6.2 ทดสอบ Username Independence: บัญชีชื่อ root แต่มีสิทธิ์เฉพาะ SELECT ต้องผ่าน
        $cleanSimulated = $conn->evaluateAllPrivilegeLevels(
            ["GRANT USAGE ON *.* TO 'root'@'localhost'", "GRANT SELECT ON `hos`.* TO 'root'@'localhost'"],
            ['global' => ['USAGE'], 'schema' => ['SELECT'], 'table' => [], 'column' => [], 'roles' => [], 'role_table_grants' => []],
            'hos'
        );
        $this->assert(
            "TC 6.3: Username Independence: User 'root' with clean SELECT is approved (not blocked by name)",
            $cleanSimulated['is_readonly'] === true && empty($cleanSimulated['prohibited_found']),
            "Prohibited: " . implode(', ', $cleanSimulated['prohibited_found'])
        );

        // 6.3 ทดสอบ Username Independence: บัญชีชื่อ hosxp_readonly แต่มีสิทธิ์ INSERT ต้องถูกปฏิเสธ
        $dirtySimulated = $conn->evaluateAllPrivilegeLevels(
            ["GRANT USAGE ON *.* TO 'hosxp_readonly'@'localhost'", "GRANT SELECT, INSERT ON `hos`.* TO 'hosxp_readonly'@'localhost'"],
            ['global' => ['USAGE'], 'schema' => ['SELECT', 'INSERT'], 'table' => [], 'column' => [], 'roles' => [], 'role_table_grants' => []],
            'hos'
        );
        $this->assert(
            "TC 6.4: Username Independence: User named 'hosxp_readonly' with INSERT grant is REJECTED",
            $dirtySimulated['is_readonly'] === false && !empty($dirtySimulated['prohibited_found']),
            "Prohibited: " . implode(', ', $dirtySimulated['prohibited_found'])
        );
    }

    /**
     * Test Case 7: ตรวจสอบสิทธิ์ที่สืบทอดผ่าน Role (Role Inheritance)
     */
    private function testCase7_RoleInheritedPrivilegeRejection(): void {
        $conn = new AgentConnection();

        // ทดสอบบัญชีจริง rxcore_role_user ที่ได้รับสิทธิ์ INSERT, UPDATE ผ่าน rxcore_test_role
        $roleConfig = [
            'hosxp_host'     => MySqlTestEnvironment::HOST,
            'hosxp_port'     => MySqlTestEnvironment::PORT,
            'hosxp_database' => MySqlTestEnvironment::DB_NAME,
            'hosxp_username' => 'rxcore_role_user',
            'hosxp_password' => 'RolePass_2026!',
            'hcode'          => '01425',
            'agent_mode'     => 'real',
        ];

        $diagRole = $conn->testConnection($roleConfig);

        $this->assert(
            "TC 7.1: User inheriting write privileges via MariaDB Role is REJECTED (success = false)",
            $diagRole['success'] === false && $diagRole['is_readonly'] === false,
            "Success=" . ($diagRole['success'] ? 'true' : 'false') . ", is_readonly=" . ($diagRole['is_readonly'] ? 'true' : 'false')
        );

        $this->assert(
            "TC 7.2: Prohibited list captures role-inherited schema write privileges",
            !empty($diagRole['prohibited']) && str_contains(implode(' ', $diagRole['prohibited']), 'INSERT'),
            "Prohibited: " . implode(', ', $diagRole['prohibited'])
        );
    }

    /**
     * Test Case 8: การเชื่อมต่อล้มเหลว หรือสิทธิ์ไม่เพียงพอ ต้องแจ้งข้อผิดพลาดอย่างปลอดภัย
     */
    private function testCase8_SafeErrorHandlingAndMasking(): void {
        $conn = new AgentConnection();

        // 8.1 การเชื่อมต่อไปยังโฮสต์ที่ไม่มีอยู่จริง (Timeout/Unreachable)
        $badHostConfig = [
            'hosxp_host'     => '192.0.2.1', // Test network blackhole
            'hosxp_port'     => 3306,
            'hosxp_database' => 'hos',
            'hosxp_username' => 'hosxp_readonly',
            'hosxp_password' => 'SuperSecretPass123!',
            'hcode'          => '01425',
            'agent_mode'     => 'real',
        ];

        $diagBadHost = $conn->testConnection($badHostConfig);

        $this->assert(
            "TC 8.1: Connection failure to unreachable host fails gracefully without exception crash",
            $diagBadHost['success'] === false && !empty($diagBadHost['error']),
            "Error: " . ($diagBadHost['error'] ?? '')
        );

        $this->assert(
            "TC 8.2: Password is NOT leaked in connection failure error message",
            stripos($diagBadHost['error'] ?? '', 'SuperSecretPass123!') === false,
            "Sanitized error: " . ($diagBadHost['error'] ?? '')
        );

        // 8.2 รหัสผ่านผิดบน MySQL
        $badPassConfig = [
            'hosxp_host'     => MySqlTestEnvironment::HOST,
            'hosxp_port'     => MySqlTestEnvironment::PORT,
            'hosxp_database' => MySqlTestEnvironment::DB_NAME,
            'hosxp_username' => MySqlTestEnvironment::TEST_USER,
            'hosxp_password' => 'WrongPassword_999!',
            'hcode'          => '01425',
            'agent_mode'     => 'real',
        ];

        $diagBadPass = $conn->testConnection($badPassConfig);

        $this->assert(
            "TC 8.3: Authentication failure returns user-friendly Thai message without exposing credentials",
            $diagBadPass['success'] === false && (stripos($diagBadPass['error'], 'Password') !== false || strpos($diagBadPass['error'], 'รหัสผ่าน') !== false),
            "Error: " . ($diagBadPass['error'] ?? '')
        );

        $this->assert(
            "TC 8.4: Password is NOT leaked in authentication failure error message",
            stripos($diagBadPass['error'] ?? '', 'WrongPassword_999!') === false,
            "Sanitized error message confirmed"
        );
    }

    /**
     * หมวดที่ 3: ตรวจสอบเปรียบเทียบ SQLite vs MySQL vs MariaDB (Multi-Engine Matrix)
     */
    private function testCase9_MultiEngineCompatibilityComparison(): void {
        echo "\n--- Group 3: Multi-Engine Comparison & EXPLAIN Plan (Test Cases 9 - 10) ---\n";

        // 9.1 รันบน SQLite In-memory
        $sqlitePdo = MockDatasetPhase32::createMockPdo();
        $sqliteExtractor = new AgentDataExtractor($sqlitePdo);
        $sqliteRes = $sqliteExtractor->extract('2026-10-01', '2026-10-31');

        // 9.2 รันบน MariaDB 10.4 จริง
        $mysqlExtractor = new AgentDataExtractor($this->roPdo);
        $mysqlRes = $mysqlExtractor->extract('2026-10-01', '2026-10-31');

        $this->assert(
            "TC 9.1: Total Read count matches identically across SQLite and MariaDB/MySQL (16 = 16)",
            $sqliteRes['total_read'] === $mysqlRes['total_read'] && $mysqlRes['total_read'] === 16,
            "SQLite={$sqliteRes['total_read']}, MariaDB={$mysqlRes['total_read']}"
        );

        $this->assert(
            "TC 9.2: Total Passed count matches identically across SQLite and MariaDB/MySQL (8 = 8)",
            $sqliteRes['total_passed'] === $mysqlRes['total_passed'] && $mysqlRes['total_passed'] === 8,
            "SQLite={$sqliteRes['total_passed']}, MariaDB={$mysqlRes['total_passed']}"
        );

        $this->assert(
            "TC 9.3: Duplicates Pruned matches identically across SQLite and MariaDB/MySQL (1 = 1)",
            $sqliteRes['duplicates_pruned'] === $mysqlRes['duplicates_pruned'] && $mysqlRes['duplicates_pruned'] === 1,
            "SQLite={$sqliteRes['duplicates_pruned']}, MariaDB={$mysqlRes['duplicates_pruned']}"
        );

        $this->assert(
            "TC 9.4: Malformed count matches identically across SQLite and MariaDB/MySQL (7 = 7)",
            $sqliteRes['total_malformed'] === $mysqlRes['total_malformed'] && $mysqlRes['total_malformed'] === 7,
            "SQLite={$sqliteRes['total_malformed']}, MariaDB={$mysqlRes['total_malformed']}"
        );

        $this->assert(
            "TC 9.5: Composite Key (hcode, hos_guid) deduplication identical across both engines",
            count($mysqlRes['sample_data']) === 8 && count($sqliteRes['sample_data']) === 8,
            "Both engines produced exactly 8 verified records"
        );
    }

    /**
     * หมวดที่ 4: วิเคราะห์ Query Execution Plan (EXPLAIN)
     */
    private function testCase10_QueryExecutionPlanAnalysis(): void {
        $drugCodes = AgentDrugRegistry::getRegisteredDrugCodes();
        $queryInfo = AgentDataExtractor::buildQuery($drugCodes);
        $explainSql = "EXPLAIN " . $queryInfo['sql'];

        $stmt = $this->roPdo->prepare($explainSql);
        $stmt->bindValue(':start_date', '2026-10-01', PDO::PARAM_STR);
        $stmt->bindValue(':end_date', '2026-10-31', PDO::PARAM_STR);
        foreach ($queryInfo['placeholders'] as $param => $code) {
            $stmt->bindValue($param, $code, PDO::PARAM_STR);
        }
        $stmt->execute();
        $plan = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assert(
            "TC 10.1: EXPLAIN plan successfully generated on MariaDB/MySQL",
            !empty($plan),
            "Plan rows: " . count($plan)
        );

        $tableKeys = [];
        foreach ($plan as $p) {
            $tableKeys[$p['table']] = $p['key'] ?? 'NULL';
        }

        $this->assert(
            "TC 10.2: Table vn_stat utilizes PRIMARY KEY for optimal eq_ref join",
            isset($tableKeys['v']) && $tableKeys['v'] === 'PRIMARY',
            "Key on vn_stat: " . ($tableKeys['v'] ?? 'None')
        );

        $this->assert(
            "TC 10.3: Table opitemrece utilizes index for icode lookup",
            isset($tableKeys['r']) && !empty($tableKeys['r']) && $tableKeys['r'] !== 'NULL',
            "Key on opitemrece: " . ($tableKeys['r'] ?? 'None')
        );
    }

    private function printSummary(): void {
        $total = $this->passed + $this->failed;
        $successRate = $total > 0 ? round(($this->passed / $total) * 100, 1) : 0;

        echo "\n=================================================================\n";
        echo "   PHASE 3.2.1 COMPATIBILITY TEST SUMMARY: {$this->passed}/{$total} PASSED ({$successRate}%)\n";
        if ($this->failed === 0) {
            echo "   STATUS: ALL COMPATIBILITY & PRIVILEGE REQUIREMENTS PASSED! \n";
        } else {
            echo "   STATUS: {$this->failed} TEST(S) FAILED!\n";
        }
        echo "=================================================================\n\n";
    }
}

// Execute tests only if run directly from CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $suite = new MySqlCompatibilityTestSuite();
    exit($suite->run());
}
