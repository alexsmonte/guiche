<?php

declare(strict_types=1);

use GuicheExample\ClinicaPiloto\Adapters\ClinicalAdapterFactory;
use GuicheExample\ClinicaPiloto\Adapters\FakeAiProvider;
use GuicheExample\ClinicaPiloto\Adapters\FakeHttpClient;
use GuicheExample\ClinicaPiloto\Adapters\FakeSqlExecutor;
use GuicheExample\ClinicaPiloto\Tools\ConsultarHorariosTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarPacienteTool;
use GuicheExample\ClinicaPiloto\Tools\ConsultarProfissionalTool;
use GuicheExample\ClinicaPiloto\Tools\CriarAgendamentoTool;
use GuicheExample\ClinicaPiloto\Tools\DisponibilidadeConveniosTool;

require __DIR__ . '/bootstrap.php';

putenv('GUICHE_CLINICAL_MODE=fake');
putenv('GUICHE_CLINIC_COMPANY_ID=1');
putenv('GUICHE_CLINIC_NAME=Clínica Exemplo');
putenv('DB_PROCEDURE_CONSULTAR_PACIENTE=fake_consultar_paciente');
putenv('GUICHE_AGENDAMENTO_API_URL=');

$config = require __DIR__ . '/config/clinic.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
};

$check(($config['mode'] ?? null) === 'fake', 'config em Fake Mode');

$sql = ClinicalAdapterFactory::sql($config);
$http = ClinicalAdapterFactory::http($config);
$ai = ClinicalAdapterFactory::ai($config);

$check($sql instanceof FakeSqlExecutor, 'SQL fake selecionado');
$check($http instanceof FakeHttpClient, 'HTTP fake selecionado');
$check($ai instanceof FakeAiProvider, 'IA fake selecionada');

$procedures = $config['procedures'];

$availability = (new DisponibilidadeConveniosTool(
    $sql,
    (string)$procedures['disponibilidade_convenios']
))->execute([
    'empresa_id' => 1,
    'tratamento_nome' => 'Avaliação',
    'convenio_nome' => 'Convênio Exemplo',
]);
$check(($availability['success'] ?? false) === true, 'DisponibilidadeConvenios usa fixture');
$check(count($availability['data'] ?? []) === 2, 'profissionais fake determinísticos');

$professional = (new ConsultarProfissionalTool(
    $sql,
    (string)$procedures['consultar_profissional']
))->execute([
    'empresa_id' => 1,
    'nome_profissional' => 'Dra. Ana Exemplo',
]);
$check(($professional['success'] ?? false) === true, 'ConsultarProfissional usa fixture');
$check(($professional['data']['profissional_id'] ?? null) === 101, 'profissional fake determinístico');

$hours = (new ConsultarHorariosTool(
    $sql,
    (string)$procedures['consultar_horarios'],
    (int)$config['horarios_por_data']
))->execute([
    'empresa_id' => 1,
    'profissional_id' => 101,
    'data_inicio' => '2030-01-15',
    'data_final' => '2030-01-16',
    'pagina' => 0,
]);
$check(($hours['success'] ?? false) === true, 'ConsultarHorarios usa fixture');
$check(count($hours['data'] ?? []) >= 1, 'horários fake determinísticos');

$patient = (new ConsultarPacienteTool(
    $sql,
    (string)$procedures['consultar_paciente']
))->execute([
    'empresa_id' => 1,
    'cpf' => '00000000000',
]);
$check(($patient['success'] ?? false) === true, 'ConsultarPaciente usa fixture');
$check(($patient['data']['paciente_id'] ?? null) === 9001, 'paciente fake determinístico');

$api = $config['agendamento_api'];
$booking = (new CriarAgendamentoTool(
    $http,
    (string)$api['url'],
    (string)$api['token'],
    (int)$api['timeout']
))->execute([
    'paciente_id' => 9001,
    'data_inicio' => '2030-01-15',
    'hora_inicio' => '09:00',
    'profissional_id' => '101',
    'servico_id' => '201',
    'titulo' => 'Paciente Exemplo',
]);
$check(($booking['success'] ?? false) === true, 'CriarAgendamento usa HTTP fake');
$check(
    ($booking['data']['agendamento_id'] ?? null) === 'fake-agendamento-001',
    'agendamento fake determinístico'
);
$check(count($http->calls()) === 1, 'HTTP fake registra uma chamada local');

$response = $ai->respond('fake', [['role' => 'user', 'content' => 'teste']], []);
$check(($response['synthetic'] ?? false) === true, 'IA fake produz resposta sintética');
$check(($response['tool_calls'] ?? null) === [], 'IA fake não chama serviço externo');

echo PHP_EOL . "OK: Fake Mode clínico executou offline." . PHP_EOL;
