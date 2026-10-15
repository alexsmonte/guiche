<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class ExecutionContextSeederTest extends TestCase
{
    private string $directory;
    private ContextStore $store;
    private ExecutionContextSeeder $seeder;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v11-execution-context-'
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

    public function testNewSessionStartsWithEmptyExecutionContext(): void
    {
        $context = $this->store->load('session-new');

        self::assertSame(
            [],
            $context['execution_context']
        );
    }

    public function testSeederPersistsTrustedIdentityPerSession(): void
    {
        $toolContext = $this->seeder->seed(
            'session-a',
            [
                'identity' => [
                    'provider' => 'whatsapp',
                    'phone_number_id' => 'phone-a',
                ],
            ]
        );

        self::assertInstanceOf(
            ToolExecutionContext::class,
            $toolContext
        );
        self::assertSame(
            'session-a',
            $toolContext->sessionId()
        );
        $identity = $toolContext->get('identity');

        self::assertIsArray($identity);
        self::assertSame(
            'phone-a',
            $identity['phone_number_id']
        );
        self::assertSame(
            'phone-a',
            $this->store
                ->load('session-a')
                ['execution_context']
                ['identity']
                ['phone_number_id']
        );
    }

    public function testSameIdentityIsIdempotentRegardlessOfKeyOrder(): void
    {
        $first = $this->seeder->seed(
            'session-order',
            [
                'identity' => [
                    'provider' => 'whatsapp',
                    'phone_number_id' => 'phone-order',
                ],
            ]
        );

        $second = $this->seeder->seed(
            'session-order',
            [
                'identity' => [
                    'phone_number_id' => 'phone-order',
                    'provider' => 'whatsapp',
                ],
            ]
        );

        self::assertSame(
            $first->all(),
            $second->all()
        );
    }

    public function testSessionCanBeEnrichedWithTenantOnlyOnce(): void
    {
        $identity = [
            'identity' => [
                'provider' => 'whatsapp',
                'phone_number_id' => 'phone-tenant',
            ],
        ];

        $this->seeder->seed(
            'session-tenant',
            $identity
        );

        $resolved = $this->seeder->seed(
            'session-tenant',
            $identity + ['tenant_id' => 142]
        );

        self::assertSame(
            142,
            $resolved->tenantId()
        );

        $again = $this->seeder->seed(
            'session-tenant',
            $identity
        );

        self::assertSame(
            142,
            $again->tenantId()
        );
    }

    public function testIdentityCannotSwitchInsideEstablishedSession(): void
    {
        $this->seeder->seed(
            'session-locked',
            [
                'identity' => [
                    'provider' => 'whatsapp',
                    'phone_number_id' => 'phone-a',
                ],
            ]
        );

        try {
            $this->seeder->seed(
                'session-locked',
                [
                    'identity' => [
                        'provider' => 'whatsapp',
                        'phone_number_id' => 'phone-b',
                    ],
                ]
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
            'phone-a',
            $this->store
                ->load('session-locked')
                ['execution_context']
                ['identity']
                ['phone_number_id']
        );
    }

    public function testTenantCannotSwitchOnceBound(): void
    {
        $values = [
            'identity' => [
                'provider' => 'whatsapp',
                'phone_number_id' => 'phone-stable',
            ],
            'tenant_id' => 142,
        ];

        $this->seeder->seed(
            'session-stable-tenant',
            $values
        );

        try {
            $this->seeder->seed(
                'session-stable-tenant',
                [
                    'identity' => $values['identity'],
                    'tenant_id' => 999,
                ]
            );

            self::fail(
                'Troca de tenant deveria ter sido bloqueada.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'já está vinculado',
                $exception->getMessage()
            );
        }

        self::assertSame(
            142,
            $this->store
                ->load('session-stable-tenant')
                ['execution_context']
                ['tenant_id']
        );
    }

    public function testContextStoreSaveCannotBypassExistingBinding(): void
    {
        $this->seeder->seed(
            'session-direct-save',
            [
                'identity' => [
                    'provider' => 'whatsapp',
                    'phone_number_id' => 'phone-safe',
                ],
                'tenant_id' => 142,
            ]
        );

        $context = $this->store->load(
            'session-direct-save'
        );

        unset($context['execution_context']);

        $this->store->save(
            'session-direct-save',
            $context
        );

        $preserved = $this->store->load(
            'session-direct-save'
        );

        self::assertSame(
            142,
            $preserved['execution_context']['tenant_id']
        );

        $preserved['execution_context']['tenant_id'] = 999;

        try {
            $this->store->save(
                'session-direct-save',
                $preserved
            );

            self::fail(
                'ContextStore::save não pode trocar binding existente.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'já está vinculado',
                $exception->getMessage()
            );
        }

        self::assertSame(
            142,
            $this->store
                ->load('session-direct-save')
                ['execution_context']
                ['tenant_id']
        );
    }

    public function testDifferentSessionsRemainIsolated(): void
    {
        $a = $this->seeder->seed(
            'session-a-isolated',
            [
                'identity' => [
                    'provider' => 'whatsapp',
                    'phone_number_id' => 'phone-a',
                ],
                'tenant_id' => 10,
            ]
        );

        $b = $this->seeder->seed(
            'session-b-isolated',
            [
                'identity' => [
                    'provider' => 'whatsapp',
                    'phone_number_id' => 'phone-b',
                ],
                'tenant_id' => 20,
            ]
        );

        self::assertSame(10, $a->tenantId());
        self::assertSame(20, $b->tenantId());
        self::assertSame(
            'phone-a',
            $this->store
                ->load('session-a-isolated')
                ['execution_context']
                ['identity']
                ['phone_number_id']
        );
        self::assertSame(
            'phone-b',
            $this->store
                ->load('session-b-isolated')
                ['execution_context']
                ['identity']
                ['phone_number_id']
        );
    }

    public function testLegacyContextWithoutExecutionContextIsCompatible(): void
    {
        $sessionId = 'legacy-no-execution-context';

        file_put_contents(
            $this->contextFile($sessionId),
            json_encode(
                [
                    'schema_version' => 1,
                    'session_id' => $sessionId,
                    'intent' => 'legacy',
                    'state' => ['keep' => true],
                    'messages' => [],
                    'tool_history' => [],
                    'usage' => [],
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );

        $loaded = $this->store->load($sessionId);

        self::assertSame(
            [],
            $loaded['execution_context']
        );
        self::assertTrue(
            $loaded['state']['keep']
        );

        $seeded = $this->seeder->seed(
            $sessionId,
            [
                'identity' => [
                    'provider' => 'legacy-adapter',
                    'account_id' => 'account-1',
                ],
            ]
        );

        $identity = $seeded->get('identity');

        self::assertIsArray($identity);
        self::assertSame(
            'account-1',
            $identity['account_id']
        );
        self::assertTrue(
            $this->store
                ->load($sessionId)
                ['state']
                ['keep']
        );
    }

    public function testMalformedPersistedExecutionContextFailsClosed(): void
    {
        $sessionId = 'malformed-execution-context';

        file_put_contents(
            $this->contextFile($sessionId),
            json_encode(
                [
                    'schema_version' => 1,
                    'session_id' => $sessionId,
                    'execution_context' => 'forged',
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'execution_context inválido/corrompido'
        );

        $this->store->load($sessionId);
    }

    public function testSeederRejectsAmbiguousInputShape(): void
    {
        try {
            $this->seeder->seed(
                'session-invalid',
                []
            );

            self::fail(
                'Seeder deveria exigir identity.'
            );
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'identity',
                $exception->getMessage()
            );
        }

        try {
            $this->seeder->seed(
                'session-invalid',
                [
                    'identity' => [
                        'provider' => 'whatsapp',
                    ],
                    'tenant_id' => '',
                ]
            );

            self::fail(
                'tenant_id vazio deveria ser rejeitado.'
            );
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'tenant_id',
                $exception->getMessage()
            );
        }
    }

    public function testSeederDependsOnExistingContextStoreContract(): void
    {
        $constructor = (
            new ReflectionClass(ExecutionContextSeeder::class)
        )->getConstructor();

        self::assertNotNull($constructor);

        $parameters = $constructor->getParameters();

        self::assertCount(1, $parameters);
        $type = $parameters[0]->getType();

        self::assertInstanceOf(
            ReflectionNamedType::class,
            $type
        );
        self::assertSame(
            ContextStoreInterface::class,
            $type->getName()
        );
    }

    private function contextFile(string $sessionId): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $sessionId)
            . '.json';
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
