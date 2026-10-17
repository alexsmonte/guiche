# Migração Guichê v1.4 → v1.5

A v1.5 é uma evolução **SemVer minor, aditiva e compatível**.

Não é necessário reescrever Tools ou Plugins existentes.

## O que permanece igual

Continuam válidos:

```php
$builder->registerTool($tool);
$builder->registerPlugin($plugin);
$builder->withInstructions($instructions);
$builder->withPromptResolver($resolver);
```

Também permanecem inalterados os contratos principais:

- `ToolInterface`;
- `PluginInterface`;
- `ContextStoreInterface`;
- `AiProviderInterface`;
- `TenantResolverInterface`.

## O que foi adicionado

### Services

```php
$builder->setService(
    MinhaDependencia::class,
    $dependencia
);
```

### Tool loader

```php
$builder->loadTool('InformacoesEmpresa');
```

resolve:

```text
App\Tool\InformacoesEmpresaTool
```

### Plugin loader

```php
$builder->loadPlugin('Clinica');
```

resolve:

```text
App\Plugin\ClinicaPlugin
```

### Prompt por tenant

```text
prompts/comportamento.md
prompts/{tenant_id}.md
```

## Migração recomendada

Atualize:

```bash
composer require asmpkg/guiche:^1.5
```

Execute:

```bash
vendor/bin/guiche init
composer dump-autoload
vendor/bin/guiche doctor
```

O `init` não sobrescreve `.env`, `guiche.php`, `guiche.prompt.md` nem um
`App\\` conflitante.

## Composer/PSR-4

Para nomes curtos, o consumidor precisa ter:

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

O `init` adiciona esse prefixo quando ele não existe.

Se `App\\` já aponta para outro diretório, o Guichê preserva o valor e o
`doctor` reporta a incompatibilidade.

## Migrando o prompt

Você pode continuar com:

```text
guiche.prompt.md
```

ou adotar:

```text
prompts/comportamento.md
```

Quando `prompts/comportamento.md` existe, o Composition Root padrão usa
`TenantPromptResolver`.

Prioridade:

```text
$app['prompt_resolver']
→ GUICHE_PROMPT_FILE
→ GUICHE_PROMPT
→ prompts/comportamento.md + prompts/{tenant_id}.md
→ guiche.prompt.md
```

## Migrando Tools gradualmente

Antes:

```php
$builder->registerTool(
    new InformacoesEmpresaTool($dependencia)
);
```

Depois, opcionalmente:

```php
$builder
    ->setService(
        MinhaDependencia::class,
        $dependencia
    )
    ->loadTool('InformacoesEmpresa');
```

As duas formas podem coexistir.

## Migrando Plugins gradualmente

Antes:

```php
$builder->registerPlugin(
    new ClinicaPlugin($dependencia)
);
```

Depois, opcionalmente:

```php
$builder
    ->setService(
        MinhaDependencia::class,
        $dependencia
    )
    ->loadPlugin('Clinica');
```

## Limites da DI

A v1.5 não oferece container completo nem autowiring recursivo.

Regras:

- dependência de classe/interface deve estar explicitamente registrada;
- parâmetro builtin obrigatório sem default falha;
- default/nullable pode ser usado quando permitido pelo construtor;
- falha é explícita e sugere `setService()`.

## Segurança

### Tenant

`tenant_id` continua vindo somente de contexto confiável:

```text
host autenticado
→ bindIdentity()
→ TenantResolver
→ tenant_id
```

### Loaders

Nomes passados para `loadTool()` e `loadPlugin()` são configuração do bootstrap.

Nunca faça:

```text
input do usuário
→ nome da classe
→ loadTool/loadPlugin
```

Nem permita que a IA escolha FQCN.

## Checklist

- atualizar para `^1.5`;
- executar `vendor/bin/guiche init`;
- executar `composer dump-autoload`;
- executar `vendor/bin/guiche doctor`;
- adotar `src/Tool` e `src/Plugin` se quiser loaders curtos;
- registrar dependências com `setService()`;
- migrar para `prompts/comportamento.md` quando quiser prompt por tenant;
- manter `tenant_id` e nomes de classes fora do controle da IA.
