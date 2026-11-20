<?php
/**
 * RxCore — Authentication & Access Control Helper
 */

require_once dirname(__DIR__) . '/config/app.php';

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . url('pages/login.php'));
        exit;
    }
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        $page_title = 'ไม่มีสิทธิ์เข้าถึง';
        require_once dirname(__DIR__) . '/includes/header.php';
        require_once dirname(__DIR__) . '/includes/sidebar.php';
        echo '<div class="app-main">';
        require_once dirname(__DIR__) . '/includes/navbar.php';
        echo '<div class="page-container">';
        echo '<div class="rx-panel p-4"><div class="d-flex align-items-center gap-3"><i class="bi bi-shield-lock fs-3 text-danger"></i><div><h5 class="mb-1">จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ (Admin)</h5><p class="text-muted mb-3">บัญชีผู้ใช้งานของคุณไม่มีสิทธิ์เข้าถึงส่วนจัดการนี้</p><a href="' . url('pages/dashboard.php') . '" class="btn btn-rx-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>กลับหน้าภาพรวม</a></div></div></div>';
        echo '</div>';
        require_once dirname(__DIR__) . '/includes/footer.php';
        exit;
    }
}
