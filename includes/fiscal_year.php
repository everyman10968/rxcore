<?php
/**
 * RxCore — Fiscal Year Manager (ปีงบประมาณราชการ ต.ค. – ก.ย.)
 * จัดการ เพิ่ม / แก้ไข / ลบ และกำหนดปีงบประมาณหลักที่แสดงบนหน้า Dashboard
 */

if (!defined('APP_NAME')) {
    require_once dirname(__DIR__) . '/config/app.php';
}
require_once __DIR__ . '/supabase.php';

class FiscalYearConfig {

    private const CONFIG_FILE = __DIR__ . '/../config/fiscal_years.json';
    private const DB_KEY = 'fiscal_years';

    /**
     * คำนวณปีงบประมาณปัจจุบันตามปฏิทินราชการไทย (ต.ค.-ธ.ค. = ปีถัดไป)
     */
    public static function currentFiscalYearBE(): int {
        $m = (int)date('n');
        $y = (int)date('Y');
        return ($m >= 10) ? ($y + 544) : ($y + 543);
    }

    /**
     * คำนวณช่วงวันเริ่มต้น-สิ้นสุดมาตรฐานของปีงบประมาณไทย (1 ต.ค. - 30 ก.ย.)
     *
     * @return array{start_date: string, end_date: string}
     */
    public static function defaultRangeForBE(int $fiscalYearBE): array {
        $adEnd = $fiscalYearBE - 543;
        $adStart = $adEnd - 1;
        return [
            'start_date' => sprintf('%04d-10-01', $adStart),
            'end_date'   => sprintf('%04d-09-30', $adEnd),
        ];
    }

    /**
     * สร้างรายการปีงบฯ เริ่มต้น (ปีปัจจุบัน + ย้อนหลัง 2 ปี)
     */
    private static function buildInitialDefaults(): array {
        $curr = self::currentFiscalYearBE();
        $list = [];
        for ($i = 0; $i < 3; $i++) {
            $be = $curr - $i;
            $rng = self::defaultRangeForBE($be);
            $list[] = [
                'fiscal_year' => $be,
                'label'       => "ปีงบประมาณ {$be}",
                'start_date'  => $rng['start_date'],
                'end_date'    => $rng['end_date'],
                'is_default'  => ($i === 0),
            ];
        }
        return $list;
    }

    /**
     * ดึงรายการปีงบประมาณทั้งหมด (เรียงจากปีล่าสุดไปเก่าสุด)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAll(): array {
        $decoded = null;

        // 1. ลองอ่านจาก Supabase rx_app_settings ก่อน (รองรับ Vercel Serverless)
        if (SupabaseService::isConfigured()) {
            $res = SupabaseService::request('GET', 'rx_app_settings?select=value&key=eq.' . self::DB_KEY . '&limit=1');
            if ($res['ok'] && !empty($res['data'][0]['value']) && is_array($res['data'][0]['value'])) {
                $decoded = $res['data'][0]['value'];
            }
        }

        // 2. Fallback อ่านจากไฟล์ config/fiscal_years.json ในเครื่อง
        if ($decoded === null && file_exists(self::CONFIG_FILE)) {
            $raw = @file_get_contents(self::CONFIG_FILE);
            $fileDecoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($fileDecoded) && !empty($fileDecoded)) {
                $decoded = $fileDecoded;
            }
        }

        if (!is_array($decoded) || empty($decoded)) {
            $defaults = self::buildInitialDefaults();
            self::writeAll($defaults);
            return $defaults;
        }

        $clean = [];
        $hasDefault = false;
        foreach ($decoded as $item) {
            if (!is_array($item) || empty($item['fiscal_year'])) {
                continue;
            }
            $be = (int)$item['fiscal_year'];
            $rng = self::defaultRangeForBE($be);
            $isDef = !empty($item['is_default']);
            if ($isDef) {
                if ($hasDefault) {
                    $isDef = false;
                }
                $hasDefault = true;
            }
            $clean[$be] = [
                'fiscal_year' => $be,
                'label'       => trim((string)($item['label'] ?? "ปีงบประมาณ {$be}")) ?: "ปีงบประมาณ {$be}",
                'start_date'  => self::validDate((string)($item['start_date'] ?? '')) ?: $rng['start_date'],
                'end_date'    => self::validDate((string)($item['end_date'] ?? '')) ?: $rng['end_date'],
                'is_default'  => $isDef,
            ];
        }

        if (empty($clean)) {
            $defaults = self::buildInitialDefaults();
            self::writeAll($defaults);
            return $defaults;
        }

        krsort($clean);
        $items = array_values($clean);
        if (!$hasDefault) {
            $items[0]['is_default'] = true;
        }
        return $items;
    }

    /**
     * ดึงปีงบประมาณหลักที่กำหนดให้แสดงบนหน้า Dashboard
     */
    public static function getDefault(): array {
        $all = self::getAll();
        foreach ($all as $item) {
            if (!empty($item['is_default'])) {
                return $item;
            }
        }
        return $all[0];
    }

