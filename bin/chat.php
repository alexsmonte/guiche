<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Infrastructure\Database\PdoFactory;
use Asmpkg\Guiche\Infrastructure\Database\PdoSqlExecutor;
use Asmpkg\Guiche\Providers\OpenAiResponsesProvider;
use Asmpkg\Guiche\RuntimeContext;
use Asmpkg\Guiche\StateManager;
use Asmpkg\Guiche\ToolRegistry;
use Asmpkg\Guiche\Tools\ConsultarHorariosTool;
use Asmpkg\Guiche\Tools\ConsultarProfissionalTool;
use Asmpkg\Guiche\Tools\DisponibilidadeConveniosTool;

use Asmpkg\Guiche\Tools\ConsultarPacienteTool;
require dirname(__DIR__) . '/vendor/autoload.php';

function loadEnvFile(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

function printJson(mixed $value): void
{
    echo json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}

function printDebugEvents(array $events): void
{
    foreach ($events as $event) {
        $type = $event['type'] ?? '';

        if ($type === 'tool_call') {
            echo PHP_EOL . "[TOOL CALL] " . ($event['name'] ?? '') . PHP_EOL;
            printJson($event['arguments'] ?? []);
        } elseif ($type === 'tool_cache_hit') {
            echo "[TOOL CACHE HIT] " . ($event['name'] ?? '') . PHP_EOL;
        } elseif ($type === 'tool_result') {
            echo "[TOOL RESULT] " . ($event['name'] ?? '') . PHP_EOL;
            printJson($event['result'] ?? []);
        } elseif ($type === 'ai_response' && !empty($event['tool_calls'] ?? [])) {
            echo "[IA decidiu usar ferramenta]" . PHP_EOL;
        } elseif ($type === 'completion_guard') {
            echo "[GUARD] Ação final bloqueada: "
                . ($event['required_tool'] ?? '')
                . " não foi executada com sucesso."
                . PHP_EOL;
        }
    }
}

loadEnvFile(dirname(__DIR__) . '/.env');

$config = require dirname(__DIR__) . '/config/guiche.php';
$prompt = require dirname(__DIR__) . '/config/prompt.php';

$pdo = PdoFactory::fromConfig($config['database']);
$sql = new PdoSqlExecutor($pdo);

$registry = new ToolRegistry();

$registry
    ->register(
        new DisponibilidadeConveniosTool(
            $sql,
            $config['procedures']['disponibilidade_convenios']
        )
    )
    ->register(
        new ConsultarProfissionalTool(
            $sql,
            $config['procedures']['consultar_profissional']
        )
    )
    ->register(
        new ConsultarHorariosTool(
            $sql,
            $config['procedures']['consultar_horarios'],
            $config['horarios_por_data']
        )
    );

$consultarPacienteProcedure = trim(
    (string)($config['procedures']['consultar_paciente'] ?? '')
);

if ($consultarPacienteProcedure !== '') {
    $registry->register(
        new ConsultarPacienteTool(
            $sql,
            $consultarPacienteProcedure
        )
    );
}

$agendamentoApiUrl = trim(
    (string)($config['agendamento_api']['url'] ?? '')
);

if ($agendamentoApiUrl !== '') {
    $registry->register(
        new \Asmpkg\Guiche\Tools\CriarAgendamentoTool(
            new \Asmpkg\Guiche\Infrastructure\Http\CurlHttpClient(),
            $agendamentoApiUrl,
            (string)($config['agendamento_api']['token'] ?? ''),
            (int)($config['agendamento_api']['timeout'] ?? 15)
        )
    );
}

$guiche = new Guiche(
    tools: $registry,
    context: new ContextStore(
        $config['context_path'],
        $config['timezone']
    ),
    ai: new \Asmpkg\Guiche\Providers\TerminalToolOutputAiProvider(
        new OpenAiResponsesProvider(
                $config['ai']['api_key'],
                $config['ai']['model']
            )
    ),
    instructions: $prompt,
    agentContext: $config['agent_context'],
    runtime: new RuntimeContext($config['timezone']),
    stateManager: new StateManager(),
    reusableTools: $config['reusable_tools'],
    completionGuards: $config['completion_guards'] ?? [],
    historyMessagesLimit: $config['history_messages_limit'] ?? 8,
    aiStateExcludePaths: $config['ai_state_exclude_paths'] ?? [],
    aiToolResultExcludePaths: $config['ai_tool_result_exclude_paths'] ?? []
);

$toolPolicyConfig = $config['tool_policy'] ?? [];

$guiche->setToolPolicy(
    new \Asmpkg\Guiche\ToolPolicy(
        is_array($toolPolicyConfig['stages'] ?? null)
            ? $toolPolicyConfig['stages']
            : [],
        is_array($toolPolicyConfig['default'] ?? null)
            ? $toolPolicyConfig['default']
            : [],
        is_array($toolPolicyConfig['bootstrap'] ?? null)
            ? $toolPolicyConfig['bootstrap']
            : []
    )
);


$sessionId = $argv[1] ?? 'piloto-cli-ia';

echo PHP_EOL;
echo "==================================================" . PHP_EOL;
echo " GUICHÊ - ETAPA 05I / APRESENTAÇÃO DETERMINÍSTICA" . PHP_EOL;
echo "==================================================" . PHP_EOL;
echo "Sessão: {$sessionId}" . PHP_EOL;
echo "DisponibilidadeConvenios: REAL" . PHP_EOL;
echo "ConsultarProfissional: REAL" . PHP_EOL;
echo "ConsultarHorarios: REAL / {$config['horarios_por_data']} horários por data" . PHP_EOL;
echo "Timezone: {$config['timezone']}" . PHP_EOL;
echo "Histórico enviado à IA: últimas {$config['history_messages_limit']} mensagens" . PHP_EOL;
echo "State enviado à IA: compactado (estado completo permanece salvo)" . PHP_EOL;
echo PHP_EOL;
echo "Comandos: /context /usage /runtime /tools /reset /sair" . PHP_EOL . PHP_EOL;

while (true) {
    $input = readline("Você: ");

    if ($input === false) {
        break;
    }

    $input = trim($input);

    if ($input === '') {
        continue;
    }

    if ($input === '/sair') {
        break;
    }

    if ($input === '/context') {
        printJson($guiche->context($sessionId));
        continue;
    }

    if ($input === '/usage') {
        $context = $guiche->context($sessionId);
        printJson($context['usage'] ?? []);
        continue;
    }

    if ($input === '/runtime') {
        $context = $guiche->context($sessionId);

        printJson([
            'timezone' => $config['timezone'],
            'updated_at' => $context['updated_at'] ?? null,
        ]);

        continue;
    }

    if ($input === '/tools') {
        printJson($guiche->tools());
        continue;
    }

    if ($input === '/reset') {
        $guiche->reset($sessionId);
        echo "Sessão apagada." . PHP_EOL;
        continue;
    }

    if (str_starts_with($input, '/')) {
        echo "Comando desconhecido: {$input}" . PHP_EOL;
        echo "Use: /context /usage /runtime /tools /reset /sair" . PHP_EOL;
        continue;
    }

    try {
        $result = $guiche->handle($sessionId, $input);

        if ($config['debug']) {
            printDebugEvents($result['events'] ?? []);
        }

        echo PHP_EOL . "Meg: " . $result['message'] . PHP_EOL . PHP_EOL;
    } catch (Throwable $exception) {
        echo PHP_EOL . "[ERRO] " . $exception->getMessage() . PHP_EOL . PHP_EOL;
    }
}
