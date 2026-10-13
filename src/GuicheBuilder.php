<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Plugin\PresentedTool;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Compositor oficial do runtime Guichê.
 *
 * Responsabilidades da v0.3:
 * - receber Plugins e suas capacidades;
 * - resolver conflitos;
 * - compor Tools, Presenters, StateManager e configuração;
 * - produzir uma instância de Guiche sem exigir montagem manual espalhada.
 *
 * Não é responsabilidade desta versão:
 * - hard execution gate;
 * - autorização de Tool em tempo de execução;
 * - enforcement de segurança da v0.4.
 */
final class GuicheBuilder
{
    private ToolRegistry $tools;
    private PresenterRegistry $presenters;

    private ?ContextStore $context = null;
    private ?AiProviderInterface $ai = null;
    private ?RuntimeContext $runtime = null;
    private ?StateManagerInterface $stateManager = null;
    private ?ToolPolicy $toolPolicy = null;

    private string $instructions = '';

    /** @var array<string, PluginInterface> */
    private array $plugins = [];

    /** @var array<string, array> */
    private array $policies = [];

    /** @var array<string, string> */
    private array $instructionFragments = [];

    /** @var array<string, array> */
    private array $completionGuards = [];

    /** @var array<string, array> */
    private array $resultProjections = [];

    private array $agentContext = [];
    private array $reusableTools = [];
    private array $runtimeCompletionGuards = [];
    private int $historyMessagesLimit = 8;
    private array $aiStateExcludePaths = [];
    private array $aiToolResultExcludePaths = [];
    private array $aiToolResultIncludePaths = [];
    private int $maxToolRounds = 5;

    public function __construct(
        ?ToolRegistry $tools = null,
        ?PresenterRegistry $presenters = null
    ) {
        $this->tools = $tools ?? new ToolRegistry();
        $this->presenters = $presenters ?? new PresenterRegistry();
    }

    public function registerPlugin(PluginInterface $plugin): self
    {
        $id = $plugin::class;

        if (isset($this->plugins[$id])) {
            throw new LogicException(
                "Plugin já registrado: {$id}"
            );
        }

        /*
         * Registro atômico:
         * uma falha não pode deixar capacidades parciais.
         */
        $before = [
            'tools' => clone $this->tools,
            'presenters' => clone $this->presenters,
            'state_manager' => $this->stateManager,
            'tool_policy' => $this->toolPolicy,
            'policies' => $this->policies,
            'instruction_fragments' => $this->instructionFragments,
            'completion_guards' => $this->completionGuards,
            'result_projections' => $this->resultProjections,
        ];

        try {
            $plugin->register($this);
            $this->plugins[$id] = $plugin;
        } catch (Throwable $exception) {
            $this->tools = $before['tools'];
            $this->presenters = $before['presenters'];
            $this->stateManager = $before['state_manager'];
            $this->toolPolicy = $before['tool_policy'];
            $this->policies = $before['policies'];
            $this->instructionFragments = $before['instruction_fragments'];
            $this->completionGuards = $before['completion_guards'];
            $this->resultProjections = $before['result_projections'];

            throw $exception;
        }

        return $this;
    }

    public function registerTool(
        ToolInterface|ToolRegistration $tool
    ): self {
        $this->tools->register($tool);

        return $this;
    }

    public function registerPresenter(
        string $toolName,
        ResultPresenterInterface $presenter
    ): self {
        $this->presenters->register(
            $toolName,
            $presenter
        );

        return $this;
    }

    public function useStateManager(
        StateManagerInterface $stateManager
    ): self {
        if ($this->stateManager !== null) {
            throw new LogicException(
                'Conflito de StateManager: já existe um StateManager registrado.'
            );
        }

        $this->stateManager = $stateManager;

        return $this;
    }

    /**
     * Policy declarativa do Plugin.
     *
     * O registro fica disponível para inspeção/documentação.
     * O hard execution gate pertence à v0.4.
     */
    public function registerPolicy(
        string $id,
        array $definition
    ): self {
        $id = $this->normalizeId($id, 'Policy');

        if (isset($this->policies[$id])) {
            throw new LogicException(
                "Policy já registrada: {$id}"
            );
        }

        $this->policies[$id] = $definition;

        return $this;
    }

