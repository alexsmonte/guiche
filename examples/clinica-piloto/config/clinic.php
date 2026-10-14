<?php

declare(strict_types=1);

$clinicalMode = strtolower(trim((string)(getenv('GUICHE_CLINICAL_MODE') ?: 'fake')));

if (!in_array($clinicalMode, ['fake', 'real'], true)) {
    throw new \RuntimeException(
        'GUICHE_CLINICAL_MODE deve ser "fake" ou "real".'
    );
}

$clinicalCompanyId = (int)(getenv('GUICHE_CLINIC_COMPANY_ID') ?: 1);
$clinicalCompanyName = trim((string)(getenv('GUICHE_CLINIC_NAME') ?: 'Clínica Exemplo'));
$clinicalFixture = trim((string)(getenv('GUICHE_FAKE_FIXTURE') ?: ''));


/**
 * Configuração do piloto clínico.
 *
 * Tudo que conhece o domínio de agendamento atual fica aqui
 * enquanto a v0.2 extrai o Core. A arquitetura formal de plugins
 * pertence à fase v0.3 e não é criada neste passo.
 */
return [
    'write_safety' => [
        'slot_revalidation' => true,
    ],
    'mode' => $clinicalMode,
    'fake' => [
        'fixture' => $clinicalFixture !== ''
            ? $clinicalFixture
            : dirname(__DIR__) . '/fixtures/clinical.php',
    ],
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
            ?: ($clinicalMode === 'fake' ? 'https://example.invalid/api/agendamentos/novo.json' : ''),
        'token' => getenv('GUICHE_AGENDAMENTO_API_TOKEN') ?: '',
        'timeout' => (int)(getenv('GUICHE_AGENDAMENTO_API_TIMEOUT') ?: 15),
    ],

    'procedures' => [
        'consultar_paciente' =>
            getenv('DB_PROCEDURE_CONSULTAR_PACIENTE') ?: ($clinicalMode === 'fake' ? 'fake_consultar_paciente' : ''),

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

        'CriarAgendamento' => [
            'input',
            'meta',
        ],
    ],

    /*
     * Whitelist explícita para a Tool WRITE.
     * O payload HTTP completo continua no histórico interno,
     * mas a IA recebe somente dados necessários para confirmar
     * a efetivação.
     */
    'ai_tool_result_include_paths' => [
        'CriarAgendamento' => [
            'success',
            'tool',
            'data.agendamento_id',
            'data.paciente_id',
            'data.data_inicio',
            'data.hora_inicio',
            'data.profissional_id',
            'data.servico_id',
            'data.titulo',
            'error',
        ],
    ],

    'agent_context' => [
        'empresa_id' => $clinicalCompanyId,
        'empresa_nome' => $clinicalCompanyName,
    ],

    'reusable_tools' => [
        'DisponibilidadeConvenios',
    ],

    /*
     * Safety Layer v0.4.
     *
     * ToolPolicy controla visibilidade/autorização por etapa.
     * Estes metadados adicionam preconditions independentes
     * e são revalidados imediatamente antes do execute().
     */
    'tool_safety' => [
        'DisponibilidadeConvenios' => [
            'stage_path' => 'state.etapa',
        ],

        'ConsultarProfissional' => [
            'stage_path' => 'state.etapa',
            'allowed_stages' => [
                'aguardando_profissional',
            ],
            'requires_state' => [
                ['path' => 'state.convenio.nome'],
                ['path' => 'state.procedimento.nome'],
            ],
        ],

        'ConsultarHorarios' => [
            'stage_path' => 'state.etapa',
            'allowed_stages' => [
                'aguardando_data',
                'consultando_horarios',
                'aguardando_horario',
                'aguardando_data_horario',
            ],
            'requires_state' => [
                ['path' => 'state.profissional.id'],
            ],
        ],

        'ConsultarPaciente' => [
            'stage_path' => 'state.etapa',
            'allowed_stages' => [
                'aguardando_cpf',
            ],
            'requires_state' => [
                ['path' => 'state.cpf'],
            ],
        ],

        'CriarAgendamento' => [
            'stage_path' => 'state.etapa',
            'allowed_stages' => [
                'pronto_para_agendar',
            ],
            'requires_state' => [
                [
                    'path' => 'state.confirmado_pelo_usuario',
                    'value' => true,
                ],
                [
                    'path' => 'state.paciente_validado',
                    'value' => true,
                ],
                ['path' => 'state.paciente_id'],
                ['path' => 'state.profissional.id'],
                ['path' => 'state.data_agendamento'],
                ['path' => 'state.horario'],
            ],
            'forbids_state' => [
                ['path' => 'state.agendamento_id'],
            ],
            'confirmation' => [
                'path' => 'state.confirmado_pelo_usuario',
                'value' => true,
            ],
        ],
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
