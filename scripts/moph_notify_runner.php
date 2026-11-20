<?php
/**
 * RxCore — MOPH Notify CLI Runner (สำหรับ Windows Task Scheduler บนเครื่องแม่ข่าย)
 *
 * ตัวอย่างคำสั่ง:
 *   php scripts/moph_notify_runner.php --check-sync     (ตรวจ rx_sync_log ที่รอแจ้งเตือนแล้วส่ง MOPH Notify)
 *   php scripts/moph_notify_runner.php --daily-summary  (ส่งสรุปประจำวันทั้งเครือข่าย)
 *   php scripts/moph_notify_runner.php --test           (ทดสอบส่งข้อความ)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: CLI only.\n");
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/moph_notify.php';

$options = getopt('', ['check-sync', 'daily-summary', 'test', 'help']);

if (isset($options['help']) || empty($options)) {
    echo "RxCore MOPH Notify CLI Runner\n";
    echo "Options:\n";
    echo "  --check-sync      Check pending rx_sync_log entries and send notifications\n";
    echo "  --daily-summary   Send daily network summary notification\n";
    echo "  --test            Send test notification\n";
    exit(0);
}

$ts = date('Y-m-d H:i:s');

if (isset($options['test'])) {
    $res = MophNotifyService::send("🔔 ทดสอบ MOPH Notify จาก CLI ({$ts})");
    echo "[{$ts}] TEST -> " . ($res['ok'] ? "OK (HTTP {$res['status']})" : "FAILED: {$res['error']}") . "\n";
    exit($res['ok'] ? 0 : 1);
}

if (isset($options['check-sync'])) {
    $res = MophNotifyService::processPendingSyncLogs();
    echo "[{$ts}] CHECK_SYNC -> " . $res['message'] . "\n";
    exit($res['ok'] ? 0 : 1);
}

if (isset($options['daily-summary'])) {
    $res = MophNotifyService::sendDailySummary();
    echo "[{$ts}] DAILY_SUMMARY -> " . ($res['ok'] ? 'SENT OK' : 'SKIPPED/FAILED: ' . $res['error']) . "\n";
    exit($res['ok'] ? 0 : 1);
}
