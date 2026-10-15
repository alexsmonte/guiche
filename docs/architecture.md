# Arquitetura do Guichê

Este documento descreve a arquitetura existente do Guichê na linha `v0.7.x`.

O objetivo central é manter o **Core genérico** e permitir que capacidades de domínio sejam conectadas por contratos públicos, sem editar o núcleo para cada novo caso de uso.

## Visão geral

```text
Aplicação / Canal
       │
       ├─────────────── eventos recebidos
       │
       ▼
Conversation Runtime
(quando processar)
       │
       ▼
Guiche
(como processar o turno)
       │
       ├── Context Store
       ├── State Manager
       ├── AI Provider
       ├── Tool Registry
       │      │
       │      ▼
       │   Safety Gate
       │      │
       │      ▼
       │     Tools
       │
       ├── Presenters
       └── Runtime Event Sink
```

A separação conceitual é:

```text
ConversationRuntime = quando processar
Guiche              = como processar/raciocinar o turno
```

## Core

As principais classes de composição e execução são:

- `Guiche`;
- `GuicheBuilder`;
- `ToolRegistry`;
- `PresenterRegistry`;
- `ToolPolicy`;
- `RuntimeContext`.

O Core não deve depender de uma clínica, paciente, convênio, agenda ou outro domínio específico.

## Contratos

As extensões são orientadas a interfaces em `src/Contracts/`.

Entre os contratos públicos existentes estão:

- `AiProviderInterface`;
- `ContextStoreInterface`;
- `ConversationStoreInterface`;
- `PluginInterface`;
- `ResultPresenterInterface`;
- `RuntimeEventSinkInterface`;
- `StateManagerInterface`;
- `ToolInterface`.

Esses contratos formam as principais fronteiras de substituição do framework.

## GuicheBuilder

`GuicheBuilder` é o ponto de composição para aplicações e Plugins.

A API pública atual permite, entre outras capacidades:

```text
withContextStore(...)
withAiProvider(...)
registerTool(...)
registerPresenter(...)
useStateManager(...)
registerPolicy(...)
addInstructionFragment(...)
registerCompletionGuard(...)
registerResultProjection(...)
registerPlugin(...)
build()
```

A aplicação pode montar o runtime diretamente pelo Builder ou registrar Plugins que adicionem capacidades ao Builder.

## Fluxo de um turno

Em uma interação síncrona, o fluxo conceitual é:

```text
mensagem
   ↓
Guiche::handle()
   ↓
carrega contexto
   ↓
StateManager::onUserMessage()
   ↓
persiste mensagem
   ↓
monta instruções + state + histórico + Tools visíveis
   ↓
AI Provider
   ↓
┌─────────────────────────────┐
│ resposta textual            │
│ ou                          │
│ solicitação de Tool call    │
└─────────────────────────────┘
              ↓ Tool call
        Safety Layer
              ↓
             Tool
              ↓
       persiste execução
              ↓
 StateManager::onToolResult()
              ↓
      resultado para IA
              ↓
       resposta final
```

O runtime pode realizar mais de uma rodada de Tool calls até produzir a resposta final, respeitando os limites configurados.

## Tool Registry

`ToolRegistry` mantém as Tools disponíveis ao runtime.

O registro contém a Tool e pode carregar metadados declarativos por meio de `ToolRegistration`.

A definição enviada ao provider permanece separada dos metadados internos de execução e segurança.

## Presenter Registry

`PresenterRegistry` permite associar uma apresentação específica ao resultado de uma Tool sem incorporar lógica de domínio ao Core.

O Presenter é opcional.

## Plugin API

Plugins implementam `PluginInterface` e recebem `GuicheBuilder` em `register()`.

```text
Plugin
  ↓
GuicheBuilder
  ├── Tool
  ├── Presenter
  ├── StateManager
  ├── Policy
  ├── Instruction Fragment
  ├── Completion Guard
  └── Result Projection
```

Veja `docs/plugins.md`.

## Safety Layer

O Guichê possui uma camada explícita de segurança de execução composta por:

- `ToolSafetyMetadata`;
- `ToolArgumentValidator`;
- `ToolExecutionGate`;
- `ToolBlockedException`.

A segurança não depende apenas do prompt. Existe enforcement no caminho de execução da Tool.

Veja `docs/security.md`.

## Estado e persistência

O Core depende de `ContextStoreInterface`, não de um backend específico.

A implementação `ContextStore` fornece persistência baseada em filesystem para o runtime atual, enquanto o contrato permite outros backends.

O estado de domínio é transformado por `StateManagerInterface`.

