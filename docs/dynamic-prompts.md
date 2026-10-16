# Prompts dinâmicos — Guichê v1.4

## Contrato

```php
interface PromptResolverInterface
{
    public function resolve(PromptContext $context): string;
}
```

`PromptContext` é imutável e deriva do `execution_context` confiável. Ele pode
expor `sessionId`, `identity` e `tenant_id`, mas não recebe diretamente mensagem
atual, histórico, state, resposta da IA ou argumentos livres de Tool.

## Runtime

O prompt é resolvido uma vez por `handle()` e reutilizado em todas as rodadas
de Tool do mesmo turno.

`withInstructions()` continua compatível e pode ser composto com o prompt
dinâmico e Instruction Fragments.

## Fontes

### Static

```php
new StaticPromptResolver('Prompt fixo');
```

### File

```php
new FilePromptResolver(__DIR__ . '/prompt.md');
```

O arquivo é relido por turno. Arquivo ausente/vazio falha fechado.

### Callback / banco / API

```php
new CallbackPromptResolver(
    static fn (PromptContext $context): string =>
        $repository->promptForTenant((string)$context->tenantId()) ?? ''
);
```

O Core não acopla PDO, ORM, CMS ou cliente HTTP.

### Fallback

```text
conteúdo → usa
""       → próxima fonte
exception → FAIL-CLOSED
```

Falhas de banco/API não devem trocar silenciosamente o agente.

## Multi-tenant

```text
SESSION
→ bindIdentity()
→ execution_context.identity
→ TenantResolver
→ execution_context.tenant_id
→ PromptContext
→ PromptResolver
```

Identity é write-once por sessão e rebinding conflitante falha fechado.

## Composition Root

```php
$factory = require __DIR__ . '/guiche.php';
$guiche = $factory(['prompt_resolver' => $resolver]);
```

Veja `examples/prompt-sources/` e `examples/framework-integration/`.
