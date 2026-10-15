<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Providers\OpenAiResponsesProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OpenAiResponsesProviderTest extends TestCase
{
    public function testOptionalToolArgumentsAreNotForcedIntoStrictMode(): void
    {
        $provider = new OpenAiResponsesProvider('test-api-key');

        $method = new ReflectionMethod(
            $provider,
            'normalizeTools'
        );

        $normalized = $method->invoke(
            $provider,
            [
                [
                    'name' => 'CatalogSearch',
                    'description' => 'Consulta catálogo.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                            ],
                        ],
                        'required' => [],
                        'additionalProperties' => false,
                    ],
                ],
            ]
        );

        self::assertIsArray($normalized);
        self::assertCount(1, $normalized);

        self::assertSame(
            'CatalogSearch',
            $normalized[0]['name']
        );

        self::assertFalse(
            $normalized[0]['strict']
        );

        self::assertSame(
            [],
            $normalized[0]['parameters']['required']
        );

        self::assertArrayHasKey(
            'query',
            $normalized[0]['parameters']['properties']
        );
    }

    public function testEmptyToolPropertiesAreSerializedAsJsonObject(): void
    {
        $provider = new OpenAiResponsesProvider('test-api-key');

        $method = new ReflectionMethod(
            $provider,
            'normalizeTools'
        );

        $normalized = $method->invoke(
            $provider,
            [
                [
                    'name' => 'CompanyInfo',
                    'description' => 'Retorna dados da empresa.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [],
                        'required' => [],
                        'additionalProperties' => false,
                    ],
                ],
            ]
        );

        self::assertInstanceOf(
            \stdClass::class,
            $normalized[0]['parameters']['properties']
        );

        $json = json_encode(
            $normalized[0]['parameters'],
            JSON_THROW_ON_ERROR
        );

        self::assertStringContainsString(
            '"properties":{}',
            $json
        );
    }

    public function testDefaultToolSchemaUsesJsonObjectForEmptyProperties(): void
    {
        $provider = new OpenAiResponsesProvider('test-api-key');

        $method = new ReflectionMethod(
            $provider,
            'normalizeTools'
        );

        $normalized = $method->invoke(
            $provider,
            [
                [
                    'name' => 'Ping',
                    'description' => 'Ping sem argumentos.',
                ],
            ]
        );

        self::assertInstanceOf(
            \stdClass::class,
            $normalized[0]['parameters']['properties']
        );

        self::assertSame(
            [],
            $normalized[0]['parameters']['required']
        );
    }
}
