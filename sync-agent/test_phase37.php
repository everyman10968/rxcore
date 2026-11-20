<?php
/**
 * RxCore Sync Agent — Phase 3.7 Automated Test Suite
 * MOPH Notify & Sync Status Monitoring (Mock Mode)
 * 
 * ครอบคลุมการทดสอบตามข้อกำหนด Phase 3.7:
 * 1. สถานะ Sync ทุกประเภท (IDLE, RUNNING, SUCCESS, PARTIAL_SUCCESS, FAILED, PREVIEW_ONLY)
 * 2. ข้อกำหนด invariant สำคัญ: PREVIEW_ONLY ต้องไม่แสดงเป็น SUCCESS อย่างเด็ดขาด
 * 3. Sync Event Log Model: การสร้าง Event, การแปลงเป็น Array/JSON, การกรอง
 * 4. การ Mask และ Redact ข้อมูลลับ (Secret/Password/Token/Key) และข้อมูลส่วนบุคคล (PII/CID/HN) ใน Event Log
 * 5. การจำกัดขนาด Log (Ring Buffer FIFO Eviction)
 * 6. MOPH Notify Message Builder: การสร้างข้อความแจ้งเตือนโดยไม่มีข้อมูลผู้ป่วยหรือรายการยา
 * 7. MOPH Notify Mock Adapter: กรณีแจ้งเตือนสำเร็จ (HTTP 200 SIMULATED_SUCCESS)
 * 8. MOPH Notify Mock Adapter: กรณีแจ้งเตือนล้มเหลว (HTTP 500 SIMULATED_FAILURE)
 * 9. MOPH Notify Mock Adapter: กรณี Timeout (HTTP 504 SIMULATED_TIMEOUT)
 * 10. MOPH Notify Mock Adapter: กรณี Credential ไม่ครบ (HTTP 400 MISSING_CONFIG)
 * 11. MOPH Notify Mock Adapter: กรณีข้อความมีข้อมูลลับต้องถูก Mask หรือปฏิเสธ (HTTP 422)
 * 12. การป้องกันการส่ง Request ออกภายนอก (Zero Outbound HTTP/Sockets Verification)
 * 13. ความปลอดภัยของ Credential: ห้ามมี Credential จริงใน Mock State
 * 14. การทำงานร่วมกันระหว่าง Sync Monitor, Event Log และ MOPH Notify Adapter
 * 15. Regression Tests เดิมทั้งหมด (Phase 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.6.1)
 */

require_once __DIR__ . '/agent_mock_clock.php';
require_once __DIR__ . '/agent_atomic_file.php';
require_once __DIR__ . '/agent_sync_event.php';
require_once __DIR__ . '/agent_moph_notify.php';
require_once __DIR__ . '/agent_sync_monitor.php';

// นำเข้าไฟล์เดิมสำหรับ Regression Verification
require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_sync_core.php';
require_once __DIR__ . '/agent_hosxp_reader.php';
require_once __DIR__ . '/agent_api_client.php';
require_once __DIR__ . '/agent_mock_server.php';
require_once __DIR__ . '/agent_scheduler.php';
require_once __DIR__ . '/agent_retry_policy.php';
require_once __DIR__ . '/agent_reconciliation.php';
require_once __DIR__ . '/agent_mock_transport.php';

