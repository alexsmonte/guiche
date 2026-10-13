<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class ContextStore
{
    private DateTimeZone $timezone;

    public function __construct(
        private readonly string $directory,
        string $timezone = 'America/Sao_Paulo'
    ) {
        $this->timezone = new DateTimeZone($timezone);

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Não foi possível criar o diretório de contexto: {$this->directory}");
        }
    }

    public function load(string $sessionId): array
    {
        $file = $this->file($sessionId);

        if (!is_file($file)) {
            return $this->emptyContext($sessionId);
        }

        $contents = file_get_contents($file);

        if ($contents === false || trim($contents) === '') {
            return $this->emptyContext($sessionId);
        }

        $context = json_decode($contents, true);

        if (!is_array($context)) {
            throw new RuntimeException("Contexto inválido para a sessão {$sessionId}.");
        }

        $context['usage'] ??= [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'interactions' => [],
        ];

        return $context;
    }

    public function save(string $sessionId, array $context): void
    {
        $context['session_id'] = $sessionId;
        $context['updated_at'] = $this->now();

        $json = json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        if (file_put_contents($this->file($sessionId), $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Não foi possível salvar o contexto da sessão {$sessionId}.");
        }
    }

    public function delete(string $sessionId): void
    {
        $file = $this->file($sessionId);

        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException("Não foi possível apagar a sessão {$sessionId}.");
        }
    }

    public function addMessage(string $sessionId, string $role, string $content): array
    {
        $context = $this->load($sessionId);

        $context['messages'][] = [
            'role' => $role,
            'content' => $content,
            'created_at' => $this->now(),
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
        $context['state'] = array_replace_recursive($context['state'] ?? [], $state);
        $this->save($sessionId, $context);

        return $context;
    }

    public function applyPatch(string $sessionId, array $patch): array
    {
        if ($patch === []) {
            return $this->load($sessionId);
        }

        $context = $this->load($sessionId);

        if (array_key_exists('intent', $patch)) {
            $context['intent'] = $patch['intent'];
        }

        foreach ($patch['unset_state'] ?? [] as $key) {
            unset($context['state'][$key]);
        }

        if (!empty($patch['state'])) {
            $context['state'] = array_replace_recursive($context['state'] ?? [], $patch['state']);
        }

        $this->save($sessionId, $context);

        return $context;
    }

    public function addToolExecution(string $sessionId, string $tool, array $arguments, array $result): array
    {
        $context = $this->load($sessionId);

        $context['tool_history'][] = [
            'tool' => $tool,
            'arguments' => $arguments,
            'fingerprint' => $this->toolFingerprint($tool, $arguments),
            'result' => $result,
            'created_at' => $this->now(),
        ];

        $this->save($sessionId, $context);

        return $context;
    }

    public function findSuccessfulToolResult(string $sessionId, string $tool, array $arguments): ?array
    {
        $context = $this->load($sessionId);
        $history = $context['tool_history'] ?? [];
        $fingerprint = $this->toolFingerprint($tool, $arguments);

        for ($i = count($history) - 1; $i >= 0; $i--) {
            $entry = $history[$i];

            if (
                ($entry['fingerprint'] ?? null) === $fingerprint
                && ($entry['result']['success'] ?? false) === true
            ) {
                return $entry['result'];
            }
        }

        return null;
    }

    public function recordInteractionUsage(string $sessionId, array $usage): array
    {
        $context = $this->load($sessionId);

        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($input + $output));

        $usage['input_tokens'] = $input;
        $usage['output_tokens'] = $output;
        $usage['total_tokens'] = $total;
        $usage['created_at'] = $this->now();

        $context['usage']['input_tokens'] += $input;
        $context['usage']['output_tokens'] += $output;
        $context['usage']['total_tokens'] += $total;
        $context['usage']['interactions'][] = $usage;

        $this->save($sessionId, $context);

        return $context;
    }

    private function emptyContext(string $sessionId): array
    {
        $now = $this->now();

        return [
            'session_id' => $sessionId,
            'intent' => null,
            'state' => [],
            'messages' => [],
            'tool_history' => [],
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'interactions' => [],
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function file(string $sessionId): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . hash('sha256', $sessionId)
            . '.json';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM);
    }

    private function toolFingerprint(string $tool, array $arguments): string
    {
        $normalized = $this->normalizeForFingerprint($arguments);

        return hash(
            'sha256',
            mb_strtolower(trim($tool), 'UTF-8')
            . '|'
            . json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function normalizeForFingerprint(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    fn (mixed $item): mixed => $this->normalizeForFingerprint($item),
                    $value
                );
            }

            ksort($value);

            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeForFingerprint($item);
            }

            return $value;
        }

        if (is_string($value)) {
            return mb_strtolower(trim($value), 'UTF-8');
        }

        return $value;
    }
}
