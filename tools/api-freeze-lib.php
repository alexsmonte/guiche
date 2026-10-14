<?php
declare(strict_types=1);

function guicheApiRoot(): string
{
    return dirname(__DIR__);
}

function guicheApiLoadAutoload(): void
{
    $autoload = guicheApiRoot() . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php não encontrado.');
    }
    require_once $autoload;
}

function guicheApiKind(string $symbol): string
{
    if (enum_exists($symbol, false)) {
        return 'enum';
    }
    if (interface_exists($symbol, false)) {
        return 'interface';
    }
    if (trait_exists($symbol, false)) {
        return 'trait';
    }
    if (class_exists($symbol, false)) {
        return 'class';
    }

    if (enum_exists($symbol)) {
        return 'enum';
    }
    if (interface_exists($symbol)) {
        return 'interface';
    }
    if (trait_exists($symbol)) {
        return 'trait';
    }
    if (class_exists($symbol)) {
        return 'class';
    }

    throw new RuntimeException("Símbolo não carregável: {$symbol}");
}

function guicheApiDiscoverSymbols(): array
{
    $root = guicheApiRoot();
    $src = $root . '/src';
    if (!is_dir($src)) {
        throw new RuntimeException('src/ não encontrado.');
    }

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    $symbols = [];
    foreach ($files as $file) {
        $code = file_get_contents($file);
        if ($code === false) {
            throw new RuntimeException("Falha lendo {$file}");
        }

        $tokens = token_get_all($code);
        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $parts = [];
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if ($t === ';' || $t === '{') {
                        break;
                    }
                    if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $parts[] = $t[1];
                    }
                }
                $namespace = trim(implode('', $parts), '\\');
                continue;
            }

            $symbolTokens = [T_CLASS, T_INTERFACE, T_TRAIT];
            if (defined('T_ENUM')) {
                $symbolTokens[] = T_ENUM;
            }
            if (!in_array($token[0], $symbolTokens, true)) {
                continue;
            }

            if ($token[0] === T_CLASS) {
                $prev = null;
                for ($p = $i - 1; $p >= 0; $p--) {
                    if (is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $prev = $tokens[$p];
                    break;
                }
                if (is_array($prev) && $prev[0] === T_NEW) {
                    continue;
                }
                if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue;
                }
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_STRING) {
                    $name = $t[1];
                    break;
                }
                if ($t === '{' || $t === '(') {
                    break;
                }
            }
            if ($name === null) {
                continue;
            }

            $fqcn = $namespace !== '' ? $namespace . '\\' . $name : $name;
            if (!str_starts_with($fqcn, 'Asmpkg\\Guiche\\')) {
                continue;
            }

            $rel = ltrim(str_replace($root, '', $file), '/');
            $symbols[$fqcn] = [
                'symbol' => $fqcn,
                'path' => $rel,
            ];
        }
    }

    ksort($symbols, SORT_STRING);
    return $symbols;
}

function guicheApiTypeNames(?ReflectionType $type, ?ReflectionClass $context = null): array
{
    if ($type === null) {
        return [];
    }

    if ($type instanceof ReflectionNamedType) {
        if ($type->isBuiltin()) {
            return [];
        }
        $name = $type->getName();
        if ($name === 'self' || $name === 'static') {
            return $context ? [$context->getName()] : [];
        }
        if ($name === 'parent') {
            $parent = $context?->getParentClass();
            return $parent ? [$parent->getName()] : [];
        }
        return [$name];
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        $names = [];
        foreach ($type->getTypes() as $part) {
            foreach (guicheApiTypeNames($part, $context) as $name) {
                $names[$name] = true;
            }
        }
        return array_keys($names);
    }

    return [];
}

function guicheApiTypeString(?ReflectionType $type): ?string
{
    if ($type === null) {
        return null;
    }

    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $name;
    }

    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(static fn(ReflectionType $t): string => guicheApiTypeString($t) ?? '', $type->getTypes()));
    }

    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(static fn(ReflectionType $t): string => guicheApiTypeString($t) ?? '', $type->getTypes()));
    }

    return (string)$type;
}

function guicheApiNormalizeValue(mixed $value): mixed
{
    if (is_null($value) || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
        return $value;
    }

    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $k => $v) {
            $normalized[(string)$k] = guicheApiNormalizeValue($v);
        }
        return $normalized;
    }

    if ($value instanceof UnitEnum) {
        return [
            '__enum' => $value::class . '::' . $value->name,
            'value' => $value instanceof BackedEnum ? $value->value : null,
        ];
    }

    return ['__type' => get_debug_type($value)];
}

