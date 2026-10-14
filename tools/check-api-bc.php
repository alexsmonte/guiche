<?php
declare(strict_types=1);

require_once __DIR__ . '/api-freeze-lib.php';

try {
    guicheApiLoadAutoload();
    $map = guicheApiLoadStabilityMap();
    $mapErrors = guicheApiValidateStabilityMap($map);
    if ($mapErrors !== []) {
        fwrite(STDERR, "ERRO: mapa de estabilidade inválido:\n- " . implode("\n- ", $mapErrors) . "\n");
        exit(2);
    }

    $baseline = guicheApiReadBaseline();
    $current = guicheApiCurrentSnapshot($map);
    $errors = guicheApiCompare($baseline, $current);

    if ($errors !== []) {
        fwrite(STDERR, "ERRO: quebra/alteração no freeze da API pública:\n- " . implode("\n- ", $errors) . "\n");
        fwrite(STDERR, "Baseline SHA-256: " . ($baseline['public_api_sha256'] ?? 'n/a') . "\n");
        fwrite(STDERR, "Atual SHA-256:    " . ($current['public_api_sha256'] ?? 'n/a') . "\n");
        exit(1);
    }

    $counts = array_count_values($map);
    echo "OK: API pública compatível com baseline v1\n";
    echo "Public: " . ($counts['public'] ?? 0) . "\n";
    echo "Experimental: " . ($counts['experimental'] ?? 0) . "\n";
    echo "Internal: " . ($counts['internal'] ?? 0) . "\n";
    echo "SHA-256 API pública: {$current['public_api_sha256']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(2);
}
