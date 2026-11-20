<?php
/**
 * RxCore — Database & Supabase Cloud Configuration
 */

require_once __DIR__ . '/app.php';
require_once dirname(__DIR__) . '/includes/supabase.php';

class DatabaseConfig {
    public static function getConfig() {
        return [
            'provider' => 'Supabase Cloud (PostgreSQL)',
            'supabase_url' => env('SUPABASE_URL', ''),
            'supabase_anon_key' => env('SUPABASE_ANON_KEY', ''),
            'supabase_service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY', ''),
            'db_schema' => env('DB_SCHEMA', 'public'),
        ];
    }

    public static function getMaskedConfig() {
        $cfg = self::getConfig();
        return [
            'provider' => $cfg['provider'],
            'supabase_url' => !empty($cfg['supabase_url']) ? $cfg['supabase_url'] : 'ยังไม่ได้กำหนด',
            'supabase_anon_key' => self::maskSecret($cfg['supabase_anon_key']),
            'supabase_service_role_key' => self::maskSecret($cfg['supabase_service_role_key']),
            'db_schema' => $cfg['db_schema'],
        ];
    }

    public static function maskSecret($value, $visibleLen = 6) {
        if (empty($value)) {
            return 'ยังไม่ได้กำหนด';
        }
        $len = strlen($value);
        if ($len <= ($visibleLen * 2)) {
            return str_repeat('•', $len);
        }
        return substr($value, 0, $visibleLen) . '••••••••' . substr($value, -$visibleLen);
    }

    public static function getStatus() {
        if (!SupabaseService::isConfigured()) {
            return [
                'connected' => false,
                'status_label' => 'ยังไม่ได้ตั้งค่า',
                'message' => 'กรุณาระบุ SUPABASE_URL และ SUPABASE_SERVICE_ROLE_KEY ในไฟล์ .env'
            ];
        }
        $res = SupabaseService::request('GET', 'rx_drug_lookup?select=id&limit=1');
        if ($res['ok']) {
            return [
                'connected' => true,
                'status_label' => 'เชื่อมต่อออนไลน์',
                'message' => 'เชื่อมต่อฐานข้อมูล Supabase Cloud พร้อมใช้งาน'
            ];
        }
        return [
            'connected' => false,
            'status_label' => 'เชื่อมต่อขัดข้อง',
            'message' => $res['error'] ?: 'ไม่สามารถเชื่อมต่อ Supabase Cloud ได้'
        ];
    }
}
