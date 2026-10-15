<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

use Asmpkg\Guiche\Execution\ToolExecutionContext;

/**
 * Extensão compatível de ToolInterface para Tools que precisam de contexto
 * de execução resolvido pelo host/runtime.
 *
 * ToolInterface::execute(array $arguments) permanece intacta na v1.
 */
interface ContextAwareToolInterface extends ToolInterface
{
    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function executeWithContext(
        array $arguments,
        ToolExecutionContext $context
    ): array;
}
