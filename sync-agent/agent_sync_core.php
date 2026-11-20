<?php
/**
 * RxCore Sync Agent — Sync Agent Core (Mock Mode)
 * Phase 3.3: Sync Agent Core
 * 
 * หน้าที่หลัก:
 * 1. ควบคุม Mock Sync Pipeline สำหรับเตรียมข้อมูลการจ่ายยา
 * 2. รองรับ Initial Sync:
 *    - เตรียมข้อมูลย้อนหลัง 1 ปี (หรือตามที่ผู้ใช้กำหนด)
 *    - ตรวจสอบความถูกต้องและตัดข้อมูลซ้ำด้วย (hcode, hos_guid)
 * 3. รองรับ Incremental Sync:
 *    - เตรียมเฉพาะรายการใหม่หลังจุดอ้างอิงล่าสุด
 *    - ป้องกันปัญหา MAX(vstdate) เพียงอย่างเดียว โดยใช้ Composite Watermark
 *      (last_vstdate + synced_keys_on_last_vstdate) รองรับรายการใหม่ที่เกิดขึ้นในวันเดียวกัน
 * 4. รักษาความปลอดภัยและข้อมูลส่วนบุคคล:
 *    - ส่งออกเฉพาะ 6 ฟิลด์: hos_guid, vn, hcode, vstdate, tpu_code_list, qty
 *    - ห้ามมี HN, CID, ชื่อผู้ป่วย หรือ PII เด็ดขาด
 * 5. ปฏิบัติตามสถานะ PREVIEW_ONLY โดยยังไม่มีการส่งข้อมูลออกภายนอกหรือบันทึกจริง
 */

require_once __DIR__ . '/agent_extractor.php';
require_once __DIR__ . '/agent_mock_state.php';
require_once __DIR__ . '/agent_drug_registry.php';

class AgentSyncCore {

    private AgentDataExtractor $extractor;
    private AgentMockSyncState $mockState;
    private array $registeredDrugCodes;

    /**
     * รายชื่อฟิลด์ 6 ฟิลด์ที่ได้รับอนุญาตให้ Sync (Data Whitelist)
     */
    public const ALLOWED_FIELDS = [
        'hos_guid',
        'vn',
        'hcode',
        'vstdate',
        'tpu_code_list',
        'qty',
    ];

    /**
     * @param AgentDataExtractor $extractor
     * @param AgentMockSyncState|null $mockState หากไม่ระบุ จะใช้ไฟล์ mock_sync_state.json อัตโนมัติ
     * @param array|null $registeredDrugCodes
     */
    public function __construct(
        AgentDataExtractor $extractor,
        ?AgentMockSyncState $mockState = null,
        ?array $registeredDrugCodes = null
    ) {
        $this->extractor = $extractor;
        $this->mockState = $mockState ?? new AgentMockSyncState(__DIR__ . '/config/mock_sync_state.json');
        $this->registeredDrugCodes = $registeredDrugCodes ?? AgentDrugRegistry::getRegisteredDrugCodes();
    }

    /**
     * ดึง Instance ของ Mock State
     */
    public function getMockState(): AgentMockSyncState {
        return $this->mockState;
    }

    /**
     * รัน Pipeline สำหรับ Initial Sync Preview
     * ย้อนหลัง 1 ปี (365 วัน) เป็นค่าเริ่มต้น หรือตามช่วงวันที่กำหนด
     * 
     * @param string|null $startDate วันที่เริ่มต้น (YYYY-MM-DD)
     * @param string|null $endDate   วันที่สิ้นสุด (YYYY-MM-DD)
     * @return array ผลลัพธ์ในสถานะ PREVIEW_ONLY
     */
    public function runInitialSyncPreview(?string $startDate = null, ?string $endDate = null): array {
        $targetEndDate = $endDate ?: date('Y-m-d');
        // หากไม่ระบุ start_date ให้คำนวณย้อนหลัง 1 ปี (1 year backward)
        $targetStartDate = $startDate ?: date('Y-m-d', strtotime('-1 year', strtotime($targetEndDate)));

        return $this->executeSyncPipeline('INITIAL_SYNC', $targetStartDate, $targetEndDate);
    }

