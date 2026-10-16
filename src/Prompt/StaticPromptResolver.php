<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Prompt;

use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use InvalidArgumentException;

/**
 * Fonte de prompt estática.
 *
 * Útil para configuração inline, fallback final explícito, testes
 * ou aplicações que já carregaram o prompt por outro mecanismo.
 */
final class StaticPromptResolver
    implements PromptResolverInterface
{
    private string $prompt;

    public function __construct(string $prompt)
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            throw new InvalidArgumentException(
                'StaticPromptResolver exige um prompt não vazio.'
            );
        }

        $this->prompt = $prompt;
    }

    public function resolve(
        PromptContext $context
    ): string {
        return $this->prompt;
    }
}
