<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ConversationStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class IncomingEventRecorder
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly ConversationStoreInterface $store,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function record(
        string $conversationKey,
        IncomingEvent $event
    ): array {
        if (trim($conversationKey) === '') {
            throw new RuntimeException(
                'conversationKey cannot be empty.'
            );
        }

        $receipt = null;

        $this->store->mutate(
            $conversationKey,
            function (array $aggregate) use (
                $conversationKey,
                $event,
                &$receipt
            ): array {
                $now = $this->clock->now();

                $aggregate = $this->normalizeAggregate(
                    $conversationKey,
                    $aggregate,
                    $now
                );

                $knownId =
                    $aggregate['external_event_ids']
                        [$event->externalEventId]
                    ?? null;

                if (is_string($knownId) && $knownId !== '') {
                    $receipt = [
                        'accepted' => false,
                        'duplicate' => true,
                        'sequence' => $aggregate['sequence'],
                        'event_id' => $knownId,
                    ];

                    return $aggregate;
                }

                $sequence = $aggregate['sequence'] + 1;

                $stored = $event->toStoredArray(
                    $sequence,
                    $now
                );

                $aggregate['sequence'] = $sequence;
                $aggregate['events'][] = $stored;
                $aggregate['external_event_ids']
                    [$event->externalEventId] =
                        $stored['event_id'];

                $aggregate['updated_at'] =
                    self::utc($now);

                $receipt = [
                    'accepted' => true,
                    'duplicate' => false,
                    'sequence' => $sequence,
                    'event_id' => $stored['event_id'],
                ];

                return $aggregate;
            }
        );

        if (!is_array($receipt)) {
            throw new RuntimeException(
                'Incoming event receipt was not produced.'
            );
        }

        return $receipt;
    }

    /**
     * @param array<mixed> $aggregate
     * @return array<mixed>
     */
    private function normalizeAggregate(
        string $conversationKey,
        array $aggregate,
        DateTimeImmutable $now
    ): array {
        $schema = $aggregate['schema_version'] ?? null;

        if ($schema !== null && (int) $schema > self::SCHEMA_VERSION) {
            throw new RuntimeException(
                'Unsupported conversation schema version.'
            );
        }

        $aggregate['schema_version'] =
            self::SCHEMA_VERSION;

        $aggregate['conversation_key'] =
            $aggregate['conversation_key']
            ?? $conversationKey;

        if (
            $aggregate['conversation_key']
            !== $conversationKey
        ) {
            throw new RuntimeException(
                'Conversation key mismatch in persisted aggregate.'
            );
        }

        $aggregate['sequence'] =
            (int) ($aggregate['sequence'] ?? 0);

        $aggregate['events'] =
            is_array($aggregate['events'] ?? null)
                ? $aggregate['events']
                : [];

        $aggregate['external_event_ids'] =
            is_array(
                $aggregate['external_event_ids'] ?? null
            )
                ? $aggregate['external_event_ids']
                : [];

        $aggregate['created_at'] =
            $aggregate['created_at']
            ?? self::utc($now);

        $aggregate['updated_at'] =
            $aggregate['updated_at']
            ?? self::utc($now);

        return $aggregate;
    }

    private static function utc(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }
}
