<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Prompt\StaticPromptResolver;
use PHPUnit\Framework\TestCase;

final class FrameworkCompositionIntegrationTest
    extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v14-framework-'
            . bin2hex(random_bytes(8));

        mkdir(
            $this->directory,
            0775,
            true
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(
            $this->directory
        );
    }

    public function testCompositionRootAcceptsHostContainerDependencies(): void
    {
        $factory = require dirname(__DIR__, 2)
            . '/resources/guiche.php';

        self::assertIsCallable($factory);

        $provider =
            new FrameworkCompositionFakeAi();

        $configured = false;

        $guiche = $factory([
            'context_store' => new ContextStore(
                $this->directory
                    . '/context'
            ),
            'ai_provider' => $provider,
            'prompt_resolver' =>
                new StaticPromptResolver(
                    'PROMPT DO CONTAINER HOST'
                ),
            'configure' =>
                static function (
                    GuicheBuilder $builder
                ) use (&$configured): void {
                    $configured = true;

                    $builder->addInstructionFragment(
                        'host-framework',
                        'FRAGMENTO DO FRAMEWORK HOST'
                    );
                },
        ]);

        $result = $guiche->handle(
            'framework-session',
            'olá'
        );

        self::assertTrue($configured);

        self::assertSame(
            'ok',
            $result['message']
        );

        self::assertStringContainsString(
            'PROMPT DO CONTAINER HOST',
            $provider->instructions
        );

        self::assertStringContainsString(
            'FRAGMENTO DO FRAMEWORK HOST',
            $provider->instructions
        );
    }

    public function testCoreDoesNotDependOnFrameworkPackages(): void
    {
        $src = dirname(__DIR__, 2)
            . '/src';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $src,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        $forbidden = [
            'Cake\\',
            'Illuminate\\',
            'Symfony\\',
        ];

        foreach ($iterator as $file) {
            if (
                !$file->isFile()
                || $file->getExtension() !== 'php'
            ) {
                continue;
            }

            $source = file_get_contents(
                $file->getPathname()
            );

            self::assertIsString($source);

            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $source,
                    $file->getPathname()
                );
            }
        }
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
            if (
                $item === '.'
                || $item === '..'
            ) {
                continue;
            }

            $path = $directory
                . '/'
                . $item;

            if (is_dir($path)) {
                $this->removeDirectory(
                    $path
                );

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}

final class FrameworkCompositionFakeAi
    implements AiProviderInterface
{
    public string $instructions = '';

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->instructions = $instructions;

        return [
            'id' => 'framework-response',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        $this->instructions = $instructions;

        return [
            'id' => 'framework-tool-response',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}
