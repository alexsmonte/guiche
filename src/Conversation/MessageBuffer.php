<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ConversationStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class MessageBuffer
{
    public function __construct(
        private readonly ConversationStoreInterface $store,
        private readonly ClockInterface $clock
    ) {
    }

    public function openTurn(
        string $conversationKey
    ): ?ConversationTurn {
        return $this->openInternal(
            $conversationKey,
            null,
            null
        );
    }

    public function openReadyTurn(
        string $conversationKey,
        int $quietWindowSeconds,
        int $maxTurnWindowSeconds
    ): ?ConversationTurn {
        if ($quietWindowSeconds < 0) {
            throw new RuntimeException(
                'quietWindowSeconds cannot be negative.'
            );
        }

        if ($maxTurnWindowSeconds <= 0) {
            throw new RuntimeException(
                'maxTurnWindowSeconds must be positive.'
            );
        }

        if ($maxTurnWindowSeconds < $quietWindowSeconds) {
            throw new RuntimeException(
                'maxTurnWindowSeconds cannot be lower than quietWindowSeconds.'
            );
        }

        return $this->openInternal(
            $conversationKey,
            $quietWindowSeconds,
            $maxTurnWindowSeconds
        );
    }

    public function tryStartProcessing(
        string $conversationKey,
        string $turnId
    ): ?ConversationTurn {
        if (trim($turnId) === '') {
            throw new RuntimeException(
                'turnId cannot be empty.'
            );
        }

        $turn = null;
        $started = false;

        $this->store->mutate(
            $conversationKey,
            function (array $aggregate) use (
                $conversationKey,
                $turnId,
                &$turn,
                &$started
            ): array {
                $aggregate = $this->normalize(
                    $conversationKey,
                    $aggregate
                );

                $storedTurn =
                    $aggregate['turns'][$turnId]
                    ?? null;

                if (!is_array($storedTurn)) {
                    throw new RuntimeException(
                        'Conversation turn not found.'
                    );
                }

                $status =
                    (string) ($storedTurn['status'] ?? '');

                if ($status === 'processing') {
                    $turn = $this->hydrateTurn(
                        $aggregate,
                        $storedTurn
                    );

                    return $aggregate;
                }

                if ($status !== 'pending') {
                    throw new RuntimeException(
                        'Only pending turn can start processing.'
                    );
                }

                $storedTurn['status'] = 'processing';
                $storedTurn['processing_started_at'] =
                    self::utc($this->clock->now());

                $aggregate['turns'][$turnId] =
                    $storedTurn;

                $aggregate['active_turn_id'] =
                    $turnId;

                $started = true;

                $turn = $this->hydrateTurn(
                    $aggregate,
                    $storedTurn
                );

                return $aggregate;
            }
        );

        if (!$started) {
            return null;
        }

        if (!$turn instanceof ConversationTurn) {
            throw new RuntimeException(
                'Conversation turn processing transition failed.'
            );
        }

        return $turn;
    }

    public function completeTurn(
        string $conversationKey,
        string $turnId
    ): ConversationTurn {
        if (trim($turnId) === '') {
            throw new RuntimeException(
                'turnId cannot be empty.'
            );
        }

        $turn = null;

        $this->store->mutate(
            $conversationKey,
            function (array $aggregate) use (
                $conversationKey,
                $turnId,
                &$turn
            ): array {
                $aggregate = $this->normalize(
                    $conversationKey,
                    $aggregate
                );

                $storedTurn =
                    $aggregate['turns'][$turnId]
                    ?? null;

                if (!is_array($storedTurn)) {
                    throw new RuntimeException(
                        'Conversation turn not found.'
                    );
                }

                $status =
                    (string) ($storedTurn['status'] ?? '');

                if ($status === 'completed') {
                    $turn = $this->hydrateTurn(
                        $aggregate,
                        $storedTurn
                    );

                    return $aggregate;
                }

                if (
                    !in_array(
                        $status,
                        ['pending', 'processing'],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        'Conversation turn cannot be completed from current status.'
                    );
                }

                $completedAt =
                    self::utc($this->clock->now());

                $storedTurn['status'] = 'completed';
                $storedTurn['completed_at'] = $completedAt;

                $aggregate['turns'][$turnId] =
                    $storedTurn;

                foreach ($aggregate['events'] as $index => $event) {
                    if (
                        is_array($event)
                        && ($event['turn_id'] ?? null) === $turnId
                    ) {
                        $aggregate['events'][$index]['consumed_at'] =
                            $completedAt;
                    }
                }

                if (
                    ($aggregate['active_turn_id'] ?? null)
                    === $turnId
                ) {
                    $aggregate['active_turn_id'] = null;
                }

                $turn = $this->hydrateTurn(
                    $aggregate,
                    $storedTurn
                );

                return $aggregate;
            }
        );

        if (!$turn instanceof ConversationTurn) {
            throw new RuntimeException(
                'Conversation turn completion failed.'
            );
        }

        return $turn;
    }

    public function failTurn(
        string $conversationKey,
        string $turnId,
        string $failureClass
    ): ConversationTurn {
        if (trim($failureClass) === '') {
            throw new RuntimeException(
                'failureClass cannot be empty.'
            );
        }

        $turn = null;

        $this->store->mutate(
            $conversationKey,
            function (array $aggregate) use (
                $conversationKey,
                $turnId,
                $failureClass,
                &$turn
            ): array {
                $aggregate = $this->normalize(
                    $conversationKey,
                    $aggregate
                );

                $storedTurn =
                    $aggregate['turns'][$turnId]
                    ?? null;

                if (!is_array($storedTurn)) {
                    throw new RuntimeException(
                        'Conversation turn not found.'
                    );
                }

                $status =
                    (string) ($storedTurn['status'] ?? '');

                if ($status === 'failed') {
                    $turn = $this->hydrateTurn(
                        $aggregate,
                        $storedTurn
                    );

                    return $aggregate;
                }

                if ($status !== 'processing') {
                    throw new RuntimeException(
                        'Only processing turn can fail.'
                    );
                }

                $storedTurn['status'] = 'failed';
                $storedTurn['failed_at'] =
                    self::utc($this->clock->now());
                $storedTurn['failure_class'] =
                    $failureClass;

                $aggregate['turns'][$turnId] =
                    $storedTurn;

                if (
                    ($aggregate['active_turn_id'] ?? null)
                    === $turnId
                ) {
                    $aggregate['active_turn_id'] = null;
                }

                $turn = $this->hydrateTurn(
                    $aggregate,
                    $storedTurn
                );

                return $aggregate;
            }
        );

        if (!$turn instanceof ConversationTurn) {
            throw new RuntimeException(
                'Conversation turn failure transition failed.'
            );
        }

        return $turn;
    }

    private function openInternal(
        string $conversationKey,
        ?int $quietWindowSeconds,
        ?int $maxTurnWindowSeconds
    ): ?ConversationTurn {
        $turn = null;

        $this->store->mutate(
            $conversationKey,
            function (array $aggregate) use (
                $conversationKey,
                $quietWindowSeconds,
                $maxTurnWindowSeconds,
                &$turn
            ): array {
                $aggregate = $this->normalize(
                    $conversationKey,
                    $aggregate
                );

                $activeTurnId =
                    $aggregate['active_turn_id'];

                if (
                    is_string($activeTurnId)
                    && $activeTurnId !== ''
                ) {
                    $storedTurn =
                        $aggregate['turns'][$activeTurnId]
                        ?? null;

                    if (is_array($storedTurn)) {
                        $status =
                            (string) (
                                $storedTurn['status']
                                ?? ''
                            );

                        if (
                            in_array(
                                $status,
                                ['pending', 'processing'],
                                true
                            )
                        ) {
                            $turn = $this->hydrateTurn(
                                $aggregate,
                                $storedTurn
                            );

                            return $aggregate;
                        }
                    }

                    $aggregate['active_turn_id'] = null;
                }

                $pendingIndexes = [];

                foreach ($aggregate['events'] as $index => $event) {
                    if (!is_array($event)) {
                        continue;
                    }

                    $assigned =
                        $event['turn_id'] ?? null;

                    if ($assigned === null || $assigned === '') {
                        $pendingIndexes[] = $index;
                    }
                }

                if ($pendingIndexes === []) {
                    $turn = null;

                    return $aggregate;
                }

                if (
                    $quietWindowSeconds !== null
                    && $maxTurnWindowSeconds !== null
                    && !$this->isReady(
                        $aggregate,
                        $pendingIndexes,
                        $quietWindowSeconds,
                        $maxTurnWindowSeconds
                    )
                ) {
                    $turn = null;

                    return $aggregate;
                }

                $sequenceSnapshot =
                    (int) $aggregate['sequence'];

                $selectedIndexes = [];

                foreach ($pendingIndexes as $index) {
                    $eventSequence =
                        (int) (
                            $aggregate['events'][$index]['sequence']
                            ?? 0
                        );

                    if (
                        $eventSequence >= 1
                        && $eventSequence <= $sequenceSnapshot
                    ) {
                        $selectedIndexes[] = $index;
                    }
                }

                if ($selectedIndexes === []) {
                    $turn = null;

                    return $aggregate;
                }

                $turnId = bin2hex(random_bytes(16));
                $eventIds = [];
                $sequences = [];

                foreach ($selectedIndexes as $index) {
                    $aggregate['events'][$index]['turn_id'] =
                        $turnId;

                    $eventIds[] =
                        (string) (
                            $aggregate['events'][$index]['event_id']
                            ?? ''
                        );

                    $sequences[] =
                        (int) (
                            $aggregate['events'][$index]['sequence']
                            ?? 0
                        );
                }

                $storedTurn = [
                    'turn_id' => $turnId,
                    'status' => 'pending',
                    'from_sequence' => min($sequences),
                    'to_sequence' => max($sequences),
                    'sequence_snapshot' => $sequenceSnapshot,
                    'event_ids' => $eventIds,
                    'created_at' =>
                        self::utc($this->clock->now()),
                    'processing_started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'failure_class' => null,
                ];

                $aggregate['turns'][$turnId] =
                    $storedTurn;

                $aggregate['active_turn_id'] =
                    $turnId;

                $turn = $this->hydrateTurn(
                    $aggregate,
                    $storedTurn
                );

                return $aggregate;
            }
        );

        return $turn;
    }

    private function isReady(
        array $aggregate,
        array $pendingIndexes,
        int $quietWindowSeconds,
        int $maxTurnWindowSeconds
    ): bool {
        $timestamps = [];

        foreach ($pendingIndexes as $index) {
            $receivedAt =
                $aggregate['events'][$index]['received_at']
                ?? null;

            if (!is_string($receivedAt) || trim($receivedAt) === '') {
                throw new RuntimeException(
                    'Pending event does not have received_at.'
                );
            }

            try {
                $timestamps[] =
                    (new DateTimeImmutable($receivedAt))
                        ->getTimestamp();
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Pending event has invalid received_at.',
                    0,
                    $exception
                );
            }
        }

        if ($timestamps === []) {
            return false;
        }

        $now =
            $this->clock->now()->getTimestamp();

        $first = min($timestamps);
        $last = max($timestamps);

        $quietReady =
            ($now - $last) >= $quietWindowSeconds;

        $maxReady =
            ($now - $first) >= $maxTurnWindowSeconds;

        return $quietReady || $maxReady;
    }

    private function normalize(
        string $conversationKey,
        array $aggregate
    ): array {
        if (
            isset($aggregate['conversation_key'])
            && $aggregate['conversation_key']
                !== $conversationKey
        ) {
            throw new RuntimeException(
                'Conversation key mismatch in buffer.'
            );
        }

        $aggregate['conversation_key'] =
            $aggregate['conversation_key']
            ?? $conversationKey;

        $aggregate['sequence'] =
            (int) ($aggregate['sequence'] ?? 0);

        $aggregate['events'] =
            is_array($aggregate['events'] ?? null)
                ? $aggregate['events']
                : [];

        $aggregate['turns'] =
            is_array($aggregate['turns'] ?? null)
                ? $aggregate['turns']
                : [];

        $aggregate['active_turn_id'] =
            $aggregate['active_turn_id']
            ?? null;

        return $aggregate;
    }

    private function hydrateTurn(
        array $aggregate,
        array $storedTurn
    ): ConversationTurn {
        $eventIds =
            is_array($storedTurn['event_ids'] ?? null)
                ? array_values($storedTurn['event_ids'])
                : [];

        $eventsById = [];

        foreach ($aggregate['events'] as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventId = $event['event_id'] ?? null;

            if (is_string($eventId) && $eventId !== '') {
                $eventsById[$eventId] = $event;
            }
        }

        $events = [];

        foreach ($eventIds as $eventId) {
            if (
                is_string($eventId)
                && isset($eventsById[$eventId])
            ) {
                $events[] = $eventsById[$eventId];
            }
        }

        return new ConversationTurn(
            turnId: (string) $storedTurn['turn_id'],
            conversationKey:
                (string) $aggregate['conversation_key'],
            status: (string) $storedTurn['status'],
            fromSequence:
                (int) $storedTurn['from_sequence'],
            toSequence:
                (int) $storedTurn['to_sequence'],
            sequenceSnapshot:
                (int) $storedTurn['sequence_snapshot'],
            eventIds: $eventIds,
            events: $events,
            createdAt:
                (string) $storedTurn['created_at'],
            processingStartedAt:
                isset($storedTurn['processing_started_at'])
                && $storedTurn['processing_started_at'] !== null
                    ? (string) $storedTurn['processing_started_at']
                    : null,
            completedAt:
                isset($storedTurn['completed_at'])
                && $storedTurn['completed_at'] !== null
                    ? (string) $storedTurn['completed_at']
                    : null,
            failedAt:
                isset($storedTurn['failed_at'])
                && $storedTurn['failed_at'] !== null
                    ? (string) $storedTurn['failed_at']
                    : null,
            failureClass:
                isset($storedTurn['failure_class'])
                && $storedTurn['failure_class'] !== null
                    ? (string) $storedTurn['failure_class']
                    : null
        );
    }

    private static function utc(
        DateTimeImmutable $value
    ): string {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }
}
