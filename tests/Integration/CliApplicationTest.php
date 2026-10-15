<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Cli\Application;
use Asmpkg\Guiche\ContextStore;
use PHPUnit\Framework\TestCase;

final class CliApplicationTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir()
            . '/guiche-cli-test-'
            . bin2hex(random_bytes(6));

        mkdir($this->projectRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testHelpListsPublicCommands(): void
    {
        [$status, $output] = $this->runCli(['guiche', 'help']);

        self::assertSame(0, $status);
        self::assertStringContainsString('Guichê CLI', $output);
        self::assertStringContainsString('init', $output);
        self::assertStringContainsString('doctor', $output);
        self::assertStringContainsString('usage', $output);
    }

    public function testInitCreatesEnvironmentAndStorageWithoutOverwritingEnv(): void
    {
        [$status, $output] = $this->runCli(['guiche', 'init']);

        self::assertSame(0, $status);
        self::assertFileExists($this->projectRoot . '/.env');
        self::assertDirectoryExists(
            $this->projectRoot . '/storage/conversations'
        );
        self::assertStringContainsString(
            'AI_API_KEY=',
            (string) file_get_contents(
                $this->projectRoot . '/.env'
            )
        );
        self::assertStringContainsString(
            '.env criado',
            $output
        );

        file_put_contents(
            $this->projectRoot . '/.env',
            "CUSTOM_VALUE=preservar\n"
        );

        [$secondStatus, $secondOutput] = $this->runCli([
            'guiche',
            'init',
        ]);

        self::assertSame(0, $secondStatus);
        self::assertSame(
            "CUSTOM_VALUE=preservar\n",
            file_get_contents($this->projectRoot . '/.env')
        );
        self::assertStringContainsString(
            '.env existente preservado',
            $secondOutput
        );
    }

    public function testUsageReadsPersistedSessionTotals(): void
    {
        $store = new ContextStore(
            $this->projectRoot . '/storage/conversations'
        );

        $store->recordInteractionUsage(
            'sessao-teste',
            [
                'input_tokens' => 120,
                'output_tokens' => 30,
                'total_tokens' => 150,
            ]
        );

        [$status, $output] = $this->runCli([
            'guiche',
            'usage',
            '--session=sessao-teste',
        ]);

        self::assertSame(0, $status);
        self::assertStringContainsString(
            'Sessão: sessao-teste',
            $output
        );
        self::assertStringContainsString(
            'Interações: 1',
            $output
        );
        self::assertStringContainsString(
            'Input tokens: 120',
            $output
        );
        self::assertStringContainsString(
            'Output tokens: 30',
            $output
        );
        self::assertStringContainsString(
            'Total tokens: 150',
            $output
        );
    }

    public function testComposerRegistersCliAsPublicBinary(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode(
            (string) file_get_contents(
                $root . '/composer.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertContains(
            'cli/guiche',
            $composer['bin'] ?? []
        );
        self::assertFileExists($root . '/cli/guiche');
        self::assertNotSame(
            0,
            fileperms($root . '/cli/guiche') & 0111
        );
        self::assertNotContains(
            '/cli',
            $composer['archive']['exclude'] ?? []
        );

        $map = require $root . '/api/stability-map.php';

        self::assertSame(
            'internal',
            $map[Application::class] ?? null
        );
    }

    /**
     * @param array<int, string> $argv
     * @return array{0:int, 1:string}
     */
    private function runCli(array $argv): array
    {
        $application = new Application(
            dirname(__DIR__, 2),
            $this->projectRoot
        );

        ob_start();

        try {
            $status = $application->run($argv);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return [$status, $output];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
