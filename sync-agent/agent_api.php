<?php
/**
 * RxCore Sync Agent — API Endpoint
 * Phase 3.1: Foundation
 * 
 * รับ AJAX Requests จาก UI สำหรับ:
 * 1. บันทึกค่าตั้งค่า (save_config)
 * 2. โหลดค่าตั้งค่า (load_config)
 * 3. ทดสอบการเชื่อมต่อ (test_connection)
 * 4. ล้างค่าตั้งค่า (clear_config)
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_connection.php';
require_once __DIR__ . '/agent_drug_registry.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_mock_state.php';
require_once __DIR__ . '/agent_sync_core.php';
require_once __DIR__ . '/agent_hosxp_reader.php';
if (file_exists(__DIR__ . '/compat_setup_mysql.php')) {
    require_once __DIR__ . '/compat_setup_mysql.php';
}
require_once __DIR__ . '/agent_api_client.php';
require_once __DIR__ . '/agent_mock_server.php';
require_once __DIR__ . '/agent_mock_clock.php';
require_once __DIR__ . '/agent_scheduler.php';
require_once __DIR__ . '/agent_mock_transport.php';
require_once __DIR__ . '/agent_retry_policy.php';
require_once __DIR__ . '/agent_reconciliation.php';
require_once __DIR__ . '/agent_sync_event.php';
require_once __DIR__ . '/agent_moph_notify.php';
require_once __DIR__ . '/agent_sync_monitor.php';
require_once __DIR__ . '/agent_production_service.php';


// หากถูก include จาก CLI หรือ Test Suite ให้ข้ามการประมวลผล Web Request
if (php_sapi_name() === 'cli' && empty($_SERVER['REQUEST_METHOD'])) {
    return;
}

// รับเฉพาะ POST Request
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// อ่าน JSON Body
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

$configManager = new AgentConfig();

try {
    switch ($action) {
        case 'save_config':
            $configData = [
                'hosxp_host'     => trim($input['hosxp_host'] ?? ''),
                'hosxp_port'     => (int)($input['hosxp_port'] ?? 3306),
                'hosxp_database' => trim($input['hosxp_database'] ?? ''),
                'hosxp_username' => trim($input['hosxp_username'] ?? ''),
                'hosxp_password' => $input['hosxp_password'] ?? '',
                'hcode'          => trim($input['hcode'] ?? ''),
                'agent_mode'     => ($input['agent_mode'] ?? 'mock') === 'real' ? 'real' : 'mock',
            ];

            // หาก password ว่าง และมี config เดิม ให้ใช้ password เดิม
            if ($configData['hosxp_password'] === '' && $configManager->hasConfig()) {
                $existingConfig = $configManager->load();
                $configData['hosxp_password'] = $existingConfig['hosxp_password'] ?? '';
            }

            $saved = $configManager->save($configData);

            // บันทึก Log (ห้ามแสดง Password)
            $logEntry = date('Y-m-d H:i:s') . " | Config saved: host={$configData['hosxp_host']}, port={$configData['hosxp_port']}, db={$configData['hosxp_database']}, user={$configData['hosxp_username']}, hcode={$configData['hcode']}, mode={$configData['agent_mode']}";
            writeAgentLog($logEntry);

            echo json_encode([
                'success' => $saved,
                'message' => $saved ? 'บันทึกค่าตั้งค่าสำเร็จ' : 'เกิดข้อผิดพลาดในการบันทึก',
            ]);
            break;
            
        case 'load_config':
            $config = $configManager->getSafeConfig();
            echo json_encode([
                'success' => true,
                'config'  => $config,
            ]);
            break;
            
        case 'test_connection':
            // โหลด Config จากไฟล์ (รวม Password ที่ถอดรหัสแล้ว) เป็นฐาน
            $config = $configManager->load();
            
            // หาก UI ส่งค่าในฟอร์มมา ให้ใช้ค่านั้นสำหรับทดสอบ
            if (isset($input['hosxp_host']) && trim($input['hosxp_host']) !== '') {
                $config['hosxp_host'] = trim($input['hosxp_host']);
            }
            if (isset($input['hosxp_port']) && (int)$input['hosxp_port'] > 0) {
                $config['hosxp_port'] = (int)$input['hosxp_port'];
            }
            if (isset($input['hosxp_database']) && trim($input['hosxp_database']) !== '') {
                $config['hosxp_database'] = trim($input['hosxp_database']);
            }
            if (isset($input['hosxp_username']) && trim($input['hosxp_username']) !== '') {
                $config['hosxp_username'] = trim($input['hosxp_username']);
            }
            if (isset($input['hcode']) && trim($input['hcode']) !== '') {
                $config['hcode'] = trim($input['hcode']);
            }
            if (!empty($input['hosxp_password'])) {
                $config['hosxp_password'] = $input['hosxp_password'];
            }
            if (isset($input['agent_mode'])) {
                $config['agent_mode'] = ($input['agent_mode'] === 'real') ? 'real' : 'mock';
            }

            $connector = new AgentConnection();
            $result = $connector->testConnection($config);
            
            // บันทึก Log (ห้ามแสดง Password)
            $status = $result['success'] ? 'SUCCESS' : 'FAILED';
            $mode = $result['mode'] ?? 'unknown';
            $logEntry = date('Y-m-d H:i:s') . " | Test Connection [{$mode}] Host: {$config['hosxp_host']}:{$config['hosxp_port']} DB: {$config['hosxp_database']} HCODE: {$config['hcode']} -> {$status}";
            if (!$result['success']) {
                $logEntry .= " | Error: " . ($result['error'] ?? 'Unknown');
            }
            writeAgentLog($logEntry);
            
            echo json_encode([
                'success' => $result['success'],
                'result'  => $result,
            ]);
            break;
            
        case 'get_logs':
            $logDir = __DIR__ . '/logs';
            $logFile = $logDir . '/agent_' . date('Y-m-d') . '.log';
            $logs = [];
            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                // นำ 50 บรรทัดล่าสุดย้อนหลัง
                $logs = array_slice(array_reverse($lines), 0, 50);
            }
            echo json_encode([
                'success' => true,
                'logs'    => $logs,
            ]);
            break;

        case 'clear_config':
            $cleared = $configManager->clear();
            writeAgentLog(date('Y-m-d H:i:s') . " | Config cleared");
            echo json_encode([
                'success' => $cleared,
                'message' => $cleared ? 'ลบค่าตั้งค่าทั้งหมดสำเร็จ' : 'เกิดข้อผิดพลาด',
            ]);
            break;

        case 'preview_extraction':
            $startDate = trim($input['start_date'] ?? '2026-10-01');
            $endDate   = trim($input['end_date'] ?? '2026-10-31');
            $scenario  = $input['scenario'] ?? 'default';

            $pdo = null;
            if ($scenario === 'mysql_real') {
                // เชื่อมต่อฐานข้อมูลทดสอบ MySQL/MariaDB จริงด้วยบัญชี Read-only (Phase 3.2.1)
                try {
                    $mysqlRoPdo = MySqlTestEnvironment::getReadOnlyPdo();
                    $extractor = new AgentDataExtractor($mysqlRoPdo);
                } catch (\Throwable $e) {
                    // หากยังไม่ได้ setup ให้ setup ครั้งแรกอัตโนมัติ
                    MySqlTestEnvironment::setup();
                    $mysqlRoPdo = MySqlTestEnvironment::getReadOnlyPdo();
                    $extractor = new AgentDataExtractor($mysqlRoPdo);
                }
            } elseif ($scenario === 'error') {
                // จำลองกรณี Adapter / Database เกิดข้อผิดพลาด (Test Case 9)
                $errorPdo = new PDO('sqlite::memory:');
                $errorPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $extractor = new AgentDataExtractor($errorPdo);
            } elseif ($scenario === 'empty') {
                // จำลองกรณีไม่มีข้อมูลในช่วงวันที่ (Test Case 8)
                $emptyPdo = MockDatasetPhase32::createMockPdo([], [], []);
                $extractor = new AgentDataExtractor($emptyPdo);
            } else {
                // ใช้ Mock Dataset ปกติที่ครอบคลุมทุก Test Case
                $extractor = new AgentDataExtractor();
            }

            $result = $extractor->extract($startDate, $endDate);
            $result['scenario'] = $scenario;
            $result['engine']   = ($scenario === 'mysql_real') ? 'MySQL/MariaDB (Real Test DB)' : 'Mock Dataset (In-memory)';

            // บันทึก Log โดยระบุเฉพาะตัวเลขสถิติ ไม่บันทึกข้อมูลส่วนบุคคลของผู้ป่วย
            if ($result['success']) {
                $logMsg = date('Y-m-d H:i:s') . " | Extraction Preview [{$startDate} to {$endDate}, {$result['engine']}]: Read={$result['total_read']}, Passed={$result['total_passed']}, DupsPruned={$result['duplicates_pruned']}, Malformed={$result['total_malformed']}";
            } else {
                $logMsg = date('Y-m-d H:i:s') . " | Extraction Preview Failed: " . ($result['error'] ?? 'Unknown error');
            }
            writeAgentLog($logMsg);

            echo json_encode([
                'success' => $result['success'],
                'data'    => $result,
            ]);
            break;

        case 'test_mysql_compatibility':
            // ดำเนินการทดสอบความเข้ากันได้ของ MySQL/MariaDB (Phase 3.2.1)
            require_once __DIR__ . '/test_mysql_compatibility.php';
            // Setup DB ก่อน
            $setupRes = MySqlTestEnvironment::setup();
            if (!$setupRes['success']) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'ไม่สามารถเตรียมฐานข้อมูลทดสอบ MySQL ได้: ' . ($setupRes['error'] ?? ''),
                ]);
                break;
            }

            $roPdo = MySqlTestEnvironment::getReadOnlyPdo();
            $serverInfo = $roPdo->query("SELECT VERSION() AS ver, DATABASE() AS db, @@character_set_database AS cs")->fetch(PDO::FETCH_ASSOC);

            // ทดสอบ Write protection
            $blockedCommands = [];
            foreach (['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE'] as $cmd) {
                try {
                    $roPdo->exec("/* test */ {$cmd} INTO opitemrece VALUES ('1')");
                    $blockedCommands[$cmd] = 'ALLOWED_UNEXPECTEDLY';
                } catch (\PDOException $e) {
                    $blockedCommands[$cmd] = 'BLOCKED_ACCESS_DENIED';
                }
            }

            // ทดสอบ Extraction
            $extractor = new AgentDataExtractor($roPdo);
            $extractRes = $extractor->extract('2026-10-01', '2026-10-31');

            echo json_encode([
                'success'           => true,
                'server_version'    => $serverInfo['ver'] ?? 'Unknown',
                'database'          => $serverInfo['db'] ?? '',
                'charset'           => $serverInfo['cs'] ?? '',
                'write_protection'  => $blockedCommands,
                'extraction_result' => $extractRes,
            ]);
            break;

        case 'get_registered_drugs':
            echo json_encode([
                'success' => true,
                'drugs'   => AgentDrugRegistry::getRegistrySummary(),
            ]);
            break;

        case 'sync_preview':
            // Phase 3.3: Mock Sync Pipeline Preview (PREVIEW_ONLY)
            $syncType  = ($input['sync_type'] ?? 'initial') === 'incremental' ? 'incremental' : 'initial';
            $startDate = trim($input['start_date'] ?? '');
            $endDate   = trim($input['end_date'] ?? '');
            $scenario  = $input['scenario'] ?? 'default';

            // จัดการ PDO ตาม Scenario
            $pdo = null;
            if ($scenario === 'mysql_real') {
                try {
                    $pdo = MySqlTestEnvironment::getReadOnlyPdo();
                } catch (\Throwable $e) {
                    MySqlTestEnvironment::setup();
                    $pdo = MySqlTestEnvironment::getReadOnlyPdo();
                }
            } elseif ($scenario === 'empty') {
                $pdo = MockDatasetPhase32::createMockPdo([], [], []);
            }

            $extractor = new AgentDataExtractor($pdo);
            $mockState = new AgentMockSyncState(__DIR__ . '/config/mock_sync_state.json');
            $syncCore  = new AgentSyncCore($extractor, $mockState);

            if ($syncType === 'incremental') {
                $preview = $syncCore->runIncrementalSyncPreview($startDate ?: null, $endDate ?: null);
            } else {
                $preview = $syncCore->runInitialSyncPreview($startDate ?: null, $endDate ?: null);
            }

            $preview['scenario'] = $scenario;
            $preview['mock_state_summary'] = $mockState->getSummary();

            $logMsg = date('Y-m-d H:i:s') . " | Sync Preview [{$syncType}, {$scenario}]: New={$preview['new_records_count']}, Existing={$preview['already_synced_count']}, Dups={$preview['duplicates_pruned']}, Malformed={$preview['total_malformed']}";
            writeAgentLog($logMsg);

            echo json_encode([
                'success' => $preview['success'],
                'data'    => $preview,
            ]);
            break;

        case 'simulate_mock_commit':
            // Phase 3.3: จำลองการบันทึก Mock State (สำหรับทดสอบ Idempotence / Same-date)
            $records  = $input['records'] ?? [];
            $syncType = ($input['sync_type'] ?? 'incremental') === 'initial' ? 'INITIAL_SYNC' : 'INCREMENTAL_SYNC';

            $extractor = new AgentDataExtractor();
            $mockState = new AgentMockSyncState(__DIR__ . '/config/mock_sync_state.json');
            $syncCore  = new AgentSyncCore($extractor, $mockState);

            $commitRes = $syncCore->simulateMockCommit($records, $syncType);

            writeAgentLog(date('Y-m-d H:i:s') . " | Mock Commit Simulated: {$commitRes['committed_count']} records [{$syncType}]");

            echo json_encode([
                'success' => true,
                'data'    => $commitRes,
            ]);
            break;

        case 'get_mock_state':
            $mockState = new AgentMockSyncState(__DIR__ . '/config/mock_sync_state.json');
            echo json_encode([
                'success' => true,
                'summary' => $mockState->getSummary(),
                'cursor'  => $mockState->getCursor(),
            ]);
            break;

        case 'reset_mock_state':
            $mockState = new AgentMockSyncState(__DIR__ . '/config/mock_sync_state.json');
            $reset = $mockState->reset();
            writeAgentLog(date('Y-m-d H:i:s') . " | Mock State Reset");
            echo json_encode([
                'success' => $reset,
                'message' => 'รีเซ็ต Mock State เรียบร้อยแล้ว',
            ]);
            break;

        case 'hosxp_test_diagnostics':
            // Phase 3.4: รัน Test A (Connection), Test B (Schema), Test C (Privilege)
            $config = $configManager->load();

            // หาก UI ส่งค่า Override มา ให้ใช้ค่านั้น
            if (isset($input['hosxp_host']) && trim($input['hosxp_host']) !== '') $config['hosxp_host'] = trim($input['hosxp_host']);
            if (isset($input['hosxp_port']) && (int)$input['hosxp_port'] > 0) $config['hosxp_port'] = (int)$input['hosxp_port'];
            if (isset($input['hosxp_database']) && trim($input['hosxp_database']) !== '') $config['hosxp_database'] = trim($input['hosxp_database']);
            if (isset($input['hosxp_username']) && trim($input['hosxp_username']) !== '') $config['hosxp_username'] = trim($input['hosxp_username']);
            if (!empty($input['hosxp_password'])) $config['hosxp_password'] = $input['hosxp_password'];
            if (isset($input['agent_mode'])) $config['agent_mode'] = $input['agent_mode'];

            $reader = new AgentHosxpReader($config);

            // Test A
            $testA = $reader->testConnection();

            // Test B & C (ถ้า A ผ่าน)
            $testB = $testA['success'] ? $reader->validateSchema() : ['success' => false, 'error' => 'ข้ามการตรวจสอบ Schema เนื่องจากเชื่อมต่อไม่สำเร็จ'];
            $testC = $testA['success'] ? $reader->validatePrivileges() : ['success' => false, 'error' => 'ข้ามการตรวจสอบสิทธิ์เนื่องจากเชื่อมต่อไม่สำเร็จ'];

            $allPassed = $testA['success'] && $testB['success'] && $testC['success'];

            $logEntry = date('Y-m-d H:i:s') . sprintf(
                " | Phase 3.4 Diagnostics: Host=%s:%s, DB=%s, User=%s -> Conn=%s, Schema=%s, Priv=%s",
                $config['hosxp_host'] ?? '',
                $config['hosxp_port'] ?? '',
                $config['hosxp_database'] ?? '',
                $config['hosxp_username'] ?? '',
                $testA['success'] ? 'PASS' : 'FAIL',
                $testB['success'] ? 'PASS' : 'FAIL',
                $testC['success'] ? 'PASS' : 'FAIL'
            );
            writeAgentLog($logEntry);

            echo json_encode([
                'success' => $allPassed,
                'test_a'  => $testA,
                'test_b'  => $testB,
                'test_c'  => $testC,
            ]);
            break;

        case 'hosxp_test_query':
            // Phase 3.4: รัน Test D (Query Validation) บน HOSxP จริง พร้อมเงื่อนไขความปลอดภัย
            $config = $configManager->load();
            if (isset($input['hosxp_host']) && trim($input['hosxp_host']) !== '') $config['hosxp_host'] = trim($input['hosxp_host']);
            if (isset($input['hosxp_port']) && (int)$input['hosxp_port'] > 0) $config['hosxp_port'] = (int)$input['hosxp_port'];
            if (isset($input['hosxp_database']) && trim($input['hosxp_database']) !== '') $config['hosxp_database'] = trim($input['hosxp_database']);
            if (isset($input['hosxp_username']) && trim($input['hosxp_username']) !== '') $config['hosxp_username'] = trim($input['hosxp_username']);
            if (!empty($input['hosxp_password'])) $config['hosxp_password'] = $input['hosxp_password'];

            $startDate = trim($input['start_date'] ?? date('Y-m-d'));
            $endDate   = trim($input['end_date'] ?? date('Y-m-d'));
            $confirmed = (bool)($input['confirmed'] ?? false);

            $reader = new AgentHosxpReader($config);
            $queryRes = $reader->validateQuery($startDate, $endDate, $confirmed);

            if ($queryRes['success']) {
                writeAgentLog(date('Y-m-d H:i:s') . " | Phase 3.4 Query Executed [{$startDate} to {$endDate}]: Read={$queryRes['total_read']}, Passed={$queryRes['total_passed']}, SampleShown={$queryRes['sample_count']}");
            } else {
                writeAgentLog(date('Y-m-d H:i:s') . " | Phase 3.4 Query Blocked/Failed: " . ($queryRes['error'] ?? 'Unknown error'));
            }

            echo json_encode([
                'success' => $queryRes['success'],
                'data'    => $queryRes,
            ]);
            break;

        case 'hosxp_compare_results':
            // Phase 3.4: รัน Test E (Compare Results) เปรียบเทียบผลลัพธ์กับ HOSxP
            $hosxpCount = isset($input['hosxp_count']) && $input['hosxp_count'] !== '' ? (int)$input['hosxp_count'] : null;
            $agentCount = (int)($input['agent_count'] ?? 0);

            $compRes = AgentHosxpReader::compareResults($hosxpCount, $agentCount);
            writeAgentLog(date('Y-m-d H:i:s') . " | Phase 3.4 Compare Results: HOSxP={$hosxpCount}, Agent={$agentCount} -> Status={$compRes['status']}");

            echo json_encode([
                'success' => true,
                'data'    => $compRes,
            ]);
            break;

        case 'api_test_sync':
            // Phase 3.5: ส่งข้อมูลผ่าน AgentApiClient
            $hcode     = trim($input['hcode'] ?? '01425');
            $apiKey    = trim($input['api_key'] ?? 'key_secret_01425_abc123');
            $mode      = $input['mode'] ?? 'local';
            $timeout   = (int)($input['timeout'] ?? 15);
            $scenario  = $input['scenario'] ?? null;
            $records   = $input['records'] ?? null;
            $skipVal   = !empty($input['skip_client_validation']);

            if (!is_array($records)) {
                $records = [
                    [
                        'hos_guid'      => '{GUID-' . strtoupper(substr(md5(uniqid()), 0, 8)) . '}',
                        'vn'            => 'VN' . date('Ymd') . '001',
                        'hcode'         => $hcode,
                        'vstdate'       => date('Y-m-d'),
                        'tpu_code_list' => '100789000012345678901001',
                        'qty'           => 10,
                    ]
                ];
            }

            if ($mode === 'curl') {
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $endpoint = "http://{$host}/rxcore/sync-agent/agent_mock_server.php";
                $client = new AgentApiClient($endpoint, $apiKey, $timeout);
            } else {
                $mockServer = new AgentMockApiServer();
                $adapterCallable = function(string $key, string $payload, array $headers) use ($mockServer) {
                    return $mockServer->handleRequest($key, $payload, $headers);
                };
                $client = new AgentApiClient('mock://local', $apiKey, $timeout, $adapterCallable);
            }

            $options = [
                'skip_client_validation' => $skipVal,
            ];
            if (!empty($scenario)) {
                $options['mock_scenario'] = $scenario;
            }

            $syncResult = $client->sendDispenseBatch($hcode, $records, $options);
            writeAgentLog(date('Y-m-d H:i:s') . " | Phase 3.5 API Test [hcode={$hcode}, mode={$mode}]: HTTP={$syncResult['http_code']}, Success=" . ($syncResult['success'] ? 'true' : 'false'));

            echo json_encode([
                'success' => true,
                'data'    => $syncResult,
                'sent_payload' => [
                    'hcode' => $hcode,
                    'records' => $records,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'api_run_all_scenarios':
            // Phase 3.5: รันและสรุปผลทั้ง 12 สถานการณ์
            $scenarios = [
                '200_ok' => ['name' => '1. HTTP 200: ส่งข้อมูลสำเร็จ', 'status' => 'PENDING'],
                '400_bad' => ['name' => '2. HTTP 400: Payload ไม่ถูกต้อง (Missing Field/Invalid Qty)', 'status' => 'PENDING'],
                '401_auth' => ['name' => '3. HTTP 401: Authentication ไม่ผ่าน (Invalid Key)', 'status' => 'PENDING'],
                '403_cross' => ['name' => '4. HTTP 403: Forbidden (Cross-facility violation)', 'status' => 'PENDING'],
                '409_conflict' => ['name' => '5. HTTP 409: Conflict Simulation', 'status' => 'PENDING'],
                '429_ratelimit' => ['name' => '6. HTTP 429: Rate Limit Exceeded', 'status' => 'PENDING'],
                '500_error' => ['name' => '7. HTTP 500: Server Error Handling', 'status' => 'PENDING'],
                'timeout_refused' => ['name' => '8. Timeout & Connection Refused', 'status' => 'PENDING'],
                'malformed_json' => ['name' => '9. Response JSON ไม่ถูกต้อง', 'status' => 'PENDING'],
                'key_safety' => ['name' => '10. API Key ปลอดภัย (ไม่รั่วไหลใน Log/Error)', 'status' => 'PENDING'],
                'pii_blocked' => ['name' => '11. สกัดกั้น PII (HN, CID) และฟิลด์เกิน', 'status' => 'PENDING'],
                'idempotence' => ['name' => '12. ป้องกันข้อมูลซ้ำ (Composite Key Upsert)', 'status' => 'PENDING'],
                'drug_inactive' => ['name' => '13. ตรวจสอบยาปิดใช้งาน (DRUG_INACTIVE)', 'status' => 'PENDING'],
            ];

            $mockServer = new AgentMockApiServer();
            $adapterCallable = function(string $key, string $payload, array $headers) use ($mockServer) {
                return $mockServer->handleRequest($key, $payload, $headers);
            };
            $validKey = 'key_secret_01425_abc123';
            $client = new AgentApiClient('mock://local', $validKey, 15, $adapterCallable);

            $sample = [
                [
                    'hos_guid'      => '{GUID-TEST-SCENARIO-01}',
                    'vn'            => 'VN001',
                    'hcode'         => '01425',
                    'vstdate'       => date('Y-m-d'),
                    'tpu_code_list' => '100789000012345678901001',
                    'qty'           => 1,
                ]
            ];

            // 1. 200 OK
            $r1 = $client->sendDispenseBatch('01425', $sample);
            $scenarios['200_ok']['http_code'] = $r1['http_code'];
            $scenarios['200_ok']['pass'] = ($r1['http_code'] === 200 && $r1['success'] === true);
            $scenarios['200_ok']['detail'] = "Inserted/Updated: {$r1['inserted_or_updated']}, Sync Log ID: {$r1['sync_log_id']}";

            // 2. 400 Bad
            $r2 = $client->sendDispenseBatch('01425', [['vn' => 'VN1', 'hcode' => '01425', 'vstdate' => date('Y-m-d'), 'tpu_code_list' => '100789000012345678901001', 'qty' => 1]]);
            $scenarios['400_bad']['http_code'] = $r2['http_code'];
            $scenarios['400_bad']['pass'] = ($r2['http_code'] === 400);
            $scenarios['400_bad']['detail'] = $r2['error'];

            // 3. 401 Auth
            $cBad = new AgentApiClient('mock://local', 'wrong_key', 15, $adapterCallable);
            $r3 = $cBad->sendDispenseBatch('01425', $sample);
            $scenarios['401_auth']['http_code'] = $r3['http_code'];
            $scenarios['401_auth']['pass'] = ($r3['http_code'] === 401);
            $scenarios['401_auth']['detail'] = $r3['error'];

            // 4. 403 Cross-tenant
            $r4 = $client->sendDispenseBatch('01426', [['hos_guid' => '{G1}', 'vn' => 'VN1', 'hcode' => '01426', 'vstdate' => date('Y-m-d'), 'tpu_code_list' => '100789000012345678901001', 'qty' => 1]], ['skip_client_validation' => true]);
            $scenarios['403_cross']['http_code'] = $r4['http_code'];
            $scenarios['403_cross']['pass'] = ($r4['http_code'] === 403);
            $scenarios['403_cross']['detail'] = $r4['error'];

            // 5. 409 Conflict
            $r5 = $client->sendDispenseBatch('01425', $sample, ['mock_scenario' => 'force_409']);
            $scenarios['409_conflict']['http_code'] = $r5['http_code'];
            $scenarios['409_conflict']['pass'] = ($r5['http_code'] === 409);
            $scenarios['409_conflict']['detail'] = $r5['error'];

            // 6. 429 Rate Limit
            $r6 = $client->sendDispenseBatch('01425', $sample, ['mock_scenario' => 'force_429']);
            $scenarios['429_ratelimit']['http_code'] = $r6['http_code'];
            $scenarios['429_ratelimit']['pass'] = ($r6['http_code'] === 429);
            $scenarios['429_ratelimit']['detail'] = $r6['error'] . " (Retry-After: " . ($r6['retry_after'] ?? 'N/A') . "s)";

            // 7. 500 Error
            $r7 = $client->sendDispenseBatch('01425', $sample, ['mock_scenario' => 'force_500']);
            $scenarios['500_error']['http_code'] = $r7['http_code'];
            $scenarios['500_error']['pass'] = ($r7['http_code'] === 500);
            $scenarios['500_error']['detail'] = $r7['error'];

            // 8. Timeout & Connection Refused
            $r8a = $client->sendDispenseBatch('01425', $sample, ['mock_scenario' => 'force_timeout']);
            $r8b = $client->sendDispenseBatch('01425', $sample, ['mock_scenario' => 'force_connection_refused']);
            $scenarios['timeout_refused']['http_code'] = "504 / 0";
            $scenarios['timeout_refused']['pass'] = ($r8a['http_code'] === 504 && !empty($r8b['is_connection_refused']));
            $scenarios['timeout_refused']['detail'] = "Gateway Timeout (504) and Connection Refused handled gracefully";

            // 9. Malformed JSON
            $r9 = $client->sendDispenseBatch('01425', $sample, ['mock_scenario' => 'force_malformed_json']);
            $scenarios['malformed_json']['http_code'] = $r9['http_code'];
            $scenarios['malformed_json']['pass'] = ($r9['error_code'] === 'MALFORMED_RESPONSE_JSON');
            $scenarios['malformed_json']['detail'] = $r9['error'];

            // 10. Key safety
            $masked = AgentApiClient::maskApiKey('SUPER_KEY_12345');
            $scenarios['key_safety']['http_code'] = 'N/A';
            $scenarios['key_safety']['pass'] = ($masked === 'SUPE...2345');
            $scenarios['key_safety']['detail'] = "Mask format: {$masked} (No plain key in logs/errors)";

            // 11. PII blocked
            $r11 = $client->sendDispenseBatch('01425', [['hos_guid' => '{G1}', 'vn' => 'VN1', 'hn' => '12345', 'hcode' => '01425', 'vstdate' => date('Y-m-d'), 'tpu_code_list' => '100789000012345678901001', 'qty' => 1]]);
            $scenarios['pii_blocked']['http_code'] = 400;
            $scenarios['pii_blocked']['pass'] = (!empty($r11['client_rejected']));
            $scenarios['pii_blocked']['detail'] = $r11['error'];

            // 12. Idempotence
            $mockServer->clearStore();
            $r12_1 = $client->sendDispenseBatch('01425', $sample);
            $r12_2 = $client->sendDispenseBatch('01425', $sample);
            $countAfter = count($mockServer->getDispenseStore());
            $scenarios['idempotence']['http_code'] = 200;
            $scenarios['idempotence']['pass'] = ($countAfter === 1);
            $scenarios['idempotence']['detail'] = "Sent 1 record twice -> Total rows in DB: {$countAfter} (No duplicate)";

            // 13. Drug Inactive
            $r13 = $client->sendDispenseBatch('01425', [['hos_guid' => '{G_INACTIVE}', 'vn' => 'VN1', 'hcode' => '01425', 'vstdate' => date('Y-m-d'), 'tpu_code_list' => '100789000012345678901004', 'qty' => 1]]);
            $scenarios['drug_inactive']['http_code'] = 200;
            $scenarios['drug_inactive']['pass'] = ($r13['failed'] === 1 && ($r13['errors'][0]['error_code'] ?? '') === 'DRUG_INACTIVE');
            $scenarios['drug_inactive']['detail'] = "Blocked with DRUG_INACTIVE reason: " . ($r13['errors'][0]['reason'] ?? '');

            echo json_encode([
                'success' => true,
                'data'    => $scenarios,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ======================================================================
        // PHASE 3.6: Scheduler, Retry Policy & Reconciliation (Mock Mode)
        // ======================================================================
        case 'phase36_scheduler_status':
            $mockTime = !empty($input['mock_time']) ? trim($input['mock_time']) : null;
            $clock = new AgentMockClock($mockTime);
            $scheduler = new AgentScheduler($clock);

            $summary = $scheduler->getStatusSummary();

            // ตรวจสอบ missed slots ย้อนหลัง 24 ชม.
            $since = $clock->now()->modify('-24 hours');
            $missed = $scheduler->detectMissedSlots($since, $clock->now());

            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'missed_slots' => $missed,
                'current_clock' => $clock->format('Y-m-d H:i:s'),
                'timezone' => 'Asia/Bangkok (+07:00)',
                'preview_only' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'phase36_trigger_slot':
            $mockTime = !empty($input['mock_time']) ? trim($input['mock_time']) : null;
            $clock = new AgentMockClock($mockTime);
            $scheduler = new AgentScheduler($clock);

            $result = $scheduler->triggerSlotRun(function($slot, $key, $time) {
                return [
                    'message' => "จำลองการทำงานรอบ {$slot} น. สำเร็จ (Mock Engine)",
                    'timestamp' => $time->format('Y-m-d H:i:s'),
                ];
            });

            echo json_encode([
                'success' => true,
                'data' => $result,
                'summary' => $scheduler->getStatusSummary(),
                'preview_only' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'phase36_run_retry_demo':
            $scenario = $input['scenario'] ?? 'transient_success';
            $clock = new AgentMockClock('2026-10-28 09:00:00');

            // กำหนด Response Sequence ตาม Scenario
            switch ($scenario) {
                case 'transient_success':
                    // 500 -> 429 (Retry-After: 2) -> 200 OK
                    $responses = [
                        ['http_code' => 500, 'body' => ['error_code' => 'INTERNAL_SERVER_ERROR', 'message' => 'Simulated backend crash (Attempt 1)']],
                        ['http_code' => 429, 'body' => ['error_code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Rate limited (Attempt 2)', 'retry_after' => 2]],
                        ['http_code' => 200, 'body' => ['success' => true, 'hcode' => '01425', 'inserted_or_updated' => 5, 'sync_log_id' => 9999]],
                    ];
                    break;

                case 'permanent_fail':
                    // 401 Unauthorized -> ไม่ retry ทันที
                    $responses = [
                        ['http_code' => 401, 'body' => ['error_code' => 'UNAUTHORIZED', 'message' => 'Invalid Health Center API Key']],
                    ];
                    break;

                case 'rate_limit_retry_after':
                    // 429 with Retry-After: 5 -> 200 OK
                    $responses = [
                        ['http_code' => 429, 'body' => ['error_code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too Many Requests', 'retry_after' => 5]],
                        ['http_code' => 200, 'body' => ['success' => true, 'hcode' => '01425', 'inserted_or_updated' => 3]],
                    ];
                    break;

                case 'exhausted':
                default:
                    // 503 x 4 -> ครบโควตาแล้วหยุด
                    $responses = [
                        ['http_code' => 503, 'body' => ['error_code' => 'SERVICE_UNAVAILABLE', 'message' => 'Gateway overloaded (1)']],
                        ['http_code' => 503, 'body' => ['error_code' => 'SERVICE_UNAVAILABLE', 'message' => 'Gateway overloaded (2)']],
                        ['http_code' => 503, 'body' => ['error_code' => 'SERVICE_UNAVAILABLE', 'message' => 'Gateway overloaded (3)']],
                        ['http_code' => 503, 'body' => ['error_code' => 'SERVICE_UNAVAILABLE', 'message' => 'Gateway overloaded (4)']],
                    ];
                    break;
            }

            $transport = new AgentMockTransport($responses);
            $policy = new AgentRetryPolicy(4, 1.0, 30.0, 2.0, 0.1, $clock);

            $result = $policy->executeWithRetry(function($attempt) use ($transport) {
                [$code, $body] = $transport->send('/rpc/sync', ['Authorization' => 'Bearer secret_key'], '{"hcode":"01425"}');
                return [
                    'http_code'   => $code,
                    'success'     => ($code === 200),
                    'retry_after' => $body['retry_after'] ?? null,
                    'data'        => $body
                ];
            });

            echo json_encode([
                'success'  => true,
                'scenario' => $scenario,
                'result'   => $result,
                'preview_only' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'phase36_reconciliation_preview':
            $completeness = $input['completeness'] ?? 'completed';
            $hcodeTarget = $input['hcode'] ?? '01425';

            // ชุดข้อมูลตัวอย่างปลายทางจำลอง (Cloud Store)
            $cloudStore = [
                "{$hcodeTarget}:{GUID-REC-UNCHANGED}" => [
                    'hcode' => $hcodeTarget, 'hos_guid' => '{GUID-REC-UNCHANGED}', 'vn' => 'VN001',
                    'vstdate' => date('Y-m-d', strtotime('-5 days')), 'tpu_code_list' => '100789000012345678901001', 'qty' => 10, 'is_deleted' => false
                ],
                "{$hcodeTarget}:{GUID-REC-CHANGED}" => [
                    'hcode' => $hcodeTarget, 'hos_guid' => '{GUID-REC-CHANGED}', 'vn' => 'VN002',
                    'vstdate' => date('Y-m-d', strtotime('-4 days')), 'tpu_code_list' => '100789000012345678901001', 'qty' => 5, 'is_deleted' => false
                ],
                "{$hcodeTarget}:{GUID-REC-MISSING-CANCEL}" => [
                    'hcode' => $hcodeTarget, 'hos_guid' => '{GUID-REC-MISSING-CANCEL}', 'vn' => 'VN003',
                    'vstdate' => date('Y-m-d', strtotime('-3 days')), 'tpu_code_list' => '100789000012345678901001', 'qty' => 2, 'is_deleted' => false
                ],
                "{$hcodeTarget}:{GUID-REC-REACTIVATED}" => [
                    'hcode' => $hcodeTarget, 'hos_guid' => '{GUID-REC-REACTIVATED}', 'vn' => 'VN004',
                    'vstdate' => date('Y-m-d', strtotime('-2 days')), 'tpu_code_list' => '100789000012345678901002', 'qty' => 1, 'is_deleted' => true, 'deleted_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
                ],
            ];

            // ชุดข้อมูลต้นทาง (HOSxP)
            $sourceRecords = [
                // Unchanged: เหมือนเดิมทุกอย่าง
                ['hcode' => $hcodeTarget, 'hos_guid' => '{GUID-REC-UNCHANGED}', 'vn' => 'VN001', 'vstdate' => date('Y-m-d', strtotime('-5 days')), 'tpu_code_list' => '100789000012345678901001', 'qty' => 10],
                // Changed: ปรับยอด qty จาก 5 เป็น 15
                ['hcode' => $hcodeTarget, 'hos_guid' => '{GUID-REC-CHANGED}', 'vn' => 'VN002', 'vstdate' => date('Y-m-d', strtotime('-4 days')), 'tpu_code_list' => '100789000012345678901001', 'qty' => 15],
                // Reactivated: รายการที่เดิมปลายทาง Soft Delete ไว้ แต่ต้นทางกลับมาพบอีกครั้ง
                ['hcode' => $hcodeTarget, 'hos_guid' => '{GUID-REC-REACTIVATED}', 'vn' => 'VN004', 'vstdate' => date('Y-m-d', strtotime('-2 days')), 'tpu_code_list' => '100789000012345678901002', 'qty' => 1],
                // New: รายการใหม่เพิ่งเพิ่ม
                ['hcode' => $hcodeTarget, 'hos_guid' => '{GUID-REC-NEW-01}', 'vn' => 'VN005', 'vstdate' => date('Y-m-d'), 'tpu_code_list' => '100789000012345678901003', 'qty' => 20],
                // สังเกต: ไม่มี GUID-REC-MISSING-CANCEL (จะกลายเป็น Soft Delete Candidate)
            ];

            $recon = new AgentReconciliation();
            $handshake = [
                'start_date' => date('Y-m-d', strtotime('-30 days')),
                'end_date'   => date('Y-m-d'),
                'source_query_status' => ($completeness === 'incomplete_timeout') ? 'TIMEOUT_FAILED' : 'COMPLETED',
                'expected_source_count' => ($completeness === 'truncated_count') ? 999 : count($sourceRecords),
            ];

            $reconResult = $recon->previewReconciliation($hcodeTarget, $sourceRecords, $cloudStore, $handshake);

            echo json_encode([
                'success' => true,
                'data'    => $reconResult,
                'completeness_mode' => $completeness,
                'preview_only' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        // ======================================================================
        // PHASE 3.7: MOPH Notify & Sync Status Monitoring (Mock Mode)
        // ======================================================================
        case 'phase37_get_status':
            $eventLog = new AgentSyncEventLog(__DIR__ . '/config/mock_sync_events.json');
            $monitor = new AgentSyncMonitor(__DIR__ . '/config/mock_sync_monitor_state.json', $eventLog);

            $filterStatus = !empty($input['filter_status']) ? trim($input['filter_status']) : null;
            $filterDate   = !empty($input['filter_date']) ? trim($input['filter_date']) : null;

            echo json_encode([
                'success'        => true,
                'status'         => $monitor->getCurrentStatus(),
                'cards'          => $monitor->getSummaryCards(),
                'history'        => $monitor->getHistory(20, $filterStatus, $filterDate),
                'recent_events'  => $eventLog->getRecentEvents(10),
                'preview_only'   => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'phase37_get_events':
            $eventLog = new AgentSyncEventLog(__DIR__ . '/config/mock_sync_events.json');
            $filter = [
                'date_from'  => !empty($input['date_from']) ? trim($input['date_from']) : null,
                'date_to'    => !empty($input['date_to']) ? trim($input['date_to']) : null,
                'severity'   => !empty($input['severity']) ? trim($input['severity']) : null,
                'event_type' => !empty($input['event_type']) ? trim($input['event_type']) : null,
                'search'     => !empty($input['search']) ? trim($input['search']) : null,
                'session_id' => !empty($input['session_id']) ? trim($input['session_id']) : null,
            ];
            $limit  = isset($input['limit']) ? (int)$input['limit'] : 50;
            $offset = isset($input['offset']) ? (int)$input['offset'] : 0;

            $eventsData = $eventLog->getEvents($filter, $limit, $offset);
            $eventsData['stats'] = $eventLog->getStats();

            echo json_encode([
                'success'      => true,
                'data'         => $eventsData,
                'preview_only' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'phase37_simulate_sync_cycle':
            $scenario   = $input['scenario'] ?? 'success';
            $syncType   = $input['sync_type'] ?? 'INCREMENTAL_SYNC';
            $slotKey    = !empty($input['slot_key']) ? trim($input['slot_key']) : date('Y-m-d') . '@09:00';
            $isPreview  = isset($input['is_preview']) ? (bool)$input['is_preview'] : true;
            $hcode      = trim($input['hcode'] ?? '01425');

            $eventLog = new AgentSyncEventLog(__DIR__ . '/config/mock_sync_events.json');
            $monitor = new AgentSyncMonitor(__DIR__ . '/config/mock_sync_monitor_state.json', $eventLog);

            // 1. เริ่ม Session
            $sessionId = $monitor->startSession($syncType, $slotKey, $isPreview);

            // 2. จำลองขั้นตอนตาม Scenario
            switch ($scenario) {
                case 'success':
                    $monitor->recordExtractionCompleted(12, 12, 0, 0);
                    $monitor->recordReconciliation('COMPLETED', 'COMPLETED', ['checked_items' => 12]);
                    $finalStatus = $isPreview ? AgentSyncMonitor::STATUS_PREVIEW_ONLY : AgentSyncMonitor::STATUS_SUCCESS;
                    $completedSession = $monitor->completeSession($finalStatus, [
                        'items_sent_success' => 12,
                        'items_duplicate'    => 0,
                        'items_failed'       => 0,
                    ]);
                    $notifyScenario = AgentMophNotifyMockAdapter::SCENARIO_SUCCESS;
                    break;

                case 'partial_success':
                    $monitor->recordExtractionCompleted(15, 12, 3, 0);
                    $monitor->recordRetry(1, 'TRANSIENT_SERVER_ERROR');
                    $monitor->recordReconciliation('COMPLETED', 'COMPLETED', ['checked_items' => 15]);
                    $completedSession = $monitor->completeSession(AgentSyncMonitor::STATUS_PARTIAL_SUCCESS, [
                        'items_sent_success' => 12,
                        'items_duplicate'    => 0,
                        'items_failed'       => 3,
                        'error_category'     => 'TRANSIENT_SERVER_ERROR',
                    ]);
                    $notifyScenario = AgentMophNotifyMockAdapter::SCENARIO_SUCCESS;
                    break;

                case 'failed':
                    $monitor->recordExtractionCompleted(10, 0, 10, 0);
                    $monitor->recordRetry(4, 'PERMANENT_UNAUTHORIZED');
                    $monitor->recordReconciliation('FAILED', 'TIMEOUT_FAILED', ['error' => 'Connection refused']);
                    $completedSession = $monitor->completeSession(AgentSyncMonitor::STATUS_FAILED, [
                        'items_sent_success' => 0,
                        'items_duplicate'    => 0,
                        'items_failed'       => 10,
                        'error_category'     => 'PERMANENT_UNAUTHORIZED',
                    ]);
                    $notifyScenario = AgentMophNotifyMockAdapter::SCENARIO_FAILURE;
                    break;

                case 'preview_only':
                default:
                    $monitor->recordExtractionCompleted(8, 8, 0, 0);
                    $monitor->recordReconciliation('PREVIEW_ONLY', 'COMPLETED', ['preview_rows' => 8]);
                    $completedSession = $monitor->completeSession(AgentSyncMonitor::STATUS_PREVIEW_ONLY, [
                        'items_sent_success' => 8,
                        'items_duplicate'    => 0,
                        'items_failed'       => 0,
                    ]);
                    $notifyScenario = AgentMophNotifyMockAdapter::SCENARIO_SUCCESS;
                    break;
            }

            // 3. จำลอง MOPH Notify
            $config = ['hcode' => $hcode];
            $messageData = AgentMophNotifyMessageBuilder::build($completedSession, $config);

            $adapter = new AgentMophNotifyMockAdapter($notifyScenario);
            $notifyResult = $adapter->notify($messageData['plain_text']);

            // 4. บันทึกผลการแจ้งเตือนกลับเข้าสู่ Monitor
            $monitor->recordNotification($notifyResult);

            echo json_encode([
                'success'        => true,
                'session'        => $completedSession,
                'notify_preview' => $messageData,
                'notify_result'  => $notifyResult,
                'cards'          => $monitor->getSummaryCards(),
                'recent_events'  => $eventLog->getRecentEvents(10),
                'preview_only'   => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'phase37_simulate_notify':
            $scenario = $input['scenario'] ?? 'success';
            $hcode    = trim($input['hcode'] ?? '01425');
            $customMsg = $input['custom_text'] ?? null;

            $eventLog = new AgentSyncEventLog(__DIR__ . '/config/mock_sync_events.json');

            if (!empty($customMsg)) {
                $inspection = AgentMophNotifyMessageBuilder::inspectAndSanitizeMessage($customMsg, false);
                $messageData = [
                    'title'           => "🔔 MOPH Notify [จำลองแบบกำหนดเอง]",
                    'body'            => $inspection['sanitized_text'],
                    'plain_text'      => $inspection['sanitized_text'],
                    'facility_name'   => AgentMophNotifyMessageBuilder::DEFAULT_FACILITY_NAMES[$hcode] ?? "รพ.สต. {$hcode}",
                    'hcode'           => $hcode,
                    'is_simulated'    => true,
                    'contains_pii'    => $inspection['detected_pii'],
                    'has_masked_data' => $inspection['has_masked_data'],
                    'status'          => 'CUSTOM',
                ];
            } else {
                $dummySession = [
                    'session_id'         => 'sync_demo_' . bin2hex(random_bytes(3)),
                    'status'             => ($scenario === 'failure') ? 'FAILED' : 'SUCCESS',
                    'sync_type'          => 'INCREMENTAL_SYNC',
                    'start_time'         => date('Y-m-d H:i:s', strtotime('-2 minutes')),
                    'end_time'           => date('Y-m-d H:i:s'),
                    'duration_sec'       => 1.25,
                    'items_read'         => 10,
                    'items_validated'    => 10,
                    'items_sent_success' => ($scenario === 'failure') ? 0 : 10,
                    'items_duplicate'    => 0,
                    'items_failed'       => ($scenario === 'failure') ? 10 : 0,
                    'retry_count'        => ($scenario === 'failure') ? 4 : 0,
                    'error_category'     => ($scenario === 'failure') ? 'TRANSIENT_SERVER_ERROR' : null,
                    'scheduler_slot'     => date('Y-m-d') . '@09:00',
                ];
                $messageData = AgentMophNotifyMessageBuilder::build($dummySession, ['hcode' => $hcode]);
            }

            $adapter = new AgentMophNotifyMockAdapter($scenario);
            if ($scenario === 'missing_config') {
                $adapter->setMockToken(null);
            }

            $notifyResult = $adapter->notify($messageData['plain_text'], [
                'reject_if_confidential' => ($scenario === 'confidential_rejected'),
            ]);

            // บันทึก Event
            $eventLog->record(
                AgentSyncEvent::NOTIFY_SIMULATED,
                "จำลองการส่ง MOPH Notify (โหมด {$scenario}): " . ($notifyResult['success'] ? 'SUCCESS' : 'FAILED/REJECTED'),
                $notifyResult['success'] ? AgentSyncEvent::SEVERITY_INFO : AgentSyncEvent::SEVERITY_WARNING,
                $notifyResult
            );

            echo json_encode([
                'success'        => true,
                'message_data'   => $messageData,
                'notify_result'  => $notifyResult,
                'recent_events'  => $eventLog->getRecentEvents(10),
                'preview_only'   => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            break;

        case 'phase37_reset_monitor':
            $eventLog = new AgentSyncEventLog(__DIR__ . '/config/mock_sync_events.json');
            $monitor = new AgentSyncMonitor(__DIR__ . '/config/mock_sync_monitor_state.json', $eventLog);

            $reset = $monitor->resetState();
            writeAgentLog(date('Y-m-d H:i:s') . " | Phase 3.7 Monitor State & Event Log Reset");

            echo json_encode([
                'success' => $reset,
                'message' => 'รีเซ็ตสถานะ Monitor และ Event Log เรียบร้อยแล้ว',
            ]);
            break;

        case 'phase37_clear_events':
            $eventLog = new AgentSyncEventLog(__DIR__ . '/config/mock_sync_events.json');
            $cleared = $eventLog->clear();

            echo json_encode([
                'success' => $cleared,
                'message' => 'ล้างประวัติ Event Log เรียบร้อยแล้ว',
            ]);
            break;

        // ======================================================================
        // PRODUCTION MODE ACTIONS (เชื่อมต่อ HOSxP Read-only & Supabase Cloud จริง)
        // ======================================================================
        case 'prod_bootstrap':
            $prod = new AgentProductionService($configManager);
            echo json_encode([
                'success' => true,
                'data'    => $prod->getBootstrapData(),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_save_settings':
            $existing = $configManager->hasConfig() ? $configManager->load() : [];
            $toSave = [
                'hcode'               => trim((string)($input['hcode'] ?? ($existing['hcode'] ?? ''))),
                'facility_name'       => trim((string)($input['facility_name'] ?? ($existing['facility_name'] ?? ''))),
                'hosxp_host'          => trim((string)($input['hosxp_host'] ?? ($existing['hosxp_host'] ?? '127.0.0.1'))),
                'hosxp_port'          => (int)($input['hosxp_port'] ?? ($existing['hosxp_port'] ?? 3306)),
                'hosxp_database'      => trim((string)($input['hosxp_database'] ?? ($existing['hosxp_database'] ?? 'hosxp_pcu'))),
                'hosxp_username'      => trim((string)($input['hosxp_username'] ?? ($existing['hosxp_username'] ?? 'rxcore_ro'))),
                'hosxp_password'      => ($input['hosxp_password'] ?? '') !== '' ? (string)$input['hosxp_password'] : ($existing['hosxp_password'] ?? ''),
                'supabase_url'        => rtrim(trim((string)($input['supabase_url'] ?? ($existing['supabase_url'] ?? ''))), '/'),
                'supabase_anon_key'   => ($input['supabase_anon_key'] ?? '') !== '' ? trim((string)$input['supabase_anon_key']) : ($existing['supabase_anon_key'] ?? ''),
                'sync_api_key'        => ($input['sync_api_key'] ?? '') !== '' ? trim((string)$input['sync_api_key']) : ($existing['sync_api_key'] ?? ''),
                'central_web_url'     => rtrim(trim((string)($input['central_web_url'] ?? ($existing['central_web_url'] ?? ''))), '/'),
                'auto_sync_enabled'   => isset($input['auto_sync_enabled']) ? (bool)$input['auto_sync_enabled'] : (bool)($existing['auto_sync_enabled'] ?? true),
                'sync_slots'             => is_array($input['sync_slots'] ?? null) ? array_values(array_filter(array_map('strval', $input['sync_slots']))) : ($existing['sync_slots'] ?? ['09:00', '14:00', '17:00']),
                'moph_notify_enabled'    => isset($input['moph_notify_enabled']) ? (bool)$input['moph_notify_enabled'] : (bool)($existing['moph_notify_enabled'] ?? false),
                'moph_notify_on_manual'  => isset($input['moph_notify_on_manual']) ? (bool)$input['moph_notify_on_manual'] : (bool)($existing['moph_notify_on_manual'] ?? false),
                'moph_client_key'        => ($input['moph_client_key'] ?? '') !== '' ? trim((string)$input['moph_client_key']) : ($existing['moph_client_key'] ?? ''),
                'moph_secret_key'        => ($input['moph_secret_key'] ?? '') !== '' ? trim((string)$input['moph_secret_key']) : ($existing['moph_secret_key'] ?? ''),
                'agent_mode'             => 'real',
            ];

            if ($toSave['hcode'] !== '' && !preg_match('/^\d{5}$/', $toSave['hcode'])) {
                echo json_encode([
                    'success' => false,
                    'error'   => 'รหัสหน่วยบริการ (HCODE) ต้องเป็นตัวเลข 5 หลักเท่านั้น',
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            $saved = $configManager->save($toSave);
            writeAgentLog(sprintf(
                '%s | Production settings saved: hcode=%s, host=%s:%d, db=%s, user=%s',
                date('Y-m-d H:i:s'),
                $toSave['hcode'] ?: '-',
                $toSave['hosxp_host'],
                $toSave['hosxp_port'],
                $toSave['hosxp_database'],
                $toSave['hosxp_username']
            ));

            $prod = new AgentProductionService($configManager);
            echo json_encode([
                'success' => $saved,
                'message' => $saved ? 'บันทึกการตั้งค่าเรียบร้อยแล้ว (เข้ารหัสคีย์ลับด้วย AES-256-GCM)' : 'บันทึกการตั้งค่าไม่สำเร็จ',
                'config'  => $prod->getBootstrapData()['config'],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_test_hosxp':
            $prod = new AgentProductionService($configManager);
            $cfg = $prod->resolveEffectiveConfig([
                'hosxp_host'     => isset($input['hosxp_host']) ? trim((string)$input['hosxp_host']) : null,
                'hosxp_port'     => isset($input['hosxp_port']) ? (int)$input['hosxp_port'] : null,
                'hosxp_database' => isset($input['hosxp_database']) ? trim((string)$input['hosxp_database']) : null,
                'hosxp_username' => isset($input['hosxp_username']) ? trim((string)$input['hosxp_username']) : null,
                'hosxp_password' => !empty($input['hosxp_password']) ? (string)$input['hosxp_password'] : null,
                'hcode'          => isset($input['hcode']) ? trim((string)$input['hcode']) : null,
            ]);
            $res = $prod->testHosxpConnection($cfg);
            writeAgentLog(sprintf(
                '%s | HOSxP Read-only Check [%s:%s/%s] -> %s',
                date('Y-m-d H:i:s'),
                $cfg['hosxp_host'] ?? '',
                $cfg['hosxp_port'] ?? '',
                $cfg['hosxp_database'] ?? '',
                $res['success'] ? 'PASS' : ('FAIL: ' . ($res['error'] ?? ''))
            ));
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_test_cloud':
            $prod = new AgentProductionService($configManager);
            $cfg = $prod->resolveEffectiveConfig([
                'supabase_url'      => !empty($input['supabase_url']) ? trim((string)$input['supabase_url']) : null,
                'supabase_anon_key' => !empty($input['supabase_anon_key']) ? trim((string)$input['supabase_anon_key']) : null,
                'sync_api_key'      => !empty($input['sync_api_key']) ? trim((string)$input['sync_api_key']) : null,
                'hcode'             => !empty($input['hcode']) ? trim((string)$input['hcode']) : null,
            ]);
            $res = $prod->testCloudConnection($cfg);
            writeAgentLog(sprintf(
                '%s | Supabase Cloud Check [hcode=%s] -> %s',
                date('Y-m-d H:i:s'),
                $cfg['hcode'] ?: '-',
                $res['success'] ? 'PASS' : ('FAIL: ' . ($res['error'] ?? ''))
            ));
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_test_moph_notify':
            $prod = new AgentProductionService($configManager);
            $cfg = $prod->resolveEffectiveConfig([
                'hcode'           => !empty($input['hcode']) ? trim((string)$input['hcode']) : null,
                'facility_name'   => !empty($input['facility_name']) ? trim((string)$input['facility_name']) : null,
                'moph_client_key' => !empty($input['moph_client_key']) ? trim((string)$input['moph_client_key']) : null,
                'moph_secret_key' => !empty($input['moph_secret_key']) ? trim((string)$input['moph_secret_key']) : null,
            ]);
            $hcLabel = trim((string)($cfg['facility_name'] ?? '')) ?: ('หน่วยบริการ ' . ($cfg['hcode'] ?: '-'));
            $testMsg = "🔔 ทดสอบการเชื่อมต่อ MOPH Notify\n{$hcLabel} (HCODE: " . ($cfg['hcode'] ?: '-') . ")\nเวลาทดสอบ: " . date('d/m/Y H:i:s') . ' น.';
            $res = $prod->sendMophNotify(
                $testMsg,
                $cfg['moph_client_key'] ?? null,
                $cfg['moph_secret_key'] ?? null
            );
            writeAgentLog(sprintf(
                '%s | MOPH Notify Test [hcode=%s] -> %s',
                date('Y-m-d H:i:s'),
                $cfg['hcode'] ?: '-',
                $res['ok'] ? "PASS (HTTP {$res['status']})" : ('FAIL: ' . $res['error'])
            ));
            echo json_encode([
                'success' => $res['ok'],
                'status'  => $res['status'],
                'message' => $res['ok'] ? "ส่งข้อความทดสอบเข้า MOPH Notify สำเร็จ (HTTP {$res['status']})" : $res['error'],
                'error'   => $res['error'],
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_refresh_drugs':
            $prod = new AgentProductionService($configManager);
            $cfg = $prod->resolveEffectiveConfig();
            $res = $prod->refreshDrugRegistry($cfg);
            writeAgentLog(date('Y-m-d H:i:s') . ' | Drug registry refreshed: count=' . ($res['count'] ?? 0));
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_preview':
            @set_time_limit(0);
            $prod = new AgentProductionService($configManager);
            $cfg = $prod->resolveEffectiveConfig();
            $startDate = trim((string)($input['start_date'] ?? date('Y-m-d')));
            $endDate   = trim((string)($input['end_date'] ?? date('Y-m-d')));
            $res = $prod->extractRealRecords($cfg, $startDate, $endDate);
            if ($res['success']) {
                // ไม่ส่ง raw records ทั้งก้อนกลับไปซ้ำกับ display_rows เพื่อประหยัดแบนด์วิดท์หน้าเว็บ
                unset($res['records']);
                writeAgentLog(sprintf(
                    '%s | Preview Extract [%s..%s, hcode=%s]: Read=%d, Valid=%d, Dups=%d',
                    date('Y-m-d H:i:s'),
                    $startDate,
                    $endDate,
                    $res['hcode'],
                    $res['total_read'],
                    $res['total_passed'],
                    $res['duplicates_pruned']
                ));
            } else {
                writeAgentLog(date('Y-m-d H:i:s') . ' | Preview Extract Failed: ' . ($res['error'] ?? ''));
            }
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_sync_now':
            @set_time_limit(0);
            $prod = new AgentProductionService($configManager);
            $cfg = $prod->resolveEffectiveConfig();
            $startDate = trim((string)($input['start_date'] ?? date('Y-m-d')));
            $endDate   = trim((string)($input['end_date'] ?? date('Y-m-d')));
            $reconcile = !empty($input['reconcile']);
            $res = $prod->syncNow($cfg, $startDate, $endDate, $reconcile);
            $notifyRes = $prod->notifySyncResult($cfg, $startDate, $endDate, $res, false);
            $res['moph_notify'] = $notifyRes;
            writeAgentLog(sprintf(
                '%s | Cloud Sync [%s..%s, hcode=%s, reconcile=%s] -> %s%s',
                date('Y-m-d H:i:s'),
                $startDate,
                $endDate,
                $cfg['hcode'] ?: '-',
                $reconcile ? 'yes' : 'no',
                $res['success'] ? ($res['message'] ?? 'SUCCESS') : ('FAILED: ' . ($res['error'] ?? '')),
                empty($notifyRes['skipped']) ? ($notifyRes['ok'] ? ' | MOPH Notify: SENT' : ' | MOPH Notify: FAIL (' . $notifyRes['error'] . ')') : ''
            ));
            $res['history'] = $prod->getSyncHistory(30);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_clear_history':
            $prod = new AgentProductionService($configManager);
            $prod->clearSyncHistory();
            writeAgentLog(date('Y-m-d H:i:s') . ' | Sync history cleared');
            echo json_encode(['success' => true, 'message' => 'ล้างประวัติการซิงค์เรียบร้อยแล้ว'], JSON_UNESCAPED_UNICODE);
            break;

        case 'prod_clear_logs':
            $logDir = __DIR__ . '/logs';
            if (is_dir($logDir)) {
                foreach (glob($logDir . '/agent_*.log') ?: [] as $lf) {
                    @unlink($lf);
                }
            }
            echo json_encode(['success' => true, 'message' => 'ล้างไฟล์บันทึกระบบเรียบร้อยแล้ว'], JSON_UNESCAPED_UNICODE);
            break;


        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => "Unknown action: {$action}",
            ]);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    // ห้ามแสดงรายละเอียด Exception ที่อาจมี Credential
    $safeMessage = preg_replace('/password.*/i', 'password [REDACTED]', $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => 'เกิดข้อผิดพลาดภายในระบบ: ' . $safeMessage,
    ]);
    writeAgentLog(date('Y-m-d H:i:s') . " | ERROR: " . $safeMessage);
}

/**
 * เขียน Log ลงไฟล์ (ห้ามบันทึก Password)
 */
function writeAgentLog(string $message): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0700, true);
        file_put_contents($logDir . '/.htaccess', "Deny from all\n");
    }
    $logFile = $logDir . '/agent_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $message . "\n", FILE_APPEND);
}
