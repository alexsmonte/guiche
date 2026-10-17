<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Prompt\CallbackPromptResolver;
use Asmpkg\Guiche\Prompt\CompositePromptResolver;
use Asmpkg\Guiche\Prompt\FallbackPromptResolver;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Prompt\StaticPromptResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MultiplePromptsV16Test extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir()
            . '/guiche-v16-prompts-'
            . bin2hex(random_bytes(8));

        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    public function testCompositePreservesOrderAndSkipsEmpty(): void
    {
        $resolver = new CompositePromptResolver(
            new StaticPromptResolver('PRIMEIRO'),
            new CallbackPromptResolver(
                static fn (PromptContext $context): string => '   '
            ),
            new StaticPromptResolver('TERCEIRO')
        );

        self::assertSame(
            "PRIMEIRO\n\nTERCEIRO",
            $resolver->resolve(
                new PromptContext('session-order', [])
            )
        );
    }

    public function testCompositeRejectsEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompositePromptResolver();
    }

    public function testCompositeFailsWhenAllSourcesAreEmpty(): void
    {
        $resolver = new CompositePromptResolver(
            new CallbackPromptResolver(
                static fn (PromptContext $context): string => ''
            ),
            new CallbackPromptResolver(
                static fn (PromptContext $context): string => '   '
            )
        );

        $this->expectException(RuntimeException::class);

        $resolver->resolve(
            new PromptContext('session-empty', [])
        );
    }

    public function testFailureStopsComposition(): void
    {
        $laterCalls = 0;

        $resolver = new CompositePromptResolver(
            new CallbackPromptResolver(
                static function (PromptContext $context): string {
                    throw new RuntimeException('source unavailable');
                }
            ),
            new CallbackPromptResolver(
                static function (
                    PromptContext $context
                ) use (&$laterCalls): string {
                    $laterCalls++;

                    return 'NÃO DEVERIA RODAR';
                }
            )
        );

        try {
            $resolver->resolve(
                new PromptContext('session-failure', [])
            );

            self::fail('Falha deveria interromper composição.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'source unavailable',
                $exception->getMessage()
            );
        }

        self::assertSame(0, $laterCalls);
    }

    public function testFallbackCanBeNestedInsideComposite(): void
    {
        $resolver = new CompositePromptResolver(
            new StaticPromptResolver('GLOBAL'),
            new FallbackPromptResolver(
                new CallbackPromptResolver(
                    static fn (PromptContext $context): string => ''
                ),
                new StaticPromptResolver('DEFAULT TENANT')
            ),
            new StaticPromptResolver('COMPLIANCE')
        );

        self::assertSame(
            "GLOBAL\n\nDEFAULT TENANT\n\nCOMPLIANCE",
            $resolver->resolve(
                new PromptContext('session-nested', [])
            )
        );
    }

    public function testBuilderComposesMixedResolversOncePerTurn(): void
    {
        $provider = new MultiplePromptsAi();
        $tool = new MultiplePromptsProbeTool();

        $firstCalls = 0;
        $secondCalls = 0;

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore(
                    $this->directory . '/context-builder'
                )
            )
            ->withAiProvider($provider)
            ->withPromptResolvers(
                new CallbackPromptResolver(
                    static function (
                        PromptContext $context
                    ) use (&$firstCalls): string {
                        $firstCalls++;

                        return 'PROMPT GLOBAL';
                    }
                ),
                static function (
                    PromptContext $context
                ) use (&$secondCalls): string {
                    $secondCalls++;

                    return 'PROMPT SESSION '
                        . $context->sessionId();
                },
                new StaticPromptResolver('PROMPT FINAL')
            )
            ->withInstructions('INSTRUÇÃO LEGADA')
            ->registerTool($tool)
            ->build();

        $result = $guiche->handle(
            'session-composite-builder',
            'olá'
        );

        self::assertSame('ok', $result['message']);
        self::assertSame(1, $firstCalls);
        self::assertSame(1, $secondCalls);
        self::assertSame(1, $tool->executions);

        self::assertCount(2, $provider->instructions);
        self::assertSame(
            $provider->instructions[0],
            $provider->instructions[1]
        );

        $instructions = $provider->instructions[0];

        $global = strpos($instructions, 'PROMPT GLOBAL');
        $session = strpos(
            $instructions,
            'PROMPT SESSION session-composite-builder'
        );
        $final = strpos($instructions, 'PROMPT FINAL');
        $legacy = strpos($instructions, 'INSTRUÇÃO LEGADA');

        self::assertIsInt($global);
        self::assertIsInt($session);
        self::assertIsInt($final);
        self::assertIsInt($legacy);

        self::assertTrue($global < $session);
        self::assertTrue($session < $final);
        self::assertTrue($final < $legacy);
    }

    public function testBuilderRejectsEmptyComposition(): void
    {
        $builder = new GuicheBuilder();

        $this->expectException(InvalidArgumentException::class);

        $builder->withPromptResolvers();
    }

    public function testLegacyWithPromptResolverStillWorks(): void
    {
        $provider = new MultiplePromptsSimpleAi();

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore(
                    $this->directory . '/context-legacy'
                )
            )
            ->withAiProvider($provider)
            ->withPromptResolver(
                new StaticPromptResolver('PROMPT ÚNICO LEGADO')
            )
            ->build();

        $result = $guiche->handle(
            'session-legacy-prompt',
            'oi'
        );

        self::assertSame('ok', $result['message']);
        self::assertStringContainsString(
            'PROMPT ÚNICO LEGADO',
            $provider->instructions
        );
    }

    public function testLastPromptConfigurationWins(): void
    {
        $provider = new MultiplePromptsSimpleAi();

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore(
                    $this->directory . '/context-last-wins'
                )
            )
            ->withAiProvider($provider)
            ->withPromptResolvers(
                new StaticPromptResolver('PROMPT A'),
                new StaticPromptResolver('PROMPT B')
            )
            ->withPromptResolver(
                new StaticPromptResolver('PROMPT FINAL ÚNICO')
            )
            ->build();

        $guiche->handle('session-last-wins', 'oi');

        self::assertStringContainsString(
            'PROMPT FINAL ÚNICO',
            $provider->instructions
        );
        self::assertStringNotContainsString(
            'PROMPT A',
            $provider->instructions
        );
        self::assertStringNotContainsString(
            'PROMPT B',
            $provider->instructions
        );
    }
}

final class MultiplePromptsProbeTool implements ToolInterface
{
    public int $executions = 0;

    public function name(): string
    {
        return 'MultiplePromptsProbe';
    }

    public function description(): string
    {
        return 'Probe neutra.';
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
        $this->executions++;

        return [
            'success' => true,
            'value' => 'probe',
        ];
    }
}

final class MultiplePromptsAi implements AiProviderInterface
{
    /** @var list<string> */
    public array $instructions = [];

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->instructions[] = $instructions;

        return [
            'id' => 'multiple-prompts-first',
            'text' => '',
            'tool_calls' => [
                [
                    'call_id' => 'probe-call',
                    'name' => 'MultiplePromptsProbe',
                    'arguments' => [],
                ],
            ],
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
            'id' => 'multiple-prompts-done',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}

final class MultiplePromptsSimpleAi implements AiProviderInterface
{
    public string $instructions = '';

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->instructions = $instructions;

        return [
            'id' => 'multiple-prompts-simple',
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
        throw new RuntimeException(
            'Não deveria continuar neste teste.'
        );
    }
}
