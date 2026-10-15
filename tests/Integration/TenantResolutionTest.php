<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use Asmpkg\Guiche\Tenant\HttpTenantResolver;
use Asmpkg\Guiche\Tenant\SqlTenantResolver;
use Asmpkg\Guiche\Tenant\TenantResolutionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TenantResolutionTest extends TestCase
{
    private string $directory;
    private ContextStore $store;
    private ExecutionContextSeeder $seeder;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v11-tenant-resolution-'
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

    public function testServiceResolvesIdentityAndPersistsTenant(): void
    {
        $this->seedIdentity(
            'session-a',
            'phone-a'
        );

        $receivedIdentity = null;

        $resolver = new CallbackTenantResolver(
            static function (array $identity) use (
                &$receivedIdentity
            ): array {
                $receivedIdentity = $identity;

                return [
                    'tenant_id' => 142,
                    'secret_from_repository' => 'must-not-persist',
                ];
            }
        );

        $service = new TenantResolutionService(
            $this->store,
            $resolver,
            $this->seeder
        );

        $context = $service->resolve('session-a');

        self::assertSame(
            [
                'provider' => 'whatsapp',
                'phone_number_id' => 'phone-a',
            ],
            $receivedIdentity
        );
        self::assertSame(142, $context->tenantId());

        $persisted = $this->store
            ->load('session-a')
            ['execution_context'];

        self::assertSame(142, $persisted['tenant_id']);
        self::assertArrayNotHasKey(
            'secret_from_repository',
            $persisted
        );
    }

    public function testServiceDoesNotResolveAgainAfterTenantBinding(): void
    {
        $this->seeder->seed(
            'session-cached',
            [
                'identity' => [
                    'provider' => 'whatsapp',
                    'phone_number_id' => 'phone-cached',
                ],
                'tenant_id' => 77,
            ]
        );

        $calls = 0;

        $resolver = new CallbackTenantResolver(
            static function (array $identity) use (&$calls): array {
                $calls++;

                return ['tenant_id' => 999];
            }
        );

        $service = new TenantResolutionService(
            $this->store,
            $resolver
        );

        $context = $service->resolve(
            'session-cached'
        );

        self::assertSame(77, $context->tenantId());
        self::assertSame(0, $calls);
    }

    public function testMissingIdentityFailsBeforeResolver(): void
    {
        $calls = 0;

        $resolver = new CallbackTenantResolver(
            static function (array $identity) use (&$calls): array {
                $calls++;

                return ['tenant_id' => 1];
            }
        );

        $service = new TenantResolutionService(
            $this->store,
            $resolver
        );

        try {
            $service->resolve('session-without-identity');

            self::fail(
                'Sessão sem identity deveria falhar fechada.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Identidade confiável ausente',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $calls);
    }

    public function testUnresolvedTenantFailsWithoutPersistingTenant(): void
    {
        $this->seedIdentity(
            'session-unresolved',
            'phone-unresolved'
        );

        $resolver = new CallbackTenantResolver(
            static fn (array $identity): array => []
        );

        $service = new TenantResolutionService(
            $this->store,
            $resolver
        );

        try {
            $service->resolve('session-unresolved');

            self::fail(
                'Tenant ausente deveria falhar fechada.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Tenant não resolvido',
                $exception->getMessage()
            );
        }

        self::assertArrayNotHasKey(
            'tenant_id',
            $this->store
                ->load('session-unresolved')
                ['execution_context']
        );
    }

    public function testInvalidTenantFailsClosed(): void
    {
        $this->seedIdentity(
            'session-invalid-tenant',
            'phone-invalid'
        );

        $resolver = new CallbackTenantResolver(
            static fn (array $identity): array => [
                'tenant_id' => '',
            ]
        );

        $service = new TenantResolutionService(
            $this->store,
            $resolver
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'tenant_id resolvido é inválido'
        );

        $service->resolve(
            'session-invalid-tenant'
        );
    }

    public function testTwoSessionsResolveToDifferentTenants(): void
    {
        $this->seedIdentity(
            'session-isolated-a',
            'phone-a'
        );
        $this->seedIdentity(
            'session-isolated-b',
            'phone-b'
        );

        $resolver = new CallbackTenantResolver(
            static fn (array $identity): array => [
                'tenant_id' => match (
                    $identity['phone_number_id'] ?? null
                ) {
                    'phone-a' => 10,
                    'phone-b' => 20,
                    default => throw new RuntimeException(
                        'identity desconhecida'
                    ),
                },
            ]
        );

        $service = new TenantResolutionService(
            $this->store,
            $resolver
        );

        self::assertSame(
            10,
            $service
                ->resolve('session-isolated-a')
                ->tenantId()
        );
        self::assertSame(
            20,
            $service
                ->resolve('session-isolated-b')
                ->tenantId()
        );

        self::assertSame(
            10,
            $this->store
                ->load('session-isolated-a')
                ['execution_context']
                ['tenant_id']
        );
        self::assertSame(
            20,
            $this->store
                ->load('session-isolated-b')
                ['execution_context']
                ['tenant_id']
        );
    }

    public function testSqlResolverSupportsSelect(): void
    {
        $db = new RecordingSqlExecutor(
            [
                ['id_empresa' => 142],
            ]
        );

        $resolver = new SqlTenantResolver(
            $db,
            'SELECT id_empresa FROM whatsapps '
                . 'WHERE phone_number_id = :phone',
            ['phone' => 'phone_number_id'],
            'id_empresa'
        );

        $result = $resolver->resolve(
            [
                'provider' => 'whatsapp',
                'phone_number_id' => 'phone-select',
            ]
        );

        self::assertSame(
            ['tenant_id' => 142],
            $result
        );
        self::assertSame(
            ['phone' => 'phone-select'],
            $db->lastParameters
        );
        self::assertStringStartsWith(
            'SELECT',
            $db->lastSql
        );
    }

    public function testSqlResolverSupportsStoredProcedureCall(): void
    {
        $db = new RecordingSqlExecutor(
            [
                ['tenant_id' => 'tenant-procedure'],
            ]
        );

        $resolver = new SqlTenantResolver(
            $db,
            'CALL resolver_tenant(:external_key)',
            ['external_key' => 'account.external_key']
        );

        $result = $resolver->resolve(
            [
                'account' => [
                    'external_key' => 'key-123',
                ],
            ]
        );

        self::assertSame(
            ['tenant_id' => 'tenant-procedure'],
            $result
        );
        self::assertSame(
            ['external_key' => 'key-123'],
            $db->lastParameters
        );
        self::assertStringStartsWith(
            'CALL',
            $db->lastSql
        );
    }

    public function testSqlResolverFailsOnNoRows(): void
    {
        $db = new RecordingSqlExecutor([]);

        $resolver = new SqlTenantResolver(
            $db,
            'SELECT tenant_id FROM tenant_map '
                . 'WHERE external_key = :key',
            ['key' => 'external_key']
        );

        self::assertSame(
            [],
            $resolver->resolve(
                ['external_key' => 'missing']
            )
        );
    }

    public function testSqlResolverFailsOnAmbiguousRows(): void
    {
        $db = new RecordingSqlExecutor(
            [
                ['tenant_id' => 1],
                ['tenant_id' => 2],
            ]
        );

        $resolver = new SqlTenantResolver(
            $db,
            'SELECT tenant_id FROM tenant_map '
                . 'WHERE external_key = :key',
            ['key' => 'external_key']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ambíguo');

        $resolver->resolve(
            ['external_key' => 'duplicated']
        );
    }

    public function testSqlResolverRejectsMissingIdentityPathBeforeDb(): void
    {
        $db = new RecordingSqlExecutor(
            [['tenant_id' => 1]]
        );

        $resolver = new SqlTenantResolver(
            $db,
            'SELECT tenant_id FROM tenant_map '
                . 'WHERE external_key = :key',
            ['key' => 'missing.path']
        );

        try {
            $resolver->resolve(
                ['external_key' => 'value']
            );

            self::fail(
                'Caminho de identity ausente deveria falhar.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'missing.path',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $db->fetchCalls);
    }

    public function testHttpResolverMapsIdentityAndNestedTenantResponse(): void
    {
        $http = new RecordingHttpClient(
            [
                'success' => true,
                'data' => [
                    'company' => [
                        'tenant_id' => 'tenant-http',
                    ],
                ],
            ]
        );

        $resolver = new HttpTenantResolver(
            $http,
            'https://tenant.example.test/resolve',
            [
                'phone_number_id' => 'phone_number_id',
                'provider' => 'provider',
            ],
            'data.company.tenant_id',
            ['X-Resolver' => 'guiche'],
            9
        );

        $result = $resolver->resolve(
            [
                'provider' => 'whatsapp',
                'phone_number_id' => 'phone-http',
            ]
        );

        self::assertSame(
            ['tenant_id' => 'tenant-http'],
            $result
        );
        self::assertSame(
            [
                'phone_number_id' => 'phone-http',
                'provider' => 'whatsapp',
            ],
            $http->lastData
        );
        self::assertSame(
            'https://tenant.example.test/resolve',
            $http->lastUrl
        );
        self::assertSame(
            ['X-Resolver' => 'guiche'],
            $http->lastHeaders
        );
        self::assertSame(9, $http->lastTimeout);
    }

    public function testHttpResolverRejectsMissingIdentityBeforeRequest(): void
    {
        $http = new RecordingHttpClient(
            ['tenant_id' => 1]
        );

        $resolver = new HttpTenantResolver(
            $http,
            'https://tenant.example.test/resolve',
            ['key' => 'missing.key']
        );

        try {
            $resolver->resolve(
                ['provider' => 'whatsapp']
            );

            self::fail(
                'Identity incompleta deveria falhar.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'missing.key',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $http->calls);
    }

    public function testCallbackResolverCoversRepositoryAdapter(): void
    {
        $repositoryCalls = 0;

        $resolver = new CallbackTenantResolver(
            static function (array $identity) use (
                &$repositoryCalls
            ): array {
                $repositoryCalls++;

                return [
                    'tenant_id' => (
                        $identity['repository_key'] ?? null
                    ) === 'repo-a'
                        ? 501
                        : 502,
                ];
            }
        );

        self::assertSame(
            ['tenant_id' => 501],
            $resolver->resolve(
                ['repository_key' => 'repo-a']
            )
        );
        self::assertSame(1, $repositoryCalls);
    }

    public function testServiceUsesTenantResolverContract(): void
    {
        $this->seedIdentity(
            'session-contract',
            'phone-contract'
        );

        $resolver = new class implements TenantResolverInterface {
            /**
             * @param array<mixed> $identity
             * @return array<mixed>
             */
            public function resolve(array $identity): array
            {
                return [
                    'tenant_id' => 808,
                ];
            }
        };

        $service = new TenantResolutionService(
            $this->store,
            $resolver
        );

        self::assertSame(
            808,
            $service
                ->resolve('session-contract')
                ->tenantId()
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
                    'provider' => 'whatsapp',
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

/**
 * @internal Test double.
 */
final class RecordingSqlExecutor implements SqlExecutorInterface
{
    public int $fetchCalls = 0;
    public string $lastSql = '';

    /**
     * @var array<mixed>
     */
    public array $lastParameters = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $rows
    ) {
    }

    /**
     * @param array<mixed> $parameters
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(
        string $sql,
        array $parameters = []
    ): array {
        $this->fetchCalls++;
        $this->lastSql = $sql;
        $this->lastParameters = $parameters;

        return $this->rows;
    }

    /**
     * @param array<mixed> $parameters
     */
    public function execute(
        string $sql,
        array $parameters = []
    ): int {
        throw new RuntimeException(
            'execute() não deve ser usado por TenantResolver.'
        );
    }
}

/**
 * @internal Test double.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    public int $calls = 0;
    public string $lastUrl = '';

    /**
     * @var array<mixed>
     */
    public array $lastData = [];

    /**
     * @var array<mixed>
     */
    public array $lastHeaders = [];

    public int $lastTimeout = 0;

    /**
     * @param array<mixed> $response
     */
    public function __construct(
        private readonly array $response
    ) {
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     * @return array<mixed>
     */
    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        $this->calls++;
        $this->lastUrl = $url;
        $this->lastData = $data;
        $this->lastHeaders = $headers;
        $this->lastTimeout = $timeout;

        return $this->response;
    }
}
