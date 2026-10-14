<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\ConversationTurn;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Conversation\MessageBuffer;
use Asmpkg\Guiche\Contracts\ClockInterface;

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

final class BufferClock implements ClockInterface
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
                'Invalid modifier.'
            );
        }

        $this->value = $next;
    }
}

$root = dirname(__DIR__);
$tempRoot =
    sys_get_temp_dir()
    . '/guiche-v051-phase03-'
    . bin2hex(random_bytes(6));

$storeDir = $tempRoot . '/store';

mkdir($storeDir, 0770, true);

try {
    echo PHP_EOL;
    echo "GUICHÊ v0.5.1 — FASE 03 / BUFFER + TURN" . PHP_EOL;
    echo "==============================================" . PHP_EOL;

    $store = new JsonConversationStore($storeDir);

    $clock = new BufferClock(
        new \DateTimeImmutable(
            '2026-10-13T22:30:00-04:00'
        )
    );

    $recorder = new IncomingEventRecorder(
        $store,
        $clock
    );

    $buffer = new MessageBuffer(
        $store,
        $clock
    );

    $key = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'buffer-main'
    );

    echo PHP_EOL;
    echo "1. BUFFER VAZIO" . PHP_EOL;

    check(
        $buffer->openTurn($key) === null,
        'buffer vazio não cria turno'
    );

    echo PHP_EOL;
    echo "2. TRÊS EVENTOS → UM TURNO" . PHP_EOL;

    foreach (
        [
            'Oi',
            'queria marcar',
            'pelo dental plus',
        ] as $index => $text
    ) {
        $recorder->record(
            $key,
            new IncomingEvent(
                'buffer-event-' . ($index + 1),
                'message',
                ['text' => $text]
            )
        );

        $clock->advance('+5 seconds');
    }

    $turn1 = $buffer->openTurn($key);

    check(
        $turn1 instanceof ConversationTurn,
        'eventos pendentes criam ConversationTurn'
    );

    check(
        $turn1->status === 'pending',
        'turno nasce pending'
    );

    check(
        $turn1->fromSequence === 1,
        'turno começa em sequence 1'
    );

    check(
        $turn1->toSequence === 3,
        'turno termina em sequence 3'
    );

    check(
        $turn1->sequenceSnapshot === 3,
        'turno fixa sequence_snapshot=3'
    );

    check(
        count($turn1->events) === 3,
        'turno contém os três eventos'
    );

    check(
        array_column(
            array_column($turn1->events, 'payload'),
            'text'
        ) === [
            'Oi',
            'queria marcar',
            'pelo dental plus',
        ],
        'ordem dos eventos é preservada'
    );

    $state = $store->load($key);

    check(
        ($state['active_turn_id'] ?? null)
            === $turn1->turnId,
        'turno pending fica ativo'
    );

    check(
        count($state['turns'] ?? []) === 1,
        'turno é persistido'
    );

    foreach ($state['events'] as $event) {
        check(
            ($event['turn_id'] ?? null)
                === $turn1->turnId,
            'evento do snapshot é vinculado ao turno'
        );

        check(
            !isset($event['consumed_at']),
            'evento ainda não é consumido ao abrir turno'
        );
    }

    echo PHP_EOL;
    echo "3. OPENTURN IDEMPOTENTE" . PHP_EOL;

    $sameTurn = $buffer->openTurn($key);

    check(
        $sameTurn instanceof ConversationTurn
        && $sameTurn->turnId === $turn1->turnId,
        'openTurn repetido devolve mesmo turno pending'
    );

    check(
        count($store->load($key)['turns']) === 1,
        'openTurn repetido não duplica turno'
    );

    echo PHP_EOL;
    echo "4. NOVO EVENTO DURANTE TURNO ATIVO" . PHP_EOL;

    $recorder->record(
        $key,
        new IncomingEvent(
            'buffer-event-4',
            'message',
            ['text' => 'quarta mensagem']
        )
    );

    $stateWithFourth = $store->load($key);

    check(
        ($stateWithFourth['sequence'] ?? null) === 4,
        'novo evento avança sequence global'
    );

    $stillSameTurn = $buffer->openTurn($key);

    check(
        $stillSameTurn instanceof ConversationTurn
        && $stillSameTurn->turnId === $turn1->turnId,
        'novo evento não altera turno pending existente'
    );

    check(
        $stillSameTurn->sequenceSnapshot === 3,
        'sequence_snapshot do turno permanece imutável'
    );

    check(
        count($stillSameTurn->events) === 3,
        'evento novo não entra retroativamente no turno'
    );

    echo PHP_EOL;
    echo "5. RESTART RECUPERA TURNO PENDING" . PHP_EOL;

    unset($buffer, $recorder, $store);

    $storeAfterRestart =
        new JsonConversationStore($storeDir);

    $bufferAfterRestart =
        new MessageBuffer(
            $storeAfterRestart,
            $clock
        );

    $recovered =
        $bufferAfterRestart->openTurn($key);

    check(
        $recovered instanceof ConversationTurn
        && $recovered->turnId === $turn1->turnId,
        'restart recupera mesmo turno pending'
    );

    check(
        $recovered->sequenceSnapshot === 3,
        'restart preserva snapshot do turno'
    );

    echo PHP_EOL;
    echo "6. COMPLETE IDEMPOTENTE" . PHP_EOL;

    $clock->advance('+30 seconds');

    $completed =
        $bufferAfterRestart->completeTurn(
            $key,
            $turn1->turnId
        );

    check(
        $completed->status === 'completed',
        'completeTurn conclui turno'
    );

    check(
        $completed->completedAt !== null,
        'turno concluído possui completed_at'
    );

    $afterComplete =
        $storeAfterRestart->load($key);

    check(
        ($afterComplete['active_turn_id'] ?? null) === null,
        'turno concluído libera active_turn_id'
    );

    $consumedOld = 0;
    $unconsumedNew = 0;

    foreach ($afterComplete['events'] as $event) {
        if (($event['sequence'] ?? 0) <= 3) {
            if (isset($event['consumed_at'])) {
                $consumedOld++;
            }
        }

        if (($event['sequence'] ?? 0) === 4) {
            if (!isset($event['consumed_at'])) {
                $unconsumedNew++;
            }
        }
    }

    check(
        $consumedOld === 3,
        'somente eventos do turno são consumidos'
    );

    check(
        $unconsumedNew === 1,
        'evento posterior permanece pendente'
    );

    $completedAgain =
        $bufferAfterRestart->completeTurn(
            $key,
            $turn1->turnId
        );

    check(
        $completedAgain->status === 'completed'
        && $completedAgain->completedAt
            === $completed->completedAt,
        'completeTurn repetido é idempotente'
    );

    echo PHP_EOL;
    echo "7. PRÓXIMO TURNO" . PHP_EOL;

    $turn2 =
        $bufferAfterRestart->openTurn($key);

    check(
        $turn2 instanceof ConversationTurn,
        'evento posterior abre próximo turno'
    );

    check(
        $turn2->turnId !== $turn1->turnId,
        'próximo turno possui novo id'
    );

    check(
        $turn2->fromSequence === 4
        && $turn2->toSequence === 4,
        'próximo turno contém somente sequence 4'
    );

    check(
        $turn2->sequenceSnapshot === 4,
        'novo turno fixa snapshot atual'
    );

    echo PHP_EOL;
    echo "8. CONCORRÊNCIA NA ABERTURA" . PHP_EOL;

    $concurrentKey = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'buffer-concurrent'
    );

    $recorderConcurrent =
        new IncomingEventRecorder(
            $storeAfterRestart,
            $clock
        );

    for ($i = 1; $i <= 3; $i++) {
        $recorderConcurrent->record(
            $concurrentKey,
            new IncomingEvent(
                'concurrent-buffer-' . $i,
                'message',
                ['text' => 'm' . $i]
            )
        );
    }

    $workerPath =
        $tempRoot . '/turn-worker.php';

    $workerSource = <<<'WORKER'
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

