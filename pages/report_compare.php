<?php
/**
 * RxCore — รายงานที่ 1: รายงานปริมาณการใช้ยา และมูลค่าการใช้ยา เทียบข้อมูล 3 ปีงบประมาณ
 * เทียบข้อมูลปีงบประมาณปัจจุบัน (Y1) เทียบย้อนหลัง 1 ปี (Y2) และย้อนหลัง 2 ปี (Y3) เช่น ปีงบฯ 2570, 2569, 2568
 * รองรับการกรอง:
 *  - ทั้งปีงบประมาณ (1 ต.ค. - 30 ก.ย.)
 *  - รายเดือน (เทียบเดือนเดียวกันทั้ง 3 ปีงบฯ เช่น ต.ค. 70 vs ต.ค. 69 vs ต.ค. 68)
 *  - สะสมถึงปัจจุบัน (YTD — เทียบตั้งแต่วันที่ 1 ต.ค. ถึงวัน/เดือนเดียวกันทั้ง 3 ปีงบฯ)
 * มุมมองข้อมูล:
 *  - รวม (แยกตามรายการยา)
 *  - แยกตาม รพ.สต. (หน่วยบริการ)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/supabase.php';
require_once dirname(__DIR__) . '/includes/reports_registry.php';

$current_page = 'reports';
$page_title = 'รายงานปริมาณและมูลค่าการใช้ยา เทียบข้อมูล 3 ปีงบประมาณ';

// -----------------------------------------------------------------------------
// 1. คำนวณปีงบประมาณไทยและตัวเลือกตัวกรอง
// -----------------------------------------------------------------------------
$today = new DateTime();
$currentMonth = (int)$today->format('n');
$currentYearAD = (int)$today->format('Y');

// ปีงบประมาณไทย: ต.ค. - ธ.ค. นับเป็นปีงบประมาณถัดไป (ค.ศ. + 544)
$currentFiscalYearBE = ($currentMonth >= 10) ? ($currentYearAD + 544) : ($currentYearAD + 543);

$targetBE = isset($_GET['fiscal_year']) ? (int)$_GET['fiscal_year'] : $currentFiscalYearBE;
if ($targetBE < 2555 || $targetBE > 2600) {
    $targetBE = $currentFiscalYearBE;
}

$y1_BE = $targetBE;       // ปีงบฯ หลัก (เช่น 2570)
$y2_BE = $targetBE - 1;   // ลบไป 1 ปี (เช่น 2569)
$y3_BE = $targetBE - 2;   // ลบไป 2 ปี (เช่น 2568)

$periodMode = $_GET['period_mode'] ?? 'full';
if (!in_array($periodMode, ['full', 'month', 'ytd'], true)) {
    $periodMode = 'full';
}

$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonth;
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}

$selectedHc = trim($_GET['hcode'] ?? '');
$selectedGroup = trim($_GET['drug_type'] ?? '');
$activeTab = ($_GET['tab'] ?? 'drugs') === 'centers' ? 'centers' : 'drugs';

$user = current_user();
if (!is_admin() && !empty($user['hcode'])) {
    $selectedHc = $user['hcode'];
}

// -----------------------------------------------------------------------------
// 2. ฟังก์ชันคำนวณช่วงวันที่ตามปีงบประมาณไทย (พ.ศ. -> ค.ศ.)
// -----------------------------------------------------------------------------
function rxcore_fiscal_date_range(int $fiscalYearBE, string $mode, int $monthNum): array {
    $adEnd = $fiscalYearBE - 543;
    $adStart = $adEnd - 1;

    if ($mode === 'month') {
        // เจาะจงรายเดือนเดียวกันในแต่ละปีงบประมาณ
        $yearOfThatMonth = ($monthNum >= 10) ? $adStart : $adEnd;
        $dStart = sprintf('%04d-%02d-01', $yearOfThatMonth, $monthNum);
        $lastDay = (int)date('t', strtotime($dStart));
        $dEnd = sprintf('%04d-%02d-%02d', $yearOfThatMonth, $monthNum, $lastDay);
        return [$dStart, $dEnd];
    }

    if ($mode === 'ytd') {
        // สะสมตั้งแต่วันที่ 1 ต.ค. จนถึงวันและเดือนปัจจุบัน (เทียบช่วงวันเดียวกันทั้ง 3 ปีงบฯ)
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

    // ค่าเริ่มต้น: เต็มปีงบประมาณ (1 ต.ค. - 30 ก.ย.)
    return [
        sprintf('%04d-10-01', $adStart),
        sprintf('%04d-09-30', $adEnd),
    ];
}

function rxcore_format_thai_short_date(string $isoDate): string {
    $shortMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];
    $ts = strtotime($isoDate);
    if ($ts === false) {
        return $isoDate;
    }
    $d = (int)date('j', $ts);
    $m = (int)date('n', $ts);
    $yBE = ((int)date('Y', $ts) + 543) % 100;
    return sprintf('%d %s %02d', $d, $shortMonths[$m] ?? '', $yBE);
}

[$y1_start, $y1_end] = rxcore_fiscal_date_range($y1_BE, $periodMode, $selectedMonth);
[$y2_start, $y2_end] = rxcore_fiscal_date_range($y2_BE, $periodMode, $selectedMonth);
[$y3_start, $y3_end] = rxcore_fiscal_date_range($y3_BE, $periodMode, $selectedMonth);

// -----------------------------------------------------------------------------
// 3. ดึง Master Data (หน่วยบริการ & ทะเบียนยา)
// -----------------------------------------------------------------------------
$healthCenters = SupabaseService::select('rx_health_centers', 'select=hcode,name,subdistrict&is_active=eq.true&order=hcode.asc');
$hcMap = [];
foreach ($healthCenters as $hc) {
    $hcMap[$hc['hcode']] = $hc['name'];
}

$drugMaster = SupabaseService::select('rx_drug_lookup', 'select=icode,drug_name,tpu_code_list,drug_type_name,unit,unitcost&is_active=eq.true&order=icode.asc');
$drugMap = [];
$drugGroups = [];
foreach ($drugMaster as $d) {
    $tpu = (string)$d['tpu_code_list'];
    $drugMap[$tpu] = $d;
    $g = trim((string)($d['drug_type_name'] ?? ''));
    if ($g !== '' && !in_array($g, $drugGroups, true)) {
        $drugGroups[] = $g;
    }
}
natcasesort($drugGroups);

// -----------------------------------------------------------------------------
// 4. ดึงข้อมูลการใช้ยาจริงจาก v_rx_drug_dispense_summary แบบแบ่งหน้าครบทุกแถว
// -----------------------------------------------------------------------------
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
    'select=*',
    $orClause,
    'order=vstdate.asc',
];
if ($selectedHc !== '') {
    $queryParts[] = 'hcode=eq.' . urlencode($selectedHc);
}
if ($selectedGroup !== '') {
    $queryParts[] = 'drug_type_name=eq.' . urlencode($selectedGroup);
}

$rawDispense = rxcore_fetch_all_rows('v_rx_drug_dispense_summary', implode('&', $queryParts));

$byDrug = [];
$byCenter = [];
foreach ($healthCenters as $hc) {
    $h = (string)$hc['hcode'];
    if ($selectedHc !== '' && $selectedHc !== $h) {
        continue;
    }
    $byCenter[$h] = [
        'hcode'       => $h,
        'name'        => $hc['name'],
        'subdistrict' => $hc['subdistrict'] ?? '',
        'y1_qty'      => 0.0, 'y1_cost' => 0.0, 'y1_tx' => 0,
        'y2_qty'      => 0.0, 'y2_cost' => 0.0, 'y2_tx' => 0,
        'y3_qty'      => 0.0, 'y3_cost' => 0.0, 'y3_tx' => 0,
    ];
}

$grand = [
    'y1_qty' => 0.0, 'y1_cost' => 0.0, 'y1_tx' => 0,
    'y2_qty' => 0.0, 'y2_cost' => 0.0, 'y2_tx' => 0,
    'y3_qty' => 0.0, 'y3_cost' => 0.0, 'y3_tx' => 0,
];

foreach ($rawDispense as $row) {
    $tpu = (string)($row['tpu_code_list'] ?? '');
    $h = (string)($row['hcode'] ?? '');
    $vstdate = (string)($row['vstdate'] ?? '');
    $qty = (float)($row['total_qty'] ?? 0);
    $cost = (float)($row['total_cost_amount'] ?? 0);
    $tx = (int)($row['dispense_tx_count'] ?? 0);

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

    $grand["y{$slot}_qty"]  += $qty;
    $grand["y{$slot}_cost"] += $cost;
    $grand["y{$slot}_tx"]   += $tx;

    if (!isset($byCenter[$h])) {
        $byCenter[$h] = [
            'hcode'       => $h,
            'name'        => $hcMap[$h] ?? $h,
            'subdistrict' => '',
            'y1_qty'      => 0.0, 'y1_cost' => 0.0, 'y1_tx' => 0,
            'y2_qty'      => 0.0, 'y2_cost' => 0.0, 'y2_tx' => 0,
            'y3_qty'      => 0.0, 'y3_cost' => 0.0, 'y3_tx' => 0,
        ];
    }
    $byCenter[$h]["y{$slot}_qty"]  += $qty;
    $byCenter[$h]["y{$slot}_cost"] += $cost;
    $byCenter[$h]["y{$slot}_tx"]   += $tx;

    if (!isset($byDrug[$tpu])) {
        $meta = $drugMap[$tpu] ?? [];
        $byDrug[$tpu] = [
            'tpu_code_list'  => $tpu,
            'icode'          => $row['icode'] ?? ($meta['icode'] ?? ''),
            'drug_name'      => $row['drug_name'] ?? ($meta['drug_name'] ?? 'ไม่ระบุชื่อยา'),
            'drug_type_name' => $row['drug_type_name'] ?? ($meta['drug_type_name'] ?? ''),
            'unit'           => $row['unit'] ?? ($meta['unit'] ?? ''),
            'y1_qty'         => 0.0, 'y1_cost' => 0.0, 'y1_tx' => 0,
            'y2_qty'         => 0.0, 'y2_cost' => 0.0, 'y2_tx' => 0,
            'y3_qty'         => 0.0, 'y3_cost' => 0.0, 'y3_tx' => 0,
        ];
    }
    $byDrug[$tpu]["y{$slot}_qty"]  += $qty;
    $byDrug[$tpu]["y{$slot}_cost"] += $cost;
    $byDrug[$tpu]["y{$slot}_tx"]   += $tx;
}

uasort($byDrug, function ($a, $b) {
    if ($b['y1_cost'] !== $a['y1_cost']) {
        return $b['y1_cost'] <=> $a['y1_cost'];
    }
    if ($b['y2_cost'] !== $a['y2_cost']) {
        return $b['y2_cost'] <=> $a['y2_cost'];
    }
    return $b['y3_cost'] <=> $a['y3_cost'];
});

uasort($byCenter, function ($a, $b) {
    if ($b['y1_cost'] !== $a['y1_cost']) {
        return $b['y1_cost'] <=> $a['y1_cost'];
    }
    return strcmp((string)$a['hcode'], (string)$b['hcode']);
});

function rxcore_calc_growth(float $current, float $prev): ?float {
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
// 5. ส่งออกไฟล์ CSV (UTF-8 BOM เปิดใน Excel ภาษาไทยได้ทันที)
// -----------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $modeTag = $periodMode === 'month' ? ('m' . str_pad((string)$selectedMonth, 2, '0', STR_PAD_LEFT)) : $periodMode;
    $filename = sprintf('rxcore_report1_compare_%d_%d_%d_%s_%s.csv', $y1_BE, $y2_BE, $y3_BE, $modeTag, $activeTab);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    if ($activeTab === 'centers') {
        fputcsv($out, [
            'ลำดับ', 'HCODE', 'ชื่อหน่วยบริการ', 'ตำบล',
            "ปริมาณ ({$colHeaderY3})", "มูลค่าบาท ({$colHeaderY3})",
            "ปริมาณ ({$colHeaderY2})", "มูลค่าบาท ({$colHeaderY2})",
            "ปริมาณ ({$colHeaderY1})", "มูลค่าบาท ({$colHeaderY1})",
            "ผลต่างมูลค่า ({$shortLabelY1} เทียบ {$shortLabelY2})", "% เปลี่ยนแปลงมูลค่า",
        ]);
        $rowNo = 1;
        foreach ($byCenter as $c) {
            $diffCost = $c['y1_cost'] - $c['y2_cost'];
            $pctCost = rxcore_calc_growth($c['y1_cost'], $c['y2_cost']);
            fputcsv($out, [
                $rowNo++, $c['hcode'], $c['name'], $c['subdistrict'],
                number_format($c['y3_qty'], 0, '.', ''), number_format($c['y3_cost'], 2, '.', ''),
                number_format($c['y2_qty'], 0, '.', ''), number_format($c['y2_cost'], 2, '.', ''),
                number_format($c['y1_qty'], 0, '.', ''), number_format($c['y1_cost'], 2, '.', ''),
                number_format($diffCost, 2, '.', ''),
                $pctCost !== null ? $pctCost . '%' : '-',
            ]);
        }
    } else {
        fputcsv($out, [
            'ลำดับ', 'ชื่อรายการยา', 'กลุ่มยา', 'หน่วยนับ',
            "ปริมาณ ({$colHeaderY3})", "มูลค่าบาท ({$colHeaderY3})",
            "ปริมาณ ({$colHeaderY2})", "มูลค่าบาท ({$colHeaderY2})",
            "ปริมาณ ({$colHeaderY1})", "มูลค่าบาท ({$colHeaderY1})",
            "ผลต่างปริมาณ ({$shortLabelY1} เทียบ {$shortLabelY2})", "% เปลี่ยนแปลงปริมาณ",
            "ผลต่างมูลค่า ({$shortLabelY1} เทียบ {$shortLabelY2})", "% เปลี่ยนแปลงมูลค่า",
        ]);
        $rowNo = 1;
        foreach ($byDrug as $d) {
            $diffQty = $d['y1_qty'] - $d['y2_qty'];
            $diffCost = $d['y1_cost'] - $d['y2_cost'];
            $pctQty = rxcore_calc_growth($d['y1_qty'], $d['y2_qty']);
            $pctCost = rxcore_calc_growth($d['y1_cost'], $d['y2_cost']);
            fputcsv($out, [
                $rowNo++, $d['drug_name'], $d['drug_type_name'], $d['unit'],
                number_format($d['y3_qty'], 0, '.', ''), number_format($d['y3_cost'], 2, '.', ''),
                number_format($d['y2_qty'], 0, '.', ''), number_format($d['y2_cost'], 2, '.', ''),
                number_format($d['y1_qty'], 0, '.', ''), number_format($d['y1_cost'], 2, '.', ''),
                number_format($diffQty, 0, '.', ''), $pctQty !== null ? $pctQty . '%' : '-',
                number_format($diffCost, 2, '.', ''), $pctCost !== null ? $pctCost . '%' : '-',
            ]);
        }
    }
    fclose($out);
    exit;
}

$diffCostGrand = $grand['y1_cost'] - $grand['y2_cost'];
$growthCostGrand = rxcore_calc_growth($grand['y1_cost'], $grand['y2_cost']);

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <?php rxcore_render_report_nav('compare_3y'); ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">รายงานปริมาณการใช้ยา และมูลค่าการใช้ยา เทียบข้อมูล 3 ปีงบประมาณ</h1>
                <p class="page-description">
                    เปรียบเทียบข้อมูลผู้ป่วยนอก (OPD) ของปีงบฯ <?= $y1_BE ?> เทียบกับปีงบฯ <?= $y2_BE ?> และ <?= $y3_BE ?>
                    <?= $periodMode === 'month' ? ' (เฉพาะเดือน' . htmlspecialchars($thaiMonths[$selectedMonth] ?? '') . ')' : ($periodMode === 'ytd' ? ' (สะสมต้นปีงบฯ ถึงปัจจุบัน)' : ' (เต็มปีงบประมาณ 12 เดือน)') ?>
                </p>
            </div>
            <div class="d-flex gap-2 rx-no-print">
                <a href="<?= url('pages/report_compare.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="btn btn-rx-secondary btn-sm">
                    <i class="bi bi-download me-1"></i> ส่งออก Excel / CSV
                </a>
                <button type="button" class="btn btn-rx-secondary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> พิมพ์รายงาน
                </button>
            </div>
        </div>

        <!-- Filter Panel -->
        <div class="rx-panel p-3 mb-4 rx-no-print">
            <form method="GET" action="<?= url('pages/report_compare.php') ?>" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">

                <div class="col-6 col-md-2">
                    <label class="form-label">ปีงบประมาณหลัก</label>
                    <select name="fiscal_year" class="form-select font-mono">
                        <?php for ($y = $currentFiscalYearBE; $y >= $currentFiscalYearBE - 4; $y--): ?>
                            <option value="<?= $y ?>" <?= ($targetBE === $y) ? 'selected' : '' ?>>
                                ปีงบฯ <?= $y ?><?= ($y === $currentFiscalYearBE) ? ' (ปัจจุบัน)' : '' ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-6 col-md-2">
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
                        <option value="">รวมทุกหน่วยบริการ (ทั้งอำเภอ)</option>
                        <?php foreach ($healthCenters as $hc): ?>
                            <option value="<?= htmlspecialchars($hc['hcode']) ?>" <?= ($selectedHc === $hc['hcode']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($hc['hcode'] . ' - ' . $hc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label class="form-label">กลุ่มยา</label>
                    <select name="drug_type" class="form-select">
                        <option value="">ทุกกลุ่มยา</option>
                        <?php foreach ($drugGroups as $dg): ?>
                            <option value="<?= htmlspecialchars($dg) ?>" <?= ($selectedGroup === $dg) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dg) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-rx-primary flex-grow-1" title="ประมวลผลรายงาน">
                        <i class="bi bi-search"></i>
                    </button>
                    <a href="<?= url('pages/report_compare.php?tab=' . urlencode($activeTab)) ?>" class="btn btn-rx-secondary" title="รีเซ็ตตัวกรอง">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- KPI Summary Cards (3 Fiscal Years + Variance) -->
        <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label"><?= htmlspecialchars($colHeaderY3) ?> (ย้อนหลัง 2 ปี)</div>
                        <div class="stat-value font-mono"><?= number_format($grand['y3_cost'], 2) ?> <span class="fs-6 fw-normal text-muted">฿</span></div>
                        <div class="small text-muted font-mono mt-1">ปริมาณ <?= number_format($grand['y3_qty']) ?> หน่วย (<?= number_format($grand['y3_tx']) ?> ครั้ง)</div>
                    </div>
                    <div class="stat-icon primary"><i class="bi bi-calendar3"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label"><?= htmlspecialchars($colHeaderY2) ?> (ย้อนหลัง 1 ปี)</div>
                        <div class="stat-value font-mono"><?= number_format($grand['y2_cost'], 2) ?> <span class="fs-6 fw-normal text-muted">฿</span></div>
                        <div class="small text-muted font-mono mt-1">ปริมาณ <?= number_format($grand['y2_qty']) ?> หน่วย (<?= number_format($grand['y2_tx']) ?> ครั้ง)</div>
                    </div>
                    <div class="stat-icon warning"><i class="bi bi-calendar2-check"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label"><?= htmlspecialchars($colHeaderY1) ?> (ปีหลัก)</div>
                        <div class="stat-value font-mono text-rx-accent"><?= number_format($grand['y1_cost'], 2) ?> <span class="fs-6 fw-normal text-muted">฿</span></div>
                        <div class="small text-muted font-mono mt-1">ปริมาณ <?= number_format($grand['y1_qty']) ?> หน่วย (<?= number_format($grand['y1_tx']) ?> ครั้ง)</div>
                    </div>
                    <div class="stat-icon success"><i class="bi bi-cash-coin"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">เปลี่ยนแปลงมูลค่า (ปีงบฯ <?= $shortLabelY1 ?> เทียบ <?= $shortLabelY2 ?>)</div>
                        <div class="stat-value font-mono <?= ($diffCostGrand > 0) ? 'text-rx-warning' : (($diffCostGrand < 0) ? 'text-rx-success' : '') ?>">
                            <?= ($diffCostGrand > 0 ? '+' : '') . number_format($diffCostGrand, 2) ?> <span class="fs-6 fw-normal text-muted">฿</span>
                        </div>
                        <div class="small text-muted font-mono mt-1">
                            <?= ($growthCostGrand !== null) ? (($growthCostGrand >= 0 ? 'เพิ่มขึ้น +' : 'ลดลง ') . $growthCostGrand . '%') : 'ยังไม่มีฐานเปรียบเทียบ' ?>
                        </div>
                    </div>
                    <div class="stat-icon <?= ($diffCostGrand > 0) ? 'warning' : 'success' ?>">
                        <i class="bi <?= ($diffCostGrand >= 0) ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow' ?>"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- แถบแสดงช่วงวันที่ที่นำมาเปรียบเทียบจริง -->
        <div class="rx-panel p-2 px-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2 small text-secondary">
            <div>
                <i class="bi bi-clock-history me-1 text-rx-accent"></i>
                <strong>ช่วงวันที่เปรียบเทียบจริง:</strong>
                <span class="ms-2">ปีงบฯ <?= $y1_BE ?>: <span class="font-mono"><?= rxcore_format_thai_short_date($y1_start) ?> – <?= rxcore_format_thai_short_date($y1_end) ?></span></span>
                <span class="mx-2 text-muted">|</span>
                <span>ปีงบฯ <?= $y2_BE ?>: <span class="font-mono"><?= rxcore_format_thai_short_date($y2_start) ?> – <?= rxcore_format_thai_short_date($y2_end) ?></span></span>
                <span class="mx-2 text-muted">|</span>
                <span>ปีงบฯ <?= $y3_BE ?>: <span class="font-mono"><?= rxcore_format_thai_short_date($y3_start) ?> – <?= rxcore_format_thai_short_date($y3_end) ?></span></span>
            </div>
            <div>
                ขอบเขต: <strong><?= $selectedHc !== '' ? htmlspecialchars(($hcMap[$selectedHc] ?? $selectedHc) . " ({$selectedHc})") : 'รวมทุก รพ.สต. ในเครือข่าย' ?></strong>
            </div>
        </div>

        <!-- สลับมุมมอง: รวมตามรายการยา vs แยกตาม รพ.สต. -->
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 rx-no-print">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= url('pages/report_compare.php?' . http_build_query(array_merge($_GET, ['tab' => 'drugs']))) ?>"
                   class="btn btn-sm <?= ($activeTab === 'drugs') ? 'btn-rx-primary' : 'btn-rx-secondary' ?>">
                    <i class="bi bi-capsule me-1"></i> รวม — แยกตามรายการยา (<?= number_format(count($byDrug)) ?> รายการ)
                </a>
                <a href="<?= url('pages/report_compare.php?' . http_build_query(array_merge($_GET, ['tab' => 'centers']))) ?>"
                   class="btn btn-sm <?= ($activeTab === 'centers') ? 'btn-rx-primary' : 'btn-rx-secondary' ?>">
                    <i class="bi bi-hospital me-1"></i> แยกตาม รพ.สต. (<?= number_format(count($byCenter)) ?> แห่ง)
                </a>
            </div>
            <?php if ($selectedHc !== '' && (is_admin() || empty($user['hcode']))): ?>
                <a href="<?= url('pages/report_compare.php?' . http_build_query(array_merge($_GET, ['hcode' => '']))) ?>" class="btn btn-rx-secondary btn-sm">
                    <i class="bi bi-x-circle me-1"></i> ล้างตัวกรองเฉพาะหน่วยบริการ (กลับมาดูรวมทุกแห่ง)
                </a>
            <?php endif; ?>
        </div>

        <?php if ($activeTab === 'drugs'): ?>
            <!-- ===============================================================
                 มุมมองที่ 1: สรุปเปรียบเทียบตามรายการยา (รวม หรือ เฉพาะ รพ.สต. ที่เลือก)
                 =============================================================== -->
            <div class="rx-panel overflow-hidden">
                <div class="rx-panel-header">
                    <span>
                        <i class="bi bi-table me-2 text-rx-accent"></i>ตารางเปรียบเทียบปริมาณและมูลค่าการใช้ยารายตัว
                        <?= $selectedHc !== '' ? ' — เฉพาะ ' . htmlspecialchars($hcMap[$selectedHc] ?? $selectedHc) : ' — ภาพรวมเครือข่าย' ?>
                    </span>
                    <span class="small text-muted">เรียงตามมูลค่าปีงบฯ <?= $y1_BE ?> มากไปน้อย</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-rx">
                        <thead>
                            <tr>
                                <th rowspan="2" class="text-center" style="width: 56px; vertical-align: middle;">ลำดับ</th>
                                <th rowspan="2" style="min-width: 220px; vertical-align: middle;">ชื่อรายการยา</th>
                                <th rowspan="2" style="vertical-align: middle;">กลุ่มยา</th>
                                <th rowspan="2" class="text-center" style="vertical-align: middle;">หน่วย</th>
                                <th colspan="2" class="text-center rx-col-group rx-col-divider"><?= htmlspecialchars($colHeaderY3) ?></th>
                                <th colspan="2" class="text-center rx-col-group rx-col-divider"><?= htmlspecialchars($colHeaderY2) ?></th>
                                <th colspan="2" class="text-center rx-col-group rx-col-divider text-rx-accent"><?= htmlspecialchars($colHeaderY1) ?></th>
                                <th colspan="2" class="text-center rx-col-group rx-col-divider">ผลต่าง (<?= $shortLabelY1 ?> เทียบ <?= $shortLabelY2 ?>)</th>
                            </tr>
                            <tr>
                                <th class="text-end rx-col-divider">ปริมาณ</th>
                                <th class="text-end">มูลค่า (บาท)</th>
                                <th class="text-end rx-col-divider">ปริมาณ</th>
                                <th class="text-end">มูลค่า (บาท)</th>
                                <th class="text-end rx-col-divider text-rx-accent">ปริมาณ</th>
                                <th class="text-end text-rx-accent">มูลค่า (บาท)</th>
                                <th class="text-end rx-col-divider">ปริมาณ (+/-)</th>
                                <th class="text-end">มูลค่า (+/-)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($byDrug)): ?>
                                <tr>
                                    <td colspan="12">
                                        <div class="rx-empty-state">
                                            <i class="bi bi-bar-chart-steps"></i>
                                            <div class="fw-medium mb-1">ยังไม่มีข้อมูลการจ่ายยาในช่วงปีงบประมาณหรือเดือนที่เลือก</div>
                                            <div class="small">เมื่อหน่วยบริการ Sync ข้อมูลการใช้ยาเข้าสู่ระบบแล้ว ตารางเปรียบเทียบ 3 ปีงบประมาณจะแสดงผลอัตโนมัติ</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $rowNo = 1;
                                foreach ($byDrug as $tpu => $d):
                                    $diffQty = $d['y1_qty'] - $d['y2_qty'];
                                    $pctQty = rxcore_calc_growth($d['y1_qty'], $d['y2_qty']);
                                    $diffCost = $d['y1_cost'] - $d['y2_cost'];
                                    $pctCost = rxcore_calc_growth($d['y1_cost'], $d['y2_cost']);
                                ?>
                                    <tr>
                                        <td class="text-center font-mono text-muted"><?= $rowNo++ ?></td>
                                        <td class="fw-medium"><?= htmlspecialchars($d['drug_name']) ?></td>
                                        <td>
                                            <?php if ($d['drug_type_name'] !== ''): ?>
                                                <span class="rx-tag"><?= htmlspecialchars($d['drug_type_name']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center text-secondary small"><?= htmlspecialchars($d['unit'] ?: '-') ?></td>

                                        <!-- ปีงบฯ -2 -->
                                        <td class="text-end font-mono text-secondary rx-col-divider"><?= number_format($d['y3_qty'], 0) ?></td>
                                        <td class="text-end font-mono text-secondary"><?= number_format($d['y3_cost'], 2) ?></td>

                                        <!-- ปีงบฯ -1 -->
                                        <td class="text-end font-mono rx-col-divider"><?= number_format($d['y2_qty'], 0) ?></td>
                                        <td class="text-end font-mono"><?= number_format($d['y2_cost'], 2) ?></td>

                                        <!-- ปีงบฯ ปัจจุบัน -->
                                        <td class="text-end font-mono fw-semibold text-rx-accent rx-col-divider"><?= number_format($d['y1_qty'], 0) ?></td>
                                        <td class="text-end font-mono fw-semibold text-rx-accent"><?= number_format($d['y1_cost'], 2) ?></td>

                                        <!-- ผลต่าง -->
                                        <td class="text-end font-mono rx-col-divider <?= ($diffQty > 0) ? 'text-rx-warning' : (($diffQty < 0) ? 'text-rx-success' : 'text-muted') ?>">
                                            <?= ($diffQty > 0 ? '+' : '') . number_format($diffQty, 0) ?>
                                            <?php if ($pctQty !== null): ?>
                                                <div class="small text-muted"><?= ($pctQty >= 0 ? '+' : '') . $pctQty ?>%</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end font-mono <?= ($diffCost > 0) ? 'text-rx-warning' : (($diffCost < 0) ? 'text-rx-success' : 'text-muted') ?>">
                                            <?= ($diffCost > 0 ? '+' : '') . number_format($diffCost, 2) ?>
                                            <?php if ($pctCost !== null): ?>
                                                <div class="small text-muted"><?= ($pctCost >= 0 ? '+' : '') . $pctCost ?>%</div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($byDrug)): ?>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="4" class="text-end">รวมทั้งสิ้น (<?= number_format(count($byDrug)) ?> รายการยา)</td>
                                    <td class="text-end font-mono rx-col-divider"><?= number_format($grand['y3_qty'], 0) ?></td>
                                    <td class="text-end font-mono"><?= number_format($grand['y3_cost'], 2) ?></td>
                                    <td class="text-end font-mono rx-col-divider"><?= number_format($grand['y2_qty'], 0) ?></td>
                                    <td class="text-end font-mono"><?= number_format($grand['y2_cost'], 2) ?></td>
                                    <td class="text-end font-mono text-rx-accent rx-col-divider"><?= number_format($grand['y1_qty'], 0) ?></td>
                                    <td class="text-end font-mono text-rx-accent"><?= number_format($grand['y1_cost'], 2) ?></td>
                                    <td class="text-end font-mono rx-col-divider">
                                        <?= (($grand['y1_qty'] - $grand['y2_qty']) > 0 ? '+' : '') . number_format($grand['y1_qty'] - $grand['y2_qty'], 0) ?>
                                    </td>
                                    <td class="text-end font-mono">
                                        <?= ($diffCostGrand > 0 ? '+' : '') . number_format($diffCostGrand, 2) ?>
                                    </td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <!-- ===============================================================
                 มุมมองที่ 2: สรุปเปรียบเทียบแยกตาม รพ.สต. (หน่วยบริการ)
                 =============================================================== -->
            <div class="rx-panel overflow-hidden">
                <div class="rx-panel-header">
                    <span><i class="bi bi-hospital me-2 text-rx-accent"></i>ตารางเปรียบเทียบปริมาณและมูลค่าการใช้ยา แยกตาม รพ.สต.</span>
                    <span class="small text-muted">คลิกชื่อ รพ.สต. เพื่อเจาะลึกดูรายการยาของแห่งนั้น</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-rx">
                        <thead>
                            <tr>
                                <th rowspan="2" class="text-center" style="width: 56px; vertical-align: middle;">ลำดับ</th>
                                <th rowspan="2" style="min-width: 230px; vertical-align: middle;">หน่วยบริการ (รพ.สต.)</th>
                                <th rowspan="2" style="vertical-align: middle;">ตำบล</th>
                                <th colspan="2" class="text-center rx-col-group rx-col-divider"><?= htmlspecialchars($colHeaderY3) ?></th>
                                <th colspan="2" class="text-center rx-col-group rx-col-divider"><?= htmlspecialchars($colHeaderY2) ?></th>
                                <th colspan="2" class="text-center rx-col-group rx-col-divider text-rx-accent"><?= htmlspecialchars($colHeaderY1) ?></th>
                                <th colspan="2" class="text-center rx-col-group rx-col-divider">ผลต่างมูลค่า (<?= $shortLabelY1 ?> เทียบ <?= $shortLabelY2 ?>)</th>
                            </tr>
                            <tr>
                                <th class="text-end rx-col-divider">ปริมาณรวม</th>
                                <th class="text-end">มูลค่า (บาท)</th>
                                <th class="text-end rx-col-divider">ปริมาณรวม</th>
                                <th class="text-end">มูลค่า (บาท)</th>
                                <th class="text-end rx-col-divider text-rx-accent">ปริมาณรวม</th>
                                <th class="text-end text-rx-accent">มูลค่า (บาท)</th>
                                <th class="text-end rx-col-divider">บาท (+/-)</th>
                                <th class="text-end">% เปลี่ยนแปลง</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($byCenter)): ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="rx-empty-state">
                                            <i class="bi bi-hospital"></i>
                                            <div class="fw-medium">ไม่พบข้อมูลหน่วยบริการ</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $rowNo = 1;
                                foreach ($byCenter as $h => $c):
                                    $diffCost = $c['y1_cost'] - $c['y2_cost'];
                                    $pctCost = rxcore_calc_growth($c['y1_cost'], $c['y2_cost']);
                                    $drillUrl = url('pages/report_compare.php?' . http_build_query([
                                        'fiscal_year' => $targetBE,
                                        'period_mode' => $periodMode,
                                        'month'       => $selectedMonth,
                                        'hcode'       => $h,
                                        'drug_type'   => $selectedGroup,
                                        'tab'         => 'drugs',
                                    ]));
                                ?>
                                    <tr>
                                        <td class="text-center font-mono text-muted"><?= $rowNo++ ?></td>
                                        <td>
                                            <a href="<?= $drillUrl ?>" class="fw-medium text-rx-accent text-decoration-none">
                                                <?= htmlspecialchars($c['name']) ?>
                                            </a>
                                            <div class="rx-code small text-muted">HCODE: <?= htmlspecialchars($h) ?></div>
                                        </td>
                                        <td class="text-secondary small"><?= htmlspecialchars($c['subdistrict'] ?: '-') ?></td>

                                        <td class="text-end font-mono text-secondary rx-col-divider"><?= number_format($c['y3_qty'], 0) ?></td>
                                        <td class="text-end font-mono text-secondary"><?= number_format($c['y3_cost'], 2) ?></td>

                                        <td class="text-end font-mono rx-col-divider"><?= number_format($c['y2_qty'], 0) ?></td>
                                        <td class="text-end font-mono"><?= number_format($c['y2_cost'], 2) ?></td>

                                        <td class="text-end font-mono fw-semibold text-rx-accent rx-col-divider"><?= number_format($c['y1_qty'], 0) ?></td>
                                        <td class="text-end font-mono fw-semibold text-rx-accent"><?= number_format($c['y1_cost'], 2) ?></td>

                                        <td class="text-end font-mono rx-col-divider <?= ($diffCost > 0) ? 'text-rx-warning' : (($diffCost < 0) ? 'text-rx-success' : 'text-muted') ?>">
                                            <?= ($diffCost > 0 ? '+' : '') . number_format($diffCost, 2) ?>
                                        </td>
                                        <td class="text-end font-mono <?= ($diffCost > 0) ? 'text-rx-warning' : (($diffCost < 0) ? 'text-rx-success' : 'text-muted') ?>">
                                            <?= $pctCost !== null ? (($pctCost >= 0 ? '+' : '') . $pctCost . '%') : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($byCenter)): ?>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="3" class="text-end">รวมทุกหน่วยบริการ (<?= number_format(count($byCenter)) ?> แห่ง)</td>
                                    <td class="text-end font-mono rx-col-divider"><?= number_format($grand['y3_qty'], 0) ?></td>
                                    <td class="text-end font-mono"><?= number_format($grand['y3_cost'], 2) ?></td>
                                    <td class="text-end font-mono rx-col-divider"><?= number_format($grand['y2_qty'], 0) ?></td>
                                    <td class="text-end font-mono"><?= number_format($grand['y2_cost'], 2) ?></td>
                                    <td class="text-end font-mono text-rx-accent rx-col-divider"><?= number_format($grand['y1_qty'], 0) ?></td>
                                    <td class="text-end font-mono text-rx-accent"><?= number_format($grand['y1_cost'], 2) ?></td>
                                    <td class="text-end font-mono rx-col-divider"><?= ($diffCostGrand > 0 ? '+' : '') . number_format($diffCostGrand, 2) ?></td>
                                    <td class="text-end font-mono"><?= $growthCostGrand !== null ? (($growthCostGrand >= 0 ? '+' : '') . $growthCostGrand . '%') : '-' ?></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>
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
