<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SessionLifecycleV16Test extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir()
            . '/guiche-v16-session-'
            . bin2hex(random_bytes(8));

        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function testBuilderRejectsInvalidTtl(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        (new GuicheBuilder())
            ->withSessionTtl(0);
    }

    public function testFirstAndSecondTurnsExposeNewThenActive(): void
    {
        $store = new ContextStore($this->directory);
        $provider = new LifecycleCaptureProvider();

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withAiProvider($provider)
            ->withSessionTtl(86400)
            ->build();

        $first = $guiche->handle(
            'session-first-active',
            'Olá'
        );

        self::assertSame(
            'new',
            $first['context']['session']['status']
                ?? null
        );
        self::assertTrue(
            $first['context']['session'][
                'is_first_message'
            ] ?? false
        );
        self::assertSame(
            1,
            $first['context']['session']['cycle']
                ?? null
        );

        self::assertStringContainsString(
            '"status": "new"',
            $provider->instructions[0]
        );
        self::assertStringContainsString(
            '"is_first_message": true',
            $provider->instructions[0]
        );

        $second = $guiche->handle(
            'session-first-active',
            'Continuando'
        );

        self::assertSame(
            'active',
            $second['context']['session']['status']
                ?? null
        );
        self::assertFalse(
            $second['context']['session'][
                'is_first_message'
            ] ?? true
        );
        self::assertSame(
            1,
            $second['context']['session']['cycle']
                ?? null
        );

        self::assertStringContainsString(
            '"status": "active"',
            $provider->instructions[1]
        );
        self::assertStringContainsString(
            '"is_first_message": false',
            $provider->instructions[1]
        );
    }

    public function testExpiredSessionStartsCleanCycleAndKeepsTrustedIdentity(): void
    {
        $store = new ContextStore($this->directory);
        $provider = new LifecycleCaptureProvider();

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withAiProvider($provider)
            ->withSessionTtl(86400)
            ->build();

        $session = 'session-expired';

        $guiche->bindIdentity(
            $session,
            [
                'provider' => 'test',
                'conversation_id' => '42',
            ]
        );

        $guiche->handle($session, 'Primeiro ciclo');

        $store->mergeState(
            $session,
            [
                'old_fact' => 'must-disappear',
            ]
        );

        $store->addToolExecution(
            $session,
            'ReadBeforeExpiry',
            ['query' => 'x'],
            [
                'success' => true,
                'value' => 'old',
            ]
        );

        $this->ageSession(
            $session,
            '2000-01-01T00:00:00+00:00'
        );

        $expired = $guiche->handle(
            $session,
            'Novo ciclo'
        );

        $context = $expired['context'];

        self::assertSame(
            'expired',
            $context['session']['status'] ?? null
        );
        self::assertTrue(
            $context['session']['is_first_message']
                ?? false
        );
        self::assertTrue(
            $context['session']['was_expired']
                ?? false
        );
        self::assertSame(
            2,
            $context['session']['cycle'] ?? null
        );

        self::assertSame([], $context['state']);
        self::assertSame([], $context['tool_history']);

        self::assertSame(
            [
                'provider' => 'test',
                'conversation_id' => '42',
            ],
            $context['execution_context']['identity']
                ?? null
        );

        self::assertCount(2, $context['messages']);
        self::assertSame(
            'Novo ciclo',
            $context['messages'][0]['content']
                ?? null
        );

        self::assertGreaterThanOrEqual(
            2,
            $context['session']['previous_cycle'][
                'message_count'
            ] ?? 0
        );

        self::assertSame(
            1,
            $context['session']['previous_cycle'][
                'tool_execution_count'
            ] ?? null
        );

        self::assertStringContainsString(
            '"status": "expired"',
            $provider->instructions[1]
        );
        self::assertStringContainsString(
            '"is_first_message": true',
            $provider->instructions[1]
        );
    }

    public function testWriteReplayDoesNotCrossExpiredCycle(): void
    {
        $store = new ContextStore($this->directory);
        $tool = new LifecycleWriteTool();

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->registerTool(
                new ToolRegistration(
                    $tool,
                    ['operation' => 'write']
                )
            )
            ->withSessionTtl(86400)
            ->build();

        $session = 'session-write-cycle';

        $first = $guiche->executeTool(
            $tool->name(),
            ['value' => 'same'],
            $session
        );

        $replay = $guiche->executeTool(
            $tool->name(),
            ['value' => 'same'],
            $session
        );

        self::assertSame(1, $tool->executions);
        self::assertSame($first, $replay);

        $this->ageSession(
            $session,
            '2000-01-01T00:00:00+00:00'
        );

        $newCycle = $guiche->executeTool(
            $tool->name(),
            ['value' => 'same'],
            $session
        );

        self::assertSame(2, $tool->executions);
        self::assertNotSame($first, $newCycle);

        $context = $store->load($session);

        self::assertSame(
            2,
            $context['session']['cycle'] ?? null
        );
        self::assertSame(
            'expired',
            $context['session']['status'] ?? null
        );
        self::assertCount(
            1,
            $context['tool_history'] ?? []
        );
    }

    public function testLifecycleIsOptInAndLegacyContextStaysUntouched(): void
    {
        $store = new ContextStore($this->directory);
        $provider = new LifecycleCaptureProvider();

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withAiProvider($provider)
            ->build();

        $result = $guiche->handle(
            'session-legacy',
            'Sem TTL'
        );

        self::assertArrayNotHasKey(
            'session',
            $result['context']
        );

        self::assertStringNotContainsString(
            '"session"',
            $provider->instructions[0]
        );
    }

    public function testLifecycleEventIsObservable(): void
    {
        $store = new ContextStore($this->directory);
        $provider = new LifecycleCaptureProvider();

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withAiProvider($provider)
            ->withSessionTtl(86400)
            ->build();

        $result = $guiche->handle(
            'session-event',
            'Oi'
        );

        $events = array_values(
            array_filter(
                $result['events'] ?? [],
                static fn (array $event): bool =>
                    ($event['type'] ?? null)
                    === 'session_lifecycle'
            )
        );

        self::assertCount(1, $events);
        self::assertSame(
            'new',
            $events[0]['status'] ?? null
        );
        self::assertTrue(
            $events[0]['is_first_message']
                ?? false
        );
    }

    private function ageSession(
        string $sessionId,
        string $updatedAt
    ): void {
        $file = rtrim(
            $this->directory,
            DIRECTORY_SEPARATOR
        )
            . DIRECTORY_SEPARATOR
            . hash('sha256', $sessionId)
            . '.json';

        self::assertFileExists($file);

        $json = file_get_contents($file);

        self::assertNotFalse($json);

        $context = json_decode(
            (string) $json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($context);

        $context['updated_at'] = $updatedAt;

        file_put_contents(
            $file,
            json_encode(
                $context,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
            . PHP_EOL
        );
    }
}

final class LifecycleCaptureProvider implements AiProviderInterface
{
    /**
     * @var list<string>
     */
    public array $instructions = [];

    /**
     * @param array<mixed> $messages
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->instructions[] = $instructions;

        return [
            'id' => 'response-'
                . count($this->instructions),
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }

    /**
     * @param array<mixed> $toolOutputs
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        return [
            'id' => $previousResponseId . '-done',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}

final class LifecycleWriteTool implements ToolInterface
{
    public int $executions = 0;

    public function name(): string
    {
        return 'LifecycleWriteV16';
    }

    public function description(): string
    {
        return 'WRITE sintética para testar escopo por ciclo.';
    }

    /**
     * @return array<mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function execute(array $arguments): array
    {
        $this->executions++;

        return [
            'success' => true,
            'execution' => $this->executions,
            'value' => $arguments['value'] ?? null,
        ];
    }
}
