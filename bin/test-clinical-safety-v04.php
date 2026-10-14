<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\GuicheBuilder;
use GuicheExample\ClinicaPiloto\ClinicPlugin;
use Asmpkg\Guiche\Safety\ToolBlockedException;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ClinicalSafetySqlV04 implements SqlExecutorInterface
{
    public int $fetches = 0;

    public function fetchAll(
        string $sql,
        array $parameters = []
    ): array {
        $this->fetches++;

        return [];
    }

    public function execute(
        string $sql,
        array $parameters = []
    ): int {
        return 1;
    }
}

final class ClinicalSafetyHttpV04 implements HttpClientInterface
{
    public int $posts = 0;

    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        $this->posts++;

        return [
            'status' => 200,
            'body' => '{"agendamento_id":98765}',
            'json' => [
                'agendamento_id' => 98765,
                'internal_provider_trace' => 'SECRET',
            ],
        ];
    }
}

function pass(string $message): void
{
    echo "OK: {$message}\n";
}

function fail_test(string $message): never
{
    fwrite(STDERR, "ERRO: {$message}\n");
    exit(1);
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail_test($message);
    }

    pass($message);
}

function expect_block(
    callable $callback,
    string $reason,
    string $message
): ToolBlockedException {
    try {
        $callback();
    } catch (ToolBlockedException $exception) {
        assert_true(
            $exception->reasonCode() === $reason,
            $message . " [{$reason}]"
        );

        return $exception;
    }

    fail_test(
        $message . ' — a chamada deveria ter sido bloqueada.'
    );
}

echo "\nGUICHÊ v0.4.0 — FASE 06 / CLINICAL SAFETY\n";
echo str_repeat('=', 57) . "\n\n";

$config = require dirname(__DIR__)
    . '/examples/clinica-piloto/config/clinic.php';

$config['write_safety']['slot_revalidation'] = false;

$config['procedures']['consultar_paciente'] =
    'consultar_paciente_por_cpf';

$config['agendamento_api'] = [
    'url' => 'https://example.invalid/agendamentos',
    'token' => 'test-token',
    'timeout' => 15,
];

$sql = new ClinicalSafetySqlV04();
$http = new ClinicalSafetyHttpV04();

$builder = new GuicheBuilder();

$builder->registerPlugin(
    new ClinicPlugin(
        $sql,
        $config,
        'INSTRUÇÕES CLÍNICAS DE TESTE',
        $http
    )
);

echo "1. METADATA SAFETY DO PLUGIN\n";

$registry = $builder->toolRegistry();

$bookingMeta = $registry->metadata(
    'CriarAgendamento'
);

assert_true(
    ($bookingMeta['operation'] ?? null) === 'write',
    'CriarAgendamento permanece declarada WRITE'
);

assert_true(
    ($bookingMeta['allowed_stages'] ?? null)
        === ['pronto_para_agendar'],
    'CriarAgendamento declara estágio permitido'
);

assert_true(
    ($bookingMeta['stage_path'] ?? null)
        === 'state.etapa',
    'CriarAgendamento declara stage_path clínico explicitamente'
);

assert_true(
    is_array($bookingMeta['requires_state'] ?? null)
    && count($bookingMeta['requires_state']) >= 6,
    'CriarAgendamento possui preconditions de estado'
);

assert_true(
    is_array($bookingMeta['forbids_state'] ?? null)
    && ($bookingMeta['forbids_state'][0]['path'] ?? null)
        === 'state.agendamento_id',
    'CriarAgendamento proíbe nova escrita após agendamento existente'
);

assert_true(
    ($bookingMeta['confirmation']['path'] ?? null)
        === 'state.confirmado_pelo_usuario',
    'CriarAgendamento exige confirmação explícita'
);

$professionalMeta = $registry->metadata(
    'ConsultarProfissional'
);

assert_true(
    ($professionalMeta['allowed_stages'] ?? null)
        === ['aguardando_profissional'],
    'ConsultarProfissional declara allowed_stages'
);

$scheduleMeta = $registry->metadata(
    'ConsultarHorarios'
);

assert_true(
    in_array(
        'aguardando_data',
        $scheduleMeta['allowed_stages'] ?? [],
        true
    ),
    'ConsultarHorarios declara etapas válidas'
);

$patientMeta = $registry->metadata(
    'ConsultarPaciente'
);

assert_true(
    ($patientMeta['allowed_stages'] ?? null)
        === ['aguardando_cpf'],
    'ConsultarPaciente declara estágio válido'
);

echo "\n2. RESULT PROJECTION CLÍNICA\n";

$bookingProjection = $builder->resultProjections()[
    'CriarAgendamento'
] ?? [];

