<?php
/**
 * RxCore — ตั้งค่าระบบ (System Settings & Live DB Status)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_admin();
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/moph_notify.php';
require_once dirname(__DIR__) . '/includes/fiscal_year.php';

$current_page = 'settings';
$page_title = 'ตั้งค่าระบบ';

$notifyNotice = null;
$fyNotice = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf()) {
        $notifyNotice = ['type' => 'danger', 'text' => 'โทเคนความปลอดภัยไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่'];
    } else {
        $fyAct = $_POST['fy_action'] ?? '';
        if ($fyAct === 'save_fy') {
            $be = (int)($_POST['fiscal_year'] ?? 0);
            $origBe = isset($_POST['orig_fiscal_year']) && $_POST['orig_fiscal_year'] !== '' ? (int)$_POST['orig_fiscal_year'] : null;
            $res = FiscalYearConfig::saveYear(
                $be,
                (string)($_POST['fy_label'] ?? ''),
                (string)($_POST['start_date'] ?? ''),
                (string)($_POST['end_date'] ?? ''),
                !empty($_POST['is_default']),
                $origBe
            );
            $fyNotice = $res['ok']
                ? ['type' => 'success', 'text' => "บันทึกปีงบประมาณ {$be} เรียบร้อยแล้ว"]
                : ['type' => 'danger', 'text' => $res['error']];
        } elseif ($fyAct === 'set_default_fy') {
            $be = (int)($_POST['fiscal_year'] ?? 0);
            $res = FiscalYearConfig::setDefault($be);
            $fyNotice = $res['ok']
                ? ['type' => 'success', 'text' => "กำหนดให้ปีงบประมาณ {$be} เป็นปีหลักบนหน้า Dashboard แล้ว"]
                : ['type' => 'danger', 'text' => $res['error']];
        } elseif ($fyAct === 'delete_fy') {
            $be = (int)($_POST['fiscal_year'] ?? 0);
            $res = FiscalYearConfig::deleteYear($be);
            $fyNotice = $res['ok']
                ? ['type' => 'success', 'text' => "ลบปีงบประมาณ {$be} เรียบร้อยแล้ว"]
                : ['type' => 'danger', 'text' => $res['error']];
        }

        $act = $_POST['moph_action'] ?? '';
        if ($act === 'save_moph') {
            $saved = MophNotifyService::saveConfig([
                'enabled'           => !empty($_POST['moph_enabled']),
                'client_key'        => $_POST['moph_client_key'] ?? '',
                'secret_key'        => $_POST['moph_secret_key'] ?? '',
                'notify_on_success' => !empty($_POST['notify_on_success']),
                'notify_on_failure' => !empty($_POST['notify_on_failure']),
                'daily_summary'     => !empty($_POST['daily_summary']),
            ]);
            $notifyNotice = $saved
                ? ['type' => 'success', 'text' => 'บันทึกการตั้งค่า MOPH Notify เรียบร้อยแล้ว']
                : ['type' => 'danger', 'text' => 'ไม่สามารถบันทึกไฟล์ config/moph_notify.json ได้'];
        } elseif ($act === 'test_moph') {
            $testMsg = "🔔 ทดสอบการเชื่อมต่อ MOPH Notify\nระบบ " . APP_NAME . " (" . HOSPITAL_NAME . ")\nเวลาทดสอบ: " . date('d/m/Y H:i:s') . " น.";
            $res = MophNotifyService::send(
                $testMsg,
                trim((string)($_POST['moph_client_key'] ?? '')) ?: null,
                trim((string)($_POST['moph_secret_key'] ?? '')) ?: null
            );
            $notifyNotice = $res['ok']
                ? ['type' => 'success', 'text' => "ส่งข้อความทดสอบเข้า MOPH Notify สำเร็จ (HTTP {$res['status']})"]
                : ['type' => 'danger', 'text' => 'ส่งไม่สำเร็จ: ' . $res['error']];
        } elseif ($act === 'send_daily_now') {
            $res = MophNotifyService::sendDailySummary(null, true);
            $notifyNotice = $res['ok']
                ? ['type' => 'success', 'text' => 'ส่งสรุปประจำวันเข้า MOPH Notify สำเร็จแล้ว']
                : ['type' => 'danger', 'text' => 'ส่งสรุปประจำวันไม่สำเร็จ: ' . $res['error']];
        } elseif ($act === 'check_pending_now') {
            $res = MophNotifyService::processPendingSyncLogs();
            $notifyNotice = $res['ok']
                ? ['type' => 'success', 'text' => $res['message']]
                : ['type' => 'danger', 'text' => $res['message']];
        }
    }
}

$fiscalYears = FiscalYearConfig::getAll();
$defaultFiscalYear = FiscalYearConfig::getDefault();

$mophCfg = MophNotifyService::getSafeConfig();
$pendingNotifyCount = SupabaseService::count('rx_sync_log', 'moph_notify_status=eq.pending&sync_status=neq.running');

$maskedDb = DatabaseConfig::getMaskedConfig();
$dbStatus = DatabaseConfig::getStatus();

$tableStats = [
    'rx_drug_lookup'       => ['label' => 'ทะเบียนยามาตรฐาน', 'count' => SupabaseService::count('rx_drug_lookup')],
    'rx_drug_cost_history' => ['label' => 'ประวัติต้นทุนยา', 'count' => SupabaseService::count('rx_drug_cost_history')],
    'rx_health_centers'    => ['label' => 'หน่วยบริการ / รพ.สต.', 'count' => SupabaseService::count('rx_health_centers')],
    'rx_dispense'          => ['label' => 'รายการจ่ายยาสะสม', 'count' => SupabaseService::count('rx_dispense', 'is_deleted=eq.false')],
    'rx_sync_log'          => ['label' => 'ประวัติการ Sync', 'count' => SupabaseService::count('rx_sync_log')],
    'rx_user_profiles'     => ['label' => 'บัญชีผู้ใช้งาน (Supabase)', 'count' => SupabaseService::count('rx_user_profiles')],
];

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <div class="page-header">
            <div>
                <h1 class="page-title">ตั้งค่าระบบ</h1>
                <p class="page-description">ตรวจสอบการเชื่อมต่อฐานข้อมูล Supabase Cloud และปริมาณข้อมูลปัจจุบัน</p>
            </div>
            <div>
                <a href="<?= url('pages/settings.php') ?>" class="btn btn-rx-secondary btn-sm">
                    <i class="bi bi-arrow-clockwise me-1"></i> ตรวจสอบสถานะใหม่
                </a>
            </div>
        </div>

        <div class="row g-3">
            <!-- จัดการปีงบประมาณ (ต.ค. - ก.ย.) -->
            <div class="col-12">
                <div class="rx-panel">
                    <div class="rx-panel-header">
                        <span><i class="bi bi-calendar-check me-2 text-rx-accent"></i>จัดการปีงบประมาณแสดงผลบน Dashboard (ปีงบราชการ ต.ค. – ก.ย.)</span>
                        <button type="button" class="btn btn-rx-primary btn-sm" onclick="openFyModal()">
                            <i class="bi bi-plus-lg me-1"></i> เพิ่มปีงบประมาณ
                        </button>
                    </div>
                    <div class="p-4 pb-3">
                        <?php if ($fyNotice !== null): ?>
                            <div class="rx-notice <?= htmlspecialchars($fyNotice['type']) ?> mb-3">
                                <div><?= htmlspecialchars($fyNotice['text']) ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="small text-secondary mb-3">
                            ปีงบประมาณหลักที่แสดงบนหน้า Dashboard ปัจจุบัน:
                            <strong class="text-rx-accent font-mono"><?= htmlspecialchars($defaultFiscalYear['label']) ?></strong>
                            <span class="font-mono text-muted">(<?= FiscalYearConfig::formatThaiDate($defaultFiscalYear['start_date']) ?> – <?= FiscalYearConfig::formatThaiDate($defaultFiscalYear['end_date']) ?>)</span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-rx">
                            <thead>
                                <tr>
                                    <th class="text-center" style="width: 110px;">ปีงบฯ (พ.ศ.)</th>
                                    <th>ชื่อแสดงผล</th>
                                    <th>วันเริ่มต้น</th>
                                    <th>วันสิ้นสุด</th>
                                    <th class="text-center">สถานะบน Dashboard</th>
                                    <th class="text-end" style="width: 240px;">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fiscalYears as $fy):
                                    $isDef = !empty($fy['is_default']);
                                ?>
                                    <tr>
                                        <td class="text-center font-mono fw-semibold"><?= (int)$fy['fiscal_year'] ?></td>
                                        <td class="fw-medium"><?= htmlspecialchars($fy['label']) ?></td>
                                        <td class="font-mono small"><?= FiscalYearConfig::formatThaiDate($fy['start_date']) ?> <span class="text-muted">(<?= htmlspecialchars($fy['start_date']) ?>)</span></td>
                                        <td class="font-mono small"><?= FiscalYearConfig::formatThaiDate($fy['end_date']) ?> <span class="text-muted">(<?= htmlspecialchars($fy['end_date']) ?>)</span></td>
                                        <td class="text-center">
                                            <?php if ($isDef): ?>
                                                <span class="rx-status active">ปีหลักที่แสดง</span>
                                            <?php else: ?>
                                                <span class="rx-status idle">สำรอง</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <?php if (!$isDef): ?>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                        <input type="hidden" name="fy_action" value="set_default_fy">
                                                        <input type="hidden" name="fiscal_year" value="<?= (int)$fy['fiscal_year'] ?>">
                                                        <button type="submit" class="btn btn-rx-secondary btn-sm py-1 px-2" title="กำหนดเป็นปีงบฯ หลักบน Dashboard">
                                                            <i class="bi bi-check2-circle me-1"></i>ตั้งเป็นหลัก
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-rx-secondary btn-sm py-1 px-2" title="แก้ไข"
                                                    onclick='openFyModal(<?= json_encode($fy, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if (count($fiscalYears) > 1): ?>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('ยืนยันลบปีงบประมาณ <?= (int)$fy['fiscal_year'] ?> ออกจากรายการ?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                        <input type="hidden" name="fy_action" value="delete_fy">
                                                        <input type="hidden" name="fiscal_year" value="<?= (int)$fy['fiscal_year'] ?>">
                                                        <button type="submit" class="btn btn-rx-secondary btn-sm py-1 px-2 text-danger" title="ลบ">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ตั้งค่า MOPH Notify (หมอพร้อม) -->
            <div class="col-12">
                <div class="rx-panel">
                    <div class="rx-panel-header">
                        <span><i class="bi bi-bell me-2 text-rx-accent"></i>แจ้งเตือน MOPH Notify (หมอพร้อม)</span>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($pendingNotifyCount > 0): ?>
                                <span class="rx-tag font-mono">รอแจ้งเตือน <?= number_format($pendingNotifyCount) ?> รอบ</span>
                            <?php endif; ?>
                            <span class="rx-status <?= $mophCfg['enabled'] ? 'online' : 'idle' ?>">
                                <?= $mophCfg['enabled'] ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?>
                            </span>
                        </div>
                    </div>
                    <div class="p-4">
                        <?php if ($notifyNotice !== null): ?>
                            <div class="rx-notice <?= htmlspecialchars($notifyNotice['type']) ?> mb-3">
                                <div><?= htmlspecialchars($notifyNotice['text']) ?></div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="<?= url('pages/settings.php') ?>" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="mophEnabled" name="moph_enabled" value="1" <?= $mophCfg['enabled'] ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-medium" for="mophEnabled">เปิดใช้งานการแจ้งเตือนผ่าน MOPH Notify</label>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Client Key <?= $mophCfg['has_client_key'] ? '<span class="text-rx-success small">(บันทึกแล้ว: ' . htmlspecialchars($mophCfg['client_key_masked']) . ')</span>' : '<span class="text-muted small">(ยังไม่ระบุ)</span>' ?></label>
                                <input type="password" name="moph_client_key" class="form-control font-mono" placeholder="เว้นว่างไว้หากใช้ Client Key เดิม">
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Secret Key <?= $mophCfg['has_secret_key'] ? '<span class="text-rx-success small">(บันทึกแล้ว: ' . htmlspecialchars($mophCfg['secret_key_masked']) . ')</span>' : '<span class="text-muted small">(ยังไม่ระบุ)</span>' ?></label>
                                <input type="password" name="moph_secret_key" class="form-control font-mono" placeholder="เว้นว่างไว้หากใช้ Secret Key เดิม">
                            </div>

                            <div class="col-12">
                                <label class="form-label d-block">เหตุการณ์ที่ต้องการให้แจ้งเตือน</label>
                                <div class="d-flex flex-wrap gap-4 mt-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="chkNotifySuccess" name="notify_on_success" value="1" <?= $mophCfg['notify_on_success'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="chkNotifySuccess">1. ผลการ Sync เมื่อสำเร็จ</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="chkNotifyFail" name="notify_on_failure" value="1" <?= $mophCfg['notify_on_failure'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="chkNotifyFail">1. ผลการ Sync เมื่อล้มเหลว / มีข้อผิดพลาด</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="chkDailySummary" name="daily_summary" value="1" <?= $mophCfg['daily_summary'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="chkDailySummary">2. สรุปประจำวันทั้งเครือข่าย (17:30 น.)</label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 d-flex flex-wrap gap-2 pt-2">
                                <button type="submit" name="moph_action" value="save_moph" class="btn btn-rx-primary btn-sm">
                                    <i class="bi bi-check2-circle me-1"></i> บันทึกการตั้งค่า
                                </button>
                                <button type="submit" name="moph_action" value="test_moph" class="btn btn-rx-secondary btn-sm">
                                    <i class="bi bi-send me-1"></i> ทดสอบส่งข้อความ
                                </button>
                                <button type="submit" name="moph_action" value="check_pending_now" class="btn btn-rx-secondary btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i> ตรวจและส่งผล Sync ที่ค้าง
                                </button>
                                <button type="submit" name="moph_action" value="send_daily_now" class="btn btn-rx-secondary btn-sm">
                                    <i class="bi bi-calendar-check me-1"></i> ส่งสรุปวันนี้ทันที
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- สถานะการเชื่อมต่อ Supabase -->
            <div class="col-12 col-lg-6">
                <div class="rx-panel h-100">
                    <div class="rx-panel-header">
                        <span>การเชื่อมต่อ Supabase Cloud</span>
                        <span class="rx-status <?= $dbStatus['connected'] ? 'online' : 'offline' ?>">
                            <?= htmlspecialchars($dbStatus['status_label']) ?>
                        </span>
                    </div>
                    <div class="p-4">
                        <div class="mb-3">
                            <label class="form-label">Supabase Project URL</label>
                            <input type="text" class="form-control font-mono" value="<?= htmlspecialchars($maskedDb['supabase_url']) ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Anon Key (Masked)</label>
                            <input type="text" class="form-control font-mono" value="<?= htmlspecialchars($maskedDb['supabase_anon_key']) ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Service Role Key (Masked)</label>
                            <input type="text" class="form-control font-mono" value="<?= htmlspecialchars($maskedDb['supabase_service_role_key']) ?>" readonly>
                        </div>
                        <div>
                            <label class="form-label">Schema / Prefix</label>
                            <input type="text" class="form-control font-mono" value="public (Prefix: rx_*)" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <!-- จำนวนข้อมูลจริงในแต่ละตาราง -->
            <div class="col-12 col-lg-6">
                <div class="rx-panel h-100">
                    <div class="rx-panel-header">
                        <span>จำนวนข้อมูลจริงในฐานข้อมูล</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-rx">
                            <thead>
                                <tr>
                                    <th>ตาราง (Table)</th>
                                    <th>คำอธิบาย</th>
                                    <th class="text-end">จำนวนแถว</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableStats as $tbl => $info): ?>
                                    <tr>
                                        <td class="rx-code"><?= htmlspecialchars($tbl) ?></td>
                                        <td class="small text-secondary"><?= htmlspecialchars($info['label']) ?></td>
                                        <td class="text-end font-mono fw-semibold"><?= number_format($info['count']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ข้อมูลสภาพแวดล้อมเซิร์ฟเวอร์ -->
            <div class="col-12">
                <div class="rx-panel">
                    <div class="rx-panel-header">
                        <span>สภาพแวดล้อมระบบ</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-rx">
                            <tbody>
                                <tr>
                                    <td class="text-secondary" style="width: 240px;">หน่วยงานหลัก</td>
                                    <td class="fw-medium"><?= htmlspecialchars(HOSPITAL_NAME) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-secondary">เวอร์ชันระบบ</td>
                                    <td class="rx-code"><?= htmlspecialchars(APP_VERSION) ?></td>
                                </tr>
                                <tr>
                                    <td class="text-secondary">PHP Runtime</td>
                                    <td class="rx-code"><?= htmlspecialchars(PHP_VERSION) ?> (<?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'CLI') ?>)</td>
                                </tr>
                                <tr>
                                    <td class="text-secondary">เขตเวลา (Timezone)</td>
                                    <td class="rx-code"><?= htmlspecialchars(date_default_timezone_get()) ?> (<?= date('Y-m-d H:i:s') ?>)</td>
                                </tr>
                                <tr>
                                    <td class="text-secondary">ผู้พัฒนา / ดูแลระบบ</td>
                                    <td>
                                        <div class="fw-medium">กฤษนัฐ สีมารัตนกูล &mdash; นักวิชาการคอมพิวเตอร์ปฏิบัติการ</div>
                                        <div class="small text-secondary">กลุ่มงานสุขภาพดิจิทัล โรงพยาบาลค้อวัง &middot; โทร. <span class="font-mono">045797161</span> &middot; <span class="font-mono">kwhosp@gmail.com</span></div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal เพิ่ม / แก้ไข ปีงบประมาณ -->
    <div class="modal fade" id="fyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="fy_action" value="save_fy">
                <input type="hidden" name="orig_fiscal_year" id="fy_orig_year" value="">

                <div class="modal-header py-3">
                    <h6 class="modal-title fw-bold mb-0" id="fyModalTitle">เพิ่มปีงบประมาณ</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">ปีงบประมาณ (พ.ศ.) <span class="text-danger">*</span></label>
                            <input type="number" name="fiscal_year" id="fy_year" min="2550" max="2650" class="form-control font-mono" required oninput="autoFillFyRange(this.value)">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">ชื่อแสดงผล</label>
                            <input type="text" name="fy_label" id="fy_label" class="form-control" placeholder="เช่น ปีงบประมาณ 2570">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">วันเริ่มต้น (1 ต.ค.) <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="fy_start" class="form-control font-mono" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">วันสิ้นสุด (30 ก.ย.) <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="fy_end" class="form-control font-mono" required>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_default" id="fy_is_default" value="1">
                                <label class="form-check-label" for="fy_is_default">ตั้งเป็นปีงบประมาณหลักที่แสดงบนหน้า Dashboard</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-rx-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-rx-primary btn-sm"><i class="bi bi-check-lg me-1"></i>บันทึกปีงบประมาณ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function autoFillFyRange(beStr) {
        var be = parseInt(beStr, 10);
        if (!be || be < 2550 || be > 2650) return;
        var orig = document.getElementById('fy_orig_year').value;
        if (orig !== '') return;
        var adEnd = be - 543;
        var adStart = adEnd - 1;
        document.getElementById('fy_label').value = 'ปีงบประมาณ ' + be;
        document.getElementById('fy_start').value = adStart + '-10-01';
        document.getElementById('fy_end').value = adEnd + '-09-30';
    }

    function openFyModal(fy) {
        if (fy) {
            document.getElementById('fyModalTitle').textContent = 'แก้ไขปีงบประมาณ ' + fy.fiscal_year;
            document.getElementById('fy_orig_year').value = fy.fiscal_year;
            document.getElementById('fy_year').value = fy.fiscal_year;
            document.getElementById('fy_label').value = fy.label || '';
            document.getElementById('fy_start').value = fy.start_date || '';
            document.getElementById('fy_end').value = fy.end_date || '';
            document.getElementById('fy_is_default').checked = Boolean(fy.is_default);
        } else {
            var nextYear = <?= (int)FiscalYearConfig::currentFiscalYearBE() ?>;
            document.getElementById('fyModalTitle').textContent = 'เพิ่มปีงบประมาณใหม่';
            document.getElementById('fy_orig_year').value = '';
            document.getElementById('fy_year').value = nextYear;
            document.getElementById('fy_is_default').checked = false;
            autoFillFyRange(nextYear);
        }
        new bootstrap.Modal(document.getElementById('fyModal')).show();
    }
    </script>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
