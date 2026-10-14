<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$tests = [
    'v0.5 / persistence contracts' =>
        'bin/test-persistence-contracts-v05.php',

    'v0.5 / context abstraction' =>
        'bin/test-context-store-abstraction-v05.php',

    'v0.5 / atomic context store' =>
        'bin/test-atomic-context-store-v05.php',

    'v0.5 / runtime event sink' =>
        'bin/test-runtime-event-sink-v05.php',

    'v0.5 / runtime observability' =>
        'bin/test-runtime-observability-v05.php',

    'v0.4 / acceptance' =>
        'bin/test-v04-acceptance.php',

    'v0.3 / acceptance' =>
        'bin/test-v03-acceptance.php',

    'v0.2 / state contract' =>
        'bin/test-core-state-contract-v02.php',

    'v0.2 / core config' =>
        'bin/test-core-config-v02.php',

    'v0.2 / tool policy' =>
        'bin/test-core-tool-policy-v02.php',

    'v0.2 / clinical state boundary' =>
        'bin/test-clinical-state-boundary-v02.php',

    'v0.2 / clinical tools boundary' =>
        'bin/test-clinical-tools-boundary-v02.php',

    'piloto clínico / safety integration' =>
        'bin/test-clinical-safety-v04.php',
];

echo PHP_EOL;
echo "GUICHÊ v0.5.0 — ACCEPTANCE CONSOLIDADO" . PHP_EOL;
echo "============================================" . PHP_EOL;

foreach ($tests as $label => $relative) {
    $file = $root . DIRECTORY_SEPARATOR . $relative;

    if (!is_file($file)) {
        fwrite(
            STDERR,
            PHP_EOL
            . "ERRO: teste obrigatório ausente: {$relative}"
            . PHP_EOL
        );
        exit(1);
    }

    echo PHP_EOL;
    echo "--- {$label}" . PHP_EOL;
    echo "    {$relative}" . PHP_EOL;
    echo PHP_EOL;

    $command =
        escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($file);

    passthru($command, $code);

    if ($code !== 0) {
        fwrite(
            STDERR,
            PHP_EOL
            . "ERRO: acceptance interrompido em {$relative}"
            . PHP_EOL
        );
        exit($code);
    }
}

echo PHP_EOL;
echo "OK: GUICHÊ v0.5.0 — ACCEPTANCE COMPLETO." . PHP_EOL;
