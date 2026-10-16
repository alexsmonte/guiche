<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use Asmpkg\Guiche\Observability\NullRuntimeEventSink;
use Asmpkg\Guiche\Observability\RuntimeEventFactory;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Tenant\TenantResolutionService;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\Safety\ToolExecutionGate;
use Asmpkg\Guiche\Safety\ToolSafetyMetadata;
use RuntimeException;
use Throwable;

final class Guiche
{
    private readonly RuntimeEventSinkInterface $eventSink;
    private readonly ?TenantResolutionService $tenantResolution;
    private ?ToolPolicy $toolPolicy = null;
    /** @var array<string, int> */
    private static array $activeSessionTurnLocks = [];
    /**
     * @param array<mixed> $agentContext
     * @param array<mixed> $aiStateExcludePaths
     * @param array<mixed> $aiToolResultExcludePaths
     * @param array<mixed> $aiToolResultIncludePaths
     * @param array<mixed> $completionGuards
     * @param array<mixed> $reusableTools
     */
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ContextStoreInterface $context,
        private readonly ?AiProviderInterface $ai = null,
        private readonly string $instructions = '',
        private readonly array $agentContext = [],
        private readonly ?RuntimeContext $runtime = null,
        private readonly ?StateManagerInterface $stateManager = null,
        private readonly array $reusableTools = [],
        private readonly array $completionGuards = [],
        private readonly int $historyMessagesLimit = 8,
        private readonly array $aiStateExcludePaths = [],
        private readonly array $aiToolResultExcludePaths = [],
        private readonly int $maxToolRounds = 5,
        private readonly array $aiToolResultIncludePaths = [],
        ?RuntimeEventSinkInterface $eventSink = null,
        ?TenantResolverInterface $tenantResolver = null,
        private readonly ?PromptResolverInterface $promptResolver = null
    ) {
        $this->eventSink = $eventSink ?? new NullRuntimeEventSink();
        $this->tenantResolution = $tenantResolver !== null
            ? new TenantResolutionService(
                $this->context,
                $tenantResolver
            )
            : null;
    }

    /**
     * @return array<mixed>
     */
    public function tools(): array
    {
        return $this->tools->definitions();
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function executeTool(
        string $name,
        array $arguments = [],
        ?string $sessionId = null
    ): array {
        $normalizedSessionId = $sessionId !== null
            ? trim($sessionId)
            : '';

        $context = $normalizedSessionId !== ''
            ? $this->context->load($normalizedSessionId)
            : [];

        $registration = $this->executionGate()->assertCanExecute(
            $name,
            $arguments,
            $context
        );

        $operation = ToolSafetyMetadata::operation(
            $registration->metadata()
        );

        $toolExecutionContext =
            $this->toolExecutionContextFor(
                $name,
                $normalizedSessionId
            );

        if ($operation === ToolSafetyMetadata::OPERATION_WRITE) {
            if ($normalizedSessionId === '') {
                throw new ToolBlockedException(
                    $name,
                    'write_session_required',
                    'Ferramentas WRITE exigem sessionId para proteção de idempotência.'
                );
            }

            $previous = $this->context->findSuccessfulToolResult(
                $normalizedSessionId,
                $name,
                $arguments
            );

            if ($previous !== null) {
                return $previous;
            }
        }

        $result = $this->executeRegisteredTool(
            $name,
            $arguments,
            $toolExecutionContext
        );

        if (
            $operation === ToolSafetyMetadata::OPERATION_WRITE
            && $normalizedSessionId !== ''
        ) {
            $this->context->addToolExecution(
                $normalizedSessionId,
                $name,
                $arguments,
                $result
            );
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    public function context(string $sessionId): array
    {
        return $this->context->load($sessionId);
    }

    /**
     * Vincula à sessão uma identidade externa confiável fornecida
     * pela aplicação host, fora do conteúdo controlado pela IA.
     *
     * @param array<mixed> $identity
     */
    public function bindIdentity(
        string $sessionId,
        array $identity
    ): ToolExecutionContext {
        return $this->withSessionTurnLock(
            $sessionId,
            fn (): ToolExecutionContext =>
                $this->bindIdentityUnlocked(
                    $sessionId,
                    $identity
                )
        );
    }

    /**
     * @param array<mixed> $identity
     */
    private function bindIdentityUnlocked(
        string $sessionId,
        array $identity
    ): ToolExecutionContext {
        $interactionId = 'identity-bind-'
            . bin2hex(random_bytes(8));

        $identityFingerprint =
            $this->trustedIdentityFingerprint(
                $identity
            );

        $existingIdentityFingerprint = null;
        $normalizedSessionId = trim($sessionId);

        if ($normalizedSessionId !== '') {
            try {
                $storedBefore = $this->context->load(
                    $normalizedSessionId
                );

                $existingIdentity =
                    $storedBefore['execution_context']
                        ['identity']
                    ?? null;

                if (
                    is_array($existingIdentity)
                    && $existingIdentity !== []
                    && !array_is_list($existingIdentity)
                ) {
                    $existingIdentityFingerprint =
                        $this->trustedIdentityFingerprint(
                            $existingIdentity
                        );
                }
            } catch (Throwable) {
                /*
                 * A leitura real feita pelo seeder continuará
                 * responsável por falhar fechada. Esta leitura
                 * auxiliar existe somente para enriquecer auditoria.
                 */
            }
        }

        $this->recordTrustedIdentityEvent(
            $sessionId,
            $interactionId,
            [
                'type' =>
                    'trusted_identity_binding_started',
                'identity_fingerprint' =>
                    $identityFingerprint,
                'existing_binding' =>
                    $existingIdentityFingerprint !== null,
                'resolver_configured' =>
                    $this->tenantResolution !== null,
            ]
        );

        try {
            $bound = (new ExecutionContextSeeder(
                $this->context
            ))->seed(
                $sessionId,
                ['identity' => $identity]
            );
        } catch (Throwable $exception) {
            $reason = (
                $existingIdentityFingerprint !== null
                && !hash_equals(
                    $existingIdentityFingerprint,
                    $identityFingerprint
                )
            )
                ? 'identity_conflict'
                : 'binding_failed';

            $this->recordTrustedIdentityEvent(
                $sessionId,
                $interactionId,
                [
                    'type' =>
                        'trusted_identity_binding_failed',
                    'identity_fingerprint' =>
                        $identityFingerprint,
                    'reason' => $reason,
                    'exception_class' =>
                        $exception::class,
                ]
            );

            throw $exception;
        }

        $this->recordTrustedIdentityEvent(
            $sessionId,
            $interactionId,
            [
                'type' => 'trusted_identity_bound',
                'identity_fingerprint' =>
                    $identityFingerprint,
                'idempotent' => (
                    $existingIdentityFingerprint !== null
                    && hash_equals(
                        $existingIdentityFingerprint,
                        $identityFingerprint
                    )
                ),
            ]
        );

        if ($this->tenantResolution === null) {
            return $bound;
        }

        try {
            $resolved =
                $this->tenantResolution->resolve(
                    $bound->sessionId()
                );
        } catch (Throwable $exception) {
            $this->recordTrustedIdentityEvent(
                $sessionId,
                $interactionId,
                [
                    'type' =>
                        'trusted_tenant_resolution_failed',
                    'identity_fingerprint' =>
                        $identityFingerprint,
                    'exception_class' =>
                        $exception::class,
                ]
            );

            throw $exception;
        }

        $tenantId = $resolved->tenantId();

        $this->recordTrustedIdentityEvent(
            $sessionId,
            $interactionId,
            [
                'type' =>
                    'trusted_tenant_resolution_succeeded',
                'identity_fingerprint' =>
                    $identityFingerprint,
                'tenant_fingerprint' =>
                    $tenantId === null
                        ? null
                        : hash(
                            'sha256',
                            serialize($tenantId)
                        ),
            ]
        );

        return $resolved;
    }

    /**
     * @param array<mixed> $identity
     */
    private function trustedIdentityFingerprint(
        array $identity
    ): string {
        return hash(
            'sha256',
            serialize(
                $this->canonicalizeTrustedIdentityForFingerprint(
                    $identity
                )
            )
        );
    }

    private function canonicalizeTrustedIdentityForFingerprint(
        mixed $value
    ): mixed {
        if (!is_array($value)) {
            if (is_object($value)) {
                return [
                    '__unsupported_type' =>
                        get_debug_type($value),
                ];
            }

            if (is_resource($value)) {
                return [
                    '__unsupported_type' =>
                        get_resource_type($value),
                ];
            }

            if (
                is_float($value)
                && !is_finite($value)
            ) {
                return [
                    '__unsupported_float' =>
                        (string) $value,
                ];
            }

            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $value[$index] =
                    $this->canonicalizeTrustedIdentityForFingerprint(
                        $item
                    );
            }

            return $value;
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] =
                $this->canonicalizeTrustedIdentityForFingerprint(
                    $item
                );
        }

        return $value;
    }

    /**
     * @param array<mixed> $event
     */
    private function recordTrustedIdentityEvent(
        string $sessionId,
        string $interactionId,
        array $event
    ): void {
        try {
            $this->eventSink->record(
                RuntimeEventFactory::fromLegacy(
                    $sessionId,
                    $interactionId,
                    $event
                )
            );
        } catch (Throwable) {
            /*
             * Observabilidade é best-effort.
             * Uma falha do sink nunca pode alterar o resultado
             * do binding ou da resolução de tenant.
             */
        }
    }

    public function reset(string $sessionId): void
    {
        $this->context->delete($sessionId);
    }

    /**
     * @return array<mixed>
     */
    public function handle(string $sessionId, string $message): array
    {
        return $this->withSessionTurnLock(
            $sessionId,
            fn (): array => $this->handleUnlocked($sessionId, $message)
        );
    }

    /**
     * @return array<mixed>
     */
    private function handleUnlocked(string $sessionId, string $message): array
    {
        if ($this->ai === null) {
            throw new RuntimeException('Nenhum provider de IA foi configurado no Guichê.');
        }

        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException('A mensagem não pode estar vazia.');
        }

        /*
         * O prompt dinâmico é resolvido exatamente uma vez por turno,
         * antes de StateManager e antes da persistência da mensagem.
         * Assim, conteúdo controlado pelo usuário não participa da
         * seleção da configuração operacional do agente.
         */
        $resolvedPrompt = $this->resolvedPromptForSession($sessionId);

        $interactionStartedAt = hrtime(true);
        $interactionId = bin2hex(random_bytes(8));
        $events = [
            [
                'type' => 'interaction_started',
            ],
        ];

        $contextBefore = $this->observedContextLoad($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId);

        if ($this->stateManager !== null) {
            $patch = $this->stateManager->onUserMessage($contextBefore, $message);
            $this->observedContextApplyPatch($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId, $patch);
        }

        $this->observedContextAddMessage($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId, 'user', $message);

        $context = $this->observedContextLoad($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId);

        $interactionUsage = [
            'interaction_id' => $interactionId,
            'user_message' => $message,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'api_calls' => [],
        ];

        $response = $this->observedAiRespond($sessionId, $interactionId, $interactionStartedAt, $events,
            $this->buildInstructions($context, $resolvedPrompt),
            $this->messagesForAi($context),
            $this->toolDefinitionsForContext($context)
        );

        $this->accumulateUsage($interactionUsage, $response);

        $events[] = [
            'type' => 'ai_response',
            'response_id' => $response['id'],
            'text' => $response['text'],
            'tool_calls' => $response['tool_calls'],
            'usage' => $response['usage'] ?? [],
        ];

        $round = 0;

        while (!empty($response['tool_calls'])) {
            $round++;

            if ($round > $this->maxToolRounds) {
                throw new RuntimeException('Limite máximo de rodadas de ferramentas atingido.');
            }

            if (($response['id'] ?? '') === '') {
                throw new RuntimeException('Provider não retornou response_id.');
            }

            $outputs = [];

            foreach ($response['tool_calls'] as $toolCall) {
                $name = (string) ($toolCall['name'] ?? '');
                $arguments = $toolCall['arguments'] ?? [];
                $callId = (string) ($toolCall['call_id'] ?? '');

                $events[] = [
                    'type' => 'tool_call',
                    'call_id' => $callId,
                    'name' => $name,
                    'arguments' => $arguments,
                ];

                $cached = false;
                $blocked = false;
                $isWrite = false;
                $previousWrite = null;
                $result = null;
                $toolExecutionContext = null;

                /*
                 * Gate 1:
                 * autoriza a chamada antes de qualquer cache/reuso.
                 * Uma Tool que já ficou proibida não pode recuperar
                 * um resultado antigo como atalho de policy.
                 */
                try {
                    $currentContext = $this->observedContextLoad($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId);

                    $registration = $this->executionGate()->assertCanExecute(
                        $name,
                        $arguments,
                        $currentContext
                    );

                    $operation = ToolSafetyMetadata::operation(
                        $registration->metadata()
                    );

                    $isWrite = $operation
                        === ToolSafetyMetadata::OPERATION_WRITE;

                    $toolExecutionContext =
                        $this->toolExecutionContextFor(
                            $name,
                            $sessionId
                        );
                } catch (ToolBlockedException $exception) {
                    $blocked = true;
                    $result = $exception->toArray();

                    $events[] = [
                        'type' => 'tool_blocked',
                        'call_id' => $callId,
                        'name' => $name,
                        'reason' => $exception->reasonCode(),
                        'details' => $exception->details(),
                    ];
                }

                if (
                    !$blocked
                    && !$isWrite
                    && in_array($name, $this->reusableTools, true)
                ) {
                    $result = $this->context->findSuccessfulToolResult(
                        $sessionId,
                        $name,
                        $arguments
                    );

                    if ($result !== null) {
                        $cached = true;
                        $events[] = [
                            'type' => 'tool_cache_hit',
                            'call_id' => $callId,
                            'name' => $name,
                            'arguments' => $arguments,
                        ];
                    }
                }

                if (!$blocked && $isWrite) {
                    $previousWrite = $this->context->findSuccessfulToolResult(
                        $sessionId,
                        $name,
                        $arguments
                    );

                    if ($previousWrite !== null) {
                        $result = $previousWrite;

                        $events[] = [
                            'type' => 'tool_write_replay',
                            'call_id' => $callId,
                            'name' => $name,
                            'fingerprint' => $this->context->toolFingerprint(
                                $sessionId,
                                $name,
                                $arguments
                            ),
                        ];
                    }
                }

                if (!$blocked && $result === null) {
                    try {
                        /*
                         * Gate 2:
                         * revalida o contexto imediatamente antes da
                         * execução real. Isto fecha stale tool calls
                         * inclusive entre múltiplas calls da mesma
                         * resposta da IA.
                         */
                        $currentContext = $this->observedContextLoad($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId);

                        $registration = $this->executionGate()->assertCanExecute(
                            $name,
                            $arguments,
                            $currentContext
                        );

                        $operation = ToolSafetyMetadata::operation(
                            $registration->metadata()
                        );

                        $isWrite = $operation
                            === ToolSafetyMetadata::OPERATION_WRITE;

                        $toolExecutionContext =
                            $this->toolExecutionContextFor(
                                $name,
                                $sessionId
                            );

                        if ($isWrite) {
                            $previousWrite = $this->context->findSuccessfulToolResult(
                                $sessionId,
                                $name,
                                $arguments
                            );

                            if ($previousWrite !== null) {
                                $result = $previousWrite;

                                $events[] = [
                                    'type' => 'tool_write_replay',
                                    'call_id' => $callId,
                                    'name' => $name,
                                    'fingerprint' => $this->context->toolFingerprint(
                                        $sessionId,
                                        $name,
                                        $arguments
                                    ),
                                ];
                            }
                        }

                        if ($result === null) {
                            $result = $this->observedToolExecution($sessionId, $interactionId, $events, $callId,
                                $name,
                                $arguments,
                            fn (): array => $this->executeRegisteredTool(
                                $name,
                                $arguments,
                                $toolExecutionContext
                            )
                        );
                        }
                    } catch (ToolBlockedException $exception) {
                        $blocked = true;
                        $result = $exception->toArray();

                        $events[] = [
                            'type' => 'tool_blocked',
                            'call_id' => $callId,
                            'name' => $name,
                            'reason' => $exception->reasonCode(),
                            'details' => $exception->details(),
                        ];
                    } catch (Throwable $exception) {
                        $result = [
                            'success' => false,
                            'tool' => $name,
                            'error' => $exception->getMessage(),
                        ];
                    }

                    if (
                        !$blocked
                        && !(
                            $isWrite
                            && $previousWrite !== null
                        )
                    ) {
                        $this->observedAddToolExecution($interactionId, $events, $callId,
                            $sessionId,
                            $name,
                            $arguments,
                            $result
                        );
                    }
                }

                if (!$blocked && $this->stateManager !== null) {
                    $currentContext = $this->observedContextLoad($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId);
                    $patch = $this->stateManager->onToolResult(
                        $currentContext,
                        $name,
                        $arguments,
                        $result
                    );

                    $this->observedContextApplyPatch($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId, $patch);
                }

                $events[] = [
                    'type' => 'tool_result',
                    'call_id' => $callId,
                    'name' => $name,
                    'cached' => $cached,
                    'result' => $result,
                ];

                $outputs[] = [
                    'call_id' => $callId,
                    'output' => $this->toolResultForAi(
                        $name,
                        $result
                    ),
                ];
            }

            $context = $this->observedContextLoad($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId);

            $response = $this->observedAiContinue($sessionId, $interactionId, $interactionStartedAt, $events,
                $response['id'],
                $this->buildInstructions($context, $resolvedPrompt),
                $outputs,
                $this->toolDefinitionsForContext($context)
            );

            $this->accumulateUsage($interactionUsage, $response);

            $events[] = [
                'type' => 'ai_response',
                'response_id' => $response['id'],
                'text' => $response['text'],
                'tool_calls' => $response['tool_calls'],
                'usage' => $response['usage'] ?? [],
            ];
        }

        $text = trim((string) ($response['text'] ?? ''));

        if ($text === '') {
            $text = 'Não consegui gerar uma resposta textual.';
        }

        $finalContext = $this->observedContextLoad($sessionId, $interactionId, $interactionStartedAt, $events,
            $sessionId
        );

        $text = $this->applyCompletionGuards(
            $finalContext,
            $events,
            $text
        );

        $this->observedContextAddMessage($sessionId, $interactionId, $interactionStartedAt, $events,
            $sessionId,
            'assistant',
            $text
        );

        $interactionUsage['assistant_message'] = $text;
        $this->observedRecordInteractionUsage($sessionId, $interactionId, $interactionStartedAt, $events, $sessionId, $interactionUsage);

        $events[] = [
            'type' => 'interaction_completed',
            'duration_ms' => $this->durationMs($interactionStartedAt),
            'tool_rounds' => $round,
            'ai_calls' => count($interactionUsage['api_calls'] ?? []),
            'input_tokens' => (int) ($interactionUsage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($interactionUsage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($interactionUsage['total_tokens'] ?? 0),
        ];

        $this->persistRuntimeEvents(
            $sessionId,
            (string) (
                $interactionUsage['interaction_id']
                ?? ''
            ),
            $events
        );


        return [
            'message' => $text,
            'events' => $events,
            'usage' => $interactionUsage,
            'runtime' => $this->runtime?->current() ?? [],
            'context' => $this->context->load($sessionId),
        ];
    }

    private function withSessionTurnLock(
        string $sessionId,
        callable $callback
    ): mixed {
        $lockDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'asmpkg-guiche-turn-locks-'
            . substr(hash('sha256', __DIR__), 0, 16);

        if (
            !is_dir($lockDirectory)
            && !mkdir($lockDirectory, 0775, true)
            && !is_dir($lockDirectory)
        ) {
            throw new RuntimeException(
                'Não foi possível criar o diretório de locks de turno.'
            );
        }

        $lockFile = $lockDirectory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $sessionId)
            . '.lock';

        if (isset(self::$activeSessionTurnLocks[$lockFile])) {
            self::$activeSessionTurnLocks[$lockFile]++;

            try {
                return $callback();
            } finally {
                self::$activeSessionTurnLocks[$lockFile]--;

                if (self::$activeSessionTurnLocks[$lockFile] === 0) {
                    unset(self::$activeSessionTurnLocks[$lockFile]);
                }
            }
        }

        $handle = fopen($lockFile, 'c+');

        if ($handle === false) {
            throw new RuntimeException(
                "Não foi possível abrir lock de turno da sessão {$sessionId}."
            );
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException(
                    "Não foi possível adquirir lock de turno da sessão {$sessionId}."
                );
            }

            self::$activeSessionTurnLocks[$lockFile] = 1;

            try {
                return $callback();
            } finally {
                unset(self::$activeSessionTurnLocks[$lockFile]);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function toolExecutionContextFor(
        string $name,
        string $sessionId
    ): ?ToolExecutionContext {
        $tool = $this->tools->get($name);

        if (!$tool instanceof ContextAwareToolInterface) {
            return null;
        }

        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            throw new ToolBlockedException(
                $name,
                'context_session_required',
                'Ferramentas contextuais exigem sessionId.'
            );
        }

        try {
            if ($this->tenantResolution !== null) {
                return $this->tenantResolution->resolve(
                    $sessionId
                );
            }

            $stored = $this->context->load($sessionId);
            $values = $stored['execution_context'] ?? [];

            if (!is_array($values)) {
                throw new RuntimeException(
                    'execution_context persistido é inválido.'
                );
            }

            $identity = $values['identity'] ?? null;

            if (
                !is_array($identity)
                || $identity === []
                || array_is_list($identity)
            ) {
                throw new ToolBlockedException(
                    $name,
                    'identity_context_required',
                    'Ferramenta contextual sem identidade confiável vinculada à sessão.'
                );
            }

            $context = new ToolExecutionContext(
                $sessionId,
                $values
            );

            if ($context->tenantId() === null) {
                throw new ToolBlockedException(
                    $name,
                    'tenant_context_required',
                    'Ferramenta contextual sem tenant vinculado à sessão.'
                );
            }

            return $context;
        } catch (ToolBlockedException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ToolBlockedException(
                $name,
                'tenant_context_unavailable',
                'Contexto de tenant indisponível para a ferramenta.',
                ['exception_class' => $exception::class],
                $exception
            );
        }
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    private function executeRegisteredTool(
        string $name,
        array $arguments,
        ?ToolExecutionContext $executionContext
    ): array {
        $tool = $this->tools->get($name);

        if ($tool instanceof ContextAwareToolInterface) {
            if ($executionContext === null) {
                throw new ToolBlockedException(
                    $name,
                    'tenant_context_required',
                    'Ferramenta contextual sem contexto de execução.'
                );
            }

            return $tool->executeWithContext(
                $arguments,
                $executionContext
            );
        }

        return $this->tools->execute(
            $name,
            $arguments
        );
    }

    private function executionGate(): ToolExecutionGate
    {
        return new ToolExecutionGate(
            $this->tools,
            $this->toolPolicy
        );
    }

    /**
     * @param array<mixed> $context
     * @param array<mixed> $events
     */
    private function applyCompletionGuards(
        array $context,
        array &$events,
        string $text
    ): string {
        $state = $context['state'] ?? [];

        foreach ($this->completionGuards as $guard) {
            $stateKey = (string) (
                $guard['state_key'] ?? ''
            );

            $stateValue = $guard['state_value']
                ?? null;

            $requiredTool = (string) (
                $guard['required_tool'] ?? ''
            );

            $fallback = trim(
                (string) (
                    $guard['fallback_message']
                    ?? ''
                )
            );

            if (
                $stateKey === ''
                || $requiredTool === ''
                || $fallback === ''
            ) {
                continue;
            }

            if (
                ($state[$stateKey] ?? null)
                !== $stateValue
            ) {
                continue;
            }

            $toolExecuted = false;

            foreach ($events as $event) {
                if (
                    ($event['type'] ?? null)
                        !== 'tool_result'
                    || ($event['name'] ?? null)
                        !== $requiredTool
                ) {
                    continue;
                }

                if (
                    ($event['result']['success'] ?? false)
                    === true
                ) {
                    $toolExecuted = true;
                    break;
                }
            }

            if ($toolExecuted) {
                continue;
            }

            $events[] = [
                'type' => 'completion_guard',
                'state_key' => $stateKey,
                'state_value' => $stateValue,
                'required_tool' => $requiredTool,
                'tool_registered' =>
                    $this->tools->has($requiredTool),
                'original_text' => $text,
                'replacement_text' => $fallback,
            ];

            return $fallback;
        }

        return $text;
    }

    /**
     * @param array<mixed> $interactionUsage
     * @param array<mixed> $response
     */
    private function accumulateUsage(array &$interactionUsage, array $response): void
    {
        $usage = $response['usage'] ?? [];

        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($input + $output));

        $interactionUsage['input_tokens'] += $input;
        $interactionUsage['output_tokens'] += $output;
        $interactionUsage['total_tokens'] += $total;

        $interactionUsage['api_calls'][] = [
            'response_id' => $response['id'] ?? null,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'details' => $usage,
        ];
    }

    private function resolvedPromptForSession(
        string $sessionId
    ): ?string {
        if ($this->promptResolver === null) {
            return null;
        }

        $prompt = trim(
            $this->promptResolver->resolve(
                $this->promptContextForSession($sessionId)
            )
        );

        if ($prompt === '') {
            throw new RuntimeException(
                'PromptResolver retornou um prompt vazio.'
            );
        }

        return $prompt;
    }

    private function promptContextForSession(
        string $sessionId
    ): PromptContext {
        if ($this->tenantResolution !== null) {
            $executionContext =
                $this->tenantResolution->resolve($sessionId);

            return new PromptContext(
                $executionContext->sessionId(),
                $executionContext->all()
            );
        }

        $stored = $this->context->load($sessionId);
        $values = $stored['execution_context'] ?? [];

        if (!is_array($values)) {
            throw new RuntimeException(
                'execution_context persistido é inválido.'
            );
        }

        return new PromptContext(
            $sessionId,
            $values
        );
    }

    /**
     * @param array<mixed> $context
     */
    private function buildInstructions(
        array $context,
        ?string $resolvedPrompt = null
    ): string {
        $runtime = $this->runtime?->current() ?? [];

        $structured = [
            'runtime' => $runtime,
            'agent_context' => $this->agentContext,
            'conversation' => [
                'intent' => $context['intent'] ?? null,
                'state' => $this->stateForAi(
                    $context['state'] ?? []
                ),
            ],
        ];

        $runtimeInstruction = '';

        if ($runtime !== []) {
            $runtimeInstruction = <<<TEXT

REGRAS DE DATA/HORA DO SISTEMA
- A data e hora em RUNTIME são a referência temporal oficial.
- Use RUNTIME para interpretar "hoje", "amanhã", dias da semana e datas sem ano.
- Não pergunte o ano se puder inferi-lo com segurança.
TEXT;
        }

        $instructionSegments = [];

        if ($resolvedPrompt !== null) {
            $resolvedPrompt = trim($resolvedPrompt);

            if ($resolvedPrompt !== '') {
                $instructionSegments[] = $resolvedPrompt;
            }
        }

        if ($this->instructions !== '') {
            $instructionSegments[] = $this->instructions;
        }

        $baseInstructions = implode(
            PHP_EOL . PHP_EOL,
            $instructionSegments
        );

        return trim($baseInstructions . $runtimeInstruction)
            . "\n\nCONTEXTO ESTRUTURADO ATUAL DO GUICHÊ:\n"
            . json_encode(
                $structured,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
    }

    /**
     * @param array<mixed> $result
     * @return array<mixed>
     */
    public function toolResultForAi(
        string $tool,
        array $result
    ): array {
        /*
         * Resultados de bloqueio pertencem à própria Safety Layer,
         * não à Tool de domínio. Mantemos um envelope mínimo fixo
         * para que a IA saiba que a execução foi recusada sem receber
         * detalhes internos do estado/policy.
         */
        if (($result['blocked'] ?? false) === true) {
            return array_intersect_key(
                $result,
                array_flip([
                    'success',
                    'tool',
                    'blocked',
                    'reason',
                    'error',
                ])
            );
        }

        $includePaths = $this->normalizeProjectionPaths(
            $this->aiToolResultIncludePaths[$tool]
                ?? []
        );

        if ($includePaths === []) {
            /*
             * Compatibilidade:
             * ausência de whitelist mantém o resultado completo
             * como base, exatamente como nas versões anteriores.
             */
            $compact = $result;
        } else {
            $compact = [];

            foreach ($includePaths as $path) {
                $this->copyArrayPath(
                    $result,
                    $compact,
                    explode('.', $path)
                );
            }
        }

        $excludePaths = $this->normalizeProjectionPaths(
            $this->aiToolResultExcludePaths[$tool]
                ?? []
        );

        foreach ($excludePaths as $path) {
            $this->unsetArrayPath(
                $compact,
                explode('.', $path)
            );
        }

        return $compact;
    }

    /**
     * @return array<mixed>
     */
    private function normalizeProjectionPaths(
        mixed $paths
    ): array {
        if (!is_array($paths)) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $path): string =>
                            trim((string)$path),
                        $paths
                    ),
                    static fn (string $path): bool =>
                        $path !== ''
                )
            )
        );
    }

    /**
     * @param array<mixed> $segments
     * @param array<mixed> $source
     * @param array<mixed> $target
     */
    private function copyArrayPath(
        array $source,
        array &$target,
        array $segments
    ): void {
        $segment = array_shift($segments);

        if (
            $segment === null
            || $segment === ''
            || !array_key_exists($segment, $source)
        ) {
            return;
        }

        if ($segments === []) {
            $target[$segment] = $source[$segment];
            return;
        }

        if (!is_array($source[$segment])) {
            return;
        }

        if (
            !isset($target[$segment])
            || !is_array($target[$segment])
        ) {
            $target[$segment] = [];
        }

        $this->copyArrayPath(
            $source[$segment],
            $target[$segment],
            $segments
        );

        if ($target[$segment] === []) {
            unset($target[$segment]);
        }
    }

    /**
     * @param array<mixed> $state
     * @return array<mixed>
     */
    public function stateForAi(array $state): array
    {
        $result = $state;

        foreach ($this->aiStateExcludePaths as $path) {
            $path = trim((string) $path);

            if ($path === '') {
                continue;
            }

            $this->unsetArrayPath(
                $result,
                explode('.', $path)
            );
        }

        return $result;
    }

    /**
     * @param array<mixed> $array
     * @param array<mixed> $segments
     */
    private function unsetArrayPath(
        array &$array,
        array $segments
    ): void {
        $segment = array_shift($segments);

        if (
            $segment === null
            || $segment === ''
            || !array_key_exists(
                $segment,
                $array
            )
        ) {
            return;
        }

        if ($segments === []) {
            unset($array[$segment]);
            return;
        }

        if (!is_array($array[$segment])) {
            return;
        }

        $this->unsetArrayPath(
            $array[$segment],
            $segments
        );

        /*
         * Se o último campo de um container foi removido,
         * não deixamos um objeto/array vazio residual na
         * projeção enviada à IA.
         */
        if ($array[$segment] === []) {
            unset($array[$segment]);
        }
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function messagesForAi(array $context): array
    {
        $messages = [];

        foreach ($context['messages'] ?? [] as $message) {
            if (
                !isset($message['role'], $message['content'])
                || !in_array($message['role'], ['user', 'assistant'], true)
            ) {
                continue;
            }

            $messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        if ($this->historyMessagesLimit <= 0) {
            return $messages;
        }

        return array_slice(
            $messages,
            -$this->historyMessagesLimit
        );
    }
    public function setToolPolicy(?ToolPolicy $toolPolicy): self
    {
        $this->toolPolicy = $toolPolicy;

        return $this;
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function toolDefinitionsForContext(array $context): array
    {
        $definitions = $this->tools->definitions();

        if ($this->toolPolicy === null) {
            return $definitions;
        }

        return $this->toolPolicy->filterDefinitions(
            $definitions,
            $context
        );
    }


    /**
     * @param array<mixed> $events
     */
    private function persistRuntimeEvents(
        string $sessionId,
        string $interactionId,
        array &$events
    ): void {
        $pendingEvents = $events;

        foreach ($pendingEvents as $legacyEvent) {
            if (
                !is_array($legacyEvent)
                || trim(
                    (string) (
                        $legacyEvent['type']
                        ?? ''
                    )
                ) === ''
            ) {
                continue;
            }

            $eventForSink = $legacyEvent;

            if (
                !isset(
                    $eventForSink['arguments_fingerprint']
                )
                && isset(
                    $eventForSink['fingerprint']
                )
                && is_scalar(
                    $eventForSink['fingerprint']
                )
            ) {
                $eventForSink['arguments_fingerprint'] =
                    (string) $eventForSink['fingerprint'];
            }

            $eventType = trim(
                (string) (
                    $eventForSink['type']
                    ?? ''
                )
            );

            if ($eventType === 'tool_call') {
                $eventForSink['type'] =
                    'tool_call_received';
            }

            $toolName = trim(
                (string) (
                    $eventForSink['tool']
                    ?? $eventForSink['name']
                    ?? ''
                )
            );

            if ($toolName !== '') {
                $eventForSink['tool'] =
                    $toolName;

                $eventForSink['operation'] ??=
                    $this->toolOperation(
                        $toolName
                    );
            }

            if (
                $eventType === 'tool_blocked'
                && !isset($eventForSink['reason'])
            ) {
                $eventForSink['reason'] =
                    $eventForSink['reason_code']
                    ?? $eventForSink['code']
                    ?? 'blocked';
            }


            if (
                isset($eventForSink['arguments'])
                && is_array(
                    $eventForSink['arguments']
                )
            ) {
                $toolName = trim(
                    (string) (
                        $eventForSink['name']
                        ?? ''
                    )
                );

                if ($toolName !== '') {
                    $eventForSink[
                        'arguments_fingerprint'
                    ] = $this->context
                        ->toolFingerprint(
                            $sessionId,
                            $toolName,
                            $eventForSink[
                                'arguments'
                            ]
                        );
                }
            }

            try {
                $this->eventSink->record(
                    RuntimeEventFactory::fromLegacy(
                        $sessionId,
                        $interactionId,
                        $eventForSink
                    )
                );
            } catch (Throwable $exception) {
                $events[] = [
                    'type' =>
                        'observability_sink_failed',
                    'exception_class' =>
                        $exception::class,
                ];

                break;
            }
        }
    }

    private function durationMs(
        int $startedAt
    ): float {
        return round(
            (hrtime(true) - $startedAt)
            / 1_000_000,
            3
        );
    }

    private function providerName(): string
    {
        return $this->ai === null
            ? 'none'
            : $this->ai::class;
    }

    private function toolOperation(
        string $tool
    ): string {
        $metadata = [];

        try {
            $registration =
                $this->tools
                    ->registrations()[$tool]
                ?? null;

            if ($registration !== null) {
                $metadata = $registration->metadata();
            }
        } catch (Throwable) {
            $metadata = [];
        }

        $operation = mb_strtolower(
            trim(
                (string) (
                    $metadata['operation']
                    ?? 'read'
                )
            ),
            'UTF-8'
        );

        return in_array(
            $operation,
            ['read', 'write'],
            true
        )
            ? $operation
            : 'unknown';
    }

    /**
     * @param array<mixed> $response
     * @return array<mixed>
     */
    private function usageSummary(
        array $response
    ): array {
        $usage = is_array(
            $response['usage']
            ?? null
        )
            ? $response['usage']
            : [];

        $input = (int) (
            $usage['input_tokens']
            ?? $usage['prompt_tokens']
            ?? 0
        );

        $output = (int) (
            $usage['output_tokens']
            ?? $usage['completion_tokens']
            ?? 0
        );

        $total = (int) (
            $usage['total_tokens']
            ?? ($input + $output)
        );

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
        ];
    }

    /**
     * @param array<mixed> $events
     * @param array<mixed> $messages
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    private function observedAiRespond(
        string $sessionId,
        string $interactionId,
        int $interactionStartedAt,
        array &$events,
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $events[] = [
            'type' => 'ai_request_started',
            'provider' => $this->providerName(),
        ];

        $startedAt = hrtime(true);

        $ai = $this->ai;

        if ($ai === null) {
            throw new RuntimeException(
                'Nenhum provider de IA foi configurado no Guichê.'
            );
        }

        try {
            $response = $ai->respond(
                $instructions,
                $messages,
                $tools
            );
        } catch (Throwable $exception) {
            $events[] = [
                'type' => 'ai_request_failed',
                'provider' => $this->providerName(),
                'duration_ms' =>
                    $this->durationMs(
                        $startedAt
                    ),
                'exception_class' =>
                    $exception::class,
                'error_code' =>
                    $exception->getCode(),
            ];

            $events[] = [
                'type' => 'interaction_failed',
                'duration_ms' =>
                    $this->durationMs(
                        $interactionStartedAt
                    ),
                'exception_class' =>
                    $exception::class,
            ];

            $failureEvents = $events;

            $this->persistRuntimeEvents(
                $sessionId,
                $interactionId,
                $failureEvents
            );

            throw $exception;
        }

        $usage =
            $this->usageSummary(
                $response
            );

        $events[] = [
            'type' => 'ai_response_received',
            'provider' => $this->providerName(),
            'model' => is_string(
                $response['model']
                ?? null
            )
                ? $response['model']
                : null,
            'duration_ms' =>
                $this->durationMs(
                    $startedAt
                ),
            'input_tokens' =>
                $usage['input_tokens'],
            'output_tokens' =>
                $usage['output_tokens'],
            'total_tokens' =>
                $usage['total_tokens'],
            'tool_call_count' =>
                is_array(
                    $response['tool_calls']
                    ?? null
                )
                    ? count(
                        $response['tool_calls']
                    )
                    : 0,
        ];

        return $response;
    }

    /**
     * @param array<mixed> $events
     * @param array<mixed> $toolOutputs
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    private function observedAiContinue(
        string $sessionId,
        string $interactionId,
        int $interactionStartedAt,
        array &$events,
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        $events[] = [
            'type' => 'ai_request_started',
            'provider' => $this->providerName(),
            'continuation' => true,
        ];

        $startedAt = hrtime(true);

        $ai = $this->ai;

        if ($ai === null) {
            throw new RuntimeException(
                'Nenhum provider de IA foi configurado no Guichê.'
            );
        }

        try {
            $response =
                $ai->continueWithToolOutputs(
                        $previousResponseId,
                        $instructions,
                        $toolOutputs,
                        $tools
                    );
        } catch (Throwable $exception) {
            $events[] = [
                'type' => 'ai_request_failed',
                'provider' => $this->providerName(),
                'continuation' => true,
                'duration_ms' =>
                    $this->durationMs(
                        $startedAt
                    ),
                'exception_class' =>
                    $exception::class,
                'error_code' =>
                    $exception->getCode(),
            ];

            $events[] = [
                'type' => 'interaction_failed',
                'duration_ms' =>
                    $this->durationMs(
                        $interactionStartedAt
                    ),
                'exception_class' =>
                    $exception::class,
            ];

            $failureEvents = $events;

            $this->persistRuntimeEvents(
                $sessionId,
                $interactionId,
                $failureEvents
            );

            throw $exception;
        }

        $usage =
            $this->usageSummary(
                $response
            );

        $events[] = [
            'type' => 'ai_response_received',
            'provider' => $this->providerName(),
            'continuation' => true,
            'model' => is_string(
                $response['model']
                ?? null
            )
                ? $response['model']
                : null,
            'duration_ms' =>
                $this->durationMs(
                    $startedAt
                ),
            'input_tokens' =>
                $usage['input_tokens'],
            'output_tokens' =>
                $usage['output_tokens'],
            'total_tokens' =>
                $usage['total_tokens'],
            'tool_call_count' =>
                is_array(
                    $response['tool_calls']
                    ?? null
                )
                    ? count(
                        $response['tool_calls']
                    )
                    : 0,
        ];

        return $response;
    }

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $events
     * @return array<mixed>
     */
    private function observedToolExecution(
        string $sessionId,
        string $interactionId,
        array &$events,
        string $callId,
        string $name,
        array $arguments,
        callable $execution
    ): array {
        $operation =
            $this->toolOperation($name);

        $fingerprint =
            $this->context
                ->toolFingerprint(
                    $sessionId,
                    $name,
                    $arguments
                );

        $events[] = [
            'type' =>
                'tool_execution_started',
            'call_id' => $callId,
            'name' => $name,
            'tool' => $name,
            'operation' => $operation,
            'arguments_fingerprint' =>
                $fingerprint,
        ];

        $startedAt = hrtime(true);

        try {
            $result = $execution();
        } catch (Throwable $exception) {
            $events[] = [
                'type' =>
                    'tool_execution_failed',
                'call_id' => $callId,
                'name' => $name,
                'tool' => $name,
                'operation' => $operation,
                'arguments_fingerprint' =>
                    $fingerprint,
                'duration_ms' =>
                    $this->durationMs(
                        $startedAt
                    ),
                'success' => false,
                'exception_class' =>
                    $exception::class,
                'error_code' =>
                    $exception->getCode(),
            ];

            throw $exception;
        }

        $events[] = [
            'type' =>
                'tool_execution_completed',
            'call_id' => $callId,
            'name' => $name,
            'tool' => $name,
            'operation' => $operation,
            'arguments_fingerprint' =>
                $fingerprint,
            'duration_ms' =>
                $this->durationMs(
                    $startedAt
                ),
            'success' =>
                ($result['success'] ?? true)
                === true,
        ];

        return $result;
    }

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $events
     * @param array<mixed> $result
     * @return array<mixed>
     */
    private function observedAddToolExecution(
        string $interactionId,
        array &$events,
        string $callId,
        string $sessionId,
        string $tool,
        array $arguments,
        array $result
    ): array {
        try {
            return $this->context
                ->addToolExecution(
                    $sessionId,
                    $tool,
                    $arguments,
                    $result
                );
        } catch (Throwable $exception) {
            $operation =
                $this->toolOperation($tool);

            $type =
                $operation === 'write'
                && (
                    $result['success']
                    ?? false
                ) === true
                    ? 'persistence_after_side_effect_failed'
                    : 'context_save_failed';

            $events[] = [
                'type' => $type,
                'call_id' => $callId,
                'name' => $tool,
                'tool' => $tool,
                'operation' => $operation,
                'arguments_fingerprint' =>
                    $this->context
                        ->toolFingerprint(
                            $sessionId,
                            $tool,
                            $arguments
                        ),
                'exception_class' =>
                    $exception::class,
                'error_code' =>
                    $exception->getCode(),
            ];

            $failureEvent = [
                $events[
                    array_key_last($events)
                ],
            ];

            $this->persistRuntimeEvents(
                $sessionId,
                $interactionId,
                $failureEvent
            );

            throw $exception;
        }
    }

    /**
     * @param array<mixed> $events
     * @return array<mixed>
     */
    private function observedContextLoad(
        string $observationSessionId,
        string $interactionId,
        int $interactionStartedAt,
        array &$events,
        string $sessionId
    ): array {
        try {
            return $this->context
                ->load($sessionId);
        } catch (Throwable $exception) {
            $events[] = [
                'type' => 'context_load_failed',
                'exception_class' =>
                    $exception::class,
                'error_code' =>
                    $exception->getCode(),
            ];

            $events[] = [
                'type' => 'interaction_failed',
                'duration_ms' =>
                    $this->durationMs(
                        $interactionStartedAt
                    ),
                'exception_class' =>
                    $exception::class,
            ];

            $failureEvents = $events;

            $this->persistRuntimeEvents(
                $observationSessionId,
                $interactionId,
                $failureEvents
            );

            throw $exception;
        }
    }

    /**
     * @param array<mixed> $events
     * @param array<mixed> $patch
     * @return array<mixed>
     */
    private function observedContextApplyPatch(
        string $observationSessionId,
        string $interactionId,
        int $interactionStartedAt,
        array &$events,
        string $sessionId,
        array $patch
    ): array {
        try {
            return $this->context
                ->applyPatch(
                    $sessionId,
                    $patch
                );
        } catch (Throwable $exception) {
            $this->contextWriteFailed(
                $observationSessionId,
                $interactionId,
                $interactionStartedAt,
                $events,
                $exception
            );

            throw $exception;
        }
    }

    /**
     * @param array<mixed> $events
     * @return array<mixed>
     */
    private function observedContextAddMessage(
        string $observationSessionId,
        string $interactionId,
        int $interactionStartedAt,
        array &$events,
        string $sessionId,
        string $role,
        string $content
    ): array {
        try {
            return $this->context
                ->addMessage(
                    $sessionId,
                    $role,
                    $content
                );
        } catch (Throwable $exception) {
            $this->contextWriteFailed(
                $observationSessionId,
                $interactionId,
                $interactionStartedAt,
                $events,
                $exception
            );

            throw $exception;
        }
    }

    /**
     * @param array<mixed> $events
     * @param array<mixed> $usage
     * @return array<mixed>
     */
    private function observedRecordInteractionUsage(
        string $observationSessionId,
        string $interactionId,
        int $interactionStartedAt,
        array &$events,
        string $sessionId,
        array $usage
    ): array {
        try {
            return $this->context
                ->recordInteractionUsage(
                    $sessionId,
                    $usage
                );
        } catch (Throwable $exception) {
            $this->contextWriteFailed(
                $observationSessionId,
                $interactionId,
                $interactionStartedAt,
                $events,
                $exception
            );

            throw $exception;
        }
    }

    /**
     * @param array<mixed> $events
     */
    private function contextWriteFailed(
        string $sessionId,
        string $interactionId,
        int $interactionStartedAt,
        array &$events,
        Throwable $exception
    ): void {
        $events[] = [
            'type' => 'context_save_failed',
            'exception_class' =>
                $exception::class,
            'error_code' =>
                $exception->getCode(),
        ];

        $events[] = [
            'type' => 'interaction_failed',
            'duration_ms' =>
                $this->durationMs(
                    $interactionStartedAt
                ),
            'exception_class' =>
                $exception::class,
        ];

        $failureEvents = $events;

        $this->persistRuntimeEvents(
            $sessionId,
            $interactionId,
            $failureEvents
        );
    }
}
