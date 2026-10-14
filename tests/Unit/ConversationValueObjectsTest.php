<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConversationValueObjectsTest extends TestCase
{
    public function testConversationKeyIsOpaqueAndSensitiveToEveryPart(): void
    {
        $base = ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'conversation-42'
        );

        self::assertSame(64, strlen($base));

        foreach ([
            'provider-a',
            'tenant-1',
            'inbox-1',
            'conversation-42',
        ] as $raw) {
            self::assertStringNotContainsString($raw, $base);
        }

        self::assertCount(
            5,
            array_unique([
                $base,
                ConversationKey::fromParts(
                    'provider-b',
                    'tenant-1',
                    'inbox-1',
                    'conversation-42'
                ),
                ConversationKey::fromParts(
                    'provider-a',
                    'tenant-2',
                    'inbox-1',
                    'conversation-42'
                ),
                ConversationKey::fromParts(
                    'provider-a',
                    'tenant-1',
                    'inbox-2',
                    'conversation-42'
                ),
                ConversationKey::fromParts(
                    'provider-a',
                    'tenant-1',
                    'inbox-1',
                    'conversation-99'
                ),
            ])
        );
    }

    public function testConversationKeyRejectsBlankPart(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConversationKey::fromParts(
            'provider',
            'tenant',
            ' ',
            'conversation'
        );
    }

    public function testIncomingEventStoresSequenceAndUtcTimestamps(): void
    {
        $event = new IncomingEvent(
            'external-1',
            'message',
            ['text' => 'Oi'],
            new DateTimeImmutable(
                '2026-10-14T00:00:00-04:00'
            )
        );

        $stored = $event->toStoredArray(
            7,
            new DateTimeImmutable(
                '2026-10-14T00:00:10-04:00'
            )
        );

        self::assertSame('external-1', $stored['external_event_id']);
        self::assertSame('message', $stored['type']);
        self::assertSame(7, $stored['sequence']);
        self::assertSame(
            '2026-10-14T04:00:00+00:00',
            $stored['occurred_at']
        );
        self::assertSame(
            '2026-10-14T04:00:10+00:00',
            $stored['received_at']
        );
        self::assertSame(['text' => 'Oi'], $stored['payload']);
        self::assertSame(32, strlen($stored['event_id']));
    }

    public function testIncomingEventRejectsBlankIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IncomingEvent('', 'message', []);
    }
}
