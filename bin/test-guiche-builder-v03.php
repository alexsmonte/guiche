<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\ToolPolicy;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkV03F04(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

function propertyV03F04(object $object, string $name): mixed
{
    $property = new \ReflectionProperty(
        $object,
        $name
    );

    return $property->getValue($object);
}

final class RuntimeToolV03F04 implements ToolInterface
{
    public function __construct(
        private readonly string $toolName = 'RuntimePing',
        private readonly bool $withOwnPresentation = false
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Tool neutra usada no teste do Builder.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $result = [
            'success' => true,
            'tool' => $this->name(),
            'data' => [
                'value' => (string)($arguments['value'] ?? ''),
            ],
            'meta' => [
                'internal' => 'secret',
                'public' => 'visible',
            ],
        ];

        if ($this->withOwnPresentation) {
            $result['_guiche'] = [
                'presentation_message' => 'APRESENTAÇÃO DA TOOL',
            ];
        }

        return $result;
    }
}

final class RuntimePresenterV03F04 implements ResultPresenterInterface
{
    public function present(array $result): ?string
    {
        $value = trim(
            (string)($result['data']['value'] ?? '')
        );

        return $value === ''
            ? null
            : 'APRESENTADO: ' . $value;
    }
}

final class RuntimeStateManagerV03F04 implements StateManagerInterface
{
    public function onUserMessage(
        array $context,
        string $message
    ): array {
        return [
            'state' => [
                'runtime_message' => $message,
            ],
        ];
    }

    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array {
        return [
            'state' => [
                'runtime_tool' => $tool,
            ],
        ];
    }
}

final class RuntimePluginV03F04 implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder
            ->registerTool(
                new ToolRegistration(
                    new RuntimeToolV03F04(),
                    [
                        'plugin' => self::class,
                        'risk' => 'low',
                    ]
                )
            )
            ->registerPresenter(
                'RuntimePing',
                new RuntimePresenterV03F04()
            )
            ->useStateManager(
                new RuntimeStateManagerV03F04()
            )
            ->registerPolicy(
                'runtime.policy',
                [
                    'mode' => 'declarative',
                ]
            )
            ->addInstructionFragment(
                'runtime.instructions',
                'FRAGMENTO DO PLUGIN'
            )
            ->registerCompletionGuard(
                'runtime.guard',
                [
                    'state_key' => 'ready',
                    'state_value' => true,
                    'required_tool' => 'RuntimePing',
                    'fallback_message' => 'Fallback neutro.',
                ]
            )
            ->registerResultProjection(
                'RuntimePing',
                [
                    'exclude_paths' => [
                        'meta.internal',
                    ],
                ]
            );
    }
}

echo PHP_EOL . "1. BUILD / PRÉ-CONDIÇÕES" . PHP_EOL;

$missingContextRejected = false;

try {
    (new GuicheBuilder())->build();
} catch (\LogicException $exception) {
    $missingContextRejected = str_contains(
        $exception->getMessage(),
        'ContextStore'
    );
}

checkV03F04(
    $missingContextRejected,
    'build() exige ContextStore explicitamente'
);

$temp = sys_get_temp_dir()
    . '/guiche-v03-f04-'
    . bin2hex(random_bytes(4));

$context = new ContextStore(
    $temp,
    'America/Manaus'
);

$policy = new ToolPolicy();

$builder = (new GuicheBuilder())
    ->withContextStore($context)
    ->withInstructions('INSTRUÇÃO BASE')
    ->withAgentContext([
        'application' => 'neutral-test',
    ])
    ->withReusableTools([
        'RuntimePing',
        'RuntimePing',
    ])
    ->withCompletionGuards([
        [
            'state_key' => 'legacy',
            'state_value' => true,
            'required_tool' => 'RuntimePing',
            'fallback_message' => 'Legacy fallback.',
        ],
    ])
    ->withHistoryMessagesLimit(12)
    ->withAiStateExcludePaths([
        'internal.large_state',
    ])
    ->withAiToolResultExcludePaths([
        'RuntimePing' => [
            'input',
        ],
    ])
    ->withMaxToolRounds(7)
    ->useToolPolicy($policy)
    ->registerPlugin(
        new RuntimePluginV03F04()
    );

$guiche = $builder->build();

checkV03F04(
    $guiche instanceof Guiche,
    'build() produz uma instância real de Guiche'
);

echo PHP_EOL . "2. TOOL + PRESENTER NO RUNTIME" . PHP_EOL;

$definitions = $guiche->tools();

checkV03F04(
    count($definitions) === 1
    && ($definitions[0]['name'] ?? null) === 'RuntimePing',
    'Tool do Plugin aparece no runtime construído'
);

$result = $guiche->executeTool(
    'RuntimePing',
    ['value' => 'teste']
);

checkV03F04(
    ($result['data']['value'] ?? null) === 'teste',
    'Tool registrada pelo Plugin executa pelo Guiche'
);

checkV03F04(
    ($result['_guiche']['presentation_message'] ?? null)
        === 'APRESENTADO: teste',
    'PresenterRegistry é aplicado à execução da Tool'
);

$ownRegistry = new ToolRegistry();
$ownRegistry->register(
    new RuntimeToolV03F04(
        'OwnPresentation',
        true
    )
);

