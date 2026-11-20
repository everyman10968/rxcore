<?php
/**
 * RxCore — ภาพรวมระบบ (Dashboard)
 * แสดงข้อมูลจริง 100% จาก Supabase Cloud ตามปีงบประมาณราชการ (ต.ค. – ก.ย.)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/supabase.php';
require_once dirname(__DIR__) . '/includes/fiscal_year.php';
require_once dirname(__DIR__) . '/includes/reports_registry.php';

$current_page = 'dashboard';
$page_title = 'ภาพรวมระบบ';

// 1. โหลดปีงบประมาณที่ตั้งค่าไว้ (ค่าเริ่มต้นดึงจากที่ตั้งใน หน้าตั้งค่าระบบ)
$allFiscalYears = FiscalYearConfig::getAll();
$defaultFy = FiscalYearConfig::getDefault();
$reqBe = isset($_GET['fy']) ? (int)$_GET['fy'] : (int)$defaultFy['fiscal_year'];
$activeFy = FiscalYearConfig::findByYear($reqBe) ?? $defaultFy;

$fyStart = $activeFy['start_date'];
$fyEnd   = $activeFy['end_date'];
$fiscalMonths = FiscalYearConfig::getFiscalMonths($activeFy);

// 2. นับจำนวนยาในทะเบียนที่เปิดใช้
$activeDrugsCount = SupabaseService::count('rx_drug_lookup', 'is_active=eq.true');

// 3. ดึงสถิติประจำปีงบประมาณ (ใช้ RPC ความเร็วสูงก่อน หากยังไม่ได้ติดตั้ง SQL จะ fallback อัตโนมัติ)
$rpcRes = SupabaseService::rpc('fn_rx_dashboard_stats', [
    'p_start_date' => $fyStart,
    'p_end_date'   => $fyEnd,
]);

$centersStats       = [];
$monthlyRaw         = [];
$topDrugs           = [];
$drugGroupsRaw      = [];
$overall            = [
    'total_vn_count'     => 0,
    'total_tx_count'     => 0,
    'used_drug_count'    => 0,
    'total_cost'         => 0.0,
    'missing_cost_count' => 0,
];
$rpcUsed = false;

if ($rpcRes['ok'] && is_array($rpcRes['data']) && isset($rpcRes['data']['centers'])) {
    $rpcUsed      = true;
    $centersStats = is_array($rpcRes['data']['centers']) ? $rpcRes['data']['centers'] : [];
    $monthlyRaw   = is_array($rpcRes['data']['monthly']) ? $rpcRes['data']['monthly'] : [];
    $topDrugs     = is_array($rpcRes['data']['top_drugs']) ? $rpcRes['data']['top_drugs'] : [];
    if (is_array($rpcRes['data']['overall'] ?? null)) {
        $overall = array_merge($overall, $rpcRes['data']['overall']);
    }
    if (is_array($rpcRes['data']['drug_groups_by_center'] ?? null)) {
        $drugGroupsRaw = $rpcRes['data']['drug_groups_by_center'];
    } else {
        // กรณี RPC บน Supabase เป็นเวอร์ชันก่อนเพิ่ม drug_groups_by_center ให้ดึงสรุปจาก View มาเติมให้ทันที
        $summaryGroupRows = rxcore_fetch_all_rows(
            'v_rx_drug_dispense_summary',
            "select=hcode,drug_type_name,dispense_tx_count,total_qty&vstdate=gte.{$fyStart}&vstdate=lte.{$fyEnd}",
            1000,
            100000
        );
        $dgMap = [];
        foreach ($summaryGroupRows as $sr) {
            $h = (string)($sr['hcode'] ?? '');
            $grp = trim((string)($sr['drug_type_name'] ?? '')) ?: 'ไม่ระบุกลุ่มยา';
            if ($h === '') continue;
            $k = $h . '|' . $grp;
            if (!isset($dgMap[$k])) {
                $dgMap[$k] = ['hcode' => $h, 'drug_type_name' => $grp, 'tx_count' => 0, 'total_qty' => 0.0];
            }
            $dgMap[$k]['tx_count']  += (int)($sr['dispense_tx_count'] ?? 0);
            $dgMap[$k]['total_qty'] += (float)($sr['total_qty'] ?? 0);
        }
        $drugGroupsRaw = array_values($dgMap);
    }
} else {
    // Fallback ฝั่ง PHP กรณียังไม่ได้รัน migration_dashboard_v2.sql
    $hcRows = SupabaseService::select('rx_health_centers', 'select=*&order=hcode.asc');
    $centerMap = [];
    foreach ($hcRows as $hc) {
        $h = (string)$hc['hcode'];
        $name = (string)($hc['name'] ?? $h);
        $fType = (!empty($hc['facility_type']) && in_array($hc['facility_type'], ['HOSPITAL', 'PCU'], true))
            ? $hc['facility_type']
            : (($h === '10968' || mb_strpos($name, 'โรงพยาบาล') === 0) ? 'HOSPITAL' : 'PCU');
        $centerMap[$h] = [
            'hcode'          => $h,
            'name'           => $name,
            'facility_type'  => $fType,
            'is_active'      => !empty($hc['is_active']),
            'last_sync_at'   => $hc['last_sync_at'] ?? null,
            'latest_vstdate' => null,
            'vn_count'       => 0,
            'tx_count'       => 0,
            'total_cost'     => 0.0,
        ];
    }

    $fyDispenseRows = rxcore_fetch_all_rows(
        'rx_dispense',
        'select=hcode,vn,vstdate,tpu_code_list,qty,total_cost_applied'
        . "&is_deleted=eq.false&vstdate=gte.{$fyStart}&vstdate=lte.{$fyEnd}",
        1000,
        150000
    );

    $centerVns = [];
    $monthCenterVns = [];
    $monthCenterAgg = [];
    $networkVns = [];
    $usedDrugs = [];
    $drugCostAgg = [];

    foreach ($fyDispenseRows as $r) {
        $h = (string)($r['hcode'] ?? '');
        $vn = trim((string)($r['vn'] ?? ''));
        $vst = (string)($r['vstdate'] ?? '');
        $ym = substr($vst, 0, 7);
        $tpu = (string)($r['tpu_code_list'] ?? '');
        $qty = (float)($r['qty'] ?? 0);
        $costNull = ($r['total_cost_applied'] === null);
        $cost = $costNull ? 0.0 : (float)$r['total_cost_applied'];

        if ($h === '') continue;

        if (!isset($centerMap[$h])) {
            $centerMap[$h] = [
                'hcode' => $h, 'name' => $h, 'facility_type' => 'PCU',
                'is_active' => true, 'last_sync_at' => null, 'latest_vstdate' => null,
                'vn_count' => 0, 'tx_count' => 0, 'total_cost' => 0.0,
            ];
        }

        $centerMap[$h]['tx_count']++;
        $centerMap[$h]['total_cost'] += $cost;
        if ($centerMap[$h]['latest_vstdate'] === null || $vst > $centerMap[$h]['latest_vstdate']) {
            $centerMap[$h]['latest_vstdate'] = $vst;
        }
        if ($vn !== '') {
            $centerVns[$h][$vn] = true;
            $networkVns[$h . '|' . $vn] = true;
            if ($ym !== '') {
                $monthCenterVns[$ym . '|' . $h][$vn] = true;
            }
        }
        if ($ym !== '') {
            if (!isset($monthCenterAgg[$ym . '|' . $h])) {
                $monthCenterAgg[$ym . '|' . $h] = ['ym' => $ym, 'hcode' => $h, 'vn_count' => 0, 'tx_count' => 0, 'total_cost' => 0.0];
            }
            $monthCenterAgg[$ym . '|' . $h]['tx_count']++;
            $monthCenterAgg[$ym . '|' . $h]['total_cost'] += $cost;
        }
        if ($tpu !== '') {
            $usedDrugs[$tpu] = true;
            if (!isset($drugCostAgg[$tpu])) {
                $drugCostAgg[$tpu] = ['tpu_code_list' => $tpu, 'tx_count' => 0, 'total_qty' => 0.0, 'total_cost' => 0.0];
            }
            $drugCostAgg[$tpu]['tx_count']++;
            $drugCostAgg[$tpu]['total_qty'] += $qty;
            $drugCostAgg[$tpu]['total_cost'] += $cost;
        }
        $overall['total_tx_count']++;
        $overall['total_cost'] += $cost;
        if ($costNull) {
            $overall['missing_cost_count']++;
        }
    }

    foreach ($centerMap as $h => &$cItem) {
        $cItem['vn_count'] = isset($centerVns[$h]) ? count($centerVns[$h]) : 0;
    }
    unset($cItem);

    usort($centerMap, function ($a, $b) {
        $ta = $a['facility_type'] === 'HOSPITAL' ? 0 : 1;
        $tb = $b['facility_type'] === 'HOSPITAL' ? 0 : 1;
        return $ta !== $tb ? ($ta <=> $tb) : strcmp($a['hcode'], $b['hcode']);
    });
    $centersStats = array_values($centerMap);

    foreach ($monthCenterAgg as $key => &$mItem) {
        $mItem['vn_count'] = isset($monthCenterVns[$key]) ? count($monthCenterVns[$key]) : 0;
    }
    unset($mItem);
    $monthlyRaw = array_values($monthCenterAgg);

    uasort($drugCostAgg, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);
    $top5Raw = array_slice($drugCostAgg, 0, 5);
    $drugLookup = SupabaseService::select('rx_drug_lookup', 'select=tpu_code_list,drug_name,drug_type_name,unit');
    $drugMeta = [];
    foreach ($drugLookup as $dl) {
        $drugMeta[$dl['tpu_code_list']] = $dl;
    }
    foreach ($top5Raw as $td) {
        $meta = $drugMeta[$td['tpu_code_list']] ?? [];
        $topDrugs[] = [
            'tpu_code_list' => $td['tpu_code_list'],
            'drug_name'     => $meta['drug_name'] ?? $td['tpu_code_list'],
            'unit'          => $meta['unit'] ?? '',
            'tx_count'      => $td['tx_count'],
            'total_qty'     => $td['total_qty'],
            'total_cost'    => $td['total_cost'],
        ];
    }

    $dgMap = [];
    foreach ($fyDispenseRows as $r) {
        $h = (string)($r['hcode'] ?? '');
        $tpu = (string)($r['tpu_code_list'] ?? '');
        if ($h === '') continue;
        $grp = trim((string)($drugMeta[$tpu]['drug_type_name'] ?? '')) ?: 'ไม่ระบุกลุ่มยา';
        $k = $h . '|' . $grp;
        if (!isset($dgMap[$k])) {
            $dgMap[$k] = ['hcode' => $h, 'drug_type_name' => $grp, 'tx_count' => 0, 'total_qty' => 0.0];
        }
        $dgMap[$k]['tx_count']++;
        $dgMap[$k]['total_qty'] += (float)($r['qty'] ?? 0);
    }
    $drugGroupsRaw = array_values($dgMap);

    $overall['total_vn_count']  = count($networkVns);
    $overall['used_drug_count'] = count($usedDrugs);
}

// 4. คำนวณสรุปหน่วยบริการ, สถานะออนไลน์ <= 3 วัน และเตรียมข้อมูลให้ JS Chart
$hcMap = [];
$activeCentersCount = 0;
$hospitalCount = 0;
$pcuCount = 0;
$online3DaysCount = 0;
$nowTs = time();
$threeDaysSec = 3 * 86400;

// พาเลตต์สีแยกแต่ละหน่วยบริการชัดเจน (อ่านชัดทั้งโหมดพาสเทลและโหมดมืด)
$unitPalette = [
    '#7c3aed', // ม่วง (รพ.แม่ข่าย / หน่วย 1)
    '#0ea5e9', // ฟ้า
    '#10b981', // เขียวมรกต
    '#f59e0b', // ส้มอำพัน
    '#ec4899', // ชมพูเข้ม
    '#14b8a6', // เขียวน้ำทะเล
    '#6366f1', // คราม
    '#ef4444', // แดงปะการัง
    '#84cc16', // เขียวมะนาว
];

$centersForJs = [];
foreach ($centersStats as $idx => &$c) {
    $h = (string)($c['hcode'] ?? '');
    $name = (string)($c['name'] ?? $h);
    $fType = (!empty($c['facility_type']) && in_array($c['facility_type'], ['HOSPITAL', 'PCU'], true))
        ? $c['facility_type']
        : (($h === '10968' || mb_strpos($name, 'โรงพยาบาล') === 0) ? 'HOSPITAL' : 'PCU');
    $c['facility_type'] = $fType;
    $hcMap[$h] = $name;

    if (!empty($c['is_active'])) {
        $activeCentersCount++;
    }
    if ($fType === 'HOSPITAL') {
        $hospitalCount++;
    } else {
        $pcuCount++;
    }

    $lastSyncAt = $c['last_sync_at'] ?? null;
    $latestVst  = $c['latest_vstdate'] ?? null;

    // เกณฑ์ออนไลน์: มีการส่งข้อมูลล่าสุดไม่เกิน 3 วัน (อ้างอิงจาก last_sync_at เป็นหลัก หากไม่มีใช้ latest_vstdate)
    $refTs = $lastSyncAt ? strtotime($lastSyncAt) : ($latestVst ? strtotime($latestVst . ' 23:59:59') : false);
    if ($refTs !== false && ($nowTs - $refTs) <= $threeDaysSec) {
        $c['_status'] = 'online';
        $c['_status_label'] = 'ออนไลน์';
        $online3DaysCount++;
    } elseif ($lastSyncAt || $latestVst) {
        $c['_status'] = 'stale';
        $c['_status_label'] = 'ขาดส่ง (>3 วัน)';
    } else {
        $c['_status'] = 'idle';
        $c['_status_label'] = 'รอข้อมูล';
    }

    $color = $unitPalette[$idx % count($unitPalette)];
    $c['_color'] = $color;

    $centersForJs[] = [
        'hcode'         => $h,
        'name'          => $name,
        'short_name'    => str_replace(['โรงพยาบาล', 'รพ.สต.'], ['รพ.', 'รพ.สต.'], $name),
        'facility_type' => $fType,
        'vn_count'      => (int)($c['vn_count'] ?? 0),
        'tx_count'      => (int)($c['tx_count'] ?? 0),
        'total_cost'    => round((float)($c['total_cost'] ?? 0), 2),
        'color'         => $color,
    ];
}
unset($c);

// เตรียมเมทริกซ์รายเดือน (12 เดือน ต.ค.-ก.ย.) ให้ JS สลับดูรวม / ตามประเภท / ราย รพ.สต. ได้ทันที
$monthlyByUnit = [];
foreach ($monthlyRaw as $mr) {
    $ym = (string)($mr['ym'] ?? '');
    $h  = (string)($mr['hcode'] ?? '');
    if ($ym === '' || $h === '') continue;
    $monthlyByUnit[$ym][$h] = (int)($mr['vn_count'] ?? 0);
}

$monthKeys   = array_column($fiscalMonths, 'ym');
$monthLabels = array_column($fiscalMonths, 'label');

// 5. จัดเตรียมข้อมูลกลุ่มยาแยกตามหน่วยบริการสำหรับแผนภูมิวงกลม (1 หน่วย = 1 แผนภูมิ)
$drugGroupPalette = [
    '#7c3aed', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899',
    '#14b8a6', '#6366f1', '#ef4444', '#84cc16', '#f97316',
    '#06b6d4', '#8b5cf6', '#d946ef', '#22c55e', '#eab308',
    '#3b82f6', '#fb7185', '#64748b',
];
$allGroupTotals = [];
$groupsByCenter = [];
foreach ($drugGroupsRaw as $dg) {
    $h = (string)($dg['hcode'] ?? '');
    $grp = trim((string)($dg['drug_type_name'] ?? '')) ?: 'ไม่ระบุกลุ่มยา';
    $qty = (float)($dg['total_qty'] ?? 0);
    $tx  = (int)($dg['tx_count'] ?? 0);
    if ($h === '' || ($qty <= 0 && $tx <= 0)) continue;

    $allGroupTotals[$grp] = ($allGroupTotals[$grp] ?? 0.0) + $qty;
    $groupsByCenter[$h][] = [
        'group'     => $grp,
        'total_qty' => round($qty, 0),
        'tx_count'  => $tx,
    ];
}
arsort($allGroupTotals);
$groupColorMap = [];
$gIdx = 0;
foreach (array_keys($allGroupTotals) as $grpName) {
    $groupColorMap[$grpName] = $drugGroupPalette[$gIdx % count($drugGroupPalette)];
    $gIdx++;
}
foreach ($groupsByCenter as $h => &$gItems) {
    usort($gItems, fn($a, $b) => $b['total_qty'] <=> $a['total_qty']);
}
unset($gItems);

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <?php if (!$rpcUsed && is_admin()): ?>
            <div class="rx-notice warning rx-no-print">
                <div>
                    <i class="bi bi-lightning-charge me-2"></i>
                    แนะนำให้นำไฟล์ <span class="font-mono">database/migration_dashboard_v2.sql</span> ไปรันใน Supabase SQL Editor เพื่อเปิดใช้งานฟังก์ชันสรุป Dashboard ความเร็วสูงและคอลัมน์ประเภทหน่วยบริการ
                </div>
            </div>
        <?php endif; ?>

        <?php if ((int)($overall['missing_cost_count'] ?? 0) > 0 && is_admin()): ?>
            <div class="rx-notice warning rx-no-print">
                <div>
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    พบรายการจ่ายยาที่ยังไม่มีราคาต้นทุนใน<?= htmlspecialchars($activeFy['label']) ?> จำนวน <strong><?= number_format((int)$overall['missing_cost_count']) ?></strong> รายการ
                </div>
                <a href="<?= url('pages/drugs.php') ?>" class="btn btn-rx-secondary btn-sm">ตรวจสอบทะเบียนยา</a>
            </div>
        <?php endif; ?>

        <!-- Page Header + Fiscal Year Switcher -->
        <div class="page-header">
            <div>
                <h1 class="page-title">ภาพรวมระบบ &mdash; <?= htmlspecialchars($activeFy['label']) ?></h1>
                <p class="page-description">
                    ช่วงปีงบประมาณ: <?= FiscalYearConfig::formatThaiDate($fyStart) ?> – <?= FiscalYearConfig::formatThaiDate($fyEnd) ?>
                    &middot; เกณฑ์ออนไลน์ส่งข้อมูลไม่เกิน 3 วัน
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <form method="GET" action="<?= url('pages/dashboard.php') ?>" class="d-flex align-items-center gap-2">
                    <select name="fy" class="form-select form-select-sm font-mono" style="min-width: 170px;" onchange="this.form.submit()" title="เลือกปีงบประมาณที่ต้องการแสดง">
                        <?php foreach ($allFiscalYears as $fyOpt): ?>
                            <option value="<?= (int)$fyOpt['fiscal_year'] ?>" <?= ((int)$fyOpt['fiscal_year'] === (int)$activeFy['fiscal_year']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fyOpt['label']) ?><?= !empty($fyOpt['is_default']) ? ' (หลัก)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if (is_admin()): ?>
                    <a href="<?= url('pages/settings.php') ?>" class="btn btn-rx-secondary btn-sm" title="เพิ่ม/แก้ไข/กำหนดปีงบประมาณหลัก">
                        <i class="bi bi-sliders me-1"></i> ตั้งค่าปีงบฯ
                    </a>
                <?php endif; ?>
                <a href="<?= url('pages/reports.php') ?>" class="btn btn-rx-primary btn-sm">
                    <i class="bi bi-file-earmark-bar-graph me-1"></i> รายงาน
                </a>
            </div>
        </div>

        <!-- 4 Top KPI Cards (ทั้งหมดแสดงข้อมูลของปีงบฯ ที่กำหนด) -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">1. จำนวนหน่วยบริการ</div>
                        <div class="stat-value"><?= number_format($activeCentersCount) ?> <span class="fs-6 fw-normal text-muted">แห่ง</span></div>
                        <div class="stat-sub">รพ. <?= $hospitalCount ?> &middot; รพ.สต. <?= $pcuCount ?> (ออนไลน์ <?= $online3DaysCount ?> แห่ง)</div>
                    </div>
                    <div class="stat-icon primary"><i class="bi bi-hospital"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">2. จำนวนยาในทะเบียน</div>
                        <div class="stat-value"><?= number_format($activeDrugsCount) ?> <span class="fs-6 fw-normal text-muted">รายการ</span></div>
                        <div class="stat-sub">มีประวัติจ่ายในปีงบฯ นี้ <?= number_format((int)$overall['used_drug_count']) ?> รายการ</div>
                    </div>
                    <div class="stat-icon info"><i class="bi bi-capsule-pill"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">3. จำนวนใบสั่งยา (ปีงบฯ <?= (int)$activeFy['fiscal_year'] ?>)</div>
                        <div class="stat-value"><?= number_format((int)$overall['total_vn_count']) ?> <span class="fs-6 fw-normal text-muted">ใบ</span></div>
                        <div class="stat-sub font-mono"><?= number_format((int)$overall['total_tx_count']) ?> รายการจ่ายยา OPD</div>
                    </div>
                    <div class="stat-icon warning"><i class="bi bi-receipt-cutoff"></i></div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">4. มูลค่าราคาทุน (ปีงบฯ <?= (int)$activeFy['fiscal_year'] ?>)</div>
                        <div class="stat-value"><?= number_format((float)$overall['total_cost'], 2) ?></div>
                        <div class="stat-sub">บาท (<?= htmlspecialchars($activeFy['label']) ?>)</div>
                    </div>
                    <div class="stat-icon success"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>

        <!-- Row 2: แผนภูมิแท่งแยกหน่วยบริการ + สถานะออนไลน์ <= 3 วัน -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-8">
                <div class="rx-panel h-100 d-flex flex-column">
                    <div class="rx-panel-header flex-wrap gap-2">
                        <div>
                            <span>ยอดรวมแต่ละหน่วยบริการ (<?= htmlspecialchars($activeFy['label']) ?>)</span>
                            <div class="small text-muted fw-normal" style="font-size: 0.76rem;">คลิกที่แท่งกราฟเพื่อดูแนวโน้มรายเดือนของหน่วยบริการนั้นด้านล่าง</div>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-rx-primary btn-sm bar-scope-btn" data-scope="ALL">ทั้งหมด</button>
                                <button type="button" class="btn btn-rx-secondary btn-sm bar-scope-btn" data-scope="PCU">เฉพาะ รพ.สต.</button>
                            </div>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-rx-primary btn-sm bar-metric-btn" data-metric="vn">ใบสั่งยา (ใบ)</button>
                                <button type="button" class="btn btn-rx-secondary btn-sm bar-metric-btn" data-metric="cost">มูลค่าทุน (฿)</button>
                            </div>
                        </div>
                    </div>
                    <div class="p-3 flex-grow-1">
                        <div style="height: 290px; position: relative;">
                            <canvas id="unitBarChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="rx-panel h-100 d-flex flex-column">
                    <div class="rx-panel-header">
                        <span>สถานะหน่วยบริการ <span class="small text-muted fw-normal">(ออนไลน์ &le; 3 วัน)</span></span>
                        <a href="<?= url('pages/health_centers.php') ?>" class="small text-decoration-none" style="color: var(--rx-accent-text);">จัดการ</a>
                    </div>
                    <div class="p-3 flex-grow-1" style="max-height: 345px; overflow-y: auto;">
                        <?php if (empty($centersStats)): ?>
                            <div class="rx-empty-state py-4">
                                <i class="bi bi-hospital"></i>
                                <div class="small mb-3">ยังไม่ได้เพิ่มหน่วยบริการในระบบ</div>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($centersStats as $hc):
                                    $lastSync = $hc['last_sync_at'] ?? null;
                                    $latestVst = $hc['latest_vstdate'] ?? null;
                                    $statusCls = $hc['_status'] === 'online' ? 'online' : ($hc['_status'] === 'stale' ? 'warning' : 'idle');
                                ?>
                                    <div class="d-flex justify-content-between align-items-start gap-2 py-2 border-bottom" style="border-color: var(--rx-border) !important;">
                                        <div class="d-flex align-items-start gap-2 overflow-hidden">
                                            <span class="mt-1 rounded-circle flex-shrink-0" style="width: 9px; height: 9px; background-color: <?= htmlspecialchars($hc['_color']) ?>;"></span>
                                            <div class="overflow-hidden">
                                                <div class="fw-medium small text-truncate">
                                                    <?= htmlspecialchars($hc['name']) ?>
                                                    <span class="rx-tag ms-1" style="font-size: 0.68rem; padding: 0.08rem 0.4rem;">
                                                        <?= $hc['facility_type'] === 'HOSPITAL' ? 'รพ.' : 'รพ.สต.' ?>
                                                    </span>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.74rem;">
                                                    <span class="rx-code"><?= htmlspecialchars($hc['hcode']) ?></span> &middot;
                                                    ส่งล่าสุด: <?= $lastSync ? date('d/m/y H:i', strtotime($lastSync)) : 'ยังไม่เคยส่ง' ?>
                                                </div>
                                                <div class="text-muted" style="font-size: 0.72rem;">
                                                    ข้อมูลบริการถึง: <?= $latestVst ? FiscalYearConfig::formatThaiDate($latestVst) : '-' ?>
                                                </div>
                                            </div>
                                        </div>
                                        <span class="rx-status <?= $statusCls ?> flex-shrink-0">
                                            <?= htmlspecialchars($hc['_status_label']) ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 3: แผนภูมิเส้นยอดรวมใบสั่งยา 12 เดือนปีงบฯ + Top 5 ยามูลค่าสูงสุด -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-8">
                <div class="rx-panel h-100 d-flex flex-column">
                    <div class="rx-panel-header flex-wrap gap-2">
                        <div>
                            <span>ยอดรวมใบสั่งยา รายเดือนปีงบประมาณ (ต.ค. – ก.ย.)</span>
                            <div class="small text-muted fw-normal" id="lblLineScopeNote" style="font-size: 0.76rem;">แสดงข้อมูล: รวมทุกหน่วยบริการ</div>
                        </div>
                        <div style="min-width: 220px;">
                            <select id="selLineUnit" class="form-select form-select-sm">
                                <option value="ALL">รวมทุกหน่วยบริการ (ทั้งเครือข่าย)</option>
                                <option value="TYPE:PCU">เฉพาะ รพ.สต. ทั้งหมด</option>
                                <option value="TYPE:HOSPITAL">เฉพาะโรงพยาบาล (รพ.)</option>
                                <?php foreach ($centersStats as $hc): ?>
                                    <option value="<?= htmlspecialchars($hc['hcode']) ?>">
                                        <?= htmlspecialchars($hc['hcode'] . ' — ' . $hc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="p-3 flex-grow-1">
                        <div style="height: 270px; position: relative;">
                            <canvas id="monthlyLineChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="rx-panel h-100 d-flex flex-column">
                    <div class="rx-panel-header">
                        <span>Top 5 ยามูลค่าสูงสุด (ปีงบฯ <?= (int)$activeFy['fiscal_year'] ?>)</span>
                        <a href="<?= url('pages/report_compare.php') ?>" class="small text-decoration-none" style="color: var(--rx-accent-text);">ดูทั้งหมด</a>
                    </div>
                    <div class="p-3 flex-grow-1">
                        <?php if (empty($topDrugs)): ?>
                            <div class="rx-empty-state py-4">
                                <i class="bi bi-capsule"></i>
                                <div class="small">ยังไม่มีข้อมูลการจ่ายยาในปีงบประมาณนี้</div>
                            </div>
                        <?php else: ?>
                            <?php $maxTopCost = max(1.0, (float)($topDrugs[0]['total_cost'] ?? 1.0)); ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($topDrugs as $idx => $td):
                                    $pctBar = min(100, round(((float)$td['total_cost'] / $maxTopCost) * 100));
                                ?>
                                    <div>
                                        <div class="d-flex justify-content-between align-items-baseline gap-2 mb-1">
                                            <div class="small fw-medium text-truncate" title="<?= htmlspecialchars($td['drug_name']) ?>">
                                                <span class="font-mono text-muted me-1"><?= $idx + 1 ?>.</span>
                                                <?= htmlspecialchars($td['drug_name']) ?>
                                            </div>
                                            <div class="font-mono small fw-semibold text-rx-accent flex-shrink-0">
                                                <?= number_format((float)$td['total_cost'], 2) ?> ฿
                                            </div>
                                        </div>
                                        <div class="progress" style="height: 6px; background-color: var(--rx-surface-subtle);">
                                            <div class="progress-bar" style="width: <?= $pctBar ?>%; background: var(--rx-aurora-horizontal);"></div>
                                        </div>
                                        <div class="d-flex justify-content-between text-muted mt-1" style="font-size: 0.72rem;">
                                            <span><?= number_format((int)$td['tx_count']) ?> ครั้ง</span>
                                            <span class="font-mono"><?= number_format((float)$td['total_qty'], 0) ?> <?= htmlspecialchars($td['unit']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 4: แผนภูมิวงกลมจำนวนยา ตามกลุ่มยาแยกตามหน่วยบริการ (1 หน่วยบริการ = 1 แผนภูมิ) -->
        <div class="rx-panel p-3 p-md-4 mb-2">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 pb-2" style="border-bottom: 1px solid var(--rx-border);">
                <div>
                    <h2 class="h6 mb-0 fw-semibold">
                        <i class="bi bi-pie-chart me-2 text-rx-accent"></i>สัดส่วนจำนวนยาตามกลุ่มยา แยกตามหน่วยบริการ (<?= htmlspecialchars($activeFy['label']) ?>)
                    </h2>
                    <div class="small text-muted">แสดงแผนภูมิวงกลมแยก 1 แผนภูมิต่อ 1 หน่วยบริการ (สีเดียวกันคือกลุ่มยาเดียวกัน)</div>
                </div>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-rx-primary btn-sm pie-metric-btn" data-metric="qty">ปริมาณยา (หน่วย)</button>
                    <button type="button" class="btn btn-rx-secondary btn-sm pie-metric-btn" data-metric="tx">จำนวนครั้งที่จ่าย</button>
                </div>
            </div>

            <?php if (!empty($groupColorMap)): ?>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach (array_slice($groupColorMap, 0, 12, true) as $gName => $gColor): ?>
                        <span class="rx-tag" style="gap: 0.4rem;">
                            <span class="rounded-circle d-inline-block" style="width: 8px; height: 8px; background-color: <?= htmlspecialchars($gColor) ?>;"></span>
                            <?= htmlspecialchars($gName) ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if (count($groupColorMap) > 12): ?>
                        <span class="small text-muted align-self-center">+ อีก <?= count($groupColorMap) - 12 ?> กลุ่ม</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <?php foreach ($centersStats as $hc):
                    $h = (string)$hc['hcode'];
                    $unitGroups = $groupsByCenter[$h] ?? [];
                    $hasData = !empty($unitGroups);
                ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="rx-panel h-100 d-flex flex-column" style="box-shadow: none;">
                            <div class="rx-panel-header py-2 px-3">
                                <div class="d-flex align-items-center gap-2 overflow-hidden">
                                    <span class="rounded-circle flex-shrink-0" style="width: 9px; height: 9px; background-color: <?= htmlspecialchars($hc['_color']) ?>;"></span>
                                    <span class="small fw-semibold text-truncate" title="<?= htmlspecialchars($hc['name']) ?>"><?= htmlspecialchars($hc['name']) ?></span>
                                </div>
                                <span class="rx-tag font-mono" style="font-size: 0.7rem;"><?= htmlspecialchars($h) ?></span>
                            </div>
                            <div class="p-3 flex-grow-1 d-flex flex-column justify-content-center">
                                <?php if (!$hasData): ?>
                                    <div class="rx-empty-state py-4">
                                        <i class="bi bi-pie-chart" style="font-size: 1.7rem;"></i>
                                        <div class="small">ยังไม่มีข้อมูลการจ่ายยาในปีงบฯ นี้</div>
                                    </div>
                                <?php else: ?>
                                    <div style="height: 205px; position: relative;">
                                        <canvas class="unit-pie-canvas" data-hcode="<?= htmlspecialchars($h) ?>"></canvas>
                                    </div>
                                    <div class="mt-2 pt-2 d-flex flex-column gap-1" style="border-top: 1px solid var(--rx-border);" id="pieSummary_<?= htmlspecialchars($h) ?>"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const centersData    = <?= json_encode($centersForJs, JSON_UNESCAPED_UNICODE) ?>;
        const monthKeys      = <?= json_encode($monthKeys, JSON_UNESCAPED_UNICODE) ?>;
        const monthLabels    = <?= json_encode($monthLabels, JSON_UNESCAPED_UNICODE) ?>;
        const monthlyByUnit  = <?= json_encode($monthlyByUnit, JSON_UNESCAPED_UNICODE) ?>;
        const groupsByCenter = <?= json_encode($groupsByCenter, JSON_UNESCAPED_UNICODE) ?>;
        const groupColorMap  = <?= json_encode($groupColorMap, JSON_UNESCAPED_UNICODE) ?>;

        function getThemeColors() {
            const isDark = document.documentElement.getAttribute('data-rx-theme') === 'aurora-dark';
            return {
                grid: isDark ? 'rgba(148, 163, 184, 0.16)' : 'rgba(100, 116, 139, 0.16)',
                tick: isDark ? '#94a3b8' : '#475569',
                lineDefault: isDark ? '#6ef0bc' : '#7c3aed',
                fillDefault: isDark ? 'rgba(110, 240, 188, 0.16)' : 'rgba(124, 58, 237, 0.12)',
            };
        }

        // =====================================================================
        // 1. แผนภูมิแท่งแสดงยอดแต่ละหน่วยบริการ (แยกสีชัดเจน + คลิกเพื่อกรองกราฟเส้น)
        // =====================================================================
        let barScope = 'ALL';
        let barMetric = 'vn';
        let currentVisibleCenters = [];
        let barChart = null;

        function getFilteredCenters() {
            if (barScope === 'PCU') {
                return centersData.filter(c => c.facility_type === 'PCU');
            }
            return centersData;
        }

        function buildBarDataset() {
            currentVisibleCenters = getFilteredCenters();
            return {
                labels: currentVisibleCenters.map(c => c.short_name),
                data: currentVisibleCenters.map(c => barMetric === 'cost' ? c.total_cost : c.vn_count),
                colors: currentVisibleCenters.map(c => c.color),
            };
        }

        const barCtx = document.getElementById('unitBarChart');
        if (barCtx) {
            const tc = getThemeColors();
            const ds = buildBarDataset();
            barChart = new Chart(barCtx, {
                type: 'bar',
                data: {
                    labels: ds.labels,
                    datasets: [{
                        label: 'จำนวนใบสั่งยา (ใบ)',
                        data: ds.data,
                        backgroundColor: ds.colors,
                        borderRadius: 7,
                        maxBarThickness: 48,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: function (evt, elements) {
                        if (elements.length > 0) {
                            const idx = elements[0].index;
                            const clicked = currentVisibleCenters[idx];
                            if (clicked && selLineUnit) {
                                selLineUnit.value = clicked.hcode;
                                updateLineChart();
                            }
                        }
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    const val = ctx.parsed.y || 0;
                                    return barMetric === 'cost'
                                        ? ' มูลค่าทุน: ' + val.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท'
                                        : ' ใบสั่งยา: ' + val.toLocaleString('th-TH') + ' ใบ';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: tc.tick },
                            grid: { color: tc.grid }
                        },
                        x: {
                            ticks: { color: tc.tick },
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        function refreshBarChart() {
            if (!barChart) return;
            const ds = buildBarDataset();
            barChart.data.labels = ds.labels;
            barChart.data.datasets[0].label = barMetric === 'cost' ? 'มูลค่าราคาทุน (บาท)' : 'จำนวนใบสั่งยา (ใบ)';
            barChart.data.datasets[0].data = ds.data;
            barChart.data.datasets[0].backgroundColor = ds.colors;
            barChart.update();
        }

        document.querySelectorAll('.bar-scope-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                barScope = this.dataset.scope;
                document.querySelectorAll('.bar-scope-btn').forEach(b => {
                    b.className = 'btn btn-sm bar-scope-btn ' + (b === this ? 'btn-rx-primary' : 'btn-rx-secondary');
                });
                refreshBarChart();
            });
        });

        document.querySelectorAll('.bar-metric-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                barMetric = this.dataset.metric;
                document.querySelectorAll('.bar-metric-btn').forEach(b => {
                    b.className = 'btn btn-sm bar-metric-btn ' + (b === this ? 'btn-rx-primary' : 'btn-rx-secondary');
                });
                refreshBarChart();
            });
        });

        // =====================================================================
        // 2. แผนภูมิเส้นยอดรวมใบสั่งยา 12 เดือนปีงบฯ (เลือกดูรวม / ตามหน่วยบริการได้)
        // =====================================================================
        const selLineUnit = document.getElementById('selLineUnit');
        const lblLineNote = document.getElementById('lblLineScopeNote');
        const lineCtx = document.getElementById('monthlyLineChart');
        let lineChart = null;

        function computeMonthlySeries(filterVal) {
            let allowedHcodes = null;
            let lineColor = null;
            let scopeText = 'รวมทุกหน่วยบริการ';

            if (filterVal === 'TYPE:PCU') {
                allowedHcodes = new Set(centersData.filter(c => c.facility_type === 'PCU').map(c => c.hcode));
                scopeText = 'เฉพาะ รพ.สต. ทั้งหมด';
            } else if (filterVal === 'TYPE:HOSPITAL') {
                allowedHcodes = new Set(centersData.filter(c => c.facility_type === 'HOSPITAL').map(c => c.hcode));
                scopeText = 'เฉพาะโรงพยาบาล (รพ.)';
            } else if (filterVal !== 'ALL') {
                allowedHcodes = new Set([filterVal]);
                const found = centersData.find(c => c.hcode === filterVal);
                if (found) {
                    lineColor = found.color;
                    scopeText = found.name + ' (' + found.hcode + ')';
                }
            }

            const counts = monthKeys.map(ym => {
                const bucket = monthlyByUnit[ym] || {};
                let sum = 0;
                Object.keys(bucket).forEach(h => {
                    if (!allowedHcodes || allowedHcodes.has(h)) {
                        sum += Number(bucket[h] || 0);
                    }
                });
                return sum;
            });

            return { counts, lineColor, scopeText };
        }

        function updateLineChart() {
            if (!lineChart || !selLineUnit) return;
            const tc = getThemeColors();
            const res = computeMonthlySeries(selLineUnit.value);
            const c = res.lineColor || tc.lineDefault;

            lineChart.data.datasets[0].data = res.counts;
            lineChart.data.datasets[0].borderColor = c;
            lineChart.data.datasets[0].pointBackgroundColor = c;
            lineChart.data.datasets[0].backgroundColor = res.lineColor ? (res.lineColor + '24') : tc.fillDefault;
            lineChart.update();

            if (lblLineNote) {
                lblLineNote.textContent = 'แสดงข้อมูล: ' + res.scopeText;
            }
        }

        if (lineCtx) {
            const tc = getThemeColors();
            const initRes = computeMonthlySeries('ALL');
            lineChart = new Chart(lineCtx, {
                type: 'line',
                data: {
                    labels: monthLabels,
                    datasets: [{
                        label: 'จำนวนใบสั่งยา (ใบ)',
                        data: initRes.counts,
                        borderColor: tc.lineDefault,
                        backgroundColor: tc.fillDefault,
                        pointBackgroundColor: tc.lineDefault,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    return ' ใบสั่งยา: ' + (ctx.parsed.y || 0).toLocaleString('th-TH') + ' ใบ';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: tc.tick },
                            grid: { color: tc.grid }
                        },
                        x: {
                            ticks: { color: tc.tick },
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        if (selLineUnit) {
            selLineUnit.addEventListener('change', updateLineChart);
        }

        // =====================================================================
        // 3. แผนภูมิวงกลมจำนวนยา ตามกลุ่มยาแยกตามหน่วยบริการ (หน่วยละ 1 แผนภูมิ)
        // =====================================================================
        let pieMetric = 'qty';
        const unitPieCharts = {};

        function getPieBorderColor() {
            return document.documentElement.getAttribute('data-rx-theme') === 'aurora-dark' ? '#181f2e' : '#ffffff';
        }

        function buildUnitPieData(hcode) {
            const raw = (groupsByCenter[hcode] || []).slice();
            raw.sort((a, b) => (pieMetric === 'tx' ? b.tx_count - a.tx_count : b.total_qty - a.total_qty));
            // จัดกลุ่มอันดับ 9 เป็นต้นไปรวมเป็น "กลุ่มอื่นๆ" เพื่อให้อ่านวงกลมง่าย
            const top = raw.slice(0, 8);
            const rest = raw.slice(8);
            if (rest.length > 0) {
                top.push({
                    group: 'กลุ่มอื่นๆ (' + rest.length + ' กลุ่ม)',
                    total_qty: rest.reduce((s, r) => s + Number(r.total_qty || 0), 0),
                    tx_count: rest.reduce((s, r) => s + Number(r.tx_count || 0), 0),
                    is_other: true
                });
            }
            const labels = top.map(x => x.group);
            const values = top.map(x => pieMetric === 'tx' ? Number(x.tx_count || 0) : Number(x.total_qty || 0));
            const colors = top.map(x => x.is_other ? '#94a3b8' : (groupColorMap[x.group] || '#64748b'));
            return { top, labels, values, colors };
        }

        function escHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }

        function renderPieSummary(hcode, ds) {
            const box = document.getElementById('pieSummary_' + hcode);
            if (!box) return;
            const total = ds.values.reduce((s, v) => s + v, 0);
            if (total <= 0) {
                box.innerHTML = '';
                return;
            }
            const unitLabel = pieMetric === 'tx' ? 'ครั้ง' : 'หน่วย';
            box.innerHTML = ds.top.slice(0, 3).map((item, idx) => {
                const val = ds.values[idx];
                const pct = ((val / total) * 100).toFixed(1);
                return `<div class="d-flex justify-content-between align-items-center" style="font-size: 0.74rem;">
                    <span class="text-truncate me-2">
                        <span class="rounded-circle d-inline-block me-1" style="width:7px;height:7px;background:${ds.colors[idx]};"></span>
                        ${escHtml(item.group)}
                    </span>
                    <span class="font-mono text-secondary flex-shrink-0">${val.toLocaleString('th-TH')} ${unitLabel} (${pct}%)</span>
                </div>`;
            }).join('');
        }

        document.querySelectorAll('.unit-pie-canvas').forEach(canvas => {
            const hcode = canvas.dataset.hcode;
            const ds = buildUnitPieData(hcode);
            renderPieSummary(hcode, ds);

            unitPieCharts[hcode] = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: ds.labels,
                    datasets: [{
                        data: ds.values,
                        backgroundColor: ds.colors,
                        borderColor: getPieBorderColor(),
                        borderWidth: 2,
                        hoverOffset: 5,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '56%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    const arr = ctx.dataset.data || [];
                                    const sum = arr.reduce((a, b) => a + Number(b || 0), 0);
                                    const v = Number(ctx.parsed || 0);
                                    const pct = sum > 0 ? ((v / sum) * 100).toFixed(1) : '0.0';
                                    const unit = pieMetric === 'tx' ? 'ครั้ง' : 'หน่วย';
                                    return ` ${ctx.label}: ${v.toLocaleString('th-TH')} ${unit} (${pct}%)`;
                                }
                            }
                        }
                    }
                }
            });
        });

        document.querySelectorAll('.pie-metric-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                pieMetric = this.dataset.metric;
                document.querySelectorAll('.pie-metric-btn').forEach(b => {
                    b.className = 'btn btn-sm pie-metric-btn ' + (b === this ? 'btn-rx-primary' : 'btn-rx-secondary');
                });
                Object.keys(unitPieCharts).forEach(hcode => {
                    const ch = unitPieCharts[hcode];
                    const ds = buildUnitPieData(hcode);
                    ch.data.labels = ds.labels;
                    ch.data.datasets[0].data = ds.values;
                    ch.data.datasets[0].backgroundColor = ds.colors;
                    ch.update();
                    renderPieSummary(hcode, ds);
                });
            });
        });

        window.addEventListener('rxcore:themechange', function () {
            const tc = getThemeColors();
            if (barChart) {
                barChart.options.scales.y.ticks.color = tc.tick;
                barChart.options.scales.y.grid.color = tc.grid;
                barChart.options.scales.x.ticks.color = tc.tick;
                barChart.update();
            }
            if (lineChart) {
                lineChart.options.scales.y.ticks.color = tc.tick;
                lineChart.options.scales.y.grid.color = tc.grid;
                lineChart.options.scales.x.ticks.color = tc.tick;
                updateLineChart();
            }
            const bCol = getPieBorderColor();
            Object.values(unitPieCharts).forEach(ch => {
                ch.data.datasets[0].borderColor = bCol;
                ch.update();
            });
        });
    });
    </script>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
