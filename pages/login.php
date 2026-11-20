<?php
/**
 * RxCore — เข้าสู่ระบบ (Real Authentication)
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/supabase.php';

if (is_logged_in()) {
    header('Location: ' . url('pages/dashboard.php'));
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้งานและรหัสผ่าน';
    } else {
        $masterUser = (string)env('ADMIN_USERNAME', 'admin');
        $masterPass = (string)env('ADMIN_PASSWORD', 'admin10968');

        // 1. ตรวจสอบบัญชีผู้ดูแลระบบหลักจาก .env
        if (strcasecmp($username, $masterUser) === 0 && hash_equals($masterPass, $password)) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'            => 'master-admin',
                'username'      => $masterUser,
                'name'          => env('ADMIN_DISPLAY_NAME', 'ผู้ดูแลระบบ'),
                'role'          => 'admin',
                'role_title'    => 'ผู้ดูแลระบบ',
                'hcode'         => '',
                'hospital_name' => HOSPITAL_NAME,
                'logged_in'     => true,
                'real_auth'     => true,
            ];
            rx_set_auth_cookie($_SESSION['user']);
            header('Location: ' . url('pages/dashboard.php'));
            exit;
        }

        // 2. ตรวจสอบผู้ใช้งานจริงผ่าน Supabase Auth + rx_user_profiles
        $email = strpos($username, '@') !== false ? strtolower($username) : (strtolower($username) . '@rxcore.local');
        $authRes = SupabaseService::authRequest('POST', 'token?grant_type=password', [
            'email'    => $email,
            'password' => $password,
        ], true);

        if ($authRes['ok'] && !empty($authRes['data']['user']['id'])) {
            $uid = $authRes['data']['user']['id'];
            $profiles = SupabaseService::select('rx_user_profiles', 'select=*&id=eq.' . urlencode($uid) . '&limit=1');
            $prof = $profiles[0] ?? null;

            if (!$prof || empty($prof['is_active'])) {
                $error = 'บัญชีผู้ใช้นี้ถูกระงับการใช้งาน หรือยังไม่ได้รับอนุญาต';
            } else {
                $hcName = HOSPITAL_NAME;
                if (!empty($prof['hcode'])) {
                    $hcs = SupabaseService::select('rx_health_centers', 'select=name&hcode=eq.' . urlencode($prof['hcode']) . '&limit=1');
                    if (!empty($hcs[0]['name'])) {
                        $hcName = $hcs[0]['name'];
                    }
                }

                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'            => $uid,
                    'username'      => $prof['username'],
                    'name'          => $prof['full_name'],
                    'role'          => $prof['role'],
                    'role_title'    => $prof['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'เจ้าหน้าที่หน่วยบริการ',
                    'hcode'         => $prof['hcode'] ?? '',
                    'hospital_name' => $hcName,
                    'logged_in'     => true,
                    'real_auth'     => true,
                ];
                rx_set_auth_cookie($_SESSION['user']);
                header('Location: ' . url('pages/dashboard.php'));
                exit;
            }
        } else {
            $error = 'ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}

$page_title = 'เข้าสู่ระบบ';
?>
<!DOCTYPE html>
<html lang="th" data-rx-theme="aurora">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
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
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        [data-rx-theme="aurora"] body {
            background: linear-gradient(135deg, #ecc9fc 0%, #cddcfa 50%, #baf5df 100%);
        }
        .login-box {
            width: 100%;
            max-width: 410px;
        }
        .login-card {
            position: relative;
            overflow: hidden;
        }
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--rx-aurora-horizontal);
        }
    </style>
</head>
<body>

<div class="login-box">
    <div class="rx-panel login-card p-4 p-sm-5">
        <div class="text-center mb-4">
            <img src="<?= asset('img/favicon.svg') ?>?v=<?= urlencode(APP_VERSION) ?>" alt="<?= htmlspecialchars(APP_NAME) ?>" width="54" height="54" class="mb-3">
            <h1 class="h4 fw-bold mb-1"><?= htmlspecialchars(APP_NAME) ?></h1>
            <p class="text-muted small mb-0"><?= htmlspecialchars(HOSPITAL_NAME) ?></p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="rx-notice danger mb-3">
                <div><i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form action="<?= url('pages/login.php') ?>" method="POST" autocomplete="on">
            <div class="mb-3">
                <label for="username" class="form-label">ชื่อผู้ใช้งาน (Username)</label>
                <input type="text" class="form-control" id="username" name="username" placeholder="ระบุชื่อผู้ใช้งาน" required autofocus>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">รหัสผ่าน (Password)</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-rx-primary w-100 py-2">
                เข้าสู่ระบบ
            </button>
        </form>
    </div>

    <div class="text-center mt-3 small text-muted">
        <div><?= htmlspecialchars(APP_NAME) ?> v<?= htmlspecialchars(APP_VERSION) ?></div>
        <div class="mt-1" style="font-size: 0.76rem; line-height: 1.45;">
            พัฒนาโดย กฤษนัฐ สีมารัตนกูล (นักวิชาการคอมพิวเตอร์ปฏิบัติการ)<br>
            กลุ่มงานสุขภาพดิจิทัล โรงพยาบาลค้อวัง &middot; โทร. 045797161 &middot; kwhosp@gmail.com
        </div>
    </div>
</div>

</body>
</html>
