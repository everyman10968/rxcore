<?php
/**
 * RxCore — ผู้ใช้งานและสิทธิ์ (User & Role Management)
 * จัดการบัญชีผู้ใช้งานจริงผ่าน Supabase Auth Admin API และตาราง rx_user_profiles
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_admin();
require_once dirname(__DIR__) . '/includes/supabase.php';

$current_page = 'users';
$page_title = 'ผู้ใช้งานและสิทธิ์';

$flashMsg = '';
$flashType = 'success';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf()) {
        $flashMsg = 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง';
        $flashType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_user') {
            $username = strtolower(trim($_POST['username'] ?? ''));
            $fullName = trim($_POST['full_name'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $hcode = trim($_POST['hcode'] ?? '');

            if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username) || $fullName === '' || strlen($password) < 6) {
                $flashMsg = 'กรุณาระบุ Username (ภาษาอังกฤษ/ตัวเลข 3 ตัวขึ้นไป), ชื่อ-นามสกุล และรหัสผ่านอย่างน้อย 6 ตัวอักษร';
                $flashType = 'danger';
            } else {
                $authEmail = strpos($username, '@') !== false ? $username : ($username . '@rxcore.local');
                $authRes = SupabaseService::authRequest('POST', 'admin/users', [
                    'email'         => $authEmail,
                    'password'      => $password,
                    'email_confirm' => true,
                    'user_metadata' => ['username' => $username, 'full_name' => $fullName],
                ]);

                if ($authRes['ok'] && !empty($authRes['data']['id'])) {
                    $uid = $authRes['data']['id'];
                    $profRes = SupabaseService::insert('rx_user_profiles', [
                        'id'        => $uid,
                        'username'  => $username,
                        'full_name' => $fullName,
                        'role'      => $role,
                        'hcode'     => $hcode !== '' ? $hcode : null,
                        'is_active' => true,
                    ]);

                    if ($profRes['ok']) {
                        $flashMsg = "สร้างบัญชีผู้ใช้งาน {$username} เรียบร้อยแล้ว";
                    } else {
                        // Rollback Auth user หากสร้าง Profile ไม่สำเร็จ
                        SupabaseService::authRequest('DELETE', 'admin/users/' . urlencode($uid));
                        $flashMsg = 'สร้างโปรไฟล์ไม่สำเร็จ: ' . $profRes['error'];
                        $flashType = 'danger';
                    }
                } else {
                    $flashMsg = 'สร้างบัญชีผู้ใช้ไม่สำเร็จ: ' . $authRes['error'];
                    $flashType = 'danger';
                }
            }
        } elseif ($action === 'update_user') {
            $uid = trim($_POST['id'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $hcode = trim($_POST['hcode'] ?? '');
            $isActive = ($_POST['is_active'] ?? '1') === '1';
            $newPassword = (string)($_POST['new_password'] ?? '');

            if ($uid !== '' && $fullName !== '') {
                $res = SupabaseService::update('rx_user_profiles', 'id=eq.' . urlencode($uid), [
                    'full_name'  => $fullName,
                    'role'       => $role,
                    'hcode'      => $hcode !== '' ? $hcode : null,
                    'is_active'  => $isActive,
                    'updated_at' => gmdate('c'),
                ]);

                if ($newPassword !== '') {
                    if (strlen($newPassword) >= 6) {
                        SupabaseService::authRequest('PUT', 'admin/users/' . urlencode($uid), ['password' => $newPassword]);
                    } else {
                        $flashMsg = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
                        $flashType = 'danger';
                    }
                }

                if ($flashType !== 'danger') {
                    if ($res['ok']) {
                        $flashMsg = 'อัปเดตข้อมูลผู้ใช้งานเรียบร้อยแล้ว';
                    } else {
                        $flashMsg = 'อัปเดตไม่สำเร็จ: ' . $res['error'];
                        $flashType = 'danger';
                    }
                }
            }
        }
    }
}

$profiles = SupabaseService::select('rx_user_profiles', 'select=*&order=created_at.desc');
$healthCenters = SupabaseService::select('rx_health_centers', 'select=hcode,name&order=hcode.asc');
$hcMap = [];
foreach ($healthCenters as $hc) {
    $hcMap[$hc['hcode']] = $hc['name'];
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

        <div class="page-header">
            <div>
                <h1 class="page-title">ผู้ใช้งานและสิทธิ์</h1>
                <p class="page-description">จัดการบัญชีผู้ใช้งานสำหรับผู้ดูแลระบบและเจ้าหน้าที่ประจำหน่วยบริการ</p>
            </div>
            <div>
                <button type="button" class="btn btn-rx-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="bi bi-person-plus me-1"></i> เพิ่มผู้ใช้งาน
                </button>
            </div>
        </div>

        <div class="rx-panel overflow-hidden">
            <div class="rx-panel-header">
                <span>บัญชีผู้ใช้งานทั้งหมด</span>
                <span class="small text-muted"><?= count($profiles) + 1 ?> บัญชี</span>
            </div>
            <div class="table-responsive">
                <table class="table table-rx">
                    <thead>
                        <tr>
                            <th>ชื่อ-นามสกุล</th>
                            <th>Username</th>
                            <th>สิทธิ์การใช้งาน</th>
                            <th>หน่วยบริการต้นสังกัด</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-end" style="width: 100px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- บัญชีผู้ดูแลระบบหลักจาก .env -->
                        <tr>
                            <td class="fw-medium"><?= htmlspecialchars(env('ADMIN_DISPLAY_NAME', 'ผู้ดูแลระบบหลัก')) ?></td>
                            <td class="rx-code"><?= htmlspecialchars(env('ADMIN_USERNAME', 'admin')) ?></td>
                            <td><span class="rx-tag">ผู้ดูแลระบบหลัก (System)</span></td>
                            <td class="text-secondary small"><?= htmlspecialchars(HOSPITAL_NAME) ?></td>
                            <td class="text-center"><span class="rx-status active">เปิดใช้</span></td>
                            <td class="text-end text-muted small">ตั้งค่าใน .env</td>
                        </tr>

                        <?php foreach ($profiles as $u):
                            $isActive = !empty($u['is_active']);
                            $hcCode = $u['hcode'] ?? '';
                        ?>
                            <tr>
                                <td class="fw-medium"><?= htmlspecialchars($u['full_name']) ?></td>
                                <td class="rx-code"><?= htmlspecialchars($u['username']) ?></td>
                                <td>
                                    <span class="rx-tag"><?= ($u['role'] === 'admin') ? 'ผู้ดูแลระบบ (Admin)' : 'เจ้าหน้าที่หน่วยบริการ (User)' ?></span>
                                </td>
                                <td class="text-secondary small">
                                    <?= $hcCode !== '' ? htmlspecialchars(($hcMap[$hcCode] ?? $hcCode) . " ({$hcCode})") : htmlspecialchars(HOSPITAL_NAME) ?>
                                </td>
                                <td class="text-center">
                                    <span class="rx-status <?= $isActive ? 'active' : 'inactive' ?>">
                                        <?= $isActive ? 'เปิดใช้' : 'ระงับ' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-rx-secondary btn-sm py-1 px-2"
                                        onclick='openEditUserModal(<?= json_encode([
                                            'id'        => $u['id'],
                                            'username'  => $u['username'],
                                            'full_name' => $u['full_name'],
                                            'role'      => $u['role'],
                                            'hcode'     => $hcCode,
                                            'is_active' => $isActive ? 1 : 0,
                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal สร้างผู้ใช้งานใหม่ -->
    <div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_user">

                <div class="modal-header py-3">
                    <h6 class="modal-title fw-bold mb-0">เพิ่มผู้ใช้งานใหม่</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="ระบุชื่อและนามสกุลจริง" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username สำหรับเข้าสู่ระบบ <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control font-mono" pattern="[A-Za-z0-9._-]{3,50}" placeholder="เช่น somchai.k" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รหัสผ่าน (อย่างน้อย 6 ตัวอักษร) <span class="text-danger">*</span></label>
                        <input type="password" name="password" minlength="6" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ระดับสิทธิ์</label>
                        <select name="role" class="form-select">
                            <option value="user">เจ้าหน้าที่หน่วยบริการ (User)</option>
                            <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">สังกัดหน่วยบริการ</label>
                        <select name="hcode" class="form-select">
                            <option value=""><?= htmlspecialchars(HOSPITAL_NAME) ?> (ส่วนกลาง)</option>
                            <?php foreach ($healthCenters as $hc): ?>
                                <option value="<?= htmlspecialchars($hc['hcode']) ?>"><?= htmlspecialchars($hc['hcode'] . ' - ' . $hc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-rx-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-rx-primary btn-sm"><i class="bi bi-check-lg me-1"></i>สร้างบัญชี</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal แก้ไขผู้ใช้งาน -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="id" id="eu_id">

                <div class="modal-header py-3">
                    <h6 class="modal-title fw-bold mb-0">แก้ไขผู้ใช้งาน (<span id="eu_username" class="font-mono"></span>)</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="eu_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ระดับสิทธิ์</label>
                        <select name="role" id="eu_role" class="form-select">
                            <option value="user">เจ้าหน้าที่หน่วยบริการ (User)</option>
                            <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สังกัดหน่วยบริการ</label>
                        <select name="hcode" id="eu_hcode" class="form-select">
                            <option value=""><?= htmlspecialchars(HOSPITAL_NAME) ?> (ส่วนกลาง)</option>
                            <?php foreach ($healthCenters as $hc): ?>
                                <option value="<?= htmlspecialchars($hc['hcode']) ?>"><?= htmlspecialchars($hc['hcode'] . ' - ' . $hc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ตั้งรหัสผ่านใหม่ (เว้นว่างหากไม่ต้องการเปลี่ยน)</label>
                        <input type="password" name="new_password" minlength="6" class="form-control" placeholder="ระบุเฉพาะกรณีเปลี่ยนรหัสผ่าน">
                    </div>
                    <div>
                        <label class="form-label">สถานะบัญชี</label>
                        <select name="is_active" id="eu_active" class="form-select">
                            <option value="1">เปิดใช้งาน</option>
                            <option value="0">ระงับการใช้งาน</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-rx-secondary btn-sm" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-rx-primary btn-sm">บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openEditUserModal(u) {
        document.getElementById('eu_id').value = u.id;
        document.getElementById('eu_username').textContent = u.username;
        document.getElementById('eu_name').value = u.full_name;
        document.getElementById('eu_role').value = u.role;
        document.getElementById('eu_hcode').value = u.hcode || '';
        document.getElementById('eu_active').value = u.is_active ? '1' : '0';
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }
    </script>

    <?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
