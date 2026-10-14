<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use GuicheExample\ClinicaPiloto\Adapters\ClinicalAdapterFactory;
use GuicheExample\ClinicaPiloto\Adapters\FakeAiProvider;
use GuicheExample\ClinicaPiloto\Adapters\FakeHttpClient;
use GuicheExample\ClinicaPiloto\Adapters\FakeSqlExecutor;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$example = $root . '/examples/clinica-piloto';

function checkV08Phase03(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

echo PHP_EOL . "GUICHÊ v0.8.0 — FASE 03 / CONFIG + ADAPTERS + FAKE MODE" . PHP_EOL;
echo str_repeat('=', 66) . PHP_EOL . PHP_EOL;

foreach ([
    '.env.example',
    'fixtures/clinical.php',
    'src/Support/EnvLoader.php',
    'src/Adapters/ClinicalAdapterFactory.php',
    'src/Adapters/FakeSqlExecutor.php',
    'src/Adapters/FakeHttpClient.php',
    'src/Adapters/FakeAiProvider.php',
    'fake-smoke.php',
] as $file) {
    checkV08Phase03(is_file($example . '/' . $file), 'existe ' . $file);
}

checkV08Phase03(
    is_subclass_of(FakeSqlExecutor::class, SqlExecutorInterface::class),
    'FakeSqlExecutor cumpre SqlExecutorInterface'
);
checkV08Phase03(
    is_subclass_of(FakeHttpClient::class, HttpClientInterface::class),
    'FakeHttpClient cumpre HttpClientInterface'
);
checkV08Phase03(
    is_subclass_of(FakeAiProvider::class, AiProviderInterface::class),
    'FakeAiProvider cumpre AiProviderInterface'
);

putenv('GUICHE_CLINICAL_MODE=fake');
putenv('GUICHE_CLINIC_COMPANY_ID=1');
putenv('GUICHE_CLINIC_NAME=Clínica Exemplo');
putenv('DB_PROCEDURE_CONSULTAR_PACIENTE=fake_consultar_paciente');
putenv('GUICHE_AGENDAMENTO_API_URL=');

$config = require $example . '/config/clinic.php';

checkV08Phase03(($config['mode'] ?? null) === 'fake', 'Fake Mode é válido');
checkV08Phase03(($config['agent_context']['empresa_id'] ?? null) === 1, 'empresa_id é sintético');
checkV08Phase03(
    ($config['agent_context']['empresa_nome'] ?? null) === 'Clínica Exemplo',
    'empresa_nome é sintético'
);
checkV08Phase03(
    str_contains((string)($config['agendamento_api']['url'] ?? ''), 'example.invalid'),
    'endpoint fake usa domínio reservado'
);

$sql = ClinicalAdapterFactory::sql($config);
$http = ClinicalAdapterFactory::http($config);
$ai = ClinicalAdapterFactory::ai($config);

checkV08Phase03($sql instanceof FakeSqlExecutor, 'factory escolhe SQL fake');
checkV08Phase03($http instanceof FakeHttpClient, 'factory escolhe HTTP fake');
checkV08Phase03($ai instanceof FakeAiProvider, 'factory escolhe IA fake');

$rows = $sql->fetchAll(
    'CALL profissionais_por_tratamento(?, ?, ?)',
    [1, 'Avaliação', 'Convênio Exemplo']
);
checkV08Phase03(count($rows) === 2, 'SQL fake é determinístico');

$httpResult = $http->postForm(
    'https://example.invalid/api/agendamentos/novo.json',
    ['paciente_id' => 9001]
);
checkV08Phase03(($httpResult['status'] ?? null) === 200, 'HTTP fake é determinístico');
checkV08Phase03(
    ($httpResult['json']['agendamento_id'] ?? null) === 'fake-agendamento-001',
    'HTTP fake usa id sintético'
);

$aiResult = $ai->respond('', [], []);
checkV08Phase03(($aiResult['synthetic'] ?? false) === true, 'IA fake é sintética');
checkV08Phase03(($aiResult['tool_calls'] ?? null) === [], 'IA fake não gera calls');

$envText = (string)file_get_contents($example . '/.env.example');
checkV08Phase03(str_contains($envText, 'GUICHE_CLINICAL_MODE=fake'), '.env.example inicia fake');
checkV08Phase03(!str_contains($envText, 'clinsoft.atendamais.com.br'), '.env.example sem endpoint real');
checkV08Phase03(!str_contains($envText, 'Ortoshine'), '.env.example sem clínica real');

$exampleText = '';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($example, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getFilename() === '.env') {
        continue;
    }

    $exampleText .= "\n" . (string)file_get_contents($file->getPathname());
}

foreach (['clinsoft.atendamais.com.br', 'Ortoshine', 'Larissa Uemi Yoqui', 'Guilherme Fernandes Soares', 'Alex da Silva Monte'] as $marker) {
    checkV08Phase03(
        !str_contains($exampleText, $marker),
        'exemplo não contém marcador real: ' . $marker
    );
}

$chat = (string)file_get_contents($example . '/chat.php');
checkV08Phase03(
    str_contains($chat, 'ClinicalAdapterFactory::sql($config)'),
    'chat seleciona SQL pela factory'
);
checkV08Phase03(
    str_contains($chat, 'ClinicalAdapterFactory::ai($config)'),
    'chat seleciona IA pela factory'
);

$plugin = (string)file_get_contents($example . '/src/ClinicPlugin.php');
checkV08Phase03(
    str_contains($plugin, 'ClinicalAdapterFactory::http($this->config)'),
    'ClinicPlugin seleciona HTTP pela factory'
);

echo PHP_EOL . "OK: GUICHÊ v0.8.0 / FASE 03 validada." . PHP_EOL;
