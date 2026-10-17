<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Cli\Application;
use PHPUnit\Framework\TestCase;

final class CliInitDoctorV15Test extends TestCase
{
    private string $projectRoot;

    /**
     * @var array<string, array{
     *     env_exists: bool,
     *     env_value: mixed,
     *     server_exists: bool,
     *     server_value: mixed,
     *     process_value: string|false
     * }>
     */
    private array $environmentBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir()
            . '/guiche-v15-cli-'
            . bin2hex(random_bytes(8));

        mkdir(
            $this->projectRoot,
            0775,
            true
        );

        foreach (
            [
                'AI_API_KEY',
                'AI_MODEL',
                'OPENAI_API_KEY',
                'OPENAI_MODEL',
            ] as $name
        ) {
            $this->backupAndClearEnvironment(
                $name
            );
        }
    }

    protected function tearDown(): void
    {
        foreach (
            $this->environmentBackup
            as $name => $backup
        ) {
            if ($backup['env_exists']) {
                $_ENV[$name] = $backup['env_value'];
            } else {
                unset($_ENV[$name]);
            }

            if ($backup['server_exists']) {
                $_SERVER[$name] =
                    $backup['server_value'];
            } else {
                unset($_SERVER[$name]);
            }

            if ($backup['process_value'] === false) {
                putenv($name);
            } else {
                putenv(
                    $name
                    . '='
                    . $backup['process_value']
                );
            }
        }

        $this->removeDirectory(
            $this->projectRoot
        );

        parent::tearDown();
    }

    public function testInitCreatesV15LayoutAndComposerMapping(): void
    {
        $this->writeComposer([
            'name' => 'example/app',
            'autoload' => [
                'psr-4' => [
                    'Domain\\' => 'domain/',
                ],
            ],
        ]);

        [$status, $output] = $this->runCli([
            'guiche',
            'init',
        ]);

        self::assertSame(0, $status);
        self::assertFileExists(
            $this->projectRoot
                . '/prompts/comportamento.md'
        );
        self::assertFileExists(
            $this->projectRoot
                . '/guiche.prompt.md'
        );
        self::assertDirectoryExists(
            $this->projectRoot . '/src/Tool'
        );
        self::assertDirectoryExists(
            $this->projectRoot . '/src/Plugin'
        );
        self::assertDirectoryExists(
            $this->projectRoot
                . '/storage/conversations'
        );
        self::assertFileExists(
            $this->projectRoot . '/guiche.php'
        );

        $composer = $this->readComposer();

        self::assertSame(
            'domain/',
            $composer['autoload']['psr-4']['Domain\\']
        );
        self::assertSame(
            'src/',
            $composer['autoload']['psr-4']['App\\']
        );
        self::assertStringContainsString(
            'prompts/comportamento.md criado',
            $output
        );
        self::assertStringContainsString(
            'composer.json atualizado com App\\\\ => src/',
            $output
        );
    }

    public function testInitIsIdempotentAndPreservesUserFiles(): void
    {
        $this->writeComposer([
            'name' => 'example/app',
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                    'Domain\\' => 'domain/',
                ],
            ],
        ]);

        [$firstStatus] = $this->runCli([
            'guiche',
            'init',
        ]);

        self::assertSame(0, $firstStatus);

        file_put_contents(
            $this->projectRoot . '/.env',
            "CUSTOM=1\n"
        );
        file_put_contents(
            $this->projectRoot
                . '/prompts/comportamento.md',
            "PROMPT CUSTOM\n"
        );
        file_put_contents(
            $this->projectRoot
                . '/guiche.prompt.md',
            "LEGADO CUSTOM\n"
        );
        file_put_contents(
            $this->projectRoot . '/guiche.php',
            "<?php\nreturn 'CUSTOM';\n"
        );

        $composerBefore = file_get_contents(
            $this->projectRoot . '/composer.json'
        );

        [$secondStatus, $output] = $this->runCli([
            'guiche',
            'init',
        ]);

        self::assertSame(0, $secondStatus);
        self::assertSame(
            "CUSTOM=1\n",
            file_get_contents(
                $this->projectRoot . '/.env'
            )
        );
        self::assertSame(
            "PROMPT CUSTOM\n",
            file_get_contents(
                $this->projectRoot
                    . '/prompts/comportamento.md'
            )
        );
        self::assertSame(
            "LEGADO CUSTOM\n",
            file_get_contents(
                $this->projectRoot
                    . '/guiche.prompt.md'
            )
        );
        self::assertSame(
            "<?php\nreturn 'CUSTOM';\n",
            file_get_contents(
                $this->projectRoot . '/guiche.php'
            )
        );
        self::assertSame(
            $composerBefore,
            file_get_contents(
                $this->projectRoot . '/composer.json'
            )
        );
        self::assertStringContainsString(
            'prompts/comportamento.md existente preservado',
            $output
        );
        self::assertStringContainsString(
            'composer.json já possui App\\\\ => src/',
            $output
        );
    }

    public function testInitDoesNotOverwriteConflictingAppMapping(): void
    {
        $this->writeComposer([
            'name' => 'example/app',
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'app/',
                    'Domain\\' => 'domain/',
                ],
            ],
        ]);

        $before = file_get_contents(
            $this->projectRoot . '/composer.json'
        );

        [$status, $output] = $this->runCli([
            'guiche',
            'init',
        ]);

        self::assertSame(0, $status);
        self::assertSame(
            $before,
            file_get_contents(
                $this->projectRoot . '/composer.json'
            )
        );
        self::assertStringContainsString(
            'mapeamento preservado',
            $output
        );
    }

    public function testInitFailsClosedOnInvalidComposerJson(): void
    {
        file_put_contents(
            $this->projectRoot . '/composer.json',
            "{invalid-json\n"
        );

        [$status] = $this->runCli([
            'guiche',
            'init',
        ]);

        self::assertSame(1, $status);
        self::assertSame(
            "{invalid-json\n",
            file_get_contents(
                $this->projectRoot . '/composer.json'
            )
        );
    }

    public function testDoctorValidatesNewPromptAndPsr4(): void
    {
        $this->prepareDoctorProject([
            'App\\' => 'src/',
        ]);

        [$status, $output] = $this->runCli([
            'guiche',
            'doctor',
        ]);

        self::assertSame(0, $status);
        self::assertStringContainsString(
            'prompts/comportamento.md',
            $output
        );
        self::assertStringContainsString(
            'prompt global disponível',
            $output
        );
        self::assertStringContainsString(
            'composer PSR-4',
            $output
        );
        self::assertStringContainsString(
            'App\\\\ => src/',
            $output
        );
        self::assertStringContainsString(
            'Diagnóstico: ambiente pronto',
            $output
        );
    }

    public function testDoctorAcceptsLegacyPromptWithWarning(): void
    {
        $this->prepareDoctorProject([
            'App\\' => 'src/',
        ]);

        @unlink(
            $this->projectRoot
                . '/prompts/comportamento.md'
        );

        file_put_contents(
            $this->projectRoot
                . '/guiche.prompt.md',
            "PROMPT LEGADO\n"
        );

        [$status, $output] = $this->runCli([
            'guiche',
            'doctor',
        ]);

        self::assertSame(0, $status);
        self::assertStringContainsString(
            'usando guiche.prompt.md legado',
            $output
        );
        self::assertStringContainsString(
            'aviso(s)',
            $output
        );
    }

    public function testDoctorFailsWhenAppMappingIsMissing(): void
    {
        $this->prepareDoctorProject([
            'Domain\\' => 'domain/',
        ]);

        [$status, $output] = $this->runCli([
            'guiche',
            'doctor',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'App\\\\ => src/ ausente',
            $output
        );
        self::assertStringContainsString(
            'falha(s)',
            $output
        );
    }

    public function testDoctorFailsOnConflictingAppMapping(): void
    {
        $this->prepareDoctorProject([
            'App\\' => 'app/',
        ]);

        [$status, $output] = $this->runCli([
            'guiche',
            'doctor',
        ]);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'não aponta para src/',
            $output
        );
    }

    public function testDoctorWarnsWhenComposerIsAbsent(): void
    {
        $this->prepareDoctorProject(null);

        [$status, $output] = $this->runCli([
            'guiche',
            'doctor',
        ]);

        self::assertSame(0, $status);
        self::assertStringContainsString(
            'composer.json não encontrado',
            $output
        );
        self::assertStringContainsString(
            'aviso(s)',
            $output
        );
    }

    /**
     * @param array<string, string>|null $psr4
     */
    private function prepareDoctorProject(
        ?array $psr4
    ): void {
        mkdir(
            $this->projectRoot . '/prompts',
            0775,
            true
        );
        mkdir(
            $this->projectRoot . '/src/Tool',
            0775,
            true
        );
        mkdir(
            $this->projectRoot . '/src/Plugin',
            0775,
            true
        );
        mkdir(
            $this->projectRoot
                . '/storage/conversations',
            0775,
            true
        );

        file_put_contents(
            $this->projectRoot
                . '/prompts/comportamento.md',
            "PROMPT GLOBAL\n"
        );
        file_put_contents(
            $this->projectRoot . '/.env',
            "AI_API_KEY=test-key\n"
            . "AI_MODEL=test-model\n"
        );

        if ($psr4 !== null) {
            $this->writeComposer([
                'name' => 'example/app',
                'autoload' => [
                    'psr-4' => $psr4,
                ],
            ]);
        }
    }

    /**
     * @param array<mixed> $composer
     */
    private function writeComposer(
        array $composer
    ): void {
        file_put_contents(
            $this->projectRoot . '/composer.json',
            json_encode(
                $composer,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    /**
     * @return array<mixed>
     */
    private function readComposer(): array
    {
        $composer = json_decode(
            (string)file_get_contents(
                $this->projectRoot . '/composer.json'
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($composer);

        return $composer;
    }

    /**
     * @param array<int, string> $argv
     * @return array{0:int, 1:string}
     */
    private function runCli(
        array $argv
    ): array {
        $application = new Application(
            dirname(__DIR__, 2),
            $this->projectRoot
        );

        ob_start();

        try {
            $status = $application->run($argv);
            $output = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return [$status, $output];
    }

    private function backupAndClearEnvironment(
        string $name
    ): void {
        $this->environmentBackup[$name] = [
            'env_exists' =>
                array_key_exists($name, $_ENV),
            'env_value' =>
                $_ENV[$name] ?? null,
            'server_exists' =>
                array_key_exists($name, $_SERVER),
            'server_value' =>
                $_SERVER[$name] ?? null,
            'process_value' =>
                getenv($name),
        ];

        unset(
            $_ENV[$name],
            $_SERVER[$name]
        );

        putenv($name);
    }

    private function removeDirectory(
        string $directory
    ): void {
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

            $path = $directory
                . '/'
                . $item;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
