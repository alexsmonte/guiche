# Guichê — host externo genérico

O contexto externo confiável do Guichê não é específico de Chatwoot.

Qualquer aplicação que consiga autenticar sua própria origem pode construir
uma identity confiável e entregá-la ao Guichê antes de processar a mensagem.

## Exemplo: CRM próprio

```php
$identity = [
    'provider' => 'custom-crm',
    'account_id' => $authenticatedAccountId,
    'conversation_id' => $conversationId,
];

$sessionId = 'crm-' . hash(
    'sha256',
    implode(':', [
        $identity['provider'],
        (string) $identity['account_id'],
        (string) $identity['conversation_id'],
    ])
);

$guiche->bindIdentity(
    $sessionId,
    $identity
);

$response = $guiche->handle(
    $sessionId,
    $message
);
```

## TenantResolver

O host configura o `TenantResolver` para traduzir a identity confiável:

```text
provider + identificadores externos
                ↓
          TenantResolver
                ↓
             tenant_id
```

Essa resolução pode usar banco, procedure, API, repository ou outra fonte
confiável da aplicação.

## Regra de confiança

A origem externa precisa ser validada pelo host **antes** de chamar
`bindIdentity()`.

Não use conteúdo da mensagem, argumentos sugeridos pela IA ou valores de
Tool call como fonte de identity.

## Providers

O formato da identity é deliberadamente aberto. Exemplos possíveis:

```text
custom-crm
telegram
instagram
whatsapp-cloud-api
evolution-api
manychat
chatwoot
aplicação própria
```

Cada adapter define quais identificadores externos são suficientes para
localizar o tenant com segurança.

O Core do Guichê não precisa conhecer nenhum desses providers.
