<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\Safety\ToolExecutionGate;
use Asmpkg\Guiche\Safety\ToolSafetyMetadata;
use RuntimeException;
use Throwable;

final class Guiche
{
    private ?ToolPolicy $toolPolicy = null;
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ContextStore $context,
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
        private readonly array $aiToolResultIncludePaths = []
    ) {
    }

    public function tools(): array
    {
        return $this->tools->definitions();
    }

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

        $result = $this->tools->execute(
            $name,
            $arguments
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

    public function context(string $sessionId): array
    {
        return $this->context->load($sessionId);
    }

    public function reset(string $sessionId): void
    {
        $this->context->delete($sessionId);
    }

    public function handle(string $sessionId, string $message): array
    {
        if ($this->ai === null) {
            throw new RuntimeException('Nenhum provider de IA foi configurado no Guichê.');
        }

        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException('A mensagem não pode estar vazia.');
        }

        $contextBefore = $this->context->load($sessionId);

        if ($this->stateManager !== null) {
            $patch = $this->stateManager->onUserMessage($contextBefore, $message);
            $this->context->applyPatch($sessionId, $patch);
        }

        $this->context->addMessage($sessionId, 'user', $message);

        $context = $this->context->load($sessionId);
        $events = [];

        $interactionUsage = [
            'interaction_id' => bin2hex(random_bytes(8)),
            'user_message' => $message,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'api_calls' => [],
        ];

        $response = $this->ai->respond(
            $this->buildInstructions($context),
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

                /*
                 * Gate 1:
                 * autoriza a chamada antes de qualquer cache/reuso.
                 * Uma Tool que já ficou proibida não pode recuperar
                 * um resultado antigo como atalho de policy.
                 */
                try {
                    $currentContext = $this->context->load($sessionId);

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
                        $currentContext = $this->context->load($sessionId);

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
                            $result = $this->tools->execute(
                                $name,
                                $arguments
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
                        $this->context->addToolExecution(
                            $sessionId,
                            $name,
                            $arguments,
                            $result
                        );
                    }
                }

                if (!$blocked && $this->stateManager !== null) {
                    $currentContext = $this->context->load($sessionId);
                    $patch = $this->stateManager->onToolResult(
                        $currentContext,
                        $name,
                        $arguments,
                        $result
                    );

                    $this->context->applyPatch($sessionId, $patch);
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

            $context = $this->context->load($sessionId);

            $response = $this->ai->continueWithToolOutputs(
                $response['id'],
                $this->buildInstructions($context),
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

        $finalContext = $this->context->load(
            $sessionId
        );

        $text = $this->applyCompletionGuards(
            $finalContext,
            $events,
            $text
        );

        $this->context->addMessage(
            $sessionId,
            'assistant',
            $text
        );

        $interactionUsage['assistant_message'] = $text;
        $this->context->recordInteractionUsage($sessionId, $interactionUsage);

        return [
            'message' => $text,
            'events' => $events,
            'usage' => $interactionUsage,
            'runtime' => $this->runtime?->current() ?? [],
            'context' => $this->context->load($sessionId),
        ];
    }

    private function executionGate(): ToolExecutionGate
    {
        return new ToolExecutionGate(
            $this->tools,
            $this->toolPolicy
        );
    }

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

    private function buildInstructions(array $context): string
    {
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

        return trim($this->instructions . $runtimeInstruction)
            . "\n\nCONTEXTO ESTRUTURADO ATUAL DO GUICHÊ:\n"
            . json_encode(
                $structured,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
    }

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

}
