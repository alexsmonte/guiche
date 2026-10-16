<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$contextDirectory = (string) ($argv[1] ?? '');
$sessionId = (string) ($argv[2] ?? '');
$message = (string) ($argv[3] ?? '');

if ($contextDirectory === '' || $sessionId === '' || $message === '') {
    fwrite(STDERR, "Argumentos inválidos.\n");
    exit(2);
}

$provider = new class implements AiProviderInterface {
    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $lastMessage = '';

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? null) !== 'user') {
                continue;
            }

            $lastMessage = (string) (
                $messages[$index]['content'] ?? ''
            );
            break;
        }

        /*
         * Mantém o primeiro processo dentro do turno tempo suficiente
         * para o segundo tentar entrar na mesma sessão.
         */
        usleep(500_000);

        $text = str_contains(
            $lastMessage,
            'CONCORRENTE-A-731'
        )
            ? 'A-OK'
            : 'B-OK';

        return [
            'id' => 'response-' . hash('sha256', $lastMessage),
            'text' => $text,
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
        throw new RuntimeException('Continuação inesperada.');
    }
};

$guiche = new Guiche(
    tools: new ToolRegistry(),
    context: new ContextStore($contextDirectory, 'UTC'),
    ai: $provider
);

$result = $guiche->handle($sessionId, $message);
fwrite(STDOUT, (string) ($result['message'] ?? ''));
