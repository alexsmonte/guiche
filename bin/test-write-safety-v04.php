<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

final class WriteSafetyProbeTool implements ToolInterface
{
    public int $executions = 0;

    public function __construct(
        private readonly string $toolName,
        private readonly bool $success = true
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Probe da proteção WRITE.';
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
                'nested' => [
                    'type' => 'object',
                ],
            ],
            'required' => ['value'],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->executions++;

        return [
            'success' => $this->success,
            'tool' => $this->toolName,
            'execution' => $this->executions,
            'value' => $arguments['value'] ?? null,
        ];
    }
}

final class WriteSafetyAi implements AiProviderInterface
{
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
        return [
            'id' => 'write-safety-initial',
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

        return [
            'id' => 'write-safety-final',
            'text' => 'final',
            'tool_calls' => [],
            'usage' => [],
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

function temp_store(string $suffix): ContextStore
{
    return new ContextStore(
        sys_get_temp_dir()
            . '/guiche-v04-write-'
            . $suffix
            . '-'
            . bin2hex(random_bytes(4)),
        'America/Manaus'
    );
}

function events_of_type(array $events, string $type): array
{
    return array_values(
        array_filter(
            $events,
            static fn (array $event): bool =>
                ($event['type'] ?? null) === $type
        )
    );
}

echo "\nGUICHÊ v0.4.0 — FASE 04 / WRITE SAFETY\n";
echo str_repeat('=', 52) . "\n\n";

echo "1. FINGERPRINT\n";

$fingerprintStore = temp_store('fingerprint');

$fpA = $fingerprintStore->toolFingerprint(
    'session-a',
    'WriteTool',
    [
        'value' => ' ABC ',
        'nested' => [
            'b' => 2,
            'a' => 1,
        ],
    ]
);

$fpB = $fingerprintStore->toolFingerprint(
    'session-a',
    'writetool',
    [
        'nested' => [
            'a' => 1,
            'b' => 2,
        ],
        'value' => 'abc',
    ]
);

$fpOtherSession = $fingerprintStore->toolFingerprint(
    'session-b',
    'WriteTool',
    [
        'value' => 'abc',
        'nested' => [
            'a' => 1,
            'b' => 2,
        ],
    ]
);

$fpOtherTool = $fingerprintStore->toolFingerprint(
    'session-a',
    'OtherWriteTool',
    [
        'value' => 'abc',
        'nested' => [
            'a' => 1,
            'b' => 2,
        ],
    ]
);

assert_true(
    $fpA === $fpB,
    'argumentos normalizados e nome da Tool produzem fingerprint estável'
);

assert_true(
    $fpA !== $fpOtherSession,
    'sessionId participa do fingerprint'
);

assert_true(
    $fpA !== $fpOtherTool,
    'nome da Tool participa do fingerprint'
);

echo "\n2. EXECUTETOOL() WRITE EXIGE SESSÃO\n";

$directTool = new WriteSafetyProbeTool('DirectWrite');
$directRegistry = new ToolRegistry();

$directRegistry->register(
    new ToolRegistration(
        $directTool,
        ['operation' => 'write']
    )
);

$directStore = temp_store('direct');
$directGuiche = new Guiche(
    tools: $directRegistry,
    context: $directStore
);

try {
    $directGuiche->executeTool(
        'DirectWrite',
        ['value' => 'x']
    );

    fail_test(
        'WRITE sem sessionId deveria ser bloqueada'
    );
} catch (ToolBlockedException $exception) {
    assert_true(
        $exception->reasonCode()
            === 'write_session_required',
        'WRITE direta sem sessionId é bloqueada'
    );
}

assert_true(
    $directTool->executions === 0,
    'WRITE sem sessionId não produz side effect'
);

echo "\n3. IDEMPOTÊNCIA DIRETA\n";

$first = $directGuiche->executeTool(
    'DirectWrite',
    ['value' => 'same'],
    'session-direct'
);

$second = $directGuiche->executeTool(
    'DirectWrite',
    ['value' => 'same'],
    'session-direct'
);

assert_true(
    $directTool->executions === 1,
    'WRITE duplicada na mesma sessão executa uma única vez'
);

assert_true(
    $second === $first,
    'replay idempotente devolve exatamente o resultado anterior'
);

$directGuiche->executeTool(
    'DirectWrite',
    ['value' => 'same'],
    'session-other'
);

assert_true(
    $directTool->executions === 2,
    'mesmos argumentos em sessão diferente são outra operação'
);

echo "\n4. WRITE NUNCA USA CACHE GENÉRICO\n";

$runtimeWrite = new WriteSafetyProbeTool('RuntimeWrite');
$runtimeRegistry = new ToolRegistry();

$runtimeRegistry->register(
    new ToolRegistration(
        $runtimeWrite,
        ['operation' => 'write']
    )
);

$runtimeAi = new WriteSafetyAi([
    [
        'call_id' => 'write-1',
        'name' => 'RuntimeWrite',
        'arguments' => ['value' => 'same'],
    ],
    [
        'call_id' => 'write-2',
        'name' => 'RuntimeWrite',
        'arguments' => ['value' => 'same'],
    ],
]);

$runtimeStore = temp_store('runtime-write');

$runtimeGuiche = new Guiche(
    tools: $runtimeRegistry,
    context: $runtimeStore,
    ai: $runtimeAi,
    reusableTools: ['RuntimeWrite']
);

$runtimeResponse = $runtimeGuiche->handle(
    'runtime-session',
    'execute duas vezes'
);

assert_true(
    $runtimeWrite->executions === 1,
    'WRITE repetida na mesma interação executa apenas uma vez'
);

assert_true(
    events_of_type(
        $runtimeResponse['events'],
        'tool_cache_hit'
    ) === [],
    'WRITE configurada como reusable ignora cache genérico'
);

$writeReplays = events_of_type(
    $runtimeResponse['events'],
    'tool_write_replay'
);

assert_true(
    count($writeReplays) === 1,
    'segunda WRITE usa replay idempotente explícito'
);

assert_true(
    isset($writeReplays[0]['fingerprint'])
    && is_string($writeReplays[0]['fingerprint'])
    && strlen($writeReplays[0]['fingerprint']) === 64,
    'evento de replay preserva fingerprint auditável'
);

$runtimeHistory = $runtimeStore->load(
    'runtime-session'
)['tool_history'] ?? [];

assert_true(
    count($runtimeHistory) === 1,
    'replay WRITE não cria segunda execução no tool_history'
);

echo "\n5. READ CONTINUA PODENDO USAR CACHE\n";

$readTool = new WriteSafetyProbeTool('ReadTool');
$readRegistry = new ToolRegistry();

$readRegistry->register(
    new ToolRegistration(
        $readTool,
        ['operation' => 'read']
    )
);

$readAi = new WriteSafetyAi([
    [
        'call_id' => 'read-1',
        'name' => 'ReadTool',
        'arguments' => ['value' => 'same'],
    ],
    [
        'call_id' => 'read-2',
        'name' => 'ReadTool',
        'arguments' => ['value' => 'same'],
    ],
]);

$readStore = temp_store('read');

$readGuiche = new Guiche(
    tools: $readRegistry,
    context: $readStore,
    ai: $readAi,
    reusableTools: ['ReadTool']
);

$readResponse = $readGuiche->handle(
    'read-session',
    'execute leitura'
);

assert_true(
    $readTool->executions === 1,
    'READ reutilizável mantém comportamento de cache'
);

assert_true(
    count(
        events_of_type(
            $readResponse['events'],
            'tool_cache_hit'
        )
    ) === 1,
    'READ produz tool_cache_hit na repetição'
);

assert_true(
    events_of_type(
        $readResponse['events'],
        'tool_write_replay'
    ) === [],
    'READ não usa mecanismo de replay WRITE'
);

echo "\n6. WRITE COM FALHA NÃO TEM RETRY AUTOMÁTICO\n";

$failedWrite = new WriteSafetyProbeTool(
    'FailedWrite',
    false
);

$failedRegistry = new ToolRegistry();

$failedRegistry->register(
    new ToolRegistration(
        $failedWrite,
        ['operation' => 'write']
    )
);

$failedAi = new WriteSafetyAi([
    [
        'call_id' => 'failed-1',
        'name' => 'FailedWrite',
        'arguments' => ['value' => 'fail'],
    ],
]);

$failedStore = temp_store('failed');

$failedGuiche = new Guiche(
    tools: $failedRegistry,
    context: $failedStore,
    ai: $failedAi,
    reusableTools: ['FailedWrite']
);

$failedGuiche->handle(
    'failed-session',
    'falhar'
);

assert_true(
    $failedWrite->executions === 1,
    'Core não repete automaticamente uma WRITE que falhou'
);

$failedHistory = $failedStore->load(
    'failed-session'
)['tool_history'] ?? [];

assert_true(
    count($failedHistory) === 1
    && ($failedHistory[0]['result']['success'] ?? true) === false,
    'falha WRITE permanece auditável no tool_history'
);

echo "\n7. AUDITORIA ESTÁTICA\n";

$source = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);

assert_true(
    is_string($source)
    && str_contains(
        $source,
        '&& !$isWrite'
    ),
    'cache genérico possui barreira explícita para WRITE'
);

assert_true(
    is_string($source)
    && str_contains(
        $source,
        "'type' => 'tool_write_replay'"
    ),
    'runtime expõe evento específico de idempotência WRITE'
);

$contextSource = file_get_contents(
    dirname(__DIR__) . '/src/ContextStore.php'
);

assert_true(
    is_string($contextSource)
    && str_contains(
        $contextSource,
        'public function toolFingerprint('
    ),
    'fingerprint é API explícita do ContextStore'
);

echo "\nOK: GUICHÊ v0.4.0 / FASE 04 validada.\n";
