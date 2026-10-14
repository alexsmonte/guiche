<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

interface ContextStoreInterface
{
    /**
     * @return array<mixed>
     */
    public function load(string $sessionId): array;

    /**
     * @param array<mixed> $context
     */
    public function save(
        string $sessionId,
        array $context
    ): void;

    public function delete(string $sessionId): void;

    /**
     * @return array<mixed>
     */
    public function addMessage(
        string $sessionId,
        string $role,
        string $content
    ): array;

    /**
     * @return array<mixed>
     */
    public function setIntent(
        string $sessionId,
        ?string $intent
    ): array;

    /**
     * @param array<mixed> $state
     * @return array<mixed>
     */
    public function mergeState(
        string $sessionId,
        array $state
    ): array;

    /**
     * @param array<mixed> $patch
     * @return array<mixed>
     */
    public function applyPatch(
        string $sessionId,
        array $patch
    ): array;

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $result
     * @return array<mixed>
     */
    public function addToolExecution(
        string $sessionId,
        string $tool,
        array $arguments,
        array $result
    ): array;

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>|null
     */
    public function findSuccessfulToolResult(
        string $sessionId,
        string $tool,
        array $arguments
    ): ?array;

    /**
     * @param array<mixed> $usage
     * @return array<mixed>
     */
    public function recordInteractionUsage(
        string $sessionId,
        array $usage
    ): array;

    /**
     * @param array<mixed> $arguments
     */
    public function toolFingerprint(
        string $sessionId,
        string $tool,
        array $arguments
    ): string;
}
