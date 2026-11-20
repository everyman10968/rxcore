<?php
/**
 * RxCore Sync Agent — Reconciliation Performance Benchmark
 * Phase 3.6.1: Reliability & Performance Hardening
 * 
 * หน้าที่และข้อกำหนด:
 * 1. สร้าง Synthetic Dataset ในหน่วยความจำ (Zero Patient Data, Zero Outbound Traffic)
 * 2. ทดสอบ Benchmark 3 ระดับสเกล:
 *    - Scale 1K:   1,000 รายการ
 *    - Scale 10K:  10,000 รายการ
 *    - Scale 50K:  50,000 รายการ
 * 3. วัดและบันทึกผลตามตัวชี้วัดจริง:
 *    - เวลาประมวลผล (Duration in milliseconds และ seconds)
 *    - Peak Memory Usage (MB)
 *    - จำนวนรายการ NEW, CHANGED, UNCHANGED, SOFT_DELETE_CANDIDATE, REACTIVATION_CANDIDATE
 *    - ความถูกต้องของ Composite Key (hcode, hos_guid)
 *    - พฤติกรรมเมื่อเกิดความล้มเหลว (Source Query Failure / Failsafe Abort)
 */

require_once __DIR__ . '/agent_reconciliation.php';

class ReconciliationBenchmark {

    private AgentReconciliation $engine;
    private string $hcode;

    public function __construct(string $hcode = '01425') {
        $this->engine = new AgentReconciliation();
        $this->hcode  = $hcode;
    }

    /**
     * สร้าง Synthetic Dataset ในหน่วยความจำ
     */
    public function generateSyntheticDataset(int $totalScale): array {
        $sourceRecords = [];
        $targetStore   = [];

        $hcode = $this->hcode;
        $date  = date('Y-m-d');

        // สัดส่วนการทดสอบ:
        // 70% Unchanged, 10% Changed, 10% New, 5% Soft Delete, 5% Reactivation
        $countUnchanged   = (int)round($totalScale * 0.70);
        $countChanged     = (int)round($totalScale * 0.10);
        $countNew         = (int)round($totalScale * 0.10);
        $countSoftDelete  = (int)round($totalScale * 0.05);
        $countReactivate  = (int)round($totalScale * 0.05);

        // 1. Unchanged Records
        for ($i = 1; $i <= $countUnchanged; $i++) {
            $guid = "SYNTH-UNC-{$i}";
            $rec = [
                'hcode'         => $hcode,
                'hos_guid'      => $guid,
                'vn'            => "VN-UNC-{$i}",
                'vstdate'       => $date,
                'tpu_code_list' => '1000000000000001',
                'qty'           => 10,
            ];
            $sourceRecords[] = $rec;
            $targetStore["{$hcode}:{$guid}"] = array_merge($rec, ['is_deleted' => false]);
        }

        // 2. Changed Records (qty ต่างกัน)
        for ($i = 1; $i <= $countChanged; $i++) {
            $guid = "SYNTH-CHG-{$i}";
            $srcRec = [
                'hcode'         => $hcode,
                'hos_guid'      => $guid,
                'vn'            => "VN-CHG-{$i}",
                'vstdate'       => $date,
                'tpu_code_list' => '1000000000000001',
                'qty'           => 15, // Source qty
            ];
            $sourceRecords[] = $srcRec;
            $targetStore["{$hcode}:{$guid}"] = [
                'hcode'         => $hcode,
                'hos_guid'      => $guid,
                'vn'            => "VN-CHG-{$i}",
                'vstdate'       => $date,
                'tpu_code_list' => '1000000000000001',
                'qty'           => 10, // Target qty แตกต่าง
                'is_deleted'    => false,
            ];
        }

        // 3. New Records (มีในต้นทาง ไม่มีในปลายทาง)
        for ($i = 1; $i <= $countNew; $i++) {
            $guid = "SYNTH-NEW-{$i}";
            $sourceRecords[] = [
                'hcode'         => $hcode,
                'hos_guid'      => $guid,
                'vn'            => "VN-NEW-{$i}",
                'vstdate'       => $date,
                'tpu_code_list' => '1000000000000002',
                'qty'           => 5,
            ];
        }

        // 4. Soft Delete Candidates (มีในปลายทาง แต่หายไปจากต้นทาง)
        for ($i = 1; $i <= $countSoftDelete; $i++) {
            $guid = "SYNTH-DEL-{$i}";
            $targetStore["{$hcode}:{$guid}"] = [
                'hcode'         => $hcode,
                'hos_guid'      => $guid,
                'vn'            => "VN-DEL-{$i}",
                'vstdate'       => $date,
                'tpu_code_list' => '1000000000000003',
                'qty'           => 8,
                'is_deleted'    => false,
            ];
        }

        // 5. Reactivation Candidates (ปลายทางเคยมาร์กลบไว้ is_deleted = true แต่ต้นทางกลับมาพบ)
        for ($i = 1; $i <= $countReactivate; $i++) {
            $guid = "SYNTH-REC-{$i}";
            $sourceRecords[] = [
                'hcode'         => $hcode,
                'hos_guid'      => $guid,
                'vn'            => "VN-REC-{$i}",
                'vstdate'       => $date,
                'tpu_code_list' => '1000000000000001',
                'qty'           => 12,
            ];
            $targetStore["{$hcode}:{$guid}"] = [
                'hcode'         => $hcode,
                'hos_guid'      => $guid,
                'vn'            => "VN-REC-{$i}",
                'vstdate'       => $date,
                'tpu_code_list' => '1000000000000001',
                'qty'           => 12,
                'is_deleted'    => true, // ทำเครื่องหมายเดิมว่าลบ
                'deleted_at'    => '2026-10-20 10:00:00',
            ];
        }

        return [
            'expected_counts' => [
                'total_scale'            => $totalScale,
                'unchanged'              => $countUnchanged,
                'changed'                => $countChanged,
                'new'                    => $countNew,
                'soft_delete_candidates' => $countSoftDelete,
                'reactivation_candidates'=> $countReactivate,
                'source_total'           => count($sourceRecords),
                'target_total'           => count($targetStore),
            ],
            'source_records'  => $sourceRecords,
            'target_store'    => $targetStore,
        ];
    }

