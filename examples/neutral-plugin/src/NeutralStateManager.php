<?php

declare(strict_types=1);

namespace Example\GuicheNeutral;

use Asmpkg\Guiche\Contracts\StateManagerInterface;

final class NeutralStateManager implements StateManagerInterface
{
    public function onUserMessage(
        array $context,
        string $message
    ): array {
        return [
            'state' => [
                'neutral_last_message' => $message,
            ],
        ];
    }

    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array {
        if (
            $tool !== 'NeutralPing'
            || ($result['success'] ?? false) !== true
        ) {
            return [];
        }

        return [
            'state' => [
                'neutral_tool_executed' => true,
                'neutral_echo' =>
                    $result['data']['echo'] ?? null,
            ],
        ];
    }
}
