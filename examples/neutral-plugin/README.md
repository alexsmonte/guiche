# Neutral Plugin — exemplo externo do Guichê v0.3

Este diretório é propositalmente externo a `src/`.

Objetivo: provar que um domínio novo pode integrar capacidades ao Guichê sem alterar o Core.

O exemplo registra:

- `NeutralPingTool` via `ToolInterface`;
- `NeutralStateManager` via `StateManagerInterface`;
- `NeutralPresenter` via `ResultPresenterInterface`;
- `NeutralPlugin` via `PluginInterface`;
- um Instruction Fragment.

No teste da v0.3, os arquivos são carregados manualmente porque este pacote-exemplo está dentro do mesmo repositório apenas para validação. Em um pacote Composer independente, seu próprio autoload PSR-4 faria esse carregamento.
