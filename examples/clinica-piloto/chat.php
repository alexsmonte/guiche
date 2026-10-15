<?php

declare(strict_types=1);

use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use GuicheExample\ClinicaPiloto\ClinicPlugin;
use GuicheExample\ClinicaPiloto\Adapters\ClinicalAdapterFactory;
use Asmpkg\Guiche\ContextStore;

use Asmpkg\Guiche\Infrastructure\Database\PdoFactory;
use Asmpkg\Guiche\Infrastructure\Database\PdoSqlExecutor;
use Asmpkg\Guiche\Providers\OpenAiResponsesProvider;
use Asmpkg\Guiche\RuntimeContext;

require __DIR__ . '/bootstrap.php';

function printJson(mixed $value): void
{
    echo json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
}

/** @param array<mixed> $events */
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


$coreConfig = require dirname(__DIR__, 2) . '/config/guiche.php';
$pilotConfig = require __DIR__ . '/config/clinic.php';
$prompt = require __DIR__ . '/config/prompt.php';

$config = array_replace_recursive($coreConfig, $pilotConfig);
$modeLabel = strtoupper(ClinicalAdapterFactory::mode($config));

$sql = ClinicalAdapterFactory::sql($config);

$sessionId = $argv[1] ?? 'piloto-cli-ia';

$tenantConfig = is_array($config['tenant_context'] ?? null)
    ? $config['tenant_context']
    : [];

$tenantMap = is_array($tenantConfig['tenants'] ?? null)
    ? $tenantConfig['tenants']
    : [];

$tenantKey = trim(
    (string)($argv[2] ?? $tenantConfig['default_key'] ?? '')
);

if (
    $tenantKey === ''
    || !array_key_exists($tenantKey, $tenantMap)
) {
    throw new RuntimeException(
        'Tenant clínico não configurado para esta sessão.'
    );
}

$contextStore = new ContextStore(
    $config['context_path'],
    $config['timezone']
);

$seeder = new ExecutionContextSeeder($contextStore);

$seedExecutionContext = static function () use (
    $seeder,
    $sessionId,
    $tenantKey
): void {
    $seeder->seed(
        $sessionId,
        [
            'identity' => [
                'provider' => 'clinical-pilot-cli',
                'tenant_key' => $tenantKey,
            ],
        ]
    );
};

$seedExecutionContext();

$tenantResolver = new CallbackTenantResolver(
    static function (array $identity) use ($tenantMap): array {
        $key = trim((string)($identity['tenant_key'] ?? ''));

        if (
            $key === ''
            || !array_key_exists($key, $tenantMap)
        ) {
            return [];
        }

        return [
            'tenant_id' => (int)$tenantMap[$key],
        ];
    }
);

$guiche = (new GuicheBuilder())
    ->withContextStore($contextStore)
    ->withTenantResolver($tenantResolver)
    ->withAiProvider(
        ClinicalAdapterFactory::ai($config)
    )
    ->withAgentContext(
        $config['agent_context'] ?? []
    )
    ->withRuntime(
        new RuntimeContext(
            $config['timezone']
        )
    )
    ->withReusableTools(
        $config['reusable_tools'] ?? []
    )
    ->withHistoryMessagesLimit(
        (int)($config['history_messages_limit'] ?? 8)
    )
    ->withAiStateExcludePaths(
        $config['ai_state_exclude_paths'] ?? []
    )
    ->withMaxToolRounds(
        max(
            1,
            (int)($config['max_tool_rounds'] ?? 5)
        )
    )
    ->registerPlugin(
        new ClinicPlugin(
            $sql,
            $config,
            $prompt
        )
    )
    ->build();


echo PHP_EOL;
echo "==================================================" . PHP_EOL;
echo " GUICHÊ - ETAPA 05I / APRESENTAÇÃO DETERMINÍSTICA" . PHP_EOL;
echo "==================================================" . PHP_EOL;
echo "Sessão: {$sessionId}" . PHP_EOL;
echo "Tenant key: {$tenantKey}" . PHP_EOL;
echo "DisponibilidadeConvenios: {$modeLabel}" . PHP_EOL;
echo "ConsultarProfissional: {$modeLabel}" . PHP_EOL;
echo "ConsultarHorarios: {$modeLabel} / {$pilotConfig['horarios_por_data']} horários por data" . PHP_EOL;
echo "Timezone: {$coreConfig['timezone']}" . PHP_EOL;
echo "Histórico enviado à IA: últimas {$coreConfig['history_messages_limit']} mensagens" . PHP_EOL;
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
            'timezone' => $coreConfig['timezone'],
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
        $seedExecutionContext();
        echo "Sessão apagada e identidade confiável recriada." . PHP_EOL;
        continue;
    }

    if (str_starts_with($input, '/')) {
        echo "Comando desconhecido: {$input}" . PHP_EOL;
        echo "Use: /context /usage /runtime /tools /reset /sair" . PHP_EOL;
        continue;
    }

    try {
        $result = $guiche->handle($sessionId, $input);

        if ($coreConfig['debug']) {
            printDebugEvents($result['events'] ?? []);
        }

        echo PHP_EOL . "Meg: " . $result['message'] . PHP_EOL . PHP_EOL;
    } catch (Throwable $exception) {
        echo PHP_EOL . "[ERRO] " . $exception->getMessage() . PHP_EOL . PHP_EOL;
    }
}
