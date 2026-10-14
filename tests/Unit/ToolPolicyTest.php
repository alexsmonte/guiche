<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\ToolPolicy;
use PHPUnit\Framework\TestCase;

final class ToolPolicyTest extends TestCase
{
    public function testUsesGenericContextPathBootstrapStageAndFallback(): void
    {
        $policy = new ToolPolicy(
            stages: [
                'draft' => ['SearchCatalog'],
                'checkout' => ['CreateOrder'],
            ],
            defaultTools: ['FallbackTool'],
            bootstrapTools: ['StartFlow'],
            contextPath: 'workflow.phase'
        );

        self::assertSame(['StartFlow'], $policy->allowedToolNames([]));
        self::assertSame(
            ['SearchCatalog'],
            $policy->allowedToolNames(['workflow' => ['phase' => 'draft']])
        );
        self::assertSame(
            ['CreateOrder'],
            $policy->allowedToolNames(['workflow' => ['phase' => 'checkout']])
        );
        self::assertSame(
            ['FallbackTool'],
            $policy->allowedToolNames(['workflow' => ['phase' => 'unknown']])
        );
    }

    public function testDefaultContextPathIsNeutralStateStage(): void
    {
        $policy = new ToolPolicy(
            stages: ['ready' => ['GenericTool']],
            bootstrapTools: ['BootstrapTool']
        );

        self::assertSame(
            ['GenericTool'],
            $policy->allowedToolNames(['state' => ['stage' => 'ready']])
        );
    }

    public function testNormalizesDuplicatesWhitespaceAndEmptyNames(): void
    {
        $policy = new ToolPolicy(
            stages: [
                'ready' => [' ToolA ', '', 'ToolA', 'ToolB'],
            ]
        );

        self::assertSame(
            ['ToolA', 'ToolB'],
            $policy->allowedToolNames(['state' => ['stage' => 'ready']])
        );
    }

    public function testAllowsAndFiltersDefinitions(): void
    {
        $policy = new ToolPolicy(
            stages: ['ready' => ['ToolB', 'ToolA']]
        );

        $context = ['state' => ['stage' => 'ready']];
        $definitions = [
            ['name' => 'ToolA', 'description' => 'A'],
            ['name' => 'Denied', 'description' => 'X'],
            ['name' => 'ToolB', 'description' => 'B'],
            ['description' => 'unnamed'],
        ];

        self::assertTrue($policy->allows('ToolA', $context));
        self::assertFalse($policy->allows('Denied', $context));
        self::assertSame(
            ['ToolA', 'ToolB'],
            array_column($policy->filterDefinitions($definitions, $context), 'name')
        );
    }
}
