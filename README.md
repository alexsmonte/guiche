# Guichê

**Guichê** é um Core PHP para construir assistentes conversacionais orientados a Tools, Plugins, estado, segurança, persistência e runtime de conversação sem acoplar o Core a um domínio específico.

O projeto evoluiu de um piloto clínico para uma biblioteca reutilizável. O domínio clínico deixa de definir a arquitetura do Core e passa a ser apenas uma integração construída sobre os mesmos contratos públicos disponíveis para qualquer outro domínio.

> Versão documentada: **v1.3.0 — Stable**
## Requisitos

- PHP `^8.4`;
- Composer;
- uma chave de API para o provider usado na conversa real. O exemplo oficial utiliza OpenAI.

O quality gate do projeto e os testes automatizados **não dependem** de OpenAI real, banco real, Clinsoft, Internet ou side effects reais.

## Instalação

Instale a linha estável v1:

```bash
composer require asmpkg/guiche:^1.0
```

Para trabalhar diretamente com o clone deste repositório:

```bash
git clone git@github.com:alexsmonte/guiche.git guiche
cd guiche
composer install
```

## Quick Start — primeira conversa

Na v1.3.0 você não precisa copiar nem criar `chat.php` para começar.

### 1. Crie um projeto vazio

```bash
mkdir meu-guiche
cd meu-guiche
composer require asmpkg/guiche:^1.3
```

### 2. Inicialize o projeto

```bash
vendor/bin/guiche init
```

O comando cria, sem sobrescrever arquivos existentes:

```text
.env
guiche.prompt.md
storage/conversations/
```

### 3. Configure o provider

Edite `.env`:

```dotenv
AI_API_KEY=sua-chave
AI_MODEL=gpt-5.6-luna
```

`AI_MODEL` possui valor padrão. Por compatibilidade, `OPENAI_API_KEY` e
`OPENAI_MODEL` ainda são aceitos como aliases legados.

### 4. Personalize o prompt

Edite:

```text
guiche.prompt.md
```

A resolução de prompt segue esta prioridade:

```text
GUICHE_PROMPT_FILE
        ↓
GUICHE_PROMPT
        ↓
guiche.prompt.md
        ↓
prompt padrão do pacote
```

### 5. Verifique o ambiente

```bash
vendor/bin/guiche doctor
```

O `doctor` valida PHP, extensões, `.env`, configuração de IA e
`storage/conversations` sem fazer uma chamada de rede ao provider.

### 6. Converse

Modo interativo:

```bash
vendor/bin/guiche chat
```

Mensagem única:

```bash
vendor/bin/guiche chat "Olá, tudo bem?"
```

Sessão explícita:

```bash
vendor/bin/guiche chat --session=cliente-123 "Olá"
```

No modo interativo:

- `/context` mostra o contexto salvo;
- `/usage` mostra o uso registrado na sessão;
- `/tools` mostra as Tools disponíveis;
- `/reset` reinicia a sessão;
- `/sair` encerra a CLI.

Esses comandos são tratados localmente e não são enviados à IA.

Também é possível consultar usage fora do chat:

```bash
vendor/bin/guiche usage --session=cliente-123
```

## Exemplo básico com Tool

A CLI da v1.2.0 oferece a menor experiência genérica sem exigir arquivo PHP
manual. Quando você quiser estudar a integração de uma Tool e um Plugin, o
exemplo oficial continua disponível em:

```text
examples/basic/
```

Ele contém:

- `BasicPlugin`;
- Tool local `CatalogSearch`;
- catálogo determinístico em memória;
- operação somente `READ`;
- nenhum MySQL;
- nenhuma API privada;
- nenhum side effect.

O `BasicPlugin` registra sua Tool pela mesma API pública do
`GuicheBuilder` usada por aplicações externas.

## Conceitos principais

### Tool

Uma Tool implementa `ToolInterface` e declara:

- nome;
- descrição;
- schema de argumentos;
- execução.

Tools podem ser classificadas como operações de leitura ou escrita. Operações de escrita possuem requisitos de segurança adicionais.

