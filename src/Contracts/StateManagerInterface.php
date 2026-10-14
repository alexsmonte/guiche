<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

/**
 * Contrato genérico para evolução do estado conversacional.
 *
 * O Core conhece apenas estes dois pontos de extensão:
 * - mensagem recebida do usuário;
 * - resultado produzido por uma Tool.
 *
 * Implementações de domínio podem transformar o contexto em patches
 * compatíveis com ContextStore::applyPatch(), sem acoplar o Guichê
 * a um fluxo específico.
 */
interface StateManagerInterface
{
    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public function onUserMessage(array $context, string $message): array;

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $context
     * @param array<mixed> $result
     * @return array<mixed>
     */
    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array;
}
