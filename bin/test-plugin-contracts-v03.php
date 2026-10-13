<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Plugin\ToolRegistration;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkV03(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class NeutralToolV03 implements ToolInterface
{
    public function name(): string
    {
        return 'NeutralEcho';
    }

    public function description(): string
    {
        return 'Ferramenta neutra para validar contratos da Plugin API.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        return [
            'success' => true,
            'tool' => $this->name(),
            'data' => [
                'value' => (string)($arguments['value'] ?? ''),
            ],
        ];
    }
}

final class BlankNameToolV03 implements ToolInterface
{
    public function name(): string
    {
        return '   ';
    }

    public function description(): string
    {
        return 'Inválida';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments): array
    {
        return [];
    }
}

final class NeutralPresenterV03 implements ResultPresenterInterface
{
    public function present(array $result): ?string
    {
        $value = $result['data']['value'] ?? null;

        return is_string($value) && $value !== ''
            ? 'Valor: ' . $value
            : null;
    }
}

echo PHP_EOL;
echo "1. CONTRATOS" . PHP_EOL;

checkV03(interface_exists(ToolInterface::class), 'ToolInterface da v0.2 continua disponível');
checkV03(interface_exists(StateManagerInterface::class), 'StateManagerInterface da v0.2 continua disponível');
checkV03(interface_exists(PluginInterface::class), 'PluginInterface da v0.3 está disponível');
checkV03(interface_exists(ResultPresenterInterface::class), 'ResultPresenterInterface da v0.3 está disponível');

$register = new \ReflectionMethod(PluginInterface::class, 'register');
$parameters = $register->getParameters();

checkV03(count($parameters) === 1, 'PluginInterface::register possui um único argumento');
checkV03(
    ($parameters[0]->getType()?->getName() ?? '') === 'Asmpkg\\Guiche\\GuicheBuilder',
    'PluginInterface registra capacidades através de GuicheBuilder'
);
checkV03(
    ($register->getReturnType()?->getName() ?? '') === 'void',
    'PluginInterface::register retorna void'
);

echo PHP_EOL;
echo "2. TOOL REGISTRATION" . PHP_EOL;

$tool = new NeutralToolV03();
$registration = new ToolRegistration(
    $tool,
    [
        'kind' => 'read',
        'reusable' => true,
        'source' => 'neutral-test',
    ]
);

checkV03($registration->tool() === $tool, 'ToolRegistration preserva a instância da Tool');
checkV03($registration->name() === 'NeutralEcho', 'ToolRegistration expõe o nome da Tool');
checkV03(
    ($registration->metadata()['kind'] ?? null) === 'read',
    'ToolRegistration preserva metadados declarativos'
);
checkV03(
    $registration->definition() === [
        'name' => 'NeutralEcho',
        'description' => 'Ferramenta neutra para validar contratos da Plugin API.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ],
    ],
    'ToolRegistration gera definição compatível com ToolInterface'
);

$invalidRejected = false;

try {
    new ToolRegistration(new BlankNameToolV03());
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}

checkV03($invalidRejected, 'ToolRegistration rejeita Tool sem nome');

echo PHP_EOL;
echo "3. PRESENTER" . PHP_EOL;

$presenter = new NeutralPresenterV03();

checkV03(
    $presenter->present([
        'data' => ['value' => 'teste'],
    ]) === 'Valor: teste',
    'Presenter neutro implementa o contrato genérico'
);
checkV03(
    $presenter->present(['data' => []]) === null,
    'Presenter pode declarar que não há apresentação aplicável'
);

echo PHP_EOL;
echo "4. FRONTEIRA DA FASE" . PHP_EOL;

$projectRoot = dirname(__DIR__);
$guicheSource = file_get_contents($projectRoot . '/src/Guiche.php');
$toolRegistrySource = file_get_contents($projectRoot . '/src/ToolRegistry.php');

checkV03(
    is_string($guicheSource) && !str_contains($guicheSource, 'PluginInterface'),
    'Fase 01 ainda não acopla Guiche ao sistema de plugins'
);
checkV03(
    is_string($toolRegistrySource)
    && !str_contains($toolRegistrySource, 'PluginInterface')
    && !str_contains($toolRegistrySource, 'GuicheBuilder'),
    'ToolRegistry continua desacoplado da Plugin API e do GuicheBuilder'
);

echo PHP_EOL;
echo "OK: GUICHÊ v0.3.0 / FASE 01 validada." . PHP_EOL;
