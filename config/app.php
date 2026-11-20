<?php
/**
 * RxCore — Application Configuration & Initialization
 * Live Production Mode (Zero Mock Data)
 */

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
date_default_timezone_set('Asia/Bangkok');

function load_env($filePath) {
    if (!file_exists($filePath)) {
        return;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            $val = trim($val, "\"'");
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
load_env($envPath);

function env($key, $default = null) {
    $val = getenv($key);
    if ($val === false) {
        $val = $_ENV[$key] ?? $default;
    }
    if ($val === 'true' || $val === '(true)') return true;
    if ($val === 'false' || $val === '(false)') return false;
    if ($val === 'null' || $val === '(null)') return null;
    return $val;
}

define('APP_NAME', env('APP_NAME', 'RxCore'));
define('HOSPITAL_NAME', env('HOSPITAL_NAME', 'โรงพยาบาลค้อวัง'));
define('APP_VERSION', '3.0.1');
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', (bool)env('APP_DEBUG', false));
define('MOCK_MODE', false);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

function rx_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? 80) == 443) {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return false;
}

function get_base_url() {
    $customUrl = env('APP_URL');
    if (!empty($customUrl)) {
        return rtrim($customUrl, '/');
    }
    $protocol = rx_is_https() ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('#/(pages|api|includes).*$#i', '', $scriptDir);
    $basePath = rtrim($basePath, '/');
    return $protocol . $host . $basePath;
}

define('BASE_URL', get_base_url());

function url($path = '') {
    $cleanPath = ltrim($path, '/');
    return BASE_URL . ($cleanPath ? '/' . $cleanPath : '');
}

function asset($path = '') {
    return url('assets/' . ltrim($path, '/'));
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

function rx_app_secret(): string {
    $secret = (string)env('APP_SECRET', '');
    if ($secret !== '') {
        return $secret;
    }
    return hash('sha256', (string)env('SUPABASE_SERVICE_ROLE_KEY', '') . '|' . (string)env('ADMIN_PASSWORD', 'rxcore-default'));
}

function rx_set_auth_cookie(array $user): void {
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $payload = [
        'user' => $user,
        'exp'  => time() + (7 * 86400),
    ];
    $b64 = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, rx_app_secret());
    setcookie('rxcore_auth', $b64 . '.' . $sig, [
        'expires'  => time() + (7 * 86400),
        'path'     => '/',
        'secure'   => rx_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function rx_clear_auth_cookie(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    setcookie('rxcore_auth', '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => rx_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// กู้คืน Session จาก Signed Cookie (รองรับ Vercel Serverless ที่ไม่แชร์ไฟล์ Session บนดิสก์)
if (empty($_SESSION['user']['logged_in']) && !empty($_COOKIE['rxcore_auth'])) {
    $parts = explode('.', (string)$_COOKIE['rxcore_auth'], 2);
    if (count($parts) === 2) {
        [$b64, $sig] = $parts;
        $expectedSig = hash_hmac('sha256', $b64, rx_app_secret());
        if (hash_equals($expectedSig, $sig)) {
            $pad = strlen($b64) % 4;
            $rawJson = base64_decode(strtr($b64 . ($pad ? str_repeat('=', 4 - $pad) : ''), '-_', '+/'), true);
            $decoded = is_string($rawJson) ? json_decode($rawJson, true) : null;
            if (is_array($decoded) && !empty($decoded['user']['real_auth']) && (int)($decoded['exp'] ?? 0) > time()) {
                $_SESSION['user'] = $decoded['user'];
            }
        }
    }
}

// ล้างข้อมูลผู้ใช้จำลอง (Mock Session) จากเวอร์ชันเก่าออกทั้งหมด
if (isset($_SESSION['user']) && empty($_SESSION['user']['real_auth'])) {
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        $_SESSION['user'] = [
            'id'            => 'master-admin',
            'username'      => env('ADMIN_USERNAME', 'admin'),
            'name'          => env('ADMIN_DISPLAY_NAME', 'ผู้ดูแลระบบ'),
            'role'          => 'admin',
            'role_title'    => 'ผู้ดูแลระบบ',
            'hcode'         => '',
            'hospital_name' => HOSPITAL_NAME,
            'logged_in'     => true,
            'real_auth'     => true,
        ];
    } else {
        unset($_SESSION['user']);
    }
}

function csrf_token(): string {
    if (!empty($_SESSION['csrf_token'])) {
        return $_SESSION['csrf_token'];
    }
    if (!empty($_COOKIE['rxcore_csrf']) && preg_match('/^[a-f0-9]{64}$/', (string)$_COOKIE['rxcore_csrf'])) {
        $_SESSION['csrf_token'] = (string)$_COOKIE['rxcore_csrf'];
        return $_SESSION['csrf_token'];
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie('rxcore_csrf', $token, [
            'expires'  => time() + (7 * 86400),
            'path'     => '/',
            'secure'   => rx_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return $token;
}

function verify_csrf(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? ($_COOKIE['rxcore_csrf'] ?? '');
    return !empty($token) && !empty($expected) && hash_equals($expected, $token);
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function is_admin() {
    $user = current_user();
    return $user && ($user['role'] ?? '') === 'admin';
}

function is_logged_in() {
    return !empty($_SESSION['user']['logged_in']) && !empty($_SESSION['user']['real_auth']);
}
