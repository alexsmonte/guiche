<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use GuicheExample\ClinicaPiloto\ClinicPlugin;
use GuicheExample\ClinicaPiloto\ClinicStateManager;
use GuicheExample\ClinicaPiloto\Presentation\ConsultarHorariosPresenter;
use GuicheExample\ClinicaPiloto\Presentation\DisponibilidadeConveniosPresenter;
use GuicheExample\ClinicaPiloto\Tools\ConsultarHorariosTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarPacienteTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarProfissionalTool;
use GuicheExample\ClinicaPiloto\Tools\CriarAgendamentoTool;
use GuicheExample\ClinicaPiloto\Tools\DisponibilidadeConveniosTool;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkV08(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }
    echo 'OK: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);

echo PHP_EOL . "GUICHÊ v0.8.0 — FASE 02 / CLINICAL EXTRACTION" . PHP_EOL;
echo str_repeat('=', 58) . PHP_EOL . PHP_EOL;

$newFiles = [
    'examples/clinica-piloto/src/ClinicPlugin.php',
    'examples/clinica-piloto/src/ClinicStateManager.php',
    'examples/clinica-piloto/src/Tools/DisponibilidadeConveniosTool.php',
    'examples/clinica-piloto/src/Tools/ConsultarProfissionalTool.php',
    'examples/clinica-piloto/src/Tools/ConsultarHorariosTool.php',
    'examples/clinica-piloto/src/Tools/ConsultarPacienteTool.php',
    'examples/clinica-piloto/src/Tools/CriarAgendamentoTool.php',
    'examples/clinica-piloto/src/Presentation/DisponibilidadeConveniosPresenter.php',
    'examples/clinica-piloto/src/Presentation/ConsultarHorariosPresenter.php',
    'examples/clinica-piloto/config/clinic.php',
    'examples/clinica-piloto/config/prompt.php',
    'examples/clinica-piloto/bootstrap.php',
    'examples/clinica-piloto/chat.php',
];
foreach ($newFiles as $file) {
    checkV08(is_file($root . '/' . $file), "existe {$file}");
}

$legacyClinicalDir = $root . '/src/Pilot/' . 'Clinical';
checkV08(!is_dir($legacyClinicalDir), 'diretório clínico legado em src/ foi removido');
$legacyConfig = $root . '/config/pilot-' . 'clinical.php';
checkV08(!is_file($legacyConfig), 'config clínico legado da raiz foi removido');
$legacyPrompt = $root . '/config/pilot-' . 'clinical-prompt.php';
checkV08(!is_file($legacyPrompt), 'prompt clínico legado da raiz foi removido');
checkV08(!is_file($root . '/bin/chat.php'), 'bootstrap clínico antigo bin/chat.php foi removido');

checkV08(is_subclass_of(ClinicPlugin::class, PluginInterface::class), 'ClinicPlugin implementa PluginInterface');
checkV08(is_subclass_of(ClinicStateManager::class, StateManagerInterface::class), 'ClinicStateManager implementa StateManagerInterface');
foreach ([
    DisponibilidadeConveniosTool::class,
    ConsultarProfissionalTool::class,
    ConsultarHorariosTool::class,
    ConsultarPacienteTool::class,
    CriarAgendamentoTool::class,
] as $class) {
    checkV08(is_subclass_of($class, ToolInterface::class), basename(str_replace('\\', '/', $class)) . ' implementa ToolInterface');
}
foreach ([DisponibilidadeConveniosPresenter::class, ConsultarHorariosPresenter::class] as $class) {
    checkV08(is_subclass_of($class, ResultPresenterInterface::class), basename(str_replace('\\', '/', $class)) . ' implementa ResultPresenterInterface');
}

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$prod = $composer['autoload']['psr-4'] ?? [];
$dev = $composer['autoload-dev']['psr-4'] ?? [];
checkV08(($prod['Asmpkg\\Guiche\\'] ?? null) === 'src/', 'autoload público do Core continua Asmpkg\\Guiche\\ => src/');
checkV08(!isset($prod['GuicheExample\\ClinicaPiloto\\']), 'exemplo não foi promovido à API/autoload público');
checkV08(($dev['GuicheExample\\ClinicaPiloto\\'] ?? null) === 'examples/clinica-piloto/src/', 'autoload-dev reconhece o exemplo clínico');

$forbidden = ['Pilot' . '\\' . 'Clinical', 'Clinical' . 'Plugin', 'Clinical' . 'StateManager', 'DisponibilidadeConvenios', 'CriarAgendamento'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    foreach ($forbidden as $needle) {
        checkV08(!str_contains($text, $needle), 'Core neutro em ' . substr($file->getPathname(), strlen($root) + 1) . " para {$needle}");
    }
}

$chat = (string) file_get_contents($root . '/examples/clinica-piloto/chat.php');
checkV08(str_contains($chat, "bootstrap.php"), 'chat do exemplo usa bootstrap local');
checkV08(str_contains($chat, 'ClinicPlugin'), 'chat do exemplo usa ClinicPlugin');
$legacyConfigReference = 'config/pilot-' . 'clinical';
checkV08(!str_contains($chat, $legacyConfigReference), 'chat não referencia config clínica legada');

echo PHP_EOL . 'OK: GUICHÊ v0.8.0 / FASE 02 validada.' . PHP_EOL;
