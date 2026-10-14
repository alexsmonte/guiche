<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Observability\JsonlRuntimeEventSink;
use Asmpkg\Guiche\Observability\NullRuntimeEventSink;
use Asmpkg\Guiche\Observability\RuntimeEventFactory;
use Asmpkg\Guiche\Tests\Fixtures\FakeAiProvider;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeEventSinkTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v06-events-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testNullSinkIsNoopContractImplementation(): void
    {
        $sink = new NullRuntimeEventSink();

        self::assertInstanceOf(
            RuntimeEventSinkInterface::class,
            $sink
        );

        $sink->record(['type' => 'noop']);

        self::assertTrue(true);
    }

    public function testJsonlWritesOneValidJsonObjectPerLine(): void
    {
        $file = $this->directory . '/events/runtime.jsonl';
        $sink = new JsonlRuntimeEventSink($file);

        foreach (['a', 'b'] as $type) {
            $sink->record(
                RuntimeEventFactory::fromLegacy(
                    'session',
                    'interaction',
                    ['type' => $type]
                )
            );
        }

        $lines = file(
            $file,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );

        self::assertIsArray($lines);
        self::assertCount(2, $lines);

        foreach ($lines as $line) {
            self::assertIsArray(
                json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
            );
        }
    }

    public function testGuicheSendsRedactedEventsToCollectorWithoutChangingResponse(): void
    {
        $collector = new class implements RuntimeEventSinkInterface {
            public array $events = [];

            public function record(array $event): void
            {
                $this->events[] = $event;
            }
        };

        $ai = new FakeAiProvider([
            [
                'id' => 'response-1',
                'text' => 'SECRET-AI-RESPONSE',
                'tool_calls' => [],
                'usage' => [
                    'input_tokens' => 7,
                    'output_tokens' => 3,
                    'total_tokens' => 10,
                ],
            ],
        ]);

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: new ContextStore(
                $this->directory . '/context',
                'America/Manaus'
            ),
            ai: $ai,
            eventSink: $collector
        );

        $result = $guiche->handle(
            'secret-session-id',
            'SECRET-USER-MESSAGE'
        );

        self::assertSame(
            'SECRET-AI-RESPONSE',
            $result['message']
        );
        self::assertNotEmpty($result['events']);
        self::assertCount(
            count($result['events']),
            $collector->events
        );

        $interactionIds = [];
        $eventIds = [];

        foreach ($collector->events as $event) {
            self::assertSame(
                hash('sha256', 'secret-session-id'),
                $event['session_key']
            );

            $interactionIds[] = $event['interaction_id'];
            $eventIds[] = $event['event_id'];
        }

        self::assertCount(
            1,
            array_unique($interactionIds)
        );
        self::assertCount(
            count($eventIds),
            array_unique($eventIds)
        );

        $json = json_encode(
            $collector->events,
            JSON_THROW_ON_ERROR
        );

        self::assertStringNotContainsString(
            'secret-session-id',
            $json
        );
        self::assertStringNotContainsString(
            'SECRET-USER-MESSAGE',
            $json
        );
        self::assertStringNotContainsString(
            'SECRET-AI-RESPONSE',
            $json
        );
    }

    public function testSinkFailureIsBestEffortAndNotRetried(): void
    {
        $failingSink = new class implements RuntimeEventSinkInterface {
            public int $calls = 0;

            public function record(array $event): void
            {
                $this->calls++;

                throw new RuntimeException(
                    'intentional sink failure'
                );
            }
        };

        $ai = new FakeAiProvider([
            [
                'id' => 'response-1',
                'text' => 'functional-result',
                'tool_calls' => [],
                'usage' => [],
            ],
        ]);

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: new ContextStore(
                $this->directory . '/failing',
                'America/Manaus'
            ),
            ai: $ai,
            eventSink: $failingSink
        );

        $result = $guiche->handle(
            'failing-session',
            'test'
        );

        self::assertSame(
            'functional-result',
            $result['message']
        );
        self::assertSame(1, $failingSink->calls);
        self::assertContains(
            'observability_sink_failed',
            array_column(
                $result['events'],
                'type'
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
