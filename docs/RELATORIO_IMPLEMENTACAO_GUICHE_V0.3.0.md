# Relatório de Implementação — Guichê v0.3.0

**Versão:** v0.3.0 — Plugin & Tool Contracts
**Data de fechamento técnico:** 13/10/2026
**Baseline:** v0.2.0 — Core Extraction
**Baseline Git observado durante a implementação:** `7260690`

---

## 1. Resumo executivo

A v0.3.0 transforma o Guichê de um Core já desacoplado do domínio clínico em uma plataforma extensível por plugins.

O objetivo central foi permitir que novos domínios conectem funcionalidades ao runtime sem editar o Core. A versão entrega contratos de plugin, registro de Tools com metadados, Presenter Registry, StateManager plugável e um `GuicheBuilder` capaz de compor o runtime real.

O piloto clínico também foi migrado para essa arquitetura por meio de `ClinicalPlugin`, preservando as regras e comportamentos existentes da v0.2.0.

O critério final de aceite foi validado com um plugin neutro, externo ao diretório `src/`, contendo:

- 1 Tool;
- 1 StateManager;
- 1 Presenter;
- Instruction Fragment;
- execução end-to-end pelo runtime;
- nenhuma alteração em `src/` durante a integração do novo plugin.

Com isso, a Definition of Done da v0.3.0 foi atendida.

---

## 2. Objetivo da versão

Permitir que qualquer domínio conecte capacidades ao Guichê sem editar o Core.

A v0.3.0 cobre composição e extensibilidade. O hard execution gate e o enforcement de segurança continuam deliberadamente reservados para a v0.4.0 — Safety Layer.

---

## 3. Entregas principais

### 3.1 Plugin API

Criado:

- `src/Contracts/PluginInterface.php`

Contrato:

```php
public function register(GuicheBuilder $builder): void;
```

O plugin recebe somente o Builder como ponto de integração.

### 3.2 Result Presenter Contract

Criado:

- `src/Contracts/ResultPresenterInterface.php`

Responsabilidade:

- transformar opcionalmente o resultado estruturado de uma Tool em uma apresentação determinística;
- manter apresentação separada da execução da Tool.

### 3.3 ToolRegistration

Criado:

- `src/Plugin/ToolRegistration.php`

Responsabilidades:

- encapsular uma `ToolInterface`;
- preservar o nome da Tool;
- registrar metadados declarativos;
- gerar definição compatível com a API já existente do `ToolRegistry`.

Metadados internos não são enviados ao provider de IA.

### 3.4 ToolRegistry evoluído

O `ToolRegistry` passou a trabalhar com `ToolRegistration`, preservando compatibilidade com o registro direto de `ToolInterface`.

Capacidades relevantes:

- `register()`;
- `get()`;
- `registration()`;
- `registrations()`;
- `metadata()`;
- `execute()`;
- `definitions()`;
- `names()`.

Conflitos de nome são rejeitados explicitamente.

### 3.5 PresenterRegistry

Criado:

- `src/PresenterRegistry.php`

Permite associar um Presenter a uma Tool pelo nome da Tool.

A ausência de Presenter permanece válida e retorna `null`.

### 3.6 GuicheBuilder

Criado/evoluído:

- `src/GuicheBuilder.php`

O Builder passou a compor o runtime real e concentra o registro de:

- Plugins;
- Tools;
- Presenters;
- StateManager;
- ToolPolicy existente;
- Policies declarativas;
- Instruction Fragments;
- Completion Guards;
- Result Projections;
- ContextStore;
- AiProvider;
- RuntimeContext;
- Agent Context;
- reusable tools;
- limites de histórico;
- projeções de estado/resultados;
- limite de rodadas de Tools.

O método `build()` gera uma instância real de `Guiche`.

### 3.7 PresentedTool

Criado:

- `src/Plugin/PresentedTool.php`

Decorator genérico usado pelo Builder para aplicar um Presenter a uma Tool sem fazer `Guiche.php` conhecer `PresenterRegistry` ou a Plugin API.

