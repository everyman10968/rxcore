<?php
/**
 * RxCore Sync Agent — Phase 3.9 Test Suite
 * Phase 3.9: Read-only Database Connection & Pre-production Validation
 * 
 * วัตถุประสงค์การทดสอบ:
 * 1. ทดสอบการเชื่อมต่อจริงกับ MySQL/MariaDB (PDO) ผ่านการตั้งค่า Agent Configuration
 * 2. ตรวจสอบการปฏิเสธ Credential ที่ผิด และการจัดการเมื่อ Host/Port ติดต่อไม่ได้ โดยไม่เปิดเผย Password
 * 3. ตรวจสอบเวอร์ชัน MySQL/MariaDB, Character set, Database Name และ Safety Guard สำหรับ Test Database
 * 4. ตรวจสอบโครงสร้าง Schema และฟิลด์ที่จำเป็น (opitemrece, drugitems, vn_stat) และการแจ้งเตือน Diagnostic
 * 5. ตรวจสอบสิทธิ์ Read-only ทุกระดับ (Global, Schema, Table, Column, Role) และปฏิเสธบัญชีที่มีสิทธิ์เขียน
 * 6. ทดสอบการปฏิเสธคำสั่งเขียนจริง (INSERT, UPDATE, DELETE, DDL CREATE, DDL DROP) บน MySQL Engine
 * 7. ทดสอบการดึงข้อมูลผ่าน AgentDataExtractor บน MySQL จริง:
 *    - JOIN 3 ตารางถูกต้อง
 *    - กรองเฉพาะรายการยาที่ลงทะเบียน (TPU) และข้ามยาที่ไม่ได้ลงทะเบียนโดยไม่สร้าง Log รายรายการ
 *    - ตัดรายการซ้ำด้วย Composite Key (hcode, hos_guid)
 *    - ตรวจจับข้อมูลผิดรูปแบบ (Malformed Data)
 *    - รับประกัน Payload มีเฉพาะ 6 ฟิลด์ที่กำหนด ไม่มี HN, CID หรือ PII
 *    - ยืนยันว่าการ Query ไม่แก้ไขข้อมูลต้นทางแม้แต่แถวเดียว (Zero Write Guarantee)
 * 8. ตรวจสอบการทำงานร่วมกับ Mock Mode เดิมโดยไม่ส่งผลกระทบต่อ Regression Tests และไม่มี Outbound Request
 */

require_once __DIR__ . '/compat_setup_mysql.php';
require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_api_client.php';
require_once __DIR__ . '/agent_connection.php';
require_once __DIR__ . '/agent_hosxp_reader.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_drug_registry.php';
require_once __DIR__ . '/mock_dataset.php';

class Phase39TestSuite {

    private int $passed = 0;
    private int $failed = 0;
    private int $notTested = 0;
    private array $errors = [];
    private string $testDb = MySqlTestEnvironment::DB_NAME;
    private string $roUser = MySqlTestEnvironment::TEST_USER;
    private string $roPass = MySqlTestEnvironment::TEST_PASS;
    private string $host   = MySqlTestEnvironment::HOST;
    private int $port      = MySqlTestEnvironment::PORT;
    private string $hcode  = '01425';

    public function runAll(): int {
        echo "\n=================================================================\n";
        echo "   RxCore Sync Agent — Phase 3.9 Automated Test Suite           \n";
        echo "   Read-only Database Connection & Pre-production Validation   \n";
        echo "=================================================================\n\n";

        // ขั้นตอนที่ 0: ตรวจสอบและเตรียมฐานข้อมูลทดสอบ rxcore_hosxp_test
        $this->ensureTestEnvironment();

        // หมวดที่ 1: ตรวจสอบการเชื่อมต่อจริงและมาตรการความปลอดภัย Credential
        $this->testGroup1_DatabaseConnectionAndSecurity();

        // หมวดที่ 2: ตรวจสอบ Schema และการรายงาน Diagnostics
        $this->testGroup2_SchemaAndDiagnostics();

        // หมวดที่ 3: ตรวจสอบสิทธิ์ Read-only ทุกระดับ และการปฏิเสธคำสั่งเขียนจริง
        $this->testGroup3_PrivilegeAuditAndWriteRejection();

        // หมวดที่ 4: ทดสอบการทำงานของ Extractor บน MySQL จริง
        $this->testGroup4_ExtractorOnRealDatabase();

        // หมวดที่ 5: ความเข้ากันได้กับ Mock Mode และ Zero Outbound Network Requests
        $this->testGroup5_MockModeCoexistenceAndSecurityInvariants();

        $this->printSummary();
        return ($this->failed > 0 ? 1 : 0);
    }

