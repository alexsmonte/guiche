<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use GuicheExample\ClinicaPiloto\ClinicStateManager;
use GuicheExample\ClinicaPiloto\Safety\ClinicalSlotRevalidator;
use GuicheExample\ClinicaPiloto\Tools\CriarAgendamentoTool;
use PHPUnit\Framework\TestCase;

final class ClinicalExampleWriteSafetyTest extends TestCase
{
    public function testAvailableSlotIsRevalidatedBeforeWrite(): void
    {
        $sql = new ClinicalWriteSafetySqlExecutor([
            ['horario_disponivel' => '15/01/2030 terça-feira 09:00'],
            ['horario_disponivel' => '15/01/2030 terça-feira 09:30'],
        ]);
        $http = new ClinicalWriteSafetyHttpClient();
        $tool = $this->tool($sql, $http);

        $result = $tool->execute($this->bookingArguments());

        self::assertTrue($result['success']);
        self::assertSame(1, $sql->fetchCalls);
        self::assertSame(1, $http->postCalls);
        self::assertSame('09:00', $http->lastData['hora_inicio'] ?? null);
    }

    public function testLostSlotFailsClosedWithoutPost(): void
    {
        $sql = new ClinicalWriteSafetySqlExecutor([
            ['horario_disponivel' => '15/01/2030 terça-feira 10:00'],
        ]);
        $http = new ClinicalWriteSafetyHttpClient();
        $tool = $this->tool($sql, $http);

        $result = $tool->execute($this->bookingArguments());

        self::assertFalse($result['success']);
        self::assertSame('slot_unavailable', $result['meta']['reason'] ?? null);
        self::assertSame('slot_revalidation', $result['meta']['source'] ?? null);
        self::assertSame(0, $http->postCalls);
        self::assertArrayNotHasKey('raw', $result['meta']);
    }

    public function testRevalidationFailureFailsClosedWithoutPost(): void
    {
        $sql = new ClinicalWriteSafetySqlExecutor([], true);
        $http = new ClinicalWriteSafetyHttpClient();
        $tool = $this->tool($sql, $http);

        $result = $tool->execute($this->bookingArguments());

        self::assertFalse($result['success']);
        self::assertSame(
            'slot_revalidation_failed',
            $result['meta']['reason'] ?? null
        );
        self::assertSame(0, $http->postCalls);
    }

    public function testInvalidRevalidationInputDoesNotQueryDatabase(): void
    {
        $sql = new ClinicalWriteSafetySqlExecutor([]);
        $revalidator = new ClinicalSlotRevalidator(
            $sql,
            'horarios_disponiveis_por_turno',
            1,
            3
        );

        $result = $revalidator->revalidate([
            'data_inicio' => 'data-invalida',
            'hora_inicio' => '99:99',
            'profissional_id' => 0,
        ]);

        self::assertFalse($result['available']);
        self::assertSame(
            'slot_revalidation_invalid_input',
            $result['reason']
        );
        self::assertSame(0, $sql->fetchCalls);
    }

    public function testLegacyConstructorWithoutRevalidatorRemainsCompatible(): void
    {
        $http = new ClinicalWriteSafetyHttpClient();
        $tool = new CriarAgendamentoTool(
            $http,
            'https://example.invalid/api/agendamentos/novo.json',
            '',
            15
        );

        $result = $tool->execute($this->bookingArguments());

        self::assertTrue($result['success']);
        self::assertSame(1, $http->postCalls);
    }

    public function testExistingAppointmentClosesNewBookingFlow(): void
    {
        $stateManager = new ClinicStateManager();

        $patch = $stateManager->onToolResult(
            [
                'state' => [
                    'etapa' => 'aguardando_cpf',
                ],
            ],
            'ConsultarPaciente',
            [
                'empresa_id' => 1,
                'cpf' => '00000000000',
            ],
            [
                'success' => true,
                'data' => [
                    'cadastro_encontrado' => true,
                    'paciente_id' => 9001,
                    'nome' => 'Paciente Exemplo',
                    'cpf' => '00000000000',
                    'agendamento_encontrado' => true,
                    'agendamentos' => [
                        [
                            'agendamento_id' => 7001,
                            'data' => '2030-01-15',
                            'hora' => '09:00',
                        ],
                    ],
                ],
            ]
        );

        self::assertSame('agendado', $patch['state']['etapa'] ?? null);
        self::assertSame(7001, $patch['state']['agendamento_id'] ?? null);
        self::assertTrue(
            $patch['state']['agendamento_existente'] ?? false
        );

        $afterConfirmation = $stateManager->onUserMessage(
            ['state' => $patch['state']],
            'sim'
        );

        self::assertNotSame(
            'pronto_para_agendar',
            $afterConfirmation['state']['etapa'] ?? null
        );
    }

    public function testPatientWithoutExistingAppointmentKeepsNormalFlow(): void
    {
        $stateManager = new ClinicStateManager();

        $patch = $stateManager->onToolResult(
            [
                'state' => [
                    'etapa' => 'aguardando_cpf',
                ],
            ],
            'ConsultarPaciente',
            [
                'empresa_id' => 1,
                'cpf' => '00000000000',
            ],
            [
                'success' => true,
                'data' => [
                    'cadastro_encontrado' => true,
                    'paciente_id' => 9001,
                    'nome' => 'Paciente Exemplo',
                    'cpf' => '00000000000',
                    'agendamento_encontrado' => false,
                    'agendamentos' => [],
                ],
            ]
        );

        self::assertSame(
            'aguardando_confirmacao',
            $patch['state']['etapa'] ?? null
        );
        self::assertArrayNotHasKey(
            'agendamento_existente',
            $patch['state']
        );
    }

    private function tool(
        ClinicalWriteSafetySqlExecutor $sql,
        ClinicalWriteSafetyHttpClient $http
    ): CriarAgendamentoTool {
        return new CriarAgendamentoTool(
            $http,
            'https://example.invalid/api/agendamentos/novo.json',
            '',
            15,
            new ClinicalSlotRevalidator(
                $sql,
                'horarios_disponiveis_por_turno',
                1,
                3
            )
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function bookingArguments(): array
    {
        return [
            'paciente_id' => 9001,
            'data_inicio' => '2030-01-15',
            'hora_inicio' => '09:00',
            'profissional_id' => '101',
            'servico_id' => '201',
            'titulo' => 'Paciente Exemplo',
        ];
    }
}

/**
 * Executor determinístico usado somente pelo acceptance PHPUnit clínico.
 */
final class ClinicalWriteSafetySqlExecutor implements SqlExecutorInterface
{
    public int $fetchCalls = 0;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly bool $fail = false
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->fetchCalls++;

        if ($this->fail) {
            throw new \RuntimeException('falha sintética de banco');
        }

        return $this->rows;
    }

    public function execute(string $sql, array $parameters = []): int
    {
        return 1;
    }
}

final class ClinicalWriteSafetyHttpClient implements HttpClientInterface
{
    public int $postCalls = 0;

    /** @var array<string, mixed> */
    public array $lastData = [];

    /**
     * @return array<string, mixed>
     */
    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        $this->postCalls++;
        $this->lastData = $data;

        return [
            'status' => 200,
            'body' => '{"agendamento_id":"phase05-booking-1"}',
            'json' => [
                'agendamento_id' => 'phase05-booking-1',
            ],
            'headers' => [],
        ];
    }
}
