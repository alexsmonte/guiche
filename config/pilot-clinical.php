<?php

declare(strict_types=1);

/**
 * Configuração do piloto clínico.
 *
 * Tudo que conhece o domínio de agendamento atual fica aqui
 * enquanto a v0.2 extrai o Core. A arquitetura formal de plugins
 * pertence à fase v0.3 e não é criada neste passo.
 */
return [
    'database' => [
        'driver' => getenv('DB_DRIVER') ?: 'mysql',
        'dsn' => getenv('DB_DSN') ?: '',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: '',
        'username' => getenv('DB_USERNAME') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],

    'agendamento_api' => [
        'url' => getenv('GUICHE_AGENDAMENTO_API_URL')
            ?: 'https://clinsoft.atendamais.com.br/api/agendamentos/novo.json',
        'token' => getenv('GUICHE_AGENDAMENTO_API_TOKEN') ?: '',
        'timeout' => (int)(getenv('GUICHE_AGENDAMENTO_API_TIMEOUT') ?: 15),
    ],

    'procedures' => [
        'consultar_paciente' =>
            getenv('DB_PROCEDURE_CONSULTAR_PACIENTE') ?: '',

        'disponibilidade_convenios' =>
            getenv('DB_PROCEDURE_DISPONIBILIDADE')
            ?: 'profissionais_por_tratamento',

        'consultar_profissional' =>
            getenv('DB_PROCEDURE_CONSULTAR_PROFISSIONAL')
            ?: 'sp_consulta_profissional',

        'consultar_horarios' =>
            getenv('DB_PROCEDURE_CONSULTAR_HORARIOS')
            ?: 'horarios_disponiveis_por_turno',
    ],

    'horarios_por_data' => (int) (
        getenv('GUICHE_HORARIOS_POR_DATA')
        ?: 3
    ),

    /*
     * São dados operacionais do piloto.
     * Continuam no contexto, mas são omitidos da cópia enviada à IA.
     */
    'ai_state_exclude_paths' => [
        'horarios_disponiveis',
        'horarios_apresentados',
        'horarios_por_data',
        'horarios_paginacao.datas_encontradas',
    ],

    /*
     * O resultado completo permanece no histórico local.
     * Estes campos são removidos somente da cópia enviada à IA.
     */
    'ai_tool_result_exclude_paths' => [
        'ConsultarPaciente' => [
            'input',
            'meta.raw_rows',
            'meta.procedure_parameters',
        ],

        'DisponibilidadeConvenios' => [
            'input',
            'meta',
        ],

        'ConsultarProfissional' => [
            'input',
            'meta',
        ],

        'ConsultarHorarios' => [
            'input',
            'meta.source',
            'meta.procedure',
            'meta.turno',
            'meta.datas_encontradas',
            'meta.linhas_brutas',
        ],
    ],

    'agent_context' => [
        'empresa_id' => 142,
        'empresa_nome' => 'Ortoshine',
    ],

    'reusable_tools' => [
        'DisponibilidadeConvenios',
    ],

    'tool_policy' => [
        'context_path' => 'state.etapa',

        'bootstrap' => [
            'DisponibilidadeConvenios',
        ],

        'default' => [],

        'stages' => [
            'aguardando_convenio' => [
                'DisponibilidadeConvenios',
            ],

            'aguardando_procedimento' => [
                'DisponibilidadeConvenios',
            ],

            'consultando_profissionais' => [
                'DisponibilidadeConvenios',
            ],

            'aguardando_profissional' => [
                'ConsultarProfissional',
            ],

            'aguardando_data' => [
                'ConsultarHorarios',
            ],

            'consultando_horarios' => [
                'ConsultarHorarios',
            ],

            'aguardando_horario' => [
                'ConsultarHorarios',
            ],

            'aguardando_data_horario' => [
                'ConsultarHorarios',
            ],

            'aguardando_cpf' => [
                'ConsultarPaciente',
            ],

            'aguardando_decisao_agendamento_existente' => [],

            'aguardando_confirmacao' => [],

            'pronto_para_agendar' => [
                'CriarAgendamento',
            ],

            'agendado' => [],
        ],
    ],

    'completion_guards' => [
        [
            'state_key' => 'etapa',
            'state_value' => 'pronto_para_agendar',
            'required_tool' => 'CriarAgendamento',
            'fallback_message' =>
                'O fluxo chegou à etapa de efetivação do agendamento, '
                . 'mas a ferramenta de gravação ainda não está disponível neste piloto.',
        ],
    ],
];
