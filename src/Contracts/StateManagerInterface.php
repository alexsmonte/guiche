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
    public function onUserMessage(array $context, string $message): array;

    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array;
}
