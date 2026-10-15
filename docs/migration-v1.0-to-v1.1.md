# Migração v1.0 → v1.1

A v1.1 é uma minor release compatível.

## Para quem não usa multi-tenancy

Nenhuma migração é obrigatória.

Tools existentes continuam implementando:

```php
interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function parameters(): array;
    public function execute(array $arguments): array;
}
```

`ContextStoreInterface` também permanece compatível.

## Quando migrar uma Tool

Migre para `ContextAwareToolInterface` quando a Tool depender de:

- tenant;
- empresa;
- workspace;
- account;
- canal/phone number que determine tenant;
- outra identidade de execução que não deve vir da IA.

## Antes

```php
final class MinhaTool implements ToolInterface
{
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'empresa_id' => ['type' => 'integer'],
                'query' => ['type' => 'string'],
            ],
            'required' => ['empresa_id', 'query'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        $empresaId = (int)$arguments['empresa_id'];

        // ...
    }
}
```

Problema: `empresa_id` pode ser produzido ou alterado pelo modelo.

## Depois

```php
final class MinhaTool implements ContextAwareToolInterface
{
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
        return $this->executeLegacy($arguments);
    }

    public function executeWithContext(
        array $arguments,
        ToolExecutionContext $context
    ): array {
        $empresaId = $context->tenantId();

        // ...
    }
}
```

## Passos

1. identifique a identity confiável da sessão;
2. grave a identity com `ExecutionContextSeeder`;
3. implemente/configure `TenantResolverInterface`;
4. ligue o resolver com `GuicheBuilder::withTenantResolver()`;
5. remova tenant/empresa do schema controlado pela IA;
6. implemente `ContextAwareToolInterface`;
7. use `ToolExecutionContext::tenantId()` no acesso a dados;
8. teste A → A e B → B;
9. teste identity swap e tenant swap;
10. mantenha Safety/WRITE idempotency existentes.

## Compatibilidade de execute()

`ContextAwareToolInterface` estende `ToolInterface`.

Por isso `execute()` continua existindo.

Projetos que precisam manter chamadas PHP diretas antigas podem conservar
um caminho legado, mas o runtime contextual do Guichê usa
`executeWithContext()`.

## Presenters

Tools contextuais continuam compatíveis com Presenters.

O Builder aplica internamente um decorator específico sem mudar a API do
consumer.

## WRITE

Ao migrar uma Tool WRITE:

- não retire confirmação já existente;
- não retire `requires_state`/`forbids_state`;
- não transforme replay em retry;
- não reintroduza tenant nos argumentos;
- qualquer revalidação imediatamente anterior ao side effect deve usar o
  tenant da execução atual.

## Rollout recomendado

Primeiro migre uma Tool READ e valide dois tenants.

Depois migre WRITE com testes explícitos de:

- tenant correto;
- side effect zero sem contexto;
- side effect zero em resolução inválida;
- replay idempotente;
- revalidação no tenant correto.
