<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Prompt;

use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cadeia explícita de fallback de prompts.
 *
 * Regra de segurança:
 * - string vazia significa "fonte sem prompt configurado" e permite
 *   tentar a próxima fonte;
 * - exceptions NÃO são capturadas. Falhas reais de banco/API/arquivo
 *   permanecem fail-closed e não trocam silenciosamente o agente.
 */
final class FallbackPromptResolver
    implements PromptResolverInterface
{
    /**
     * @var list<PromptResolverInterface>
     */
    private array $resolvers;

    public function __construct(
        PromptResolverInterface ...$resolvers
    ) {
        if ($resolvers === []) {
            throw new InvalidArgumentException(
                'FallbackPromptResolver exige ao menos um resolver.'
            );
        }

        $this->resolvers = array_values(
            $resolvers
        );
    }

    public function resolve(
        PromptContext $context
    ): string {
        foreach ($this->resolvers as $resolver) {
            $prompt = trim(
                $resolver->resolve($context)
            );

            if ($prompt !== '') {
                return $prompt;
            }
        }

        throw new RuntimeException(
            'Nenhuma fonte de prompt retornou conteúdo.'
        );
    }
}
