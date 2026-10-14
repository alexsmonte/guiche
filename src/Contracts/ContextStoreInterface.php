<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

interface ContextStoreInterface
{
    public function load(string $sessionId): array;

    public function save(
        string $sessionId,
        array $context
    ): void;

    public function delete(string $sessionId): void;

    public function addMessage(
        string $sessionId,
        string $role,
        string $content
    ): array;

    public function setIntent(
        string $sessionId,
        ?string $intent
    ): array;

    public function mergeState(
        string $sessionId,
        array $state
    ): array;

    public function applyPatch(
        string $sessionId,
        array $patch
    ): array;

    public function addToolExecution(
        string $sessionId,
        string $tool,
        array $arguments,
        array $result
    ): array;

    public function findSuccessfulToolResult(
        string $sessionId,
        string $tool,
        array $arguments
    ): ?array;

    public function recordInteractionUsage(
        string $sessionId,
        array $usage
    ): array;

    public function toolFingerprint(
        string $sessionId,
        string $tool,
        array $arguments
    ): string;
}
