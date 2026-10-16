# API Stability & SemVer

Este documento define a política oficial de estabilidade da API do Guichê para a linha v1.x.

## Regra principal

- `public`: faz parte do contrato estável da v1.x. Remoção, rename ou alteração incompatível exige major version.
- `experimental`: disponível para uso avançado, mas ainda não integra o compromisso de backward compatibility da v1.x até promoção explícita para `public`.
- `internal`: detalhe de implementação. Pode mudar sem aviso e não deve ser usado por consumidores.

O mapa canônico está em `api/stability-map.php`. O snapshot estrutural congelado está em `api/baseline-v1.json`.

Hash SHA-256 da API pública congelada na v1.4.0: `00af77a63fd4273fac60231169a9fc043a2429d4cc4b610cfcdcea1508de4f00`.

## Política de SemVer

1. Na linha v1.x, mudanças incompatíveis em símbolos `public` são reservadas para v2.0.0.
2. Adições públicas compatíveis podem entrar em minor releases, mas exigem atualização explícita do baseline e revisão de SemVer.
3. Bug fixes sem mudança de contrato público pertencem a patch releases.
4. Símbolos `experimental` e `internal` não entram no cálculo automático de BC da API estável.
5. Todo novo símbolo em `src/` deve ser classificado antes de o quality gate passar.

## Gate de backward compatibility

Execute:

```bash
php8.4 tools/check-api-bc.php
```

O gate falha quando um símbolo público é removido, quando sua estrutura/assinatura muda, quando surge novo símbolo público sem revisão do freeze ou quando aparece um símbolo de `src/` sem classificação.

A atualização do baseline é deliberadamente protegida e só pode ser feita com:

```bash
GUICHE_ALLOW_API_BASELINE_UPDATE=1 php8.4 tools/api-snapshot.php --write-baseline
```

> Atualizar o baseline não torna uma mudança automaticamente compatível. A revisão SemVer continua obrigatória.

## API pública estável

| Símbolo | Tipo | Motivo |
|---|---|---|
| `Asmpkg\Guiche\ContextStore` | `class` | implementação padrão de ContextStore |
| `Asmpkg\Guiche\Contracts\AiProviderInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\ClockInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\ContextStoreInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\ConversationStoreInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\HttpClientInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\PluginInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\ResultPresenterInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\ScheduledEventStoreInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\SqlExecutorInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\StateManagerInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Contracts\ToolInterface` | `interface` | contrato público de extensão |
| `Asmpkg\Guiche\Conversation\ScheduledEvent` | `class` | tipo exposto transitivamente por API pública |
| `Asmpkg\Guiche\Guiche` | `class` | entrypoint principal |
| `Asmpkg\Guiche\GuicheBuilder` | `class` | builder público |
| `Asmpkg\Guiche\Observability\JsonlRuntimeEventSink` | `class` | sink público JSONL |
| `Asmpkg\Guiche\Observability\NullRuntimeEventSink` | `class` | sink público padrão |
| `Asmpkg\Guiche\Plugin\ToolRegistration` | `class` | tipo exposto transitivamente por API pública |
| `Asmpkg\Guiche\PresenterRegistry` | `class` | tipo exposto transitivamente por API pública |
| `Asmpkg\\Guiche\\Contracts\\PromptResolverInterface` | `interface` | resolução dinâmica de prompt |
| `Asmpkg\\Guiche\\Prompt\\PromptContext` | `class` | contexto imutável do resolver |
| `Asmpkg\\Guiche\\Prompt\\CallbackPromptResolver` | `class` | adapter Closure/repository/API |
| `Asmpkg\\Guiche\\Prompt\\FallbackPromptResolver` | `class` | composição explícita fail-closed |
| `Asmpkg\\Guiche\\Prompt\\FilePromptResolver` | `class` | prompt em arquivo |
| `Asmpkg\\Guiche\\Prompt\\StaticPromptResolver` | `class` | prompt estático |
| `Asmpkg\Guiche\RuntimeContext` | `class` | tipo exposto transitivamente por API pública |
| `Asmpkg\Guiche\Safety\ToolBlockedException` | `class` | exceção observável pelo consumidor |
| `Asmpkg\Guiche\ToolPolicy` | `class` | política pública configurável |
| `Asmpkg\Guiche\ToolRegistry` | `class` | tipo exposto transitivamente por API pública |

