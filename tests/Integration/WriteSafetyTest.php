<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\Tests\Fixtures\FakeAiProvider;
use Asmpkg\Guiche\Tests\Fixtures\FakeTool;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class WriteSafetyTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v06-write-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testDirectWriteRequiresSessionAndReplaysDuplicate(): void
    {
        $tool = new FakeTool(
            'DirectWrite',
            [
                'success' => true,
                'created_id' => 42,
            ]
        );

        $registry = new ToolRegistry();
        $registry->register(
            new ToolRegistration(
                $tool,
                ['operation' => 'write']
            )
        );

        $guiche = new Guiche(
            tools: $registry,
            context: new ContextStore(
                $this->directory . '/direct',
                'America/Manaus'
            )
        );

        try {
            $guiche->executeTool(
                'DirectWrite',
                ['value' => 'same']
            );
            self::fail('WRITE without session must be blocked.');
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'write_session_required',
                $exception->reasonCode()
            );
        }

        self::assertCount(0, $tool->executions);

        $first = $guiche->executeTool(
            'DirectWrite',
            ['value' => 'same'],
            'session-a'
        );

        $second = $guiche->executeTool(
            'DirectWrite',
            ['value' => 'same'],
            'session-a'
        );

        self::assertCount(1, $tool->executions);
        self::assertSame($first, $second);

        $guiche->executeTool(
            'DirectWrite',
            ['value' => 'same'],
            'session-b'
        );

        self::assertCount(2, $tool->executions);
    }

    public function testRuntimeWriteUsesReplayNotGenericCache(): void
    {
        $tool = new FakeTool(
            'RuntimeWrite',
            ['success' => true, 'value' => 'same']
        );

        $registry = new ToolRegistry();
        $registry->register(
            new ToolRegistration(
                $tool,
                ['operation' => 'write']
            )
        );

        $ai = new FakeAiProvider([
            [
                'id' => 'write-initial',
                'text' => '',
                'tool_calls' => [
                    [
                        'call_id' => 'write-1',
                        'name' => 'RuntimeWrite',
                        'arguments' => ['value' => 'same'],
                    ],
                    [
                        'call_id' => 'write-2',
                        'name' => 'RuntimeWrite',
                        'arguments' => ['value' => 'same'],
                    ],
                ],
                'usage' => [],
            ],
            [
                'id' => 'write-final',
                'text' => 'final',
                'tool_calls' => [],
                'usage' => [],
            ],
        ]);

        $store = new ContextStore(
            $this->directory . '/runtime-write',
            'America/Manaus'
        );

        $guiche = new Guiche(
            tools: $registry,
            context: $store,
            ai: $ai,
            reusableTools: ['RuntimeWrite']
        );

        $response = $guiche->handle(
            'runtime-session',
            'execute twice'
        );

        self::assertCount(1, $tool->executions);
        self::assertCount(
            1,
            $this->eventsOfType(
                $response['events'],
                'tool_write_replay'
            )
        );
        self::assertCount(
            0,
            $this->eventsOfType(
                $response['events'],
                'tool_cache_hit'
            )
        );
        self::assertCount(
            1,
            $store->load('runtime-session')['tool_history']
        );
    }

    public function testReusableReadStillUsesGenericCache(): void
    {
        $tool = new FakeTool(
            'ReadTool',
            ['success' => true, 'value' => 'same']
        );

        $registry = new ToolRegistry();
        $registry->register(
            new ToolRegistration(
                $tool,
                ['operation' => 'read']
            )
        );

        $ai = new FakeAiProvider([
            [
                'id' => 'read-initial',
                'text' => '',
                'tool_calls' => [
                    [
                        'call_id' => 'read-1',
                        'name' => 'ReadTool',
                        'arguments' => ['value' => 'same'],
                    ],
                    [
                        'call_id' => 'read-2',
                        'name' => 'ReadTool',
                        'arguments' => ['value' => 'same'],
                    ],
                ],
                'usage' => [],
            ],
            [
                'id' => 'read-final',
                'text' => 'final',
                'tool_calls' => [],
                'usage' => [],
            ],
        ]);

        $guiche = new Guiche(
            tools: $registry,
            context: new ContextStore(
                $this->directory . '/runtime-read',
                'America/Manaus'
            ),
            ai: $ai,
            reusableTools: ['ReadTool']
        );

        $response = $guiche->handle(
            'read-session',
            'read twice'
        );

        self::assertCount(1, $tool->executions);
        self::assertCount(
            1,
            $this->eventsOfType(
                $response['events'],
                'tool_cache_hit'
            )
        );
        self::assertCount(
            0,
            $this->eventsOfType(
                $response['events'],
                'tool_write_replay'
            )
        );
    }

    private function eventsOfType(
        array $events,
        string $type
    ): array {
        return array_values(
            array_filter(
                $events,
                static fn (array $event): bool =>
                    ($event['type'] ?? null) === $type
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
