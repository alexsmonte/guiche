<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Example\Basic\BasicPlugin;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Providers\OpenAiResponsesProvider;
use Dotenv\Dotenv;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';
require_once __DIR__ . '/src/CatalogTool.php';
require_once __DIR__ . '/src/BasicPlugin.php';

Dotenv::createImmutable($root)->safeLoad();

$env = static function (string $name, mixed $default = null): mixed {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

    if ($value === null) {
        $processValue = getenv($name);
        $value = $processValue !== false ? $processValue : null;
    }

    return $value === null || $value === '' ? $default : $value;
};

$apiKey = trim((string)$env(
    'AI_API_KEY',
    $env('OPENAI_API_KEY', '')
));

if ($apiKey === '') {
    fwrite(
        STDERR,
        "Defina AI_API_KEY no arquivo .env antes de executar o exemplo.\n"
    );
    exit(1);
}

$model = trim((string)$env(
    'AI_MODEL',
    $env('OPENAI_MODEL', 'gpt-5.6-luna')
));

$sessionId = trim((string)$env(
    'GUICHE_SESSION_ID',
    'basic-cli'
));

$storage = $root . '/storage/examples/basic/conversations';

$context = new ContextStore($storage);
$ai = new OpenAiResponsesProvider(
    $apiKey,
    $model !== '' ? $model : 'gpt-5.6-luna'
);

$builder = new GuicheBuilder();
$builder->withContextStore($context);
$builder->withAiProvider($ai);
$builder->registerPlugin(new BasicPlugin());

$guiche = $builder->build();

$printJson = static function (mixed $value): void {
    echo json_encode(
        $value,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
};

$respond = static function (string $message) use (
    $guiche,
    $sessionId
): void {
    $result = $guiche->handle($sessionId, $message);
    echo 'Guichê: ' . (string)($result['message'] ?? '') . PHP_EOL;
};

if ($argc > 1) {
    $message = trim(implode(' ', array_slice($argv, 1)));

    if ($message === '') {
        fwrite(STDERR, "Informe uma mensagem.\n");
        exit(1);
    }

    $respond($message);
    exit(0);
}

echo "Guichê — exemplo básico\n";
echo "Modelo: {$model}\n";
echo "Comandos: /context, /usage, /tools, /reset, /sair\n\n";

while (true) {
    $line = readline('Você: ');

    if ($line === false) {
        echo PHP_EOL;
        break;
    }

    $line = trim($line);

    if ($line === '') {
        continue;
    }

    if (in_array($line, ['/sair', '/exit', '/quit'], true)) {
        break;
    }

    if ($line === '/context') {
        $printJson($guiche->context($sessionId));
        continue;
    }

    if ($line === '/usage') {
        $contextData = $guiche->context($sessionId);
        $printJson($contextData['usage'] ?? []);
        continue;
    }

    if ($line === '/tools') {
        $printJson($guiche->tools());
        continue;
    }

    if ($line === '/reset') {
        $guiche->reset($sessionId);
        echo "Guichê: conversa reiniciada." . PHP_EOL;
        continue;
    }

    try {
        $respond($line);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Erro: ' . $exception->getMessage() . PHP_EOL);
    }
}
