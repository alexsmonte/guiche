<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ScheduledEventStoreInterface;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class Scheduler
{
    public function __construct(
        private readonly ScheduledEventStoreInterface $store,
        private readonly ClockInterface $clock
    ) {
    }

    public function schedule(
        string $conversationKey,
        string $type,
        array $payload,
        DateTimeImmutable $dueAt,
        ?string $dedupeKey = null,
        ?int $sequenceSnapshot = null,
        array $requiredState = []
    ): ScheduledEvent {
        if (trim($conversationKey) === '') {
            throw new RuntimeException(
                'conversationKey cannot be empty.'
            );
        }

        if (trim($type) === '') {
            throw new RuntimeException(
                'type cannot be empty.'
            );
        }

        $dedupeHash = null;

        if ($dedupeKey !== null) {
            $dedupeKey = trim($dedupeKey);

            if ($dedupeKey === '') {
                throw new RuntimeException(
                    'dedupeKey cannot be empty when provided.'
                );
            }

            $dedupeHash =
                hash('sha256', $dedupeKey);
        }

        return $this->store->schedule(
            ScheduledEvent::pending(
                conversationKey: $conversationKey,
                type: $type,
                payload: $payload,
                dueAt: $dueAt,
                now: $this->clock->now(),
                dedupeHash: $dedupeHash,
                sequenceSnapshot: $sequenceSnapshot,
                requiredState: $requiredState
            )
        );
    }

    /**
     * @return list<ScheduledEvent>
     */
    public function claimDue(
        string $workerId,
        int $leaseSeconds,
        int $limit = 10
    ): array {
        return $this->store->claimDue(
            $this->clock->now(),
            $workerId,
            $leaseSeconds,
            $limit
        );
    }

    public function renewLease(
        ScheduledEvent $event,
        int $leaseSeconds
    ): ScheduledEvent {
        return $this->store->renewLease(
            $event->eventId,
            $this->token($event),
            $this->clock->now(),
            $leaseSeconds
        );
    }

    public function complete(
        ScheduledEvent $event
    ): ScheduledEvent {
        return $this->store->complete(
            $event->eventId,
            $this->token($event),
            $this->clock->now()
        );
    }

    public function markStale(
        ScheduledEvent $event,
        string $reason
    ): ScheduledEvent {
        return $this->store->markStale(
            $event->eventId,
            $this->token($event),
            $this->clock->now(),
            $reason
        );
    }

    public function markIndeterminate(
        ScheduledEvent $event,
        Throwable $failure
    ): ScheduledEvent {
        return $this->store->markIndeterminate(
            $event->eventId,
            $this->token($event),
            $this->clock->now(),
            $failure::class
        );
    }

    public function cancel(
        string $eventId
    ): ScheduledEvent {
        return $this->store->cancel(
            $eventId,
            $this->clock->now()
        );
    }

    public function find(
        string $eventId
    ): ?ScheduledEvent {
        return $this->store->find(
            $eventId
        );
    }

    private function token(
        ScheduledEvent $event
    ): string {
        $token = $event->leaseToken;

        if (!is_string($token) || $token === '') {
            throw new RuntimeException(
                'Scheduled event has no lease token.'
            );
        }

        return $token;
    }
}
