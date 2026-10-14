<?php
declare(strict_types=1);

require_once __DIR__ . '/api-freeze-lib.php';

guicheApiLoadAutoload();
$map = guicheApiLoadStabilityMap();
$snapshot = guicheApiCurrentSnapshot($map);

$write = in_array('--write-baseline', $argv, true);
if ($write) {
    if (getenv('GUICHE_ALLOW_API_BASELINE_UPDATE') !== '1') {
        fwrite(STDERR, "ERRO: atualização do baseline exige GUICHE_ALLOW_API_BASELINE_UPDATE=1\n");
        exit(2);
    }

    $target = guicheApiRoot() . '/api/baseline-v1.json';
    file_put_contents($target, guicheApiEncode($snapshot));
    echo "OK: baseline atualizado em api/baseline-v1.json\n";
    echo "SHA-256 API pública: {$snapshot['public_api_sha256']}\n";
    exit(0);
}

echo guicheApiEncode($snapshot);
