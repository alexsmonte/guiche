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
     * @return array<mixed>
     */
    public function parameters(): array;

    /**
     * Executa a ferramenta e devolve um resultado serializável.
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function execute(array $arguments): array;
}
