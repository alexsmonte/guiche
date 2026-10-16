<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Execution;

use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use InvalidArgumentException;
use RuntimeException;

final class ExecutionContextSeeder
{
    public function __construct(
        private readonly ContextStoreInterface $contextStore
    ) {
    }

    /**
     * @param array<mixed> $values
     */
    public function seed(
        string $sessionId,
        array $values
    ): ToolExecutionContext {
        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            throw new InvalidArgumentException(
                'sessionId não pode ser vazio.'
            );
        }

        $values = $this->validateValues($values);

        $stored = $this->contextStore->load($sessionId);
        $current = $this->executionContextFrom($stored);

        $merged = $this->mergeWriteOnce(
            $sessionId,
            $current,
            $values
        );

        if (!$this->executionContextValuesEqual(
            $current,
            $merged
        )) {
            $stored['execution_context'] = $merged;

            $this->contextStore->save(
                $sessionId,
                $stored
            );
        }

        $persisted = $this->executionContextFrom(
            $this->contextStore->load($sessionId)
        );

        foreach ($merged as $key => $value) {
            if (
                !array_key_exists($key, $persisted)
                || !$this->executionContextValuesEqual(
                    $value,
                    $persisted[$key]
                )
            ) {
                throw new RuntimeException(
                    "Falha ao confirmar execution_context da sessão {$sessionId}."
                );
            }
        }

        return new ToolExecutionContext(
            $sessionId,
            $persisted
        );
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private function validateValues(array $values): array
    {
        if (array_key_exists('session_id', $values)) {
            throw new InvalidArgumentException(
                'session_id não pode existir dentro de execution_context.'
            );
        }

        $identity = $values['identity'] ?? null;

        if (
            !is_array($identity)
            || $identity === []
            || array_is_list($identity)
        ) {
            throw new InvalidArgumentException(
                'execution_context.identity deve ser um mapa associativo não vazio.'
            );
        }

        foreach (array_keys($identity) as $key) {
            if (
                !is_string($key)
                || trim($key) === ''
            ) {
                throw new InvalidArgumentException(
                    'execution_context.identity contém chave inválida.'
                );
            }
        }

        $values['identity'] = $this->normalizeIdentityValue(
            $identity,
            'execution_context.identity'
        );

        if (array_key_exists('tenant_id', $values)) {
            $tenantId = $values['tenant_id'];

            if (
                !is_int($tenantId)
                && !(
                    is_string($tenantId)
                    && trim($tenantId) !== ''
                )
            ) {
                throw new InvalidArgumentException(
                    'execution_context.tenant_id deve ser int ou string não vazia.'
                );
            }
        }

        return $values;
    }

    private function normalizeIdentityValue(
        mixed $value,
        string $path
    ): mixed {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $childPath = $path
                    . '.'
                    . (string) $key;

                $value[$key] = $this->normalizeIdentityValue(
                    $item,
                    $childPath
                );
            }

            return $value;
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException(
                "{$path} contém float não finito."
            );
        }

        if (
            $value === null
            || is_string($value)
            || is_int($value)
            || is_float($value)
            || is_bool($value)
        ) {
            return $value;
        }

        throw new InvalidArgumentException(
            "{$path} contém valor não serializável."
        );
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function executionContextFrom(
        array $context
    ): array {
        $executionContext =
            $context['execution_context'] ?? [];

        if (!is_array($executionContext)) {
            throw new RuntimeException(
                'execution_context persistido é inválido.'
            );
        }

        return $executionContext;
    }

    /**
     * @param array<mixed> $current
     * @param array<mixed> $incoming
     * @return array<mixed>
     */
    private function mergeWriteOnce(
        string $sessionId,
        array $current,
        array $incoming
    ): array {
        foreach ($current as $key => $value) {
            if (
                array_key_exists($key, $incoming)
                && !$this->executionContextValuesEqual(
                    $value,
                    $incoming[$key]
                )
            ) {
                throw new RuntimeException(
                    "execution_context da sessão {$sessionId} já está vinculado e não pode ser alterado."
                );
            }
        }

        return array_replace(
            $current,
            $incoming
        );
    }

    private function executionContextValuesEqual(
        mixed $left,
        mixed $right
    ): bool {
        return serialize(
            $this->canonicalize($left)
        ) === serialize(
            $this->canonicalize($right)
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $value[$index] = $this->canonicalize($item);
            }

            return $value;
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
