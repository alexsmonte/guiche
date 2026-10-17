<?php

declare(strict_types=1);

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Prompt\FilePromptResolver;
use Asmpkg\Guiche\Prompt\PromptContext;
use Asmpkg\Guiche\Prompt\StaticPromptResolver;
use Asmpkg\Guiche\Prompt\TenantPromptResolver;
use Asmpkg\Guiche\Providers\OpenAiResponsesProvider;
use Asmpkg\Guiche\RuntimeContext;

/**
 * Composition Root oficial do projeto consumidor.
 *
 * O arquivo é copiado para <projeto>/guiche.php por:
 *
 *     vendor/bin/guiche init
 *
 * A aplicação/framework pode então fazer:
 *
 *     $factory = require __DIR__ . '/guiche.php';
 *     $guiche = $factory();
 *
 * Dependências podem ser substituídas sem editar o Core:
 *
 *     $guiche = $factory([
 *         'ai_provider' => $provider,
 *         'context_store' => $store,
 *         'tenant_resolver' => $tenantResolver,
 *         'prompt_resolver' => $promptResolver,
 *         'services' => [...],
 *         'tools' => [...],
 *         'plugins' => [...],
 *     ]);
 *
 * @return Closure(array<string, mixed>): Guiche
 */
return static function (array $app = []): Guiche {
    $root = __DIR__;

    $env = static function (
        string $name,
        mixed $default = null
    ): mixed {
        $value = $_ENV[$name]
            ?? $_SERVER[$name]
            ?? null;

        if ($value === null) {
            $processValue = getenv($name);
            $value = $processValue !== false
                ? $processValue
                : null;
        }

        return $value === null || $value === ''
            ? $default
            : $value;
    };

    $timezone = trim((string)(
        $app['timezone']
        ?? $env(
            'GUICHE_TIMEZONE',
            'America/Sao_Paulo'
        )
    ));

    if ($timezone === '') {
        $timezone = 'America/Sao_Paulo';
    }

    $contextPath = trim((string)(
        $app['context_path']
        ?? ($root . '/storage/conversations')
    ));

    if ($contextPath === '') {
        throw new \RuntimeException(
            'context_path do Composition Root não pode ser vazio.'
        );
    }

    $contextStore = $app['context_store']
        ?? new ContextStore(
            $contextPath,
            $timezone
        );

    if (!$contextStore instanceof ContextStoreInterface) {
        throw new \RuntimeException(
            'context_store precisa implementar ContextStoreInterface.'
        );
    }

    $provider = $app['ai_provider'] ?? null;

    if ($provider === null) {
        $apiKey = trim((string)(
            $env(
                'AI_API_KEY',
                $env('OPENAI_API_KEY', '')
            )
        ));

        if ($apiKey === '') {
            throw new \RuntimeException(
                'AI_API_KEY não configurada no Composition Root.'
            );
        }

        $model = trim((string)(
            $env(
                'AI_MODEL',
                $env(
                    'OPENAI_MODEL',
                    'gpt-5.6-luna'
                )
            )
        ));

        $provider = new OpenAiResponsesProvider(
            $apiKey,
            $model !== ''
                ? $model
                : 'gpt-5.6-luna'
        );
    }

    if (!$provider instanceof AiProviderInterface) {
        throw new \RuntimeException(
            'ai_provider precisa implementar AiProviderInterface.'
        );
    }

    $runtime = $app['runtime']
        ?? new RuntimeContext($timezone);

    if (!$runtime instanceof RuntimeContext) {
        throw new \RuntimeException(
            'runtime precisa ser uma instância de RuntimeContext.'
        );
    }

    /*
     * Prioridade do resolver:
     *
     * $app['prompt_resolver']
     *     ↓
     * GUICHE_PROMPT_FILE
     *     ↓
     * GUICHE_PROMPT
     *     ↓
     * prompts/comportamento.md + {tenant_id}.md
     *     ↓
     * guiche.prompt.md legado
     *
     * O layout multi-tenant só é ativado automaticamente quando
     * prompts/comportamento.md existe, preservando instalações antigas.
     */
    $promptResolver = $app['prompt_resolver']
        ?? null;

    if ($promptResolver === null) {
        $configuredFile = trim((string)(
            $env(
                'GUICHE_PROMPT_FILE',
                ''
            )
        ));

        if ($configuredFile !== '') {
            $promptResolver =
                new FilePromptResolver(
                    $configuredFile,
                    $root
                );
        } else {
            $inline = trim((string)(
                $env(
                    'GUICHE_PROMPT',
                    ''
                )
            ));

            if ($inline !== '') {
                $promptResolver =
                    new StaticPromptResolver(
                        $inline
                    );
            } elseif (
                is_file(
                    $root
                    . '/prompts/comportamento.md'
                )
            ) {
                $promptResolver =
                    new TenantPromptResolver(
                        $root
                    );
            } else {
                $promptResolver =
                    new FilePromptResolver(
                        'guiche.prompt.md',
                        $root
                    );
            }
        }
    }
    if (
        !$promptResolver instanceof PromptResolverInterface
        && !$promptResolver instanceof \Closure
    ) {
        throw new \RuntimeException(
            'prompt_resolver precisa implementar PromptResolverInterface ou ser Closure.'
        );
    }

    $historyMessagesLimit = max(
        0,
        (int)(
            $app['history_messages_limit']
            ?? $env(
                'GUICHE_HISTORY_MESSAGES_LIMIT',
                8
            )
        )
    );

    $maxToolRounds = max(
        1,
        (int)(
            $app['max_tool_rounds']
            ?? 5
        )
    );

    $builder = (new GuicheBuilder())
        ->withContextStore($contextStore)
        ->withAiProvider($provider)
        ->withRuntime($runtime)
        ->withPromptResolver($promptResolver)
        ->withHistoryMessagesLimit(
            $historyMessagesLimit
        )
        ->withMaxToolRounds(
            $maxToolRounds
        );

    $instructions = trim((string)(
        $app['instructions'] ?? ''
    ));

    if ($instructions !== '') {
        $builder->withInstructions(
            $instructions
        );
    }

    $agentContext = $app['agent_context'] ?? [];

    if (!is_array($agentContext)) {
        throw new \RuntimeException(
            'agent_context precisa ser array.'
        );
    }

    if ($agentContext !== []) {
        $builder->withAgentContext(
            $agentContext
        );
    }

    $reusableTools = $app['reusable_tools'] ?? [];

    if (!is_array($reusableTools)) {
        throw new \RuntimeException(
            'reusable_tools precisa ser array.'
        );
    }

    if ($reusableTools !== []) {
        $builder->withReusableTools(
            $reusableTools
        );
    }

    $tenantResolver = $app['tenant_resolver']
        ?? null;

    if ($tenantResolver !== null) {
        if (
            !$tenantResolver
                instanceof TenantResolverInterface
        ) {
            throw new \RuntimeException(
                'tenant_resolver precisa implementar TenantResolverInterface.'
            );
        }

        $builder->withTenantResolver(
            $tenantResolver
        );
    }

    /*
     * Services precisam ser registrados antes dos loaders para permitir
     * injeção explícita de dependências por ClassResolver.
     *
     * Exemplo:
     *
     * 'services' => [
     *     Connection::class => $connection,
     * ],
     */
    $services = $app['services'] ?? [];

    if (!is_array($services)) {
        throw new \RuntimeException(
            'services precisa ser array.'
        );
    }

    foreach ($services as $id => $service) {
        if (
            !is_string($id)
            || trim($id) === ''
        ) {
            throw new \RuntimeException(
                'Cada service precisa possuir um id string não vazio.'
            );
        }

        if (!is_object($service)) {
            throw new \RuntimeException(
                "Service '{$id}' precisa ser object."
            );
        }

        $builder->setService(
            $id,
            $service
        );
    }

    /*
     * Compatibilidade:
     *
     * tools:
     * - ToolInterface / ToolRegistration => registerTool()
     * - string => loadTool()
     *
     * plugins:
     * - PluginInterface => registerPlugin()
     * - string => loadPlugin()
     *
     * Assim instalações antigas continuam válidas e projetos novos
     * podem usar as convenções App\Tool e App\Plugin.
     */
    $tools = $app['tools'] ?? [];
    $plugins = $app['plugins'] ?? [];

    if (!is_array($tools)) {
        throw new \RuntimeException(
            'tools precisa ser array.'
        );
    }

    if (!is_array($plugins)) {
        throw new \RuntimeException(
            'plugins precisa ser array.'
        );
    }

    foreach ($tools as $tool) {
        if (is_string($tool)) {
            $builder->loadTool($tool);

            continue;
        }

        if (
            !$tool instanceof ToolInterface
            && !$tool instanceof ToolRegistration
        ) {
            throw new \RuntimeException(
                'Cada item de tools precisa ser string, ToolInterface ou ToolRegistration.'
            );
        }

        $builder->registerTool($tool);
    }

    foreach ($plugins as $plugin) {
        if (is_string($plugin)) {
            $builder->loadPlugin($plugin);

            continue;
        }

        if (!$plugin instanceof PluginInterface) {
            throw new \RuntimeException(
                'Cada item de plugins precisa ser string ou implementar PluginInterface.'
            );
        }

        $builder->registerPlugin(
            $plugin
        );
    }
    /*
     * Escape hatch framework-agnostic.
     *
     * Laravel, Symfony, CakePHP ou PHP puro podem usar esta Closure
     * para aplicar configurações adicionais ao Builder sem alterar
     * nenhuma classe do Core.
     */
    $configure = $app['configure'] ?? null;

    if ($configure !== null) {
        if (!is_callable($configure)) {
            throw new \RuntimeException(
                'configure precisa ser callable.'
            );
        }

        $configure($builder);
    }

    return $builder->build();
};
