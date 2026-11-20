<?php
/**
 * RxCore — Server-Side Supabase REST & Auth Client
 * ทำงานเฉพาะฝั่ง PHP Backend เท่านั้น ไม่เปิดเผย Service Role Key ไปยัง Browser
 */

require_once dirname(__DIR__) . '/config/app.php';

class SupabaseService {
    private static function baseUrl(): string {
        return rtrim((string)env('SUPABASE_URL', ''), '/');
    }

    private static function serviceKey(): string {
        $key = (string)env('SUPABASE_SERVICE_ROLE_KEY', '');
        return $key !== '' ? $key : (string)env('SUPABASE_ANON_KEY', '');
    }

    public static function isConfigured(): bool {
        $url = self::baseUrl();
        $key = self::serviceKey();
        return $url !== '' && $key !== '' && strpos($url, 'example-project') === false;
    }

    /**
     * เรียก Supabase PostgREST API
     * @return array{ok: bool, status: int, data: mixed, count: ?int, error: string}
     */
    public static function request(string $method, string $path, ?array $body = null, array $extraHeaders = []): array {
        if (!self::isConfigured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'count' => null, 'error' => 'ยังไม่ได้ตั้งค่า SUPABASE_URL หรือ Key ใน .env'];
        }

        $url = self::baseUrl() . '/rest/v1/' . ltrim($path, '/');
        $key = self::serviceKey();

        $headers = array_merge([
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$respHeaders) {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => $status, 'data' => null, 'count' => null, 'error' => $curlErr ?: 'เชื่อมต่อ Supabase ไม่สำเร็จ'];
        }

        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        $count = null;
        if (!empty($respHeaders['content-range']) && preg_match('#/(\d+)$#', $respHeaders['content-range'], $m)) {
            $count = (int)$m[1];
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'data' => $decoded, 'count' => $count, 'error' => ''];
        }

        $errMsg = is_array($decoded)
            ? ($decoded['message'] ?? $decoded['error_description'] ?? $decoded['msg'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE))
            : (string)$raw;

        return ['ok' => false, 'status' => $status, 'data' => $decoded, 'count' => null, 'error' => $errMsg];
    }

    public static function select(string $table, string $query = 'select=*'): array {
        $res = self::request('GET', $table . '?' . ltrim($query, '?'));
        return $res['ok'] && is_array($res['data']) ? $res['data'] : [];
    }

    public static function count(string $table, string $filter = ''): int {
        $q = 'select=id' . ($filter !== '' ? '&' . ltrim($filter, '&') : '');
        $res = self::request('GET', $table . '?' . $q, null, [
            'Prefer: count=exact',
            'Range-Unit: items',
            'Range: 0-0',
        ]);
        return $res['count'] ?? 0;
    }

    public static function insert(string $table, array $payload, string $onConflict = ''): array {
        $path = $table . ($onConflict !== '' ? '?on_conflict=' . urlencode($onConflict) : '');
        $prefer = $onConflict !== ''
            ? 'Prefer: return=representation,resolution=merge-duplicates'
            : 'Prefer: return=representation';
        return self::request('POST', $path, $payload, [$prefer]);
    }

    public static function update(string $table, string $filter, array $payload): array {
        return self::request('PATCH', $table . '?' . ltrim($filter, '?'), $payload, ['Prefer: return=representation']);
    }

    public static function delete(string $table, string $filter): array {
        return self::request('DELETE', $table . '?' . ltrim($filter, '?'));
    }

    public static function rpc(string $fnName, array $params = []): array {
        return self::request('POST', 'rpc/' . ltrim($fnName, '/'), $params);
    }

    /**
     * เรียก Supabase Auth API (สำหรับสร้างผู้ใช้จริง / ตรวจสอบรหัสผ่านจริง)
     */
    public static function authRequest(string $method, string $authPath, ?array $body = null, bool $useAnonKey = false): array {
        if (!self::isConfigured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Supabase not configured'];
        }
        $url = self::baseUrl() . '/auth/v1/' . ltrim($authPath, '/');
        $key = $useAnonKey ? (string)env('SUPABASE_ANON_KEY', '') : self::serviceKey();

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'apikey: ' . $key,
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => $status, 'data' => null, 'error' => $err];
        }
        $decoded = json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'data' => $decoded, 'error' => ''];
        }
        $msg = is_array($decoded) ? ($decoded['msg'] ?? $decoded['error_description'] ?? $decoded['message'] ?? 'Auth error') : (string)$raw;
        return ['ok' => false, 'status' => $status, 'data' => $decoded, 'error' => $msg];
    }
}

