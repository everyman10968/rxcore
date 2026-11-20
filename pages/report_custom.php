<?php
/**
 * RxCore — รายงานสรุปการใช้ยาผู้ป่วยนอกตามช่วงวันที่กำหนดเอง (Custom Date-Range OPD Report)
 * ดึงข้อมูลจริง 100% จาก Supabase View v_rx_drug_dispense_summary
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/supabase.php';
require_once dirname(__DIR__) . '/includes/reports_registry.php';

$current_page = 'reports';
$page_title = 'รายงานสรุปการใช้ยาผู้ป่วยนอกตามช่วงวันที่';

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$selectedHc = trim($_GET['hcode'] ?? '');
$selectedGroup = trim($_GET['drug_type'] ?? '');

$user = current_user();
if (!is_admin() && !empty($user['hcode'])) {
    $selectedHc = $user['hcode'];
}

$healthCenters = SupabaseService::select('rx_health_centers', 'select=hcode,name&is_active=eq.true&order=hcode.asc');
$drugGroupsRaw = SupabaseService::select('rx_drug_lookup', 'select=drug_type_name&is_active=eq.true');
$drugGroups = array_values(array_unique(array_filter(array_column($drugGroupsRaw, 'drug_type_name'))));
natcasesort($drugGroups);

$queryParts = [
    'select=*',
    'vstdate=gte.' . urlencode($startDate),
    'vstdate=lte.' . urlencode($endDate),
    'order=vstdate.desc,total_qty.desc',
];
if ($selectedHc !== '') {
    $queryParts[] = 'hcode=eq.' . urlencode($selectedHc);
}
if ($selectedGroup !== '') {
    $queryParts[] = 'drug_type_name=eq.' . urlencode($selectedGroup);
}

$summaryRows = rxcore_fetch_all_rows('v_rx_drug_dispense_summary', implode('&', $queryParts));

$byDrug = [];
$totalTx = 0;
$totalQty = 0.0;
$totalCost = 0.0;

foreach ($summaryRows as $r) {
    $tpu = (string)($r['tpu_code_list'] ?? '');
    $tx = (int)($r['dispense_tx_count'] ?? 0);
    $qty = (float)($r['total_qty'] ?? 0);
    $cost = (float)($r['total_cost_amount'] ?? 0);

    $totalTx += $tx;
    $totalQty += $qty;
    $totalCost += $cost;

    if (!isset($byDrug[$tpu])) {
        $byDrug[$tpu] = [
            'icode'          => $r['icode'] ?? '-',
            'tpu_code_list'  => $tpu,
            'drug_name'      => $r['drug_name'] ?? '-',
            'drug_type_name' => $r['drug_type_name'] ?? '',
            'unit'           => $r['unit'] ?? '',
            'tx_count'       => 0,
            'total_qty'      => 0.0,
            'total_cost'     => 0.0,
        ];
    }
    $byDrug[$tpu]['tx_count'] += $tx;
    $byDrug[$tpu]['total_qty'] += $qty;
    $byDrug[$tpu]['total_cost'] += $cost;
}

uasort($byDrug, fn($a, $b) => $b['total_cost'] <=> $a['total_cost']);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="rxcore_custom_report_' . $startDate . '_to_' . $endDate . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ลำดับ', 'ชื่อรายการยา', 'กลุ่มยา', 'จำนวนครั้ง', 'ปริมาณรวม', 'หน่วยนับ', 'มูลค่าต้นทุนรวม (บาท)']);
    $rowNo = 1;
    foreach ($byDrug as $d) {
        fputcsv($out, [
            $rowNo++,
            $d['drug_name'],
            $d['drug_type_name'],
            $d['tx_count'],
            number_format($d['total_qty'], 0, '.', ''),
            $d['unit'],
            number_format($d['total_cost'], 2, '.', ''),
        ]);
    }
    fclose($out);
    exit;
}

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <?php rxcore_render_report_nav('custom_range'); ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">รายงานสรุปการใช้ยาผู้ป่วยนอกตามช่วงวันที่</h1>
                <p class="page-description">สรุปปริมาณและมูลค่าต้นทุนการจ่ายยาผู้ป่วยนอก (OPD) ตามช่วงวันที่ หน่วยบริการ และกลุ่มยา</p>
            </div>
            <div class="d-flex gap-2 rx-no-print">
                <a href="<?= url('pages/report_custom.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="btn btn-rx-secondary btn-sm">
                    <i class="bi bi-download me-1"></i> ส่งออก CSV
                </a>
                <button type="button" class="btn btn-rx-secondary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> พิมพ์
                </button>
            </div>
        </div>

        <!-- Filter Panel -->
        <div class="rx-panel p-3 mb-4 rx-no-print">
            <form method="GET" action="<?= url('pages/report_custom.php') ?>" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label">ตั้งแต่วันที่</label>
                    <input type="date" name="start_date" class="form-control font-mono" value="<?= htmlspecialchars($startDate) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">ถึงวันที่</label>
                    <input type="date" name="end_date" class="form-control font-mono" value="<?= htmlspecialchars($endDate) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">หน่วยบริการ</label>
                    <select name="hcode" class="form-select" <?= (!is_admin() && !empty($user['hcode'])) ? 'disabled' : '' ?>>
                        <option value="">ทุกหน่วยบริการ</option>
                        <?php foreach ($healthCenters as $hc): ?>
                            <option value="<?= htmlspecialchars($hc['hcode']) ?>" <?= $selectedHc === $hc['hcode'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($hc['hcode'] . ' - ' . $hc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">กลุ่มยา</label>
                    <select name="drug_type" class="form-select">
                        <option value="">ทุกกลุ่มยา</option>
                        <?php foreach ($drugGroups as $grp): ?>
                            <option value="<?= htmlspecialchars($grp) ?>" <?= $selectedGroup === $grp ? 'selected' : '' ?>>
                                <?= htmlspecialchars($grp) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-rx-primary flex-grow-1">แสดงผล</button>
                    <a href="<?= url('pages/report_custom.php') ?>" class="btn btn-rx-secondary">รีเซ็ต</a>
                </div>
            </form>
        </div>

        <!-- Summary KPIs -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">จำนวนครั้งที่จ่ายยา</div>
                        <div class="stat-value"><?= number_format($totalTx) ?> <span class="fs-6 fw-normal text-muted">รายการ</span></div>
                    </div>
                    <div class="stat-icon primary"><i class="bi bi-receipt"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">จำนวนรายการยาที่มีการจ่าย</div>
                        <div class="stat-value"><?= number_format(count($byDrug)) ?> <span class="fs-6 fw-normal text-muted">ชนิดยา</span></div>
                    </div>
                    <div class="stat-icon warning"><i class="bi bi-capsule"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">มูลค่าต้นทุนรวม</div>
                        <div class="stat-value"><?= number_format($totalCost, 2) ?> <span class="fs-6 fw-normal text-muted">บาท</span></div>
                    </div>
                    <div class="stat-icon success"><i class="bi bi-calculator"></i></div>
                </div>
            </div>
        </div>

        <!-- Report Table -->
        <div class="rx-panel overflow-hidden">
            <div class="rx-panel-header">
                <span>สรุปปริมาณและมูลค่าการใช้ยารายตัว</span>
                <span class="small text-muted font-mono"><?= date('d/m/Y', strtotime($startDate)) ?> – <?= date('d/m/Y', strtotime($endDate)) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-rx">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 60px;">ลำดับ</th>
                            <th>ชื่อรายการยา</th>
                            <th style="width: 175px;">กลุ่มยา</th>
                            <th class="text-end">จำนวนครั้ง</th>
                            <th class="text-end">ปริมาณรวม</th>
                            <th class="text-center">หน่วยนับ</th>
                            <th class="text-end">มูลค่าต้นทุนรวม (บาท)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($byDrug)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="rx-empty-state">
                                        <i class="bi bi-bar-chart"></i>
                                        <div class="fw-medium mb-1">ไม่มีข้อมูลการจ่ายยาในช่วงเวลาที่เลือก</div>
                                        <div class="small">เมื่อมีการ Sync ข้อมูลจากหน่วยบริการแล้ว สามารถเรียกดูและส่งออกรายงานได้ที่นี่</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $rowNo = 1;
                            foreach ($byDrug as $d):
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
                                    <td class="text-end font-mono"><?= number_format($d['tx_count']) ?></td>
                                    <td class="text-end font-mono fw-semibold"><?= number_format($d['total_qty'], 0) ?></td>
                                    <td class="text-center text-secondary small"><?= htmlspecialchars($d['unit']) ?></td>
                                    <td class="text-end font-mono"><?= number_format($d['total_cost'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($byDrug)): ?>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="3" class="text-end">รวมทั้งสิ้น (<?= number_format(count($byDrug)) ?> รายการยา)</td>
                                <td class="text-end font-mono"><?= number_format($totalTx) ?></td>
                                <td class="text-end font-mono"><?= number_format($totalQty, 0) ?></td>
                                <td></td>
                                <td class="text-end font-mono text-rx-accent"><?= number_format($totalCost, 2) ?></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </main>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

