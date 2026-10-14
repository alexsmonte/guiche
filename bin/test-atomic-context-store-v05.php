<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\ContextStore;

function checkAtomicV05(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new \RuntimeException(
            'FALHOU: ' . $message
        );
    }

    echo 'OK: ' . $message . PHP_EOL;
}

function contextFileV05(
    string $directory,
    string $sessionId
): string {
    return rtrim(
        $directory,
        DIRECTORY_SEPARATOR
    )
        . DIRECTORY_SEPARATOR
        . hash('sha256', $sessionId)
        . '.json';
}

function removeTreeV05(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory
            . DIRECTORY_SEPARATOR
            . $entry;

        if (is_dir($path)) {
            removeTreeV05($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}

/*
 * Worker multiprocesso.
 */
if (($argv[1] ?? '') === '--worker') {
    $directory = (string) ($argv[2] ?? '');
    $sessionId = (string) ($argv[3] ?? '');
    $workerId = (int) ($argv[4] ?? 0);
    $iterations = (int) ($argv[5] ?? 0);

    if (
        $directory === ''
        || $sessionId === ''
        || $workerId <= 0
        || $iterations <= 0
    ) {
        fwrite(
            STDERR,
            "Worker recebeu argumentos inválidos.\n"
        );
        exit(2);
    }

    $store = new ContextStore(
        $directory,
        'America/Manaus'
    );

    for ($i = 1; $i <= $iterations; $i++) {
        $store->mergeState(
            $sessionId,
            [
                'concurrency' => [
                    'worker_' . $workerId => [
                        'item_' . $i =>
                            $workerId . ':' . $i,
                    ],
                ],
            ]
        );
    }

    exit(0);
}

$directory = sys_get_temp_dir()
    . '/guiche-v05-atomic-'
    . bin2hex(random_bytes(8));

mkdir($directory, 0775, true);

try {
    echo "GUICHÊ v0.5.0 — FASE 03 / ATOMIC CONTEXT STORE\n";
    echo "=====================================================\n\n";

    echo "1. SCHEMA VERSION / CONTEXTO NOVO\n";

    $store = new ContextStore(
        $directory,
        'America/Manaus'
    );

    $new = $store->load('new-session');

    checkAtomicV05(
        ($new['schema_version'] ?? null) === 1,
        'contexto novo nasce normalizado em schema_version=1'
    );

    checkAtomicV05(
        isset(
            $new['state'],
            $new['messages'],
            $new['tool_history'],
            $new['usage'],
            $new['created_at'],
            $new['updated_at']
        ),
        'contexto novo possui campos mínimos'
    );

    $store->addMessage(
        'new-session',
        'user',
        'persistir'
    );

    $newFile = contextFileV05(
        $directory,
        'new-session'
    );

    $persistedNew = json_decode(
        (string) file_get_contents($newFile),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    checkAtomicV05(
        ($persistedNew['schema_version'] ?? null) === 1,
        'primeira persistência grava schema_version=1'
    );

    echo "\n2. CONTEXTO LEGADO v0.4\n";

    $legacySession = 'legacy-v04';
    $legacyFile = contextFileV05(
        $directory,
        $legacySession
    );

    $legacy = [
        'session_id' => $legacySession,
        'intent' => 'legacy-intent',
        'state' => [
            'plugin_data' => [
                'preservar' => true,
            ],
        ],
        'messages' => [
            [
                'role' => 'user',
                'content' => 'legado',
            ],
        ],
        'tool_history' => [],
        'custom_root' => [
            'unknown' => 'preserve-me',
        ],
        'created_at' => '2026-10-01T10:00:00-04:00',
        'updated_at' => '2026-10-01T10:00:00-04:00',
    ];

    file_put_contents(
        $legacyFile,
        json_encode(
            $legacy,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        )
    );

    $loadedLegacy = $store->load(
        $legacySession
    );

    checkAtomicV05(
        ($loadedLegacy['schema_version'] ?? null) === 1,
        'contexto v0.4 sem versão é normalizado em memória'
    );

    checkAtomicV05(
        ($loadedLegacy['custom_root']['unknown'] ?? null)
            === 'preserve-me',
        'normalização preserva campos desconhecidos do contexto'
    );

    checkAtomicV05(
        ($loadedLegacy['state']['plugin_data']['preservar'] ?? false)
            === true,
        'normalização não apaga estado de plugin'
    );

    checkAtomicV05(
        ($loadedLegacy['usage']['total_tokens'] ?? null) === 0
        && is_array(
            $loadedLegacy['usage']['interactions']
            ?? null
        ),
        'normalização completa usage ausente do legado'
    );

    $store->mergeState(
        $legacySession,
        ['migrated' => true]
    );

    $legacyPersisted = json_decode(
        (string) file_get_contents($legacyFile),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    checkAtomicV05(
        ($legacyPersisted['schema_version'] ?? null) === 1,
        'primeira mutação persiste legado no schema atual'
    );

    checkAtomicV05(
        ($legacyPersisted['custom_root']['unknown'] ?? null)
            === 'preserve-me',
        'migração persistida continua preservando dados desconhecidos'
    );

    echo "\n3. CORRUPTION HANDLING\n";

    $corruptSession = 'corrupt';
    $corruptFile = contextFileV05(
        $directory,
        $corruptSession
    );

    file_put_contents(
        $corruptFile,
        '{"json":'
    );

    $corruptionDetected = false;

    try {
        $store->load($corruptSession);
    } catch (\RuntimeException $exception) {
        $corruptionDetected = str_contains(
            mb_strtolower(
                $exception->getMessage(),
                'UTF-8'
            ),
            'corrompido'
        );
    }

    checkAtomicV05(
        $corruptionDetected,
        'JSON inválido falha explicitamente como contexto corrompido'
    );

    echo "\n4. TEMP FILE + RENAME ATÔMICO\n";

    $atomicSession = 'atomic-write';

    $store->mergeState(
        $atomicSession,
        ['stable' => 'yes']
    );

    $atomicFile = contextFileV05(
        $directory,
        $atomicSession
    );

    checkAtomicV05(
        glob($atomicFile . '.tmp.*') === [],
        'persistência concluída não deixa temp file residual'
    );

    $interruptedTemp = $atomicFile
        . '.tmp.interrupted';

    file_put_contents(
        $interruptedTemp,
        '{"partial":'
    );

    $stable = $store->load(
        $atomicSession
    );

    checkAtomicV05(
        ($stable['state']['stable'] ?? null)
            === 'yes',
        'temp interrompido nunca substitui contexto final válido'
    );

    unlink($interruptedTemp);

    $decodedAtomic = json_decode(
        (string) file_get_contents($atomicFile),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    checkAtomicV05(
        ($decodedAtomic['state']['stable'] ?? null)
            === 'yes',
        'arquivo final permanece JSON íntegro'
    );

    echo "\n5. CONCORRÊNCIA MULTIPROCESSO\n";

    $concurrentSession = 'concurrent';
    $workers = 8;
    $iterations = 25;
    $processes = [];

    for ($worker = 1; $worker <= $workers; $worker++) {
        $command = [
            PHP_BINARY,
            __FILE__,
            '--worker',
            $directory,
            $concurrentSession,
            (string) $worker,
            (string) $iterations,
        ];

        $pipes = [];

        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException(
                "Não foi possível iniciar worker {$worker}."
            );
        }

        $processes[] = [
            'worker' => $worker,
            'process' => $process,
            'stderr' => $pipes[2],
        ];
    }

    foreach ($processes as $item) {
        $stderr = stream_get_contents(
            $item['stderr']
        );

        fclose($item['stderr']);

        $status = proc_close(
            $item['process']
        );

        if ($status !== 0) {
            throw new \RuntimeException(
                'Worker '
                . $item['worker']
                . ' falhou: '
                . trim((string) $stderr)
            );
        }
    }

    $afterConcurrency = (
        new ContextStore(
            $directory,
            'America/Manaus'
        )
    )->load($concurrentSession);

    $concurrencyState =
        $afterConcurrency['state']['concurrency']
        ?? [];

    $totalItems = 0;

    for ($worker = 1; $worker <= $workers; $worker++) {
        $workerItems =
            $concurrencyState[
                'worker_' . $worker
            ]
            ?? [];

        checkAtomicV05(
            count($workerItems) === $iterations,
            "worker {$worker} preservou todas as mutações"
        );

        $totalItems += count($workerItems);
    }

    checkAtomicV05(
        $totalItems === $workers * $iterations,
        'mutações concorrentes não sofreram lost update'
    );

    $concurrentRaw = file_get_contents(
        contextFileV05(
            $directory,
            $concurrentSession
        )
    );

    checkAtomicV05(
        is_array(
            json_decode(
                (string) $concurrentRaw,
                true,
                512,
                JSON_THROW_ON_ERROR
            )
        ),
        'contexto concorrente final é JSON válido'
    );

    echo "\n6. DELETE SOB LOCK\n";

    $deleteSession = 'delete-lock';

    $store->mergeState(
        $deleteSession,
        ['exists' => true]
    );

    $deleteFile = contextFileV05(
        $directory,
        $deleteSession
    );

    checkAtomicV05(
        is_file($deleteFile),
        'contexto a apagar existe'
    );

    $store->delete($deleteSession);

    checkAtomicV05(
        !is_file($deleteFile),
        'delete remove contexto final sob lock de sessão'
    );

    $afterDelete = $store->load(
        $deleteSession
    );

    checkAtomicV05(
        ($afterDelete['schema_version'] ?? null) === 1
        && ($afterDelete['state'] ?? []) === [],
        'load após delete retorna contexto novo normalizado'
    );

    echo "\n7. IDEMPOTÊNCIA APÓS RESTART\n";

    $restartSession = 'restart-idempotency';
    $restartArguments = [
        'alpha' => 1,
        'beta' => 'VALUE',
    ];

    $result = [
        'success' => true,
        'data' => [
            'id' => 999,
        ],
    ];

    $firstProcessStore = new ContextStore(
        $directory,
        'America/Manaus'
    );

    $fingerprintBefore =
        $firstProcessStore->toolFingerprint(
            $restartSession,
            'WriteProbe',
            $restartArguments
        );

    $firstProcessStore->addToolExecution(
        $restartSession,
        'WriteProbe',
        $restartArguments,
        $result
    );

    unset($firstProcessStore);

    $secondProcessStore = new ContextStore(
        $directory,
        'America/Manaus'
    );

    $fingerprintAfter =
        $secondProcessStore->toolFingerprint(
            $restartSession,
            'WriteProbe',
            [
                'beta' => ' value ',
                'alpha' => 1,
            ]
        );

    checkAtomicV05(
        $fingerprintBefore === $fingerprintAfter,
        'fingerprint idempotente permanece estável após nova instância'
    );

    $replayed =
        $secondProcessStore
            ->findSuccessfulToolResult(
                $restartSession,
                'WriteProbe',
                [
                    'beta' => ' value ',
                    'alpha' => 1,
                ]
            );

    checkAtomicV05(
        $replayed === $result,
        'tool_history persistido permite replay após restart'
    );

    $historyCount = count(
        $secondProcessStore
            ->load($restartSession)[
                'tool_history'
            ]
            ?? []
    );

    checkAtomicV05(
        $historyCount === 1,
        'restart não duplica histórico persistido'
    );

    echo "\n8. FRONTEIRAS ESTÁTICAS\n";

    $source = file_get_contents(
        dirname(__DIR__)
        . '/src/ContextStore.php'
    );

    checkAtomicV05(
        str_contains(
            (string) $source,
            'flock('
        ),
        'ContextStore usa flock'
    );

    checkAtomicV05(
        str_contains(
            (string) $source,
            "'.lock'"
        ),
        'ContextStore usa arquivo de lock separado'
    );

    checkAtomicV05(
        str_contains(
            (string) $source,
            "'.tmp.'"
        ),
        'ContextStore grava por temp file'
    );

    checkAtomicV05(
        str_contains(
            (string) $source,
            'rename('
        ),
        'ContextStore publica por rename atômico'
    );

    checkAtomicV05(
        str_contains(
            (string) $source,
            'SCHEMA_VERSION = 1'
        ),
        'schema do contexto é explicitamente versionado'
    );

    echo "\nOK: GUICHÊ v0.5.0 / FASE 03 validada.\n";
} finally {
    removeTreeV05($directory);
}
