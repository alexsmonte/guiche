<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Example\GuicheNeutral\NeutralPingTool;
use Example\GuicheNeutral\NeutralPlugin;
use Example\GuicheNeutral\NeutralPresenter;
use Example\GuicheNeutral\NeutralStateManager;

require dirname(__DIR__) . '/vendor/autoload.php';

$pluginRoot = dirname(__DIR__)
    . '/examples/neutral-plugin/src';

require_once $pluginRoot . '/NeutralPingTool.php';
require_once $pluginRoot . '/NeutralStateManager.php';
require_once $pluginRoot . '/NeutralPresenter.php';
require_once $pluginRoot . '/NeutralPlugin.php';

function checkNeutralV03(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException(
            'FALHOU: ' . $message
        );
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class NeutralFakeAiV03
    implements AiProviderInterface
{
    public array $respondInstructions = [];
    public array $respondTools = [];
    public array $continueInstructions = [];
    public array $continueOutputs = [];
    public array $continueTools = [];

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->respondInstructions[] = $instructions;
        $this->respondTools[] = $tools;

        return [
            'id' => 'neutral-response-1',
            'text' => '',
            'tool_calls' => [
                [
                    'call_id' => 'neutral-call-1',
                    'name' => 'NeutralPing',
                    'arguments' => [
                        'message' => 'olá externo',
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
                'total_tokens' => 2,
            ],
        ];
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        $this->continueInstructions[] = $instructions;
        $this->continueOutputs[] = $toolOutputs;
        $this->continueTools[] = $tools;

        return [
            'id' => 'neutral-response-2',
            'text' => 'Fluxo neutro concluído.',
            'tool_calls' => [],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
                'total_tokens' => 2,
            ],
        ];
    }
}

echo PHP_EOL;
echo "1. PLUGIN REALMENTE EXTERNO AO CORE" . PHP_EOL;

$root = realpath(dirname(__DIR__));
$src = realpath(dirname(__DIR__) . '/src');
$pluginPath = realpath(
    dirname(__DIR__) . '/examples/neutral-plugin'
);

checkNeutralV03(
    is_string($root)
    && is_string($src)
    && is_string($pluginPath)
    && !str_starts_with(
        $pluginPath,
        $src . DIRECTORY_SEPARATOR
    ),
    'plugin neutro está fora de src/'
);

checkNeutralV03(
    new NeutralPlugin() instanceof PluginInterface,
    'NeutralPlugin implementa PluginInterface'
);

checkNeutralV03(
    new NeutralPingTool() instanceof ToolInterface,
    'NeutralPingTool implementa ToolInterface'
);

checkNeutralV03(
    new NeutralStateManager()
        instanceof StateManagerInterface,
    'NeutralStateManager implementa StateManagerInterface'
);

checkNeutralV03(
    new NeutralPresenter()
        instanceof ResultPresenterInterface,
    'NeutralPresenter implementa ResultPresenterInterface'
);

echo PHP_EOL;
echo "2. REGISTRO SEM ALTERAR O CORE" . PHP_EOL;

$ai = new NeutralFakeAiV03();
$temp = sys_get_temp_dir()
    . '/guiche-neutral-v03-'
    . bin2hex(random_bytes(5));

$builder = (new GuicheBuilder())
    ->withContextStore(
        new ContextStore(
            $temp,
            'America/Manaus'
        )
    )
    ->withAiProvider($ai)
    ->registerPlugin(
        new NeutralPlugin()
    );

checkNeutralV03(
    $builder->toolRegistry()->names()
        === ['NeutralPing'],
    'plugin externo registra sua Tool'
);

checkNeutralV03(
    $builder->presenterRegistry()->has(
        'NeutralPing'
    ),
    'plugin externo registra seu Presenter'
);

checkNeutralV03(
    $builder->stateManager()
        instanceof NeutralStateManager,
    'plugin externo fornece seu StateManager'
);

checkNeutralV03(
    isset(
        $builder->instructionFragments()[
            'neutral.instructions'
        ]
    ),
    'plugin externo registra Instruction Fragment'
);

$registration = $builder
    ->toolRegistry()
    ->registration('NeutralPing');

checkNeutralV03(
    ($registration->metadata()['domain'] ?? null)
        === 'neutral',
    'ToolRegistration mantém metadados do plugin externo'
);

echo PHP_EOL;
echo "3. BUILD DO RUNTIME" . PHP_EOL;

$guiche = $builder->build();

checkNeutralV03(
    $guiche instanceof Guiche,
    'GuicheBuilder constrói runtime com plugin externo'
);

checkNeutralV03(
    array_column(
        $guiche->tools(),
        'name'
    ) === ['NeutralPing'],
    'Tool externa aparece na definição do runtime'
);

echo PHP_EOL;
echo "4. EXECUÇÃO END-TO-END" . PHP_EOL;

$result = $guiche->handle(
    'neutral-v03-session',
    'execute o teste neutro'
);

checkNeutralV03(
    ($result['message'] ?? null)
        === 'Fluxo neutro concluído.',
    'runtime conclui conversa usando plugin externo'
);

$events = $result['events'] ?? [];

$toolCalls = array_values(
    array_filter(
        $events,
        static fn (array $event): bool =>
            ($event['type'] ?? null)
                === 'tool_call'
    )
);

$toolResults = array_values(
    array_filter(
        $events,
        static fn (array $event): bool =>
            ($event['type'] ?? null)
                === 'tool_result'
    )
);

checkNeutralV03(
    count($toolCalls) === 1
    && ($toolCalls[0]['name'] ?? null)
        === 'NeutralPing',
    'IA fake dispara a Tool do plugin externo'
);

checkNeutralV03(
    count($toolResults) === 1
    && (
        $toolResults[0]['result']['data']['echo']
        ?? null
    ) === 'olá externo',
    'Tool externa é executada pelo Guiche'
);

$capturedOutput =
    $ai->continueOutputs[0][0]['output']
    ?? [];

checkNeutralV03(
    (
        $capturedOutput['_guiche']
        ['presentation_message']
        ?? null
    ) === 'Eco neutro: olá externo',
    'Presenter externo é aplicado pelo runtime'
);

checkNeutralV03(
    str_contains(
        (string)(
            $ai->respondInstructions[0]
            ?? ''
        ),
        'PLUGIN NEUTRO ATIVO'
    ),
    'Instruction Fragment externo chega ao provider'
);

echo PHP_EOL;
echo "5. STATE MANAGER EXTERNO" . PHP_EOL;

$state = $result['context']['state']
    ?? [];

checkNeutralV03(
    ($state['neutral_last_message'] ?? null)
        === 'execute o teste neutro',
    'StateManager externo processa mensagem do usuário'
);

checkNeutralV03(
    ($state['neutral_tool_executed'] ?? false)
        === true,
    'StateManager externo processa resultado da Tool'
);

checkNeutralV03(
    ($state['neutral_echo'] ?? null)
        === 'olá externo',
    'estado externo preserva dado produzido pela Tool'
);

echo PHP_EOL;
echo "6. INDEPENDÊNCIA DE DOMÍNIO" . PHP_EOL;

$pluginSource = '';

foreach (
    glob(
        dirname(__DIR__)
        . '/examples/neutral-plugin/src/*.php'
    ) ?: []
    as $file
) {
    $pluginSource .=
        (string)file_get_contents($file);
}

$clinicalMarkers = [
    'Ortoshine',
    'Clinical',
    'agendamento',
    'convenio',
    'tratamento',
    'profissional',
    'paciente',
    'state.etapa',
];

$neutral = true;

foreach ($clinicalMarkers as $marker) {
    if (stripos($pluginSource, $marker) !== false) {
        $neutral = false;
        break;
    }
}

checkNeutralV03(
    $neutral,
    'plugin de aceite não contém semântica clínica'
);

echo PHP_EOL;
echo "OK: plugin neutro externo validado end-to-end." . PHP_EOL;
