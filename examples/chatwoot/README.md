# Guichê + Chatwoot — contexto externo confiável

Este exemplo mostra como uma aplicação hospedeira pode ligar uma conversa do
Chatwoot a uma sessão do Guichê sem colocar dados de tenant no prompt e sem
pedir à IA que descubra a empresa.

## Fronteira de confiança

O adapter deste diretório começa **depois** da autenticação/validação feita
pelo host.

```text
Internet
  ↓
endpoint do host
  ↓
autenticação / controles de confiança do host
  ↓
payload considerado confiável
  ↓
ChatwootWebhookAdapter
  ↓
Guiche::bindIdentity()
  ↓
TenantResolver
  ↓
tenant_id
  ↓
Guiche::handle()
```

O exemplo deliberadamente não implementa autenticação de webhook porque a
política depende da implantação do aplicativo. O Guichê não deve inventar uma
prova de confiança que o provedor/host não tenha estabelecido.

## Identity usada

Para `message_created`:

```php
[
    'provider' => 'chatwoot',
    'account_id' => $payload['account']['id'],
    'inbox_id' => $payload['inbox']['id'],
    'conversation_id' => $payload['conversation']['id'],
]
```

A mensagem individual (`payload.id`) não entra na identity. Assim, mensagens
sucessivas da mesma conversa reutilizam a mesma sessão.

## Session ID

O adapter deriva uma chave opaca:

```text
chatwoot-<sha256>
```

O hash usa:

```text
provider + account_id + inbox_id + conversation_id
```

Nenhum desses IDs aparece em claro na session ID.

## Uso

```php
$adapter = new ChatwootWebhookAdapter();

$identity = $adapter->identityFromTrustedPayload($trustedPayload);
$sessionId = $adapter->sessionId($identity);

$guiche->bindIdentity($sessionId, $identity);

$response = $guiche->handle(
    $sessionId,
    $adapter->messageFromTrustedPayload($trustedPayload)
);
```

Ou, usando o helper:

```php
$response = $adapter->handleTrustedIncoming(
    $guiche,
    $trustedPayload
);
```

## TenantResolver

O tenant continua sendo responsabilidade do `TenantResolver` configurado no
Guichê.

Exemplo conceitual:

```php
new CallbackTenantResolver(
    static function (array $identity): array {
        // SELECT/API/repository do host:
        // provider + account_id + inbox_id -> tenant_id

        return ['tenant_id' => 142];
    }
);
```

A IA não escolhe `tenant_id`.

## Proteções do adapter

O exemplo falha fechado quando:

- `event` não é `message_created`;
- `message_type` não é `incoming`;
- a mensagem é privada;
- `account.id`, `inbox.id` ou `conversation.id` estão ausentes/inválidos;
- `inbox.id` contradiz `conversation.inbox_id`;
- `account.id` contradiz `conversation.account_id`;
- `content` não é texto ou está vazio.

## Escopo

Este é um **adapter de referência**, não uma dependência do Core.

Nenhum namespace/classe Chatwoot é adicionado a `src/`.
