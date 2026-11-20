<?php
/**
 * RxCore — หน่วยบริการ / รพ.สต. ในเครือข่าย (Health Centers Management)
 * เชื่อมต่อและจัดการข้อมูลจริงบน Supabase Cloud (ตาราง rx_health_centers)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_login();
require_once dirname(__DIR__) . '/includes/supabase.php';

$current_page = 'health_centers';
$page_title = 'หน่วยบริการ / รพ.สต.';

$flashMsg = '';
$flashType = 'success';
$generatedApiKeyInfo = null;

// ตรวจสอบว่าตาราง rx_health_centers มีคอลัมน์ facility_type แล้วหรือยัง
$probeHc = SupabaseService::select('rx_health_centers', 'select=*&limit=1');
$hasFacilityTypeCol = !empty($probeHc) && array_key_exists('facility_type', $probeHc[0]);

function rxcore_infer_facility_type(array $hc): string {
    if (!empty($hc['facility_type']) && in_array($hc['facility_type'], ['HOSPITAL', 'PCU'], true)) {
        return $hc['facility_type'];
    }
    $name = trim((string)($hc['name'] ?? ''));
    if (($hc['hcode'] ?? '') === '10968' || mb_strpos($name, 'โรงพยาบาล') === 0) {
        return 'HOSPITAL';
    }
    return 'PCU';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_admin()) {
    if (!verify_csrf()) {
        $flashMsg = 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง';
        $flashType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_hc') {
            $editId = (int)($_POST['id'] ?? 0);
            $hcode = trim($_POST['hcode'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $facilityType = ($_POST['facility_type'] ?? 'PCU') === 'HOSPITAL' ? 'HOSPITAL' : 'PCU';
            $subdistrict = trim($_POST['subdistrict'] ?? '');
            $district = trim($_POST['district'] ?? 'ค้อวัง');
            $province = trim($_POST['province'] ?? 'ยโสธร');
            $coordinator = trim($_POST['coordinator'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $population = max(0, (int)($_POST['population'] ?? 0));
            $isActive = isset($_POST['is_active']) && $_POST['is_active'] === '1';

            if (!preg_match('/^\d{5}$/', $hcode) || $name === '') {
                $flashMsg = 'รหัสหน่วยบริการ (HCODE) ต้องเป็นตัวเลข 5 หลัก และต้องระบุชื่อหน่วยบริการ';
                $flashType = 'danger';
            } else {
                $payload = [
                    'hcode'       => $hcode,
                    'name'        => $name,
                    'subdistrict' => $subdistrict !== '' ? $subdistrict : null,
                    'district'    => $district !== '' ? $district : null,
                    'province'    => $province !== '' ? $province : null,
                    'coordinator' => $coordinator !== '' ? $coordinator : null,
                    'phone'       => $phone !== '' ? $phone : null,
                    'population'  => $population,
                    'is_active'   => $isActive,
                    'updated_at'  => gmdate('c'),
                ];
                if ($hasFacilityTypeCol) {
                    $payload['facility_type'] = $facilityType;
                }

                if ($editId > 0) {
                    $res = SupabaseService::update('rx_health_centers', 'id=eq.' . $editId, $payload);
                    if ($res['ok']) {
                        $flashMsg = "อัปเดตข้อมูล {$name} ({$hcode}) เรียบร้อยแล้ว";
                    } else {
                        $flashMsg = 'อัปเดตไม่สำเร็จ: ' . $res['error'];
                        $flashType = 'danger';
                    }
                } else {
                    // สร้าง API Key จริงสำหรับ Sync Agent และเก็บเฉพาะ SHA-256 Hash ลงฐานข้อมูล
                    $plainKey = 'rxk_live_' . $hcode . '_' . bin2hex(random_bytes(16));
                    $payload['api_key_hash'] = hash('sha256', $plainKey);
                    $payload['api_key_prefix'] = 'rxk_live_' . $hcode . '_';

                    $res = SupabaseService::insert('rx_health_centers', $payload);
                    if ($res['ok']) {
                        $flashMsg = "เพิ่มหน่วยบริการ {$name} ({$hcode}) สำเร็จ";
                        $generatedApiKeyInfo = [
                            'hcode' => $hcode,
                            'name'  => $name,
                            'key'   => $plainKey,
                        ];
                    } else {
                        $flashMsg = 'เพิ่มหน่วยบริการไม่สำเร็จ: ' . $res['error'];
                        $flashType = 'danger';
                    }
                }
            }
        } elseif ($action === 'regenerate_key') {
            $targetId = (int)($_POST['id'] ?? 0);
            $hcode = trim($_POST['hcode'] ?? '');
            $name = trim($_POST['name'] ?? '');
            if ($targetId > 0 && preg_match('/^\d{5}$/', $hcode)) {
                $plainKey = 'rxk_live_' . $hcode . '_' . bin2hex(random_bytes(16));
                $res = SupabaseService::update('rx_health_centers', 'id=eq.' . $targetId, [
                    'api_key_hash'   => hash('sha256', $plainKey),
                    'api_key_prefix' => 'rxk_live_' . $hcode . '_',
                    'updated_at'     => gmdate('c'),
                ]);
                if ($res['ok']) {
                    $flashMsg = "ออก API Key ใหม่สำหรับ {$name} ({$hcode}) สำเร็จ";
                    $generatedApiKeyInfo = [
                        'hcode' => $hcode,
                        'name'  => $name,
                        'key'   => $plainKey,
                    ];
                } else {
                    $flashMsg = 'ไม่สามารถออก API Key ใหม่ได้: ' . $res['error'];
                    $flashType = 'danger';
                }
            }
        }
    }
}

$centers = SupabaseService::select('rx_health_centers', 'select=*&order=hcode.asc');
foreach ($centers as &$cRef) {
    $cRef['_type'] = rxcore_infer_facility_type($cRef);
}
unset($cRef);

// เรียงโรงพยาบาล (รพ.) ไว้บนสุด แล้วตามด้วย รพ.สต. เรียงตามรหัส hcode
usort($centers, function ($a, $b) {
    $ta = $a['_type'] === 'HOSPITAL' ? 0 : 1;
    $tb = $b['_type'] === 'HOSPITAL' ? 0 : 1;
    if ($ta !== $tb) {
        return $ta <=> $tb;
    }
    return strcmp((string)$a['hcode'], (string)$b['hcode']);
});

// คำนวณยอดจ่ายยาวันนี้ของแต่ละหน่วยบริการจากข้อมูลจริง
$today = date('Y-m-d');
$todayDispenses = SupabaseService::select('rx_dispense', "select=hcode,qty&vstdate=eq.{$today}&is_deleted=eq.false");
$todayCountByHcode = [];
foreach ($todayDispenses as $row) {
    $hc = $row['hcode'] ?? '';
    if ($hc !== '') {
        $todayCountByHcode[$hc] = ($todayCountByHcode[$hc] ?? 0) + 1;
    }
}

$onlineCount = 0;
$activeUnits = 0;
$hospitalCount = 0;
$pcuCount = 0;
foreach ($centers as $c) {
    if ($c['_type'] === 'HOSPITAL') {
        $hospitalCount++;
    } else {
        $pcuCount++;
    }
    if (!empty($c['is_active'])) {
        $activeUnits++;
    }
    if (!empty($c['last_sync_at']) && (time() - strtotime($c['last_sync_at'])) <= 3 * 86400) {
        $onlineCount++;
    }
}

require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once dirname(__DIR__) . '/includes/navbar.php'; ?>

    <main class="page-container">
        <?php if ($flashMsg !== ''): ?>
            <div class="rx-notice <?= htmlspecialchars($flashType) ?>">
                <div><i class="bi <?= $flashType === 'success' ? 'bi-check-circle' : 'bi-exclamation-circle' ?> me-2"></i><?= htmlspecialchars($flashMsg) ?></div>
                <button type="button" class="btn-close btn-sm" onclick="this.parentElement.remove()"></button>
            </div>
        <?php endif; ?>

        <?php if ($generatedApiKeyInfo !== null): ?>
            <div class="rx-panel p-3 mb-4" style="border-color: var(--rx-accent);">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="fw-semibold mb-1">
                            <i class="bi bi-key-fill text-info me-2"></i>Sync API Key สำหรับ <?= htmlspecialchars($generatedApiKeyInfo['name']) ?> (<?= htmlspecialchars($generatedApiKeyInfo['hcode']) ?>)
                        </div>
                        <div class="small text-muted mb-2">กรุณาคัดลอกคีย์นี้ไปตั้งค่าใน Sync Agent ทันที ระบบเก็บเฉพาะค่า Hash จึงจะไม่แสดงคีย์เต็มอีกหลังจากปิดหน้านี้</div>
                        <div class="d-flex align-items-center gap-2">
                            <input type="text" id="newPlainApiKey" class="form-control font-mono" style="max-width: 480px;" value="<?= htmlspecialchars($generatedApiKeyInfo['key']) ?>" readonly>
                            <button type="button" class="btn btn-rx-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('newPlainApiKey').value); window.rxToast('คัดลอกแล้ว', 'คัดลอก API Key ลงคลิปบอร์ดเรียบร้อย', 'success');">
                                <i class="bi bi-copy me-1"></i> คัดลอก
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-title">หน่วยบริการ / รพ.สต.</h1>
                <p class="page-description">จัดการรายชื่อสถานพยาบาลในเครือข่ายและกุญแจเชื่อมต่อ Sync Agent</p>
            </div>
            <?php if (is_admin()): ?>
                <div>
                    <button type="button" class="btn btn-rx-primary btn-sm" onclick="openAddHcModal()">
                        <i class="bi bi-plus-lg me-1"></i> เพิ่มหน่วยบริการ
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$hasFacilityTypeCol && is_admin()): ?>
            <div class="rx-notice warning">
                <div><i class="bi bi-info-circle me-2"></i>ยังไม่ได้เพิ่มคอลัมน์ <span class="font-mono">facility_type</span> ใน Supabase กรุณานำไฟล์ <span class="font-mono">database/migration_dashboard_v2.sql</span> ไปรันใน SQL Editor เพื่อบันทึกประเภทหน่วยบริการหลัก</div>
            </div>
        <?php endif; ?>

        <!-- KPI Row -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">หน่วยบริการที่ลงทะเบียน</div>
                        <div class="stat-value"><?= count($centers) ?> <span class="fs-6 fw-normal text-muted">แห่ง</span></div>
                        <div class="stat-sub">รพ. <?= $hospitalCount ?> แห่ง &middot; รพ.สต. <?= $pcuCount ?> แห่ง</div>
                    </div>
                    <div class="stat-icon primary"><i class="bi bi-hospital"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">เปิดใช้งานระบบ</div>
                        <div class="stat-value"><?= $activeUnits ?> <span class="fs-6 fw-normal text-muted">แห่ง</span></div>
                        <div class="stat-sub">พร้อมรับข้อมูลจาก Sync Agent</div>
                    </div>
                    <div class="stat-icon success"><i class="bi bi-check2-circle"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card">
                    <div>
                        <div class="stat-label">ออนไลน์ (ส่งข้อมูลไม่เกิน 3 วัน)</div>
                        <div class="stat-value"><?= $onlineCount ?> <span class="fs-6 fw-normal text-muted">แห่ง</span></div>
                        <div class="stat-sub">จากทั้งหมด <?= count($centers) ?> แห่ง</div>
                    </div>
                    <div class="stat-icon warning"><i class="bi bi-arrow-repeat"></i></div>
                </div>
            </div>
        </div>

        <!-- Health Centers Table -->
        <div class="rx-panel overflow-hidden">
            <div class="table-responsive">
                <table class="table table-rx">
                    <thead>
                        <tr>
                            <th style="width: 100px;">รหัส 5 หลัก</th>
                            <th style="width: 120px;">ประเภท</th>
                            <th>ชื่อหน่วยบริการ</th>
                            <th>ที่ตั้ง</th>
                            <th>ผู้ประสานงาน / โทรศัพท์</th>
                            <th class="text-end">จ่ายยาวันนี้</th>
                            <th>Sync ล่าสุด</th>
                            <th class="text-center">สถานะ</th>
                            <?php if (is_admin()): ?>
                                <th class="text-end" style="width: 130px;">จัดการ</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($centers)): ?>
                            <tr>
                                <td colspan="<?= is_admin() ? 9 : 8 ?>">
                                    <div class="rx-empty-state">
                                        <i class="bi bi-hospital"></i>
                                        <div class="fw-medium mb-1">ยังไม่มีข้อมูลหน่วยบริการในระบบ</div>
                                        <div class="small mb-3">เริ่มต้นใช้งานจริงโดยเพิ่ม รพ.แม่ข่าย หรือ รพ.สต. ในเครือข่ายเพื่อรับ API Key สำหรับ Sync Agent</div>
                                        <?php if (is_admin()): ?>
                                            <button type="button" class="btn btn-rx-primary btn-sm" onclick="openAddHcModal()">
                                                <i class="bi bi-plus-lg me-1"></i> เพิ่มหน่วยบริการแห่งแรก
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($centers as $hc):
                                $isActive = !empty($hc['is_active']);
                                $lastSync = $hc['last_sync_at'] ?? null;
                                $todayTx = $todayCountByHcode[$hc['hcode']] ?? 0;
                                $isHospital = ($hc['_type'] === 'HOSPITAL');
                            ?>
                                <tr>
                                    <td class="rx-code fw-semibold"><?= htmlspecialchars($hc['hcode']) ?></td>
                                    <td>
                                        <?php if ($isHospital): ?>
                                            <span class="rx-status active" style="font-size: 0.75rem;">รพ. (แม่ข่าย)</span>
                                        <?php else: ?>
                                            <span class="rx-tag">รพ.สต.</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?= htmlspecialchars($hc['name']) ?></div>
                                        <div class="rx-code small text-muted"><?= htmlspecialchars($hc['api_key_prefix'] ?? '') ?>••••</div>
                                    </td>
                                    <td class="text-secondary small">
                                        <?= htmlspecialchars(trim(($hc['subdistrict'] ? 'ต.' . $hc['subdistrict'] . ' ' : '') . ($hc['district'] ? 'อ.' . $hc['district'] : ''))) ?: '-' ?>
                                    </td>
                                    <td class="small">
                                        <div><?= htmlspecialchars($hc['coordinator'] ?: '-') ?></div>
                                        <?php if (!empty($hc['phone'])): ?>
                                            <div class="text-muted rx-code"><?= htmlspecialchars($hc['phone']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end font-mono"><?= number_format($todayTx) ?></td>
                                    <td class="small text-secondary">
                                        <?php if ($lastSync): ?>
                                            <?= date('d/m/Y H:i', strtotime($lastSync)) ?>
                                        <?php else: ?>
                                            <span class="text-muted">ยังไม่เคย Sync</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="rx-status <?= $isActive ? 'active' : 'inactive' ?>">
                                            <?= $isActive ? 'เปิดใช้' : 'ระงับ' ?>
                                        </span>
                                    </td>
                                    <?php if (is_admin()): ?>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <button type="button" class="btn btn-rx-secondary btn-sm py-1 px-2" title="แก้ไขข้อมูล"
                                                    onclick='openEditHcModal(<?= json_encode([
                                                        'id'            => $hc['id'],
                                                        'hcode'         => $hc['hcode'],
                                                        'name'          => $hc['name'],
                                                        'facility_type' => $hc['_type'],
                                                        'subdistrict'   => $hc['subdistrict'],
                                                        'district'      => $hc['district'],
                                                        'province'      => $hc['province'],
                                                        'coordinator'   => $hc['coordinator'],
                                                        'phone'         => $hc['phone'],
                                                        'population'    => $hc['population'],
                                                        'is_active'     => $isActive ? 1 : 0,
                                                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('ต้องการออก API Key ใหม่สำหรับ <?= htmlspecialchars(addslashes($hc['name'])) ?> หรือไม่? (คีย์เดิมจะใช้งานไม่ได้ทันที)');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                                    <input type="hidden" name="action" value="regenerate_key">
                                                    <input type="hidden" name="id" value="<?= (int)$hc['id'] ?>">
                                                    <input type="hidden" name="hcode" value="<?= htmlspecialchars($hc['hcode']) ?>">
                                                    <input type="hidden" name="name" value="<?= htmlspecialchars($hc['name']) ?>">
                                                    <button type="submit" class="btn btn-rx-secondary btn-sm py-1 px-2" title="ออก Sync API Key ใหม่">
                                                        <i class="bi bi-key"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <?php if (is_admin()): ?>
    <div class="modal fade" id="hcModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_hc">
                <input type="hidden" name="id" id="hc_id" value="0">

                <div class="modal-header py-3">
                    <h6 class="modal-title fw-bold mb-0" id="hcModalTitle">เพิ่มหน่วยบริการ</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">รหัสสถานพยาบาล 5 หลัก (HCODE) <span class="text-danger">*</span></label>
                            <input type="text" name="hcode" id="hc_hcode" maxlength="5" pattern="\d{5}" class="form-control font-mono" placeholder="เช่น 10968" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ประเภทหน่วยบริการหลัก <span class="text-danger">*</span></label>
                            <select name="facility_type" id="hc_facility_type" class="form-select" required>
                                <option value="PCU">รพ.สต.</option>
                                <option value="HOSPITAL">โรงพยาบาล (รพ.)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ชื่อหน่วยบริการ / รพ.สต. <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="hc_name" class="form-control" placeholder="เช่น โรงพยาบาลค้อวัง หรือ รพ.สต. ..." required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">ตำบล</label>
                            <input type="text" name="subdistrict" id="hc_subdistrict" class="form-control" placeholder="ตำบล">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">อำเภอ</label>
                            <input type="text" name="district" id="hc_district" class="form-control" value="ค้อวัง">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">จังหวัด</label>
                            <input type="text" name="province" id="hc_province" class="form-control" value="ยโสธร">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">ผู้ประสานงาน / ผู้รับผิดชอบ</label>
                            <input type="text" name="coordinator" id="hc_coordinator" class="form-control" placeholder="ชื่อ-นามสกุล">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">เบอร์โทรศัพท์</label>
                            <input type="text" name="phone" id="hc_phone" class="form-control font-mono" placeholder="045-xxx-xxx">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">ประชากรรับผิดชอบ</label>
                            <input type="number" min="0" name="population" id="hc_population" class="form-control font-mono" value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">สถานะการเชื่อมต่อ</label>
                            <select name="is_active" id="hc_active" class="form-select">
                                <option value="1">เปิดใช้งาน</option>
                                <option value="0">ระงับชั่วคราว</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-rx-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-rx-primary btn-sm"><i class="bi bi-check-lg me-1"></i>บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddHcModal() {
        document.getElementById('hcModalTitle').textContent = 'เพิ่มหน่วยบริการใหม่';
        document.getElementById('hc_id').value = '0';
        document.getElementById('hc_hcode').value = '';
        document.getElementById('hc_hcode').readOnly = false;
        document.getElementById('hc_facility_type').value = 'PCU';
        document.getElementById('hc_name').value = '';
        document.getElementById('hc_subdistrict').value = '';
        document.getElementById('hc_district').value = 'ค้อวัง';
        document.getElementById('hc_province').value = 'ยโสธร';
        document.getElementById('hc_coordinator').value = '';
        document.getElementById('hc_phone').value = '';
        document.getElementById('hc_population').value = '0';
        document.getElementById('hc_active').value = '1';
        new bootstrap.Modal(document.getElementById('hcModal')).show();
    }

    function openEditHcModal(hc) {
        document.getElementById('hcModalTitle').textContent = 'แก้ไขข้อมูลหน่วยบริการ';
        document.getElementById('hc_id').value = hc.id || 0;
        document.getElementById('hc_hcode').value = hc.hcode || '';
        document.getElementById('hc_hcode').readOnly = true;
        document.getElementById('hc_facility_type').value = hc.facility_type || 'PCU';
        document.getElementById('hc_name').value = hc.name || '';
        document.getElementById('hc_subdistrict').value = hc.subdistrict || '';
        document.getElementById('hc_district').value = hc.district || 'ค้อวัง';
        document.getElementById('hc_province').value = hc.province || 'ยโสธร';
        document.getElementById('hc_coordinator').value = hc.coordinator || '';
        document.getElementById('hc_phone').value = hc.phone || '';
        document.getElementById('hc_population').value = hc.population || 0;
        document.getElementById('hc_active').value = hc.is_active ? '1' : '0';
        new bootstrap.Modal(document.getElementById('hcModal')).show();
    }
    </script>
    <?php endif; ?>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