    public static function findByYear(int $fiscalYearBE): ?array {
        foreach (self::getAll() as $item) {
            if ((int)$item['fiscal_year'] === $fiscalYearBE) {
                return $item;
            }
        }
        return null;
    }

    /**
     * เพิ่มหรือแก้ไขปีงบประมาณ
     *
     * @return array{ok: bool, error: string}
     */
    public static function saveYear(int $fiscalYearBE, string $label, string $startDate, string $endDate, bool $setDefault = false, ?int $originalYearBE = null): array {
        if ($fiscalYearBE < 2550 || $fiscalYearBE > 2650) {
            return ['ok' => false, 'error' => 'ปีงบประมาณ (พ.ศ.) ต้องอยู่ระหว่าง 2550 – 2650'];
        }
        $sDate = self::validDate($startDate);
        $eDate = self::validDate($endDate);
        if ($sDate === null || $eDate === null) {
            return ['ok' => false, 'error' => 'รูปแบบวันที่เริ่มต้นและวันที่สิ้นสุดไม่ถูกต้อง'];
        }
        if ($sDate > $eDate) {
            return ['ok' => false, 'error' => 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด'];
        }

        $all = self::getAll();
        $map = [];
        $wasDefault = false;
        foreach ($all as $item) {
            $y = (int)$item['fiscal_year'];
            if ($originalYearBE !== null && $y === $originalYearBE) {
                $wasDefault = !empty($item['is_default']);
                continue;
            }
            if ($y === $fiscalYearBE && !empty($item['is_default'])) {
                $wasDefault = true;
            }
            $map[$y] = $item;
        }

        $finalDefault = $setDefault || $wasDefault;
        if ($finalDefault) {
            foreach ($map as &$m) {
                $m['is_default'] = false;
            }
            unset($m);
        }

        $cleanLabel = trim($label) !== '' ? trim($label) : "ปีงบประมาณ {$fiscalYearBE}";
        $map[$fiscalYearBE] = [
            'fiscal_year' => $fiscalYearBE,
            'label'       => $cleanLabel,
            'start_date'  => $sDate,
            'end_date'    => $eDate,
            'is_default'  => $finalDefault,
        ];

        krsort($map);
        $items = array_values($map);
        $hasAnyDef = false;
        foreach ($items as $it) {
            if (!empty($it['is_default'])) {
                $hasAnyDef = true;
                break;
            }
        }
        if (!$hasAnyDef && !empty($items)) {
            $items[0]['is_default'] = true;
        }

        if (!self::writeAll($items)) {
            return ['ok' => false, 'error' => 'ไม่สามารถบันทึกไฟล์ config/fiscal_years.json ได้'];
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * ตั้งปีงบประมาณที่เลือกเป็นค่าเริ่มต้นสำหรับหน้า Dashboard
     */
    public static function setDefault(int $fiscalYearBE): array {
        $all = self::getAll();
        $found = false;
        foreach ($all as &$item) {
            if ((int)$item['fiscal_year'] === $fiscalYearBE) {
                $item['is_default'] = true;
                $found = true;
            } else {
                $item['is_default'] = false;
            }
        }
        unset($item);

        if (!$found) {
            return ['ok' => false, 'error' => "ไม่พบปีงบประมาณ {$fiscalYearBE} ในรายการ"];
        }
        if (!self::writeAll($all)) {
            return ['ok' => false, 'error' => 'ไม่สามารถบันทึกการตั้งค่าได้'];
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * ลบปีงบประมาณ (ห้ามลบหากเหลือเพียง 1 ปี)
     */
    public static function deleteYear(int $fiscalYearBE): array {
        $all = self::getAll();
        if (count($all) <= 1) {
            return ['ok' => false, 'error' => 'ต้องเหลือปีงบประมาณอย่างน้อย 1 รายการในระบบ'];
        }

        $remaining = [];
        $deletedWasDefault = false;
        foreach ($all as $item) {
            if ((int)$item['fiscal_year'] === $fiscalYearBE) {
                $deletedWasDefault = !empty($item['is_default']);
                continue;
            }
            $remaining[] = $item;
        }

        if (count($remaining) === count($all)) {
            return ['ok' => false, 'error' => "ไม่พบปีงบประมาณ {$fiscalYearBE}"];
        }
        if ($deletedWasDefault && !empty($remaining)) {
            $remaining[0]['is_default'] = true;
        }
        if (!self::writeAll($remaining)) {
            return ['ok' => false, 'error' => 'ไม่สามารถบันทึกการลบได้'];
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * คืนค่ารายการ 12 เดือนตามลำดับปีงบประมาณไทย (ต.ค. - ก.ย.)
     *
     * @return array<int, array{ym: string, label: string}>
     */
    public static function getFiscalMonths(array $fy): array {
        $thaiShort = [
            1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
            5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
            9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
        ];
        $start = new DateTime(substr((string)$fy['start_date'], 0, 7) . '-01');
        $end   = new DateTime(substr((string)$fy['end_date'], 0, 7) . '-01');
        $months = [];
        $guard = 0;
        while ($start <= $end && $guard < 24) {
            $m = (int)$start->format('n');
            $yShort = (((int)$start->format('Y')) + 543) % 100;
            $months[] = [
                'ym'    => $start->format('Y-m'),
                'label' => ($thaiShort[$m] ?? $m) . ' ' . sprintf('%02d', $yShort),
            ];
            $start->modify('+1 month');
            $guard++;
        }
        return $months;
    }

    public static function formatThaiDate(string $isoDate): string {
        $thaiShort = [
            1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
            5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
            9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
        ];
        $ts = strtotime($isoDate);
        if ($ts === false) {
            return $isoDate;
        }
        return (int)date('j', $ts) . ' ' . ($thaiShort[(int)date('n', $ts)] ?? '') . ' ' . ((int)date('Y', $ts) + 543);
    }

    private static function validDate(string $d): ?string {
        $d = trim($d);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
    }

    private static function writeAll(array $items): bool {
        $clean = array_values($items);
        $dbSaved = false;

        if (SupabaseService::isConfigured()) {
            $res = SupabaseService::insert('rx_app_settings', [
                'key'        => self::DB_KEY,
                'value'      => $clean,
                'updated_at' => gmdate('c'),
            ], 'key');
            $dbSaved = !empty($res['ok']);
        }

        $fileSaved = false;
        $dir = dirname(self::CONFIG_FILE);
        if (is_writable($dir) || (!is_dir($dir) && @mkdir($dir, 0755, true))) {
            $fileSaved = @file_put_contents(
                self::CONFIG_FILE,
                json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ) !== false;
        }

        return $dbSaved || $fileSaved;
    }
}