Se a Tool já produzir `_guiche.presentation_message`, o comportamento existente tem precedência.

---

## 4. Atomicidade e conflitos de plugins

O `GuicheBuilder` rejeita conflitos explícitos e realiza rollback do registro quando um plugin falha durante sua composição.

Foram validados:

- plugin duplicado;
- Tool duplicada;
- conflito de StateManager;
- falha parcial de plugin;
- rollback atômico;
- múltiplos plugins distintos.

---

## 5. Fronteira Core × Plugin

A v0.3.0 preserva a separação arquitetural definida na v0.2.0.

O runtime `Guiche` continua sem conhecer:

- `PluginInterface`;
- `ClinicalPlugin`;
- `PresenterRegistry`;
- qualquer domínio específico.

A composição acontece no `GuicheBuilder`.

---

## 6. Migração do piloto clínico

Criado:

- `src/Pilot/Clinical/ClinicalPlugin.php`

O plugin clínico registra:

### Tools

- `DisponibilidadeConvenios`;
- `ConsultarProfissional`;
- `ConsultarHorarios`;
- `ConsultarPaciente`;
- `CriarAgendamento`.

### State

- `ClinicalStateManager`.

### Presenters

- `DisponibilidadeConveniosPresenter`;
- `ConsultarHorariosPresenter`.

Os Presenters clínicos passaram a implementar `ResultPresenterInterface`.

### Tool Policy

A política clínica continua usando explicitamente:

```text
state.etapa
```

O default genérico do Core continua:

```text
state.stage
```

### Demais capacidades

O plugin também registra:

- prompt clínico como Instruction Fragment;
- Completion Guards;
- Result Projections;
- metadados de Tool, incluindo leitura/escrita.

---

## 7. Bootstrap clínico

O `bin/chat.php` deixou de montar manualmente as capacidades clínicas.

Antes, o bootstrap instanciava diretamente Tools, StateManager, ToolRegistry e ToolPolicy.

Na v0.3.0, a composição passa pelo padrão:

```php
(new GuicheBuilder())
    ->registerPlugin(new ClinicalPlugin(...))
    ->build();
```

Isso transforma o piloto em consumidor da Plugin API.

---

## 8. Plugin neutro de referência

Criado fora de `src/`:

```text
examples/neutral-plugin/
├── composer.json
├── README.md
└── src/
    ├── NeutralPingTool.php
    ├── NeutralStateManager.php
    ├── NeutralPresenter.php
    └── NeutralPlugin.php
```

O exemplo não contém semântica clínica.

Ele demonstra como um domínio externo pode usar apenas os contratos públicos do Guichê.

---

## 9. Aceite end-to-end do plugin externo

O teste final validou o fluxo completo:

```text
Plugin externo
    ↓
GuicheBuilder
    ↓
ToolRegistry / PresenterRegistry / StateManager
    ↓
Guiche::handle()
    ↓
AiProvider fake solicita NeutralPing
    ↓
NeutralPingTool executa
    ↓
NeutralPresenter gera apresentação
    ↓
NeutralStateManager atualiza estado
    ↓
runtime conclui a conversa
```

Também foi validado que o Instruction Fragment do plugin chega ao provider.

---

## 10. Definition of Done da v0.3.0

Critério central:

> Um plugin independente da clínica deve registrar uma Tool, um StateManager simples e opcionalmente um Presenter, sem exigir alteração no Core.

Resultado:

- [x] plugin independente da clínica;
- [x] plugin fora de `src/`;
- [x] 1 Tool;
- [x] 1 StateManager;
- [x] 1 Presenter;
- [x] metadados de Tool;
- [x] Instruction Fragment;
- [x] runtime construído via `GuicheBuilder`;
- [x] Tool executada end-to-end;
- [x] Presenter aplicado;
- [x] StateManager aplicado;
- [x] nenhum arquivo de `src/` alterado durante a integração do plugin neutro;
- [x] regressões da v0.2.0 preservadas;
- [x] `git diff --check` limpo;
- [x] hard execution gate não implementado antecipadamente.

**Definition of Done atendida.**

---

