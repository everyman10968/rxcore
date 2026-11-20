<?php
/**
 * RxCore Sync Agent — Mock Sync State
 * Phase 3.3: Sync Agent Core (Mock Mode)
 * 
 * หน้าที่:
 * 1. จำลองสถานะการ Sync ข้อมูลการจ่ายยาในระดับ Mock State
 * 2. บันทึกและตรวจสอบรายการที่เคย Sync แล้วผ่าน Composite Key (hcode, hos_guid)
 * 3. จัดการ Cursor Watermark สำหรับ Incremental Sync:
 *    - last_vstdate: วันที่ล่าสุดที่เคย Sync
 *    - synced_keys_on_last_vstdate: รายการ Composite Key บนวันล่าสุด เพื่อรองรับข้อมูลใหม่ในวันเดียวกัน
 * 4. ห้ามบันทึกสถานะลงฐานข้อมูลจริงใน Phase นี้ (ใช้ไฟล์ JSON ใน config/ หรือ In-Memory เท่านั้น)
 */

require_once __DIR__ . '/agent_atomic_file.php';

class AgentMockSyncState {

    private ?string $filePath;
    private array $state;
    private $faultInjector = null;

    /**
     * @param string|null $filePath ที่อยู่ไฟล์เก็บ Mock State (หากส่ง null จะทำงานแบบ In-Memory 100%)
     * @param bool $autoLoad โหลดค่าจากไฟล์อัตโนมัติหากมีอยู่
     * @param callable|null $faultInjector ฟังก์ชันจำลองความผิดพลาดสำหรับทดสอบ
     */
    public function __construct(?string $filePath = null, bool $autoLoad = true, ?callable $faultInjector = null) {
        $this->filePath = $filePath;
        $this->state = $this->getDefaultState();
        $this->faultInjector = $faultInjector;

        if ($this->filePath !== null && $autoLoad && file_exists($this->filePath)) {
            $this->loadState();
        }
    }

    /**
     * กำหนด Fault Injector สำหรับการทดสอบ
     */
    public function setFaultInjector(?callable $faultInjector): self {
        $this->faultInjector = $faultInjector;
        return $this;
    }

    /**
     * โครงสร้างเริ่มต้นของ Mock State
     */
    private function getDefaultState(): array {
        return [
            'cursor' => [
                'last_vstdate'   => null,
                'last_sync_time' => null,
                'strategy'       => 'composite_watermark',
            ],
            'synced_records' => [], // key: "hcode|hos_guid" => ['hcode', 'hos_guid', 'vstdate', 'synced_at']
            'synced_keys_on_last_vstdate' => [],
            'stats' => [
                'total_synced_records' => 0,
                'initial_sync_done'    => false,
                'last_sync_type'       => null,
            ],
        ];
    }

    /**
     * โหลด State จากไฟล์ (ปลอดภัยด้วย AgentAtomicFile)
     */
    public function loadState(): array {
        if ($this->filePath === null || !file_exists($this->filePath)) {
            return $this->state;
        }

        $decoded = AgentAtomicFile::readJson($this->filePath, $this->getDefaultState());
        if (is_array($decoded)) {
            $this->state = array_merge($this->getDefaultState(), $decoded);
            if (!isset($this->state['cursor'])) {
                $this->state['cursor'] = $this->getDefaultState()['cursor'];
            }
            if (!isset($this->state['synced_records'])) {
                $this->state['synced_records'] = [];
            }
            if (!isset($this->state['stats'])) {
                $this->state['stats'] = $this->getDefaultState()['stats'];
            }
        }

        return $this->state;
    }

