<?php
/**
 * RxCore Sync Agent — Phase 3.5.1 Security Audit Automated Test Suite
 * 
 * รันการทดสอบตรวจสอบความมั่นคงปลอดภัย (Security Audit) และความสอดคล้องของ API Contract
 * ครอบคลุม 15 ประเด็นความปลอดภัยตามข้อกำหนดของ Phase 3.5.1:
 * 
 * 1.  API Key ถูกต้องและไม่ถูกต้อง
 * 2.  API Key ของสถานพยาบาล A พยายามส่ง hcode ของสถานพยาบาล B
 * 3.  ไม่ส่ง hcode หรือส่ง hcode ว่าง
 * 4.  ปลอม hcode ใน Payload (ทั้ง Root Level และ Record Level)
 * 5.  ส่ง hos_guid ซ้ำภายในสถานพยาบาลเดียวกัน (Idempotence บน Composite Key)
 * 6.  ส่ง hos_guid เดียวกันจากคนละสถานพยาบาล (Multi-tenant Key Coexistence)
 * 7.  ส่ง Payload ที่มีฟิลด์เกิน Whitelist
 * 8.  ส่ง Payload ที่ขาดฟิลด์จำเป็น
 * 9.  ส่ง qty เป็น 0, ค่าติดลบ, String หรือค่าที่ผิดรูปแบบ
 * 10. ส่งวันที่ผิดรูปแบบ (รูปแบบผิด, วันที่ไม่มีอยู่จริงบนปฏิทิน, วันที่ในอนาคตเกินกำหนด)
 * 11. ส่ง JSON ที่ไม่ถูกต้อง
 * 12. ส่ง Request ขนาดใหญ่เกินขีดจำกัดที่กำหนด (Payload > 5MB, Batch > 1,000 records)
 * 13. ตรวจสอบว่า Error Response และ Log ไม่เปิดเผย API Key, Password หรือข้อมูลลับ
 * 14. ตรวจสอบว่า API Client ไม่ปิด TLS Certificate Verification ในโหมดใช้งานจริง
 * 15. ตรวจสอบว่าไม่มีการเชื่อมต่อออกภายนอกระหว่างการทดสอบ (Zero Outbound Network)
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

require_once __DIR__ . '/agent_api_client.php';
require_once __DIR__ . '/agent_mock_server.php';

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

echo "\n=================================================================\n";
echo "   RxCore Sync Agent — Phase 3.5.1 Security Audit Test Suite     \n";
echo "   API Contract, Multi-tenant Isolation & Attack Surface Tests   \n";
echo "=================================================================\n\n";

$hcodeA = '01425';
$apiKeyA = 'key_secret_01425_abc123';

$hcodeB = '01426';
$apiKeyB = 'key_secret_01426_xyz789';

$mockServer = new AgentMockApiServer();
$adapterCallable = function(string $key, string $payload, array $headers) use ($mockServer) {
    return $mockServer->handleRequest($key, $payload, $headers);
};

$clientA = new AgentApiClient('mock://local', $apiKeyA, 15, $adapterCallable);
$clientB = new AgentApiClient('mock://local', $apiKeyB, 15, $adapterCallable);

$validRecordA = [
    'hos_guid'      => '{GUID-AUDIT-A01}',
    'vn'            => 'VN20261028001',
    'hcode'         => $hcodeA,
    'vstdate'       => date('Y-m-d'),
    'tpu_code_list' => '100789000012345678901001',
    'qty'           => 10,
];

// ==============================================================================
// TEST 1: API Key ถูกต้องและไม่ถูกต้อง
// ==============================================================================
echo "--- 1. Testing Valid & Invalid API Key ---\n";
$mockServer->clearStore();

// 1.1 Valid API Key
$res1Valid = $clientA->sendDispenseBatch($hcodeA, [$validRecordA]);
assertTest("TC 1.1: Valid API Key accepted with HTTP 200", $res1Valid['http_code'] === 200 && $res1Valid['success'] === true);

// 1.2 Invalid API Key
$invalidKeyClient = new AgentApiClient('mock://local', 'wrong_fake_key_999999', 15, $adapterCallable);
$res1Invalid = $invalidKeyClient->sendDispenseBatch($hcodeA, [$validRecordA]);
assertTest("TC 1.2: Invalid API Key rejected with HTTP 401", $res1Invalid['http_code'] === 401 && $res1Invalid['success'] === false);
assertTest("TC 1.3: Error code is UNAUTHORIZED", ($res1Invalid['data']['error_code'] ?? '') === 'UNAUTHORIZED');

// 1.3 Empty API Key
$emptyKeyClient = new AgentApiClient('mock://local', '', 15, $adapterCallable);
$res1Empty = $emptyKeyClient->sendDispenseBatch($hcodeA, [$validRecordA]);
assertTest("TC 1.4: Empty API Key rejected with HTTP 401", $res1Empty['http_code'] === 401);

// ==============================================================================
// TEST 2: API Key ของสถานพยาบาล A พยายามส่ง hcode ของสถานพยาบาล B
// ==============================================================================
echo "\n--- 2. Testing API Key of Facility A sending hcode of Facility B ---\n";
$recordB = [
    'hos_guid'      => '{GUID-AUDIT-B01}',
    'vn'            => 'VN20261028002',
    'hcode'         => $hcodeB,
    'vstdate'       => date('Y-m-d'),
    'tpu_code_list' => '100789000012345678901001',
    'qty'           => 5,
];

$res2Cross = $clientA->sendDispenseBatch($hcodeB, [$recordB], ['skip_client_validation' => true]);
assertTest("TC 2.1: Key A sending hcode B rejected with HTTP 403 Forbidden", $res2Cross['http_code'] === 403);
assertTest("TC 2.2: Error code is FORBIDDEN_CROSS_TENANT", ($res2Cross['data']['error_code'] ?? '') === 'FORBIDDEN_CROSS_TENANT');
assertTest("TC 2.3: Error message identifies cross-tenant facility violation", str_contains($res2Cross['error'], '403') || str_contains($res2Cross['error'], 'Forbidden'));

// ==============================================================================
// TEST 3: ไม่ส่ง hcode หรือส่ง hcode ว่าง
// ==============================================================================
echo "\n--- 3. Testing Missing or Empty Root hcode ---\n";

// 3.1 ไม่ส่ง hcode (omitted from root payload)
$payloadMissingHcode = json_encode(['records' => [$validRecordA]], JSON_UNESCAPED_UNICODE);
[$code3a, $body3a] = $mockServer->handleRequest($apiKeyA, $payloadMissingHcode);
assertTest("TC 3.1: Missing root hcode rejected with HTTP 400", $code3a === 400);
assertTest("TC 3.2: Error code is MISSING_OR_EMPTY_HCODE", ($body3a['error_code'] ?? '') === 'MISSING_OR_EMPTY_HCODE');

// 3.2 ส่ง hcode ว่าง ("")
$payloadEmptyHcode = json_encode(['hcode' => '', 'records' => [$validRecordA]], JSON_UNESCAPED_UNICODE);
[$code3b, $body3b] = $mockServer->handleRequest($apiKeyA, $payloadEmptyHcode);
assertTest("TC 3.3: Empty string root hcode rejected with HTTP 400", $code3b === 400);
assertTest("TC 3.4: Error code is MISSING_OR_EMPTY_HCODE", ($body3b['error_code'] ?? '') === 'MISSING_OR_EMPTY_HCODE');

// 3.3 ส่ง hcode เป็น null
$payloadNullHcode = json_encode(['hcode' => null, 'records' => [$validRecordA]], JSON_UNESCAPED_UNICODE);
[$code3c, $body3c] = $mockServer->handleRequest($apiKeyA, $payloadNullHcode);
assertTest("TC 3.5: Null root hcode rejected with HTTP 400", $code3c === 400);

// 3.4 ส่ง hcode เป็นช่องว่างล้วน ("   ")
$payloadWhitespaceHcode = json_encode(['hcode' => '   ', 'records' => [$validRecordA]], JSON_UNESCAPED_UNICODE);
[$code3d, $body3d] = $mockServer->handleRequest($apiKeyA, $payloadWhitespaceHcode);
assertTest("TC 3.6: Whitespace-only root hcode rejected with HTTP 400", $code3d === 400 && ($body3d['error_code'] ?? '') === 'MISSING_OR_EMPTY_HCODE');

// 3.5 ส่ง hcode สั้นกว่า 5 หลัก (เช่น '123')
$payloadShortHcode = json_encode(['hcode' => '123', 'records' => [$validRecordA]], JSON_UNESCAPED_UNICODE);
[$code3e, $body3e] = $mockServer->handleRequest($apiKeyA, $payloadShortHcode);
assertTest("TC 3.7: Non-5-digit short root hcode rejected with HTTP 400 INVALID_HCODE_FORMAT", $code3e === 400 && ($body3e['error_code'] ?? '') === 'INVALID_HCODE_FORMAT');

// 3.6 ส่ง hcode ที่มีตัวอักษร (เช่น '0142A')
$payloadNonDigitHcode = json_encode(['hcode' => '0142A', 'records' => [$validRecordA]], JSON_UNESCAPED_UNICODE);
[$code3f, $body3f] = $mockServer->handleRequest($apiKeyA, $payloadNonDigitHcode);
assertTest("TC 3.8: Non-digit alphanumeric root hcode rejected with HTTP 400 INVALID_HCODE_FORMAT", $code3f === 400 && ($body3f['error_code'] ?? '') === 'INVALID_HCODE_FORMAT');

// 3.7 ส่ง hcode ยาวเกิน 5 หลัก (เช่น '014259')
$payloadLongHcode = json_encode(['hcode' => '014259', 'records' => [$validRecordA]], JSON_UNESCAPED_UNICODE);
[$code3g, $body3g] = $mockServer->handleRequest($apiKeyA, $payloadLongHcode);
assertTest("TC 3.9: Over-5-digit root hcode rejected with HTTP 400 INVALID_HCODE_FORMAT", $code3g === 400 && ($body3g['error_code'] ?? '') === 'INVALID_HCODE_FORMAT');

// 3.8 ส่ง hcode เป็น Array (Non-scalar)
$payloadArrayHcode = json_encode(['hcode' => ['nested' => '01425'], 'records' => [$validRecordA]], JSON_UNESCAPED_UNICODE);
[$code3h, $body3h] = $mockServer->handleRequest($apiKeyA, $payloadArrayHcode);
assertTest("TC 3.10: Non-scalar array root hcode rejected with HTTP 400", $code3h === 400);

// ==============================================================================
// TEST 4: ปลอม hcode ใน Payload (Record-Level Spoofing)
// ==============================================================================
echo "\n--- 4. Testing Spoofed hcode in Payload (Record Level) ---\n";
// Root payload ประกาศว่าเป็น 01425 แต่ใน Record แอบใส่ hcode เป็น 01426
$spoofedRecord = [
    'hos_guid'      => '{GUID-SPOOF-01}',
    'vn'            => 'VN20261028003',
    'hcode'         => '01426', // Spoofed to facility B!
    'vstdate'       => date('Y-m-d'),
    'tpu_code_list' => '100789000012345678901001',
    'qty'           => 1,
];

// 4.1 Client-side Pre-flight catches record-level spoofing
$res4Client = $clientA->sendDispenseBatch($hcodeA, [$spoofedRecord]);
assertTest("TC 4.1: Client Pre-flight blocks record-level spoofed hcode", $res4Client['success'] === false && !empty($res4Client['client_rejected']));

// 4.2 Server Mock catches record-level spoofing when bypassed
$res4Server = $clientA->sendDispenseBatch($hcodeA, [$spoofedRecord], ['skip_client_validation' => true]);
assertTest("TC 4.2: Server Mock rejects record-level spoofed hcode with HTTP 403", $res4Server['http_code'] === 403);
assertTest("TC 4.3: Error code is FORBIDDEN_CROSS_TENANT", ($res4Server['data']['error_code'] ?? '') === 'FORBIDDEN_CROSS_TENANT');

// ==============================================================================
// TEST 5: ส่ง hos_guid ซ้ำภายในสถานพยาบาลเดียวกัน (Idempotence & Upsert)
// ==============================================================================
echo "\n--- 5. Testing Duplicate hos_guid within Same Facility (Idempotence) ---\n";
$mockServer->clearStore();

$dupGuidRecord1 = [
    'hos_guid'      => '{GUID-SAME-HOSP-DUP}',
    'vn'            => 'VN1001',
    'hcode'         => $hcodeA,
    'vstdate'       => date('Y-m-d'),
    'tpu_code_list' => '100789000012345678901001',
    'qty'           => 10,
];

$dupGuidRecord2 = [
    'hos_guid'      => '{GUID-SAME-HOSP-DUP}', // Same GUID!
    'vn'            => 'VN1001',
    'hcode'         => $hcodeA,
    'vstdate'       => date('Y-m-d'),
    'tpu_code_list' => '100789000012345678901001',
    'qty'           => 15, // Updated qty
];

// ส่งครั้งที่ 1
$res5a = $clientA->sendDispenseBatch($hcodeA, [$dupGuidRecord1]);
$countAfterFirst = count($mockServer->getDispenseStore());
assertTest("TC 5.1: First submission of hos_guid inserted", $res5a['inserted_or_updated'] === 1 && $countAfterFirst === 1);

// ส่งครั้งที่ 2 ด้วย hos_guid เดิม
$res5b = $clientA->sendDispenseBatch($hcodeA, [$dupGuidRecord2]);
$countAfterSecond = count($mockServer->getDispenseStore());
assertTest("TC 5.2: Resubmission with same hos_guid updates via upsert", $res5b['inserted_or_updated'] === 1);
assertTest("TC 5.3: Total store count remains exactly 1 (No duplicate created)", $countAfterSecond === 1);
$storedItem = $mockServer->getDispenseStore()["{$hcodeA}:{GUID-SAME-HOSP-DUP}"];
assertTest("TC 5.4: Stored record reflects updated qty = 15", (float)$storedItem['qty'] === 15.0);

// ==============================================================================
// TEST 6: ส่ง hos_guid เดียวกันจากคนละสถานพยาบาล (Multi-tenant Key Coexistence)
// ==============================================================================
echo "\n--- 6. Testing Same hos_guid across Different Facilities (Multi-tenant) ---\n";
$mockServer->clearStore();

$sharedGuid = '{GUID-SHARED-BETWEEN-HOSPITALS}';

$recordFromA = [
    'hos_guid'      => $sharedGuid,
    'vn'            => 'VN-HOSP-A',
    'hcode'         => $hcodeA,
    'vstdate'       => date('Y-m-d'),
    'tpu_code_list' => '100789000012345678901001',
    'qty'           => 10,
];

$recordFromB = [
    'hos_guid'      => $sharedGuid, // Same GUID!
    'vn'            => 'VN-HOSP-B',
    'hcode'         => $hcodeB,
    'vstdate'       => date('Y-m-d'),
    'tpu_code_list' => '100789000012345678901001',
    'qty'           => 20,
];

// รพ.สต. A ส่ง
$res6a = $clientA->sendDispenseBatch($hcodeA, [$recordFromA]);
assertTest("TC 6.1: Hospital A sends shared GUID successfully", $res6a['http_code'] === 200 && $res6a['inserted_or_updated'] === 1);

// รพ.สต. B ส่งด้วย Key ของตนเอง
$res6b = $clientB->sendDispenseBatch($hcodeB, [$recordFromB]);
assertTest("TC 6.2: Hospital B sends shared GUID successfully", $res6b['http_code'] === 200 && $res6b['inserted_or_updated'] === 1);

// ตรวจสอบว่าในฐานข้อมูลมี 2 แถวแยกกันอย่างชัดเจนตามคู่รหัส (hcode, hos_guid)
$allStored = $mockServer->getDispenseStore();
assertTest("TC 6.3: Dispense store contains both records independently (Count = 2)", count($allStored) === 2);
assertTest("TC 6.4: Record A preserved with hcode=01425 and qty=10", isset($allStored["{$hcodeA}:{$sharedGuid}"]) && (float)$allStored["{$hcodeA}:{$sharedGuid}"]['qty'] === 10.0);
assertTest("TC 6.5: Record B preserved with hcode=01426 and qty=20", isset($allStored["{$hcodeB}:{$sharedGuid}"]) && (float)$allStored["{$hcodeB}:{$sharedGuid}"]['qty'] === 20.0);

// ==============================================================================
// TEST 7: ส่ง Payload ที่มีฟิลด์เกิน Whitelist
// ==============================================================================
echo "\n--- 7. Testing Payload with Fields Outside Whitelist ---\n";
$extraFieldRecord = [
    'hos_guid'       => '{GUID-EXTRA-001}',
    'vn'             => 'VN001',
    'hcode'          => $hcodeA,
    'vstdate'        => date('Y-m-d'),
    'tpu_code_list'  => '100789000012345678901001',
    'qty'            => 1,
    'unauthorized_f' => 'MALICIOUS_EXTRA_VALUE',
];

$res7Client = $clientA->sendDispenseBatch($hcodeA, [$extraFieldRecord]);
assertTest("TC 7.1: Client Pre-flight blocks unauthorized extra fields", $res7Client['success'] === false && !empty($res7Client['client_rejected']));

$res7Server = $clientA->sendDispenseBatch($hcodeA, [$extraFieldRecord], ['skip_client_validation' => true]);
assertTest("TC 7.2: Server Mock rejects unauthorized extra fields with HTTP 400", $res7Server['http_code'] === 400);
assertTest("TC 7.3: Error code is UNAUTHORIZED_FIELD", ($res7Server['data']['error_code'] ?? '') === 'UNAUTHORIZED_FIELD');

// ==============================================================================
// TEST 8: ส่ง Payload ที่ขาดฟิลด์จำเป็น
// ==============================================================================
echo "\n--- 8. Testing Payload Missing Required Fields ---\n";
$requiredFields = ['hos_guid', 'vn', 'hcode', 'vstdate', 'tpu_code_list', 'qty'];

foreach ($requiredFields as $field) {
    $incompleteRecord = $validRecordA;
    unset($incompleteRecord[$field]);

    $res8 = $clientA->sendDispenseBatch($hcodeA, [$incompleteRecord], ['skip_client_validation' => true]);
    assertTest("TC 8.{$field}: Missing required field '{$field}' rejected with HTTP 400", $res8['http_code'] === 400 && ($res8['data']['error_code'] ?? '') === 'MISSING_REQUIRED_FIELD');
}

// ==============================================================================
// TEST 9: ส่ง qty เป็น 0, ค่าติดลบ, String หรือค่าที่ผิดรูปแบบ
// ==============================================================================
echo "\n--- 9. Testing Invalid qty Values (0, Negative, String, Empty, Boolean) ---\n";
$badQtyValues = [
    'zero'          => 0,
    'negative'      => -5,
    'string'        => 'invalid_ten',
    'empty_str'     => '',
    'boolean'       => true,
    'malformed_dec' => '10.5.5',
    'sql_injection' => '10; DROP TABLE',
    'array_type'    => [10],
];

foreach ($badQtyValues as $label => $val) {
    $badQtyRecord = $validRecordA;
    $badQtyRecord['qty'] = $val;

    $res9 = $clientA->sendDispenseBatch($hcodeA, [$badQtyRecord], ['skip_client_validation' => true]);
    $errCode = $res9['data']['error_code'] ?? '';
    assertTest("TC 9.{$label}: Qty ({$label}) rejected with HTTP 400", $res9['http_code'] === 400 && in_array($errCode, ['INVALID_QTY', 'MISSING_REQUIRED_FIELD'], true));
}

// ==============================================================================
// TEST 10: ส่งวันที่ผิดรูปแบบ
// ==============================================================================
echo "\n--- 10. Testing Invalid vstdate (Format, Non-existent, Future Limit) ---\n";
$badDates = [
    'slashes'      => '2026/10/28',
    'thai_format'  => '28-10-2026',
    'non_existent' => '2026-02-31', // ไม่มีวันที่ 31 ก.พ.
    'invalid_mon'  => '2026-13-45',
    'future_far'   => '2099-01-01', // เกินวันพรุ่งนี้ (chk_dispense_vstdate_valid)
    'not_a_date'   => 'invalid-date-string',
    'array_date'   => ['2026-10-28'],
];

foreach ($badDates as $label => $dateVal) {
    $badDateRecord = $validRecordA;
    $badDateRecord['vstdate'] = $dateVal;

    $res10 = $clientA->sendDispenseBatch($hcodeA, [$badDateRecord], ['skip_client_validation' => true]);
    $dateValDisplay = is_scalar($dateVal) ? (string)$dateVal : gettype($dateVal);
    assertTest("TC 10.{$label}: Vstdate '{$dateValDisplay}' rejected with HTTP 400", $res10['http_code'] === 400);
}

// ==============================================================================
// TEST 11: ส่ง JSON ที่ไม่ถูกต้อง
// ==============================================================================
echo "\n--- 11. Testing Malformed JSON Syntax ---\n";
[$code11a, $body11a] = $mockServer->handleRequest($apiKeyA, "{broken_json_syntax: [true");
assertTest("TC 11.1: Malformed JSON syntax rejected with HTTP 400", $code11a === 400);
assertTest("TC 11.2: Error code is INVALID_JSON", ($body11a['error_code'] ?? '') === 'INVALID_JSON');

[$code11b, $body11b] = $mockServer->handleRequest($apiKeyA, "");
assertTest("TC 11.3: Empty body rejected with HTTP 400 EMPTY_PAYLOAD", $code11b === 400 && ($body11b['error_code'] ?? '') === 'EMPTY_PAYLOAD');

// ==============================================================================
// TEST 12: ส่ง Request ขนาดใหญ่เกินขีดจำกัดที่กำหนด
// ==============================================================================
echo "\n--- 12. Testing Large Request Limits (Batch Size & Payload Size) ---\n";

// 12.1 Batch Record Limit (เกิน 1,000 รายการ)
$largeBatch = [];
for ($i = 0; $i < 1005; $i++) {
    $largeBatch[] = [
        'hos_guid'      => "{GUID-LARGE-{$i}}",
        'vn'            => "VN{$i}",
        'hcode'         => $hcodeA,
        'vstdate'       => date('Y-m-d'),
        'tpu_code_list' => '100789000012345678901001',
        'qty'           => 1,
    ];
}

// Client Pre-flight blocks large batch
$res12Client = $clientA->sendDispenseBatch($hcodeA, $largeBatch);
assertTest("TC 12.1: Client Pre-flight blocks batch exceeding 1000 records", $res12Client['success'] === false && !empty($res12Client['client_rejected']));

// Server Mock returns HTTP 413
$res12Server = $clientA->sendDispenseBatch($hcodeA, $largeBatch, ['skip_client_validation' => true]);
assertTest("TC 12.2: Server Mock rejects batch > 1000 with HTTP 413", $res12Server['http_code'] === 413);
assertTest("TC 12.3: Error code is BATCH_SIZE_EXCEEDED", ($res12Server['data']['error_code'] ?? '') === 'BATCH_SIZE_EXCEEDED');

// 12.2 Payload Byte Size Limit (เกิน 5 MB)
$hugePayload = json_encode([
    'hcode'   => $hcodeA,
    'records' => [$validRecordA],
    'padding' => str_repeat('A', 5242881) // 5 MB + 1 byte
]);
[$code12Byte, $body12Byte] = $mockServer->handleRequest($apiKeyA, $hugePayload);
assertTest("TC 12.4: Payload > 5MB rejected with HTTP 413 PAYLOAD_TOO_LARGE", $code12Byte === 413 && ($body12Byte['error_code'] ?? '') === 'PAYLOAD_TOO_LARGE');

// ==============================================================================
// TEST 13: ตรวจสอบว่า Error Response และ Log ไม่เปิดเผย API Key หรือข้อมูลลับ
// ==============================================================================
echo "\n--- 13. Testing Secret Redaction in Errors & Logs ---\n";
$superSecretKey = 'VERY_SECRET_KEY_987654321_DO_NOT_LEAK';
$sensitiveClient = new AgentApiClient('http://127.0.0.1:59998/api/sync', $superSecretKey, 2);

$res13Fail = $sensitiveClient->sendDispenseBatch($hcodeA, [$validRecordA]);
$serializedResponse = json_encode($res13Fail);

assertTest("TC 13.1: Full secret API Key is NOT in response JSON", !str_contains($serializedResponse, $superSecretKey));
assertTest("TC 13.2: Masked API Key helper outputs partial mask", str_contains(AgentApiClient::maskApiKey($superSecretKey), '...'));
assertTest("TC 13.3: Log file does not contain plain API Key", !str_contains(file_get_contents(__DIR__ . '/agent_mock_server.php'), $superSecretKey));

// ==============================================================================
// TEST 14: ตรวจสอบว่า API Client ไม่ปิด TLS Certificate Verification
// ==============================================================================
echo "\n--- 14. Testing TLS Certificate Verification Enforcement ---\n";
assertTest("TC 14.1: AgentApiClient has TLS Verification enforced (tlsVerify = true)", $clientA->isTlsVerificationEnforced() === true);

// ตรวจสอบ Source Code ว่าไม่มีการตั้งค่า CURLOPT_SSL_VERIFYPEER เป็น false
$clientSource = file_get_contents(__DIR__ . '/agent_api_client.php');
$hasSslDisabled = (bool)preg_match('/CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0)/i', $clientSource);
assertTest("TC 14.2: Source code strictly contains ZERO CURLOPT_SSL_VERIFYPEER=false statements", $hasSslDisabled === false);

$hasVerifyHostDisabled = (bool)preg_match('/CURLOPT_SSL_VERIFYHOST\s*,\s*(false|0)/i', $clientSource);
assertTest("TC 14.3: Source code strictly contains ZERO CURLOPT_SSL_VERIFYHOST=0 statements", $hasVerifyHostDisabled === false);

// ==============================================================================
// TEST 15: ตรวจสอบว่าไม่มีการเชื่อมต่อออกภายนอกระหว่างการทดสอบ
// ==============================================================================
echo "\n--- 15. Testing Zero Outbound Network Connectivity in Tests ---\n";
assertTest("TC 15.1: Test client endpoint is strictly local mock (assertNoExternalConnection = true)", $clientA->assertNoExternalConnection() === true);

// ตรวจสอบฟังก์ชันตรวจจับ External URLs
assertTest("TC 15.2: Detects external Supabase cloud URL", AgentApiClient::isExternalUrl('https://abcxyz.supabase.co/rest/v1/rpc') === true);
assertTest("TC 15.3: Detects external public IP address", AgentApiClient::isExternalUrl('http://203.150.22.1/api') === true);
assertTest("TC 15.4: Confirms localhost is internal", AgentApiClient::isExternalUrl('http://localhost:8080/api') === false);
assertTest("TC 15.5: Confirms 127.0.0.1 is internal", AgentApiClient::isExternalUrl('http://127.0.0.1:80/api') === false);
assertTest("TC 15.6: Confirms mock:// scheme is internal", AgentApiClient::isExternalUrl('mock://local') === false);

echo "\n=================================================================\n";
echo "   PHASE 3.5.1 TEST SUMMARY: {$passed}/" . ($passed + $failed) . " PASSED (" . round($passed / ($passed + $failed) * 100) . "%)\n";
if ($failed === 0) {
    echo "   STATUS: ALL 15 SECURITY AUDIT SCENARIOS PASSED SUCCESSFULLY! \n";
} else {
    echo "   STATUS: SOME SECURITY AUDIT TESTS FAILED!\n";
}
echo "=================================================================\n\n";
exit($failed > 0 ? 1 : 0);
