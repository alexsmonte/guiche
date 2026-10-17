# Guichê v1.6 — Session Lifecycle

## Objetivo

Permitir que a aplicação defina por quanto tempo uma conversa continua pertencendo ao mesmo ciclo operacional.

Configuração desejada desde o backlog do StateManager:

```dotenv
GUICHE_SESSION_TTL=86400
```

Uso:

```php
$ttl = (int) (
    getenv('GUICHE_SESSION_TTL')
    ?: 86400
);

$guiche = (new GuicheBuilder())
    ->withSessionTtl($ttl)
    ->build();
```

## Compatibilidade

Session Lifecycle é **opt-in**.

Se `withSessionTtl()` não for chamado:

- não há expiração automática;
- o Guichê não adiciona metadata `session`;
- o prompt não recebe bloco `session`;
- o comportamento v1.5/v1.6 anterior permanece igual.

## Estados por turno

Quando habilitado, o prompt estruturado recebe:

```json
{
  "session": {
    "status": "new",
    "is_first_message": true,
    "is_new_cycle": true,
    "was_expired": false,
    "cycle": 1,
    "ttl_seconds": 86400,
    "started_at": "..."
  }
}
```

### `new`

Primeiro turno do primeiro ciclo:

```text
status=new
is_first_message=true
is_new_cycle=true
was_expired=false
```

Pode ser usado pelo prompt para saudação e apresentação inicial.

### `active`

Turnos seguintes antes do TTL:

```text
status=active
is_first_message=false
is_new_cycle=false
was_expired=false
```

O atendimento continua normalmente.

### `expired`

Primeiro turno após ultrapassar o TTL:

```text
status=expired
is_first_message=true
is_new_cycle=true
was_expired=true
cycle=anterior+1
```

Para o agente, isso representa um novo ciclo de atendimento.

## O que é preservado na expiração

É preservado:

```text
execution_context
```

Isso mantém a identidade externa confiável/tenant já vinculado à mesma sessão.

Também é preservado apenas um resumo não sensível do ciclo anterior:

- número do ciclo;
- início;
- última atividade;
- momento da expiração;
- quantidade de mensagens;
- quantidade de Tools executadas;
- totais de tokens.

## O que é reiniciado

Um novo ciclo limpa:

```text
intent
state
messages
tool_history
usage do ciclo
```

Essa separação é necessária para que:

- o prompt não receba conversa antiga;
- StateManager não herde decisões operacionais vencidas;
- cache READ não atravesse ciclos;
- replay/idempotência de WRITE fique limitado ao ciclo operacional atual.

A aplicação de domínio continua responsável por idempotência própria de efeitos externos quando necessário, por exemplo uma API que aceite uma chave idempotente de negócio.

## `session.is_first_message`

O recurso resolve o caso:

```text
primeira interação
→ saudação + apresentação

conversa ativa
→ continuar sem repetir apresentação

primeiro turno após expiração
→ pode tratar como nova abertura
```

A metadata fica disponível no contexto estruturado enviado ao provider.

## Persistência

O contexto persistido recebe metadata semelhante a:

```json
{
  "session": {
    "status": "active",
    "is_first_message": false,
    "is_new_cycle": false,
    "was_expired": false,
    "cycle": 1,
    "ttl_seconds": 86400,
    "started_at": "...",
    "transition_at": "...",
    "last_activity_at": "..."
  }
}
```

Após expiração, `previous_cycle` contém somente o resumo do ciclo encerrado.

## Clock

O cálculo interno usa `ClockInterface` e `SystemClock`.

Isso permite testes determinísticos do componente sem `sleep()`.

## Concorrência

O `handle()` já usa lock por sessão.

O lifecycle é executado dentro desse fluxo antes de StateManager, prompt operacional e Tools.

`executeTool()` também aplica o lifecycle antes de Safety Gate, cache e replay quando a sessão está configurada com TTL.

## API pública

A única nova API pública desta fase é:

```php
GuicheBuilder::withSessionTtl(int $seconds): self
```

O construtor de `Guiche`, `ContextStoreInterface`, `ToolInterface` e demais contratos congelados não mudam.

## Fora do escopo

Esta fase não implementa:

- exclusão física automática do arquivo de sessão;
- cron de limpeza;
- TTL de Redis;
- TTL por Tool;
- follow-up;
- múltiplos prompts;
- política específica por domínio.

Follow-up e múltiplos prompts pertencem às próximas fases.