$store =
    new \Asmpkg\Guiche\Conversation\JsonConversationStore(
        $argv[2]
    );

$clock =
    new class implements
        \Asmpkg\Guiche\Contracts\ClockInterface
    {
        public function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable(
                '2026-10-14T03:00:00Z'
            );
        }
    };

$buffer =
    new \Asmpkg\Guiche\Conversation\MessageBuffer(
        $store,
        $clock
    );

$turn = $buffer->openTurn($argv[3]);

echo json_encode(
    [
        'turn_id' => $turn?->turnId,
        'from' => $turn?->fromSequence,
        'to' => $turn?->toSequence,
        'snapshot' => $turn?->sequenceSnapshot,
    ],
    JSON_THROW_ON_ERROR
);
WORKER;

    file_put_contents(
        $workerPath,
        $workerSource
    );

    $processes = [];

    for ($i = 1; $i <= 8; $i++) {
        $pipes = [];

        $process = proc_open(
            [
                PHP_BINARY,
                $workerPath,
                $root,
                $storeDir,
                $concurrentKey,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException(
                'Falha ao iniciar worker.'
            );
        }

        fclose($pipes[0]);

        $processes[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    $turnIds = [];

    foreach ($processes as $worker) {
        $stdout =
            stream_get_contents($worker['stdout']);
        $stderr =
            stream_get_contents($worker['stderr']);

        fclose($worker['stdout']);
        fclose($worker['stderr']);

        $code = proc_close($worker['process']);

        if ($code !== 0) {
            throw new \RuntimeException(
                "Worker falhou: {$stderr}"
            );
        }

        $decoded = json_decode(
            $stdout,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $turnIds[] = $decoded['turn_id'];
    }

    check(
        count(array_unique($turnIds)) === 1,
        'oito workers recebem o mesmo turno'
    );

    $concurrentState =
        $storeAfterRestart->load($concurrentKey);

    check(
        count($concurrentState['turns'] ?? []) === 1,
        'concorrência cria somente um turno'
    );

    check(
        ($concurrentState['sequence'] ?? null) === 3,
        'abertura de turno não altera sequence'
    );

    echo PHP_EOL;
    echo "9. RESTART APÓS CONCORRÊNCIA" . PHP_EOL;

    $restartAgain =
        new JsonConversationStore($storeDir);

    $bufferAgain =
        new MessageBuffer(
            $restartAgain,
            $clock
        );

    $pendingAgain =
        $bufferAgain->openTurn(
            $concurrentKey
        );

    check(
        $pendingAgain instanceof ConversationTurn
        && $pendingAgain->turnId === $turnIds[0],
        'turno concorrente também sobrevive a restart'
    );

    echo PHP_EOL;
    echo "10. FRONTEIRA DA FASE" . PHP_EOL;

    $guicheSource =
        file_get_contents(
            $root . '/src/Guiche.php'
        );

    check(
        is_string($guicheSource)
        && !str_contains(
            $guicheSource,
            'MessageBuffer'
        ),
        'Guiche não recebe responsabilidade de buffer'
    );

    check(
        is_string($guicheSource)
        && !str_contains(
            $guicheSource,
            'ConversationTurn'
        ),
        'Guiche não gerencia lifecycle de turno'
    );

    foreach (
        [
            'Debounce.php',
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
            "Fase 03 ainda não implementa {$future}"
        );
    }

    echo PHP_EOL;
    echo "OK: GUICHÊ v0.5.1 / FASE 03 validada." . PHP_EOL;
} finally {
    removeTree($tempRoot);
}
