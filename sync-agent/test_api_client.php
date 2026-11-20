<?php
/**
 * RxCore Sync Agent — Phase 3.5 Automated Test Suite
 * Supabase API Integration (Mock Endpoint Only)
 * 
 * รันการทดสอบข้อกำหนดทั้งหมด 12 สถานการณ์ของ Phase 3.5:
 * 1.  HTTP 200: รับข้อมูลสำเร็จ (Success & RPC Response)
 * 2.  HTTP 400: Payload ไม่ถูกต้อง (Missing fields, Invalid qty, Invalid date)
 * 3.  HTTP 401: Authentication ไม่ผ่าน (Invalid API Key)
 * 4.  HTTP 403: Forbidden (Cross-tenant facility isolation violation)
 * 5.  HTTP 409: รายการซ้ำหรือ Conflict
 * 6.  HTTP 429: Rate Limit Exceeded
 * 7.  HTTP 500: Server Error (Graceful failure without crash)
 * 8.  Timeout และ Connection Refused
 * 9.  Response JSON ไม่ถูกต้อง (Non-JSON / HTML error page)
 * 10. API Key ปลอดภัย (ไม่เปิดเผย Plaintext Key ใน Error/Logs)
 * 11. Payload มีฟิลด์เกินข้อกำหนด หรือมีข้อมูล PII (HN, CID)
 * 12. ส่ง Payload เดิมซ้ำแล้วไม่สร้างข้อมูลซ้ำ (Idempotence บน Composite Key (hcode, hos_guid))
 * 13. ทดสอบ Schema RPC Behavior: กรองยาปิดใช้งาน (DRUG_INACTIVE) ตาม schema.sql
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
echo "   RxCore Sync Agent — Phase 3.5 Automated Test Suite            \n";
echo "   Supabase API Client & Mock Adapter Verification               \n";
echo "=================================================================\n\n";

// ข้อมูลอ้างอิงสำหรับการทดสอบ
$validHcode = '01425';
$validApiKey = 'key_secret_01425_abc123';
$otherHcode = '01426';
$otherApiKey = 'key_secret_01426_xyz789';

$mockServer = new AgentMockApiServer();
$adapterCallable = function(string $key, string $payload, array $headers) use ($mockServer) {
    return $mockServer->handleRequest($key, $payload, $headers);
};

// ------------------------------------------------------------------------------
// TEST SCENARIO 1: HTTP 200 รับข้อมูลสำเร็จ
// ------------------------------------------------------------------------------
echo "--- 1. Testing HTTP 200: Successful Transmission & RPC Response ---\n";
$mockServer->clearStore();
$client = new AgentApiClient('mock://local', $validApiKey, 15, $adapterCallable);

$sampleRecords = [
    [
        'hos_guid'      => '{GUID-TEST-200-01}',
        'vn'            => 'VN20261027001',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901001', // Paracetamol (Active)
        'qty'           => 10,
    ],
    [
        'hos_guid'      => '{GUID-TEST-200-02}',
        'vn'            => 'VN20261027002',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901002', // Vaccine (Active)
        'qty'           => 1,
    ],
];

$res1 = $client->sendDispenseBatch($validHcode, $sampleRecords);

assertTest("TC 1.1: Request succeeds with success=true", $res1['success'] === true);
assertTest("TC 1.2: HTTP status code is 200", $res1['http_code'] === 200);
assertTest("TC 1.3: Returns correct target hcode '01425'", $res1['hcode'] === '01425');
assertTest("TC 1.4: inserted_or_updated equals 2", $res1['inserted_or_updated'] === 2);
assertTest("TC 1.5: failed count is 0", $res1['failed'] === 0);
assertTest("TC 1.6: mock_mode flag is true (Explicit Mock indicator)", $res1['mock_mode'] === true);
assertTest("TC 1.7: sync_log_id returned from RPC", !empty($res1['sync_log_id']));

// ------------------------------------------------------------------------------
// TEST SCENARIO 2: HTTP 400 Payload ไม่ถูกต้อง
// ------------------------------------------------------------------------------
echo "\n--- 2. Testing HTTP 400: Malformed & Invalid Payload ---\n";

// 2.1 ขาดฟิลด์จำเป็น (Missing hos_guid)
$badRecordsMissing = [
    [
        'vn'            => 'VN999',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901001',
        'qty'           => 5,
    ]
];
$res2a = $client->sendDispenseBatch($validHcode, $badRecordsMissing);
assertTest("TC 2.1: Missing required field blocked with 400", $res2a['http_code'] === 400 && $res2a['success'] === false);

// 2.2 จำนวนยา <= 0 (CHECK qty > 0 constraint)
$badRecordsQty = [
    [
        'hos_guid'      => '{GUID-BAD-QTY}',
        'vn'            => 'VN999',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901001',
        'qty'           => 0, // Invalid: must be > 0
    ]
];
$res2b = $client->sendDispenseBatch($validHcode, $badRecordsQty);
assertTest("TC 2.2: Zero qty blocked with 400", $res2b['http_code'] === 400 && $res2b['success'] === false);

// 2.3 รูปแบบวันที่ไม่ถูกต้อง (Non YYYY-MM-DD)
$badRecordsDate = [
    [
        'hos_guid'      => '{GUID-BAD-DATE}',
        'vn'            => 'VN999',
        'hcode'         => '01425',
        'vstdate'       => '27/10/2026', // Wrong format
        'tpu_code_list' => '100789000012345678901001',
        'qty'           => 5,
    ]
];
$res2c = $client->sendDispenseBatch($validHcode, $badRecordsDate);
assertTest("TC 2.3: Invalid date format blocked with 400", $res2c['http_code'] === 400 && $res2c['success'] === false);

// 2.4 ทดสอบ Server-side 400 ด้วยการ Bypass client validation
$res2d = $client->sendDispenseBatch($validHcode, $badRecordsQty, ['skip_client_validation' => true]);
assertTest("TC 2.4: Server-side validation catches invalid qty returning HTTP 400", $res2d['http_code'] === 400);

// ------------------------------------------------------------------------------
// TEST SCENARIO 3: HTTP 401 Authentication ไม่ผ่าน
// ------------------------------------------------------------------------------
echo "\n--- 3. Testing HTTP 401: Unauthorized (Invalid API Key) ---\n";
$unauthClient = new AgentApiClient('mock://local', 'invalid_secret_key_999999', 15, $adapterCallable);
$res3 = $unauthClient->sendDispenseBatch($validHcode, $sampleRecords);

assertTest("TC 3.1: Invalid API Key returns success=false", $res3['success'] === false);
assertTest("TC 3.2: HTTP status code is 401 Unauthorized", $res3['http_code'] === 401);
assertTest("TC 3.3: error_code is UNAUTHORIZED", ($res3['data']['error_code'] ?? '') === 'UNAUTHORIZED');
assertTest("TC 3.4: Error message explains authentication failure", str_contains($res3['error'], '401'));

// ------------------------------------------------------------------------------
// TEST SCENARIO 4: HTTP 403 Forbidden (Cross-facility Tenant Isolation)
// ------------------------------------------------------------------------------
echo "\n--- 4. Testing HTTP 403: Forbidden (Cross-facility Tenant Isolation) ---\n";
// ใช้ Key ของ รพ.สต. 01425 แต่พยายามส่งหรือระบุ hcode เป็น 01426
$res4a = $client->sendDispenseBatch($otherHcode, [
    [
        'hos_guid'      => '{GUID-CROSS-01}',
        'vn'            => 'VN001',
        'hcode'         => $otherHcode, // 01426
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901001',
        'qty'           => 1,
    ]
], ['skip_client_validation' => true]);

assertTest("TC 4.1: Cross-facility sync returns success=false", $res4a['success'] === false);
assertTest("TC 4.2: HTTP status code is 403 Forbidden", $res4a['http_code'] === 403);
assertTest("TC 4.3: error_code is FORBIDDEN_CROSS_TENANT", ($res4a['data']['error_code'] ?? '') === 'FORBIDDEN_CROSS_TENANT');
assertTest("TC 4.4: Error message indicates tenant isolation violation", str_contains($res4a['error'], '403') || str_contains($res4a['error'], 'Forbidden'));

// ------------------------------------------------------------------------------
// TEST SCENARIO 5: HTTP 409 Conflict
// ------------------------------------------------------------------------------
echo "\n--- 5. Testing HTTP 409: Conflict Simulation ---\n";
$res5 = $client->sendDispenseBatch($validHcode, $sampleRecords, ['mock_scenario' => 'force_409']);

assertTest("TC 5.1: Conflict returns success=false", $res5['success'] === false);
assertTest("TC 5.2: HTTP status code is 409 Conflict", $res5['http_code'] === 409);
assertTest("TC 5.3: error_code is CONFLICT", ($res5['data']['error_code'] ?? '') === 'CONFLICT');

// ------------------------------------------------------------------------------
// TEST SCENARIO 6: HTTP 429 Rate Limit
// ------------------------------------------------------------------------------
echo "\n--- 6. Testing HTTP 429: Rate Limit Exceeded ---\n";
$res6 = $client->sendDispenseBatch($validHcode, $sampleRecords, ['mock_scenario' => 'force_429']);

assertTest("TC 6.1: Rate limit returns success=false", $res6['success'] === false);
assertTest("TC 6.2: HTTP status code is 429", $res6['http_code'] === 429);
assertTest("TC 6.3: error_code is RATE_LIMIT_EXCEEDED", ($res6['data']['error_code'] ?? '') === 'RATE_LIMIT_EXCEEDED');
assertTest("TC 6.4: retry_after property is returned", isset($res6['retry_after']) && $res6['retry_after'] > 0);

// ------------------------------------------------------------------------------
// TEST SCENARIO 7: HTTP 500 Server Error
// ------------------------------------------------------------------------------
echo "\n--- 7. Testing HTTP 500: Server Error Handling ---\n";
$res7 = $client->sendDispenseBatch($validHcode, $sampleRecords, ['mock_scenario' => 'force_500']);

assertTest("TC 7.1: Server error returns success=false", $res7['success'] === false);
assertTest("TC 7.2: HTTP status code is 500", $res7['http_code'] === 500);
assertTest("TC 7.3: Handled gracefully without unhandled PHP exception", is_array($res7));
assertTest("TC 7.4: error_code is SERVER_ERROR or INTERNAL_SERVER_ERROR", in_array($res7['data']['error_code'] ?? '', ['SERVER_ERROR', 'INTERNAL_SERVER_ERROR'], true));

// ------------------------------------------------------------------------------
// TEST SCENARIO 8: Timeout และ Connection Refused
// ------------------------------------------------------------------------------
echo "\n--- 8. Testing Timeout & Connection Refused ---\n";

// 8.1 Gateway Timeout (HTTP 504)
$res8GatewayTimeout = $client->sendDispenseBatch($validHcode, $sampleRecords, ['mock_scenario' => 'force_timeout']);
assertTest("TC 8.1: Gateway Timeout returns HTTP 504", $res8GatewayTimeout['http_code'] === 504);
assertTest("TC 8.2: Gateway Timeout error captured gracefully", str_contains($res8GatewayTimeout['error'], '504') || str_contains($res8GatewayTimeout['error'], 'Timeout'));

// 8.2 Network Transport Timeout
$timeoutClient = new AgentApiClient('http://127.0.0.1:59998/api/sync', $validApiKey, 1);
$res8NetTimeout = $timeoutClient->sendDispenseBatch($validHcode, $sampleRecords);
assertTest("TC 8.3: Transport timeout captured with is_timeout=true", ($res8NetTimeout['is_timeout'] ?? false) === true);

// 8.3 Connection Refused
$res8Refused = $client->sendDispenseBatch($validHcode, $sampleRecords, ['mock_scenario' => 'force_connection_refused']);
assertTest("TC 8.4: Connection refused returns success=false", $res8Refused['success'] === false);
assertTest("TC 8.5: Connection refused error captured with is_connection_refused=true", ($res8Refused['is_connection_refused'] ?? false) === true);

// ------------------------------------------------------------------------------
// TEST SCENARIO 9: Response JSON ไม่ถูกต้อง
// ------------------------------------------------------------------------------
echo "\n--- 9. Testing Malformed / Non-JSON Response Handling ---\n";
$res9 = $client->sendDispenseBatch($validHcode, $sampleRecords, ['mock_scenario' => 'force_malformed_json']);

assertTest("TC 9.1: Malformed JSON returns success=false", $res9['success'] === false);
assertTest("TC 9.2: error_code is MALFORMED_RESPONSE_JSON", $res9['error_code'] === 'MALFORMED_RESPONSE_JSON');
assertTest("TC 9.3: Error explains invalid JSON from server", str_contains($res9['error'], 'Invalid JSON'));
assertTest("TC 9.4: Raw response preview safely captured", !empty($res9['raw_response']));

// ------------------------------------------------------------------------------
// TEST SCENARIO 10: API Key ปลอดภัย (ไม่เปิดเผย Plaintext Key)
// ------------------------------------------------------------------------------
echo "\n--- 10. Testing API Key Redaction & Masking ---\n";
$secretPlainKey = 'SUPER_SECRET_TOKEN_999xyz';
$maskedKey = AgentApiClient::maskApiKey($secretPlainKey);

assertTest("TC 10.1: Masked key starts with prefix and ends with suffix", str_starts_with($maskedKey, 'SUPE') && str_ends_with($maskedKey, 'xyz'));
assertTest("TC 10.2: Masked key contains middle dots", str_contains($maskedKey, '...'));
assertTest("TC 10.3: Full plain key is NOT in masked string", !str_contains($maskedKey, $secretPlainKey));

// ทดสอบว่าใน Response / Error ทั้งหมดไม่มี $secretPlainKey ปรากฏอยู่
$clientWithSecret = new AgentApiClient('http://127.0.0.1:59998/api/sync', $secretPlainKey, 2);
$resSecretFail = $clientWithSecret->sendDispenseBatch($validHcode, $sampleRecords);
$serialized = json_encode($resSecretFail);

assertTest("TC 10.4: Error response does NOT leak raw API Key", !str_contains($serialized, $secretPlainKey));

// ------------------------------------------------------------------------------
// TEST SCENARIO 11: Payload มีฟิลด์เกินข้อกำหนด หรือมีข้อมูล PII
// ------------------------------------------------------------------------------
echo "\n--- 11. Testing Data Whitelist & PII Rejection (HN, CID, Extra Fields) ---\n";

// 11.1 พยายามส่ง HN และ CID
$piiRecords = [
    [
        'hos_guid'      => '{GUID-PII-01}',
        'vn'            => 'VN001',
        'hn'            => '12345678', // FORBIDDEN PII
        'cid'           => '1100500123456', // FORBIDDEN PII
        'patient_name'  => 'นายทดสอบ สมมุติ', // FORBIDDEN PII
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901001',
        'qty'           => 1,
    ]
];

$res11Pii = $client->sendDispenseBatch($validHcode, $piiRecords);
assertTest("TC 11.1: Payload with HN/CID/Name is strictly blocked pre-flight", $res11Pii['success'] === false);
assertTest("TC 11.2: Client rejection flag is set (blocked before network)", !empty($res11Pii['client_rejected']));
assertTest("TC 11.3: Error explicitly cites PII Security Violation", str_contains($res11Pii['error'], 'PII Security Violation'));

// 11.2 พยายามส่งฟิลด์เกินข้อกำหนด (Unknown Extra Field)
$extraFieldRecords = [
    [
        'hos_guid'       => '{GUID-EXTRA-01}',
        'vn'             => 'VN001',
        'hcode'          => '01425',
        'vstdate'        => '2026-10-27',
        'tpu_code_list'  => '100789000012345678901001',
        'qty'            => 1,
        'doctor_license' => 'ว12345', // UNAUTHORIZED EXTRA FIELD
    ]
];
$res11Extra = $client->sendDispenseBatch($validHcode, $extraFieldRecords);
assertTest("TC 11.4: Extra unauthorized field is blocked pre-flight", $res11Extra['success'] === false);
assertTest("TC 11.5: Error identifies Schema Discrepancy", str_contains($res11Extra['error'], 'Schema Discrepancy'));

// 11.3 ทดสอบกรณี Bypass Client แล้ว Server Mock สกัดกั้น PII
$res11ServerPii = $client->sendDispenseBatch($validHcode, $piiRecords, ['skip_client_validation' => true]);
assertTest("TC 11.6: Server Mock also strictly rejects PII with HTTP 400 PRIVACY_VIOLATION", 
    $res11ServerPii['http_code'] === 400 && ($res11ServerPii['data']['error_code'] ?? '') === 'PRIVACY_VIOLATION'
);

// ------------------------------------------------------------------------------
// TEST SCENARIO 12: Idempotence & Duplicate Prevention บน (hcode, hos_guid)
// ------------------------------------------------------------------------------
echo "\n--- 12. Testing Idempotence & Composite Key (hcode, hos_guid) Upsert ---\n";
$mockServer->clearStore();

$idemRecords = [
    [
        'hos_guid'      => '{GUID-IDEM-01}',
        'vn'            => 'VN20261027001',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901001',
        'qty'           => 10,
    ],
    [
        'hos_guid'      => '{GUID-IDEM-02}',
        'vn'            => 'VN20261027002',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901002',
        'qty'           => 1,
    ],
    [
        'hos_guid'      => '{GUID-IDEM-03}',
        'vn'            => 'VN20261027003',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901003',
        'qty'           => 20,
    ],
];

// รอบที่ 1: ส่งชุดข้อมูล 3 รายการ
$res12First = $client->sendDispenseBatch($validHcode, $idemRecords);
$storeCountFirst = count($mockServer->getDispenseStore());

assertTest("TC 12.1: First submission succeeds (inserted 3)", $res12First['inserted_or_updated'] === 3);
assertTest("TC 12.2: Dispense store contains exactly 3 records", $storeCountFirst === 3);

// รอบที่ 2: ส่งชุดข้อมูลเดิมซ้ำ (Same payload retransmitted)
$res12Second = $client->sendDispenseBatch($validHcode, $idemRecords);
$storeCountSecond = count($mockServer->getDispenseStore());

assertTest("TC 12.3: Second submission succeeds (updated 3 via upsert)", $res12Second['inserted_or_updated'] === 3);
assertTest("TC 12.4: Dispense store still contains exactly 3 records (NO DUPLICATES CREATED)", $storeCountSecond === 3);
assertTest("TC 12.5: Store count before and after resending is identical", $storeCountFirst === $storeCountSecond);

// ตรวจสอบว่า record มี flag is_update = true
$store = $mockServer->getDispenseStore();
assertTest("TC 12.6: Records correctly marked as updated in upsert store", $store['01425:{GUID-IDEM-01}']['is_update'] === true);

// ------------------------------------------------------------------------------
// TEST SCENARIO 13: Schema RPC Behavior: Inactive Drug Interception
// ------------------------------------------------------------------------------
echo "\n--- 13. Testing RPC Drug Status (Inactive Drug Intercepted) ---\n";
// ยารหัส 100789000012345678901004 มี is_active = false ตาม rx_drug_lookup ใน schema.sql
$inactiveDrugBatch = [
    [
        'hos_guid'      => '{GUID-ACTIVE-OK}',
        'vn'            => 'VN100',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901001', // Active
        'qty'           => 5,
    ],
    [
        'hos_guid'      => '{GUID-INACTIVE-BLOCKED}',
        'vn'            => 'VN101',
        'hcode'         => '01425',
        'vstdate'       => '2026-10-27',
        'tpu_code_list' => '100789000012345678901004', // Inactive (Banned)
        'qty'           => 2,
    ],
];

$res13 = $client->sendDispenseBatch($validHcode, $inactiveDrugBatch);

assertTest("TC 13.1: Batch with inactive drug returns HTTP 200 (Partial success)", $res13['http_code'] === 200);
assertTest("TC 13.2: Exactly 1 record inserted/updated (Active drug)", $res13['inserted_or_updated'] === 1);
assertTest("TC 13.3: Exactly 1 record failed (Inactive drug)", $res13['failed'] === 1);
assertTest("TC 13.4: Error specifies DRUG_INACTIVE matching Phase 2 schema", 
    !empty($res13['errors']) && $res13['errors'][0]['error_code'] === 'DRUG_INACTIVE'
);

echo "\n=================================================================\n";
echo "   PHASE 3.5 TEST SUMMARY: {$passed}/" . ($passed + $failed) . " PASSED (" . round($passed / ($passed + $failed) * 100) . "%)\n";
if ($failed === 0) {
    echo "   STATUS: ALL 13 TEST SCENARIOS PASSED SUCCESSFULLY! \n";
} else {
    echo "   STATUS: SOME TESTS FAILED!\n";
}
echo "=================================================================\n\n";
exit($failed > 0 ? 1 : 0);
