<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$phpunit = $root . '/vendor/bin/phpunit';
$coverageDir = $root . '/coverage';
$clover = $coverageDir . '/clover.xml';
$summary = $coverageDir . '/summary.txt';
$checker = $root . '/tools/check-coverage.php';

if (!is_file($phpunit)) {
    fwrite(STDERR, "ERRO: PHPUnit não encontrado.\n");
    exit(2);
}

$hasXdebug = extension_loaded('xdebug');
$hasPcov = extension_loaded('pcov');

if (!$hasXdebug && !$hasPcov) {
    fwrite(
        STDERR,
        "ERRO: coverage requer Xdebug ou PCOV no PHP CLI.\n"
    );
    exit(2);
}

if (!is_dir($coverageDir)) {
    mkdir($coverageDir, 0775, true);
}

@unlink($clover);
@unlink($summary);

if ($hasXdebug) {
    putenv('XDEBUG_MODE=coverage');
}

$command = [
    PHP_BINARY,
    $phpunit,
    '--coverage-clover',
    $clover,
    '--coverage-text=' . $summary,
];

$escaped = implode(
    ' ',
    array_map(
        'escapeshellarg',
        $command
    )
);

passthru($escaped, $status);

if ($status !== 0) {
    fwrite(
        STDERR,
        "ERRO: PHPUnit coverage falhou.\n"
    );
    exit($status);
}

if (is_file($summary)) {
    echo file_get_contents($summary);
}

passthru(
    escapeshellarg(PHP_BINARY)
    . ' '
    . escapeshellarg($checker)
    . ' '
    . escapeshellarg($clover)
    . ' 70.00',
    $coverageStatus
);

exit($coverageStatus);
