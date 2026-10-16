# Contexto dinâmico multi-tenant

A v1.1 permite que a mesma Tool seja executada para tenants diferentes sem
confiar em identificadores produzidos pela IA.

## Modelo

```text
SESSION
  ↓
IDENTITY
  ↓
TENANT RESOLVER
  ↓
TENANT
  ↓
TOOL EXECUTION CONTEXT
  ↓
CONTEXT-AWARE TOOL
```

A regra principal é:

> tenant é contexto de execução confiável, não argumento de negócio
> controlado pelo modelo.

## 1. Vincule uma identity à sessão

```php
use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;

$store = new ContextStore(
    __DIR__ . '/storage/context',
    'America/Manaus'
);

$seeder = new ExecutionContextSeeder($store);

$seeder->seed(
    'session-123',
    [
        'identity' => [
            'provider' => 'whatsapp',
            'phone_number_id' => 'phone-abc',
        ],
    ]
);
```

Uma identity válida deve ser derivada de dados confiáveis do canal ou da
aplicação.

Não use texto gerado pela IA para construir essa identity.

## 2. Configure um TenantResolver

O contrato é:

```php
interface TenantResolverInterface
{
    public function resolve(array $identity): array;
}
```

O resolver recebe somente a identity confiável.

### Repository / service / callback

```php
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;

$resolver = new CallbackTenantResolver(
    static function (array $identity): array {
        $phoneNumberId =
            (string)($identity['phone_number_id'] ?? '');

        $tenantId = meuRepositorio()
            ->tenantPorPhoneNumberId($phoneNumberId);

        return $tenantId === null
            ? []
            : ['tenant_id' => $tenantId];
    }
);
```

### SELECT

Use `SqlTenantResolver` quando a resolução for uma consulta SQL simples.

Conceitualmente:

```sql
SELECT empresa_id AS tenant_id
FROM canais
WHERE phone_number_id = :phone_number_id
```

A consulta precisa resolver no máximo um tenant. Resultado ausente ou
ambíguo deve falhar fechado.

### Stored Procedure

O mesmo resolver SQL pode encapsular uma procedure, por exemplo:

```sql
CALL resolver_tenant_por_canal(:phone_number_id)
```

A procedure deve devolver um único `tenant_id`.

### API HTTP

Use `HttpTenantResolver` quando a fonte de autoridade estiver em outro
serviço.

A requisição deve enviar somente os campos permitidos da identity e a
resposta deve ser mapeada para:

```php
['tenant_id' => 142]
```

Erros, ausência de tenant ou resposta inválida não liberam a Tool.

## 3. Ligue o resolver ao GuicheBuilder

```php
$guiche = (new GuicheBuilder())
    ->withContextStore($store)
    ->withTenantResolver($resolver)
    ->registerPlugin($plugin)
    ->build();
```

## 4. Implemente ContextAwareToolInterface

```php
use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Execution\ToolExecutionContext;

final class ConsultarDadosTool implements ContextAwareToolInterface
{
    public function name(): string
    {
        return 'ConsultarDados';
    }

    public function description(): string
    {
        return 'Consulta dados no tenant vinculado à sessão.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        return ['success' => false];
    }

    public function executeWithContext(
        array $arguments,
        ToolExecutionContext $context
    ): array {
        $tenantId = $context->tenantId();

        if ($tenantId === null) {
            throw new RuntimeException(
                'Tenant não disponível.'
            );
        }

        return meuRepositorio()
            ->consultar($tenantId, $arguments['query']);
    }
}
```

## Segurança

### Não exponha tenant como argumento de IA

Evite:

```php
'properties' => [
    'empresa_id' => ['type' => 'integer'],
]
```

Prefira:

```php
$empresaId = $context->tenantId();
```

### Binding é imutável

Por sessão:

- `identity` é write-once;
- `tenant_id` é write-once após resolução.

Tentativas de troca falham fechadas.

### Sem contexto, sem side effect

Uma ContextAware Tool:

- precisa de `sessionId`;
- precisa de identity/tenant válidos;
- não executa quando a resolução falha.

### Safety continua obrigatória

Multi-tenancy não substitui:

- ToolPolicy;
- allowed stages;
- requires/forbids state;
- confirmação;
- Gate 1 / Gate 2;
- idempotência de WRITE;
- Result Projection.

## Legacy Tools

Nada muda para uma Tool que implementa apenas `ToolInterface`.

```text
ToolInterface
→ execute(arguments)
```

Ela não exige TenantResolver nem session context.

## Isolamento esperado

```text
session-a → identity-a → tenant-a → Tool
session-b → identity-b → tenant-b → mesma Tool
```

A mesma classe/instância pode servir os dois tenants sem misturar o
escopo de dados.

## Binding recomendado a partir da v1.3

Aplicações externas não precisam instanciar `ExecutionContextSeeder`
diretamente.

O caminho público recomendado é:

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

Quando um `TenantResolverInterface` está configurado, o próprio binding
resolve e persiste o tenant antes de retornar.

```text
trusted host
   ↓
bindIdentity()
   ↓
identity write-once
   ↓
TenantResolver
   ↓
tenant_id write-once
   ↓
ToolExecutionContext
```

A mesma identity pode ser vinculada novamente de forma idempotente. Uma
identity diferente para a mesma sessão é rejeitada.

`bindIdentity()` e `handle()` usam o mesmo lock por sessão para impedir que
um turno atravesse um binding ainda em andamento.

Veja `docs/trusted-external-identity.md`.
