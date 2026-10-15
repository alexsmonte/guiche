# Migração Guichê v1.1 → v1.2

## Resumo

A v1.2.0 é uma evolução aditiva de Developer Experience.

Não há quebra da API pública da linha v1 e aplicações que já montam o runtime
diretamente com `GuicheBuilder` podem continuar funcionando sem alteração.

## O que muda

A v1.2 adiciona a CLI oficial:

```bash
vendor/bin/guiche
```

Comandos principais:

```bash
vendor/bin/guiche init
vendor/bin/guiche doctor
vendor/bin/guiche chat
vendor/bin/guiche usage
```

## Antes

Um primeiro uso normalmente exigia copiar o exemplo ou criar/manter um
entrypoint semelhante a:

```text
chat.php
```

## Agora

Em um projeto novo:

```bash
composer require asmpkg/guiche:^1.2
vendor/bin/guiche init
```

Depois configure `.env` e execute:

```bash
vendor/bin/guiche doctor
vendor/bin/guiche chat
```

## Prompt

O `init` cria:

```text
guiche.prompt.md
```

A resolução de prompt segue:

```text
GUICHE_PROMPT_FILE
        ↓
GUICHE_PROMPT
        ↓
guiche.prompt.md
        ↓
prompt padrão do pacote
```

Projetos que já usam instruction fragments por Plugins ou
`GuicheBuilder::withInstructions()` continuam podendo manter o bootstrap
existente.

## Variáveis de IA

Nomes canônicos:

```dotenv
AI_API_KEY=
AI_MODEL=gpt-5.6-luna
```

Aliases legados ainda aceitos:

```dotenv
OPENAI_API_KEY=
OPENAI_MODEL=
```

## Persistência

A CLI padrão grava contexto em:

```text
storage/conversations/
```

Esse caminho pertence ao projeto consumidor, não ao diretório
`vendor/asmpkg/guiche`.

## Segurança

A CLI não altera o modelo de autorização.

Continuam válidos:

- Tool call da IA não é autorização;
- Tool Policy;
- hard execution gate imediatamente antes de `execute()`;
- validação de argumentos e estado;
- WRITE sem cache/retry genérico;
- replay/idempotência;
- Result Projection;
- contexto confiável multi-tenant.

## Compatibilidade

A superfície pública congelada da v1 permanece compatível.

A implementação da CLI e o `PromptResolver` são classificados como `internal`,
portanto não ampliam o contrato público BC da biblioteca.

## Checklist de atualização

```bash
composer require asmpkg/guiche:^1.2
vendor/bin/guiche init
vendor/bin/guiche doctor
vendor/bin/guiche chat
```

Se sua aplicação já possui bootstrap próprio, a adoção da CLI é opcional.
