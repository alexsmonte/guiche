<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\ConversationRuntime;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Conversation\MessageBuffer;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\ToolRegistry;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException(
            'FALHOU: ' . $message
        );
    }

    echo 'OK: ' . $message . PHP_EOL;
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;

        if (is_dir($full)) {
            removeTree($full);
        } else {
            @unlink($full);
        }
    }

    @rmdir($path);
}

final class RuntimeClock implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $value
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->value;
    }

    public function advance(string $modifier): void
    {
        $next = $this->value->modify($modifier);

        if (!$next instanceof \DateTimeImmutable) {
            throw new \RuntimeException(
                'Invalid clock modifier.'
            );
        }

        $this->value = $next;
    }
}

final class CapturingAiProvider implements AiProviderInterface
{
    public int $respondCalls = 0;

    public array $messageBatches = [];

    public bool $fail = false;

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->respondCalls++;
        $this->messageBatches[] = $messages;

        if ($this->fail) {
            throw new \RuntimeException(
                'SECRET_PROVIDER_FAILURE'
            );
        }

        return [
            'id' => 'runtime-response-' . $this->respondCalls,
            'response_id' =>
                'runtime-response-' . $this->respondCalls,
            'text' => 'Resposta consolidada',
            'output_text' => 'Resposta consolidada',
            'message' => 'Resposta consolidada',
            'tool_calls' => [],
            'usage' => [
                'input_tokens' => 3,
                'output_tokens' => 2,
                'total_tokens' => 5,
            ],
        ];
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        throw new \RuntimeException(
            'continueWithToolOutputs não deveria ser chamado.'
        );
    }
}

$root = dirname(__DIR__);
$tempRoot =
    sys_get_temp_dir()
    . '/guiche-v051-phase04-'
    . bin2hex(random_bytes(6));

$conversationDir = $tempRoot . '/conversations';
$contextDir = $tempRoot . '/contexts';

mkdir($conversationDir, 0770, true);
mkdir($contextDir, 0770, true);

