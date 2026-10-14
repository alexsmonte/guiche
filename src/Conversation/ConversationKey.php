<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use InvalidArgumentException;
use JsonException;

final class ConversationKey
{
    /**
     * @throws JsonException
     */
    public static function fromParts(
        string $provider,
        string $tenant,
        string $account,
        string $conversationId
    ): string {
        $parts = [
            trim($provider),
            trim($tenant),
            trim($account),
            trim($conversationId),
        ];

        foreach ($parts as $index => $value) {
            if ($value === '') {
                throw new InvalidArgumentException(
                    "Conversation key part {$index} cannot be empty."
                );
            }
        }

        return hash(
            'sha256',
            json_encode(
                $parts,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            )
        );
    }
}
