<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

use Asmpkg\Guiche\Conversation\ScheduledEvent;
use DateTimeImmutable;

interface ScheduledEventStoreInterface
{
    public function schedule(
        ScheduledEvent $event
    ): ScheduledEvent;

    public function find(
        string $eventId
    ): ?ScheduledEvent;

    /**
     * @return list<ScheduledEvent>
     */
    public function claimDue(
        DateTimeImmutable $now,
        string $workerId,
        int $leaseSeconds,
        int $limit
    ): array;

    public function renewLease(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $now,
        int $leaseSeconds
    ): ScheduledEvent;

    public function complete(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $now
    ): ScheduledEvent;

    public function markStale(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $now,
        string $reason
    ): ScheduledEvent;

    public function markIndeterminate(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $now,
        string $failureClass
    ): ScheduledEvent;

    public function cancel(
        string $eventId,
        DateTimeImmutable $now
    ): ScheduledEvent;
}
