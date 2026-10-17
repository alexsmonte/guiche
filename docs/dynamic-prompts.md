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

<!-- guiche-v15-tenant-prompt:start -->
## TenantPromptResolver — v1.5

A v1.5 inclui uma implementação pública para o layout:

```text
prompts/
├── comportamento.md
├── empresa-42.md
└── empresa-99.md
```

Uso:

```php
use Asmpkg\Guiche\Prompt\TenantPromptResolver;

$resolver = new TenantPromptResolver(__DIR__);
```

O arquivo global é obrigatório e não pode estar vazio.

Sem tenant:

```text
comportamento.md
```

Com `tenant_id=empresa-42`:

```text
comportamento.md

empresa-42.md
```

O arquivo de tenant é opcional. Se não existir ou estiver vazio, somente o
global é usado.

O conteúdo é relido em cada `resolve()`, permitindo atualização sem manter
prompt antigo em memória.

### Sanitização

A chave do tenant é transformada com uma whitelist equivalente a:

```text
A-Z a-z 0-9 . _ -
```

outros caracteres são substituídos por `_`.

Isso evita usar `tenant_id` como caminho arbitrário. O tenant continua vindo do
`PromptContext` confiável; sanitização de filename não substitui a regra de
autoridade.

### Prioridade no Composition Root

```text
$app['prompt_resolver']
→ GUICHE_PROMPT_FILE
→ GUICHE_PROMPT
→ TenantPromptResolver quando prompts/comportamento.md existe
→ guiche.prompt.md legado
```

Assim a v1.5 adota o layout novo sem quebrar consumidores da v1.4.
<!-- guiche-v15-tenant-prompt:end -->
