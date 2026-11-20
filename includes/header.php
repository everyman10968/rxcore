<?php
/**
 * RxCore — Global Header Component
 */
if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/app.php';
}
$title = isset($page_title) ? htmlspecialchars($page_title) . ' — ' . APP_NAME : APP_NAME . ' — ' . HOSPITAL_NAME;
?>
<!DOCTYPE html>
<html lang="th" data-rx-theme="aurora">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= asset('img/favicon.svg') ?>?v=<?= urlencode(APP_VERSION) ?>">
    <link rel="shortcut icon" href="<?= asset('img/favicon.svg') ?>?v=<?= urlencode(APP_VERSION) ?>">
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('rxcore_ui_theme') || 'aurora';
                document.documentElement.setAttribute('data-rx-theme', saved === 'aurora-dark' ? 'aurora-dark' : 'aurora');
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= asset('css/custom.css') ?>?v=<?= urlencode(APP_VERSION) ?>">
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="app-wrapper">