| `Asmpkg\Guiche\Contracts\ContextAwareToolInterface` | `interface` | extensão compatível de Tool para execução com contexto |
| `Asmpkg\Guiche\Contracts\TenantResolverInterface` | `interface` | contrato público para resolução de tenant |
| `Asmpkg\Guiche\Execution\ExecutionContextSeeder` | `class` | vincula identity confiável à sessão |
| `Asmpkg\Guiche\Execution\ToolExecutionContext` | `class` | contexto imutável entregue a Tools contextuais |
| `Asmpkg\Guiche\Tenant\CallbackTenantResolver` | `class` | adapter público para repository/callback |
| `Asmpkg\Guiche\Tenant\HttpTenantResolver` | `class` | adapter público de resolução HTTP |
| `Asmpkg\Guiche\Tenant\SqlTenantResolver` | `class` | adapter público de resolução SQL/Stored Procedure |
| `Asmpkg\Guiche\Tenant\TenantResolutionService` | `class` | pipeline público de resolução e binding de tenant |

## Decisão para v1.0.0

Na v1.0.0, os 11 símbolos atualmente classificados como `experimental`
permanecem explicitamente fora do compromisso BC da API pública v1.x.

A lista foi congelada em `api/experimental-v1.json` e é validada por
`tools/check-experimental-api.php`. Promoção para `public` exige decisão
explícita de API, revisão SemVer e atualização deliberada dos baselines.

## Decisão para v1.1.0

A v1.1.0 mantém os 11 símbolos do Conversation Runtime como
`experimental` e adiciona oito símbolos `public` de contexto/tenant.

Contagem congelada:

- Public: 32;
- Experimental: 11;
- Internal: 12.

SHA-256 da API pública:

`f4328ccc4724f0e097e0d9369329a6bb3bbfe27bca5cddc8d452f43a63130c41`

As adições são compatíveis com SemVer da linha v1.x. O contrato original
de `ToolInterface` e `ContextStoreInterface` não foi quebrado.

## Decisão para v1.3.0

A v1.3.0 adiciona `Guiche::bindIdentity()` à classe pública `Guiche`.

A mudança é aditiva e compatível com SemVer. Nenhum símbolo público é
removido e nenhuma assinatura pública anterior é alterada.

Contagem congelada:

- Public: 32;
- Experimental: 11;
- Internal: 14.

SHA-256 da API pública congelada para v1.3.0:

`fa76a663dcb6afdd10dee53913a50fc6686ef88ce4f9618411967bc7168b4c52`

A partir da v1.3, `bindIdentity()` é o caminho público recomendado para hosts
externos. `ExecutionContextSeeder` permanece disponível por compatibilidade,
mas consumidores normais não precisam utilizá-lo diretamente.

## Decisão para v1.4.0

A v1.4 adiciona seis símbolos públicos de prompt e
`GuicheBuilder::withPromptResolver()` de forma aditiva.

- Public: 38;
- Experimental: 11;
- Internal: 14.

SHA-256 da API pública congelada:

`00af77a63fd4273fac60231169a9fc043a2429d4cc4b610cfcdcea1508de4f00`

`withInstructions()` permanece suportado e o Conversation Runtime continua
experimental.

## API experimental

| Símbolo | Tipo | Motivo |
|---|---|---|
| `Asmpkg\Guiche\Conversation\ConversationKey` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\ConversationRuntime` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\ConversationTurn` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\FollowUpScheduler` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\IncomingEvent` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\IncomingEventRecorder` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\JsonConversationStore` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\JsonScheduledEventStore` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\MessageBuffer` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\ScheduledEventRunner` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |
| `Asmpkg\Guiche\Conversation\Scheduler` | `class` | conversation runtime ainda fora do compromisso BC estável v1 |

## API interna

| Símbolo | Tipo | Motivo |
|---|---|---|
| `Asmpkg\Guiche\Plugin\ContextAwarePresentedTool` | `class` | decorator interno para Presenter de Tool contextual |
| `Asmpkg\Guiche\Clock\SystemClock` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Infrastructure\Database\PdoFactory` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Infrastructure\Database\PdoSqlExecutor` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Infrastructure\Http\CurlHttpClient` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Observability\RuntimeEventFactory` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Plugin\PresentedTool` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Providers\OpenAiResponsesProvider` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Providers\TerminalToolOutputAiProvider` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Safety\ToolArgumentValidator` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Safety\ToolExecutionGate` | `class` | detalhe de implementação não necessário à integração pública |
| `Asmpkg\Guiche\Safety\ToolSafetyMetadata` | `class` | detalhe de implementação não necessário à integração pública |
