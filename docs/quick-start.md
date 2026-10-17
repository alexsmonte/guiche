# Quick Start do Guichê v1.5

## Instalação

```bash
mkdir meu-guiche
cd meu-guiche
composer require asmpkg/guiche:^1.5
vendor/bin/guiche init
composer dump-autoload
```

O `init` é idempotente e cria, sem sobrescrever personalizações:

```text
.env
guiche.prompt.md
guiche.php
prompts/
└── comportamento.md
src/
├── Tool/
└── Plugin/
storage/
└── conversations/
```

Quando existe `composer.json`, o `init` garante:

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

Se o prefixo foi adicionado pelo `init`, execute `composer dump-autoload` uma
vez para atualizar o autoloader instalado.

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

## Tool por convenção

Crie:

```text
src/Tool/InformacoesEmpresaTool.php
```

com namespace:

```php
namespace App\Tool;
```

Carregue:

```php
$builder->loadTool('InformacoesEmpresa');
```

Resolução:

```text
InformacoesEmpresa
→ App\Tool\InformacoesEmpresaTool
```

FQCN também é aceito.

## Plugin por convenção

Crie:

```text
src/Plugin/ClinicaPlugin.php
```

com namespace:

```php
namespace App\Plugin;
```

Carregue:

```php
$builder->loadPlugin('Clinica');
```

`registerTool()` e `registerPlugin()` continuam disponíveis quando você quer
passar instâncias explicitamente.

## Dependências de construtor

A v1.5 não faz autowiring recursivo irrestrito.

Registre serviços explicitamente:

```php
$builder
    ->setService(MinhaConexao::class, $conexao)
    ->loadTool('InformacoesEmpresa');
```

Uma dependência obrigatória ausente falha de forma explícita.

## Composition Root

`guiche.php` retorna uma factory:

```php
$factory = require __DIR__ . '/guiche.php';

$guiche = $factory([
    'ai_provider' => $aiProvider,
    'context_store' => $contextStore,
    'tenant_resolver' => $tenantResolver,

    'services' => [
        MinhaConexao::class => $conexao,
    ],

    'tools' => [
        'InformacoesEmpresa',
        $toolEmInstancia,
    ],

    'plugins' => [
        'Clinica',
        $pluginEmInstancia,
    ],
]);
```

Services são registrados antes dos loaders.

## Prompt multi-tenant

Prompt global:

```text
prompts/comportamento.md
```

Prompt opcional de tenant:

```text
prompts/{tenant_id}.md
```

Exemplo:

```text
prompts/comportamento.md
prompts/empresa-42.md
```

Com `tenant_id=empresa-42`:

```text
comportamento global
        +
regras do tenant empresa-42
```

Prioridade:

```text
$app['prompt_resolver']
→ GUICHE_PROMPT_FILE
→ GUICHE_PROMPT
→ prompts/comportamento.md + prompts/{tenant_id}.md
→ guiche.prompt.md legado
```

O `tenant_id` é derivado do contexto confiável:

```php
$guiche->bindIdentity(
    $sessionId,
    $trustedIdentity
);

$result = $guiche->handle(
    $sessionId,
    $message
);
```

Mensagem, state e argumentos livres de Tool não selecionam tenant.

## Segurança dos loaders

Os nomes enviados para:

```php
loadTool(...)
loadPlugin(...)
```

devem vir de configuração de bootstrap controlada pela aplicação.

Não faça:

```text
mensagem do usuário → nome da classe → loadTool()
```

nem:

```text
saída da IA → nome do Plugin → loadPlugin()
```

Os loaders não fazem scanning de diretório, `glob`, `require` ou `include`.
A descoberta de classes pertence ao Composer/PSR-4.

Veja:

- `docs/tools.md`;
- `docs/plugins.md`;
- `docs/dynamic-prompts.md`;
- `docs/migration-v1.4-to-v1.5.md`.
