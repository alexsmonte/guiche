<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DynamicPromptResolutionTest extends TestCase
{
    private string $contextDirectory;

    protected function setUp(): void
    {
        $this->contextDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'guiche-v14-prompt-'
            . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->contextDirectory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->contextDirectory,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($this->contextDirectory);
    }

    public function testClosureResolverUsesTrustedTenantAndRunsOncePerTurn(): void
    {
        $provider = new class implements AiProviderInterface {
            /** @var list<string> */
            public array $instructions = [];

            public function respond(
                string $instructions,
                array $messages,
                array $tools = []
            ): array {
                $this->instructions[] = $instructions;

                return [
                    'id' => 'response-1',
                    'text' => '',
                    'tool_calls' => [
                        [
                            'call_id' => 'call-1',
                            'name' => 'Probe',
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
                    'id' => 'response-2',
                    'text' => 'ok',
                    'tool_calls' => [],
                    'usage' => [],
                ];
            }
        };

        $tool = new class implements ToolInterface {
            public function name(): string
            {
                return 'Probe';
            }

            public function description(): string
            {
                return 'Probe neutra para teste.';
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
                    'value' => 'ok',
                ];
            }
        };

        $resolverCalls = 0;
        $receivedContext = null;

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore($this->contextDirectory)
            )
            ->withAiProvider($provider)
            ->withTenantResolver(
                new CallbackTenantResolver(
                    static function (array $identity): array {
                        return [
                            'tenant_id' =>
                                'tenant-' . $identity['external_id'],
                        ];
                    }
                )
            )
            ->withInstructions('REGRA GLOBAL')
            ->addInstructionFragment(
                'plugin-probe',
                'REGRA DO PLUGIN'
            )
            ->withPromptResolver(
                static function (
                    PromptContext $context
                ) use (
                    &$resolverCalls,
                    &$receivedContext
                ): string {
                    $resolverCalls++;
                    $receivedContext = $context;

                    return 'PROMPT DO TENANT '
                        . (string) $context->tenantId();
                }
            )
            ->registerTool($tool)
            ->build();

        $guiche->bindIdentity(
            'session-dynamic',
            ['external_id' => '42']
        );

        $result = $guiche->handle(
            'session-dynamic',
            'MENSAGEM_CONTROLADA_PELO_USUARIO'
        );

        self::assertSame('ok', $result['message']);
        self::assertSame(1, $resolverCalls);
        self::assertInstanceOf(
            PromptContext::class,
            $receivedContext
        );
        self::assertSame(
            'session-dynamic',
            $receivedContext->sessionId()
        );
        self::assertSame(
            'tenant-42',
            $receivedContext->tenantId()
        );
        self::assertSame(
            ['external_id' => '42'],
            $receivedContext->get('identity')
        );

        self::assertCount(2, $provider->instructions);
        self::assertSame(
            $provider->instructions[0],
            $provider->instructions[1]
        );

        self::assertStringContainsString(
            'PROMPT DO TENANT tenant-42',
            $provider->instructions[0]
        );
        self::assertStringContainsString(
            'REGRA GLOBAL',
            $provider->instructions[0]
        );
        self::assertStringContainsString(
            'REGRA DO PLUGIN',
            $provider->instructions[0]
        );
        self::assertStringNotContainsString(
            'MENSAGEM_CONTROLADA_PELO_USUARIO',
            $provider->instructions[0]
        );
    }

    public function testLegacyStaticInstructionsRemainCompatible(): void
    {
        $provider = new class implements AiProviderInterface {
            public string $instructions = '';

            public function respond(
                string $instructions,
                array $messages,
                array $tools = []
            ): array {
                $this->instructions = $instructions;

                return [
                    'id' => 'legacy-response',
                    'text' => 'legacy-ok',
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
        };

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore($this->contextDirectory)
            )
            ->withAiProvider($provider)
            ->withInstructions('PROMPT ESTÁTICO LEGADO')
            ->build();

        $result = $guiche->handle(
            'session-legacy',
            'olá'
        );

        self::assertSame(
            'legacy-ok',
            $result['message']
        );
        self::assertStringContainsString(
            'PROMPT ESTÁTICO LEGADO',
            $provider->instructions
        );
    }

    public function testEmptyDynamicPromptFailsClosedBeforeAiCall(): void
    {
        $provider = new class implements AiProviderInterface {
            public int $calls = 0;

            public function respond(
                string $instructions,
                array $messages,
                array $tools = []
            ): array {
                $this->calls++;

                return [
                    'id' => 'unexpected',
                    'text' => 'unexpected',
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
                $this->calls++;

                return [
                    'id' => 'unexpected',
                    'text' => 'unexpected',
                    'tool_calls' => [],
                    'usage' => [],
                ];
            }
        };

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore($this->contextDirectory)
            )
            ->withAiProvider($provider)
            ->withPromptResolver(
                static fn (PromptContext $context): string => '   '
            )
            ->build();

        try {
            $guiche->handle(
                'session-empty',
                'mensagem'
            );

            self::fail(
                'Prompt vazio deveria falhar fechado.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'prompt vazio',
                strtolower($exception->getMessage())
            );
        }

        self::assertSame(0, $provider->calls);
    }

    public function testTenantBackedPromptRequiresTrustedIdentity(): void
    {
        $provider = new class implements AiProviderInterface {
            public int $calls = 0;

            public function respond(
                string $instructions,
                array $messages,
                array $tools = []
            ): array {
                $this->calls++;

                return [
                    'id' => 'unexpected',
                    'text' => 'unexpected',
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
                $this->calls++;

                return [
                    'id' => 'unexpected',
                    'text' => 'unexpected',
                    'tool_calls' => [],
                    'usage' => [],
                ];
            }
        };

        $resolverCalled = false;

        $guiche = (new GuicheBuilder())
            ->withContextStore(
                new ContextStore($this->contextDirectory)
            )
            ->withAiProvider($provider)
            ->withTenantResolver(
                new CallbackTenantResolver(
                    static fn (array $identity): array => [
                        'tenant_id' => 'tenant-x',
                    ]
                )
            )
            ->withPromptResolver(
                static function (
                    PromptContext $context
                ) use (&$resolverCalled): string {
                    $resolverCalled = true;

                    return 'não deveria resolver';
                }
            )
            ->build();

        try {
            $guiche->handle(
                'session-without-identity',
                'mensagem'
            );

            self::fail(
                'Tenant prompt sem identidade deveria falhar fechado.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Identidade confiável ausente',
                $exception->getMessage()
            );
        }

        self::assertFalse($resolverCalled);
        self::assertSame(0, $provider->calls);
    }
}
