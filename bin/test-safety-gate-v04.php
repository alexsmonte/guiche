<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\Safety\ToolExecutionGate;
use Asmpkg\Guiche\ToolPolicy;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

final class SafetyGateProbeTool implements ToolInterface
{
    public int $executions = 0;

    public function __construct(
        private readonly string $toolName = 'SafetyProbe'
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Tool neutra usada para validar a Safety Layer.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'string',
                    'minLength' => 1,
                ],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $this->executions++;

        return [
            'success' => true,
            'value' => $arguments['value'] ?? null,
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
        $message
        . " — execução deveria ter sido bloqueada com {$reason}."
    );
}

echo "\nGUICHÊ v0.4.0 — FASE 02 / SAFETY GATE\n";
echo str_repeat('=', 52) . "\n\n";

echo "1. REGISTRO E OPERAÇÃO\n";

$registry = new ToolRegistry();
$gate = new ToolExecutionGate($registry);

expect_block(
    static fn () =>
        $gate->assertCanExecute(
            'NaoExiste',
            [],
            []
        ),
    'tool_not_registered',
    'Tool inexistente é bloqueada antes do runtime'
);

$unknownOperationTool = new SafetyGateProbeTool(
    'UnknownOperation'
);

$registry->register(
    new ToolRegistration(
        $unknownOperationTool,
        [
            'operation' => 'delete-everything',
        ]
    )
);

expect_block(
    static fn () =>
        $gate->assertCanExecute(
            'UnknownOperation',
            ['value' => 'ok'],
            []
        ),
    'operation_invalid',
    'operation desconhecida falha fechada'
);

$legacyTool = new SafetyGateProbeTool('LegacyRead');

$registry->register(
    new ToolRegistration(
        $legacyTool,
        []
    )
);

$registration = $gate->assertCanExecute(
    'LegacyRead',
    ['value' => 'ok'],
    []
);

assert_true(
    $registration->name() === 'LegacyRead',
    'registro legado sem operation mantém compatibilidade READ'
);

assert_true(
    $legacyTool->executions === 0,
    'autorizar no gate não executa a Tool'
);

echo "\n2. TOOL POLICY\n";

$policyTool = new SafetyGateProbeTool('PolicyTool');
$policyRegistry = new ToolRegistry();

$policyRegistry->register(
    new ToolRegistration(
        $policyTool,
        [
            'operation' => 'read',
        ]
    )
);

$policy = new ToolPolicy(
    [
        'allowed' => ['PolicyTool'],
        'denied' => [],
    ],
    [],
    [],
    'state.stage'
);

$policyGate = new ToolExecutionGate(
    $policyRegistry,
    $policy
);

$policyGate->assertCanExecute(
    'PolicyTool',
    ['value' => 'ok'],
    [
        'state' => [
            'stage' => 'allowed',
        ],
    ]
);

pass('ToolPolicy autoriza Tool no estágio permitido');

expect_block(
    static fn () =>
        $policyGate->assertCanExecute(
            'PolicyTool',
            ['value' => 'ok'],
            [
                'state' => [
                    'stage' => 'denied',
                ],
            ]
        ),
    'policy_denied',
    'ToolPolicy bloqueia Tool mesmo quando ela está registrada'
);

echo "\n3. ALLOWED STAGES\n";

$stageTool = new SafetyGateProbeTool('StageTool');
$stageRegistry = new ToolRegistry();

$stageRegistry->register(
    new ToolRegistration(
        $stageTool,
        [
            'operation' => 'read',
            'allowed_stages' => [
                'review',
                'ready',
            ],
            'stage_path' => 'workflow.phase',
        ]
    )
);

$stageGate = new ToolExecutionGate($stageRegistry);

$stageGate->assertCanExecute(
    'StageTool',
    ['value' => 'ok'],
    [
        'workflow' => [
            'phase' => 'ready',
        ],
    ]
);

pass('allowed_stages aceita stage_path neutro de domínio');

expect_block(
    static fn () =>
        $stageGate->assertCanExecute(
            'StageTool',
            ['value' => 'ok'],
            [
                'workflow' => [
                    'phase' => 'draft',
                ],
            ]
        ),
    'stage_not_allowed',
    'allowed_stages bloqueia estágio incompatível'
);

echo "\n4. REQUIRES STATE\n";

$requiredTool = new SafetyGateProbeTool('RequiredTool');
$requiredRegistry = new ToolRegistry();

$requiredRegistry->register(
    new ToolRegistration(
        $requiredTool,
        [
            'operation' => 'read',
            'requires_state' => [
                'state.customer_id',
                'state.ready' => true,
            ],
        ]
    )
);

$requiredGate = new ToolExecutionGate($requiredRegistry);

expect_block(
    static fn () =>
        $requiredGate->assertCanExecute(
            'RequiredTool',
            ['value' => 'ok'],
            [
                'state' => [
                    'ready' => true,
                ],
            ]
        ),
    'required_state_missing',
    'requires_state bloqueia caminho obrigatório ausente'
);

