<?php
/**
 * RxCore — Global Top Header Component
 */
if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/app.php';
}
$user = current_user();
?>
<header class="app-header">
    <div class="d-flex align-items-center gap-3">
        <button class="btn btn-rx-secondary d-lg-none py-1 px-2" id="sidebarToggle" type="button" aria-label="เมนู">
            <i class="bi bi-list fs-5"></i>
        </button>
        <div class="d-flex align-items-center gap-2 small">
            <span class="text-muted d-none d-sm-inline"><?= htmlspecialchars(HOSPITAL_NAME) ?></span>
            <span class="text-muted d-none d-sm-inline">/</span>
            <span class="fw-semibold"><?= htmlspecialchars($page_title ?? 'ภาพรวม') ?></span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
        <!-- ปุ่มสลับโทนสีหน้าจอ (Aurora Pastel / Aurora Dark) -->
        <button type="button" class="btn btn-rx-secondary btn-sm d-flex align-items-center gap-2 py-1 px-2" id="rxThemeToggle" title="สลับโหมดสีพาสเทล / โหมดมืด">
            <i class="bi bi-sun" id="rxThemeIcon"></i>
            <span class="d-none d-md-inline" id="rxThemeLabel" style="font-size: 0.8rem;">โหมดพาสเทล</span>
        </button>

        <div class="dropdown">
            <button class="btn btn-rx-secondary btn-sm d-flex align-items-center gap-2 py-1 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i>
                <span class="d-none d-sm-inline" style="font-size: 0.82rem;"><?= htmlspecialchars($user['username'] ?? 'user') ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end mt-1">
                <li class="px-3 py-2">
                    <div class="fw-semibold" style="font-size: 0.88rem;"><?= htmlspecialchars($user['name'] ?? '') ?></div>
                    <div class="text-muted" style="font-size: 0.76rem;"><?= htmlspecialchars($user['hospital_name'] ?? HOSPITAL_NAME) ?></div>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <?php if (is_admin()): ?>
                    <li><a class="dropdown-item" href="<?= url('pages/settings.php') ?>"><i class="bi bi-sliders me-2"></i>ตั้งค่าระบบ</a></li>
                <?php endif; ?>
                <li><a class="dropdown-item text-danger" href="<?= url('pages/logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>ออกจากระบบ</a></li>
            </ul>
        </div>
    </div>
</header>
