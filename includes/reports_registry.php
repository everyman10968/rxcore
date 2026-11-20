<?php
/**
 * RxCore — Central Reports Catalog & Shared Report Helpers
 * ทะเบียนรายชื่อรายงานทั้งหมดในเมนู "รายงาน" และฟังก์ชันดึงข้อมูลขนาดใหญ่แบบแบ่งหน้าอัตโนมัติ
 */

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/app.php';
}
require_once __DIR__ . '/supabase.php';

/**
 * รายการรายงานทั้งหมดที่สร้างไว้ในระบบ RxCore (เรียงเป็นแถวตามลำดับ)
 * เมื่อสร้างรายงานที่ 3, 4, 5 ... เพิ่มเติม ให้เพิ่มต่อท้ายในรายการนี้
 *
 * @return array<int, array<string, string>>
 */
function rxcore_get_reports_catalog(): array {
    return [
        [
            'id'          => 'compare_3y',
            'title'       => 'รายงานปริมาณการใช้ยา และมูลค่าการใช้ยา เทียบข้อมูล 3 ปีงบประมาณ',
            'description' => 'เปรียบเทียบข้อมูลปีงบประมาณปัจจุบัน และย้อนหลัง 1–2 ปี (กรองดูได้ทั้งเต็มปี, รายเดือนเดียวกัน, หรือสะสม YTD ทั้งภาพรวมและแยกตาม รพ.สต.)',
            'path'        => 'pages/report_compare.php',
        ],
        [
            'id'          => 'custom_range',
            'title'       => 'รายงานสรุปการใช้ยาผู้ป่วยนอก (OPD) ตามช่วงวันที่กำหนดเอง',
            'description' => 'สรุปปริมาณและมูลค่าต้นทุนการจ่ายยารายตัว โดยระบุตั้งแต่วันที่–ถึงวันที่ กรองตามหน่วยบริการและกลุ่มยา',
            'path'        => 'pages/report_custom.php',
        ],
        [
            'id'          => 'prescriptions_3y',
            'title'       => 'รายงานสรุปจำนวนใบสั่งยา แยกตามหน่วยบริการ ย้อนหลัง 3 ปีงบประมาณ',
            'description' => 'นับจำนวนใบสั่งยาผู้ป่วยนอก (COUNT DISTINCT VN) แยกตาม รพ.สต. เทียบปีงบประมาณปัจจุบัน และย้อนหลัง 1–2 ปี (กรองเต็มปี / รายเดือน / สะสม YTD)',
            'path'        => 'pages/report_prescriptions.php',
        ],
    ];
}

/**
 * ปุ่มย้อนกลับหน้ารายการรายงานทั้งหมด (แสดงด้านบนของหน้ารายงานย่อย และซ่อนอัตโนมัติเมื่อสั่งพิมพ์)
 */
function rxcore_render_report_nav(string $activeReportId): void {
    $catalog = rxcore_get_reports_catalog();
    $currentNum = null;
    foreach ($catalog as $idx => $item) {
        if ($item['id'] === $activeReportId) {
            $currentNum = $idx + 1;
            break;
        }
    }
    ?>
    <div class="rx-report-nav rx-no-print">
        <a href="<?= url('pages/reports.php') ?>" class="btn btn-rx-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> กลับหน้ารวมรายงาน
        </a>
        <?php if ($currentNum !== null): ?>
            <span class="small text-muted font-mono">รายงานลำดับที่ <?= $currentNum ?> / <?= count($catalog) ?></span>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * ดึงข้อมูลจาก Supabase PostgREST แบบแบ่งหน้าอัตโนมัติ (Chunked Pagination)
 * ป้องกันปัญหาข้อมูลเกินเพดาน 1,000 แถวเริ่มต้นของ PostgREST สำหรับรายงานเปรียบเทียบหลายปีงบประมาณ
 */
function rxcore_fetch_all_rows(string $table, string $query = 'select=*', int $chunkSize = 1000, int $maxRows = 50000): array {
    $allRows = [];
    $offset = 0;
    $cleanQuery = ltrim($query, '?');

    while ($offset < $maxRows) {
        $rangeEnd = $offset + $chunkSize - 1;
        $res = SupabaseService::request('GET', $table . '?' . $cleanQuery, null, [
            'Range-Unit: items',
            "Range: {$offset}-{$rangeEnd}",
        ]);

        if (!$res['ok'] || !is_array($res['data']) || empty($res['data'])) {
            break;
        }

        $batchCount = count($res['data']);
        foreach ($res['data'] as $row) {
            $allRows[] = $row;
        }

        if ($batchCount < $chunkSize) {
            break;
        }
        $offset += $chunkSize;
    }

    return $allRows;
}
