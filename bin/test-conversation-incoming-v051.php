<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Contracts\ClockInterface;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException(
            'FALHOU: ' . $message
        );
    }

    echo 'OK: ' . $message . PHP_EOL;
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;

        if (is_dir($full)) {
            removeTree($full);
        } else {
            @unlink($full);
        }
    }

    @rmdir($path);
}

final class FrozenConversationClock
    implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $value
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->value;
    }

    public function advance(string $modifier): void
    {
        $next = $this->value->modify($modifier);

        if (!$next instanceof \DateTimeImmutable) {
            throw new \RuntimeException(
                'Invalid clock modifier.'
            );
        }

        $this->value = $next;
    }
}

$root = dirname(__DIR__);
$tempRoot =
    sys_get_temp_dir()
    . '/guiche-v051-phase02-'
    . bin2hex(random_bytes(6));

$storeDir = $tempRoot . '/store';

mkdir($storeDir, 0770, true);

try {
    echo PHP_EOL;
    echo "GUICHÊ v0.5.1 — FASE 02 / INCOMING EVENTS" . PHP_EOL;
    echo "=================================================" . PHP_EOL;

    echo PHP_EOL;
    echo "1. CONVERSATION KEY" . PHP_EOL;

    $key = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'conversation-42'
    );

    check(
        strlen($key) === 64,
        'conversation_key é SHA-256 opaco'
    );

    foreach (
        [
            'provider-a',
            'tenant-1',
            'inbox-1',
            'conversation-42',
        ] as $raw
    ) {
        check(
            !str_contains($key, $raw),
            "conversation_key não expõe {$raw}"
        );
    }

    $changedProvider = ConversationKey::fromParts(
        'provider-b',
        'tenant-1',
        'inbox-1',
        'conversation-42'
    );

    $changedTenant = ConversationKey::fromParts(
        'provider-a',
        'tenant-2',
        'inbox-1',
        'conversation-42'
    );

    $changedInbox = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-2',
        'conversation-42'
    );

    $changedConversation = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'conversation-99'
    );

    check(
        count(
            array_unique(
                [
                    $key,
                    $changedProvider,
                    $changedTenant,
                    $changedInbox,
                    $changedConversation,
                ]
            )
        ) === 5,
        'provider/tenant/inbox/conversation participam da identidade'
    );

    echo PHP_EOL;
    echo "2. STORE JSON ATÔMICO" . PHP_EOL;

    $store = new JsonConversationStore($storeDir);

    check(
        $store->load($key) === [],
        'conversa ausente inicia vazia'
    );

    $seeded = $store->mutate(
        $key,
        static function (array $state): array {
            $state['plugin_unknown'] = [
                'keep' => true,
            ];

            return $state;
        }
    );

    check(
        ($seeded['plugin_unknown']['keep'] ?? false) === true,
        'store persiste array genérico'
    );

    check(
        (
            $store->load($key)
                ['plugin_unknown']['keep']
            ?? false
        ) === true,
        'load recupera estado persistido'
    );

    $dataPath =
        $storeDir
        . '/'
        . hash('sha256', $key)
        . '.json';

    check(
        is_file($dataPath),
        'arquivo final usa nome opaco'
    );

    check(
        glob($dataPath . '.tmp.*') === [],
        'persistência não deixa temp residual'
    );

    echo PHP_EOL;
    echo "3. PRIMEIRO INCOMING EVENT" . PHP_EOL;

    $clock = new FrozenConversationClock(
        new \DateTimeImmutable(
            '2026-10-13T22:00:00-04:00'
        )
    );

    $recorder = new IncomingEventRecorder(
        $store,
        $clock
    );

    $event1 = new IncomingEvent(
        'provider-event-001',
        'message',
        [
            'text' => 'Oi',
            'sender' => 'external-user',
        ],
        new \DateTimeImmutable(
            '2026-10-13T21:59:59-04:00'
        )
    );

    $receipt1 = $recorder->record(
        $key,
        $event1
    );

    check(
        $receipt1['accepted'] === true,
        'primeiro evento é aceito'
    );

    check(
        $receipt1['duplicate'] === false,
        'primeiro evento não é duplicado'
    );

    check(
        $receipt1['sequence'] === 1,
        'primeiro evento recebe sequence=1'
    );

    $state = $store->load($key);

    check(
        ($state['schema_version'] ?? null) === 1,
        'agregado possui schema_version=1'
    );

    check(
        ($state['conversation_key'] ?? null) === $key,
        'agregado preserva conversation_key'
    );

    check(
        ($state['sequence'] ?? null) === 1,
        'sequence persistida'
    );

    check(
        count($state['events'] ?? []) === 1,
        'evento persistido uma vez'
    );

    check(
        (
            $state['events'][0]['external_event_id']
            ?? null
        ) === 'provider-event-001',
        'external_event_id persistido'
    );

    check(
        (
            $state['events'][0]['payload']['text']
            ?? null
        ) === 'Oi',
        'payload normalizado necessário é preservado'
    );

    check(
        (
            $state['plugin_unknown']['keep']
            ?? false
        ) === true,
        'recording preserva campos desconhecidos'
    );

    echo PHP_EOL;
    echo "4. DEDUPLICAÇÃO" . PHP_EOL;

    $duplicate = $recorder->record(
        $key,
        new IncomingEvent(
            'provider-event-001',
            'message',
            ['text' => 'DUPLICADO']
        )
    );

    check(
        $duplicate['accepted'] === false,
        'evento repetido não é aceito novamente'
    );

    check(
        $duplicate['duplicate'] === true,
        'evento repetido é identificado como duplicado'
    );

    check(
        $duplicate['sequence'] === 1,
        'duplicata não incrementa sequence'
    );

    $state = $store->load($key);

    check(
        count($state['events']) === 1,
        'duplicata não cria segundo evento'
    );

    check(
        $state['events'][0]['payload']['text'] === 'Oi',
        'duplicata não sobrescreve evento original'
    );

    echo PHP_EOL;
    echo "5. NOVO EVENTO / SEQUENCE" . PHP_EOL;

    $clock->advance('+5 seconds');

    $receipt2 = $recorder->record(
        $key,
        new IncomingEvent(
            'provider-event-002',
            'message',
            ['text' => 'queria marcar']
        )
    );

    check(
        $receipt2['sequence'] === 2,
        'segundo evento único incrementa sequence'
    );

    $state = $store->load($key);

    check(
        count($state['events']) === 2,
        'dois eventos únicos permanecem ordenados'
    );

    check(
        $state['events'][0]['sequence'] === 1
        && $state['events'][1]['sequence'] === 2,
        'eventos carregam sequence individual'
    );

    echo PHP_EOL;
    echo "6. ISOLAMENTO ENTRE CONVERSAS" . PHP_EOL;

    $otherKey = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'conversation-other'
    );

    $otherRecorder = new IncomingEventRecorder(
        $store,
        $clock
    );

    $other = $otherRecorder->record(
        $otherKey,
        new IncomingEvent(
            'provider-event-001',
            'message',
            ['text' => 'mesmo external id']
        )
    );

    check(
        $other['accepted'] === true,
        'mesmo external_event_id em outra conversa é independente'
    );

    check(
        $other['sequence'] === 1,
        'outra conversa possui sequence própria'
    );

    echo PHP_EOL;
    echo "7. RESTART-SAFE DEDUPE" . PHP_EOL;

    unset($recorder, $store);

    $storeAfterRestart =
        new JsonConversationStore($storeDir);

    $recorderAfterRestart =
        new IncomingEventRecorder(
            $storeAfterRestart,
            $clock
        );

    $restartDuplicate =
        $recorderAfterRestart->record(
            $key,
            new IncomingEvent(
                'provider-event-002',
                'message',
                ['text' => 'retry after restart']
            )
        );

    check(
        $restartDuplicate['duplicate'] === true,
        'dedupe sobrevive a nova instância/processo'
    );

    check(
        $restartDuplicate['sequence'] === 2,
        'restart não incrementa sequence em duplicata'
    );

    echo PHP_EOL;
    echo "8. JSON CORROMPIDO" . PHP_EOL;

    $corruptKey = ConversationKey::fromParts(
        'provider-a',
        'tenant-1',
        'inbox-1',
        'corrupt'
    );

    $corruptPath =
        $storeDir
        . '/'
        . hash('sha256', $corruptKey)
        . '.json';

    file_put_contents(
        $corruptPath,
        '{"broken":'
    );

    $corruptionDetected = false;

    try {
        $storeAfterRestart->load($corruptKey);
    } catch (\RuntimeException $exception) {
        $corruptionDetected =
            str_contains(
                $exception->getMessage(),
                'corrupted'
            );
    }

    check(
        $corruptionDetected,
        'JSON corrompido falha explicitamente'
    );

    check(
        file_get_contents($corruptPath)
            === '{"broken":',
        'erro de leitura não sobrescreve arquivo corrompido'
    );

    echo PHP_EOL;
    echo "9. CONCORRÊNCIA / DEDUPE ATÔMICA" . PHP_EOL;

    $workerPath =
        $tempRoot . '/worker.php';

    $workerSource = <<<'WORKER'
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

