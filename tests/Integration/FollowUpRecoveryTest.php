<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\FollowUpScheduler;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Conversation\JsonScheduledEventStore;
use Asmpkg\Guiche\Conversation\ScheduledEventRunner;
use Asmpkg\Guiche\Conversation\Scheduler;
use Asmpkg\Guiche\Tests\Fixtures\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FollowUpRecoveryTest extends TestCase
{
    private string $root;
    private FrozenClock $clock;
    private JsonConversationStore $conversationStore;
    private IncomingEventRecorder $incoming;
    private Scheduler $scheduler;
    private ContextStore $contextStore;
    private FollowUpScheduler $followUps;
    private ScheduledEventRunner $runner;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/guiche-v06-followup-'
            . bin2hex(random_bytes(8));

        $this->clock = new FrozenClock(
            new DateTimeImmutable(
                '2026-10-14T12:00:00Z'
            )
        );

        $this->conversationStore = new JsonConversationStore(
            $this->root . '/conversations'
        );

        $this->incoming = new IncomingEventRecorder(
            $this->conversationStore,
            $this->clock
        );

        $this->scheduler = new Scheduler(
            new JsonScheduledEventStore(
                $this->root . '/scheduler'
            ),
            $this->clock
        );

        $this->contextStore = new ContextStore(
            $this->root . '/contexts',
            'UTC'
        );

        $this->followUps = new FollowUpScheduler(
            $this->scheduler,
            $this->conversationStore,
            $this->clock
        );

        $this->runner = new ScheduledEventRunner(
            $this->scheduler,
            $this->conversationStore,
            $this->contextStore
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testDefaultFollowUpCapturesSnapshotAndCompletesAfter300Seconds(): void
    {
        $key = $this->key('complete');

        $this->incoming->record(
            $key,
            new IncomingEvent(
                'event-1',
                'message',
                ['text' => 'hello']
            )
        );

        $this->contextStore->mergeState(
            $key,
            ['stage' => 'waiting']
        );

        $event = $this->followUps->schedule(
            $key,
            ['template' => 'neutral'],
            ['state.stage' => 'waiting']
        );

        self::assertSame(
            1,
            $event->sequenceSnapshot
        );

        self::assertSame(
            300,
            $event->dueAt->getTimestamp()
            - $this->clock->now()->getTimestamp()
        );

        self::assertSame(
            ['state.stage' => 'waiting'],
            $event->requiredState
        );

        self::assertSame(
            [],
            $this->runner->runDue(
                'worker',
                60,
                static function (): void {}
            )
        );

        $this->clock->advanceSeconds(300);

        $executions = 0;

        $result = $this->runner->runDue(
            'worker',
            60,
            static function () use (&$executions): void {
                $executions++;
            }
        );

        self::assertSame(1, $executions);
        self::assertSame(
            'completed',
            $result[0]['status']
        );
    }

    public function testUserResponseMakesFollowUpStale(): void
    {
        $key = $this->key('sequence-stale');

        $this->incoming->record(
            $key,
            new IncomingEvent(
                'event-1',
                'message',
                ['text' => 'first']
            )
        );

        $event = $this->followUps->schedule(
            $key
        );

        $this->clock->advanceSeconds(100);

        $this->incoming->record(
            $key,
            new IncomingEvent(
                'event-2',
                'message',
                ['text' => 'user replied']
            )
        );

        $this->clock->advanceSeconds(200);

        $executions = 0;

        $result = $this->runner->runDue(
            'worker-stale',
            60,
            static function () use (&$executions): void {
                $executions++;
            }
        );

        self::assertSame(0, $executions);
        self::assertSame(
            'stale',
            $result[0]['status']
        );
        self::assertSame(
            'sequence_changed',
            $result[0]['reason']
        );
        self::assertSame(
            'stale',
            $this->scheduler
                ->find($event->eventId)
                ?->status
        );
    }

    public function testChangedRequiredStateMakesFollowUpStale(): void
    {
        $key = $this->key('state-stale');

        $this->incoming->record(
            $key,
            new IncomingEvent(
                'event-1',
                'message',
                ['text' => 'first']
            )
        );

        $this->contextStore->mergeState(
            $key,
            ['stage' => 'waiting']
        );

        $this->followUps->schedule(
            $key,
            [],
            ['state.stage' => 'waiting']
        );

        $this->contextStore->mergeState(
            $key,
            ['stage' => 'changed']
        );

        $this->clock->advanceSeconds(300);

        $result = $this->runner->runDue(
            'worker-state',
            60,
            static function (): void {
                throw new \RuntimeException(
                    'must not execute'
                );
            }
        );

        self::assertSame(
            'stale',
            $result[0]['status']
        );
        self::assertSame(
            'state_condition_changed',
            $result[0]['reason']
        );
    }

    public function testHandlerFailureBecomesIndeterminateWithoutRetry(): void
    {
        $key = $this->key('indeterminate');

        $this->incoming->record(
            $key,
            new IncomingEvent(
                'event-1',
                'message',
                ['text' => 'first']
            )
        );

        $event = $this->followUps->schedule(
            $key
        );

        $this->clock->advanceSeconds(300);

        $attempts = 0;

        $result = $this->runner->runDue(
            'worker-indeterminate',
            60,
            static function () use (&$attempts): void {
                $attempts++;

                throw new \RuntimeException(
                    'SECRET_EXTERNAL_RESULT_UNKNOWN'
                );
            }
        );

        self::assertSame(1, $attempts);
        self::assertSame(
            'indeterminate',
            $result[0]['status']
        );

        $persisted = $this->scheduler->find(
            $event->eventId
        );

        self::assertSame(
            'indeterminate',
            $persisted?->status
        );
        self::assertSame(
            \RuntimeException::class,
            $persisted?->failureClass
        );

        $raw = file_get_contents(
            $this->root
            . '/scheduler/scheduled-events.json'
        );

        self::assertIsString($raw);
        self::assertStringNotContainsString(
            'SECRET_EXTERNAL_RESULT_UNKNOWN',
            $raw
        );

        $this->clock->advanceSeconds(3600);

        self::assertSame(
            [],
            $this->runner->runDue(
                'worker-again',
                60,
                static function () use (&$attempts): void {
                    $attempts++;
                }
            )
        );

        self::assertSame(1, $attempts);
    }

    public function testExpiredAbandonedClaimIsRecoveredAfterRestart(): void
    {
        $key = $this->key('recovery');

        $this->incoming->record(
            $key,
            new IncomingEvent(
                'event-1',
                'message',
                ['text' => 'first']
            )
        );

        $event = $this->followUps->schedule(
            $key,
            [],
            [],
            10
        );

        $this->clock->advanceSeconds(10);

        $abandoned = $this->scheduler->claimDue(
            'dead-worker',
            10,
            1
        );

        self::assertCount(1, $abandoned);
        self::assertSame(
            $event->eventId,
            $abandoned[0]->eventId
        );

        $this->clock->advanceSeconds(11);

        $scheduler2 = new Scheduler(
            new JsonScheduledEventStore(
                $this->root . '/scheduler'
            ),
            $this->clock
        );

        $runner2 = new ScheduledEventRunner(
            $scheduler2,
            $this->conversationStore,
            $this->contextStore
        );

        $runs = 0;

        $result = $runner2->runDue(
            'recovery-worker',
            60,
            static function () use (&$runs): void {
                $runs++;
            }
        );

        self::assertSame(1, $runs);
        self::assertSame(
            'completed',
            $result[0]['status']
        );
        self::assertSame(
            2,
            $scheduler2
                ->find($event->eventId)
                ?->claimCount
        );
    }

    private function key(string $suffix): string
    {
        return ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            $suffix
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
