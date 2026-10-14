<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Tests\Fixtures\FakeAiProvider;
use Asmpkg\Guiche\Tests\Fixtures\FakeStateManager;
use Asmpkg\Guiche\Tests\Fixtures\InMemoryContextStore;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class StateManagerContractTest extends TestCase
{
    public function testUserMessagePatchIsAppliedByCore(): void
    {
        $store = new InMemoryContextStore();
        $stateManager = new FakeStateManager(
            userPatch: [
                'intent' => 'generic_flow',
                'state' => [
                    'flow' => 'generic',
                    'user_hook' => true,
                ],
            ]
        );
        $ai = new FakeAiProvider([
            [
                'id' => 'response-1',
                'text' => 'Generic flow completed.',
                'tool_calls' => [],
                'usage' => [],
            ],
        ]);

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $store,
            ai: $ai,
            instructions: 'Generic test.',
            stateManager: $stateManager
        );

        $result = $guiche->handle(
            'state-contract',
            'domain independent message'
        );

        self::assertSame('generic_flow', $result['context']['intent']);
        self::assertTrue($result['context']['state']['user_hook']);
        self::assertSame('generic', $result['context']['state']['flow']);
        self::assertSame('Generic flow completed.', $result['message']);
        self::assertCount(1, $stateManager->userCalls);
        self::assertSame(
            'domain independent message',
            $stateManager->userCalls[0]['message']
        );

        $persisted = $store->load('state-contract');

        self::assertSame('generic_flow', $persisted['intent']);
        self::assertTrue($persisted['state']['user_hook']);
    }

    public function testToolResultContractRemainsDomainIndependent(): void
    {
        $stateManager = new FakeStateManager(
            toolPatch: [
                'state' => [
                    'tool_hook' => true,
                    'last_tool' => 'AnyTool',
                ],
            ]
        );

        $patch = $stateManager->onToolResult(
            ['state' => []],
            'AnyTool',
            ['value' => 'abc'],
            ['success' => true, 'data' => ['value' => 'abc']]
        );

        self::assertTrue($patch['state']['tool_hook']);
        self::assertSame('AnyTool', $patch['state']['last_tool']);
        self::assertSame('AnyTool', $stateManager->toolCalls[0]['tool']);
        self::assertSame(
            ['value' => 'abc'],
            $stateManager->toolCalls[0]['arguments']
        );
    }
}
