<?php
/**
 * RxCore Sync Agent — Test Real MOPH Notify Dispatch
 * ทดสอบส่งข้อความแจ้งเตือนจริงไปยัง MOPH Notify API
 */

$clientKey = 'b5e492673f60546a95df9636dfc5ea59a388bc25';
$secretKey = 'QJEXH3YDDHU2QYRGWDW4IY4QF4LQ';
$url       = 'https://morpromt2f.moph.go.th/api/notify/send';

require_once __DIR__ . '/agent_moph_notify.php';

$syncData = [
    'status'             => 'SUCCESS',
    'sync_type'          => 'INCREMENTAL_SYNC',
    'start_time'         => date('Y-m-d H:i:s', strtotime('-3 seconds')),
    'end_time'           => date('Y-m-d H:i:s'),
    'duration_sec'       => 2.45,
    'items_read'         => 12,
    'items_validated'    => 12,
    'items_sent_success' => 12,
    'items_duplicate'    => 0,
    'items_failed'       => 0,
    'retry_count'        => 0,
    'scheduler_slot'     => date('Y-m-d') . '@09:00',
];
$config = [
    'hcode'         => '01425',
    'facility_name' => 'รพ.สต. บ้านดอนทอง',
];
$built = AgentMophNotifyMessageBuilder::build($syncData, $config);
$messageText = $built['plain_text'];


$payload = json_encode([
    'messages' => [
        [
            'type' => 'text',
            'text' => $messageText,
        ]
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

echo "===============================================================\n";
echo "   MOPH Notify Real Dispatch Test                             \n";
echo "===============================================================\n";
echo "Endpoint URL: {$url}\n";
echo "Client Key  : " . substr($clientKey, 0, 6) . '...' . substr($clientKey, -4) . "\n";
echo "Payload     :\n{$payload}\n";
echo "---------------------------------------------------------------\n";
echo "กำลังส่ง Request ไปยัง MOPH Notify...\n";

$startTime = microtime(true);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
// รองรับ SSL บน Windows XAMPP ตาม guide
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'client-key: ' . $clientKey,
    'secret-key: ' . $secretKey,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
$duration = microtime(true) - $startTime;
curl_close($ch);

echo "HTTP Status Code : {$httpCode}\n";
echo "Execution Time   : " . number_format($duration, 3) . " วินาที\n";

if ($curlError) {
    echo "cURL Error ({$curlErrno}): {$curlError}\n";
    echo "ผลการทดสอบ: ❌ การเชื่อมต่อระดับ Network ล้มเหลว\n";
} else {
    echo "Response Body    : {$response}\n";
    if (in_array($httpCode, [200, 201])) {
        echo "\nผลการทดสอบ: ✅ ส่งแจ้งเตือนไปยัง MOPH Notify สำเร็จสมบูรณ์!\n";
    } else {
        echo "\nผลการทดสอบ: ⚠️ MOPH Notify ตอบกลับด้วย HTTP Code {$httpCode} (ไม่สำเร็จ)\n";
    }
}
echo "===============================================================\n";