    /**
     * บันทึก State ลงไฟล์แบบ Atomic Replacement
     */
    public function saveState(): bool {
        if ($this->filePath === null) {
            return true; // In-memory mode
        }

        try {
            return AgentAtomicFile::writeJson($this->filePath, $this->state, $this->faultInjector);
        } catch (\Throwable $e) {
            // ไม่ให้ข้อผิดพลาดทำลายความต่อเนื่องของระบบ แจ้งผ่าน error_log
            error_log("[AgentMockSyncState] Atomic Save Failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ตรวจสอบว่ารายการ (hcode, hos_guid) นี้เคย Sync แล้วหรือไม่
     */
    public function hasSynced(string $hcode, string $hosGuid): bool {
        $key = $this->buildCompositeKey($hcode, $hosGuid);
        return isset($this->state['synced_records'][$key]);
    }

    /**
     * สร้าง Composite Key (hcode, hos_guid)
     */
    public static function buildCompositeKey(string $hcode, string $hosGuid): string {
        return trim($hcode) . '|' . trim($hosGuid);
    }

    /**
     * บันทึกรายการใหม่ลงใน Mock State (Batch Commit)
     * 
     * @param array $records รายการที่มีฟิลด์ hcode, hos_guid, vstdate
     * @param string $syncType 'INITIAL_SYNC' หรือ 'INCREMENTAL_SYNC'
     */
    public function batchRecordSynced(array $records, string $syncType = 'INCREMENTAL_SYNC'): void {
        $now = date('Y-m-d H:i:s');
        $maxVstdate = $this->state['cursor']['last_vstdate'];

        foreach ($records as $r) {
            $hcode   = trim((string)($r['hcode'] ?? ''));
            $hosGuid = trim((string)($r['hos_guid'] ?? ''));
            $vstdate = trim((string)($r['vstdate'] ?? ''));

            if ($hcode === '' || $hosGuid === '') {
                continue;
            }

            $key = $this->buildCompositeKey($hcode, $hosGuid);
            $this->state['synced_records'][$key] = [
                'hcode'     => $hcode,
                'hos_guid'  => $hosGuid,
                'vstdate'   => $vstdate,
                'synced_at' => $now,
            ];

            if ($vstdate !== '') {
                if ($maxVstdate === null || $vstdate > $maxVstdate) {
                    $maxVstdate = $vstdate;
                }
            }
        }

        // อัปเดต Cursor
        $this->state['cursor']['last_sync_time'] = $now;
        $this->state['cursor']['last_vstdate']   = $maxVstdate;
        $this->state['stats']['total_synced_records'] = count($this->state['synced_records']);
        $this->state['stats']['last_sync_type'] = $syncType;

        if ($syncType === 'INITIAL_SYNC') {
            $this->state['stats']['initial_sync_done'] = true;
        }

        // รวบรวมรายการ keys ที่อยู่บน last_vstdate เพื่อเป็นจุดอ้างอิงของวันเดียวกัน
        if ($maxVstdate !== null) {
            $keysOnMaxDate = [];
            foreach ($this->state['synced_records'] as $key => $item) {
                if ($item['vstdate'] === $maxVstdate) {
                    $keysOnMaxDate[] = $key;
                }
            }
            $this->state['synced_keys_on_last_vstdate'] = $keysOnMaxDate;
        }

        $this->saveState();
    }

    /**
     * ดึงวันที่สุดท้ายที่เคย Sync (last_vstdate)
     */
    public function getLastVstdate(): ?string {
        return $this->state['cursor']['last_vstdate'] ?? null;
    }

    /**
     * ตรวจสอบว่าเคยทำ Initial Sync แล้วหรือไม่
     */
    public function isInitialSyncDone(): bool {
        return (bool)($this->state['stats']['initial_sync_done'] ?? false);
    }

    /**
     * ดึงข้อมูล Cursor ปัจจุบัน
     */
    public function getCursor(): array {
        return $this->state['cursor'];
    }

    /**
     * ดึงสถิติสรุปของ Mock State
     */
    public function getSummary(): array {
        return [
            'total_synced_records'        => count($this->state['synced_records']),
            'initial_sync_done'           => $this->isInitialSyncDone(),
            'last_sync_time'              => $this->state['cursor']['last_sync_time'],
            'last_vstdate'                => $this->state['cursor']['last_vstdate'],
            'keys_count_on_last_vstdate'  => count($this->state['synced_keys_on_last_vstdate'] ?? []),
            'last_sync_type'              => $this->state['stats']['last_sync_type'] ?? null,
            'is_mock_mode'                => true,
            'storage_type'                => $this->filePath ? 'file' : 'in_memory',
        ];
    }

    /**
     * ดึงรายการ Synced Records ทั้งหมด
     */
    public function getAllSyncedRecords(): array {
        return $this->state['synced_records'];
    }

    /**
     * รีเซ็ต State กลับสู่ค่าเริ่มต้น
     */
    public function reset(): bool {
        $this->state = $this->getDefaultState();
        if ($this->filePath !== null && file_exists($this->filePath)) {
            @unlink($this->filePath);
        }
        return true;
    }
}
