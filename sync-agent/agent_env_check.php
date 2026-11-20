<?php
/**
 * RxCore Sync Agent — Environment & Dependency Auditor
 * Phase 3.8.1: Windows Agent Packaging & Deployment Readiness
 * 
 * หน้าที่และข้อกำหนด:
 * 1. ตรวจสอบสภาพแวดล้อม PHP และ Extensions ที่จำเป็น:
 *    - openssl (AES-256-GCM AEAD encryption)
 *    - pdo & pdo_mysql (MySQL / MariaDB Read-only connectivity)
 *    - mbstring (UTF-8 & Thai character support)
 *    - json (API payloads & atomic state files)
 *    - curl (HTTP transport client)
 * 2. ตรวจสอบระบบปฏิบัติการและเวอร์ชัน:
 *    - ตรวจสอบความเข้ากันได้กับ Windows (Windows 10, 11, Server 2016/2019/2022)
 *    - ตรวจสอบและบันทึก PHP Version จริงที่ทดสอบ (PHP 8.2)
 * 3. ตรวจสอบการจัดการ Path บน Windows:
 *    - รองรับ Path ที่มีช่องว่าง (Spaces in directory paths)
 *    - รองรับ Path ที่มีภาษาไทย (Unicode & Thai characters)
 * 4. ตรวจสอบสิทธิ์การเข้าถึงไฟล์ (File Permissions & Security):
 *    - สิทธิ์การเขียนโฟลเดอร์ config/ และ logs/
 *    - การป้องกันการเข้าถึงตรงผ่าน .htaccess
 * 5. จัดทำรายงาน Audit Checklist ที่เป็นมาตรฐานสำหรับติดตั้งที่ รพ.สต.
 */

class AgentEnvironmentChecker {

    public const REQUIRED_PHP_MIN_VERSION = '8.1.0';

    public const REQUIRED_EXTENSIONS = [
        'openssl'   => 'จำเป็นสำหรับการเข้ารหัส AES-256-GCM (AEAD) จัดเก็บรหัสผ่านและ Credential',
        'pdo'       => 'จำเป็นสำหรับ Database Access Layer',
        'pdo_mysql' => 'จำเป็นสำหรับการเชื่อมต่อฐานข้อมูล HOSxP MySQL / MariaDB',
        'mbstring'  => 'จำเป็นสำหรับการจัดการข้อความภาษาไทยและ UTF-8 Multibyte',
        'json'      => 'จำเป็นสำหรับ Serialization ของ Mock State และ API Payloads',
        'curl'      => 'จำเป็นสำหรับ HTTP Transport Client (เมื่อเข้าสู่โหมดเชื่อมต่อ)',
    ];

    /**
     * ดำเนินการตรวจสอบสภาพแวดล้อมทั้งหมดแบบ Comprehensive Audit
     */
    public static function checkAll(?string $baseDir = null): array {
        $base = $baseDir ?? dirname(__DIR__);
        
        $phpCheck        = self::checkPhpVersion();
        $extensionCheck  = self::checkExtensions();
        $osCheck         = self::checkOperatingSystem();
        $pathCheck       = self::checkPathSupport($base);
        $permissionCheck = self::checkDirectoryPermissions($base);

        $allPassed = $phpCheck['passed']
            && $extensionCheck['all_passed']
            && $pathCheck['all_passed']
            && $permissionCheck['all_passed'];

        return [
            'success'               => $allPassed,
            'timestamp'             => date('Y-m-d H:i:s'),
            'php_version'           => $phpCheck,
            'extensions'            => $extensionCheck,
            'operating_system'      => $osCheck,
            'path_compatibility'    => $pathCheck,
            'directory_permissions' => $permissionCheck,
            'tested_runtime'        => [
                'php_version' => PHP_VERSION,
                'os_family'   => PHP_OS_FAMILY,
                'sapi_name'   => php_sapi_name(),
                'architecture'=> (PHP_INT_SIZE * 8) . '-bit',
            ],
        ];
    }

    /**
     * 1. ตรวจสอบเวอร์ชัน PHP
     */
    public static function checkPhpVersion(): array {
        $current = PHP_VERSION;
        $isCompatible = version_compare($current, self::REQUIRED_PHP_MIN_VERSION, '>=');

        return [
            'passed'          => $isCompatible,
            'current_version' => $current,
            'min_required'    => self::REQUIRED_PHP_MIN_VERSION,
            'detail'          => $isCompatible 
                ? "เวอร์ชัน PHP {$current} รองรับการทำงาน (ขั้นต่ำ: " . self::REQUIRED_PHP_MIN_VERSION . ")"
                : "เวอร์ชัน PHP {$current} ต่ำกว่าเกณฑ์ขั้นต่ำ (" . self::REQUIRED_PHP_MIN_VERSION . ")",
        ];
    }

    /**
     * 2. ตรวจสอบ Extensions ที่จำเป็น
     */
    public static function checkExtensions(): array {
        $results = [];
        $allPassed = true;

        foreach (self::REQUIRED_EXTENSIONS as $ext => $description) {
            $isLoaded = extension_loaded($ext);
            if (!$isLoaded) {
                $allPassed = false;
            }
            $results[$ext] = [
                'loaded'      => $isLoaded,
                'description' => $description,
            ];
        }

        return [
            'all_passed' => $allPassed,
            'details'    => $results,
        ];
    }