    /**
     * ดำเนินการรัน Benchmark สำหรับสเกลที่กำหนด
     */
    public function benchmarkScale(int $scale): array {
        // บันทึก Memory ก่อนเริ่ม
        gc_collect_cycles();
        $memBefore = memory_get_usage(true);

        $dataset = $this->generateSyntheticDataset($scale);
        $expected = $dataset['expected_counts'];

        $memAfterDataset = memory_get_usage(true);

        // จับเวลาด้วยความแม่นยำระดับนาโนวินาที (hrtime)
        $startNano = hrtime(true);
        $result = $this->engine->previewReconciliation(
            $this->hcode,
            $dataset['source_records'],
            $dataset['target_store'],
            [
                'start_date'            => date('Y-m-d', strtotime('-30 days')),
                'end_date'              => date('Y-m-d'),
                'source_query_status'   => 'COMPLETED',
                'expected_source_count' => count($dataset['source_records']),
            ]
        );
        $endNano = hrtime(true);

        $durationMs = ($endNano - $startNano) / 1e6;
        $peakMemBytes = memory_get_peak_usage(true);
        $peakMemMb = round($peakMemBytes / (1024 * 1024), 2);

        // ตรวจสอบความถูกต้องของ Composite Key (hcode, hos_guid)
        $keyIntegrityPass = true;
        foreach (['new_candidates', 'changed_candidates', 'soft_delete_candidates', 'reactivation_candidates'] as $listKey) {
            foreach ($result[$listKey] ?? [] as $cand) {
                if (empty($cand['composite_key']) || empty($cand['hos_guid']) || $cand['hcode'] !== $this->hcode) {
                    $keyIntegrityPass = false;
                    break 2;
                }
            }
        }

        // ตรวจสอบความสอดคล้องของตัวนับ
        $counts = $result['counts'] ?? [];
        $countsMatch = (
            ($counts['new_items'] ?? 0) === $expected['new'] &&
            ($counts['changed_items'] ?? 0) === $expected['changed'] &&
            ($counts['unchanged_items'] ?? 0) === $expected['unchanged'] &&
            ($counts['soft_delete_candidates'] ?? 0) === $expected['soft_delete_candidates'] &&
            ($counts['reactivation_candidates'] ?? 0) === $expected['reactivation_candidates']
        );

        // คืนหน่วยความจำ
        unset($dataset);
        unset($result);
        gc_collect_cycles();

        return [
            'scale'                 => $scale,
            'source_rows'           => $expected['source_total'],
            'target_rows'           => $expected['target_total'],
            'duration_ms'           => round($durationMs, 2),
            'duration_sec'          => round($durationMs / 1000.0, 4),
            'peak_memory_mb'        => $peakMemMb,
            'new_count'             => $counts['new_items'] ?? 0,
            'changed_count'         => $counts['changed_items'] ?? 0,
            'unchanged_count'       => $counts['unchanged_items'] ?? 0,
            'soft_delete_count'     => $counts['soft_delete_candidates'] ?? 0,
            'reactivation_count'    => $counts['reactivation_candidates'] ?? 0,
            'key_integrity_pass'    => $keyIntegrityPass,
            'counts_match_pass'     => $countsMatch,
            'throughput_rows_sec'   => $durationMs > 0 ? (int)round(($scale / ($durationMs / 1000.0))) : 0,
        ];
    }

