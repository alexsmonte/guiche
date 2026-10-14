<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

/**
 * Contrato independente do fornecedor de IA.
 *
 * O Guichê trabalha com uma resposta normalizada:
 *
 * [
 *     'id' => 'id-do-provider',
 *     'text' => 'texto final ou vazio',
 *     'tool_calls' => [
 *         [
 *             'call_id' => '...',
 *             'name' => 'NomeDaFerramenta',
 *             'arguments' => [...]
 *         ]
 *     ]
 * ]
 */
interface AiProviderInterface
{
    /**
     * @param array<mixed> $messages
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function respond(string $instructions, array $messages, array $tools = []): array;

    /**
     * @param array<mixed> $toolOutputs
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array;
}