    /**
     * 3. ตรวจสอบระบบปฏิบัติการ
     */
    public static function checkOperatingSystem(): array {
        $isWindows = (PHP_OS_FAMILY === 'Windows');
        $osName = php_uname('s');
        $release = php_uname('r');
        $version = php_uname('v');

        return [
            'is_windows'    => $isWindows,
            'os_name'       => $osName,
            'os_release'    => $release,
            'os_version'    => $version,
            'compatibility' => $isWindows ? 'WINDOWS_NATIVE' : 'CROSS_PLATFORM_COMPATIBLE',
            'detail'        => "ตรวจพบระบบปฏิบัติการ: {$osName} ({$release})",
        ];
    }

    /**
     * 4. ตรวจสอบความเข้ากันได้ของ Path (รองรับช่องว่าง และอักขระภาษาไทย)
     */
    public static function checkPathSupport(string $testDirectory): array {
        $tests = [];

        // 4.1 ตรวจสอบ Path ปัจจุบัน
        $hasSpaces = (strpos($testDirectory, ' ') !== false);
        $tests['current_path_has_spaces'] = [
            'path'   => $testDirectory,
            'status' => true,
            'detail' => $hasSpaces ? 'Path ปัจจุบันมีช่องว่าง (ระบบรองรับได้)' : 'Path ปกติไม่มีช่องว่าง',
        ];

        // 4.2 ทดสอบสร้างและเข้าถึงโฟลเดอร์จำลองที่มีช่องว่าง
        $tempBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rxcore_test_' . uniqid();
        $spaceDir = $tempBase . DIRECTORY_SEPARATOR . 'Folder With Spaces';
        $spaceOk = false;
        try {
            if (!is_dir($spaceDir)) {
                mkdir($spaceDir, 0777, true);
            }
            $testFile = $spaceDir . DIRECTORY_SEPARATOR . 'test.txt';
            file_put_contents($testFile, "OK");
            $readBack = file_get_contents($testFile);
            $spaceOk = ($readBack === "OK");
            unlink($testFile);
            rmdir($spaceDir);
        } catch (\Throwable $e) {
            $spaceOk = false;
        }
        $tests['space_path_io'] = [
            'passed' => $spaceOk,
            'detail' => $spaceOk ? 'รองรับการอ่าน/เขียน Path ที่มีช่องว่างสมบูรณ์' : 'เกิดข้อผิดพลาดกับ Path ที่มีช่องว่าง',
        ];

        // 4.3 ทดสอบสร้างและเข้าถึงโฟลเดอร์จำลองที่มีภาษาไทย
        $thaiDir = $tempBase . DIRECTORY_SEPARATOR . 'ทดสอบ_รพสต_ดอนทอง';
        $thaiOk = false;
        try {
            if (!is_dir($thaiDir)) {
                mkdir($thaiDir, 0777, true);
            }
            $testFileThai = $thaiDir . DIRECTORY_SEPARATOR . 'ทดสอบ.txt';
            file_put_contents($testFileThai, "ภาษาไทย");
            $readBackThai = file_get_contents($testFileThai);
            $thaiOk = ($readBackThai === "ภาษาไทย");
            unlink($testFileThai);
            rmdir($thaiDir);
            if (is_dir($tempBase)) {
                @rmdir($tempBase);
            }
        } catch (\Throwable $e) {
            $thaiOk = false;
        }
        $tests['thai_unicode_path_io'] = [
            'passed' => $thaiOk,
            'detail' => $thaiOk ? 'รองรับการอ่าน/เขียน Path ภาษาไทย (Unicode) สมบูรณ์' : 'เกิดข้อผิดพลาดกับ Path ภาษาไทย',
        ];

        $allPathPassed = $spaceOk && $thaiOk;

        return [
            'all_passed' => $allPathPassed,
            'details'    => $tests,
        ];
    }

    /**
     * 5. ตรวจสอบสิทธิ์การเข้าถึงไฟล์และโฟลเดอร์สำคัญ
     */
    public static function checkDirectoryPermissions(string $baseDir): array {
        $dirs = [
            'config' => $baseDir . DIRECTORY_SEPARATOR . 'config',
            'logs'   => $baseDir . DIRECTORY_SEPARATOR . 'logs',
        ];

        $details = [];
        $allPassed = true;

        foreach ($dirs as $name => $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0700, true);
            }

            $isWritable = is_writable($path);
            $isReadable = is_readable($path);
            $hasHtaccess = file_exists($path . DIRECTORY_SEPARATOR . '.htaccess');

            if (!$isWritable || !$isReadable) {
                $allPassed = false;
            }

            $details[$name] = [
                'path'         => $path,
                'readable'     => $isReadable,
                'writable'     => $isWritable,
                'has_htaccess' => $hasHtaccess,
            ];
        }

        return [
            'all_passed' => $allPassed,
            'details'    => $details,
        ];
    }
}
