<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Contracts\ScheduledEventStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

final class JsonScheduledEventStore
    implements ScheduledEventStoreInterface
{
    private const SCHEMA_VERSION = 1;

    private readonly string $dataPath;

    private readonly string $lockPath;

    public function __construct(
        private readonly string $directory
    ) {
        if (trim($this->directory) === '') {
            throw new RuntimeException(
                'Scheduled event store directory cannot be empty.'
            );
        }

        if (
            !is_dir($this->directory)
            && !mkdir($this->directory, 0770, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException(
                'Unable to create scheduled event store directory.'
            );
        }

        $this->dataPath =
            $this->directory
            . DIRECTORY_SEPARATOR
            . 'scheduled-events.json';

        $this->lockPath =
            $this->directory
            . DIRECTORY_SEPARATOR
            . 'scheduled-events.lock';
    }

    public function schedule(
        ScheduledEvent $event
    ): ScheduledEvent {
        $scheduled = null;

        $this->mutate(
            function (array $state) use (
                $event,
                &$scheduled
            ): array {
                $dedupeHash = $event->dedupeHash;

                if (
                    is_string($dedupeHash)
                    && $dedupeHash !== ''
                ) {
                    $existingId =
                        $state['dedupe'][$dedupeHash]
                        ?? null;

                    if (
                        is_string($existingId)
                        && isset($state['events'][$existingId])
                        && is_array($state['events'][$existingId])
                    ) {
                        $scheduled =
                            ScheduledEvent::fromArray(
                                $state['events'][$existingId]
                            );

                        return $state;
                    }
                }

                if (isset($state['events'][$event->eventId])) {
                    throw new RuntimeException(
                        'Scheduled event id already exists.'
                    );
                }

                $state['events'][$event->eventId] =
                    $event->toArray();

                if (
                    is_string($dedupeHash)
                    && $dedupeHash !== ''
                ) {
                    $state['dedupe'][$dedupeHash] =
                        $event->eventId;
                }

                $scheduled = $event;

                return $state;
            }
        );

        if (!$scheduled instanceof ScheduledEvent) {
            throw new RuntimeException(
                'Scheduled event was not persisted.'
            );
        }

        return $scheduled;
    }

    public function find(
        string $eventId
    ): ?ScheduledEvent {
        if (trim($eventId) === '') {
            throw new RuntimeException(
                'eventId cannot be empty.'
            );
        }

        $state = $this->readShared();

        $data =
            $state['events'][$eventId]
            ?? null;

        if (!is_array($data)) {
            return null;
        }

        return ScheduledEvent::fromArray($data);
    }

    public function claimDue(
        DateTimeImmutable $now,
        string $workerId,
        int $leaseSeconds,
        int $limit
    ): array {
        if (trim($workerId) === '') {
            throw new RuntimeException(
                'workerId cannot be empty.'
            );
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

        $claimed = [];

        $this->mutate(
            function (array $state) use (
                $now,
                $workerId,
                $leaseSeconds,
                $limit,
                &$claimed
            ): array {
                $now = self::utcDate($now);
                $candidates = [];

                foreach ($state['events'] as $data) {
                    if (!is_array($data)) {
                        continue;
                    }

                    $event =
                        ScheduledEvent::fromArray($data);

                    if (!$this->isClaimable($event, $now)) {
                        continue;
                    }

                    $candidates[] = $event;
                }

                usort(
                    $candidates,
                    static function (
                        ScheduledEvent $left,
                        ScheduledEvent $right
                    ): int {
                        $due =
                            $left->dueAt
                                <=> $right->dueAt;

                        if ($due !== 0) {
                            return $due;
                        }

                        $created =
                            $left->createdAt
                                <=> $right->createdAt;

                        if ($created !== 0) {
                            return $created;
                        }

                        return strcmp(
                            $left->eventId,
                            $right->eventId
                        );
                    }
                );

                foreach (
                    array_slice($candidates, 0, $limit)
                    as $candidate
                ) {
                    $leaseToken =
                        bin2hex(random_bytes(16));

                    $leaseUntil =
                        $now->modify(
                            "+{$leaseSeconds} seconds"
                        );

                    $updated =
                        $this->copy(
                            $candidate,
                            status: 'claimed',
                            updatedAt: $now,
                            leaseOwner: $workerId,
                            leaseToken: $leaseToken,
                            leaseUntil: $leaseUntil,
                            claimCount:
                                $candidate->claimCount + 1
                        );

                    $state['events'][$updated->eventId] =
                        $updated->toArray();

                    $claimed[] = $updated;
                }

                return $state;
            }
        );

        return $claimed;
    }

    public function renewLease(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $now,
        int $leaseSeconds
    ): ScheduledEvent {
        if ($leaseSeconds <= 0) {
            throw new RuntimeException(
                'leaseSeconds must be positive.'
            );
        }

        $updated = null;

        $this->mutate(
            function (array $state) use (
                $eventId,
                $leaseToken,
                $now,
                $leaseSeconds,
                &$updated
            ): array {
                $event =
                    $this->requireEvent(
                        $state,
                        $eventId
                    );

                $now = self::utcDate($now);

                $this->assertActiveLease(
                    $event,
                    $leaseToken,
                    $now
                );

                $updated =
                    $this->copy(
                        $event,
                        updatedAt: $now,
                        leaseOwner: $event->leaseOwner,
                        leaseToken: $event->leaseToken,
                        leaseUntil:
                            $now->modify(
                                "+{$leaseSeconds} seconds"
                            )
                    );

                $state['events'][$eventId] =
                    $updated->toArray();

                return $state;
            }
        );

        if (!$updated instanceof ScheduledEvent) {
            throw new RuntimeException(
                'Lease renewal failed.'
            );
        }

        return $updated;
    }

    public function complete(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $now
    ): ScheduledEvent {
        $updated = null;

        $this->mutate(
            function (array $state) use (
                $eventId,
                $leaseToken,
                $now,
                &$updated
            ): array {
                $event =
                    $this->requireEvent(
                        $state,
                        $eventId
                    );

                $now = self::utcDate($now);

                $this->assertActiveLease(
                    $event,
                    $leaseToken,
                    $now
                );

                $updated =
                    $this->copy(
                        $event,
                        status: 'completed',
                        updatedAt: $now,
                        leaseOwner: null,
                        leaseToken: null,
                        leaseUntil: null,
                        completedAt: $now
                    );

                $state['events'][$eventId] =
                    $updated->toArray();

                return $state;
            }
        );

        if (!$updated instanceof ScheduledEvent) {
            throw new RuntimeException(
                'Scheduled event completion failed.'
            );
        }

        return $updated;
    }

    public function markStale(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $now,
        string $reason
    ): ScheduledEvent {
        if (
            !in_array(
                $reason,
                [
                    'sequence_changed',
                    'state_condition_changed',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid stale reason.'
            );
        }

        $updated = null;

        $this->mutate(
            function (array $state) use (
                $eventId,
                $leaseToken,
                $now,
                $reason,
                &$updated
            ): array {
                $event =
                    $this->requireEvent(
                        $state,
                        $eventId
                    );

                $now = self::utcDate($now);

                $this->assertTokenOwnership(
                    $event,
                    $leaseToken
                );

                $updated =
                    $this->copy(
                        $event,
                        status: 'stale',
                        updatedAt: $now,
                        leaseOwner: null,
                        leaseToken: null,
                        leaseUntil: null,
                        staleAt: $now,
                        staleReason: $reason
                    );

                $state['events'][$eventId] =
                    $updated->toArray();

                return $state;
            }
        );

        if (!$updated instanceof ScheduledEvent) {
            throw new RuntimeException(
                'Scheduled event stale transition failed.'
            );
        }

        return $updated;
    }

    public function markIndeterminate(
        string $eventId,
        string $leaseToken,
        DateTimeImmutable $now,
        string $failureClass
    ): ScheduledEvent {
        if (trim($failureClass) === '') {
            throw new RuntimeException(
                'failureClass cannot be empty.'
            );
        }

        $updated = null;

        $this->mutate(
            function (array $state) use (
                $eventId,
                $leaseToken,
                $now,
                $failureClass,
                &$updated
            ): array {
                $event =
                    $this->requireEvent(
                        $state,
                        $eventId
                    );

                $now = self::utcDate($now);

                $this->assertTokenOwnership(
                    $event,
                    $leaseToken
                );

                $updated =
                    $this->copy(
                        $event,
                        status: 'indeterminate',
                        updatedAt: $now,
                        leaseOwner: null,
                        leaseToken: null,
                        leaseUntil: null,
                        indeterminateAt: $now,
                        failureClass: $failureClass
                    );

                $state['events'][$eventId] =
                    $updated->toArray();

                return $state;
            }
        );

        if (!$updated instanceof ScheduledEvent) {
            throw new RuntimeException(
                'Scheduled event indeterminate transition failed.'
            );
        }

        return $updated;
    }

    public function cancel(
        string $eventId,
        DateTimeImmutable $now
    ): ScheduledEvent {
        $updated = null;

        $this->mutate(
            function (array $state) use (
                $eventId,
                $now,
                &$updated
            ): array {
                $event =
                    $this->requireEvent(
                        $state,
                        $eventId
                    );

                if ($event->status === 'cancelled') {
                    $updated = $event;

                    return $state;
                }

                if (
                    in_array(
                        $event->status,
                        [
                            'completed',
                            'stale',
                            'indeterminate',
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Terminal scheduled event cannot be cancelled.'
                    );
                }

                $now = self::utcDate($now);

                $updated =
                    $this->copy(
                        $event,
                        status: 'cancelled',
                        updatedAt: $now,
                        leaseOwner: null,
                        leaseToken: null,
                        leaseUntil: null,
                        cancelledAt: $now
                    );

                $state['events'][$eventId] =
                    $updated->toArray();

                return $state;
            }
        );

        if (!$updated instanceof ScheduledEvent) {
            throw new RuntimeException(
                'Scheduled event cancellation failed.'
            );
        }

        return $updated;
    }

    private function copy(
        ScheduledEvent $event,
        ?string $status = null,
        ?DateTimeImmutable $updatedAt = null,
        string|null $leaseOwner = null,
        string|null $leaseToken = null,
        DateTimeImmutable|null $leaseUntil = null,
        ?int $claimCount = null,
        DateTimeImmutable|null $completedAt = null,
        DateTimeImmutable|null $cancelledAt = null,
        DateTimeImmutable|null $staleAt = null,
        string|null $staleReason = null,
        DateTimeImmutable|null $indeterminateAt = null,
        string|null $failureClass = null
    ): ScheduledEvent {
        $args = [
            'eventId' => $event->eventId,
            'conversationKey' => $event->conversationKey,
            'type' => $event->type,
            'payload' => $event->payload,
            'dueAt' => $event->dueAt,
            'status' => $status ?? $event->status,
            'createdAt' => $event->createdAt,
            'updatedAt' => $updatedAt ?? $event->updatedAt,
            'dedupeHash' => $event->dedupeHash,
            'leaseOwner' => $leaseOwner,
            'leaseToken' => $leaseToken,
            'leaseUntil' => $leaseUntil,
            'claimCount' => $claimCount ?? $event->claimCount,
            'completedAt' => $completedAt ?? $event->completedAt,
            'cancelledAt' => $cancelledAt ?? $event->cancelledAt,
            'sequenceSnapshot' => $event->sequenceSnapshot,
            'requiredState' => $event->requiredState,
            'staleAt' => $staleAt ?? $event->staleAt,
            'staleReason' => $staleReason ?? $event->staleReason,
            'indeterminateAt' =>
                $indeterminateAt ?? $event->indeterminateAt,
            'failureClass' =>
                $failureClass ?? $event->failureClass,
        ];

        return new ScheduledEvent(...$args);
    }

    private function isClaimable(
        ScheduledEvent $event,
        DateTimeImmutable $now
    ): bool {
        if ($event->dueAt > $now) {
            return false;
        }

        if ($event->status === 'pending') {
            return true;
        }

        if (
            $event->status === 'claimed'
            && $event->leaseUntil instanceof DateTimeImmutable
            && $event->leaseUntil <= $now
        ) {
            return true;
        }

        return false;
    }

    private function assertActiveLease(
        ScheduledEvent $event,
        string $leaseToken,
        DateTimeImmutable $now
    ): void {
        $this->assertTokenOwnership(
            $event,
            $leaseToken
        );

        if (
            !$event->leaseUntil instanceof DateTimeImmutable
            || $event->leaseUntil <= $now
        ) {
            throw new RuntimeException(
                'Scheduled event lease has expired.'
            );
        }
    }

    private function assertTokenOwnership(
        ScheduledEvent $event,
        string $leaseToken
    ): void {
        if ($event->status !== 'claimed') {
            throw new RuntimeException(
                'Scheduled event is not claimed.'
            );
        }

        if (
            trim($leaseToken) === ''
            || !hash_equals(
                (string) $event->leaseToken,
                $leaseToken
            )
        ) {
            throw new RuntimeException(
                'Scheduled event lease token is stale.'
            );
        }
    }

    /**
     * @param array<mixed> $state
     */
    private function requireEvent(
        array $state,
        string $eventId
    ): ScheduledEvent {
        if (trim($eventId) === '') {
            throw new RuntimeException(
                'eventId cannot be empty.'
            );
        }

        $data =
            $state['events'][$eventId]
            ?? null;

        if (!is_array($data)) {
            throw new RuntimeException(
                'Scheduled event not found.'
            );
        }

        return ScheduledEvent::fromArray($data);
    }

    /**
     * @return array<mixed>
     */
    private function readShared(): array
    {
        $lock = $this->openLock();

        try {
            if (!flock($lock, LOCK_SH)) {
                throw new RuntimeException(
                    'Unable to acquire scheduled event read lock.'
                );
            }

            return $this->readUnlocked();
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<mixed>
     */
    private function mutate(callable $mutation): array
    {
        $lock = $this->openLock();

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(
                    'Unable to acquire scheduled event write lock.'
                );
            }

            $state = $this->readUnlocked();
            $next = $mutation($state);

            if (!is_array($next)) {
                throw new RuntimeException(
                    'Scheduled event mutation must return array.'
                );
            }

            $this->writeUnlocked($next);

            return $next;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<mixed>
     */
    private function readUnlocked(): array
    {
        if (!is_file($this->dataPath)) {
            return $this->emptyState();
        }

        $json =
            file_get_contents(
                $this->dataPath
            );

        if ($json === false || trim($json) === '') {
            throw new RuntimeException(
                'Scheduled event data is empty or unreadable.'
            );
        }

        try {
            $state = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Scheduled event data is corrupted.',
                0,
                $exception
            );
        }

        if (!is_array($state)) {
            throw new RuntimeException(
                'Scheduled event data must decode to array.'
            );
        }

        $schema =
            (int) ($state['schema_version'] ?? 0);

        if ($schema > self::SCHEMA_VERSION) {
            throw new RuntimeException(
                'Unsupported scheduled event schema version.'
            );
        }

        $state['schema_version'] =
            self::SCHEMA_VERSION;

        $state['events'] =
            is_array($state['events'] ?? null)
                ? $state['events']
                : [];

        $state['dedupe'] =
            is_array($state['dedupe'] ?? null)
                ? $state['dedupe']
                : [];

        return $state;
    }

    /**
     * @param array<mixed> $state
     */
    private function writeUnlocked(
        array $state
    ): void {
        try {
            $json = json_encode(
                $state,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Scheduled event state cannot be encoded.',
                0,
                $exception
            );
        }

        $temp =
            $this->dataPath
            . '.tmp.'
            . bin2hex(random_bytes(8));

        $handle = @fopen($temp, 'xb');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create scheduled event temp file.'
            );
        }

        try {
            $payload = $json . PHP_EOL;
            $offset = 0;
            $length = strlen($payload);

            while ($offset < $length) {
                $written =
                    fwrite(
                        $handle,
                        substr($payload, $offset)
                    );

                if (
                    $written === false
                    || $written === 0
                ) {
                    throw new RuntimeException(
                        'Unable to write scheduled event temp file.'
                    );
                }

                $offset += $written;
            }

            if (!fflush($handle)) {
                throw new RuntimeException(
                    'Unable to flush scheduled event temp file.'
                );
            }

            if (function_exists('fsync')) {
                @fsync($handle);
            }

            fclose($handle);
            $handle = null;

            if (!rename($temp, $this->dataPath)) {
                throw new RuntimeException(
                    'Unable to publish scheduled event state atomically.'
                );
            }
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($temp);

            throw $exception;
        }
    }

    /**
     * @return resource
     */
    private function openLock()
    {
        $handle =
            @fopen(
                $this->lockPath,
                'c+'
            );

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to open scheduled event lock.'
            );
        }

        return $handle;
    }

    /**
     * @return array<mixed>
     */
    private function emptyState(): array
    {
        return [
            'schema_version' =>
                self::SCHEMA_VERSION,
            'events' => [],
            'dedupe' => [],
        ];
    }

    private static function utcDate(
        DateTimeImmutable $value
    ): DateTimeImmutable {
        return $value->setTimezone(
            new DateTimeZone('UTC')
        );
    }
}
