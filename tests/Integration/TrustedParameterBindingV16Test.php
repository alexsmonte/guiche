<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class TrustedParameterBindingV16Test extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v16-trusted-'
            . bin2hex(random_bytes(6));

        mkdir(
            $this->directory,
            0775,
            true
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        foreach (
            glob($this->directory . '/*') ?: []
            as $file
        ) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->directory);
    }

    public function testProtectedParametersAreHiddenFromAiDefinition(): void
    {
        $registration = new ToolRegistration(
            new TrustedParameterBindingTestTool(),
            $this->metadata()
        );

        $parameters =
            $registration->definition()['parameters'];

        self::assertSame(
            ['query'],
            array_keys($parameters['properties'])
        );

        self::assertSame(
            ['query'],
            $parameters['required']
        );
    }

    public function testAllTrustedSourcesOverrideForgedAiValues(): void
    {
        [$guiche, $tool] = $this->runtime();

        $result = $guiche->executeTool(
            'TrustedBindingTest',
            [
                'query' => 'horarios',
                'tenant_id' => 999,
                'session_key' => 'forged-session',
                'origin' => 'forged-origin',
                'region' => 'forged-region',
                'service_value' => 'forged-service',
            ],
            'trusted-session'
        );

        self::assertTrue($result['success']);

        self::assertSame(
            'horarios',
            $result['data']['query']
        );
        self::assertSame(
            142,
            $result['data']['tenant_id']
        );
        self::assertSame(
            'trusted-session',
            $result['data']['session_key']
        );
        self::assertSame(
            'guiche',
            $result['data']['origin']
        );
        self::assertSame(
            'AM',
            $result['data']['region']
        );
        self::assertSame(
            'service-142',
            $result['data']['service_value']
        );
        self::assertSame(1, $tool->executions);
    }

    public function testWriteReplayUsesBoundArgumentsNotForgedArguments(): void
    {
        [$guiche, $tool] = $this->runtime();

        $first = $guiche->executeTool(
            'TrustedBindingTest',
            [
                'query' => 'horarios',
                'tenant_id' => 999,
                'session_key' => 'forged-a',
                'origin' => 'forged-a',
                'region' => 'forged-a',
                'service_value' => 'forged-a',
            ],
            'trusted-session'
        );

        $second = $guiche->executeTool(
            'TrustedBindingTest',
            [
                'query' => 'horarios',
                'tenant_id' => 888,
                'session_key' => 'forged-b',
                'origin' => 'forged-b',
                'region' => 'forged-b',
                'service_value' => 'forged-b',
            ],
            'trusted-session'
        );

        self::assertSame($first, $second);
        self::assertSame(
            1,
            $tool->executions,
            'WRITE equivalente após binding deve usar replay idempotente.'
        );
    }

    public function testMissingTrustedContextFailsClosed(): void
    {
        $store = new ContextStore(
            $this->directory
        );

        (new ExecutionContextSeeder($store))->seed(
            'missing-tenant',
            [
                'identity' => [
                    'provider' => 'test',
                    'conversation_id' => '123',
                ],
            ]
        );

        $registry = new ToolRegistry();

        $registry->register(
            new ToolRegistration(
                new TrustedParameterBindingTestTool(),
                $this->metadata()
            )
        );

        $guiche = new Guiche(
            $registry,
            $store
        );

        try {
            $guiche->executeTool(
                'TrustedBindingTest',
                ['query' => 'horarios'],
                'missing-tenant'
            );

            self::fail(
                'Binding deveria falhar sem tenant_id.'
            );
        } catch (ToolBlockedException $exception) {
            self::assertSame(
                'trusted_parameter_unavailable',
                $exception->reasonCode()
            );

            self::assertSame(
                'tenant_id',
                $exception->details()['parameter']
                    ?? null
            );
        }
    }

    public function testToolWithoutTrustedParametersKeepsLegacyDefinition(): void
    {
        $tool = new TrustedParameterBindingLegacyTool();

        $registration = new ToolRegistration(
            $tool,
            ['operation' => 'read']
        );

        self::assertSame(
            $tool->parameters(),
            $registration->definition()['parameters']
        );
    }

    /**
     * @return array{0: Guiche, 1: TrustedParameterBindingTestTool}
     */
    private function runtime(): array
    {
        $store = new ContextStore(
            $this->directory
        );

        (new ExecutionContextSeeder($store))->seed(
            'trusted-session',
            [
                'identity' => [
                    'provider' => 'test',
                    'conversation_id' => '42',
                ],
                'tenant_id' => 142,
            ]
        );

        $tool = new TrustedParameterBindingTestTool();
        $registry = new ToolRegistry();

        $registry->register(
            new ToolRegistration(
                $tool,
                array_merge(
                    [
                        'operation' => 'write',
                    ],
                    $this->metadata()
                )
            )
        );

        return [
            new Guiche(
                $registry,
                $store
            ),
            $tool,
        ];
    }

    /** @return array<mixed> */
    private function metadata(): array
    {
        return [
            'trusted_parameter_config' => [
                'region' => 'AM',
            ],

            'trusted_parameters' => [
                'tenant_id' => [
                    'source' => 'context',
                    'path' => 'tenant_id',
                    'visible' => false,
                ],

                'session_key' => [
                    'source' => 'context',
                    'path' => 'session_id',
                    'visible' => false,
                ],

                'origin' => [
                    'source' => 'fixed',
                    'value' => 'guiche',
                    'visible' => false,
                ],

                'region' => [
                    'source' => 'config',
                    'path' => 'region',
                    'visible' => false,
                ],

                'service_value' => [
                    'source' => 'service',
                    'visible' => false,
                    'resolver' => static function (
                        ?ToolExecutionContext $context
                    ): string {
                        return 'service-'
                            . (string)$context?->tenantId();
                    },
                ],
            ],
        ];
    }
}

final class TrustedParameterBindingTestTool implements ToolInterface
{
    public int $executions = 0;

    public function name(): string
    {
        return 'TrustedBindingTest';
    }

    public function description(): string
    {
        return 'Tool sintética para Trusted Parameter Binding.';
    }

    /** @return array<mixed> */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                ],
                'tenant_id' => [
                    'type' => 'integer',
                ],
                'session_key' => [
                    'type' => 'string',
                ],
                'origin' => [
                    'type' => 'string',
                ],
                'region' => [
                    'type' => 'string',
                ],
                'service_value' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'query',
                'tenant_id',
                'session_key',
                'origin',
                'region',
                'service_value',
            ],
            'additionalProperties' => false,
        ];
    }

    /** @param array<mixed> $arguments */
    public function execute(array $arguments): array
    {
        $this->executions++;

        return [
            'success' => true,
            'tool' => $this->name(),
            'data' => $arguments,
        ];
    }
}

final class TrustedParameterBindingLegacyTool implements ToolInterface
{
    public function name(): string
    {
        return 'LegacyBindingTest';
    }

    public function description(): string
    {
        return 'Tool legada sem binding.';
    }

    /** @return array<mixed> */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'string',
                ],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
    }

    /** @param array<mixed> $arguments */
    public function execute(array $arguments): array
    {
        return [
            'success' => true,
            'data' => $arguments,
        ];
    }
}
