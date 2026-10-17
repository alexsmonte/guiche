# External Consumer Guide

Este guia prova a fronteira pública do Guichê: uma aplicação externa pode instalar a biblioteca, fornecer seu próprio provider, Tool, Plugin e ContextStore e continuar protegida pelo hard execution gate sem editar `src/`.

## Instalação

Na linha estável v1:

```bash
composer require asmpkg/guiche:^1.0
```

Requisitos oficiais da linha v1: consulte [`php-support.md`](php-support.md). A política de estabilidade está em [`api-stability.md`](api-stability.md).


## Bootstrap pela CLI na v1.2

Para uma aplicação que quer começar com o runtime padrão, a v1.2 elimina a
necessidade de criar um entrypoint de chat manual:

```bash
vendor/bin/guiche init
vendor/bin/guiche doctor
vendor/bin/guiche chat
```

`init` cria `.env`, `guiche.prompt.md` e `storage/conversations/` no projeto
consumidor sem sobrescrever personalizações existentes.

O prompt pode vir de `GUICHE_PROMPT_FILE`, `GUICHE_PROMPT`,
`guiche.prompt.md` ou do fallback distribuído pelo pacote.

A CLI é uma camada de Developer Experience. Ela monta o runtime por
`GuicheBuilder` e não contorna Safety Layer, Tool Policy, hard execution gate ou
idempotência.

Aplicações com bootstrap próprio podem continuar usando diretamente os contratos
públicos descritos abaixo.

## Contratos usados

Uma integração normal depende principalmente de:

- `Asmpkg\Guiche\Contracts\AiProviderInterface`
- `Asmpkg\Guiche\Contracts\ToolInterface`
- `Asmpkg\Guiche\Contracts\PluginInterface`
- `Asmpkg\Guiche\Contracts\ContextStoreInterface`
- `Asmpkg\Guiche\GuicheBuilder`
- `Asmpkg\Guiche\Plugin\ToolRegistration`

Esses símbolos estão na superfície pública congelada para a v1.

## 1. Tool externa

```php
use Asmpkg\Guiche\Contracts\ToolInterface;

final class PublishCatalogItemTool implements ToolInterface
{
    public function name(): string
    {
        return 'PublishCatalogItem';
    }

    public function description(): string
    {
        return 'Publica um item de catálogo após as preconditions da aplicação.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string'],
            ],
            'required' => ['sku'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        // O side effect só será alcançado se o hard execution gate autorizar.
        return ['success' => true, 'sku' => $arguments['sku']];
    }
}
```

## 2. Plugin externo com Safety metadata

```php
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\GuicheBuilder;

final class CatalogPlugin implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder->registerTool(new Asmpkg\Guiche\Plugin\ToolRegistration(
            new PublishCatalogItemTool(),
            [
                'operation' => 'write',
                'requires_state' => ['state.release_approved'],
            ]
        ));

    }
}
```

`requires_state` é validado imediatamente antes de `ToolInterface::execute()`. Se `state.release_approved` não existir, a Tool é bloqueada e o side effect não ocorre.

> O exemplo mínimo não usa `addInstructionFragment()`. Instruction fragments são uma capacidade adicional do Builder, mas não são necessários para registrar uma Tool segura nem para validar o acceptance externo da linha v1.

## 3. ContextStore externo

Implemente `ContextStoreInterface` quando quiser trocar o backend padrão. O Core depende do contrato, não de filesystem específico.

O backend precisa preservar, no mínimo, as operações declaradas pelo contrato: load/save/delete, mensagens, state patches, histórico de Tool, replay idempotente e usage. A aplicação decide se isso será JSON, SQL, Redis ou outro storage.

```php
use Asmpkg\Guiche\Contracts\ContextStoreInterface;

final class ApplicationContextStore implements ContextStoreInterface
{
    // Implemente os métodos do contrato no backend da sua aplicação.
}
```

