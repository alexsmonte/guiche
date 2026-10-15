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
}
