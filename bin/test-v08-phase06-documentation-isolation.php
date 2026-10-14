<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$example = $root . '/examples/clinica-piloto';

$ok = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ERRO: {$message}\n");
        exit(1);
    }

    echo "OK: {$message}\n";
};

echo "\nGUICHÊ v0.8.0 — FASE 06 / DOCUMENTAÇÃO + ISOLAMENTO\n";
echo str_repeat('=', 62) . "\n\n";

$composerRaw = file_get_contents($root . '/composer.json');
$ok($composerRaw !== false, 'composer.json pode ser lido');

$composer = json_decode((string)$composerRaw, true, 512, JSON_THROW_ON_ERROR);
$ok(($composer['name'] ?? null) === 'asmpkg/guiche', 'nome do pacote permanece asmpkg/guiche');
$ok(($composer['type'] ?? null) === 'library', 'pacote permanece do tipo library');

$publicPsr4 = $composer['autoload']['psr-4'] ?? [];
$devPsr4 = $composer['autoload-dev']['psr-4'] ?? [];

$ok(isset($publicPsr4['Asmpkg\\Guiche\\']), 'autoload público expõe namespace do Core');
$ok(
    !isset($publicPsr4['GuicheExample\\ClinicaPiloto\\']),
    'namespace clínico não está no autoload público'
);

foreach ($publicPsr4 as $prefix => $paths) {
    foreach ((array)$paths as $path) {
        $normalized = str_replace('\\', '/', (string)$path);
        $ok(
            !str_contains($normalized, 'examples/clinica-piloto'),
            "autoload público não aponta para example: {$prefix}"
        );
    }
}

$ok(
    isset($devPsr4['GuicheExample\\ClinicaPiloto\\']),
    'namespace clínico existe somente no autoload-dev'
);

$devPaths = (array)$devPsr4['GuicheExample\\ClinicaPiloto\\'];
$ok(
    in_array('examples/clinica-piloto/src/', $devPaths, true)
        || in_array('examples/clinica-piloto/src', $devPaths, true),
    'autoload-dev aponta para examples/clinica-piloto/src'
);

$isolatedLoader = new ClassLoader();

foreach ($publicPsr4 as $prefix => $paths) {
    foreach ((array)$paths as $path) {
        $isolatedLoader->addPsr4(
            (string)$prefix,
            $root . '/' . trim((string)$path, '/')
        );
    }
}

$coreFile = $isolatedLoader->findFile('Asmpkg\\Guiche\\Guiche');
$clinicFile = $isolatedLoader->findFile(
    'GuicheExample\\ClinicaPiloto\\ClinicPlugin'
);

$ok(is_string($coreFile) && $coreFile !== '', 'autoload público isolado encontra Guiche');
$ok($clinicFile === false, 'autoload público isolado não encontra ClinicPlugin');

foreach ([
    'README.md',
    'INSTALL.md',
    '.env.example',
    '.gitignore',
    'fake-smoke.php',
    'fixtures/clinical.php',
    'config/clinic.php',
    'src/ClinicPlugin.php',
    'src/ClinicStateManager.php',
] as $relative) {
    $ok(is_file($example . '/' . $relative), "exemplo contém {$relative}");
}

$install = file_get_contents($example . '/INSTALL.md');
$readme = file_get_contents($example . '/README.md');
$rootReadme = file_get_contents($root . '/README.md');
$env = file_get_contents($example . '/.env.example');

$ok(is_string($install), 'INSTALL.md pode ser lido');
$ok(is_string($readme), 'README do exemplo pode ser lido');
$ok(is_string($rootReadme), 'README raiz pode ser lido');
$ok(is_string($env), '.env.example pode ser lido');

foreach ([
    'Fake Mode',
    'Real Mode',
    'fake-smoke.php',
    'bin/test-v08-clinical-acceptance.sh',
    'composer check',
    'autoload público',
    'GUICHE_CLINICAL_MODE=real',
] as $needle) {
    $ok(str_contains((string)$install, $needle), "INSTALL documenta {$needle}");
}

$ok(
    str_contains((string)$rootReadme, 'examples/clinica-piloto/INSTALL.md'),
    'README raiz aponta para guia do exemplo'
);
$ok(
    str_contains((string)$readme, 'INSTALL.md'),
    'README do exemplo aponta para guia de instalação'
);
$ok(
    preg_match('/^GUICHE_CLINICAL_MODE=fake\s*$/m', (string)$env) === 1,
    '.env.example inicia em Fake Mode'
);
$ok(
    preg_match('/^GUICHE_AGENDAMENTO_API_URL=\s*$/m', (string)$env) === 1,
    '.env.example não distribui endpoint real'
);

exec(
    'git -C ' . escapeshellarg($root)
        . ' ls-files --error-unmatch '
        . escapeshellarg('examples/clinica-piloto/.env')
        . ' 2>/dev/null',
    $trackedOutput,
    $trackedEnvCode
);
$ok($trackedEnvCode !== 0, '.env local não está rastreado pelo Git');

exec(
    'git -C ' . escapeshellarg($root)
        . ' check-ignore -q '
        . escapeshellarg('examples/clinica-piloto/.env'),
    $ignoredOutput,
    $ignoredCode
);
$ok($ignoredCode === 0, '.env local está protegido por gitignore');

$scanFiles = [
    $example . '/README.md',
    $example . '/INSTALL.md',
    $example . '/.env.example',
    $example . '/fixtures/clinical.php',
    $example . '/config/clinic.php',
    $example . '/config/prompt-clinical.php',
];

$forbidden = [
    'clinsoft.atendamais.com.br',
    'Ortoshine',
    'Larissa Uemi Yoqui',
    'Guilherme Fernandes Soares',
    'Alex da Silva Monte',
];

foreach ($scanFiles as $file) {
    if (!is_file($file)) {
        continue;
    }

    $contents = (string)file_get_contents($file);

    foreach ($forbidden as $needle) {
        $ok(
            !str_contains($contents, $needle),
            basename($file) . " não contém marcador operacional real: {$needle}"
        );
    }
}

echo "\nOK: GUICHÊ v0.8.0 / FASE 06 documentação e isolamento validados.\n";
