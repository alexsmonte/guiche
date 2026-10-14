<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$tests = [
    'v0.5.1 / contracts' =>
        'bin/test-conversation-contracts-v051.php',
    'v0.5.1 / incoming events + sequence' =>
        'bin/test-conversation-incoming-v051.php',
    'v0.5.1 / buffer + turn' =>
        'bin/test-conversation-buffer-turn-v051.php',
    'v0.5.1 / conversation runtime' =>
        'bin/test-conversation-runtime-v051.php',
    'v0.5.1 / durable scheduler' =>
        'bin/test-scheduler-v051.php',
    'v0.5.1 / stale + follow-up + recovery' =>
        'bin/test-followup-recovery-v051.php',
    'v0.5 / full regression' =>
        'bin/test-v05-acceptance.php',
];

echo PHP_EOL;
echo "GUICHÊ v0.5.1 — ACCEPTANCE CONSOLIDADO" . PHP_EOL;
echo "==============================================" . PHP_EOL;

foreach ($tests as $label => $relative) {
    $path = $root . '/' . $relative;

    if (!is_file($path)) {
        throw new RuntimeException(
            "Teste ausente: {$relative}"
        );
    }

    echo PHP_EOL;
    echo "--- {$label}" . PHP_EOL;
    echo "    {$relative}" . PHP_EOL;
    echo PHP_EOL;

    $command =
        escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($path);

    passthru($command, $code);

    if ($code !== 0) {
        throw new RuntimeException(
            "Acceptance falhou em {$relative}."
        );
    }
}

echo PHP_EOL;
echo "OK: GUICHÊ v0.5.1 — ACCEPTANCE COMPLETO." . PHP_EOL;
