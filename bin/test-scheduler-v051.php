<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\JsonScheduledEventStore;
use Asmpkg\Guiche\Conversation\ScheduledEvent;
use Asmpkg\Guiche\Conversation\Scheduler;
use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ScheduledEventStoreInterface;

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

        $full =
            $path
            . DIRECTORY_SEPARATOR
            . $item;

        if (is_dir($full)) {
            removeTree($full);
        } else {
            @unlink($full);
        }
    }

    @rmdir($path);
}

final class SchedulerClock implements ClockInterface
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
        $next =
            $this->value->modify(
                $modifier
            );

        if (!$next instanceof \DateTimeImmutable) {
            throw new \RuntimeException(
                'Invalid clock modifier.'
            );
        }

        $this->value = $next;
    }
}

$root = dirname(__DIR__);
$tempRoot =
    sys_get_temp_dir()
    . '/guiche-v051-phase05-'
    . bin2hex(random_bytes(6));

$storeDir =
    $tempRoot . '/scheduler';

mkdir($storeDir, 0770, true);

try {
    echo PHP_EOL;
    echo "GUICHÊ v0.5.1 — FASE 05 / DURABLE SCHEDULER" . PHP_EOL;
    echo "=================================================" . PHP_EOL;

    echo PHP_EOL;
    echo "1. CONTRATO + PERSISTÊNCIA" . PHP_EOL;

    check(
        interface_exists(
            ScheduledEventStoreInterface::class
        ),
        'ScheduledEventStoreInterface disponível'
    );

    $clock =
        new SchedulerClock(
            new \DateTimeImmutable(
                '2026-10-14T00:00:00-04:00'
            )
        );

    $store =
        new JsonScheduledEventStore(
            $storeDir
        );

    check(
        $store instanceof ScheduledEventStoreInterface,
        'JsonScheduledEventStore implementa contrato'
    );

    $scheduler =
        new Scheduler(
            $store,
            $clock
        );

    $key =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'scheduler-main'
        );

    $future =
        $scheduler->schedule(
            $key,
            'neutral.event',
            ['kind' => 'test'],
            $clock->now()->modify('+60 seconds'),
            'logical-event-1'
        );

    check(
        $future->status === 'pending',
        'evento nasce pending'
    );

    check(
        $future->dedupeHash
            === hash(
                'sha256',
                'logical-event-1'
            ),
        'dedupe_key é persistida somente como hash'
    );

    $dataPath =
        $storeDir
        . '/scheduled-events.json';

    check(
        is_file($dataPath),
        'scheduler é persistido em arquivo durável'
    );

    $raw =
        file_get_contents($dataPath);

    check(
        is_string($raw)
        && !str_contains(
            $raw,
            'logical-event-1'
        ),
        'dedupe_key bruta não aparece no storage'
    );

    check(
        glob($dataPath . '.tmp.*') === [],
        'persistência não deixa temp residual'
    );

    echo PHP_EOL;
    echo "2. DEDUPE DE AGENDAMENTO" . PHP_EOL;

    $same =
        $scheduler->schedule(
            $key,
            'neutral.event.changed',
            ['kind' => 'changed'],
            $clock->now()->modify('+600 seconds'),
            'logical-event-1'
        );

    check(
        $same->eventId === $future->eventId,
        'mesma dedupe_key retorna evento original'
    );

    check(
        $same->type === 'neutral.event',
        'dedupe não sobrescreve evento original'
    );

    echo PHP_EOL;
    echo "3. EVENTO FUTURO NÃO É CLAIMED" . PHP_EOL;

    check(
        $scheduler->claimDue(
            'worker-a',
            30,
            10
        ) === [],
        'evento antes de due_at não pode ser claimed'
    );

    $clock->advance('+60 seconds');

    $claimed =
        $scheduler->claimDue(
            'worker-a',
            30,
            10
        );

    check(
        count($claimed) === 1,
        'evento vencido é claimed'
    );

    $claim1 = $claimed[0];

    check(
        $claim1->status === 'claimed',
        'status muda para claimed'
    );

    check(
        $claim1->leaseOwner === 'worker-a',
        'lease registra worker'
    );

    check(
        is_string($claim1->leaseToken)
        && strlen($claim1->leaseToken) === 32,
        'claim gera fencing token'
    );

    check(
        $claim1->leaseUntil
            instanceof \DateTimeImmutable,
        'claim possui lease_until'
    );

    check(
        $claim1->claimCount === 1,
        'primeiro claim incrementa claim_count'
    );

    echo PHP_EOL;
    echo "4. RESTART PRESERVA TIMER E LEASE" . PHP_EOL;

    unset($scheduler, $store);

    $storeAfterRestart =
        new JsonScheduledEventStore(
            $storeDir
        );

    $schedulerAfterRestart =
        new Scheduler(
            $storeAfterRestart,
            $clock
        );

    $persisted =
        $schedulerAfterRestart->find(
            $claim1->eventId
        );

    check(
        $persisted instanceof ScheduledEvent,
        'evento existe após nova instância'
    );

    check(
        $persisted->status === 'claimed',
        'lease sobrevive a restart'
    );

    check(
        $persisted->leaseToken
            === $claim1->leaseToken,
        'fencing token sobrevive a restart'
    );

    check(
        $schedulerAfterRestart->claimDue(
            'worker-b',
            30,
            10
        ) === [],
        'lease ativo impede segundo worker'
    );

    echo PHP_EOL;
    echo "5. LEASE EXPIRADO É RECLAIMABLE" . PHP_EOL;

    $clock->advance('+31 seconds');

    $reclaimedList =
        $schedulerAfterRestart->claimDue(
            'worker-b',
            30,
            10
        );

    check(
        count($reclaimedList) === 1,
        'lease expirado pode ser reclaimed'
    );

    $reclaimed =
        $reclaimedList[0];

    check(
        $reclaimed->eventId
            === $claim1->eventId,
        'reclaim mantém identidade do evento'
    );

    check(
        $reclaimed->leaseOwner
            === 'worker-b',
        'reclaim transfere ownership'
    );

    check(
        $reclaimed->leaseToken
            !== $claim1->leaseToken,
        'reclaim troca fencing token'
    );

    check(
        $reclaimed->claimCount === 2,
        'reclaim incrementa claim_count'
    );

    $oldTokenRejected = false;

    try {
        $storeAfterRestart->complete(
            $claim1->eventId,
            (string) $claim1->leaseToken,
            $clock->now()
        );
    } catch (\RuntimeException $exception) {
        $oldTokenRejected =
            str_contains(
                $exception->getMessage(),
                'stale'
            );
    }

    check(
        $oldTokenRejected,
        'worker antigo não conclui após reclaim'
    );

    $completed =
        $schedulerAfterRestart->complete(
            $reclaimed
        );

    check(
        $completed->status === 'completed',
        'worker com token atual conclui evento'
    );

    check(
        $completed->completedAt
            instanceof \DateTimeImmutable,
        'evento concluído possui completed_at'
    );

    check(
        $completed->leaseToken === null,
        'conclusão limpa lease token'
    );

    echo PHP_EOL;
    echo "6. RENEW LEASE" . PHP_EOL;

    $renewEvent =
        $schedulerAfterRestart->schedule(
            $key,
            'neutral.renew',
            ['x' => 1],
            $clock->now(),
            'renew-1'
        );

    $renewClaim =
        $schedulerAfterRestart
            ->claimDue(
                'worker-renew',
                10,
                1
            )[0];

    $clock->advance('+5 seconds');

    $renewed =
        $schedulerAfterRestart
            ->renewLease(
                $renewClaim,
                10
            );

    check(
        $renewed->leaseUntil
            > $renewClaim->leaseUntil,
        'renewLease estende lease'
    );

    $clock->advance('+6 seconds');

    check(
        $schedulerAfterRestart->claimDue(
            'worker-other',
            10,
            10
        ) === [],
        'lease renovado ainda bloqueia reclaim'
    );

    $clock->advance('+5 seconds');

    $renewReclaim =
        $schedulerAfterRestart->claimDue(
            'worker-other',
            10,
            10
        );

    check(
        count($renewReclaim) === 1
        && $renewReclaim[0]->eventId
            === $renewEvent->eventId,
        'evento volta a ser reclaimable após lease renovado expirar'
    );

    echo PHP_EOL;
    echo "7. CANCELAMENTO" . PHP_EOL;

    $pendingCancel =
        $schedulerAfterRestart->schedule(
            $key,
            'neutral.cancel.pending',
            [],
            $clock->now(),
            'cancel-pending'
        );

    $cancelledPending =
        $schedulerAfterRestart->cancel(
            $pendingCancel->eventId
        );

    check(
        $cancelledPending->status === 'cancelled',
        'evento pending pode ser cancelado'
    );

    $cancelledAgain =
        $schedulerAfterRestart->cancel(
            $pendingCancel->eventId
        );

    check(
        $cancelledAgain->cancelledAt
            == $cancelledPending->cancelledAt,
        'cancelamento é idempotente'
    );

    $claimedCancel =
        $schedulerAfterRestart->schedule(
            $key,
            'neutral.cancel.claimed',
            [],
            $clock->now(),
            'cancel-claimed'
        );

    $claimForCancel =
        $schedulerAfterRestart
            ->claimDue(
                'worker-cancel',
                30,
                10
            );

    $claimedCancelCurrent = null;

    foreach ($claimForCancel as $candidate) {
        if (
            $candidate->eventId
            === $claimedCancel->eventId
        ) {
            $claimedCancelCurrent = $candidate;
            break;
        }
    }

    check(
        $claimedCancelCurrent
            instanceof ScheduledEvent,
        'evento alvo foi claimed antes do cancelamento'
    );

    $cancelledClaimed =
        $schedulerAfterRestart->cancel(
            $claimedCancel->eventId
        );

    check(
        $cancelledClaimed->status === 'cancelled',
        'evento claimed pode ser cancelado'
    );

    check(
        $cancelledClaimed->leaseToken === null,
        'cancelamento invalida lease'
    );

    $cancelTokenRejected = false;

    try {
        $schedulerAfterRestart->complete(
            $claimedCancelCurrent
        );
    } catch (\RuntimeException) {
        $cancelTokenRejected = true;
    }

    check(
        $cancelTokenRejected,
        'token anterior não conclui evento cancelado'
    );

    echo PHP_EOL;
    echo "8. ORDER + LIMIT" . PHP_EOL;

    $orderKey =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'scheduler-order'
        );

    $orderA =
        $schedulerAfterRestart->schedule(
            $orderKey,
            'order.a',
            [],
            $clock->now()->modify('-30 seconds'),
            'order-a'
        );

    $orderB =
        $schedulerAfterRestart->schedule(
            $orderKey,
            'order.b',
            [],
            $clock->now()->modify('-20 seconds'),
            'order-b'
        );

    $orderC =
        $schedulerAfterRestart->schedule(
            $orderKey,
            'order.c',
            [],
            $clock->now()->modify('-10 seconds'),
            'order-c'
        );

    $ordered =
        $schedulerAfterRestart->claimDue(
            'worker-order',
            60,
            2
        );

    check(
        count($ordered) === 2,
        'limit restringe quantidade claimed'
    );

    check(
        $ordered[0]->eventId === $orderA->eventId
        && $ordered[1]->eventId === $orderB->eventId,
        'claim respeita due_at mais antigo primeiro'
    );

    check(
        $schedulerAfterRestart->find(
            $orderC->eventId
        )?->status === 'pending',
        'evento fora do limit permanece pending'
    );

    echo PHP_EOL;
    echo "9. CLAIM CONCORRENTE" . PHP_EOL;

    $raceDir =
        $tempRoot . '/scheduler-race';

    mkdir($raceDir, 0770, true);

    $raceClock =
        new SchedulerClock(
            new \DateTimeImmutable(
                '2026-10-14T12:00:00Z'
            )
        );

    $raceScheduler =
        new Scheduler(
            new JsonScheduledEventStore(
                $raceDir
            ),
            $raceClock
        );

    $raceEvent =
        $raceScheduler->schedule(
            $key,
            'race',
            [],
            $raceClock->now(),
            'race-one'
        );

    $workerPath =
        $tempRoot . '/scheduler-worker.php';

    $workerSource = <<<'WORKER'
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

