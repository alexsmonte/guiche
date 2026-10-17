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

interface LoadPluginDependencyInterface
{
}

final class LoadPluginDependency implements LoadPluginDependencyInterface
{
}

abstract class LoadPluginFixtureBase implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
    }
}

final class ClinicaFixturePlugin extends LoadPluginFixtureBase
{
}

final class JaComSufixoFixturePlugin extends LoadPluginFixtureBase
{
}

final class PrimeiroFixturePlugin extends LoadPluginFixtureBase
{
}

final class SegundoFixturePlugin extends LoadPluginFixtureBase
{
}

final class FqcnFixturePlugin extends LoadPluginFixtureBase
{
}

final class ComDependenciaFixturePlugin extends LoadPluginFixtureBase
{
    public function __construct(
        public readonly LoadPluginDependencyInterface $dependency
    ) {
    }
}

final class ClasseInvalidaPluginFixture
{
}

final class AtomicRollbackTool implements ToolInterface
{
    public function name(): string
    {
        return 'atomic_rollback_fixture';
    }

    public function description(): string
    {
        return 'Tool temporária para validar rollback de Plugin.';
    }

    /**
     * @return array<mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function execute(array $arguments): array
    {
        return [
            'success' => true,
        ];
    }
}

final class AtomicFailureFixturePlugin implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder->registerTool(
            new AtomicRollbackTool()
        );

        throw new RuntimeException(
            'falha proposital do plugin'
        );
    }
}

if (!class_exists('App\\Plugin\\ClinicaPlugin', false)) {
    class_alias(
        ClinicaFixturePlugin::class,
        'App\\Plugin\\ClinicaPlugin'
    );
}

if (!class_exists('App\\Plugin\\JaComSufixoPlugin', false)) {
    class_alias(
        JaComSufixoFixturePlugin::class,
        'App\\Plugin\\JaComSufixoPlugin'
    );
}

if (!class_exists('App\\Plugin\\PrimeiroPlugin', false)) {
    class_alias(
        PrimeiroFixturePlugin::class,
        'App\\Plugin\\PrimeiroPlugin'
    );
}

if (!class_exists('App\\Plugin\\SegundoPlugin', false)) {
    class_alias(
        SegundoFixturePlugin::class,
        'App\\Plugin\\SegundoPlugin'
    );
}

if (!class_exists('App\\Plugin\\ComDependenciaPlugin', false)) {
    class_alias(
        ComDependenciaFixturePlugin::class,
        'App\\Plugin\\ComDependenciaPlugin'
    );
}

if (!class_exists('App\\Plugin\\ClasseInvalidaPlugin', false)) {
    class_alias(
        ClasseInvalidaPluginFixture::class,
        'App\\Plugin\\ClasseInvalidaPlugin'
    );
}

if (!class_exists('App\\Plugin\\AtomicFailurePlugin', false)) {
    class_alias(
        AtomicFailureFixturePlugin::class,
        'App\\Plugin\\AtomicFailurePlugin'
    );
}

final class GuicheBuilderLoadPluginTest extends TestCase
{
    public function testLoadsShortPluginNameUsingConvention(): void
    {
        $builder = new GuicheBuilder();

        $result = $builder->loadPlugin(
            'Clinica'
        );

        self::assertSame($builder, $result);
        self::assertCount(1, $builder->plugins());
        self::assertInstanceOf(
            ClinicaFixturePlugin::class,
            array_values($builder->plugins())[0]
        );
    }

    public function testDoesNotDuplicatePluginSuffix(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadPlugin(
            'JaComSufixoPlugin'
        );

        self::assertCount(1, $builder->plugins());
        self::assertInstanceOf(
            JaComSufixoFixturePlugin::class,
            array_values($builder->plugins())[0]
        );
    }

    public function testLoadsPluginByFqcn(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadPlugin(
            FqcnFixturePlugin::class
        );

        self::assertCount(1, $builder->plugins());
        self::assertInstanceOf(
            FqcnFixturePlugin::class,
            array_values($builder->plugins())[0]
        );
    }

    public function testLoadsFqcnWithLeadingBackslash(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadPlugin(
            '\\' . FqcnFixturePlugin::class
        );

        self::assertCount(1, $builder->plugins());
        self::assertInstanceOf(
            FqcnFixturePlugin::class,
            array_values($builder->plugins())[0]
        );
    }

    public function testLoadPluginsLoadsMultiplePlugins(): void
    {
        $builder = new GuicheBuilder();

        $result = $builder->loadPlugins(
            'Primeiro',
            'Segundo'
        );

        self::assertSame($builder, $result);
        self::assertCount(2, $builder->plugins());

        $plugins = array_values($builder->plugins());

        self::assertInstanceOf(
            PrimeiroFixturePlugin::class,
            $plugins[0]
        );
        self::assertInstanceOf(
            SegundoFixturePlugin::class,
            $plugins[1]
        );
    }

    public function testLoadPluginsAcceptsZeroArguments(): void
    {
        $builder = new GuicheBuilder();

        self::assertSame(
            $builder,
            $builder->loadPlugins()
        );
        self::assertSame([], $builder->plugins());
    }

    public function testInjectsRegisteredServiceIntoLoadedPlugin(): void
    {
        $builder = new GuicheBuilder();
        $dependency = new LoadPluginDependency();

        $builder->setService(
            LoadPluginDependencyInterface::class,
            $dependency
        );

        $builder->loadPlugin(
            'ComDependencia'
        );

        $plugins = array_values(
            $builder->plugins()
        );

        self::assertCount(1, $plugins);
        self::assertInstanceOf(
            ComDependenciaFixturePlugin::class,
            $plugins[0]
        );
        self::assertSame(
            $dependency,
            $plugins[0]->dependency
        );
    }

    public function testFailsForMissingShortPlugin(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'Classe esperada: App\\Plugin\\NaoExistePlugin'
        );

        $builder->loadPlugin(
            'NaoExiste'
        );
    }

    public function testFailsForEmptyPluginName(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'Nome do Plugin não pode ser vazio.'
        );

        $builder->loadPlugin('   ');
    }

    public function testFailsWhenResolvedClassIsNotAPlugin(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            PluginInterface::class
        );

        $builder->loadPlugin(
            'ClasseInvalida'
        );
    }

    public function testPreservesDuplicateProtectionFromRegisterPlugin(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadPlugin(
            'Clinica'
        );

        $this->expectException(
            LogicException::class
        );
        $this->expectExceptionMessage(
            'Plugin já registrado'
        );

        $builder->loadPlugin(
            'Clinica'
        );
    }

    public function testMissingServiceFailsClosedThroughClassResolver(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Registre a dependência com GuicheBuilder::setService().'
        );

        $builder->loadPlugin(
            'ComDependencia'
        );
    }

    public function testRegisterFailureKeepsPluginRegistrationAtomic(): void
    {
        $builder = new GuicheBuilder();

        try {
            $builder->loadPlugin(
                'AtomicFailure'
            );

            self::fail(
                'Plugin deveria falhar propositalmente.'
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                'falha proposital do plugin',
                $exception->getMessage()
            );
        }

        self::assertSame(
            [],
            $builder->plugins()
        );
        self::assertFalse(
            $builder->toolRegistry()->has(
                'atomic_rollback_fixture'
            )
        );
    }
}
