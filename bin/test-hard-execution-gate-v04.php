<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\ToolPolicy;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

final class HardGateProbeTool implements ToolInterface
{
    public int $executions = 0;

    public function __construct(
        private readonly string $toolName
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Probe neutra para validar o hard execution gate.';
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
            'tool' => $this->toolName,
            'value' => $arguments['value'] ?? null,
        ];
    }
}

final class ForgedCallAi implements AiProviderInterface
{
    public array $initialTools = [];
    public array $continuedTools = [];
    public array $outputs = [];

    public function __construct(
        private readonly array $toolCalls
    ) {
    }

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->initialTools = $tools;

        return [
            'id' => 'response-initial',
            'text' => '',
            'tool_calls' => $this->toolCalls,
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
        $this->continuedTools = $tools;

        return [
            'id' => 'response-final',
            'text' => 'final',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}

final class StaleStateManager implements StateManagerInterface
{
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
        if (
            $tool === 'StaleTool'
            && ($result['success'] ?? false) === true
        ) {
            return [
                'state' => [
                    'stage' => 'second',
                ],
            ];
        }

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
        . " — chamada deveria ter sido bloqueada."
    );
}

function temp_store(string $suffix): ContextStore
{
    $directory = sys_get_temp_dir()
        . '/guiche-v04-hard-gate-'
        . $suffix
        . '-'
        . bin2hex(random_bytes(4));

    return new ContextStore(
        $directory,
        'America/Manaus'
    );
}

function event_by_type(
    array $events,
    string $type
): ?array {
    foreach ($events as $event) {
        if (($event['type'] ?? null) === $type) {
            return $event;
        }
    }

    return null;
}

echo "\nGUICHÊ v0.4.0 — FASE 03 / HARD EXECUTION GATE\n";
echo str_repeat('=', 58) . "\n\n";

echo "1. EXECUTETOOL() NÃO PODE MAIS CONTORNAR POLICY\n";

$directTool = new HardGateProbeTool('DirectProtected');
$directRegistry = new ToolRegistry();

$directRegistry->register(
    new ToolRegistration(
        $directTool,
        [
            'operation' => 'write',
            'confirmation' => 'state.confirmed',
        ]
    )
);

$directStore = temp_store('direct');
$directSession = 'session-direct';

$directStore->mergeState(
    $directSession,
    [
        'stage' => 'denied',
        'confirmed' => true,
    ]
);

$directGuiche = new Guiche(
    tools: $directRegistry,
    context: $directStore
);

$directGuiche->setToolPolicy(
    new ToolPolicy(
        [
            'ready' => ['DirectProtected'],
            'denied' => [],
        ],
        [],
        [],
        'state.stage'
    )
);

expect_block(
    static fn () =>
        $directGuiche->executeTool(
            'DirectProtected',
            ['value' => 'x'],
            $directSession
        ),
    'policy_denied',
    'executeTool() respeita ToolPolicy'
);

assert_true(
    $directTool->executions === 0,
    'Tool bloqueada por executeTool() não executa side effect'
);

$directStore->mergeState(
    $directSession,
    [
        'stage' => 'ready',
        'confirmed' => true,
    ]
);

$result = $directGuiche->executeTool(
    'DirectProtected',
    ['value' => 'ok'],
    $directSession
);

assert_true(
    ($result['success'] ?? false) === true,
    'executeTool() permitido continua funcional'
);

assert_true(
    $directTool->executions === 1,
    'executeTool() executa exatamente uma vez quando autorizado'
);

echo "\n2. FORGED TOOL CALL DA IA\n";

$visibleTool = new HardGateProbeTool('VisibleTool');
$hiddenTool = new HardGateProbeTool('HiddenTool');
$forgedRegistry = new ToolRegistry();

$forgedRegistry->register(
    new ToolRegistration(
        $visibleTool,
        ['operation' => 'read']
    )
);

$forgedRegistry->register(
    new ToolRegistration(
        $hiddenTool,
        ['operation' => 'write']
    )
);

$forgedAi = new ForgedCallAi([
    [
        'call_id' => 'forged-1',
        'name' => 'HiddenTool',
        'arguments' => [
            'value' => 'forged',
        ],
    ],
]);

$forgedStore = temp_store('forged');
$forgedSession = 'session-forged';

$forgedStore->mergeState(
    $forgedSession,
    [
        'stage' => 'ready',
    ]
);

$forgedGuiche = new Guiche(
    tools: $forgedRegistry,
    context: $forgedStore,
    ai: $forgedAi
);

$forgedGuiche->setToolPolicy(
    new ToolPolicy(
        [
            'ready' => ['VisibleTool'],
        ],
        [],
        [],
        'state.stage'
    )
);

$forgedResponse = $forgedGuiche->handle(
    $forgedSession,
    'teste forged'
);

$initialNames = array_map(
    static fn (array $definition): string =>
        (string)($definition['name'] ?? ''),
    $forgedAi->initialTools
);

assert_true(
    $initialNames === ['VisibleTool'],
    'ToolPolicy continua filtrando o que é exposto à IA'
);

assert_true(
    $hiddenTool->executions === 0,
    'Tool não exposta mas forjada pela IA não executa'
);

$blockedEvent = event_by_type(
    $forgedResponse['events'],
    'tool_blocked'
);

assert_true(
    is_array($blockedEvent)
    && ($blockedEvent['name'] ?? null) === 'HiddenTool'
    && ($blockedEvent['reason'] ?? null) === 'policy_denied',
    'forged tool call produz evento tool_blocked auditável'
);

assert_true(
    (
        $forgedAi->outputs[0]['output']['blocked']
        ?? false
    ) === true,
    'resultado bloqueado é devolvido à IA como envelope estruturado'
);

assert_true(
    (
        $forgedAi->outputs[0]['output']['reason']
        ?? null
    ) === 'policy_denied',
    'IA recebe reason code do bloqueio'
);

$forgedContext = $forgedStore->load(
    $forgedSession
);

assert_true(
    ($forgedContext['tool_history'] ?? []) === [],
    'chamada bloqueada não é registrada como execução de Tool'
);

echo "\n3. STALE TOOL CALL ENTRE CALLS DA MESMA RESPOSTA\n";

$staleTool = new HardGateProbeTool('StaleTool');
$staleRegistry = new ToolRegistry();

$staleRegistry->register(
    new ToolRegistration(
        $staleTool,
        ['operation' => 'write']
    )
);

$staleAi = new ForgedCallAi([
    [
        'call_id' => 'stale-1',
        'name' => 'StaleTool',
        'arguments' => [
            'value' => 'first',
        ],
    ],
    [
        'call_id' => 'stale-2',
        'name' => 'StaleTool',
        'arguments' => [
            'value' => 'second',
        ],
    ],
]);

$staleStore = temp_store('stale');
$staleSession = 'session-stale';

$staleStore->mergeState(
    $staleSession,
    [
        'stage' => 'first',
    ]
);

$staleGuiche = new Guiche(
    tools: $staleRegistry,
    context: $staleStore,
    ai: $staleAi,
    stateManager: new StaleStateManager()
);

$staleGuiche->setToolPolicy(
    new ToolPolicy(
        [
            'first' => ['StaleTool'],
            'second' => [],
        ],
        [],
        [],
        'state.stage'
    )
);

$staleResponse = $staleGuiche->handle(
    $staleSession,
    'teste stale'
);

assert_true(
    $staleTool->executions === 1,
    'primeira call autorizada executa e segunda stale não executa'
);

$staleContext = $staleStore->load(
    $staleSession
);

assert_true(
    ($staleContext['state']['stage'] ?? null) === 'second',
    'primeira Tool alterou o estado antes da segunda call'
);

assert_true(
    count($staleContext['tool_history'] ?? []) === 1,
    'somente a execução real entra no tool_history'
);

$blockedEvents = array_values(
    array_filter(
        $staleResponse['events'],
        static fn (array $event): bool =>
            ($event['type'] ?? null) === 'tool_blocked'
    )
);

assert_true(
    count($blockedEvents) === 1
    && ($blockedEvents[0]['call_id'] ?? null) === 'stale-2'
    && ($blockedEvents[0]['reason'] ?? null) === 'policy_denied',
    'segunda call é revalidada contra o estado novo e bloqueada'
);

assert_true(
    (
        $staleAi->outputs[0]['output']['success']
        ?? false
    ) === true,
    'primeiro output mantém resultado real'
);

assert_true(
    (
        $staleAi->outputs[1]['output']['blocked']
        ?? false
    ) === true,
    'segundo output informa bloqueio sem executar Tool'
);

echo "\n4. GATE ANTES DE CACHE E IMEDIATAMENTE ANTES DE EXECUTE\n";

$source = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);

assert_true(
    is_string($source)
    && substr_count(
        $source,
        'assertCanExecute('
    ) >= 3,
    'Guiche possui gates nos caminhos direto e runtime'
);

$gate1 = strpos(
    $source,
    'Gate 1:'
);

$cache = strpos(
    $source,
    'findSuccessfulToolResult',
    $gate1 !== false ? $gate1 : 0
);

$gate2 = strpos(
    $source,
    'Gate 2:'
);

$execute = strpos(
    $source,
    '$this->tools->execute(',
    $gate2 !== false ? $gate2 : 0
);

assert_true(
    $gate1 !== false
    && $cache !== false
    && $gate1 < $cache,
    'autorização acontece antes do cache/reuso'
);

assert_true(
    $gate2 !== false
    && $execute !== false
    && $gate2 < $execute,
    'revalidação acontece imediatamente antes da execução real'
);

echo "\nOK: GUICHÊ v0.4.0 / FASE 03 validada.\n";
