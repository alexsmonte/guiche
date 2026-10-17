# Guichê v1.6 — Declarative Loading

## Objetivo

Reduzir boilerplate no Composition Root sem esconder as APIs já existentes.

Antes:

```php
$builder->setService(
    MinhaInterface::class,
    $servico
);

$builder->loadTools(
    'ConsultarCep',
    MinhaTool::class
);

$builder->loadPlugins(
    'MeuDominio',
    OutroPlugin::class
);
```

Agora também é possível:

```php
$builder->loadCapabilities([
    'services' => [
        MinhaInterface::class => $servico,
    ],
    'tools' => [
        'ConsultarCep',
        MinhaTool::class,
    ],
    'plugins' => [
        'MeuDominio',
        OutroPlugin::class,
    ],
]);
```

## Escopo propositalmente pequeno

A declaração aceita somente:

```text
services
tools
plugins
```

Não entram no array:

- ContextStore;
- AI Provider;
- prompts;
- TTL de sessão;
- Tool Policy;
- follow-up;
- projections;
- state manager;
- runtime;
- configurações específicas de domínio.

Essas capacidades possuem contratos próprios e continuam explícitas.

## Services

Formato:

```php
'services' => [
    MinhaInterface::class => $objeto,
]
```

Regras:

- a chave precisa ser string não vazia;
- o valor precisa ser um objeto;
- não há instanciação automática de service por string;
- não há factories mágicas;
- aliases por interface continuam possíveis.

A intenção é reaproveitar exatamente `setService()` e o
`ServiceRegistry` existentes.

## Tools

Formato:

```php
'tools' => [
    'ConsultarCep',
    MinhaTool::class,
]
```

São aceitos os mesmos nomes já aceitos por `loadTool()`:

```text
ConsultarCep
App\Tool\ConsultarCepTool
Vendor\Package\MinhaTool
```

Nome curto continua seguindo:

```text
App\Tool\{Nome}Tool
```

## Plugins

Formato:

```php
'plugins' => [
    'MeuDominio',
    MeuPlugin::class,
]
```

Nome curto continua seguindo:

```text
App\Plugin\{Nome}Plugin
```

## Ordem

A ordem oficial é:

```text
1. services
2. tools
3. plugins
```

Isso garante que dependências declaradas em `services` estejam disponíveis
nos construtores das Tools e Plugins.

## Preflight

Antes de alterar o Builder real, o carregamento:

1. valida toda a estrutura da declaração;
2. cria um `ServiceRegistry` temporário;
3. resolve as classes;
4. instancia Tools e Plugins;
5. valida os respectivos contratos;
6. detecta conflitos diretos já conhecidos.

Somente depois publica as capacidades.

Assim, casos como:

```text
Tool inexistente
Plugin inexistente
dependência de construtor ausente
service malformado
lista inválida
Tool duplicada declarada diretamente
Plugin duplicado declarado diretamente
```

falham antes da aplicação normal da declaração.

## Plugins continuam responsáveis pelo próprio registro

Ao executar:

```php
$builder->registerPlugin($plugin);
```

continua valendo a atomicidade já existente do registro individual de Plugin.

A Fase 09 não cria um container transacional global sobre todo o Builder.

## Strict config

Chaves desconhecidas falham.

Isto:

```php
$builder->loadCapabilities([
    'tool' => ['MinhaTool'],
]);
```

não é convertido silenciosamente para `tools`.

O erro de digitação precisa ser corrigido.

## Compatibilidade

Continuam válidos e recomendados quando forem mais claros:

```php
$builder->setService(...);
$builder->loadTool(...);
$builder->loadTools(...);
$builder->loadPlugin(...);
$builder->loadPlugins(...);
$builder->registerTool(...);
$builder->registerPlugin(...);
```

`loadCapabilities()` é apenas uma camada aditiva de DX.

## Uso com arquivo PHP

Um projeto consumidor pode manter:

```php
// config/capabilities.php

return [
    'services' => [
        MinhaInterface::class => $container->get(
            MinhaInterface::class
        ),
    ],
    'tools' => [
        'ConsultarCep',
    ],
    'plugins' => [
        'MeuDominio',
    ],
];
```

e no Composition Root:

```php
$builder->loadCapabilities(
    require __DIR__ . '/config/capabilities.php'
);
```

O Guichê não passa a ser responsável por localizar esse arquivo.
