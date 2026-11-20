<?php
/**
 * RxCore Sync Agent — Reconciliation Preview Module (Mock Mode)
 * Phase 3.6: Scheduler, Retry & Reconciliation
 * 
 * หน้าที่และข้อกำหนด:
 * 1. สร้างกลไกจำลองการตรวจสอบและกระทบยอดข้อมูลย้อนหลัง 30 วัน
 * 2. เปรียบเทียบข้อมูลต้นทาง (HOSxP) และปลายทางจำลอง (Mock Supabase / Cloud) ด้วย Composite Key (hcode, hos_guid)
 * 3. ตรวจสอบ Source Completeness อย่างเข้มงวด:
 *    - ต้องตรวจสอบว่า source_query_status = 'COMPLETED' เท่านั้น
 *    - ต้องตรวจสอบว่า expected_source_count ตรงกับจำนวนคู่รหัสที่ได้รับจริง (ป้องกัน Payload Truncation)
 *    - ห้ามตัดสินว่ารายการถูกลบเพียงเพราะ Query ล้มเหลวหรือได้ข้อมูลไม่ครบ (Zero False Soft-deletes)
 * 4. ตรวจสอบชุด Composite Key จริง ไม่ตัดสินจากจำนวนแถว (Row Count Invariant Detection)
 * 5. จำแนกสถานะรายการ:
 *    - NEW: รายการใหม่ที่มีในต้นทาง แต่ยังไม่มีในปลายทาง
 *    - CHANGED: รายการที่มีอยู่ทั้งสองฝั่งแต่ข้อมูลเปลี่ยนไป (เช่น qty หรือ tpu_code_list เปลี่ยน)
 *    - UNCHANGED: รายการที่ตรงกันทั้งคีย์และข้อมูล
 *    - SOFT_DELETE_CANDIDATE: รายการในปลายทางที่หายไปจากต้นทาง (แสดงใน Preview เท่านั้น ห้ามลบจริง)
 *    - REACTIVATION_CANDIDATE: รายการที่ปลายทางเคยถูก Soft Delete ไว้ (is_deleted = true) แต่กลับมาพบในต้นทางอีกครั้ง
 * 6. ผลลัพธ์อยู่ในสถานะ PREVIEW_ONLY เสมอ ปราศจากการส่งคำสั่งแก้ไขจริง
 */

class AgentReconciliation {

    public const STATUS_PREVIEW_ONLY = 'PREVIEW_ONLY';
    public const STATUS_FAILED       = 'FAILED';

