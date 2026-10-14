<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\JsonScheduledEventStore;
use Asmpkg\Guiche\Conversation\Scheduler;
use Asmpkg\Guiche\Tests\Fixtures\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchedulerTest extends TestCase
{
    private string $directory;
    private FrozenClock $clock;
    private Scheduler $scheduler;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v06-scheduler-'
            . bin2hex(random_bytes(8));

        $this->clock = new FrozenClock(
            new DateTimeImmutable(
                '2026-10-14T12:00:00Z'
            )
        );

        $this->scheduler = new Scheduler(
            new JsonScheduledEventStore(
                $this->directory
            ),
            $this->clock
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testScheduleDedupesAndDoesNotExposeRawDedupeKey(): void
    {
        $key = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'scheduler'
        );

        $event = $this->scheduler->schedule(
            $key,
            'neutral.event',
            ['kind' => 'test'],
            $this->clock->now()->modify('+60 seconds'),
            'logical-event-1'
        );

        self::assertSame('pending', $event->status);
        self::assertSame(
            hash('sha256', 'logical-event-1'),
            $event->dedupeHash
        );

        $same = $this->scheduler->schedule(
            $key,
            'changed.event',
            ['changed' => true],
            $this->clock->now()->modify('+600 seconds'),
            'logical-event-1'
        );

        self::assertSame(
            $event->eventId,
            $same->eventId
        );
        self::assertSame(
            'neutral.event',
            $same->type
        );

        $raw = file_get_contents(
            $this->directory
            . '/scheduled-events.json'
        );

        self::assertIsString($raw);
        self::assertStringNotContainsString(
            'logical-event-1',
            $raw
        );
    }

    public function testClaimLeaseReclaimFencingAndComplete(): void
    {
        $key = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'lease'
        );

        $event = $this->scheduler->schedule(
            $key,
            'lease.event',
            [],
            $this->clock->now(),
            'lease-event'
        );

        $claimed = $this->scheduler->claimDue(
            'worker-a',
            30,
            10
        );

        self::assertCount(1, $claimed);

        $claimA = $claimed[0];

        self::assertSame('claimed', $claimA->status);
        self::assertSame('worker-a', $claimA->leaseOwner);
        self::assertSame(32, strlen((string) $claimA->leaseToken));
        self::assertSame(1, $claimA->claimCount);

        self::assertSame(
            [],
            $this->scheduler->claimDue(
                'worker-b',
                30,
                10
            )
        );

        $this->clock->advanceSeconds(31);

        $reclaimed = $this->scheduler->claimDue(
            'worker-b',
            30,
            10
        );

        self::assertCount(1, $reclaimed);

        $claimB = $reclaimed[0];

        self::assertSame(
            $event->eventId,
            $claimB->eventId
        );
        self::assertSame('worker-b', $claimB->leaseOwner);
        self::assertNotSame(
            $claimA->leaseToken,
            $claimB->leaseToken
        );
        self::assertSame(2, $claimB->claimCount);

        try {
            (new JsonScheduledEventStore(
                $this->directory
            ))->complete(
                $claimA->eventId,
                (string) $claimA->leaseToken,
                $this->clock->now()
            );

            self::fail('Stale fencing token must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'stale',
                $exception->getMessage()
            );
        }

        $completed = $this->scheduler->complete(
            $claimB
        );

        self::assertSame(
            'completed',
            $completed->status
        );
        self::assertNull(
            $completed->leaseToken
        );
    }

    public function testRestartPreservesLeaseAndCancelInvalidatesIt(): void
    {
        $key = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'restart'
        );

        $event = $this->scheduler->schedule(
            $key,
            'restart.event',
            [],
            $this->clock->now(),
            'restart-event'
        );

        $claimed = $this->scheduler->claimDue(
            'worker-a',
            60,
            1
        )[0];

        $restarted = new Scheduler(
            new JsonScheduledEventStore(
                $this->directory
            ),
            $this->clock
        );

        $persisted = $restarted->find(
            $event->eventId
        );

        self::assertNotNull($persisted);
        self::assertSame(
            $claimed->leaseToken,
            $persisted->leaseToken
        );

        $cancelled = $restarted->cancel(
            $event->eventId
        );

        self::assertSame(
            'cancelled',
            $cancelled->status
        );
        self::assertNull(
            $cancelled->leaseToken
        );

        $this->expectException(RuntimeException::class);

        $restarted->complete($claimed);
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
