<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class ContextStore implements ContextStoreInterface
{
    private const SCHEMA_VERSION = 1;

    private DateTimeZone $timezone;

    public function __construct(
        private readonly string $directory,
        string $timezone = 'America/Sao_Paulo'
    ) {
        $this->timezone = new DateTimeZone($timezone);

        if (
            !is_dir($this->directory)
            && !mkdir($this->directory, 0775, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException(
                "Não foi possível criar o diretório de contexto: {$this->directory}"
            );
        }
    }

    /**
     * @return array<mixed>
     */
    public function load(string $sessionId): array
    {
        return $this->withSessionLock(
            $sessionId,
            LOCK_SH,
            fn (): array => $this->loadUnlocked($sessionId)
        );
    }

    /**
     * @param array<mixed> $context
     */
    public function save(string $sessionId, array $context): void
    {
        $this->withSessionLock(
            $sessionId,
            LOCK_EX,
            function () use ($sessionId, $context): void {
                $this->writeUnlocked($sessionId, $context);
            }
        );
    }

    public function delete(string $sessionId): void
    {
        $this->withSessionLock(
            $sessionId,
            LOCK_EX,
            function () use ($sessionId): void {
                $file = $this->file($sessionId);

                if (is_file($file) && !unlink($file)) {
                    throw new RuntimeException(
                        "Não foi possível apagar a sessão {$sessionId}."
                    );
                }
            }
        );
    }

    /**
     * @return array<mixed>
     */
    public function addMessage(
        string $sessionId,
        string $role,
        string $content
    ): array {
        return $this->mutate(
            $sessionId,
            function (array $context) use ($role, $content): array {
                $context['messages'][] = [
                    'role' => $role,
                    'content' => $content,
                    'created_at' => $this->now(),
                ];

                return $context;
            }
        );
    }

    /**
     * @return array<mixed>
     */
    public function setIntent(
        string $sessionId,
        ?string $intent
    ): array {
        return $this->mutate(
            $sessionId,
            function (array $context) use ($intent): array {
                $context['intent'] = $intent;

                return $context;
            }
        );
    }

    /**
     * @param array<mixed> $state
     * @return array<mixed>
     */
    public function mergeState(
        string $sessionId,
        array $state
    ): array {
        return $this->mutate(
            $sessionId,
            function (array $context) use ($state): array {
                $context['state'] = array_replace_recursive(
                    $context['state'] ?? [],
                    $state
                );

                return $context;
            }
        );
    }

    /**
     * @param array<mixed> $patch
     * @return array<mixed>
     */
    public function applyPatch(
        string $sessionId,
        array $patch
    ): array {
        if ($patch === []) {
            return $this->load($sessionId);
        }

        return $this->mutate(
            $sessionId,
            function (array $context) use ($patch): array {
                if (array_key_exists('intent', $patch)) {
                    $context['intent'] = $patch['intent'];
                }

                foreach ($patch['unset_state'] ?? [] as $key) {
                    unset($context['state'][$key]);
                }

                if (!empty($patch['state'])) {
                    $context['state'] = array_replace_recursive(
                        $context['state'] ?? [],
                        $patch['state']
                    );
                }

                return $context;
            }
        );
    }

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
    ): array {
        return $this->mutate(
            $sessionId,
            function (array $context) use (
                $sessionId,
                $tool,
                $arguments,
                $result
            ): array {
                $context['tool_history'][] = [
                    'tool' => $tool,
                    'arguments' => $arguments,
                    'fingerprint' => $this->toolFingerprint(
                        $sessionId,
                        $tool,
                        $arguments
                    ),
                    'result' => $result,
                    'created_at' => $this->now(),
                ];

                return $context;
            }
        );
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>|null
     */
    public function findSuccessfulToolResult(
        string $sessionId,
        string $tool,
        array $arguments
    ): ?array {
        $context = $this->load($sessionId);
        $history = $context['tool_history'] ?? [];
        $fingerprint = $this->toolFingerprint(
            $sessionId,
            $tool,
            $arguments
        );

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

    /**
     * @param array<mixed> $usage
     * @return array<mixed>
     */
    public function recordInteractionUsage(
        string $sessionId,
        array $usage
    ): array {
        return $this->mutate(
            $sessionId,
            function (array $context) use ($usage): array {
                $normalizedUsage = $usage;

                $input = (int) (
                    $normalizedUsage['input_tokens'] ?? 0
                );

                $output = (int) (
                    $normalizedUsage['output_tokens'] ?? 0
                );

                $total = (int) (
                    $normalizedUsage['total_tokens']
                    ?? ($input + $output)
                );

                $normalizedUsage['input_tokens'] = $input;
                $normalizedUsage['output_tokens'] = $output;
                $normalizedUsage['total_tokens'] = $total;
                $normalizedUsage['created_at'] = $this->now();

                $context['usage']['input_tokens'] += $input;
                $context['usage']['output_tokens'] += $output;
                $context['usage']['total_tokens'] += $total;
                $context['usage']['interactions'][] =
                    $normalizedUsage;

                return $context;
            }
        );
    }

    /**
     * @param array<mixed> $arguments
     */
    public function toolFingerprint(
        string $sessionId,
        string $tool,
        array $arguments
    ): string {
        $normalized = $this->normalizeForFingerprint(
            $arguments
        );

        return hash(
            'sha256',
            $sessionId
            . '|'
            . mb_strtolower(trim($tool), 'UTF-8')
            . '|'
            . json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );
    }

    /**
     * @return array<mixed>
     */
    private function mutate(
        string $sessionId,
        callable $mutation
    ): array {
        return $this->withSessionLock(
            $sessionId,
            LOCK_EX,
            function () use ($sessionId, $mutation): array {
                $context = $this->loadUnlocked($sessionId);
                $mutated = $mutation($context);

                if (!is_array($mutated)) {
                    throw new RuntimeException(
                        'Mutação de contexto precisa retornar array.'
                    );
                }

                return $this->writeUnlocked(
                    $sessionId,
                    $mutated
                );
            }
        );
    }

    /**
     * @return array<mixed>
     */
    private function loadUnlocked(string $sessionId): array
    {
        $file = $this->file($sessionId);

        if (!is_file($file)) {
            return $this->emptyContext($sessionId);
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(
                "Não foi possível ler o contexto da sessão {$sessionId}."
            );
        }

        if (trim($contents) === '') {
            throw new RuntimeException(
                "Contexto vazio/corrompido para a sessão {$sessionId}."
            );
        }

        try {
            $context = json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Contexto inválido/corrompido para a sessão {$sessionId}.",
                0,
                $exception
            );
        }

        if (!is_array($context)) {
            throw new RuntimeException(
                "Contexto inválido/corrompido para a sessão {$sessionId}."
            );
        }

        return $this->normalizeContext(
            $sessionId,
            $context
        );
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function writeUnlocked(
        string $sessionId,
        array $context
    ): array {
        $context = $this->normalizeContext(
            $sessionId,
            $context
        );

        $context['schema_version'] = self::SCHEMA_VERSION;
        $context['session_id'] = $sessionId;
        $context['updated_at'] = $this->now();

        if (
            !isset($context['created_at'])
            || trim((string) $context['created_at']) === ''
        ) {
            $context['created_at'] = $context['updated_at'];
        }

        try {
            $json = json_encode(
                $context,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Não foi possível serializar o contexto da sessão {$sessionId}.",
                0,
                $exception
            );
        }

        $file = $this->file($sessionId);
        $temporary = $file
            . '.tmp.'
            . bin2hex(random_bytes(8));

        $handle = fopen($temporary, 'xb');

        if ($handle === false) {
            throw new RuntimeException(
                "Não foi possível criar arquivo temporário para a sessão {$sessionId}."
            );
        }

        $completed = false;

        try {
            $payload = $json . PHP_EOL;
            $length = strlen($payload);
            $written = 0;

            while ($written < $length) {
                $bytes = fwrite(
                    $handle,
                    substr($payload, $written)
                );

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException(
                        "Falha ao gravar contexto temporário da sessão {$sessionId}."
                    );
                }

                $written += $bytes;
            }

            if (!fflush($handle)) {
                throw new RuntimeException(
                    "Falha ao descarregar contexto temporário da sessão {$sessionId}."
                );
            }

            if (
                function_exists('fsync')
                && !fsync($handle)
            ) {
                throw new RuntimeException(
                    "Falha ao sincronizar contexto temporário da sessão {$sessionId}."
                );
            }

            if (!fclose($handle)) {
                $handle = null;

                throw new RuntimeException(
                    "Falha ao fechar contexto temporário da sessão {$sessionId}."
                );
            }

            $handle = null;

            if (!rename($temporary, $file)) {
                throw new RuntimeException(
                    "Não foi possível publicar atomicamente o contexto da sessão {$sessionId}."
                );
            }

            $completed = true;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (!$completed && is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $context;
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function normalizeContext(
        string $sessionId,
        array $context
    ): array {
        $version = array_key_exists(
            'schema_version',
            $context
        )
            ? (int) $context['schema_version']
            : 0;

        if ($version > self::SCHEMA_VERSION) {
            throw new RuntimeException(
                "Versão de contexto não suportada para a sessão {$sessionId}: {$version}."
            );
        }

        $now = $this->now();

        $context['schema_version'] = self::SCHEMA_VERSION;
        $context['session_id'] = $sessionId;
        $context['intent'] = $context['intent'] ?? null;

        if (!is_array($context['state'] ?? null)) {
            $context['state'] = [];
        }

        if (!is_array($context['messages'] ?? null)) {
            $context['messages'] = [];
        }

        if (!is_array($context['tool_history'] ?? null)) {
            $context['tool_history'] = [];
        }

        if (!is_array($context['usage'] ?? null)) {
            $context['usage'] = [];
        }

        $context['usage']['input_tokens'] = (int) (
            $context['usage']['input_tokens'] ?? 0
        );

        $context['usage']['output_tokens'] = (int) (
            $context['usage']['output_tokens'] ?? 0
        );

        $context['usage']['total_tokens'] = (int) (
            $context['usage']['total_tokens'] ?? 0
        );

        if (
            !is_array(
                $context['usage']['interactions']
                ?? null
            )
        ) {
            $context['usage']['interactions'] = [];
        }

        if (
            !isset($context['created_at'])
            || trim((string) $context['created_at']) === ''
        ) {
            $context['created_at'] = $now;
        }

        if (
            !isset($context['updated_at'])
            || trim((string) $context['updated_at']) === ''
        ) {
            $context['updated_at'] =
                $context['created_at'];
        }

        return $context;
    }

    /**
     * @return array<mixed>
     */
    private function emptyContext(string $sessionId): array
    {
        $now = $this->now();

        return [
            'schema_version' => self::SCHEMA_VERSION,
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

    /**
     * @param int<0, 7> $operation
     */
    private function withSessionLock(
        string $sessionId,
        int $operation,
        callable $callback
    ): mixed {
        $lockFile = $this->lockFile($sessionId);
        $handle = fopen($lockFile, 'c+');

        if ($handle === false) {
            throw new RuntimeException(
                "Não foi possível abrir lock da sessão {$sessionId}."
            );
        }

        try {
            if (!flock($handle, $operation)) {
                throw new RuntimeException(
                    "Não foi possível adquirir lock da sessão {$sessionId}."
                );
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function file(string $sessionId): string
    {
        return rtrim(
            $this->directory,
            DIRECTORY_SEPARATOR
        )
            . DIRECTORY_SEPARATOR
            . hash('sha256', $sessionId)
            . '.json';
    }

    private function lockFile(string $sessionId): string
    {
        return rtrim(
            $this->directory,
            DIRECTORY_SEPARATOR
        )
            . DIRECTORY_SEPARATOR
            . hash('sha256', $sessionId)
            . '.lock';
    }

    private function now(): string
    {
        return (
            new DateTimeImmutable(
                'now',
                $this->timezone
            )
        )->format(DATE_ATOM);
    }

    private function normalizeForFingerprint(
        mixed $value
    ): mixed {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(
                    fn (mixed $item): mixed =>
                        $this->normalizeForFingerprint(
                            $item
                        ),
                    $value
                );
            }

            ksort($value);

            foreach ($value as $key => $item) {
                $value[$key] =
                    $this->normalizeForFingerprint(
                        $item
                    );
            }

            return $value;
        }

        if (is_string($value)) {
            return mb_strtolower(
                trim($value),
                'UTF-8'
            );
        }

        return $value;
    }
}
