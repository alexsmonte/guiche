<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Fixtures;

use Asmpkg\Guiche\Contracts\ContextStoreInterface;

final class InMemoryContextStore implements ContextStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];

    public function load(string $sessionId): array
    {
        return $this->contexts[$sessionId] ?? $this->emptyContext($sessionId);
    }

    public function save(string $sessionId, array $context): void
    {
        $this->contexts[$sessionId] = $context;
    }

    public function delete(string $sessionId): void
    {
        unset($this->contexts[$sessionId]);
    }

    public function addMessage(string $sessionId, string $role, string $content): array
    {
        $context = $this->load($sessionId);
        $context['messages'] ??= [];
        $context['messages'][] = [
            'role' => $role,
            'content' => $content,
        ];

        $this->save($sessionId, $context);

        return $context;
    }

    public function setIntent(string $sessionId, ?string $intent): array
    {
        $context = $this->load($sessionId);
        $context['intent'] = $intent;
        $this->save($sessionId, $context);

        return $context;
    }

    public function mergeState(string $sessionId, array $state): array
    {
        $context = $this->load($sessionId);
        $current = is_array($context['state'] ?? null) ? $context['state'] : [];
        $context['state'] = array_replace_recursive($current, $state);
        $this->save($sessionId, $context);

        return $context;
    }

    public function applyPatch(string $sessionId, array $patch): array
    {
        $context = $this->load($sessionId);

        foreach ($patch as $key => $value) {
            if ($key === 'state' && is_array($value)) {
                $current = is_array($context['state'] ?? null) ? $context['state'] : [];
                $context['state'] = array_replace_recursive($current, $value);
                continue;
            }

            $context[$key] = $value;
        }

        $this->save($sessionId, $context);

        return $context;
    }

    public function addToolExecution(
        string $sessionId,
        string $tool,
        array $arguments,
        array $result
    ): array {
        $context = $this->load($sessionId);
        $context['tool_history'] ??= [];
        $context['tool_history'][] = [
            'tool' => $tool,
            'arguments' => $arguments,
            'result' => $result,
        ];

        $this->save($sessionId, $context);

        return $context;
    }

    public function findSuccessfulToolResult(
        string $sessionId,
        string $tool,
        array $arguments
    ): ?array {
        $history = $this->load($sessionId)['tool_history'] ?? [];

        for ($index = count($history) - 1; $index >= 0; $index--) {
            $entry = $history[$index] ?? [];

            if (
                ($entry['tool'] ?? null) === $tool
                && ($entry['arguments'] ?? null) === $arguments
                && (($entry['result']['success'] ?? false) === true)
            ) {
                return $entry['result'];
            }
        }

        return null;
    }

    public function recordInteractionUsage(string $sessionId, array $usage): array
    {
        $context = $this->load($sessionId);
        $context['usage_history'] ??= [];
        $context['usage_history'][] = $usage;
        $this->save($sessionId, $context);

        return $context;
    }

    public function toolFingerprint(string $sessionId, string $tool, array $arguments): string
    {
        return hash('sha256', json_encode(
            [
                'session_id' => $sessionId,
                'tool' => $tool,
                'arguments' => $this->normalize($arguments),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyContext(string $sessionId): array
    {
        return [
            'session_id' => $sessionId,
            'intent' => null,
            'state' => [],
            'messages' => [],
            'tool_history' => [],
            'usage_history' => [],
        ];
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
