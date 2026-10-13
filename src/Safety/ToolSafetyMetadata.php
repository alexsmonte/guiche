<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Safety;

/**
 * Normalizador neutro dos metadados de segurança declarados em ToolRegistration.
 *
 * Formatos suportados:
 *
 * operation:
 *   read | write
 *
 * allowed_stages:
 *   ['stage_a', 'stage_b']
 *
 * requires_state / forbids_state:
 *   [
 *       'state.foo',             // precisa existir
 *       'state.bar' => true,     // precisa ser exatamente true
 *       [
 *           'path' => 'state.x',
 *           'value' => 10,
 *       ],
 *   ]
 *
 * confirmation:
 *   true                         // state.confirmed === true
 *   'state.confirmed_by_user'    // path customizado === true
 *   [
 *       'path' => 'state.confirmed_by_user',
 *       'value' => true,
 *   ]
 */
final class ToolSafetyMetadata
{
    public const OPERATION_READ = 'read';
    public const OPERATION_WRITE = 'write';
    public const OPERATION_UNKNOWN = 'unknown';

    public static function operation(array $metadata): string
    {
        $raw = strtolower(trim((string)(
            $metadata['operation']
            ?? $metadata['kind']
            ?? ''
        )));

        if ($raw === '') {
            /*
             * Compatibilidade com registros legados da v0.2/v0.3.
             * Escritas devem declarar explicitamente operation=write.
             */
            return self::OPERATION_READ;
        }

        if (in_array(
            $raw,
            [
                self::OPERATION_READ,
                self::OPERATION_WRITE,
            ],
            true
        )) {
            return $raw;
        }

        return self::OPERATION_UNKNOWN;
    }

    public static function isRead(array $metadata): bool
    {
        return self::operation($metadata)
            === self::OPERATION_READ;
    }

    public static function isWrite(array $metadata): bool
    {
        return self::operation($metadata)
            === self::OPERATION_WRITE;
    }

    public static function allowedStages(array $metadata): array
    {
        $stages = $metadata['allowed_stages'] ?? [];

        if (!is_array($stages)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $stage): string =>
                    trim((string)$stage),
                $stages
            ),
            static fn (string $stage): bool =>
                $stage !== ''
        )));
    }

    public static function stagePath(array $metadata): ?string
    {
        $path = trim((string)($metadata['stage_path'] ?? ''));

        return $path !== ''
            ? $path
            : null;
    }

    public static function requiredState(array $metadata): array
    {
        return self::normalizeStateConditions(
            $metadata['requires_state'] ?? []
        );
    }

    public static function forbiddenState(array $metadata): array
    {
        return self::normalizeStateConditions(
            $metadata['forbids_state'] ?? []
        );
    }

    public static function confirmation(array $metadata): ?array
    {
        $definition = $metadata['confirmation']
            ?? $metadata['requires_confirmation']
            ?? null;

        if ($definition === null || $definition === false) {
            return null;
        }

        if ($definition === true) {
            return [
                'path' => 'state.confirmed',
                'operator' => 'equals',
                'value' => true,
            ];
        }

        if (is_string($definition)) {
            $path = trim($definition);

            return $path !== ''
                ? [
                    'path' => $path,
                    'operator' => 'equals',
                    'value' => true,
                ]
                : null;
        }

        if (!is_array($definition)) {
            return null;
        }

        $path = trim((string)($definition['path'] ?? ''));

        if ($path === '') {
            return null;
        }

        return [
            'path' => $path,
            'operator' => 'equals',
            'value' => $definition['value'] ?? true,
        ];
    }

    private static function normalizeStateConditions(
        mixed $definition
    ): array {
        if (!is_array($definition)) {
            return [];
        }

        $conditions = [];

        foreach ($definition as $key => $value) {
            if (is_string($key)) {
                $path = trim($key);

                if ($path === '') {
                    continue;
                }

                $conditions[] = [
                    'path' => $path,
                    'operator' => 'equals',
                    'value' => $value,
                ];

                continue;
            }

            if (is_string($value)) {
                $path = trim($value);

                if ($path === '') {
                    continue;
                }

                $conditions[] = [
                    'path' => $path,
                    'operator' => 'exists',
                ];

                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $path = trim((string)($value['path'] ?? ''));

            if ($path === '') {
                continue;
            }

            if (array_key_exists('value', $value)) {
                $conditions[] = [
                    'path' => $path,
                    'operator' => 'equals',
                    'value' => $value['value'],
                ];

                continue;
            }

            $conditions[] = [
                'path' => $path,
                'operator' => 'exists',
            ];
        }

        return $conditions;
    }
}
