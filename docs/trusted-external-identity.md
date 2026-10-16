# Trusted External Identity

A partir do Guichê v1.3, aplicações externas possuem uma API pública direta
para vincular identidade confiável a uma sessão:

```php
$guiche->bindIdentity(
    $sessionId,
    [
        'provider' => 'custom-crm',
        'account_id' => $accountId,
        'conversation_id' => $conversationId,
    ]
);
```

## Modelo de confiança

`bindIdentity()` não autentica o sistema externo.

A ordem correta é:

```text
request externo
      ↓
host autentica / valida origem
      ↓
host extrai identidade confiável
      ↓
Guiche::bindIdentity()
      ↓
TenantResolver
      ↓
tenant_id
      ↓
Guiche::handle()
```

Nunca use texto do usuário, saída da IA ou argumentos de Tool como fonte de
autoridade para a identity.

## Contrato da identity

A identity é um mapa associativo não vazio.

Exemplo:

```php
[
    'provider' => 'custom-crm',
    'account_id' => 42,
    'conversation_id' => 9001,
]
```

Os nomes dos campos são definidos pelo host/adapter.

Valores precisam ser serializáveis com segurança. Arrays, strings,
inteiros, floats finitos, booleanos e `null` são aceitos. Objetos,
resources e floats não finitos são rejeitados.

## Imutabilidade

Por sessão:

- identity é write-once;
- tenant é write-once;
- mesmo binding pode ser repetido;
- binding conflitante falha fechado.

```text
session A → identity A → tenant A
session B → identity B → tenant B
```

Uma sessão não pode trocar silenciosamente para outro tenant.

## TenantResolver

Se o Guichê foi construído com `TenantResolverInterface`, `bindIdentity()`
aciona a resolução automaticamente.

O resolver recebe somente a identity confiável.

```php
$resolver = new CallbackTenantResolver(
    static function (array $identity): array {
        return [
            'tenant_id' => 142,
        ];
    }
);
```

Campos extras retornados pelo resolver não são promovidos ao
`execution_context`.

## ToolExecutionContext

Context-aware Tools continuam recebendo:

```php
$context->sessionId();
$context->tenantId();
$context->get('identity');
```

O tenant vem do contexto confiável e não de argumentos sugeridos pela IA.

## Concorrência

`bindIdentity()` e `handle()` usam o mesmo lock por sessão.

Consequências:

- um turno não atravessa um binding ainda em andamento;
- múltiplos bindings iguais são idempotentes;
- bindings conflitantes falham fechado;
- sessões diferentes não se bloqueiam entre si.

## Observabilidade

Eventos de binding e resolução são auditáveis, mas não carregam identity ou
tenant em claro.

São utilizados fingerprints SHA-256 para correlação.

Além disso, o sanitizador descarta chaves:

```text
identity
tenant_id
execution_context
```

inclusive quando aparecem aninhadas em atributos de eventos.

## Chatwoot

O adapter oficial de referência está em:

```text
examples/chatwoot/
```

Ele usa:

```text
provider=chatwoot
account_id
inbox_id
conversation_id
```

Chatwoot não é dependência do Core.

## Outros providers

O mesmo mecanismo pode atender:

```text
CRM próprio
Telegram
Instagram
WhatsApp Cloud API
Evolution API
ManyChat
webhook próprio
outros sistemas
```

Veja:

```text
examples/external-host/
```

## API recomendada

Aplicações normais devem preferir:

```php
$guiche->bindIdentity($sessionId, $identity);
$guiche->handle($sessionId, $message);
```

`ExecutionContextSeeder` permanece disponível por compatibilidade e uso
avançado, mas não é necessário para a integração comum.