    /**
     * รัน Pipeline สำหรับ Incremental Sync Preview
     * ใช้ Composite Cursor (last_vstdate + keys) เพื่อดึงเฉพาะรายการใหม่
     * 
     * @param string|null $customStartDate วันที่เริ่มต้นแบบกำหนดเอง (ถ้ามี)
     * @param string|null $customEndDate   วันที่สิ้นสุดแบบกำหนดเอง (ถ้ามี)
     * @return array ผลลัพธ์ในสถานะ PREVIEW_ONLY
     */
    public function runIncrementalSyncPreview(?string $customStartDate = null, ?string $customEndDate = null): array {
        $targetEndDate = $customEndDate ?: date('Y-m-d');

        // หากมีการระบุ customStartDate ให้ใช้ค่านั้น
        if (!empty($customStartDate)) {
            $targetStartDate = $customStartDate;
        } else {
            // ดึง last_vstdate จาก Cursor
            $cursorVstdate = $this->mockState->getLastVstdate();
            if ($cursorVstdate !== null) {
                // เริ่มต้นตั้งแต่วันที่ last_vstdate เพื่อตรวจหารายการใหม่ที่บันทึกเพิ่มในวันเดียวกัน
                $targetStartDate = $cursorVstdate;
            } else {
                // หากยังไม่เคย Sync เลย ให้ถอยหลัง 1 วันเป็นค่าเริ่มต้นของ Incremental
                $targetStartDate = date('Y-m-d', strtotime('-1 day', strtotime($targetEndDate)));
            }
        }

        return $this->executeSyncPipeline('INCREMENTAL_SYNC', $targetStartDate, $targetEndDate);
    }

    /**
     * กระบวนการ Sync Pipeline แกนกลาง (7 ขั้นตอน)
     */
    private function executeSyncPipeline(string $syncType, string $startDate, string $endDate): array {
        $startTime = microtime(true);

        // ขั้นตอนที่ 1: ตรวจสอบการตั้งค่าและทะเบียนยา
        if (empty($this->registeredDrugCodes)) {
            return [
                'success'           => false,
                'status'            => 'PREVIEW_ONLY',
                'sync_type'         => $syncType,
                'error'             => 'ไม่พบรายการยาในทะเบียนที่ได้รับอนุญาตให้ Sync',
                'start_date'        => $startDate,
                'end_date'          => $endDate,
                'total_read'        => 0,
                'total_passed'      => 0,
                'duplicates_pruned' => 0,
                'total_malformed'   => 0,
                'new_records_count' => 0,
                'already_synced_count' => 0,
                'new_records'       => [],
                'already_synced_records' => [],
            ];
        }

        // ขั้นตอนที่ 2 & 3: อ่านและตรวจสอบความถูกต้องของข้อมูลผ่าน Data Extractor
        $extractResult = $this->extractor->extract($startDate, $endDate);
        if (!$extractResult['success']) {
            return [
                'success'           => false,
                'status'            => 'PREVIEW_ONLY',
                'sync_type'         => $syncType,
                'error'             => $this->sanitizeErrorMessage($extractResult['error'] ?? 'การดึงข้อมูลล้มเหลว'),
                'start_date'        => $startDate,
                'end_date'          => $endDate,
                'total_read'        => $extractResult['total_read'] ?? 0,
                'total_passed'      => 0,
                'duplicates_pruned' => $extractResult['duplicates_pruned'] ?? 0,
                'total_malformed'   => $extractResult['total_malformed'] ?? 0,
                'new_records_count' => 0,
                'already_synced_count' => 0,
                'new_records'       => [],
                'already_synced_records' => [],
            ];
        }

        $rawPassed = $extractResult['sample_data'] ?? [];
        $newRecords = [];
        $alreadySyncedRecords = [];

        // ขั้นตอนที่ 4 & 5: แยกข้อมูลเป็นรายการใหม่และรายการที่มีอยู่แล้ว พร้อมตรวจสอบ 6 ฟิลด์อย่างเข้มงวด
        foreach ($rawPassed as $record) {
            // บังคับ Whitelist 6 ฟิลด์ strictly isolation
            $isolatedRecord = $this->isolateAllowedFields($record);

            $hcode   = $isolatedRecord['hcode'];
            $hosGuid = $isolatedRecord['hos_guid'];

            if ($this->mockState->hasSynced($hcode, $hosGuid)) {
                $alreadySyncedRecords[] = $isolatedRecord;
            } else {
                $newRecords[] = $isolatedRecord;
            }
        }

        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        // ขั้นตอนที่ 6 & 7: สร้าง Sync Preview และสรุปผลในสถานะ PREVIEW_ONLY
        return [
            'success'               => true,
            'status'                => 'PREVIEW_ONLY',
            'sync_type'             => $syncType,
            'is_simulation'         => true,
            'cloud_sent'            => false,
            'start_date'            => $startDate,
            'end_date'              => $endDate,
            'total_read'            => $extractResult['total_read'],
            'total_passed'          => $extractResult['total_passed'],
            'duplicates_pruned'     => $extractResult['duplicates_pruned'],
            'total_malformed'       => $extractResult['total_malformed'],
            'malformed_summary'     => $extractResult['malformed_summary'],
            'new_records_count'     => count($newRecords),
            'already_synced_count'  => count($alreadySyncedRecords),
            'new_records'           => $newRecords,
            'already_synced_records'=> $alreadySyncedRecords,
            'cursor_before'         => $this->mockState->getCursor(),
            'cursor_strategy'       => 'composite_watermark (last_vstdate + composite_keys)',
            'execution_time_ms'     => $executionTimeMs,
            'warning'               => 'PREVIEW_ONLY: ผลลัพธ์นี้เป็นการจำลองเตรียมข้อมูลเท่านั้น ยังไม่มีการส่งข้อมูลไปยัง Supabase หรือภายนอก',
            'message'               => sprintf(
                '[%s] พบข้อมูลใหม่ %d รายการ | ข้อมูลที่เคย Sync แล้ว %d รายการ | ตัดซ้ำ %d รายการ | ข้อมูลผิดรูปแบบ %d รายการ',
                $syncType,
                count($newRecords),
                count($alreadySyncedRecords),
                $extractResult['duplicates_pruned'],
                $extractResult['total_malformed']
            ),
        ];
    }

