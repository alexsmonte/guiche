<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Safety\ToolSafetyMetadata;
use PHPUnit\Framework\TestCase;

final class ToolSafetyMetadataTest extends TestCase
{
    public function testOperationDefaultsToReadAndUnknownFailsClosed(): void
    {
        self::assertSame(
            ToolSafetyMetadata::OPERATION_READ,
            ToolSafetyMetadata::operation([])
        );
        self::assertTrue(
            ToolSafetyMetadata::isWrite(['operation' => 'WRITE'])
        );
        self::assertSame(
            ToolSafetyMetadata::OPERATION_UNKNOWN,
            ToolSafetyMetadata::operation(['operation' => 'delete-everything'])
        );
    }

    public function testAllowedStagesAreNormalized(): void
    {
        self::assertSame(
            ['draft', 'ready'],
            ToolSafetyMetadata::allowedStages([
                'allowed_stages' => [' draft ', 'ready', 'draft', ''],
            ])
        );
    }

    public function testStateConditionsSupportExistsAndEquals(): void
    {
        self::assertSame(
            [
                ['path' => 'state.user_id', 'operator' => 'exists'],
                [
                    'path' => 'state.confirmed',
                    'operator' => 'equals',
                    'value' => true,
                ],
                [
                    'path' => 'state.scope',
                    'operator' => 'equals',
                    'value' => 'booking',
                ],
            ],
            ToolSafetyMetadata::requiredState([
                'requires_state' => [
                    'state.user_id',
                    'state.confirmed' => true,
                    ['path' => 'state.scope', 'value' => 'booking'],
                ],
            ])
        );

        self::assertCount(
            2,
            ToolSafetyMetadata::forbiddenState([
                'forbids_state' => [
                    'state.completed',
                    'state.cancelled' => true,
                ],
            ])
        );
    }

    public function testConfirmationIsNormalizedDeclaratively(): void
    {
        self::assertSame(
            [
                'path' => 'state.confirmed_by_user',
                'operator' => 'equals',
                'value' => true,
            ],
            ToolSafetyMetadata::confirmation([
                'confirmation' => [
                    'path' => 'state.confirmed_by_user',
                    'value' => true,
                ],
            ])
        );

        self::assertSame(
            [
                'path' => 'state.confirmed',
                'operator' => 'equals',
                'value' => true,
            ],
            ToolSafetyMetadata::confirmation(['confirmation' => true])
        );
    }
}
