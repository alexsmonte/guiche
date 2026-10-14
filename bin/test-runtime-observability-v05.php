<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\ToolRegistry;

function checkObsV05(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException(
            'FALHOU: ' . $message
        );
    }

    echo 'OK: ' . $message . PHP_EOL;
}

function rmTreeObsV05(
    string $directory
): void {
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory
            . DIRECTORY_SEPARATOR
            . $entry;

        if (is_dir($path)) {
            rmTreeObsV05($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function eventTypesV05(array $events): array
{
    return array_values(
        array_map(
            static fn (array $event): string =>
                (string) (
                    $event['type']
                    ?? ''
                ),
            $events
        )
    );
}

function eventsOfTypeV05(
    array $events,
    string $type
): array {
    return array_values(
        array_filter(
            $events,
            static fn (array $event): bool =>
                ($event['type'] ?? null)
                    === $type
        )
    );
}

function registerWithMetadataV05(
    ToolRegistry $registry,
    ToolInterface $tool,
    array $metadata
): void {
    $reflection =
        new ReflectionClass(
            ToolRegistration::class
        );

    $constructor =
        $reflection->getConstructor();

    if ($constructor === null) {
        throw new RuntimeException(
            'ToolRegistration sem construtor.'
        );
    }

    $arguments = [];

    foreach (
        $constructor->getParameters()
        as $parameter
    ) {
        $name = mb_strtolower(
            $parameter->getName(),
            'UTF-8'
        );

        if (
            $name === 'tool'
            || str_contains(
                $name,
                'tool'
            )
        ) {
            $arguments[] = $tool;
            continue;
        }

        if (
            $name === 'metadata'
            || $name === 'meta'
            || str_contains(
                $name,
                'metadata'
            )
        ) {
            $arguments[] = $metadata;
            continue;
        }

        if (
            $parameter
                ->isDefaultValueAvailable()
        ) {
            $arguments[] =
                $parameter
                    ->getDefaultValue();

            continue;
        }

        throw new RuntimeException(
            'Parâmetro inesperado em ToolRegistration: '
            . $parameter->getName()
        );
    }

    $registration =
        $reflection
            ->newInstanceArgs(
                $arguments
            );

    $registry->register(
        $registration
    );
}

final class CollectorObsV05
    implements RuntimeEventSinkInterface
{
    public array $events = [];

    public function record(array $event): void
    {
        $this->events[] = $event;
    }
}

final class ScriptedAiObsV05
    implements AiProviderInterface
{
    private int $index = 0;

    public function __construct(
        private readonly array $script,
        private readonly int $delayUs = 1000
    ) {
    }

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        return $this->next();
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        return $this->next();
    }

    private function next(): array
    {
        if ($this->delayUs > 0) {
            usleep($this->delayUs);
        }

        $item =
            $this->script[
                $this->index
            ]
            ?? null;

        $this->index++;

        if ($item instanceof Throwable) {
            throw $item;
        }

        if (!is_array($item)) {
            throw new RuntimeException(
                'Script da IA esgotado.'
            );
        }

        return $item;
    }
}

final class ProbeToolObsV05
    implements ToolInterface
{
    public int $executions = 0;

    public function __construct(
        private readonly string $toolName,
        private readonly bool $fail = false,
        private readonly int $delayUs = 1000
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Tool de teste de observabilidade.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $this->executions++;

        if ($this->delayUs > 0) {
            usleep($this->delayUs);
        }

        if ($this->fail) {
            throw new RuntimeException(
                'TOOL_SECRET_EXCEPTION_MESSAGE'
            );
        }

        return [
            'success' => true,
            'tool' => $this->toolName,
            'data' => [
                'secret_result' =>
                    'TOOL_SECRET_RESULT',
            ],
        ];
    }
}

final class FailingToolHistoryStoreObsV05
    implements ContextStoreInterface
{
    public function __construct(
        private readonly ContextStoreInterface $inner
    ) {
    }

    public function load(string $sessionId): array
    {
        return $this->inner->load($sessionId);
    }

    public function save(
        string $sessionId,
        array $context
    ): void {
        $this->inner->save(
            $sessionId,
            $context
        );
    }

    public function delete(string $sessionId): void
    {
        $this->inner->delete($sessionId);
    }

    public function addMessage(
        string $sessionId,
        string $role,
        string $content
    ): array {
        return $this->inner->addMessage(
            $sessionId,
            $role,
            $content
        );
    }

    public function setIntent(
        string $sessionId,
        ?string $intent
    ): array {
        return $this->inner->setIntent(
            $sessionId,
            $intent
        );
    }

    public function mergeState(
        string $sessionId,
        array $state
    ): array {
        return $this->inner->mergeState(
            $sessionId,
            $state
        );
    }

    public function applyPatch(
        string $sessionId,
        array $patch
    ): array {
        return $this->inner->applyPatch(
            $sessionId,
            $patch
        );
    }

    public function addToolExecution(
        string $sessionId,
        string $tool,
        array $arguments,
        array $result
    ): array {
        throw new RuntimeException(
            'PERSISTENCE_SECRET_FAILURE'
        );
    }

    public function findSuccessfulToolResult(
        string $sessionId,
        string $tool,
        array $arguments
    ): ?array {
        return $this->inner
            ->findSuccessfulToolResult(
                $sessionId,
                $tool,
                $arguments
            );
    }

    public function recordInteractionUsage(
        string $sessionId,
        array $usage
    ): array {
        return $this->inner
            ->recordInteractionUsage(
                $sessionId,
                $usage
            );
    }

    public function toolFingerprint(
        string $sessionId,
        string $tool,
        array $arguments
    ): string {
        return $this->inner
            ->toolFingerprint(
                $sessionId,
                $tool,
                $arguments
            );
    }
}

function aiResponseV05(
    string $id,
    string $text = '',
    array $toolCalls = [],
    array $usage = [
        'input_tokens' => 5,
        'output_tokens' => 2,
        'total_tokens' => 7,
    ]
): array {
    return [
        'id' => $id,
        'response_id' => $id,
        'text' => $text,
        'output_text' => $text,
        'message' => $text,
        'tool_calls' => $toolCalls,
        'usage' => $usage,
        'model' => 'fake-observability',
    ];
}

function toolCallV05(
    string $name,
    string $callId,
    string $value
): array {
    return [
        'name' => $name,
        'call_id' => $callId,
        'arguments' => [
            'value' => $value,
        ],
    ];
}

$directory = sys_get_temp_dir()
    . '/guiche-v05-observability-'
    . bin2hex(random_bytes(8));

mkdir($directory, 0775, true);

try {
    echo "GUICHÊ v0.5.0 — FASE 05 / OBSERVABILITY\n";
    echo "=============================================\n\n";

    echo "1. INTERAÇÃO + IA + TOKENS\n";

    $collector = new CollectorObsV05();

    $runtime = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $directory . '/simple',
            'America/Manaus'
        ),
        ai: new ScriptedAiObsV05([
            aiResponseV05(
                'resp_simple',
                'AI_SECRET_RESPONSE'
            ),
        ]),
        eventSink: $collector
    );

    $result = $runtime->handle(
        'session-secret-simple',
        'USER_SECRET_MESSAGE'
    );

    $types = eventTypesV05(
        $collector->events
    );

    foreach ([
        'interaction_started',
        'ai_request_started',
        'ai_response_received',
        'interaction_completed',
    ] as $requiredType) {
        checkObsV05(
            in_array(
                $requiredType,
                $types,
                true
            ),
            "evento {$requiredType} presente"
        );
    }

    $aiEvents = eventsOfTypeV05(
        $collector->events,
        'ai_response_received'
    );

    checkObsV05(
        isset(
            $aiEvents[0]['attributes'][
                'duration_ms'
            ]
        )
        && $aiEvents[0]['attributes'][
            'duration_ms'
        ] >= 0,
        'AI duration_ms observável'
    );

    checkObsV05(
        ($aiEvents[0]['attributes'][
            'total_tokens'
        ] ?? null) === 7,
        'tokens da IA observáveis'
    );

    $completed = eventsOfTypeV05(
        $collector->events,
        'interaction_completed'
    );

    checkObsV05(
        isset(
            $completed[0]['attributes'][
                'duration_ms'
            ]
        ),
        'duração total da interação observável'
    );

    checkObsV05(
        ($completed[0]['attributes'][
            'total_tokens'
        ] ?? null) === 7,
        'totais de tokens no interaction_completed'
    );

    $interactionIds = array_unique(
        array_map(
            static fn (array $event): mixed =>
                $event['interaction_id']
                ?? null,
            $collector->events
        )
    );

    checkObsV05(
        count($interactionIds) === 1,
        'mesmo interaction_id em toda cadeia'
    );

    $raw = json_encode(
        $collector->events,
        JSON_THROW_ON_ERROR
    );

    foreach ([
        'USER_SECRET_MESSAGE',
        'AI_SECRET_RESPONSE',
        'session-secret-simple',
    ] as $forbidden) {
        checkObsV05(
            !str_contains(
                $raw,
                $forbidden
            ),
            "payload bruto omitido: {$forbidden}"
        );
    }

    echo "\n2. TOOL DURATION\n";

    $tool = new ProbeToolObsV05(
        'ReadProbe'
    );

    $registry = new ToolRegistry();
    $registry->register($tool);

    $collector = new CollectorObsV05();

    $runtime = new Guiche(
        tools: $registry,
        context: new ContextStore(
            $directory . '/tool',
            'America/Manaus'
        ),
        ai: new ScriptedAiObsV05([
            aiResponseV05(
                'resp_tool_1',
                '',
                [
                    toolCallV05(
                        'ReadProbe',
                        'call_read_1',
                        'ARG_SECRET_VALUE'
                    ),
                ]
            ),
            aiResponseV05(
                'resp_tool_2',
                'feito'
            ),
        ]),
        eventSink: $collector
    );

    $runtime->handle(
        'tool-session',
        'execute'
    );

    $started = eventsOfTypeV05(
        $collector->events,
        'tool_execution_started'
    );

    $finished = eventsOfTypeV05(
        $collector->events,
        'tool_execution_completed'
    );

    checkObsV05(
        count($started) === 1,
        'tool_execution_started emitido'
    );

    checkObsV05(
        count($finished) === 1,
        'tool_execution_completed emitido'
    );

    checkObsV05(
        ($finished[0]['attributes'][
            'duration_ms'
        ] ?? -1) >= 0,
        'Tool duration_ms observável'
    );

    checkObsV05(
        ($finished[0]['call_id'] ?? null)
            === 'call_read_1',
        'call_id correlaciona Tool'
    );

    checkObsV05(
        isset(
            $finished[0]['attributes'][
                'arguments_fingerprint'
            ]
        ),
        'fingerprint substitui argumentos'
    );

    $toolRaw = json_encode(
        $collector->events,
        JSON_THROW_ON_ERROR
    );

    foreach ([
        'ARG_SECRET_VALUE',
        'TOOL_SECRET_RESULT',
    ] as $forbidden) {
        checkObsV05(
            !str_contains(
                $toolRaw,
                $forbidden
            ),
            "Tool payload bruto omitido: {$forbidden}"
        );
    }

    echo "\n3. TOOL FAILURE\n";

    $tool = new ProbeToolObsV05(
        'FailProbe',
        true
    );

    $registry = new ToolRegistry();
    $registry->register($tool);

    $collector = new CollectorObsV05();

    $runtime = new Guiche(
        tools: $registry,
        context: new ContextStore(
            $directory . '/tool-fail',
            'America/Manaus'
        ),
        ai: new ScriptedAiObsV05([
            aiResponseV05(
                'resp_fail_1',
                '',
                [
                    toolCallV05(
                        'FailProbe',
                        'call_fail_1',
                        'fail'
                    ),
                ]
            ),
            aiResponseV05(
                'resp_fail_2',
                'fallback'
            ),
        ]),
        eventSink: $collector
    );

    $runtime->handle(
        'tool-fail-session',
        'execute'
    );

    $failures = eventsOfTypeV05(
        $collector->events,
        'tool_execution_failed'
    );

    checkObsV05(
        count($failures) === 1,
        'tool_execution_failed emitido'
    );

    checkObsV05(
        ($failures[0]['attributes'][
            'exception_class'
        ] ?? null) === RuntimeException::class,
        'classe da exception observável'
    );

    $failureRaw = json_encode(
        $collector->events,
        JSON_THROW_ON_ERROR
    );

    checkObsV05(
        !str_contains(
            $failureRaw,
            'TOOL_SECRET_EXCEPTION_MESSAGE'
        ),
        'mensagem bruta da exception não vaza'
    );

    echo "\n4. PROVIDER FAILURE\n";

    $collector = new CollectorObsV05();

    $runtime = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $directory . '/ai-fail',
            'America/Manaus'
        ),
        ai: new ScriptedAiObsV05([
            new RuntimeException(
                'PROVIDER_SECRET_FAILURE'
            ),
        ]),
        eventSink: $collector
    );

    $providerFailed = false;

    try {
        $runtime->handle(
            'provider-fail-session',
            'execute'
        );
    } catch (RuntimeException) {
        $providerFailed = true;
    }

    checkObsV05(
        $providerFailed,
        'falha do provider continua explícita'
    );

    $types = eventTypesV05(
        $collector->events
    );

    checkObsV05(
        in_array(
            'ai_request_failed',
            $types,
            true
        ),
        'ai_request_failed persistido'
    );

    checkObsV05(
        in_array(
            'interaction_failed',
            $types,
            true
        ),
        'interaction_failed persistido'
    );

    $providerRaw = json_encode(
        $collector->events,
        JSON_THROW_ON_ERROR
    );

    checkObsV05(
        !str_contains(
            $providerRaw,
            'PROVIDER_SECRET_FAILURE'
        ),
        'mensagem bruta do provider não vaza'
    );

    echo "\n5. READ CACHE HIT\n";

    $tool = new ProbeToolObsV05(
        'CacheProbe'
    );

    $registry = new ToolRegistry();
    $registry->register($tool);

    $collector = new CollectorObsV05();

    $sameCall = toolCallV05(
        'CacheProbe',
        'call_cache',
        'same'
    );

    $runtime = new Guiche(
        tools: $registry,
        context: new ContextStore(
            $directory . '/cache',
            'America/Manaus'
        ),
        ai: new ScriptedAiObsV05([
            aiResponseV05(
                'cache_1',
                '',
                [$sameCall]
            ),
            aiResponseV05(
                'cache_2',
                '',
                [$sameCall]
            ),
            aiResponseV05(
                'cache_3',
                'done'
            ),
        ]),
        reusableTools: ['CacheProbe'],
        eventSink: $collector
    );

    $runtime->handle(
        'cache-session',
        'execute'
    );

    checkObsV05(
        $tool->executions === 1,
        'READ repetida executa Tool uma vez'
    );

    $cacheEvents = eventsOfTypeV05(
        $collector->events,
        'tool_cache_hit'
    );

    checkObsV05(
        count($cacheEvents) === 1,
        'tool_cache_hit observável'
    );

    checkObsV05(
        isset(
            $cacheEvents[0]['attributes'][
                'arguments_fingerprint'
            ]
        ),
        'cache hit possui fingerprint'
    );

    echo "\n6. WRITE REPLAY\n";

    $writeTool = new ProbeToolObsV05(
        'WriteProbe'
    );

    $registry = new ToolRegistry();

    registerWithMetadataV05(
        $registry,
        $writeTool,
        [
            'operation' => 'write',
        ]
    );

    $collector = new CollectorObsV05();

    $sameWrite = toolCallV05(
        'WriteProbe',
        'call_write',
        'same-write'
    );

    $runtime = new Guiche(
        tools: $registry,
        context: new ContextStore(
            $directory . '/write',
            'America/Manaus'
        ),
        ai: new ScriptedAiObsV05([
            aiResponseV05(
                'write_1',
                '',
                [$sameWrite]
            ),
            aiResponseV05(
                'write_2',
                '',
                [$sameWrite]
            ),
            aiResponseV05(
                'write_3',
                'done'
            ),
        ]),
        eventSink: $collector
    );

    $runtime->handle(
        'write-session',
        'execute'
    );

    checkObsV05(
        $writeTool->executions === 1,
        'WRITE repetida executa side effect uma vez'
    );

    $replays = eventsOfTypeV05(
        $collector->events,
        'tool_write_replay'
    );

    checkObsV05(
        count($replays) === 1,
        'tool_write_replay observável'
    );

    checkObsV05(
        isset(
            $replays[0]['attributes'][
                'arguments_fingerprint'
            ]
        ),
        'WRITE replay possui fingerprint'
    );

    echo "\n7. SAFETY BLOCK\n";

    $collector = new CollectorObsV05();

    $runtime = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $directory . '/blocked',
            'America/Manaus'
        ),
        ai: new ScriptedAiObsV05([
            aiResponseV05(
                'blocked_1',
                '',
                [
                    toolCallV05(
                        'UnknownTool',
                        'call_blocked',
                        'x'
                    ),
                ]
            ),
            aiResponseV05(
                'blocked_2',
                'blocked handled'
            ),
        ]),
        eventSink: $collector
    );

    $runtime->handle(
        'blocked-session',
        'execute'
    );

    $blocked = eventsOfTypeV05(
        $collector->events,
        'tool_blocked'
    );

    checkObsV05(
        count($blocked) === 1,
        'tool_blocked observável'
    );

    checkObsV05(
        trim(
            (string) (
                $blocked[0]['attributes'][
                    'reason'
                ]
                ?? ''
            )
        ) !== '',
        'tool_blocked possui reason'
    );

    echo "\n8. SIDE EFFECT + PERSISTENCE FAILURE\n";

    $writeTool = new ProbeToolObsV05(
        'WritePersistProbe'
    );

    $registry = new ToolRegistry();

    registerWithMetadataV05(
        $registry,
        $writeTool,
        [
            'operation' => 'write',
        ]
    );

    $innerStore = new ContextStore(
        $directory . '/persistence-inner',
        'America/Manaus'
    );

    $failingStore =
        new FailingToolHistoryStoreObsV05(
            $innerStore
        );

    $collector = new CollectorObsV05();

    $runtime = new Guiche(
        tools: $registry,
        context: $failingStore,
        ai: new ScriptedAiObsV05([
            aiResponseV05(
                'persist_1',
                '',
                [
                    toolCallV05(
                        'WritePersistProbe',
                        'call_persist',
                        'x'
                    ),
                ]
            ),
        ]),
        eventSink: $collector
    );

    $persistenceFailed = false;

    try {
        $runtime->handle(
            'persistence-session',
            'execute'
        );
    } catch (RuntimeException) {
        $persistenceFailed = true;
    }

    checkObsV05(
        $persistenceFailed,
        'falha de persistência continua explícita'
    );

    checkObsV05(
        $writeTool->executions === 1,
        'side effect não é repetido após falha de persistência'
    );

    $critical = eventsOfTypeV05(
        $collector->events,
        'persistence_after_side_effect_failed'
    );

    checkObsV05(
        count($critical) === 1,
        'persistence_after_side_effect_failed observável'
    );

    $criticalRaw = json_encode(
        $collector->events,
        JSON_THROW_ON_ERROR
    );

    checkObsV05(
        !str_contains(
            $criticalRaw,
            'PERSISTENCE_SECRET_FAILURE'
        ),
        'mensagem bruta da falha de persistência não vaza'
    );

    echo "\n9. FRONTEIRA ESTÁTICA\n";

    $source = file_get_contents(
        dirname(__DIR__) . '/src/Guiche.php'
    );

    checkObsV05(
        str_contains(
            (string) $source,
            'hrtime(true)'
        ),
        'duração usa relógio monotônico'
    );

    checkObsV05(
        !str_contains(
            (string) $source,
            'microtime('
        ),
        'instrumentação não usa microtime'
    );

    foreach ([
        'Clinical',
        'Paciente',
        'Convenio',
        'Agendamento',
        'Prometheus',
        'OpenTelemetry',
    ] as $forbidden) {
        checkObsV05(
            !str_contains(
                (string) $source,
                $forbidden
            ),
            "Core neutro: {$forbidden}"
        );
    }

    echo "\nOK: GUICHÊ v0.5.0 / FASE 05 validada.\n";
} finally {
    rmTreeObsV05($directory);
}
