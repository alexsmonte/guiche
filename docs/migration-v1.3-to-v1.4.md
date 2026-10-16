# Migração Guichê v1.3 → v1.4

A v1.4 é minor e aditiva.

## Atualizar

```bash
composer require asmpkg/guiche:^1.4
vendor/bin/guiche init
```

`init` pode criar `guiche.php`; arquivos existentes são preservados.

## Compatibilidade

Continuam válidos:

- `ToolInterface`;
- `ContextStoreInterface`;
- `TenantResolverInterface`;
- `Guiche::bindIdentity()`;
- `GuicheBuilder::withInstructions()`.

Projetos antigos sem `guiche.php` continuam usando o bootstrap legado.

## Novidades

- `PromptResolverInterface`;
- `PromptContext`;
- `CallbackPromptResolver`;
- `StaticPromptResolver`;
- `FilePromptResolver`;
- `FallbackPromptResolver`;
- `GuicheBuilder::withPromptResolver()`;
- `guiche.php` como Composition Root.

Exemplo:

```php
$builder->withPromptResolver(
    new FilePromptResolver(__DIR__ . '/prompt.md')
);
```

Ou Closure:

```php
$builder->withPromptResolver(
    static fn (PromptContext $context): string =>
        'Prompt ' . (string)$context->tenantId()
);
```

## Segurança

Para prompt multi-tenant use:

```text
host confiável → bindIdentity() → TenantResolver
→ PromptContext → PromptResolver
```

Não derive tenant da mensagem do usuário.

`FallbackPromptResolver` avança apenas para retorno vazio; exceptions propagam.
