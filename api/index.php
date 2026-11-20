<?php
/**
 * RxCore — Server API Healthcheck & Sync Agent Registry Gateway
 */

header('Content-Type: application/json; charset=UTF-8');
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/supabase.php';

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'health'));

if ($action === 'drug_registry') {
    $rawInput = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $hcode  = trim((string)($rawInput['hcode'] ?? $_SERVER['HTTP_X_HCODE'] ?? ''));
    $apiKey = trim((string)($rawInput['api_key'] ?? $_SERVER['HTTP_X_SYNC_API_KEY'] ?? ''));

    if (!preg_match('/^\d{5}$/', $hcode) || $apiKey === '') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'ต้องระบุรหัสหน่วยบริการ (hcode) และ Sync API Key ที่ถูกต้อง',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $verify = SupabaseService::request('POST', 'rpc/fn_verify_health_center_api_key', [
        'p_hcode'         => $hcode,
        'p_plain_api_key' => $apiKey,
    ]);

    if (empty($verify['ok']) || $verify['data'] !== true) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Sync API Key ไม่ถูกต้อง หรือหน่วยบริการยังไม่ได้เปิดใช้งาน',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = SupabaseService::request(
        'GET',
        'rx_drug_lookup?select=icode,tpu_code_list,drug_name,drug_type_name,unit,is_active&is_active=eq.true&order=icode.asc.nullslast&limit=2000'
    );

    if (empty($res['ok'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error'   => 'ไม่สามารถดึงทะเบียนยาจากฐานข้อมูลกลางได้',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $drugs = [];
    foreach (($res['data'] ?? []) as $r) {
        $drugs[] = [
            'icode'          => isset($r['icode']) ? (int)$r['icode'] : null,
            'tpu_code_list'  => (string)($r['tpu_code_list'] ?? ''),
            'drugname'       => (string)($r['drug_name'] ?? ''),
            'drug_type_name' => (string)($r['drug_type_name'] ?? ''),
            'units'          => (string)($r['unit'] ?? ''),
            'is_active'      => true,
        ];
    }

    echo json_encode([
        'success' => true,
        'count'   => count($drugs),
        'drugs'   => $drugs,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$dbCheck = SupabaseService::request('GET', 'rx_drug_lookup?select=id&limit=1');

echo json_encode([
    'system'    => APP_NAME,
    'hospital'  => HOSPITAL_NAME,
    'status'    => $dbCheck['ok'] ? 'online' : 'degraded',
    'version'   => APP_VERSION,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
