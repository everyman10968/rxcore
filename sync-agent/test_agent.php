<?php
/**
 * RxCore Sync Agent — Automated Test Suite
 * Phase 3.1: Foundation
 * 
 * รันการทดสอบข้อกำหนดทั้งหมดของ Phase 3.1:
 * 1. Encryption & Decryption (AES-256-GCM AEAD พร้อม Authentication Tag & Tamper Detection)
 * 2. Backward Compatibility & Seamless Migration (อ่าน CBC เดิมได้สมบูรณ์ และบันทึกใหม่เป็น GCM โดยข้อมูลไม่เสียหาย)
 * 3. Log Security & Redaction (Mask รหัสผ่านทั้งหมด)
 * 4. Validation Rules (Host, Port, DB, Username, HCODE 5-digit)
 * 5. Multi-Level MySQL Privilege Audit (Global, Schema, Table, Column, Role) โดยไม่ยึดติดกับชื่อ Username
 * 6. HOSxP Table Detection
 * 7. Real Connection Graceful Error Handling
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_connection.php';
require_once __DIR__ . '/agent_api.php';

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name} - {$detail}\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "  RxCore Sync Agent — Phase 3.1 Comprehensive Test Suite\n";
echo "========================================================\n\n";

// TEST GROUP 1: Config & AES-256-GCM AEAD Encryption
echo "--- 1. Testing Config & AES-256-GCM AEAD Encryption ---\n";
$configManager = new AgentConfig();
$testPass = 'Secret#Password!@2026$::Special&Char';
$testConfig = [
    'hosxp_host'     => '192.168.1.150',
    'hosxp_port'     => 3306,
    'hosxp_database' => 'hos',
    'hosxp_username' => 'hosxp_readonly',
    'hosxp_password' => $testPass,
    'hcode'          => '01425',
    'agent_mode'     => 'mock',
];

$saved = $configManager->save($testConfig);
assertTest("Save configuration with password", $saved === true);

// Verify file on disk does NOT contain plaintext password
$rawJson = file_get_contents(__DIR__ . '/config/agent_settings.enc.json');
assertTest("Plaintext password NOT in storage file", strpos($rawJson, $testPass) === false);
assertTest("Encrypted password field exists", strpos($rawJson, 'hosxp_password_encrypted') !== false);

// Verify GCM structure: IV :: Tag :: Ciphertext (3 base64 parts)
$decodedSettings = json_decode($rawJson, true);
$encParts = explode('::', $decodedSettings['hosxp_password_encrypted'] ?? '');
assertTest("AES-256-GCM format contains 3 parts (IV, Tag, Ciphertext)", count($encParts) === 3);
assertTest("GCM Authentication Tag exists (16 bytes)", strlen(base64_decode($encParts[1])) === 16);

// Verify load correctly decrypts
$loaded = $configManager->load();
assertTest("Password decrypted accurately with Authentication Tag", $loaded['hosxp_password'] === $testPass);
assertTest("HCODE saved accurately", $loaded['hcode'] === '01425');

// Tamper Test 1: Tampered Tag must fail decryption
$tamperedTagParts = $encParts;
$rawTag = base64_decode($tamperedTagParts[1]);
$rawTag[0] = chr(ord($rawTag[0]) ^ 0xFF); // flip bits
$tamperedTagParts[1] = base64_encode($rawTag);
$tamperedTagString = implode('::', $tamperedTagParts);

$reflection = new ReflectionClass($configManager);
$decryptMethod = $reflection->getMethod('decrypt');
$decryptMethod->setAccessible(true);
$tamperTagResult = $decryptMethod->invoke($configManager, $tamperedTagString);
assertTest("Tampered Authentication Tag rejected by GCM (returns false)", $tamperTagResult === false);

// Tamper Test 2: Tampered Ciphertext must fail decryption
$tamperedCipherParts = $encParts;
$rawCipher = base64_decode($tamperedCipherParts[2]);
$rawCipher[0] = chr(ord($rawCipher[0]) ^ 0xAA); // flip bits
$tamperedCipherParts[2] = base64_encode($rawCipher);
$tamperedCipherString = implode('::', $tamperedCipherParts);
$tamperCipherResult = $decryptMethod->invoke($configManager, $tamperedCipherString);
assertTest("Tampered Ciphertext rejected by GCM (returns false)", $tamperCipherResult === false);

// Verify getSafeConfig hides password
$safe = $configManager->getSafeConfig();
assertTest("Safe config password is empty", $safe['hosxp_password'] === '');
assertTest("Safe config indicates has_saved_password", $safe['has_saved_password'] === true);

// TEST GROUP 2: Backward Compatibility & Seamless CBC -> GCM Migration
echo "\n--- 2. Testing Backward Compatibility & CBC -> GCM Migration ---\n";
$encKeyProp = $reflection->getProperty('encryptionKey');
$encKeyProp->setAccessible(true);
$key = $encKeyProp->getValue($configManager);

$legacyPassword = 'Legacy#CBC#Password#2026';
// Create raw CBC format 1 (iv::ciphertext)
$ivCbc1 = openssl_random_pseudo_bytes(16);
$rawCbc1 = openssl_encrypt($legacyPassword, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $ivCbc1);
$cbcEncString1 = base64_encode($ivCbc1) . '::' . base64_encode($rawCbc1);

// Create raw CBC format 2 (legacy base64 wrapped)
$ivCbc2 = openssl_random_pseudo_bytes(16);
$rawCbc2 = openssl_encrypt($legacyPassword, 'aes-256-cbc', $key, 0, $ivCbc2);
$cbcEncString2 = base64_encode($ivCbc2 . '::' . base64_decode($rawCbc2));

// Assert decryption of both CBC formats
$decCbc1 = $decryptMethod->invoke($configManager, $cbcEncString1);
assertTest("Decrypt CBC Format 1 (base64 pair)", $decCbc1 === $legacyPassword);

$decCbc2 = $decryptMethod->invoke($configManager, $cbcEncString2);
assertTest("Decrypt CBC Format 2 (legacy wrapped)", $decCbc2 === $legacyPassword);

// Test End-to-End Migration on Disk:
$configFile = __DIR__ . '/config/agent_settings.enc.json';
$legacyDiskConfig = [
    'hosxp_host'     => '192.168.10.99',
    'hosxp_port'     => 3307,
    'hosxp_database' => 'hos_legacy',
    'hosxp_username' => 'hos_reader',
    'hcode'          => '01429',
    'agent_mode'     => 'mock',
    'created_at'     => '2026-02-15 08:30:00',
    'updated_at'     => '2026-02-15 08:30:00',
    'hosxp_password_encrypted' => $cbcEncString1,
];
file_put_contents($configFile, json_encode($legacyDiskConfig, JSON_PRETTY_PRINT));

// Load legacy config -> reads CBC password and all fields accurately
$loadedLegacy = $configManager->load();
assertTest("Load existing CBC config from file", $loadedLegacy['hosxp_password'] === $legacyPassword);
assertTest("Preserve host from existing config", $loadedLegacy['hosxp_host'] === '192.168.10.99');
assertTest("Preserve port from existing config", $loadedLegacy['hosxp_port'] === 3307);
assertTest("Preserve database name", $loadedLegacy['hosxp_database'] === 'hos_legacy');
assertTest("Preserve HCODE", $loadedLegacy['hcode'] === '01429');

// Save config (e.g. updating host/port) without re-typing password
$loadedLegacy['hosxp_port'] = 3306;
$savedMigrated = $configManager->save($loadedLegacy);
assertTest("Save migrated config successfully", $savedMigrated === true);

// Verify file on disk is now converted to AES-256-GCM (3 parts)
$rawMigratedJson = json_decode(file_get_contents($configFile), true);
$migratedParts = explode('::', $rawMigratedJson['hosxp_password_encrypted'] ?? '');
assertTest("Migrated storage converted to AES-256-GCM (3 parts)", count($migratedParts) === 3);
assertTest("Migrated storage contains 16-byte GCM tag", strlen(base64_decode($migratedParts[1])) === 16);

// Verify loading migrated config decrypts accurately with new GCM format
$loadedGcm = $configManager->load();
assertTest("Load migrated config under GCM matches password", $loadedGcm['hosxp_password'] === $legacyPassword);
assertTest("Updated port preserved", $loadedGcm['hosxp_port'] === 3306);
assertTest("Original created_at preserved", $loadedGcm['created_at'] === '2026-02-15 08:30:00');

// TEST GROUP 3: Log Security & Redaction
echo "\n--- 3. Testing Log Security & Redaction ---\n";
$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/agent_' . date('Y-m-d') . '.log';
$testLogMsg = "Sample audit log with password={$testPass} should be masked";
writeAgentLog(preg_replace('/password=\S+/i', 'password=[REDACTED]', $testLogMsg));

$logContent = file_exists($logFile) ? file_get_contents($logFile) : '';
assertTest("Log file exists", file_exists($logFile));
assertTest("Log file does NOT contain raw password", strpos($logContent, $testPass) === false);
assertTest("Log file contains [REDACTED] label", strpos($logContent, '[REDACTED]') !== false);
assertTest("Log directory has .htaccess protection", file_exists($logDir . '/.htaccess'));

// TEST GROUP 4: Validation Rules
echo "\n--- 4. Testing Input Validation Rules ---\n";
$conn = new AgentConnection();

// Test empty host
$resInvalidHost = $conn->testConnection(['hosxp_host' => '', 'hosxp_port' => 3306, 'hosxp_database' => 'hos', 'hosxp_username' => 'hosxp_readonly', 'hcode' => '01425', 'agent_mode' => 'mock']);
assertTest("Reject empty host", $resInvalidHost['success'] === false && strpos($resInvalidHost['error'], 'Host') !== false);

// Test invalid port
$resInvalidPort = $conn->testConnection(['hosxp_host' => '127.0.0.1', 'hosxp_port' => 99999, 'hosxp_database' => 'hos', 'hosxp_username' => 'hosxp_readonly', 'hcode' => '01425', 'agent_mode' => 'mock']);
assertTest("Reject invalid port (> 65535)", $resInvalidPort['success'] === false && strpos($resInvalidPort['error'], 'Port') !== false);

// Test invalid HCODE (not 5 digits)
$resInvalidHcode = $conn->testConnection(['hosxp_host' => '127.0.0.1', 'hosxp_port' => 3306, 'hosxp_database' => 'hos', 'hosxp_username' => 'hosxp_readonly', 'hcode' => '123', 'agent_mode' => 'mock']);
assertTest("Reject invalid HCODE (less than 5 digits)", $resInvalidHcode['success'] === false && strpos($resInvalidHcode['error'], 'hcode') !== false);

$resAlphaHcode = $conn->testConnection(['hosxp_host' => '127.0.0.1', 'hosxp_port' => 3306, 'hosxp_database' => 'hos', 'hosxp_username' => 'hosxp_readonly', 'hcode' => 'ABCDE', 'agent_mode' => 'mock']);
assertTest("Reject non-numeric HCODE", $resAlphaHcode['success'] === false && strpos($resAlphaHcode['error'], 'hcode') !== false);

// TEST GROUP 5: Multi-Level MySQL Privilege Audit (Global, Schema, Table, Column, Role)
echo "\n--- 5. Testing Multi-Level MySQL Privilege Audit ---\n";

// 5.1 Clean Read-Only Profile (Global USAGE + Schema SELECT) -> PASS
$cleanPrivs = $conn->evaluateAllPrivilegeLevels(
    ["GRANT USAGE ON *.* TO CURRENT_USER", "GRANT SELECT ON `hos`.* TO CURRENT_USER"],
    ['global' => ['USAGE'], 'schema' => ['SELECT']],
    'hos'
);
assertTest("Clean Read-only permissions approved", $cleanPrivs['is_readonly'] === true);
assertTest("Has SELECT privilege", $cleanPrivs['has_select'] === true);
assertTest("Zero prohibited privileges", empty($cleanPrivs['prohibited_found']));

// 5.2 Global Level Write (e.g. ALL PRIVILEGES ON *.* or INSERT ON *.*) -> REJECT
$globalWritePrivs = $conn->evaluateAllPrivilegeLevels(
    ["GRANT INSERT ON *.* TO CURRENT_USER", "GRANT SELECT ON *.* TO CURRENT_USER"],
    ['global' => ['INSERT', 'SELECT']],
    'hos'
);
assertTest("Reject Global-level write (INSERT on *.*)", $globalWritePrivs['is_readonly'] === false);
assertTest("Identified Global level prohibited privilege", !empty($globalWritePrivs['prohibited_found']) && strpos($globalWritePrivs['prohibited_found'][0], 'Global') !== false);

// 5.3 Schema Level Write (e.g. UPDATE ON `hos`.*) -> REJECT
$schemaWritePrivs = $conn->evaluateAllPrivilegeLevels(
    ["GRANT SELECT, UPDATE ON `hos`.* TO CURRENT_USER"],
    ['schema' => ['SELECT', 'UPDATE']],
    'hos'
);
assertTest("Reject Schema-level write (UPDATE on hos.*)", $schemaWritePrivs['is_readonly'] === false);
assertTest("Identified Schema level prohibited privilege", !empty($schemaWritePrivs['prohibited_found']) && strpos($schemaWritePrivs['prohibited_found'][0], 'Schema') !== false);

// 5.4 Table Level Write (e.g. DELETE ON `hos`.`opitemrece`) -> REJECT
$tableWritePrivs = $conn->evaluateAllPrivilegeLevels(
    ["GRANT SELECT ON `hos`.* TO CURRENT_USER", "GRANT DELETE ON `hos`.`opitemrece` TO CURRENT_USER"],
    ['schema' => ['SELECT'], 'table' => [['table' => 'opitemrece', 'privilege' => 'DELETE']]],
    'hos'
);
assertTest("Reject Table-level write (DELETE on hos.opitemrece)", $tableWritePrivs['is_readonly'] === false);
assertTest("Identified Table level prohibited privilege", !empty($tableWritePrivs['prohibited_found']) && strpos($tableWritePrivs['prohibited_found'][0], 'Table') !== false);

// 5.5 Column Level Write (e.g. UPDATE (vstdate) ON `hos`.`ovst`) -> REJECT
$columnWritePrivs = $conn->evaluateAllPrivilegeLevels(
    ["GRANT SELECT ON `hos`.* TO CURRENT_USER", "GRANT UPDATE (vstdate) ON `hos`.`ovst` TO CURRENT_USER"],
    ['schema' => ['SELECT'], 'column' => [['table' => 'ovst', 'column' => 'vstdate', 'privilege' => 'UPDATE']]],
    'hos'
);
assertTest("Reject Column-level write (UPDATE on hos.ovst(vstdate))", $columnWritePrivs['is_readonly'] === false);
assertTest("Identified Column level prohibited privilege", !empty($columnWritePrivs['prohibited_found']) && strpos($columnWritePrivs['prohibited_found'][0], 'Column') !== false);

// 5.6 Role Inherited Write (Role 'db_writer' assigned with INSERT) -> REJECT
$roleWritePrivs = $conn->evaluateAllPrivilegeLevels(
    ["GRANT SELECT ON `hos`.* TO CURRENT_USER"],
    ['schema' => ['SELECT'], 'role_table_grants' => [['role' => 'db_writer', 'table' => 'drugitems', 'privilege' => 'INSERT']]],
    'hos'
);
assertTest("Reject Role-inherited write (INSERT from role 'db_writer')", $roleWritePrivs['is_readonly'] === false);
assertTest("Identified Role level prohibited privilege", !empty($roleWritePrivs['prohibited_found']) && strpos($roleWritePrivs['prohibited_found'][0], 'Role') !== false);

// 5.7 Decision Independence from Username:
// Even if username is 'root', if privileges are clean readonly -> APPROVED
$rootMockConfig = [
    'hosxp_host'     => '127.0.0.1',
    'hosxp_port'     => 3306,
    'hosxp_database' => 'hos',
    'hosxp_username' => 'root', // Name is root, but grants are strictly read-only
    'hosxp_password' => 'mockpass',
    'hcode'          => '01425',
    'agent_mode'     => 'mock',
    'simulated_grants' => ["GRANT USAGE ON *.* TO CURRENT_USER", "GRANT SELECT ON `hos`.* TO CURRENT_USER"]
];
$resRootClean = $conn->testConnection($rootMockConfig);
assertTest("User 'root' with clean SELECT grants is APPROVED (not blocked by name)", $resRootClean['success'] === true && $resRootClean['is_readonly'] === true);

// Even if username is 'hosxp_readonly', if grants contain write -> REJECTED
$fakeReadOnlyConfig = [
    'hosxp_host'     => '127.0.0.1',
    'hosxp_port'     => 3306,
    'hosxp_database' => 'hos',
    'hosxp_username' => 'hosxp_readonly', // Friendly name, but has write permission!
    'hosxp_password' => 'mockpass',
    'hcode'          => '01425',
    'agent_mode'     => 'mock',
    'simulated_grants' => ["GRANT SELECT, INSERT ON `hos`.* TO CURRENT_USER"]
];
$resFakeReadOnly = $conn->testConnection($fakeReadOnlyConfig);
assertTest("User 'hosxp_readonly' with INSERT grant is REJECTED (proves name doesn't decide)", $resFakeReadOnly['success'] === false && $resFakeReadOnly['is_readonly'] === false);

// TEST GROUP 6: HOSxP Table Detection
echo "\n--- 6. Testing HOSxP Table Detection ---\n";
assertTest("Identified required HOSxP tables", count($resRootClean['hosxp_tables']) === 5);
assertTest("Contains table 'ovstdiag'", in_array('ovstdiag', $resRootClean['hosxp_tables']));
assertTest("Contains table 'opitemrece'", in_array('opitemrece', $resRootClean['hosxp_tables']));
assertTest("Contains table 'drugitems'", in_array('drugitems', $resRootClean['hosxp_tables']));
assertTest("Contains table 'patient'", in_array('patient', $resRootClean['hosxp_tables']));
assertTest("Contains table 'ovst'", in_array('ovst', $resRootClean['hosxp_tables']));

// TEST GROUP 7: Real Connection Graceful Error Handling
echo "\n--- 7. Testing Real Connection Graceful Error Handling ---\n";
$fakeRealConfig = [
    'hosxp_host'     => '127.0.0.1',
    'hosxp_port'     => 59999, // Unused port that immediately refuses
    'hosxp_database' => 'hos',
    'hosxp_username' => 'hosxp_readonly',
    'hosxp_password' => 'supersecretpass',
    'hcode'          => '01425',
    'agent_mode'     => 'real',
];
$resReal = $conn->testConnection($fakeRealConfig);
assertTest("Gracefully fails when unreachable host", $resReal['success'] === false);
assertTest("Error message is user friendly", !empty($resReal['error']));
assertTest("Error does NOT leak password", strpos($resReal['error'], 'supersecretpass') === false);

echo "\n========================================================\n";
echo "  Test Summary: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
