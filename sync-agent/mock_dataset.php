<?php
/**
 * RxCore Sync Agent — Mock Dataset & Mock PDO Adapter
 * Phase 3.2: Read-only Data Extraction
 * 
 * ชุดข้อมูลจำลองสำหรับตาราง HOSxP:
 * 1. opitemrece (รายการจ่ายยาและเวชภัณฑ์)
 * 2. drugitems (ทะเบียนยาใน HOSxP)
 * 3. vn_stat (ข้อมูลการรับบริการผู้ป่วยนอก)
 * 
 * ครอบคลุมทั้ง 10 กรณีทดสอบตามข้อกำหนด:
 * 1. ข้อมูลปกติที่ผ่านทุกเงื่อนไข
 * 2. รหัสยาที่ไม่มีในทะเบียน (ต้องข้ามโดยไม่บันทึก Log)
 * 3. hos_guid ซ้ำภายในสถานพยาบาลเดียวกัน (ตัดออก)
 * 4. hos_guid เดียวกันแต่คนละ hcode (ต้องไม่ตัดเป็นรายการซ้ำ)
 * 5. hos_guid ว่าง (แจ้งผลตรวจสอบว่าผิดรูปแบบ)
 * 6. qty เป็น 0 หรือติดลบ (แจ้งผลตรวจสอบว่าผิดรูปแบบ)
 * 7. วันที่อยู่นอกช่วงที่เลือก (กรองออกทาง SQL)
 * 8. ไม่มีข้อมูลในช่วงที่ระบุ (คืนค่าว่างอย่างถูกต้อง)
 * 9. Adapter หรือ Query เกิดข้อผิดพลาด (จัดการข้อผิดพลาดอย่างปลอดภัย)
 * 10. ข้อมูลที่มี vn หรือ hcode ไม่ครบ/ผิดรูปแบบ (แจ้งผลตรวจสอบว่าผิดรูปแบบ)
 */

require_once __DIR__ . '/agent_drug_registry.php';

class MockDatasetPhase32 {

    /**
     * คืนค่าข้อมูลดิบตาราง drugitems สำหรับ Mock Database
     */
    public static function getDrugItemsRows(): array {
        return [
            ['icode' => '1000001', 'name' => 'Paracetamol 500 mg tab', 'tpu_code_list' => '227514'],
            ['icode' => '1000002', 'name' => 'Amoxicillin 500 mg cap',   'tpu_code_list' => '189422'],
            ['icode' => '1000003', 'name' => 'Metformin 500 mg tab',     'tpu_code_list' => '304192'],
            ['icode' => '1000004', 'name' => 'Amlodipine 5 mg tab',      'tpu_code_list' => '312891'],
            ['icode' => '1000005', 'name' => 'Losartan 50 mg tab',       'tpu_code_list' => '415820'],
            ['icode' => '1000006', 'name' => 'Omeprazole 20 mg cap',     'tpu_code_list' => '521940'],
            ['icode' => '1000007', 'name' => 'Simvastatin 20 mg tab',    'tpu_code_list' => '632145'],
            ['icode' => '1000008', 'name' => 'Enalapril 5 mg tab',       'tpu_code_list' => '710239'],
            ['icode' => '1000009', 'name' => 'Glibenclamide 5 mg tab',   'tpu_code_list' => '842109'],
            ['icode' => '1000010', 'name' => 'Aspirin 81 mg tab',        'tpu_code_list' => '931402'],
            // ยานอกทะเบียน (Test Case 2)
            ['icode' => '1999999', 'name' => 'Unregistered Vitamin C',   'tpu_code_list' => '999999'],
        ];
    }

