<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ProjectionProbeTool implements ToolInterface
{
    public int $executions = 0;

    public function __construct(
        private readonly string $toolName = 'ProjectionTool'
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Probe neutra para Result Projection.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
            ],
            'required' => ['value'],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->executions++;

        return [
            'success' => true,
            'public' => [
                'id' => 42,
                'label' => 'visível',
                'internal_hint' => 'não enviar',
            ],
            'secret' => [
                'token' => 'TOP-SECRET',
                'document' => 'CPF-INTERNO',
            ],
            'raw' => [
                'provider_payload' => 'muito-dado',
            ],
            'value' => $arguments['value'] ?? null,
        ];
    }
}

final class ProjectionAi implements AiProviderInterface
{
    public array $outputs = [];

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        return [
            'id' => 'projection-initial',
            'text' => '',
            'tool_calls' => [
                [
                    'call_id' => 'projection-1',
                    'name' => 'ProjectionTool',
                    'arguments' => [
                        'value' => 'teste',
                    ],
                ],
            ],
            'usage' => [],
        ];
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        $this->outputs = $toolOutputs;

        return [
            'id' => 'projection-final',
            'text' => 'final',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}

final class ProjectionStateManager implements StateManagerInterface
{
    public array $lastResult = [];

    public function onUserMessage(
        array $context,
        string $message
    ): array {
        return [];
    }

    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array {
        $this->lastResult = $result;

        return [];
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

function temp_store(string $suffix): ContextStore
{
    return new ContextStore(
        sys_get_temp_dir()
            . '/guiche-v04-projection-'
            . $suffix
            . '-'
            . bin2hex(random_bytes(4)),
        'America/Manaus'
    );
}

echo "\nGUICHÊ v0.4.0 — FASE 05 / RESULT PROJECTION\n";
echo str_repeat('=', 58) . "\n\n";

echo "1. INCLUDE_PATHS COMO WHITELIST\n";

$registry = new ToolRegistry();
$tool = new ProjectionProbeTool();

$registry->register(
    new ToolRegistration(
        $tool,
        ['operation' => 'read']
    )
);

$guiche = new Guiche(
    tools: $registry,
    context: temp_store('direct'),
    aiToolResultExcludePaths: [
        'ProjectionTool' => [
            'public.internal_hint',
            'secret.token',
        ],
    ],
    aiToolResultIncludePaths: [
        'ProjectionTool' => [
            'success',
            'public.id',
            'public.label',
            'public.internal_hint',
            'secret.token',
            'missing.path',
        ],
    ]
);

$full = [
    'success' => true,
    'public' => [
        'id' => 42,
        'label' => 'visível',
        'internal_hint' => 'não enviar',
    ],
    'secret' => [
        'token' => 'TOP-SECRET',
        'document' => 'CPF-INTERNO',
    ],
    'raw' => [
        'provider_payload' => 'muito-dado',
    ],
];

$projected = $guiche->toolResultForAi(
    'ProjectionTool',
    $full
);

assert_true(
    $projected === [
        'success' => true,
        'public' => [
            'id' => 42,
            'label' => 'visível',
        ],
    ],
    'include_paths cria whitelist e exclude_paths vence no corte final'
);

assert_true(
    !isset($projected['secret'])
    && !isset($projected['raw']),
    'campos fora da whitelist não chegam à IA'
);

echo "\n2. COMPATIBILIDADE EXCLUDE-ONLY\n";

$legacyGuiche = new Guiche(
    tools: $registry,
    context: temp_store('legacy'),
    aiToolResultExcludePaths: [
        'ProjectionTool' => [
            'secret.token',
            'raw.provider_payload',
        ],
    ]
);

$legacyProjected = $legacyGuiche->toolResultForAi(
    'ProjectionTool',
    $full
);

assert_true(
    ($legacyProjected['public']['id'] ?? null) === 42
    && ($legacyProjected['secret']['document'] ?? null)
        === 'CPF-INTERNO',
    'sem include_paths o comportamento legado preserva o restante do resultado'
);

assert_true(
    !isset($legacyProjected['secret']['token'])
    && !isset($legacyProjected['raw']['provider_payload']),
    'exclude_paths legado continua funcionando'
);

echo "\n3. BLOQUEIO USA ENVELOPE MÍNIMO DA SAFETY LAYER\n";

$blockedProjected = $guiche->toolResultForAi(
    'ProjectionTool',
    [
        'success' => false,
        'tool' => 'ProjectionTool',
        'blocked' => true,
        'reason' => 'policy_denied',
        'error' => 'bloqueado',
        'details' => [
            'current_stage' => 'interno',
            'allowed_tools' => ['A', 'B'],
        ],
        'secret' => 'não enviar',
    ]
);

assert_true(
    $blockedProjected === [
        'success' => false,
        'tool' => 'ProjectionTool',
        'blocked' => true,
        'reason' => 'policy_denied',
        'error' => 'bloqueado',
    ],
    'tool_blocked não vaza details internos para a IA'
);

echo "\n4. BUILDER COMPILA INCLUDE + EXCLUDE\n";

$builder = new GuicheBuilder();
$builderTool = new ProjectionProbeTool();

$builder
    ->withContextStore(temp_store('builder'))
    ->registerTool(
        new ToolRegistration(
            $builderTool,
            ['operation' => 'read']
        )
    )
    ->withAiToolResultIncludePaths([
        'ProjectionTool' => [
            'success',
        ],
    ])
    ->withAiToolResultExcludePaths([
        'ProjectionTool' => [
            'public.internal_hint',
        ],
    ])
    ->registerResultProjection(
        'ProjectionTool',
        [
            'include_paths' => [
                'public.id',
                'public.label',
                'secret.token',
            ],
            'exclude_paths' => [
                'secret.token',
            ],
        ]
    );

$built = $builder->build();

$builtProjected = $built->toolResultForAi(
    'ProjectionTool',
    $full
);

assert_true(
    $builtProjected === [
        'success' => true,
        'public' => [
            'id' => 42,
            'label' => 'visível',
        ],
    ],
    'GuicheBuilder combina configuração direta e Result Projection do Plugin'
);

echo "\n5. RESULTADO COMPLETO PERMANECE INTERNO\n";

$runtimeTool = new ProjectionProbeTool();
$runtimeRegistry = new ToolRegistry();

$runtimeRegistry->register(
    new ToolRegistration(
        $runtimeTool,
        ['operation' => 'read']
    )
);

$ai = new ProjectionAi();
$stateManager = new ProjectionStateManager();
$store = temp_store('runtime');

$runtime = new Guiche(
    tools: $runtimeRegistry,
    context: $store,
    ai: $ai,
    stateManager: $stateManager,
    aiToolResultExcludePaths: [
        'ProjectionTool' => [
            'public.internal_hint',
        ],
    ],
    aiToolResultIncludePaths: [
        'ProjectionTool' => [
            'success',
            'public.id',
            'public.label',
        ],
    ]
);

$response = $runtime->handle(
    'projection-session',
    'executar'
);

$history = $store->load(
    'projection-session'
)['tool_history'] ?? [];

assert_true(
    ($history[0]['result']['secret']['token'] ?? null)
        === 'TOP-SECRET',
    'tool_history preserva resultado completo internamente'
);

assert_true(
    ($stateManager->lastResult['secret']['token'] ?? null)
        === 'TOP-SECRET',
    'StateManager recebe resultado completo'
);

$aiOutput = $ai->outputs[0]['output'] ?? [];

assert_true(
    $aiOutput === [
        'success' => true,
        'public' => [
            'id' => 42,
            'label' => 'visível',
        ],
    ],
    'IA recebe somente a projeção autorizada'
);

$toolResultEvents = array_values(
    array_filter(
        $response['events'],
        static fn (array $event): bool =>
            ($event['type'] ?? null)
                === 'tool_result'
    )
);

assert_true(
    ($toolResultEvents[0]['result']['secret']['token'] ?? null)
        === 'TOP-SECRET',
    'evento interno tool_result mantém resultado completo'
);

echo "\n6. AUDITORIA ESTÁTICA\n";

$guicheSource = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);

$builderSource = file_get_contents(
    dirname(__DIR__) . '/src/GuicheBuilder.php'
);

assert_true(
    is_string($guicheSource)
    && str_contains(
        $guicheSource,
        'copyArrayPath('
    ),
    'Core possui cópia seletiva de paths'
);

assert_true(
    is_string($guicheSource)
    && str_contains(
        $guicheSource,
        'aiToolResultIncludePaths'
    ),
    'Guiche possui whitelist explícita de resultado'
);

assert_true(
    is_string($builderSource)
    && str_contains(
        $builderSource,
        "['include_paths']"
    ),
    'GuicheBuilder compila include_paths declarados por Plugins'
);

echo "\nOK: GUICHÊ v0.4.0 / FASE 05 validada.\n";
