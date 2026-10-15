<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function runV11(
    string $label,
    array $command,
    string $root
): void {
    echo "\n--- v1.1 / {$label}\n";
    echo '    ' . implode(' ', $command) . "\n\n";

    $process = proc_open(
        $command,
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ],
        $pipes,
        $root
    );

    if (!is_resource($process)) {
        fwrite(
            STDERR,
            "ERRO: não foi possível executar {$label}.\n"
        );
        exit(1);
    }

    $status = proc_close($process);

    if ($status !== 0) {
        fwrite(
            STDERR,
            "ERRO: acceptance {$label} falhou "
            . "com status {$status}.\n"
        );
        exit($status);
    }
}

function assertFileContainsV11(
    string $file,
    string $needle
): void {
    $content = file_get_contents($file);

    if (
        $content === false
        || !str_contains($content, $needle)
    ) {
        fwrite(
            STDERR,
            "ERRO: {$file} não contém marcador: {$needle}\n"
        );
        exit(1);
    }

    echo "OK: {$file} contém {$needle}\n";
}

echo "\nGUICHÊ v1.1.0 — ACCEPTANCE CONSOLIDADO\n";
echo str_repeat('=', 47) . "\n";

runV11(
    'context + tenant PHPUnit',
    [
        $root . '/vendor/bin/phpunit',
        'tests/Integration/ExecutionContextSeederTest.php',
        'tests/Integration/TenantResolutionTest.php',
        'tests/Integration/ContextualRuntimeTest.php',
        'tests/Integration/ClinicalMultitenantContextTest.php',
        'tests/Integration/MultitenantIsolationSecurityTest.php',
    ],
    $root
);

runV11(
    'multitenant concurrency',
    [
        PHP_BINARY,
        'bin/test-v11-multitenant-isolation.php',
    ],
    $root
);

runV11(
    'hard execution gate',
    [
        PHP_BINARY,
        'bin/test-hard-execution-gate-v04.php',
    ],
    $root
);

runV11(
    'write safety',
    [
        PHP_BINARY,
        'bin/test-write-safety-v04.php',
    ],
    $root
);

runV11(
    'clinical safety',
    [
        PHP_BINARY,
        'bin/test-clinical-safety-v04.php',
    ],
    $root
);

runV11(
    'public API freeze',
    [
        PHP_BINARY,
        'tools/check-api-bc.php',
    ],
    $root
);

runV11(
    'experimental API freeze',
    [
        PHP_BINARY,
        'tools/check-experimental-api.php',
    ],
    $root
);

echo "\n--- v1.1 / documentação\n\n";

assertFileContainsV11(
    $root . '/README.md',
    'v1.1.0 — Stable'
);

assertFileContainsV11(
    $root . '/CHANGELOG.md',
    '## [1.1.0] - 2026-10-15'
);

assertFileContainsV11(
    $root . '/docs/api-stability.md',
    'Public: 32'
);

assertFileContainsV11(
    $root . '/docs/multitenancy.md',
    'SESSION'
);

assertFileContainsV11(
    $root . '/docs/migration-v1.0-to-v1.1.md',
    'ContextAwareToolInterface'
);

assertFileContainsV11(
    $root . '/docs/release-v1.1.0.md',
    'f4328ccc4724f0e097e0d9369329a6bb3bbfe27bca5cddc8d452f43a63130c41'
);

echo "\nOK: GUICHÊ v1.1.0 — ACCEPTANCE COMPLETO.\n";