    /**
     * คืนค่าข้อมูลดิบตาราง vn_stat สำหรับ Mock Database
     */
    public static function getVnStatRows(): array {
        return [
            // รพ.สต. 01425 (บ้านดอนทอง)
            ['vn' => '691025080001', 'hn' => 'HN001', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
            ['vn' => '691025080002', 'hn' => 'HN002', 'hcode' => '01425', 'vstdate' => '2026-10-25'],
            ['vn' => '691026080001', 'hn' => 'HN003', 'hcode' => '01425', 'vstdate' => '2026-10-26'],
            ['vn' => '691026080002', 'hn' => 'HN004', 'hcode' => '01425', 'vstdate' => '2026-10-26'],
            ['vn' => '691027080001', 'hn' => 'HN005', 'hcode' => '01425', 'vstdate' => '2026-10-27'],

            // รพ.สต. 01426 (หนองบัวพัฒนา) - ใช้ทดสอบคนละ hcode แต่ hos_guid เดียวกัน
            ['vn' => '691026090001', 'hn' => 'HN101', 'hcode' => '01426', 'vstdate' => '2026-10-26'],
            ['vn' => '691027090001', 'hn' => 'HN102', 'hcode' => '01426', 'vstdate' => '2026-10-27'],

            // วันที่อยู่นอกช่วง (Test Case 7)
            ['vn' => '690815080001', 'hn' => 'HN201', 'hcode' => '01425', 'vstdate' => '2026-08-15'],
            ['vn' => '691120080001', 'hn' => 'HN202', 'hcode' => '01425', 'vstdate' => '2026-11-20'],

            // รายการที่ hcode ผิดรูปแบบ/ว่าง (Test Case 10)
            ['vn' => '691026999001', 'hn' => 'HN301', 'hcode' => '',      'vstdate' => '2026-10-26'],
            ['vn' => '691026999002', 'hn' => 'HN302', 'hcode' => '999',   'vstdate' => '2026-10-26'], // รหัสไม่ครบ 5 หลัก
            ['vn' => '',             'hn' => 'HN303', 'hcode' => '01425', 'vstdate' => '2026-10-26'], // vn ว่าง
        ];
    }

    /**
     * คืนค่าข้อมูลดิบตาราง opitemrece สำหรับ Mock Database
     */
    public static function getOpitemreceRows(): array {
        return [
            // 1. รายการปกติ ผ่านทุกเงื่อนไข (Test Case 1)
            [
                'hos_guid' => '{A1B2C3D4-0001-4000-8000-000000000001}',
                'vn'       => '691025080001',
                'icode'    => '1000001', // Paracetamol (227514)
                'vstdate'  => '2026-10-25',
                'qty'      => 20.0,
            ],
            [
                'hos_guid' => '{A1B2C3D4-0002-4000-8000-000000000002}',
                'vn'       => '691025080002',
                'icode'    => '1000002', // Amoxicillin (189422)
                'vstdate'  => '2026-10-25',
                'qty'      => 15.0,
            ],
            [
                'hos_guid' => '{A1B2C3D4-0003-4000-8000-000000000003}',
                'vn'       => '691026080001',
                'icode'    => '1000003', // Metformin (304192)
                'vstdate'  => '2026-10-26',
                'qty'      => 60.0,
            ],
            [
                'hos_guid' => '{A1B2C3D4-0004-4000-8000-000000000004}',
                'vn'       => '691026080002',
                'icode'    => '1000004', // Amlodipine (312891)
                'vstdate'  => '2026-10-26',
                'qty'      => 30.0,
            ],
            [
                'hos_guid' => '{A1B2C3D4-0005-4000-8000-000000000005}',
                'vn'       => '691027080001',
                'icode'    => '1000005', // Losartan (415820)
                'vstdate'  => '2026-10-27',
                'qty'      => 30.0,
            ],

            // 2. รหัสยาที่ไม่มีในทะเบียน (Test Case 2)
            // ยา icode 1999999 มี tpu_code_list = 999999 ซึ่งไม่มีใน Mock Registry
            [
                'hos_guid' => '{A1B2C3D4-UNREG-4000-8000-000000000001}',
                'vn'       => '691025080001',
                'icode'    => '1999999',
                'vstdate'  => '2026-10-25',
                'qty'      => 10.0,
            ],

            // 3. hos_guid ซ้ำภายในสถานพยาบาลเดียวกัน hcode 01425 (Test Case 3)
            // hos_guid {DUP-HCODE-01425} ปรากฏ 2 ครั้งใน hcode 01425 -> ตัวที่ 2 ต้องถูกตัดออก
            [
                'hos_guid' => '{DUP-HCODE-01425}',
                'vn'       => '691026080001',
                'icode'    => '1000001',
                'vstdate'  => '2026-10-26',
                'qty'      => 10.0,
            ],
            [
                'hos_guid' => '{DUP-HCODE-01425}', // ซ้ำใน hcode 01425
                'vn'       => '691026080002',
                'icode'    => '1000002',
                'vstdate'  => '2026-10-26',
                'qty'      => 10.0,
            ],

            // 4. hos_guid เดียวกันแต่คนละ hcode (Test Case 4)
            // hos_guid {SHARED-GUID-DIFF-HCODE} อยู่ใน hcode 01425 (vn 691026080001) และ hcode 01426 (vn 691026090001)
            // ทั้ง 2 รายการต้องผ่าน ไม่ถือว่าเป็นรายการซ้ำ
            [
                'hos_guid' => '{SHARED-GUID-DIFF-HCODE}',
                'vn'       => '691026080001', // hcode 01425
                'icode'    => '1000006',      // Omeprazole (521940)
                'vstdate'  => '2026-10-26',
                'qty'      => 14.0,
            ],
            [
                'hos_guid' => '{SHARED-GUID-DIFF-HCODE}',
                'vn'       => '691026090001', // hcode 01426 (ต่างสถานพยาบาล)
                'icode'    => '1000006',      // Omeprazole (521940)
                'vstdate'  => '2026-10-26',
                'qty'      => 14.0,
            ],

            // 5. hos_guid ว่าง / whitespace (Test Case 5)
            [
                'hos_guid' => '',
                'vn'       => '691027080001',
                'icode'    => '1000001',
                'vstdate'  => '2026-10-27',
                'qty'      => 10.0,
            ],
            [
                'hos_guid' => '   ',
                'vn'       => '691027080001',
                'icode'    => '1000002',
                'vstdate'  => '2026-10-27',
                'qty'      => 10.0,
            ],

            // 6. qty เป็น 0 หรือติดลบ (Test Case 6)
            [
                'hos_guid' => '{ZERO-QTY-RECORD-0001}',
                'vn'       => '691027080001',
                'icode'    => '1000003',
                'vstdate'  => '2026-10-27',
                'qty'      => 0.0,
            ],
            [
                'hos_guid' => '{NEGATIVE-QTY-RECORD-02}',
                'vn'       => '691027080001',
                'icode'    => '1000004',
                'vstdate'  => '2026-10-27',
                'qty'      => -5.0,
            ],

            // 7. วันที่อยู่นอกช่วง (Test Case 7)
            [
                'hos_guid' => '{OUT-OF-RANGE-PAST-01}',
                'vn'       => '690815080001',
                'icode'    => '1000001',
                'vstdate'  => '2026-08-15',
                'qty'      => 20.0,
            ],
            [
                'hos_guid' => '{OUT-OF-RANGE-FUTURE-02}',
                'vn'       => '691120080001',
                'icode'    => '1000002',
                'vstdate'  => '2026-11-20',
                'qty'      => 10.0,
            ],

            // 10. vn หรือ hcode ไม่ครบ / ผิดรูปแบบ (Test Case 10)
            [
                'hos_guid' => '{MALFORMED-EMPTY-HCODE}',
                'vn'       => '691026999001', // vn_stat.hcode = ''
                'icode'    => '1000001',
                'vstdate'  => '2026-10-26',
                'qty'      => 10.0,
            ],
            [
                'hos_guid' => '{MALFORMED-SHORT-HCODE}',
                'vn'       => '691026999002', // vn_stat.hcode = '999'
                'icode'    => '1000002',
                'vstdate'  => '2026-10-26',
                'qty'      => 10.0,
            ],
            [
                'hos_guid' => '{MALFORMED-EMPTY-VN}',
                'vn'       => '',             // vn ว่าง
                'icode'    => '1000003',
                'vstdate'  => '2026-10-26',
                'qty'      => 10.0,
            ],
        ];
    }

    /**
     * สร้าง In-memory SQLite PDO จำลองฐานข้อมูล HOSxP
     * พร้อมสร้างตารางและใส่ชุดข้อมูลจำลองตามโครงสร้างจริง
     */
    public static function createMockPdo(?array $customOpitemrece = null, ?array $customVnStat = null, ?array $customDrugItems = null): PDO {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // สร้างตาราง opitemrece
        $pdo->exec("
            CREATE TABLE opitemrece (
                hos_guid TEXT,
                vn TEXT,
                icode TEXT,
                vstdate TEXT,
                qty REAL
            )
        ");

        // สร้างตาราง drugitems
        $pdo->exec("
            CREATE TABLE drugitems (
                icode TEXT PRIMARY KEY,
                name TEXT,
                tpu_code_list TEXT
            )
        ");

        // สร้างตาราง vn_stat
        $pdo->exec("
            CREATE TABLE vn_stat (
                vn TEXT PRIMARY KEY,
                hn TEXT,
                hcode TEXT,
                vstdate TEXT
            )
        ");

        // ใส่ข้อมูล drugitems
        $stmtDrug = $pdo->prepare("INSERT INTO drugitems (icode, name, tpu_code_list) VALUES (:icode, :name, :tpu_code_list)");
        $drugRows = $customDrugItems ?? self::getDrugItemsRows();
        foreach ($drugRows as $row) {
            $stmtDrug->execute($row);
        }

        // ใส่ข้อมูล vn_stat
        $stmtVn = $pdo->prepare("INSERT INTO vn_stat (vn, hn, hcode, vstdate) VALUES (:vn, :hn, :hcode, :vstdate)");
        $vnRows = $customVnStat ?? self::getVnStatRows();
        foreach ($vnRows as $row) {
            $stmtVn->execute($row);
        }

        // ใส่ข้อมูล opitemrece
        $stmtOp = $pdo->prepare("INSERT INTO opitemrece (hos_guid, vn, icode, vstdate, qty) VALUES (:hos_guid, :vn, :icode, :vstdate, :qty)");
        $opRows = $customOpitemrece ?? self::getOpitemreceRows();
        foreach ($opRows as $row) {
            $stmtOp->execute($row);
        }

        return $pdo;
    }
}
