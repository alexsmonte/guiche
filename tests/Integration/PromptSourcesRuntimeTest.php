<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Prompt\CallbackPromptResolver;
use Asmpkg\Guiche\Prompt\FallbackPromptResolver;
use Asmpkg\Guiche\Prompt\FilePromptResolver;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use PHPUnit\Framework\TestCase;

final class PromptSourcesRuntimeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-prompt-runtime-'
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

    public function testTenantAwareSourceFallsBackToFileWhenNotConfigured(): void
    {
        $fallbackFile = $this->directory
            . '/default.prompt.md';

        file_put_contents(
            $fallbackFile,
            "PROMPT DEFAULT DE ARQUIVO\n"
        );

        $repositoryResolver =
            new CallbackPromptResolver(
                static function (
                    PromptContext $context
                ): string {
                    return $context->tenantId()
                        === 'tenant-a'
                            ? 'PROMPT DO BANCO TENANT A'
                            : '';
                }
            );

        $resolver = new FallbackPromptResolver(
            $repositoryResolver,
            new FilePromptResolver(
                $fallbackFile
            )
        );

        $provider = new PromptSourcesFakeAi();

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore(
                    $this->directory
                        . '/context'
                )
            )
            ->withAiProvider($provider)
            ->withTenantResolver(
                new CallbackTenantResolver(
                    static function (
                        array $identity
                    ): array {
                        return [
                            'tenant_id' =>
                                (string)$identity[
                                    'tenant_id'
                                ],
                        ];
                    }
                )
            )
            ->withPromptResolver($resolver)
            ->build();

        $guiche->bindIdentity(
            'session-a',
            ['tenant_id' => 'tenant-a']
        );

        $guiche->handle(
            'session-a',
            'olá A'
        );

        $guiche->bindIdentity(
            'session-b',
            ['tenant_id' => 'tenant-b']
        );

        $guiche->handle(
            'session-b',
            'olá B'
        );

        self::assertCount(
            2,
            $provider->instructions
        );

        self::assertStringContainsString(
            'PROMPT DO BANCO TENANT A',
            $provider->instructions[0]
        );

        self::assertStringContainsString(
            'PROMPT DEFAULT DE ARQUIVO',
            $provider->instructions[1]
        );
    }

    public function testFileSourceCanChangeBetweenTurns(): void
    {
        $path = $this->directory
            . '/dynamic.prompt.md';

        file_put_contents(
            $path,
            "PROMPT PRIMEIRO\n"
        );

        $provider = new PromptSourcesFakeAi();

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore(
                    $this->directory
                        . '/context-file'
                )
            )
            ->withAiProvider($provider)
            ->withPromptResolver(
                new FilePromptResolver($path)
            )
            ->build();

        $guiche->handle(
            'file-session',
            'primeira mensagem'
        );

        file_put_contents(
            $path,
            "PROMPT SEGUNDO\n"
        );

        $guiche->handle(
            'file-session',
            'segunda mensagem'
        );

        self::assertCount(
            2,
            $provider->instructions
        );

        self::assertStringContainsString(
            'PROMPT PRIMEIRO',
            $provider->instructions[0]
        );

        self::assertStringContainsString(
            'PROMPT SEGUNDO',
            $provider->instructions[1]
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

final class PromptSourcesFakeAi
    implements AiProviderInterface
{
    /**
     * @var list<string>
     */
    public array $instructions = [];

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->instructions[] = $instructions;

        return [
            'id' => 'prompt-source-response',
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
        $this->instructions[] = $instructions;

        return [
            'id' => 'prompt-source-tool-response',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}
