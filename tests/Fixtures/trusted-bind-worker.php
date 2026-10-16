<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$contextDirectory = $argv[1] ?? '';
$sessionId = $argv[2] ?? '';
$identityJson = $argv[3] ?? '';
$tenantId = $argv[4] ?? '';
$enteredFile = $argv[5] ?? '-';
$releaseFile = $argv[6] ?? '-';

try {
    $identity = json_decode(
        $identityJson,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($identity)) {
        throw new RuntimeException(
            'Identity do worker precisa ser array.'
        );
    }

    $resolver = new CallbackTenantResolver(
        static function (array $trustedIdentity) use (
            $tenantId,
            $enteredFile,
            $releaseFile
        ): array {
            if ($enteredFile !== '-') {
                if (file_put_contents(
                    $enteredFile,
                    'entered'
                ) === false) {
                    throw new RuntimeException(
                        'Não foi possível sinalizar entrada no resolver.'
                    );
                }
            }

            if ($releaseFile !== '-') {
                $deadline = microtime(true) + 8.0;

                while (!is_file($releaseFile)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException(
                            'Timeout aguardando liberação do resolver.'
                        );
                    }

                    usleep(10_000);
                }
            }

            return [
                'tenant_id' => $tenantId,
            ];
        }
    );

    $guiche = new Guiche(
        tools: new ToolRegistry(),
        context: new ContextStore(
            $contextDirectory,
            'UTC'
        ),
        tenantResolver: $resolver
    );

    $context = $guiche->bindIdentity(
        $sessionId,
        $identity
    );

    echo json_encode(
        [
            'success' => true,
            'tenant_id' => $context->tenantId(),
            'identity' => $context->get('identity'),
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        $exception::class
        . ': '
        . $exception->getMessage()
    );

    exit(2);
}
