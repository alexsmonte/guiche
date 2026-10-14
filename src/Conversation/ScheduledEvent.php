<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class ScheduledEvent
{
    private const STATUSES = [
        'pending',
        'claimed',
        'completed',
        'cancelled',
        'stale',
        'indeterminate',
    ];

    public function __construct(
        public string $eventId,
        public string $conversationKey,
        public string $type,
        public array $payload,
        public DateTimeImmutable $dueAt,
        public string $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $dedupeHash = null,
        public ?string $leaseOwner = null,
        public ?string $leaseToken = null,
        public ?DateTimeImmutable $leaseUntil = null,
        public int $claimCount = 0,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $cancelledAt = null,
        public ?int $sequenceSnapshot = null,
        public array $requiredState = [],
        public ?DateTimeImmutable $staleAt = null,
        public ?string $staleReason = null,
        public ?DateTimeImmutable $indeterminateAt = null,
        public ?string $failureClass = null
    ) {
        if (trim($this->eventId) === '') {
            throw new InvalidArgumentException(
                'eventId cannot be empty.'
            );
        }

        if (trim($this->conversationKey) === '') {
            throw new InvalidArgumentException(
                'conversationKey cannot be empty.'
            );
        }

        if (trim($this->type) === '') {
            throw new InvalidArgumentException(
                'type cannot be empty.'
            );
        }

        if (!in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                'Scheduled event status is invalid.'
            );
        }

        if ($this->claimCount < 0) {
            throw new InvalidArgumentException(
                'claimCount cannot be negative.'
            );
        }

        if (
            $this->sequenceSnapshot !== null
            && $this->sequenceSnapshot < 0
        ) {
            throw new InvalidArgumentException(
                'sequenceSnapshot cannot be negative.'
            );
        }

        foreach ($this->requiredState as $path => $_expected) {
            if (
                !is_string($path)
                || trim($path) === ''
            ) {
                throw new InvalidArgumentException(
                    'requiredState paths must be non-empty strings.'
                );
            }
        }

        try {
            json_encode(
                [
                    'payload' => $this->payload,
                    'required_state' => $this->requiredState,
                ],
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Scheduled event payload/state requirements must be JSON serializable.',
                0,
                $exception
            );
        }

        if ($this->status === 'claimed') {
            if (
                trim((string) $this->leaseOwner) === ''
                || trim((string) $this->leaseToken) === ''
                || !$this->leaseUntil instanceof DateTimeImmutable
            ) {
                throw new InvalidArgumentException(
                    'Claimed event requires owner, token and leaseUntil.'
                );
            }
        }
    }

    public static function pending(
        string $conversationKey,
        string $type,
        array $payload,
        DateTimeImmutable $dueAt,
        DateTimeImmutable $now,
        ?string $dedupeHash = null,
        ?int $sequenceSnapshot = null,
        array $requiredState = []
    ): self {
        return new self(
            eventId: bin2hex(random_bytes(16)),
            conversationKey: $conversationKey,
            type: $type,
            payload: $payload,
            dueAt: self::utcDate($dueAt),
            status: 'pending',
            createdAt: self::utcDate($now),
            updatedAt: self::utcDate($now),
            dedupeHash: $dedupeHash,
            sequenceSnapshot: $sequenceSnapshot,
            requiredState: $requiredState
        );
    }

    public static function fromArray(array $data): self
    {
        try {
            return new self(
                eventId: (string) ($data['event_id'] ?? ''),
                conversationKey:
                    (string) ($data['conversation_key'] ?? ''),
                type: (string) ($data['type'] ?? ''),
                payload:
                    is_array($data['payload'] ?? null)
                        ? $data['payload']
                        : [],
                dueAt: new DateTimeImmutable(
                    (string) ($data['due_at'] ?? '')
                ),
                status: (string) ($data['status'] ?? ''),
                createdAt: new DateTimeImmutable(
                    (string) ($data['created_at'] ?? '')
                ),
                updatedAt: new DateTimeImmutable(
                    (string) ($data['updated_at'] ?? '')
                ),
                dedupeHash:
                    isset($data['dedupe_hash'])
                    && $data['dedupe_hash'] !== null
                        ? (string) $data['dedupe_hash']
                        : null,
                leaseOwner:
                    isset($data['lease_owner'])
                    && $data['lease_owner'] !== null
                        ? (string) $data['lease_owner']
                        : null,
                leaseToken:
                    isset($data['lease_token'])
                    && $data['lease_token'] !== null
                        ? (string) $data['lease_token']
                        : null,
                leaseUntil:
                    isset($data['lease_until'])
                    && $data['lease_until'] !== null
                        ? new DateTimeImmutable(
                            (string) $data['lease_until']
                        )
                        : null,
                claimCount:
                    (int) ($data['claim_count'] ?? 0),
                completedAt:
                    isset($data['completed_at'])
                    && $data['completed_at'] !== null
                        ? new DateTimeImmutable(
                            (string) $data['completed_at']
                        )
                        : null,
                cancelledAt:
                    isset($data['cancelled_at'])
                    && $data['cancelled_at'] !== null
                        ? new DateTimeImmutable(
                            (string) $data['cancelled_at']
                        )
                        : null,
                sequenceSnapshot:
                    array_key_exists('sequence_snapshot', $data)
                    && $data['sequence_snapshot'] !== null
                        ? (int) $data['sequence_snapshot']
                        : null,
                requiredState:
                    is_array($data['required_state'] ?? null)
                        ? $data['required_state']
                        : [],
                staleAt:
                    isset($data['stale_at'])
                    && $data['stale_at'] !== null
                        ? new DateTimeImmutable(
                            (string) $data['stale_at']
                        )
                        : null,
                staleReason:
                    isset($data['stale_reason'])
                    && $data['stale_reason'] !== null
                        ? (string) $data['stale_reason']
                        : null,
                indeterminateAt:
                    isset($data['indeterminate_at'])
                    && $data['indeterminate_at'] !== null
                        ? new DateTimeImmutable(
                            (string) $data['indeterminate_at']
                        )
                        : null,
                failureClass:
                    isset($data['failure_class'])
                    && $data['failure_class'] !== null
                        ? (string) $data['failure_class']
                        : null
            );
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }

            throw new RuntimeException(
                'Scheduled event data is invalid.',
                0,
                $exception
            );
        }
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'conversation_key' => $this->conversationKey,
            'type' => $this->type,
            'payload' => $this->payload,
            'due_at' => self::utcString($this->dueAt),
            'status' => $this->status,
            'created_at' => self::utcString($this->createdAt),
            'updated_at' => self::utcString($this->updatedAt),
            'dedupe_hash' => $this->dedupeHash,
            'lease_owner' => $this->leaseOwner,
            'lease_token' => $this->leaseToken,
            'lease_until' =>
                $this->leaseUntil === null
                    ? null
                    : self::utcString($this->leaseUntil),
            'claim_count' => $this->claimCount,
            'completed_at' =>
                $this->completedAt === null
                    ? null
                    : self::utcString($this->completedAt),
            'cancelled_at' =>
                $this->cancelledAt === null
                    ? null
                    : self::utcString($this->cancelledAt),
            'sequence_snapshot' => $this->sequenceSnapshot,
            'required_state' => $this->requiredState,
            'stale_at' =>
                $this->staleAt === null
                    ? null
                    : self::utcString($this->staleAt),
            'stale_reason' => $this->staleReason,
            'indeterminate_at' =>
                $this->indeterminateAt === null
                    ? null
                    : self::utcString($this->indeterminateAt),
            'failure_class' => $this->failureClass,
        ];
    }

    private static function utcDate(
        DateTimeImmutable $value
    ): DateTimeImmutable {
        return $value->setTimezone(
            new DateTimeZone('UTC')
        );
    }

    private static function utcString(
        DateTimeImmutable $value
    ): string {
        return self::utcDate($value)->format(DATE_ATOM);
    }
}
