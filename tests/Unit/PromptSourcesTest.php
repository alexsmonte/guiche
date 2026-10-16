<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Prompt\CallbackPromptResolver;
use Asmpkg\Guiche\Prompt\FallbackPromptResolver;
use Asmpkg\Guiche\Prompt\FilePromptResolver;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Prompt\StaticPromptResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PromptSourcesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-prompt-sources-'
            . bin2hex(random_bytes(8));

        mkdir(
            $this->directory,
            0775,
            true
        );
    }

    protected function tearDown(): void
    {
        $items = glob(
            $this->directory . '/*'
        );

        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_file($item)) {
                    @unlink($item);
                }
            }
        }

        @rmdir($this->directory);
    }

    public function testStaticPromptResolverReturnsConfiguredPrompt(): void
    {
        $resolver = new StaticPromptResolver(
            "  PROMPT ESTÁTICO  \n"
        );

        self::assertSame(
            'PROMPT ESTÁTICO',
            $resolver->resolve(
                $this->context()
            )
        );
    }

    public function testStaticPromptResolverRejectsEmptyPrompt(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new StaticPromptResolver(" \n ");
    }

    public function testFilePromptResolverReadsFileOnEveryResolve(): void
    {
        $path = $this->directory
            . '/tenant.prompt.md';

        file_put_contents(
            $path,
            "PROMPT V1\n"
        );

        $resolver = new FilePromptResolver(
            'tenant.prompt.md',
            $this->directory
        );

        self::assertSame(
            'PROMPT V1',
            $resolver->resolve(
                $this->context()
            )
        );

        file_put_contents(
            $path,
            "PROMPT V2\n"
        );

        self::assertSame(
            'PROMPT V2',
            $resolver->resolve(
                $this->context()
            )
        );
    }

    public function testFilePromptResolverFailsClosedWhenMissing(): void
    {
        $resolver = new FilePromptResolver(
            'ausente.md',
            $this->directory
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Prompt não encontrado'
        );

        $resolver->resolve(
            $this->context()
        );
    }

    public function testFilePromptResolverFailsClosedWhenEmpty(): void
    {
        file_put_contents(
            $this->directory . '/vazio.md',
            "\n  \n"
        );

        $resolver = new FilePromptResolver(
            'vazio.md',
            $this->directory
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Prompt vazio'
        );

        $resolver->resolve(
            $this->context()
        );
    }

    public function testFallbackUsesNextSourceOnlyForEmptyResult(): void
    {
        $first = new CallbackPromptResolver(
            static fn (
                PromptContext $context
            ): string => ''
        );

        $second = new StaticPromptResolver(
            'PROMPT FALLBACK'
        );

        $resolver = new FallbackPromptResolver(
            $first,
            $second
        );

        self::assertSame(
            'PROMPT FALLBACK',
            $resolver->resolve(
                $this->context()
            )
        );
    }

    public function testFallbackDoesNotSwallowSourceFailure(): void
    {
        $first = new CallbackPromptResolver(
            static function (
                PromptContext $context
            ): string {
                throw new RuntimeException(
                    'falha do repositório'
                );
            }
        );

        $second = new StaticPromptResolver(
            'NÃO DEVE SER USADO'
        );

        $resolver = new FallbackPromptResolver(
            $first,
            $second
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'falha do repositório'
        );

        $resolver->resolve(
            $this->context()
        );
    }

    public function testFallbackFailsClosedWhenAllSourcesAreEmpty(): void
    {
        $resolver = new FallbackPromptResolver(
            new CallbackPromptResolver(
                static fn (
                    PromptContext $context
                ): string => ''
            ),
            new CallbackPromptResolver(
                static fn (
                    PromptContext $context
                ): string => " \n "
            )
        );

        $this->expectException(
            RuntimeException::class
        );
        $this->expectExceptionMessage(
            'Nenhuma fonte de prompt'
        );

        $resolver->resolve(
            $this->context()
        );
    }

    public function testFallbackRequiresAtLeastOneResolver(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        new FallbackPromptResolver();
    }

    private function context(): PromptContext
    {
        return new PromptContext(
            'prompt-source-session',
            ['tenant_id' => 'tenant-1']
        );
    }
}
