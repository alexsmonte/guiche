# Guichê — Clínica Piloto

Exemplo clínico avançado extraído do piloto histórico do Guichê.

## Estado na v0.8 / Fase 02

Nesta fase o domínio clínico foi retirado de `src/` e passou a viver integralmente em `examples/clinica-piloto/`.
O comportamento clínico existente foi preservado; esta fase **não** introduz ainda Fake Mode, adapters fake/real,
`.env.example`, sanitização final da configuração nem a nova revalidação de slot. Esses itens pertencem às fases
seguintes da v0.8.

### Estrutura

- `src/ClinicPlugin.php`
- `src/ClinicStateManager.php`
- `src/Tools/`
- `src/Presentation/`
- `config/clinic.php`
- `config/prompt.php`
- `bootstrap.php`
- `chat.php`

O exemplo depende apenas dos contratos públicos do Guichê. O Core não depende deste exemplo.

## v0.8 — Fase 03: Configuração + Adapters + Fake Mode

O exemplo clínico possui dois modos explícitos:

- `fake` — padrão seguro, determinístico e offline;
- `real` — integra banco, API HTTP e OpenAI somente quando configurados.

O Fake Mode não exige banco, token da API de agendamento, chave da OpenAI nem internet.

```bash
php8.4 examples/clinica-piloto/fake-smoke.php
```

As implementações fake usam os mesmos contratos genéricos do Core:
`SqlExecutorInterface`, `HttpClientInterface` e `AiProviderInterface`.

A Fase 03 não implementa ainda tratamento novo de agendamento existente nem
revalidação do slot imediatamente antes do WRITE. Esses itens pertencem à Fase 04.

## v0.8 — Fase 04: Clinical Flow + Write Safety

O fluxo clínico adiciona duas proteções determinísticas:

1. **Agendamento já existente**: quando `ConsultarPaciente` devolve um
   agendamento válido, o `ClinicStateManager` move a conversa diretamente para
   `agendado` e popula `state.agendamento_id`. O `forbids_state` já existente
   na Safety Layer bloqueia qualquer nova criação.

2. **Revalidação final do slot**: no fluxo oficial do `ClinicPlugin`, a
   `CriarAgendamentoTool` consulta novamente `ConsultarHorarios` com
   `pagina=0`, para a mesma data e profissional, imediatamente antes do POST.
   Se o horário sumiu ou a revalidação falhou, o comportamento é **fail closed**
   e nenhum POST é realizado.

A idempotência continua sendo responsabilidade da Safety Layer genérica do
Core. O exemplo clínico não cria um segundo mecanismo de idempotência.

<!-- GUICHE_V08_PHASE06_START -->
## Instalação e execução

O exemplo inicia em **Fake Mode** e pode ser validado sem serviços externos.

```bash
cp examples/clinica-piloto/.env.example examples/clinica-piloto/.env
php examples/clinica-piloto/fake-smoke.php
bash bin/test-v08-clinical-acceptance.sh
```

O guia completo de Fake Mode, Real Mode, segurança de escrita e isolamento
arquitetural está em [`INSTALL.md`](INSTALL.md).

O namespace `GuicheExample\ClinicaPiloto` pertence ao ambiente de
desenvolvimento/exemplo e **não compõe o autoload público da biblioteca**.
<!-- GUICHE_V08_PHASE06_END -->