### Plugin

Um Plugin implementa `PluginInterface` e registra capacidades pelo `GuicheBuilder`, como:

- Tools;
- Presenters;
- State Manager;
- Policies;
- instruction fragments;
- completion guards;
- result projections.

### State

O contexto estruturado da conversa pode ser persistido por uma implementação de `ContextStoreInterface`. O Core não precisa conhecer detalhes do domínio para manter esse estado.

### Contexto dinâmico multi-tenant

A partir da v1.1, uma Tool pode implementar `ContextAwareToolInterface`
quando sua execução depende de identidade e tenant vinculados à sessão.

O fluxo confiável é:

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
ContextAwareToolInterface::executeWithContext()
```

`tenant_id` não deve ser aceito da IA como fonte de autoridade.

O `ToolInterface` original permanece compatível. Tools legadas continuam
executando por `execute(array $arguments)` sem exigir contexto multi-tenant.

Veja:

- `docs/multitenancy.md`;
- `docs/migration-v1.0-to-v1.1.md`.

### Contexto externo confiável — v1.3

A v1.3 torna público o caminho recomendado para aplicações externas
vincularem uma identidade autenticada à sessão:

```php
$guiche->bindIdentity(
    $sessionId,
    [
        'provider' => 'custom-crm',
        'account_id' => $accountId,
        'conversation_id' => $conversationId,
    ]
);

$response = $guiche->handle(
    $sessionId,
    $message
);
```

O fluxo é:

```text
host externo confiável
        ↓
bindIdentity()
        ↓
execution_context.identity
        ↓
TenantResolver
        ↓
execution_context.tenant_id
        ↓
ToolExecutionContext
        ↓
