<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use GuicheExample\ClinicaPiloto\ClinicPlugin;
use GuicheExample\ClinicaPiloto\ClinicStateManager;
use GuicheExample\ClinicaPiloto\Presentation\ConsultarHorariosPresenter;
use GuicheExample\ClinicaPiloto\Presentation\DisponibilidadeConveniosPresenter;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkV03F05(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException(
            'FALHOU: ' . $message
        );
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class FakeSqlV03F05 implements SqlExecutorInterface
{
    public function fetchAll(
        string $sql,
        array $parameters = []
    ): array {
        return [];
    }

    public function execute(
        string $sql,
        array $parameters = []
    ): int {
        return 1;
    }
}

final class FakeHttpV03F05 implements HttpClientInterface
{
    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        return [
            'status' => 200,
            'body' => '{}',
            'json' => [],
        ];
    }
}

$config = [
    'procedures' => [
        'disponibilidade_convenios' =>
            'profissionais_por_tratamento',
        'consultar_profissional' =>
            'sp_consulta_profissional',
        'consultar_horarios' =>
            'horarios_disponiveis_por_turno',
        'consultar_paciente' =>
            'consultar_paciente_por_cpf',
    ],
    'horarios_por_data' => 3,
    'agendamento_api' => [
        'url' => 'https://example.invalid/agendamentos',
        'token' => 'test-token',
        'timeout' => 15,
    ],
    'tool_policy' => [
        'stage_path' => 'state.etapa',
        'bootstrap' => [
            'DisponibilidadeConvenios',
        ],
        'default' => [],
        'stages' => [
            'aguardando_convenio' => [
                'DisponibilidadeConvenios',
            ],
            'aguardando_profissional' => [
                'ConsultarProfissional',
            ],
            'aguardando_data' => [
                'ConsultarHorarios',
            ],
            'aguardando_cpf' => [
                'ConsultarPaciente',
            ],
            'pronto_para_agendar' => [
                'CriarAgendamento',
            ],
        ],
    ],
    'completion_guards' => [
        [
            'state_key' => 'etapa',
            'state_value' => 'pronto_para_agendar',
            'required_tool' => 'CriarAgendamento',
            'fallback_message' =>
                'Não foi possível efetivar.',
        ],
    ],
    'ai_tool_result_exclude_paths' => [
        'ConsultarHorarios' => [
            'input',
            'meta.internal',
        ],
    ],
];

$plugin = new ClinicPlugin(
    new FakeSqlV03F05(),
    $config,
    'INSTRUÇÕES CLÍNICAS DE TESTE',
    new FakeHttpV03F05()
);

echo PHP_EOL . "1. CONTRATO DO PLUGIN" . PHP_EOL;

checkV03F05(
    $plugin instanceof PluginInterface,
    'ClinicPlugin implementa PluginInterface'
);

$builder = new GuicheBuilder();
$builder->registerPlugin($plugin);

checkV03F05(
    isset($builder->plugins()[ClinicPlugin::class]),
    'ClinicPlugin é registrado pelo GuicheBuilder'
);

echo PHP_EOL . "2. TOOLS CLÍNICAS" . PHP_EOL;

$expectedTools = [
    'DisponibilidadeConvenios',
    'ConsultarProfissional',
    'ConsultarHorarios',
    'ConsultarPaciente',
    'CriarAgendamento',
];

checkV03F05(
    $builder->toolRegistry()->names() === $expectedTools,
    'ClinicPlugin registra as cinco Tools na ordem atual'
);

foreach (
    [
        'DisponibilidadeConvenios',
        'ConsultarProfissional',
        'ConsultarHorarios',
        'ConsultarPaciente',
    ]
    as $toolName
) {
    checkV03F05(
        ($builder->toolRegistry()->metadata(
            $toolName
        )['operation'] ?? null) === 'read',
        "{$toolName} é declarada como operação de leitura"
    );
}

checkV03F05(
    ($builder->toolRegistry()->metadata(
        'CriarAgendamento'
    )['operation'] ?? null) === 'write',
    'CriarAgendamento é declarada como operação de escrita'
);

checkV03F05(
    ($builder->toolRegistry()->metadata(
        'CriarAgendamento'
    )['domain'] ?? null) === 'clinical',
    'metadados identificam o domínio clínico'
);

echo PHP_EOL . "3. STATE + PRESENTERS" . PHP_EOL;

checkV03F05(
    $builder->stateManager()
        instanceof ClinicStateManager,
    'ClinicStateManager é fornecido pelo Plugin'
);

checkV03F05(
    $builder->presenterRegistry()->get(
        'DisponibilidadeConvenios'
    ) instanceof DisponibilidadeConveniosPresenter,
    'Presenter de disponibilidade é registrado'
);

checkV03F05(
    $builder->presenterRegistry()->get(
        'ConsultarHorarios'
    ) instanceof ConsultarHorariosPresenter,
    'Presenter de horários é registrado'
);