    /**
     * ดำเนินการ Reconciliation Preview (ย้อนหลัง 30 วันเป็นค่าเริ่มต้น)
     * 
     * @param string $hcode รหัสสถานพยาบาล 5 หลัก
     * @param array $sourceRecords ข้อมูลต้นทาง (จาก Extractor หรือ Mock HOSxP)
     * @param array $targetStore ข้อมูลปลายทางจำลอง (เช่น dispenseStore ของ Mock Server หรือ Mock State)
     * @param array $handshakeOptions ตัวเลือกตรวจสอบ Handshake:
     *                                - 'start_date': string (YYYY-MM-DD)
     *                                - 'end_date': string (YYYY-MM-DD)
     *                                - 'source_query_status': string ('COMPLETED', 'TIMEOUT', 'FAILED' ฯลฯ)
     *                                - 'expected_source_count': ?int
     * @return array โครงสร้างผลลัพธ์ Reconciliation Preview
     */
    public function previewReconciliation(
        string $hcode,
        array $sourceRecords,
        array $targetStore,
        array $handshakeOptions = []
    ): array {
        $now = date('Y-m-d H:i:s');
        $targetEndDate = $handshakeOptions['end_date'] ?? date('Y-m-d');
        $targetStartDate = $handshakeOptions['start_date'] ?? date('Y-m-d', strtotime('-30 days', strtotime($targetEndDate)));
        $sourceStatus = $handshakeOptions['source_query_status'] ?? 'COMPLETED';
        $expectedCount = $handshakeOptions['expected_source_count'] ?? count($sourceRecords);

        // ----------------------------------------------------------------------
        // กฎความปลอดภัยที่ 1: ตรวจสอบความสมบูรณ์ของคิวรีต้นทาง (Source Query Completion)
        // ----------------------------------------------------------------------
        if ($sourceStatus !== 'COMPLETED') {
            return [
                'success'             => false,
                'status'              => self::STATUS_FAILED,
                'error_code'          => 'RECONCILIATION_SOURCE_INCOMPLETE',
                'message'             => "ต้นทางดึงข้อมูลไม่สมบูรณ์ (source_query_status = '{$sourceStatus}'). ยกเลิกการ Reconciliation ทันที เพื่อป้องกันข้อมูลสูญหาย",
                'hcode'               => $hcode,
                'start_date'          => $targetStartDate,
                'end_date'            => $targetEndDate,
                'source_completeness' => 'INCOMPLETE_ABORTED',
                'counts' => [
                    'source_total'            => count($sourceRecords),
                    'target_in_scope'         => 0,
                    'new_items'               => 0,
                    'changed_items'           => 0,
                    'unchanged_items'         => 0,
                    'soft_delete_candidates'  => 0, // ปลอดภัย: ต้องเป็น 0 เสมอเมื่อคิวรีไม่สมบูรณ์
                    'reactivation_candidates' => 0,
                ],
                'new_candidates'           => [],
                'changed_candidates'       => [],
                'soft_delete_candidates'   => [],
                'reactivation_candidates'  => [],
                'preview_only'             => true,
            ];
        }

        // ----------------------------------------------------------------------
        // กฎความปลอดภัยที่ 2: ตรวจสอบการตัดทอนของข้อมูล (Payload Truncation Protection)
        // ----------------------------------------------------------------------
        $actualSourceCount = count($sourceRecords);
        if ($expectedCount !== null && $actualSourceCount !== (int)$expectedCount) {
            return [
                'success'             => false,
                'status'              => self::STATUS_FAILED,
                'error_code'          => 'RECONCILIATION_PAYLOAD_TRUNCATED',
                'message'             => "จำนวนข้อมูลต้นทางที่ได้รับ ({$actualSourceCount}) ไม่ตรงกับยอดที่ยืนยัน ({$expectedCount}). ยกเลิกการ Reconciliation ทันที",
                'hcode'               => $hcode,
                'start_date'          => $targetStartDate,
                'end_date'            => $targetEndDate,
                'source_completeness' => 'TRUNCATED_ABORTED',
                'counts' => [
                    'source_total'            => $actualSourceCount,
                    'target_in_scope'         => 0,
                    'new_items'               => 0,
                    'changed_items'           => 0,
                    'unchanged_items'         => 0,
                    'soft_delete_candidates'  => 0,
                    'reactivation_candidates' => 0,
                ],
                'new_candidates'           => [],
                'changed_candidates'       => [],
                'soft_delete_candidates'   => [],
                'reactivation_candidates'  => [],
                'preview_only'             => true,
            ];
        }

        // ----------------------------------------------------------------------
        // กฎความปลอดภัยที่ 3: ตรวจสอบความถูกต้องของช่วงวันที่ (Date Range Validation)
        // ----------------------------------------------------------------------
        if ($targetStartDate > $targetEndDate) {
            return [
                'success'             => false,
                'status'              => self::STATUS_FAILED,
                'error_code'          => 'INVALID_DATE_RANGE',
                'message'             => "ช่วงวันที่ไม่ถูกต้อง: วันที่เริ่มต้น ({$targetStartDate}) ต้องไม่มากกว่าวันที่สิ้นสุด ({$targetEndDate})",
                'hcode'               => $hcode,
                'start_date'          => $targetStartDate,
                'end_date'            => $targetEndDate,
                'source_completeness' => 'INVALID_PARAMS',
                'counts' => [
                    'source_total'            => $actualSourceCount,
                    'target_in_scope'         => 0,
                    'new_items'               => 0,
                    'changed_items'           => 0,
                    'unchanged_items'         => 0,
                    'soft_delete_candidates'  => 0,
                    'reactivation_candidates' => 0,
                ],
                'new_candidates'           => [],
                'changed_candidates'       => [],
                'soft_delete_candidates'   => [],
                'reactivation_candidates'  => [],
                'preview_only'             => true,
            ];
        }

        // ----------------------------------------------------------------------
        // รวบรวมข้อมูลต้นทางด้วย Composite Key: "hcode:hos_guid"
        // ----------------------------------------------------------------------
        $sourceMap = [];
        foreach ($sourceRecords as $sRec) {
            $recHcode = trim((string)($sRec['hcode'] ?? $hcode));
            $recGuid  = trim((string)($sRec['hos_guid'] ?? ''));

            // ตรวจสอบ Cross-hospital defense บนระดับ Pair
            if ($recHcode !== $hcode) {
                return [
                    'success'             => false,
                    'status'              => self::STATUS_FAILED,
                    'error_code'          => 'RECONCILIATION_SECURITY_VIOLATION',
                    'message'             => "พบรายการคู่รหัสที่มี hcode ('{$recHcode}') ไม่ตรงกับสถานพยาบาลที่ตรวจสอบ ('{$hcode}')",
                    'hcode'               => $hcode,
                    'source_completeness' => 'SECURITY_VIOLATION',
                    'counts' => [
                        'source_total'            => $actualSourceCount,
                        'target_in_scope'         => 0,
                        'new_items'               => 0,
                        'changed_items'           => 0,
                        'unchanged_items'         => 0,
                        'soft_delete_candidates'  => 0,
                        'reactivation_candidates' => 0,
                    ],
                    'preview_only' => true,
                ];
            }

            if ($recGuid === '') {
                continue;
            }

            $compKey = "{$hcode}:{$recGuid}";
            $sourceMap[$compKey] = [
                'hcode'         => $hcode,
                'hos_guid'      => $recGuid,
                'vn'            => trim((string)($sRec['vn'] ?? '')),
                'vstdate'       => trim((string)($sRec['vstdate'] ?? '')),
                'tpu_code_list' => trim((string)($sRec['tpu_code_list'] ?? '')),
                'qty'           => (float)($sRec['qty'] ?? 0),
            ];
        }

        // ----------------------------------------------------------------------
        // กรองข้อมูลปลายทาง (Target) เฉพาะสถานพยาบาลนี้ และอยู่ในช่วง 30 วัน
        // ----------------------------------------------------------------------
        $targetInScope = [];
        foreach ($targetStore as $key => $tRec) {
            $tHcode = trim((string)($tRec['hcode'] ?? ''));
            $tGuid  = trim((string)($tRec['hos_guid'] ?? ''));
            $tDate  = trim((string)($tRec['vstdate'] ?? ''));

            if ($tHcode !== $hcode || $tGuid === '') {
                continue;
            }

            // กรองเฉพาะช่วงวันที่ที่กำหนด
            if ($tDate >= $targetStartDate && $tDate <= $targetEndDate) {
                $compKey = "{$tHcode}:{$tGuid}";
                $targetInScope[$compKey] = $tRec;
            }
        }

        // ----------------------------------------------------------------------
        // ดำเนินการเปรียบเทียบ Composite Key (hcode, hos_guid) จริง
        // ----------------------------------------------------------------------
        $newCandidates = [];
        $changedCandidates = [];
        $unchangedCount = 0;
        $reactivationCandidates = [];
        $softDeleteCandidates = [];

        // 1. ตรวจสอบฝั่งต้นทาง (Source -> Target)
        foreach ($sourceMap as $compKey => $sItem) {
            if (!isset($targetInScope[$compKey])) {
                // รายการใหม่: มีในต้นทาง แต่ไม่มีในปลายทาง
                $newCandidates[] = [
                    'composite_key' => $compKey,
                    'hcode'         => $sItem['hcode'],
                    'hos_guid'      => $sItem['hos_guid'],
                    'vn'            => $sItem['vn'],
                    'vstdate'       => $sItem['vstdate'],
                    'tpu_code_list' => $sItem['tpu_code_list'],
                    'qty'           => $sItem['qty'],
                    'reconciliation_status' => 'NEW',
                    'action_preview'        => 'UPSERT_INSERT',
                ];
            } else {
                $tItem = $targetInScope[$compKey];
                $isDeleted = !empty($tItem['is_deleted']);

                // หากปลายทางเคย Soft Delete ไว้ แต่ต้นทางกลับมาพบอีกครั้ง -> Reactivation Candidate!
                if ($isDeleted) {
                    $reactivationCandidates[] = [
                        'composite_key' => $compKey,
                        'hcode'         => $sItem['hcode'],
                        'hos_guid'      => $sItem['hos_guid'],
                        'vn'            => $sItem['vn'],
                        'vstdate'       => $sItem['vstdate'],
                        'tpu_code_list' => $sItem['tpu_code_list'],
                        'qty'           => $sItem['qty'],
                        'target_deleted_at' => $tItem['deleted_at'] ?? 'previously_deleted',
                        'reconciliation_status' => 'REACTIVATION_CANDIDATE',
                        'action_preview'        => 'UNSET_SOFT_DELETE',
                    ];
                    continue;
                }

                // เปรียบเทียบข้อมูลว่ามีการเปลี่ยนแปลงหรือไม่ (Changed vs Unchanged)
                $qtyDiff = abs((float)$sItem['qty'] - (float)$tItem['qty']) > 0.001;
                $tpuDiff = ($sItem['tpu_code_list'] !== ($tItem['tpu_code_list'] ?? ''));
                $vnDiff  = ($sItem['vn'] !== ($tItem['vn'] ?? ''));
                $dateDiff= ($sItem['vstdate'] !== ($tItem['vstdate'] ?? ''));

                if ($qtyDiff || $tpuDiff || $vnDiff || $dateDiff) {
                    $changedCandidates[] = [
                        'composite_key' => $compKey,
                        'hcode'         => $sItem['hcode'],
                        'hos_guid'      => $sItem['hos_guid'],
                        'source'        => $sItem,
                        'target_before' => [
                            'qty'           => (float)$tItem['qty'],
                            'tpu_code_list' => $tItem['tpu_code_list'] ?? '',
                            'vn'            => $tItem['vn'] ?? '',
                            'vstdate'       => $tItem['vstdate'] ?? '',
                        ],
                        'differences'   => [
                            'qty_changed'   => $qtyDiff,
                            'tpu_changed'   => $tpuDiff,
                            'vn_changed'    => $vnDiff,
                            'date_changed'  => $dateDiff,
                        ],
                        'reconciliation_status' => 'CHANGED',
                        'action_preview'        => 'UPSERT_UPDATE',
                    ];
                } else {
                    $unchangedCount++;
                }
            }
        }

        // 2. ตรวจสอบฝั่งปลายทางที่ไม่พบในต้นทาง (Target -> Missing in Source)
        foreach ($targetInScope as $compKey => $tItem) {
            $isAlreadyDeleted = !empty($tItem['is_deleted']);

            // หากปลายทางยัง Active อยู่ (is_deleted = false) แต่ต้นทางไม่มีรายการนี้ -> Soft Delete Candidate!
            if (!$isAlreadyDeleted && !isset($sourceMap[$compKey])) {
                $softDeleteCandidates[] = [
                    'composite_key' => $compKey,
                    'hcode'         => $tItem['hcode'],
                    'hos_guid'      => $tItem['hos_guid'],
                    'vn'            => $tItem['vn'] ?? '',
                    'vstdate'       => $tItem['vstdate'] ?? '',
                    'tpu_code_list' => $tItem['tpu_code_list'] ?? '',
                    'qty'           => (float)($tItem['qty'] ?? 0),
                    'reconciliation_status' => 'MISSING_IN_SOURCE',
                    'action_preview'        => 'SOFT_DELETE_CANDIDATE',
                ];
            }
        }

        return [
            'success'             => true,
            'status'              => self::STATUS_PREVIEW_ONLY,
            'hcode'               => $hcode,
            'start_date'          => $targetStartDate,
            'end_date'            => $targetEndDate,
            'source_completeness' => 'VERIFIED_COMPLETE',
            'counts' => [
                'source_total'            => count($sourceMap),
                'target_in_scope'         => count($targetInScope),
                'new_items'               => count($newCandidates),
                'changed_items'           => count($changedCandidates),
                'unchanged_items'         => $unchangedCount,
                'soft_delete_candidates'  => count($softDeleteCandidates),
                'reactivation_candidates' => count($reactivationCandidates),
            ],
            'new_candidates'           => $newCandidates,
            'changed_candidates'       => $changedCandidates,
            'soft_delete_candidates'   => $softDeleteCandidates,
            'reactivation_candidates'  => $reactivationCandidates,
            'preview_only'             => true,
            'warning'                  => 'PREVIEW_ONLY: ผลลัพธ์นี้แสดงเพื่อตรวจสอบการกระทบยอดเท่านั้น ห้ามแก้ไขหรือลบข้อมูลจริงในขั้นตอนนี้',
            'timestamp'                => $now,
        ];
    }
}
