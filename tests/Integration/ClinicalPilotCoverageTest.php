<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\GuicheBuilder;
use GuicheExample\ClinicaPiloto\ClinicPlugin;
use GuicheExample\ClinicaPiloto\ClinicStateManager;
use GuicheExample\ClinicaPiloto\Presentation\ConsultarHorariosPresenter;
use GuicheExample\ClinicaPiloto\Presentation\DisponibilidadeConveniosPresenter;
use GuicheExample\ClinicaPiloto\Tools\ConsultarHorariosTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarPacienteTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarProfissionalTool;
use GuicheExample\ClinicaPiloto\Tools\CriarAgendamentoTool;
use GuicheExample\ClinicaPiloto\Tools\DisponibilidadeConveniosTool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoverageQueueSqlExecutor implements SqlExecutorInterface
{
    /** @var list<array<mixed>> */
    public array $responses = [];

    /** @var list<array{sql:string, parameters:array<mixed>}> */
    public array $calls = [];

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->calls[] = [
            'sql' => $sql,
            'parameters' => $parameters,
        ];

        return array_shift($this->responses) ?? [];
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->calls[] = [
            'sql' => $sql,
            'parameters' => $parameters,
        ];

        return 1;
    }
}

final class CoverageQueueHttpClient implements HttpClientInterface
{
    /** @var list<array<mixed>|\Throwable> */
    public array $responses = [];

    /** @var list<array<mixed>> */
    public array $calls = [];

    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        $this->calls[] = [
            'url' => $url,
            'data' => $data,
            'headers' => $headers,
            'timeout' => $timeout,
        ];

        $response = array_shift($this->responses);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return is_array($response)
            ? $response
            : [
                'status' => 200,
                'body' => '{}',
                'json' => [],
            ];
    }
}

final class ClinicalPilotCoverageTest extends TestCase
{
    public function testPluginRegistersClinicalCapabilities(): void
    {
        $sql = new CoverageQueueSqlExecutor();
        $http = new CoverageQueueHttpClient();

        $config = require dirname(__DIR__, 2)
            . '/examples/clinica-piloto/config/clinic.php';

        $config['procedures']['consultar_paciente'] =
            'consultar_paciente_por_cpf';

        $config['agendamento_api'] = [
            'url' => 'https://example.invalid/agendamentos',
            'token' => 'token-teste',
            'timeout' => 5,
        ];

        $builder = new GuicheBuilder();
        $builder->registerPlugin(
            new ClinicPlugin(
                $sql,
                $config,
                'INSTRUÇÕES CLÍNICAS',
                $http
            )
        );

        self::assertSame(
            [
                'DisponibilidadeConvenios',
                'ConsultarProfissional',
                'ConsultarHorarios',
                'ConsultarPaciente',
                'CriarAgendamento',
            ],
            $builder->toolRegistry()->names()
        );

        self::assertInstanceOf(
            ClinicStateManager::class,
            $builder->stateManager()
        );

        self::assertInstanceOf(
            DisponibilidadeConveniosPresenter::class,
            $builder->presenterRegistry()->get(
                'DisponibilidadeConvenios'
            )
        );

        self::assertInstanceOf(
            ConsultarHorariosPresenter::class,
            $builder->presenterRegistry()->get(
                'ConsultarHorarios'
            )
        );

        self::assertNotNull($builder->toolPolicy());
        self::assertNotEmpty($builder->completionGuards());
        self::assertNotEmpty($builder->resultProjections());

        self::assertSame(
            'write',
            $builder->toolRegistry()
                ->metadata('CriarAgendamento')['operation']
        );
    }

