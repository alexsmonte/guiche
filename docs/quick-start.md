# Quick Start do Guichê

Este guia demonstra a menor experiência funcional da linha estável **v1.x**
usando o exemplo oficial de catálogo.

## Objetivo

Ao final, você terá:

```text
terminal
   ↓
chat.php
   ↓
GuicheBuilder
   ↓
BasicPlugin
   ↓
CatalogSearch
   ↓
OpenAiResponsesProvider
   ↓
resposta
```

O catálogo é local. Não existe banco de dados nem serviço privado por trás
da Tool.

## Requisitos

- PHP `^8.4`;
- Composer;
- uma chave de API para o provider usado na conversa real.

## Projeto novo

```bash
mkdir meu-guiche
cd meu-guiche
composer require asmpkg/guiche:^1.0
```

## Copiar o exemplo

```bash
mkdir -p examples
cp -R vendor/asmpkg/guiche/examples/basic examples/basic
```

Na v1.1.1 o exemplo continua copiável para fora de `vendor/`, mas não exige
mais `export` manual de credenciais.

## Configurar ambiente

```bash
cp examples/basic/.env.example .env
nano .env
```

Preencha:

```dotenv
AI_API_KEY=sua-chave
AI_MODEL=gpt-5.6-luna
```

O `chat.php` carrega `.env` automaticamente.

## Conversa interativa

```bash
php examples/basic/chat.php
```

Experimente:

```text
Quais serviços existem?
```

Depois:

```text
Quanto custa o Suporte Pro?
```

A Tool `CatalogSearch` é a fonte de verdade do catálogo.

## Mensagem única

```bash
php examples/basic/chat.php "Liste os serviços de suporte"
```

## Comandos locais

No modo interativo:

```text
/context
/usage
/tools
/reset
/sair
```

Esses comandos são processados localmente e não são enviados à IA.

## O que observar no código

### CatalogTool

Arquivo:

```text
examples/basic/src/CatalogTool.php
```

Implementa `ToolInterface` e contém dados locais determinísticos.

### BasicPlugin

Arquivo:

```text
examples/basic/src/BasicPlugin.php
```

Implementa `PluginInterface` e registra `CatalogSearch` no `GuicheBuilder`
como operação `read`.

### chat.php

Arquivo:

```text
examples/basic/chat.php
```

Monta:

1. carregamento automático de `.env`;
2. `ContextStore`;
3. `OpenAiResponsesProvider`;
4. `GuicheBuilder`;
5. `BasicPlugin`;
6. runtime via `build()`;
7. conversa via `handle()`.

## Regra de segurança do exemplo

O exemplo básico não executa escrita externa.

```text
CatalogSearch
operation = read
side effect = nenhum
fonte = catálogo local
```

Isso deixa a primeira experiência concentrada no fluxo
`mensagem → IA → Tool → IA → resposta` sem introduzir banco, credenciais de
domínio ou efeitos irreversíveis.

## Próximos passos

Depois de dominar o exemplo básico:

1. leia `docs/architecture.md`;
2. leia `docs/tools.md`;
3. leia `docs/plugins.md`;
4. leia `docs/state.md`;
5. leia `docs/security.md`.

A v1.2.0 eliminará também a necessidade de copiar/manter manualmente
`chat.php`, por meio da CLI oficial `vendor/bin/guiche`.
