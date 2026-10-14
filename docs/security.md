# Segurança no Guichê

A segurança do Guichê parte de uma regra simples:

> o fato de a IA solicitar uma Tool não significa que a Tool está autorizada a executar.

O framework aplica segurança no caminho de execução, além das orientações de prompt.

## Modelo de proteção

```text
IA solicita Tool
      ↓
registro
      ↓
Policy
      ↓
metadata de segurança
      ↓
validação de argumentos
      ↓
preconditions de estado
      ↓
confirmação
      ↓
idempotência / duplicate-write protection
      ↓
Tool::execute()
```

Se uma etapa falhar, o side effect não deve acontecer.

## Componentes

A Safety Layer possui:

- `ToolSafetyMetadata`;
- `ToolArgumentValidator`;
- `ToolExecutionGate`;
- `ToolBlockedException`.

## Fail closed

Uma condição desconhecida ou inválida de segurança não deve ser convertida silenciosamente em permissão.

Exemplo:

```text
operation = valor desconhecido
        ↓
bloquear
```

## Registro

Uma Tool precisa estar registrada.

Uma chamada para nome inexistente deve ser bloqueada antes de qualquer tentativa de execução.

## ToolPolicy

`ToolPolicy` pode limitar a Tool com base no estado ou estágio do runtime.

Policy é enforcement de aplicação, não documentação para o modelo.

## READ e WRITE

A classificação de operação muda o tratamento de risco.

### READ

READ pode consultar dados e pode ser reutilizada/cacheada quando explicitamente configurada.

Uma Tool marcada READ não deve esconder side effects relevantes.

### WRITE

WRITE representa side effect.

Exemplos:

```text
criar
alterar
cancelar
enviar
confirmar
efetivar
```

WRITE recebe proteções adicionais.

## Allowed stages

Tools podem limitar os estágios válidos para execução.

```text
allowed_stages
```

O `stage_path` pode apontar para um caminho de estado específico do Plugin sem acoplar o Core ao domínio.

## requires_state

`requires_state` representa preconditions obrigatórias.

Exemplos conceituais:

```text
campo precisa existir
campo precisa ser igual a determinado valor
```

A ausência ou divergência bloqueia antes da Tool.

## forbids_state

`forbids_state` bloqueia a Tool quando determinado estado já existe.

É útil para impedir uma nova escrita quando o efeito já foi concluído.

## Validação de argumentos

`ToolArgumentValidator` valida a estrutura de argumentos contra o schema da Tool.

Entre as validações suportadas estão:

- campos obrigatórios;
- tipos;
- propriedades adicionais quando proibidas.

O objetivo é impedir argumentos estruturalmente inválidos antes de `execute()`.

## Confirmação

Operações sensíveis podem exigir confirmação representada no estado.

A confirmação deve vir do fluxo confiável da aplicação.

Não trate texto gerado pela própria IA como confirmação suficiente de uma intenção do usuário.

## Hard execution gate

`ToolExecutionGate` é a fronteira final anterior à execução.

Isso evita a arquitetura frágil:

```text
prompt diz "não faça"
      ↓
modelo pede mesmo assim
      ↓
Tool executa
```

O comportamento desejado é:

```text
modelo pede
      ↓
gate revalida
      ↓
bloqueia quando incompatível
```

## ToolBlockedException

Bloqueios são estruturados.

A exceção preserva informações como:

- Tool;
- reason code;
- detalhes auditáveis apropriados.

Não é necessário expor todos os detalhes internos ao usuário final.

## Idempotência de WRITE

WRITE repetida não pode ser resolvida com retry cego.

O runtime usa fingerprint de chamada normalizada para suportar proteção de idempotência.

Regra:

```text
mesma escrita
      ↓
replay idempotente quando já conhecida
      ↓
sem segundo side effect
```

## Cache

WRITE não usa cache genérico.

```text
READ  → cache/reuse
WRITE → idempotency/replay
```

Misturar esses mecanismos aumenta risco de duplicação ou semântica incorreta.

## Falha de WRITE

O Core não deve repetir automaticamente uma WRITE que falhou.

Uma falha pode ocorrer depois de o sistema externo ter produzido o side effect e antes de a aplicação receber confirmação confiável.

Esse cenário deve permanecer auditável e ser tratado de forma explícita.

## Result Projection

Uma Tool pode devolver mais dados do que a IA precisa.

Result Projection limita o retorno enviado ao modelo.

Remova da superfície de IA, quando não forem necessários:

- headers;
- tokens;
- payloads HTTP;
- identificadores internos;
- dados pessoais;
- metadados de infraestrutura;
- campos auxiliares de integração.

## Prompt injection

Tools e dados externos devem ser tratados como dados, não como autoridade automática para mudar regras de segurança.

Instruções vindas de resultado de Tool, banco ou conteúdo externo não substituem Policies, metadata e gate.

## Observabilidade

Observabilidade segue minimização de dados.

Por padrão, evite registrar integralmente:

- mensagens;
- state;
- argumentos de Tool;
- resultados de Tool;
- headers;
- tokens/segredos;
- payload bruto do provider.

Prefira identificadores, contadores e fingerprints quando forem suficientes.

## Result Projection versus logs

São superfícies diferentes.

```text
projeção para IA
        ≠
política de observabilidade
```

Um campo permitido à IA não precisa automaticamente ser gravado no log, e o contrário também não deve ser presumido.

## Segredos

Nunca coloque credenciais em:

- prompt;
- descrição de Tool;
- state enviado à IA;
- resultado de Tool;
- logs;
- mensagens de erro exibidas ao usuário.

Providers e infraestrutura devem receber segredos por configuração apropriada da aplicação.

## Plugins

Plugins são responsáveis por declarar corretamente o risco de suas Tools.

O Core não consegue deduzir semanticamente que uma operação chamada `Processar` cria uma cobrança, envia uma mensagem ou grava um registro.

Classifique operações pelo efeito real, não pelo nome.

## Checklist de uma Tool WRITE

Antes de publicar uma WRITE:

1. `operation=write`;
2. argumentos restritos;
3. Policy definida;
4. estágios permitidos definidos quando aplicável;
5. `requires_state` adequado;
6. `forbids_state` adequado;
7. confirmação quando necessária;
8. proteção de idempotência;
9. nenhuma reutilização de cache READ;
10. Result Projection mínima;
11. sem retry automático;
12. testes provam que bloqueios ocorrem antes do side effect.
