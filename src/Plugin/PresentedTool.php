<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Plugin;

use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;

/**
 * Decorator interno usado pelo GuicheBuilder.
 *
 * Permite integrar PresenterRegistry ao runtime sem acoplar Guiche
 * ao sistema de plugins e sem alterar ToolInterface.
 */
final class PresentedTool implements ToolInterface
{
    public function __construct(
        private readonly ToolInterface $tool,
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

    /**
     * @return array<mixed>
     */
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
        $result = $this->tool->execute($arguments);

        $existing = trim(
            (string)($result['_guiche']['presentation_message'] ?? '')
        );

        /*
         * Compatibilidade com a v0.2:
         * se a Tool já trouxe uma apresentação própria, ela prevalece.
         */
        if ($existing !== '') {
            return $result;
        }

        $presentation = $this->presenter->present($result);

        if (
            !is_string($presentation)
            || trim($presentation) === ''
        ) {
            return $result;
        }

        $result['_guiche'] = array_merge(
            is_array($result['_guiche'] ?? null)
                ? $result['_guiche']
                : [],
            [
                'presentation_message' => $presentation,
            ]
        );

        return $result;
    }
}
