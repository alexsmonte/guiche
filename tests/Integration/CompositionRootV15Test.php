<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompositionRootV15Test extends TestCase
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
            . '/guiche-v15-root-'
            . bin2hex(random_bytes(8));

        mkdir(
            $this->projectRoot,
            0775,
            true
        );

        $this->copyCompositionRoot();

        foreach (
            [
                'GUICHE_PROMPT_FILE',
                'GUICHE_PROMPT',
            ] as $name
        ) {
            $this->backupAndClearEnvironment(
                $name
            );
        }

        CompositionRootV15LoadedTool::$dependency = null;
        CompositionRootV15LoadedPlugin::$dependency = null;
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

    public function testExplicitPromptResolverHasHighestPriority(): void
    {
        $this->writePrompt(
            'prompts/comportamento.md',
            'NOVO DEFAULT'
        );
        $this->writePrompt(
            'guiche.prompt.md',
            'LEGADO'
        );

        $this->setEnvironment(
            'GUICHE_PROMPT_FILE',
            'arquivo-que-nao-deve-ser-usado.md'
        );
        $this->setEnvironment(
            'GUICHE_PROMPT',
            'ENV INLINE'
        );

        $provider = new CompositionRootV15FakeAi();

        $guiche = $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' => $provider,
            'prompt_resolver' =>
                static fn (
                    PromptContext $context
                ): string => 'EXPLICITO',
        ]);

        $guiche->handle(
            'session-explicit',
            'olá'
        );

        self::assertStringContainsString(
            'EXPLICITO',
            $provider->instructions
        );
        self::assertStringNotContainsString(
            'ENV INLINE',
            $provider->instructions
        );
        self::assertStringNotContainsString(
            'NOVO DEFAULT',
            $provider->instructions
        );
    }

    public function testPromptFileEnvironmentPrecedesInlineAndTenantDefault(): void
    {
        $this->writePrompt(
            'prompt-env.md',
            'PROMPT DE ARQUIVO'
        );
        $this->writePrompt(
            'prompts/comportamento.md',
            'NOVO DEFAULT'
        );

        $this->setEnvironment(
            'GUICHE_PROMPT_FILE',
            'prompt-env.md'
        );
        $this->setEnvironment(
            'GUICHE_PROMPT',
            'PROMPT INLINE'
        );

        $provider = new CompositionRootV15FakeAi();

        $guiche = $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' => $provider,
        ]);

        $guiche->handle(
            'session-file',
            'olá'
        );

        self::assertStringContainsString(
            'PROMPT DE ARQUIVO',
            $provider->instructions
        );
        self::assertStringNotContainsString(
            'PROMPT INLINE',
            $provider->instructions
        );
        self::assertStringNotContainsString(
            'NOVO DEFAULT',
            $provider->instructions
        );
    }

    public function testInlineEnvironmentPrecedesTenantDefault(): void
    {
        $this->writePrompt(
            'prompts/comportamento.md',
            'NOVO DEFAULT'
        );

        $this->setEnvironment(
            'GUICHE_PROMPT',
            'PROMPT INLINE'
        );

        $provider = new CompositionRootV15FakeAi();

        $guiche = $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' => $provider,
        ]);

        $guiche->handle(
            'session-inline',
            'olá'
        );

        self::assertStringContainsString(
            'PROMPT INLINE',
            $provider->instructions
        );
        self::assertStringNotContainsString(
            'NOVO DEFAULT',
            $provider->instructions
        );
    }

    public function testTenantPromptResolverIsDefaultWhenGlobalPromptExists(): void
    {
        $this->writePrompt(
            'prompts/comportamento.md',
            'COMPORTAMENTO GLOBAL'
        );
        $this->writePrompt(
            'prompts/empresa-42.md',
            'REGRAS EMPRESA 42'
        );
        $this->writePrompt(
            'guiche.prompt.md',
            'LEGADO NAO USAR'
        );

        $provider = new CompositionRootV15FakeAi();

        $guiche = $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' => $provider,
            'tenant_resolver' =>
                new CallbackTenantResolver(
                    static function (
                        array $identity
                    ): array {
                        return [
                            'tenant_id' =>
                                'empresa-'
                                . $identity['empresa'],
                        ];
                    }
                ),
        ]);

        $guiche->bindIdentity(
            'session-tenant',
            ['empresa' => '42']
        );

        $guiche->handle(
            'session-tenant',
            'olá'
        );

        self::assertStringContainsString(
            'COMPORTAMENTO GLOBAL'
            . PHP_EOL
            . PHP_EOL
            . 'REGRAS EMPRESA 42',
            $provider->instructions
        );
        self::assertStringNotContainsString(
            'LEGADO NAO USAR',
            $provider->instructions
        );
    }

    public function testLegacyPromptRemainsFallback(): void
    {
        $this->writePrompt(
            'guiche.prompt.md',
            'PROMPT LEGADO'
        );

        $provider = new CompositionRootV15FakeAi();

        $guiche = $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' => $provider,
        ]);

        $guiche->handle(
            'session-legacy',
            'olá'
        );

        self::assertStringContainsString(
            'PROMPT LEGADO',
            $provider->instructions
        );
    }

    public function testServicesAndStringLoadersWorkWithLegacyInstances(): void
    {
        $this->writePrompt(
            'guiche.prompt.md',
            'PROMPT'
        );

        $dependency =
            new CompositionRootV15DependencyValue(
                'service-ok'
            );

        $configured = false;

        $guiche = $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' =>
                new CompositionRootV15FakeAi(),
            'services' => [
                CompositionRootV15Dependency::class =>
                    $dependency,
            ],
            'tools' => [
                'CompositionRootV15Loaded',
                new CompositionRootV15LegacyTool(),
            ],
            'plugins' => [
                'CompositionRootV15Loaded',
                new CompositionRootV15LegacyPlugin(),
            ],
            'configure' =>
                static function (
                    GuicheBuilder $builder
                ) use (&$configured): void {
                    $configured = true;
                    $builder->registerTool(
                        new CompositionRootV15ConfiguredTool()
                    );
                },
        ]);

        self::assertSame(
            $dependency,
            CompositionRootV15LoadedTool::$dependency
        );
        self::assertSame(
            $dependency,
            CompositionRootV15LoadedPlugin::$dependency
        );
        self::assertTrue($configured);

        $names = array_column(
            $guiche->tools(),
            'name'
        );

        self::assertContains(
            'CompositionRootV15LoadedTool',
            $names
        );
        self::assertContains(
            'CompositionRootV15PluginTool',
            $names
        );
        self::assertContains(
            'CompositionRootV15LegacyTool',
            $names
        );
        self::assertContains(
            'CompositionRootV15LegacyPluginTool',
            $names
        );
        self::assertContains(
            'CompositionRootV15ConfiguredTool',
            $names
        );
    }

    public function testServicesMustBeArray(): void
    {
        $this->writePrompt(
            'guiche.prompt.md',
            'PROMPT'
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'services precisa ser array'
        );

        $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' =>
                new CompositionRootV15FakeAi(),
            'services' => 'invalido',
        ]);
    }

    public function testServiceIdMustBeNonEmptyString(): void
    {
        $this->writePrompt(
            'guiche.prompt.md',
            'PROMPT'
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'id string não vazio'
        );

        $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' =>
                new CompositionRootV15FakeAi(),
            'services' => [
                0 => new \stdClass(),
            ],
        ]);
    }

    public function testServiceValueMustBeObject(): void
    {
        $this->writePrompt(
            'guiche.prompt.md',
            'PROMPT'
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'precisa ser object'
        );

        $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' =>
                new CompositionRootV15FakeAi(),
            'services' => [
                'dep' => 'nao-object',
            ],
        ]);
    }

    public function testInvalidToolStillFailsClearly(): void
    {
        $this->writePrompt(
            'guiche.prompt.md',
            'PROMPT'
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Cada item de tools precisa ser string'
        );

        $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' =>
                new CompositionRootV15FakeAi(),
            'tools' => [123],
        ]);
    }

    public function testInvalidPluginStillFailsClearly(): void
    {
        $this->writePrompt(
            'guiche.prompt.md',
            'PROMPT'
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Cada item de plugins precisa ser string'
        );

        $this->factory()([
            'context_store' => $this->contextStore(),
            'ai_provider' =>
                new CompositionRootV15FakeAi(),
            'plugins' => [123],
        ]);
    }

    /**
     * @return callable(array<string, mixed>): Guiche
     */
    private function factory(): callable
    {
        $factory = require $this->projectRoot
            . '/guiche.php';

        self::assertIsCallable($factory);

        return $factory;
    }

    private function contextStore(): ContextStore
    {
        return new ContextStore(
            $this->projectRoot
                . '/storage/conversations'
        );
    }

    private function copyCompositionRoot(): void
    {
        $source = dirname(__DIR__, 2)
            . '/resources/guiche.php';

        if (
            !copy(
                $source,
                $this->projectRoot
                    . '/guiche.php'
            )
        ) {
            throw new RuntimeException(
                'Falha ao copiar Composition Root.'
            );
        }
    }

    private function writePrompt(
        string $relativePath,
        string $content
    ): void {
        $path = $this->projectRoot
            . '/'
            . $relativePath;

        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir(
                $directory,
                0775,
                true
            );
        }

        file_put_contents(
            $path,
            $content
        );
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

    private function setEnvironment(
        string $name,
        string $value
    ): void {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv(
            $name . '=' . $value
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

interface CompositionRootV15Dependency
{
    public function value(): string;
}

final class CompositionRootV15DependencyValue
    implements CompositionRootV15Dependency
{
    public function __construct(
        private readonly string $value
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }
}

final class CompositionRootV15FakeAi
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
            'id' => 'v15-response',
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
            'id' => 'v15-response-tools',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}

final class CompositionRootV15LoadedTool
    implements ToolInterface
{
    public static ?CompositionRootV15Dependency $dependency =
        null;

    public function __construct(
        CompositionRootV15Dependency $dependency
    ) {
        self::$dependency = $dependency;
    }

    public function name(): string
    {
        return 'CompositionRootV15LoadedTool';
    }

    public function description(): string
    {
        return 'Tool carregada por string.';
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
        return [
            'value' => self::$dependency?->value(),
        ];
    }
}

final class CompositionRootV15LoadedPlugin
    implements PluginInterface
{
    public static ?CompositionRootV15Dependency $dependency =
        null;

    public function __construct(
        CompositionRootV15Dependency $dependency
    ) {
        self::$dependency = $dependency;
    }

    public function register(
        GuicheBuilder $builder
    ): void {
        $builder->registerTool(
            new CompositionRootV15PluginTool()
        );
    }
}

final class CompositionRootV15PluginTool
    implements ToolInterface
{
    public function name(): string
    {
        return 'CompositionRootV15PluginTool';
    }

    public function description(): string
    {
        return 'Tool do Plugin carregado por string.';
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
        return ['ok' => true];
    }
}

final class CompositionRootV15LegacyTool
    implements ToolInterface
{
    public function name(): string
    {
        return 'CompositionRootV15LegacyTool';
    }

    public function description(): string
    {
        return 'Tool em instância, compatibilidade.';
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
        return ['ok' => true];
    }
}

final class CompositionRootV15LegacyPlugin
    implements PluginInterface
{
    public function register(
        GuicheBuilder $builder
    ): void {
        $builder->registerTool(
            new CompositionRootV15LegacyPluginTool()
        );
    }
}

final class CompositionRootV15LegacyPluginTool
    implements ToolInterface
{
    public function name(): string
    {
        return 'CompositionRootV15LegacyPluginTool';
    }

    public function description(): string
    {
        return 'Tool de Plugin em instância.';
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
        return ['ok' => true];
    }
}

final class CompositionRootV15ConfiguredTool
    implements ToolInterface
{
    public function name(): string
    {
        return 'CompositionRootV15ConfiguredTool';
    }

    public function description(): string
    {
        return 'Tool registrada pelo escape hatch configure.';
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
        return ['ok' => true];
    }
}

if (
    !class_exists(
        'App\\Tool\\CompositionRootV15LoadedTool',
        false
    )
) {
    class_alias(
        CompositionRootV15LoadedTool::class,
        'App\\Tool\\CompositionRootV15LoadedTool'
    );
}

if (
    !class_exists(
        'App\\Plugin\\CompositionRootV15LoadedPlugin',
        false
    )
) {
    class_alias(
        CompositionRootV15LoadedPlugin::class,
        'App\\Plugin\\CompositionRootV15LoadedPlugin'
    );
}
