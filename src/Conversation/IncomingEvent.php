<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

final readonly class IncomingEvent
{
    /**
     * @throws JsonException
     * @param array<mixed> $payload
     */
    public function __construct(
        public string $externalEventId,
        public string $type,
        public array $payload,
        public ?DateTimeImmutable $occurredAt = null
    ) {
        if (trim($this->externalEventId) === '') {
            throw new InvalidArgumentException(
                'externalEventId cannot be empty.'
            );
        }

        if (trim($this->type) === '') {
            throw new InvalidArgumentException(
                'type cannot be empty.'
            );
        }

        json_encode(
            $this->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @return array<mixed>
     */
    public function toStoredArray(
        int $sequence,
        DateTimeImmutable $receivedAt
    ): array {
        return [
            'event_id' => bin2hex(random_bytes(16)),
            'external_event_id' => $this->externalEventId,
            'type' => $this->type,
            'sequence' => $sequence,
            'occurred_at' => $this->occurredAt === null
                ? null
                : self::utc($this->occurredAt),
            'received_at' => self::utc($receivedAt),
            'payload' => $this->payload,
        ];
    }

    private static function utc(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }
}
