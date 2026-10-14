<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Tests\Fixtures\FakeTool;
use Asmpkg\Guiche\Tests\Fixtures\InMemoryContextStore;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ResultProjectionTest extends TestCase
{
    private array $fullResult = [
        'success' => true,
        'public' => [
            'id' => 42,
            'label' => 'visible',
            'internal_hint' => 'do-not-send',
        ],
        'secret' => [
            'token' => 'TOP-SECRET',
            'document' => 'INTERNAL-DOCUMENT',
        ],
        'raw' => [
            'provider_payload' => 'raw-data',
        ],
    ];

    public function testIncludePathsAreWhitelistAndExcludeWins(): void
    {
        $guiche = $this->guiche(
            include: [
                'ProjectionTool' => [
                    'success',
                    'public.id',
                    'public.label',
                    'public.internal_hint',
                    'secret.token',
                ],
            ],
            exclude: [
                'ProjectionTool' => [
                    'public.internal_hint',
                    'secret.token',
                ],
            ]
        );

        self::assertSame(
            [
                'success' => true,
                'public' => [
                    'id' => 42,
                    'label' => 'visible',
                ],
            ],
            $guiche->toolResultForAi('ProjectionTool', $this->fullResult)
        );
    }

    public function testExcludeOnlyKeepsLegacyRemainder(): void
    {
        $guiche = $this->guiche(
            exclude: [
                'ProjectionTool' => [
                    'secret.token',
                    'raw.provider_payload',
                ],
            ]
        );

        $projected = $guiche->toolResultForAi(
            'ProjectionTool',
            $this->fullResult
        );

        self::assertSame(42, $projected['public']['id']);
        self::assertSame(
            'INTERNAL-DOCUMENT',
            $projected['secret']['document']
        );
        self::assertArrayNotHasKey('token', $projected['secret']);
        self::assertFalse(
            isset($projected['raw']['provider_payload'])
        );
    }

    public function testBlockedResultUsesMinimalSafetyEnvelope(): void
    {
        $guiche = $this->guiche(
            include: [
                'ProjectionTool' => ['success'],
            ]
        );

        self::assertSame(
            [
                'success' => false,
                'tool' => 'ProjectionTool',
                'blocked' => true,
                'reason' => 'policy_denied',
                'error' => 'blocked',
            ],
            $guiche->toolResultForAi(
                'ProjectionTool',
                [
                    'success' => false,
                    'tool' => 'ProjectionTool',
                    'blocked' => true,
                    'reason' => 'policy_denied',
                    'error' => 'blocked',
                    'details' => ['internal' => true],
                    'secret' => 'do-not-send',
                ]
            )
        );
    }

    public function testBuilderMergesDirectAndDeclarativeProjection(): void
    {
        $tool = new FakeTool('ProjectionTool');

        $guiche = (new GuicheBuilder())
            ->withContextStore(new InMemoryContextStore())
            ->registerTool(
                new ToolRegistration(
                    $tool,
                    ['operation' => 'read']
                )
            )
            ->withAiToolResultIncludePaths([
                'ProjectionTool' => ['success'],
            ])
            ->withAiToolResultExcludePaths([
                'ProjectionTool' => ['public.internal_hint'],
            ])
            ->registerResultProjection(
                'ProjectionTool',
                [
                    'include_paths' => [
                        'public.id',
                        'public.label',
                        'secret.token',
                    ],
                    'exclude_paths' => [
                        'secret.token',
                    ],
                ]
            )
            ->build();

        self::assertSame(
            [
                'success' => true,
                'public' => [
                    'id' => 42,
                    'label' => 'visible',
                ],
            ],
            $guiche->toolResultForAi(
                'ProjectionTool',
                $this->fullResult
            )
        );
    }

    private function guiche(
        array $include = [],
        array $exclude = []
    ): Guiche {
        $registry = new ToolRegistry();
        $registry->register(
            new ToolRegistration(
                new FakeTool('ProjectionTool'),
                ['operation' => 'read']
            )
        );

        return new Guiche(
            tools: $registry,
            context: new InMemoryContextStore(),
            aiToolResultExcludePaths: $exclude,
            aiToolResultIncludePaths: $include
        );
    }
}
