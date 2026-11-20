<?php
/**
 * RxCore Sync Agent — Data Extractor & Validation Module
 * Phase 3.2: Read-only Data Extraction
 * 
 * หน้าที่:
 * 1. ดึงข้อมูลการจ่ายยาจาก HOSxP tables: opitemrece, drugitems, vn_stat (Read-only)
 * 2. ตรวจสอบเงื่อนไขความถูกต้องของข้อมูล (Validation Pipeline):
 *    - เลือกเฉพาะรหัสยาที่อยู่ในทะเบียนยาที่กำหนด (AgentDrugRegistry)
 *    - ยาที่ไม่มีในทะเบียนให้ข้ามโดยไม่บันทึก Log
 *    - hos_guid ต้องไม่ว่าง (ไม่ใช่ค่าว่าง หรือ whitespace)
 *    - qty ต้องมากกว่า 0 และเป็นตัวเลข
 *    - vn และ hcode ต้องมีค่าและรูปแบบถูกต้อง (hcode 5 หลัก)
 *    - ป้องกันรายการซ้ำด้วย Composite Key (hcode, hos_guid)
 *      (hos_guid เดียวกันแต่คนละ hcode ไม่ถือเป็นรายการซ้ำ)
 * 3. ส่งออกเฉพาะ 6 ฟิลด์ที่กำหนด: hos_guid, vn, hcode, vstdate, tpu_code_list, qty
 *    (ห้ามดึง HN, CID, ชื่อผู้ป่วย หรือ PII ใดๆ)
 */

require_once __DIR__ . '/agent_drug_registry.php';
require_once __DIR__ . '/mock_dataset.php';

class AgentDataExtractor {

    private ?PDO $pdo;
    private array $registeredDrugCodes;

    /**
     * @param PDO|null $pdo หากไม่ระบุ จะสร้าง Mock In-memory PDO อัตโนมัติ
     * @param array|null $registeredDrugCodes หากไม่ระบุ จะดึงจาก AgentDrugRegistry
     */
    public function __construct(?PDO $pdo = null, ?array $registeredDrugCodes = null) {
        $this->pdo = $pdo ?? MockDatasetPhase32::createMockPdo();
        $this->registeredDrugCodes = $registeredDrugCodes ?? AgentDrugRegistry::getRegisteredDrugCodes();
    }

    /**
     * สร้าง SQL Query และ Bind Parameters อย่างปลอดภัยด้วย PDO
     * ห้ามนำค่าผู้ใช้มาต่อ String ใน SQL โดยตรง
     * 
     * @param string[] $drugCodes รายการรหัสยา TPU
     * @return array ['sql' => string, 'placeholders' => string[]]
     */
    public static function buildQuery(array $drugCodes): array {
        if (empty($drugCodes)) {
            // กรณีไม่มีรหัสยาในทะเบียน ให้ใช้เงื่อนไขที่เป็นเท็จอย่างปลอดภัย
            $sql = "
                SELECT
                    r.hos_guid,
                    r.vn,
                    v.hcode,
                    r.vstdate,
                    d.tpu_code_list,
                    r.qty
                FROM opitemrece r
                INNER JOIN drugitems d ON d.icode = r.icode
                INNER JOIN vn_stat v ON v.vn = r.vn
                WHERE 1 = 0
                ORDER BY r.vstdate, r.vn
            ";
            return ['sql' => $sql, 'placeholders' => []];
        }

        // สร้าง Named Placeholders สำหรับ IN clause เช่น :drug_0, :drug_1, ...
        $placeholders = [];
        foreach (array_values($drugCodes) as $idx => $code) {
            $placeholders[':drug_' . $idx] = (string)$code;
        }

        $inClause = implode(', ', array_keys($placeholders));

        $sql = "
            SELECT
                r.hos_guid,
                r.vn,
                r.hn,
                v.hcode,
                r.vstdate,
                d.tpu_code_list,
                r.qty
            FROM opitemrece r
            INNER JOIN drugitems d ON d.icode = r.icode
            INNER JOIN vn_stat v ON v.vn = r.vn
            WHERE r.vstdate >= :start_date
              AND r.vstdate <= :end_date
              AND d.tpu_code_list IN ({$inClause})
            ORDER BY r.vstdate, r.vn
        ";

        return [
            'sql'          => trim($sql),
            'placeholders' => $placeholders,
        ];
    }

