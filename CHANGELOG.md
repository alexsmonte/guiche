# Changelog

Todas as mudanças relevantes deste projeto serão registradas neste arquivo.

O formato segue a ideia de **Keep a Changelog** e o versionamento do projeto segue **Semantic Versioning**.

## [1.4.0] - 2026-10-16

### Added

- `PromptResolverInterface`, `PromptContext` e `withPromptResolver()`;
- Callback/Static/File/Fallback PromptResolver;
- resolução dinâmica uma vez por turno;
- `guiche.php` Composition Root criado pelo `init`;
- exemplos de fontes e integração com frameworks.

### Security

- PromptContext deriva de contexto confiável;
- mensagem/state/Tool args não selecionam tenant;
- rebinding conflitante continua fail-closed;
- fallback não mascara exceptions;
- concorrência sem cross-tenant prompt leak.

### Compatibility

- SemVer minor aditiva;
- `withInstructions()` permanece compatível;
- Public: 38; Experimental: 11; Internal: 14;
- SHA-256: `00af77a63fd4273fac60231169a9fc043a2429d4cc4b610cfcdcea1508de4f00`.

### Validation

- 253 testes / 1507 assertions;
- PHPStan sem erros;
- coverage 76,13% (threshold 70%);
- clean install Composer externo validado na Fase 06.

## [1.3.0] - 2026-10-16

### Added

- `Guiche::bindIdentity()` como API pública recomendada para vincular
  identidade externa confiável a uma sessão;
- pipeline direto `host externo → bindIdentity() → TenantResolver →
  ToolExecutionContext → Tool`;
- adapter de referência para Chatwoot em `examples/chatwoot/`;
- exemplo provider-agnostic em `examples/external-host/`;
- documentação de migração e integração para contexto externo confiável.

### Security

- `execution_context.identity` permanece write-once e imutável por sessão;
- rebinding idêntico é idempotente;
- tentativa de troca de identidade falha fechado;
- resolução de tenant recebe somente identity confiável;
- dados extras retornados pelo resolver não são promovidos ao
  `execution_context`;
- valores não serializáveis e floats não finitos são rejeitados antes de
  persistência/resolução;
- raw `identity`, `tenant_id` e `execution_context` são removidos da
  observabilidade;
- contexto confiável não é promovido ao prompt da IA;
- falhas do resolver são auditáveis sem persistir sua mensagem bruta;
- `bindIdentity()` e `handle()` da mesma sessão compartilham lock exclusivo;
- concorrência entre sessões diferentes permanece independente.

### External integrations

- Chatwoot utiliza `account_id`, `inbox_id` e `conversation_id` como identity
  de referência;
- a session ID do exemplo Chatwoot é opaca e estável por conversa;
- mensagens Chatwoot outgoing/private são rejeitadas no adapter de
  referência;
- autenticação/verificação de requests externos permanece responsabilidade
  do host, antes de `bindIdentity()`.

### Compatibility

- mudança SemVer minor e aditiva;
- `ToolInterface` permanece inalterada;
- Tools legadas continuam sem exigir contexto multi-tenant;
- `ContextStoreInterface` permanece inalterada;
- Public: 32 símbolos;
- Experimental: 11 símbolos;
- Internal: 14 símbolos;
- SHA-256 da API pública:
  `fa76a663dcb6afdd10dee53913a50fc6686ef88ce4f9618411967bc7168b4c52`.

### Validation

- 222 testes na suíte completa da Fase 05;
- regressão focada v1.3: 75 testes / 389 assertions;
- PHPStan sem erros;
- cobertura de linhas/statements: 75,69%;
- threshold mínimo: 70%;
- concorrência multiprocesso validada para binding idempotente, conflito,
  bind/handle e isolamento entre sessões;
- API pública v1 e freeze experimental preservados.

## [1.2.0] - 2026-10-15

### Added

- CLI oficial distribuída pelo Composer como `vendor/bin/guiche`;
- comandos `help`, `init`, `doctor`, `chat` e `usage`;
- bootstrap automático de `.env`, `guiche.prompt.md` e `storage/conversations`;
- chat interativo e mensagem única sem exigir `chat.php` manual;
- suporte a sessão explícita com `--session`;
- `PromptResolver` interno com prompt por arquivo, variável de ambiente ou fallback do pacote;
- prompt padrão distribuído em `resources/guiche.prompt.md`.