try {
    echo PHP_EOL;
    echo "GUICHÊ v0.5.1 — FASE 04 / CONVERSATION RUNTIME" . PHP_EOL;
    echo "=====================================================" . PHP_EOL;

    $store =
        new JsonConversationStore(
            $conversationDir
        );

    $clock =
        new RuntimeClock(
            new \DateTimeImmutable(
                '2026-10-13T18:00:00-04:00'
            )
        );

    $recorder =
        new IncomingEventRecorder(
            $store,
            $clock
        );

    $buffer =
        new MessageBuffer(
            $store,
            $clock
        );

    $provider =
        new CapturingAiProvider();

    $guiche =
        new Guiche(
            tools: new ToolRegistry(),
            context: new ContextStore(
                $contextDir,
                'UTC'
            ),
            ai: $provider,
            instructions: 'Teste neutro do Conversation Runtime.'
        );

    $runtime =
        new ConversationRuntime(
            guiche: $guiche,
            buffer: $buffer
        );

    echo PHP_EOL;
    echo "1. QUIET WINDOW" . PHP_EOL;

    $key = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'quiet-example'
    );

    $recorder->record(
        $key,
        new IncomingEvent(
            'quiet-1',
            'message',
            ['text' => 'Oi']
        )
    );

    $clock->advance('+5 seconds');

    $recorder->record(
        $key,
        new IncomingEvent(
            'quiet-2',
            'message',
            ['text' => 'queria marcar']
        )
    );

    $clock->advance('+5 seconds');

    $recorder->record(
        $key,
        new IncomingEvent(
            'quiet-3',
            'message',
            ['text' => 'pelo dental plus']
        )
    );

    check(
        $runtime->processReady($key) === null,
        'turno não dispara imediatamente após última mensagem'
    );

    check(
        $provider->respondCalls === 0,
        'Guiche ainda não foi chamado'
    );

    $clock->advance('+39 seconds');

    check(
        $runtime->processReady($key) === null,
        '39s de silêncio ainda não completam quiet window'
    );

    check(
        $provider->respondCalls === 0,
        'nenhuma chamada antecipada à IA'
    );

    $clock->advance('+1 second');

    $processed =
        $runtime->processReady($key);

    check(
        is_array($processed)
        && ($processed['processed'] ?? false) === true,
        '40s de silêncio liberam o turno'
    );

    check(
        $provider->respondCalls === 1,
        'três mensagens causam uma única chamada ao Guiche'
    );

    check(
        ($processed['message'] ?? null)
            === "Oi\nqueria marcar\npelo dental plus",
        'mensagens são consolidadas em ordem'
    );

    $lastBatch =
        $provider->messageBatches[
            array_key_last($provider->messageBatches)
        ];

    $lastMessage =
        $lastBatch[
            array_key_last($lastBatch)
        ]['content'] ?? null;

    check(
        $lastMessage
            === "Oi\nqueria marcar\npelo dental plus",
        'Guiche recebe uma única mensagem consolidada'
    );

    $quietState = $store->load($key);
    $quietTurn =
        reset($quietState['turns']);

    check(
        ($quietTurn['status'] ?? null) === 'completed',
        'turno é concluído após resposta do Guiche'
    );

    check(
        ($quietState['active_turn_id'] ?? null) === null,
        'turno concluído libera conversa'
    );

    check(
        $runtime->processReady($key) === null,
        'turno concluído não é processado novamente'
    );

    check(
        $provider->respondCalls === 1,
        'processReady repetido não duplica chamada'
    );

    echo PHP_EOL;
    echo "2. QUIET WINDOW É REINICIADA" . PHP_EOL;

    $resetKey = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'quiet-reset'
    );

    $recorder->record(
        $resetKey,
        new IncomingEvent(
            'reset-1',
            'message',
            ['text' => 'primeira']
        )
    );

    $clock->advance('+39 seconds');

    $recorder->record(
        $resetKey,
        new IncomingEvent(
            'reset-2',
            'message',
            ['text' => 'segunda']
        )
    );

    $clock->advance('+1 second');

    check(
        $runtime->processReady($resetKey) === null,
        'mensagem nova reinicia quiet window'
    );

    $clock->advance('+39 seconds');

    $resetProcessed =
        $runtime->processReady($resetKey);

    check(
        is_array($resetProcessed)
        && ($resetProcessed['processed'] ?? false) === true,
        '40s desde a mensagem mais recente liberam turno'
    );

    echo PHP_EOL;
    echo "3. MAX TURN WINDOW" . PHP_EOL;

    $maxKey = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'max-window'
    );

    $recorder->record(
        $maxKey,
        new IncomingEvent(
            'max-1',
            'message',
            ['text' => 'm1']
        )
    );

    $clock->advance('+30 seconds');

    $recorder->record(
        $maxKey,
        new IncomingEvent(
            'max-2',
            'message',
            ['text' => 'm2']
        )
    );

    $clock->advance('+30 seconds');

    $recorder->record(
        $maxKey,
        new IncomingEvent(
            'max-3',
            'message',
            ['text' => 'm3']
        )
    );

    $clock->advance('+59 seconds');

    $recorder->record(
        $maxKey,
        new IncomingEvent(
            'max-4',
            'message',
            ['text' => 'm4']
        )
    );

    check(
        $runtime->processReady($maxKey) === null,
        '119s ainda não atingem max window e quiet não fechou'
    );

    $clock->advance('+1 second');

    $maxProcessed =
        $runtime->processReady($maxKey);

    check(
        is_array($maxProcessed)
        && ($maxProcessed['processed'] ?? false) === true,
        '120s desde primeira mensagem forçam turno'
    );

    check(
        ($maxProcessed['message'] ?? null)
            === "m1\nm2\nm3\nm4",
        'max window preserva todas mensagens do snapshot'
    );

    echo PHP_EOL;
    echo "4. START PROCESSING EVITA DUPLICIDADE" . PHP_EOL;

    $busyKey = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'busy-turn'
    );

    $recorder->record(
        $busyKey,
        new IncomingEvent(
            'busy-1',
            'message',
            ['text' => 'processar uma vez']
        )
    );

    $clock->advance('+40 seconds');

    $busyTurn =
        $buffer->openReadyTurn(
            $busyKey,
            40,
            120
        );

    check(
        $busyTurn !== null
        && $busyTurn->status === 'pending',
        'turno pronto pode ser aberto'
    );

    $started =
        $buffer->tryStartProcessing(
            $busyKey,
            $busyTurn->turnId
        );

    check(
        $started !== null
        && $started->status === 'processing',
        'primeiro runner inicia processamento'
    );

    $secondStart =
        $buffer->tryStartProcessing(
            $busyKey,
            $busyTurn->turnId
        );

    check(
        $secondStart === null,
        'segundo runner não inicia o mesmo turno'
    );

    $callsBeforeBusy =
        $provider->respondCalls;

    $busyResult =
        $runtime->processReady($busyKey);

    check(
        is_array($busyResult)
        && ($busyResult['processed'] ?? true) === false
        && ($busyResult['reason'] ?? null)
            === 'already_processing',
        'runtime não reexecuta turno encontrado em processing'
    );

    check(
        $provider->respondCalls === $callsBeforeBusy,
        'estado processing não causa retry cego'
    );

    echo PHP_EOL;
    echo "5. FALHA NÃO TEM RETRY AUTOMÁTICO" . PHP_EOL;

    $failureProvider =
        new CapturingAiProvider();

    $failureProvider->fail = true;

    $failureContextDir =
        $tempRoot . '/contexts-failure';

    mkdir($failureContextDir, 0770, true);

    $failureGuiche =
        new Guiche(
            tools: new ToolRegistry(),
            context: new ContextStore(
                $failureContextDir,
                'UTC'
            ),
            ai: $failureProvider,
            instructions: 'Falha controlada.'
        );

    $failureRuntime =
        new ConversationRuntime(
            guiche: $failureGuiche,
            buffer: $buffer
        );

    $failureKey = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'failure-turn'
    );

    $recorder->record(
        $failureKey,
        new IncomingEvent(
            'failure-1',
            'message',
            ['text' => 'falhar']
        )
    );

    $clock->advance('+40 seconds');

    $failed = false;

    try {
        $failureRuntime->processReady(
            $failureKey
        );
    } catch (\RuntimeException $exception) {
        $failed =
            $exception->getMessage()
            === 'SECRET_PROVIDER_FAILURE';
    }

    check(
        $failed,
        'falha do Guiche continua explícita'
    );

    check(
        $failureProvider->respondCalls === 1,
        'primeira tentativa chamou provider uma vez'
    );

    $failureState =
        $store->load($failureKey);

    $failedTurn =
        reset($failureState['turns']);

    check(
        ($failedTurn['status'] ?? null) === 'failed',
        'turno com exceção vira failed'
    );

    check(
        ($failedTurn['failure_class'] ?? null)
            === \RuntimeException::class,
        'somente classe da falha é persistida'
    );

    check(
        !str_contains(
            json_encode(
                $failureState,
                JSON_THROW_ON_ERROR
            ),
            'SECRET_PROVIDER_FAILURE'
        ),
        'mensagem bruta da exceção não vaza no Conversation Store'
    );

    check(
        $failureRuntime->processReady(
            $failureKey
        ) === null,
        'turno failed não recebe retry automático'
    );

    check(
        $failureProvider->respondCalls === 1,
        'segunda passagem não repete provider'
    );

    echo PHP_EOL;
    echo "6. CONFIGURAÇÃO DAS JANELAS" . PHP_EOL;

    $invalidQuiet = false;

    try {
        new ConversationRuntime(
            guiche: $guiche,
            buffer: $buffer,
            quietWindowSeconds: -1,
            maxTurnWindowSeconds: 120
        );
    } catch (\RuntimeException) {
        $invalidQuiet = true;
    }

    check(
        $invalidQuiet,
        'quiet window negativa é rejeitada'
    );

    $invalidMax = false;

    try {
        new ConversationRuntime(
            guiche: $guiche,
            buffer: $buffer,
            quietWindowSeconds: 40,
            maxTurnWindowSeconds: 20
        );
    } catch (\RuntimeException) {
        $invalidMax = true;
    }

    check(
        $invalidMax,
        'max window menor que quiet window é rejeitada'
    );

    echo PHP_EOL;
    echo "7. FRONTEIRA ARQUITETURAL" . PHP_EOL;

    $guicheSource =
        file_get_contents(
            $root . '/src/Guiche.php'
        );

    check(
        is_string($guicheSource)
        && !str_contains(
            $guicheSource,
            'ConversationRuntime'
        ),
        'Guiche não conhece ConversationRuntime'
    );

    check(
        is_string($guicheSource)
        && !str_contains(
            $guicheSource,
            'quietWindow'
        ),
        'Guiche não implementa debounce'
    );

    $runtimeSource =
        file_get_contents(
            $root
            . '/src/Conversation/ConversationRuntime.php'
        );

    check(
        is_string($runtimeSource)
        && str_contains(
            $runtimeSource,
            '->handle('
        ),
        'ConversationRuntime delega processamento ao Guiche'
    );

    foreach (
        [
            'FollowUp.php',
        ] as $future
    ) {
        $found = false;

        $iterator =
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root . '/src',
                    \FilesystemIterator::SKIP_DOTS
                )
            );

        foreach ($iterator as $item) {
            if (
                $item->isFile()
                && $item->getFilename() === $future
            ) {
                $found = true;
                break;
            }
        }

        check(
            !$found,
            "Fase 04 ainda não implementa {$future}"
        );
    }

    echo PHP_EOL;
    echo "OK: GUICHÊ v0.5.1 / FASE 04 validada." . PHP_EOL;
} finally {
    removeTree($tempRoot);
}
