<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$autoloadFile = $root . '/vendor/autoload.php';
$mapFile = $root . '/api/stability-map.php';
$baselineFile = $root . '/api/experimental-v1.json';

if (!is_file($autoloadFile)) {
    fwrite(STDERR, "ERRO: vendor/autoload.php ausente. Execute composer install/update antes do checker.\n");
    exit(1);
}

require_once $autoloadFile;

if (!is_file($mapFile) || !is_file($baselineFile)) {
    fwrite(STDERR, "ERRO: stability map/baseline experimental ausente.\n");
    exit(1);
}

$map = require $mapFile;
if (!is_array($map)) {
    fwrite(STDERR, "ERRO: api/stability-map.php inválido.\n");
    exit(1);
}

try {
    $baseline = json_decode(
        (string) file_get_contents($baselineFile),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    fwrite(STDERR, "ERRO: api/experimental-v1.json inválido: {$e->getMessage()}\n");
    exit(1);
}

if (($baseline['schema_version'] ?? null) !== 1) {
    fwrite(STDERR, "ERRO: schema_version experimental inesperado.\n");
    exit(1);
}

if (($baseline['decision'] ?? null) !== 'retain_experimental') {
    fwrite(STDERR, "ERRO: decisão Experimental v1 inesperada.\n");
    exit(1);
}

$expected = $baseline['symbols'] ?? null;
if (!is_array($expected)) {
    fwrite(STDERR, "ERRO: lista Experimental congelada ausente.\n");
    exit(1);
}

$expected = array_values(array_unique(array_map('strval', $expected)));
sort($expected, SORT_STRING);

$actual = array_keys(array_filter(
    $map,
    static fn (mixed $status): bool => $status === 'experimental'
));
sort($actual, SORT_STRING);

if ($actual !== $expected) {
    $removed = array_values(array_diff($expected, $actual));
    $added = array_values(array_diff($actual, $expected));

    fwrite(STDERR, "ERRO: superfície Experimental divergiu do freeze v1.\n");
    if ($removed !== []) {
        fwrite(STDERR, "Removidos/reclassificados:\n- " . implode("\n- ", $removed) . "\n");
    }
    if ($added !== []) {
        fwrite(STDERR, "Adicionados/reclassificados:\n- " . implode("\n- ", $added) . "\n");
    }
    exit(1);
}

foreach ($expected as $symbol) {
    if (($map[$symbol] ?? null) !== 'experimental') {
        fwrite(STDERR, "ERRO: {$symbol} não está classificado como experimental.\n");
        exit(1);
    }

    if (
        !class_exists($symbol)
        && !interface_exists($symbol)
        && !trait_exists($symbol)
        && !(function_exists('enum_exists') && enum_exists($symbol))
    ) {
        fwrite(STDERR, "ERRO: símbolo Experimental não carregável: {$symbol}\n");
        exit(1);
    }
}

printf("OK: Experimental API v1 freeze preservado.\n");
printf("Experimental: %d\n", count($actual));
printf("Decision: retain_experimental\n");
