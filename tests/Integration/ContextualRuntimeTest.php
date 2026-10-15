<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\Tests\Fixtures\FakeAiProvider;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContextualRuntimeTest extends TestCase
{
    private string $directory;
    private ContextStore $store;
    private ExecutionContextSeeder $seeder;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v11-contextual-runtime-'
            . bin2hex(random_bytes(8));

        $this->store = new ContextStore(
            $this->directory,
            'America/Manaus'
        );

        $this->seeder = new ExecutionContextSeeder(
            $this->store
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testLegacyToolStillExecutesWithoutSession(): void
    {
        $tool = new LegacyV11Tool('LegacyRead');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $guiche = new Guiche(
            $registry,
            $this->store
        );

        $result = $guiche->executeTool(
            'LegacyRead',
            ['value' => 'ok']
        );

        self::assertSame('legacy', $result['mode']);
        self::assertSame(1, $tool->calls);
    }

    public function testContextAwareToolUsesResolvedTenantNotAiArgument(): void
    {
        $this->seedIdentity('session-a', 'phone-a');

        $tool = new ContextualV11Tool(
            'ContextualRead'
        );

        $guiche = $this->guicheWith(
            $tool,
            new MappingV11TenantResolver(
                ['phone-a' => 142]
            )
        );

        $result = $guiche->executeTool(
            'ContextualRead',
            [
                'tenant_id' => 999,
                'value' => 'hello',
            ],
            'session-a'
        );

        self::assertSame(142, $result['context_tenant_id']);
        self::assertSame(999, $result['argument_tenant_id']);
        self::assertSame('session-a', $result['session_id']);
        self::assertSame(1, $tool->contextCalls);
        self::assertSame(0, $tool->legacyCalls);
        self::assertSame(
            142,
            $this->store
                ->load('session-a')
                ['execution_context']
                ['tenant_id']
        );
    }

    public function testContextAwareToolRequiresSession(): void
    {
        $tool = new ContextualV11Tool(
            'NeedsSession'
        );

        $guiche = $this->guicheWith(
            $tool,
            new MappingV11TenantResolver([])
        );

        try {
            $guiche->executeTool(
                'NeedsSession',
                []
            );

            self::fail('ContextAware Tool deveria ser bloqueada.');
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'context_session_required',
                $exception->reasonCode()
            );
        }

        self::assertSame(0, $tool->contextCalls);
    }

    public function testPersistedTenantWorksWithoutResolver(): void
    {
        $this->seeder->seed(
            'session-prebound',
            [
                'identity' => [
                    'provider' => 'test',
                    'phone_number_id' => 'phone-prebound',
                ],
                'tenant_id' => 'tenant-prebound',
            ]
        );

        $tool = new ContextualV11Tool('Prebound');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $guiche = new Guiche(
            $registry,
            $this->store
        );

        $result = $guiche->executeTool(
            'Prebound',
            [],
            'session-prebound'
        );

        self::assertSame(
            'tenant-prebound',
            $result['context_tenant_id']
        );
        self::assertSame(1, $tool->contextCalls);
    }

    public function testMissingTenantWithoutResolverFailsClosed(): void
    {
        $this->seedIdentity(
            'session-no-tenant',
            'phone-no-tenant'
        );

        $tool = new ContextualV11Tool('NoTenant');
        $registry = new ToolRegistry();
        $registry->register($tool);

        $guiche = new Guiche(
            $registry,
            $this->store
        );

        try {
            $guiche->executeTool(
                'NoTenant',
                [],
                'session-no-tenant'
            );

            self::fail('Tool sem tenant deveria ser bloqueada.');
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'tenant_context_required',
                $exception->reasonCode()
            );
        }

        self::assertSame(0, $tool->contextCalls);
    }

    public function testResolverFailureDoesNotReachTool(): void
    {
        $this->seedIdentity(
            'session-failure',
            'phone-failure'
        );

        $tool = new ContextualV11Tool('ResolverFailure');

        $resolver = new class implements TenantResolverInterface {
            /** @param array<mixed> $identity @return array<mixed> */
            public function resolve(array $identity): array
            {
                throw new RuntimeException(
                    'database secret failure'
                );
            }
        };

        $guiche = $this->guicheWith(
            $tool,
            $resolver
        );

        try {
            $guiche->executeTool(
                'ResolverFailure',
                [],
                'session-failure'
            );

            self::fail('Falha do resolver deveria bloquear.');
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'tenant_context_unavailable',
                $exception->reasonCode()
            );
            self::assertStringNotContainsString(
                'database secret failure',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $tool->contextCalls);
    }

    public function testWriteReplayStillExecutesOnce(): void
    {
        $this->seedIdentity(
            'session-write',
            'phone-write'
        );

        $tool = new ContextualV11Tool(
            'ContextualWrite'
        );

        $registry = new ToolRegistry();
        $registry->register(
            new ToolRegistration(
                $tool,
                ['operation' => 'write']
            )
        );

        $guiche = new Guiche(
            $registry,
            $this->store,
            tenantResolver: new MappingV11TenantResolver(
                ['phone-write' => 501]
            )
        );

        $first = $guiche->executeTool(
            'ContextualWrite',
            ['value' => 'same'],
            'session-write'
        );

        $second = $guiche->executeTool(
            'ContextualWrite',
            ['value' => 'same'],
            'session-write'
        );

        self::assertSame($first, $second);
        self::assertSame(1, $tool->contextCalls);
        self::assertCount(
            1,
            $this->store
                ->load('session-write')
                ['tool_history']
        );
    }

    public function testHandleReadCacheExecutesContextualToolOnce(): void
    {
        $this->seedIdentity(
            'session-cache',
            'phone-cache'
        );

        $tool = new ContextualV11Tool(
            'ContextualCache'
        );

        $registry = new ToolRegistry();
        $registry->register(
            new ToolRegistration(
                $tool,
                ['operation' => 'read']
            )
        );

        $ai = new FakeAiProvider(
            [
                [
                    'id' => 'response-1',
                    'text' => '',
                    'tool_calls' => [
                        [
                            'call_id' => 'call-1',
                            'name' => 'ContextualCache',
                            'arguments' => ['value' => 'same'],
                        ],
                        [
                            'call_id' => 'call-2',
                            'name' => 'ContextualCache',
                            'arguments' => ['value' => 'same'],
                        ],
                    ],
                    'usage' => [],
                ],
                [
                    'id' => 'response-2',
                    'text' => 'final',
                    'tool_calls' => [],
                    'usage' => [],
                ],
            ]
        );

        $resolver = new MappingV11TenantResolver(
            ['phone-cache' => 700]
        );

        $guiche = new Guiche(
            $registry,
            $this->store,
            ai: $ai,
            reusableTools: ['ContextualCache'],
            tenantResolver: $resolver
        );

        $result = $guiche->handle(
            'session-cache',
            'teste'
        );

        self::assertSame('final', $result['message']);
        self::assertSame(1, $tool->contextCalls);
        self::assertSame(1, $resolver->calls);

        $cacheHits = array_values(
            array_filter(
                $result['events'],
                static fn (array $event): bool =>
                    ($event['type'] ?? null)
                    === 'tool_cache_hit'
            )
        );

        self::assertCount(1, $cacheHits);
    }

    public function testBuilderPresenterWorksForContextAwareTool(): void
    {
        $this->seedIdentity(
            'session-presented',
            'phone-presented'
        );

        $tool = new ContextualV11Tool(
            'ContextualPresented'
        );

        $presenter = new class implements ResultPresenterInterface {
            /** @param array<mixed> $result */
            public function present(array $result): string
            {
                return 'tenant='
                    . (string) (
                        $result['context_tenant_id'] ?? ''
                    );
            }
        };

        $guiche = (new GuicheBuilder())
            ->withContextStore($this->store)
            ->withTenantResolver(
                new MappingV11TenantResolver(
                    ['phone-presented' => 808]
                )
            )
            ->registerTool($tool)
            ->registerPresenter(
                'ContextualPresented',
                $presenter
            )
            ->build();

        $result = $guiche->executeTool(
            'ContextualPresented',
            [],
            'session-presented'
        );

        self::assertSame(
            'tenant=808',
            $result['_guiche']['presentation_message']
        );
        self::assertSame(1, $tool->contextCalls);
        self::assertSame(0, $tool->legacyCalls);
    }

    public function testBuilderLegacyPresenterStillNeedsNoTenant(): void
    {
        $tool = new LegacyV11Tool(
            'LegacyPresented'
        );

        $presenter = new class implements ResultPresenterInterface {
            /** @param array<mixed> $result */
            public function present(array $result): string
            {
                return 'legacy-presented';
            }
        };

        $guiche = (new GuicheBuilder())
            ->withContextStore($this->store)
            ->registerTool($tool)
            ->registerPresenter(
                'LegacyPresented',
                $presenter
            )
            ->build();

        $result = $guiche->executeTool(
            'LegacyPresented',
            []
        );

        self::assertSame(
            'legacy-presented',
            $result['_guiche']['presentation_message']
        );
        self::assertSame(1, $tool->calls);
    }

    public function testSessionsRemainTenantIsolated(): void
    {
        $this->seedIdentity('session-a2', 'phone-a');
        $this->seedIdentity('session-b2', 'phone-b');

        $tool = new ContextualV11Tool(
            'TenantIsolation'
        );

        $guiche = $this->guicheWith(
            $tool,
            new MappingV11TenantResolver(
                [
                    'phone-a' => 10,
                    'phone-b' => 20,
                ]
            )
        );

        $a = $guiche->executeTool(
            'TenantIsolation',
            [],
            'session-a2'
        );

        $b = $guiche->executeTool(
            'TenantIsolation',
            [],
            'session-b2'
        );

        self::assertSame(10, $a['context_tenant_id']);
        self::assertSame(20, $b['context_tenant_id']);
    }

    private function guicheWith(
        ToolInterface $tool,
        TenantResolverInterface $resolver
    ): Guiche {
        $registry = new ToolRegistry();
        $registry->register($tool);

        return new Guiche(
            $registry,
            $this->store,
            tenantResolver: $resolver
        );
    }

    private function seedIdentity(
        string $sessionId,
        string $phoneNumberId
    ): void {
        $this->seeder->seed(
            $sessionId,
            [
                'identity' => [
                    'provider' => 'test',
                    'phone_number_id' => $phoneNumberId,
                ],
            ]
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

            $path = $directory
                . DIRECTORY_SEPARATOR
                . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}

final class LegacyV11Tool implements ToolInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $toolName
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Legacy test tool';
    }

    /** @return array<mixed> */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    /** @param array<mixed> $arguments @return array<mixed> */
    public function execute(array $arguments): array
    {
        $this->calls++;

        return [
            'success' => true,
            'mode' => 'legacy',
            'arguments' => $arguments,
        ];
    }
}

