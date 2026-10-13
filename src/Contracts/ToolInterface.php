<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * Schema dos argumentos aceitos pela ferramenta.
     *
     * Formato esperado:
     * [
     *     'type' => 'object',
     *     'properties' => [...],
     *     'required' => [...]
     * ]
     */
    public function parameters(): array;

    /**
     * Executa a ferramenta e devolve um resultado serializável.
     */
    public function execute(array $arguments): array;
}
