<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Fixtures;

use Asmpkg\Guiche\Contracts\StateManagerInterface;

final class FakeStateManager implements StateManagerInterface
{
    /** @var list<array<string, mixed>> */
    public array $userCalls = [];

    /** @var list<array<string, mixed>> */
    public array $toolCalls = [];

    /**
     * @param array<string, mixed> $userPatch
     * @param array<string, mixed> $toolPatch
     */
    public function __construct(
        private readonly array $userPatch = [],
        private readonly array $toolPatch = []
    ) {
    }

    public function onUserMessage(array $context, string $message): array
    {
        $this->userCalls[] = [
            'context' => $context,
            'message' => $message,
        ];

        return $this->userPatch;
    }

    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array {
        $this->toolCalls[] = [
            'context' => $context,
            'tool' => $tool,
            'arguments' => $arguments,
            'result' => $result,
        ];

        return $this->toolPatch;
    }
}
