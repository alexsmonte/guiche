<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Safety;

/**
 * Validador estrutural mínimo do schema JSON declarado por ToolInterface.
 *
 * A Safety Layer valida a forma do input ANTES da Tool.
 * Regras semânticas específicas do domínio continuam pertencendo à Tool.
 */
final class ToolArgumentValidator
{
    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    public function validate(
        array $schema,
        array $arguments
    ): array {
        return $this->validateObject(
            $schema,
            $arguments,
            '$'
        );
    }

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $schema
     */
    public function isValid(
        array $schema,
        array $arguments
    ): bool {
        return $this->validate(
            $schema,
            $arguments
        ) === [];
    }

    /**
     * @param array<mixed> $schema
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function validateObject(
        array $schema,
        array $value,
        string $path
    ): array {
        $errors = [];

        $required = is_array($schema['required'] ?? null)
            ? $schema['required']
            : [];

        foreach ($required as $requiredKey) {
            $requiredKey = trim((string)$requiredKey);

            if (
                $requiredKey !== ''
                && !array_key_exists(
                    $requiredKey,
                    $value
                )
            ) {
                $errors[] = sprintf(
                    '%s.%s é obrigatório.',
                    $path,
                    $requiredKey
                );
            }
        }

        $properties = is_array(
            $schema['properties'] ?? null
        )
            ? $schema['properties']
            : [];

        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($value) as $key) {
                if (!array_key_exists($key, $properties)) {
                    $errors[] = sprintf(
                        '%s.%s não é um argumento permitido.',
                        $path,
                        (string)$key
                    );
                }
            }
        }

        foreach ($value as $key => $item) {
            if (!isset($properties[$key])) {
                continue;
            }

            $propertySchema = is_array($properties[$key])
                ? $properties[$key]
                : [];

            $propertyPath = $path . '.' . $key;

            foreach (
                $this->validateValue(
                    $propertySchema,
                    $item,
                    $propertyPath
                )
                as $error
            ) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function validateValue(
        array $schema,
        mixed $value,
        string $path
    ): array {
        $errors = [];

        $type = trim((string)($schema['type'] ?? ''));

        if (
            $type !== ''
            && !$this->matchesType(
                $type,
                $value
            )
        ) {
            $errors[] = sprintf(
                '%s deve ser do tipo %s.',
                $path,
                $type
            );

            return $errors;
        }

        if (
            isset($schema['enum'])
            && is_array($schema['enum'])
            && !in_array(
                $value,
                $schema['enum'],
                true
            )
        ) {
            $errors[] = sprintf(
                '%s possui valor fora do enum permitido.',
                $path
            );
        }

        if (
            $type === 'string'
            && is_string($value)
        ) {
            if (
                isset($schema['minLength'])
                && mb_strlen($value, 'UTF-8')
                    < (int)$schema['minLength']
            ) {
                $errors[] = sprintf(
                    '%s deve possuir no mínimo %d caracteres.',
                    $path,
                    (int)$schema['minLength']
                );
            }

            $pattern = (string)($schema['pattern'] ?? '');

            if (
                $pattern !== ''
                && @preg_match(
                    '~' . str_replace('~', '\~', $pattern) . '~u',
                    $value
                ) === 0
            ) {
                $errors[] = sprintf(
                    '%s não corresponde ao padrão esperado.',
                    $path
                );
            }
        }

        if (
            $type === 'object'
            && is_array($value)
        ) {
            foreach (
                $this->validateObject(
                    $schema,
                    $value,
                    $path
                )
                as $error
            ) {
                $errors[] = $error;
            }
        }

        if (
            $type === 'array'
            && is_array($value)
            && isset($schema['items'])
            && is_array($schema['items'])
        ) {
            foreach ($value as $index => $item) {
                foreach (
                    $this->validateValue(
                        $schema['items'],
                        $item,
                        sprintf(
                            '%s[%d]',
                            $path,
                            $index
                        )
                    )
                    as $error
                ) {
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    private function matchesType(
        string $type,
        mixed $value
    ): bool {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => true,
        };
    }
}
