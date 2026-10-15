# Guichê v1.1.0 — Release Notes

## Contexto dinâmico multi-tenant para Tools

A v1.1.0 introduz contexto confiável de execução para aplicações em que a
mesma Tool precisa operar em tenants diferentes.

```text
SESSION → IDENTITY → TENANT → TOOL
```

## Destaques

- `ContextAwareToolInterface`;
- `ToolExecutionContext`;
- `ExecutionContextSeeder`;
- `TenantResolverInterface`;
- `TenantResolutionService`;
- resolvers SQL, HTTP e callback/repository;
- `GuicheBuilder::withTenantResolver()`;
- suporte a Presenters em Tools contextuais;
- bindings imutáveis de identity/tenant;
- exemplo clínico multi-tenant;
- acceptance concorrente e adversarial.

## Segurança

Tenant deixou de ser responsabilidade da IA.

Uma aplicação pode derivar tenant de:

- phone number/canal;
- API key/account;
- workspace;
- repository;
- SELECT;
- Stored Procedure;
- API HTTP.

A identity é vinculada à sessão e o tenant resolvido não pode ser trocado
posteriormente.

ContextAware Tools sem contexto válido falham antes de side effects.

## Backward compatibility

Esta release é aditiva.

Permanecem compatíveis:

- `ToolInterface`;
- `ContextStoreInterface`;
- Tools legadas;
- ToolRegistry;
- Safety Layer;
- READ cache;
- WRITE replay/idempotência;
- Result Projection;
- Conversation Runtime experimental.

## API freeze

- Public: 32;
- Experimental: 11;
- Internal: 12;
- Public SHA-256:
  `f4328ccc4724f0e097e0d9369329a6bb3bbfe27bca5cddc8d452f43a63130c41`.

## Validação

Antes da preparação documental final:

- 173 testes;
- 736 assertions;
- PHPStan sem erros;
- coverage de linhas/statements: 76,65%;
- acceptance de concorrência com oito workers;
- hard execution gate aprovado;
- WRITE safety aprovado;
- clinical safety aprovado;
- API freeze aprovado.

A Fase 07 executa novamente o acceptance v1.1 e `composer check` antes de
declarar o commit pronto para merge/tag.

## Upgrade

```bash
composer require asmpkg/guiche:^1.1
```

Consumidores que não usam contexto multi-tenant não precisam alterar suas
Tools.

Veja `docs/migration-v1.0-to-v1.1.md`.
