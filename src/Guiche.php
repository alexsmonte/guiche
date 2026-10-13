<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
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
        private readonly int $maxToolRounds = 5
    ) {
    }

    public function tools(): array
    {
        return $this->tools->definitions();
    }

    public function executeTool(string $name, array $arguments = []): array
    {
        return $this->tools->execute($name, $arguments);
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
                $result = null;

                if (in_array($name, $this->reusableTools, true)) {
                    $result = $this->context->findSuccessfulToolResult($sessionId, $name, $arguments);

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

                if ($result === null) {
                    try {
                        $result = $this->tools->execute($name, $arguments);
                    } catch (Throwable $exception) {
                        $result = [
                            'success' => false,
                            'tool' => $name,
                            'error' => $exception->getMessage(),
                        ];
                    }

                    $this->context->addToolExecution($sessionId, $name, $arguments, $result);
                }

                if ($this->stateManager !== null) {
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
        $compact = $result;

        $paths = $this->aiToolResultExcludePaths[$tool]
            ?? [];

        foreach ($paths as $path) {
            $path = trim(
                (string) $path
            );

            if ($path === '') {
                continue;
            }

            $this->unsetArrayPath(
                $compact,
                explode('.', $path)
            );
        }

        return $compact;
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
