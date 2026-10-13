<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$tests = [
    'bin/test-safety-contracts-v04.php',
    'bin/test-safety-gate-v04.php',
    'bin/test-hard-execution-gate-v04.php',
    'bin/test-write-safety-v04.php',
    'bin/test-result-projection-v04.php',
    'bin/test-clinical-safety-v04.php',
];

echo PHP_EOL;
echo "GUICHÊ v0.4.0 — ACCEPTANCE" . PHP_EOL;
echo str_repeat('=', 44) . PHP_EOL;

foreach ($tests as $relative) {
    $file = $root . '/' . $relative;

    if (!is_file($file)) {
        fwrite(
            STDERR,
            "ERRO: teste ausente: {$relative}" . PHP_EOL
        );
        exit(1);
    }

    echo PHP_EOL . "--- {$relative}" . PHP_EOL;

    passthru(
        escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($file),
        $exitCode
    );

    if ($exitCode !== 0) {
        fwrite(
            STDERR,
            "ERRO: acceptance interrompido em {$relative}"
            . PHP_EOL
        );
        exit($exitCode);
    }
}

echo PHP_EOL;
echo "OK: GUICHÊ v0.4.0 — ACCEPTANCE COMPLETO." . PHP_EOL;
