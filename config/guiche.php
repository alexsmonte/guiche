<?php

declare(strict_types=1);

/**
 * Configuração genérica do Core do Guichê.
 *
 * Este arquivo não deve conhecer clínica, paciente, convênio,
 * profissional, agendamento, procedures ou Tools de um domínio.
 */
return [
    'timezone' => getenv('GUICHE_TIMEZONE') ?: 'America/Sao_Paulo',

    'context_path' => dirname(__DIR__) . '/storage/conversations',

    'ai' => [
        'provider' => 'openai',
        'api_key' => getenv('OPENAI_API_KEY') ?: '',
        'model' => getenv('OPENAI_MODEL') ?: 'gpt-5.6-luna',
    ],

    'history_messages_limit' => (int) (
        getenv('GUICHE_HISTORY_MESSAGES_LIMIT')
        ?: 8
    ),

    'debug' => filter_var(
        getenv('GUICHE_DEBUG') ?: 'true',
        FILTER_VALIDATE_BOOL
    ),
];
