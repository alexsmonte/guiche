<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Observability\JsonlRuntimeEventSink;
use Asmpkg\Guiche\Observability\NullRuntimeEventSink;
use Asmpkg\Guiche\Observability\RuntimeEventFactory;
use Asmpkg\Guiche\ToolRegistry;

function checkV05EventSink(
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

function rmTreeV05EventSink(
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
            rmTreeV05EventSink($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

if (($argv[1] ?? '') === '--worker') {
    $file = (string) ($argv[2] ?? '');
    $worker = (int) ($argv[3] ?? 0);
    $iterations = (int) ($argv[4] ?? 0);

    $sink = new JsonlRuntimeEventSink(
        $file
    );

    for ($i = 1; $i <= $iterations; $i++) {
        $sink->record([
            'schema_version' => 1,
            'event_id' => "{$worker}-{$i}",
            'type' => 'worker_event',
            'occurred_at' =>
                '2026-10-13T00:00:00+00:00',
            'session_key' =>
                hash('sha256', 'worker'),
            'interaction_id' =>
                "worker-{$worker}",
            'attributes' => [
                'worker' => $worker,
                'iteration' => $i,
            ],
        ]);
    }

    exit(0);
}

final class FakeAiV05
    implements AiProviderInterface
{
    public function __construct(
        private readonly string $text
    ) {
    }

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        return [
            'id' => 'resp_v05',
            'response_id' => 'resp_v05',
            'text' => $this->text,
            'output_text' => $this->text,
            'message' => $this->text,
            'tool_calls' => [],
            'usage' => [
                'input_tokens' => 7,
                'output_tokens' => 3,
                'total_tokens' => 10,
            ],
        ];
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        throw new RuntimeException(
            'continueWithToolOutputs inesperado.'
        );
    }
}

final class CollectorV05
    implements RuntimeEventSinkInterface
{
    public array $events = [];

    public function record(array $event): void
    {
        $this->events[] = $event;
    }
}

final class FailingSinkV05
    implements RuntimeEventSinkInterface
{
    public int $calls = 0;

    public function record(array $event): void
    {
        $this->calls++;

        throw new RuntimeException(
            'falha proposital'
        );
    }
}

$directory = sys_get_temp_dir()
    . '/guiche-v05-event-sink-'
    . bin2hex(random_bytes(6));

mkdir($directory, 0775, true);

try {
    echo "GUICHÊ v0.5.0 — FASE 04 / EVENT SINK\n";
    echo "==========================================\n\n";

    echo "1. NULL SINK\n";

    $null = new NullRuntimeEventSink();
    $null->record(['type' => 'noop']);

    checkV05EventSink(
        $null instanceof RuntimeEventSinkInterface,
        'NullRuntimeEventSink implementa contrato'
    );

    echo "\n2. EVENT FACTORY\n";

    $event = RuntimeEventFactory::fromLegacy(
        'session-super-secreta',
        'interaction-1',
        [
            'type' => 'tool_call',
            'call_id' => 'call-1',
            'name' => 'Probe',
            'arguments' => [
                'cpf' => '12345678901',
            ],
            'arguments_fingerprint' =>
                hash('sha256', 'args'),
            'text' => 'TEXTO_SECRETO',
            'result' => [
                'success' => true,
                'payload' => 'RESULTADO_SECRETO',
            ],
        ]
    );

    checkV05EventSink(
        ($event['schema_version'] ?? null)
            === 1,
        'schema_version=1'
    );

    checkV05EventSink(
        strlen(
            (string) (
                $event['event_id'] ?? ''
            )
        ) === 32,
        'event_id presente'
    );

    checkV05EventSink(
        ($event['session_key'] ?? null)
            === hash(
                'sha256',
                'session-super-secreta'
            ),
        'session_key usa SHA-256'
    );

    checkV05EventSink(
        ($event['interaction_id'] ?? null)
            === 'interaction-1',
        'interaction_id preservado'
    );

    checkV05EventSink(
        ($event['call_id'] ?? null)
            === 'call-1',
        'call_id preservado'
    );

    $eventJson = json_encode(
        $event,
        JSON_THROW_ON_ERROR
    );

    foreach ([
        '12345678901',
        'TEXTO_SECRETO',
        'RESULTADO_SECRETO',
        'session-super-secreta',
    ] as $forbidden) {
        checkV05EventSink(
            !str_contains(
                $eventJson,
                $forbidden
            ),
            "payload sensível omitido: {$forbidden}"
        );
    }

    checkV05EventSink(
        isset(
            $event['attributes'][
                'arguments_fingerprint'
            ]
        ),
        'fingerprint substitui argumentos brutos'
    );

    echo "\n3. JSONL\n";

    $jsonlFile =
        $directory . '/direct/events.jsonl';

    $jsonl =
        new JsonlRuntimeEventSink(
            $jsonlFile
        );

    foreach (['a', 'b'] as $type) {
        $jsonl->record(
            RuntimeEventFactory::fromLegacy(
                'session',
                'interaction',
                ['type' => $type]
            )
        );
    }

    $lines = file(
        $jsonlFile,
        FILE_IGNORE_NEW_LINES
        | FILE_SKIP_EMPTY_LINES
    );

    checkV05EventSink(
        is_array($lines)
        && count($lines) === 2,
        'JSONL grava uma linha por evento'
    );

    foreach ($lines ?: [] as $line) {
        json_decode(
            $line,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    checkV05EventSink(
        true,
        'linhas JSONL são válidas'
    );

    echo "\n4. JSONL CONCORRENTE\n";

    $concurrentFile =
        $directory
        . '/concurrent/events.jsonl';

    $workers = 4;
    $iterations = 25;
    $processes = [];

    for ($worker = 1; $worker <= $workers; $worker++) {
        $pipes = [];

        $process = proc_open(
            [
                PHP_BINARY,
                __FILE__,
                '--worker',
                $concurrentFile,
                (string) $worker,
                (string) $iterations,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                "worker {$worker} não iniciou."
            );
        }

        $processes[] = [
            'worker' => $worker,
            'process' => $process,
            'stderr' => $pipes[2],
        ];
    }

    foreach ($processes as $item) {
        $stderr = stream_get_contents(
            $item['stderr']
        );

        fclose($item['stderr']);

        $status = proc_close(
            $item['process']
        );

        if ($status !== 0) {
            throw new RuntimeException(
                'worker '
                . $item['worker']
                . ' falhou: '
                . trim((string) $stderr)
            );
        }
    }

    $concurrentLines = file(
        $concurrentFile,
        FILE_IGNORE_NEW_LINES
        | FILE_SKIP_EMPTY_LINES
    );

    checkV05EventSink(
        is_array($concurrentLines)
        && count($concurrentLines)
            === $workers * $iterations,
        'append concorrente não perde eventos'
    );

    foreach ($concurrentLines ?: [] as $line) {
        json_decode(
            $line,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    checkV05EventSink(
        true,
        'append concorrente não mistura JSON'
    );

    echo "\n5. GUICHE + COLLECTOR\n";

    $collector = new CollectorV05();

    $guiche = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $directory . '/context',
            'America/Manaus'
        ),
        ai: new FakeAiV05(
            'RESPOSTA_SUPER_SECRETA'
        ),
        eventSink: $collector
    );

    $result = $guiche->handle(
        'sessao-runtime-secreta',
        'MENSAGEM_USUARIO_SECRETA'
    );

    checkV05EventSink(
        ($result['message'] ?? null)
            === 'RESPOSTA_SUPER_SECRETA',
        'sink não altera resposta do runtime'
    );

    checkV05EventSink(
        !empty($result['events']),
        'events legados continuam no handle'
    );

    checkV05EventSink(
        count($collector->events)
            === count($result['events']),
        'cada evento legado é enviado uma vez'
    );

    $interactionIds = [];
    $eventIds = [];

    foreach ($collector->events as $persisted) {
        $interactionIds[] =
            $persisted['interaction_id']
            ?? null;

        $eventIds[] =
            $persisted['event_id']
            ?? null;

        checkV05EventSink(
            ($persisted['session_key'] ?? null)
                === hash(
                    'sha256',
                    'sessao-runtime-secreta'
                ),
            'session_key correlaciona sem expor sessão'
        );
    }

    checkV05EventSink(
        count(array_unique($interactionIds))
            === 1,
        'todos eventos compartilham interaction_id'
    );

    checkV05EventSink(
        count(array_unique($eventIds))
            === count($eventIds),
        'event_id é único'
    );

    $collectorJson = json_encode(
        $collector->events,
        JSON_THROW_ON_ERROR
    );

    foreach ([
        'MENSAGEM_USUARIO_SECRETA',
        'RESPOSTA_SUPER_SECRETA',
        'sessao-runtime-secreta',
    ] as $forbidden) {
        checkV05EventSink(
            !str_contains(
                $collectorJson,
                $forbidden
            ),
            "runtime não persiste dado bruto: {$forbidden}"
        );
    }

    echo "\n6. BUILDER\n";

    $builderSink =
        new CollectorV05();

    $built = (
        new GuicheBuilder()
    )
        ->withContextStore(
            new ContextStore(
                $directory . '/builder',
                'America/Manaus'
            )
        )
        ->withRuntimeEventSink(
            $builderSink
        )
        ->build();

    $reflection =
        new ReflectionClass($built);

    $property =
        $reflection->getProperty(
            'eventSink'
        );

    $property->setAccessible(true);

    checkV05EventSink(
        $property->getValue($built)
            === $builderSink,
        'Builder injeta exatamente o sink'
    );

    echo "\n7. DEFAULT NULL SINK\n";

    $default = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $directory . '/default',
            'America/Manaus'
        ),
        ai: new FakeAiV05('default-ok')
    );

    $defaultResult = $default->handle(
        'default-session',
        'teste'
    );

    checkV05EventSink(
        ($defaultResult['message'] ?? null)
            === 'default-ok',
        'runtime funciona sem sink explícito'
    );

    echo "\n8. BEST EFFORT\n";

    $failingSink =
        new FailingSinkV05();

    $failing = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $directory . '/failing',
            'America/Manaus'
        ),
        ai: new FakeAiV05(
            'resultado-funcional'
        ),
        eventSink: $failingSink
    );

    $failingResult = $failing->handle(
        'failing-session',
        'teste'
    );

    checkV05EventSink(
        ($failingResult['message'] ?? null)
            === 'resultado-funcional',
        'falha do sink não quebra runtime'
    );

    checkV05EventSink(
        $failingSink->calls === 1,
        'sink com falha não é retentado'
    );

    $types = array_map(
        static fn (array $item): mixed =>
            $item['type'] ?? null,
        $failingResult['events'] ?? []
    );

    checkV05EventSink(
        in_array(
            'observability_sink_failed',
            $types,
            true
        ),
        'falha do sink fica detectável'
    );

    echo "\nOK: GUICHÊ v0.5.0 / FASE 04 validada.\n";
} finally {
    rmTreeV05EventSink($directory);
}
