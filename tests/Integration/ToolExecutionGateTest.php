<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\Safety\ToolExecutionGate;
use Asmpkg\Guiche\Tests\Fixtures\FakeTool;
use Asmpkg\Guiche\ToolPolicy;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolExecutionGateTest extends TestCase
{
    public function testMissingToolAndUnknownOperationFailClosed(): void
    {
        $registry = new ToolRegistry();
        $gate = new ToolExecutionGate($registry);

        $this->assertBlocked(
            fn () => $gate->assertCanExecute('Missing', [], []),
            'tool_not_registered'
        );

        $registry->register(
            new ToolRegistration(
                new FakeTool('UnknownOperation'),
                ['operation' => 'delete-everything']
            )
        );

        $this->assertBlocked(
            fn () => $gate->assertCanExecute(
                'UnknownOperation',
                [],
                []
            ),
            'operation_invalid'
        );
    }

    public function testPolicyAndAllowedStagesAreEnforcedBeforeExecution(): void
    {
        $tool = new FakeTool('PolicyTool');

        $registry = new ToolRegistry();
        $registry->register(
            new ToolRegistration(
                $tool,
                [
                    'operation' => 'read',
                    'allowed_stages' => ['ready'],
                    'stage_path' => 'workflow.phase',
                ]
            )
        );

        $policy = new ToolPolicy(
            stages: [
                'allowed' => ['PolicyTool'],
                'denied' => [],
            ],
            contextPath: 'state.stage'
        );

        $gate = new ToolExecutionGate($registry, $policy);

        $gate->assertCanExecute(
            'PolicyTool',
            [],
            [
                'state' => ['stage' => 'allowed'],
                'workflow' => ['phase' => 'ready'],
            ]
        );

        self::assertSame([], $tool->executions);

        $this->assertBlocked(
            fn () => $gate->assertCanExecute(
                'PolicyTool',
                [],
                [
                    'state' => ['stage' => 'denied'],
                    'workflow' => ['phase' => 'ready'],
                ]
            ),
            'policy_denied'
        );

        $this->assertBlocked(
            fn () => $gate->assertCanExecute(
                'PolicyTool',
                [],
                [
                    'state' => ['stage' => 'allowed'],
                    'workflow' => ['phase' => 'draft'],
                ]
            ),
            'stage_not_allowed'
        );

        self::assertSame([], $tool->executions);
    }

    public function testRequiresForbidsConfirmationAndArgumentsAreEnforced(): void
    {
        $tool = new FakeTool(
            toolName: 'WriteTool',
            result: ['success' => true],
            parameters: [
                'type' => 'object',
                'properties' => [
                    'value' => [
                        'type' => 'string',
                        'minLength' => 1,
                    ],
                ],
                'required' => ['value'],
                'additionalProperties' => false,
            ]
        );

        $registry = new ToolRegistry();
        $registry->register(
            new ToolRegistration(
                $tool,
                [
                    'operation' => 'write',
                    'requires_state' => [
                        'state.customer_id',
                        'state.ready' => true,
                    ],
                    'forbids_state' => [
                        'state.completed',
                    ],
                    'confirmation' => 'state.confirmed',
                ]
            )
        );

        $gate = new ToolExecutionGate($registry);

        $this->assertBlocked(
            fn () => $gate->assertCanExecute(
                'WriteTool',
                ['value' => 'ok'],
                [
                    'state' => [
                        'ready' => true,
                        'confirmed' => true,
                    ],
                ]
            ),
            'required_state_missing'
        );

        $this->assertBlocked(
            fn () => $gate->assertCanExecute(
                'WriteTool',
                ['value' => 'ok'],
                [
                    'state' => [
                        'customer_id' => 10,
                        'ready' => true,
                        'confirmed' => true,
                        'completed' => false,
                    ],
                ]
            ),
            'forbidden_state_present'
        );

        $this->assertBlocked(
            fn () => $gate->assertCanExecute(
                'WriteTool',
                ['value' => 'ok'],
                [
                    'state' => [
                        'customer_id' => 10,
                        'ready' => true,
                        'confirmed' => false,
                    ],
                ]
            ),
            'confirmation_required'
        );

        $blocked = $this->assertBlocked(
            fn () => $gate->assertCanExecute(
                'WriteTool',
                [],
                [
                    'state' => [
                        'customer_id' => 10,
                        'ready' => true,
                        'confirmed' => true,
                    ],
                ]
            ),
            'invalid_arguments'
        );

        self::assertNotEmpty($blocked->details()['errors'] ?? []);

        $gate->assertCanExecute(
            'WriteTool',
            ['value' => 'ok'],
            [
                'state' => [
                    'customer_id' => 10,
                    'ready' => true,
                    'confirmed' => true,
                ],
            ]
        );

        self::assertSame([], $tool->executions);
    }

    private function assertBlocked(
        callable $callback,
        string $reason
    ): ToolBlockedException {
        try {
            $callback();
        } catch (ToolBlockedException $exception) {
            self::assertSame($reason, $exception->reasonCode());

            return $exception;
        }

        self::fail("Expected ToolBlockedException reason={$reason}.");
    }
}
