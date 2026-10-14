<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\FollowUpScheduler;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Conversation\JsonScheduledEventStore;
use Asmpkg\Guiche\Conversation\ScheduledEventRunner;
use Asmpkg\Guiche\Conversation\Scheduler;
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

final class FollowUpClock implements ClockInterface
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
            $this->value->modify($modifier);

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
    . '/guiche-v051-phase06-'
    . bin2hex(random_bytes(6));

$conversationDir =
    $tempRoot . '/conversations';

$schedulerDir =
    $tempRoot . '/scheduler';

$contextDir =
    $tempRoot . '/contexts';

mkdir($conversationDir, 0770, true);
mkdir($schedulerDir, 0770, true);
mkdir($contextDir, 0770, true);

try {
    echo PHP_EOL;
    echo "GUICHÊ v0.5.1 — FASE 06 / STALE + FOLLOW-UP + RECOVERY" . PHP_EOL;
    echo "============================================================" . PHP_EOL;

    $clock =
        new FollowUpClock(
            new \DateTimeImmutable(
                '2026-10-14T12:00:00Z'
            )
        );

    $conversationStore =
        new JsonConversationStore(
            $conversationDir
        );

    $incoming =
        new IncomingEventRecorder(
            $conversationStore,
            $clock
        );

    $scheduledStore =
        new JsonScheduledEventStore(
            $schedulerDir
        );

    $scheduler =
        new Scheduler(
            $scheduledStore,
            $clock
        );

    $contextStore =
        new ContextStore(
            $contextDir,
            'UTC'
        );

    $followUps =
        new FollowUpScheduler(
            $scheduler,
            $conversationStore,
            $clock
        );

    $runner =
        new ScheduledEventRunner(
            $scheduler,
            $conversationStore,
            $contextStore
        );

    echo PHP_EOL;
    echo "1. FOLLOW-UP CAPTURA SNAPSHOT + 5 MIN" . PHP_EOL;

    $key =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'followup-ok'
        );

    $incoming->record(
        $key,
        new IncomingEvent(
            'followup-ok-1',
            'message',
            ['text' => 'oi']
        )
    );

    $contextStore->mergeState(
        $key,
        ['stage' => 'waiting']
    );

    $follow =
        $followUps->schedule(
            $key,
            ['template' => 'neutral'],
            ['state.stage' => 'waiting']
        );

    check(
        $follow->sequenceSnapshot === 1,
        'follow-up captura sequence_snapshot atual'
    );

    check(
        $follow->dueAt->getTimestamp()
            - $clock->now()->getTimestamp()
            === 300,
        'follow-up default é 300 segundos'
    );

    check(
        $follow->requiredState
            === ['state.stage' => 'waiting'],
        'precondição de state é persistida'
    );

    $followAgain =
        $followUps->schedule(
            $key,
            ['template' => 'changed'],
            ['state.stage' => 'waiting']
        );

    check(
        $followAgain->eventId === $follow->eventId,
        'follow-up da mesma sequence é deduplicado'
    );

    check(
        $runner->runDue(
            'worker-a',
            60,
            static function (): void {
                throw new \RuntimeException(
                    'não deveria executar antes do due'
                );
            }
        ) === [],
        'follow-up não executa antes de due_at'
    );

    $clock->advance('+300 seconds');

    $executions = 0;

    $result =
        $runner->runDue(
            'worker-a',
            60,
            static function () use (
                &$executions
            ): void {
                $executions++;
            }
        );

    check(
        $executions === 1,
        'handler válido executa uma vez'
    );

    check(
        ($result[0]['status'] ?? null)
            === 'completed',
        'follow-up válido termina completed'
    );

    check(
        $scheduler->find(
            $follow->eventId
        )?->status === 'completed',
        'completed fica persistido'
    );

    echo PHP_EOL;
    echo "2. USER RESPONSE TORNA FOLLOW-UP STALE" . PHP_EOL;

    $staleKey =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'followup-stale'
        );

    $incoming->record(
        $staleKey,
        new IncomingEvent(
            'stale-1',
            'message',
            ['text' => 'primeira']
        )
    );

    $staleEvent =
        $followUps->schedule(
            $staleKey
        );

    check(
        $staleEvent->sequenceSnapshot === 1,
        'stale case agenda snapshot=1'
    );

    $clock->advance('+100 seconds');

    $incoming->record(
        $staleKey,
        new IncomingEvent(
            'stale-2',
            'message',
            ['text' => 'usuário respondeu']
        )
    );

    $clock->advance('+200 seconds');

    $staleExecutions = 0;

    $staleResult =
        $runner->runDue(
            'worker-stale',
            60,
            static function () use (
                &$staleExecutions
            ): void {
                $staleExecutions++;
            }
        );

    check(
        $staleExecutions === 0,
        'handler não executa quando sequence mudou'
    );

    check(
        ($staleResult[0]['status'] ?? null)
            === 'stale'
        && ($staleResult[0]['reason'] ?? null)
            === 'sequence_changed',
        'sequence diferente produz stale explícito'
    );

    $stalePersisted =
        $scheduler->find(
            $staleEvent->eventId
        );

    check(
        $stalePersisted?->status === 'stale'
        && $stalePersisted->staleReason
            === 'sequence_changed',
        'stale por sequence fica persistido'
    );

    check(
        $runner->runDue(
            'worker-stale-2',
            60,
            static function () use (
                &$staleExecutions
            ): void {
                $staleExecutions++;
            }
        ) === [],
        'evento stale não volta para fila'
    );

    echo PHP_EOL;
    echo "3. STATE CONDITION TORNA FOLLOW-UP STALE" . PHP_EOL;

    $stateKey =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'followup-state'
        );

    $incoming->record(
        $stateKey,
        new IncomingEvent(
            'state-1',
            'message',
            ['text' => 'oi']
        )
    );

    $contextStore->mergeState(
        $stateKey,
        ['stage' => 'waiting']
    );

    $stateEvent =
        $followUps->schedule(
            $stateKey,
            [],
            ['state.stage' => 'waiting']
        );

    $contextStore->mergeState(
        $stateKey,
        ['stage' => 'changed']
    );

    $clock->advance('+300 seconds');

    $stateExecutions = 0;

    $stateResult =
        $runner->runDue(
            'worker-state',
            60,
            static function () use (
                &$stateExecutions
            ): void {
                $stateExecutions++;
            }
        );

    check(
        $stateExecutions === 0,
        'handler não executa quando state mudou'
    );

    check(
        ($stateResult[0]['status'] ?? null)
            === 'stale'
        && ($stateResult[0]['reason'] ?? null)
            === 'state_condition_changed',
        'precondição incompatível produz stale'
    );

    echo PHP_EOL;
    echo "4. HANDLER INCERTO → INDETERMINATE" . PHP_EOL;

    $indeterminateKey =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'followup-indeterminate'
        );

    $incoming->record(
        $indeterminateKey,
        new IncomingEvent(
            'indeterminate-1',
            'message',
            ['text' => 'oi']
        )
    );

    $indeterminateEvent =
        $followUps->schedule(
            $indeterminateKey
        );

    $clock->advance('+300 seconds');

    $attempts = 0;

    $indeterminateResult =
        $runner->runDue(
            'worker-indeterminate',
            60,
            static function () use (
                &$attempts
            ): void {
                $attempts++;

                throw new \RuntimeException(
                    'SECRET_EXTERNAL_RESULT_UNKNOWN'
                );
            }
        );

    check(
        $attempts === 1,
        'handler incerto é tentado uma vez'
    );

    check(
        ($indeterminateResult[0]['status'] ?? null)
            === 'indeterminate',
        'exceção após início vira indeterminate'
    );

    $indeterminatePersisted =
        $scheduler->find(
            $indeterminateEvent->eventId
        );

    check(
        $indeterminatePersisted?->status
            === 'indeterminate',
        'indeterminate fica persistido'
    );

    check(
        $indeterminatePersisted?->failureClass
            === \RuntimeException::class,
        'somente classe da falha é persistida'
    );

    $schedulerRaw =
        file_get_contents(
            $schedulerDir
            . '/scheduled-events.json'
        );

    check(
        is_string($schedulerRaw)
        && !str_contains(
            $schedulerRaw,
            'SECRET_EXTERNAL_RESULT_UNKNOWN'
        ),
        'mensagem bruta da falha não vaza'
    );

    $clock->advance('+3600 seconds');

    check(
        $runner->runDue(
            'worker-indeterminate-2',
            60,
            static function () use (
                &$attempts
            ): void {
                $attempts++;
            }
        ) === [],
        'indeterminate nunca recebe retry automático'
    );

    check(
        $attempts === 1,
        'nenhum side effect potencial é repetido'
    );

    echo PHP_EOL;
    echo "5. RECOVERY DE CLAIM ABANDONADO" . PHP_EOL;

    $recoveryKey =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'followup-recovery'
        );

    $incoming->record(
        $recoveryKey,
        new IncomingEvent(
            'recovery-1',
            'message',
            ['text' => 'oi']
        )
    );

    $recoveryEvent =
        $followUps->schedule(
            $recoveryKey,
            [],
            [],
            10
        );

    $clock->advance('+10 seconds');

    $abandoned =
        $scheduler->claimDue(
            'dead-worker',
            10,
            1
        );

    check(
        count($abandoned) === 1
        && $abandoned[0]->eventId
            === $recoveryEvent->eventId,
        'worker morto deixa evento claimed'
    );

    unset(
        $runner,
        $followUps,
        $scheduler,
        $scheduledStore
    );

    $clock->advance('+11 seconds');

    $scheduledStore2 =
        new JsonScheduledEventStore(
            $schedulerDir
        );

    $scheduler2 =
        new Scheduler(
            $scheduledStore2,
            $clock
        );

    $followUps2 =
        new FollowUpScheduler(
            $scheduler2,
            $conversationStore,
            $clock
        );

    $runner2 =
        new ScheduledEventRunner(
            $scheduler2,
            $conversationStore,
            $contextStore
        );

    $recoveryRuns = 0;

    $recoveryResult =
        $runner2->runDue(
            'recovery-worker',
            60,
            static function () use (
                &$recoveryRuns
            ): void {
                $recoveryRuns++;
            }
        );

    check(
        $recoveryRuns === 1,
        'lease expirado antes do handler é recuperado'
    );

    check(
        ($recoveryResult[0]['status'] ?? null)
            === 'completed',
        'recovery conclui evento'
    );

    check(
        $scheduler2->find(
            $recoveryEvent->eventId
        )?->claimCount === 2,
        'recovery usa reclaim e incrementa claim_count'
    );

    echo PHP_EOL;
    echo "6. FOLLOW-UP CONFIGURÁVEL" . PHP_EOL;

    $customKey =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'followup-custom'
        );

    $incoming->record(
        $customKey,
        new IncomingEvent(
            'custom-1',
            'message',
            ['text' => 'oi']
        )
    );

    $customFollowUps =
        new FollowUpScheduler(
            $scheduler2,
            $conversationStore,
            $clock,
            600
        );

    $custom =
        $customFollowUps->schedule(
            $customKey
        );

    check(
        $custom->dueAt->getTimestamp()
            - $clock->now()->getTimestamp()
            === 600,
        'default de follow-up pode ser configurado'
    );

    echo PHP_EOL;
    echo "7. NEUTRALIDADE E FRONTEIRAS" . PHP_EOL;

    $sources = '';

    foreach (
        [
            $root . '/src/Conversation/FollowUpScheduler.php',
            $root . '/src/Conversation/ScheduledEventRunner.php',
            $root . '/src/Conversation/ScheduledEvent.php',
        ] as $sourcePath
    ) {
        $content =
            file_get_contents(
                $sourcePath
            );

        if (!is_string($content)) {
            throw new \RuntimeException(
                'Falha ao ler fonte para auditoria.'
            );
        }

        $sources .= $content;
    }

    foreach (
        [
            'Clinical',
            'Paciente',
            'Convenio',
            'Convênio',
            'Agendamento',
            'Dental',
            'Ortoshine',
        ] as $forbidden
    ) {
        check(
            !str_contains(
                $sources,
                $forbidden
            ),
            "Conversation Runtime neutro: {$forbidden}"
        );
    }

    $guicheSource =
        file_get_contents(
            $root . '/src/Guiche.php'
        );

    check(
        is_string($guicheSource)
        && !str_contains(
            $guicheSource,
            'FollowUpScheduler'
        )
        && !str_contains(
            $guicheSource,
            'ScheduledEventRunner'
        ),
        'Guiche continua sem responsabilidade de scheduling'
    );

    echo PHP_EOL;
    echo "OK: GUICHÊ v0.5.1 / FASE 06 validada." . PHP_EOL;
} finally {
    removeTree($tempRoot);
}