    /**
     * Liga uma ToolPolicy já existente ao runtime.
     *
     * Isto preserva o comportamento de filtragem da v0.2;
     * não cria o hard execution gate da v0.4.
     */
    public function useToolPolicy(ToolPolicy $toolPolicy): self
    {
        if ($this->toolPolicy !== null) {
            throw new LogicException(
                'Conflito de ToolPolicy: já existe uma ToolPolicy configurada.'
            );
        }

        $this->toolPolicy = $toolPolicy;

        return $this;
    }

    public function addInstructionFragment(
        string $id,
        string $fragment
    ): self {
        $id = $this->normalizeId(
            $id,
            'Instruction Fragment'
        );

        $fragment = trim($fragment);

        if ($fragment === '') {
            throw new InvalidArgumentException(
                'Instruction Fragment não pode ser vazio.'
            );
        }

        if (isset($this->instructionFragments[$id])) {
            throw new LogicException(
                "Instruction Fragment já registrado: {$id}"
            );
        }

        $this->instructionFragments[$id] = $fragment;

        return $this;
    }

    public function registerCompletionGuard(
        string $id,
        array $definition
    ): self {
        $id = $this->normalizeId(
            $id,
            'Completion Guard'
        );

        if (isset($this->completionGuards[$id])) {
            throw new LogicException(
                "Completion Guard já registrado: {$id}"
            );
        }

        $this->completionGuards[$id] = $definition;

        return $this;
    }

    public function registerResultProjection(
        string $toolName,
        array $definition
    ): self {
        $toolName = $this->normalizeId(
            $toolName,
            'Result Projection'
        );

        if (isset($this->resultProjections[$toolName])) {
            throw new LogicException(
                "Result Projection já registrada para: {$toolName}"
            );
        }

        $this->resultProjections[$toolName] = $definition;

        return $this;
    }

