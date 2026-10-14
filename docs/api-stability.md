# API Stability & SemVer

Este documento define a política oficial de estabilidade da API do Guichê para a linha v1.x.

## Regra principal

- `public`: faz parte do contrato estável da v1.x. Remoção, rename ou alteração incompatível exige major version.
- `experimental`: disponível para uso avançado, mas ainda não integra o compromisso de backward compatibility da v1.x até promoção explícita para `public`.
- `internal`: detalhe de implementação. Pode mudar sem aviso e não deve ser usado por consumidores.

O mapa canônico está em `api/stability-map.php`. O snapshot estrutural congelado está em `api/baseline-v1.json`.

Hash SHA-256 da API pública congelada: `39e417149aaee5ac9008de4bb3e477c579826f86ea9902b8755965fc1482368d`.

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
| `Asmpkg\Guiche\RuntimeContext` | `class` | tipo exposto transitivamente por API pública |
| `Asmpkg\Guiche\Safety\ToolBlockedException` | `class` | exceção observável pelo consumidor |
| `Asmpkg\Guiche\ToolPolicy` | `class` | política pública configurável |
| `Asmpkg\Guiche\ToolRegistry` | `class` | tipo exposto transitivamente por API pública |

## Decisão para v1.0.0

Na v1.0.0, os 11 símbolos atualmente classificados como `experimental`
permanecem explicitamente fora do compromisso BC da API pública v1.x.

A lista foi congelada em `api/experimental-v1.json` e é validada por
`tools/check-experimental-api.php`. Promoção para `public` exige decisão
explícita de API, revisão SemVer e atualização deliberada dos baselines.

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
