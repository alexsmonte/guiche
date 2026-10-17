<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Cache\ToolReusePolicy;
use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\ToolRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReusableToolsValidityV16Test extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir()
            . '/guiche-v16-reuse-'
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

    public function testSameDependenciesProduceCacheHit(): void
    {
        $store = new ContextStore($this->directory);
        $tool = new CountingReusableReadTool();
        $guiche = $this->runtime(
            $store,
            $tool,
            [
                'reuse' => [
                    'depends_on' => [
                        'state.scope',
                    ],
                ],
            ]
        );

        $session = 'reuse-same-state';
        $store->mergeState($session, ['scope' => 'A']);

        $first = $guiche->handle($session, 'primeira');
        $second = $guiche->handle($session, 'segunda');

        self::assertSame(1, $tool->executions);
        self::assertFalse(
            $this->hasEvent($first, 'tool_cache_hit')
        );
        self::assertTrue(
            $this->hasEvent($second, 'tool_cache_hit')
        );

        $toolResult = $this->event(
            $second,
            'tool_result'
        );

        self::assertArrayNotHasKey(
            '__guiche_reuse_v1',
            $toolResult['result'] ?? []
        );

        $context = $store->load($session);
        $history = $context['tool_history'] ?? [];

        self::assertCount(1, $history);
        self::assertArrayHasKey(
            '__guiche_reuse_v1',
            $history[0]['result'] ?? []
        );
    }

    public function testDependencyChangeInvalidatesCache(): void
    {
        $store = new ContextStore($this->directory);
        $tool = new CountingReusableReadTool();
        $guiche = $this->runtime(
            $store,
            $tool,
            [
                'reuse' => [
                    'depends_on' => [
                        'state.scope',
                    ],
                ],
            ]
        );

        $session = 'reuse-state-change';
        $store->mergeState($session, ['scope' => 'A']);

        $guiche->handle($session, 'estado A');

        $store->mergeState($session, ['scope' => 'B']);

        $changed = $guiche->handle(
            $session,
            'estado B'
        );

        self::assertSame(2, $tool->executions);
        self::assertFalse(
            $this->hasEvent($changed, 'tool_cache_hit')
        );

        $invalidated = $this->event(
            $changed,
            'tool_cache_invalidated'
        );

        self::assertSame(
            'dependency_changed',
            $invalidated['reason'] ?? null
        );
    }

    public function testStateAtoBtoADoesNotResurrectOlderCache(): void
    {
        $store = new ContextStore($this->directory);
        $tool = new CountingReusableReadTool();
        $guiche = $this->runtime(
            $store,
            $tool,
            [
                'reuse' => [
                    'depends_on' => [
                        'state.scope',
                    ],
                ],
            ]
        );

        $session = 'reuse-a-b-a';

        $store->mergeState($session, ['scope' => 'A']);
        $guiche->handle($session, 'A1');

        $store->mergeState($session, ['scope' => 'B']);
        $guiche->handle($session, 'B');

        $store->mergeState($session, ['scope' => 'A']);
        $backToA = $guiche->handle($session, 'A2');

        self::assertSame(3, $tool->executions);

        $invalidated = $this->event(
            $backToA,
            'tool_cache_invalidated'
        );

        self::assertSame(
            'dependency_changed',
            $invalidated['reason'] ?? null
        );
    }

    public function testLegacyReusableToolKeepsOldSemantics(): void
    {
        $store = new ContextStore($this->directory);
        $tool = new CountingReusableReadTool();
        $guiche = $this->runtime(
            $store,
            $tool,
            []
        );

        $session = 'reuse-legacy';

        $store->mergeState($session, ['scope' => 'A']);
        $guiche->handle($session, 'A');

        $store->mergeState($session, ['scope' => 'B']);
        $second = $guiche->handle($session, 'B');

        self::assertSame(1, $tool->executions);
        self::assertTrue(
            $this->hasEvent($second, 'tool_cache_hit')
        );
        self::assertFalse(
            $this->hasEvent(
                $second,
                'tool_cache_invalidated'
            )
        );
    }

    public function testLegacyHistoryIsNotTrustedAfterDependenciesAreAdded(): void
    {
        $store = new ContextStore($this->directory);
        $tool = new CountingReusableReadTool();
        $session = 'reuse-migration';

        $store->mergeState($session, ['scope' => 'A']);

        $legacy = $this->runtime(
            $store,
            $tool,
            []
        );

        $legacy->handle($session, 'legado');

        $aware = $this->runtime(
            $store,
            $tool,
            [
                'reuse' => [
                    'depends_on' => [
                        'state.scope',
                    ],
                ],
            ]
        );

        $migrated = $aware->handle(
            $session,
            'com dependência'
        );

        self::assertSame(2, $tool->executions);

        $invalidated = $this->event(
            $migrated,
            'tool_cache_invalidated'
        );

        self::assertSame(
            'dependency_signature_missing',
            $invalidated['reason'] ?? null
        );
    }

    public function testMetadataIsValidatedWhenToolIsRegistered(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new ToolRegistration(
            new CountingReusableReadTool(),
            [
                'reuse' => [
                    'depends_on' =>
                        'state.scope',
                ],
            ]
        );
    }

    public function testDependencyFingerprintIsOrderIndependent(): void
    {
        $policy = new ToolReusePolicy();

        $context = [
            'state' => [
                'scope' => 'A',
                'customer' => [
                    'id' => 10,
                ],
            ],
        ];

        $first = $policy->dependencyFingerprint(
            [
                'reuse' => [
                    'depends_on' => [
                        'state.scope',
                        'state.customer.id',
                    ],
                ],
            ],
            $context
        );

        $second = $policy->dependencyFingerprint(
            [
                'reuse' => [
                    'depends_on' => [
                        'state.customer.id',
                        'state.scope',
                    ],
                ],
            ],
            $context
        );

        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    /**
     * @param array<mixed> $metadata
     */
    private function runtime(
        ContextStore $store,
        CountingReusableReadTool $tool,
        array $metadata
    ): Guiche {
        $registry = new ToolRegistry();

        $registry->register(
            new ToolRegistration(
                $tool,
                array_replace(
                    [
                        'operation' => 'read',
                    ],
                    $metadata
                )
            )
        );

        return new Guiche(
            tools: $registry,
            context: $store,
            ai: new AlwaysCallReusableToolProvider(
                $tool->name()
            ),
            reusableTools: [
                $tool->name(),
            ]
        );
    }

    /**
     * @param array<mixed> $result
     */
    private function hasEvent(
        array $result,
        string $type
    ): bool {
        foreach ($result['events'] ?? [] as $event) {
            if (($event['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $result
     * @return array<mixed>
     */
    private function event(
        array $result,
        string $type
    ): array {
        foreach ($result['events'] ?? [] as $event) {
            if (($event['type'] ?? null) === $type) {
                return $event;
            }
        }

        self::fail(
            "Evento não encontrado: {$type}"
        );
    }
}

final class CountingReusableReadTool implements ToolInterface
{
    public int $executions = 0;

    public function name(): string
    {
        return 'ReusableReadV16';
    }

    public function description(): string
    {
        return 'Tool READ usada para testar cache contextual.';
    }

    /**
     * @return array<mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'query',
            ],
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
            'value' => 'execution-'
                . $this->executions,
            'query' => $arguments['query'] ?? null,
        ];
    }
}

final class AlwaysCallReusableToolProvider implements AiProviderInterface
{
    private int $sequence = 0;

    public function __construct(
        private readonly string $toolName
    ) {
    }

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
        $this->sequence++;

        return [
            'id' => 'response-'
                . $this->sequence,
            'text' => '',
            'tool_calls' => [
                [
                    'call_id' => 'call-'
                        . $this->sequence,
                    'name' => $this->toolName,
                    'arguments' => [
                        'query' => 'same',
                    ],
                ],
            ],
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
            'id' => $previousResponseId
                . '-done',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}