$ownBuilder = new GuicheBuilder(
    $ownRegistry
);

$ownBuilder
    ->withContextStore(
        new ContextStore(
            $temp . '-own',
            'America/Manaus'
        )
    )
    ->registerPresenter(
        'OwnPresentation',
        new RuntimePresenterV03F04()
    );

$ownGuiche = $ownBuilder->build();

$ownResult = $ownGuiche->executeTool(
    'OwnPresentation',
    ['value' => 'teste']
);

checkV03F04(
    ($ownResult['_guiche']['presentation_message'] ?? null)
        === 'APRESENTAÇÃO DA TOOL',
    'apresentação já produzida pela Tool da v0.2 tem precedência'
);

echo PHP_EOL . "3. STATE MANAGER + CONFIGURAÇÃO" . PHP_EOL;

checkV03F04(
    propertyV03F04($guiche, 'stateManager')
        instanceof RuntimeStateManagerV03F04,
    'StateManager do Plugin foi injetado no runtime'
);

$instructions = propertyV03F04(
    $guiche,
    'instructions'
);

checkV03F04(
    str_contains($instructions, 'INSTRUÇÃO BASE')
    && str_contains($instructions, 'FRAGMENTO DO PLUGIN')
    && strpos($instructions, 'INSTRUÇÃO BASE')
        < strpos($instructions, 'FRAGMENTO DO PLUGIN'),
    'instrução base e fragments são compilados em ordem'
);

checkV03F04(
    propertyV03F04($guiche, 'agentContext')
        === ['application' => 'neutral-test'],
    'agentContext é composto pelo Builder'
);

checkV03F04(
    propertyV03F04($guiche, 'reusableTools')
        === ['RuntimePing'],
    'reusableTools são normalizadas'
);

checkV03F04(
    propertyV03F04($guiche, 'historyMessagesLimit') === 12,
    'historyMessagesLimit é composto pelo Builder'
);

checkV03F04(
    propertyV03F04($guiche, 'maxToolRounds') === 7,
    'maxToolRounds é composto pelo Builder'
);

echo PHP_EOL . "4. GUARDS + RESULT PROJECTIONS" . PHP_EOL;

$guards = propertyV03F04(
    $guiche,
    'completionGuards'
);

checkV03F04(
    count($guards) === 2,
    'guards existentes e guards de Plugin são combinados'
);

$excludePaths = propertyV03F04(
    $guiche,
    'aiToolResultExcludePaths'
);

checkV03F04(
    ($excludePaths['RuntimePing'] ?? [])
        === ['input', 'meta.internal'],
    'Result Projection do Plugin compõe paths já existentes'
);

$compact = $guiche->toolResultForAi(
    'RuntimePing',
    [
        'input' => ['value' => 'x'],
        'data' => ['value' => 'x'],
        'meta' => [
            'internal' => 'secret',
            'public' => 'visible',
        ],
    ]
);

checkV03F04(
    !isset($compact['input'])
    && !isset($compact['meta']['internal'])
    && ($compact['meta']['public'] ?? null) === 'visible',
    'Result Projection atua somente na cópia enviada à IA'
);

echo PHP_EOL . "5. TOOL POLICY EXISTENTE" . PHP_EOL;

checkV03F04(
    propertyV03F04($guiche, 'toolPolicy') === $policy,
    'ToolPolicy da v0.2 pode ser ligada pelo Builder'
);

checkV03F04(
    ($builder->policies()['runtime.policy']['mode'] ?? null)
        === 'declarative',
    'Policy declarativa do Plugin permanece disponível separadamente'
);

echo PHP_EOL . "6. SNAPSHOT E COMPATIBILIDADE" . PHP_EOL;

$builder->registerTool(
    new RuntimeToolV03F04('LateTool')
);

checkV03F04(
    !$guiche->tools()
        || !in_array(
            'LateTool',
            array_column($guiche->tools(), 'name'),
            true
        ),
    'runtime construído é snapshot e não muda após novo registro no Builder'
);

$manualGuiche = new Guiche(
    tools: new ToolRegistry(),
    context: new ContextStore(
        $temp . '-manual',
        'America/Manaus'
    )
);

checkV03F04(
    $manualGuiche instanceof Guiche,
    'construção manual da v0.2 continua válida'
);

echo PHP_EOL . "7. FRONTEIRA v0.3 / v0.4" . PHP_EOL;

$guicheSource = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);

checkV03F04(
    is_string($guicheSource)
    && !str_contains($guicheSource, 'PluginInterface')
    && !str_contains($guicheSource, 'GuicheBuilder')
    && !str_contains($guicheSource, 'PresenterRegistry'),
    'Guiche continua sem depender da infraestrutura de Plugins'
);

$builderSource = file_get_contents(
    dirname(__DIR__) . '/src/GuicheBuilder.php'
);

checkV03F04(
    is_string($builderSource)
    && !str_contains($builderSource, 'hardExecutionGate')
    && !str_contains($builderSource, 'authorizeToolExecution'),
    'Fase 04 não introduz hard execution gate'
);

echo PHP_EOL;
echo "OK: GUICHÊ v0.3.0 / FASE 04 validada." . PHP_EOL;
