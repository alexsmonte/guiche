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
    public function respond(string $instructions, array $messages, array $tools = []): array;

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array;
}