final class ContextualV11Tool implements ContextAwareToolInterface
{
    public int $legacyCalls = 0;
    public int $contextCalls = 0;

    public function __construct(
        private readonly string $toolName
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Contextual test tool';
    }

    /** @return array<mixed> */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tenant_id' => ['type' => 'integer'],
                'value' => ['type' => 'string'],
            ],
        ];
    }

    /** @param array<mixed> $arguments @return array<mixed> */
    public function execute(array $arguments): array
    {
        $this->legacyCalls++;

        return [
            'success' => false,
            'mode' => 'legacy-unexpected',
        ];
    }

    /** @param array<mixed> $arguments @return array<mixed> */
    public function executeWithContext(
        array $arguments,
        ToolExecutionContext $context
    ): array {
        $this->contextCalls++;

        return [
            'success' => true,
            'context_tenant_id' => $context->tenantId(),
            'session_id' => $context->sessionId(),
            'argument_tenant_id' =>
                $arguments['tenant_id'] ?? null,
            'value' => $arguments['value'] ?? null,
        ];
    }
}

final class MappingV11TenantResolver implements TenantResolverInterface
{
    public int $calls = 0;

    /** @param array<string, int|string> $mapping */
    public function __construct(
        private readonly array $mapping
    ) {
    }

    /** @param array<mixed> $identity @return array<mixed> */
    public function resolve(array $identity): array
    {
        $this->calls++;

        $phone = (string) (
            $identity['phone_number_id'] ?? ''
        );

        if (!array_key_exists($phone, $this->mapping)) {
            return [];
        }

        return [
            'tenant_id' => $this->mapping[$phone],
        ];
    }
}
