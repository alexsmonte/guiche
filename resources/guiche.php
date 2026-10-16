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
use Asmpkg\Guiche\Prompt\PromptContext;
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
     * Resolver padrão:
     *
     * GUICHE_PROMPT_FILE
     *     ↓
     * GUICHE_PROMPT
     *     ↓
     * <projeto>/guiche.prompt.md
     *
     * O arquivo é lido em cada turno, permitindo atualização do prompt
     * sem reconstruir o objeto Guiche.
     */
    $promptResolver = $app['prompt_resolver']
        ?? static function (
            PromptContext $context
        ) use (
            $root,
            $env
        ): string {
            $configuredFile = trim((string)(
                $env(
                    'GUICHE_PROMPT_FILE',
                    ''
                )
            ));

            if ($configuredFile !== '') {
                $isAbsolute = str_starts_with(
                    $configuredFile,
                    '/'
                ) || preg_match(
                    '/^[A-Za-z]:[\\\\\/]/',
                    $configuredFile
                ) === 1;

                $path = $isAbsolute
                    ? $configuredFile
                    : $root
                        . '/'
                        . ltrim(
                            $configuredFile,
                            '/\\'
                        );

                return readGuichePrompt(
                    $path,
                    'GUICHE_PROMPT_FILE'
                );
            }

            $inline = trim((string)(
                $env(
                    'GUICHE_PROMPT',
                    ''
                )
            ));

            if ($inline !== '') {
                return $inline;
            }

            return readGuichePrompt(
                $root . '/guiche.prompt.md',
                'guiche.prompt.md'
            );
        };

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
     * Tools e Plugins podem ser fornecidos pela aplicação/framework.
     *
     * Em projetos simples, também é possível editar este arquivo e
     * preencher diretamente estas listas.
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
        if (
            !$tool instanceof ToolInterface
            && !$tool instanceof ToolRegistration
        ) {
            throw new \RuntimeException(
                'Cada item de tools precisa ser ToolInterface ou ToolRegistration.'
            );
        }

        $builder->registerTool($tool);
    }

    foreach ($plugins as $plugin) {
        if (!$plugin instanceof PluginInterface) {
            throw new \RuntimeException(
                'Cada item de plugins precisa implementar PluginInterface.'
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

/**
 * Lê um prompt de forma fail-closed.
 */
function readGuichePrompt(
    string $path,
    string $source
): string {
    if (!is_file($path)) {
        throw new \RuntimeException(
            "Prompt não encontrado em {$source}: {$path}"
        );
    }

    $content = file_get_contents($path);

    if ($content === false) {
        throw new \RuntimeException(
            "Não foi possível ler o prompt em {$source}: {$path}"
        );
    }

    $content = trim($content);

    if ($content === '') {
        throw new \RuntimeException(
            "Prompt vazio em {$source}: {$path}"
        );
    }

    return $content;
}
