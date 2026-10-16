<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConcurrentSessionTurnTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir()
            . '/guiche-concurrent-turn-'
            . bin2hex(random_bytes(8));

        if (
            !mkdir($this->root, 0775, true)
            && !is_dir($this->root)
        ) {
            throw new RuntimeException(
                'Não foi possível criar diretório temporário.'
            );
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testConcurrentTurnsInSameSessionRemainAtomic(): void
    {
        $contextDirectory = $this->root . '/contexts';
        $sessionId = 'same-session';
        $worker = dirname(__DIR__)
            . '/Fixtures/concurrent-turn-worker.php';

        $processA = $this->startWorker(
            $worker,
            $contextDirectory,
            $sessionId,
            'Guarde CONCORRENTE-A-731. Responda A-OK.'
        );

        /*
         * Sem o lock de turno, A já terá persistido a mensagem do
         * usuário e estará aguardando o provider quando B entrar.
         */
        usleep(50_000);

        $processB = $this->startWorker(
            $worker,
            $contextDirectory,
            $sessionId,
            'Guarde CONCORRENTE-B-842. Responda B-OK.'
        );

        $resultA = $this->finishWorker($processA);
        $resultB = $this->finishWorker($processB);

        self::assertSame(
            0,
            $resultA['exit_code'],
            $resultA['stderr']
        );
        self::assertSame(
            0,
            $resultB['exit_code'],
            $resultB['stderr']
        );
        self::assertSame('A-OK', $resultA['stdout']);
        self::assertSame('B-OK', $resultB['stdout']);

        $context = (new ContextStore(
            $contextDirectory,
            'UTC'
        ))->load($sessionId);

        $messages = $context['messages'];

        self::assertCount(4, $messages);
        self::assertSame(
            ['user', 'assistant', 'user', 'assistant'],
            array_column($messages, 'role')
        );

        for ($index = 0; $index < 4; $index += 2) {
            $user = (string) (
                $messages[$index]['content'] ?? ''
            );
            $assistant = (string) (
                $messages[$index + 1]['content'] ?? ''
            );

            if (str_contains($user, 'CONCORRENTE-A-731')) {
                self::assertSame('A-OK', $assistant);
                continue;
            }

            self::assertStringContainsString(
                'CONCORRENTE-B-842',
                $user
            );
            self::assertSame('B-OK', $assistant);
        }

        self::assertCount(
            2,
            $context['usage']['interactions']
        );
    }

    /**
     * @return array{
     *     process: resource,
     *     stdout: resource,
     *     stderr: resource
     * }
     */
    private function startWorker(
        string $worker,
        string $contextDirectory,
        string $sessionId,
        string $message
    ): array {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                $worker,
                $contextDirectory,
                $sessionId,
                $message,
            ],
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
    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker['stdout']);
        $stderr = stream_get_contents($worker['stderr']);

        fclose($worker['stdout']);
        fclose($worker['stderr']);

        $exitCode = proc_close($worker['process']);

        return [
            'exit_code' => $exitCode,
            'stdout' => trim((string) $stdout),
            'stderr' => trim((string) $stderr),
        ];
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
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
