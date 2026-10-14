# Plugins no Guichê

Plugins são o mecanismo principal para adicionar um domínio ou conjunto de capacidades sem editar o Core.

Um Plugin implementa `PluginInterface`.

## Contrato

O contrato público é baseado em um único ponto de registro:

```php
interface PluginInterface
{
    public function register(GuicheBuilder $builder): void;
}
```

O Plugin recebe o Builder e registra suas capacidades.

## Capacidades registráveis

A API pública atual do `GuicheBuilder` inclui:

```text
registerTool(...)
registerPresenter(...)
useStateManager(...)
registerPolicy(...)
addInstructionFragment(...)
registerCompletionGuard(...)
registerResultProjection(...)
```

O Builder também recebe infraestrutura da aplicação, como Context Store e AI Provider.

## Exemplo mínimo

```php
final class BasicPlugin implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder->registerTool(
            new ToolRegistration(
                new CatalogTool(),
                [
                    'operation' => 'read',
                    'domain' => 'basic-example',
                ]
            )
        );

        $builder->addInstructionFragment(
            'basic.catalog',
            'Use CatalogSearch para consultar o catálogo.'
        );
    }
}
```

A assinatura exata dos métodos públicos deve ser tratada como API da versão instalada.

## Registro na aplicação

```php
$builder = new GuicheBuilder();

$builder->withContextStore($context);
$builder->withAiProvider($provider);
$builder->registerPlugin(new BasicPlugin());

$guiche = $builder->build();
```

A aplicação não precisa editar `ToolRegistry` nem `Guiche` para instalar o Plugin.

## Tool

O Plugin pode registrar uma ou mais Tools por `ToolRegistration`.

Metadados de operação e segurança acompanham o registro.

Veja `docs/tools.md`.

## Presenter

Um `ResultPresenterInterface` pode adaptar a apresentação do resultado de uma Tool.

O Presenter é opcional e deve ser usado quando a forma de apresentar um resultado pertence ao domínio, não ao Core.

## State Manager

Um Plugin pode fornecer `StateManagerInterface`.

O State Manager transforma mensagens e resultados de Tool em patches de estado.

Isso permite que a conversa possua regras de domínio sem colocá-las em `Guiche.php`.

Veja `docs/state.md`.

## Policy

Plugins podem registrar Policy declarativa e associar regras de execução ao runtime.

Policy não substitui o hard execution gate. Ela participa da política que o gate aplica.

## Instruction fragments

Um Plugin pode adicionar instruções ao prompt composto.

Use fragments para explicar ao modelo como usar as capacidades do Plugin.

Não use fragments como única barreira para operações perigosas.

```text
prompt = orientação
Safety Layer = enforcement
```

## Completion Guards

Completion Guards podem impedir que o turno seja considerado concluído de forma incompatível com o estado e as operações necessárias.

Eles são registrados como capacidade declarativa do Builder.

## Result Projections

Plugins podem declarar projeções para limitar quais partes de um resultado de Tool são devolvidas à IA.

Isso é especialmente importante para integrações com payloads ricos ou infraestrutura externa.

## Conflitos

O Builder trata conflitos como erros explícitos.

Casos importantes:

- mesma classe de Plugin registrada duas vezes;
- duas Tools com o mesmo nome;
- conflito de State Manager;
- falha parcial durante registro.

O registro de Plugin deve preservar atomicidade: um Plugin que falha não deve deixar um conjunto parcial de capacidades instalado.

## Ordem

Instruction fragments preservam a ordem de registro.

Ao compor múltiplos Plugins, considere a ordem como parte da configuração da aplicação.

## Snapshot no build

`build()` produz o runtime a partir da configuração atual.

A instância construída é tratada como snapshot da composição. Alterar o Builder depois não deve ser usado como forma de modificar retroativamente um runtime já construído.

## Plugin neutro

`examples/neutral-plugin/` existe para provar que um Plugin externo ao Core pode integrar:

- Tool;
- State Manager;
- Presenter;
- instruction fragment;

sem ser colocado em `src/`.

## BasicPlugin

`examples/basic/` é o exemplo recomendado para começar.

Ele prioriza legibilidade:

```text
BasicPlugin
    ↓
CatalogSearch
    ↓
catálogo local
```

Não há banco, API privada ou regra clínica.

## Plugin de domínio

Um Plugin real pode agrupar:

```text
MeuDominioPlugin
├── Tools/
├── Presentation/
├── StateManager
├── policies
├── instructions
├── completion guards
└── result projections
```

O limite arquitetural permanece: capacidades do domínio entram pelo Plugin; o Core não é reescrito para conhecê-las.

## Checklist de um Plugin

Antes de considerar um Plugin pronto:

1. implementa `PluginInterface`;
2. não exige alteração no Core;
3. Tools possuem nomes únicos;
4. READ/WRITE está correto;
5. schemas são restritivos;
6. preconditions estão declaradas;
7. State Manager não vaza regras para o Core;
8. Presenters são opcionais e específicos;
9. projections minimizam retorno;
10. testes cobrem registro, conflitos e execução;
11. falha de registro não deixa configuração parcial.
