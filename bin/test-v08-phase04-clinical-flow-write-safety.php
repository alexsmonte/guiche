<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use GuicheExample\ClinicaPiloto\ClinicStateManager;
use GuicheExample\ClinicaPiloto\Safety\ClinicalSlotRevalidator;
use GuicheExample\ClinicaPiloto\Tools\CriarAgendamentoTool;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkV08Phase04(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class Phase04Sql implements SqlExecutorInterface
{
    public int $fetchCalls = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly bool $fail = false
    ) {
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->fetchCalls++;

        if ($this->fail) {
            throw new RuntimeException('falha sintética de banco');
        }

        return $this->rows;
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return 1;
    }
}

final class Phase04Http implements HttpClientInterface
{
    public int $postCalls = 0;

    /** @var array<string, mixed> */
    public array $lastData = [];

    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        $this->postCalls++;
        $this->lastData = $data;

        return [
            'status' => 200,
            'body' => '{"agendamento_id":"phase04-booking-1"}',
            'json' => [
                'agendamento_id' => 'phase04-booking-1',
            ],
            'headers' => [],
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function bookingArgumentsV08Phase04(): array
{
    return [
        'paciente_id' => 9001,
        'data_inicio' => '2030-01-15',
        'hora_inicio' => '09:00',
        'profissional_id' => '101',
        'servico_id' => '201',
        'titulo' => 'Paciente Exemplo',
    ];
}

echo PHP_EOL;
echo "GUICHÊ v0.8.0 — FASE 04 / CLINICAL FLOW + WRITE SAFETY" . PHP_EOL;
echo str_repeat('=', 68) . PHP_EOL . PHP_EOL;

echo "1. SLOT DISPONÍVEL → READ FINAL + WRITE" . PHP_EOL;

$sqlAvailable = new Phase04Sql([
    ['horario_disponivel' => '15/01/2030 terça-feira 09:00'],
    ['horario_disponivel' => '15/01/2030 terça-feira 09:30'],
]);
$httpAvailable = new Phase04Http();
$revalidatorAvailable = new ClinicalSlotRevalidator(
    $sqlAvailable,
    'horarios_disponiveis_por_turno',
    1,
    3
);
$toolAvailable = new CriarAgendamentoTool(
    $httpAvailable,
    'https://example.invalid/api/agendamentos/novo.json',
    '',
    15,
    $revalidatorAvailable
);
$resultAvailable = $toolAvailable->execute(bookingArgumentsV08Phase04());

checkV08Phase04(
    ($resultAvailable['success'] ?? false) === true,
    'slot ainda disponível permite WRITE'
);
checkV08Phase04(
    $sqlAvailable->fetchCalls === 1,
    'disponibilidade é consultada novamente imediatamente antes do WRITE'
);
checkV08Phase04(
    $httpAvailable->postCalls === 1,
    'slot válido produz exatamente um POST'
);

echo PHP_EOL . "2. SLOT PERDIDO → FAIL CLOSED / ZERO POST" . PHP_EOL;

$sqlLost = new Phase04Sql([
    ['horario_disponivel' => '15/01/2030 terça-feira 10:00'],
]);
$httpLost = new Phase04Http();
$toolLost = new CriarAgendamentoTool(
    $httpLost,
    'https://example.invalid/api/agendamentos/novo.json',
    '',
    15,
    new ClinicalSlotRevalidator(
        $sqlLost,
        'horarios_disponiveis_por_turno',
        1,
        3
    )
);
$resultLost = $toolLost->execute(bookingArgumentsV08Phase04());

checkV08Phase04(
    ($resultLost['success'] ?? true) === false,
    'slot perdido bloqueia criação'
);
checkV08Phase04(
    ($resultLost['meta']['reason'] ?? null) === 'slot_unavailable',
    'bloqueio informa reason estável slot_unavailable'
);
checkV08Phase04(
    $httpLost->postCalls === 0,
    'slot perdido não produz side effect HTTP'
);
checkV08Phase04(
    !array_key_exists('raw', $resultLost['meta'] ?? []),
    'falha de revalidação não vaza retorno bruto'
);

echo PHP_EOL . "3. REVALIDAÇÃO INDISPONÍVEL → FAIL CLOSED" . PHP_EOL;

$sqlFailure = new Phase04Sql([], true);
$httpFailure = new Phase04Http();
$toolFailure = new CriarAgendamentoTool(
    $httpFailure,
    'https://example.invalid/api/agendamentos/novo.json',
    '',
    15,
    new ClinicalSlotRevalidator(
        $sqlFailure,
        'horarios_disponiveis_por_turno',
        1,
        3
    )
);
$resultFailure = $toolFailure->execute(bookingArgumentsV08Phase04());

checkV08Phase04(
    ($resultFailure['success'] ?? true) === false,
    'falha na consulta final bloqueia criação'
);
checkV08Phase04(
    ($resultFailure['meta']['reason'] ?? null) === 'slot_revalidation_failed',
    'falha técnica possui reason estável'
);
checkV08Phase04(
    $httpFailure->postCalls === 0,
    'falha técnica de revalidação não produz POST'
);

echo PHP_EOL . "4. COMPATIBILIDADE DA TOOL" . PHP_EOL;

$legacyHttp = new Phase04Http();
$legacyTool = new CriarAgendamentoTool(
    $legacyHttp,
    'https://example.invalid/api/agendamentos/novo.json',
    '',
    15
);
$legacyResult = $legacyTool->execute(bookingArgumentsV08Phase04());

checkV08Phase04(
    ($legacyResult['success'] ?? false) === true,
    'construtor legado com quatro argumentos continua válido'
);
checkV08Phase04(
    $legacyHttp->postCalls === 1,
    'uso direto legado mantém comportamento anterior'
);

echo PHP_EOL . "5. AGENDAMENTO JÁ EXISTENTE" . PHP_EOL;

$state = new ClinicStateManager();
$existing = $state->onToolResult(
    [
        'state' => [
            'etapa' => 'aguardando_cpf',
        ],
    ],
    'ConsultarPaciente',
    [
        'empresa_id' => 1,
        'cpf' => '00000000000',
    ],
    [
        'success' => true,
        'data' => [
            'cadastro_encontrado' => true,
            'paciente_id' => 9001,
            'nome' => 'Paciente Exemplo',
            'cpf' => '00000000000',
            'agendamento_encontrado' => true,
            'agendamentos' => [
                [
                    'agendamento_id' => 7001,
                    'data' => '2030-01-15',
                    'hora' => '09:00',
                ],
            ],
        ],
    ]
);

checkV08Phase04(
    ($existing['state']['etapa'] ?? null) === 'agendado',
    'agendamento existente encerra fluxo de novo agendamento'
);
checkV08Phase04(
    ($existing['state']['agendamento_id'] ?? null) === 7001,
    'ID do agendamento existente alimenta o mesmo forbids_state da Safety Layer'
);
checkV08Phase04(
    ($existing['state']['agendamento_existente'] ?? false) === true,
    'estado diferencia agendamento pré-existente'
);

$afterYes = $state->onUserMessage(
    [
        'state' => $existing['state'],
    ],
    'sim'
);

checkV08Phase04(
    ($afterYes['state']['etapa'] ?? null) !== 'pronto_para_agendar',
    'confirmação posterior não reabre WRITE após agendamento existente'
);

echo PHP_EOL . "6. PACIENTE SEM AGENDAMENTO EXISTENTE" . PHP_EOL;

$normal = $state->onToolResult(
    [
        'state' => [
            'etapa' => 'aguardando_cpf',
        ],
    ],
    'ConsultarPaciente',
    [
        'empresa_id' => 1,
        'cpf' => '00000000000',
    ],
    [
        'success' => true,
        'data' => [
            'cadastro_encontrado' => true,
            'paciente_id' => 9001,
            'nome' => 'Paciente Exemplo',
            'cpf' => '00000000000',
            'agendamento_encontrado' => false,
            'agendamentos' => [],
        ],
    ]
);

checkV08Phase04(
    ($normal['state']['etapa'] ?? null) === 'aguardando_confirmacao',
    'paciente sem agendamento preserva fluxo normal'
);

echo PHP_EOL . "7. INTEGRAÇÃO ESTÁTICA DO PLUGIN" . PHP_EOL;

$pluginText = (string)file_get_contents(
    dirname(__DIR__) . '/examples/clinica-piloto/src/ClinicPlugin.php'
);
$toolText = (string)file_get_contents(
    dirname(__DIR__) . '/examples/clinica-piloto/src/Tools/CriarAgendamentoTool.php'
);

checkV08Phase04(
    str_contains($pluginText, 'ClinicalSlotRevalidator'),
    'ClinicPlugin conhece revalidador clínico do exemplo'
);
checkV08Phase04(
    str_contains($pluginText, '$this->slotRevalidator()'),
    'ClinicPlugin injeta revalidador na Tool de WRITE'
);
checkV08Phase04(
    strpos($toolText, '$this->slotRevalidator->revalidate(')
        < strpos($toolText, '$this->http->postForm('),
    'revalidação aparece antes do POST no caminho da Tool'
);

echo PHP_EOL . "OK: GUICHÊ v0.8.0 / FASE 04 validada." . PHP_EOL;
