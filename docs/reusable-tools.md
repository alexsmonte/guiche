# Guichê v1.6 — Reusable Tools com validade contextual

## Objetivo

`withReusableTools()` já permitia reaproveitar o último resultado bem-sucedido de uma Tool `READ` dentro da mesma sessão quando `Tool + argumentos` possuíam o mesmo fingerprint.

A v1.6 mantém esse comportamento como padrão e acrescenta uma camada opcional de validade baseada no contexto atual da conversa.

O objetivo é impedir que um resultado antigo seja reutilizado quando fatos operacionais relevantes mudaram, mesmo que a IA repita exatamente os mesmos argumentos.

## Compatibilidade

Nada muda para uma Tool que:

- não está em `withReusableTools()`;
- está em `withReusableTools()` mas não declara `reuse.depends_on`.

Nesse caso permanece a regra histórica:

```text
sessão + nome da Tool + argumentos vinculados
```

`WRITE` não usa este cache. A proteção de `WRITE` continua sendo o mecanismo separado de replay/idempotência.

## Declarando dependências

A configuração fica nos metadados do `ToolRegistration`:

```php
new ToolRegistration(
    $tool,
    [
        'operation' => 'read',
        'reuse' => [
            'depends_on' => [
                'state.convenio.nome',
                'state.procedimento.id',
                'execution_context.tenant_id',
            ],
        ],
    ]
);
```

A Tool ainda precisa estar habilitada para reuso:

```php
$builder->withReusableTools([
    'MinhaTool',
]);
```

Os dois mecanismos possuem funções diferentes:

- `withReusableTools()` — habilita o reuso;
- `reuse.depends_on` — define quando o resultado continua válido.

## Como funciona

Na primeira execução:

```text
Tool call
→ Trusted Parameter Binding
→ Safety Gate
→ assinatura das dependências atuais
→ execução real
→ resultado normal para StateManager/IA
→ cópia do resultado + assinatura no tool_history
```

Em uma chamada posterior com os mesmos argumentos:

```text
Safety Gate
→ assinatura das dependências atuais
→ procura último resultado bem-sucedido
→ compara assinatura
```

Se a assinatura for igual:

```text
tool_cache_hit
→ Tool não executa novamente
```

Se a assinatura mudou:

```text
tool_cache_invalidated
reason=dependency_changed
→ Tool executa novamente
```

## Migração segura

Históricos criados antes desta funcionalidade não possuem assinatura de dependências.

Quando uma Tool passa a declarar `reuse.depends_on`, uma entrada antiga sem assinatura é tratada como inválida:

```text
reason=dependency_signature_missing
```

A Tool executa novamente e o novo resultado passa a carregar a assinatura interna.

Isso evita aceitar silenciosamente um cache legado cuja validade contextual não pode ser comprovada.

## Estado A → B → A

O Guichê consulta deliberadamente apenas o resultado bem-sucedido mais recente para o fingerprint de argumentos.

Exemplo:

```text
A → executa e grava A
B → invalida A, executa e grava B
A → B é o registro mais recente e não corresponde a A
  → executa novamente
```

Uma entrada antiga de A não é ressuscitada atrás de B.

Essa decisão favorece segurança e previsibilidade em vez de maximizar a taxa de cache hit.

## Chave interna reservada

A assinatura é adicionada somente à cópia persistida no `tool_history`, sob namespace interno:

```text
__guiche_reuse_v1
```

Ela não é entregue:

- à Tool;
- ao `StateManager`;
- à IA;
- ao evento `tool_result`.

Chaves iniciadas por `__guiche_` são reservadas ao runtime.

## Invalidação não destrutiva

A v1.6 não apaga entradas antigas do histórico.

A invalidação é lógica: uma entrada incompatível simplesmente deixa de ser elegível para reuso.

Isso preserva auditoria e evita mutações destrutivas no histórico operacional.

## Interação com Trusted Parameter Binding

A ordem continua:

```text
argumentos da IA
→ Trusted Parameter Binding
→ Safety Gate
→ fingerprint/cache
→ execução
```

Logo, valores protegidos injetados pelo runtime já participam do fingerprint de argumentos.

`reuse.depends_on` complementa esse mecanismo quando a validade também depende de fatos do contexto que não pertencem aos argumentos da Tool.

## Fora do escopo desta fase

Esta fase não implementa:

- TTL de sessão;
- expiração temporal do cache;
- purge automático;
- cache distribuído;
- persistência externa dedicada;
- retry de WRITE;
- alteração da idempotência de WRITE.

Expiração temporal e lifecycle de sessão pertencem às fases seguintes da v1.6.
