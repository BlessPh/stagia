<?php
declare(strict_types=1);
require_once __DIR__ . '/ApiTestSupport.php';

$requested = array_slice($argv, 1);
$tests = $requested ?: ['contract', 'integration', 'authorization', 'capacity-concurrency'];
$failed = 0;

foreach ($tests as $test) {
    $file = __DIR__ . '/' . basename($test) . '.php';
    echo "\n=== {$test} ===\n";
    if (!is_file($file)) {
        echo "[FAIL] Test inconnu: {$test}\n";
        $failed++;
        continue;
    }
    $suite = new ApiTestSuite();
    try {
        require $file;
    } catch (ApiTestSkipped $e) {
        echo '[SKIP] ' . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        echo '[FAIL] ' . $e->getMessage() . "\n";
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