ContextAwareTool
```

`bindIdentity()` é write-once por sessão, idempotente para o mesmo binding e
falha fechado quando há tentativa de troca. Binding e turno da mesma sessão
também compartilham o mesmo lock de concorrência.

O Core não depende de Chatwoot ou de qualquer provider externo. Chatwoot é
um adapter de referência em `examples/chatwoot/`; um exemplo genérico fica
em `examples/external-host/`.

A autenticação do webhook/request externo pertence ao host. Somente depois
de estabelecer a confiança o host deve chamar `bindIdentity()`.

Veja:

- `docs/trusted-external-identity.md`;
- `docs/migration-v1.2-to-v1.3.md`;
- `examples/chatwoot/`;
- `examples/external-host/`.

### Safety Layer

A execução de Tools passa por regras estruturadas de segurança. O runtime suporta, entre outros mecanismos:

- classificação READ/WRITE;
- validação de argumentos;
- preconditions de estado;
- estágios permitidos;
- confirmação;
- proteção de idempotência para escrita;
- bloqueio de duplicate writes;
- Result Projection.

### Conversation Runtime

O runtime de conversação cobre responsabilidades como:

- incoming events;
- buffer/debounce;
- turn;
- sequence;
- scheduler;
- claim/lease;
- stale-event protection;
- follow-up e recovery.

A divisão conceitual é:

```text
ConversationRuntime = quando processar
Guiche              = como processar/raciocinar o turno
```

## Exemplo básico

O código de referência fica em:

```text
examples/basic/
```

Ele foi criado especificamente para Developer Experience e deve permanecer:

- pequeno;
- legível;
- neutro de domínio;
- sem banco;
- sem dependências privadas;
- seguro para leitura;
- executável como primeira experiência com o framework.

Veja [Quick Start detalhado](docs/quick-start.md).

## Desenvolvimento

Instale as dependências:

```bash
composer install
```

Execute o quality gate:

```bash
composer check
```

O quality gate inclui PHPUnit, PHPStan e cobertura mínima definida pelo projeto.

## Arquitetura e documentação

A documentação pública da linha v1 é organizada em:

- Quick Start: `docs/quick-start.md`;
- arquitetura: `docs/architecture.md`;
- Tools: `docs/tools.md`;
- Plugins: `docs/plugins.md`;
- State: `docs/state.md`;
- contexto multi-tenant: `docs/multitenancy.md`;
- migração v1.0 → v1.1: `docs/migration-v1.0-to-v1.1.md`;
- migração v1.1 → v1.2: `docs/migration-v1.1-to-v1.2.md`;
- segurança: `docs/security.md`.

A política de estabilidade da API e suporte PHP complementa essa documentação.

## Status

A v1.0.0 estabeleceu a primeira linha estável do Guichê. A v1.1.0 adicionou contexto dinâmico multi-tenant para Tools e a v1.1.1 corrigiu o carregamento de ambiente. A v1.2.0 adiciona a CLI pública `vendor/bin/guiche`, bootstrap automático, `doctor`, `usage`, chat sem `chat.php` manual e resolução configurável de prompt.

O compromisso da linha v1 inclui:

- API `public` protegida por SemVer/BC;
- suporte oficial a PHP 8.4 e PHP 8.5;
- hard execution gate antes de side effects;
- idempotência explícita para WRITE;
- extensibilidade por Tools, Plugins, ContextStore e AiProvider;
- instalação e uso comprovados em consumidor Composer externo;
- Conversation Runtime disponível como API `experimental`, fora do compromisso BC estável até promoção explícita.

O principal critério de aceite permanece:

> em um projeto vazio, um desenvolvedor deve conseguir seguir somente este README e obter uma conversa funcional.

## Licença

Consulte o arquivo de licença do repositório.

<!-- GUICHE_V08_CLINICAL_EXAMPLE_START -->
## Exemplo clínico avançado

A biblioteca mantém o Core genérico em `src/` e disponibiliza um exemplo
completo de agendamento em
[`examples/clinica-piloto/`](examples/clinica-piloto/README.md).

Para executar o exemplo pela primeira vez, use o **Fake Mode**, que não depende
de banco, IA ou API externos:

```bash
cp examples/clinica-piloto/.env.example examples/clinica-piloto/.env
php examples/clinica-piloto/fake-smoke.php
```

Veja o guia de instalação e isolamento em
[`examples/clinica-piloto/INSTALL.md`](examples/clinica-piloto/INSTALL.md).

O namespace do exemplo não pertence ao autoload público do pacote.
<!-- GUICHE_V08_CLINICAL_EXAMPLE_END -->

<!-- guiche-v1-stable:start -->
## Linha estável v1

A v1.0.0 congela a primeira superfície pública estável do Guichê e mantém o Conversation Runtime explicitamente experimental.

Instalação:

```bash
composer require asmpkg/guiche:^1.0
```

Fluxo básico de integração:

```php
$builder = new Asmpkg\Guiche\GuicheBuilder();
$builder->withContextStore($contextStore);
$builder->withAiProvider($provider);
$builder->registerPlugin($plugin);

$guiche = $builder->build();
$result = $guiche->handle('session-123', 'Olá');
```

Pontos de extensão estáveis:

- `ToolInterface`;
- `PluginInterface`;
- `ContextStoreInterface`;
- `AiProviderInterface`;
- `ToolRegistration`;
- `GuicheBuilder`.

O **hard execution gate** valida policy, estado, argumentos e regras de WRITE imediatamente antes da execução real da Tool. Uma Tool call da IA nunca equivale, por si só, a autorização de side effect.

Documentação da linha v1:

- [Quick Start](docs/quick-start.md)
- [External Consumer Guide](docs/external-consumer.md)
- [API Stability & SemVer](docs/api-stability.md)
- [PHP Support](docs/php-support.md)
- [Security Architecture](docs/security.md)
- [Security Policy](SECURITY.md)
- [Migração v0.9 → v1.0](docs/migration-v0.9-to-v1.0.md)
- [Release v1.0.0](docs/releases/v1.0.0.md)
- [Release v1.2.0](docs/releases/v1.2.0.md)

Os exemplos permanecem separados do Core: `examples/basic/` para início rápido e `examples/clinica-piloto/` como exemplo avançado de domínio.
<!-- guiche-v1-stable:end -->