function guicheApiReferencedPackageSymbols(string $symbol): array
{
    $r = new ReflectionClass($symbol);
    $refs = [];

    $parent = $r->getParentClass();
    if ($parent) {
        $refs[$parent->getName()] = true;
    }
    foreach ($r->getInterfaceNames() as $interface) {
        $refs[$interface] = true;
    }

    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $symbol && !$r->isInterface()) {
            continue;
        }
        foreach (guicheApiTypeNames($method->getReturnType(), $r) as $name) {
            $refs[$name] = true;
        }
        foreach ($method->getParameters() as $parameter) {
            foreach (guicheApiTypeNames($parameter->getType(), $r) as $name) {
                $refs[$name] = true;
            }
        }
    }

    foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $symbol) {
            continue;
        }
        foreach (guicheApiTypeNames($property->getType(), $r) as $name) {
            $refs[$name] = true;
        }
    }

    return array_values(array_filter(
        array_keys($refs),
        static fn(string $name): bool => str_starts_with($name, 'Asmpkg\\Guiche\\')
    ));
}

function guicheApiBuildClassification(): array
{
    guicheApiLoadAutoload();
    $symbols = guicheApiDiscoverSymbols();

    $classification = [];
    $reason = [];

    foreach ($symbols as $symbol => $meta) {
        if (str_starts_with($meta['path'], 'src/Contracts/')) {
            $classification[$symbol] = 'public';
            $reason[$symbol] = 'contrato público de extensão';
        }
    }

    $stableSeeds = [
        'Asmpkg\\Guiche\\Guiche' => 'entrypoint principal',
        'Asmpkg\\Guiche\\GuicheBuilder' => 'builder público',
        'Asmpkg\\Guiche\\ContextStore' => 'implementação padrão de ContextStore',
        'Asmpkg\\Guiche\\ToolPolicy' => 'política pública configurável',
        'Asmpkg\\Guiche\\Safety\\ToolBlockedException' => 'exceção observável pelo consumidor',
        'Asmpkg\\Guiche\\Observability\\NullRuntimeEventSink' => 'sink público padrão',
        'Asmpkg\\Guiche\\Observability\\JsonlRuntimeEventSink' => 'sink público JSONL',
    ];

    foreach ($stableSeeds as $symbol => $why) {
        if (isset($symbols[$symbol])) {
            $classification[$symbol] = 'public';
            $reason[$symbol] = $why;
        }
    }

    do {
        $changed = false;
        foreach ($classification as $symbol => $status) {
            if ($status !== 'public') {
                continue;
            }
            foreach (guicheApiReferencedPackageSymbols($symbol) as $ref) {
                if (!isset($symbols[$ref])) {
                    continue;
                }
                if (($classification[$ref] ?? null) === 'public') {
                    continue;
                }
                $classification[$ref] = 'public';
                $reason[$ref] = 'tipo exposto transitivamente por API pública';
                $changed = true;
            }
        }
    } while ($changed);

    foreach ($symbols as $symbol => $meta) {
        if (isset($classification[$symbol])) {
            continue;
        }

        if (str_starts_with($symbol, 'Asmpkg\\Guiche\\Conversation\\')) {
            $classification[$symbol] = 'experimental';
            $reason[$symbol] = 'conversation runtime ainda fora do compromisso BC estável v1';
            continue;
        }

        $classification[$symbol] = 'internal';
        $reason[$symbol] = 'detalhe de implementação não necessário à integração pública';
    }

    ksort($classification, SORT_STRING);

    $rows = [];
    foreach ($classification as $symbol => $status) {
        $rows[$symbol] = [
            'classification' => $status,
            'reason' => $reason[$symbol],
            'path' => $symbols[$symbol]['path'],
            'kind' => guicheApiKind($symbol),
        ];
    }

    return $rows;
}

function guicheApiLoadStabilityMap(): array
{
    $file = guicheApiRoot() . '/api/stability-map.php';
    if (!is_file($file)) {
        throw new RuntimeException('api/stability-map.php não encontrado.');
    }
    $map = require $file;
    if (!is_array($map)) {
        throw new RuntimeException('api/stability-map.php inválido.');
    }
    ksort($map, SORT_STRING);
    return $map;
}

function guicheApiValidateStabilityMap(array $map): array
{
    guicheApiLoadAutoload();
    $symbols = guicheApiDiscoverSymbols();

    $errors = [];
    $allowed = ['public', 'internal', 'experimental'];

    foreach ($symbols as $symbol => $_meta) {
        if (!array_key_exists($symbol, $map)) {
            $errors[] = "símbolo sem classificação: {$symbol}";
            continue;
        }
        if (!in_array($map[$symbol], $allowed, true)) {
            $errors[] = "classificação inválida para {$symbol}: {$map[$symbol]}";
        }
    }

    foreach ($map as $symbol => $status) {
        if (!isset($symbols[$symbol])) {
            $errors[] = "mapa referencia símbolo inexistente: {$symbol}";
        }
    }

    return $errors;
}

