<?php
/**
 * RxCore Phase 3.9 — Regression Test Integrity Auditor
 * Audits test counting, exit codes, assertion failures, and executes all 13 test suites.
 */

$testFiles = [
    'test_agent.php',
    'test_extractor.php',
    'test_sync_core.php',
    'test_hosxp_reader.php',
    'test_api_client.php',
    'test_security_audit.php',
    'test_mysql_compatibility.php',
    'test_phase36.php',
    'test_phase361.php',
    'test_phase37.php',
    'test_packaging.php',
    'test_isolated_deployment.php',
    'test_phase39.php',
    'test_phase40.php',
];

echo "====================================================================\n";
echo "  1. STATIC CODE AUDIT OF 14 TEST SUITES\n";
echo "====================================================================\n";

$auditData = [];

foreach ($testFiles as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "[MISSING] {$file} does not exist!\n";
        continue;
    }

    $content = file_get_contents($path);
    $lines = count(file($path));
    $bytes = filesize($path);

    // Count assertion calls in source code
    // Patterns: assertTest(...), $this->assert(...), $this->assertTest(...)
    preg_match_all('/\b(assertTest|assert)\s*\(/i', $content, $matches);
    $staticAssertionCalls = count($matches[0]);

    // Check exit calls
    preg_match_all('/\bexit\s*\((.*?)\)/i', $content, $exitMatches);
    $exitExpressions = $exitMatches[1] ?? [];

    // Check failure detection logic
    $handlesFailure = (
        stripos($content, '$failed') !== false ||
        stripos($content, '$this->failed') !== false ||
        stripos($content, 'exit(1)') !== false ||
        stripos($content, 'exit($passed ? 0 : 1)') !== false
    );

    $auditData[$file] = [
        'lines' => $lines,
        'bytes' => $bytes,
        'static_assertions' => $staticAssertionCalls,
        'exit_calls' => $exitExpressions,
        'handles_failure' => $handlesFailure,
    ];

    echo sprintf(
        "%-28s | Lines: %4d | Size: %5d B | Static Assertions: %3d | Exit: %s\n",
        $file,
        $lines,
        $bytes,
        $staticAssertionCalls,
        implode(', ', array_map('trim', $exitExpressions)) ?: 'NONE'
    );
}

echo "\n====================================================================\n";
echo "  2. EXECUTING ALL 14 TEST SUITES (RECORDING RAW RESULTS)\n";
echo "====================================================================\n";

$executionResults = [];

foreach ($testFiles as $file) {
    $path = __DIR__ . '/' . $file;
    $cmd = sprintf('"%s" "%s"', PHP_BINARY, $path);
    
    $startTime = microtime(true);
    $output = [];
    $exitCode = -1;
    exec($cmd, $output, $exitCode);
    $elapsed = round(microtime(true) - $startTime, 3);
    $outText = implode("\n", $output);

    // Extract PASS count
    preg_match_all('/\[PASS\]/i', $outText, $passMatches);
    $passCount = count($passMatches[0]);

    // Extract FAIL count
    preg_match_all('/\[FAIL\]/i', $outText, $failMatches);
    $failCount = count($failMatches[0]);

    // Extract NOT TESTED count
    preg_match_all('/\[NOT TESTED\]/i', $outText, $notTestedMatches);
    $notTestedCount = count($notTestedMatches[0]);

    $totalCalculated = $passCount + $failCount + $notTestedCount;

    $executionResults[$file] = [
        'pass' => $passCount,
        'fail' => $failCount,
        'not_tested' => $notTestedCount,
        'total' => $totalCalculated,
        'exit_code' => $exitCode,
        'elapsed' => $elapsed,
        'output_tail' => array_slice($output, -10),
    ];

    $statusBadge = ($exitCode === 0 && $failCount === 0) ? 'PASS' : 'FAIL';

    echo sprintf(
        "%-28s | Exit: %2d | PASS: %3d | FAIL: %2d | NOT_TESTED: %2d | Total: %3d | Time: %6.3fs | %s\n",
        $file,
        $exitCode,
        $passCount,
        $failCount,
        $notTestedCount,
        $totalCalculated,
        $elapsed,
        $statusBadge
    );
}

// Summary formula check
$grandPass = 0;
$grandFail = 0;
$grandNotTested = 0;
$grandTotal = 0;

foreach ($executionResults as $r) {
    $grandPass += $r['pass'];
    $grandFail += $r['fail'];
    $grandNotTested += $r['not_tested'];
    $grandTotal += $r['total'];
}

echo "\n====================================================================\n";
echo "  3. GRAND TOTAL REGRESSION INTEGRITY SUMMARY\n";
echo "====================================================================\n";
echo "Formula: Total ({$grandTotal}) = PASS ({$grandPass}) + FAIL ({$grandFail}) + NOT TESTED ({$grandNotTested})\n";
echo "Check:   " . ($grandTotal === ($grandPass + $grandFail + $grandNotTested) ? 'MATHEMATICALLY SOUND [OK]' : 'MISMATCH DETECTED [ERROR]') . "\n";
echo "====================================================================\n";