checkV03F05(
    $builder->presenterRegistry()->get(
        'DisponibilidadeConvenios'
    ) instanceof ResultPresenterInterface,
    'Presenter de disponibilidade cumpre contrato genérico'
);

checkV03F05(
    $builder->presenterRegistry()->get(
        'ConsultarHorarios'
    ) instanceof ResultPresenterInterface,
    'Presenter de horários cumpre contrato genérico'
);

echo PHP_EOL . "4. POLICY / INSTRUÇÕES / GUARDS / PROJECTIONS" . PHP_EOL;

checkV03F05(
    isset($builder->policies()['clinical.tool-policy']),
    'ToolPolicy clínica possui registro declarativo'
);

checkV03F05(
    $builder->toolPolicy() !== null,
    'ToolPolicy clínica também é ligada ao runtime'
);

$allowed = $builder->toolPolicy()?->allowedToolNames([
    'state' => [
        'etapa' => 'aguardando_profissional',
    ],
]) ?? [];

checkV03F05(
    $allowed === ['ConsultarProfissional'],
    'ToolPolicy clínica lê explicitamente state.etapa'
);

checkV03F05(
    ($builder->instructionFragments()[
        'clinical.instructions'
    ] ?? null) === 'INSTRUÇÕES CLÍNICAS DE TESTE',
    'prompt clínico é registrado como Instruction Fragment'
);

checkV03F05(
    count($builder->completionGuards()) === 1
    && (
        array_values(
            $builder->completionGuards()
        )[0]['required_tool'] ?? null
    ) === 'CriarAgendamento',
    'Completion Guard clínico é registrado pelo Plugin'
);

checkV03F05(
    (
        $builder->resultProjections()[
            'ConsultarHorarios'
        ]['exclude_paths'][0]
        ?? null
    ) === 'input',
    'Result Projection clínica é registrada pelo Plugin'
);

echo PHP_EOL . "5. BUILD DO PILOTO VIA PLUGIN" . PHP_EOL;

$temp = sys_get_temp_dir()
    . '/guiche-v03-f05-'
    . bin2hex(random_bytes(4));

$guiche = $builder
    ->withContextStore(
        new ContextStore(
            $temp,
            'America/Manaus'
        )
    )
    ->withAgentContext([
        'empresa_id' => 142,
    ])
    ->withReusableTools([
        'DisponibilidadeConvenios',
    ])
    ->withAiStateExcludePaths([
        'horarios_disponiveis',
    ])
    ->build();

checkV03F05(
    $guiche instanceof Guiche,
    'piloto clínico pode ser construído pelo GuicheBuilder'
);

checkV03F05(
    array_column(
        $guiche->tools(),
        'name'
    ) === $expectedTools,
    'runtime construído recebe todas as Tools do ClinicPlugin'
);

echo PHP_EOL . "6. BOOTSTRAP CLÍNICO MIGRADO" . PHP_EOL;

$chatSource = file_get_contents(
    dirname(__DIR__) . '/examples/clinica-piloto/chat.php'
);

checkV03F05(
    is_string($chatSource)
    && str_contains(
        $chatSource,
        'new ClinicPlugin('
    )
    && str_contains(
        $chatSource,
        '->registerPlugin('
    )
    && str_contains(
        $chatSource,
        'new GuicheBuilder()'
    ),
    'examples/clinica-piloto/chat.php usa GuicheBuilder + ClinicPlugin'
);

$forbiddenManualWiring = [
    'new DisponibilidadeConveniosTool(',
    'new ConsultarProfissionalTool(',
    'new ConsultarHorariosTool(',
    'new ConsultarPacienteTool(',
    'new CriarAgendamentoTool(',
    'new ClinicStateManager(',
    'new ToolRegistry(',
    'new ToolPolicy(',
];

$manualWiringRemoved = true;

foreach ($forbiddenManualWiring as $marker) {
    if (
        is_string($chatSource)
        && str_contains($chatSource, $marker)
    ) {
        $manualWiringRemoved = false;
        break;
    }
}

checkV03F05(
    $manualWiringRemoved,
    'examples/clinica-piloto/chat.php não monta mais capacidades clínicas manualmente'
);

echo PHP_EOL . "7. CORE CONTINUA NEUTRO" . PHP_EOL;

$coreFiles = [
    dirname(__DIR__) . '/src/Guiche.php',
    dirname(__DIR__) . '/src/GuicheBuilder.php',
    dirname(__DIR__) . '/src/ToolRegistry.php',
    dirname(__DIR__) . '/src/PresenterRegistry.php',
];

$coreNeutral = true;

foreach ($coreFiles as $file) {
    $source = file_get_contents($file);

    if (
        is_string($source)
        && (
            str_contains($source, 'ClinicPlugin')
            || str_contains(
                $source,
                'Pilot\\Clinical'
            )
        )
    ) {
        $coreNeutral = false;
        break;
    }
}

checkV03F05(
    $coreNeutral,
    'Core genérico não conhece ClinicPlugin nem namespace clínico'
);

echo PHP_EOL;
echo "OK: GUICHÊ v0.3.0 / FASE 05 validada." . PHP_EOL;
