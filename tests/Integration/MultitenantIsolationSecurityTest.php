<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use GuicheExample\ClinicaPiloto\Tools\DisponibilidadeConveniosTool;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class MultitenantIsolationSecurityTest extends TestCase
{
    public function testSessionsResolveAndExecuteOnlyTheirOwnTenant(): void
    {
        $store = $this->store();
        $seeder = new ExecutionContextSeeder($store);
        $tool = new IsolationContextTool();

        $seeder->seed(
            'session-a',
            ['identity' => $this->identity('clinic-a')]
        );
        $seeder->seed(
            'session-b',
            ['identity' => $this->identity('clinic-b')]
        );

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withTenantResolver(
                $this->resolver([
                    'clinic-a' => 101,
                    'clinic-b' => 202,
                ])
            )
            ->registerTool(
                new ToolRegistration(
                    $tool,
                    ['operation' => 'read']
                )
            )
            ->build();

        $a = $guiche->executeTool(
            $tool->name(),
            [
                'value' => 'same-request',
                'tenant_id' => 999,
            ],
            'session-a'
        );

        $b = $guiche->executeTool(
            $tool->name(),
            [
                'value' => 'same-request',
                'tenant_id' => 999,
            ],
            'session-b'
        );

        self::assertSame(101, $a['tenant_id']);
        self::assertSame(202, $b['tenant_id']);
        self::assertSame(999, $a['argument_tenant_id']);
        self::assertSame(999, $b['argument_tenant_id']);

        self::assertSame(
            [101, 202],
            $tool->executedTenants
        );

        self::assertSame(
            101,
            $store->load(
                'session-a'
            )['execution_context']['tenant_id'] ?? null
        );

        self::assertSame(
            202,
            $store->load(
                'session-b'
            )['execution_context']['tenant_id'] ?? null
        );
    }

    public function testIdentityCannotSwitchAfterBinding(): void
    {
        $store = $this->store();
        $seeder = new ExecutionContextSeeder($store);

        $seeder->seed(
            'identity-lock',
            ['identity' => $this->identity('clinic-a')]
        );

        $blocked = false;

        try {
            $seeder->seed(
                'identity-lock',
                ['identity' => $this->identity('clinic-b')]
            );
        } catch (Throwable) {
            $blocked = true;
        }

        self::assertTrue($blocked);

        self::assertSame(
            'clinic-a',
            $store->load(
                'identity-lock'
            )['execution_context']['identity']['tenant_key'] ?? null
        );
    }

    public function testTenantCannotSwitchAfterResolution(): void
    {
        $store = $this->store();
        $seeder = new ExecutionContextSeeder($store);
        $tool = new IsolationContextTool();

        $seeder->seed(
            'tenant-lock',
            ['identity' => $this->identity('clinic-a')]
        );

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withTenantResolver(
                $this->resolver(['clinic-a' => 101])
            )
            ->registerTool($tool)
            ->build();

        $guiche->executeTool(
            $tool->name(),
            ['value' => 'resolve'],
            'tenant-lock'
        );

        $blocked = false;

        try {
            $seeder->seed(
                'tenant-lock',
                [
                    'identity' => $this->identity('clinic-a'),
                    'tenant_id' => 202,
                ]
            );
        } catch (Throwable) {
            $blocked = true;
        }

        self::assertTrue($blocked);

        self::assertSame(
            101,
            $store->load(
                'tenant-lock'
            )['execution_context']['tenant_id'] ?? null
        );
    }

    public function testContextAwareToolWithoutSessionHasNoSideEffect(): void
    {
        $tool = new IsolationContextTool();

        $guiche = (new GuicheBuilder())
            ->withContextStore($this->store())
            ->registerTool($tool)
            ->build();

        try {
            $guiche->executeTool(
                $tool->name(),
                ['value' => 'blocked']
            );

            self::fail('ContextAware Tool sem sessão deveria bloquear.');
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'context_session_required',
                $exception->reasonCode()
            );
        }

        self::assertSame(0, $tool->contextCalls);
    }

    public function testMissingTenantBlocksWriteBeforeSideEffect(): void
    {
        $store = $this->store();
        $seeder = new ExecutionContextSeeder($store);
        $tool = new IsolationContextTool();

        $seeder->seed(
            'write-no-tenant',
            ['identity' => $this->identity('clinic-a')]
        );

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->registerTool(
                new ToolRegistration(
                    $tool,
                    ['operation' => 'write']
                )
            )
            ->build();

        try {
            $guiche->executeTool(
                $tool->name(),
                ['value' => 'would-write'],
                'write-no-tenant'
            );

            self::fail('WRITE sem tenant deveria bloquear.');
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'tenant_context_required',
                $exception->reasonCode()
            );
        }

        self::assertSame(0, $tool->contextCalls);
    }

    public function testResolverFailureFailsClosedWithoutSecretLeak(): void
    {
        $store = $this->store();
        $seeder = new ExecutionContextSeeder($store);
        $tool = new IsolationContextTool();

        $seeder->seed(
            'resolver-failure',
            ['identity' => $this->identity('clinic-a')]
        );

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withTenantResolver(
                new CallbackTenantResolver(
                    static function (array $identity): array {
                        throw new RuntimeException(
                            'SECRET-TENANT-LOOKUP'
                        );
                    }
                )
            )
            ->registerTool($tool)
            ->build();

        try {
            $guiche->executeTool(
                $tool->name(),
                ['value' => 'blocked'],
                'resolver-failure'
            );

            self::fail('Resolver com falha deveria bloquear.');
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'tenant_context_unavailable',
                $exception->reasonCode()
            );

            self::assertStringNotContainsString(
                'SECRET-TENANT-LOOKUP',
                json_encode(
                    $exception->toArray(),
                    JSON_THROW_ON_ERROR
                )
            );
        }

        self::assertSame(0, $tool->contextCalls);
    }

    public function testLegacyToolRemainsSessionlessAndDoesNotResolveTenant(): void
    {
        $resolverCalls = 0;
        $tool = new IsolationLegacyTool();

        $guiche = (new GuicheBuilder())
            ->withContextStore($this->store())
            ->withTenantResolver(
                new CallbackTenantResolver(
                    static function (array $identity) use (
                        &$resolverCalls
                    ): array {
                        $resolverCalls++;

                        throw new RuntimeException(
                            'resolver não deveria ser chamado'
                        );
                    }
                )
            )
            ->registerTool($tool)
            ->build();

        $result = $guiche->executeTool(
            $tool->name(),
            ['value' => 'legacy']
        );

        self::assertTrue($result['success']);
        self::assertSame('legacy', $result['value']);
        self::assertSame(1, $tool->calls);
        self::assertSame(0, $resolverCalls);
    }

    public function testClinicalRuntimeRejectsForgedEmpresaIdBeforeDatabase(): void
    {
        $store = $this->store();
        $seeder = new ExecutionContextSeeder($store);
        $sql = new IsolationSqlProbe();

        $seeder->seed(
            'clinical-forged',
            ['identity' => $this->identity('clinic-a')]
        );

        $tool = new DisponibilidadeConveniosTool(
            $sql,
            'profissionais_por_tratamento'
        );

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withTenantResolver(
                $this->resolver(['clinic-a' => 101])
            )
            ->registerTool($tool)
            ->build();

        try {
            $guiche->executeTool(
                $tool->name(),
                [
                    'empresa_id' => 999,
                    'tratamento_nome' => 'Limpeza',
                    'convenio_nome' => 'Dental Plus',
                ],
                'clinical-forged'
            );

            self::fail('empresa_id forjado deveria ser rejeitado.');
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'invalid_arguments',
                $exception->reasonCode()
            );
        }

        self::assertSame(0, $sql->fetchCalls);
    }

    public function testClinicalSameToolUsesTenantFromEachSession(): void
    {
        $store = $this->store();
        $seeder = new ExecutionContextSeeder($store);
        $sql = new IsolationSqlProbe();

        $seeder->seed(
            'clinical-a',
            ['identity' => $this->identity('clinic-a')]
        );
        $seeder->seed(
            'clinical-b',
            ['identity' => $this->identity('clinic-b')]
        );

        $tool = new DisponibilidadeConveniosTool(
            $sql,
            'profissionais_por_tratamento'
        );

        $guiche = (new GuicheBuilder())
            ->withContextStore($store)
            ->withTenantResolver(
                $this->resolver([
                    'clinic-a' => 101,
                    'clinic-b' => 202,
                ])
            )
            ->registerTool($tool)
            ->build();

        $arguments = [
            'tratamento_nome' => 'Limpeza',
            'convenio_nome' => 'Dental Plus',
        ];

        $guiche->executeTool(
            $tool->name(),
            $arguments,
            'clinical-a'
        );

        $guiche->executeTool(
            $tool->name(),
            $arguments,
            'clinical-b'
        );

        self::assertSame(2, $sql->fetchCalls);
        self::assertSame(101, $sql->parameters[0][0]);
        self::assertSame(202, $sql->parameters[1][0]);
    }

    private function store(): ContextStore
    {
        return new ContextStore(
            sys_get_temp_dir()
                . '/guiche-v11-isolation-'
                . bin2hex(random_bytes(6)),
            'America/Manaus'
        );
    }

    /**
     * @return array{provider:string, tenant_key:string}
     */
    private function identity(string $key): array
    {
        return [
            'provider' => 'test',
            'tenant_key' => $key,
        ];
    }

    /**
     * @param array<string, int> $map
     */
    private function resolver(
        array $map
    ): CallbackTenantResolver {
        return new CallbackTenantResolver(
            static function (array $identity) use ($map): array {
                $key = trim(
                    (string)($identity['tenant_key'] ?? '')
                );

                if (
                    $key === ''
                    || !array_key_exists($key, $map)
                ) {
                    return [];
                }

                return [
                    'tenant_id' => $map[$key],
                ];
            }
        );
    }
}

