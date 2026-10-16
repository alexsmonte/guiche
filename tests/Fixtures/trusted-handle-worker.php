<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$contextDirectory = $argv[1] ?? '';
$sessionId = $argv[2] ?? '';
$message = $argv[3] ?? '';

final class TrustedHandleWorkerAi implements AiProviderInterface
{
    /**
     * @param array<mixed> $messages
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        return [
            'id' => 'trusted-handle-worker',
            'text' => 'HANDLE-OK',
            'tool_calls' => [],
            'usage' => [],
        ];
    }

    /**
     * @param array<mixed> $toolOutputs
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        return [
            'id' => 'trusted-handle-worker-cont',
            'text' => 'HANDLE-OK',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}

try {
    $guiche = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $contextDirectory,
            'UTC'
        ),
        ai: new TrustedHandleWorkerAi()
    );

    $response = $guiche->handle(
        $sessionId,
        $message
    );

    echo (string) ($response['message'] ?? '');
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        $exception::class
        . ': '
        . $exception->getMessage()
    );

    exit(2);
}
