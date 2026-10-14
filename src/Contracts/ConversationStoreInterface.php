<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

interface ConversationStoreInterface
{
    public function load(string $conversationKey): array;

    /**
     * Executa read-modify-write atomicamente para uma conversa.
     *
     * @param callable(array): array $mutation
     */
    public function mutate(
        string $conversationKey,
        callable $mutation
    ): array;

    public function delete(string $conversationKey): void;
}
