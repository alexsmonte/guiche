# Relatório de Implementação — Guichê v0.8.0

## Clinical Example

Data do release: 2026-10-14T12:36:32-04:00

## 1. Baseline

- versão anterior: `v0.7.0`;
- commit baseline: `f56e88fda1b8074edc9349956a248ee1d7453386`;
- branch de desenvolvimento: `feature/v0.8-clinical-example`;
- pacote: `asmpkg/guiche`;
- PHP validado: `8.4.24`.

## 2. Objetivo concluído

A v0.8.0 transforma o antigo piloto clínico embutido em um **exemplo avançado
separado do Core**, mantendo o domínio fora da API pública da biblioteca.

O Core público permanece em:

`Asmpkg\Guiche\ -> src/`

O exemplo clínico permanece em:

`GuicheExample\ClinicaPiloto\ -> examples/clinica-piloto/src/`

e pertence somente ao `autoload-dev`.

## 3. Entregas principais

- extração do domínio clínico para `examples/clinica-piloto/`;
- `ClinicPlugin`;
- `ClinicStateManager`;
- cinco Tools clínicas;
- Presenters;
- configuração própria;
- `.env.example` sanitizado;
- Fake Mode totalmente offline;
- adapters Fake/Real selecionáveis;
- fixture sintética;
- tratamento de agendamento existente;
- revalidação final de slot antes de WRITE;
- fail closed em perda/falha de revalidação;
- preservação da idempotência e Result Projection do Core;
- PHPUnit clínico permanente;
- acceptance clínico consolidado;
- documentação de instalação e isolamento.

## 4. Safety

A escrita clínica continua protegida pela Safety Layer genérica:

- Tool Policy;
- allowed stages;
- requires/forbids state;
- confirmação;
- hard execution gate;
- idempotência;
- Result Projection.

A v0.8 adiciona uma responsabilidade específica do domínio:

- revalidar o slot imediatamente antes do side effect HTTP;
- zero POST quando o slot não está mais disponível;
- zero POST quando a revalidação falha tecnicamente.

## 5. Testes e qualidade

Resultado pré-release:

- PHPUnit total: 112 testes;
- assertions: 540;
- PHPStan: sem erros;
- coverage de linhas/statements: `75.82%`;
- threshold mínimo: 70%;
- Fake Mode: offline;
- installation smoke: aprovado;
- acceptance v0.8: aprovado;
- regressões v0.2–v0.5.1: aprovadas.

## 6. Imutabilidade comprovada

Hashes aprovados:

- Core `src/`: `974969b1f9e8749420a06ef16b7c5f07bbeb9d2f3d3ea74ec105390322638a6b`;
- Clinical Example `src/`: `5600e4e8614dc9fe2f8554dee6bf884cdbd366cc1a3dd68a6ca2958f0aa71410`.

As Fases 05 e 06 não alteraram esses códigos de produção.

## 7. Segurança de distribuição

O exemplo distribuído:

- não contém endpoint operacional real;
- não contém credenciais;
- não contém nomes da clínica/pessoas reais auditados;
- inicia em Fake Mode;
- protege `.env` por gitignore.

## 8. Documentação

- `examples/clinica-piloto/README.md`;
- `examples/clinica-piloto/INSTALL.md`;
- `v0.8-clinical-example.md`;
- auditorias em `docs/audits/v0.8-*.md`.

## 9. Licença

Status: **MIT**.

A release não inventa uma licença. Se continuar pendente, o tema deve ser
resolvido explicitamente antes de uma distribuição pública que exija declaração
formal de licença.

## 10. Acceptance oficial

`bin/test-v08-acceptance.sh`

Esse runner consolida a extração estrutural e o acceptance clínico das Fases
03–06.

## 11. Próximo marco

`v0.9.0 — Release Candidate`

A v0.9 deve tratar a biblioteca como candidata de release estável, concentrando
compatibilidade, packaging, documentação pública, revisão final de API e
pendências de publicação — sem reintroduzir domínio clínico no Core.
