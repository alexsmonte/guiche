# Tools no Guichê

Tools são capacidades executáveis expostas ao runtime por meio de `ToolInterface`.

Elas representam operações que a IA pode solicitar, mas a decisão do modelo **não é autorização suficiente para execução**. O caminho real passa pelas políticas e pela Safety Layer.

## ToolInterface

O contrato público possui quatro responsabilidades essenciais:

```php
interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    public function parameters(): array;

    public function execute(array $arguments): array;
}
```

Uma Tool deve possuir nome estável, descrição útil ao provider, schema de argumentos e retorno serializável.

## Schema de argumentos

`parameters()` descreve os argumentos aceitos.

Exemplo:

```php
public function parameters(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'query' => [
                'type' => 'string',
            ],
        ],
        'required' => [],
        'additionalProperties' => false,
    ];
}
```

O schema é usado para definição da Tool e para validação estrutural antes da execução.

## Registro

Tools podem ser registradas pelo `GuicheBuilder`.

```php
$builder->registerTool(
    new ToolRegistration(
        new CatalogTool(),
        [
            'operation' => 'read',
            'domain' => 'basic-example',
        ]
    )
);
```

Plugins normalmente fazem esse registro em `PluginInterface::register()`.

## ToolRegistration

`ToolRegistration` associa:

```text
ToolInterface
+
metadados declarativos
```

Os metadados internos não devem ser confundidos com a definição enviada à IA.

Eles podem carregar informações usadas por segurança e composição do runtime.

## READ e WRITE

Toda Tool deve ser pensada explicitamente quanto a side effects.

### READ

Operações de leitura:

- consultam;
- calculam;
- recuperam;
- não devem produzir side effect externo relevante.

Uma READ pode ser elegível a reutilização/cache quando a configuração permitir.

### WRITE

Operações de escrita:

- criam;
- alteram;
- enviam;
- cancelam;
- confirmam;
- ou produzem outro side effect externo.

WRITE exige tratamento mais restritivo.

No Guichê, WRITE não deve depender de retry cego nem de cache genérico para evitar duplicação de efeitos.

## Caminho de execução

Conceitualmente:

```text
AI solicita Tool
      ↓
Tool registrada?
      ↓
Policy permite?
      ↓
estágio permitido?
      ↓
preconditions atendidas?
      ↓
argumentos válidos?
      ↓
confirmação, se exigida?
      ↓
proteção de escrita/idempotência
      ↓
Tool::execute()
```

Qualquer bloqueio anterior deve impedir que `execute()` seja alcançado.

## ToolPolicy

`ToolPolicy` fornece regras de autorização ligadas ao runtime.

Policy declarativa registrada por Plugin e enforcement efetivo são conceitos relacionados, mas separados.

Não coloque segurança crítica apenas na descrição da Tool ou no prompt.

## ToolExecutionGate

`ToolExecutionGate` é a fronteira de enforcement da Safety Layer.

Ele trabalha com os registros e metadados de segurança para decidir se a operação pode chegar à execução.

Bloqueios são representados por `ToolBlockedException` com informação estruturada para auditoria.

## Allowed stages

Uma Tool pode declarar estágios em que sua execução é aceita.

Exemplo conceitual:

```text
allowed_stages = [
    "awaiting_search",
    "ready_to_query"
]
```

O caminho do estágio pode ser configurado de modo neutro ao domínio.

## Preconditions de estado

Metadados podem exigir ou proibir dados no estado:

```text
requires_state
forbids_state
```

Isso evita executar uma Tool apenas porque a IA conseguiu formar argumentos sintaticamente válidos.

## Confirmação

Uma Tool de escrita pode exigir confirmação explícita.

A confirmação precisa estar representada no estado esperado pelo gate. Uma frase gerada pelo modelo dizendo que "está confirmado" não substitui o dado confiável de estado.

## Idempotência

Side effects precisam ser protegidos contra repetição.

A Safety Layer utiliza fingerprint normalizado para distinguir chamadas e permitir replay idempotente de escrita quando aplicável.

Regra:

```text
WRITE repetida ≠ executar novamente automaticamente
```

## Reutilização e cache

READ pode usar reutilização quando configurada.

WRITE não usa o cache genérico de READ.

São mecanismos diferentes:

```text
READ  → cache/reuse possível
WRITE → replay idempotente explícito
```

## Result Projection

O retorno bruto de uma Tool pode conter dados que não devem voltar para a IA.

Result Projection permite definir a superfície liberada ao modelo.

Exemplo conceitual:

```text
resultado bruto
├── dado funcional             → IA
├── id necessário              → IA
├── payload HTTP interno       → removido
├── headers                    → removido
└── metadados de infraestrutura→ removido
```

A projeção para IA não deve ser confundida com política de observabilidade.

## Erros

Uma Tool deve lançar exceção para erros de execução reais ou devolver o envelope esperado pelo domínio conforme o contrato adotado.

O Core registra falhas e não deve transformar falha de WRITE em retry automático.

## Exemplo básico

`examples/basic/src/CatalogTool.php` é a referência mínima.

Características:

```text
nome      CatalogSearch
operação  READ
fonte     catálogo local
banco     nenhum
HTTP      nenhum
side effect nenhum
```

O exemplo é intencionalmente pequeno para mostrar o contrato sem ruído de infraestrutura.

## Recomendações

Ao criar uma Tool:

1. use nome estável;
2. descreva uma única capacidade;
3. mantenha schema restritivo;
4. use `additionalProperties: false` quando apropriado;
5. classifique READ/WRITE;
6. declare preconditions de segurança;
7. minimize o retorno;
8. não coloque segredo em descrição ou resultado;
9. evite side effects escondidos em Tools declaradas READ;
10. teste autorização e bloqueio antes de testar apenas o caminho feliz.
