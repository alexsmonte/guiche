<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\ConversationRuntime;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Conversation\MessageBuffer;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Tests\Fixtures\FrozenClock;
use Asmpkg\Guiche\ToolRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ConversationRuntimeTest extends TestCase
{
    private string $root;
    private JsonConversationStore $store;
    private FrozenClock $clock;
    private IncomingEventRecorder $recorder;
    private MessageBuffer $buffer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/guiche-v06-runtime-'
            . bin2hex(random_bytes(8));

        $this->store = new JsonConversationStore(
            $this->root . '/conversations'
        );

        $this->clock = new FrozenClock(
            new DateTimeImmutable(
                '2026-10-14T04:00:00Z'
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
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testQuietWindowConsolidatesMessagesIntoSingleGuicheCall(): void
    {
        $provider = new class implements AiProviderInterface {
            public int $respondCalls = 0;
            public array $messageBatches = [];

            public function respond(
                string $instructions,
                array $messages,
                array $tools = []
            ): array {
                $this->respondCalls++;
                $this->messageBatches[] = $messages;

                return [
                    'id' => 'response-' . $this->respondCalls,
                    'text' => 'Consolidated response',
                    'tool_calls' => [],
                    'usage' => [],
                ];
            }

            public function continueWithToolOutputs(
                string $previousResponseId,
                string $instructions,
                array $toolOutputs,
                array $tools = []
            ): array {
                throw new \RuntimeException(
                    'Unexpected tool continuation.'
                );
            }
        };

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: new ContextStore(
                $this->root . '/contexts',
                'UTC'
            ),
            ai: $provider
        );

        $runtime = new ConversationRuntime(
            $guiche,
            $this->buffer
        );

        $key = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'quiet'
        );

        foreach (
            ['Oi', 'queria marcar', 'pelo dental plus']
            as $index => $text
        ) {
            $this->recorder->record(
                $key,
                new IncomingEvent(
                    'event-' . ($index + 1),
                    'message',
                    ['text' => $text]
                )
            );

            if ($index < 2) {
                $this->clock->advanceSeconds(5);
            }
        }

        self::assertNull(
            $runtime->processReady($key)
        );
        self::assertSame(0, $provider->respondCalls);

        $this->clock->advanceSeconds(40);

        $processed = $runtime->processReady(
            $key
        );

        self::assertTrue(
            $processed['processed']
        );
        self::assertSame(
            "Oi\nqueria marcar\npelo dental plus",
            $processed['message']
        );
        self::assertSame(1, $provider->respondCalls);

        $lastBatch = $provider->messageBatches[0];
        $lastMessage = $lastBatch[
            array_key_last($lastBatch)
        ]['content'] ?? null;

        self::assertSame(
            "Oi\nqueria marcar\npelo dental plus",
            $lastMessage
        );

        self::assertNull(
            $runtime->processReady($key)
        );
        self::assertSame(1, $provider->respondCalls);
    }

    public function testFailureMarksTurnFailedAndDoesNotBlindRetry(): void
    {
        $provider = new class implements AiProviderInterface {
            public int $respondCalls = 0;

            public function respond(
                string $instructions,
                array $messages,
                array $tools = []
            ): array {
                $this->respondCalls++;

                throw new \RuntimeException(
                    'SECRET_PROVIDER_FAILURE'
                );
            }

            public function continueWithToolOutputs(
                string $previousResponseId,
                string $instructions,
                array $toolOutputs,
                array $tools = []
            ): array {
                throw new \RuntimeException(
                    'Unexpected continuation.'
                );
            }
        };

        $runtime = new ConversationRuntime(
            new Guiche(
                tools: new ToolRegistry(),
                context: new ContextStore(
                    $this->root . '/failure-context',
                    'UTC'
                ),
                ai: $provider
            ),
            $this->buffer
        );

        $key = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'failure'
        );

        $this->recorder->record(
            $key,
            new IncomingEvent(
                'failure-1',
                'message',
                ['text' => 'fail']
            )
        );

        $this->clock->advanceSeconds(40);

        try {
            $runtime->processReady($key);
            self::fail('Provider failure must surface.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'SECRET_PROVIDER_FAILURE',
                $exception->getMessage()
            );
        }

        self::assertSame(1, $provider->respondCalls);

        $state = $this->store->load($key);
        $turn = reset($state['turns']);

        self::assertSame(
            'failed',
            $turn['status']
        );
        self::assertSame(
            \RuntimeException::class,
            $turn['failure_class']
        );

        self::assertStringNotContainsString(
            'SECRET_PROVIDER_FAILURE',
            json_encode($state, JSON_THROW_ON_ERROR)
        );

        self::assertNull(
            $runtime->processReady($key)
        );
        self::assertSame(1, $provider->respondCalls);
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