    public function testStateManagerCoversMainClinicalTransitions(): void
    {
        $manager = new ClinicStateManager();

        self::assertSame(
            [],
            $manager->onToolResult(
                [],
                'Qualquer',
                [],
                ['success' => false]
            )
        );

        self::assertSame(
            [],
            $manager->onToolResult(
                [],
                'ToolDesconhecida',
                [],
                ['success' => true]
            )
        );

        $availability = $manager->onToolResult(
            ['state' => []],
            'DisponibilidadeConvenios',
            [
                'tratamento_nome' => 'Limpeza',
                'convenio_nome' => 'Dental Plus',
            ],
            [
                'success' => true,
                'data' => [
                    ['nome' => 'Dra. Larissa'],
                ],
            ]
        );

        self::assertSame(
            'aguardando_profissional',
            $availability['state']['etapa']
        );
        self::assertSame(
            'Limpeza',
            $availability['state']['procedimento']['nome']
        );

        $availabilityChanged = $manager->onToolResult(
            [
                'state' => [
                    'procedimento' => ['nome' => 'Canal'],
                    'convenio' => ['nome' => 'Particular'],
                    'profissional' => ['id' => 99],
                    'horario' => '10:00',
                ],
            ],
            'DisponibilidadeConvenios',
            [
                'tratamento_nome' => 'Limpeza',
                'convenio_nome' => 'Dental Plus',
            ],
            [
                'success' => true,
                'data' => [
                    ['nome' => 'Dra. Larissa'],
                ],
            ]
        );

        self::assertSame(
            'aguardando_profissional',
            $availabilityChanged['state']['etapa']
        );
        self::assertNotEmpty($availabilityChanged['unset_state']);

        $professional = $manager->onToolResult(
            [
                'state' => [
                    'profissional' => [
                        'id' => 11,
                        'nome' => 'Anterior',
                    ],
                ],
            ],
            'ConsultarProfissional',
            ['nome_profissional' => 'Dra. Larissa'],
            [
                'success' => true,
                'data' => [
                    'profissional_id' => 574,
                    'nome' => 'Dra. Larissa',
                ],
            ]
        );

        self::assertSame(
            574,
            $professional['state']['profissional']['id']
        );
        self::assertSame(
            'aguardando_data',
            $professional['state']['etapa']
        );
        self::assertNotEmpty($professional['unset_state']);

        $scheduleResult = [
            'success' => true,
            'data' => [
                [
                    'data' => '2026-10-20',
                    'data_br' => '20/10/2026',
                    'dia_semana' => 'terça-feira',
                    'horarios' => ['09:00', '10:00'],
                ],
                [
                    'data' => '2026-10-21',
                    'data_br' => '21/10/2026',
                    'dia_semana' => 'quarta-feira',
                    'horarios' => ['09:00'],
                ],
            ],
            'meta' => [
                'pagina_horarios' => 1,
                'horarios_por_data' => 2,
                'tem_mais_horarios' => true,
                'proxima_pagina_horarios' => 2,
                'total_datas' => 2,
                'primeira_data_encontrada' => '2026-10-20',
                'ultima_data_encontrada' => '2026-10-21',
                'proxima_data_inicio' => '2026-10-22',
                'pode_buscar_mais_datas' => false,
                'data_solicitada_encontrada' => true,
                'datas_encontradas' => [],
            ],
        ];

        $schedules = $manager->onToolResult(
            ['state' => []],
            'ConsultarHorarios',
            [
                'data_inicio' => '2026-10-20',
                'data_final' => '2026-10-21',
                'pagina' => 1,
            ],
            $scheduleResult
        );

        self::assertSame(
            'aguardando_horario',
            $schedules['state']['etapa']
        );
        self::assertCount(
            3,
            $schedules['state']['horarios_apresentados']
        );

        $page2 = $manager->onToolResult(
            [
                'state' => [
                    'data_inicio' => '2026-10-20',
                    'data_final' => '2026-10-21',
                    'horarios_apresentados' => [
                        [
                            'data' => '2026-10-20',
                            'data_br' => '20/10/2026',
                            'dia_semana' => 'terça-feira',
                            'hora' => '09:00',
                        ],
                    ],
                ],
            ],
            'ConsultarHorarios',
            [
                'data_inicio' => '2026-10-20',
                'data_final' => '2026-10-21',
                'pagina' => 2,
            ],
            [
                'success' => true,
                'data' => [
                    [
                        'data' => '2026-10-20',
                        'data_br' => '20/10/2026',
                        'dia_semana' => 'terça-feira',
                        'horarios' => ['09:00', '11:00'],
                    ],
                ],
                'meta' => [
                    'pagina_horarios' => 2,
                    'tem_mais_horarios' => false,
                ],
            ]
        );

        self::assertCount(
            2,
            $page2['state']['horarios_apresentados']
        );

        $patientFound = $manager->onToolResult(
            [],
            'ConsultarPaciente',
            ['cpf' => '12345678901'],
            [
                'success' => true,
                'data' => [
                    'cadastro_encontrado' => true,
                    'paciente_id' => '123',
                    'nome' => 'Maria Teste',
                    'cpf' => '123.456.789-01',
                ],
            ]
        );

        self::assertSame(
            'aguardando_confirmacao',
            $patientFound['state']['etapa']
        );
        self::assertSame(
            123,
            $patientFound['state']['paciente_id']
        );
        self::assertTrue(
            $patientFound['state']['paciente_validado']
        );

        $patientMissing = $manager->onToolResult(
            [],
            'ConsultarPaciente',
            ['cpf' => '12345678901'],
            [
                'success' => true,
                'data' => [
                    'cadastro_encontrado' => false,
                    'paciente_id' => null,
                ],
            ]
        );

        self::assertTrue(
            $patientMissing['state']['paciente_nao_encontrado']
        );

        $booking = $manager->onToolResult(
            [],
            'CriarAgendamento',
            [],
            [
                'success' => true,
                'data' => [
                    'agendamento_id' => '98765',
                ],
            ]
        );

        self::assertSame(
            'agendado',
            $booking['state']['etapa']
        );
        self::assertSame(
            98765,
            $booking['state']['agendamento_id']
        );
    }

