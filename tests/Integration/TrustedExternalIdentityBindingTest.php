<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TrustedExternalIdentityBindingTest extends TestCase
{
    private string $directory;
    private ContextStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v13-trusted-identity-'
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

    public function testBindIdentityPersistsTrustedExternalIdentity(): void
    {
        $guiche = $this->guiche();
        $identity = $this->chatwootIdentity(101, 7, 9001);

        $context = $guiche->bindIdentity(
            'chatwoot:101:9001',
            $identity
        );

        self::assertInstanceOf(
            ToolExecutionContext::class,
            $context
        );
        self::assertSame(
            'chatwoot:101:9001',
            $context->sessionId()
        );
        self::assertSame(
            $identity,
            $context->get('identity')
        );
        self::assertNull($context->tenantId());
        self::assertSame(
            $identity,
            $this->store
                ->load('chatwoot:101:9001')
                ['execution_context']
                ['identity']
        );
    }

    public function testSameIdentityIsIdempotentRegardlessOfKeyOrder(): void
    {
        $guiche = $this->guiche();

        $first = $guiche->bindIdentity(
            'chatwoot:101:9002',
            [
                'provider' => 'chatwoot',
                'account_id' => 101,
                'inbox_id' => 7,
                'conversation_id' => 9002,
            ]
        );

        $second = $guiche->bindIdentity(
            'chatwoot:101:9002',
            [
                'conversation_id' => 9002,
                'inbox_id' => 7,
                'account_id' => 101,
                'provider' => 'chatwoot',
            ]
        );

        self::assertSame($first->all(), $second->all());
    }

    public function testConflictingIdentityCannotReplaceEstablishedBinding(): void
    {
        $guiche = $this->guiche();
        $sessionId = 'chatwoot:101:9003';
        $original = $this->chatwootIdentity(101, 7, 9003);

        $guiche->bindIdentity($sessionId, $original);

        try {
            $guiche->bindIdentity(
                $sessionId,
                $this->chatwootIdentity(999, 7, 9003)
            );

            self::fail(
                'Troca de identidade deveria ter sido bloqueada.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'já está vinculado',
                $exception->getMessage()
            );
        }

        self::assertSame(
            $original,
            $this->store
                ->load($sessionId)
                ['execution_context']
                ['identity']
        );
    }

    public function testConfiguredTenantResolverRunsFromTrustedIdentityOnly(): void
    {
        $resolver = new class implements TenantResolverInterface {
            public int $calls = 0;

            /** @var array<mixed> */
            public array $received = [];

            public function resolve(array $identity): array
            {
                $this->calls++;
                $this->received = $identity;

                return [
                    'tenant_id' => 142,
                    'should_not_be_promoted' => 'internal',
                ];
            }
        };

        $sessionId = 'chatwoot:101:9004';
        $identity = $this->chatwootIdentity(101, 7, 9004);

        $this->store->mergeState(
            $sessionId,
            ['forged_tenant_id' => 999]
        );
        $this->store->addMessage(
            $sessionId,
            'user',
            'tenant_id=999'
        );

        $guiche = $this->guiche($resolver);
        $context = $guiche->bindIdentity(
            $sessionId,
            $identity
        );

        self::assertSame(1, $resolver->calls);
        self::assertSame($identity, $resolver->received);
        self::assertSame(142, $context->tenantId());
        self::assertFalse(
            $context->has('should_not_be_promoted')
        );

        $stored = $this->store->load($sessionId);

        self::assertSame(
            142,
            $stored['execution_context']['tenant_id']
        );
        self::assertArrayNotHasKey(
            'should_not_be_promoted',
            $stored['execution_context']
        );
    }

    public function testResolvedTenantMakesRepeatedBindingResolverIdempotent(): void
    {
        $resolver = new class implements TenantResolverInterface {
            public int $calls = 0;

            public function resolve(array $identity): array
            {
                $this->calls++;

                return ['tenant_id' => 142];
            }
        };

        $guiche = $this->guiche($resolver);
        $identity = $this->chatwootIdentity(101, 7, 9005);

        $first = $guiche->bindIdentity(
            'chatwoot:101:9005',
            $identity
        );
        $second = $guiche->bindIdentity(
            'chatwoot:101:9005',
            $identity
        );

        self::assertSame(142, $first->tenantId());
        self::assertSame(142, $second->tenantId());
        self::assertSame(1, $resolver->calls);
    }

    public function testDifferentSessionsKeepTrustedIdentityAndTenantIsolated(): void
    {
        $resolver = new class implements TenantResolverInterface {
            public function resolve(array $identity): array
            {
                return [
                    'tenant_id' => match ($identity['account_id'] ?? null) {
                        101 => 142,
                        202 => 284,
                        default => throw new RuntimeException(
                            'Conta não reconhecida.'
                        ),
                    },
                ];
            }
        };

        $guiche = $this->guiche($resolver);

        $a = $guiche->bindIdentity(
            'chatwoot:101:9101',
            $this->chatwootIdentity(101, 7, 9101)
        );
        $b = $guiche->bindIdentity(
            'chatwoot:202:9202',
            $this->chatwootIdentity(202, 8, 9202)
        );

        self::assertSame(142, $a->tenantId());
        self::assertSame(284, $b->tenantId());
        self::assertSame(
            101,
            $this->store
                ->load('chatwoot:101:9101')
                ['execution_context']
                ['identity']
                ['account_id']
        );
        self::assertSame(
            202,
            $this->store
                ->load('chatwoot:202:9202')
                ['execution_context']
                ['identity']
                ['account_id']
        );
    }

    private function guiche(
        ?TenantResolverInterface $resolver = null
    ): Guiche {
        return new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            tenantResolver: $resolver
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function chatwootIdentity(
        int $accountId,
        int $inboxId,
        int $conversationId
    ): array {
        return [
            'provider' => 'chatwoot',
            'account_id' => $accountId,
            'inbox_id' => $inboxId,
            'conversation_id' => $conversationId,
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
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