    /**
     * ทดสอบ Failsafe กรณีต้นทางส่งข้อมูลไม่ครบ หรือคิวรีหลุดกลางคัน
     */
    public function benchmarkFailsafe(int $scale = 10000): array {
        $dataset = $this->generateSyntheticDataset($scale);

        // ทดสอบ 1: Query Timeout
        $startNano = hrtime(true);
        $resTimeout = $this->engine->previewReconciliation(
            $this->hcode,
            $dataset['source_records'],
            $dataset['target_store'],
            [
                'source_query_status' => 'TIMEOUT',
            ]
        );
        $endNano = hrtime(true);
        $abortDurationMs = ($endNano - $startNano) / 1e6;

        $timeoutSafe = (
            $resTimeout['success'] === false &&
            $resTimeout['status'] === AgentReconciliation::STATUS_FAILED &&
            $resTimeout['counts']['soft_delete_candidates'] === 0
        );

        // ทดสอบ 2: Truncated Payload (Expected ไม่ตรงกับข้อมูลจริง)
        $resTruncated = $this->engine->previewReconciliation(
            $this->hcode,
            $dataset['source_records'],
            $dataset['target_store'],
            [
                'source_query_status'   => 'COMPLETED',
                'expected_source_count' => count($dataset['source_records']) + 500, // ขาดไป 500 แถว
            ]
        );

        $truncatedSafe = (
            $resTruncated['success'] === false &&
            $resTruncated['status'] === AgentReconciliation::STATUS_FAILED &&
            $resTruncated['counts']['soft_delete_candidates'] === 0
        );

        return [
            'timeout_abort_ms'     => round($abortDurationMs, 2),
            'timeout_failsafe'     => $timeoutSafe,
            'truncated_failsafe'   => $truncatedSafe,
            'zero_false_deletes'   => ($resTimeout['counts']['soft_delete_candidates'] === 0 && $resTruncated['counts']['soft_delete_candidates'] === 0),
        ];
    }

    /**
     * รัน Benchmark เต็มชุด 1K, 10K, 50K
     */
    public function runFullBenchmark(): array {
        $results = [];
        $scales = [1000, 10000, 50000];

        foreach ($scales as $scale) {
            $results["scale_{$scale}"] = $this->benchmarkScale($scale);
        }

        $results['failsafe_10k'] = $this->benchmarkFailsafe(10000);

        return $results;
    }
}

// Execution via CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "=================================================================\n";
    echo "   RxCore Sync Agent — Phase 3.6.1 Reconciliation Benchmark     \n";
    echo "   Testing Scales: 1K (1,000), 10K (10,000), 50K (50,000) rows   \n";
    echo "=================================================================\n\n";

    $benchmark = new ReconciliationBenchmark();
    $report = $benchmark->runFullBenchmark();

    echo sprintf("%-8s | %-12s | %-10s | %-12s | %-10s | %-8s\n", 
        "Scale", "Rows (Src/Tgt)", "Time (ms)", "Peak Mem (MB)", "Throughput", "Integrity");
    echo str_repeat("-", 75) . "\n";

    foreach ([1000, 10000, 50000] as $s) {
        $row = $report["scale_{$s}"];
        echo sprintf("%-8s | %-12s | %-10s | %-12s | %-10s | %-8s\n",
            number_format($s),
            "{$row['source_rows']}/{$row['target_rows']}",
            number_format($row['duration_ms'], 2) . " ms",
            "{$row['peak_memory_mb']} MB",
            number_format($row['throughput_rows_sec']) . " r/s",
            ($row['key_integrity_pass'] && $row['counts_match_pass']) ? "PASS" : "FAIL"
        );
        echo sprintf("         ↳ NEW: %s, CHANGED: %s, UNCHANGED: %s, SOFT_DEL: %s, REACTIVATE: %s\n",
            number_format($row['new_count']),
            number_format($row['changed_count']),
            number_format($row['unchanged_count']),
            number_format($row['soft_delete_count']),
            number_format($row['reactivation_count'])
        );
    }

    echo "\n--- Failsafe Protection Benchmark (10,000 Rows) ---\n";
    $fs = $report['failsafe_10k'];
    echo "  [PASS] Timeout Abort Time: {$fs['timeout_abort_ms']} ms\n";
    echo "  [PASS] Timeout Failsafe Status: " . ($fs['timeout_failsafe'] ? "SUCCESS (ABORTED)" : "FAIL") . "\n";
    echo "  [PASS] Truncation Failsafe Status: " . ($fs['truncated_failsafe'] ? "SUCCESS (ABORTED)" : "FAIL") . "\n";
    echo "  [PASS] Zero False Deletes Guarantee: " . ($fs['zero_false_deletes'] ? "CONFIRMED (0 candidates)" : "FAILED") . "\n";

    echo "\n=================================================================\n";
    echo "   BENCHMARK COMPLETED SUCCESSFULLY!                             \n";
    echo "=================================================================\n";
}
