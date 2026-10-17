<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Cache;

use InvalidArgumentException;

/**
 * Política interna de reuso de resultados de Tools READ.
 *
 * `withReusableTools()` continua sendo o opt-in de reuso.
 * Esta classe acrescenta validade contextual opcional via metadata:
 *
 * [
 *     'reuse' => [
 *         'depends_on' => [
 *             'state.customer_id',
 *             'execution_context.tenant_id',
 *         ],
 *     ],
 * ]
 *
 * A assinatura é persistida somente no resultado armazenado em
 * tool_history. Ela nunca é entregue à Tool, StateManager ou IA.
 */
final class ToolReusePolicy
{
    public const METADATA_KEY = 'reuse';
    public const DEPENDS_ON_KEY = 'depends_on';

    /**
     * Namespace reservado exclusivamente ao histórico interno.
     *
     * Tools não devem usar chaves iniciadas por "__guiche_".
     */
    private const HISTORY_MARKER = '__guiche_reuse_v1';

    /**
     * @param array<mixed> $metadata
     * @return list<string>
     */
    public function dependencyPaths(array $metadata): array
    {
        $reuse = $metadata[self::METADATA_KEY] ?? null;

        if ($reuse === null) {
            return [];
        }

        if (!is_array($reuse) || array_is_list($reuse)) {
            throw new InvalidArgumentException(
                'metadata reuse precisa ser um objeto associativo.'
            );
        }

        $raw = $reuse[self::DEPENDS_ON_KEY] ?? [];

        if ($raw === []) {
            return [];
        }

        if (!is_array($raw)) {
            throw new InvalidArgumentException(
                'reuse.depends_on precisa ser uma lista de caminhos.'
            );
        }

        $paths = [];

        foreach ($raw as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException(
                    'Cada caminho de reuse.depends_on precisa ser string.'
                );
            }

            $path = trim($path);

            if ($path === '') {
                throw new InvalidArgumentException(
                    'reuse.depends_on não aceita caminho vazio.'
                );
            }

            foreach (explode('.', $path) as $segment) {
                if (trim($segment) === '') {
                    throw new InvalidArgumentException(
                        "Caminho de reuse.depends_on inválido: {$path}"
                    );
                }
            }

            $paths[] = $path;
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Retorna null quando a Tool usa o comportamento legado:
     * reuso somente por sessão + Tool + argumentos.
     *
     * @param array<mixed> $metadata
     * @param array<mixed> $context
     */
    public function dependencyFingerprint(
        array $metadata,
        array $context
    ): ?string {
        $paths = $this->dependencyPaths($metadata);

        if ($paths === []) {
            return null;
        }

        $snapshot = [];

        foreach ($paths as $path) {
            [$exists, $value] = $this->readPath(
                $context,
                explode('.', $path)
            );

            $snapshot[$path] = [
                'exists' => $exists,
                'value' => $exists
                    ? $this->canonicalize($value)
                    : null,
            ];
        }

        return hash(
            'sha256',
            serialize([
                'paths' => $paths,
                'snapshot' => $snapshot,
            ])
        );
    }

    /**
     * Anexa assinatura apenas à cópia persistida no histórico.
     *
     * @param array<mixed> $result
     * @return array<mixed>
     */
    public function resultForHistory(
        array $result,
        ?string $dependencyFingerprint
    ): array {
        if ($dependencyFingerprint === null) {
            return $result;
        }

        $result[self::HISTORY_MARKER] = [
            'schema_version' => 1,
            'dependency_fingerprint' =>
                $dependencyFingerprint,
        ];

        return $result;
    }

    /**
     * Valida um resultado vindo do histórico.
     *
     * Retorno:
     * - result != null: cache válido;
     * - result == null + reason: entrada antiga/inválida.
     *
     * @param array<mixed> $candidate
     * @return array{result: array<mixed>|null, reason: string|null}
     */
    public function validateCachedResult(
        array $candidate,
        ?string $expectedDependencyFingerprint
    ): array {
        if ($expectedDependencyFingerprint === null) {
            return [
                'result' => $candidate,
                'reason' => null,
            ];
        }

        $marker = $candidate[self::HISTORY_MARKER] ?? null;

        if (!is_array($marker)) {
            return [
                'result' => null,
                'reason' => 'dependency_signature_missing',
            ];
        }

        $schemaVersion = $marker['schema_version'] ?? null;
        $storedFingerprint =
            $marker['dependency_fingerprint'] ?? null;

        if (
            $schemaVersion !== 1
            || !is_string($storedFingerprint)
            || $storedFingerprint === ''
        ) {
            return [
                'result' => null,
                'reason' => 'dependency_signature_invalid',
            ];
        }

        if (!hash_equals(
            $storedFingerprint,
            $expectedDependencyFingerprint
        )) {
            return [
                'result' => null,
                'reason' => 'dependency_changed',
            ];
        }

        unset($candidate[self::HISTORY_MARKER]);

        return [
            'result' => $candidate,
            'reason' => null,
        ];
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $segments
     * @return array{0: bool, 1: mixed}
     */
    private function readPath(
        array $source,
        array $segments
    ): array {
        $value = $source;

        foreach ($segments as $segment) {
            if (
                !is_array($value)
                || !array_key_exists($segment, $value)
            ) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_object($value)) {
                return [
                    '__unsupported_type' =>
                        get_debug_type($value),
                ];
            }

            if (is_resource($value)) {
                return [
                    '__unsupported_type' =>
                        get_resource_type($value),
                ];
            }

            if (
                is_float($value)
                && !is_finite($value)
            ) {
                return [
                    '__unsupported_float' =>
                        (string) $value,
                ];
            }

            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $value[$index] =
                    $this->canonicalize($item);
            }

            return $value;
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] =
                $this->canonicalize($item);
        }

        return $value;
    }
}
