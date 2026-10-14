<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Tests\Fixtures\FakeTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ToolRegistrationTest extends TestCase
{
    public function testPreservesToolMetadataAndProviderDefinitionBoundaries(): void
    {
        $tool = new FakeTool(
            toolName: ' Echo ',
            result: ['success' => true],
            toolDescription: 'Echo description',
            parameters: [
                'type' => 'object',
                'properties' => ['value' => ['type' => 'string']],
                'required' => ['value'],
            ]
        );

        $registration = new ToolRegistration(
            $tool,
            ['source' => 'test', 'operation' => 'read']
        );

        self::assertSame($tool, $registration->tool());
        self::assertSame('Echo', $registration->name());
        self::assertSame(
            ['source' => 'test', 'operation' => 'read'],
            $registration->metadata()
        );
        self::assertSame(
            [
                'name' => 'Echo',
                'description' => 'Echo description',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['value' => ['type' => 'string']],
                    'required' => ['value'],
                ],
            ],
            $registration->definition()
        );
        self::assertArrayNotHasKey('metadata', $registration->definition());
    }

    public function testRejectsBlankToolName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistration(new FakeTool('   '));
    }
}
