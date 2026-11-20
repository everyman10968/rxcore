<?php
/**
 * RxCore — รายงานที่ 3: รายงานสรุปจำนวนใบสั่งยา แยกตามหน่วยบริการ ย้อนหลัง 3 ปีงบประมาณ
 * นับจำนวนใบสั่งยาผู้ป่วยนอกแบบไม่ซ้ำใบ (COUNT DISTINCT vn) แยกตามหน่วยบริการ (รพ.สต.)
 * เปรียบเทียบปีงบประมาณปัจจุบัน (Y1) เทียบย้อนหลัง 1 ปี (Y2) และย้อนหลัง 2 ปี (Y3)
 * รองรับการกรองทั้งปีงบประมาณ, รายเดือนเดียวกัน, และสะสมถึงปัจจุบัน (YTD)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/supabase.php';
require_once dirname(__DIR__) . '/includes/reports_registry.php';

$current_page = 'reports';
$page_title = 'รายงานสรุปจำนวนใบสั่งยา แยกตามหน่วยบริการ ย้อนหลัง 3 ปี';

// -----------------------------------------------------------------------------
// 1. คำนวณปีงบประมาณไทยและตัวกรอง
// -----------------------------------------------------------------------------
$today = new DateTime();
$currentMonth = (int)$today->format('n');
$currentYearAD = (int)$today->format('Y');

$currentFiscalYearBE = ($currentMonth >= 10) ? ($currentYearAD + 544) : ($currentYearAD + 543);

$targetBE = isset($_GET['fiscal_year']) ? (int)$_GET['fiscal_year'] : $currentFiscalYearBE;
if ($targetBE < 2555 || $targetBE > 2600) {
    $targetBE = $currentFiscalYearBE;
}

$y1_BE = $targetBE;
$y2_BE = $targetBE - 1;
$y3_BE = $targetBE - 2;

$periodMode = $_GET['period_mode'] ?? 'full';
if (!in_array($periodMode, ['full', 'month', 'ytd'], true)) {
    $periodMode = 'full';
}

$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}

$selectedHc = trim($_GET['hcode'] ?? '');
$user = current_user();
if (!is_admin() && !empty($user['hcode'])) {
    $selectedHc = $user['hcode'];
}

function rxcore_rx3_fiscal_date_range(int $fiscalYearBE, string $mode, int $monthNum): array {
    $adEnd = $fiscalYearBE - 543;
    $adStart = $adEnd - 1;

    if ($mode === 'month') {
        $yearOfThatMonth = ($monthNum >= 10) ? $adStart : $adEnd;
        $dStart = sprintf('%04d-%02d-01', $yearOfThatMonth, $monthNum);
        $lastDay = (int)date('t', strtotime($dStart));
        $dEnd = sprintf('%04d-%02d-%02d', $yearOfThatMonth, $monthNum, $lastDay);
        return [$dStart, $dEnd];
    }

    if ($mode === 'ytd') {
        $now = new DateTime();
        $currMonth = (int)$now->format('n');
        $currDay = (int)$now->format('j');
        $endYear = ($currMonth >= 10) ? $adStart : $adEnd;
        $maxDay = (int)date('t', strtotime(sprintf('%04d-%02d-01', $endYear, $currMonth)));
        $safeDay = min($currDay, $maxDay);
        return [
            sprintf('%04d-10-01', $adStart),
            sprintf('%04d-%02d-%02d', $endYear, $currMonth, $safeDay),
        ];
    }

    return [
        sprintf('%04d-10-01', $adStart),
        sprintf('%04d-09-30', $adEnd),
    ];
}

function rxcore_rx3_thai_short_date(string $isoDate): string {
    $shortMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];
    $ts = strtotime($isoDate);
    if ($ts === false) {
        return $isoDate;
    }
    return sprintf('%d %s %02d', (int)date('j', $ts), $shortMonths[(int)date('n', $ts)] ?? '', ((int)date('Y', $ts) + 543) % 100);
}

[$y1_start, $y1_end] = rxcore_rx3_fiscal_date_range($y1_BE, $periodMode, $selectedMonth);
[$y2_start, $y2_end] = rxcore_rx3_fiscal_date_range($y2_BE, $periodMode, $selectedMonth);
[$y3_start, $y3_end] = rxcore_rx3_fiscal_date_range($y3_BE, $periodMode, $selectedMonth);

// -----------------------------------------------------------------------------
// 2. ดึงรายชื่อหน่วยบริการ (รพ.สต.)
// -----------------------------------------------------------------------------
$healthCenters = SupabaseService::select('rx_health_centers', 'select=hcode,name,subdistrict&is_active=eq.true&order=hcode.asc');
$byCenter = [];
$hcMap = [];

foreach ($healthCenters as $hc) {
    $h = (string)$hc['hcode'];
    $hcMap[$h] = $hc['name'];
    if ($selectedHc !== '' && $selectedHc !== $h) {
        continue;
    }
    $byCenter[$h] = [
        'hcode'       => $h,
        'name'        => $hc['name'],
        'subdistrict' => $hc['subdistrict'] ?? '',
        'y1_vn'       => 0, 'y1_items' => 0,
        'y2_vn'       => 0, 'y2_items' => 0,
        'y3_vn'       => 0, 'y3_items' => 0,
    ];
}

// -----------------------------------------------------------------------------
// 3. คำนวณ COUNT(DISTINCT vn) แยกตามหน่วยบริการ ทั้ง 3 ปีงบประมาณ
//    - ลำดับแรกเรียก RPC fn_rx_report_prescriptions_3y (หากติดตั้งใน Supabase แล้ว)
//    - หากยังไม่ได้รันไฟล์ SQL Migration จะคำนวณ COUNT(DISTINCT vn) จาก rx_dispense โดยตรงทันที
// -----------------------------------------------------------------------------
$rpcRes = SupabaseService::rpc('fn_rx_report_prescriptions_3y', [
    'p_y1_start' => $y1_start,
    'p_y1_end'   => $y1_end,
    'p_y2_start' => $y2_start,
    'p_y2_end'   => $y2_end,
    'p_y3_start' => $y3_start,
    'p_y3_end'   => $y3_end,
    'p_hcode'    => $selectedHc !== '' ? $selectedHc : null,
]);

if ($rpcRes['ok'] && is_array($rpcRes['data'])) {
    foreach ($rpcRes['data'] as $r) {
        $h = (string)($r['hcode'] ?? '');
        if (!isset($byCenter[$h])) {
            continue;
        }
        $byCenter[$h]['y1_vn']    = (int)($r['y1_vn_count'] ?? 0);
        $byCenter[$h]['y1_items'] = (int)($r['y1_item_count'] ?? 0);
        $byCenter[$h]['y2_vn']    = (int)($r['y2_vn_count'] ?? 0);
        $byCenter[$h]['y2_items'] = (int)($r['y2_item_count'] ?? 0);
        $byCenter[$h]['y3_vn']    = (int)($r['y3_vn_count'] ?? 0);
        $byCenter[$h]['y3_items'] = (int)($r['y3_item_count'] ?? 0);
    }
} else {
    $orClause = sprintf(
        'or=(and(vstdate.gte.%s,vstdate.lte.%s),and(vstdate.gte.%s,vstdate.lte.%s),and(vstdate.gte.%s,vstdate.lte.%s))',
        $y1_start,
        $y1_end,
        $y2_start,
        $y2_end,
        $y3_start,
        $y3_end
    );
    $queryParts = [
        'select=hcode,vn,vstdate',
        'is_deleted=eq.false',
        $orClause,
    ];
    if ($selectedHc !== '') {
        $queryParts[] = 'hcode=eq.' . urlencode($selectedHc);
    }

    $rawRows = rxcore_fetch_all_rows('rx_dispense', implode('&', $queryParts), 1000, 250000);
    $vnSets = [];

    foreach ($rawRows as $row) {
        $h = (string)($row['hcode'] ?? '');
        $vn = trim((string)($row['vn'] ?? ''));
        $vstdate = (string)($row['vstdate'] ?? '');
        if ($h === '' || $vn === '') {
            continue;
        }

        $slot = 0;
        if ($vstdate >= $y1_start && $vstdate <= $y1_end) {
            $slot = 1;
        } elseif ($vstdate >= $y2_start && $vstdate <= $y2_end) {
            $slot = 2;
        } elseif ($vstdate >= $y3_start && $vstdate <= $y3_end) {
            $slot = 3;
        }
        if ($slot === 0) {
            continue;
        }

        if (!isset($byCenter[$h])) {
            $byCenter[$h] = [
                'hcode'       => $h,
                'name'        => $hcMap[$h] ?? $h,
                'subdistrict' => '',
                'y1_vn'       => 0, 'y1_items' => 0,
                'y2_vn'       => 0, 'y2_items' => 0,
                'y3_vn'       => 0, 'y3_items' => 0,
            ];
        }

        $byCenter[$h]["y{$slot}_items"]++;
        $vnSets[$h][$slot][$vn] = true;
    }

    foreach ($vnSets as $h => $slots) {
        for ($s = 1; $s <= 3; $s++) {
            $byCenter[$h]["y{$s}_vn"] = isset($slots[$s]) ? count($slots[$s]) : 0;
        }
    }
}

// เรียงตามจำนวนใบสั่งยาปีหลัก (y1_vn) มากไปน้อย หากเท่ากันเรียงตามรหัสหน่วยบริการ
uasort($byCenter, function ($a, $b) {
    if ($b['y1_vn'] !== $a['y1_vn']) {
        return $b['y1_vn'] <=> $a['y1_vn'];
    }
    if ($b['y2_vn'] !== $a['y2_vn']) {
        return $b['y2_vn'] <=> $a['y2_vn'];
    }
    return strcmp((string)$a['hcode'], (string)$b['hcode']);
});

$grand = [
    'y1_vn' => 0, 'y1_items' => 0,
    'y2_vn' => 0, 'y2_items' => 0,
    'y3_vn' => 0, 'y3_items' => 0,
];
foreach ($byCenter as $c) {
    $grand['y1_vn']    += $c['y1_vn'];
    $grand['y1_items'] += $c['y1_items'];
    $grand['y2_vn']    += $c['y2_vn'];
    $grand['y2_items'] += $c['y2_items'];
    $grand['y3_vn']    += $c['y3_vn'];
    $grand['y3_items'] += $c['y3_items'];
}

function rxcore_rx3_calc_growth(float $current, float $prev): ?float {
    if ($prev <= 0.0) {
        return $current > 0.0 ? 100.0 : null;
    }
    return round((($current - $prev) / $prev) * 100, 1);
}

$thaiMonths = [
    10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    1  => 'มกราคม', 2  => 'กุมภาพันธ์', 3  => 'มีนาคม',
    4  => 'เมษายน', 5  => 'พฤษภาคม', 6  => 'มิถุนายน',
    7  => 'กรกฎาคม', 8  => 'สิงหาคม', 9  => 'กันยายน',
];

$shortLabelY1 = substr((string)$y1_BE, -2);
$shortLabelY2 = substr((string)$y2_BE, -2);
$shortLabelY3 = substr((string)$y3_BE, -2);

if ($periodMode === 'month') {
    $mLabel = $thaiMonths[$selectedMonth] ?? '';
    $colHeaderY3 = "{$mLabel} ปีงบฯ {$shortLabelY3}";
    $colHeaderY2 = "{$mLabel} ปีงบฯ {$shortLabelY2}";
    $colHeaderY1 = "{$mLabel} ปีงบฯ {$shortLabelY1}";
} elseif ($periodMode === 'ytd') {
    $colHeaderY3 = "สะสม YTD ปีงบฯ {$y3_BE}";
    $colHeaderY2 = "สะสม YTD ปีงบฯ {$y2_BE}";
    $colHeaderY1 = "สะสม YTD ปีงบฯ {$y1_BE}";
} else {
    $colHeaderY3 = "ปีงบฯ {$y3_BE}";
    $colHeaderY2 = "ปีงบฯ {$y2_BE}";
    $colHeaderY1 = "ปีงบฯ {$y1_BE}";
}

// -----------------------------------------------------------------------------
// 4. ส่งออกไฟล์ CSV (UTF-8 BOM เปิดด้วย Excel ภาษาไทยได้ทันที)
// -----------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $modeTag = $periodMode === 'month' ? ('m' . str_pad((string)$selectedMonth, 2, '0', STR_PAD_LEFT)) : $periodMode;
    $filename = sprintf('rxcore_report3_prescriptions_%d_%d_%d_%s.csv', $y1_BE, $y2_BE, $y3_BE, $modeTag);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    fputcsv($out, [
        'ลำดับ',
        'ชื่อหน่วยบริการ',
        'ตำบล',
        "จำนวนใบสั่งยา ({$colHeaderY3})",
        "จำนวนใบสั่งยา ({$colHeaderY2})",
        "จำนวนใบสั่งยา ({$colHeaderY1})",
        "ผลต่างใบสั่งยา ({$shortLabelY1} เทียบ {$shortLabelY2})",
        '% เปลี่ยนแปลง',
    ]);

    $rowNo = 1;
    foreach ($byCenter as $c) {
        $diffVn = $c['y1_vn'] - $c['y2_vn'];
        $pctVn = rxcore_rx3_calc_growth((float)$c['y1_vn'], (float)$c['y2_vn']);
        fputcsv($out, [
            $rowNo++,
            $c['name'],
            $c['subdistrict'],
            $c['y3_vn'],
            $c['y2_vn'],
            $c['y1_vn'],
            $diffVn,
            $pctVn !== null ? $pctVn . '%' : '-',
        ]);
    }
    fclose($out);
    exit;
}

$diffVnGrand = $grand['y1_vn'] - $grand['y2_vn'];
$growthVnGrand = rxcore_rx3_calc_growth((float)$grand['y1_vn'], (float)$grand['y2_vn']);

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <?php rxcore_render_report_nav('prescriptions_3y'); ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">รายงานสรุปจำนวนใบสั่งยา แยกตามหน่วยบริการ ย้อนหลัง 3 ปี</h1>
                <p class="page-description">
                    นับจำนวนใบสั่งยาผู้ป่วยนอกแบบไม่ซ้ำใบ (<span class="font-mono">COUNT(DISTINCT vn)</span>) เปรียบเทียบปีงบฯ <?= $y1_BE ?>, <?= $y2_BE ?> และ <?= $y3_BE ?>
                </p>
            </div>
            <div class="d-flex gap-2 rx-no-print">
                <a href="<?= url('pages/report_prescriptions.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="btn btn-rx-secondary btn-sm">
                    <i class="bi bi-download me-1"></i> ส่งออก Excel / CSV
                </a>
                <button type="button" class="btn btn-rx-secondary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> พิมพ์รายงาน
                </button>
            </div>
        </div>

        <!-- Filter Panel -->
        <div class="rx-panel p-3 mb-4 rx-no-print">
            <form method="GET" action="<?= url('pages/report_prescriptions.php') ?>" class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label">ปีงบประมาณหลัก</label>
                    <select name="fiscal_year" class="form-select font-mono">
                        <?php for ($y = $currentFiscalYearBE; $y >= $currentFiscalYearBE - 4; $y--): ?>
                            <option value="<?= $y ?>" <?= ($targetBE === $y) ? 'selected' : '' ?>>
                                ปีงบฯ <?= $y ?><?= ($y === $currentFiscalYearBE) ? ' (ปัจจุบัน)' : '' ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label">รูปแบบช่วงเวลา</label>
                    <select name="period_mode" id="selPeriodMode" class="form-select" onchange="toggleMonthSelect(this.value)">
                        <option value="full" <?= ($periodMode === 'full') ? 'selected' : '' ?>>เต็มปีงบฯ (ต.ค. – ก.ย.)</option>
                        <option value="month" <?= ($periodMode === 'month') ? 'selected' : '' ?>>รายเดือน (เทียบเดือนเดียวกัน)</option>
                        <option value="ytd" <?= ($periodMode === 'ytd') ? 'selected' : '' ?>>สะสมถึงวันนี้ (YTD)</option>
                    </select>
                </div>

                <div class="col-6 col-md-2" id="boxMonthSelect" style="<?= ($periodMode === 'month') ? '' : 'display: none;' ?>">
                    <label class="form-label">เดือนที่เปรียบเทียบ</label>
                    <select name="month" class="form-select">
                        <?php foreach ($thaiMonths as $mNum => $mName): ?>
                            <option value="<?= $mNum ?>" <?= ($selectedMonth === $mNum) ? 'selected' : '' ?>>
                                เดือน<?= htmlspecialchars($mName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-3">
                    <label class="form-label">หน่วยบริการ (รพ.สต.)</label>
                    <?php if (!is_admin() && !empty($user['hcode'])): ?>
                        <input type="hidden" name="hcode" value="<?= htmlspecialchars($selectedHc) ?>">
                    <?php endif; ?>
                    <select name="hcode" class="form-select" <?= (!is_admin() && !empty($user['hcode'])) ? 'disabled' : '' ?>>
                        <option value="">ทุกหน่วยบริการ (ทั้งอำเภอ)</option>
                        <?php foreach ($healthCenters as $hc): ?>
                            <option value="<?= htmlspecialchars($hc['hcode']) ?>" <?= ($selectedHc === $hc['hcode']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($hc['hcode'] . ' - ' . $hc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-rx-primary flex-grow-1" title="ประมวลผลรายงาน">
                        <i class="bi bi-search"></i>
                    </button>
                    <a href="<?= url('pages/report_prescriptions.php') ?>" class="btn btn-rx-secondary" title="รีเซ็ตตัวกรอง">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- KPI Summary Cards -->
        <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label"><?= htmlspecialchars($colHeaderY3) ?> (ย้อนหลัง 2 ปี)</div>
                        <div class="stat-value font-mono"><?= number_format($grand['y3_vn']) ?> <span class="fs-6 fw-normal text-muted">ใบ</span></div>
                        <div class="small text-muted font-mono mt-1">รวม <?= number_format($grand['y3_items']) ?> รายการยา</div>
                    </div>
                    <div class="stat-icon primary"><i class="bi bi-file-earmark-medical"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label"><?= htmlspecialchars($colHeaderY2) ?> (ย้อนหลัง 1 ปี)</div>
                        <div class="stat-value font-mono"><?= number_format($grand['y2_vn']) ?> <span class="fs-6 fw-normal text-muted">ใบ</span></div>
                        <div class="small text-muted font-mono mt-1">รวม <?= number_format($grand['y2_items']) ?> รายการยา</div>
                    </div>
                    <div class="stat-icon warning"><i class="bi bi-file-earmark-check"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label"><?= htmlspecialchars($colHeaderY1) ?> (ปีหลัก)</div>
                        <div class="stat-value font-mono text-rx-accent"><?= number_format($grand['y1_vn']) ?> <span class="fs-6 fw-normal text-muted">ใบ</span></div>
                        <div class="small text-muted font-mono mt-1">รวม <?= number_format($grand['y1_items']) ?> รายการยา</div>
                    </div>
                    <div class="stat-icon success"><i class="bi bi-clipboard2-pulse"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">ผลต่างใบสั่งยา (ปีงบฯ <?= $shortLabelY1 ?> เทียบ <?= $shortLabelY2 ?>)</div>
                        <div class="stat-value font-mono <?= ($diffVnGrand > 0) ? 'text-rx-warning' : (($diffVnGrand < 0) ? 'text-rx-success' : '') ?>">
                            <?= ($diffVnGrand > 0 ? '+' : '') . number_format($diffVnGrand) ?> <span class="fs-6 fw-normal text-muted">ใบ</span>
                        </div>
                        <div class="small text-muted font-mono mt-1">
                            <?= ($growthVnGrand !== null) ? (($growthVnGrand >= 0 ? 'เพิ่มขึ้น +' : 'ลดลง ') . $growthVnGrand . '%') : 'ยังไม่มีฐานเปรียบเทียบ' ?>
                        </div>
                    </div>
                    <div class="stat-icon <?= ($diffVnGrand > 0) ? 'warning' : 'success' ?>">
                        <i class="bi <?= ($diffVnGrand >= 0) ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow' ?>"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- ช่วงวันที่ที่นำมาเปรียบเทียบจริง -->
        <div class="rx-panel p-2 px-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2 small text-secondary">
            <div>
                <i class="bi bi-clock-history me-1 text-rx-accent"></i>
                <strong>ช่วงวันที่เปรียบเทียบจริง:</strong>
                <span class="ms-2">ปีงบฯ <?= $y1_BE ?>: <span class="font-mono"><?= rxcore_rx3_thai_short_date($y1_start) ?> – <?= rxcore_rx3_thai_short_date($y1_end) ?></span></span>
                <span class="mx-2 text-muted">|</span>
                <span>ปีงบฯ <?= $y2_BE ?>: <span class="font-mono"><?= rxcore_rx3_thai_short_date($y2_start) ?> – <?= rxcore_rx3_thai_short_date($y2_end) ?></span></span>
                <span class="mx-2 text-muted">|</span>
                <span>ปีงบฯ <?= $y3_BE ?>: <span class="font-mono"><?= rxcore_rx3_thai_short_date($y3_start) ?> – <?= rxcore_rx3_thai_short_date($y3_end) ?></span></span>
            </div>
            <div>
                เกณฑ์การนับ: <strong class="font-mono">COUNT(DISTINCT vn)</strong> ต่อหน่วยบริการ
            </div>
        </div>

        <!-- ตารางสรุปจำนวนใบสั่งยาแยกตามหน่วยบริการ -->
        <div class="rx-panel overflow-hidden">
            <div class="rx-panel-header">
                <span><i class="bi bi-hospital me-2 text-rx-accent"></i>ตารางสรุปจำนวนใบสั่งยาผู้ป่วยนอก แยกตามหน่วยบริการ</span>
                <span class="small text-muted">เรียงตามจำนวนใบสั่งยาปีงบฯ <?= $y1_BE ?> มากไปน้อย</span>
            </div>
            <div class="table-responsive">
                <table class="table table-rx">
                    <thead>
                        <tr>
                            <th rowspan="2" class="text-center" style="width: 60px; vertical-align: middle;">ลำดับ</th>
                            <th rowspan="2" style="min-width: 240px; vertical-align: middle;">หน่วยบริการ (รพ.สต.)</th>
                            <th rowspan="2" style="vertical-align: middle;">ตำบล</th>
                            <th colspan="2" class="text-center rx-col-group rx-col-divider"><?= htmlspecialchars($colHeaderY3) ?></th>
                            <th colspan="2" class="text-center rx-col-group rx-col-divider"><?= htmlspecialchars($colHeaderY2) ?></th>
                            <th colspan="2" class="text-center rx-col-group rx-col-divider text-rx-accent"><?= htmlspecialchars($colHeaderY1) ?></th>
                            <th colspan="2" class="text-center rx-col-group rx-col-divider">ผลต่างใบสั่งยา (<?= $shortLabelY1 ?> เทียบ <?= $shortLabelY2 ?>)</th>
                        </tr>
                        <tr>
                            <th class="text-end rx-col-divider">ใบสั่งยา (ใบ)</th>
                            <th class="text-end">เฉลี่ยรายการ/ใบ</th>
                            <th class="text-end rx-col-divider">ใบสั่งยา (ใบ)</th>
                            <th class="text-end">เฉลี่ยรายการ/ใบ</th>
                            <th class="text-end rx-col-divider text-rx-accent">ใบสั่งยา (ใบ)</th>
                            <th class="text-end text-rx-accent">เฉลี่ยรายการ/ใบ</th>
                            <th class="text-end rx-col-divider">จำนวนใบ (+/-)</th>
                            <th class="text-end">% เปลี่ยนแปลง</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byCenter)): ?>
                            <tr>
                                <td colspan="11">
                                    <div class="rx-empty-state">
                                        <i class="bi bi-file-earmark-medical"></i>
                                        <div class="fw-medium">ไม่พบข้อมูลหน่วยบริการ</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $rowNo = 1;
                            foreach ($byCenter as $h => $c):
                                $diffVn = $c['y1_vn'] - $c['y2_vn'];
                                $pctVn = rxcore_rx3_calc_growth((float)$c['y1_vn'], (float)$c['y2_vn']);
                                $avgY3 = $c['y3_vn'] > 0 ? ($c['y3_items'] / $c['y3_vn']) : 0.0;
                                $avgY2 = $c['y2_vn'] > 0 ? ($c['y2_items'] / $c['y2_vn']) : 0.0;
                                $avgY1 = $c['y1_vn'] > 0 ? ($c['y1_items'] / $c['y1_vn']) : 0.0;
                            ?>
                                <tr>
                                    <td class="text-center font-mono text-muted"><?= $rowNo++ ?></td>
                                    <td class="fw-medium"><?= htmlspecialchars($c['name']) ?></td>
                                    <td class="text-secondary small"><?= htmlspecialchars($c['subdistrict'] ?: '-') ?></td>

                                    <!-- ปีงบฯ -2 -->
                                    <td class="text-end font-mono rx-col-divider"><?= number_format($c['y3_vn']) ?></td>
                                    <td class="text-end font-mono text-secondary small"><?= $c['y3_vn'] > 0 ? number_format($avgY3, 1) : '-' ?></td>

                                    <!-- ปีงบฯ -1 -->
                                    <td class="text-end font-mono rx-col-divider"><?= number_format($c['y2_vn']) ?></td>
                                    <td class="text-end font-mono text-secondary small"><?= $c['y2_vn'] > 0 ? number_format($avgY2, 1) : '-' ?></td>

                                    <!-- ปีงบฯ ปัจจุบัน -->
                                    <td class="text-end font-mono fw-semibold text-rx-accent rx-col-divider"><?= number_format($c['y1_vn']) ?></td>
                                    <td class="text-end font-mono text-secondary small"><?= $c['y1_vn'] > 0 ? number_format($avgY1, 1) : '-' ?></td>

                                    <!-- ผลต่าง -->
                                    <td class="text-end font-mono rx-col-divider <?= ($diffVn > 0) ? 'text-rx-warning' : (($diffVn < 0) ? 'text-rx-success' : 'text-muted') ?>">
                                        <?= ($diffVn > 0 ? '+' : '') . number_format($diffVn) ?>
                                    </td>
                                    <td class="text-end font-mono <?= ($diffVn > 0) ? 'text-rx-warning' : (($diffVn < 0) ? 'text-rx-success' : 'text-muted') ?>">
                                        <?= $pctVn !== null ? (($pctVn >= 0 ? '+' : '') . $pctVn . '%') : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($byCenter)):
                        $grandAvgY3 = $grand['y3_vn'] > 0 ? ($grand['y3_items'] / $grand['y3_vn']) : 0.0;
                        $grandAvgY2 = $grand['y2_vn'] > 0 ? ($grand['y2_items'] / $grand['y2_vn']) : 0.0;
                        $grandAvgY1 = $grand['y1_vn'] > 0 ? ($grand['y1_items'] / $grand['y1_vn']) : 0.0;
                    ?>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="3" class="text-end">รวมทุกหน่วยบริการ (<?= number_format(count($byCenter)) ?> แห่ง)</td>
                                <td class="text-end font-mono rx-col-divider"><?= number_format($grand['y3_vn']) ?></td>
                                <td class="text-end font-mono text-secondary small"><?= $grand['y3_vn'] > 0 ? number_format($grandAvgY3, 1) : '-' ?></td>
                                <td class="text-end font-mono rx-col-divider"><?= number_format($grand['y2_vn']) ?></td>
                                <td class="text-end font-mono text-secondary small"><?= $grand['y2_vn'] > 0 ? number_format($grandAvgY2, 1) : '-' ?></td>
                                <td class="text-end font-mono text-rx-accent rx-col-divider"><?= number_format($grand['y1_vn']) ?></td>
                                <td class="text-end font-mono text-secondary small"><?= $grand['y1_vn'] > 0 ? number_format($grandAvgY1, 1) : '-' ?></td>
                                <td class="text-end font-mono rx-col-divider"><?= ($diffVnGrand > 0 ? '+' : '') . number_format($diffVnGrand) ?></td>
                                <td class="text-end font-mono"><?= $growthVnGrand !== null ? (($growthVnGrand >= 0 ? '+' : '') . $growthVnGrand . '%') : '-' ?></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </main>

    <script>
        function toggleMonthSelect(val) {
            var box = document.getElementById('boxMonthSelect');
            if (box) {
                box.style.display = (val === 'month') ? '' : 'none';
            }
        }
    </script>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

