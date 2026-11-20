<?php
/**
 * RxCore — สถานะการ Sync ข้อมูล (Sync Monitoring)
 * แสดงประวัติและสถานะจริงจากตาราง rx_health_centers และ rx_sync_log
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/supabase.php';

$current_page = 'sync_status';
$page_title = 'สถานะการ Sync';

$centers = SupabaseService::select('rx_health_centers', 'select=hcode,name,subdistrict,is_active,last_sync_at&order=hcode.asc');
$hcMap = [];
foreach ($centers as $c) {
    $hcMap[$c['hcode']] = $c['name'];
}

$logs = SupabaseService::select('rx_sync_log', 'select=*&order=sync_started_at.desc&limit=50');

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <div class="page-header">
            <div>
                <h1 class="page-title">สถานะการ Sync ข้อมูล</h1>
                <p class="page-description">ติดตามสถานะการส่งข้อมูลจาก Sync Agent ประจำหน่วยบริการและประวัติการทำงานล่าสุด</p>
            </div>
            <div>
                <a href="<?= url('pages/sync_status.php') ?>" class="btn btn-rx-secondary btn-sm">
                    <i class="bi bi-arrow-clockwise me-1"></i> รีเฟรช
                </a>
            </div>
        </div>

        <!-- 1. สถานะรายหน่วยบริการ -->
        <div class="rx-panel overflow-hidden mb-4">
            <div class="rx-panel-header">
                <span>สถานะการเชื่อมต่อรายหน่วยบริการ</span>
                <span class="small text-muted"><?= count($centers) ?> แห่ง</span>
            </div>
            <div class="table-responsive">
                <table class="table table-rx">
                    <thead>
                        <tr>
                            <th style="width: 105px;">HCODE</th>
                            <th>หน่วยบริการ</th>
                            <th>Sync ล่าสุด</th>
                            <th class="text-center" style="width: 140px;">สถานะปัจจุบัน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($centers)): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="rx-empty-state py-4">
                                        <i class="bi bi-hdd-network"></i>
                                        <div>ยังไม่มีหน่วยบริการที่ลงทะเบียน</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($centers as $hc):
                                $last = $hc['last_sync_at'] ?? null;
                                $diffSec = $last ? (time() - strtotime($last)) : null;
                                if (empty($hc['is_active'])) {
                                    $stClass = 'inactive';
                                    $stText = 'ระงับใช้งาน';
                                } elseif ($diffSec === null) {
                                    $stClass = 'idle';
                                    $stText = 'รอ Sync ครั้งแรก';
                                } elseif ($diffSec <= 3600) {
                                    $stClass = 'online';
                                    $stText = 'ปกติ';
                                } else {
                                    $stClass = 'warning';
                                    $stText = 'เกิน 1 ชม.';
                                }
                            ?>
                                <tr>
                                    <td class="rx-code fw-semibold"><?= htmlspecialchars($hc['hcode']) ?></td>
                                    <td>
                                        <span class="fw-medium"><?= htmlspecialchars($hc['name']) ?></span>
                                        <?php if (!empty($hc['subdistrict'])): ?>
                                            <span class="text-muted small ms-1">(ต.<?= htmlspecialchars($hc['subdistrict']) ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="font-mono small text-secondary">
                                        <?= $last ? date('d/m/Y H:i:s', strtotime($last)) : '-' ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="rx-status <?= $stClass ?>"><?= $stText ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. ประวัติการ Sync จริง -->
        <div class="rx-panel overflow-hidden">
            <div class="rx-panel-header">
                <span>ประวัติการ Sync ล่าสุด (50 รายการ)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-rx">
                    <thead>
                        <tr>
                            <th>เวลา</th>
                            <th>หน่วยบริการ</th>
                            <th class="text-end">ส่งทั้งหมด</th>
                            <th class="text-end">สำเร็จ</th>
                            <th class="text-end">ไม่ผ่าน</th>
                            <th class="text-end">ใช้เวลา</th>
                            <th class="text-center">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="rx-empty-state py-4">
                                        <i class="bi bi-journal-text"></i>
                                        <div class="fw-medium mb-1">ยังไม่มีประวัติการ Sync</div>
                                        <div class="small">ประวัติจะถูกบันทึกโดยอัตโนมัติเมื่อ Sync Agent ส่งข้อมูลเข้าสู่ระบบ</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log):
                                $st = $log['sync_status'] ?? 'running';
                                $badgeClass = $st === 'success' ? 'online' : ($st === 'partial' ? 'warning' : ($st === 'failed' ? 'offline' : 'idle'));
                                $badgeLabel = $st === 'success' ? 'สำเร็จ' : ($st === 'partial' ? 'บางส่วน' : ($st === 'failed' ? 'ล้มเหลว' : 'กำลังทำงาน'));
                            ?>
                                <tr>
                                    <td class="rx-code"><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['sync_started_at']))) ?></td>
                                    <td>
                                        <span class="fw-medium"><?= htmlspecialchars($hcMap[$log['hcode']] ?? $log['hcode']) ?></span>
                                        <span class="rx-code ms-1">(<?= htmlspecialchars($log['hcode']) ?>)</span>
                                    </td>
                                    <td class="text-end font-mono"><?= number_format((int)($log['total_records'] ?? 0)) ?></td>
                                    <td class="text-end font-mono text-success"><?= number_format((int)($log['success_records'] ?? 0)) ?></td>
                                    <td class="text-end font-mono <?= ((int)($log['failed_records'] ?? 0)) > 0 ? 'text-danger' : 'text-muted' ?>">
                                        <?= number_format((int)($log['failed_records'] ?? 0)) ?>
                                    </td>
                                    <td class="text-end font-mono text-secondary">
                                        <?= $log['duration_ms'] !== null ? number_format((int)$log['duration_ms']) . ' ms' : '-' ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="rx-status <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
