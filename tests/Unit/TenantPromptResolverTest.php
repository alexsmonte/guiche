<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Prompt\TenantPromptResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TenantPromptResolverTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'guiche-tenant-prompt-'
            . bin2hex(random_bytes(8));

        mkdir(
            $this->projectRoot
            . DIRECTORY_SEPARATOR
            . 'prompts',
            0777,
            true
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(
            $this->projectRoot
        );

        parent::tearDown();
    }

    public function testImplementsPromptResolverContract(): void
    {
        $this->writePrompt(
            'comportamento.md',
            'Global'
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertInstanceOf(
            PromptResolverInterface::class,
            $resolver
        );
    }

    public function testRejectsEmptyProjectRoot(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'exige a raiz do projeto'
        );

        new TenantPromptResolver('   ');
    }

    public function testGlobalPromptIsRequired(): void
    {
        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Prompt global não encontrado'
        );

        $resolver->resolve(
            new PromptContext('session-1')
        );
    }

    public function testGlobalPromptCannotBeEmpty(): void
    {
        $this->writePrompt(
            'comportamento.md',
            " \n \t "
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Prompt global está vazio'
        );

        $resolver->resolve(
            new PromptContext('session-1')
        );
    }

    public function testReturnsOnlyGlobalWithoutTenant(): void
    {
        $this->writePrompt(
            'comportamento.md',
            "\n  PROMPT GLOBAL  \n"
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertSame(
            'PROMPT GLOBAL',
            $resolver->resolve(
                new PromptContext('session-1')
            )
        );
    }

    public function testReturnsOnlyGlobalWhenTenantPromptDoesNotExist(): void
    {
        $this->writePrompt(
            'comportamento.md',
            'PROMPT GLOBAL'
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertSame(
            'PROMPT GLOBAL',
            $resolver->resolve(
                new PromptContext(
                    'session-1',
                    ['tenant_id' => 123]
                )
            )
        );
    }

    public function testComposesGlobalThenTenant(): void
    {
        $this->writePrompt(
            'comportamento.md',
            "\nGLOBAL\n"
        );
        $this->writePrompt(
            '123.md',
            "\nTENANT 123\n"
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertSame(
            'GLOBAL' . PHP_EOL . PHP_EOL . 'TENANT 123',
            $resolver->resolve(
                new PromptContext(
                    'session-1',
                    ['tenant_id' => 123]
                )
            )
        );
    }

    public function testSupportsStringTenantId(): void
    {
        $this->writePrompt(
            'comportamento.md',
            'GLOBAL'
        );
        $this->writePrompt(
            'empresa-am.md',
            'EMPRESA AM'
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertSame(
            'GLOBAL' . PHP_EOL . PHP_EOL . 'EMPRESA AM',
            $resolver->resolve(
                new PromptContext(
                    'session-1',
                    ['tenant_id' => 'empresa-am']
                )
            )
        );
    }

    public function testEmptyTenantPromptDoesNotChangeGlobal(): void
    {
        $this->writePrompt(
            'comportamento.md',
            'GLOBAL'
        );
        $this->writePrompt(
            '123.md',
            " \n "
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertSame(
            'GLOBAL',
            $resolver->resolve(
                new PromptContext(
                    'session-1',
                    ['tenant_id' => 123]
                )
            )
        );
    }

    public function testSanitizesTenantKeyBeforeBuildingPath(): void
    {
        $this->writePrompt(
            'comportamento.md',
            'GLOBAL'
        );
        $this->writePrompt(
            '.._cliente_.._segredo.md',
            'TENANT SANITIZADO'
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertSame(
            'GLOBAL'
            . PHP_EOL
            . PHP_EOL
            . 'TENANT SANITIZADO',
            $resolver->resolve(
                new PromptContext(
                    'session-1',
                    [
                        'tenant_id' =>
                            '../cliente/../segredo',
                    ]
                )
            )
        );
    }

    public function testBackslashAndOtherCharactersAreSanitized(): void
    {
        $this->writePrompt(
            'comportamento.md',
            'GLOBAL'
        );
        $this->writePrompt(
            'cliente_norte_2026.md',
            'NORTE'
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertSame(
            'GLOBAL' . PHP_EOL . PHP_EOL . 'NORTE',
            $resolver->resolve(
                new PromptContext(
                    'session-1',
                    [
                        'tenant_id' =>
                            'cliente\\norte:2026',
                    ]
                )
            )
        );
    }

    public function testBlankTenantIdFromContextFallsBackToGlobal(): void
    {
        $this->writePrompt(
            'comportamento.md',
            'GLOBAL'
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        self::assertSame(
            'GLOBAL',
            $resolver->resolve(
                new PromptContext(
                    'session-1',
                    ['tenant_id' => '   ']
                )
            )
        );
    }

    public function testFilesAreReadAgainOnEachResolve(): void
    {
        $this->writePrompt(
            'comportamento.md',
            'GLOBAL V1'
        );
        $this->writePrompt(
            '7.md',
            'TENANT V1'
        );

        $resolver = new TenantPromptResolver(
            $this->projectRoot
        );

        $context = new PromptContext(
            'session-1',
            ['tenant_id' => 7]
        );

        self::assertSame(
            'GLOBAL V1'
            . PHP_EOL
            . PHP_EOL
            . 'TENANT V1',
            $resolver->resolve($context)
        );

        $this->writePrompt(
            'comportamento.md',
            'GLOBAL V2'
        );
        $this->writePrompt(
            '7.md',
            'TENANT V2'
        );

        self::assertSame(
            'GLOBAL V2'
            . PHP_EOL
            . PHP_EOL
            . 'TENANT V2',
            $resolver->resolve($context)
        );
    }

    private function writePrompt(
        string $filename,
        string $content
    ): void {
        file_put_contents(
            $this->projectRoot
            . DIRECTORY_SEPARATOR
            . 'prompts'
            . DIRECTORY_SEPARATOR
            . $filename,
            $content
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
                . DIRECTORY_SEPARATOR
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
