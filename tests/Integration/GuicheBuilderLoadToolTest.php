<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\GuicheBuilder;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

interface LoadToolDependencyInterface
{
}

final class LoadToolDependency implements LoadToolDependencyInterface
{
}

abstract class LoadToolFixtureBase implements ToolInterface
{
    public function description(): string
    {
        return 'Fixture de teste do loadTool.';
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
            'tool' => $this->name(),
        ];
    }
}

final class InformacoesEmpresaFixtureTool extends LoadToolFixtureBase
{
    public function name(): string
    {
        return 'informacoes_empresa_fixture';
    }
}

final class JaComSufixoFixtureTool extends LoadToolFixtureBase
{
    public function name(): string
    {
        return 'ja_com_sufixo_fixture';
    }
}

final class PrimeiraFixtureTool extends LoadToolFixtureBase
{
    public function name(): string
    {
        return 'primeira_fixture';
    }
}

final class SegundaFixtureTool extends LoadToolFixtureBase
{
    public function name(): string
    {
        return 'segunda_fixture';
    }
}

final class FqcnFixtureTool extends LoadToolFixtureBase
{
    public function name(): string
    {
        return 'fqcn_fixture';
    }
}

final class ComDependenciaFixtureTool extends LoadToolFixtureBase
{
    public function __construct(
        public readonly LoadToolDependencyInterface $dependency
    ) {
    }

    public function name(): string
    {
        return 'com_dependencia_fixture';
    }
}

final class ClasseInvalidaFixture
{
}

if (!class_exists('App\\Tool\\InformacoesEmpresaTool', false)) {
    class_alias(
        InformacoesEmpresaFixtureTool::class,
        'App\\Tool\\InformacoesEmpresaTool'
    );
}

if (!class_exists('App\\Tool\\JaComSufixoTool', false)) {
    class_alias(
        JaComSufixoFixtureTool::class,
        'App\\Tool\\JaComSufixoTool'
    );
}

if (!class_exists('App\\Tool\\PrimeiraTool', false)) {
    class_alias(
        PrimeiraFixtureTool::class,
        'App\\Tool\\PrimeiraTool'
    );
}

if (!class_exists('App\\Tool\\SegundaTool', false)) {
    class_alias(
        SegundaFixtureTool::class,
        'App\\Tool\\SegundaTool'
    );
}

if (!class_exists('App\\Tool\\ComDependenciaTool', false)) {
    class_alias(
        ComDependenciaFixtureTool::class,
        'App\\Tool\\ComDependenciaTool'
    );
}

if (!class_exists('App\\Tool\\ClasseInvalidaTool', false)) {
    class_alias(
        ClasseInvalidaFixture::class,
        'App\\Tool\\ClasseInvalidaTool'
    );
}

final class GuicheBuilderLoadToolTest extends TestCase
{
    public function testLoadsShortToolNameUsingConvention(): void
    {
        $builder = new GuicheBuilder();

        $result = $builder->loadTool(
            'InformacoesEmpresa'
        );

        self::assertSame($builder, $result);
        self::assertTrue(
            $builder->toolRegistry()->has(
                'informacoes_empresa_fixture'
            )
        );
    }

    public function testDoesNotDuplicateToolSuffix(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadTool(
            'JaComSufixoTool'
        );

        self::assertTrue(
            $builder->toolRegistry()->has(
                'ja_com_sufixo_fixture'
            )
        );
    }

    public function testLoadsToolByFqcn(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadTool(
            FqcnFixtureTool::class
        );

        self::assertTrue(
            $builder->toolRegistry()->has(
                'fqcn_fixture'
            )
        );
    }

    public function testLoadsFqcnWithLeadingBackslash(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadTool(
            '\\' . FqcnFixtureTool::class
        );

        self::assertTrue(
            $builder->toolRegistry()->has(
                'fqcn_fixture'
            )
        );
    }

    public function testLoadToolsLoadsMultipleTools(): void
    {
        $builder = new GuicheBuilder();

        $result = $builder->loadTools(
            'Primeira',
            'Segunda'
        );

        self::assertSame($builder, $result);
        self::assertTrue(
            $builder->toolRegistry()->has(
                'primeira_fixture'
            )
        );
        self::assertTrue(
            $builder->toolRegistry()->has(
                'segunda_fixture'
            )
        );
    }

    public function testLoadToolsAcceptsZeroArguments(): void
    {
        $builder = new GuicheBuilder();

        self::assertSame(
            $builder,
            $builder->loadTools()
        );
        self::assertSame(
            [],
            $builder->toolRegistry()->names()
        );
    }

    public function testInjectsRegisteredServiceIntoLoadedTool(): void
    {
        $builder = new GuicheBuilder();
        $dependency = new LoadToolDependency();

        $builder->setService(
            LoadToolDependencyInterface::class,
            $dependency
        );

        $builder->loadTool(
            'ComDependencia'
        );

        $tool = $builder->toolRegistry()->get(
            'com_dependencia_fixture'
        );

        self::assertInstanceOf(
            ComDependenciaFixtureTool::class,
            $tool
        );
        self::assertSame(
            $dependency,
            $tool->dependency
        );
    }

    public function testFailsForMissingShortTool(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'Classe esperada: App\\Tool\\NaoExisteTool'
        );

        $builder->loadTool(
            'NaoExiste'
        );
    }

    public function testFailsForEmptyToolName(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'Nome da Tool não pode ser vazio.'
        );

        $builder->loadTool('   ');
    }

    public function testFailsWhenResolvedClassIsNotATool(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            ToolInterface::class
        );

        $builder->loadTool(
            'ClasseInvalida'
        );
    }

    public function testPreservesDuplicateProtectionFromToolRegistry(): void
    {
        $builder = new GuicheBuilder();

        $builder->loadTool(
            'InformacoesEmpresa'
        );

        $this->expectException(
            LogicException::class
        );
        $this->expectExceptionMessage(
            'Conflito de ferramenta'
        );

        $builder->loadTool(
            'InformacoesEmpresa'
        );
    }

    public function testMissingServiceFailsClosedThroughClassResolver(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(
            \RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Registre a dependência com GuicheBuilder::setService().'
        );

        $builder->loadTool(
            'ComDependencia'
        );
    }
}
