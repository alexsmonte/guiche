# Relatório de Implementação — Guichê v0.4.0

## Status

**Safety Layer implementada e validada localmente.**

- Branch: `feature/v0.4-safety-layer`
- Baseline de desenvolvimento: `17dc2720e442bbcd5492d070efef17a3cc84ba81`
- Data de aceite: 2026-10-13
- Tag alvo: `v0.4.0`

> Este relatório é gerado antes do commit/tag final. O fechamento Git deve ocorrer somente após revisão do diff e confirmação do acceptance.

## Objetivo da versão

A v0.4.0 transforma as políticas declarativas das versões anteriores em controles obrigatórios de execução.

A proteção ocorre em dois níveis:

1. filtragem das Tools expostas à IA;
2. validação obrigatória imediatamente antes da execução real.

## Entregas

### Safety contracts

Foram introduzidos:

- `ToolBlockedException`;
- `ToolSafetyMetadata`;
- `ToolArgumentValidator`;
- `ToolExecutionGate`.

### Hard Execution Gate

O runtime passou a validar Tools em:

- `Guiche::executeTool()`;
- loop de `tool_calls` de `Guiche::handle()`.

Chamadas forged ou stale são recusadas antes de side effects e geram evento `tool_blocked`.

### Preconditions

Metadados suportados:

- `operation`;
- `allowed_stages`;
- `stage_path`;
- `requires_state`;
- `forbids_state`;
- `confirmation`.

### WRITE safety

Foi implementado fingerprint com:

```text
session + tool + argumentos normalizados
```

Garantias:

- WRITE direta exige sessão;
- WRITE não usa cache genérico;
- duplicate WRITE bem-sucedida usa replay idempotente;
- replay não produz novo side effect;
- WRITE com falha não recebe retry automático do Core;
- READ reutilizável mantém cache existente.

### Result Projection

Foi implementado:

- `include_paths` como whitelist;
- `exclude_paths` como corte final;
- compatibilidade quando não há whitelist;
- resultado completo preservado internamente;
- projeção mínima enviada à IA;
- envelope `tool_blocked` sem detalhes internos.

### Integração clínica

O `ClinicalPlugin` passou a consumir `tool_safety` declarativo da configuração.

`CriarAgendamento` exige:

- etapa `pronto_para_agendar`;
- confirmação explícita;
- paciente validado;
- paciente identificado;
- profissional escolhido;
- data definida;
- horário definido;
- ausência de `agendamento_id` anterior.

A Tool WRITE também possui whitelist específica de resultado para a IA.

## Reason codes implementados

- `tool_not_registered`
- `operation_invalid`
- `policy_denied`
- `stage_not_allowed`
- `required_state_missing`
- `required_state_mismatch`
- `forbidden_state_present`
- `forbidden_state_match`
- `confirmation_required`
- `invalid_arguments`
- `write_session_required`

## Eventos relevantes

- `tool_call`
- `tool_blocked`
- `tool_result`
- `tool_cache_hit`
- `tool_write_replay`

## Testes da v0.4

- `bin/test-safety-contracts-v04.php`
- `bin/test-safety-gate-v04.php`
- `bin/test-hard-execution-gate-v04.php`
- `bin/test-write-safety-v04.php`
- `bin/test-result-projection-v04.php`
- `bin/test-clinical-safety-v04.php`
- `bin/test-v04-acceptance.php`

## Regressões

Foram executadas regressões determinísticas das versões:

- v0.2 — Core Extraction;
- v0.3 — Plugin & Tool Contracts;
- v0.4 — Safety Layer.

Testes que dependem de banco/API reais não fazem parte deste acceptance determinístico.

## Definition of Done

- [x] ToolPolicy aplicada no runtime
- [x] bypass de `executeTool()` fechado
- [x] bypass do loop de `tool_calls` fechado
- [x] stale Tool call bloqueada
- [x] forged Tool call bloqueada
- [x] argumentos validados antes da Tool
- [x] READ/WRITE diferenciados
- [x] duplicate WRITE protegida
- [x] WRITE sem cache genérico
- [x] WRITE sem retry automático do Core
- [x] fingerprint inclui sessão
- [x] Result Projection com whitelist
- [x] resultado completo preservado internamente
- [x] integração clínica com preconditions reais
- [x] regressões v0.2/v0.3
- [x] acceptance v0.4
- [x] `git diff --check`
- [ ] commit final
- [ ] tag `v0.4.0`
- [ ] push da branch/tag

## Próximo marco

`v0.5.0 — Persistence & Observability`.