### Developer Experience

- `init` é idempotente e preserva `.env` e prompt existentes;
- `doctor` valida o ambiente sem realizar chamada de rede ao provider;
- `/context`, `/usage`, `/tools`, `/reset` e `/sair` permanecem locais no chat;
- contexto e usage da CLI são persistidos no projeto consumidor, fora de `vendor/`;
- Quick Start passa a funcionar por Composer + CLI sem copiar o exemplo básico.

### Prompt resolution

Prioridade:

1. `GUICHE_PROMPT_FILE`;
2. `GUICHE_PROMPT`;
3. `guiche.prompt.md`;
4. prompt padrão do pacote.

### Compatibility

- API pública v1 permanece inalterada e compatível;
- Public: 32 símbolos;
- Experimental: 11 símbolos;
- Internal: 14 símbolos;
- SHA-256 da API pública:
  `f4328ccc4724f0e097e0d9369329a6bb3bbfe27bca5cddc8d452f43a63130c41`;
- `Application` e `PromptResolver` da CLI são `internal`.

### Validation

- 183 testes / 807 assertions;
- PHPStan sem erros;
- cobertura de linhas/statements: 75,18%;
- threshold mínimo: 70%;
- API pública v1 e freeze experimental preservados;
- instalação Composer externa validada com cópia física, sem symlink;
- `vendor/bin/guiche`, `init`, `doctor`, `chat`, prompt customizado,
  persistência e `usage` validados em projeto consumidor vazio.


## [1.1.1] - 2026-10-15

### Fixed

- carregamento automático de arquivos `.env` nos entrypoints oficiais;
- configuração pública padronizada em `AI_API_KEY` e `AI_MODEL`;
- compatibilidade temporária com `OPENAI_API_KEY` e `OPENAI_MODEL`;
- comandos locais do exemplo básico tratados sem envio desnecessário ao provider de IA;
- bootstrap do exemplo clínico simplificado para usar o carregador de ambiente compartilhado.

### Added

- dependência runtime `vlucas/phpdotenv`;
- `.env.example` próprio para o exemplo básico;
- regressões automatizadas do Developer Experience para carregamento de ambiente e comandos locais.

### Documentation

- Quick Start e README do exemplo básico atualizados para configuração via `.env`;
- documentação principal alinhada aos nomes genéricos `AI_API_KEY` e `AI_MODEL`.

### Validation

- 176 testes / 764 assertions;
- PHPStan sem erros;
- cobertura de linhas/statements: 76,65%;
- `composer validate --strict`, `composer audit` e `composer check` aprovados.


## [1.1.0] - 2026-10-15

### Added

- `ContextAwareToolInterface` para Tools que dependem de contexto confiável de execução;
- `ToolExecutionContext` imutável com `sessionId`, identity, tenant e valores contextuais;
- `ExecutionContextSeeder` para vincular identity à sessão;
- `TenantResolverInterface` e pipeline de resolução por `TenantResolutionService`;
- resolvers SQL, HTTP e callback/repository;
- suporte do `GuicheBuilder` a `withTenantResolver()`;
- dispatch contextual no `Guiche` sem alteração da assinatura de `ToolInterface`;
- decorator interno `ContextAwarePresentedTool` para preservar Presenters em Tools contextuais;
- exemplo clínico multi-tenant sem `empresa_id` controlado pela IA;
- acceptance adversarial de isolamento e concorrência entre sessões/tenants.

### Security

- `execution_context.identity` é write-once por sessão;
- `tenant_id` resolvido é write-once por sessão;
- ContextAware Tool sem sessão falha fechada antes da execução;
- tenant ausente ou resolução inválida bloqueia antes de side effects;
- argumentos de tenant forjados não substituem `ToolExecutionContext::tenantId()`;
- Tools clínicas rejeitam `empresa_id` extra no Hard Gate e derivam a empresa do contexto confiável;
- falhas de TenantResolver não vazam mensagens brutas no envelope de bloqueio;
- Gate 1, Gate 2, READ cache e WRITE replay/idempotência permanecem preservados.

### Compatibility

