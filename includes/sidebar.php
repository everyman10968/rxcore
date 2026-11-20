<?php
/**
 * RxCore — Global Sidebar Component
 */
if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/app.php';
}

$currentPage = $current_page ?? 'dashboard';
$user = current_user();
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <img src="<?= asset('img/favicon.svg') ?>?v=<?= urlencode(APP_VERSION) ?>" alt="<?= htmlspecialchars(APP_NAME) ?>" width="36" height="36" style="flex-shrink: 0;">
        <div>
            <h1 class="brand-title"><?= htmlspecialchars(APP_NAME) ?></h1>
            <p class="brand-subtitle"><?= htmlspecialchars(HOSPITAL_NAME) ?></p>
        </div>
    </div>

    <nav class="sidebar-nav">
        <p class="nav-section-title">ข้อมูลและรายงาน</p>

        <a href="<?= url('pages/dashboard.php') ?>" class="sidebar-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2"></i>
            <span>ภาพรวมระบบ</span>
        </a>

        <a href="<?= url('pages/reports.php') ?>" class="sidebar-link <?= ($currentPage === 'reports') ? 'active' : '' ?>">
            <i class="bi bi-file-earmark-bar-graph"></i>
            <span>รายงาน</span>
        </a>

        <a href="<?= url('pages/drugs.php') ?>" class="sidebar-link <?= ($currentPage === 'drugs') ? 'active' : '' ?>">
            <i class="bi bi-capsule"></i>
            <span>ทะเบียนยา</span>
        </a>

        <p class="nav-section-title">เครือข่ายและการเชื่อมต่อ</p>

        <a href="<?= url('pages/health_centers.php') ?>" class="sidebar-link <?= ($currentPage === 'health_centers') ? 'active' : '' ?>">
            <i class="bi bi-hospital"></i>
            <span>หน่วยบริการ / รพ.สต.</span>
        </a>

        <a href="<?= url('pages/sync_status.php') ?>" class="sidebar-link <?= ($currentPage === 'sync_status') ? 'active' : '' ?>">
            <i class="bi bi-arrow-repeat"></i>
            <span>สถานะการ Sync</span>
        </a>

        <?php if (is_admin()): ?>
            <p class="nav-section-title">ผู้ดูแลระบบ</p>

            <a href="<?= url('pages/users.php') ?>" class="sidebar-link <?= ($currentPage === 'users') ? 'active' : '' ?>">
                <i class="bi bi-people"></i>
                <span>ผู้ใช้งานและสิทธิ์</span>
            </a>

            <a href="<?= url('pages/settings.php') ?>" class="sidebar-link <?= ($currentPage === 'settings') ? 'active' : '' ?>">
                <i class="bi bi-sliders"></i>
                <span>ตั้งค่าระบบ</span>
            </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="overflow-hidden">
                <div class="text-truncate fw-medium" style="font-size: 0.84rem; color: #e2e8f0;">
                <div class="text-truncate sidebar-user-name">
                    <?= htmlspecialchars($user['name'] ?? 'ผู้ใช้งาน') ?>
                </div>
                <div class="text-truncate" style="font-size: 0.74rem; color: #7c8ca3;">
                <div class="text-truncate sidebar-user-role">
                    <?= is_admin() ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่หน่วยบริการ' ?>
                </div>
            </div>
            <a href="<?= url('pages/logout.php') ?>" class="btn btn-sm p-1 px-2" style="color: #9fb0c7;" title="ออกจากระบบ">
            <a href="<?= url('pages/logout.php') ?>" class="btn btn-sm p-1 px-2 sidebar-logout-btn" title="ออกจากระบบ">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>
