<?php

/**
 * Прогон всех проверок: `php tests/run.php` (или `composer test`).
 *
 * Можно ограничить набор подстрокой: `php tests/run.php каталог`.
 */

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];

foreach (TestRegistry::$cases as $suite => $cases) {
    $shown = false;

    foreach ($cases as $title => $case) {
        if ($filter !== '' && mb_stripos($suite . ' ' . $title, $filter) === false) {
            continue;
        }

        if (!$shown) {
            echo "\n" . $suite . "\n";
            $shown = true;
        }

        try {
            $case();
            $passed++;
            echo '  ok   ' . $title . "\n";
        } catch (Throwable $e) {
            $failed[] = ['suite' => $suite, 'title' => $title, 'error' => $e];
            echo '  FAIL ' . $title . "\n";
            echo '       ' . $e->getMessage() . "\n";
        }
    }
}

echo "\n";

if ($failed === []) {
    echo 'Пройдено проверок: ' . $passed . ". Провалов нет.\n";
    exit(0);
}

echo 'Пройдено: ' . $passed . ', провалено: ' . count($failed) . "\n";
foreach ($failed as $item) {
    $e = $item['error'];
    echo '  - ' . $item['suite'] . ' / ' . $item['title'] . "\n";
    if (!$e instanceof AssertionFailed) {
        echo '    ' . get_class($e) . ' в ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

exit(1);
