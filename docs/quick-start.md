# Quick Start do Guichê

Este guia demonstra a menor experiência funcional da versão **v0.7.0** usando o exemplo oficial de catálogo.

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

O catálogo é local. Não existe banco de dados nem serviço privado por trás da Tool.

## Requisitos

- PHP `^8.4`;
- Composer;
- `OPENAI_API_KEY` para a conversa real.

## Projeto novo

```bash
mkdir meu-guiche
cd meu-guiche
composer require asmpkg/guiche:^0.7
```

## Copiar o exemplo

```bash
mkdir -p examples
cp -R vendor/asmpkg/guiche/examples/basic examples/basic
```

O exemplo é copiado para fora de `vendor/` de propósito. Assim o código pode ser estudado e modificado como código da aplicação, sem editar a dependência instalada.

## Configurar ambiente

```bash
export OPENAI_API_KEY="sua-chave"
export OPENAI_MODEL="gpt-5.6-luna"
```

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

## Reiniciar a sessão

No modo interativo:

```text
/reset
```

Para encerrar:

```text
/sair
```

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

Implementa `PluginInterface` e registra `CatalogSearch` no `GuicheBuilder` como operação `read`.

### chat.php

Arquivo:

```text
examples/basic/chat.php
```

Monta:

1. `ContextStore`;
2. `OpenAiResponsesProvider`;
3. `GuicheBuilder`;
4. `BasicPlugin`;
5. runtime via `build()`;
6. conversa via `handle()`.

## Regra de segurança do exemplo

O exemplo básico não executa escrita externa.

```text
CatalogSearch
operation = read
side effect = nenhum
fonte = catálogo local
```

Isso deixa a primeira experiência concentrada no fluxo `mensagem → IA → Tool → IA → resposta` sem introduzir banco, credenciais de domínio ou efeitos irreversíveis.

## Próximos passos

Depois de dominar o exemplo básico:

1. leia `docs/architecture.md`;
2. leia `docs/tools.md`;
3. leia `docs/plugins.md`;
4. leia `docs/state.md`;
5. leia `docs/security.md`.

Esses documentos técnicos são parte da entrega de Developer Experience da v0.7.
