<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use Asmpkg\Guiche\Tenant\TenantResolutionService;

require dirname(__DIR__) . '/vendor/autoload.php';

function v11Pass(string $message): void
{
    echo "OK: {$message}\n";
}

function v11Fail(string $message): never
{
    fwrite(STDERR, "ERRO: {$message}\n");
    exit(1);
}

function v11Assert(bool $condition, string $message): void
{
    if (!$condition) {
        v11Fail($message);
    }

    v11Pass($message);
}

echo "\nGUICHÊ v1.1.0 — FASE 06 / MULTITENANT ISOLATION\n";
echo str_repeat('=', 59) . "\n\n";

$root = dirname(__DIR__);
$directory = sys_get_temp_dir()
    . '/guiche-v11-binding-race-'
    . bin2hex(random_bytes(6));

$store = new ContextStore(
    $directory,
    'America/Manaus'
);

$worker = sys_get_temp_dir()
    . '/guiche-v11-binding-worker-'
    . bin2hex(random_bytes(6))
    . '.php';

$workerCode = <<<'PHP_WORKER'
<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;

require $argv[1] . '/vendor/autoload.php';

$store = new ContextStore(
    $argv[2],
    'America/Manaus'
);

$seeder = new ExecutionContextSeeder($store);

try {
    $seeder->seed(
        'race-session',
        [
            'identity' => [
                'provider' => 'race',
                'tenant_key' => $argv[3],
            ],
        ]
    );

    echo "BOUND";
    exit(0);
} catch (Throwable $exception) {
    echo get_class($exception);
    exit(3);
}
PHP_WORKER;

file_put_contents($worker, $workerCode);

echo "1. CONCURRENT IDENTITY BINDING\n";

$processes = [];

for ($index = 0; $index < 8; $index++) {
    $key = $index % 2 === 0
        ? 'clinic-a'
        : 'clinic-b';

    $pipes = [];

    $process = proc_open(
        [
            PHP_BINARY,
            $worker,
            $root,
            $directory,
            $key,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        @unlink($worker);
        v11Fail('não foi possível iniciar worker concorrente.');
    }

    fclose($pipes[0]);

    $processes[] = [
        'process' => $process,
        'stdout' => $pipes[1],
        'stderr' => $pipes[2],
        'key' => $key,
    ];
}

$successes = 0;
$failures = 0;

foreach ($processes as $entry) {
    $stdout = stream_get_contents($entry['stdout']);
    $stderr = stream_get_contents($entry['stderr']);

    fclose($entry['stdout']);
    fclose($entry['stderr']);

    $status = proc_close($entry['process']);

    if ($status === 0 && trim($stdout) === 'BOUND') {
        $successes++;
        continue;
    }

    if ($status === 3) {
        $failures++;
        continue;
    }

    @unlink($worker);

    v11Fail(
        'worker terminou em estado inesperado: '
        . $entry['key']
        . ' status=' . $status
        . ' stdout=' . trim($stdout)
        . ' stderr=' . trim($stderr)
    );
}

@unlink($worker);

v11Assert(
    $successes >= 1,
    'pelo menos um worker conseguiu vincular a identity'
);

v11Assert(
    $successes + $failures === 8,
    'todos os workers terminaram de forma controlada'
);

$context = $store->load('race-session');
$identity = $context['execution_context']['identity'] ?? null;

v11Assert(
    is_array($identity),
    'binding concorrente final possui identity íntegra'
);

$key = trim((string)($identity['tenant_key'] ?? ''));

v11Assert(
    in_array($key, ['clinic-a', 'clinic-b'], true),
    'identity final pertence a exatamente um tenant conhecido'
);

echo "\n2. TENANT RESOLUTION APÓS A CORRIDA\n";

$service = new TenantResolutionService(
    $store,
    new CallbackTenantResolver(
        static function (array $identity): array {
            return match (
                (string)($identity['tenant_key'] ?? '')
            ) {
                'clinic-a' => ['tenant_id' => 101],
                'clinic-b' => ['tenant_id' => 202],
                default => [],
            };
        }
    )
);

$resolved = $service->resolve('race-session');

$expectedTenant = $key === 'clinic-a'
    ? 101
    : 202;

v11Assert(
    $resolved->tenantId() === $expectedTenant,
    'tenant_id resolvido corresponde à identity vencedora'
);

$persisted = $store->load('race-session');

v11Assert(
    ($persisted['execution_context']['tenant_id'] ?? null)
        === $expectedTenant,
    'tenant_id correto foi persistido na mesma sessão'
);

echo "\n3. IDENTITY SWAP APÓS BINDING\n";

$opposite = $key === 'clinic-a'
    ? 'clinic-b'
    : 'clinic-a';

$seeder = new ExecutionContextSeeder($store);
$identitySwapBlocked = false;

try {
    $seeder->seed(
        'race-session',
        [
            'identity' => [
                'provider' => 'race',
                'tenant_key' => $opposite,
            ],
        ]
    );
} catch (Throwable) {
    $identitySwapBlocked = true;
}

v11Assert(
    $identitySwapBlocked,
    'identity oposta não substitui binding vencedor'
);

echo "\n4. TENANT SWAP APÓS RESOLUTION\n";

$tenantSwapBlocked = false;

try {
    $seeder->seed(
        'race-session',
        [
            'identity' => [
                'provider' => 'race',
                'tenant_key' => $key,
            ],
            'tenant_id' => $expectedTenant === 101
                ? 202
                : 101,
        ]
    );
} catch (Throwable) {
    $tenantSwapBlocked = true;
}

v11Assert(
    $tenantSwapBlocked,
    'tenant_id persistido não pode ser trocado'
);

$final = $store->load('race-session');

v11Assert(
    ($final['execution_context']['identity']['tenant_key'] ?? null)
        === $key,
    'identity final permaneceu imutável'
);

v11Assert(
    ($final['execution_context']['tenant_id'] ?? null)
        === $expectedTenant,
    'tenant final permaneceu consistente'
);

echo "\nOK: GUICHÊ v1.1.0 / isolamento concorrente validado.\n";