    /**
     * จำลองการ Commit ข้อมูลใหม่เข้าสู่ Mock State (สำหรับใช้ทดสอบการรันซ้ำ / Idempotence)
     * ไม่มีการเชื่อมต่อกับฐานข้อมูลจริงหรือ Cloud
     * 
     * @param array $records รายการใหม่ที่จะจำลองการบันทึก
     * @param string $syncType ชนิดการ Sync
     * @return array ผลการจำลองการบันทึก
     */
    public function simulateMockCommit(array $records, string $syncType = 'INCREMENTAL_SYNC'): array {
        $this->mockState->batchRecordSynced($records, $syncType);

        return [
            'success'         => true,
            'status'          => 'MOCK_COMMITTED',
            'committed_count' => count($records),
            'cloud_sent'      => false,
            'is_simulation'   => true,
            'mock_summary'    => $this->mockState->getSummary(),
            'cursor'          => $this->mockState->getCursor(),
        ];
    }

    /**
     * รีเซ็ต Mock State
     */
    public function resetMockState(): bool {
        return $this->mockState->reset();
    }

    /**
     * ตรวจสอบและสกัดเฉพาะ 6 ฟิลด์ที่กำหนด
     * ห้ามไม่ให้มี HN, CID, ชื่อผู้ป่วย หรือฟิลด์อื่นใดหลุดเข้าไปใน Pipeline
     */
    public function isolateAllowedFields(array $row): array {
        $clean = [];
        foreach (self::ALLOWED_FIELDS as $field) {
            $clean[$field] = $row[$field] ?? null;
        }
        return $clean;
    }

    /**
     * ล้างข้อมูล Credentials และ Internal Path ออกจาก Error Message
     */
    private function sanitizeErrorMessage(string $msg): string {
        $msg = preg_replace('/password[=:\s]+[^\s,;]+/i', 'password=***', $msg);
        $msg = preg_replace('/[a-zA-Z]:\\\\[^\s:;,]+/i', '[hidden_path]', $msg);
        return $msg;
    }
}