$clock =
    new class implements
        \Asmpkg\Guiche\Contracts\ClockInterface
    {
        public function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable(
                '2026-10-14T12:00:00Z'
            );
        }
    };

$scheduler =
    new \Asmpkg\Guiche\Conversation\Scheduler(
        new \Asmpkg\Guiche\Conversation\JsonScheduledEventStore(
            $argv[2]
        ),
        $clock
    );

$claimed =
    $scheduler->claimDue(
        $argv[3],
        30,
        1
    );

echo json_encode(
    [
        'count' => count($claimed),
        'event_id' =>
            $claimed[0]->eventId
            ?? null,
        'lease_token' =>
            $claimed[0]->leaseToken
            ?? null,
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

        $process =
            proc_open(
                [
                    PHP_BINARY,
                    $workerPath,
                    $root,
                    $raceDir,
                    'worker-' . $i,
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
                'Falha ao iniciar worker concorrente.'
            );
        }

        fclose($pipes[0]);

        $processes[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    $claimers = [];

    foreach ($processes as $worker) {
        $stdout =
            stream_get_contents(
                $worker['stdout']
            );

        $stderr =
            stream_get_contents(
                $worker['stderr']
            );

        fclose($worker['stdout']);
        fclose($worker['stderr']);

        $code =
            proc_close(
                $worker['process']
            );

        if ($code !== 0) {
            throw new \RuntimeException(
                "Worker concorrente falhou: {$stderr}"
            );
        }

        $decoded =
            json_decode(
                $stdout,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        if (($decoded['count'] ?? 0) === 1) {
            $claimers[] = $decoded;
        }
    }

    check(
        count($claimers) === 1,
        'oito workers produzem exatamente um claim'
    );

    check(
        ($claimers[0]['event_id'] ?? null)
            === $raceEvent->eventId,
        'claim concorrente pertence ao evento correto'
    );

    $racePersisted =
        (new JsonScheduledEventStore(
            $raceDir
        ))->find(
            $raceEvent->eventId
        );

    check(
        $racePersisted?->claimCount === 1,
        'claim concorrente incrementa claim_count uma vez'
    );

    echo PHP_EOL;
    echo "10. JSON CORROMPIDO" . PHP_EOL;

    $corruptDir =
        $tempRoot . '/scheduler-corrupt';

    mkdir($corruptDir, 0770, true);

    $corruptPath =
        $corruptDir
        . '/scheduled-events.json';

    file_put_contents(
        $corruptPath,
        '{"broken":'
    );

    $corruptStore =
        new JsonScheduledEventStore(
            $corruptDir
        );

    $corruptionDetected = false;

    try {
        $corruptStore->find('anything');
    } catch (\RuntimeException $exception) {
        $corruptionDetected =
            str_contains(
                $exception->getMessage(),
                'corrupted'
            );
    }

    check(
        $corruptionDetected,
        'JSON corrompido falha explicitamente'
    );

    check(
        file_get_contents($corruptPath)
            === '{"broken":',
        'erro não sobrescreve storage corrompido'
    );

    echo PHP_EOL;
    echo "11. FRONTEIRA DA FASE" . PHP_EOL;

    $schedulerSource =
        file_get_contents(
            $root
            . '/src/Conversation/Scheduler.php'
        );

    check(
        is_string($schedulerSource)
        && !str_contains(
            $schedulerSource,
            'use Asmpkg\\Guiche\\Guiche;'
        )
        && !str_contains(
            $schedulerSource,
            'private readonly Guiche'
        )
        && !str_contains(
            $schedulerSource,
            'Guiche $'
        )
        && !str_contains(
            $schedulerSource,
            '->handle('
        ),
        'Scheduler não depende nem executa Guiche'
    );

    check(
        is_string($schedulerSource)
        && !str_contains(
            mb_strtolower(
                $schedulerSource,
                'UTF-8'
            ),
            'followup'
        ),
        'Scheduler não conhece follow-up'
    );

    $scheduledSource =
        file_get_contents(
            $root
            . '/src/Conversation/ScheduledEvent.php'
        );

    check(
        is_string($scheduledSource),
        'ScheduledEvent permanece inspecionável após evolução da Fase 06'
    );

    echo PHP_EOL;
    echo "OK: GUICHÊ v0.5.1 / FASE 05 validada." . PHP_EOL;
} finally {
    removeTree($tempRoot);
}
