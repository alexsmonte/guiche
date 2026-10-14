<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\ConversationTurn;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Conversation\MessageBuffer;
use Asmpkg\Guiche\Tests\Fixtures\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MessageBufferTest extends TestCase
{
    private string $directory;
    private JsonConversationStore $store;
    private FrozenClock $clock;
    private IncomingEventRecorder $recorder;
    private MessageBuffer $buffer;
    private string $key;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v06-buffer-'
            . bin2hex(random_bytes(8));

        $this->store = new JsonConversationStore(
            $this->directory
        );

        $this->clock = new FrozenClock(
            new DateTimeImmutable(
                '2026-10-14T03:00:00Z'
            )
        );

        $this->recorder = new IncomingEventRecorder(
            $this->store,
            $this->clock
        );

        $this->buffer = new MessageBuffer(
            $this->store,
            $this->clock
        );

        $this->key = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'buffer'
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testOpenTurnCreatesImmutableSnapshotAndIsIdempotent(): void
    {
        foreach (['one', 'two', 'three'] as $index => $text) {
            $this->recorder->record(
                $this->key,
                new IncomingEvent(
                    'event-' . ($index + 1),
                    'message',
                    ['text' => $text]
                )
            );

            $this->clock->advanceSeconds(5);
        }

        $turn = $this->buffer->openTurn(
            $this->key
        );

        self::assertInstanceOf(
            ConversationTurn::class,
            $turn
        );
        self::assertSame('pending', $turn->status);
        self::assertSame(1, $turn->fromSequence);
        self::assertSame(3, $turn->toSequence);
        self::assertSame(3, $turn->sequenceSnapshot);
        self::assertCount(3, $turn->events);

        $same = $this->buffer->openTurn(
            $this->key
        );

        self::assertSame(
            $turn->turnId,
            $same?->turnId
        );

        $this->recorder->record(
            $this->key,
            new IncomingEvent(
                'event-4',
                'message',
                ['text' => 'four']
            )
        );

        $stillSame = $this->buffer->openTurn(
            $this->key
        );

        self::assertSame(
            $turn->turnId,
            $stillSame?->turnId
        );
        self::assertSame(
            3,
            $stillSame?->sequenceSnapshot
        );
        self::assertCount(
            3,
            $stillSame?->events ?? []
        );
    }

    public function testCompleteConsumesOnlySnapshotAndNextEventOpensNextTurn(): void
    {
        foreach (['one', 'two', 'three'] as $index => $text) {
            $this->recorder->record(
                $this->key,
                new IncomingEvent(
                    'event-' . ($index + 1),
                    'message',
                    ['text' => $text]
                )
            );
        }

        $turn1 = $this->buffer->openTurn(
            $this->key
        );

        self::assertNotNull($turn1);

        $this->recorder->record(
            $this->key,
            new IncomingEvent(
                'event-4',
                'message',
                ['text' => 'four']
            )
        );

        $completed = $this->buffer->completeTurn(
            $this->key,
            $turn1->turnId
        );

        self::assertSame(
            'completed',
            $completed->status
        );

        $state = $this->store->load(
            $this->key
        );

        $consumed = 0;
        $pendingFourth = false;

        foreach ($state['events'] as $event) {
            if (($event['sequence'] ?? 0) <= 3) {
                $consumed += isset($event['consumed_at'])
                    ? 1
                    : 0;
            }

            if (($event['sequence'] ?? 0) === 4) {
                $pendingFourth = !isset(
                    $event['consumed_at']
                );
            }
        }

        self::assertSame(3, $consumed);
        self::assertTrue($pendingFourth);

        $turn2 = $this->buffer->openTurn(
            $this->key
        );

        self::assertNotNull($turn2);
        self::assertNotSame(
            $turn1->turnId,
            $turn2->turnId
        );
        self::assertSame(4, $turn2->fromSequence);
        self::assertSame(4, $turn2->toSequence);
    }

    public function testQuietWindowAndMaxWindowAreDeterministic(): void
    {
        $this->recorder->record(
            $this->key,
            new IncomingEvent(
                'quiet-1',
                'message',
                ['text' => 'hello']
            )
        );

        self::assertNull(
            $this->buffer->openReadyTurn(
                $this->key,
                40,
                120
            )
        );

        $this->clock->advanceSeconds(39);

        self::assertNull(
            $this->buffer->openReadyTurn(
                $this->key,
                40,
                120
            )
        );

        $this->clock->advanceSeconds(1);

        self::assertNotNull(
            $this->buffer->openReadyTurn(
                $this->key,
                40,
                120
            )
        );
    }

    public function testTryStartProcessingOnlyStartsOnce(): void
    {
        $this->recorder->record(
            $this->key,
            new IncomingEvent(
                'event-1',
                'message',
                ['text' => 'hello']
            )
        );

        $turn = $this->buffer->openTurn(
            $this->key
        );

        self::assertNotNull($turn);

        $first = $this->buffer->tryStartProcessing(
            $this->key,
            $turn->turnId
        );

        self::assertSame(
            'processing',
            $first?->status
        );

        self::assertNull(
            $this->buffer->tryStartProcessing(
                $this->key,
                $turn->turnId
            )
        );
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
