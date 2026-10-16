<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

use Asmpkg\Guiche\Prompt\PromptContext;

/**
 * Resolve as instruções-base do assistente a partir de contexto confiável.
 *
 * A origem pode ser arquivo, banco, API, cache, CMS ou composição externa.
 * Mensagem do usuário, saída da IA e argumentos livres de Tool não devem
 * decidir diretamente qual prompt será carregado.
 */
interface PromptResolverInterface
{
    public function resolve(PromptContext $context): string;
}
