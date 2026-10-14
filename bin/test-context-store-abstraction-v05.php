<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\ToolRegistry;

function assertV05Phase02(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException('FALHOU: ' . $message);
    }

    echo 'OK: ' . $message . PHP_EOL;
}

final class MemoryContextStoreV05 implements ContextStoreInterface
{
    private array $contexts = [];

    public function load(string $sessionId): array
    {
        return $this->contexts[$sessionId] ?? $this->emptyContext($sessionId);
    }

    public function save(string $sessionId, array $context): void
    {
        $context['session_id'] = $sessionId;
        $this->contexts[$sessionId] = $context;
    }

    public function delete(string $sessionId): void
    {
        unset($this->contexts[$sessionId]);
    }

    public function addMessage(
        string $sessionId,
        string $role,
        string $content
    ): array {
        $context = $this->load($sessionId);
        $context['messages'][] = [
            'role' => $role,
            'content' => $content,
        ];

        $this->save($sessionId, $context);

        return $context;
    }

    public function setIntent(
        string $sessionId,
        ?string $intent
    ): array {
        $context = $this->load($sessionId);
        $context['intent'] = $intent;
        $this->save($sessionId, $context);

        return $context;
    }

    public function mergeState(
        string $sessionId,
        array $state
    ): array {
        $context = $this->load($sessionId);
        $context['state'] = array_replace_recursive(
            $context['state'] ?? [],
            $state
        );

        $this->save($sessionId, $context);

        return $context;
    }

    public function applyPatch(
        string $sessionId,
        array $patch
    ): array {
        $context = $this->load($sessionId);

        if (array_key_exists('intent', $patch)) {
            $context['intent'] = $patch['intent'];
        }

        if (!empty($patch['state']) && is_array($patch['state'])) {
            $context['state'] = array_replace_recursive(
                $context['state'] ?? [],
                $patch['state']
            );
        }

        foreach (($patch['unset_state'] ?? []) as $key) {
            unset($context['state'][$key]);
        }

        $this->save($sessionId, $context);

        return $context;
    }

    public function addToolExecution(
        string $sessionId,
        string $tool,
        array $arguments,
        array $result
    ): array {
        $context = $this->load($sessionId);

        $context['tool_history'][] = [
            'tool' => $tool,
            'arguments' => $arguments,
            'fingerprint' => $this->toolFingerprint(
                $sessionId,
                $tool,
                $arguments
            ),
            'result' => $result,
        ];

        $this->save($sessionId, $context);

        return $context;
    }

    public function findSuccessfulToolResult(
        string $sessionId,
        string $tool,
        array $arguments
    ): ?array {
        $fingerprint = $this->toolFingerprint(
            $sessionId,
            $tool,
            $arguments
        );

        $history = array_reverse(
            $this->load($sessionId)['tool_history'] ?? []
        );

        foreach ($history as $entry) {
            if (
                ($entry['tool'] ?? null) === $tool
                && ($entry['fingerprint'] ?? null) === $fingerprint
                && (($entry['result']['success'] ?? true) === true)
            ) {
                return $entry['result'] ?? null;
            }
        }

        return null;
    }

    public function recordInteractionUsage(
        string $sessionId,
        array $usage
    ): array {
        $context = $this->load($sessionId);

        $context['usage']['input_tokens'] =
            (int) ($context['usage']['input_tokens'] ?? 0)
            + (int) ($usage['input_tokens'] ?? 0);

        $context['usage']['output_tokens'] =
            (int) ($context['usage']['output_tokens'] ?? 0)
            + (int) ($usage['output_tokens'] ?? 0);

        $context['usage']['total_tokens'] =
            (int) ($context['usage']['total_tokens'] ?? 0)
            + (int) ($usage['total_tokens'] ?? 0);

        $context['usage']['interactions'][] = $usage;

        $this->save($sessionId, $context);

        return $context;
    }

    public function toolFingerprint(
        string $sessionId,
        string $tool,
        array $arguments
    ): string {
        $normalized = $this->normalize($arguments);

        return hash(
            'sha256',
            $sessionId
            . '|'
            . mb_strtolower(trim($tool), 'UTF-8')
            . '|'
            . json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            )
        );
    }

    private function emptyContext(string $sessionId): array
    {
        return [
            'session_id' => $sessionId,
            'intent' => null,
            'state' => [],
            'messages' => [],
            'tool_history' => [],
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'interactions' => [],
            ],
        ];
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value)
                ? mb_strtolower(trim($value), 'UTF-8')
                : $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}

