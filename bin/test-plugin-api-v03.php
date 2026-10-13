<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkV03F03(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class NeutralToolV03F03 implements ToolInterface
{
    public function __construct(
        private readonly string $toolName = 'NeutralPing'
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Tool neutra para validar Plugin API.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
            ],
            'required' => ['message'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        return [
            'success' => true,
            'tool' => $this->name(),
            'data' => [
                'message' => (string)($arguments['message'] ?? ''),
            ],
        ];
    }
}

final class NeutralPresenterV03F03 implements ResultPresenterInterface
{
    public function present(array $result): ?string
    {
        $message = trim(
            (string)($result['data']['message'] ?? '')
        );

        return $message === ''
            ? null
            : 'PING: ' . $message;
    }
}

final class NeutralStateManagerV03F03 implements StateManagerInterface
{
    public function onUserMessage(
        array $context,
        string $message
    ): array {
        return [
            'state' => [
                'last_message' => $message,
            ],
        ];
    }

    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array {
        return [
            'state' => [
                'last_tool' => $tool,
            ],
        ];
    }
}

final class NeutralPluginV03F03 implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder
            ->registerTool(
                new ToolRegistration(
                    new NeutralToolV03F03(),
                    [
                        'plugin' => self::class,
                        'kind' => 'neutral',
                    ]
                )
            )
            ->registerPresenter(
                'NeutralPing',
                new NeutralPresenterV03F03()
            )
            ->useStateManager(
                new NeutralStateManagerV03F03()
            )
            ->registerPolicy(
                'neutral.tool-policy',
                [
                    'stage_path' => 'state.stage',
                    'mode' => 'declarative',
                ]
            )
            ->addInstructionFragment(
                'neutral.instructions',
                'Responda de maneira neutra.'
            )
            ->registerCompletionGuard(
                'neutral.guard',
                [
                    'when' => ['state.ready' => true],
                    'required_tool' => 'NeutralPing',
                ]
            )
            ->registerResultProjection(
                'NeutralPing',
                [
                    'exclude_paths' => ['meta.internal'],
                ]
            );
    }
}

final class SecondNeutralPluginV03F03 implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder
            ->registerTool(
                new NeutralToolV03F03('SecondNeutral')
            )
            ->addInstructionFragment(
                'second.instructions',
                'Segundo fragmento neutro.'
            );
    }
}

final class ConflictingToolPluginV03F03 implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder
            ->registerTool(
                new NeutralToolV03F03('ConflictTemporary')
            )
            ->registerTool(
                new NeutralToolV03F03('NeutralPing')
            );
    }
}

final class ConflictingStatePluginV03F03 implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder->useStateManager(
            new NeutralStateManagerV03F03()
        );
    }
}

echo PHP_EOL . "1. PLUGIN INTERFACE EM FUNCIONAMENTO" . PHP_EOL;

$builder = new GuicheBuilder();
$plugin = new NeutralPluginV03F03();

$returned = $builder->registerPlugin($plugin);

checkV03F03(
    $returned === $builder,
    'registerPlugin() é fluente'
);

checkV03F03(
    isset($builder->plugins()[NeutralPluginV03F03::class]),
    'plugin registrado fica identificável pela classe'
);

checkV03F03(
    $builder->toolRegistry()->has('NeutralPing'),
    'plugin registra Tool sem editar ToolRegistry'
);

checkV03F03(
    $builder->presenterRegistry()->has('NeutralPing'),
    'plugin registra Presenter'
);

checkV03F03(
    $builder->stateManager() instanceof NeutralStateManagerV03F03,
    'plugin registra StateManager pelo contrato genérico'
);

checkV03F03(
    ($builder->toolRegistry()->metadata('NeutralPing')['kind'] ?? null)
        === 'neutral',
    'ToolRegistration conserva metadados do plugin'
);

echo PHP_EOL . "2. CAPACIDADES DECLARATIVAS" . PHP_EOL;

checkV03F03(
    ($builder->policies()['neutral.tool-policy']['mode'] ?? null)
        === 'declarative',
    'plugin registra Policy declarativa'
);

checkV03F03(
    ($builder->instructionFragments()['neutral.instructions'] ?? null)
        === 'Responda de maneira neutra.',
    'plugin registra Instruction Fragment'
);

checkV03F03(
    ($builder->completionGuards()['neutral.guard']['required_tool'] ?? null)
        === 'NeutralPing',
    'plugin registra Completion Guard'
);

