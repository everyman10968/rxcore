<?php
/**
 * RxCore Sync Agent — Portable PHP Runtime Bundler
 * คัดลอกเฉพาะไฟล์ PHP 8.2 ที่จำเป็นจาก XAMPP ไปไว้ใน dist/rxcore-sync-agent/runtime/php/
 * เพื่อให้เครื่อง รพ.สต. ที่ไม่มี XAMPP สามารถเปิด Web UI และรัน Auto-Sync ได้ทันที
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$sourcePhpDir = 'c:\\xampp\\php';
$caSource     = 'c:\\xampp\\apache\\bin\\curl-ca-bundle.crt';
$targetRuntime = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'rxcore-sync-agent' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'php';
$targetExtDir  = $targetRuntime . DIRECTORY_SEPARATOR . 'ext';

if (!is_dir($sourcePhpDir) || !file_exists($sourcePhpDir . DIRECTORY_SEPARATOR . 'php.exe')) {
    fwrite(STDERR, "[ERROR] ไม่พบ PHP ต้นทางที่ {$sourcePhpDir}\n");
    exit(1);
}

if (!is_dir($targetExtDir)) {
    mkdir($targetExtDir, 0755, true);
}

$rootFiles = [
    'php.exe',
    'php8ts.dll',
    'libcrypto-3-x64.dll',
    'libssl-3-x64.dll',
    'nghttp2.dll',
    'libssh2.dll',
    'libsodium.dll',
    'libsqlite3.dll',
];

$extFiles = [
    'php_curl.dll',
    'php_mbstring.dll',
    'php_mysqli.dll',
    'php_openssl.dll',
    'php_pdo_mysql.dll',
];

$copied = 0;
foreach ($rootFiles as $f) {
    $src = $sourcePhpDir . DIRECTORY_SEPARATOR . $f;
    $dst = $targetRuntime . DIRECTORY_SEPARATOR . $f;
    if (file_exists($src)) {
        copy($src, $dst);
        $copied++;
    } else {
        echo "[WARN] Missing root file: {$f}\n";
    }
}

foreach ($extFiles as $f) {
    $src = $sourcePhpDir . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . $f;
    $dst = $targetExtDir . DIRECTORY_SEPARATOR . $f;
    if (file_exists($src)) {
        copy($src, $dst);
        $copied++;
    } else {
        echo "[WARN] Missing extension: {$f}\n";
    }
}

if (file_exists($caSource)) {
    copy($caSource, $targetRuntime . DIRECTORY_SEPARATOR . 'cacert.pem');
    $copied++;
}

$phpIni = <<<INI
[PHP]
engine = On
short_open_tag = Off
max_execution_time = 0
memory_limit = 512M
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
display_errors = Off
log_errors = On
default_charset = "UTF-8"

extension_dir = "ext"
extension=curl
extension=mbstring
extension=mysqli
extension=openssl
extension=pdo_mysql

[Date]
date.timezone = "Asia/Bangkok"

[curl]
curl.cainfo = "runtime/php/cacert.pem"

[openssl]
openssl.cafile = "runtime/php/cacert.pem"
INI;

file_put_contents($targetRuntime . DIRECTORY_SEPARATOR . 'php.ini', $phpIni . "\r\n");

echo "[SUCCESS] Bundled Portable PHP into {$targetRuntime} ({$copied} files + php.ini)\n";