function guicheApiMethodSnapshot(ReflectionMethod $method): array
{
    $parameters = [];
    foreach ($method->getParameters() as $parameter) {
        $row = [
            'name' => $parameter->getName(),
            'type' => guicheApiTypeString($parameter->getType()),
            'by_reference' => $parameter->isPassedByReference(),
            'variadic' => $parameter->isVariadic(),
            'optional' => $parameter->isOptional(),
            'has_default' => $parameter->isDefaultValueAvailable(),
        ];

        if ($parameter->isDefaultValueAvailable()) {
            if ($parameter->isDefaultValueConstant()) {
                $row['default_constant'] = $parameter->getDefaultValueConstantName();
            } else {
                $row['default'] = guicheApiNormalizeValue($parameter->getDefaultValue());
            }
        }

        $parameters[] = $row;
    }

    return [
        'name' => $method->getName(),
        'static' => $method->isStatic(),
        'final' => $method->isFinal(),
        'abstract' => $method->isAbstract(),
        'returns_reference' => $method->returnsReference(),
        'return_type' => guicheApiTypeString($method->getReturnType()),
        'parameters' => $parameters,
    ];
}

function guicheApiSymbolSnapshot(string $symbol): array
{
    $r = new ReflectionClass($symbol);

    $methods = [];
    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $symbol) {
            continue;
        }
        $methods[$method->getName()] = guicheApiMethodSnapshot($method);
    }
    ksort($methods, SORT_STRING);

    $properties = [];
    foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $symbol) {
            continue;
        }
        $properties[$property->getName()] = [
            'name' => $property->getName(),
            'static' => $property->isStatic(),
            'readonly' => method_exists($property, 'isReadOnly') ? $property->isReadOnly() : false,
            'type' => guicheApiTypeString($property->getType()),
            'has_default' => $property->hasDefaultValue(),
            'default' => $property->hasDefaultValue() ? guicheApiNormalizeValue($property->getDefaultValue()) : null,
        ];
    }
    ksort($properties, SORT_STRING);

    $constants = [];
    foreach ($r->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $symbol) {
            continue;
        }
        $constants[$constant->getName()] = [
            'name' => $constant->getName(),
            'final' => method_exists($constant, 'isFinal') ? $constant->isFinal() : false,
            'value' => guicheApiNormalizeValue($constant->getValue()),
        ];
    }
    ksort($constants, SORT_STRING);

    $parent = $r->getParentClass();

    $interfaces = array_values(array_unique($r->getInterfaceNames()));
    sort($interfaces, SORT_STRING);

    return [
        'kind' => guicheApiKind($symbol),
        'final' => $r->isFinal(),
        'abstract' => $r->isAbstract(),
        'readonly' => method_exists($r, 'isReadOnly') ? $r->isReadOnly() : false,
        'parent' => $parent ? $parent->getName() : null,
        'interfaces' => $interfaces,
        'methods' => $methods,
        'properties' => $properties,
        'constants' => $constants,
    ];
}

function guicheApiCurrentSnapshot(array $map): array
{
    $errors = guicheApiValidateStabilityMap($map);
    if ($errors !== []) {
        throw new RuntimeException("Mapa de estabilidade inválido:\n- " . implode("\n- ", $errors));
    }

    $public = [];
    foreach ($map as $symbol => $status) {
        if ($status !== 'public') {
            continue;
        }
        $public[$symbol] = guicheApiSymbolSnapshot($symbol);
    }
    ksort($public, SORT_STRING);

    $publicJson = json_encode($public, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return [
        'schema_version' => 1,
        'package' => 'asmpkg/guiche',
        'stability_policy' => [
            'public' => 'BC commitment for v1.x',
            'experimental' => 'no BC commitment until promoted to public',
            'internal' => 'implementation detail',
        ],
        'public_api_sha256' => hash('sha256', $publicJson),
        'public_symbols' => $public,
    ];
}

function guicheApiEncode(array $value): string
{
    return json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
}

function guicheApiReadBaseline(): array
{
    $file = guicheApiRoot() . '/api/baseline-v1.json';
    if (!is_file($file)) {
        throw new RuntimeException('api/baseline-v1.json não encontrado.');
    }
    $data = json_decode(file_get_contents($file) ?: '', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('baseline-v1.json inválido.');
    }
    return $data;
}

function guicheApiCompare(array $baseline, array $current): array
{
    $errors = [];

    $baseSymbols = $baseline['public_symbols'] ?? [];
    $curSymbols = $current['public_symbols'] ?? [];

    foreach ($baseSymbols as $symbol => $baseDef) {
        if (!array_key_exists($symbol, $curSymbols)) {
            $errors[] = "símbolo público removido: {$symbol}";
            continue;
        }

        $curDef = $curSymbols[$symbol];
        if ($baseDef !== $curDef) {
            $errors[] = "assinatura/estrutura pública alterada: {$symbol}";
        }
    }

    foreach ($curSymbols as $symbol => $_curDef) {
        if (!array_key_exists($symbol, $baseSymbols)) {
            $errors[] = "novo símbolo público requer revisão explícita do freeze: {$symbol}";
        }
    }

    if (($baseline['public_api_sha256'] ?? null) !== ($current['public_api_sha256'] ?? null) && $errors === []) {
        $errors[] = 'hash da API pública mudou sem diferença estrutural identificada.';
    }

    return $errors;
}