assert_true(
    in_array(
        'data.agendamento_id',
        $bookingProjection['include_paths'] ?? [],
        true
    ),
    'CriarAgendamento possui whitelist de resultado'
);

assert_true(
    in_array(
        'meta',
        $bookingProjection['exclude_paths'] ?? [],
        true
    ),
    'metadados HTTP de CriarAgendamento são excluídos da IA'
);

echo "\n3. HARD GATE NO FLUXO CLÍNICO REAL\n";

$directory = sys_get_temp_dir()
    . '/guiche-v04-clinical-safety-'
    . bin2hex(random_bytes(4));

$store = new ContextStore(
    $directory,
    'America/Manaus'
);

$guiche = $builder
    ->withContextStore($store)
    ->withAgentContext(
        $config['agent_context'] ?? []
    )
    ->withReusableTools(
        $config['reusable_tools'] ?? []
    )
    ->withAiStateExcludePaths(
        $config['ai_state_exclude_paths'] ?? []
    )
    ->build();

$session = 'clinical-write';

$store->mergeState(
    $session,
    [
        'etapa' => 'pronto_para_agendar',
        'paciente_validado' => true,
        'paciente_id' => 123,
        'profissional' => [
            'id' => 77,
            'nome' => 'Profissional Teste',
        ],
        'data_agendamento' => '2026-10-20',
        'horario' => '09:30',
        'confirmado_pelo_usuario' => false,
    ]
);

$arguments = [
    'paciente_id' => 123,
    'data_inicio' => '2026-10-20',
    'hora_inicio' => '09:30',
    'profissional_id' => '77',
    'servico_id' => '28233',
    'titulo' => 'Paciente Teste',
];

expect_block(
    static fn () =>
        $guiche->executeTool(
            'CriarAgendamento',
            $arguments,
            $session
        ),
    'required_state_mismatch',
    'WRITE clínica sem confirmação é bloqueada'
);

assert_true(
    $http->posts === 0,
    'bloqueio de confirmação ocorre antes do HTTP'
);

$store->mergeState(
    $session,
    [
        'confirmado_pelo_usuario' => true,
    ]
);

$first = $guiche->executeTool(
    'CriarAgendamento',
    $arguments,
    $session
);

assert_true(
    ($first['success'] ?? false) === true
    && ($first['data']['agendamento_id'] ?? null)
        === 98765,
    'WRITE clínica autorizada executa normalmente'
);

assert_true(
    $http->posts === 1,
    'primeira escrita produz exatamente um POST'
);

$second = $guiche->executeTool(
    'CriarAgendamento',
    $arguments,
    $session
);

assert_true(
    $second === $first,
    'segunda escrita idêntica usa replay idempotente'
);

assert_true(
    $http->posts === 1,
    'replay idempotente não produz segundo POST'
);

$projected = $guiche->toolResultForAi(
    'CriarAgendamento',
    $first
);

assert_true(
    ($projected['data']['agendamento_id'] ?? null)
        === 98765,
    'projeção envia agendamento_id à IA'
);

assert_true(
    !isset($projected['meta'])
    && !isset($projected['input']),
    'projeção não envia payload HTTP nem input interno'
);

echo "\n4. PRECONDITION DE READ CLÍNICA\n";

$readSession = 'clinical-read';

$store->mergeState(
    $readSession,
    [
        'etapa' => 'aguardando_profissional',
    ]
);

expect_block(
    static fn () =>
        $guiche->executeTool(
            'ConsultarProfissional',
            [
                'nome_profissional' => 'Profissional Teste',
                'empresa_id' => 142,
                'sessao_id' => 'teste',
            ],
            $readSession
        ),
    'required_state_missing',
    'READ clínica sem convênio/procedimento é bloqueada por precondition'
);

assert_true(
    $sql->fetches === 0,
    'READ bloqueada não chega ao banco'
);

echo "\n5. FORBIDS_STATE APÓS AGENDAMENTO\n";

$store->mergeState(
    $session,
    [
        'agendamento_id' => 98765,
    ]
);

expect_block(
    static fn () =>
        $guiche->executeTool(
            'CriarAgendamento',
            [
                'paciente_id' => 123,
                'data_inicio' => '2026-10-20',
                'hora_inicio' => '10:00',
                'profissional_id' => '77',
                'servico_id' => '28233',
                'titulo' => 'Paciente Teste',
            ],
            $session
        ),
    'forbidden_state_present',
    'novo WRITE é bloqueado quando já existe agendamento_id'
);

assert_true(
    $http->posts === 1,
    'forbids_state evita novo side effect HTTP'
);

echo "\nOK: GUICHÊ v0.4.0 / integração clínica validada.\n";
