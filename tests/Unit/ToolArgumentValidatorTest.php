<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Safety\ToolArgumentValidator;
use PHPUnit\Framework\TestCase;

final class ToolArgumentValidatorTest extends TestCase
{
    private array $schema = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string', 'minLength' => 2],
            'mode' => [
                'type' => 'string',
                'enum' => ['read', 'write'],
            ],
        ],
        'required' => ['id', 'name'],
        'additionalProperties' => false,
    ];

    public function testValidArgumentsPass(): void
    {
        self::assertSame(
            [],
            (new ToolArgumentValidator())->validate(
                $this->schema,
                [
                    'id' => 10,
                    'name' => 'Alex',
                    'mode' => 'read',
                ]
            )
        );
    }

    public function testMissingRequiredFieldIsReported(): void
    {
        $errors = (new ToolArgumentValidator())->validate(
            $this->schema,
            ['id' => 10]
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString('$.name', $errors[0]);
    }

    public function testWrongTypeAndEnumAreReported(): void
    {
        $validator = new ToolArgumentValidator();

        $wrongType = $validator->validate(
            $this->schema,
            ['id' => '10', 'name' => 'Alex']
        );

        self::assertNotEmpty($wrongType);
        self::assertStringContainsString('integer', implode(' ', $wrongType));

        $wrongEnum = $validator->validate(
            $this->schema,
            ['id' => 10, 'name' => 'Alex', 'mode' => 'destroy']
        );

        self::assertNotEmpty($wrongEnum);
    }

    public function testAdditionalPropertiesFalseIsEnforced(): void
    {
        $errors = (new ToolArgumentValidator())->validate(
            $this->schema,
            [
                'id' => 10,
                'name' => 'Alex',
                'dangerous' => true,
            ]
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString(
            'não é um argumento permitido',
            $errors[0]
        );
    }
}