    public function testStateManagerParsesPatientChoices(): void
    {
        $manager = new ClinicStateManager();

        $uniqueTime = $manager->onUserMessage(
            [
                'state' => [
                    'etapa' => 'aguardando_horario',
                    'horarios_apresentados' => [
                        [
                            'data' => '2026-10-20',
                            'data_br' => '20/10/2026',
                            'dia_semana' => 'terça-feira',
                            'hora' => '09:30',
                        ],
                    ],
                ],
            ],
            '9:30'
        );

        self::assertSame(
            '09:30',
            $uniqueTime['state']['horario']
        );
        self::assertSame(
            '2026-10-20',
            $uniqueTime['state']['data_agendamento']
        );
        self::assertSame(
            'aguardando_cpf',
            $uniqueTime['state']['etapa']
        );

        $ambiguous = $manager->onUserMessage(
            [
                'state' => [
                    'etapa' => 'aguardando_horario',
                    'horarios_apresentados' => [
                        [
                            'data' => '2026-10-20',
                            'data_br' => '20/10/2026',
                            'dia_semana' => 'terça-feira',
                            'hora' => '09:00',
                        ],
                        [
                            'data' => '2026-10-21',
                            'data_br' => '21/10/2026',
                            'dia_semana' => 'quarta-feira',
                            'hora' => '09:00',
                        ],
                    ],
                ],
            ],
            '9'
        );

        self::assertSame(
            'aguardando_data_horario',
            $ambiguous['state']['etapa']
        );
        self::assertSame(
            '09:00',
            $ambiguous['state']['horario_pendente']['hora']
        );
        self::assertCount(
            2,
            $ambiguous['state']['horario_pendente']['datas']
        );

        $cpf = $manager->onUserMessage(
            [
                'state' => [
                    'etapa' => 'aguardando_cpf',
                ],
            ],
            'Meu CPF é 123.456.789-01'
        );

        self::assertSame(
            '12345678901',
            $cpf['state']['cpf']
        );

        $confirmed = $manager->onUserMessage(
            [
                'state' => [
                    'etapa' => 'aguardando_confirmacao',
                ],
            ],
            'sim'
        );

        self::assertTrue(
            $confirmed['state']['confirmado_pelo_usuario']
        );
        self::assertSame(
            'pronto_para_agendar',
            $confirmed['state']['etapa']
        );

        self::assertSame(
            [],
            $manager->onUserMessage(
                ['state' => ['etapa' => 'aguardando_cpf']],
                'sem cpf aqui'
            )
        );
    }

