<?php

declare(strict_types=1);

/**
 * Configuração genérica do Core do Guichê.
 *
 * Este arquivo não deve conhecer clínica, paciente, convênio,
 * profissional, agendamento, procedures ou Tools de um domínio.
 */

$env = static function (string $name, mixed $default = null): mixed {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

    if ($value === null) {
        $processValue = getenv($name);
        $value = $processValue !== false ? $processValue : null;
    }

    if ($value === null || $value === '') {
        return $default;
    }

    return $value;
};

$apiKey = $env(
    'AI_API_KEY',
    $env('OPENAI_API_KEY', '')
);

$model = $env(
    'AI_MODEL',
    $env('OPENAI_MODEL', 'gpt-5.6-luna')
);

return [
    'timezone' => (string)$env(
        'GUICHE_TIMEZONE',
        'America/Sao_Paulo'
    ),

    'context_path' => dirname(__DIR__) . '/storage/conversations',

    'ai' => [
        'provider' => 'openai',
        'api_key' => (string)$apiKey,
        'model' => (string)$model,
    ],

    'history_messages_limit' => (int)$env(
        'GUICHE_HISTORY_MESSAGES_LIMIT',
        8
    ),

    'debug' => filter_var(
        (string)$env('GUICHE_DEBUG', 'true'),
        FILTER_VALIDATE_BOOL
    ),
];
