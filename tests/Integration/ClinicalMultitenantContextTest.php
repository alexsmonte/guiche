<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use GuicheExample\ClinicaPiloto\Safety\ClinicalSlotRevalidator;
use GuicheExample\ClinicaPiloto\Tools\ConsultarHorariosTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarPacienteTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarProfissionalTool;
use GuicheExample\ClinicaPiloto\Tools\CriarAgendamentoTool;
use GuicheExample\ClinicaPiloto\Tools\DisponibilidadeConveniosTool;
use PHPUnit\Framework\TestCase;

final class ClinicalMultitenantContextTest extends TestCase
{
    public function testSchemasHideEmpresaIdAndAllToolsAreContextAware(): void
    {
        $sql = new ClinicalTenantSqlProbe();
        $http = new ClinicalTenantHttpProbe();

        $tools = [
            new DisponibilidadeConveniosTool($sql),
            new ConsultarProfissionalTool($sql),
            new ConsultarHorariosTool($sql),
            new ConsultarPacienteTool($sql, 'consultar_paciente_por_cpf'),
            new CriarAgendamentoTool(
                $http,
                'https://example.invalid/agendamentos'
            ),
        ];

        foreach ($tools as $tool) {
            self::assertInstanceOf(
                ContextAwareToolInterface::class,
                $tool
            );

            $schema = $tool->parameters();

            self::assertArrayNotHasKey(
                'empresa_id',
                $schema['properties'] ?? []
            );

            self::assertNotContains(
                'empresa_id',
                $schema['required'] ?? []
            );
        }
    }

    public function testSameToolObjectUsesDifferentTrustedTenants(): void
    {
        $sql = new ClinicalTenantSqlProbe();

        $tool = new DisponibilidadeConveniosTool($sql);

        $arguments = [
            'empresa_id' => 999,
            'tratamento_nome' => 'Limpeza',
            'convenio_nome' => 'Dental Plus',
        ];

        $tool->executeWithContext(
            $arguments,
            $this->context('session-a', 101)
        );

        $tool->executeWithContext(
            $arguments,
            $this->context('session-b', 202)
        );

        self::assertSame(101, $sql->calls[0]['parameters'][0]);
        self::assertSame(202, $sql->calls[1]['parameters'][0]);
    }

    public function testProfessionalAndScheduleIgnoreForgedCompany(): void
    {
        $sql = new ClinicalTenantSqlProbe();

        (new ConsultarProfissionalTool($sql))->executeWithContext(
            [
                'empresa_id' => 999,
                'nome_profissional' => 'Dra. Ana',
            ],
            $this->context('professional', 303)
        );

        self::assertSame(303, $sql->calls[0]['parameters'][0]);

        (new ConsultarHorariosTool($sql))->executeWithContext(
            [
                'empresa_id' => 999,
                'profissional_id' => 77,
                'data_inicio' => '2026-10-20',
                'data_final' => '2026-10-20',
                'pagina' => 0,
            ],
            $this->context('schedule', 404)
        );

        self::assertSame(404, $sql->calls[1]['parameters'][3]);
    }

    public function testPatientUsesTrustedTenantAndSessionId(): void
    {
        $sql = new ClinicalTenantSqlProbe();

        (new ConsultarPacienteTool(
            $sql,
            'consultar_paciente_por_cpf'
        ))->executeWithContext(
            [
                'empresa_id' => 999,
                'cpf' => '000.000.000-00',
                'sessao_id' => 'forged-session',
            ],
            $this->context('trusted-session', 505)
        );

        self::assertSame(
            [505, '00000000000', 'trusted-session'],
            $sql->calls[1]['parameters']
        );
    }

    public function testBookingSlotRevalidationUsesTrustedTenant(): void
    {
        $sql = new ClinicalTenantSqlProbe();
        $http = new ClinicalTenantHttpProbe();

        $tool = new CriarAgendamentoTool(
            $http,
            'https://example.invalid/agendamentos',
            '',
            15,
            new ClinicalSlotRevalidator(
                $sql,
                'horarios_disponiveis_por_turno',
                0,
                3
            )
        );

        $result = $tool->executeWithContext(
            [
                'empresa_id' => 999,
                'paciente_id' => 123,
                'data_inicio' => '2026-10-20',
                'hora_inicio' => '09:30',
                'profissional_id' => '77',
                'servico_id' => '28233',
                'titulo' => 'Paciente Teste',
            ],
            $this->context('booking', 606)
        );

        self::assertTrue((bool)($result['success'] ?? false));
        self::assertSame(1, $http->posts);
        self::assertSame(606, $sql->calls[0]['parameters'][3]);
    }

    private function context(
        string $sessionId,
        int $tenantId
    ): ToolExecutionContext {
        return new ToolExecutionContext(
            $sessionId,
            [
                'identity' => [
                    'provider' => 'test',
                    'tenant_key' => 'tenant-' . $tenantId,
                ],
                'tenant_id' => $tenantId,
            ]
        );
    }
}

final class ClinicalTenantSqlProbe implements SqlExecutorInterface
{
    /** @var list<array{sql:string, parameters:array<mixed>}> */
    public array $calls = [];

    public function fetchAll(
        string $sql,
        array $parameters = []
    ): array {
        $this->calls[] = [
            'sql' => $sql,
            'parameters' => $parameters,
        ];

        if (str_contains($sql, 'information_schema.PARAMETERS')) {
            return [
                ['PARAMETER_NAME' => 'p_empresa_id'],
                ['PARAMETER_NAME' => 'p_cpf'],
                ['PARAMETER_NAME' => 'p_sessao_id'],
            ];
        }

        if (str_contains($sql, 'consultar_paciente_por_cpf')) {
            return [[
                'paciente_id' => 123,
                'nome_completo' => 'Paciente Teste',
                'cpf' => '00000000000',
            ]];
        }

        if (str_contains($sql, 'sp_consulta_profissional')) {
            return [[
                'profissional_id' => 77,
                'nome_completo' => 'Dra. Ana',
            ]];
        }

        if (str_contains($sql, 'horarios_disponiveis_por_turno')) {
            return [[
                'horario_disponivel' =>
                    '20/10/2026 terça-feira 09:30',
            ]];
        }

        return [];
    }

    public function execute(
        string $sql,
        array $parameters = []
    ): int {
        return 1;
    }
}

final class ClinicalTenantHttpProbe implements HttpClientInterface
{
    public int $posts = 0;

    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        $this->posts++;

        return [
            'status' => 200,
            'json' => ['agendamento_id' => 98765],
            'body' => '{"agendamento_id":98765}',
        ];
    }
}
