<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Prompt;

use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use InvalidArgumentException;
use RuntimeException;

final class CompositePromptResolver implements PromptResolverInterface
{
    /** @var list<PromptResolverInterface> */
    private array $resolvers;

    public function __construct(PromptResolverInterface ...$resolvers)
    {
        if ($resolvers === []) {
            throw new InvalidArgumentException(
                'CompositePromptResolver exige ao menos um resolver.'
            );
        }

        $this->resolvers = array_values($resolvers);
    }

    public function resolve(PromptContext $context): string
    {
        $segments = [];

        foreach ($this->resolvers as $resolver) {
            $segment = trim($resolver->resolve($context));

            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        if ($segments === []) {
            throw new RuntimeException(
                'Nenhum resolver da composição retornou conteúdo.'
            );
        }

        return implode(PHP_EOL . PHP_EOL, $segments);
    }
}
