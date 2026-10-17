<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\GuicheBuilder;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

interface DeclarativeDependencyInterface
{
}

final class DeclarativeDependency
    implements DeclarativeDependencyInterface
{
}

final class DeclarativeFixtureTool
    implements ToolInterface
{
    public function __construct(
        public readonly DeclarativeDependencyInterface $dependency
    ) {
    }

    public function name(): string
    {
        return 'declarative_fixture';
    }

    public function description(): string
    {
        return 'Tool fixture do Declarative Loading.';
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

    public function execute(array $arguments): array
    {
        return [
            'success' => true,
        ];
    }
}

final class DeclarativeSecondFixtureTool
    implements ToolInterface
{
    public function name(): string
    {
        return 'declarative_second_fixture';
    }

    public function description(): string
    {
        return 'Segunda Tool fixture.';
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

    public function execute(array $arguments): array
    {
        return [
            'success' => true,
        ];
    }
}

final class DeclarativeFixturePlugin
    implements PluginInterface
{
    public function __construct(
        public readonly DeclarativeDependencyInterface $dependency
    ) {
    }

    public function register(
        GuicheBuilder $builder
    ): void {
        $builder->addInstructionFragment(
            'declarative-plugin',
            'REGRA DECLARATIVA'
        );
    }
}

final class DeclarativeShortTool
    implements ToolInterface
{
    public function name(): string
    {
        return 'declarative_short';
    }

    public function description(): string
    {
        return 'Tool curta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        return ['success' => true];
    }
}

final class DeclarativeShortPlugin
    implements PluginInterface
{
    public function register(
        GuicheBuilder $builder
    ): void {
        $builder->addInstructionFragment(
            'declarative-short-plugin',
            'SHORT PLUGIN'
        );
    }
}

if (!class_exists(
    'App\\Tool\\DeclarativeShortTool',
    false
)) {
    class_alias(
        DeclarativeShortTool::class,
        'App\\Tool\\DeclarativeShortTool'
    );
}

if (!class_exists(
    'App\\Plugin\\DeclarativeShortPlugin',
    false
)) {
    class_alias(
        DeclarativeShortPlugin::class,
        'App\\Plugin\\DeclarativeShortPlugin'
    );
}

final class DeclarativeLoadingV16Test
    extends TestCase
{
    public function testLoadsServicesToolsAndPluginsFromOneDefinition(): void
    {
        $dependency =
            new DeclarativeDependency();

        $builder = new GuicheBuilder();

        $result = $builder->loadCapabilities([
            'services' => [
                DeclarativeDependencyInterface::class =>
                    $dependency,
            ],
            'tools' => [
                DeclarativeFixtureTool::class,
            ],
            'plugins' => [
                DeclarativeFixturePlugin::class,
            ],
        ]);

        self::assertSame($builder, $result);

        self::assertTrue(
            $builder->hasService(
                DeclarativeDependencyInterface::class
            )
        );

        $tool = $builder
            ->toolRegistry()
            ->get('declarative_fixture');

        self::assertInstanceOf(
            DeclarativeFixtureTool::class,
            $tool
        );

        self::assertSame(
            $dependency,
            $tool->dependency
        );

        $plugins = array_values(
            $builder->plugins()
        );

        self::assertCount(1, $plugins);
        self::assertInstanceOf(
            DeclarativeFixturePlugin::class,
            $plugins[0]
        );
        self::assertSame(
            $dependency,
            $plugins[0]->dependency
        );

        self::assertArrayHasKey(
            'declarative-plugin',
            $builder->instructionFragments()
        );
    }

    public function testShortNamesReuseExistingConventions(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadCapabilities([
            'tools' => [
                'DeclarativeShort',
            ],
            'plugins' => [
                'DeclarativeShort',
            ],
        ]);

        self::assertTrue(
            $builder->toolRegistry()->has(
                'declarative_short'
            )
        );

        self::assertArrayHasKey(
            DeclarativeShortPlugin::class,
            $builder->plugins()
        );
    }

    public function testEmptyDefinitionIsNoOp(): void
    {
        $builder = new GuicheBuilder();

        self::assertSame(
            $builder,
            $builder->loadCapabilities([])
        );

        self::assertSame(
            [],
            $builder->toolRegistry()->names()
        );

        self::assertSame(
            [],
            $builder->plugins()
        );
    }

    public function testUnknownTopLevelKeyFailsClosed(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'Chave de capability desconhecida: tool'
        );

        $builder->loadCapabilities([
            'tool' => [
                DeclarativeFixtureTool::class,
            ],
        ]);
    }

    public function testToolsMustBeAList(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'capabilities.tools precisa ser uma lista.'
        );

        $builder->loadCapabilities([
            'tools' => [
                'first' =>
                    DeclarativeFixtureTool::class,
            ],
        ]);
    }

    public function testPluginsMustBeAList(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'capabilities.plugins precisa ser uma lista.'
        );

        $builder->loadCapabilities([
            'plugins' => [
                'first' =>
                    DeclarativeFixturePlugin::class,
            ],
        ]);
    }

    public function testServiceMustBeObject(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'precisa ser um objeto'
        );

        $builder->loadCapabilities([
            'services' => [
                DeclarativeDependencyInterface::class =>
                    DeclarativeDependency::class,
            ],
        ]);
    }

    public function testMalformedDefinitionDoesNotPublishValidService(): void
    {
        $builder = new GuicheBuilder();

        try {
            $builder->loadCapabilities([
                'services' => [
                    DeclarativeDependencyInterface::class =>
                        new DeclarativeDependency(),
                ],
                'tools' => 'not-a-list',
            ]);

            self::fail(
                'Declaração malformada deveria falhar.'
            );
        } catch (InvalidArgumentException) {
        }

        self::assertFalse(
            $builder->hasService(
                DeclarativeDependencyInterface::class
            )
        );
    }

    public function testMissingClassFailsBeforePublishingServices(): void
    {
        $builder = new GuicheBuilder();

        try {
            $builder->loadCapabilities([
                'services' => [
                    DeclarativeDependencyInterface::class =>
                        new DeclarativeDependency(),
                ],
                'tools' => [
                    'ClasseQueNaoExisteDeclarativeV16',
                ],
            ]);

            self::fail(
                'Tool inexistente deveria falhar.'
            );
        } catch (InvalidArgumentException) {
        }

        self::assertFalse(
            $builder->hasService(
                DeclarativeDependencyInterface::class
            )
        );
    }

    public function testMissingDependencyFailsBeforeMutation(): void
    {
        $builder = new GuicheBuilder();

        try {
            $builder->loadCapabilities([
                'tools' => [
                    DeclarativeFixtureTool::class,
                ],
            ]);

            self::fail(
                'Dependência ausente deveria falhar.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Registre a dependência',
                $exception->getMessage()
            );
        }

        self::assertSame(
            [],
            $builder->toolRegistry()->names()
        );
    }

    public function testDuplicateDeclaredToolFailsBeforePublishingService(): void
    {
        $builder = new GuicheBuilder();

        $builder->registerTool(
            new DeclarativeSecondFixtureTool()
        );

        try {
            $builder->loadCapabilities([
                'services' => [
                    DeclarativeDependencyInterface::class =>
                        new DeclarativeDependency(),
                ],
                'tools' => [
                    DeclarativeSecondFixtureTool::class,
                ],
            ]);

            self::fail(
                'Tool duplicada deveria falhar.'
            );
        } catch (LogicException $exception) {
            self::assertStringContainsString(
                'Conflito de ferramenta',
                $exception->getMessage()
            );
        }

        self::assertFalse(
            $builder->hasService(
                DeclarativeDependencyInterface::class
            )
        );
    }

    public function testImperativeLoadingRemainsCompatible(): void
    {
        $dependency =
            new DeclarativeDependency();

        $builder = new GuicheBuilder();

        $builder->setService(
            DeclarativeDependencyInterface::class,
            $dependency
        );

        $builder->loadTool(
            DeclarativeFixtureTool::class
        );

        $builder->loadPlugin(
            DeclarativeFixturePlugin::class
        );

        self::assertTrue(
            $builder->toolRegistry()->has(
                'declarative_fixture'
            )
        );

        self::assertCount(
            1,
            $builder->plugins()
        );
    }
}
