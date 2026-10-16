<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Prompt;

use InvalidArgumentException;

/**
 * Snapshot imutável do contexto confiável usado para escolher um prompt.
 *
 * O runtime da v1.4 preencherá este objeto a partir da sessão e do
 * execution_context confiável. O contrato é deliberadamente genérico
 * para não acoplar o Core a provider externo, banco, framework ou domínio.
 */
final class PromptContext
{
    private readonly string $sessionId;

    /**
     * @param array<mixed> $values
     */
    public function __construct(
        string $sessionId,
        private readonly array $values = []
    ) {
        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            throw new InvalidArgumentException(
                'sessionId do PromptContext não pode ser vazio.'
            );
        }

        $this->sessionId = $sessionId;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function tenantId(): string|int|null
    {
        $tenantId = $this->values['tenant_id'] ?? null;

        if (is_int($tenantId)) {
            return $tenantId;
        }

        if (is_string($tenantId)) {
            $tenantId = trim($tenantId);

            return $tenantId !== ''
                ? $tenantId
                : null;
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    public function all(): array
    {
        return $this->values;
    }
}
