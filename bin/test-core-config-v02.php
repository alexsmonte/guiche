<?php

declare(strict_types=1);

function checkCoreConfigV02(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

$root = dirname(__DIR__);

$core = require $root . '/config/guiche.php';
$pilot = require $root . '/config/pilot-clinical.php';
$prompt = require $root . '/config/pilot-clinical-prompt.php';

checkCoreConfigV02(
    isset(
        $core['timezone'],
        $core['context_path'],
        $core['ai'],
        $core['history_messages_limit'],
        $core['debug']
    ),
    'Core mantém somente infraestrutura/configuração genérica necessária'
);

foreach (
    [
        'database',
        'agendamento_api',
        'procedures',
        'horarios_por_data',
        'agent_context',
        'reusable_tools',
        'tool_policy',
        'completion_guards',
        'ai_state_exclude_paths',
        'ai_tool_result_exclude_paths',
    ]
    as $pilotKey
) {
    checkCoreConfigV02(
        !array_key_exists($pilotKey, $core),
        "Core não contém configuração de domínio: {$pilotKey}"
    );

    checkCoreConfigV02(
        array_key_exists($pilotKey, $pilot),
        "Piloto contém configuração de domínio: {$pilotKey}"
    );
}

$coreFile = file_get_contents($root . '/config/guiche.php');

checkCoreConfigV02(
    is_string($coreFile),
    'arquivo de configuração do Core pode ser inspecionado'
);

foreach (
    [
        'Ortoshine',
        'clinsoft.atendamais.com.br',
        'profissionais_por_tratamento',
        'DisponibilidadeConvenios',
        'ConsultarPaciente',
        'ConsultarProfissional',
        'ConsultarHorarios',
        'CriarAgendamento',
        'aguardando_convenio',
        'aguardando_profissional',
        'aguardando_cpf',
        'pronto_para_agendar',
    ]
    as $domainMarker
) {
    checkCoreConfigV02(
        !str_contains((string)$coreFile, $domainMarker),
        "Core não conhece marcador clínico: {$domainMarker}"
    );
}

checkCoreConfigV02(
    ($pilot['agent_context']['empresa_id'] ?? null) === 142
        && ($pilot['agent_context']['empresa_nome'] ?? null) === 'Ortoshine',
    'dados atuais do piloto foram preservados'
);

checkCoreConfigV02(
    ($pilot['procedures']['disponibilidade_convenios'] ?? null)
        === 'profissionais_por_tratamento',
    'procedure atual do piloto foi preservada'
);

checkCoreConfigV02(
    ($pilot['agendamento_api']['url'] ?? null)
        === 'https://clinsoft.atendamais.com.br/api/agendamentos/novo.json',
    'endpoint atual do piloto foi preservado'
);

checkCoreConfigV02(
    is_string($prompt)
        && str_contains($prompt, 'Meg')
        && str_contains($prompt, 'Ortoshine'),
    'prompt clínico foi preservado e identificado como piloto'
);

checkCoreConfigV02(
    !is_file($root . '/config/prompt.php'),
    'nome genérico config/prompt.php não permanece mascarando domínio'
);

echo PHP_EOL;
echo "OK: fronteira de configuração Core/Piloto da v0.2 validada." . PHP_EOL;
