<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;

function assertV05(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

echo "GUICHÊ v0.5.0 — FASE 01 / PERSISTENCE CONTRACTS\n";
echo "========================================================\n\n";

echo "1. CONTRATOS\n";
assertV05(interface_exists(ContextStoreInterface::class), 'ContextStoreInterface disponível');
assertV05(interface_exists(RuntimeEventSinkInterface::class), 'RuntimeEventSinkInterface disponível');
assertV05(
    is_subclass_of(ContextStore::class, ContextStoreInterface::class),
    'ContextStore implementa ContextStoreInterface'
);

$requiredMethods = [
    'load',
    'save',
    'delete',
    'addMessage',
    'setIntent',
    'mergeState',
    'applyPatch',
    'addToolExecution',
    'findSuccessfulToolResult',
    'recordInteractionUsage',
    'toolFingerprint',
];

foreach ($requiredMethods as $method) {
    assertV05(
        method_exists(ContextStoreInterface::class, $method),
        "contrato expõe {$method}()"
    );
}

echo "\n2. RUNTIME EVENT SINK\n";

$sink = new class implements RuntimeEventSinkInterface {
    public array $events = [];

    public function record(array $event): void
    {
        $this->events[] = $event;
    }
};

$sink->record([
    'type' => 'phase_01_probe',
    'schema_version' => 1,
]);

assertV05(count($sink->events) === 1, 'RuntimeEventSinkInterface aceita evento serializável');
assertV05(
    json_encode($sink->events[0], JSON_THROW_ON_ERROR) !== '',
    'evento do contrato é serializável em JSON'
);

echo "\n3. BACKEND ATUAL CONTINUA FUNCIONAL\n";

$directory = sys_get_temp_dir()
    . '/guiche-v05-contract-'
    . bin2hex(random_bytes(6));

$store = new ContextStore($directory, 'America/Manaus');
$sessionId = 'phase-01-' . bin2hex(random_bytes(4));

$initial = $store->load($sessionId);

assertV05(
    ($initial['session_id'] ?? null) === $sessionId,
    'ContextStore concreto continua criando contexto da sessão'
);

$store->addMessage($sessionId, 'user', 'probe');
$loaded = $store->load($sessionId);

assertV05(
    ($loaded['messages'][0]['content'] ?? null) === 'probe',
    'addMessage/load permanecem compatíveis'
);

$fingerprintA = $store->toolFingerprint(
    $sessionId,
    'ProbeTool',
    ['b' => 2, 'a' => 1]
);

$fingerprintB = $store->toolFingerprint(
    $sessionId,
    'ProbeTool',
    ['a' => 1, 'b' => 2]
);

assertV05(
    $fingerprintA === $fingerprintB,
    'fingerprint da v0.4 permanece estável'
);

$store->delete($sessionId);

if (is_dir($directory)) {
    $entries = scandir($directory);

    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    @rmdir($directory);
}

echo "\n4. NEUTRALIDADE\n";

$contractSource = file_get_contents(
    dirname(__DIR__) . '/src/Contracts/ContextStoreInterface.php'
);
$sinkSource = file_get_contents(
    dirname(__DIR__) . '/src/Contracts/RuntimeEventSinkInterface.php'
);

$combined = strtolower((string) $contractSource . "\n" . (string) $sinkSource);

foreach (['clinical', 'paciente', 'convenio', 'agendamento'] as $forbidden) {
    assertV05(
        !str_contains($combined, $forbidden),
        "contratos não conhecem domínio {$forbidden}"
    );
}

echo "\nOK: GUICHÊ v0.5.0 / FASE 01 validada.\n";
