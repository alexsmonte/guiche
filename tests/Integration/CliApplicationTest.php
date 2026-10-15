<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Cli\Application;
use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Cli\PromptResolver;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
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
        [$status, $output] = $this->runCli([
            'guiche',
            'help',
        ]);

        self::assertSame(0, $status);
        self::assertStringContainsString('Guichê CLI', $output);
        self::assertStringContainsString('init', $output);
        self::assertStringContainsString('doctor', $output);
        self::assertStringContainsString('chat', $output);
        self::assertStringContainsString('usage', $output);
    }

    public function testInitCreatesEnvironmentAndStorageWithoutOverwritingEnv(): void
    {
        [$status, $output] = $this->runCli([
            'guiche',
            'init',
        ]);

        self::assertSame(0, $status);
        self::assertFileExists(
            $this->projectRoot . '/.env'
        );
        self::assertDirectoryExists(
            $this->projectRoot . '/storage/conversations'
        );
        self::assertFileExists(
            $this->projectRoot . '/guiche.prompt.md'
        );
        self::assertStringContainsString(
            'Você é um assistente executado pelo Guichê.',
            (string) file_get_contents(
                $this->projectRoot . '/guiche.prompt.md'
            )
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
        file_put_contents(
            $this->projectRoot . '/guiche.prompt.md',
            "PROMPT PERSONALIZADO\n"
        );

        [$secondStatus, $secondOutput] = $this->runCli([
            'guiche',
            'init',
        ]);

        self::assertSame(0, $secondStatus);
        self::assertSame(
            "CUSTOM_VALUE=preservar\n",
            file_get_contents(
                $this->projectRoot . '/.env'
            )
        );
        self::assertStringContainsString(
            '.env existente preservado',
            $secondOutput
        );
        self::assertSame(
            "PROMPT PERSONALIZADO\n",
            file_get_contents(
                $this->projectRoot . '/guiche.prompt.md'
            )
        );
        self::assertStringContainsString(
            'guiche.prompt.md existente preservado',
            $secondOutput
        );
    }

    public function testPromptResolverUsesExpectedPriority(): void
    {
        $packageRoot = dirname(__DIR__, 2);

        file_put_contents(
            $this->projectRoot . '/guiche.prompt.md',
            "prompt do projeto\n"
        );

        $resolver = new PromptResolver(
            $packageRoot,
            $this->projectRoot
        );

        self::assertSame(
            'prompt do projeto',
            $resolver->resolve()
        );

        $explicit = $this->projectRoot
            . '/prompt-explicito.md';

        file_put_contents(
            $explicit,
            "prompt de arquivo explícito\n"
        );

        $previousFile = getenv('GUICHE_PROMPT_FILE');
        $previousInline = getenv('GUICHE_PROMPT');

        try {
            putenv(
                'GUICHE_PROMPT=prompt inline'
            );
            $_ENV['GUICHE_PROMPT'] = 'prompt inline';

            $inlineResolver = new PromptResolver(
                $packageRoot,
                $this->projectRoot
            );

            self::assertSame(
                'prompt inline',
                $inlineResolver->resolve()
            );

            putenv(
                'GUICHE_PROMPT_FILE='
                . $explicit
            );
            $_ENV['GUICHE_PROMPT_FILE'] = $explicit;

            $fileResolver = new PromptResolver(
                $packageRoot,
                $this->projectRoot
            );

            self::assertSame(
                'prompt de arquivo explícito',
                $fileResolver->resolve()
            );
            self::assertStringContainsString(
                'prompt-explicito.md',
                $fileResolver->source()
            );
        } finally {
            if ($previousFile === false) {
                putenv('GUICHE_PROMPT_FILE');
                unset($_ENV['GUICHE_PROMPT_FILE']);
            } else {
                putenv(
                    'GUICHE_PROMPT_FILE='
                    . $previousFile
                );
                $_ENV['GUICHE_PROMPT_FILE'] = $previousFile;
            }

            if ($previousInline === false) {
                putenv('GUICHE_PROMPT');
                unset($_ENV['GUICHE_PROMPT']);
            } else {
                putenv(
                    'GUICHE_PROMPT='
                    . $previousInline
                );
                $_ENV['GUICHE_PROMPT'] = $previousInline;
            }
        }
    }

    public function testChatUsesProjectPromptAutomatically(): void
    {
        file_put_contents(
            $this->projectRoot . '/guiche.prompt.md',
            "PROMPT EXCLUSIVO DO PROJETO\n"
        );

        $provider = new CliFakeAiProvider();

        [$status] = $this->runCli(
            [
                'guiche',
                'chat',
                'teste',
            ],
            $provider
        );

        self::assertSame(0, $status);
        self::assertStringStartsWith(
            'PROMPT EXCLUSIVO DO PROJETO',
            $provider->lastInstructions
        );
        self::assertStringContainsString(
            'CONTEXTO ESTRUTURADO ATUAL DO GUICHÊ:',
            $provider->lastInstructions
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

    public function testChatBootstrapsRuntimeWithoutManualChatFile(): void
    {
        [$status, $output] = $this->runCli(
            [
                'guiche',
                'chat',
                '--session=bootstrap-test',
                'Olá',
                'Guichê',
            ],
            new CliFakeAiProvider()
        );

        self::assertSame(0, $status);
        self::assertStringContainsString(
            'Guichê: resposta fake: Olá Guichê',
            $output
        );

        $store = new ContextStore(
            $this->projectRoot . '/storage/conversations'
        );

        $context = $store->load(
            'bootstrap-test'
        );

        self::assertCount(
            2,
            $context['messages']
        );
        self::assertSame(
            'user',
            $context['messages'][0]['role']
        );
        self::assertSame(
            'Olá Guichê',
            $context['messages'][0]['content']
        );
        self::assertSame(
            'assistant',
            $context['messages'][1]['role']
        );
        self::assertSame(
            15,
            $context['usage']['total_tokens']
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
        self::assertFileExists(
            $root . '/cli/guiche'
        );
        self::assertNotSame(
            0,
            fileperms(
                $root . '/cli/guiche'
            ) & 0111
        );
        self::assertNotContains(
            '/cli',
            $composer['archive']['exclude'] ?? []
        );

        $map = require $root
            . '/api/stability-map.php';

        self::assertSame(
            'internal',
            $map[Application::class] ?? null
        );
    }

    /**
     * @param array<int, string> $argv
     * @return array{0:int, 1:string}
     */
    private function runCli(
        array $argv,
        ?AiProviderInterface $provider = null
    ): array {
        $application = new Application(
            dirname(__DIR__, 2),
            $this->projectRoot,
            $provider
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

final class CliFakeAiProvider implements AiProviderInterface
{
    public string $lastInstructions = '';

    /**
     * @param array<mixed> $messages
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->lastInstructions = $instructions;

        $last = end($messages);
        $content = is_array($last)
            ? (string) ($last['content'] ?? '')
            : '';

        return [
            'id' => 'resp-cli-fake',
            'text' => 'resposta fake: ' . $content,
            'tool_calls' => [],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
            ],
        ];
    }

    /**
     * @param array<mixed> $toolOutputs
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        return [
            'id' => 'resp-cli-fake-tools',
            'text' => 'resposta fake',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}
