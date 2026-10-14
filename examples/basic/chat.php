<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Example\Basic\BasicPlugin;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Providers\OpenAiResponsesProvider;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';
require_once __DIR__ . '/src/CatalogTool.php';
require_once __DIR__ . '/src/BasicPlugin.php';

$apiKey = trim((string) getenv('OPENAI_API_KEY'));

if ($apiKey === '') {
    fwrite(STDERR, "Defina OPENAI_API_KEY antes de executar o exemplo.\n");
    exit(1);
}

$model = trim((string) (getenv('OPENAI_MODEL') ?: 'gpt-5.6-luna'));
$sessionId = trim((string) (getenv('GUICHE_SESSION_ID') ?: 'basic-cli'));
$storage = $root . '/storage/examples/basic/conversations';

$context = new ContextStore($storage);
$ai = new OpenAiResponsesProvider($apiKey, $model);

$builder = new GuicheBuilder();
$builder->withContextStore($context);
$builder->withAiProvider($ai);
$builder->registerPlugin(new BasicPlugin());

$guiche = $builder->build();

$respond = static function (string $message) use ($guiche, $sessionId): void {
    $result = $guiche->handle($sessionId, $message);
    echo 'Guichê: ' . (string) ($result['message'] ?? '') . PHP_EOL;
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
echo "Comandos: /reset, /sair\n\n";

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
