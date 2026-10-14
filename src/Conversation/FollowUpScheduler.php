<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ConversationStoreInterface;
use RuntimeException;

final class FollowUpScheduler
{
    public const TYPE = 'follow_up';

    public const DEFAULT_DELAY_SECONDS = 300;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ConversationStoreInterface $conversationStore,
        private readonly ClockInterface $clock,
        private readonly int $defaultDelaySeconds =
            self::DEFAULT_DELAY_SECONDS
    ) {
        if ($this->defaultDelaySeconds <= 0) {
            throw new RuntimeException(
                'defaultDelaySeconds must be positive.'
            );
        }
    }

    public function schedule(
        string $conversationKey,
        array $payload = [],
        array $requiredState = [],
        ?int $delaySeconds = null,
        ?string $dedupeKey = null
    ): ScheduledEvent {
        $delay =
            $delaySeconds
            ?? $this->defaultDelaySeconds;

        if ($delay <= 0) {
            throw new RuntimeException(
                'Follow-up delay must be positive.'
            );
        }

        $aggregate =
            $this->conversationStore->load(
                $conversationKey
            );

        $sequence =
            (int) ($aggregate['sequence'] ?? 0);

        $dedupeKey ??=
            'follow-up|'
            . $conversationKey
            . '|'
            . $sequence;

        return $this->scheduler->schedule(
            conversationKey: $conversationKey,
            type: self::TYPE,
            payload: $payload,
            dueAt:
                $this->clock
                    ->now()
                    ->modify("+{$delay} seconds"),
            dedupeKey: $dedupeKey,
            sequenceSnapshot: $sequence,
            requiredState: $requiredState
        );
    }
}
