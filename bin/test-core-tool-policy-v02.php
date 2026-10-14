<?php

declare(strict_types=1);

use Asmpkg\Guiche\ToolPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkCoreToolPolicyV02(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

$policy = new ToolPolicy(
    stages: [
        'draft' => ['SearchCatalog'],
        'checkout' => ['CreateOrder'],
    ],
    defaultTools: ['FallbackTool'],
    bootstrapTools: ['StartFlow'],
    contextPath: 'workflow.phase'
);

checkCoreToolPolicyV02(
    $policy->allowedToolNames([]) === ['StartFlow'],
    'contexto sem fase usa bootstrap genérico'
);

checkCoreToolPolicyV02(
    $policy->allowedToolNames([
        'workflow' => ['phase' => 'draft'],
    ]) === ['SearchCatalog'],
    'ToolPolicy lê caminho de contexto não clínico'
);

checkCoreToolPolicyV02(
    $policy->allowedToolNames([
        'workflow' => ['phase' => 'checkout'],
    ]) === ['CreateOrder'],
    'mapeamento genérico de fase funciona'
);

checkCoreToolPolicyV02(
    $policy->allowedToolNames([
        'workflow' => ['phase' => 'unknown'],
    ]) === ['FallbackTool'],
    'fase desconhecida usa default genérico'
);

$defaultPolicy = new ToolPolicy(
    stages: [
        'ready' => ['GenericTool'],
    ],
    bootstrapTools: ['BootstrapTool']
);

checkCoreToolPolicyV02(
    $defaultPolicy->allowedToolNames([
        'state' => ['stage' => 'ready'],
    ]) === ['GenericTool'],
    'default neutro do Core usa state.stage'
);

$source = file_get_contents(dirname(__DIR__) . '/src/ToolPolicy.php');

checkCoreToolPolicyV02(
    is_string($source)
        && !str_contains($source, "['state']['etapa']")
        && !str_contains($source, "'state.etapa'"),
    'ToolPolicy do Core não conhece state.etapa'
);

$pilotConfig = require dirname(__DIR__) . '/examples/clinica-piloto/config/clinic.php';

checkCoreToolPolicyV02(
    ($pilotConfig['tool_policy']['context_path'] ?? null) === 'state.etapa',
    'piloto clínico declara explicitamente seu caminho de estágio'
);

echo PHP_EOL;
echo "OK: ToolPolicy genérica da v0.2 validada." . PHP_EOL;
