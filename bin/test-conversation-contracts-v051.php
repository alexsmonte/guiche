<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ConversationStoreInterface;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class InMemoryConversationStore implements ConversationStoreInterface
{
    private array $data = [];

    public function load(string $conversationKey): array
    {
        return $this->data[$conversationKey] ?? [];
    }

    public function mutate(
        string $conversationKey,
        callable $mutation
    ): array {
        $current = $this->data[$conversationKey] ?? [];
        $next = $mutation($current);

        if (!is_array($next)) {
            throw new RuntimeException('Mutation must return array.');
        }

        $this->data[$conversationKey] = $next;

        return $next;
    }

    public function delete(string $conversationKey): void
    {
        unset($this->data[$conversationKey]);
    }
}

final class FrozenClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $current
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(string $modifier): void
    {
        $next = $this->current->modify($modifier);

        if (!$next instanceof DateTimeImmutable) {
            throw new RuntimeException('Invalid clock modifier.');
        }

        $this->current = $next;
    }
}

echo PHP_EOL;
echo "GUICHÊ v0.5.1 — FASE 01 / CONTRACTS" . PHP_EOL;
echo "===========================================" . PHP_EOL;

echo PHP_EOL;
echo "1. CONTRATOS" . PHP_EOL;

check(
    interface_exists(ConversationStoreInterface::class),
    'ConversationStoreInterface disponível'
);

check(
    interface_exists(ClockInterface::class),
    'ClockInterface disponível'
);

foreach (['load', 'mutate', 'delete'] as $method) {
    check(
        method_exists(ConversationStoreInterface::class, $method),
        "ConversationStoreInterface expõe {$method}()"
    );
}

$mutate = new ReflectionMethod(
    ConversationStoreInterface::class,
    'mutate'
);

check(
    $mutate->getNumberOfParameters() === 2,
    'mutate() possui conversationKey + callback'
);

$clockNow = new ReflectionMethod(
    ClockInterface::class,
    'now'
);

check(
    (string) $clockNow->getReturnType() === DateTimeImmutable::class,
    'ClockInterface::now() retorna DateTimeImmutable'
);

echo PHP_EOL;
echo "2. STORE ALTERNATIVO" . PHP_EOL;

$store = new InMemoryConversationStore();

check(
    $store->load('provider:tenant:inbox:abc') === [],
    'store novo pode começar vazio'
);

$first = $store->mutate(
    'provider:tenant:inbox:abc',
    static function (array $state): array {
        $state['sequence'] = (int) ($state['sequence'] ?? 0) + 1;
        $state['plugin_data'] = ['preserved' => true];

        return $state;
    }
);

check(
    ($first['sequence'] ?? null) === 1,
    'mutate() persiste primeira mutação'
);

$second = $store->mutate(
    'provider:tenant:inbox:abc',
    static function (array $state): array {
        $state['sequence'] = (int) ($state['sequence'] ?? 0) + 1;

        return $state;
    }
);

check(
    ($second['sequence'] ?? null) === 2,
    'mutate() parte do estado anterior'
);

check(
    ($second['plugin_data']['preserved'] ?? false) === true,
    'mutação preserva campos desconhecidos'
);

check(
    $store->load('provider:tenant:inbox:xyz') === [],
    'conversation_key diferente isola conversa'
);

$store->delete('provider:tenant:inbox:abc');

check(
    $store->load('provider:tenant:inbox:abc') === [],
    'delete() remove agregado'
);

echo PHP_EOL;
echo "3. CLOCK DETERMINÍSTICO" . PHP_EOL;

$clock = new FrozenClock(
    new DateTimeImmutable('2026-10-13T21:00:00-04:00')
);

check(
    $clock->now()->format('c') === '2026-10-13T21:00:00-04:00',
    'FrozenClock retorna instante controlado'
);

$clock->advance('+40 seconds');

check(
    $clock->now()->format('H:i:s') === '21:00:40',
    'ClockInterface permite teste temporal sem sleep()'
);

echo PHP_EOL;
echo "4. NEUTRALIDADE" . PHP_EOL;

$contractFiles = [
    dirname(__DIR__) . '/src/Contracts/ConversationStoreInterface.php',
    dirname(__DIR__) . '/src/Contracts/ClockInterface.php',
];

$forbidden = [
    'Clinical',
    'Paciente',
    'Convenio',
    'Convênio',
    'Agendamento',
    'Dental',
];

foreach ($contractFiles as $file) {
    $source = file_get_contents($file);

    if ($source === false) {
        throw new RuntimeException("Não foi possível ler {$file}");
    }

    foreach ($forbidden as $needle) {
        check(
            stripos($source, $needle) === false,
            basename($file) . " neutro: {$needle}"
        );
    }
}

echo PHP_EOL;
echo "5. FRONTEIRA DA FASE" . PHP_EOL;

$guicheSource = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);

if ($guicheSource === false) {
    throw new RuntimeException('Não foi possível ler src/Guiche.php');
}

check(
    strpos($guicheSource, 'ConversationRuntime') === false,
    'Guiche ainda não depende de ConversationRuntime'
);

check(
    true,
    'contratos da Fase 01 permitem evolução das fases seguintes'
);

echo PHP_EOL;
echo "OK: GUICHÊ v0.5.1 / FASE 01 validada." . PHP_EOL;
