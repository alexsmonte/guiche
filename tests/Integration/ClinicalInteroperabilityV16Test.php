<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use GuicheExample\ClinicaPiloto\ClinicStateManager;
use PHPUnit\Framework\TestCase;

final class ClinicalInteroperabilityV16Test extends TestCase
{
    public function testLegacyConsultaPacienteFindsExistingPatient(): void
    {
        $manager = new ClinicStateManager();

        $patch = $manager->onToolResult(
            ['state' => []],
            'ConsultaPaciente',
            ['cpf' => '123.456.789-01'],
            [
                'success' => true,
                'data' => [
                    [
                        'resposta_ia' =>
                            "paciente_id: 148445\n"
                            . "Nome do paciente: Paciente Teste\n",
                    ],
                ],
            ]
        );

        self::assertSame(
            148445,
            $patch['state']['paciente_id']
        );
        self::assertSame(
            'Paciente Teste',
            $patch['state']['paciente_nome']
        );
        self::assertSame(
            '12345678901',
            $patch['state']['cpf']
        );
        self::assertTrue(
            $patch['state']['paciente_validado']
        );
        self::assertTrue(
            $patch['state']['paciente_cadastrado']
        );
    }

    public function testLegacyConsultaPacienteMissingPreservesCpf(): void
    {
        $manager = new ClinicStateManager();

        $patch = $manager->onToolResult(
            [
                'state' => [
                    'paciente_id' => 99,
                    'paciente_nome' => 'Anterior',
                    'agendamento_id' => 77,
                ],
            ],
            'ConsultaPaciente',
            ['cpf' => '987.654.321-00'],
            [
                'success' => true,
                'data' => [
                    [
                        'resposta_ia' =>
                            'Paciente não tem cadastro',
                    ],
                ],
            ]
        );

        self::assertSame(
            '98765432100',
            $patch['state']['cpf']
        );
        self::assertFalse(
            $patch['state']['paciente_cadastrado']
        );
        self::assertSame(
            'paciente_nao_cadastrado',
            $patch['state']['status']
        );
        self::assertContains(
            'paciente_id',
            $patch['unset_state']
        );
        self::assertContains(
            'agendamento_id',
            $patch['unset_state']
        );
    }

    public function testCurrentConsultarPacienteMissingAlsoPreservesCpf(): void
    {
        $manager = new ClinicStateManager();

        $patch = $manager->onToolResult(
            ['state' => []],
            'ConsultarPaciente',
            ['cpf' => '987.654.321-00'],
            [
                'success' => true,
                'data' => [
                    'cadastro_encontrado' => false,
                    'paciente_id' => null,
                    'cpf' => '98765432100',
                ],
            ]
        );

        self::assertSame(
            '98765432100',
            $patch['state']['cpf']
        );
        self::assertFalse(
            $patch['state']['paciente_cadastrado']
        );
    }

    public function testCadastrarPacienteConsumesReturnedRealId(): void
    {
        $manager = new ClinicStateManager();

        $patch = $manager->onToolResult(
            [
                'state' => [
                    'cpf' => '12345678901',
                ],
            ],
            'CadastrarPaciente',
            [
                'cpf' => '123.456.789-01',
                'nome_completo' => 'Novo Paciente',
                'data_nascimento' => '10/04/1992',
                'endereco' => 'Rua Exemplo, 100',
                'celular' => '92999999999',
            ],
            [
                'success' => true,
                'data' => [
                    [
                        'novo_paciente_id' => 217166,
                    ],
                ],
            ]
        );

        self::assertSame(
            217166,
            $patch['state']['paciente_id']
        );
        self::assertSame(
            'Novo Paciente',
            $patch['state']['paciente_nome']
        );
        self::assertSame(
            '12345678901',
            $patch['state']['cpf']
        );
        self::assertTrue(
            $patch['state']['paciente_cadastrado']
        );
        self::assertTrue(
            $patch['state']['paciente_validado']
        );
        self::assertSame(
            'paciente_cadastrado',
            $patch['state']['status']
        );
        self::assertSame(
            'aguardando_confirmacao',
            $patch['state']['etapa']
        );
    }

    public function testAgendarConsultaAliasesConsumeBookingResult(): void
    {
        $manager = new ClinicStateManager();

        foreach (
            ['AgendarConsulta', 'agendarConsulta']
            as $tool
        ) {
            $patch = $manager->onToolResult(
                ['state' => []],
                $tool,
                [
                    'data_inicio' => '2026-10-21',
                    'hora_inicio' => '10:00',
                ],
                [
                    'success' => true,
                    'data' => [
                        [
                            'agendamento_id' => 98765,
                        ],
                    ],
                ]
            );

            self::assertSame(
                98765,
                $patch['state']['agendamento_id']
            );
            self::assertSame(
                'agendado',
                $patch['state']['etapa']
            );
            self::assertSame(
                'agendado',
                $patch['state']['status']
            );
            self::assertSame(
                '2026-10-21',
                $patch['state']['data_agendamento']
            );
            self::assertSame(
                '10:00',
                $patch['state']['horario']
            );
        }
    }

    public function testFailedExternalToolDoesNotChangeState(): void
    {
        $manager = new ClinicStateManager();

        self::assertSame(
            [],
            $manager->onToolResult(
                ['state' => []],
                'CadastrarPaciente',
                ['cpf' => '12345678901'],
                ['success' => false]
            )
        );
    }
}
