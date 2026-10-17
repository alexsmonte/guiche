<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Conversation\FollowUpRuntime;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Contracts\ClockInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FollowUpDxV16Test extends TestCase
{
    private string $root;
    private string $conversationDir;
    private string $schedulerDir;
    private string $contextDir;
    private FollowUpDxClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir()
            . '/guiche-v16-followup-dx-'
            . bin2hex(random_bytes(8));

        $this->conversationDir =
            $this->root . '/conversations';

        $this->schedulerDir =
            $this->root . '/scheduler';

        $this->contextDir =
            $this->root . '/contexts';

        $this->clock = new FollowUpDxClock(
            new DateTimeImmutable(
                '2026-10-17T12:00:00Z'
            )
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function testFactoryUsesTwoHoursByDefault(): void
    {
        $runtime = $this->runtime();

        self::assertTrue($runtime->enabled());
        self::assertSame(
            7200,
            $runtime->defaultDelaySeconds()
        );

        $key = 'followup-default-delay';
        $this->recordIncoming($key, 'event-1');

        $event = $runtime->schedule($key);

        self::assertNotNull($event);
        self::assertSame(
            7200,
            $event->dueAt->getTimestamp()
            - $this->clock->now()->getTimestamp()
        );
        self::assertSame(
            1,
            $event->sequenceSnapshot
        );
    }

    public function testDisabledRuntimeDoesNotScheduleOrClaim(): void
    {
        $runtime = FollowUpRuntime::fromDirectories(
            $this->conversationDir,
            $this->schedulerDir,
            $this->contextDir,
            enabled: false,
            clock: $this->clock
        );

        $key = 'followup-disabled';
        $this->recordIncoming($key, 'event-1');

        self::assertFalse($runtime->enabled());
        self::assertNull(
            $runtime->schedule($key)
        );

        $runs = 0;

        self::assertSame(
            [],
            $runtime->runDue(
                'worker-disabled',
                static function () use (&$runs): void {
                    $runs++;
                }
            )
        );

        self::assertSame(0, $runs);
    }

    public function testSameSequenceIsDeduplicated(): void
    {
        $runtime = $this->runtime();
        $key = 'followup-dedupe';

        $this->recordIncoming($key, 'event-1');

        $first = $runtime->schedule(
            $key,
            ['template' => 'first']
        );

        $second = $runtime->schedule(
            $key,
            ['template' => 'second']
        );

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(
            $first->eventId,
            $second->eventId
        );
    }

    public function testUserResponseMakesOldFollowUpStale(): void
    {
        $runtime = $this->runtime(
            defaultDelaySeconds: 60
        );

        $key = 'followup-user-response';

        $this->recordIncoming($key, 'event-1');

        $event = $runtime->schedule($key);

        self::assertNotNull($event);
        self::assertSame(
            1,
            $event->sequenceSnapshot
        );

        $this->clock->advance('+30 seconds');

        $this->recordIncoming($key, 'event-2');

        $this->clock->advance('+30 seconds');

        $executions = 0;

        $results = $runtime->runDue(
            'worker-stale-sequence',
            static function () use (&$executions): void {
                $executions++;
            }
        );

        self::assertSame(0, $executions);
        self::assertSame(
            'stale',
            $results[0]['status'] ?? null
        );
        self::assertSame(
            'sequence_changed',
            $results[0]['reason'] ?? null
        );

        self::assertSame(
            'stale',
            $runtime->find(
                $event->eventId
            )?->status
        );
    }

    public function testRequiredStateMakesFollowUpStale(): void
    {
        $runtime = $this->runtime(
            defaultDelaySeconds: 60
        );

        $key = 'followup-state';

        $this->recordIncoming($key, 'event-1');

        $context = new ContextStore(
            $this->contextDir,
            'UTC'
        );

        $context->mergeState(
            $key,
            ['stage' => 'waiting']
        );

        $event = $runtime->schedule(
            $key,
            requiredState: [
                'state.stage' => 'waiting',
            ]
        );

        self::assertNotNull($event);

        $context->mergeState(
            $key,
            ['stage' => 'completed']
        );

        $this->clock->advance('+60 seconds');

        $executions = 0;

        $results = $runtime->runDue(
            'worker-stale-state',
            static function () use (&$executions): void {
                $executions++;
            }
        );

        self::assertSame(0, $executions);
        self::assertSame(
            'stale',
            $results[0]['status'] ?? null
        );
        self::assertSame(
            'state_condition_changed',
            $results[0]['reason'] ?? null
        );
    }

    public function testValidFollowUpExecutesOnce(): void
    {
        $runtime = $this->runtime(
            defaultDelaySeconds: 60
        );

        $key = 'followup-valid';

        $this->recordIncoming($key, 'event-1');

        $event = $runtime->schedule(
            $key,
            ['template' => 'resume']
        );

        self::assertNotNull($event);

        $this->clock->advance('+60 seconds');

        $executions = 0;

        $results = $runtime->runDue(
            'worker-valid',
            static function () use (&$executions): void {
                $executions++;
            }
        );

        self::assertSame(1, $executions);
        self::assertSame(
            'completed',
            $results[0]['status'] ?? null
        );

        self::assertSame(
            [],
            $runtime->runDue(
                'worker-valid-again',
                static function () use (&$executions): void {
                    $executions++;
                }
            )
        );

        self::assertSame(1, $executions);
        self::assertSame(
            'completed',
            $runtime->find(
                $event->eventId
            )?->status
        );
    }

    public function testFailureBecomesIndeterminateWithoutRetry(): void
    {
        $runtime = $this->runtime(
            defaultDelaySeconds: 10
        );

        $key = 'followup-indeterminate';

        $this->recordIncoming($key, 'event-1');

        $event = $runtime->schedule($key);

        self::assertNotNull($event);

        $this->clock->advance('+10 seconds');

        $attempts = 0;

        $results = $runtime->runDue(
            'worker-indeterminate',
            static function () use (&$attempts): void {
                $attempts++;

                throw new RuntimeException(
                    'external result unknown'
                );
            }
        );

        self::assertSame(1, $attempts);
        self::assertSame(
            'indeterminate',
            $results[0]['status'] ?? null
        );

        $this->clock->advance('+3600 seconds');

        self::assertSame(
            [],
            $runtime->runDue(
                'worker-indeterminate-again',
                static function () use (&$attempts): void {
                    $attempts++;
                }
            )
        );

        self::assertSame(1, $attempts);
        self::assertSame(
            'indeterminate',
            $runtime->find(
                $event->eventId
            )?->status
        );
    }

    public function testCancelAndFindRemainAvailableWhenDisabled(): void
    {
        $enabled = $this->runtime(
            defaultDelaySeconds: 60
        );

        $key = 'followup-cancel-disabled';

        $this->recordIncoming($key, 'event-1');

        $event = $enabled->schedule($key);

        self::assertNotNull($event);

        $disabled = FollowUpRuntime::fromDirectories(
            $this->conversationDir,
            $this->schedulerDir,
            $this->contextDir,
            defaultDelaySeconds: 60,
            enabled: false,
            clock: $this->clock
        );

        self::assertSame(
            'pending',
            $disabled->find(
                $event->eventId
            )?->status
        );

        $cancelled = $disabled->cancel(
            $event->eventId
        );

        self::assertSame(
            'cancelled',
            $cancelled->status
        );
    }

    public function testInvalidWorkerLimitsFailClosed(): void
    {
        $runtime = $this->runtime();

        $this->expectException(
            RuntimeException::class
        );

        $runtime->runDue(
            'worker-invalid',
            static function (): void {
            },
            leaseSeconds: 0
        );
    }

    private function runtime(
        int $defaultDelaySeconds = 7200
    ): FollowUpRuntime {
        return FollowUpRuntime::fromDirectories(
            $this->conversationDir,
            $this->schedulerDir,
            $this->contextDir,
            defaultDelaySeconds:
                $defaultDelaySeconds,
            enabled: true,
            contextTimezone: 'UTC',
            clock: $this->clock
        );
    }

    private function recordIncoming(
        string $key,
        string $eventId
    ): void {
        $store = new JsonConversationStore(
            $this->conversationDir
        );

        $recorder = new IncomingEventRecorder(
            $store,
            $this->clock
        );

        $recorder->record(
            $key,
            new IncomingEvent(
                $eventId,
                'message',
                ['text' => $eventId]
            )
        );
    }

    private function removeTree(
        string $directory
    ): void {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory
                . DIRECTORY_SEPARATOR
                . $item;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}

final class FollowUpDxClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $value
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }

    public function advance(string $modifier): void
    {
        $next = $this->value->modify(
            $modifier
        );

        if (!$next instanceof DateTimeImmutable) {
            throw new RuntimeException(
                'Invalid clock modifier.'
            );
        }

        $this->value = $next;
    }
}
