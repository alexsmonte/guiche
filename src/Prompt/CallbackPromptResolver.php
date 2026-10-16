<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Prompt;

use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use Closure;

/**
 * Adapter genérico para resolver prompts por callback.
 *
 * O callback recebe somente PromptContext, que é montado pelo runtime
 * a partir de contexto confiável da sessão.
 */
final class CallbackPromptResolver implements PromptResolverInterface
{
    /**
     * @var Closure(PromptContext): string
     */
    private Closure $resolver;

    /**
     * @param callable(PromptContext): string $resolver
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = Closure::fromCallable($resolver);
    }

    public function resolve(PromptContext $context): string
    {
        return ($this->resolver)($context);
    }
}