    public function withContextStore(ContextStore $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function withAiProvider(
        ?AiProviderInterface $ai
    ): self {
        $this->ai = $ai;

        return $this;
    }

    public function withInstructions(string $instructions): self
    {
        $this->instructions = trim($instructions);

        return $this;
    }

    public function withAgentContext(array $agentContext): self
    {
        $this->agentContext = $agentContext;

        return $this;
    }

    public function withRuntime(
        ?RuntimeContext $runtime
    ): self {
        $this->runtime = $runtime;

        return $this;
    }

    public function withReusableTools(array $reusableTools): self
    {
        $this->reusableTools = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $name): string =>
                            trim((string)$name),
                        $reusableTools
                    ),
                    static fn (string $name): bool =>
                        $name !== ''
                )
            )
        );

        return $this;
    }

    /**
     * Guards já existentes no bootstrap/aplicação.
     *
     * Os guards registrados pelos Plugins são combinados no build().
     */
    public function withCompletionGuards(
        array $completionGuards
    ): self {
        $this->runtimeCompletionGuards = array_values(
            $completionGuards
        );

        return $this;
    }

    public function withHistoryMessagesLimit(
        int $limit
    ): self {
        if ($limit < 0) {
            throw new InvalidArgumentException(
                'historyMessagesLimit não pode ser negativo.'
            );
        }

        $this->historyMessagesLimit = $limit;

        return $this;
    }

    public function withAiStateExcludePaths(
        array $paths
    ): self {
        $this->aiStateExcludePaths = $paths;

        return $this;
    }

    public function withAiToolResultExcludePaths(
        array $paths
    ): self {
        $this->aiToolResultExcludePaths = $paths;

        return $this;
    }

    public function withAiToolResultIncludePaths(
        array $paths
    ): self {
        $this->aiToolResultIncludePaths = $paths;

        return $this;
    }

    public function withMaxToolRounds(int $rounds): self
    {
        if ($rounds < 1) {
            throw new InvalidArgumentException(
                'maxToolRounds precisa ser maior ou igual a 1.'
            );
        }

        $this->maxToolRounds = $rounds;

        return $this;
    }

    /**
     * Constrói um snapshot do runtime.
     *
     * Alterações posteriores no Builder não modificam a instância
     * de Guiche já construída.
     */
    public function build(): Guiche
    {
        if ($this->context === null) {
            throw new LogicException(
                'ContextStore é obrigatório para construir o Guichê.'
            );
        }

        $guiche = new Guiche(
            tools: $this->runtimeToolRegistry(),
            context: $this->context,
            ai: $this->ai,
            instructions: $this->compiledInstructions(),
            agentContext: $this->agentContext,
            runtime: $this->runtime,
            stateManager: $this->stateManager,
            reusableTools: $this->reusableTools,
            completionGuards: $this->compiledCompletionGuards(),
            historyMessagesLimit: $this->historyMessagesLimit,
            aiStateExcludePaths: $this->aiStateExcludePaths,
            aiToolResultExcludePaths:
                $this->compiledToolResultExcludePaths(),
            maxToolRounds: $this->maxToolRounds,
            aiToolResultIncludePaths:
                $this->compiledToolResultIncludePaths()
        );

        if ($this->toolPolicy !== null) {
            $guiche->setToolPolicy(
                $this->toolPolicy
            );
        }

        return $guiche;
    }

    public function toolRegistry(): ToolRegistry
    {
        return $this->tools;
    }

    public function presenterRegistry(): PresenterRegistry
    {
        return $this->presenters;
    }

    public function stateManager(): ?StateManagerInterface
    {
        return $this->stateManager;
    }

    public function toolPolicy(): ?ToolPolicy
    {
        return $this->toolPolicy;
    }

    /**
     * @return array<string, PluginInterface>
     */
    public function plugins(): array
    {
        return $this->plugins;
    }

    /**
     * @return array<string, array>
     */
    public function policies(): array
    {
        return $this->policies;
    }

    /**
     * @return array<string, string>
     */
    public function instructionFragments(): array
    {
        return $this->instructionFragments;
    }

    /**
     * @return array<string, array>
     */
    public function completionGuards(): array
    {
        return $this->completionGuards;
    }

    /**
     * @return array<string, array>
     */
    public function resultProjections(): array
    {
        return $this->resultProjections;
    }

    private function runtimeToolRegistry(): ToolRegistry
    {
        $runtime = new ToolRegistry();

        foreach ($this->tools->registrations() as $registration) {
            $tool = $registration->tool();
            $name = $registration->name();

            if ($this->presenters->has($name)) {
                $tool = new PresentedTool(
                    $tool,
                    $this->presenters->get($name)
                );
            }

            $runtime->register(
                new ToolRegistration(
                    $tool,
                    $registration->metadata()
                )
            );
        }

        return $runtime;
    }

    private function compiledInstructions(): string
    {
        $segments = [];

        if ($this->instructions !== '') {
            $segments[] = $this->instructions;
        }

        foreach ($this->instructionFragments as $fragment) {
            $fragment = trim($fragment);

            if ($fragment !== '') {
                $segments[] = $fragment;
            }
        }

        return implode(
            PHP_EOL . PHP_EOL,
            $segments
        );
    }

    private function compiledCompletionGuards(): array
    {
        return array_values(
            array_merge(
                $this->runtimeCompletionGuards,
                array_values($this->completionGuards)
            )
        );
    }

    private function compiledToolResultIncludePaths(): array
    {
        $paths = $this->aiToolResultIncludePaths;

        foreach (
            $this->resultProjections
            as $toolName => $definition
        ) {
            $include = $definition['include_paths'] ?? [];

            if (!is_array($include)) {
                continue;
            }

            $existing = $paths[$toolName] ?? [];

            if (!is_array($existing)) {
                $existing = [];
            }

            $paths[$toolName] = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn (mixed $path): string =>
                                trim((string)$path),
                            array_merge(
                                $existing,
                                $include
                            )
                        ),
                        static fn (string $path): bool =>
                            $path !== ''
                    )
                )
            );
        }

        return $paths;
    }

    private function compiledToolResultExcludePaths(): array
    {
        $paths = $this->aiToolResultExcludePaths;

        foreach (
            $this->resultProjections
            as $toolName => $definition
        ) {
            $exclude = $definition['exclude_paths'] ?? [];

            if (!is_array($exclude)) {
                continue;
            }

            $existing = $paths[$toolName] ?? [];

            if (!is_array($existing)) {
                $existing = [];
            }

            $paths[$toolName] = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn (mixed $path): string =>
                                trim((string)$path),
                            array_merge(
                                $existing,
                                $exclude
                            )
                        ),
                        static fn (string $path): bool =>
                            $path !== ''
                    )
                )
            );
        }

        return $paths;
    }

    private function normalizeId(
        string $id,
        string $capability
    ): string {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException(
                "{$capability} precisa possuir identificador."
            );
        }

        return $id;
    }
}
