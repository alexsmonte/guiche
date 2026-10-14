<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Guiche;
use GuicheExample\ClinicaPiloto\ClinicStateManager;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkClinicalStateBoundaryV02(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

$manager = new ClinicStateManager();

checkClinicalStateBoundaryV02(
    $manager instanceof StateManagerInterface,
    'ClinicStateManager implementa o contrato genérico do Core'
);

checkClinicalStateBoundaryV02(
    !is_file(dirname(__DIR__) . '/src/StateManager.php'),
    'implementação clínica não permanece no namespace/arquivo raiz'
);

$reflection = new ReflectionMethod(Guiche::class, '__construct');
$parameter = null;

foreach ($reflection->getParameters() as $candidate) {
    if ($candidate->getName() === 'stateManager') {
        $parameter = $candidate;
        break;
    }
}

$type = $parameter?->getType();

checkClinicalStateBoundaryV02(
    $type instanceof ReflectionNamedType
        && $type->getName() === StateManagerInterface::class,
    'Guiche continua dependendo somente de StateManagerInterface'
);

$clinicalSource = file_get_contents(
    dirname(__DIR__) . '/examples/clinica-piloto/src/ClinicStateManager.php'
);

checkClinicalStateBoundaryV02(
    is_string($clinicalSource)
        && str_contains($clinicalSource, "'DisponibilidadeConvenios'")
        && str_contains($clinicalSource, "'ConsultarPaciente'")
        && str_contains($clinicalSource, "'ConsultarHorarios'")
        && str_contains($clinicalSource, "'CriarAgendamento'"),
    'regras de Tools clínicas foram preservadas na implementação do piloto'
);

$guicheSource = file_get_contents(dirname(__DIR__) . '/src/Guiche.php');

checkClinicalStateBoundaryV02(
    is_string($guicheSource)
        && !str_contains($guicheSource, 'ClinicStateManager')
        && !str_contains($guicheSource, 'DisponibilidadeConvenios')
        && !str_contains($guicheSource, 'ConsultarPaciente')
        && !str_contains($guicheSource, 'CriarAgendamento'),
    'Core Guiche não conhece a implementação nem as Tools clínicas'
);

echo PHP_EOL;
echo "OK: fronteira de estado clínico da v0.2 validada." . PHP_EOL;
