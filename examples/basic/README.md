# Exemplo básico

Exemplo mínimo e neutro de domínio para validar o Guichê com uma Tool local
somente de leitura.

## Preparar

Na raiz do projeto consumidor:

```bash
cp examples/basic/.env.example .env
```

Edite `.env` e informe:

```dotenv
AI_API_KEY=sua-chave
AI_MODEL=gpt-5.6-luna
```

## Executar

```bash
php examples/basic/chat.php
```

Ou envie uma única mensagem:

```bash
php examples/basic/chat.php "Qual é o preço do Suporte Pro?"
```

Comandos locais do modo interativo:

```text
/context
/usage
/tools
/reset
/sair
```

Esses comandos são tratados pela CLI e não são enviados à IA.

A Tool `CatalogSearch` é local, determinística, `READ` e não produz
side effects.
