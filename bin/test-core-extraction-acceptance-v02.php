<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Pilot\Clinical\ClinicalStateManager;
use Asmpkg\Guiche\Pilot\Clinical\Tools\ConsultarHorariosTool;
use Asmpkg\Guiche\Pilot\Clinical\Tools\ConsultarPacienteTool;
use Asmpkg\Guiche\Pilot\Clinical\Tools\ConsultarProfissionalTool;
use Asmpkg\Guiche\Pilot\Clinical\Tools\CriarAgendamentoTool;
use Asmpkg\Guiche\Pilot\Clinical\Tools\DisponibilidadeConveniosTool;
use Asmpkg\Guiche\ToolPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkCoreExtractionV02(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);

$coreMarkers = [
    'Ortoshine',
    'clinsoft.atendamais.com.br',
    'DisponibilidadeConvenios',
    'ConsultarProfissional',
    'ConsultarHorarios',
    'ConsultarPaciente',
    'CriarAgendamento',
    'aguardando_convenio',
    'aguardando_profissional',
    'aguardando_cpf',
    'pronto_para_agendar',
    'state.etapa',
];

$coreFiles = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $root . '/src',
        FilesystemIterator::SKIP_DOTS
    )
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $pathname = $file->getPathname();
    $relative = str_replace($root . '/', '', $pathname);

    if (str_starts_with($relative, 'src/Pilot/')) {
        continue;
    }

    $coreFiles[] = $pathname;
}

checkCoreExtractionV02(
    $coreFiles !== [],
    'arquivos do Core foram localizados para auditoria'
);

foreach ($coreFiles as $file) {
    $source = file_get_contents($file);

    if (!is_string($source)) {
        throw new RuntimeException('FALHOU: não foi possível ler ' . $file);
    }

    foreach ($coreMarkers as $marker) {
        checkCoreExtractionV02(
            !str_contains($source, $marker),
            str_replace($root . '/', '', $file)
                . " não contém marcador clínico: {$marker}"
        );
    }
}

checkCoreExtractionV02(
    !is_dir($root . '/src/Tools'),
    'src/Tools genérico não permanece contendo domínio'
);

checkCoreExtractionV02(
    !is_dir($root . '/src/Presentation'),
    'src/Presentation genérico não permanece contendo domínio'
);

checkCoreExtractionV02(
    !is_file($root . '/src/StateManager.php'),
    'StateManager clínico não permanece na raiz do Core'
);

$clinicalState = new ClinicalStateManager();

checkCoreExtractionV02(
    $clinicalState instanceof StateManagerInterface,
    'ClinicalStateManager permanece ligado ao Core apenas pelo contrato'
);

$reflection = new ReflectionMethod(Guiche::class, '__construct');
$stateParameter = null;

foreach ($reflection->getParameters() as $parameter) {
    if ($parameter->getName() === 'stateManager') {
        $stateParameter = $parameter;
        break;
    }
}

$type = $stateParameter?->getType();

checkCoreExtractionV02(
    $type instanceof ReflectionNamedType
        && $type->getName() === StateManagerInterface::class,
    'Guiche depende de StateManagerInterface'
);

$clinicalTools = [
    DisponibilidadeConveniosTool::class,
    ConsultarProfissionalTool::class,
    ConsultarHorariosTool::class,
    ConsultarPacienteTool::class,
    CriarAgendamentoTool::class,
];

foreach ($clinicalTools as $toolClass) {
    checkCoreExtractionV02(
        class_exists($toolClass),
        "autoload encontra {$toolClass}"
    );

    checkCoreExtractionV02(
        is_subclass_of($toolClass, ToolInterface::class),
        "{$toolClass} usa somente ToolInterface do Core"
    );
}

$coreConfig = require $root . '/config/guiche.php';
$pilotConfig = require $root . '/config/pilot-clinical.php';

$domainConfigKeys = [
    'database',
    'agendamento_api',
    'procedures',
    'horarios_por_data',
    'agent_context',
    'reusable_tools',
    'tool_policy',
    'completion_guards',
    'ai_state_exclude_paths',
    'ai_tool_result_exclude_paths',
];

foreach ($domainConfigKeys as $key) {
    checkCoreExtractionV02(
        !array_key_exists($key, $coreConfig),
        "config do Core não contém domínio: {$key}"
    );

    checkCoreExtractionV02(
        array_key_exists($key, $pilotConfig),
        "config do piloto contém domínio: {$key}"
    );
}

checkCoreExtractionV02(
    ($pilotConfig['tool_policy']['context_path'] ?? null) === 'state.etapa',
    'piloto declara explicitamente state.etapa'
);

$genericPolicy = new ToolPolicy(
    stages: ['ready' => ['GenericTool']],
    bootstrapTools: ['BootstrapTool']
);

checkCoreExtractionV02(
    $genericPolicy->allowedToolNames([
        'state' => ['stage' => 'ready'],
    ]) === ['GenericTool'],
    'ToolPolicy funciona com default genérico state.stage'
);

$providerSource = file_get_contents(
    $root . '/src/Providers/TerminalToolOutputAiProvider.php'
);

checkCoreExtractionV02(
    is_string($providerSource)
        && str_contains($providerSource, 'terminal_message')
        && str_contains($providerSource, 'presentation_message'),
    'convenções determinísticas do provider permanecem disponíveis'
);

echo PHP_EOL;
echo "OK: aceite completo da Core Extraction v0.2 validado." . PHP_EOL;
