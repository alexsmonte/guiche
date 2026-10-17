<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Clock\SystemClock;
use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\ConversationStoreInterface;
use Asmpkg\Guiche\Contracts\ScheduledEventStoreInterface;
use RuntimeException;

/**
 * Facade de DX para o motor durável de follow-up.
 *
 * Não altera nem duplica Scheduler, FollowUpScheduler ou
 * ScheduledEventRunner. Apenas compõe as peças já existentes.
 *
 * @experimental
 */
final class FollowUpRuntime
{
    public const DEFAULT_AFTER_SECONDS = 7200;
    public const DEFAULT_LEASE_SECONDS = 60;
    public const DEFAULT_LIMIT = 10;

    private readonly Scheduler $scheduler;
    private readonly FollowUpScheduler $followUps;
    private readonly ScheduledEventRunner $runner;

    public function __construct(
        ScheduledEventStoreInterface $scheduledEventStore,
        ConversationStoreInterface $conversationStore,
        ContextStoreInterface $contextStore,
        ?ClockInterface $clock = null,
        private readonly int $defaultDelaySeconds =
            self::DEFAULT_AFTER_SECONDS,
        private readonly bool $enabled = true
    ) {
        if ($this->defaultDelaySeconds <= 0) {
            throw new RuntimeException(
                'defaultDelaySeconds must be positive.'
            );
        }

        $clock ??= new SystemClock();

        $this->scheduler = new Scheduler(
            $scheduledEventStore,
            $clock
        );

        $this->followUps = new FollowUpScheduler(
            $this->scheduler,
            $conversationStore,
            $clock,
            $this->defaultDelaySeconds
        );

        $this->runner = new ScheduledEventRunner(
            $this->scheduler,
            $conversationStore,
            $contextStore
        );
    }

    /**
     * Atalho para instalação padrão em JSON.
     */
    public static function fromDirectories(
        string $conversationDirectory,
        string $schedulerDirectory,
        string $contextDirectory,
        int $defaultDelaySeconds =
            self::DEFAULT_AFTER_SECONDS,
        bool $enabled = true,
        string $contextTimezone = 'UTC',
        ?ClockInterface $clock = null
    ): self {
        return new self(
            new JsonScheduledEventStore(
                $schedulerDirectory
            ),
            new JsonConversationStore(
                $conversationDirectory
            ),
            new ContextStore(
                $contextDirectory,
                $contextTimezone
            ),
            $clock,
            $defaultDelaySeconds,
            $enabled
        );
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function defaultDelaySeconds(): int
    {
        return $this->defaultDelaySeconds;
    }

    /**
     * Agenda follow-up somente quando o recurso está habilitado.
     *
     * O snapshot de sequence e as precondições de estado continuam
     * sendo responsabilidade do FollowUpScheduler existente.
     *
     * @param array<mixed> $payload
     * @param array<mixed> $requiredState
     */
    public function schedule(
        string $conversationKey,
        array $payload = [],
        array $requiredState = [],
        ?int $delaySeconds = null,
        ?string $dedupeKey = null
    ): ?ScheduledEvent {
        if (!$this->enabled) {
            return null;
        }

        return $this->followUps->schedule(
            $conversationKey,
            $payload,
            $requiredState,
            $delaySeconds,
            $dedupeKey
        );
    }

    /**
     * Executa eventos vencidos por worker externo.
     *
     * Quando disabled, nenhum evento é claimed.
     *
     * @param callable(ScheduledEvent): mixed $handler
     *
     * @return list<array{
     *     event_id:string,
     *     status:string,
     *     reason?:string
     * }>
     */
    public function runDue(
        string $workerId,
        callable $handler,
        int $leaseSeconds =
            self::DEFAULT_LEASE_SECONDS,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        if (!$this->enabled) {
            return [];
        }

        if ($leaseSeconds <= 0) {
            throw new RuntimeException(
                'leaseSeconds must be positive.'
            );
        }

        if ($limit <= 0) {
            throw new RuntimeException(
                'limit must be positive.'
            );
        }

        return $this->runner->runDue(
            $workerId,
            $leaseSeconds,
            $handler,
            $limit
        );
    }

    /**
     * Gerenciamento permanece disponível mesmo quando disabled,
     * permitindo cancelar timers persistidos anteriormente.
     */
    public function cancel(
        string $eventId
    ): ScheduledEvent {
        return $this->scheduler->cancel(
            $eventId
        );
    }

    public function find(
        string $eventId
    ): ?ScheduledEvent {
        return $this->scheduler->find(
            $eventId
        );
    }
}
