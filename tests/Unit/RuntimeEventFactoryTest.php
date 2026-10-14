<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Observability\RuntimeEventFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeEventFactoryTest extends TestCase
{
    public function testBuildsCorrelatedRedactedEvent(): void
    {
        $fingerprint = hash('sha256', 'args');

        $event = RuntimeEventFactory::fromLegacy(
            'session-super-secret',
            'interaction-1',
            [
                'type' => 'tool_call',
                'call_id' => 'call-1',
                'arguments' => ['cpf' => '12345678901'],
                'arguments_fingerprint' => $fingerprint,
                'text' => 'SECRET-TEXT',
                'result' => [
                    'success' => true,
                    'payload' => 'SECRET-RESULT',
                ],
                'api_key' => 'SECRET-KEY',
            ]
        );

        self::assertSame(1, $event['schema_version']);
        self::assertSame(32, strlen($event['event_id']));
        self::assertSame(
            hash('sha256', 'session-super-secret'),
            $event['session_key']
        );
        self::assertSame('interaction-1', $event['interaction_id']);
        self::assertSame('call-1', $event['call_id']);
        self::assertSame(
            $fingerprint,
            $event['attributes']['arguments_fingerprint']
        );
        self::assertTrue($event['attributes']['success']);

        $json = json_encode($event, JSON_THROW_ON_ERROR);

        foreach ([
            'session-super-secret',
            '12345678901',
            'SECRET-TEXT',
            'SECRET-RESULT',
            'SECRET-KEY',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json);
        }
    }

    public function testNormalizesUsageWithoutRawPayload(): void
    {
        $event = RuntimeEventFactory::fromLegacy(
            'session',
            'interaction',
            [
                'type' => 'ai_response_received',
                'usage' => [
                    'input_tokens' => 7,
                    'output_tokens' => 3,
                    'total_tokens' => 10,
                ],
                'output' => 'do-not-persist',
            ]
        );

        self::assertSame(
            [
                'input_tokens' => 7,
                'output_tokens' => 3,
                'total_tokens' => 10,
            ],
            $event['attributes']['usage']
        );

        self::assertStringNotContainsString(
            'do-not-persist',
            json_encode($event, JSON_THROW_ON_ERROR)
        );
    }

    public function testMissingTypeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        RuntimeEventFactory::fromLegacy('session', 'interaction', []);
    }
}
