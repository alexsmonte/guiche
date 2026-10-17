<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Session;

use Asmpkg\Guiche\Clock\SystemClock;
use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Guiche;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use WeakMap;

/**
 * Lifecycle conversacional opcional por sessão.
 *
 * O Builder habilita o recurso com withSessionTtl().
 * Sem configuração, o Guichê mantém o comportamento legado.
 *
 * A configuração por runtime usa WeakMap para não ampliar o construtor
 * público congelado de Guiche nem transportar opções internas por
 * agent_context.
 */
final class SessionLifecycleManager
{
    /**
     * @var WeakMap<Guiche, int>|null
     */
    private static ?WeakMap $configuredTtl = null;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly ContextStoreInterface $context,
        private readonly int $ttlSeconds,
        ?ClockInterface $clock = null
    ) {
        if ($this->ttlSeconds < 1) {
            throw new InvalidArgumentException(
                'Session TTL precisa ser maior ou igual a 1 segundo.'
            );
        }

        $this->clock = $clock ?? new SystemClock();
    }

    public static function configure(
        Guiche $guiche,
        int $ttlSeconds
    ): void {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException(
                'Session TTL precisa ser maior ou igual a 1 segundo.'
            );
        }

        self::$configuredTtl ??= new WeakMap();
        self::$configuredTtl[$guiche] = $ttlSeconds;
    }

    public static function configuredTtl(
        Guiche $guiche
    ): ?int {
        if (self::$configuredTtl === null) {
            return null;
        }

        return self::$configuredTtl[$guiche] ?? null;
    }

    /**
     * Prepara o ciclo antes de mensagem ou execução direta de Tool.
     *
     * @return array{
     *   status: 'new'|'active'|'expired',
     *   is_first_message: bool,
     *   is_new_cycle: bool,
     *   was_expired: bool,
     *   cycle: int,
     *   ttl_seconds: int,
     *   started_at: string
     * }
     */
    public function beginTurn(string $sessionId): array
    {
        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            throw new InvalidArgumentException(
                'sessionId não pode ser vazio no Session Lifecycle.'
            );
        }

        $context = $this->context->load($sessionId);
        $now = $this->clock->now();

        if (!$this->hasConversationActivity($context)) {
            return $this->beginEmptyCycle(
                $sessionId,
                $context,
                $now
            );
        }

        if ($this->isExpired($context, $now)) {
            return $this->beginExpiredCycle(
                $sessionId,
                $context,
                $now
            );
        }

        return $this->continueActiveCycle(
            $sessionId,
            $context,
            $now
        );
    }

    /**
     * @param array<mixed> $context
     * @return array{
     *   status: 'new'|'active'|'expired',
     *   is_first_message: bool,
     *   is_new_cycle: bool,
     *   was_expired: bool,
     *   cycle: int,
     *   ttl_seconds: int,
     *   started_at: string
     * }
     */
    private function beginEmptyCycle(
        string $sessionId,
        array $context,
        DateTimeImmutable $now
    ): array {
        $existing = $this->sessionMetadata($context);
        $existingStatus = trim(
            (string) ($existing['status'] ?? '')
        );

        /*
         * Se uma execução caiu depois de preparar o ciclo, mas antes
         * de registrar atividade, repetimos a mesma transição em vez
         * de incrementar cycle novamente.
         */
        if (
            in_array(
                $existingStatus,
                ['new', 'expired'],
                true
            )
            && ($existing['is_first_message'] ?? false) === true
        ) {
            $metadata = $existing;
            $metadata['ttl_seconds'] = $this->ttlSeconds;

            $context['session'] = $metadata;
            $this->context->save($sessionId, $context);

            return $this->publicSnapshot($metadata);
        }

        $cycle = max(
            1,
            (int) ($existing['cycle'] ?? 1)
        );

        $metadata = [
            'status' => 'new',
            'is_first_message' => true,
            'is_new_cycle' => true,
            'was_expired' => false,
            'cycle' => $cycle,
            'ttl_seconds' => $this->ttlSeconds,
            'started_at' => $now->format(DATE_ATOM),
            'transition_at' => $now->format(DATE_ATOM),
        ];

        if (isset($existing['previous_cycle'])) {
            $metadata['previous_cycle'] =
                $existing['previous_cycle'];
        }

        $context['session'] = $metadata;
        $this->context->save($sessionId, $context);

        return $this->publicSnapshot($metadata);
    }

    /**
     * @param array<mixed> $context
     * @return array{
     *   status: 'new'|'active'|'expired',
     *   is_first_message: bool,
     *   is_new_cycle: bool,
     *   was_expired: bool,
     *   cycle: int,
     *   ttl_seconds: int,
     *   started_at: string
     * }
     */
    private function continueActiveCycle(
        string $sessionId,
        array $context,
        DateTimeImmutable $now
    ): array {
        $existing = $this->sessionMetadata($context);

        $cycle = max(
            1,
            (int) ($existing['cycle'] ?? 1)
        );

        $startedAt = trim(
            (string) (
                $existing['started_at']
                ?? $context['created_at']
                ?? $now->format(DATE_ATOM)
            )
        );

        if ($startedAt === '') {
            $startedAt = $now->format(DATE_ATOM);
        }

        $metadata = [
            'status' => 'active',
            'is_first_message' => false,
            'is_new_cycle' => false,
            'was_expired' => false,
            'cycle' => $cycle,
            'ttl_seconds' => $this->ttlSeconds,
            'started_at' => $startedAt,
            'transition_at' => $now->format(DATE_ATOM),
            'last_activity_at' => $this->activityTimestamp(
                $context
            )->format(DATE_ATOM),
        ];

        if (isset($existing['previous_cycle'])) {
            $metadata['previous_cycle'] =
                $existing['previous_cycle'];
        }

        $context['session'] = $metadata;
        $this->context->save($sessionId, $context);

        return $this->publicSnapshot($metadata);
    }

    /**
     * Expiração inicia um ciclo operacional limpo.
     *
     * Preservamos somente execution_context confiável e um resumo
     * não sensível do ciclo anterior. Intent, state, mensagens,
     * cache/replay de Tools e usage do ciclo são reiniciados.
     *
     * @param array<mixed> $context
     * @return array{
     *   status: 'new'|'active'|'expired',
     *   is_first_message: bool,
     *   is_new_cycle: bool,
     *   was_expired: bool,
     *   cycle: int,
     *   ttl_seconds: int,
     *   started_at: string
     * }
     */
    private function beginExpiredCycle(
        string $sessionId,
        array $context,
        DateTimeImmutable $now
    ): array {
        $existing = $this->sessionMetadata($context);

        $previousCycle = max(
            1,
            (int) ($existing['cycle'] ?? 1)
        );

        $previousStartedAt = trim(
            (string) (
                $existing['started_at']
                ?? $context['created_at']
                ?? ''
            )
        );

        $lastActivity =
            $this->activityTimestamp($context);

        $executionContext =
            $context['execution_context'] ?? [];

        if (!is_array($executionContext)) {
            throw new RuntimeException(
                'execution_context inválido durante expiração da sessão.'
            );
        }

        $messages = is_array(
            $context['messages'] ?? null
        )
            ? $context['messages']
            : [];

        $history = is_array(
            $context['tool_history'] ?? null
        )
            ? $context['tool_history']
            : [];

        $usage = is_array(
            $context['usage'] ?? null
        )
            ? $context['usage']
            : [];

        $metadata = [
            'status' => 'expired',
            'is_first_message' => true,
            'is_new_cycle' => true,
            'was_expired' => true,
            'cycle' => $previousCycle + 1,
            'ttl_seconds' => $this->ttlSeconds,
            'started_at' => $now->format(DATE_ATOM),
            'transition_at' => $now->format(DATE_ATOM),
            'previous_cycle' => [
                'cycle' => $previousCycle,
                'started_at' =>
                    $previousStartedAt !== ''
                        ? $previousStartedAt
                        : null,
                'last_activity_at' =>
                    $lastActivity->format(DATE_ATOM),
                'expired_at' => $now->format(DATE_ATOM),
                'message_count' => count($messages),
                'tool_execution_count' => count($history),
                'input_tokens' => (int) (
                    $usage['input_tokens'] ?? 0
                ),
                'output_tokens' => (int) (
                    $usage['output_tokens'] ?? 0
                ),
                'total_tokens' => (int) (
                    $usage['total_tokens'] ?? 0
                ),
            ],
        ];

        $fresh = [
            'schema_version' =>
                (int) ($context['schema_version'] ?? 1),
            'session_id' => $sessionId,
            'intent' => null,
            'execution_context' => $executionContext,
            'state' => [],
            'messages' => [],
            'tool_history' => [],
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'interactions' => [],
            ],
            'created_at' => $now->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
            'session' => $metadata,
        ];

        $this->context->save($sessionId, $fresh);

        return $this->publicSnapshot($metadata);
    }

    /**
     * @param array<mixed> $context
     */
    private function isExpired(
        array $context,
        DateTimeImmutable $now
    ): bool {
        $lastActivity = $this->activityTimestamp(
            $context
        );

        $elapsed =
            $now->getTimestamp()
            - $lastActivity->getTimestamp();

        if ($elapsed < 0) {
            /*
             * Pequeno clock skew não deve expirar sessão ativa.
             */
            return false;
        }

        return $elapsed >= $this->ttlSeconds;
    }

    /**
     * @param array<mixed> $context
     */
    private function activityTimestamp(
        array $context
    ): DateTimeImmutable {
        $raw = trim(
            (string) (
                $context['updated_at']
                ?? $context['created_at']
                ?? ''
            )
        );

        if ($raw === '') {
            throw new RuntimeException(
                'Contexto sem timestamp para Session Lifecycle.'
            );
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Timestamp inválido no contexto para Session Lifecycle.',
                0,
                $exception
            );
        }
    }

    /**
     * @param array<mixed> $context
     */
    private function hasConversationActivity(
        array $context
    ): bool {
        if (($context['intent'] ?? null) !== null) {
            return true;
        }

        foreach (
            ['state', 'messages', 'tool_history']
            as $key
        ) {
            if (
                is_array($context[$key] ?? null)
                && $context[$key] !== []
            ) {
                return true;
            }
        }

        $interactions =
            $context['usage']['interactions'] ?? [];

        return is_array($interactions)
            && $interactions !== [];
    }

    /**
     * @param array<mixed> $context
     * @return array<string, mixed>
     */
    private function sessionMetadata(
        array $context
    ): array {
        $metadata = $context['session'] ?? [];

        return is_array($metadata)
            ? $metadata
            : [];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{
     *   status: 'new'|'active'|'expired',
     *   is_first_message: bool,
     *   is_new_cycle: bool,
     *   was_expired: bool,
     *   cycle: int,
     *   ttl_seconds: int,
     *   started_at: string
     * }
     */
    private function publicSnapshot(
        array $metadata
    ): array {
        $status = (string) (
            $metadata['status'] ?? 'active'
        );

        if (
            !in_array(
                $status,
                ['new', 'active', 'expired'],
                true
            )
        ) {
            $status = 'active';
        }

        return [
            'status' => $status,
            'is_first_message' => (bool) (
                $metadata['is_first_message']
                ?? false
            ),
            'is_new_cycle' => (bool) (
                $metadata['is_new_cycle']
                ?? false
            ),
            'was_expired' => (bool) (
                $metadata['was_expired']
                ?? false
            ),
            'cycle' => max(
                1,
                (int) ($metadata['cycle'] ?? 1)
            ),
            'ttl_seconds' => $this->ttlSeconds,
            'started_at' => (string) (
                $metadata['started_at'] ?? ''
            ),
        ];
    }
}