expect_block(
    static fn () =>
        $requiredGate->assertCanExecute(
            'RequiredTool',
            ['value' => 'ok'],
            [
                'state' => [
                    'customer_id' => 10,
                    'ready' => false,
                ],
            ]
        ),
    'required_state_mismatch',
    'requires_state bloqueia valor obrigatório incompatível'
);

$requiredGate->assertCanExecute(
    'RequiredTool',
    ['value' => 'ok'],
    [
        'state' => [
            'customer_id' => 10,
            'ready' => true,
        ],
    ]
);

pass('requires_state permite contexto completo');

echo "\n5. FORBIDS STATE\n";

$forbiddenTool = new SafetyGateProbeTool('ForbiddenTool');
$forbiddenRegistry = new ToolRegistry();

$forbiddenRegistry->register(
    new ToolRegistration(
        $forbiddenTool,
        [
            'operation' => 'write',
            'forbids_state' => [
                'state.locked' => true,
                'state.cancelled',
            ],
        ]
    )
);

$forbiddenGate = new ToolExecutionGate(
    $forbiddenRegistry
);

expect_block(
    static fn () =>
        $forbiddenGate->assertCanExecute(
            'ForbiddenTool',
            ['value' => 'ok'],
            [
                'state' => [
                    'locked' => true,
                ],
            ]
        ),
    'forbidden_state_match',
    'forbids_state bloqueia valor proibido'
);

expect_block(
    static fn () =>
        $forbiddenGate->assertCanExecute(
            'ForbiddenTool',
            ['value' => 'ok'],
            [
                'state' => [
                    'locked' => false,
                    'cancelled' => false,
                ],
            ]
        ),
    'forbidden_state_present',
    'forbids_state por existência bloqueia mesmo valor false'
);

$forbiddenGate->assertCanExecute(
    'ForbiddenTool',
    ['value' => 'ok'],
    [
        'state' => [
            'locked' => false,
        ],
    ]
);

pass('forbids_state permite contexto sem estado incompatível');

echo "\n6. CONFIRMAÇÃO\n";

$confirmationTool = new SafetyGateProbeTool(
    'ConfirmationTool'
);

$confirmationRegistry = new ToolRegistry();

$confirmationRegistry->register(
    new ToolRegistration(
        $confirmationTool,
        [
            'operation' => 'write',
            'confirmation' =>
                'state.confirmed_by_user',
        ]
    )
);

$confirmationGate = new ToolExecutionGate(
    $confirmationRegistry
);

expect_block(
    static fn () =>
        $confirmationGate->assertCanExecute(
            'ConfirmationTool',
            ['value' => 'ok'],
            [
                'state' => [
                    'confirmed_by_user' => false,
                ],
            ]
        ),
    'confirmation_required',
    'WRITE declarada pode exigir confirmação explícita'
);

$confirmationGate->assertCanExecute(
    'ConfirmationTool',
    ['value' => 'ok'],
    [
        'state' => [
            'confirmed_by_user' => true,
        ],
    ]
);

pass('confirmação válida libera a autorização');

echo "\n7. ARGUMENTOS\n";

$argumentTool = new SafetyGateProbeTool(
    'ArgumentTool'
);

$argumentRegistry = new ToolRegistry();

$argumentRegistry->register(
    new ToolRegistration(
        $argumentTool,
        [
            'operation' => 'read',
        ]
    )
);

$argumentGate = new ToolExecutionGate(
    $argumentRegistry
);

$blocked = expect_block(
    static fn () =>
        $argumentGate->assertCanExecute(
            'ArgumentTool',
            [],
            []
        ),
    'invalid_arguments',
    'schema inválido bloqueia antes da Tool'
);

assert_true(
    ($blocked->details()['errors'] ?? []) !== [],
    'bloqueio de argumentos preserva erros estruturados'
);

$argumentGate->assertCanExecute(
    'ArgumentTool',
    ['value' => 'valid'],
    []
);

assert_true(
    $argumentTool->executions === 0,
    'argumentos válidos não causam side effect na Fase 02'
);

echo "\n8. FRONTEIRA DA FASE\n";

$guicheSource = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);

assert_true(
    class_exists(ToolExecutionGate::class),
    'ToolExecutionGate permanece disponível após evolução do runtime'
);

$gateSource = file_get_contents(
    dirname(__DIR__) . '/src/Safety/ToolExecutionGate.php'
);

assert_true(
    is_string($gateSource)
    && !str_contains(
        $gateSource,
        '->execute('
    ),
    'gate continua autorizador e não executa Tool diretamente'
);

$registrySource = file_get_contents(
    dirname(__DIR__) . '/src/ToolRegistry.php'
);

assert_true(
    is_string($registrySource)
    && !str_contains(
        $registrySource,
        'ToolExecutionGate'
    ),
    'ToolRegistry continua sem enforcement interno nesta fase'
);

echo "\nOK: GUICHÊ v0.4.0 / FASE 02 validada.\n";