final class IsolationContextTool implements ContextAwareToolInterface
{
    public int $contextCalls = 0;

    /** @var list<int|string|null> */
    public array $executedTenants = [];

    public function name(): string
    {
        return 'IsolationContextTool';
    }

    public function description(): string
    {
        return 'Probe contextual usado em testes de isolamento.';
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
                'tenant_id' => [
                    'type' => 'integer',
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
        return [
            'success' => false,
            'legacy_path' => true,
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
        $this->executedTenants[] =
            $context->tenantId();

        return [
            'success' => true,
            'tenant_id' => $context->tenantId(),
            'argument_tenant_id' =>
                $arguments['tenant_id'] ?? null,
            'value' => $arguments['value'] ?? null,
        ];
    }
}

final class IsolationLegacyTool implements ToolInterface
{
    public int $calls = 0;

    public function name(): string
    {
        return 'IsolationLegacyTool';
    }

    public function description(): string
    {
        return 'Probe legado usado em regressão v1.1.';
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
        $this->calls++;

        return [
            'success' => true,
            'value' => (string)($arguments['value'] ?? ''),
        ];
    }
}

final class IsolationSqlProbe implements SqlExecutorInterface
{
    public int $fetchCalls = 0;

    /** @var list<array<mixed>> */
    public array $parameters = [];

    public function fetchAll(
        string $sql,
        array $parameters = []
    ): array {
        $this->fetchCalls++;
        $this->parameters[] = $parameters;

        return [];
    }

    public function execute(
        string $sql,
        array $parameters = []
    ): int {
        return 1;
    }
}