    public function testConsultarPacienteCoversProcedureMappingAndNormalization(): void
    {
        $db = new CoverageQueueSqlExecutor();

        $db->responses = [
            [
                ['PARAMETER_NAME' => 'p_empresa_id'],
                ['PARAMETER_NAME' => 'p_cpf'],
                ['PARAMETER_NAME' => 'p_sessao_id'],
            ],
            [
                [
                    'paciente_id' => '321',
                    'nome_completo' => 'Maria da Silva',
                    'cpf' => '123.456.789-01',
                    'agendamento_id' => '77',
                    'data_agendamento' => '2026-10-20',
                    'hora_inicio' => '09:30',
                    'nome_profissional' => 'Dra. Larissa',
                    'tratamento' => 'Limpeza',
                ],
            ],
        ];

        $tool = new ConsultarPacienteTool(
            $db,
            'consultar_paciente_por_cpf'
        );

        self::assertSame('ConsultarPaciente', $tool->name());
        self::assertSame($tool->name(), $tool->getName());
        self::assertSame($tool->description(), $tool->getDescription());
        self::assertSame($tool->schema(), $tool->inputSchema());
        self::assertSame($tool->schema(), $tool->getInputSchema());
        self::assertSame($tool->schema(), $tool->parameters());
        self::assertSame($tool->schema(), $tool->getParameters());
        self::assertSame(
            'ConsultarPaciente',
            $tool->definition()['name']
        );

        $result = $tool->execute([
            'empresa_id' => 142,
            'cpf' => '123.456.789-01',
            'sessao_id' => 'sessao-teste',
        ]);

        self::assertTrue($result['success']);
        self::assertSame(321, $result['data']['paciente_id']);
        self::assertSame(
            'Maria da Silva',
            $result['data']['nome']
        );
        self::assertTrue(
            $result['data']['agendamento_encontrado']
        );
        self::assertSame(
            77,
            $result['data']['agendamentos'][0]['agendamento_id']
        );
        self::assertStringContainsString(
            'Cadastro localizado',
            $result['_guiche']['terminal_message']
        );

        self::assertCount(2, $db->calls);
        self::assertStringContainsString(
            'information_schema.PARAMETERS',
            $db->calls[0]['sql']
        );
        self::assertStringContainsString(
            'CALL `consultar_paciente_por_cpf`',
            $db->calls[1]['sql']
        );
    }

    public function testConsultarPacienteCoversFailuresAndNestedPayload(): void
    {
        $db = new CoverageQueueSqlExecutor();
        $tool = new ConsultarPacienteTool(
            $db,
            'consultar_paciente_por_cpf'
        );

        self::assertFalse(
            $tool->execute([
                'empresa_id' => 0,
                'cpf' => '12345678901',
            ])['success']
        );

        self::assertFalse(
            $tool->execute([
                'empresa_id' => 142,
                'cpf' => '123',
            ])['success']
        );

        $db->responses = [[]];

        self::assertFalse(
            $tool->execute([
                'empresa_id' => 142,
                'cpf' => '12345678901',
            ])['success']
        );

        $db->responses = [
            [
                ['PARAMETER_NAME' => 'p_nao_reconhecido'],
            ],
        ];

        self::assertFalse(
            $tool->execute([
                'empresa_id' => 142,
                'cpf' => '12345678901',
            ])['success']
        );

        $db->responses = [
            [
                ['PARAMETER_NAME' => 'empresa'],
                ['PARAMETER_NAME' => 'cpf'],
            ],
            [
                [
                    'payload' => json_encode(
                        [
                            'paciente_id' => 999,
                            'nome_paciente' => 'Paciente JSON',
                            'cpf' => '12345678901',
                        ],
                        JSON_THROW_ON_ERROR
                    ),
                ],
            ],
        ];

        $nested = $tool->execute([
            'empresa_id' => 142,
            'cpf' => '12345678901',
        ]);

        self::assertTrue($nested['success']);
        self::assertSame(
            999,
            $nested['data']['paciente_id']
        );

        $this->expectException(RuntimeException::class);

        new ConsultarPacienteTool(
            $db,
            'procedure-invalida!'
        );
    }

