<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Fixtures;

use Asmpkg\Guiche\Contracts\ToolInterface;
use Throwable;

final class FakeTool implements ToolInterface
{
    /** @var list<array<string, mixed>> */
    public array $executions = [];

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        private readonly string $toolName = 'FakeTool',
        private readonly array $result = ['ok' => true],
        private readonly string $toolDescription = 'Deterministic fake tool for tests.',
        private readonly array $parameters = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
            'additionalProperties' => true,
        ],
        private readonly ?Throwable $failure = null
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }

    public function execute(array $arguments): array
    {
        $this->executions[] = $arguments;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}
