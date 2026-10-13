<?php

declare(strict_types=1);

use Asmpkg\Guiche\Safety\ToolArgumentValidator;
use Asmpkg\Guiche\Safety\ToolBlockedException;
use Asmpkg\Guiche\Safety\ToolSafetyMetadata;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkV04F01(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException(
            'FALHOU: ' . $message
        );
    }

    echo 'OK: ' . $message . PHP_EOL;
}

echo PHP_EOL;
echo "GUICHÊ v0.4.0 — FASE 01 / SAFETY CONTRACTS" . PHP_EOL;
echo "==================================================" . PHP_EOL;

echo PHP_EOL;
echo "1. TOOL BLOCKED EXCEPTION" . PHP_EOL;

$exception = new ToolBlockedException(
    'NeutralWrite',
    'policy_denied',
    'Execução não autorizada.',
    [
        'stage' => 'draft',
    ]
);

checkV04F01(
    $exception->tool() === 'NeutralWrite',
    'bloqueio preserva o nome da Tool'
);

checkV04F01(
    $exception->reasonCode() === 'policy_denied',
    'bloqueio preserva reason code auditável'
);

$serialized = $exception->toArray();

checkV04F01(
    ($serialized['blocked'] ?? null) === true,
    'bloqueio possui envelope estruturado'
);

checkV04F01(
    ($serialized['details']['stage'] ?? null)
        === 'draft',
    'bloqueio preserva detalhes estruturados'
);

echo PHP_EOL;
echo "2. READ / WRITE METADATA" . PHP_EOL;

checkV04F01(
    ToolSafetyMetadata::operation([])
        === ToolSafetyMetadata::OPERATION_READ,
    'registro legado sem operation mantém compatibilidade como READ'
);

checkV04F01(
    ToolSafetyMetadata::isWrite([
        'operation' => 'WRITE',
    ]),
    'operation=write é reconhecida sem depender de caixa'
);

checkV04F01(
    ToolSafetyMetadata::operation([
        'operation' => 'delete-everything',
    ]) === ToolSafetyMetadata::OPERATION_UNKNOWN,
    'operação desconhecida não é silenciosamente convertida'
);

checkV04F01(
    ToolSafetyMetadata::allowedStages([
        'allowed_stages' => [
            'draft',
            'ready',
            'draft',
            '',
        ],
    ]) === [
        'draft',
        'ready',
    ],
    'allowed_stages são normalizados e deduplicados'
);

echo PHP_EOL;
echo "3. STATE PRECONDITIONS" . PHP_EOL;

$required = ToolSafetyMetadata::requiredState([
    'requires_state' => [
        'state.user_id',
        'state.confirmed' => true,
        [
            'path' => 'state.scope',
            'value' => 'booking',
        ],
    ],
]);

checkV04F01(
    count($required) === 3,
    'requires_state aceita existência e igualdade'
);

checkV04F01(
    $required[0] === [
        'path' => 'state.user_id',
        'operator' => 'exists',
    ],
    'requires_state por lista significa existência'
);

checkV04F01(
    $required[1] === [
        'path' => 'state.confirmed',
        'operator' => 'equals',
        'value' => true,
    ],
    'requires_state associativo preserva valor esperado'
);

$forbidden = ToolSafetyMetadata::forbiddenState([
    'forbids_state' => [
        'state.completed',
        'state.cancelled' => true,
    ],
]);

checkV04F01(
    count($forbidden) === 2,
    'forbids_state é normalizado'
);

$confirmation = ToolSafetyMetadata::confirmation([
    'confirmation' => [
        'path' => 'state.confirmado_pelo_usuario',
        'value' => true,
    ],
]);

checkV04F01(
    $confirmation === [
        'path' => 'state.confirmado_pelo_usuario',
        'operator' => 'equals',
        'value' => true,
    ],
    'confirmação é declarativa e neutra de domínio'
);

echo PHP_EOL;
echo "4. ARGUMENT VALIDATION" . PHP_EOL;

$validator = new ToolArgumentValidator();

$schema = [
    'type' => 'object',
    'properties' => [
        'id' => [
            'type' => 'integer',
        ],
        'name' => [
            'type' => 'string',
            'minLength' => 2,
        ],
        'mode' => [
            'type' => 'string',
            'enum' => [
                'read',
                'write',
            ],
        ],
    ],
    'required' => [
        'id',
        'name',
    ],
    'additionalProperties' => false,
];

checkV04F01(
    $validator->validate(
        $schema,
        [
            'id' => 10,
            'name' => 'Alex',
            'mode' => 'read',
        ]
    ) === [],
    'argumentos válidos passam na validação estrutural'
);

$missing = $validator->validate(
    $schema,
    [
        'id' => 10,
    ]
);

checkV04F01(
    count($missing) === 1
        && str_contains(
            $missing[0],
            '$.name'
        ),
    'campo obrigatório ausente é detectado'
);

$wrongType = $validator->validate(
    $schema,
    [
        'id' => '10',
        'name' => 'Alex',
    ]
);

checkV04F01(
    count($wrongType) === 1
        && str_contains(
            $wrongType[0],
            'integer'
        ),
    'tipo incompatível é detectado antes da Tool'
);

$extra = $validator->validate(
    $schema,
    [
        'id' => 10,
        'name' => 'Alex',
        'dangerous' => true,
    ]
);

checkV04F01(
    count($extra) === 1
        && str_contains(
            $extra[0],
            'não é um argumento permitido'
        ),
    'additionalProperties=false é respeitado'
);

echo PHP_EOL;
echo "5. FRONTEIRA DA FASE" . PHP_EOL;

$root = dirname(__DIR__);
$guiche = file_get_contents(
    $root . '/src/Guiche.php'
);

checkV04F01(
    is_string($guiche),
    'Guiche permanece carregável após evolução da Safety Layer'
);

checkV04F01(
    class_exists(ToolBlockedException::class)
        && class_exists(ToolSafetyMetadata::class)
        && class_exists(ToolArgumentValidator::class),
    'contratos da Safety Layer estão carregáveis por PSR-4'
);

echo PHP_EOL;
echo "OK: GUICHÊ v0.4.0 / FASE 01 validada." . PHP_EOL;
