# Guichê

**Guichê** é um Core PHP para construir assistentes conversacionais orientados a Tools, Plugins, estado, segurança, persistência e runtime de conversação sem acoplar o Core a um domínio específico.

O projeto evoluiu de um piloto clínico para uma biblioteca reutilizável. O domínio clínico deixa de definir a arquitetura do Core e passa a ser apenas uma integração construída sobre os mesmos contratos públicos disponíveis para qualquer outro domínio.

> Versão documentada: **v1.1.0 — Stable**

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

A forma mais rápida de validar o Guichê é usar o exemplo básico incluído no pacote. Ele contém:

- `BasicPlugin`;
- a Tool local `CatalogSearch`;
- catálogo determinístico em memória;
- operação somente `READ`;
- nenhum MySQL;
- nenhuma API privada;
- nenhum side effect.

### 1. Crie um projeto vazio

```bash
mkdir meu-guiche
cd meu-guiche
composer require asmpkg/guiche:^1.0
```

### 2. Copie o exemplo oficial

```bash
mkdir -p examples
cp -R vendor/asmpkg/guiche/examples/basic examples/basic
```

A estrutura ficará assim:

```text
meu-guiche/
├── composer.json
├── vendor/
└── examples/
    └── basic/
        ├── README.md
        ├── chat.php
        └── src/
            ├── BasicPlugin.php
            └── CatalogTool.php
```

### 3. Configure o provider

```bash
export OPENAI_API_KEY="sua-chave"
export OPENAI_MODEL="gpt-5.6-luna"
```

`OPENAI_MODEL` é opcional; o exemplo possui um valor padrão.

### 4. Inicie a conversa

```bash
php examples/basic/chat.php
```

Exemplo de pergunta:

```text
Você: Quais serviços existem no catálogo?
```

A IA pode chamar `CatalogSearch`, receber o catálogo local e responder usando somente os dados retornados pela Tool.

Também é possível executar uma única mensagem:

```bash
php examples/basic/chat.php "Qual é o preço do Suporte Pro?"
```

No modo interativo:

- `/reset` reinicia o contexto da sessão;
- `/sair` encerra a CLI.

## Como o exemplo é composto

O fluxo essencial é:

```text
mensagem do usuário
        ↓
Guiche::handle()
        ↓
AI Provider
        ↓
Tool call, quando necessária
        ↓
Safety / Policy
        ↓
Tool
        ↓
resultado
        ↓
AI Provider
        ↓
resposta final
```

O `BasicPlugin` registra a Tool por meio da API pública do `GuicheBuilder`. O exemplo não modifica o Core.

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
- segurança: `docs/security.md`.

A política de estabilidade da API e suporte PHP complementa essa documentação.

## Status

A v1.0.0 estabeleceu a primeira linha estável do Guichê. A v1.1.0 adiciona contexto dinâmico multi-tenant para Tools sem quebrar o contrato público existente.

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

Os exemplos permanecem separados do Core: `examples/basic/` para início rápido e `examples/clinica-piloto/` como exemplo avançado de domínio.
<!-- guiche-v1-stable:end -->
