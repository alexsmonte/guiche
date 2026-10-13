<?php

declare(strict_types=1);

use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\PresenterRegistry;
use Asmpkg\Guiche\ToolRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkV03F02(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class NeutralEchoToolV03F02 implements ToolInterface
{
    public function __construct(private readonly string $toolName = 'NeutralEcho')
    {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return 'Ferramenta neutra de teste da v0.3.';
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

final class NeutralPresenterV03F02 implements ResultPresenterInterface
{
    public function present(array $result): ?string
    {
        if (($result['success'] ?? false) !== true) {
            return null;
        }

        $value = trim((string)($result['data']['value'] ?? ''));

        return $value === '' ? null : 'Apresentado: ' . $value;
    }
}

echo PHP_EOL . "1. TOOL REGISTRY / COMPATIBILIDADE v0.2" . PHP_EOL;

$legacyTool = new NeutralEchoToolV03F02('LegacyNeutral');

$legacyRegistry = new ToolRegistry();
$returnedRegistry = $legacyRegistry->register($legacyTool);

checkV03F02(
    $returnedRegistry === $legacyRegistry,
    'register() continua fluente'
);

checkV03F02(
    $legacyRegistry->has('LegacyNeutral'),
    'registro direto de ToolInterface continua suportado'
);

checkV03F02(
    $legacyRegistry->get('LegacyNeutral') === $legacyTool,
    'get() continua devolvendo ToolInterface'
);

checkV03F02(
    $legacyRegistry->names() === ['LegacyNeutral'],
    'names() mantém comportamento da v0.2'
);

$legacyResult = $legacyRegistry->execute(
    'LegacyNeutral',
    ['value' => 'abc']
);

checkV03F02(
    ($legacyResult['data']['value'] ?? null) === 'abc',
    'execute() mantém comportamento da v0.2'
);

$legacyDefinitions = $legacyRegistry->definitions();

checkV03F02(
    count($legacyDefinitions) === 1
    && ($legacyDefinitions[0]['name'] ?? null) === 'LegacyNeutral'
    && array_key_exists('description', $legacyDefinitions[0])
    && array_key_exists('parameters', $legacyDefinitions[0]),
    'definitions() mantém o contrato enviado ao provider'
);

echo PHP_EOL . "2. TOOL REGISTRATION / METADADOS" . PHP_EOL;

$registeredTool = new NeutralEchoToolV03F02('RegisteredNeutral');

$registration = new ToolRegistration(
    $registeredTool,
    [
        'source' => 'neutral-plugin',
        'read_only' => true,
        'risk' => 'low',
    ]
);

$registry = new ToolRegistry();
$registry->register($registration);

checkV03F02(
    $registry->registration('RegisteredNeutral') === $registration,
    'ToolRegistry preserva ToolRegistration'
);

checkV03F02(
    $registry->get('RegisteredNeutral') === $registeredTool,
    'ToolRegistry resolve a Tool interna do ToolRegistration'
);

checkV03F02(
    ($registry->metadata('RegisteredNeutral')['source'] ?? null)
        === 'neutral-plugin',
    'metadados declarativos ficam disponíveis no registry'
);

$definitions = $registry->definitions();

checkV03F02(
    !array_key_exists('metadata', $definitions[0]),
    'metadados internos não vazam para a definição da IA'
);

checkV03F02(
    array_keys($registry->registrations()) === ['RegisteredNeutral'],
    'registrations() expõe os registros por nome'
);

echo PHP_EOL . "3. CONFLITOS DE TOOL" . PHP_EOL;

$duplicateRejected = false;

try {
    $registry->register(
        new NeutralEchoToolV03F02('RegisteredNeutral')
    );
} catch (\LogicException $exception) {
    $duplicateRejected = str_contains(
        $exception->getMessage(),
        'RegisteredNeutral'
    );
}

checkV03F02(
    $duplicateRejected,
    'nome duplicado de Tool é rejeitado explicitamente'
);

$missingToolRejected = false;

try {
    $registry->get('ToolQueNaoExiste');
} catch (\InvalidArgumentException $exception) {
    $missingToolRejected = true;
}

checkV03F02(
    $missingToolRejected,
    'consulta de Tool inexistente continua falhando explicitamente'
);

echo PHP_EOL . "4. PRESENTER REGISTRY" . PHP_EOL;

$presenters = new PresenterRegistry();
$presenter = new NeutralPresenterV03F02();

$returnedPresenters = $presenters->register(
    'RegisteredNeutral',
    $presenter
);

checkV03F02(
    $returnedPresenters === $presenters,
    'PresenterRegistry::register() é fluente'
);

checkV03F02(
    $presenters->has('RegisteredNeutral'),
    'PresenterRegistry identifica Presenter registrado'
);

checkV03F02(
    $presenters->get('RegisteredNeutral') === $presenter,
    'PresenterRegistry devolve a instância registrada'
);

checkV03F02(
    $presenters->present(
        'RegisteredNeutral',
        [
            'success' => true,
            'data' => ['value' => 'teste'],
        ]
    ) === 'Apresentado: teste',
    'PresenterRegistry delega apresentação ao contrato genérico'
);

checkV03F02(
    $presenters->present(
        'SemPresenter',
        ['success' => true]
    ) === null,
    'ausência de Presenter é opcional e retorna null'
);

checkV03F02(
    $presenters->names() === ['RegisteredNeutral'],
    'PresenterRegistry lista associações registradas'
);

checkV03F02(
    array_keys($presenters->all()) === ['RegisteredNeutral'],
    'PresenterRegistry expõe seu mapa sem semântica de domínio'
);

$duplicatePresenterRejected = false;

try {
    $presenters->register(
        'RegisteredNeutral',
        new NeutralPresenterV03F02()
    );
} catch (\LogicException $exception) {
    $duplicatePresenterRejected = str_contains(
        $exception->getMessage(),
        'RegisteredNeutral'
    );
}

checkV03F02(
    $duplicatePresenterRejected,
    'Presenter duplicado para a mesma Tool é rejeitado'
);

$blankPresenterRejected = false;

try {
    (new PresenterRegistry())->register(
        '   ',
        new NeutralPresenterV03F02()
    );
} catch (\InvalidArgumentException $exception) {
    $blankPresenterRejected = true;
}

checkV03F02(
    $blankPresenterRejected,
    'PresenterRegistry rejeita nome vazio'
);

echo PHP_EOL . "5. FRONTEIRA DA FASE" . PHP_EOL;

$guicheSource = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);

checkV03F02(
    is_string($guicheSource)
    && !str_contains($guicheSource, 'PresenterRegistry'),
    'Fase 02 ainda não acopla PresenterRegistry ao Guiche'
);

checkV03F02(
    is_string($guicheSource)
    && !str_contains($guicheSource, 'ToolRegistration'),
    'Guiche ainda consome somente a API pública do ToolRegistry'
);

echo PHP_EOL;
echo "OK: GUICHÊ v0.3.0 / FASE 02 validada." . PHP_EOL;
