# Trusted Parameter Binding

Disponível a partir da v1.6.

Trusted Parameters permitem que uma Tool declare argumentos que fazem parte do
schema real de execução, mas não ficam sob controle da IA.

## Problema

Uma Tool pode precisar de:

- `tenant_id` / `empresa_id`;
- `session_id`;
- identificador fixo de origem;
- valor de configuração;
- valor resolvido por serviço confiável.

Esses valores não devem depender do modelo.

## Declaração

A declaração usa metadados já suportados por `ToolRegistration`:

```php
new ToolRegistration(
    $tool,
    [
        'operation' => 'read',

        'trusted_parameter_config' => [
            'region' => 'AM',
        ],

        'trusted_parameters' => [
            'empresa_id' => [
                'source' => 'context',
                'path' => 'tenant_id',
                'visible' => false,
            ],

            'sessao_id' => [
                'source' => 'context',
                'path' => 'session_id',
                'visible' => false,
            ],

            'origin' => [
                'source' => 'fixed',
                'value' => 'guiche',
                'visible' => false,
            ],

            'region' => [
                'source' => 'config',
                'path' => 'region',
                'visible' => false,
            ],
        ],
    ]
);
```

## Fontes

### `ai`

Comportamento normal. O argumento continua visível e controlado pela IA.

### `context`

Lê do `ToolExecutionContext` confiável da sessão.

`session_id` é um path especial que lê `ToolExecutionContext::sessionId()`.

Exemplo:

```php
[
    'source' => 'context',
    'path' => 'tenant_id',
    'visible' => false,
]
```

### `fixed`

Valor literal definido no registro:

```php
[
    'source' => 'fixed',
    'value' => 'chatwoot',
    'visible' => false,
]
```

### `config`

Lê por path de `trusted_parameter_config`:

```php
'trusted_parameter_config' => [
    'clinic' => [
        'country' => 'BR',
    ],
],

'trusted_parameters' => [
    'country' => [
        'source' => 'config',
        'path' => 'clinic.country',
        'visible' => false,
    ],
],
```

### `service`

Usa um callable confiável registrado pela aplicação:

```php
[
    'source' => 'service',
    'visible' => false,
    'resolver' => static function (
        ?ToolExecutionContext $context,
        array $conversationContext,
        string $toolName,
        string $parameter
    ): string {
        return 'valor-confiavel';
    },
]
```

## Ordem de segurança

O runtime executa:

```text
Tool call da IA
→ Trusted Parameter Binding
→ ToolExecutionGate
→ fingerprint/cache/idempotência
→ revalidação + novo binding
→ Tool execute
→ StateManager
```

Consequências:

1. parâmetro oculto não aparece no schema enviado à IA;
2. valor forjado pela IA é substituído antes da validação;
3. o gate valida o argumento final real;
4. fingerprint e idempotência usam o valor confiável;
5. a Tool recebe exatamente os argumentos efetivos;
6. o StateManager recebe os mesmos argumentos efetivos.

## Compatibilidade

Tools sem `trusted_parameters` mantêm o comportamento anterior.

Nenhuma assinatura pública da v1 foi alterada.
