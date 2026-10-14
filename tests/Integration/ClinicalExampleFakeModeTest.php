<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use GuicheExample\ClinicaPiloto\Adapters\ClinicalAdapterFactory;
use GuicheExample\ClinicaPiloto\Adapters\FakeAiProvider;
use GuicheExample\ClinicaPiloto\Adapters\FakeHttpClient;
use GuicheExample\ClinicaPiloto\Adapters\FakeSqlExecutor;
use PHPUnit\Framework\TestCase;

final class ClinicalExampleFakeModeTest extends TestCase
{
    public function testFactorySelectsOnlyFakeAdaptersInFakeMode(): void
    {
        $config = $this->fakeConfig();

        self::assertSame('fake', ClinicalAdapterFactory::mode($config));
        self::assertInstanceOf(
            FakeSqlExecutor::class,
            ClinicalAdapterFactory::sql($config)
        );
        self::assertInstanceOf(
            FakeHttpClient::class,
            ClinicalAdapterFactory::http($config)
        );
        self::assertInstanceOf(
            FakeAiProvider::class,
            ClinicalAdapterFactory::ai($config)
        );
    }

    public function testFakeSqlUsesSyntheticFixtureDeterministically(): void
    {
        $fixtures = ClinicalAdapterFactory::fixtures($this->fakeConfig());
        $sql = new FakeSqlExecutor($fixtures);

        $rows = $sql->fetchAll(
            'CALL horarios_disponiveis_por_turno(?, ?, ?, ?)',
            [1, 101, '2030-01-15', '2030-01-15']
        );

        self::assertNotEmpty($rows);
        self::assertSame(
            '15/01/2030 terça-feira 09:00',
            $rows[0]['horario_disponivel'] ?? null
        );
        self::assertCount(1, $sql->calls());
    }

    public function testFakeHttpProducesSyntheticBookingWithoutNetwork(): void
    {
        $fixtures = ClinicalAdapterFactory::fixtures($this->fakeConfig());
        $http = new FakeHttpClient($fixtures);

        $response = $http->postForm(
            'https://example.invalid/api/agendamentos/novo.json',
            [
                'paciente_id' => 9001,
            ]
        );

        self::assertSame(200, $response['status']);
        self::assertSame(
            'fake-agendamento-001',
            $response['json']['agendamento_id'] ?? null
        );
        self::assertCount(1, $http->calls());
    }

    public function testFakeAiIsSyntheticAndNeverRequestsToolCalls(): void
    {
        $ai = new FakeAiProvider();

        $first = $ai->respond('instruções', [
            [
                'role' => 'user',
                'content' => 'mensagem fake',
            ],
        ]);
        $second = $ai->continueWithToolOutputs(
            (string)$first['id'],
            'instruções',
            [
                [
                    'tool_call_id' => 'fake-call',
                    'output' => ['success' => true],
                ],
            ]
        );

        self::assertTrue($first['synthetic']);
        self::assertSame([], $first['tool_calls']);
        self::assertSame(0, $first['usage']['total_tokens']);
        self::assertTrue($second['synthetic']);
        self::assertNotSame($first['id'], $second['id']);
    }

    public function testInvalidModeFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);

        ClinicalAdapterFactory::mode([
            'mode' => 'invalido',
        ]);
    }

    public function testDistributedFixtureAndEnvRemainSanitized(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = (string)file_get_contents(
            $root . '/examples/clinica-piloto/fixtures/clinical.php'
        );
        $env = (string)file_get_contents(
            $root . '/examples/clinica-piloto/.env.example'
        );

        self::assertStringContainsString('Paciente Exemplo', $fixture);
        self::assertStringContainsString(
            'GUICHE_CLINICAL_MODE=fake',
            $env
        );
        self::assertMatchesRegularExpression(
            '/^GUICHE_AGENDAMENTO_API_URL=\s*$/m',
            $env
        );
        self::assertStringNotContainsString(
            'clinsoft.atendamais.com.br',
            $fixture . $env
        );
        self::assertStringNotContainsString(
            'Ortoshine',
            $fixture . $env
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeConfig(): array
    {
        return [
            'mode' => 'fake',
            'fake' => [
                'fixture' => dirname(__DIR__, 2)
                    . '/examples/clinica-piloto/fixtures/clinical.php',
            ],
        ];
    }
}