$store =
    new \Asmpkg\Guiche\Conversation\JsonConversationStore(
        $argv[2]
    );

$clock =
    new class implements
        \Asmpkg\Guiche\Contracts\ClockInterface
    {
        public function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable(
                '2026-10-14T02:00:00Z'
            );
        }
    };

$recorder =
    new \Asmpkg\Guiche\Conversation\IncomingEventRecorder(
        $store,
        $clock
    );

$receipt = $recorder->record(
    $argv[3],
    new \Asmpkg\Guiche\Conversation\IncomingEvent(
        $argv[4],
        'message',
        ['worker' => $argv[5]]
    )
);

echo json_encode(
    $receipt,
    JSON_THROW_ON_ERROR
);
WORKER;

    file_put_contents(
        $workerPath,
        $workerSource
    );

    $concurrentKey =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'concurrent-duplicate'
        );

    $processes = [];

    for ($i = 1; $i <= 8; $i++) {
        $command = [
            PHP_BINARY,
            $workerPath,
            $root,
            $storeDir,
            $concurrentKey,
            'same-external-event',
            (string) $i,
        ];

        $pipes = [];

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException(
                'Não foi possível iniciar worker.'
            );
        }

        fclose($pipes[0]);

        $processes[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    $acceptedCount = 0;

    foreach ($processes as $worker) {
        $stdout =
            stream_get_contents($worker['stdout']);
        $stderr =
            stream_get_contents($worker['stderr']);

        fclose($worker['stdout']);
        fclose($worker['stderr']);

        $code = proc_close($worker['process']);

        if ($code !== 0) {
            throw new \RuntimeException(
                "Worker falhou: {$stderr}"
            );
        }

        $decoded = json_decode(
            $stdout,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (($decoded['accepted'] ?? false) === true) {
            $acceptedCount++;
        }
    }

    $concurrentState =
        $storeAfterRestart->load($concurrentKey);

    check(
        $acceptedCount === 1,
        'oito workers aceitam external_event_id apenas uma vez'
    );

    check(
        ($concurrentState['sequence'] ?? null) === 1,
        'dedupe concorrente incrementa sequence uma vez'
    );

    check(
        count($concurrentState['events'] ?? []) === 1,
        'dedupe concorrente persiste um evento'
    );

    echo PHP_EOL;
    echo "10. CONCORRÊNCIA / EVENTOS ÚNICOS" . PHP_EOL;

    $uniqueKey =
        ConversationKey::fromParts(
            'provider-a',
            'tenant-1',
            'inbox-1',
            'concurrent-unique'
        );

    $processes = [];

    for ($i = 1; $i <= 8; $i++) {
        $command = [
            PHP_BINARY,
            $workerPath,
            $root,
            $storeDir,
            $uniqueKey,
            'unique-event-' . $i,
            (string) $i,
        ];

        $pipes = [];

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException(
                'Não foi possível iniciar worker único.'
            );
        }

        fclose($pipes[0]);

        $processes[] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];
    }

    foreach ($processes as $worker) {
        stream_get_contents($worker['stdout']);
        $stderr =
            stream_get_contents($worker['stderr']);

        fclose($worker['stdout']);
        fclose($worker['stderr']);

        $code = proc_close($worker['process']);

        if ($code !== 0) {
            throw new \RuntimeException(
                "Worker único falhou: {$stderr}"
            );
        }
    }

    $uniqueState =
        $storeAfterRestart->load($uniqueKey);

    check(
        ($uniqueState['sequence'] ?? null) === 8,
        'oito eventos concorrentes únicos geram sequence=8'
    );

    check(
        count($uniqueState['events'] ?? []) === 8,
        'nenhum evento concorrente único é perdido'
    );

    $sequences = array_column(
        $uniqueState['events'],
        'sequence'
    );

    sort($sequences);

    check(
        $sequences === range(1, 8),
        'sequence concorrente não possui lacunas nem duplicatas'
    );

    echo PHP_EOL;
    echo "11. DELETE SOB LOCK" . PHP_EOL;

    $storeAfterRestart->delete($otherKey);

    check(
        $storeAfterRestart->load($otherKey) === [],
        'delete remove agregado da conversa'
    );

    echo PHP_EOL;
    echo "12. FRONTEIRA DA FASE" . PHP_EOL;

    $guicheSource =
        file_get_contents(
            $root . '/src/Guiche.php'
        );

    check(
        is_string($guicheSource)
        && !str_contains(
            $guicheSource,
            'IncomingEventRecorder'
        ),
        'Guiche não recebe responsabilidade de ingestão'
    );

    foreach (
        [
            'Debounce.php',
            'FollowUp.php',
        ] as $future
    ) {
        $found = false;

        $iterator =
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root . '/src',
                    \FilesystemIterator::SKIP_DOTS
                )
            );

        foreach ($iterator as $item) {
            if (
                $item->isFile()
                && $item->getFilename() === $future
            ) {
                $found = true;
                break;
            }
        }

        check(
            !$found,
            "Fase 02 ainda não implementa {$future}"
        );
    }

    echo PHP_EOL;
    echo "OK: GUICHÊ v0.5.1 / FASE 02 validada." . PHP_EOL;
} finally {
    removeTree($tempRoot);
}
