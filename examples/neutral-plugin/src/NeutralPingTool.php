<?php

declare(strict_types=1);

namespace Example\GuicheNeutral;

use Asmpkg\Guiche\Contracts\ToolInterface;

final class NeutralPingTool implements ToolInterface
{
    public function name(): string
    {
        return 'NeutralPing';
    }

    public function description(): string
    {
        return 'Retorna deterministicamente o texto recebido para validar uma integração neutra.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Texto que será devolvido pela ferramenta.',
                ],
            ],
            'required' => ['message'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $message = trim(
            (string)($arguments['message'] ?? '')
        );

        return [
            'success' => true,
            'tool' => $this->name(),
            'data' => [
                'echo' => $message,
            ],
        ];
    }
}
