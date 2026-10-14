<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Fixtures;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use RuntimeException;

final class FakeAiProvider implements AiProviderInterface
{
    /** @var list<array<string, mixed>> */
    private array $responses;

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /**
     * @param list<array<string, mixed>> $responses
     */
    public function __construct(array $responses = [])
    {
        $this->responses = array_values($responses);
    }

    public function respond(string $instructions, array $messages, array $tools = []): array
    {
        $this->calls[] = [
            'method' => 'respond',
            'instructions' => $instructions,
            'messages' => $messages,
            'tools' => $tools,
        ];

        return $this->nextResponse();
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        $this->calls[] = [
            'method' => 'continueWithToolOutputs',
            'previous_response_id' => $previousResponseId,
            'instructions' => $instructions,
            'tool_outputs' => $toolOutputs,
            'tools' => $tools,
        ];

        return $this->nextResponse();
    }

    /**
     * @param array<string, mixed> $response
     */
    public function queue(array $response): void
    {
        $this->responses[] = $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function nextResponse(): array
    {
        if ($this->responses === []) {
            throw new RuntimeException('FakeAiProvider response queue exhausted.');
        }

        return array_shift($this->responses);
    }
}
