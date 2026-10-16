<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Observability;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class RuntimeEventFactory
{
    private const SCHEMA_VERSION = 1;

    /**
     * @param array<mixed> $legacyEvent
     * @return array<mixed>
     */
    public static function fromLegacy(
        string $sessionId,
        string $interactionId,
        array $legacyEvent
    ): array {
        $type = trim(
            (string) ($legacyEvent['type'] ?? '')
        );

        if ($type === '') {
            throw new RuntimeException(
                'Evento do runtime precisa possuir type.'
            );
        }

        $event = [
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => bin2hex(random_bytes(16)),
            'type' => $type,
            'occurred_at' => (
                new DateTimeImmutable(
                    'now',
                    new DateTimeZone('UTC')
                )
            )->format(DATE_ATOM),
            'session_key' => hash(
                'sha256',
                $sessionId
            ),
            'interaction_id' => $interactionId,
            'attributes' => self::attributes(
                $legacyEvent
            ),
        ];

        $callId = trim(
            (string) ($legacyEvent['call_id'] ?? '')
        );

        if ($callId !== '') {
            $event['call_id'] = $callId;
        }

        return $event;
    }

    /**
     * @param array<mixed> $legacyEvent
     * @return array<mixed>
     */
    private static function attributes(
        array $legacyEvent
    ): array {
        $attributes = $legacyEvent;

        unset(
            $attributes['type'],
            $attributes['call_id'],
            $attributes['text'],
            $attributes['tool_calls'],
            $attributes['usage'],
            $attributes['arguments'],
            $attributes['result'],
            $attributes['original_text'],
            $attributes['replacement_text'],
            $attributes['error'],
            $attributes['details'],
            $attributes['payload'],
            $attributes['raw'],
            $attributes['output']
        );

        if (
            isset($legacyEvent['arguments_fingerprint'])
            && is_string(
                $legacyEvent['arguments_fingerprint']
            )
        ) {
            $attributes['arguments_fingerprint'] =
                $legacyEvent['arguments_fingerprint'];
        }

        if (isset($legacyEvent['tool_calls'])) {
            $attributes['tool_call_count'] =
                is_array($legacyEvent['tool_calls'])
                    ? count($legacyEvent['tool_calls'])
                    : 0;
        }

        if (
            isset($legacyEvent['usage'])
            && is_array($legacyEvent['usage'])
        ) {
            $usage = $legacyEvent['usage'];

            $attributes['usage'] = [
                'input_tokens' => (int) (
                    $usage['input_tokens']
                    ?? $usage['prompt_tokens']
                    ?? 0
                ),
                'output_tokens' => (int) (
                    $usage['output_tokens']
                    ?? $usage['completion_tokens']
                    ?? 0
                ),
                'total_tokens' => (int) (
                    $usage['total_tokens']
                    ?? 0
                ),
            ];
        }

        if (
            isset($legacyEvent['result'])
            && is_array($legacyEvent['result'])
        ) {
            $attributes['success'] = (
                $legacyEvent['result']['success']
                ?? false
            ) === true;
        }

        return self::sanitize($attributes);
    }

    private static function sanitize(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            if (is_object($value)) {
                return get_debug_type($value);
            }

            if (is_resource($value)) {
                return get_resource_type($value);
            }

            return $value;
        }

        $result = [];

        foreach ($value as $key => $item) {
            $normalizedKey = mb_strtolower(
                (string) $key,
                'UTF-8'
            );

            if (
                str_contains($normalizedKey, 'token')
                && !in_array(
                    $normalizedKey,
                    [
                        'input_tokens',
                        'output_tokens',
                        'total_tokens',
                        'prompt_tokens',
                        'completion_tokens',
                    ],
                    true
                )
            ) {
                continue;
            }

            if (
                str_contains($normalizedKey, 'password')
                || str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'authorization')
                || str_contains($normalizedKey, 'cookie')
                || str_contains($normalizedKey, 'api_key')
            ) {
                continue;
            }

            if (
                in_array(
                    $normalizedKey,
                    [
                        'identity',
                        'tenant_id',
                        'execution_context',
                    ],
                    true
                )
            ) {
                continue;
            }

            $result[$key] = self::sanitize(
                $item
            );
        }

        return $result;
    }
}