## 11. Testes da v0.3.0

Testes principais:

- `bin/test-plugin-contracts-v03.php`;
- `bin/test-plugin-registries-v03.php`;
- `bin/test-plugin-api-v03.php`;
- `bin/test-guiche-builder-v03.php`;
- `bin/test-clinical-plugin-v03.php`;
- `bin/test-neutral-plugin-v03.php`;
- `bin/test-v03-acceptance.php`.

Áreas cobertas:

- contratos;
- registries;
- metadados;
- conflitos;
- atomicidade;
- múltiplos plugins;
- Builder;
- Presenter;
- StateManager;
- ToolPolicy;
- Completion Guards;
- Result Projections;
- bootstrap clínico;
- plugin externo;
- execução end-to-end;
- fronteira v0.3/v0.4.

---

## 12. Regressões da v0.2.0

A v0.3.0 manteve os testes da Core Extraction.

Foram revalidados:

- contrato genérico de estado;
- configuração Core × Piloto;
- ToolPolicy genérica;
- fronteira do `ClinicalStateManager`;
- fronteira das Tools/Presenters clínicos;
- aceite completo da Core Extraction.

Isso preserva retrocompatibilidade com a arquitetura estabelecida na v0.2.0.

---

## 13. Compatibilidade preservada

A v0.3.0 mantém:

- construção manual de `Guiche` da v0.2.0;
- `ToolInterface`;
- `StateManagerInterface`;
- comportamento existente de `Guiche.php`;
- ToolPolicy genérica;
- regras clínicas;
- Tools clínicas;
- comportamento determinístico dos Presenters existentes;
- configurações clínicas isoladas no piloto.

---

## 14. Limite deliberado da versão

A v0.3.0 registra e compõe capacidades.

Ela **não** implementa o hard execution gate.

O enforcement de autorização de execução de Tools pertence à próxima fase:

```text
v0.4.0 — Safety Layer
```

Essa separação foi testada explicitamente durante o aceite.

---

## 15. Arquitetura resultante

```text
Aplicação / domínio
        │
        ▼
  PluginInterface
        │
        ▼
   GuicheBuilder
   ├── ToolRegistry
   ├── PresenterRegistry
   ├── StateManagerInterface
   ├── ToolPolicy
   ├── Policies
   ├── Instruction Fragments
   ├── Completion Guards
   └── Result Projections
        │
        ▼
      Guiche
        │
        ▼
     Runtime
```

O Core permanece neutro; os domínios entram por plugins.

---

## 16. Evolução arquitetural

```text
v0.1.0-pilot
Piloto clínico com Core acoplado

        ↓

v0.2.0
Core Extraction
Core genérico + piloto isolado

        ↓

v0.3.0
Plugin & Tool Contracts
Core genérico + Plugin API + Builder + piloto como plugin

        ↓

v0.4.0
Safety Layer
Hard execution gate + enforcement
```

---

## 17. Próxima fase — v0.4.0 Safety Layer

Prioridade central da próxima versão:

- hard execution gate;
- enforcement da ToolPolicy no momento da execução;
- classificação leitura × escrita;
- autorização antes de executar Tools;
- proteção adicional para Tools com side effects;
- testes de bypass;
- falhas explícitas e auditáveis;
- preservação da composição por plugins criada na v0.3.0.

A v0.4.0 deve consumir os metadados e contratos definidos nesta versão, e não voltar a acoplar regras de domínio ao Core.

---

## 18. Conclusão

A v0.3.0 cumpre seu objetivo arquitetural.

O Guichê deixa de depender de alterações no Core para receber um novo domínio. O `GuicheBuilder` passa a ser o ponto de composição, `PluginInterface` passa a ser o ponto de extensão, e o piloto clínico torna-se uma implementação concreta dessa API.

O plugin neutro externo comprova que a arquitetura não funciona apenas para o domínio clínico: uma integração nova pode ser adicionada, executada end-to-end e manter estado/apresentação sem modificar `src/`.

**Status final: v0.3.0 — Plugin & Tool Contracts concluída e apta para release.**
