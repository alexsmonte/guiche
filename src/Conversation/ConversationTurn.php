<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use InvalidArgumentException;

final readonly class ConversationTurn
{
    /**
     * @param array<mixed> $eventIds
     * @param array<mixed> $events
     */
    public function __construct(
        public string $turnId,
        public string $conversationKey,
        public string $status,
        public int $fromSequence,
        public int $toSequence,
        public int $sequenceSnapshot,
        public array $eventIds,
        public array $events,
        public string $createdAt,
        public ?string $processingStartedAt = null,
        public ?string $completedAt = null,
        public ?string $failedAt = null,
        public ?string $failureClass = null
    ) {
        if (trim($this->turnId) === '') {
            throw new InvalidArgumentException(
                'turnId cannot be empty.'
            );
        }

        if (trim($this->conversationKey) === '') {
            throw new InvalidArgumentException(
                'conversationKey cannot be empty.'
            );
        }

        if (
            !in_array(
                $this->status,
                ['pending', 'processing', 'completed', 'failed'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Conversation turn status is invalid.'
            );
        }

        if ($this->fromSequence < 1) {
            throw new InvalidArgumentException(
                'fromSequence must be >= 1.'
            );
        }

        if ($this->toSequence < $this->fromSequence) {
            throw new InvalidArgumentException(
                'toSequence cannot be lower than fromSequence.'
            );
        }

        if ($this->sequenceSnapshot < $this->toSequence) {
            throw new InvalidArgumentException(
                'sequenceSnapshot cannot be lower than toSequence.'
            );
        }
    }
}
