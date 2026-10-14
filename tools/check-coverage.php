<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(
        STDERR,
        "Uso: php tools/check-coverage.php <clover.xml> [threshold]\n"
    );
    exit(2);
}

$file = (string) $argv[1];
$threshold = isset($argv[2])
    ? (float) $argv[2]
    : 70.0;

if (!is_file($file)) {
    fwrite(
        STDERR,
        "ERRO: relatório Clover não encontrado: {$file}\n"
    );
    exit(2);
}

$xml = simplexml_load_file($file);

if ($xml === false) {
    fwrite(
        STDERR,
        "ERRO: Clover XML inválido: {$file}\n"
    );
    exit(2);
}

$metrics = $xml->project->metrics ?? null;

if ($metrics === null) {
    fwrite(
        STDERR,
        "ERRO: métricas globais não encontradas no Clover.\n"
    );
    exit(2);
}

$statements = (int) ($metrics['statements'] ?? 0);
$covered = (int) ($metrics['coveredstatements'] ?? 0);

if ($statements <= 0) {
    fwrite(
        STDERR,
        "ERRO: Clover não contém statements executáveis.\n"
    );
    exit(2);
}

$percentage = ($covered / $statements) * 100;

printf(
    "Coverage de linhas/statements: %.2f%% (%d/%d)\n",
    $percentage,
    $covered,
    $statements
);

printf(
    "Threshold mínimo: %.2f%%\n",
    $threshold
);

if ($percentage + 0.00001 < $threshold) {
    fwrite(
        STDERR,
        sprintf(
            "ERRO: coverage %.2f%% abaixo do threshold %.2f%%.\n",
            $percentage,
            $threshold
        )
    );
    exit(1);
}

echo "OK: coverage threshold atendido.\n";
