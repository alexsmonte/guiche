<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function checkAcceptanceV03(
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

$root = dirname(__DIR__);

echo PHP_EOL;
echo "GUICHÊ v0.3.0 — ACEITE CONSOLIDADO" . PHP_EOL;
echo str_repeat('=', 42) . PHP_EOL;

echo PHP_EOL . "1. CONTRATOS" . PHP_EOL;

checkAcceptanceV03(
    interface_exists(
        \Asmpkg\Guiche\Contracts\PluginInterface::class
    ),
    'PluginInterface disponível'
);

checkAcceptanceV03(
    interface_exists(
        \Asmpkg\Guiche\Contracts\ResultPresenterInterface::class
    ),
    'ResultPresenterInterface disponível'
);

checkAcceptanceV03(
    class_exists(
        \Asmpkg\Guiche\Plugin\ToolRegistration::class
    ),
    'ToolRegistration disponível'
);

echo PHP_EOL . "2. REGISTRIES + BUILDER" . PHP_EOL;

checkAcceptanceV03(
    class_exists(
        \Asmpkg\Guiche\ToolRegistry::class
    ),
    'ToolRegistry disponível'
);

checkAcceptanceV03(
    class_exists(
        \Asmpkg\Guiche\PresenterRegistry::class
    ),
    'PresenterRegistry disponível'
);

checkAcceptanceV03(
    class_exists(
        \Asmpkg\Guiche\GuicheBuilder::class
    )
    && method_exists(
        \Asmpkg\Guiche\GuicheBuilder::class,
        'registerPlugin'
    )
    && method_exists(
        \Asmpkg\Guiche\GuicheBuilder::class,
        'build'
    ),
    'GuicheBuilder registra Plugins e constrói runtime'
);

echo PHP_EOL . "3. CAPACIDADES v0.3" . PHP_EOL;

$builder = new \Asmpkg\Guiche\GuicheBuilder();

$requiredMethods = [
    'registerTool',
    'registerPresenter',
    'useStateManager',
    'registerPolicy',
    'addInstructionFragment',
    'registerCompletionGuard',
    'registerResultProjection',
];

foreach ($requiredMethods as $method) {
    checkAcceptanceV03(
        method_exists($builder, $method),
        "GuicheBuilder expõe {$method}()"
    );
}

echo PHP_EOL . "4. PILOTO CLÍNICO COMO PLUGIN" . PHP_EOL;

checkAcceptanceV03(
    class_exists(
        \Asmpkg\Guiche\Pilot\Clinical\ClinicalPlugin::class
    )
    && is_subclass_of(
        \Asmpkg\Guiche\Pilot\Clinical\ClinicalPlugin::class,
        \Asmpkg\Guiche\Contracts\PluginInterface::class
    ),
    'piloto clínico está encapsulado em ClinicalPlugin'
);

$chat = file_get_contents(
    $root . '/bin/chat.php'
);

checkAcceptanceV03(
    is_string($chat)
    && str_contains(
        $chat,
        'new ClinicalPlugin('
    )
    && str_contains(
        $chat,
        'new GuicheBuilder()'
    ),
    'bootstrap clínico usa Plugin API'
);

echo PHP_EOL . "5. PLUGIN NEUTRO EXTERNO" . PHP_EOL;

checkAcceptanceV03(
    is_file(
        $root
        . '/examples/neutral-plugin/src/NeutralPlugin.php'
    ),
    'plugin neutro existe fora de src/'
);

$neutralPath = realpath(
    $root . '/examples/neutral-plugin'
);
$srcPath = realpath(
    $root . '/src'
);

checkAcceptanceV03(
    is_string($neutralPath)
    && is_string($srcPath)
    && !str_starts_with(
        $neutralPath,
        $srcPath . DIRECTORY_SEPARATOR
    ),
    'plugin neutro não depende de ser colocado no Core'
);

echo PHP_EOL . "6. FRONTEIRA v0.3 / v0.4" . PHP_EOL;

$coreSafetySources = '';

foreach (
    [
        $root . '/src/Guiche.php',
        $root . '/src/GuicheBuilder.php',
        $root . '/src/Plugin/PresentedTool.php',
    ]
    as $file
) {
    $coreSafetySources .=
        (string)file_get_contents($file);
}

$hardGateSymbols = [
    'hardExecutionGate',
    'authorizeToolExecution',
    'blockUnauthorizedTool',
];

$hasHardGate = false;

foreach ($hardGateSymbols as $symbol) {
    if (
        str_contains(
            $coreSafetySources,
            'function ' . $symbol
        )
        || str_contains(
            $coreSafetySources,
            '->' . $symbol . '('
        )
    ) {
        $hasHardGate = true;
        break;
    }
}

checkAcceptanceV03(
    !$hasHardGate,
    'hard execution gate continua reservado para v0.4'
);

echo PHP_EOL;
echo "OK: Definition of Done estrutural da v0.3 atendida." . PHP_EOL;
