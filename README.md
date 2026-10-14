# Guichê

**Guichê** é um Core PHP para construir assistentes conversacionais orientados a Tools, Plugins, estado, segurança, persistência e runtime de conversação sem acoplar o Core a um domínio específico.

O projeto evoluiu de um piloto clínico para uma biblioteca reutilizável. O domínio clínico deixa de definir a arquitetura do Core e passa a ser apenas uma integração construída sobre os mesmos contratos públicos disponíveis para qualquer outro domínio.

> Versão documentada: **v0.7.0 — Developer Experience**

## Requisitos

- PHP `^8.4`;
- Composer;
- uma chave de API para o provider usado na conversa real. O exemplo oficial utiliza OpenAI.

O quality gate do projeto e os testes automatizados **não dependem** de OpenAI real, banco real, Clinsoft, Internet ou side effects reais.

## Instalação

Quando a versão estiver disponível no repositório Composer configurado pela aplicação:

```bash
composer require asmpkg/guiche:^0.7
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
composer require asmpkg/guiche:^0.7
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

Na v0.7 a documentação é organizada em:

- Quick Start: `docs/quick-start.md`;
- arquitetura: `docs/architecture.md`;
- Tools: `docs/tools.md`;
- Plugins: `docs/plugins.md`;
- State: `docs/state.md`;
- segurança: `docs/security.md`.

Os cinco documentos técnicos são consolidados na fase seguinte da v0.7.

## Status

A v0.7.0 tem como objetivo tornar a biblioteca compreensível e utilizável por um desenvolvedor que não participou da construção do piloto original.

O principal critério de aceite é:

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
