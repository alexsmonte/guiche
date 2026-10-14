# Guichê — exemplo básico

Este diretório demonstra a menor integração útil do Guichê com:

- `BasicPlugin`;
- uma Tool local e determinística chamada `CatalogSearch`;
- `GuicheBuilder`;
- `ContextStore`;
- `OpenAiResponsesProvider`;
- uma CLI interativa em `chat.php`.

O catálogo é local: não usa banco de dados, HTTP, Clinsoft ou qualquer API privada.

## Executar

Na raiz do projeto:

```bash
export OPENAI_API_KEY="sua-chave"
export OPENAI_MODEL="gpt-5.6-luna"

php8.4 examples/basic/chat.php
```

Ou faça uma única pergunta:

```bash
php8.4 examples/basic/chat.php "Quais serviços existem no catálogo?"
```

No modo interativo:

- `/reset` apaga o contexto da sessão do exemplo;
- `/sair` encerra a CLI.

Os contextos desse exemplo são gravados em:

```text
storage/examples/basic/conversations/
```

> O README principal e o Quick Start oficial serão consolidados na Fase 03 da v0.7.
