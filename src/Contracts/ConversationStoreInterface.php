<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

interface ConversationStoreInterface
{
    /**
     * @return array<mixed>
     */
    public function load(string $conversationKey): array;

    /**
     * Executa read-modify-write atomicamente para uma conversa.
     *
     * @param callable(array<mixed>): array<mixed> $mutation
     * @return array<mixed>
     */
    public function mutate(
        string $conversationKey,
        callable $mutation
    ): array;

    public function delete(string $conversationKey): void;
}
