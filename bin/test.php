<?php

declare(strict_types=1);

/**
 * Lightweight test runner (PHPUnit-style API without the dependency).
 *
 *   php bin/test.php            run everything under tests/
 *   php bin/test.php Feature    run classes whose file matches a substring
 */

require dirname(__DIR__) . '/bootstrap/app.php';

use Tests\TestCase;

$only = $argv[1] ?? '';
$files = [];
foreach (['Unit', 'Feature', 'Security'] as $dir) {
    foreach (glob(BASE_PATH . "/tests/{$dir}/*Test.php") ?: [] as $file) {
        if ($only === '' || stripos($file, $only) !== false || stripos(basename($file, '.php'), $only) !== false) {
            $files[] = [$dir, $file];
        }
    }
}

if ($files === []) {
    fwrite(STDERR, "No test files matched '{$only}'." . PHP_EOL);
    exit(1);
}

require BASE_PATH . '/tests/TestCase.php';

$passed = 0;
$failed = 0;
$totalAssertions = 0;
$start = microtime(true);

foreach ($files as [$dir, $file]) {
    require $file;
    $class = 'Tests\\' . $dir . '\\' . basename($file, '.php');
    if (!class_exists($class)) {
        fwrite(STDERR, "SKIP {$class}: class not found" . PHP_EOL);
        continue;
    }
    $instance = new $class();
    $methods = array_filter(
        get_class_methods($instance),
        fn ($m) => str_starts_with($m, 'test') && !(new ReflectionMethod($instance, $m))->isStatic()
    );

    foreach ($methods as $method) {
        try {
            // Fresh environment per test method (re-seeded DB is per-class).
            $instance->setUp();
            $instance->{$method}();
            $instance->tearDown();
            $passed++;
            $totalAssertions += $instance->assertions;
            print('.');
        } catch (\Throwable $e) {
            $failed++;
            print(PHP_EOL . "FAIL {$class}::{$method}" . PHP_EOL . '  ' . $e->getMessage() . PHP_EOL
                . '  at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
        }
    }
    print(PHP_EOL);
}

$elapsed = round((microtime(true) - $start) * 1000);
print(PHP_EOL . ($failed === 0 ? 'OK' : 'FAILURES') . ": {$passed} passed, {$failed} failed"
    . " ({$totalAssertions} assertions, {$elapsed} ms)" . PHP_EOL);
exit($failed === 0 ? 0 : 1);
