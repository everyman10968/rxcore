<?php
/**
 * RxCore — Vercel Cron Endpoint (ส่งสรุปประจำวันเข้า MOPH Notify เวลา 17:30 น.)
 * ตั้งเวลาใน vercel.json: "30 10 * * *" (UTC = 17:30 น. เวลาไทย)
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/moph_notify.php';

$cronSecret = (string)env('CRON_SECRET', '');
if ($cronSecret !== '') {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!hash_equals('Bearer ' . $cronSecret, $authHeader)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$res = MophNotifyService::sendDailySummary();
if (!$res['ok']) {
    http_response_code(500);
}

echo json_encode([
    'ok'        => $res['ok'],
    'timestamp' => date('Y-m-d H:i:s'),
    'error'     => $res['error'],
], JSON_UNESCAPED_UNICODE);