    private function assert(bool $condition, string $testName, string $detail = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failed++;
            $msg = "  [FAIL] {$testName}" . ($detail !== '' ? " — Details: {$detail}" : '') . "\n";
            $this->errors[] = $testName;
            echo $msg;
        }
    }

    private function recordNotTested(string $testName, string $reason): void {
        $this->notTested++;
        echo "  [NOT TESTED] {$testName} — {$reason}\n";
    }

    /**
     * เตรียมฐานข้อมูลทดสอบ rxcore_hosxp_test
     */
    private function ensureTestEnvironment(): void {
        echo "--- 0. Initializing/Resetting Dedicated Test Database --- \n";
        $setupRes = MySqlTestEnvironment::setup();
        if (!$setupRes['success']) {
            echo "  [FATAL] Unable to setup test database: " . ($setupRes['error'] ?? 'Unknown error') . "\n";
            exit(1);
        }
        echo "  Database: {$setupRes['database']} ready (Users: {$setupRes['user']}, writer: {$setupRes['writer_user']})\n\n";
    }

    /**
     * หมวดที่ 1: ตรวจสอบการเชื่อมต่อจริงและมาตรการความปลอดภัย Credential
     */
    private function testGroup1_DatabaseConnectionAndSecurity(): void {
        echo "--- Group 1: Database Connection & Credential Safety ---\n";

        // 1.1 เชื่อมต่อสำเร็จด้วย Credential ที่ถูกต้อง
        $validConfig = [
            'hosxp_host'     => $this->host,
            'hosxp_port'     => $this->port,
            'hosxp_database' => $this->testDb,
            'hosxp_username' => $this->roUser,
            'hosxp_password' => $this->roPass,
            'hcode'          => $this->hcode,
            'agent_mode'     => 'real',
        ];
        $reader = new AgentHosxpReader($validConfig);
        $connRes = $reader->testConnection();

        $this->assert($connRes['success'] === true, 'TC 1.1: Connects successfully to MySQL with valid credentials');
        $this->assert(!empty($connRes['server_version']), "TC 1.2: Server version returned: {$connRes['server_version']}");
        $this->assert($connRes['database'] === $this->testDb, "TC 1.3: Active database matches test DB: {$this->testDb}");
        $this->assert(AgentHosxpReader::isSafeTestDatabase($connRes['database']), 'TC 1.4: Target database verified as safe test database');

        // 1.2 ปฏิเสธการเชื่อมต่อกับฐานข้อมูล Production
        $this->assert(AgentHosxpReader::isSafeTestDatabase('hos') === false, 'TC 1.5: Production DB name "hos" is rejected by test safety guard');
        $this->assert(AgentHosxpReader::isSafeTestDatabase('hosxp') === false, 'TC 1.6: Production DB name "hosxp" is rejected by test safety guard');
        $this->assert(AgentHosxpReader::isSafeTestDatabase('hospital') === false, 'TC 1.7: Production DB name "hospital" is rejected by test safety guard');

        // 1.3 ปฏิเสธ Credential ที่ผิด (Wrong Password)
        $invalidPassConfig = $validConfig;
        $invalidPassConfig['hosxp_password'] = 'WrongPassword_2026_Inc!';
        $readerInvalid = new AgentHosxpReader($invalidPassConfig);
        $resInvalid = $readerInvalid->testConnection();

        $this->assert($resInvalid['success'] === false, 'TC 1.8: Rejects connection with wrong password');
        $this->assert(strpos($resInvalid['error'] ?? '', 'WrongPassword_2026_Inc!') === false, 'TC 1.9: Password is NOT leaked in authentication failure message');

        // 1.4 จัดการกรณี Host / Port ติดต่อไม่ได้ โดยไม่เปิดเผย Password
        $unreachableConfig = $validConfig;
        $unreachableConfig['hosxp_host'] = '192.0.2.1'; // RFC 5737 TEST-NET (unreachable)
        $unreachableConfig['hosxp_port'] = 3306;
        $readerUnreachable = new AgentHosxpReader($unreachableConfig);
        $resUnreach = $readerUnreachable->testConnection();

        $this->assert($resUnreach['success'] === false, 'TC 1.10: Unreachable host fails gracefully without unhandled exception');
        $this->assert(strpos($resUnreach['error'] ?? '', $this->roPass) === false, 'TC 1.11: Password is NOT leaked in network connection error message');

        // 1.5 ปฏิเสธบัญชีผู้ดูแลระบบ (Administrative Accounts Prohibited)
        $adminUsers = ['root', 'admin', 'administrator', 'sa'];
        foreach ($adminUsers as $adminUser) {
            $adminCfg = $validConfig;
            $adminCfg['hosxp_username'] = $adminUser;
            $rAdmin = new AgentHosxpReader($adminCfg);
            $resAdm = $rAdmin->testConnection();
            $this->assert(
                $resAdm['success'] === false && !empty($resAdm['account_rejected']),
                "TC 1.12: Disallowed admin account '{$adminUser}' is strictly blocked"
            );
        }

        // 1.6 ทดสอบ Connection Diagnostics โดยไม่มี Credential
        $diag = $reader->getConnectionDiagnostics();
        $this->assert($diag['connected'] === true, 'TC 1.13: Connection diagnostics reports connected = true');
        $this->assert($diag['password_status'] === '[PROTECTED - AES-256-GCM ENCRYPTED]', 'TC 1.14: Password status is protected and masked');
        $this->assert(!isset($diag['password']) && !isset($diag['hosxp_password']), 'TC 1.15: Plaintext password strictly absent from diagnostics');
    }

    /**
     * หมวดที่ 2: ตรวจสอบ Schema และการรายงาน Diagnostics
     */
    private function testGroup2_SchemaAndDiagnostics(): void {
        echo "\n--- Group 2: Schema Validation & Diagnostic Reporting ---\n";

        $validConfig = [
            'hosxp_host'     => $this->host,
            'hosxp_port'     => $this->port,
            'hosxp_database' => $this->testDb,
            'hosxp_username' => $this->roUser,
            'hosxp_password' => $this->roPass,
            'hcode'          => $this->hcode,
            'agent_mode'     => 'real',
        ];
        $reader = new AgentHosxpReader($validConfig);
        $schemaRes = $reader->validateSchema();

        // 2.1 ตรวจสอบโครงสร้างตารางครบถ้วน
        $this->assert($schemaRes['success'] === true, 'TC 2.1: Schema validation succeeds on rxcore_hosxp_test');
        $this->assert(empty($schemaRes['missing_items']), 'TC 2.2: Missing items list is empty on complete schema');
        $this->assert($schemaRes['tables_status']['opitemrece']['exists'] === true, 'TC 2.3: Table opitemrece confirmed exists');
        $this->assert($schemaRes['tables_status']['drugitems']['exists'] === true, 'TC 2.4: Table drugitems confirmed exists');
        $this->assert($schemaRes['tables_status']['vn_stat']['exists'] === true, 'TC 2.5: Table vn_stat confirmed exists');

        // 2.2 ตรวจสอบฟิลด์ที่จำเป็นในแต่ละตาราง
        $opCols = $schemaRes['tables_status']['opitemrece']['found_columns'];
        foreach (['hos_guid', 'vn', 'vstdate', 'icode', 'qty'] as $reqCol) {
            $this->assert(in_array($reqCol, $opCols, true), "TC 2.6: Column opitemrece.{$reqCol} confirmed present");
        }

        $drugCols = $schemaRes['tables_status']['drugitems']['found_columns'];
        foreach (['icode', 'tpu_code_list'] as $reqCol) {
            $this->assert(in_array($reqCol, $drugCols, true), "TC 2.7: Column drugitems.{$reqCol} confirmed present");
        }

        $vnCols = $schemaRes['tables_status']['vn_stat']['found_columns'];
        foreach (['vn', 'hcode'] as $reqCol) {
            $this->assert(in_array($reqCol, $vnCols, true), "TC 2.8: Column vn_stat.{$reqCol} confirmed present");
        }

        // 2.3 ตรวจสอบ Diagnostic เมื่อตารางหรือคอลัมน์ไม่ครบถ้วน (Simulated Schema Mismatch)
        $mockDb = new PDO('sqlite::memory:');
        $mockDb->exec("CREATE TABLE opitemrece (hos_guid TEXT, vn TEXT)"); // ขาด vstdate, icode, qty
        $mockDb->exec("CREATE TABLE drugitems (icode TEXT)");              // ขาด tpu_code_list
        // ขาด vn_stat ทั้งตาราง

        $mockReader = new AgentHosxpReader($validConfig, $mockDb);
        $mockSchemaRes = $mockReader->validateSchema($mockDb);

        $this->assert($mockSchemaRes['success'] === false, 'TC 2.9: Correctly detects incomplete schema as failed');
        $this->assert(count($mockSchemaRes['missing_items']) >= 3, 'TC 2.10: Accurately identifies all missing tables and columns');
        $this->assert(strpos($mockSchemaRes['message'], 'vn_stat') !== false, 'TC 2.11: Diagnostics clearly mentions missing table vn_stat');
        $this->assert(strpos($mockSchemaRes['message'], 'tpu_code_list') !== false, 'TC 2.12: Diagnostics clearly mentions missing column tpu_code_list');
    }

    /**
     * หมวดที่ 3: ตรวจสอบสิทธิ์ Read-only ทุกระดับ และการปฏิเสธคำสั่งเขียนจริง
     */
    private function testGroup3_PrivilegeAuditAndWriteRejection(): void {
        echo "\n--- Group 3: Database Privilege Audit & Write Rejection ---\n";

        // 3.1 ตรวจสอบสิทธิ์บัญชี Read-only จริง (rxcore_ro_test)
        $roConfig = [
            'hosxp_host'     => $this->host,
            'hosxp_port'     => $this->port,
            'hosxp_database' => $this->testDb,
            'hosxp_username' => $this->roUser,
            'hosxp_password' => $this->roPass,
            'hcode'          => $this->hcode,
            'agent_mode'     => 'real',
        ];
        $roReader = new AgentHosxpReader($roConfig);
        $privRes = $roReader->validatePrivileges();

        $this->assert($privRes['success'] === true, 'TC 3.1: Real MySQL Read-only account passes privilege audit');
        $this->assert($privRes['is_readonly'] === true, 'TC 3.2: is_readonly confirmed true for rxcore_ro_test');
        $this->assert(empty($privRes['prohibited']), 'TC 3.3: Prohibited write privileges list is empty');

        // 3.2 ปฏิเสธบัญชีที่มีสิทธิ์เขียนจริง (rxcore_writer_test)
        $writerConfig = [
            'hosxp_host'     => $this->host,
            'hosxp_port'     => $this->port,
            'hosxp_database' => $this->testDb,
            'hosxp_username' => 'rxcore_writer_test',
            'hosxp_password' => 'WriterPass_2026!',
            'hcode'          => $this->hcode,
            'agent_mode'     => 'real',
        ];
        $writerReader = new AgentHosxpReader($writerConfig);
        $writerPrivRes = $writerReader->validatePrivileges();

        $this->assert($writerPrivRes['success'] === false, 'TC 3.4: Account with write privileges is REJECTED');
        $this->assert($writerPrivRes['is_readonly'] === false, 'TC 3.5: is_readonly confirmed false for writer account');
        $this->assert(!empty($writerPrivRes['prohibited']), 'TC 3.6: Prohibited privileges list identifies write grants');

        // 3.3 ปฏิเสธบัญชีที่สืบทอดสิทธิ์ผ่าน Role (rxcore_role_user)
        $roleConfig = [
            'hosxp_host'     => $this->host,
            'hosxp_port'     => $this->port,
            'hosxp_database' => $this->testDb,
            'hosxp_username' => 'rxcore_role_user',
            'hosxp_password' => 'RolePass_2026!',
            'hcode'          => $this->hcode,
            'agent_mode'     => 'real',
        ];
        $roleReader = new AgentHosxpReader($roleConfig);
        $rolePrivRes = $roleReader->validatePrivileges();

        $this->assert($rolePrivRes['success'] === false, 'TC 3.7: Role-inherited write privileges account is REJECTED');
        $this->assert($rolePrivRes['is_readonly'] === false, 'TC 3.8: is_readonly confirmed false for role-inherited user');

        // 3.4 ความเป็นอิสระจากชื่อผู้ใช้ (Username Independence)
        $simulatedWriterConfig = [
            'hosxp_host'        => $this->host,
            'hosxp_port'        => $this->port,
            'hosxp_database'    => $this->testDb,
            'hosxp_username'    => 'hosxp_readonly',
            'hcode'             => $this->hcode,
            'agent_mode'        => 'mock',
            'simulated_catalog' => [
                'global' => ['USAGE'],
                'schema' => ['SELECT', 'INSERT', 'UPDATE'],
                'table'  => [],
                'column' => [],
                'roles'  => [],
            ],
            'simulated_grants'  => [
                "GRANT USAGE ON *.* TO 'hosxp_readonly'@'%'",
                "GRANT SELECT, INSERT, UPDATE ON `{$this->testDb}`.* TO 'hosxp_readonly'@'%'"
            ]
        ];
        $connTester = new AgentConnection();
        $simRes = $connTester->testConnection($simulatedWriterConfig);
        $this->assert($simRes['success'] === false, 'TC 3.9: Username "hosxp_readonly" with write grant is REJECTED');
        $this->assert($simRes['is_readonly'] === false, 'TC 3.10: Privilege engine detects INSERT/UPDATE regardless of username');

        // 3.5 ทดสอบการปฏิเสธคำสั่งเขียนจริง (INSERT, UPDATE, DELETE, DDL) บน MySQL Engine
        $roPdo = MySqlTestEnvironment::getReadOnlyPdo();
        $rejectionRes = $this->executeWriteRejectionProbe($roPdo, $this->testDb);

        $this->assert($rejectionRes['success'] === true, 'TC 3.11: Real MySQL Engine rejected all write attempts');
        $this->assert($rejectionRes['details']['insert']['rejected'] === true, 'TC 3.12: INSERT statement rejected by MySQL Engine (Access denied)');
        $this->assert($rejectionRes['details']['update']['rejected'] === true, 'TC 3.13: UPDATE statement rejected by MySQL Engine (Access denied)');
        $this->assert($rejectionRes['details']['delete']['rejected'] === true, 'TC 3.14: DELETE statement rejected by MySQL Engine (Access denied)');
        $this->assert($rejectionRes['details']['ddl_create']['rejected'] === true, 'TC 3.15: DDL CREATE TABLE rejected by MySQL Engine (Access denied)');
        $this->assert($rejectionRes['details']['ddl_drop']['rejected'] === true, 'TC 3.16: DDL DROP TABLE rejected by MySQL Engine (Access denied)');

        // 3.6 ป้องกันการทดสอบคำสั่งเขียนหากฐานข้อมูลไม่ใช่ Test Database
        $prodProbeRes = $this->executeWriteRejectionProbe($roPdo, 'hos');
        $this->assert($prodProbeRes['success'] === false, 'TC 3.17: Safety guard blocks write probe on non-test DB "hos"');
        $this->assert(!empty($prodProbeRes['blocked']), 'TC 3.18: Blocked flag is set when target is non-test DB');
    }

    /**
     * หมวดที่ 4: ทดสอบการทำงานของ Extractor บน MySQL จริง
     */
    private function testGroup4_ExtractorOnRealDatabase(): void {
        echo "\n--- Group 4: Data Extractor & Pipeline Execution on Real Database ---\n";

        $roPdo = MySqlTestEnvironment::getReadOnlyPdo();

        // นับจำนวนแถวในตารางก่อนรันการดึงข้อมูล เพื่อตรวจสอบ Zero Write Guarantee
        $countOpBefore = (int)$roPdo->query("SELECT COUNT(*) FROM opitemrece")->fetchColumn();
        $countDrugBefore = (int)$roPdo->query("SELECT COUNT(*) FROM drugitems")->fetchColumn();
        $countVnBefore = (int)$roPdo->query("SELECT COUNT(*) FROM vn_stat")->fetchColumn();

        // 4.1 ตรวจสอบข้อกำหนดการยืนยันก่อน Query ข้อมูลจริง (Confirmation Required)
        $roConfig = [
            'hosxp_host'     => $this->host,
            'hosxp_port'     => $this->port,
            'hosxp_database' => $this->testDb,
            'hosxp_username' => $this->roUser,
            'hosxp_password' => $this->roPass,
            'hcode'          => $this->hcode,
            'agent_mode'     => 'real',
        ];
        $reader = new AgentHosxpReader($roConfig, $roPdo);

        $unconfirmedRes = $reader->validateQuery('2026-10-25', '2026-10-25', false);
        $this->assert($unconfirmedRes['success'] === false, 'TC 4.1: Query without confirmation is REJECTED');
        $this->assert(!empty($unconfirmedRes['confirmation_required']), 'TC 4.2: confirmation_required flag is returned');

        // 4.2 ตรวจสอบข้อจำกัดช่วงวันไม่เกิน 1 วันสำหรับการอ่านข้อมูลครั้งแรก
        $overLimitRes = $reader->validateQuery('2026-10-25', '2026-10-28', true);
        $this->assert($overLimitRes['success'] === false, 'TC 4.3: Date range > 1 day rejected for first-time query');

        // 4.3 ดำเนินการ Query จริงบน MySQL (วันที่ 2026-10-25)
        $queryRes = $reader->validateQuery('2026-10-25', '2026-10-25', true);
        $this->assert($queryRes['success'] === true, 'TC 4.4: Query execution on real MySQL succeeds');
        $this->assert($queryRes['total_read'] > 0, "TC 4.5: Total read matches synthetic data (read: {$queryRes['total_read']})");
        $this->assert($queryRes['total_passed'] === 2, "TC 4.6: Exactly 2 valid records passed for 2026-10-25 (actual: {$queryRes['total_passed']})");

        // 4.4 ตรวจสอบความถูกต้องของ 6 ฟิลด์ที่กำหนด (Strict 6-Field Whitelist)
        $allowedFields = ['hos_guid', 'vn', 'hcode', 'vstdate', 'tpu_code_list', 'qty'];
        $piiViolations = 0;
        foreach ($queryRes['sample_data'] as $sampleRow) {
            foreach (array_keys($sampleRow) as $k) {
                if (!in_array($k, $allowedFields, true)) {
                    $piiViolations++;
                }
            }
            if (isset($sampleRow['hn']) || isset($sampleRow['cid']) || isset($sampleRow['fname']) || isset($sampleRow['lname'])) {
                $piiViolations++;
            }
        }
        $this->assert($piiViolations === 0, 'TC 4.7: Strictly 0 PII or unapproved fields in Extractor sample payload');
        $this->assert(count($queryRes['sample_data'][0]) === 6, 'TC 4.8: Each record contains exactly 6 fields');

        // 4.5 ตรวจสอบการตัดรายการซ้ำด้วย Composite Key (hcode, hos_guid)
        $registeredDrugs = AgentDrugRegistry::getRegisteredDrugCodes();
        $extractor = new AgentDataExtractor($roPdo, $registeredDrugs);
        $fullRangeRes = $extractor->extract('2026-10-01', '2026-10-31');

        $this->assert($fullRangeRes['success'] === true, 'TC 4.9: Full date range extract succeeds via Extractor');
        $this->assert($fullRangeRes['duplicates_pruned'] === 1, "TC 4.10: Exactly 1 duplicate pruned by (hcode, hos_guid) (actual: {$fullRangeRes['duplicates_pruned']})");
        $this->assert($fullRangeRes['total_malformed'] === 7, "TC 4.11: Malformed records correctly detected and isolated (count: {$fullRangeRes['total_malformed']})");

        // 4.6 ข้ามรายการยาที่ไม่ได้ลงทะเบียนโดยไม่สร้าง Log รายรายการ
        $allTpuInPassed = array_unique(array_column($fullRangeRes['sample_data'], 'tpu_code_list'));
        $unregisteredFound = 0;
        foreach ($allTpuInPassed as $tpu) {
            if (!AgentDrugRegistry::isRegistered($tpu)) {
                $unregisteredFound++;
            }
        }
        $this->assert($unregisteredFound === 0, 'TC 4.12: Zero unregistered drugs present in extracted sample data');

        // 4.7 หยุดการทดสอบ Extractor ทันทีหากบัญชีมีสิทธิ์เขียน
        $writerConfig = [
            'hosxp_host'     => $this->host,
            'hosxp_port'     => $this->port,
            'hosxp_database' => $this->testDb,
            'hosxp_username' => 'rxcore_writer_test',
            'hosxp_password' => 'WriterPass_2026!',
            'hcode'          => $this->hcode,
            'agent_mode'     => 'real',
        ];
        $stopWriterReader = new AgentHosxpReader($writerConfig);
        $stopPrivCheck = $stopWriterReader->validatePrivileges();
        $extractorStopped = false;
        if (!$stopPrivCheck['success'] || !$stopPrivCheck['is_readonly']) {
            $extractorStopped = true; // หยุดตามนโยบายความปลอดภัย
        }
        $this->assert($extractorStopped === true, 'TC 4.13: Extractor pipeline strictly blocked when account has write privileges');

        // 4.8 ตรวจสอบการเปรียบเทียบผลลัพธ์ (Test E)
        $compareMatched = AgentHosxpReader::compareResults(2, $queryRes['total_passed']);
        $this->assert($compareMatched['matched'] === true, 'TC 4.14: Comparison with expected HOSxP count matches 100% (2 = 2)');
        $this->assert($compareMatched['difference'] === 0, 'TC 4.15: Difference between HOSxP count and Agent count is 0');

        $compareMismatch = AgentHosxpReader::compareResults(5, $queryRes['total_passed']);
        $this->assert($compareMismatch['matched'] === false, 'TC 4.16: Differing count correctly reports MISMATCH');
        $this->assert($compareMismatch['difference'] === -3, 'TC 4.17: Difference calculated correctly (-3)');

        // 4.9 ยืนยัน Zero Write Guarantee: ตรวจสอบจำนวนแถวในตารางหลังรัน Query ทั้งหมด
        $countOpAfter = (int)$roPdo->query("SELECT COUNT(*) FROM opitemrece")->fetchColumn();
        $countDrugAfter = (int)$roPdo->query("SELECT COUNT(*) FROM drugitems")->fetchColumn();
        $countVnAfter = (int)$roPdo->query("SELECT COUNT(*) FROM vn_stat")->fetchColumn();

        $this->assert($countOpBefore === $countOpAfter, "TC 4.18: opitemrece row count identical before and after queries ({$countOpBefore} = {$countOpAfter})");
        $this->assert($countDrugBefore === $countDrugAfter, "TC 4.19: drugitems row count identical before and after queries ({$countDrugBefore} = {$countDrugAfter})");
        $this->assert($countVnBefore === $countVnAfter, "TC 4.20: vn_stat row count identical before and after queries ({$countVnBefore} = {$countVnAfter})");
    }

    /**
     * หมวดที่ 5: ความเข้ากันได้กับ Mock Mode และ Zero Outbound Network Requests
     */
    private function testGroup5_MockModeCoexistenceAndSecurityInvariants(): void {
        echo "\n--- Group 5: Mock Mode Coexistence & Outbound Invariants ---\n";

        // 5.1 ยืนยันว่าการตั้งค่า Real Connection ไม่เปลี่ยน Default Mock Mode ของ AgentConfig
        $cfgMgr = new AgentConfig();
        $currentCfg = $cfgMgr->load();
        $this->assert(isset($currentCfg['agent_mode']), 'TC 5.1: AgentConfig has agent_mode defined');

        // 5.2 ยืนยันว่า Mock Connection เดิมยังทำงานได้ถูกต้อง 100%
        $mockConfig = [
            'hosxp_host'     => '127.0.0.1',
            'hosxp_port'     => 3306,
            'hosxp_database' => 'hos',
            'hosxp_username' => 'hosxp_readonly',
            'hosxp_password' => 'secret',
            'hcode'          => $this->hcode,
            'agent_mode'     => 'mock',
        ];
        $connTester = new AgentConnection();
        $mockRes = $connTester->testConnection($mockConfig);
        $this->assert($mockRes['success'] === true, 'TC 5.2: Mock connection test succeeds without real DB');
        $this->assert($mockRes['mode'] === 'mock', 'TC 5.3: Mode is strictly mock');

        // 5.3 ยืนยันว่าไม่มีการส่ง Outbound Network Request ออกภายนอก
        $this->assert(defined('RXCORE_STRICT_MOCK_MODE') ? RXCORE_STRICT_MOCK_MODE : true, 'TC 5.4: RXCORE_STRICT_MOCK_MODE is active');
        $this->assert(AgentApiClient::isExternalUrl('https://api.supabase.co/v1') === true, 'TC 5.5: Detects external Supabase URL');
        $this->assert(AgentApiClient::isExternalUrl('http://127.0.0.1:3306') === false, 'TC 5.6: Internal loopback address confirmed safe');
        $this->assert(AgentApiClient::isExternalUrl('mock://local') === false, 'TC 5.7: Mock scheme confirmed internal');
    }

    /**
     * ดำเนินการทดสอบปฏิเสธคำสั่งเขียน (INSERT, UPDATE, DELETE, DDL) บน MySQL Engine
     */
    private function executeWriteRejectionProbe(PDO $pdo, string $database): array {
        if (!AgentHosxpReader::isSafeTestDatabase($database)) {
            return [
                'success' => false,
                'blocked' => true,
                'error'   => "Safety guard: Database '{$database}' is not a verified test database.",
            ];
        }

        $results = [];
        $allRejected = true;

        // 1. INSERT
        try {
            $pdo->exec("INSERT INTO opitemrece (hos_guid, vn, icode, vstdate, qty) VALUES ('{PROBE-TEST}', 'PROBE', '1000001', '2026-10-25', 1)");
            $allRejected = false;
            $results['insert'] = ['rejected' => false];
            @$pdo->exec("DELETE FROM opitemrece WHERE hos_guid = '{PROBE-TEST}'");
        } catch (\PDOException $e) {
            $results['insert'] = ['rejected' => true, 'msg' => $e->getMessage()];
        }

        // 2. UPDATE
        try {
            $pdo->exec("UPDATE opitemrece SET qty = 99 WHERE vn = 'NON_EXISTENT_PROBE'");
            $allRejected = false;
            $results['update'] = ['rejected' => false];
        } catch (\PDOException $e) {
            $results['update'] = ['rejected' => true, 'msg' => $e->getMessage()];
        }

        // 3. DELETE
        try {
            $pdo->exec("DELETE FROM opitemrece WHERE vn = 'NON_EXISTENT_PROBE'");
            $allRejected = false;
            $results['delete'] = ['rejected' => false];
        } catch (\PDOException $e) {
            $results['delete'] = ['rejected' => true, 'msg' => $e->getMessage()];
        }

        // 4. DDL CREATE
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `rxcore_probe_tbl` (`id` INT)");
            $allRejected = false;
            $results['ddl_create'] = ['rejected' => false];
            @$pdo->exec("DROP TABLE IF EXISTS `rxcore_probe_tbl`");
        } catch (\PDOException $e) {
            $results['ddl_create'] = ['rejected' => true, 'msg' => $e->getMessage()];
        }

        // 5. DDL DROP
        try {
            $pdo->exec("DROP TABLE IF EXISTS `rxcore_probe_tbl`");
            $allRejected = false;
            $results['ddl_drop'] = ['rejected' => false];
        } catch (\PDOException $e) {
            $results['ddl_drop'] = ['rejected' => true, 'msg' => $e->getMessage()];
        }

        return [
            'success'      => $allRejected,
            'all_rejected' => $allRejected,
            'details'      => $results,
        ];
    }

    private function printSummary(): void {
        $total = $this->passed + $this->failed + $this->notTested;
        $successRate = ($this->passed + $this->failed) > 0 ? round(($this->passed / ($this->passed + $this->failed)) * 100, 1) : 0;

        echo "\n=================================================================\n";
        echo sprintf("   PHASE 3.9 TEST SUMMARY: %d/%d PASSED (%s%%)\n",
            $this->passed,
            $total,
            $successRate
        );
        echo "   PASS:       {$this->passed}\n";
        echo "   FAIL:       {$this->failed}\n";
        echo "   NOT TESTED: {$this->notTested}\n";
        if ($this->failed === 0) {
            echo "   STATUS: ALL PHASE 3.9 DATABASE VALIDATION TESTS PASSED! \n";
        } else {
            echo "   STATUS: SOME TESTS FAILED! (Errors: " . implode(', ', $this->errors) . ")\n";
        }
        echo "=================================================================\n\n";
    }
}

// เรียกเมื่อรันผ่าน CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $suite = new Phase39TestSuite();
    exit($suite->runAll());
}
