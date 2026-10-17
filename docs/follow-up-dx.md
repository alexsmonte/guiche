# Guichê v1.6 — Follow-up DX

## Objetivo

O Guichê já possui um motor durável de follow-up com:

- `Scheduler`;
- `FollowUpScheduler`;
- `ScheduledEventRunner`;
- `JsonScheduledEventStore`;
- snapshot de `sequence`;
- dedupe;
- lease;
- fencing token;
- stale por nova resposta;
- stale por mudança de estado;
- recovery de worker abandonado;
- `indeterminate` sem retry cego.

A v1.6 não substitui esse motor.

A Fase 07 adiciona apenas uma facade de composição:

```php
Asmpkg\Guiche\Conversation\FollowUpRuntime
```

## Configuração sugerida

```dotenv
GUICHE_FOLLOWUP_ENABLED=true
GUICHE_FOLLOWUP_AFTER=7200
```

Exemplo:

```php
use Asmpkg\Guiche\Conversation\FollowUpRuntime;

$enabled = filter_var(
    getenv('GUICHE_FOLLOWUP_ENABLED') ?: 'false',
    FILTER_VALIDATE_BOOL
);

$after = (int) (
    getenv('GUICHE_FOLLOWUP_AFTER')
    ?: FollowUpRuntime::DEFAULT_AFTER_SECONDS
);

$followUp = FollowUpRuntime::fromDirectories(
    conversationDirectory:
        __DIR__ . '/../storage/conversations',
    schedulerDirectory:
        __DIR__ . '/../storage/scheduler',
    contextDirectory:
        __DIR__ . '/../storage/contexts',
    defaultDelaySeconds: $after,
    enabled: $enabled
);
```

## Agendamento

Ao terminar um turno que continua aguardando o usuário:

```php
$event = $followUp->schedule(
    $conversationKey,
    payload: [
        'template' => 'retomar_atendimento',
    ],
    requiredState: [
        'state.etapa' => 'aguardando_resposta',
    ]
);
```

O `FollowUpScheduler` captura a `sequence` atual.

## Quando o usuário responde

A entrada do usuário precisa passar pelo `IncomingEventRecorder` / `ConversationRuntime` já existente.

Isso incrementa a `sequence`.

Quando o follow-up antigo vencer:

```text
sequence atual != sequence_snapshot
→ stale
→ handler não executa
```

Não é necessário cancelar manualmente o timer antigo.

## Quando o atendimento muda de estado

Também podemos declarar:

```php
requiredState: [
    'state.etapa' => 'aguardando_resposta',
]
```

Se o estado mudar antes do vencimento:

```text
state incompatível
→ stale
→ handler não executa
```

## Worker

O envio real continua sendo side effect externo e deve rodar em worker/cron:

```php
$results = $followUp->runDue(
    workerId: 'follow-up-worker-1',
    handler: function ($event): void {
        // enviar via Chatwoot, WhatsApp, e-mail etc.
    }
);
```

A facade não conhece Chatwoot, WhatsApp ou qualquer domínio.

## Falha incerta

Se o handler lançar exceção depois de potencialmente iniciar um side effect:

```text
evento → indeterminate
```

O runtime não faz retry automático.

Isso evita duplicar uma mensagem cujo resultado externo ficou incerto.

## Disabled

Com:

```dotenv
GUICHE_FOLLOWUP_ENABLED=false
```

`schedule()` retorna `null` e `runDue()` retorna `[]`.

Eventos já persistidos continuam consultáveis e podem ser cancelados:

```php
$followUp->find($eventId);
$followUp->cancel($eventId);
```

## Delay

A facade usa como default da v1.6:

```text
7200 segundos
```

ou duas horas.

O baixo nível `FollowUpScheduler` mantém seu default histórico de 300 segundos para preservar compatibilidade.

Também é possível sobrescrever por evento:

```php
$followUp->schedule(
    $conversationKey,
    delaySeconds: 1800
);
```

## Fluxo recomendado

```text
mensagem do usuário
→ IncomingEventRecorder
→ ConversationRuntime / Guiche
→ atendimento ainda aberto?
→ FollowUpRuntime::schedule()
→ snapshot da sequence + requiredState

usuário responde antes do prazo
→ sequence muda
→ evento antigo fica stale

estado muda antes do prazo
→ requiredState falha
→ evento fica stale

prazo vence sem mudanças
→ worker chama runDue()
→ handler externo envia follow-up
→ evento completed
```

## Fronteiras

A Fase 07 não:

- coloca scheduler dentro de `Guiche`;
- adiciona scheduling ao `GuicheBuilder`;
- envia mensagem diretamente;
- conhece Chatwoot;
- conhece domínio clínico;
- cria retry automático;
- duplica regras de stale/recovery.

## Status da API

`FollowUpRuntime` pertence a `Conversation\` e permanece:

```text
experimental
```

Não faz parte do compromisso BC público estável da v1.x.
