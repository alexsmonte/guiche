<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Pilot\Clinical\ClinicalStateManager as StateManager;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkCoreStateV02(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class GenericStateManagerV02 implements StateManagerInterface
{
    public array $calls = [];

    public function onUserMessage(array $context, string $message): array
    {
        $this->calls[] = [
            'type' => 'user',
            'message' => $message,
        ];

        return [
            'intent' => 'generic_flow',
            'state' => [
                'flow' => 'generic',
                'user_hook' => true,
            ],
        ];
    }

    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array {
        $this->calls[] = [
            'type' => 'tool',
            'tool' => $tool,
        ];

        return [
            'state' => [
                'tool_hook' => true,
                'last_tool' => $tool,
                'tool_value' => $result['data']['value'] ?? null,
            ],
        ];
    }
}

final class GenericAiProviderV02 implements AiProviderInterface
{
    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        return [
            'id' => 'resp-v02-1',
            'text' => 'Fluxo genérico concluído.',
            'tool_calls' => [],
            'usage' => [],
        ];
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        throw new RuntimeException(
            'Este teste não deve precisar continuar com Tool outputs.'
        );
    }
}

$pilotStateManager = new StateManager();

checkCoreStateV02(
    $pilotStateManager instanceof StateManagerInterface,
    'StateManager atual continua válido através do contrato'
);

$constructor = (new ReflectionClass(Guiche::class))->getConstructor();
$stateParameter = null;

foreach ($constructor?->getParameters() ?? [] as $parameter) {
    if ($parameter->getName() === 'stateManager') {
        $stateParameter = $parameter;
        break;
    }
}

checkCoreStateV02(
    $stateParameter !== null,
    'Guiche continua expondo o ponto de extensão stateManager'
);

$type = $stateParameter?->getType();

checkCoreStateV02(
    $type instanceof ReflectionNamedType
        && $type->getName() === StateManagerInterface::class,
    'Guiche depende da interface genérica, não da implementação clínica'
);

$directory = sys_get_temp_dir()
    . '/guiche-v02-state-contract-'
    . bin2hex(random_bytes(6));

$stateManager = new GenericStateManagerV02();

try {
    $guiche = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $directory,
            'America/Manaus'
        ),
        ai: new GenericAiProviderV02(),
        instructions: 'Teste genérico.',
        stateManager: $stateManager
    );

    $result = $guiche->handle(
        'v02-contract',
        'mensagem de domínio qualquer'
    );

    checkCoreStateV02(
        ($result['context']['intent'] ?? null) === 'generic_flow',
        'hook de mensagem pode definir intenção sem semântica clínica'
    );

    checkCoreStateV02(
        ($result['context']['state']['user_hook'] ?? false) === true,
        'patch de mensagem é aplicado pelo Core'
    );

    checkCoreStateV02(
        count($stateManager->calls) === 1
            && ($stateManager->calls[0]['type'] ?? null) === 'user',
        'Guiche executa o hook genérico de mensagem'
    );

    checkCoreStateV02(
        ($result['message'] ?? null) === 'Fluxo genérico concluído.',
        'fluxo genérico continua produzindo resposta final'
    );

    $toolPatch = $stateManager->onToolResult(
        $result['context'],
        'QualquerTool',
        ['value' => 'abc'],
        [
            'success' => true,
            'data' => [
                'value' => 'abc',
            ],
        ]
    );

    checkCoreStateV02(
        ($toolPatch['state']['tool_hook'] ?? false) === true
            && ($toolPatch['state']['last_tool'] ?? null) === 'QualquerTool'
            && ($toolPatch['state']['tool_value'] ?? null) === 'abc',
        'contrato de resultado de Tool é independente de domínio'
    );
} finally {
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }
}

echo PHP_EOL;
echo "OK: contrato genérico de estado da v0.2 validado." . PHP_EOL;
