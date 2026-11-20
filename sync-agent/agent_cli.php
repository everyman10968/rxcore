<?php
/**
 * RxCore Sync Agent — Command Line Interface (CLI) Runner
 * ใช้สำหรับรันคำสั่งบน Windows CLI, Windows Task Scheduler หรือ Auto-Sync เบื้องหลัง
 *
 * ตัวอย่างการใช้งาน:
 *   php agent_cli.php --help
 *   php agent_cli.php --status
 *   php agent_cli.php --test
 *   php agent_cli.php --sync --days=7 --reconcile
 *   php agent_cli.php --sync --start=2026-10-01 --end=2026-10-31
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: This script can only be run from the command line (CLI).\n");
}

date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/agent_config.php';
require_once __DIR__ . '/agent_connection.php';
require_once __DIR__ . '/agent_production_service.php';

$options = getopt('', ['help', 'test', 'status', 'sync', 'reconcile', 'days:', 'start:', 'end:', 'mode:']);

if (isset($options['help']) || empty($options)) {
    echo "====================================================\n";
    echo "  RxCore Sync Agent — CLI Runner\n";
    echo "====================================================\n\n";
    echo "Usage:\n";
    echo "  php agent_cli.php [options]\n\n";
    echo "Options:\n";
    echo "  --sync              Run production sync from HOSxP to Supabase Cloud\n";
    echo "  --days=<n>          Number of days ending today to sync (default: 7)\n";
    echo "  --start=<YYYY-MM-DD> Start date for --sync (overrides --days)\n";
    echo "  --end=<YYYY-MM-DD>   End date for --sync (default: today)\n";
    echo "  --reconcile         Enable Soft-Delete reconciliation for the synced range\n";
    echo "  --test              Run MySQL connection test using saved configuration\n";
    echo "  --status            Display current agent configuration status\n";
    echo "  --mode=<mode>       Force test mode: 'mock' or 'real' (optional)\n";
    echo "  --help              Show this help message\n\n";
    exit(0);
}

$configManager = new AgentConfig();

// Option: --status
if (isset($options['status'])) {
    echo "--- RxCore Sync Agent Status ---\n";
    if (!$configManager->hasConfig()) {
        echo "Status: No configuration file found.\n";
        echo "Please configure via Web UI or save settings first.\n";
        exit(1);
    }

    $safe = $configManager->getSafeConfig();
    echo "HOSxP Host:       " . $safe['hosxp_host'] . "\n";
    echo "HOSxP Port:       " . $safe['hosxp_port'] . "\n";
    echo "HOSxP Database:   " . $safe['hosxp_database'] . "\n";
    echo "HOSxP Username:   " . $safe['hosxp_username'] . "\n";
    echo "HCODE:            " . ($safe['hcode'] ?: '(not set)') . "\n";
    echo "Agent Mode:       " . $safe['agent_mode'] . "\n";
    echo "Password Saved:   " . ($safe['has_saved_password'] ? 'YES (Encrypted AES-256-GCM AEAD)' : 'NO') . "\n";
    echo "Sync Key Saved:   " . (!empty($safe['has_saved_sync_key']) ? 'YES (' . $safe['sync_api_key_masked'] . ')' : 'NO') . "\n";
    echo "Last Updated:     " . ($safe['updated_at'] ?: '-') . "\n";
    exit(0);
}

// Option: --sync (Production Sync for Windows Task Scheduler)
if (isset($options['sync'])) {
    @set_time_limit(0);

    $prod = new AgentProductionService($configManager);
    $cfg = $prod->resolveEffectiveConfig();

    $endDate = !empty($options['end']) ? trim((string)$options['end']) : date('Y-m-d');
    if (!empty($options['start'])) {
        $startDate = trim((string)$options['start']);
    } else {
        $days = isset($options['days']) ? max(1, (int)$options['days']) : 7;
        $offsetDays = $days - 1;
        $startDate = date('Y-m-d', strtotime("-{$offsetDays} days", strtotime($endDate)));
    }

    $reconcile = isset($options['reconcile']);
    $hcode = trim((string)($cfg['hcode'] ?? ''));

    echo "[" . date('Y-m-d H:i:s') . "] Starting RxCore Auto-Sync...\n";
    echo "HCODE:     " . ($hcode !== '' ? $hcode : '(not set)') . "\n";
    echo "Range:     {$startDate} .. {$endDate}\n";
    echo "Reconcile: " . ($reconcile ? 'YES' : 'NO') . "\n";

    $res = $prod->syncNow($cfg, $startDate, $endDate, $reconcile);
    $notifyRes = $prod->notifySyncResult($cfg, $startDate, $endDate, $res, true);

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
        @file_put_contents($logDir . '/.htaccess', "Deny from all\n");
    }
    $logFile = $logDir . '/agent_' . date('Y-m-d') . '.log';

    $notifySuffix = '';
    if (empty($notifyRes['skipped'])) {
        $notifySuffix = $notifyRes['ok']
            ? ' | MOPH Notify: SENT'
            : (' | MOPH Notify: FAILED (' . $notifyRes['error'] . ')');
    }

    if (!empty($res['success'])) {
        $synced  = (int)($res['inserted_or_updated'] ?? 0);
        $deleted = (int)($res['deleted'] ?? 0);
        $failed  = (int)($res['failed'] ?? 0);
        $dur     = (int)($res['duration_ms'] ?? 0);
        $line = sprintf(
            '%s | Auto-Sync CLI [%s..%s, hcode=%s, reconcile=%s] -> SUCCESS (synced=%d, deleted=%d, failed=%d, %dms)%s',
            date('Y-m-d H:i:s'),
            $startDate,
            $endDate,
            $hcode ?: '-',
            $reconcile ? 'yes' : 'no',
            $synced,
            $deleted,
            $failed,
            $dur,
            $notifySuffix
        );
        @file_put_contents($logFile, $line . "\n", FILE_APPEND);
        echo "[SUCCESS] Synced={$synced}, Deleted={$deleted}, Failed={$failed}, Duration={$dur} ms{$notifySuffix}\n";
        exit(0);
    } else {
        $err = (string)($res['error'] ?? 'Unknown error');
        $line = sprintf(
            '%s | Auto-Sync CLI [%s..%s, hcode=%s, reconcile=%s] -> FAILED: %s%s',
            date('Y-m-d H:i:s'),
            $startDate,
            $endDate,
            $hcode ?: '-',
            $reconcile ? 'yes' : 'no',
            $err,
            $notifySuffix
        );
        @file_put_contents($logFile, $line . "\n", FILE_APPEND);
        echo "[FAILED] {$err}{$notifySuffix}\n";
        exit(1);
    }
}

// Option: --test
if (isset($options['test'])) {
    echo "--- Running RxCore Sync Agent Connection Test ---\n";
    $config = $configManager->load();

    if (isset($options['mode']) && in_array($options['mode'], ['mock', 'real'])) {
        $config['agent_mode'] = $options['mode'];
    }

    echo "Mode:     " . strtoupper($config['agent_mode']) . "\n";
    echo "Target:   " . $config['hosxp_host'] . ":" . $config['hosxp_port'] . "/" . $config['hosxp_database'] . "\n";
    echo "User:     " . $config['hosxp_username'] . "\n";
    echo "HCODE:    " . ($config['hcode'] ?: '(empty)') . "\n";
    echo "Testing connection, please wait...\n\n";

    $connector = new AgentConnection();
    $result = $connector->testConnection($config);

    if ($result['success']) {
        echo "[SUCCESS] Connection established successfully!\n";
        echo "Server Version:  " . ($result['server_version'] ?? 'N/A') . "\n";
        echo "Database:        " . ($result['database'] ?? 'N/A') . "\n";
        echo "Read-only:       " . ($result['is_readonly'] ? 'YES (Strict Read-only verified from MySQL)' : 'NO') . "\n";
        echo "Privileges:      " . implode(', ', $result['privileges'] ?? []) . "\n";
        echo "Tables Found:    " . ($result['tables_found'] ?? 0) . "\n";
        echo "HOSxP Tables:    " . implode(', ', $result['hosxp_tables'] ?? []) . "\n";
        echo "Duration:        " . ($result['duration_ms'] ?? 0) . " ms\n";

        if (!empty($result['diagnostics']['queries_executed'])) {
            echo "\nPrivilege Audit Queries Executed:\n";
            foreach ($result['diagnostics']['queries_executed'] as $q) {
                echo " - {$q}\n";
            }
        }

        if (!empty($result['warnings'])) {
            echo "\nWarnings:\n";
            foreach ($result['warnings'] as $w) {
                echo " - {$w}\n";
            }
        }
        exit(0);
    } else {
        echo "[FAILED] Connection test failed or security violation!\n";
        echo "Error:    " . ($result['error'] ?? 'Unknown error') . "\n";
        if (!empty($result['prohibited'])) {
            echo "Prohibited Privileges Found: " . implode(', ', $result['prohibited']) . "\n";
        }
        echo "Duration: " . ($result['duration_ms'] ?? 0) . " ms\n";
        exit(1);
    }
}