echo "GUICHÊ v0.5.0 — FASE 02 / CONTEXT STORE ABSTRACTION\n";
echo "=========================================================\n\n";

echo "1. TYPE HINTS DO CORE\n";

$constructor = (new ReflectionClass(Guiche::class))->getConstructor();
assertV05Phase02($constructor !== null, 'Guiche possui construtor');

$contextParam = null;

foreach ($constructor->getParameters() as $parameter) {
    if ($parameter->getName() === 'context') {
        $contextParam = $parameter;
        break;
    }
}

assertV05Phase02(
    $contextParam !== null,
    'Guiche possui parâmetro context'
);

assertV05Phase02(
    $contextParam?->getType() instanceof ReflectionNamedType
    && $contextParam->getType()->getName()
        === ContextStoreInterface::class,
    'Guiche depende de ContextStoreInterface'
);

$builderMethod = new ReflectionMethod(
    GuicheBuilder::class,
    'withContextStore'
);

$builderParam = $builderMethod->getParameters()[0] ?? null;

assertV05Phase02(
    $builderParam !== null
    && $builderParam->getType() instanceof ReflectionNamedType
    && $builderParam->getType()->getName()
        === ContextStoreInterface::class,
    'GuicheBuilder depende de ContextStoreInterface'
);

echo "\n2. BACKEND EM MEMÓRIA / GUICHE DIRETO\n";

$memory = new MemoryContextStoreV05();
$session = 'phase02-direct';

$memory->mergeState(
    $session,
    ['backend' => 'memory']
);

$guiche = new Guiche(
    tools: new ToolRegistry(),
    context: $memory
);

assertV05Phase02(
    ($guiche->context($session)['state']['backend'] ?? null)
        === 'memory',
    'Guiche funciona com backend que não herda ContextStore'
);

$guiche->reset($session);

assertV05Phase02(
    ($guiche->context($session)['state'] ?? []) === [],
    'reset() usa apenas o contrato de persistência'
);

echo "\n3. BACKEND EM MEMÓRIA / GUICHEBUILDER\n";

$builderStore = new MemoryContextStoreV05();
$builderStore->mergeState(
    'phase02-builder',
    ['builder' => true]
);

$built = (new GuicheBuilder())
    ->withContextStore($builderStore)
    ->build();

assertV05Phase02(
    $built instanceof Guiche,
    'GuicheBuilder constrói runtime com backend substituível'
);

assertV05Phase02(
    ($built->context('phase02-builder')['state']['builder'] ?? null)
        === true,
    'runtime construído usa exatamente o store injetado'
);

echo "\n4. COMPATIBILIDADE DO BACKEND JSON ATUAL\n";

$directory = sys_get_temp_dir()
    . '/guiche-v05-phase02-'
    . bin2hex(random_bytes(6));

$jsonStore = new ContextStore(
    $directory,
    'America/Manaus'
);

$legacyGuiche = new Guiche(
    tools: new ToolRegistry(),
    context: $jsonStore
);

assertV05Phase02(
    $legacyGuiche instanceof Guiche,
    'construção manual com ContextStore concreto continua válida'
);

$builderWithJson = (new GuicheBuilder())
    ->withContextStore($jsonStore)
    ->build();

assertV05Phase02(
    $builderWithJson instanceof Guiche,
    'GuicheBuilder continua aceitando ContextStore concreto'
);

$legacyGuiche->reset('phase02-json');

if (is_dir($directory)) {
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

echo "\n5. FRONTEIRA DA FASE\n";

$guicheSource = file_get_contents(
    dirname(__DIR__) . '/src/Guiche.php'
);
$builderSource = file_get_contents(
    dirname(__DIR__) . '/src/GuicheBuilder.php'
);

assertV05Phase02(
    !preg_match(
        '/private\s+readonly\s+ContextStore\s+\$context\b/',
        (string) $guicheSource
    ),
    'Guiche não possui mais propriedade tipada pelo backend concreto'
);

assertV05Phase02(
    str_contains(
        (string) $guicheSource,
        'ContextStoreInterface $context'
    ),
    'Guiche declara explicitamente o contrato'
);

assertV05Phase02(
    str_contains(
        (string) $builderSource,
        'ContextStoreInterface'
    ),
    'GuicheBuilder conhece o contrato de persistência'
);

assertV05Phase02(
    !str_contains(
        strtolower((string) $guicheSource),
        'clinical'
    )
    && !str_contains(
        strtolower((string) $builderSource),
        'clinical'
    ),
    'desacoplamento permanece neutro de domínio'
);

echo "\nOK: GUICHÊ v0.5.0 / FASE 02 validada.\n";
