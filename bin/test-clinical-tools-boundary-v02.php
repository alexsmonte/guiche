<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\ToolInterface;
use GuicheExample\ClinicaPiloto\Presentation\ConsultarHorariosPresenter;
use GuicheExample\ClinicaPiloto\Presentation\DisponibilidadeConveniosPresenter;
use GuicheExample\ClinicaPiloto\Tools\ConsultarHorariosTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarPacienteTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarProfissionalTool;
use GuicheExample\ClinicaPiloto\Tools\CriarAgendamentoTool;
use GuicheExample\ClinicaPiloto\Tools\DisponibilidadeConveniosTool;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkClinicalToolsBoundaryV02(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);

$toolClasses = [
    DisponibilidadeConveniosTool::class,
    ConsultarProfissionalTool::class,
    ConsultarHorariosTool::class,
    ConsultarPacienteTool::class,
    CriarAgendamentoTool::class,
];

foreach ($toolClasses as $class) {
    checkClinicalToolsBoundaryV02(
        class_exists($class),
        "autoload encontra {$class}"
    );

    checkClinicalToolsBoundaryV02(
        is_subclass_of($class, ToolInterface::class),
        "{$class} continua implementando ToolInterface"
    );
}

foreach (
    [
        DisponibilidadeConveniosPresenter::class,
        ConsultarHorariosPresenter::class,
    ]
    as $class
) {
    checkClinicalToolsBoundaryV02(
        class_exists($class),
        "autoload encontra {$class}"
    );
}

checkClinicalToolsBoundaryV02(
    !is_dir($root . '/src/Tools'),
    'namespace/diretório genérico src/Tools não contém domínio clínico'
);

checkClinicalToolsBoundaryV02(
    !is_dir($root . '/src/Presentation'),
    'namespace/diretório genérico src/Presentation não contém domínio clínico'
);

$coreFiles = [
    $root . '/src/Guiche.php',
    $root . '/src/ToolRegistry.php',
    $root . '/src/ToolPolicy.php',
    $root . '/src/Contracts/ToolInterface.php',
];

foreach ($coreFiles as $file) {
    $source = file_get_contents($file);

    checkClinicalToolsBoundaryV02(
        is_string($source)
            && !str_contains($source, 'Pilot\\Clinical\\Tools')
            && !str_contains($source, 'DisponibilidadeConveniosTool')
            && !str_contains($source, 'ConsultarPacienteTool')
            && !str_contains($source, 'CriarAgendamentoTool'),
        basename($file) . ' não depende de Tool clínica concreta'
    );
}

echo PHP_EOL;
echo "OK: fronteira de Tools/Presentation clínicas da v0.2 validada." . PHP_EOL;
