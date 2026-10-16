<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Cli\Application;
use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use PHPUnit\Framework\TestCase;

final class CompositionRootTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir()
            . '/guiche-v14-root-'
            . bin2hex(random_bytes(8));

        mkdir(
            $this->projectRoot,
            0775,
            true
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(
            $this->projectRoot
        );
    }

    public function testOfficialTemplateComposesRuntimeDependencies(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $factory = require $packageRoot
            . '/resources/guiche.php';

        self::assertIsCallable($factory);

        $provider = new CompositionRootFakeAi();
        $store = new ContextStore(
            $this->projectRoot
                . '/storage/runtime'
        );

        $promptCalls = 0;

        $guiche = $factory([
            'context_store' => $store,
            'ai_provider' => $provider,
            'timezone' => 'America/Manaus',
            'tenant_resolver' =>
                new CallbackTenantResolver(
                    static function (
                        array $identity
                    ): array {
                        return [
                            'tenant_id' =>
                                'tenant-'
                                . $identity['external_id'],
                        ];
                    }
                ),
            'prompt_resolver' =>
                static function (
                    PromptContext $context
                ) use (&$promptCalls): string {
                    $promptCalls++;

                    return 'PROMPT ROOT '
                        . (string)$context->tenantId();
                },
            'tools' => [
                new CompositionRootDirectTool(),
            ],
            'plugins' => [
                new CompositionRootPlugin(),
            ],
        ]);

        $guiche->bindIdentity(
            'root-session',
            ['external_id' => '42']
        );

        $result = $guiche->handle(
            'root-session',
            'olá'
        );

        self::assertSame(
            'resposta composition root',
            $result['message']
        );
        self::assertSame(1, $promptCalls);
        self::assertStringContainsString(
            'PROMPT ROOT tenant-42',
            $provider->instructions
        );

        $toolNames = array_column(
            $guiche->tools(),
            'name'
        );

        self::assertContains(
            'RootDirectTool',
            $toolNames
        );
        self::assertContains(
            'RootPluginTool',
            $toolNames
        );
    }

    public function testCliInitCreatesAndPreservesCompositionRoot(): void
    {
        $application = new Application(
            dirname(__DIR__, 2),
            $this->projectRoot,
            new CompositionRootFakeAi()
        );

        ob_start();

        try {
            $status = $application->run([
                'guiche',
                'init',
            ]);
            $output = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $status);

        $rootFile = $this->projectRoot
            . '/guiche.php';

        self::assertFileExists($rootFile);
        self::assertStringContainsString(
            'Composition Root',
            (string)file_get_contents(
                $rootFile
            )
        );
        self::assertStringContainsString(
            'guiche.php criado como Composition Root',
            $output
        );

        file_put_contents(
            $rootFile,
            "<?php\nreturn 'PRESERVAR';\n"
        );

        ob_start();

        try {
            $secondStatus = $application->run([
                'guiche',
                'init',
            ]);
            $secondOutput =
                (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(
            0,
            $secondStatus
        );
        self::assertSame(
            "<?php\nreturn 'PRESERVAR';\n",
            file_get_contents($rootFile)
        );
        self::assertStringContainsString(
            'guiche.php existente preservado',
            $secondOutput
        );
    }

    public function testCliPrefersProjectCompositionRootWhenPresent(): void
    {
        $rootFile = $this->projectRoot
            . '/guiche.php';

        $code = <<<'PHPFILE'
<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Prompt\PromptContext;

return static function (array $app = []): Guiche {
    return (new GuicheBuilder())
        ->withContextStore(
            new ContextStore(
                __DIR__ . '/storage/conversations'
            )
        )
        ->withAiProvider(
            $app['ai_provider']
        )
        ->withPromptResolver(
            static fn (
                PromptContext $context
            ): string => 'ROOT EXCLUSIVO DO PROJETO'
        )
        ->build();
};
PHPFILE;

        file_put_contents(
            $rootFile,
            $code
        );

        $provider = new CompositionRootFakeAi();

        $application = new Application(
            dirname(__DIR__, 2),
            $this->projectRoot,
            $provider
        );

        ob_start();

        try {
            $status = $application->run([
                'guiche',
                'chat',
                '--session=root-cli',
                'mensagem',
            ]);
            $output = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $status);
        self::assertStringContainsString(
            'Guichê: resposta composition root',
            $output
        );
        self::assertStringContainsString(
            'ROOT EXCLUSIVO DO PROJETO',
            $provider->instructions
        );
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

final class CompositionRootFakeAi
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
            'id' => 'root-response',
            'text' => 'resposta composition root',
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
            'id' => 'root-response-tools',
            'text' => 'resposta composition root',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}

final class CompositionRootDirectTool
    implements ToolInterface
{
    public function name(): string
    {
        return 'RootDirectTool';
    }

    public function description(): string
    {
        return 'Tool direta do Composition Root.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function execute(
        array $arguments
    ): array {
        return ['success' => true];
    }
}

final class CompositionRootPluginTool
    implements ToolInterface
{
    public function name(): string
    {
        return 'RootPluginTool';
    }

    public function description(): string
    {
        return 'Tool registrada por Plugin.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function execute(
        array $arguments
    ): array {
        return ['success' => true];
    }
}

final class CompositionRootPlugin
    implements PluginInterface
{
    public function register(
        GuicheBuilder $builder
    ): void {
        $builder->registerTool(
            new CompositionRootPluginTool()
        );
    }
}
