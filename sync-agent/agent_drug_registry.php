<?php
/**
 * RxCore Sync Agent — Drug Registry
 *
 * จัดการรายการรหัสยาที่ได้รับอนุญาตให้ Sync (TPU / TMT Code List)
 * - คงค่าเริ่มต้นสำหรับ Unit Test Suites เดิมไว้เพื่อ Backward Compatibility
 * - รองรับการโหลดและเปิดใช้งานทะเบียนยาจริง (drug_registry_production.json / Supabase rx_drug_lookup)
 *   สำหรับการทำงานในโหมดใช้งานจริง (Production Mode) โดยไม่มีข้อมูลต้นทุนยาที่เป็นความลับ
 */

class AgentDrugRegistry {
    /**
     * รายการรหัสยามาตรฐานเริ่มต้น (คงไว้สำหรับ Automated Unit Test Suites)
     */
    private static array $registeredDrugs = [
        '227514' => [
            'code'         => 'MED-001',
            'generic_name' => 'Paracetamol',
            'dosage'       => '500 mg',
            'form'         => 'Tablet',
        ],
        '189422' => [
            'code'         => 'MED-002',
            'generic_name' => 'Amoxicillin',
            'dosage'       => '500 mg',
            'form'         => 'Capsule',
        ],
        '304192' => [
            'code'         => 'MED-003',
            'generic_name' => 'Metformin HCl',
            'dosage'       => '500 mg',
            'form'         => 'Tablet',
        ],
        '312891' => [
            'code'         => 'MED-004',
            'generic_name' => 'Amlodipine Besylate',
            'dosage'       => '5 mg',
            'form'         => 'Tablet',
        ],
        '415820' => [
            'code'         => 'MED-005',
            'generic_name' => 'Losartan Potassium',
            'dosage'       => '50 mg',
            'form'         => 'Tablet',
        ],
        '521940' => [
            'code'         => 'MED-006',
            'generic_name' => 'Omeprazole',
            'dosage'       => '20 mg',
            'form'         => 'Capsule',
        ],
        '632145' => [
            'code'         => 'MED-007',
            'generic_name' => 'Simvastatin',
            'dosage'       => '20 mg',
            'form'         => 'Tablet',
        ],
        '710239' => [
            'code'         => 'MED-008',
            'generic_name' => 'Enalapril Maleate',
            'dosage'       => '5 mg',
            'form'         => 'Tablet',
        ],
        '842109' => [
            'code'         => 'MED-009',
            'generic_name' => 'Glibenclamide',
            'dosage'       => '5 mg',
            'form'         => 'Tablet',
        ],
        '931402' => [
            'code'         => 'MED-010',
            'generic_name' => 'Aspirin',
            'dosage'       => '81 mg',
            'form'         => 'Tablet',
        ],
    ];

    /**
     * ดึงรายการรหัสยาที่ลงทะเบียนไว้ทั้งหมด (array of string TPU codes)
     * @return string[]
     */
    public static function getRegisteredDrugCodes(): array {
        return array_map('strval', array_keys(self::$registeredDrugs));
    }

    /**
     * ตรวจสอบว่ารหัสยาอยู่ในทะเบียนหรือไม่
     */
    public static function isRegistered(string $tpuCode): bool {
        return isset(self::$registeredDrugs[trim($tpuCode)]);
    }

    /**
     * ลงทะเบียนรหัสยาเพิ่มเติม
     */
    public static function registerDrug(string $tpuCode, array $info = []): void {
        self::$registeredDrugs[trim($tpuCode)] = array_merge([
            'code'         => 'HOSP-' . $tpuCode,
            'generic_name' => 'Hospital Registered Drug',
            'dosage'       => '',
            'form'         => '',
        ], $info);
    }

    /**
     * ลงทะเบียนกลุ่มรหัสยาเพิ่มเติม
     */
    public static function registerDrugs(array $drugs): void {
        foreach ($drugs as $tpu => $info) {
            if (is_array($info)) {
                self::registerDrug((string)$tpu, $info);
            } else {
                self::registerDrug((string)$info, []);
            }
        }
    }

    /**
     * ดึงข้อมูลสรุปทะเบียนยาสำหรับหน้า Preview
     */
    public static function getRegistrySummary(): array {
        $summary = [];
        foreach (self::$registeredDrugs as $tpu => $info) {
            $summary[] = [
                'tpu_code'       => (string)$tpu,
                'code'           => $info['code'] ?? '',
                'generic_name'   => $info['generic_name'] ?? '',
                'dosage'         => $info['dosage'] ?? '',
                'form'           => $info['form'] ?? '',
                'drug_type_name' => $info['drug_type_name'] ?? '',
            ];
        }
        return $summary;
    }

    /**
     * อ่านรายการทะเบียนยาจริงจากไฟล์ drug_registry_production.json
     */
    public static function loadProductionDrugs(?string $file = null): array {
        $target = $file ?? (__DIR__ . '/drug_registry_production.json');
        if (!file_exists($target)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($target), true);
        if (!is_array($decoded)) {
            return [];
        }
        $active = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (isset($row['is_active']) && !$row['is_active']) {
                continue;
            }
            $tpu = trim((string)($row['tpu_code_list'] ?? ''));
            if ($tpu === '') {
                continue;
            }
            $active[] = [
                'icode'          => isset($row['icode']) ? (int)$row['icode'] : null,
                'tpu_code_list'  => $tpu,
                'drugname'       => (string)($row['drugname'] ?? $row['drug_name'] ?? ''),
                'drug_type_name' => (string)($row['drug_type_name'] ?? ''),
                'units'          => (string)($row['units'] ?? $row['unit'] ?? ''),
                'is_active'      => true,
            ];
        }
        return $active;
    }

    /**
     * เปิดใช้งานทะเบียนยาจริงแทนค่าเริ่มต้นในหน่วยความจำสำหรับการดึงข้อมูลจริง
     */
    public static function activateProductionRegistry(?string $file = null): array {
        $drugs = self::loadProductionDrugs($file);
        $newMap = [];
        foreach ($drugs as $d) {
            $tpu = $d['tpu_code_list'];
            $newMap[$tpu] = [
                'code'           => $d['icode'] ? (string)$d['icode'] : ('TPU-' . $tpu),
                'generic_name'   => $d['drugname'],
                'dosage'         => '',
                'form'           => $d['units'],
                'drug_type_name' => $d['drug_type_name'],
            ];
        }
        self::$registeredDrugs = $newMap;
        return $drugs;
    }

    /**
     * บันทึกทะเบียนยาจริงลงไฟล์ JSON (ไม่เก็บข้อมูลต้นทุนยาโดยเด็ดขาด)
     */
    public static function saveProductionDrugs(array $rows, ?string $file = null): bool {
        $target = $file ?? (__DIR__ . '/drug_registry_production.json');
        $clean = [];
        foreach ($rows as $row) {
            $tpu = trim((string)($row['tpu_code_list'] ?? ''));
            if ($tpu === '') {
                continue;
            }
            $isActive = isset($row['is_active']) ? (bool)$row['is_active'] : true;
            if (!$isActive) {
                continue;
            }
            $clean[] = [
                'icode'          => isset($row['icode']) && $row['icode'] !== '' ? (int)$row['icode'] : null,
                'tpu_code_list'  => $tpu,
                'drugname'       => (string)($row['drugname'] ?? $row['drug_name'] ?? ''),
                'drug_type_name' => (string)($row['drug_type_name'] ?? ''),
                'units'          => (string)($row['units'] ?? $row['unit'] ?? ''),
                'is_active'      => true,
            ];
        }
        return file_put_contents(
            $target,
            json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }
}
