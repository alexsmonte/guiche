# Quick Start do Guichê v1.4

## Instalação

```bash
mkdir meu-guiche
cd meu-guiche
composer require asmpkg/guiche:^1.4
vendor/bin/guiche init
```

O `init` cria sem sobrescrever:

```text
.env
guiche.prompt.md
guiche.php
storage/conversations/
```

Configure:

```dotenv
AI_API_KEY=sua-chave
AI_MODEL=gpt-5.6-luna
```

Valide:

```bash
vendor/bin/guiche doctor
```

Converse:

```bash
vendor/bin/guiche chat
vendor/bin/guiche chat --session=cliente-123 "Olá"
```

## Composition Root

`guiche.php` retorna uma factory:

```php
$factory = require __DIR__ . '/guiche.php';

$guiche = $factory([
    'ai_provider' => $aiProvider,
    'context_store' => $contextStore,
    'tenant_resolver' => $tenantResolver,
    'prompt_resolver' => $promptResolver,
    'tools' => $tools,
    'plugins' => $plugins,
]);
```

## Prompt

Prioridade padrão da v1.4:

```text
GUICHE_PROMPT_FILE
→ GUICHE_PROMPT
→ guiche.prompt.md
```

Resolvers públicos:

- `StaticPromptResolver`;
- `FilePromptResolver`;
- `CallbackPromptResolver`;
- `FallbackPromptResolver`.

## Multi-tenant

```php
$guiche->bindIdentity($sessionId, $trustedIdentity);
$result = $guiche->handle($sessionId, $message);
```

```text
SESSION → identity → TenantResolver → tenant_id
→ PromptContext → PromptResolver → prompt
```

Mensagem, state e argumentos livres de Tool não selecionam tenant.

Veja `docs/dynamic-prompts.md` e `docs/migration-v1.3-to-v1.4.md`.
