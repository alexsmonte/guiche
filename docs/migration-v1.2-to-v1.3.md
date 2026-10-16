# Migração — Guichê v1.2 para v1.3

A v1.3 é uma release **minor compatível**.

Nenhuma API pública existente da v1.2 foi removida.

## Instalação

```bash
composer require asmpkg/guiche:^1.3
```

## O que muda para integrações externas

Na v1.2/v1.1, integrações avançadas podiam usar diretamente
`ExecutionContextSeeder`.

Na v1.3, o caminho recomendado passa a ser:

```php
$guiche->bindIdentity(
    $sessionId,
    $identity
);

$response = $guiche->handle(
    $sessionId,
    $message
);
```

## Antes

```php
$seeder = new ExecutionContextSeeder($contextStore);

$seeder->seed(
    $sessionId,
    ['identity' => $identity]
);

$response = $guiche->handle(
    $sessionId,
    $message
);
```

## Depois

```php
$guiche->bindIdentity(
    $sessionId,
    $identity
);

$response = $guiche->handle(
    $sessionId,
    $message
);
```

## TenantResolver

Quando configurado, o resolver é acionado pelo próprio binding.

Não é necessário executar a resolução manualmente.

## Compatibilidade

Continuam válidos:

- `ToolInterface`;
- Tools legadas;
- `ContextStoreInterface`;
- `ContextAwareToolInterface`;
- `TenantResolverInterface`;
- `ToolExecutionContext`;
- `ExecutionContextSeeder`, para compatibilidade/uso avançado.

## Segurança

A aplicação hospedeira continua responsável por estabelecer a confiança no
request externo antes de chamar `bindIdentity()`.

Não migre campos recebidos diretamente da IA para identity.

## Chatwoot

A v1.3 inclui um adapter de referência em `examples/chatwoot/`.

Ele é opcional e não adiciona dependência Chatwoot ao Core.

## Concorrência

A v1.3 serializa `bindIdentity()` e `handle()` por sessão.

Não é necessário adicionar lock externo para proteger especificamente essa
fronteira quando ambos usam o runtime padrão do Guichê.

## SemVer

A adição de `Guiche::bindIdentity()` expande a API pública sem quebrar
assinaturas anteriores, portanto a versão correta é `1.3.0`, e não `2.0.0`.
