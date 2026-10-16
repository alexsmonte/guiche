<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TrustedIdentityConcurrencyDxTest extends TestCase
{
    private string $root;
    private string $contextDirectory;
    private string $bindWorker;
    private string $handleWorker;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/guiche-v13-trusted-concurrency-'
            . bin2hex(random_bytes(8));

        $this->contextDirectory =
            $this->root . '/contexts';

        if (
            !mkdir(
                $this->root,
                0775,
                true
            )
            && !is_dir($this->root)
        ) {
            throw new RuntimeException(
                'Não foi possível criar diretório temporário.'
            );
        }

        $this->bindWorker = dirname(__DIR__)
            . '/Fixtures/trusted-bind-worker.php';

        $this->handleWorker = dirname(__DIR__)
            . '/Fixtures/trusted-handle-worker.php';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testConcurrentSameIdentityIsIdempotent(): void
    {
        $identity = [
            'provider' => 'custom-crm',
            'account_id' => 10,
            'conversation_id' => 900,
        ];

        $workers = [];

        for ($index = 0; $index < 8; $index++) {
            $workers[] = $this->startBindWorker(
                'same-identity-session',
                $identity,
                'tenant-42'
            );
        }

        foreach ($workers as $worker) {
            $result = $this->finishWorker(
                $worker
            );

            self::assertSame(
                0,
                $result['exit_code'],
                $result['stderr']
            );

            $payload = json_decode(
                $result['stdout'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            self::assertSame(
                'tenant-42',
                $payload['tenant_id']
            );

            self::assertSame(
                $identity,
                $payload['identity']
            );
        }

        $stored = $this->store()
            ->load('same-identity-session')
            ['execution_context'];

        self::assertSame(
            $identity,
            $stored['identity']
        );

        self::assertSame(
            'tenant-42',
            $stored['tenant_id']
        );
    }

    public function testConcurrentConflictingIdentityFailsClosed(): void
    {
        $identityA = [
            'provider' => 'custom-crm',
            'account_id' => 100,
            'conversation_id' => 700,
        ];

        $identityB = [
            'provider' => 'custom-crm',
            'account_id' => 200,
            'conversation_id' => 700,
        ];

        $workerA = $this->startBindWorker(
            'conflict-session',
            $identityA,
            'tenant-a'
        );

        $workerB = $this->startBindWorker(
            'conflict-session',
            $identityB,
            'tenant-b'
        );

        $resultA = $this->finishWorker($workerA);
        $resultB = $this->finishWorker($workerB);

        $exitCodes = [
            $resultA['exit_code'],
            $resultB['exit_code'],
        ];

        sort($exitCodes);

        self::assertSame(
            [0, 2],
            $exitCodes,
            $resultA['stderr']
            . "\n"
            . $resultB['stderr']
        );

        $stored = $this->store()
            ->load('conflict-session')
            ['execution_context'];

        $winnerIsA = $stored['identity']
            === $identityA;

        if ($winnerIsA) {
            self::assertSame(
                'tenant-a',
                $stored['tenant_id']
            );
            return;
        }

        self::assertSame(
            $identityB,
            $stored['identity']
        );

        self::assertSame(
            'tenant-b',
            $stored['tenant_id']
        );
    }

    public function testBindIdentityAndHandleShareSameSessionLock(): void
    {
        $entered = $this->root
            . '/resolver-entered';

        $release = $this->root
            . '/resolver-release';

        $identity = [
            'provider' => 'custom-crm',
            'account_id' => 88,
            'conversation_id' => 990,
        ];

        $bind = $this->startBindWorker(
            'bind-handle-session',
            $identity,
            'tenant-88',
            $entered,
            $release
        );

        $this->waitForFile($entered);

        $handle = $this->startHandleWorker(
            'bind-handle-session',
            'MENSAGEM-CONCORRENTE-551'
        );

        usleep(150_000);

        $status = proc_get_status(
            $handle['process']
        );

        self::assertTrue(
            $status['running'],
            'handle() não aguardou o bindIdentity() da mesma sessão.'
        );

        touch($release);

        $bindResult = $this->finishWorker($bind);
        $handleResult = $this->finishWorker($handle);

        self::assertSame(
            0,
            $bindResult['exit_code'],
            $bindResult['stderr']
        );

        self::assertSame(
            0,
            $handleResult['exit_code'],
            $handleResult['stderr']
        );

        self::assertSame(
            'HANDLE-OK',
            $handleResult['stdout']
        );

        $stored = $this->store()
            ->load('bind-handle-session');

        self::assertSame(
            $identity,
            $stored['execution_context']['identity']
        );

        self::assertSame(
            'tenant-88',
            $stored['execution_context']['tenant_id']
        );

        self::assertCount(
            2,
            $stored['messages']
        );

        self::assertSame(
            ['user', 'assistant'],
            array_column(
                $stored['messages'],
                'role'
            )
        );

        self::assertSame(
            'MENSAGEM-CONCORRENTE-551',
            $stored['messages'][0]['content']
        );
    }

    public function testDifferentSessionsDoNotBlockEachOther(): void
    {
        $entered = $this->root
            . '/session-a-entered';

        $release = $this->root
            . '/session-a-release';

        $slow = $this->startBindWorker(
            'independent-session-a',
            [
                'provider' => 'custom-crm',
                'account_id' => 1,
                'conversation_id' => 1,
            ],
            'tenant-a',
            $entered,
            $release
        );

        $this->waitForFile($entered);

        $fast = $this->startBindWorker(
            'independent-session-b',
            [
                'provider' => 'custom-crm',
                'account_id' => 2,
                'conversation_id' => 2,
            ],
            'tenant-b'
        );

        $deadline = microtime(true) + 2.0;

        do {
            $status = proc_get_status(
                $fast['process']
            );

            if (!$status['running']) {
                break;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            touch($release);
        }

        self::assertFalse(
            $status['running'],
            'Sessões diferentes compartilharam lock indevidamente.'
        );

        $fastResult = $this->finishWorker($fast);

        touch($release);

        $slowResult = $this->finishWorker($slow);

        self::assertSame(
            0,
            $fastResult['exit_code'],
            $fastResult['stderr']
        );

        self::assertSame(
            0,
            $slowResult['exit_code'],
            $slowResult['stderr']
        );

        self::assertSame(
            'tenant-a',
            $this->store()
                ->load('independent-session-a')
                ['execution_context']
                ['tenant_id']
        );

        self::assertSame(
            'tenant-b',
            $this->store()
                ->load('independent-session-b')
                ['execution_context']
                ['tenant_id']
        );
    }

    public function testGenericExternalHostDxIsNotChatwootSpecific(): void
    {
        $readme = file_get_contents(
            dirname(__DIR__, 2)
            . '/examples/external-host/README.md'
        );

        self::assertIsString($readme);

        self::assertStringContainsString(
            "'provider' => 'custom-crm'",
            $readme
        );

        self::assertStringContainsString(
            'bindIdentity(',
            $readme
        );

        self::assertStringContainsString(
            'TenantResolver',
            $readme
        );

        self::assertStringNotContainsString(
            'ExecutionContextSeeder',
            $readme
        );
    }

    /**
     * @param array<mixed> $identity
     * @return array{
     *     process: resource,
     *     stdout: resource,
     *     stderr: resource
     * }
     */
    private function startBindWorker(
        string $sessionId,
        array $identity,
        string $tenantId,
        string $enteredFile = '-',
        string $releaseFile = '-'
    ): array {
        return $this->startProcess(
            [
                PHP_BINARY,
                $this->bindWorker,
                $this->contextDirectory,
                $sessionId,
                json_encode(
                    $identity,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ),
                $tenantId,
                $enteredFile,
                $releaseFile,
            ]
        );
    }

    /**
     * @return array{
     *     process: resource,
     *     stdout: resource,
     *     stderr: resource
     * }
     */
    private function startHandleWorker(
        string $sessionId,
        string $message
    ): array {
        return $this->startProcess(
            [
                PHP_BINARY,
                $this->handleWorker,
                $this->contextDirectory,
                $sessionId,
                $message,
            ]
        );
    }

    /**
     * @param array<int, string> $command
     * @return array{
     *     process: resource,
     *     stdout: resource,
     *     stderr: resource
     * }
     */
    private function startProcess(array $command): array
    {
        $pipes = [];

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2)
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                'Não foi possível iniciar worker concorrente.'
            );
        }

        fclose($pipes[0]);

        return [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    /**
     * @param array{
     *     process: resource,
     *     stdout: resource,
     *     stderr: resource
     * } $worker
     * @return array{
     *     exit_code: int,
     *     stdout: string,
     *     stderr: string
     * }
     */
    private function finishWorker(
        array $worker
    ): array {
        $stdout = stream_get_contents(
            $worker['stdout']
        );

        $stderr = stream_get_contents(
            $worker['stderr']
        );

        fclose($worker['stdout']);
        fclose($worker['stderr']);

        $exitCode = proc_close(
            $worker['process']
        );

        return [
            'exit_code' => $exitCode,
            'stdout' => trim(
                (string) $stdout
            ),
            'stderr' => trim(
                (string) $stderr
            ),
        ];
    }

    private function waitForFile(
        string $path
    ): void {
        $deadline = microtime(true) + 3.0;

        while (!is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    "Timeout aguardando sinalização: {$path}"
                );
            }

            usleep(10_000);
        }
    }

    private function store(): ContextStore
    {
        return new ContextStore(
            $this->contextDirectory,
            'UTC'
        );
    }

    private function removeTree(
        string $directory
    ): void {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if (
                $entry === '.'
                || $entry === '..'
            ) {
                continue;
            }

            $path = $directory
                . DIRECTORY_SEPARATOR
                . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