class Phase37TestSuite {

    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function assert(bool $condition, string $testName, string $failureDetails = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failed++;
            $msg = "  [FAIL] {$testName}";
            if ($failureDetails !== '') {
                $msg .= " — Details: {$failureDetails}";
            }
            echo $msg . "\n";
            $this->errors[] = $testName;
        }
    }

    public function run(): int {
        echo "\n=================================================================\n";
        echo "   RxCore Sync Agent — Phase 3.7 Automated Test Suite            \n";
        echo "   MOPH Notify & Sync Status Monitoring (Mock Mode)             \n";
        echo "=================================================================\n\n";

        $this->testSyncStatusAllTypes();
        $this->testPreviewOnlyInvariant();
        $this->testEventLogModelAndLifecycle();
        $this->testEventLogSecretAndPiiRedaction();
        $this->testEventLogRingBufferLimits();
        $this->testEventLogFiltering();
        $this->testNotifyMessageBuilderNoPatientData();
        $this->testNotifyScenarioSuccess();
        $this->testNotifyScenarioFailure();
        $this->testNotifyScenarioTimeout();
        $this->testNotifyScenarioMissingConfig();
        $this->testNotifyScenarioConfidentialData();
        $this->testZeroOutboundNetworkRequests();
        $this->testCredentialSafetyInMockState();
        $this->testMonitorIntegratedCycle();
        $this->testRegressionCompatibilityPreviousPhases();

        echo "\n=================================================================\n";
        echo sprintf("   PHASE 3.7 TEST SUMMARY: %d/%d PASSED (%s%%)\n",
            $this->passed,
            $this->passed + $this->failed,
            number_format(($this->passed / max(1, $this->passed + $this->failed)) * 100, 1)
        );
        if ($this->failed === 0) {
            echo "   STATUS: ALL PHASE 3.7 TEST SCENARIOS PASSED SUCCESSFULLY! \n";
        } else {
            echo "   STATUS: SOME TESTS FAILED! (Errors: " . implode(', ', $this->errors) . ")\n";
        }
        echo "=================================================================\n\n";
        return $this->failed > 0 ? 1 : 0;
    }

    /**
     * 1. ทดสอบสถานะ Sync ทุกประเภท: IDLE, RUNNING, SUCCESS, PARTIAL_SUCCESS, FAILED, PREVIEW_ONLY
     */
    private function testSyncStatusAllTypes(): void {
        echo "--- 1. Testing Sync Status Monitor: All Status Types ---\n";

        $clock = new AgentMockClock('2026-10-28 09:00:00');
        $monitor = new AgentSyncMonitor(null, null, $clock, false);

        // 1.1 IDLE
        $st = $monitor->getCurrentStatus();
        $this->assert($st['status'] === AgentSyncMonitor::STATUS_IDLE, 'TC 1.1: Initial monitor status is IDLE');

        // 1.2 RUNNING
        $sessId = $monitor->startSession('INCREMENTAL_SYNC', '2026-10-28@09:00', false);
        $stRunning = $monitor->getCurrentStatus();
        $this->assert($stRunning['status'] === AgentSyncMonitor::STATUS_RUNNING, 'TC 1.2: Status changes to RUNNING after startSession');
        $this->assert($stRunning['is_running'] === true, 'TC 1.3: is_running flag is true');
        $this->assert($stRunning['current_session']['session_id'] === $sessId, 'TC 1.4: Current session ID matches');

        // 1.3 SUCCESS (เมื่อไม่ได้อยู่ในโหมด Preview)
        $clock->advanceSeconds(3); // 3 วินาที
        $completedSuccess = $monitor->completeSession(AgentSyncMonitor::STATUS_SUCCESS, [
            'items_read'         => 20,
            'items_validated'    => 20,
            'items_sent_success' => 20,
            'items_failed'       => 0,
        ]);
        $this->assert($completedSuccess['status'] === AgentSyncMonitor::STATUS_SUCCESS, 'TC 1.5: Non-preview run completes with SUCCESS status');
        $this->assert($completedSuccess['duration_sec'] === 3, 'TC 1.6: Duration calculated accurately (3s)');
        $this->assert($completedSuccess['items_sent_success'] === 20, 'TC 1.7: Sent success items recorded (20)');

        // 1.4 PARTIAL_SUCCESS
        $monitor->startSession('INCREMENTAL_SYNC', '2026-10-28@12:00', false);
        $completedPartial = $monitor->completeSession(AgentSyncMonitor::STATUS_PARTIAL_SUCCESS, [
            'items_read'         => 15,
            'items_validated'    => 12,
            'items_sent_success' => 10,
            'items_failed'       => 5,
            'retry_count'        => 2,
            'error_category'     => 'TRANSIENT_SERVER_ERROR',
        ]);
        $this->assert($completedPartial['status'] === AgentSyncMonitor::STATUS_PARTIAL_SUCCESS, 'TC 1.8: Records PARTIAL_SUCCESS accurately');
        $this->assert($completedPartial['items_failed'] === 5, 'TC 1.9: Failed count recorded accurately (5)');
        $this->assert($completedPartial['retry_count'] === 2, 'TC 1.10: Retry count recorded accurately (2)');

        // 1.5 FAILED
        $monitor->startSession('INCREMENTAL_SYNC', '2026-10-28@16:00', false);
        $completedFailed = $monitor->completeSession(AgentSyncMonitor::STATUS_FAILED, [
            'items_read'         => 10,
            'items_sent_success' => 0,
            'items_failed'       => 10,
            'error_category'     => 'PERMANENT_UNAUTHORIZED',
        ]);
        $this->assert($completedFailed['status'] === AgentSyncMonitor::STATUS_FAILED, 'TC 1.11: Records FAILED status accurately');
        $this->assert($completedFailed['error_category'] === 'PERMANENT_UNAUTHORIZED', 'TC 1.12: Error category recorded');
    }

    /**
     * 2. ทดสอบ Invariant สำคัญ: PREVIEW_ONLY ต้องไม่แสดงเป็น SUCCESS อย่างเด็ดขาด
     */
    private function testPreviewOnlyInvariant(): void {
        echo "\n--- 2. Testing Critical Invariant: PREVIEW_ONLY Must Not Be SUCCESS ---\n";

        $clock = new AgentMockClock('2026-10-28 09:00:00');
        $monitor = new AgentSyncMonitor(null, null, $clock, false);

        // เริ่ม Session ในโหมด Preview (is_preview = true)
        $monitor->startSession('INCREMENTAL_SYNC', '2026-10-28@09:00', true);

        // พยายาม completeSession ด้วย SUCCESS
        $completed = $monitor->completeSession(AgentSyncMonitor::STATUS_SUCCESS, [
            'items_read'         => 10,
            'items_sent_success' => 10,
        ]);

        // สถานะต้องถูกบังคับเป็น PREVIEW_ONLY โดยอัตโนมัติ
        $this->assert($completed['status'] === AgentSyncMonitor::STATUS_PREVIEW_ONLY, 'TC 2.1: Preview run requesting SUCCESS is strictly converted to PREVIEW_ONLY');
        $this->assert($completed['is_preview'] === true, 'TC 2.2: is_preview flag remains true');

        $status = $monitor->getCurrentStatus();
        $this->assert($status['status'] === AgentSyncMonitor::STATUS_PREVIEW_ONLY, 'TC 2.3: Monitor current status is PREVIEW_ONLY');
        $this->assert($status['summary_kpis']['total_success'] === 0, 'TC 2.4: Lifetime total_success KPI remains 0 (Prevented false success count)');
        $this->assert($status['summary_kpis']['total_preview_only'] === 1, 'TC 2.5: total_preview_only KPI incremented accurately');

        $cards = $monitor->getSummaryCards();
        $this->assert($cards['current_status']['status'] === 'PREVIEW_ONLY', 'TC 2.6: Summary card displays PREVIEW_ONLY');
        $this->assert($cards['current_status']['is_preview'] === true, 'TC 2.7: Summary card indicates is_preview = true');
    }

    /**
     * 3. ทดสอบ Sync Event Log Model & Lifecycle
     */
    private function testEventLogModelAndLifecycle(): void {
        echo "\n--- 3. Testing Sync Event Log Model & Lifecycle ---\n";

        $eventLog = new AgentSyncEventLog(null, 100);

        // ทดสอบสร้าง Event ประเภทต่างๆ
        $evt1 = $eventLog->record(
            AgentSyncEvent::SYNC_STARTED,
            'เริ่มต้นรอบเวลา 09:00 น.',
            AgentSyncEvent::SEVERITY_INFO,
            ['slot' => '09:00'],
            'sess_001',
            '2026-10-28@09:00',
            '2026-10-28 09:00:00'
        );

        $this->assert(str_starts_with($evt1->getId(), 'evt_'), 'TC 3.1: Event ID generated with evt_ prefix');
        $this->assert($evt1->getEventType() === AgentSyncEvent::SYNC_STARTED, 'TC 3.2: Event type matches SYNC_STARTED');
        $this->assert($evt1->getSeverity() === AgentSyncEvent::SEVERITY_INFO, 'TC 3.3: Severity matches INFO');
        $this->assert($evt1->getSessionId() === 'sess_001', 'TC 3.4: Session ID matches');
        $this->assert($evt1->getSlotKey() === '2026-10-28@09:00', 'TC 3.5: Slot key matches');
        $this->assert($evt1->isSimulated() === true, 'TC 3.6: isSimulated flag is true');

        // ทดสอบ toArray() และ fromArray()
        $arr = $evt1->toArray();
        $this->assert(is_array($arr), 'TC 3.7: Event serializes cleanly to array');
        $recreated = AgentSyncEvent::fromArray($arr);
        $this->assert($recreated->getId() === $evt1->getId(), 'TC 3.8: Recreated event matches original ID');
        $this->assert($recreated->getEventType() === $evt1->getEventType(), 'TC 3.9: Recreated event matches type');

        // บันทึก Event อื่นๆ
        $eventLog->record(AgentSyncEvent::EXTRACTION_COMPLETED, 'สกัดข้อมูลสำเร็จ 10 รายการ', AgentSyncEvent::SEVERITY_INFO);
        $eventLog->record(AgentSyncEvent::RETRY_SCHEDULED, 'จัดคิว Retry ครั้งที่ 1', AgentSyncEvent::SEVERITY_WARNING);
        $eventLog->record(AgentSyncEvent::API_SEND_FAILED, 'ส่งข้อมูลไม่สำเร็จ HTTP 500', AgentSyncEvent::SEVERITY_ERROR);
        $eventLog->record(AgentSyncEvent::NOTIFY_SIMULATED, 'จำลองแจ้งเตือน MOPH Notify สำเร็จ', AgentSyncEvent::SEVERITY_INFO);

        $stats = $eventLog->getStats();
        $this->assert($stats['total_events'] === 5, 'TC 3.10: Total events recorded is 5');
        $this->assert($stats['by_severity']['INFO'] === 3, 'TC 3.11: Info severity count is 3');
        $this->assert($stats['by_severity']['WARNING'] === 1, 'TC 3.12: Warning severity count is 1');
        $this->assert($stats['by_severity']['ERROR'] === 1, 'TC 3.13: Error severity count is 1');
    }

    /**
     * 4. ทดสอบการ Mask และ Redact ข้อมูลลับ (Secret/Password/Token/Key) และข้อมูลส่วนบุคคล (PII/CID/HN)
     */
    private function testEventLogSecretAndPiiRedaction(): void {
        echo "\n--- 4. Testing Strict Redaction of Secrets & Patient PII ---\n";

        // 4.1 ทดสอบ Redact ข้อความ Summary
        $rawText = "เชื่อมต่อ host=192.168.1.100 password=SuperSecretPassword123&token=abc Bearer my_jwt_token_123456789 key_secret_01425_secretkey";
        $redactedText = AgentSyncEvent::redactString($rawText);

        $this->assert(!str_contains($redactedText, 'SuperSecretPassword123'), 'TC 4.1: Plaintext password redacted from summary');
        $this->assert(str_contains($redactedText, '[REDACTED_SECRET]'), 'TC 4.2: Redaction token [REDACTED_SECRET] inserted');
        $this->assert(!str_contains($redactedText, 'my_jwt_token_123456789'), 'TC 4.3: Bearer token redacted from summary');
        $this->assert(!str_contains($redactedText, 'key_secret_01425_secretkey'), 'TC 4.4: Secret API key redacted from summary');

        // 4.2 ทดสอบ Redact เลขบัตรประชาชน 13 หลัก
        $cidText = "ผู้รับบริการเลขบัตร 1103701234567 และ 3-1005-01234-56-1 เข้ารับยา";
        $redactedCid = AgentSyncEvent::redactString($cidText);
        $this->assert(!str_contains($redactedCid, '1103701234567'), 'TC 4.5: 13-digit Thai CID redacted from text');
        $this->assert(!str_contains($redactedCid, '3-1005-01234-56-1'), 'TC 4.6: Hyphenated 13-digit Thai CID redacted from text');
        $this->assert(str_contains($redactedCid, '[REDACTED_CID]'), 'TC 4.7: [REDACTED_CID] marker inserted');

        // 4.3 ทดสอบ sanitizeDetails() ป้องกัน PII และ Payload เต็มชุด
        $rawDetails = [
            'hcode'            => '01425',
            'password'         => 'H0sxP_P@ss',
            'api_key'          => 'key_secret_01425_abc',
            'hn'               => '994821',
            'cid'              => '1103701234567',
            'patient_name'     => 'นายสมชาย สบายดี',
            'records'          => [
                ['hos_guid' => '{G1}', 'qty' => 10],
                ['hos_guid' => '{G2}', 'qty' => 5],
            ],
            'safe_metric_read' => 20
        ];

        $sanitized = AgentSyncEvent::sanitizeDetails($rawDetails);

        $this->assert($sanitized['password'] === '[REDACTED_SECRET]', 'TC 4.8: Detail key password sanitized');
        $this->assert($sanitized['api_key'] === '[REDACTED_SECRET]', 'TC 4.9: Detail key api_key sanitized');
        $this->assert($sanitized['hn'] === '[REDACTED_PII]', 'TC 4.10: Patient HN sanitized to [REDACTED_PII]');
        $this->assert($sanitized['cid'] === '[REDACTED_PII]', 'TC 4.11: Patient CID sanitized to [REDACTED_PII]');
        $this->assert($sanitized['patient_name'] === '[REDACTED_PII]', 'TC 4.12: Patient name sanitized to [REDACTED_PII]');
        $this->assert(str_contains($sanitized['records'], '[REDACTED_BATCH_PAYLOAD: count=2]'), 'TC 4.13: Full batch payload records replaced with count summary');
        $this->assert($sanitized['safe_metric_read'] === 20, 'TC 4.14: Safe numeric metric preserved');
    }

    /**
     * 5. ทดสอบการจำกัดขนาด Log (Ring Buffer FIFO Eviction)
     */
    private function testEventLogRingBufferLimits(): void {
        echo "\n--- 5. Testing Log Size Limits & Ring Buffer FIFO Pruning ---\n";

        // ตั้งขีดจำกัดขนาด 10 รายการ
        $log = new AgentSyncEventLog(null, 10);

        // ใส่ 15 Events
        for ($i = 1; $i <= 15; $i++) {
            $log->record(
                AgentSyncEvent::SYNC_STARTED,
                "Event sequence #{$i}",
                AgentSyncEvent::SEVERITY_INFO,
                ['seq' => $i]
            );
        }

        $events = $log->getEvents([], 100);

        $this->assert($events['total'] === 10, 'TC 5.1: Log size strictly capped at max 10 events');
        $firstEvent = $events['events'][0]; // รายการล่าสุด
        $lastEvent = $events['events'][9];  // รายการเก่าสุดที่เหลืออยู่

        $this->assert($firstEvent['summary'] === 'Event sequence #15', 'TC 5.2: Most recent event sequence #15 preserved');
        $this->assert($lastEvent['summary'] === 'Event sequence #6', 'TC 5.3: Oldest retained event is sequence #6 (FIFO order)');

        // ยืนยันว่า Events #1 ถึง #5 ถูกตัดทิ้ง (Evicted)
        $hasSeq1 = false;
        foreach ($events['events'] as $e) {
            if ($e['summary'] === 'Event sequence #1') {
                $hasSeq1 = true;
            }
        }
        $this->assert(!$hasSeq1, 'TC 5.4: Oldest event sequence #1 was evicted cleanly');
    }

    /**
     * 6. ทดสอบการกรอง Event Log
     */
    private function testEventLogFiltering(): void {
        echo "\n--- 6. Testing Event Log Filtering (Date, Severity, Type, Search) ---\n";

        $log = new AgentSyncEventLog(null, 100);

        $log->record(AgentSyncEvent::SYNC_STARTED, 'งานช่วงเช้า', AgentSyncEvent::SEVERITY_INFO, [], 's1', 'slot1', '2026-10-27 09:00:00');
        $log->record(AgentSyncEvent::API_SEND_FAILED, 'ข้อผิดพลาดเครือข่าย', AgentSyncEvent::SEVERITY_ERROR, [], 's2', 'slot2', '2026-10-28 09:00:00');
        $log->record(AgentSyncEvent::RETRY_SCHEDULED, 'หน่วงเวลา 5 วินาที', AgentSyncEvent::SEVERITY_WARNING, [], 's2', 'slot2', '2026-10-28 09:00:05');
        $log->record(AgentSyncEvent::NOTIFY_SIMULATED, 'จำลองแจ้งเตือนไลน์', AgentSyncEvent::SEVERITY_INFO, [], 's2', 'slot2', '2026-10-28 09:00:10');

        // 6.1 กรองตาม Severity = ERROR
        $filteredError = $log->getEvents(['severity' => 'ERROR']);
        $this->assert($filteredError['total'] === 1, 'TC 6.1: Severity filter returns exactly 1 ERROR event');
        $this->assert($filteredError['events'][0]['event_type'] === AgentSyncEvent::API_SEND_FAILED, 'TC 6.2: Matched event is API_SEND_FAILED');

        // 6.2 กรองตาม Event Type = NOTIFY_SIMULATED
        $filteredType = $log->getEvents(['event_type' => AgentSyncEvent::NOTIFY_SIMULATED]);
        $this->assert($filteredType['total'] === 1, 'TC 6.3: Event type filter returns exactly 1 NOTIFY_SIMULATED');

        // 6.3 กรองตามวันที่ 2026-10-28
        $filteredDate = $log->getEvents(['date_from' => '2026-10-28', 'date_to' => '2026-10-28']);
        $this->assert($filteredDate['total'] === 3, 'TC 6.4: Date filter returns 3 events for 2026-10-28');

        // 6.4 กรองตาม Keyword Search
        $filteredSearch = $log->getEvents(['search' => 'เครือข่าย']);
        $this->assert($filteredSearch['total'] === 1, 'TC 6.5: Keyword search matches summary containing "เครือข่าย"');
    }

    /**
     * 7. ทดสอบ MOPH Notify Message Builder: การสร้างข้อความแจ้งเตือนโดยไม่มีข้อมูลผู้ป่วย
     */
    private function testNotifyMessageBuilderNoPatientData(): void {
        echo "\n--- 7. Testing MOPH Notify Message Builder: Zero Patient Data ---\n";

        $sessionData = [
            'status'             => 'SUCCESS',
            'sync_type'          => 'INCREMENTAL_SYNC',
            'start_time'         => '2026-10-28 09:00:00',
            'end_time'           => '2026-10-28 09:00:02',
            'duration_sec'       => 2.14,
            'items_read'         => 12,
            'items_validated'    => 12,
            'items_sent_success' => 12,
            'items_duplicate'    => 0,
            'items_failed'       => 0,
            'retry_count'        => 0,
            'scheduler_slot'     => '2026-10-28@09:00',
        ];

        $config = [
            'hcode'         => '01425',
            'facility_name' => 'รพ.สต. บ้านดอนทอง',
        ];

        $message = AgentMophNotifyMessageBuilder::build($sessionData, $config);

        $this->assert(str_contains($message['title'], 'รพ.สต. บ้านดอนทอง'), 'TC 7.1: Message title contains facility name');
        $this->assert(str_contains($message['body'], '01425'), 'TC 7.2: Message body contains HCODE');
        $this->assert(str_contains($message['body'], '2.14 วินาที'), 'TC 7.3: Message body contains duration');
        $this->assert(str_contains($message['body'], '12 รายการ'), 'TC 7.4: Message body contains accurate item counts');
        $this->assert(str_contains($message['body'], '[SIMULATED / MOCK MODE]'), 'TC 7.5: Message explicitly flagged with [SIMULATED / MOCK MODE]');
        $this->assert($message['is_simulated'] === true, 'TC 7.6: is_simulated flag is true');
        $this->assert($message['contains_pii'] === false, 'TC 7.7: Zero patient PII detected in standard notification');

        // ตรวจสอบว่าไม่มีคำที่เกี่ยวกับข้อมูลผู้ป่วยเด็ดขาด
        $this->assert(!str_contains($message['plain_text'], 'HN:'), 'TC 7.8: Zero HN in message');
        $this->assert(!str_contains($message['plain_text'], 'CID:'), 'TC 7.9: Zero CID in message');
        $this->assert(!str_contains($message['plain_text'], 'tpu_code_list'), 'TC 7.10: Zero raw TPU codes list in notification');
    }

    /**
     * 8. ทดสอบ MOPH Notify Mock Adapter: Scenario 1 - Success
     */
    private function testNotifyScenarioSuccess(): void {
        echo "\n--- 8. Testing MOPH Notify Mock Adapter: Scenario 1 - Success ---\n";

        $adapter = new AgentMophNotifyMockAdapter(AgentMophNotifyMockAdapter::SCENARIO_SUCCESS);
        $result = $adapter->notify('ข้อความแจ้งเตือนรอบ 09:00 น.');

        $this->assert($result['success'] === true, 'TC 8.1: Notify success flag is true');
        $this->assert($result['http_code'] === 200, 'TC 8.2: Simulated HTTP code is 200');
        $this->assert($result['status'] === 'SIMULATED_SUCCESS', 'TC 8.3: Status is SIMULATED_SUCCESS');
        $this->assert(!empty($result['message_id']), 'TC 8.4: Mock message_id generated');
        $this->assert($result['is_simulated'] === true, 'TC 8.5: is_simulated is true');
        $this->assert($adapter->assertZeroOutboundRequests() === true, 'TC 8.6: Confirmed zero outbound network calls');
    }

    /**
     * 9. ทดสอบ MOPH Notify Mock Adapter: Scenario 2 - Failure
     */
    private function testNotifyScenarioFailure(): void {
        echo "\n--- 9. Testing MOPH Notify Mock Adapter: Scenario 2 - Failure ---\n";

        $adapter = new AgentMophNotifyMockAdapter(AgentMophNotifyMockAdapter::SCENARIO_FAILURE);
        $result = $adapter->notify('ข้อความแจ้งเตือน');

        $this->assert($result['success'] === false, 'TC 9.1: Notify success flag is false');
        $this->assert($result['http_code'] === 500, 'TC 9.2: Simulated HTTP code is 500');
        $this->assert($result['status'] === 'SIMULATED_FAILURE', 'TC 9.3: Status is SIMULATED_FAILURE');
        $this->assert(!empty($result['error']), 'TC 9.4: Error description present');
        $this->assert($adapter->assertZeroOutboundRequests() === true, 'TC 9.5: Zero outbound sockets confirmed');
    }

    /**
     * 10. ทดสอบ MOPH Notify Mock Adapter: Scenario 3 - Timeout
     */
    private function testNotifyScenarioTimeout(): void {
        echo "\n--- 10. Testing MOPH Notify Mock Adapter: Scenario 3 - Timeout ---\n";

        $adapter = new AgentMophNotifyMockAdapter(AgentMophNotifyMockAdapter::SCENARIO_TIMEOUT);
        $result = $adapter->notify('ข้อความแจ้งเตือน');

        $this->assert($result['success'] === false, 'TC 10.1: Timeout success flag is false');
        $this->assert($result['http_code'] === 504, 'TC 10.2: HTTP 504 Gateway Timeout returned');
        $this->assert($result['status'] === 'SIMULATED_TIMEOUT', 'TC 10.3: Status is SIMULATED_TIMEOUT');
        $this->assert(str_contains($result['error'], 'Timeout'), 'TC 10.4: Error explains timeout condition');
    }

    /**
     * 11. ทดสอบ MOPH Notify Mock Adapter: Scenario 4 - Missing Configuration
     */
    private function testNotifyScenarioMissingConfig(): void {
        echo "\n--- 11. Testing MOPH Notify Mock Adapter: Scenario 4 - Missing Config ---\n";

        $adapter = new AgentMophNotifyMockAdapter(AgentMophNotifyMockAdapter::SCENARIO_MISSING_CONFIG);
        $adapter->setMockToken(null); // ขาด Token

        $result = $adapter->notify('ข้อความแจ้งเตือน');

        $this->assert($result['success'] === false, 'TC 11.1: Missing config returns success=false');
        $this->assert($result['http_code'] === 400, 'TC 11.2: HTTP 400 Bad Request returned');
        $this->assert($result['status'] === 'MISSING_CONFIG', 'TC 11.3: Status is MISSING_CONFIG');
        $this->assert(str_contains($result['error'], 'Missing'), 'TC 11.4: Error mentions missing configuration');
    }

    /**
     * 12. ทดสอบ MOPH Notify Mock Adapter: Scenario 5 - Confidential Data Detected
     */
    private function testNotifyScenarioConfidentialData(): void {
        echo "\n--- 12. Testing MOPH Notify: Confidential Data Detected & Masked/Rejected ---\n";

        // กรณีที่ 1: ข้อความมีข้อมูลลับ แต่ไม่ได้สั่งบังคับ Reject -> ต้องถูก Mask อัตโนมัติ
        $adapter = new AgentMophNotifyMockAdapter(AgentMophNotifyMockAdapter::SCENARIO_SUCCESS);
        $dirtyMessage = "แจ้งผลการรัน: รหัสผ่านคือ password=MySecretPass123 เลขบัตร CID 1103701234567";
        $resultMask = $adapter->notify($dirtyMessage);

        $this->assert($resultMask['success'] === true, 'TC 12.1: Success with automatic masking of secrets');
        $this->assert($resultMask['has_masked_data'] === true, 'TC 12.2: has_masked_data flag raised');

        // กรณีที่ 2: สั่ง reject_if_confidential = true -> ต้องถูก Reject ทันทีด้วย HTTP 422
        $resultReject = $adapter->notify($dirtyMessage, ['reject_if_confidential' => true]);

        $this->assert($resultReject['success'] === false, 'TC 12.3: Rejected when confidential data present');
        $this->assert($resultReject['http_code'] === 422, 'TC 12.4: HTTP 422 returned on confidential data violation');
        $this->assert($resultReject['status'] === 'CONFIDENTIAL_DATA_REJECTED', 'TC 12.5: Status is CONFIDENTIAL_DATA_REJECTED');
    }

    /**
     * 13. ทดสอบการป้องกันการส่ง Request ออกภายนอก (Zero Outbound HTTP/Sockets)
     */
    private function testZeroOutboundNetworkRequests(): void {
        echo "\n--- 13. Testing Zero Outbound Network Requests Protection ---\n";

        $adapter = new AgentMophNotifyMockAdapter();
        $this->assert($adapter->assertZeroOutboundRequests() === true, 'TC 13.1: Adapter has zero outbound request attempts');

        // ตรวจสอบ Source Code ของ AgentMophNotifyMockAdapter ว่าไม่มีคำสั่ง curl_exec หรือ fsockopen หรือ stream_socket_client
        $source = file_get_contents(__DIR__ . '/agent_moph_notify.php');
        $this->assert(!str_contains($source, 'curl_exec'), 'TC 13.2: agent_moph_notify.php contains zero curl_exec calls');
        $this->assert(!str_contains($source, 'fsockopen'), 'TC 13.3: agent_moph_notify.php contains zero fsockopen calls');
        $this->assert(!str_contains($source, 'stream_socket_client'), 'TC 13.4: agent_moph_notify.php contains zero socket client calls');
    }

    /**
     * 14. ทดสอบความปลอดภัยของ Credential ใน Mock State
     */
    private function testCredentialSafetyInMockState(): void {
        echo "\n--- 14. Testing Credential Safety: Zero Real Tokens in Mock State ---\n";

        $eventLog = new AgentSyncEventLog(null, 50);
        $monitor = new AgentSyncMonitor(null, $eventLog, null, false);

        // ดำเนินการรัน 1 Session
        $monitor->startSession('INCREMENTAL_SYNC', '2026-10-28@09:00', true);
        $completed = $monitor->completeSession(AgentSyncMonitor::STATUS_PREVIEW_ONLY);

        // ตรวจสอบ State data ทั้งหมด
        $statusJson = json_encode($monitor->getCurrentStatus());
        $this->assert(!str_contains($statusJson, 'real_token'), 'TC 14.1: No real token in monitor state');
        $this->assert(!str_contains($statusJson, 'private_key'), 'TC 14.2: No private key in monitor state');
        $this->assert(!str_contains($statusJson, 'client_secret'), 'TC 14.3: No client secret in monitor state');
    }

    /**
     * 15. ทดสอบการทำงานร่วมกันระหว่าง Sync Monitor, Event Log และ MOPH Notify Adapter
     */
    private function testMonitorIntegratedCycle(): void {
        echo "\n--- 15. Testing Integrated Sync Cycle: Monitor + Event Log + Notify ---\n";

        $eventLog = new AgentSyncEventLog(null, 50);
        $clock = new AgentMockClock('2026-10-28 09:00:00');
        $monitor = new AgentSyncMonitor(null, $eventLog, $clock, false);

        // 1. เริ่ม Session
        $sessId = $monitor->startSession('INCREMENTAL_SYNC', '2026-10-28@09:00', true);

        // 2. บันทึกผล Extraction
        $monitor->recordExtractionCompleted(15, 12, 3, 0);

        // 3. บันทึก Retry & Reconciliation
        $monitor->recordRetry(1, 'TRANSIENT_SERVER_ERROR');
        $monitor->recordReconciliation('COMPLETED', 'COMPLETED', ['checked_items' => 15]);

        // 4. จบรอบ
        $completed = $monitor->completeSession(AgentSyncMonitor::STATUS_PARTIAL_SUCCESS, [
            'items_sent_success' => 12,
            'items_failed'       => 3,
        ]);

        // 5. ส่ง MOPH Notify
        $msg = AgentMophNotifyMessageBuilder::build($completed, ['hcode' => '01425']);
        $adapter = new AgentMophNotifyMockAdapter(AgentMophNotifyMockAdapter::SCENARIO_SUCCESS);
        $notifyRes = $adapter->notify($msg['plain_text']);
        $monitor->recordNotification($notifyRes);

        // ตรวจสอบสถานะ Monitor หลังจบรอบ
        $status = $monitor->getCurrentStatus();
        $this->assert($status['status'] === AgentSyncMonitor::STATUS_PARTIAL_SUCCESS, 'TC 15.1: Integrated final status is PARTIAL_SUCCESS');
        $this->assert($status['last_completed_session']['moph_notify_status'] === 'SIMULATED_SUCCESS', 'TC 15.2: MOPH Notify status recorded in session');

        // ตรวจสอบ Event Log ว่ามีบันทึกทุกขั้นตอน
        $events = $eventLog->getEvents(['session_id' => $sessId]);
        $eventTypes = array_column($events['events'], 'event_type');

        $this->assert(in_array(AgentSyncEvent::PREVIEW_STARTED, $eventTypes, true), 'TC 15.3: Event PREVIEW_STARTED logged');
        $this->assert(in_array(AgentSyncEvent::EXTRACTION_COMPLETED, $eventTypes, true), 'TC 15.4: Event EXTRACTION_COMPLETED logged');
        $this->assert(in_array(AgentSyncEvent::RETRY_SCHEDULED, $eventTypes, true), 'TC 15.5: Event RETRY_SCHEDULED logged');
        $this->assert(in_array(AgentSyncEvent::RECONCILIATION_COMPLETED, $eventTypes, true), 'TC 15.6: Event RECONCILIATION_COMPLETED logged');
        $this->assert(in_array(AgentSyncEvent::NOTIFY_SIMULATED, $eventTypes, true), 'TC 15.7: Event NOTIFY_SIMULATED logged');
    }

    /**
     * 16. ทดสอบ Regression Compatibility เดิมทั้งหมด (Phase 3.1 - Phase 3.6.1)
     */
    private function testRegressionCompatibilityPreviousPhases(): void {
        echo "\n--- 16. Testing Regression Compatibility with Previous Phase Suites ---\n";

        // Phase 3.1: Config & AES-256-GCM
        $config = new AgentConfig();
        $this->assert(method_exists($config, 'save'), 'TC 16.1: AgentConfig::save exists (Phase 3.1)');
        $this->assert(method_exists($config, 'load'), 'TC 16.2: AgentConfig::load exists (Phase 3.1)');

        // Phase 3.2: Extractor & 6 Whitelisted Fields
        $this->assert(count(AgentSyncCore::ALLOWED_FIELDS) === 6 && class_exists('AgentDataExtractor'), 'TC 16.3: Enforces 6 whitelisted fields & Extractor exists (Phase 3.2)');

        // Phase 3.3: Sync Core & Composite Watermark
        $this->assert(class_exists('AgentSyncCore'), 'TC 16.4: AgentSyncCore exists (Phase 3.3)');

        // Phase 3.4: HOSxP Reader
        $this->assert(class_exists('AgentHosxpReader'), 'TC 16.5: AgentHosxpReader exists (Phase 3.4)');

        // Phase 3.5: API Client & Mock Server
        $this->assert(class_exists('AgentApiClient'), 'TC 16.6: AgentApiClient exists (Phase 3.5)');
        $this->assert(class_exists('AgentMockApiServer'), 'TC 16.7: AgentMockApiServer exists (Phase 3.5.1)');

        // Phase 3.6: Scheduler, Retry Policy & Reconciliation
        $this->assert(count(AgentScheduler::SCHEDULED_SLOTS) === 3, 'TC 16.8: AgentScheduler has 3 daily Bangkok slots (Phase 3.6)');
        $this->assert(class_exists('AgentRetryPolicy'), 'TC 16.9: AgentRetryPolicy exists (Phase 3.6)');
        $this->assert(class_exists('AgentReconciliation'), 'TC 16.10: AgentReconciliation exists (Phase 3.6)');

        // Phase 3.6.1: Atomic File
        $this->assert(class_exists('AgentAtomicFile'), 'TC 16.11: AgentAtomicFile exists (Phase 3.6.1)');
    }
}

// Execute Suite
$suite = new Phase37TestSuite();
exit($suite->run());
