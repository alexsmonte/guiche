# Guichê v1.6 — Multiple Prompts

## Objetivo

Permitir compor várias fontes de prompt mantendo `PromptResolverInterface`
como contrato único.

A v1.6 adiciona:

```php
CompositePromptResolver
GuicheBuilder::withPromptResolvers(...)
```

## Composite x Fallback

`FallbackPromptResolver` usa a primeira fonte não vazia.

`CompositePromptResolver` concatena todas as fontes não vazias na ordem
declarada.

Exemplo:

```php
$builder->withPromptResolvers(
    new FilePromptResolver('/prompts/global.md'),
    static fn (PromptContext $context): string =>
        carregarPromptTenant($context->tenantId()),
    new FilePromptResolver('/prompts/compliance.md')
);
```

Resultado conceitual:

```text
global

tenant

compliance
```

## Regras

- ordem de registro é preservada;
- string vazia significa segmento opcional ausente;
- todos vazios falham fechado;
- exceptions não são capturadas;
- uma falha interrompe a composição;
- cada resolver é executado uma vez por turno;
- continuações após Tool reutilizam o prompt já resolvido;
- `withPromptResolver()` permanece compatível;
- a última chamada entre `withPromptResolver()` e `withPromptResolvers()`
  define a configuração usada pelo Builder.

## Composição com Fallback

As estratégias podem ser aninhadas:

```php
$tenant = new FallbackPromptResolver(
    $tenantDatabase,
    $tenantDefaultFile
);

$full = new CompositePromptResolver(
    $global,
    $tenant,
    $compliance
);
```

## TenantPromptResolver

`TenantPromptResolver` continua intacto. Ele já implementa a composição
específica:

```text
comportamento.md + {tenant_id}.md
```

O novo Composite é genérico e não substitui essa classe.

## Segurança

Todos os filhos recebem o mesmo `PromptContext` confiável.

Texto do usuário, output da IA e argumentos livres de Tool não participam
diretamente da escolha do prompt.

## API pública aditiva

```php
Asmpkg\Guiche\Prompt\CompositePromptResolver

GuicheBuilder::withPromptResolvers(
    PromptResolverInterface|Closure ...$resolvers
): self
```

Nenhuma assinatura pública anterior é modificada.