    public function testConsultarHorariosCoversPaginationCompleteModeAndValidation(): void
    {
        $db = new CoverageQueueSqlExecutor();

        $rows = [
            ['mensagem' => 'Agenda encontrada'],
            ['horario_disponivel' => '20/10/2026 terça-feira 09:00'],
            ['horario_disponivel' => '20/10/2026 terça-feira 10:00'],
            ['horario_disponivel' => '20/10/2026 terça-feira 11:00'],
            ['horario_disponivel' => '21/10/2026 quarta-feira 09:30'],
            ['horario_disponivel' => '21/10/2026 quarta-feira 10:30'],
            ['horario_disponivel' => 'formato inválido'],
            ['outra_coluna' => 'ignorar'],
        ];

        $db->responses = [$rows];

        $tool = new ConsultarHorariosTool(
            $db,
            'horarios_disponiveis_por_turno',
            2
        );

        self::assertSame('ConsultarHorarios', $tool->name());
        self::assertArrayHasKey(
            'pagina',
            $tool->parameters()['properties']
        );

        $page1 = $tool->execute([
            'empresa_id' => 142,
            'profissional_id' => 574,
            'data_inicio' => '2026-10-20',
            'data_final' => '2026-10-22',
            'pagina' => 1,
        ]);

        self::assertTrue($page1['success']);
        self::assertSame('paginado', $page1['meta']['modo']);
        self::assertTrue($page1['meta']['tem_mais_horarios']);
        self::assertSame(
            2,
            $page1['meta']['proxima_pagina_horarios']
        );
        self::assertSame(
            '2026-10-22',
            $page1['meta']['proxima_data_inicio']
        );
        self::assertNotEmpty(
            $page1['_guiche']['terminal_message']
        );

        $db->responses = [$rows];

        $complete = $tool->execute([
            'empresa_id' => 142,
            'profissional_id' => 574,
            'data_inicio' => '2026-10-20',
            'data_final' => '2026-10-22',
            'pagina' => 0,
        ]);

        self::assertSame('completo', $complete['meta']['modo']);
        self::assertFalse(
            $complete['meta']['tem_mais_horarios']
        );
        self::assertCount(
            3,
            $complete['data'][0]['horarios']
        );

        try {
            $tool->execute([
                'empresa_id' => 142,
            ]);
            self::fail('Parâmetro obrigatório deveria falhar.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $tool->execute([
                'empresa_id' => 142,
                'profissional_id' => 574,
                'data_inicio' => '2026-10-20',
                'data_final' => '2026-10-22',
                'pagina' => -1,
            ]);
            self::fail('Página negativa deveria falhar.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $tool->execute([
                'empresa_id' => 142,
                'profissional_id' => 574,
                'data_inicio' => '20/10/2026',
                'data_final' => '2026-10-22',
                'pagina' => 1,
            ]);
            self::fail('Data inválida deveria falhar.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            new ConsultarHorariosTool(
                $db,
                'procedure-invalida!',
                2
            );
            self::fail('Procedure inválida deveria falhar.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            new ConsultarHorariosTool(
                $db,
                'horarios_disponiveis',
                0
            );
            self::fail('hoursPerDate inválido deveria falhar.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testConsultarProfissionalCoversObservedAndStructuredFormats(): void
    {
        $db = new CoverageQueueSqlExecutor();
        $tool = new ConsultarProfissionalTool(
            $db,
            'sp_consulta_profissional'
        );

        self::assertSame(
            'ConsultarProfissional',
            $tool->name()
        );
        self::assertArrayHasKey(
            'nome_profissional',
            $tool->parameters()['properties']
        );

        $db->responses = [
            [
                [
                    'profissionais' =>
                        '[ profissional_id: 574 nome_completo: Larissa Uemi Yoqui ]',
                ],
            ],
        ];

        $observed = $tool->execute([
            'empresa_id' => 142,
            'nome_profissional' => 'Larissa Uemi Yoqui',
        ]);

        self::assertTrue($observed['success']);
        self::assertSame(
            574,
            $observed['data']['profissional_id']
        );

        $db->responses = [
            [
                [
                    'id_profissional' => '77',
                    'nome_profissional' => 'Dra. Teste',
                ],
            ],
        ];

        $structured = $tool->execute([
            'empresa_id' => 142,
            'nome_profissional' => 'Dra. Teste',
        ]);

        self::assertSame(
            77,
            $structured['data']['profissional_id']
        );

        $db->responses = [[]];

        self::assertFalse(
            $tool->execute([
                'empresa_id' => 142,
                'nome_profissional' => 'Inexistente',
            ])['success']
        );

        try {
            $tool->execute([
                'empresa_id' => 142,
            ]);
            self::fail('Nome obrigatório deveria falhar.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $db->responses = [
            [['profissionais' => 'formato inesperado']],
        ];

        try {
            $tool->execute([
                'empresa_id' => 142,
                'nome_profissional' => 'Teste',
            ]);
            self::fail('Retorno não parseável deveria falhar.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);

        new ConsultarProfissionalTool(
            $db,
            'procedure-invalida!'
        );
    }

    public function testDisponibilidadeToolAndPresenterCoverMainBranches(): void
    {
        $db = new CoverageQueueSqlExecutor();
        $db->responses = [
            [
                [
                    'profissional_atendimento' =>
                        'Dra. Larissa (atende segunda-feira)',
                ],
                [
                    'nome_completo' => 'Dr. Olavo',
                ],
                [
                    'nome_completo' => 'Dr. Olavo',
                ],
            ],
        ];

        $tool = new DisponibilidadeConveniosTool(
            $db,
            'profissionais_por_tratamento'
        );

        self::assertSame(
            'DisponibilidadeConvenios',
            $tool->name()
        );
        self::assertNotEmpty($tool->description());
        self::assertArrayHasKey(
            'tratamento_nome',
            $tool->parameters()['properties']
        );

        $result = $tool->execute([
            'empresa_id' => 142,
            'tratamento_nome' => 'Limpeza',
            'convenio_nome' => 'Dental Plus',
        ]);

        self::assertTrue($result['success']);
        self::assertStringContainsString(
            'Dra. Larissa',
            $result['_guiche']['presentation_message']
        );
        self::assertStringContainsString(
            'Dr. Olavo',
            $result['_guiche']['presentation_message']
        );

        $presenter = new DisponibilidadeConveniosPresenter();

        self::assertNull(
            $presenter->present(['success' => false])
        );

        self::assertStringContainsString(
            'Não encontrei',
            (string) $presenter->present([
                'success' => true,
                'data' => [],
            ])
        );

        self::assertNull(
            $presenter->present([
                'success' => true,
                'data' => [
                    ['nome' => ''],
                    ['nome' => []],
                    'ignorar',
                ],
            ])
        );

        try {
            $tool->execute([
                'empresa_id' => 142,
                'tratamento_nome' => 'Limpeza',
            ]);
            self::fail('Convênio obrigatório deveria falhar.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);

        new DisponibilidadeConveniosTool(
            $db,
            'procedure-invalida!'
        );
    }

    public function testCriarAgendamentoCoversValidationSuccessAndFailures(): void
    {
        $http = new CoverageQueueHttpClient();

        $tool = new CriarAgendamentoTool(
            $http,
            'https://example.invalid/agendamentos',
            'token-teste',
            7
        );

        self::assertSame('CriarAgendamento', $tool->name());
        self::assertSame($tool->name(), $tool->getName());
        self::assertSame($tool->description(), $tool->getDescription());
        self::assertSame($tool->schema(), $tool->inputSchema());
        self::assertSame($tool->schema(), $tool->getInputSchema());
        self::assertSame($tool->schema(), $tool->parameters());
        self::assertSame($tool->schema(), $tool->getParameters());
        self::assertSame(
            'CriarAgendamento',
            $tool->definition()['name']
        );

        $base = [
            'paciente_id' => 123,
            'data_inicio' => '2026-10-20',
            'hora_inicio' => '09:30',
            'profissional_id' => '574',
            'servico_id' => '28233',
            'titulo' => 'Maria Teste',
        ];

        self::assertFalse(
            $tool->execute(
                array_replace($base, ['paciente_id' => 0])
            )['success']
        );

        self::assertFalse(
            $tool->execute(
                array_replace(
                    $base,
                    ['data_inicio' => '20/10/2026']
                )
            )['success']
        );

        self::assertFalse(
            $tool->execute(
                array_replace(
                    $base,
                    ['hora_inicio' => '25:99']
                )
            )['success']
        );

        self::assertFalse(
            $tool->execute(
                array_replace($base, ['titulo' => ''])
            )['success']
        );

        $withoutUrl = new CriarAgendamentoTool(
            $http,
            ''
        );

        self::assertFalse(
            $withoutUrl->execute($base)['success']
        );

        $http->responses = [
            [
                'status' => 201,
                'body' => '{"ok":true}',
                'json' => [
                    'data' => [
                        'agendamento_id' => '98765',
                    ],
                ],
            ],
        ];

        $success = $tool->execute($base);

        self::assertTrue($success['success']);
        self::assertSame(
            98765,
            $success['data']['agendamento_id']
        );
        self::assertSame(
            'Bearer token-teste',
            $http->calls[0]['headers']['Authorization']
        );
        self::assertSame(7, $http->calls[0]['timeout']);

        $http->responses = [
            [
                'status' => 500,
                'body' => 'erro',
                'json' => [],
            ],
        ];

        self::assertFalse(
            $tool->execute($base)['success']
        );

        $http->responses = [
            [
                'status' => 200,
                'body' => '{}',
                'json' => [],
            ],
        ];

        self::assertFalse(
            $tool->execute($base)['success']
        );

        $http->responses = [
            new RuntimeException('falha simulada'),
        ];

        self::assertSame(
            'falha simulada',
            $tool->execute($base)['error']
        );

        $bodyHttp = new CoverageQueueHttpClient();
        $bodyHttp->responses = [
            [
                'status' => 200,
                'body' => 'agendamento_id: ABC-123',
                'json' => null,
            ],
        ];

        $bodyTool = new CriarAgendamentoTool(
            $bodyHttp,
            'https://example.invalid/agendamentos',
            'Bearer pronto'
        );

        self::assertSame(
            'ABC-123',
            $bodyTool->execute($base)['data']['agendamento_id']
        );
    }

    public function testConsultarHorariosPresenterBranches(): void
    {
        $presenter = new ConsultarHorariosPresenter();

        self::assertNull(
            $presenter->present(['success' => false])
        );

        self::assertNotEmpty(
            $presenter->present([
                'success' => true,
                'data' => [],
                'message' => 'Sem horários nesta faixa.',
                'meta' => [],
            ])
        );

        $message = $presenter->present([
            'success' => true,
            'data' => [
                [
                    'data' => '2026-10-20',
                    'data_br' => '20/10/2026',
                    'dia_semana' => 'terça-feira',
                    'horarios' => ['09:00', '10:00'],
                    'total_horarios' => 3,
                    'tem_mais_horarios' => true,
                ],
            ],
            'meta' => [
                'tem_mais_horarios' => true,
                'proxima_pagina_horarios' => 2,
                'pode_buscar_mais_datas' => true,
                'proxima_data_inicio' => '2026-10-21',
            ],
        ]);

        self::assertNotEmpty($message);
        self::assertStringContainsString('09:00', (string) $message);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyClinicPluginAcceptanceRunsInsidePhpUnit(): void
    {
        require dirname(__DIR__, 2)
            . '/bin/test-clinical-plugin-v03.php';

        self::addToAssertionCount(1);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLegacyClinicalSafetyAcceptanceRunsInsidePhpUnit(): void
    {
        require dirname(__DIR__, 2)
            . '/bin/test-clinical-safety-v04.php';

        self::addToAssertionCount(1);
    }
}
