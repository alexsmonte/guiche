<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Contracts;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Tests\Fixtures\FakeAiProvider;
use Asmpkg\Guiche\Tests\Fixtures\FakeStateManager;
use Asmpkg\Guiche\Tests\Fixtures\FakeTool;
use Asmpkg\Guiche\Tests\Fixtures\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FixtureContractsTest extends TestCase
{
    public function testFakeAiProviderIsDeterministicAndRecordsCalls(): void
    {
        $provider = new FakeAiProvider([
            ['id' => 'r1', 'text' => 'ok', 'tool_calls' => []],
            ['id' => 'r2', 'text' => 'done', 'tool_calls' => []],
        ]);

        self::assertInstanceOf(AiProviderInterface::class, $provider);

        $first = $provider->respond('instructions', [['role' => 'user', 'content' => 'hello']]);
        $second = $provider->continueWithToolOutputs('r1', 'instructions', [['call_id' => 'c1', 'output' => ['ok' => true]]]);

        self::assertSame('r1', $first['id']);
        self::assertSame('r2', $second['id']);
        self::assertCount(2, $provider->calls);
        self::assertSame('respond', $provider->calls[0]['method']);
        self::assertSame('continueWithToolOutputs', $provider->calls[1]['method']);
    }

    public function testFakeToolImplementsContractAndRecordsArguments(): void
    {
        $tool = new FakeTool('Ping', ['pong' => true]);

        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertSame('Ping', $tool->name());
        self::assertSame(['pong' => true], $tool->execute(['value' => 42]));
        self::assertSame([['value' => 42]], $tool->executions);
    }

    public function testFakeStateManagerReturnsConfiguredPatches(): void
    {
        $state = new FakeStateManager(['state' => ['stage' => 'next']], ['state' => ['tool_seen' => true]]);

        self::assertInstanceOf(StateManagerInterface::class, $state);
        self::assertSame(['state' => ['stage' => 'next']], $state->onUserMessage([], 'hello'));
        self::assertSame(
            ['state' => ['tool_seen' => true]],
            $state->onToolResult([], 'Ping', [], ['pong' => true])
        );
        self::assertCount(1, $state->userCalls);
        self::assertCount(1, $state->toolCalls);
    }

    public function testFrozenClockProvidesDeterministicTime(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-14T00:00:00+00:00'));

        self::assertInstanceOf(ClockInterface::class, $clock);
        self::assertSame('2026-10-14T00:00:00+00:00', $clock->now()->format(DATE_ATOM));

        $clock->advanceSeconds(40);

        self::assertSame('2026-10-14T00:00:40+00:00', $clock->now()->format(DATE_ATOM));
    }
}