Veja `docs/state.md`.

## Conversation Runtime

A camada `src/Conversation/` contém mecanismos para processar conversas além de uma chamada síncrona isolada.

Entre os componentes existentes estão:

- `IncomingEvent`;
- `IncomingEventRecorder`;
- `MessageBuffer`;
- `ConversationTurn`;
- `ConversationRuntime`;
- `Scheduler`;
- `ScheduledEvent`;
- `ScheduledEventRunner`;
- `FollowUpScheduler`.

O runtime suporta a separação entre recebimento do evento e processamento efetivo do turno.

Conceitualmente:

```text
Incoming Event
      ↓
persistência
      ↓
Message Buffer
      ↓
Quiet Window / Max Window
      ↓
Conversation Turn
      ↓
Guiche::handle()
      ↓
resposta
      ↓
Scheduled Events
```

## Observabilidade

O contrato `RuntimeEventSinkInterface` desacopla a produção de eventos do destino.

Implementações existentes incluem:

- `NullRuntimeEventSink`;
- `JsonlRuntimeEventSink`.

`RuntimeEventFactory` centraliza a construção de eventos estruturados do runtime.

A observabilidade deve seguir minimização de dados. Logs não são uma licença para copiar indiscriminadamente mensagens, estado, argumentos, respostas ou credenciais.

## Providers

Providers de IA implementam `AiProviderInterface`.

O projeto contém:

- `OpenAiResponsesProvider`;
- `TerminalToolOutputAiProvider`.

O Core não deve exigir que uma Tool conheça o provider utilizado.

## Infraestrutura

Integrações concretas ficam atrás de contratos.

Exemplos atuais:

```text
Infrastructure/
├── Database/
│   ├── PdoFactory
│   └── PdoSqlExecutor
└── Http/
    └── CurlHttpClient
```

Código de domínio pode depender dos contratos apropriados sem obrigar o Core a conhecer PDO ou cURL diretamente.

## Core versus domínio

Regra arquitetural:

```text
src/Guiche.php
src/GuicheBuilder.php
src/Contracts/
src/Safety/
src/Conversation/
```

não devem ganhar regras específicas de um domínio para suportar um novo caso de uso.

Uma funcionalidade de domínio deve preferencialmente entrar por:

```text
Plugin + Tools + StateManager + Presenters + Policies
```

## Exemplos

Dois exemplos demonstram extensibilidade:

- `examples/basic/`: primeira experiência de Developer Experience;
- `examples/neutral-plugin/`: prova de Plugin externo ao Core.

O exemplo básico usa uma Tool local de leitura e não depende de banco ou API privada.

## Testabilidade

A arquitetura é orientada a contratos para permitir substituição por Fakes.

A suíte automatizada possui Fixtures para IA, Tool, State Manager, Presenter, Clock e persistência em memória onde aplicável.

O quality gate oficial é:

```bash
composer check
```

Ele deve permanecer independente de APIs reais e side effects reais.

## Contexto dinâmico multi-tenant — v1.1

A v1.1 adiciona uma camada de contexto confiável entre a sessão e a Tool.

```text
SESSION
  ↓
execution_context.identity
  ↓
TenantResolver
  ↓
execution_context.tenant_id
  ↓
ToolExecutionContext
  ↓
TOOL
```

Responsabilidades:

- `ExecutionContextSeeder`: vincula identity à sessão;
- `TenantResolverInterface`: traduz identity confiável em tenant;
- `TenantResolutionService`: resolve, valida e persiste tenant;
- `ToolExecutionContext`: transporta session/identity/tenant de forma imutável;
- `ContextAwareToolInterface`: recebe o contexto no momento da execução.

### Imutabilidade

`identity` e `tenant_id` são bindings de segurança.

Depois de vinculados à sessão, não podem ser trocados por outra chamada,
por argumento da IA ou por concorrência entre workers.

### Compatibilidade

A nova camada é opt-in.

```text
ToolInterface
    ↓
execute(arguments)

ContextAwareToolInterface extends ToolInterface
    ↓
executeWithContext(arguments, context)
```

O Core preserva o caminho legado para Tools não contextuais.

### Safety

A resolução de contexto ocorre dentro do runtime de execução e não
substitui a Safety Layer.

```text
Policy / Gate
  ↓
Context resolution
  ↓
WRITE replay check quando aplicável
  ↓
execução real
```

No runtime com duas gates, o contexto é novamente obtido no caminho que
leva à execução efetiva, preservando a revalidação imediatamente anterior
ao side effect.
