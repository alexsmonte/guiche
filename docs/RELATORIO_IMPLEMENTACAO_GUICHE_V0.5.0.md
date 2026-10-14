# Relatório de Implementação — Guichê v0.5.0

## Persistence & Observability

A versão `v0.5.0` evolui o Guichê de um runtime seguro com persistência concreta em JSON para um runtime seguro, persistente por contrato, resistente a concorrência e observável por eventos estruturados.

## Baseline

- Versão anterior: `v0.4.0 — Safety Layer`
- Baseline da branch: `d782b3a0eee74e1d72f687ea4a1314530d20f185`
- Branch de desenvolvimento: `feature/v0.5-persistence-observability`
- Tag da release: `v0.5.0`

## Objetivos concluídos

### Persistence Contract

Foi criado `ContextStoreInterface`, permitindo que o Core dependa de um contrato em vez de depender exclusivamente do backend JSON concreto.

`Guiche` e `GuicheBuilder` passaram a aceitar `ContextStoreInterface`, preservando compatibilidade com `ContextStore`.

### Persistência atômica

O backend JSON foi endurecido com:

- lock separado por sessão;
- `flock`;
- read-modify-write protegido;
- escrita em arquivo temporário;
- publicação por `rename`;
- flush/fsync quando disponível;
- tratamento explícito de JSON inválido;
- proteção contra lost update.

### Schema e compatibilidade

O contexto passou a possuir `schema_version = 1`.

Contextos legados da v0.4 sem `schema_version` são normalizados em memória e persistidos no schema atual na primeira mutação, preservando campos desconhecidos e estado de plugins.

### Restart-safe idempotency

O fingerprint de Tool WRITE continua incluindo:

- sessão;
- nome da Tool;
- argumentos normalizados.

O histórico persistido permite reconhecer uma WRITE já concluída mesmo depois de recriar o processo/ContextStore.

### Runtime Event Sink

Foi criado `RuntimeEventSinkInterface` e implementados:

- `NullRuntimeEventSink`;
- `JsonlRuntimeEventSink`.

O sink é opcional. Sem configuração explícita, o runtime utiliza Null Sink e mantém compatibilidade.

### Correlação

Os eventos persistidos possuem:

- `schema_version`;
- `event_id`;
- `session_key`;
- `interaction_id`;
- `call_id` quando aplicável.

`session_key` é derivada da sessão e evita persistir o identificador bruto.

### Observabilidade

A v0.5 instrumenta:

- início/conclusão/falha de interação;
- chamadas de IA;
- duração de IA;
- usage/tokens;
- recebimento de Tool call;
- início/conclusão/falha de Tool;
- duração de Tool;
- `tool_blocked`;
- `tool_cache_hit`;
- `tool_write_replay`;
- falha de load/save do contexto;
- falha de persistência depois de side effect.

Durações usam relógio monotônico (`hrtime(true)`).

### Data minimization

Por padrão, a telemetria não persiste:

- mensagens brutas do usuário;
- texto bruto da IA;
- argumentos brutos de Tool;
- resultados brutos de Tool;
- payloads de provider;
- mensagens brutas de exceptions;
- identificador bruto de sessão.

Argumentos são correlacionados por fingerprint.

### Safety Layer preservada

A observabilidade não altera a ordem de segurança.

O caminho continua garantindo:

1. Tool registrada;
2. Policy;
3. preconditions;
4. validação dos argumentos;
5. revalidação imediatamente antes da execução;
6. execução real;
7. persistência/auditoria.

A execução real continua visível no caminho do `handle()` e é entregue à instrumentação apenas como closure.

### Side effect + falha de persistência

O cenário crítico foi testado:

```text
Tool WRITE executa com sucesso
        ↓
persistência do histórico falha
        ↓
persistence_after_side_effect_failed
        ↓
erro explícito
        ↓
nenhum retry automático da WRITE
```

Isso evita duplicação de side effects.

## Eventos principais

```text
interaction_started
interaction_completed
interaction_failed

ai_request_started
ai_response_received
ai_request_failed

tool_call_received
tool_execution_started
tool_execution_completed
tool_execution_failed

tool_blocked
tool_cache_hit
tool_write_replay

context_load_failed
context_save_failed
persistence_after_side_effect_failed
```

## Testes permanentes da v0.5

```text
bin/test-persistence-contracts-v05.php
bin/test-context-store-abstraction-v05.php
bin/test-atomic-context-store-v05.php
bin/test-runtime-event-sink-v05.php
bin/test-runtime-observability-v05.php
bin/test-v05-acceptance.php
```

## Acceptance e regressões

O acceptance consolidado valida:

- contratos de persistência;
- desacoplamento do backend;
- schema e legado v0.4;
- corrupção de JSON;
- atomic write;
- concorrência multiprocesso;
- idempotência após restart;
- Null Sink;
- JSONL Sink;
- append concorrente;
- correlação;
- durações;
- usage/tokens;
- Safety blocks;
- READ cache;
- WRITE replay;
- falhas de provider;
- falhas de Tool;
- falha de persistência após side effect;
- data minimization;
- regressões v0.2;
- acceptance v0.3;
- acceptance v0.4;
- integração clínica;
- neutralidade do Core.

## Decisões arquiteturais

### ContextStore por contrato

```text
Guiche
  ↓
ContextStoreInterface
  ↓
backend
```

O backend JSON continua sendo a implementação padrão desta versão.

### Observabilidade estruturada

Observabilidade usa eventos estruturados em vez de strings de log.

### Telemetria não controla execução

Falhas do Event Sink são best-effort e não causam retry da execução principal.

### Telemetria nunca repete side effect

Uma falha de observabilidade ou persistência não pode provocar nova execução automática de Tool WRITE.

## Fora do escopo da v0.5

Permanecem para versões posteriores:

- Redis;
- PDO Context Store;
- OpenTelemetry;
- Prometheus;
- tracing distribuído;
- dashboard;
- scheduler;
- debounce;
- follow-up;
- Conversation Runtime.

## Próximo marco

O próximo marco planejado é:

```text
v0.5.1 — Conversation Runtime
```

Essa versão tratará o momento em que uma conversa deve ser processada, incluindo persistência de eventos de entrada, sequence/version, buffer, debounce, scheduler, stale protection e follow-up.

## Definition of Done

- [x] `ContextStoreInterface`;
- [x] Core depende do contrato;
- [x] backend JSON compatível;
- [x] schema version;
- [x] contexto v0.4 compatível;
- [x] mutações atomicamente protegidas;
- [x] escrita resistente a truncamento;
- [x] idempotência após restart;
- [x] `RuntimeEventSinkInterface`;
- [x] Null Sink;
- [x] JSONL Sink;
- [x] event ID;
- [x] session correlation;
- [x] interaction correlation;
- [x] AI duration;
- [x] Tool duration;
- [x] tokens;
- [x] Safety events;
- [x] cache hit;
- [x] WRITE replay;
- [x] failure events;
- [x] data minimization;
- [x] Safety Layer preservada;
- [x] regressões v0.2/v0.3/v0.4;
- [x] Core neutro;
- [x] `git diff --check`;
- [x] relatório de implementação;
- [ ] merge em `main`;
- [ ] tag `v0.5.0`;
- [ ] push;
- [ ] working tree limpa.

Os quatro itens finais são realizados pelo fechamento Git da mesma Fase 06, após o relatório entrar no commit da feature.