- `ToolInterface` permanece com a assinatura v1 original;
- Tools legadas continuam compatíveis e não exigem `sessionId` nem `TenantResolver`;
- `ContextStoreInterface` permanece inalterada;
- mudanças públicas da v1.1 são aditivas e compatíveis com SemVer;
- API pública congelada: 32 símbolos;
- API experimental: 11 símbolos;
- API interna: 12 símbolos;
- SHA-256 da API pública: `f4328ccc4724f0e097e0d9369329a6bb3bbfe27bca5cddc8d452f43a63130c41`.

### Validation

- 173 testes / 736 assertions no fechamento da Fase 06;
- PHPStan sem erros;
- cobertura de linhas/statements: 76,65%;
- acceptance concorrente com oito workers;
- `composer check`, API freeze e Safety Layer aprovados.


## [1.0.2] - 2026-10-15

### Fixed

- permite parâmetros opcionais de Tools no provider OpenAI sem forçar strict mode incompatível;
- ajusta o exemplo básico para consultas gerais sem filtro e consultas específicas com termo normalizado;
- adiciona regressão para Tool com argumento opcional.


## [1.0.1] - 2026-10-14

### Fixed

- Corrige o caminho do `config/guiche.php` no entrypoint `examples/clinica-piloto/chat.php`.
- Combina corretamente a configuração do Core com a configuração clínica antes de criar os adapters.
- Exibe `FAKE` ou `REAL` no banner do CLI de acordo com o adapter efetivamente selecionado.
- Adiciona teste de regressão que executa o chat clínico em Fake Mode e valida sua inicialização.

## [1.0.0] - 2026-10-14

### Added

- primeira linha estável da API pública do Guichê;
- freeze explícito dos 11 símbolos `experimental` do Conversation Runtime;
- checker `check-experimental-api` integrado ao quality gate;
- documentação estável v1, migration notes e release notes;
- validação de consumidor Composer externo da release v0.9.0.

### Stability

- 24 símbolos `public` permanecem no compromisso BC da linha v1.x;
- 11 símbolos `experimental` permanecem fora do compromisso BC até promoção explícita;
- 11 símbolos `internal` continuam livres para evolução interna;
- SHA-256 da API pública permanece `39e417149aaee5ac9008de4bb3e477c579826f86ea9902b8755965fc1482368d`.

### Compatibility

- PHP 8.4 e PHP 8.5 são oficialmente suportados;
- o Core permanece byte-for-byte sem alterações em `src/` desde a v0.9.0 durante o fechamento documental da v1.

## [0.9.0] - 2026-10-14

### Added

- classificação formal da superfície de API em Public, Experimental e Internal;
- baseline de compatibilidade da API pública e checker de BC integrado ao `composer check`;
- política pública de estabilidade e SemVer;
- suporte/documentação de distribuição Composer para a RC;
- guia completo de consumidor externo;
- `SECURITY.md` com política de reporte e invariantes de segurança;
- workflow GitHub Actions com matriz PHP 8.4/8.5;
- `composer audit` e smoke de distribuição na CI;
- acceptance externo com Tool, Plugin, ContextStore e AiProvider customizados;
- artefatos e manifestos de release reproduzíveis.

### Changed

- documentação pública reorganizada para apresentar o Guichê como Core genérico;
- instalação/aceite externo passa a validar uma dependência Composer real, sem symlink;
- pacote distribuído exclui arquivos de desenvolvimento, auditorias internas, storage e arquivos de ambiente;
- o clinical scheduling permanece exemplo de domínio e não faz parte da API pública do Core.

### Security

- hard execution gate continua obrigatório imediatamente antes de `ToolInterface::execute()`;
- Tool call gerada pelo provider não equivale a autorização de side effect;
- WRITE permanece sem retry/cache genérico;
- replay idempotente de WRITE continua explicitamente persistido;
- result projection limita dados enviados ao provider;
- observabilidade continua sem persistir payload bruto sensível;
- CI opera com `contents: read`, sem secrets e sem `pull_request_target`;
- Actions externas são fixadas em SHAs completos;
- revisão local não encontrou `.env`, chave privada ou padrão de segredo de alta confiança versionado.

### Compatibility

- constraint do pacote: PHP `^8.4`;
- matriz validada: PHP 8.4 e PHP 8.5;
- ambos os runtimes passaram na CI oficial antes da publicação.

### API stability

- Public: 24 símbolos;
- Experimental: 11 símbolos;
- Internal: 11 símbolos;
- SHA-256 da API pública congelada: `39e417149aaee5ac9008de4bb3e477c579826f86ea9902b8755965fc1482368d`.
