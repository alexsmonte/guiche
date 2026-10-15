# Quick Start do Guichê

Este guia demonstra a menor experiência funcional da linha estável **v1.2.x**
sem exigir a criação manual de `chat.php`.

## Objetivo

Ao final, você terá:

```text
terminal
   ↓
vendor/bin/guiche
   ↓
bootstrap automático
   ↓
GuicheBuilder
   ↓
ContextStore
   ↓
AI Provider
   ↓
resposta
```

## Requisitos

- PHP `^8.4`;
- Composer;
- uma chave de API para o provider usado na conversa real.

## 1. Projeto novo

```bash
mkdir meu-guiche
cd meu-guiche
composer require asmpkg/guiche:^1.2
```

## 2. Inicialização automática

```bash
vendor/bin/guiche init
```

O comando cria:

```text
.env
guiche.prompt.md
storage/
└── conversations/
```

O `init` é idempotente: se `.env` ou `guiche.prompt.md` já existirem, eles são
preservados.

## 3. Configurar IA

Abra `.env` e informe:

```dotenv
AI_API_KEY=sua-chave
AI_MODEL=gpt-5.6-luna
```

`AI_MODEL` possui valor padrão.

Os nomes legados abaixo continuam aceitos temporariamente por compatibilidade:

```dotenv
OPENAI_API_KEY=
OPENAI_MODEL=
```

Os nomes canônicos da v1.2 são `AI_API_KEY` e `AI_MODEL`.

## 4. Configurar o prompt

O arquivo criado automaticamente é:

```text
guiche.prompt.md
```

Você pode editar esse arquivo diretamente.

A prioridade de resolução é:

```text
1. GUICHE_PROMPT_FILE
2. GUICHE_PROMPT
3. guiche.prompt.md na raiz do projeto
4. resources/guiche.prompt.md do pacote
```

Exemplo com arquivo explícito:

```dotenv
GUICHE_PROMPT_FILE=config/prompts/atendimento.md
```

Exemplo inline:

```dotenv
GUICHE_PROMPT="Você é um assistente objetivo."
```

`GUICHE_PROMPT_FILE` tem prioridade sobre `GUICHE_PROMPT`.

## 5. Verificar o ambiente

```bash
vendor/bin/guiche doctor
```

O diagnóstico verifica:

- PHP compatível;
- extensões necessárias;
- presença do `.env`;
- `AI_API_KEY`;
- `AI_MODEL`;
- `storage/conversations` gravável.

O `doctor` é determinístico e não envia uma requisição de teste ao provider.

## 6. Conversa interativa

```bash
vendor/bin/guiche chat
```

No modo interativo:

```text
/context
/usage
/tools
/reset
/sair
```

Esses comandos são locais e não são enviados à IA.

## 7. Mensagem única

```bash
vendor/bin/guiche chat "Olá, tudo bem?"
```

## 8. Sessão explícita

```bash
vendor/bin/guiche chat --session=cliente-123 "Olá"
```

O contexto é persistido no projeto consumidor em:

```text
storage/conversations/
```

## 9. Usage

```bash
vendor/bin/guiche usage
```

Ou para uma sessão específica:

```bash
vendor/bin/guiche usage --session=cliente-123
```

O comando exibe:

- quantidade de interações;
- input tokens;
- output tokens;
- total de tokens.

## 10. Comandos disponíveis

```bash
vendor/bin/guiche help
vendor/bin/guiche init
vendor/bin/guiche doctor
vendor/bin/guiche chat
vendor/bin/guiche usage
```

## Exemplo básico com Tool

O Quick Start da v1.2 não depende mais do exemplo básico. Ele continua
disponível para demonstrar como um domínio registra Plugin e Tool sem alterar o
Core:

```text
examples/basic/
├── chat.php
└── src/
    ├── BasicPlugin.php
    └── CatalogTool.php
```

`CatalogSearch` é uma Tool local somente READ, com catálogo determinístico e sem
side effects.

## Segurança

O bootstrap da CLI usa o runtime normal do Guichê. A CLI não cria caminho
alternativo para execução de Tools.

Quando Tools são registradas, continuam valendo as mesmas invariantes do Core:

```text
Tool call da IA
        ↓
Safety / Policy
        ↓
Hard execution gate
        ↓
execute()
```

Uma Tool call da IA nunca equivale, por si só, à autorização de side effect.

WRITE continua sem retry/cache genérico e usa as proteções de idempotência do
runtime.

## Próximos passos

Depois do primeiro chat:

1. leia `docs/architecture.md`;
2. leia `docs/tools.md`;
3. leia `docs/plugins.md`;
4. leia `docs/state.md`;
5. leia `docs/security.md`;
6. leia `docs/external-consumer.md`;
7. para atualizar da v1.1, leia `docs/migration-v1.1-to-v1.2.md`.