checkV03F03(
    (
        $builder->resultProjections()['NeutralPing']['exclude_paths'][0]
        ?? null
    ) === 'meta.internal',
    'plugin registra Result Projection'
);

checkV03F03(
    method_exists($builder, 'build'),
    'GuicheBuilder evoluiu na Fase 04 e agora expõe build()'
);

echo PHP_EOL . "3. MÚLTIPLOS PLUGINS" . PHP_EOL;

$builder->registerPlugin(
    new SecondNeutralPluginV03F03()
);

checkV03F03(
    count($builder->plugins()) === 2,
    'Builder aceita múltiplos plugins distintos'
);

checkV03F03(
    $builder->toolRegistry()->has('SecondNeutral'),
    'segundo plugin adiciona nova Tool'
);

checkV03F03(
    count($builder->instructionFragments()) === 2,
    'fragmentos de instrução preservam ordem de registro'
);

echo PHP_EOL . "4. CONFLITOS E ATOMICIDADE" . PHP_EOL;

$duplicatePluginRejected = false;

try {
    $builder->registerPlugin(
        new NeutralPluginV03F03()
    );
} catch (\LogicException $exception) {
    $duplicatePluginRejected = true;
}

checkV03F03(
    $duplicatePluginRejected,
    'mesma classe de Plugin não pode ser registrada duas vezes'
);

$beforeNames = $builder->toolRegistry()->names();

$toolConflictRejected = false;

try {
    $builder->registerPlugin(
        new ConflictingToolPluginV03F03()
    );
} catch (\LogicException $exception) {
    $toolConflictRejected = str_contains(
        $exception->getMessage(),
        'NeutralPing'
    );
}

checkV03F03(
    $toolConflictRejected,
    'conflito de Tool entre plugins é explícito'
);

checkV03F03(
    $builder->toolRegistry()->names() === $beforeNames,
    'falha de Plugin não deixa registro parcial de Tool'
);

checkV03F03(
    !$builder->toolRegistry()->has('ConflictTemporary'),
    'rollback atômico remove capacidade parcial do Plugin'
);

$stateConflictRejected = false;

try {
    $builder->registerPlugin(
        new ConflictingStatePluginV03F03()
    );
} catch (\LogicException $exception) {
    $stateConflictRejected = str_contains(
        $exception->getMessage(),
        'StateManager'
    );
}

checkV03F03(
    $stateConflictRejected,
    'conflito de StateManager entre plugins é explícito'
);

checkV03F03(
    count($builder->plugins()) === 2,
    'Plugin que falha não entra na lista de plugins registrados'
);

echo PHP_EOL . "5. VALIDAÇÃO DE IDENTIFICADORES" . PHP_EOL;

$blankPolicyRejected = false;

try {
    (new GuicheBuilder())->registerPolicy(
        '   ',
        []
    );
} catch (\InvalidArgumentException $exception) {
    $blankPolicyRejected = true;
}

checkV03F03(
    $blankPolicyRejected,
    'Policy exige identificador'
);

$blankInstructionRejected = false;

try {
    (new GuicheBuilder())->addInstructionFragment(
        'test',
        '   '
    );
} catch (\InvalidArgumentException $exception) {
    $blankInstructionRejected = true;
}

checkV03F03(
    $blankInstructionRejected,
    'Instruction Fragment vazio é rejeitado'
);

echo PHP_EOL . "6. FRONTEIRA CORE / PLUGIN" . PHP_EOL;

$guicheSource = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);

checkV03F03(
    is_string($guicheSource)
    && !str_contains($guicheSource, 'PluginInterface')
    && !str_contains($guicheSource, 'GuicheBuilder'),
    'Guiche runtime continua desacoplado da Plugin API nesta fase'
);

$builderSource = file_get_contents(
    dirname(__DIR__) . '/src/GuicheBuilder.php'
);

$clinicalMarkers = [
    'paciente',
    'profissional',
    'convênio',
    'convenio',
    'agendamento',
    'tratamento',
    'horário',
    'horario',
];

$neutral = true;

foreach ($clinicalMarkers as $marker) {
    if (
        is_string($builderSource)
        && str_contains(
            mb_strtolower($builderSource),
            mb_strtolower($marker)
        )
    ) {
        $neutral = false;
        break;
    }
}

checkV03F03(
    $neutral,
    'GuicheBuilder não contém semântica clínica'
);

echo PHP_EOL;
echo "OK: GUICHÊ v0.3.0 / FASE 03 validada." . PHP_EOL;
