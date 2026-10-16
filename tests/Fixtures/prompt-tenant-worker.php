<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;

if ($argc !== 6) {
    fwrite(
        STDERR,
        "Uso: worker <context-dir> <session> <external-id> <result-file> <marker>\n"
    );
    exit(2);
}

[, $contextDir, $sessionId, $externalId, $resultFile, $marker] = $argv;

$provider = new class implements AiProviderInterface {
    public string $instructions = '';

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->instructions = $instructions;

        return [
            'id' => 'worker-response',
            'text' => 'ok',
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
        $this->instructions = $instructions;

        return [
            'id' => 'worker-tool-response',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
};

try {
    $guiche = (new GuicheBuilder())
        ->withContextStore(
            new ContextStore($contextDir)
        )
        ->withAiProvider($provider)
        ->withTenantResolver(
            new CallbackTenantResolver(
                static function (
                    array $identity
                ): array {
                    return [
                        'tenant_id' =>
                            'tenant-'
                            . (string)$identity['external_id'],
                    ];
                }
            )
        )
        ->withPromptResolver(
            static function (
                PromptContext $context
            ): string {
                return 'PROMPT ISOLADO '
                    . (string)$context->tenantId();
            }
        )
        ->build();

    $guiche->bindIdentity(
        $sessionId,
        [
            'provider' => 'worker',
            'external_id' => $externalId,
        ]
    );

    $guiche->handle(
        $sessionId,
        'mensagem concorrente ' . $marker
    );

    file_put_contents(
        $resultFile,
        json_encode(
            [
                'ok' => true,
                'session_id' => $sessionId,
                'external_id' => $externalId,
                'instructions' =>
                    $provider->instructions,
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        )
    );
} catch (Throwable $exception) {
    file_put_contents(
        $resultFile,
        json_encode(
            [
                'ok' => false,
                'exception' => $exception::class,
            ],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        )
    );

    exit(1);
}
