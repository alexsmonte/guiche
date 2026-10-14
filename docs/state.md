# Estado e persistência no Guichê

O Guichê separa:

```text
estado da conversa
persistência do contexto
regras de transição do domínio
runtime de eventos da conversa
```

Essas responsabilidades não precisam compartilhar a mesma implementação.

## ContextStoreInterface

O Core depende de `ContextStoreInterface`.

Isso permite substituir o backend de persistência sem obrigar `Guiche` a conhecer filesystem, banco ou outro mecanismo concreto.

A implementação `ContextStore` é o backend concreto disponível no projeto para contexto persistido em filesystem.

## Contexto

O contexto mantém informações necessárias ao processamento de uma sessão.

Conceitualmente:

```text
context
├── session
├── messages
├── intent
├── state
├── tool history
├── usage
└── dados de runtime/persistência
```

Nem todo dado persistido deve ser enviado ao provider de IA.

## StateManagerInterface

`StateManagerInterface` é a fronteira para lógica de transição de estado de domínio.

O runtime chama o State Manager em momentos como:

```text
mensagem do usuário
      ↓
onUserMessage(...)
      ↓
patch

resultado de Tool
      ↓
onToolResult(...)
      ↓
patch
```

O State Manager não deve precisar alterar diretamente arquivos do Context Store.

## Patches

A evolução de estado ocorre por patches.

O objetivo é representar a intenção da mudança, em vez de permitir que cada componente sobrescreva o contexto completo.

Conceitualmente:

```text
patch
├── intent
├── state
└── unset_state
```

A implementação concreta do contrato define como o patch é aplicado.

## Invalidação de estado

Quando uma escolha anterior deixa de ser válida, o State Manager deve remover dados dependentes.

Exemplo genérico:

```text
troca A
  ↓
B depende de A
  ↓
remover B e dados derivados
```

Essa lógica pertence ao domínio.

O Core não deve conhecer quais campos são dependentes entre si.

## Persistência atômica

A camada de persistência foi desenhada para evitar a gravação direta de conteúdo parcial.

Para filesystem, o padrão seguro é:

```text
estado novo
   ↓
arquivo temporário
   ↓
gravação completa
   ↓
rename
```

Operações que alteram o mesmo contexto também precisam considerar concorrência para evitar lost updates.

## Idempotência persistente

Proteções de escrita não podem depender apenas de memória do processo.

Quando uma operação WRITE usa fingerprint/replay idempotente, o dado necessário para impedir repetição deve sobreviver a restart de acordo com o backend adotado.

## Context Store versus Conversation Store

São responsabilidades distintas.

### Context Store

Mantém o contexto usado pelo `Guiche` para raciocinar um turno.

### Conversation Store

`ConversationStoreInterface` dá suporte ao runtime de conversação, eventos recebidos, sequência e processamento ao redor dos turnos.

Não assuma que ambos precisam ser o mesmo objeto ou a mesma tabela.

## Incoming events

A camada `Conversation/` representa eventos recebidos antes do processamento final do turno.

`IncomingEventRecorder` persiste o recebimento de forma separada da decisão de quando chamar o Guichê.

Isso permite:

```text
evento chegou
   ↓
persistir
   ↓
bufferizar
   ↓
processar depois do quiet window
```

## Sequence

O runtime de conversação usa versão/sequência para detectar se a conversa mudou.

Esse mecanismo é fundamental para proteção contra eventos agendados obsoletos.

Conceitualmente:

```text
evento agendado
sequence_snapshot = 10

usuário envia nova mensagem
sequence = 11

worker pega evento antigo
10 != 11
   ↓
STALE
   ↓
não executar
```

## Message Buffer

`MessageBuffer` permite consolidar mensagens próximas antes de criar um turno.

Exemplo:

```text
18:00:00 "Oi"
18:00:05 "quero ajuda"
18:00:10 "sobre o plano Pro"

quiet window
      ↓
um turno consolidado
```

A camada de buffer decide **quando** o conteúdo deve formar um turno; o `Guiche` continua responsável por **como** raciocinar sobre esse turno.

## Scheduler

`Scheduler`, `ScheduledEvent` e `ScheduledEventRunner` suportam trabalho agendado durável.

Um evento agendado precisa ser revalidado antes de produzir efeito.

Follow-up não deve assumir que a conversa permaneceu igual desde o momento em que foi agendado.

## Follow-up

`FollowUpScheduler` representa agendamento de continuidade/inatividade dentro do runtime.

Regras de follow-up devem respeitar:

- estado atual;
- sequence atual;
- cancelamento;
- claim/lease;
- stale-event protection.

## Observabilidade e estado

Não registre automaticamente o estado completo em eventos de observabilidade.

Princípio:

```text
persistência funcional ≠ payload de log
```

Dados necessários ao runtime podem ser sensíveis ou excessivos para telemetria.

## Estado enviado à IA

O runtime pode excluir caminhos do estado antes de construir o contexto enviado ao provider.

Use essa capacidade para reduzir dados que não são necessários ao raciocínio.

## Testes

Testes de State e persistência devem cobrir:

- aplicação de patch;
- remoção de estado;
- backend substituível;
- concorrência;
- persistência após restart;
- sequence;
- stale events;
- buffer/debounce;
- recovery.

A suíte automatizada utiliza Fakes e backends em memória onde apropriado para manter o quality gate independente de infraestrutura externa.
