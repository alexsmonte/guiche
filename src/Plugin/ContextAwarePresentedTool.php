<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Plugin;

use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Execution\ToolExecutionContext;

final class ContextAwarePresentedTool implements ContextAwareToolInterface
{
    public function __construct(
        private readonly ContextAwareToolInterface $tool,
        private readonly ResultPresenterInterface $presenter
    ) {
    }

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    /** @return array<mixed> */
    public function parameters(): array
    {
        return $this->tool->parameters();
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function execute(array $arguments): array
    {
        return $this->present(
            $this->tool->execute($arguments)
        );
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function executeWithContext(
        array $arguments,
        ToolExecutionContext $context
    ): array {
        return $this->present(
            $this->tool->executeWithContext(
                $arguments,
                $context
            )
        );
    }

    /**
     * @param array<mixed> $result
     * @return array<mixed>
     */
    private function present(array $result): array
    {
        $existing = trim(
            (string) (
                $result['_guiche']['presentation_message']
                ?? ''
            )
        );

        if ($existing !== '') {
            return $result;
        }

        $presentation = $this->presenter->present(
            $result
        );

        if (
            $presentation === null
            || trim($presentation) === ''
        ) {
            return $result;
        }

        $result['_guiche'] = array_merge(
            is_array($result['_guiche'] ?? null)
                ? $result['_guiche']
                : [],
            ['presentation_message' => $presentation]
        );

        return $result;
    }
}