O comando abaixo mostra a assinatura exata instalada:

```bash
php -r '
require "vendor/autoload.php";
$r = new ReflectionClass(Asmpkg\Guiche\Contracts\ContextStoreInterface::class);
foreach ($r->getMethods() as $m) echo $m->getName(), PHP_EOL;
'
```

## 4. Provider externo

O provider implementa `AiProviderInterface`, devolvendo a resposta normalizada do Guichê em `respond()` e `continueWithToolOutputs()`.

```php
use Asmpkg\Guiche\Contracts\AiProviderInterface;

final class ApplicationAiProvider implements AiProviderInterface
{
    public function respond(string $instructions, array $messages, array $tools = []): array
    {
        // Traduza seu provider para o formato normalizado do Guichê.
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        // Continue a conversa após os resultados/bloqueios das Tools.
    }
}
```

## 5. Montagem do runtime

```php
use Asmpkg\Guiche\GuicheBuilder;

$builder = new GuicheBuilder();
$builder->withContextStore($contextStore);
$builder->withAiProvider($provider);
$builder->registerPlugin(new CatalogPlugin());

$guiche = $builder->build();
$result = $guiche->handle('session-123', 'publique o item SKU-001');
```

## 6. O que o hard execution gate garante

O registro de uma Tool não equivale a autorização de execução. Antes de `execute()` o Core revalida:

1. Tool registrada;
2. operação READ/WRITE;
3. Tool Policy;
4. estágio permitido, quando declarado;
5. `requires_state`;
6. `forbids_state`;
7. confirmação, quando declarada;
8. argumentos;
9. regras de WRITE/idempotência.

Uma chamada de Tool produzida pela IA não consegue contornar esse gate.

## 7. WRITE e idempotência

Tools WRITE não usam cache genérico. O `ContextStoreInterface` participa do fingerprint/replay para evitar repetir automaticamente o mesmo side effect na mesma sessão.

## 8. Separação de domínio

Nada neste fluxo depende do exemplo clínico. O exemplo em `examples/clinica-piloto/` é apenas uma demonstração avançada de domínio.

Para a menor experiência funcional, use `vendor/bin/guiche init` e `vendor/bin/guiche chat`. Para estudar Plugin + Tool, use `examples/basic/`.

## 9. Checklist do consumidor

- instale `asmpkg/guiche`;
- opcionalmente inicialize o runtime padrão com `vendor/bin/guiche init`;
- implemente/seleciona um `AiProviderInterface`;
- crie suas `ToolInterface`;
- agrupe-as em `PluginInterface`;
- escolha um `ContextStoreInterface`;
- declare Safety metadata;
- monte tudo via `GuicheBuilder`;
- execute seus testes de domínio sem modificar o Core.

<!-- guiche-v15-consumer:start -->
## 10. Convenções de projeto na v1.5

Para usar os loaders curtos no projeto consumidor:

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

Depois de adicionar esse prefixo pela primeira vez:

```bash
composer dump-autoload
```

Estrutura recomendada:

```text
src/
├── Tool/
│   └── InformacoesEmpresaTool.php
└── Plugin/
    └── ClinicaPlugin.php
```

O Composition Root aceita simultaneamente a forma antiga e a nova:

```php
$guiche = $factory([
    'services' => [
        MinhaDependencia::class => $dependencia,
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

`services` são registrados antes dos loaders.

O nome curto nunca deve vir da IA ou de input livre. Ele pertence à configuração
do host.

## 11. Prompt multi-tenant em arquivos

O `init` da v1.5 cria:

```text
prompts/comportamento.md
```

e você pode acrescentar:

```text
prompts/{tenant_id}.md
```

O tenant é derivado de `bindIdentity()` + `TenantResolver`, não da mensagem.

Instalações antigas que ainda usam `guiche.prompt.md` continuam compatíveis.
<!-- guiche-v15-consumer:end -->
