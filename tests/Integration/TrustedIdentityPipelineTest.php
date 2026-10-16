<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use Asmpkg\Guiche\ToolRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class TrustedIdentityPipelineTest extends TestCase
{
    private string $directory;
    private ContextStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v13-trusted-pipeline-'
            . bin2hex(random_bytes(8));

        $this->store = new ContextStore(
            $this->directory,
            'America/Manaus'
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testResolverReceivesOnlyTrustedIdentityAndExtrasAreDiscarded(): void
    {
        $received = null;

        $guiche = $this->guiche(
            static function (array $identity) use (&$received): array {
                $received = $identity;

                return [
                    'tenant_id' => 142,
                    'role' => 'admin',
                    'secret' => 'must-not-persist',
                ];
            }
        );

        $identity = [
            'provider' => 'chatwoot',
            'account_id' => 10,
            'inbox_id' => 20,
            'conversation_id' => 30,
            'metadata' => [
                'region' => 'br',
                'channel' => 'whatsapp',
            ],
        ];

        $context = $guiche->bindIdentity(
            'pipeline-extras',
            $identity
        );

        self::assertSame($identity, $received);
        self::assertSame(142, $context->tenantId());

        $persisted = $this->store
            ->load('pipeline-extras')
            ['execution_context'];

        self::assertSame($identity, $persisted['identity']);
        self::assertSame(142, $persisted['tenant_id']);
        self::assertArrayNotHasKey('role', $persisted);
        self::assertArrayNotHasKey('secret', $persisted);
    }

    public function testIdentityRejectsObjectBeforeResolver(): void
    {
        $calls = 0;

        $guiche = $this->guiche(
            static function (array $identity) use (&$calls): array {
                $calls++;

                return ['tenant_id' => 1];
            }
        );

        try {
            $guiche->bindIdentity(
                'pipeline-object',
                [
                    'provider' => 'custom',
                    'payload' => new stdClass(),
                ]
            );

            self::fail('Objeto dentro da identity deveria falhar.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'valor não serializável',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $calls);
    }

    public function testIdentityRejectsNonFiniteFloatBeforeResolver(): void
    {
        $calls = 0;

        $guiche = $this->guiche(
            static function (array $identity) use (&$calls): array {
                $calls++;

                return ['tenant_id' => 1];
            }
        );

        try {
            $guiche->bindIdentity(
                'pipeline-float',
                [
                    'provider' => 'custom',
                    'score' => INF,
                ]
            );

            self::fail('Float não finito deveria falhar.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'float não finito',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $calls);
    }

    public function testResolverFailureHasSafeTopLevelMessageAndCanRetry(): void
    {
        $calls = 0;

        $guiche = $this->guiche(
            static function (array $identity) use (&$calls): array {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException(
                        'database_password=super-secret'
                    );
                }

                return ['tenant_id' => 321];
            }
        );

        $identity = [
            'provider' => 'chatwoot',
            'account_id' => 55,
            'inbox_id' => 66,
            'conversation_id' => 77,
        ];

        try {
            $guiche->bindIdentity(
                'pipeline-retry',
                $identity
            );

            self::fail('Primeira resolução deveria falhar.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Falha ao resolver tenant',
                $exception->getMessage()
            );
            self::assertStringNotContainsString(
                'super-secret',
                $exception->getMessage()
            );
        }

        $afterFailure = $this->store
            ->load('pipeline-retry')
            ['execution_context'];

        self::assertSame($identity, $afterFailure['identity']);
        self::assertArrayNotHasKey(
            'tenant_id',
            $afterFailure
        );

        $context = $guiche->bindIdentity(
            'pipeline-retry',
            $identity
        );

        self::assertSame(321, $context->tenantId());
        self::assertSame(2, $calls);
    }

    public function testTenantStringIsNormalizedBeforePersistence(): void
    {
        $guiche = $this->guiche(
            static fn (array $identity): array => [
                'tenant_id' => '  tenant-abc  ',
            ]
        );

        $context = $guiche->bindIdentity(
            'pipeline-tenant-string',
            [
                'provider' => 'custom',
                'external_id' => 'abc',
            ]
        );

        self::assertSame(
            'tenant-abc',
            $context->tenantId()
        );

        self::assertSame(
            'tenant-abc',
            $this->store
                ->load('pipeline-tenant-string')
                ['execution_context']
                ['tenant_id']
        );
    }

    public function testAiStyleArgumentCannotOverrideTenantDeliveredToTool(): void
    {
        $tool = new TrustedPipelineContextTool();
        $registry = new ToolRegistry();
        $registry->register($tool);

        $guiche = new Guiche(
            tools: $registry,
            context: $this->store,
            tenantResolver: new CallbackTenantResolver(
                static fn (array $identity): array => [
                    'tenant_id' => 142,
                ]
            )
        );

        $identity = [
            'provider' => 'chatwoot',
            'account_id' => 1,
            'inbox_id' => 2,
            'conversation_id' => 3,
        ];

        $bound = $guiche->bindIdentity(
            'pipeline-tool',
            $identity
        );

        self::assertSame(142, $bound->tenantId());

        $result = $guiche->executeTool(
            'TrustedPipelineContext',
            ['tenant_id' => 999],
            'pipeline-tool'
        );

        self::assertSame(
            142,
            $result['context_tenant_id']
        );
        self::assertSame(
            999,
            $result['argument_tenant_id']
        );
        self::assertSame(
            $identity,
            $result['identity']
        );
        self::assertSame(
            'pipeline-tool',
            $result['session_id']
        );
        self::assertSame(1, $tool->contextCalls);
        self::assertSame(0, $tool->legacyCalls);
    }

    private function guiche(callable $resolver): Guiche
    {
        return new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            tenantResolver: new CallbackTenantResolver(
                $resolver
            )
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->removeTree($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}

final class TrustedPipelineContextTool implements ContextAwareToolInterface
{
    public int $legacyCalls = 0;
    public int $contextCalls = 0;

    public function name(): string
    {
        return 'TrustedPipelineContext';
    }

    public function description(): string
    {
        return 'Tool contextual para teste do pipeline confiável.';
    }

    /** @return array<mixed> */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tenant_id' => [
                    'type' => 'integer',
                ],
            ],
        ];
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function execute(array $arguments): array
    {
        $this->legacyCalls++;

        return [
            'success' => false,
            'mode' => 'legacy',
        ];
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function executeWithContext(
        array $arguments,
        ToolExecutionContext $context
    ): array {
        $this->contextCalls++;

        return [
            'success' => true,
            'context_tenant_id' => $context->tenantId(),
            'argument_tenant_id' => $arguments['tenant_id'] ?? null,
            'identity' => $context->get('identity'),
            'session_id' => $context->sessionId(),
        ];
    }
}