    /**
     * ดึงและตรวจสอบข้อมูลการจ่ายยาตามช่วงวันที่กำหนด
     * 
     * @param string $startDate รูปแบบ YYYY-MM-DD
     * @param string $endDate   รูปแบบ YYYY-MM-DD
     * @return array ผลลัพธ์การสกัดและตรวจสอบข้อมูล
     */
    public function extract(string $startDate, string $endDate): array {
        $startTime = microtime(true);

        // 1. ตรวจสอบรูปแบบวันที่
        if (!$this->validateDateFormat($startDate) || !$this->validateDateFormat($endDate)) {
            return [
                'success'           => false,
                'error'             => 'รูปแบบวันที่ไม่ถูกต้อง ต้องอยู่ในรูปแบบ YYYY-MM-DD',
                'start_date'        => $startDate,
                'end_date'          => $endDate,
                'total_read'        => 0,
                'total_passed'      => 0,
                'duplicates_pruned' => 0,
                'total_malformed'   => 0,
                'malformed_summary' => [],
                'sample_data'       => [],
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        }

        if ($startDate > $endDate) {
            return [
                'success'           => false,
                'error'             => 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',
                'start_date'        => $startDate,
                'end_date'          => $endDate,
                'total_read'        => 0,
                'total_passed'      => 0,
                'duplicates_pruned' => 0,
                'total_malformed'   => 0,
                'malformed_summary' => [],
                'sample_data'       => [],
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        }

        // 2. ดำเนินการ Query ผ่าน PDO พร้อม Parameter Binding
        try {
            $queryInfo = self::buildQuery($this->registeredDrugCodes);
            $stmt = $this->pdo->prepare($queryInfo['sql']);

            // Bind วันที่
            if (!empty($queryInfo['placeholders'])) {
                $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
                $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);

                // Bind รหัสยาทีละตัวแบบ PDO Type-Safe
                foreach ($queryInfo['placeholders'] as $param => $code) {
                    $stmt->bindValue($param, $code, PDO::PARAM_STR);
                }
            }

            $stmt->execute();
            $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            // ป้องกันข้อมูลการเชื่อมต่อและ credentials หลุดในข้อความผิดพลาด
            $safeError = 'Database query failed: ' . $this->sanitizeErrorMessage($e->getMessage());
            return [
                'success'           => false,
                'error'             => $safeError,
                'start_date'        => $startDate,
                'end_date'          => $endDate,
                'total_read'        => 0,
                'total_passed'      => 0,
                'duplicates_pruned' => 0,
                'total_malformed'   => 0,
                'malformed_summary' => [],
                'sample_data'       => [],
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        } catch (Throwable $e) {
            $safeError = 'Extraction exception: ' . $this->sanitizeErrorMessage($e->getMessage());
            return [
                'success'           => false,
                'error'             => $safeError,
                'start_date'        => $startDate,
                'end_date'          => $endDate,
                'total_read'        => 0,
                'total_passed'      => 0,
                'duplicates_pruned' => 0,
                'total_malformed'   => 0,
                'malformed_summary' => [],
                'sample_data'       => [],
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
        }

        $totalRead = count($rawRows);
        $passedRecords = [];
        $duplicatesPruned = 0;
        $malformedSummary = [
            'missing_hos_guid'        => 0,
            'invalid_qty'             => 0,
            'missing_vn'              => 0,
            'missing_or_invalid_hcode' => 0,
            'invalid_vstdate'         => 0,
        ];
        $malformedIssues = [];
        $seenCompositeKeys = [];

        // 3. Validation & Sanitization Pipeline
        foreach ($rawRows as $idx => $row) {
            $hosGuid = isset($row['hos_guid']) ? trim((string)$row['hos_guid']) : '';
            $vn      = isset($row['vn']) ? trim((string)$row['vn']) : '';
            $hn      = isset($row['hn']) ? trim((string)$row['hn']) : '';
            $hcode   = isset($row['hcode']) ? trim((string)$row['hcode']) : '';
            $vstdate = isset($row['vstdate']) ? trim((string)$row['vstdate']) : '';
            $tpuCode = isset($row['tpu_code_list']) ? trim((string)$row['tpu_code_list']) : '';
            $qtyRaw  = $row['qty'] ?? null;

            // เงื่อนไขที่ 1: ตรวจสอบทะเบียนยา (หากหลุดมา ต้องข้ามโดยไม่บันทึก Log)
            if (!AgentDrugRegistry::isRegistered($tpuCode)) {
                continue;
            }

            // เงื่อนไขที่ 2: hos_guid ต้องไม่ว่าง
            if ($hosGuid === '') {
                $malformedSummary['missing_hos_guid']++;
                $malformedIssues[] = [
                    'row'    => $idx + 1,
                    'reason' => 'hos_guid ว่างหรือไม่ระบุ',
                    'field'  => 'hos_guid',
                ];
                continue;
            }

            // เงื่อนไขที่ 3: qty ต้องเป็นตัวเลขและมากกว่า 0
            if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
                $malformedSummary['invalid_qty']++;
                $malformedIssues[] = [
                    'row'    => $idx + 1,
                    'reason' => 'qty ต้องมากกว่า 0 (พบค่า: ' . htmlspecialchars((string)$qtyRaw) . ')',
                    'field'  => 'qty',
                ];
                continue;
            }

            // เงื่อนไขที่ 4: vn ต้องไม่ว่าง
            if ($vn === '') {
                $malformedSummary['missing_vn']++;
                $malformedIssues[] = [
                    'row'    => $idx + 1,
                    'reason' => 'vn ว่างหรือไม่ระบุ',
                    'field'  => 'vn',
                ];
                continue;
            }

            // เงื่อนไขที่ 5: hcode ต้องไม่ว่างและเป็นตัวเลข 5 หลัก
            if ($hcode === '' || !preg_match('/^[0-9]{5}$/', $hcode)) {
                $malformedSummary['missing_or_invalid_hcode']++;
                $malformedIssues[] = [
                    'row'    => $idx + 1,
                    'reason' => 'hcode ไม่ถูกต้อง ต้องเป็นรหัส 5 หลัก (พบ: ' . htmlspecialchars($hcode) . ')',
                    'field'  => 'hcode',
                ];
                continue;
            }

            // เงื่อนไขที่ 6: vstdate รูปแบบวันที่ถูกต้อง
            if (!$this->validateDateFormat($vstdate)) {
                $malformedSummary['invalid_vstdate']++;
                $malformedIssues[] = [
                    'row'    => $idx + 1,
                    'reason' => 'vstdate ผิดรูปแบบ',
                    'field'  => 'vstdate',
                ];
                continue;
            }

            // เงื่อนไขที่ 7: ป้องกันรายการซ้ำด้วย Composite Key (hcode, hos_guid)
            $compositeKey = $hcode . '|' . $hosGuid;
            if (isset($seenCompositeKeys[$compositeKey])) {
                $duplicatesPruned++;
                // ห้ามบันทึกข้อมูลส่วนบุคคลลง Log
                continue;
            }
            $seenCompositeKeys[$compositeKey] = true;

            // ผ่านการตรวจสอบทุกเงื่อนไข: เก็บฟิลด์ที่ได้รับอนุญาต
            $passedRecords[] = [
                'hos_guid'      => $hosGuid,
                'vn'            => $vn,
                'hn'            => $hn,
                'hcode'         => $hcode,
                'vstdate'       => $vstdate,
                'tpu_code_list' => $tpuCode,
                'qty'           => (float)$qtyRaw,
            ];
        }

        $totalMalformed = array_sum($malformedSummary);
        $executionTimeMs = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'success'           => true,
            'start_date'        => $startDate,
            'end_date'          => $endDate,
            'total_read'        => $totalRead,
            'total_passed'      => count($passedRecords),
            'duplicates_pruned' => $duplicatesPruned,
            'total_malformed'   => $totalMalformed,
            'malformed_summary' => $malformedSummary,
            'malformed_issues'  => $malformedIssues,
            'sample_data'       => $passedRecords,
            'execution_time_ms' => $executionTimeMs,
            'message'           => sprintf(
                'อ่านข้อมูล %d รายการ | ผ่านการตรวจสอบ %d รายการ | ตัดซ้ำ %d รายการ | ข้อมูลผิดรูปแบบ %d รายการ',
                $totalRead,
                count($passedRecords),
                $duplicatesPruned,
                $totalMalformed
            ),
        ];
    }

    /**
     * ตรวจสอบรูปแบบวันที่ YYYY-MM-DD
     */
    private function validateDateFormat(string $date): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }
        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
    }

    /**
     * กำจัดข้อความที่มีรหัสผ่าน, path ระบบ หรือข้อมูลความลับออกจาก Error Message
     */
    private function sanitizeErrorMessage(string $message): string {
        // ซ่อน password หรือ credential patterns
        $sanitized = preg_replace('/password[=:\s]+[^\s,;]+/i', 'password=***', $message);
        // ซ่อน absolute system paths
        $sanitized = preg_replace('/[a-zA-Z]:\\\\[^\s:;,]+/i', '[hidden_path]', $sanitized);
        return $sanitized;
    }
}
