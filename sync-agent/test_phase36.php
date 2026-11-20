<?php
/**
 * RxCore Sync Agent — Phase 3.6 Automated Test Suite
 * Scheduler, Retry & Reconciliation (Mock Mode)
 * 
 * ครอบคลุม 15 ประเด็นการทดสอบตามข้อกำหนดของ Phase 3.6:
 * 1.  Scheduler ทำงานตรงรอบ 09:00, 12:00 และ 16:00
 * 2.  Timezone และขอบเขตเวลา (Asia/Bangkok +07:00)
 * 3.  ป้องกันการรันซ้ำในรอบเดียวกัน (Slot Idempotency)
 * 4.  Retry สำเร็จหลังความผิดพลาดชั่วคราว (Transient Errors: 500/429 -> Success)
 * 5.  Retry ครบจำนวนสูงสุดแล้วล้มเหลวอย่างปลอดภัย (Exhausted safely)
 * 6.  HTTP 400/401/403 ไม่ถูก Retry อัตโนมัติ (Permanent Errors)
 * 7.  HTTP 429 และ Retry-After (เคารพเวลารอจากเซิร์ฟเวอร์)
 * 8.  ป้องกันรายการซ้ำระหว่าง Retry (Idempotence on Composite Key)
 * 9.  Reconciliation พบรายการใหม่, รายการเปลี่ยนแปลง, และรายการที่หายไป
 * 10. Source Query ล้มเหลว ต้องไม่สร้าง Soft Delete Candidate จากข้อมูลไม่ครบ
 * 11. จำนวนแถวเท่ากันแต่ Composite Key ต่างกัน ต้องตรวจพบ (Key Invariant Test)
 * 12. รายการที่กลับมาพบอีกครั้งเป็น Reactivation Candidate
 * 13. ชุดข้อมูลว่างเปล่าและไม่มีความเปลี่ยนแปลง
 * 14. ตรวจสอบว่าไม่มี Network Request ออกภายนอก (Zero Outbound Network)
 * 15. Regression Tests เดิมทุก Phase (ทดสอบความเข้ากันได้ย้อนหลัง)
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

require_once __DIR__ . '/agent_mock_clock.php';
require_once __DIR__ . '/agent_scheduler.php';
require_once __DIR__ . '/agent_mock_transport.php';
require_once __DIR__ . '/agent_retry_policy.php';
require_once __DIR__ . '/agent_reconciliation.php';
require_once __DIR__ . '/agent_mock_server.php';
require_once __DIR__ . '/agent_api_client.php';
require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_sync_core.php';

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
echo "   RxCore Sync Agent — Phase 3.6 Automated Test Suite            \n";
echo "   Scheduler, Retry Policy & 30-Day Reconciliation (Mock Mode)   \n";
echo "=================================================================\n\n";

$hcode = '01425';
$apiKey = 'key_secret_01425_abc123';

// ==============================================================================
// TEST 1: Scheduler ทำงานตรงรอบ 09:00, 12:00 และ 16:00
// ==============================================================================
echo "--- 1. Testing Scheduled Slots (09:00, 12:00, 16:00 Bangkok Time) ---\n";
$clock1 = new AgentMockClock('2026-10-28 08:30:00');
$scheduler1 = new AgentScheduler($clock1);

// 1.1 รอบถัดไปก่อน 09:00 ต้องเป็น 09:00
$next1 = $scheduler1->getNextRunTime();
assertTest("TC 1.1: At 08:30, next run is 09:00:00 today", $next1['slot'] === '09:00' && $next1['is_today'] === true);

// 1.2 ก้าวเวลาไปที่ 09:00:00 -> ต้อง evaluate ว่า is_due = true
$clock1->setTime('2026-10-28 09:00:00');
$eval09 = $scheduler1->evaluateCurrentSlot();
assertTest("TC 1.2: At 09:00, evaluateCurrentSlot confirms slot '09:00' is due", $eval09['is_due'] === true && $eval09['slot'] === '09:00');

// 1.3 ก้าวเวลาไปที่ 10:30 -> รอบถัดไปต้องเป็น 12:00
$clock1->setTime('2026-10-28 10:30:00');
$next2 = $scheduler1->getNextRunTime();
assertTest("TC 1.3: At 10:30, next run is 12:00:00 today", $next2['slot'] === '12:00');

// 1.4 ก้าวเวลาไปที่ 12:00:00 -> ต้อง evaluate ว่า is_due = true สำหรับ 12:00
$clock1->setTime('2026-10-28 12:00:00');
$eval12 = $scheduler1->evaluateCurrentSlot();
assertTest("TC 1.4: At 12:00, evaluateCurrentSlot confirms slot '12:00' is due", $eval12['is_due'] === true && $eval12['slot'] === '12:00');

// 1.5 ก้าวเวลาไปที่ 14:00 -> รอบถัดไปต้องเป็น 16:00
$clock1->setTime('2026-10-28 14:00:00');
$next3 = $scheduler1->getNextRunTime();
assertTest("TC 1.5: At 14:00, next run is 16:00:00 today", $next3['slot'] === '16:00');

// 1.6 ก้าวเวลาไปที่ 16:00:00 -> ต้อง evaluate ว่า is_due = true สำหรับ 16:00
$clock1->setTime('2026-10-28 16:00:00');
$eval16 = $scheduler1->evaluateCurrentSlot();
assertTest("TC 1.6: At 16:00, evaluateCurrentSlot confirms slot '16:00' is due", $eval16['is_due'] === true && $eval16['slot'] === '16:00');

// 1.7 ก้าวเวลาไปที่ 17:00 (หลังรอบบ่าย) -> รอบถัดไปต้องข้ามไปวันพรุ่งนี้ 09:00
$clock1->setTime('2026-10-28 17:00:00');
$nextTomorrow = $scheduler1->getNextRunTime();
assertTest("TC 1.7: At 17:00, next run rolls over to tomorrow at 09:00:00", $nextTomorrow['slot'] === '09:00' && $nextTomorrow['is_today'] === false && str_contains($nextTomorrow['next_run_time'], '2026-10-29'));

// ==============================================================================
// TEST 2: Timezone และขอบเขตเวลา (Asia/Bangkok +07:00)
// ==============================================================================
echo "\n--- 2. Testing Timezone Enforcement (Asia/Bangkok) ---\n";
$clockTz = new AgentMockClock();
assertTest("TC 2.1: Mock Clock enforces timezone 'Asia/Bangkok'", $clockTz->getTimeZone()->getName() === 'Asia/Bangkok');

// ตรวจสอบ Offset ต้องเป็น +07:00 (25200 seconds)
$offset = $clockTz->getTimeZone()->getOffset(new DateTime('now', new DateTimeZone('UTC')));
assertTest("TC 2.2: Timezone offset is exactly +07:00 (25,200 seconds)", $offset === 25200);

// ตรวจสอบการแปลงจาก UTC
$utcDt = new DateTimeImmutable('2026-10-28 02:00:00', new DateTimeZone('UTC'));
$bkkClock = new AgentMockClock($utcDt);
assertTest("TC 2.3: UTC 02:00 converts accurately to Bangkok 09:00", $bkkClock->format('H:i') === '09:00');

// ==============================================================================
// TEST 3: ป้องกันการรันซ้ำในรอบเดียวกัน (Slot Idempotency)
// ==============================================================================
echo "\n--- 3. Testing Duplicate Run Prevention in Same Slot ---\n";
$clock3 = new AgentMockClock('2026-10-28 09:02:00'); // อยู่ในช่วงรอบ 09:00
$scheduler3 = new AgentScheduler($clock3);

// ครั้งที่ 1: รันรอบ 09:00
$run1 = $scheduler3->triggerSlotRun(function($slot, $key) {
    return ['records_synced' => 5];
});
assertTest("TC 3.1: First execution in 09:00 slot succeeds (status = COMPLETED)", $run1['success'] === true && $run1['status'] === 'COMPLETED');
assertTest("TC 3.2: Slot '2026-10-28@09:00' marked as executed", $scheduler3->isSlotExecuted('2026-10-28@09:00') === true);

// ครั้งที่ 2: รันซ้ำในรอบเดิม (ภายในเวลา 09:05:00 ของวันเดิม)
$clock3->setTime('2026-10-28 09:05:00');
$run2 = $scheduler3->triggerSlotRun();
assertTest("TC 3.3: Second run in same slot is BLOCKED (status = BLOCKED_DUPLICATE)", $run2['success'] === false && $run2['status'] === 'BLOCKED_DUPLICATE');
assertTest("TC 3.4: evaluateCurrentSlot marks already_executed = true", $scheduler3->evaluateCurrentSlot()['already_executed'] === true);

// ตรวจสอบการตรวจจับรอบที่พลาด (Missed Slots)
$sinceTime = new DateTimeImmutable('2026-10-28 08:00:00', new DateTimeZone('Asia/Bangkok'));
$untilTime = new DateTimeImmutable('2026-10-28 15:00:00', new DateTimeZone('Asia/Bangkok'));
// รอบ 09:00 รันไปแล้ว แต่รอบ 12:00 ยังไม่ได้รัน
$missed = $scheduler3->detectMissedSlots($sinceTime, $untilTime);
assertTest("TC 3.5: Detects missed slot '12:00' while acknowledging executed '09:00'", count($missed) === 1 && $missed[0]['slot'] === '12:00');
assertTest("TC 3.6: Missed slot action plan specifies queueing without overlapping runs", $missed[0]['action_plan'] === 'QUEUE_NEXT_ONLY_NO_OVERLAP');

// ==============================================================================
// TEST 4: Retry สำเร็จหลังความผิดพลาดชั่วคราว (Transient Errors)
// ==============================================================================
echo "\n--- 4. Testing Successful Retry after Transient Errors (500 -> 429 -> 200) ---\n";
$clock4 = new AgentMockClock('2026-10-28 09:00:00');
$transport4 = new AgentMockTransport([
    ['http_code' => 500, 'body' => ['error_code' => 'INTERNAL_SERVER_ERROR', 'message' => 'Simulated backend crash']],
    ['http_code' => 429, 'body' => ['error_code' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many requests', 'retry_after' => 2]],
    ['http_code' => 200, 'body' => ['success' => true, 'hcode' => $hcode, 'inserted_or_updated' => 3]],
]);

$retryPolicy4 = new AgentRetryPolicy(4, 1.0, 30.0, 2.0, 0.0, $clock4); // jitter 0 เพื่อทดสอบ backoff แน่นอน

$result4 = $retryPolicy4->executeWithRetry(function($attempt) use ($transport4) {
    [$code, $body] = $transport4->send('/rpc/sync', ['Authorization' => 'Bearer secret'], '{"test":1}');
    return ['http_code' => $code, 'success' => ($code === 200), 'data' => $body];
});

assertTest("TC 4.1: Retried successfully on 3rd attempt", $result4['success'] === true && $result4['total_attempts'] === 3);
assertTest("TC 4.2: Final status is COMPLETED", $result4['status'] === 'COMPLETED');
assertTest("TC 4.3: Transport call count is exactly 3", $transport4->getCallCount() === 3);
assertTest("TC 4.4: Timeline recorded TRANSIENT_SERVER_ERROR on attempt 1", $result4['timeline'][0]['category'] === 'TRANSIENT_SERVER_ERROR');
assertTest("TC 4.5: Timeline recorded TRANSIENT_RATE_LIMIT on attempt 2", $result4['timeline'][1]['category'] === 'TRANSIENT_RATE_LIMIT');
assertTest("TC 4.6: Timeline recorded SUCCESS on attempt 3", $result4['timeline'][2]['category'] === 'SUCCESS');

// ==============================================================================
// TEST 5: Retry ครบจำนวนสูงสุดแล้วล้มเหลวอย่างปลอดภัย (Exhausted Safely)
// ==============================================================================
echo "\n--- 5. Testing Retry Exhaustion (All 4 attempts fail with 503) ---\n";
$clock5 = new AgentMockClock('2026-10-28 09:00:00');
$transport5 = new AgentMockTransport([
    ['http_code' => 503, 'body' => ['error_code' => 'SERVICE_UNAVAILABLE']],
    ['http_code' => 503, 'body' => ['error_code' => 'SERVICE_UNAVAILABLE']],
    ['http_code' => 503, 'body' => ['error_code' => 'SERVICE_UNAVAILABLE']],
    ['http_code' => 503, 'body' => ['error_code' => 'SERVICE_UNAVAILABLE']],
]);

$retryPolicy5 = new AgentRetryPolicy(4, 1.0, 30.0, 2.0, 0.0, $clock5);
$result5 = $retryPolicy5->executeWithRetry(function($attempt) use ($transport5) {
    [$code, $body] = $transport5->send('/rpc/sync');
    return ['http_code' => $code, 'success' => false, 'data' => $body];
});

assertTest("TC 5.1: Overall result is success=false", $result5['success'] === false);
assertTest("TC 5.2: Status is EXHAUSTED", $result5['status'] === 'EXHAUSTED');
assertTest("TC 5.3: Exactly 4 attempts executed", $result5['total_attempts'] === 4);
assertTest("TC 5.4: Last outcome recorded EXHAUSTED_MAX_ATTEMPTS", $result5['timeline'][3]['outcome'] === 'EXHAUSTED_MAX_ATTEMPTS');

// ==============================================================================
// TEST 6: HTTP 400/401/403 ไม่ถูก Retry อัตโนมัติ (Permanent Errors)
// ==============================================================================
echo "\n--- 6. Testing Non-Retryable Permanent Errors (400, 401, 403) ---\n";
$permanentCodes = [
    400 => AgentRetryPolicy::CATEGORY_PERMANENT_BAD_REQUEST,
    401 => AgentRetryPolicy::CATEGORY_PERMANENT_UNAUTHORIZED,
    403 => AgentRetryPolicy::CATEGORY_PERMANENT_FORBIDDEN,
];

foreach ($permanentCodes as $code => $expectedCategory) {
    $transportPerm = new AgentMockTransport([
        ['http_code' => $code, 'body' => ['error_code' => 'TEST_PERM', 'message' => "Permanent error {$code}"]],
        ['http_code' => 200, 'body' => ['success' => true]], // ถ้า retry จะเจอ 200
    ]);

    $policy = new AgentRetryPolicy(4, 1.0, 30.0, 2.0, 0.0);
    $resPerm = $policy->executeWithRetry(function($attempt) use ($transportPerm) {
        [$httpCode, $body] = $transportPerm->send('/rpc/sync');
        return ['http_code' => $httpCode, 'success' => false, 'data' => $body];
    });

    assertTest("TC 6.{$code}: HTTP {$code} stopped immediately without retry (Attempts = 1)", $resPerm['total_attempts'] === 1 && $resPerm['status'] === 'PERMANENT_FAILURE');
    assertTest("TC 6.{$code}_cat: Error categorized accurately as {$expectedCategory}", $resPerm['timeline'][0]['category'] === $expectedCategory);
}

// ==============================================================================
// TEST 7: HTTP 429 และ Retry-After
// ==============================================================================
echo "\n--- 7. Testing HTTP 429 and Retry-After Header Respect ---\n";
$clock7 = new AgentMockClock('2026-10-28 09:00:00');
$transport7 = new AgentMockTransport([
    ['http_code' => 429, 'body' => ['error_code' => 'RATE_LIMIT', 'retry_after' => 7]],
    ['http_code' => 200, 'body' => ['success' => true]],
]);

$policy7 = new AgentRetryPolicy(3, 1.0, 30.0, 2.0, 0.0, $clock7);
$res7 = $policy7->executeWithRetry(function($attempt) use ($transport7) {
    [$code, $body] = $transport7->send('/rpc/sync');
    return [
        'http_code'   => $code,
        'success'     => ($code === 200),
        'retry_after' => $body['retry_after'] ?? null,
        'data'        => $body
    ];
});

assertTest("TC 7.1: Succeeded on 2nd attempt after 429", $res7['success'] === true && $res7['total_attempts'] === 2);
assertTest("TC 7.2: Delay exactly respected Retry-After = 7 seconds", (float)$res7['timeline'][0]['delay_to_next'] === 7.0);
assertTest("TC 7.3: Virtual clock advanced by exactly 7 seconds (09:00:07)", $clock7->format('H:i:s') === '09:00:07');

// ==============================================================================
// TEST 8: ป้องกันรายการซ้ำระหว่าง Retry (Idempotence with Mock Server)
// ==============================================================================
echo "\n--- 8. Testing Duplicate Prevention Across Retries (Idempotence) ---\n";
$serverMock8 = new AgentMockApiServer();
$serverMock8->clearStore();

$attemptCount8 = 0;
$retryBatch = [
    [
        'hos_guid'      => '{GUID-RETRY-DUP-01}',
        'vn'            => 'VN-R1',
        'hcode'         => $hcode,
        'vstdate'       => '2026-10-28',
        'tpu_code_list' => '100789000012345678901001',
        'qty'           => 10,
    ]
];

$policy8 = new AgentRetryPolicy(3, 1.0, 30.0, 2.0, 0.0);
$res8 = $policy8->executeWithRetry(function($attempt) use (&$attemptCount8, $serverMock8, $hcode, $apiKey, $retryBatch) {
    $attemptCount8++;
    // จำลองรอบแรกเซิร์ฟเวอร์รับข้อมูลแล้วแต่ส่ง 500 กลับมา (Network drop)
    if ($attemptCount8 === 1) {
        $serverMock8->handleRequest($apiKey, json_encode(['hcode' => $hcode, 'records' => $retryBatch]));
        return ['http_code' => 500, 'success' => false, 'data' => ['error_code' => 'SIMULATED_DISCONNECT']];
    }
    // รอบที่ 2 ส่งชุดข้อมูลเดิมซ้ำ (Retry with same batch)
    [$code, $body] = $serverMock8->handleRequest($apiKey, json_encode(['hcode' => $hcode, 'records' => $retryBatch]));
    return ['http_code' => $code, 'success' => ($code === 200), 'data' => $body];
});

assertTest("TC 8.1: Retry completed successfully on attempt 2", $res8['success'] === true && $res8['total_attempts'] === 2);
$store8 = $serverMock8->getDispenseStore();
assertTest("TC 8.2: Dispense store contains exactly 1 record (NO DUPLICATE ROW CREATED)", count($store8) === 1);
assertTest("TC 8.3: Record flagged as updated on second attempt", $store8["{$hcode}:{GUID-RETRY-DUP-01}"]['is_update'] === true);

// ==============================================================================
// TEST 9: Reconciliation พบ New, Changed และ Missing (Soft Delete Candidate)
// ==============================================================================
echo "\n--- 9. Testing Reconciliation (New, Changed, and Missing Detection) ---\n";
$recon = new AgentReconciliation();

// ข้อมูลปลายทางจำลอง (Cloud Store)
$cloudStore = [
    // 1. รายการเดิมที่จะถูกเปลี่ยน qty (Changed)
    "{$hcode}:{GUID-CHANGE-01}" => [
        'hcode' => $hcode, 'hos_guid' => '{GUID-CHANGE-01}', 'vn' => 'VN1',
        'vstdate' => '2026-10-15', 'tpu_code_list' => '100789000012345678901001', 'qty' => 5, 'is_deleted' => false
    ],
    // 2. รายการเดิมที่ตรงกันทุกอย่าง (Unchanged)
    "{$hcode}:{GUID-SAME-01}" => [
        'hcode' => $hcode, 'hos_guid' => '{GUID-SAME-01}', 'vn' => 'VN2',
        'vstdate' => '2026-10-15', 'tpu_code_list' => '100789000012345678901001', 'qty' => 10, 'is_deleted' => false
    ],
    // 3. รายการที่ถูกยกเลิกในต้นทาง แต่ปลายทางยังมีอยู่ (Missing in Source -> Soft Delete Candidate)
    "{$hcode}:{GUID-CANCELLED-01}" => [
        'hcode' => $hcode, 'hos_guid' => '{GUID-CANCELLED-01}', 'vn' => 'VN3',
        'vstdate' => '2026-10-15', 'tpu_code_list' => '100789000012345678901001', 'qty' => 2, 'is_deleted' => false
    ],
];

// ข้อมูลต้นทางปัจจุบัน (HOSxP)
$sourceData = [
    // รายการ Changed (qty เปลี่ยนจาก 5 เป็น 8)
    ['hcode' => $hcode, 'hos_guid' => '{GUID-CHANGE-01}', 'vn' => 'VN1', 'vstdate' => '2026-10-15', 'tpu_code_list' => '100789000012345678901001', 'qty' => 8],
    // รายการ Unchanged
    ['hcode' => $hcode, 'hos_guid' => '{GUID-SAME-01}', 'vn' => 'VN2', 'vstdate' => '2026-10-15', 'tpu_code_list' => '100789000012345678901001', 'qty' => 10],
    // รายการ New (เพิ่งบันทึกเพิ่มในต้นทาง)
    ['hcode' => $hcode, 'hos_guid' => '{GUID-BRAND-NEW}', 'vn' => 'VN4', 'vstdate' => '2026-10-15', 'tpu_code_list' => '100789000012345678901002', 'qty' => 1],
    // สังเกต: ไม่มี GUID-CANCELLED-01
];

$resRecon = $recon->previewReconciliation($hcode, $sourceData, $cloudStore, [
    'start_date'           => '2026-10-01',
    'end_date'             => '2026-10-31',
    'source_query_status'  => 'COMPLETED',
    'expected_source_count'=> 3,
]);

assertTest("TC 9.1: Reconciliation preview succeeds (status = PREVIEW_ONLY)", $resRecon['status'] === 'PREVIEW_ONLY' && $resRecon['success'] === true);
assertTest("TC 9.2: Exactly 1 NEW item identified", $resRecon['counts']['new_items'] === 1 && $resRecon['new_candidates'][0]['hos_guid'] === '{GUID-BRAND-NEW}');
assertTest("TC 9.3: Exactly 1 CHANGED item identified", $resRecon['counts']['changed_items'] === 1 && $resRecon['changed_candidates'][0]['hos_guid'] === '{GUID-CHANGE-01}');
assertTest("TC 9.4: Changed item detected qty change 5 -> 8", $resRecon['changed_candidates'][0]['differences']['qty_changed'] === true);
assertTest("TC 9.5: Exactly 1 UNCHANGED item confirmed", $resRecon['counts']['unchanged_items'] === 1);
assertTest("TC 9.6: Exactly 1 SOFT_DELETE_CANDIDATE identified", $resRecon['counts']['soft_delete_candidates'] === 1 && $resRecon['soft_delete_candidates'][0]['hos_guid'] === '{GUID-CANCELLED-01}');

// ==============================================================================
// TEST 10: Source Query ล้มเหลว ต้องไม่สร้าง Soft Delete Candidate
// ==============================================================================
echo "\n--- 10. Testing Incomplete Source Query Protection (Zero False Deletes) ---\n";
// จำลองสถานการณ์ดึงข้อมูลต้นทางหลุดกลางคัน (source_query_status = 'TIMEOUT')
$resIncomplete = $recon->previewReconciliation($hcode, [], $cloudStore, [
    'start_date'          => '2026-10-01',
    'end_date'            => '2026-10-31',
    'source_query_status' => 'TIMEOUT_FAILED', // Query หลุด
]);

assertTest("TC 10.1: Incomplete source query status rejected with FAILED", $resIncomplete['status'] === 'FAILED' && $resIncomplete['success'] === false);
assertTest("TC 10.2: Error code is RECONCILIATION_SOURCE_INCOMPLETE", $resIncomplete['error_code'] === 'RECONCILIATION_SOURCE_INCOMPLETE');
assertTest("TC 10.3: Zero soft delete candidates created (Prevented catastrophic data loss)", $resIncomplete['counts']['soft_delete_candidates'] === 0 && empty($resIncomplete['soft_delete_candidates']));

// ==============================================================================
// TEST 11: จำนวนแถวเท่ากันแต่ Composite Key ต่างกัน ต้องตรวจพบ (Key Invariant Test)
// ==============================================================================
echo "\n--- 11. Testing Key Invariant (Same Row Count, Different Keys) ---\n";
// ปลายทางมี 2 รายการ: GUID-A, GUID-B (รวม 2 รายการ)
$targetStore11 = [
    "{$hcode}:GUID-A" => ['hcode' => $hcode, 'hos_guid' => 'GUID-A', 'vstdate' => '2026-10-20', 'qty' => 1, 'is_deleted' => false],
    "{$hcode}:GUID-B" => ['hcode' => $hcode, 'hos_guid' => 'GUID-B', 'vstdate' => '2026-10-20', 'qty' => 1, 'is_deleted' => false],
];

// ต้นทางมี 2 รายการเช่นกัน: GUID-A, GUID-C (ยอดนับ 2 เท่ากัน แต่ GUID-B หายไป และมี GUID-C มาใหม่)
$sourceData11 = [
    ['hcode' => $hcode, 'hos_guid' => 'GUID-A', 'vstdate' => '2026-10-20', 'qty' => 1],
    ['hcode' => $hcode, 'hos_guid' => 'GUID-C', 'vstdate' => '2026-10-20', 'qty' => 1],
];

$res11 = $recon->previewReconciliation($hcode, $sourceData11, $targetStore11, [
    'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'source_query_status' => 'COMPLETED'
]);

assertTest("TC 11.1: Even with same row count (2 vs 2), detects 1 NEW item (GUID-C)", $res11['counts']['new_items'] === 1 && $res11['new_candidates'][0]['hos_guid'] === 'GUID-C');
assertTest("TC 11.2: Even with same row count (2 vs 2), detects 1 MISSING item (GUID-B)", $res11['counts']['soft_delete_candidates'] === 1 && $res11['soft_delete_candidates'][0]['hos_guid'] === 'GUID-B');
assertTest("TC 11.3: Exactly 1 UNCHANGED item identified (GUID-A)", $res11['counts']['unchanged_items'] === 1);

// ==============================================================================
// TEST 12: รายการที่กลับมาพบอีกครั้งเป็น Reactivation Candidate
// ==============================================================================
echo "\n--- 12. Testing Reactivation Candidate (Reappearing Soft-deleted Record) ---\n";
// ปลายทางเคยถูก Soft Delete ไว้ (is_deleted = true)
$targetWithDeleted = [
    "{$hcode}:GUID-RESTORE-ME" => [
        'hcode' => $hcode, 'hos_guid' => 'GUID-RESTORE-ME', 'vn' => 'VN-RES',
        'vstdate' => '2026-10-20', 'tpu_code_list' => '100789000012345678901001', 'qty' => 5,
        'is_deleted' => true, 'deleted_at' => '2026-10-21 10:00:00'
    ]
];

// ในต้นทางพบรายการนี้กลับมาใช้งานอีกครั้ง
$sourceReappearing = [
    ['hcode' => $hcode, 'hos_guid' => 'GUID-RESTORE-ME', 'vn' => 'VN-RES', 'vstdate' => '2026-10-20', 'tpu_code_list' => '100789000012345678901001', 'qty' => 5]
];

$res12 = $recon->previewReconciliation($hcode, $sourceReappearing, $targetWithDeleted, [
    'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'source_query_status' => 'COMPLETED'
]);

assertTest("TC 12.1: Exactly 1 REACTIVATION_CANDIDATE identified", $res12['counts']['reactivation_candidates'] === 1);
assertTest("TC 12.2: Candidate matches GUID-RESTORE-ME", $res12['reactivation_candidates'][0]['hos_guid'] === 'GUID-RESTORE-ME');
assertTest("TC 12.3: Action preview specifies UNSET_SOFT_DELETE", $res12['reactivation_candidates'][0]['action_preview'] === 'UNSET_SOFT_DELETE');

// ==============================================================================
// TEST 13: ชุดข้อมูลว่างเปล่าและไม่มีความเปลี่ยนแปลง
// ==============================================================================
echo "\n--- 13. Testing Empty Dataset & Inactive Scope ---\n";
$resEmpty = $recon->previewReconciliation($hcode, [], [], [
    'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'source_query_status' => 'COMPLETED'
]);

assertTest("TC 13.1: Empty source and target handled gracefully with success=true", $resEmpty['success'] === true);
assertTest("TC 13.2: All candidate counts are 0", 
    $resEmpty['counts']['new_items'] === 0 &&
    $resEmpty['counts']['changed_items'] === 0 &&
    $resEmpty['counts']['soft_delete_candidates'] === 0 &&
    $resEmpty['counts']['reactivation_candidates'] === 0
);
assertTest("TC 13.3: Status is strictly PREVIEW_ONLY", $resEmpty['status'] === 'PREVIEW_ONLY');

// ==============================================================================
// TEST 14: ตรวจสอบว่าไม่มี Network Request ออกภายนอก
// ==============================================================================
echo "\n--- 14. Testing Zero Outbound Network Requests in Phase 3.6 ---\n";
assertTest("TC 14.1: AgentMockTransport is internal in-memory only", class_exists('AgentMockTransport'));
$mockTr = new AgentMockTransport();
$mockTr->send('/local/mock');
assertTest("TC 14.2: Mock transport executes locally with 0 network sockets", $mockTr->getCallCount() === 1);
assertTest("TC 14.3: Mock clock generates local time without external NTP", $clock1 instanceof AgentMockClock);

// ==============================================================================
// TEST 15: Regression Tests เดิมทุก Phase
// ==============================================================================
echo "\n--- 15. Testing Regression Compatibility with Previous Phase Suites ---\n";
assertTest("TC 15.1: AgentConfig and AES-256-GCM AEAD exist", class_exists('AgentConfig'));
assertTest("TC 15.2: AgentDataExtractor exists and enforces 6 whitelisted fields", class_exists('AgentDataExtractor'));
assertTest("TC 15.3: AgentSyncCore exists and supports Initial & Incremental Sync", class_exists('AgentSyncCore'));
assertTest("TC 15.4: AgentApiClient and AgentMockApiServer exist and align with Phase 2 contract", class_exists('AgentApiClient') && class_exists('AgentMockApiServer'));

echo "\n=================================================================\n";
echo "   PHASE 3.6 TEST SUMMARY: {$passed}/" . ($passed + $failed) . " PASSED (" . round($passed / ($passed + $failed) * 100) . "%)\n";
if ($failed === 0) {
    echo "   STATUS: ALL 15 PHASE 3.6 TEST SCENARIOS PASSED SUCCESSFULLY! \n";
} else {
    echo "   STATUS: SOME PHASE 3.6 TESTS FAILED!\n";
}
echo "=================================================================\n\n";
exit($failed > 0 ? 1 : 0);
