<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use GuicheExample\ClinicaPiloto\ClinicStateManager;
use PHPUnit\Framework\TestCase;

final class OperationalStateV16Test extends TestCase
{
    public function testCapturesInitialSchedulingAndParticularFacts(): void
    {
        $manager = new ClinicStateManager();

        $patch = $manager->onUserMessage(
            ['state' => []],
            'quero marcar uma consulta particular'
        );

        self::assertSame(
            'agendamento',
            $patch['intent']
        );
        self::assertSame(
            'Particular',
            $patch['state']['convenio']['nome']
        );

        self::assertArrayNotHasKey(
            'profissional',
            $patch['state']
        );
        self::assertArrayNotHasKey(
            'paciente_id',
            $patch['state']
        );
    }

    public function testRecognizesRebookingAndCancellationBeforeScheduling(): void
    {
        $manager = new ClinicStateManager();

        $rebooking = $manager->onUserMessage(
            ['state' => []],
            'quero remarcar minha consulta'
        );

        self::assertSame(
            'reagendamento',
            $rebooking['intent']
        );

        $cancellation = $manager->onUserMessage(
            ['state' => []],
            'quero cancelar minha consulta'
        );

        self::assertSame(
            'cancelamento',
            $cancellation['intent']
        );
    }

    public function testAmbiguousMessageDoesNotCreateOperationalFacts(): void
    {
        $manager = new ClinicStateManager();

        self::assertSame(
            [],
            $manager->onUserMessage(
                ['state' => []],
                'estou apenas tirando uma dúvida'
            )
        );
    }

    public function testChangingExplicitConvenioInvalidatesDependentChoice(): void
    {
        $manager = new ClinicStateManager();

        $patch = $manager->onUserMessage(
            [
                'state' => [
                    'convenio' => [
                        'nome' => 'Dental Plus',
                    ],
                    'procedimento' => [
                        'nome' => 'Limpeza',
                    ],
                    'profissional' => [
                        'id' => 574,
                        'nome' => 'Dra. Teste',
                    ],
                    'data_agendamento' => '2026-10-20',
                    'horario' => '09:00',
                ],
            ],
            'agora quero particular'
        );

        self::assertSame(
            'Particular',
            $patch['state']['convenio']['nome']
        );
        self::assertContains(
            'profissional',
            $patch['unset_state']
        );
        self::assertContains(
            'data_agendamento',
            $patch['unset_state']
        );
        self::assertContains(
            'horario',
            $patch['unset_state']
        );
    }

    public function testSchedulesAcceptDataInicialWithoutBreakingCurrentState(): void
    {
        $manager = new ClinicStateManager();

        $patch = $manager->onToolResult(
            [
                'state' => [
                    'data_inicio' => '2026-10-19',
                    'data_final' => '2026-10-19',
                    'data_agendamento' => '2026-10-19',
                    'horario' => '09:00',
                ],
            ],
            'ConsultarHorarios',
            [
                'data_inicial' => '2026-10-20',
                'data_final' => '2026-10-21',
                'pagina' => 1,
            ],
            [
                'success' => true,
                'data' => [],
                'meta' => [],
            ]
        );

        self::assertSame(
            '2026-10-20',
            $patch['state']['data_inicio']
        );
        self::assertSame(
            '2026-10-21',
            $patch['state']['data_final']
        );
        self::assertContains(
            'data_agendamento',
            $patch['unset_state']
        );
        self::assertContains(
            'horario',
            $patch['unset_state']
        );
    }

    public function testFailedToolStillProducesNoStateMutation(): void
    {
        $manager = new ClinicStateManager();

        self::assertSame(
            [],
            $manager->onToolResult(
                ['state' => []],
                'ConsultarHorarios',
                [],
                ['success' => false]
            )
        );
    }
}
