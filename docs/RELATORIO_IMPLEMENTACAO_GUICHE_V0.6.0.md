# Relatório de Implementação — Guichê v0.6.0

Data: 2026-10-14

## Objetivo da versão

A v0.6.0 consolida a base de qualidade automatizada do Guichê antes das fases de Developer Experience, exemplo clínico formal e release candidate.

O foco desta versão foi transformar os testes históricos e as validações manuais das versões anteriores em um quality gate reproduzível.

## Resultado final

- PHPUnit: **99 testes / 496 assertions**
- PHPStan: **level 8 / 0 erros**
- Coverage: **76.00% (4976/6547 statements)**
- Threshold mínimo: **70.00%**
- Acceptance legado v0.5.1: **PASS**
- `composer check`: **PASS**
- Scripts legados preservados: **33**
- Baseline PHPStan de erros: **não utilizado**
- `ignoreErrors` global: **não utilizado**

## Test Suite

A suíte PHPUnit passou a cobrir:

- Core e contratos;
- Plugin API;
- Safety Layer;
- Persistence e Observability;
- Conversation Runtime;
- Scheduler, leases, fencing e recovery;
- Pilot clínico.

O Pilot clínico possui testes de comportamento para:

- `ClinicalPlugin`;
- `ClinicalStateManager`;
- `DisponibilidadeConveniosTool`;
- `ConsultarProfissionalTool`;
- `ConsultarHorariosTool`;
- `ConsultarPacienteTool`;
- `CriarAgendamentoTool`;
- presenters clínicos;
- hard gate de WRITE;
- idempotência e result projection.

## Static Analysis

PHPStan foi integrado em **level 8**.

Foram corrigidos de forma explícita:

- value types de arrays;
- contratos de callables;
- tipos de propriedades e retornos;
- narrowing de valores nullable;
- guards redundantes;
- contratos de retorno de infraestrutura;
- match exhaustivo;
- tipos de flags de locking.

A análise terminou com **0 erros** sem baseline de supressão.

## Coverage

O primeiro levantamento registrou:

- **48.57%**
- **3180/6547 statements**

Após a migração dos cenários clínicos para PHPUnit:

- **76.00%**
- **4976/6547 statements**

O threshold definido para a v0.6 é **70%**.

O número foi atingido sem excluir o Pilot clínico e sem reduzir o threshold.

## Quality Gate

O comando:

```bash
composer check
```

valida a cadeia completa da versão:

1. PHPUnit;
2. PHPStan level 8;
3. coverage real com threshold;
4. acceptance legado v0.5.1.

## Compatibilidade com testes legados

Os 33 scripts históricos em `bin/test-*.php` foram preservados nesta release.

A remoção foi adiada porque parte deles ainda possui valor específico para cenários multiprocess, concorrência e recovery. A migração física poderá ser feita posteriormente após equivalência 1:1 formal.

## Auditorias

Documentação detalhada disponível em:

- `docs/audits/v0.6-fase-01-*`
- `docs/audits/v0.6-fase-02-*`
- `docs/audits/v0.6-fase-03-*`
- `docs/audits/v0.6-fase-04-*`
- `docs/audits/v0.6-fase-05-*`
- `docs/audits/v0.6-fase-06-static-analysis-coverage-quality-gate.md`
- `docs/audits/v0.6-fase-07-pre-release.md`

## Status

**v0.6.0 pronta para release local.**

Próxima versão do roadmap:

**v0.7 — Developer Experience**
