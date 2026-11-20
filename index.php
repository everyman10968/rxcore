<?php
/**
 * RxCore — Main Entry Point & Router
 * Phase 1: Web App Foundation & Setup
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';

// หากยังไม่ได้ Login ให้นำทางไปยังหน้า Login
if (!is_logged_in()) {
    header('Location: ' . url('pages/login.php'));
    exit;
}

// Router อย่างง่ายสำหรับเรียกผ่าน ?page=xxx
$page = $_GET['page'] ?? 'dashboard';

$allowedPages = [
    'dashboard' => 'pages/dashboard.php',
    'drugs' => 'pages/drugs.php',
    'reports' => 'pages/reports.php',
    'report_compare' => 'pages/report_compare.php',
    'report_custom' => 'pages/report_custom.php',
    'report_prescriptions' => 'pages/report_prescriptions.php',
    'health_centers' => 'pages/health_centers.php',
    'sync_status' => 'pages/sync_status.php',
    'settings' => 'pages/settings.php',
    'users' => 'pages/users.php',
    'login' => 'pages/login.php',
    'logout' => 'pages/logout.php'
];

if (array_key_exists($page, $allowedPages)) {
    header('Location: ' . url($allowedPages[$page]));
    exit;
} else {
    header('Location: ' . url('pages/dashboard.php'));
    exit;
}
