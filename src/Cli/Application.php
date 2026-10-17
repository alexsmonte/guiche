<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Cli;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Providers\OpenAiResponsesProvider;
use Dotenv\Dotenv;
use RuntimeException;
use Throwable;

final class Application
{
    public function __construct(
        private readonly string $packageRoot,
        private readonly string $projectRoot,
        private readonly ?AiProviderInterface $aiProvider = null
    ) {
    }

    /**
     * @param array<int, string> $argv
     */
    public function run(array $argv): int
    {
        $command = strtolower(trim($argv[1] ?? 'help'));
        $arguments = array_slice($argv, 2);

        try {
            return match ($command) {
                '', 'help', '--help', '-h' => $this->help(),
                'init' => $this->init(),
                'doctor' => $this->doctor(),
                'chat' => $this->chat($arguments),
                'usage' => $this->usage($arguments),
                default => $this->unknown($command),
            };
        } catch (Throwable $exception) {
            fwrite(
                STDERR,
                'Guichê: ' . $exception->getMessage() . PHP_EOL
            );

            return 1;
        }
    }

    private function help(): int
    {
        echo <<<'TXT'
Guichê CLI

Uso:
  guiche <comando> [opções]

Comandos:
  help                Exibe esta ajuda.
  init                Inicializa arquivos locais mínimos do projeto.
  doctor              Verifica ambiente PHP, extensões e configuração.
  chat [mensagem]     Inicia conversa ou envia uma mensagem única.
  usage [sessão]      Exibe consumo acumulado de tokens da sessão.

Opções:
  --session=<id>      Define a sessão para chat ou usage.

Exemplos:
  vendor/bin/guiche init
  vendor/bin/guiche doctor
  vendor/bin/guiche chat
  vendor/bin/guiche chat "Olá, tudo bem?"
  vendor/bin/guiche chat --session=cliente-123 "Olá"
  vendor/bin/guiche usage
  vendor/bin/guiche usage --session=cliente-123

TXT;

        return 0;
    }

    private function init(): int
    {
        $source = $this->packageRoot . '/.env.example';
        $target = $this->projectRoot . '/.env';
        $promptSource = $this->packageRoot
            . '/resources/guiche.prompt.md';
        $legacyPromptTarget = $this->projectRoot
            . '/'
            . PromptResolver::PROJECT_PROMPT_FILE;
        $promptDirectory = $this->projectRoot
            . '/prompts';
        $globalPromptTarget = $promptDirectory
            . '/comportamento.md';
        $compositionRootSource = $this->packageRoot
            . '/resources/guiche.php';
        $compositionRootTarget = $this->projectRoot
            . '/guiche.php';
        $storage = $this->storagePath();
        $toolDirectory = $this->projectRoot
            . '/src/Tool';
        $pluginDirectory = $this->projectRoot
            . '/src/Plugin';

        if (!is_file($source)) {
            throw new RuntimeException(
                '.env.example do pacote não foi encontrado.'
            );
        }

        if (!is_file($promptSource)) {
            throw new RuntimeException(
                'Prompt padrão do pacote não foi encontrado.'
            );
        }

        if (!is_file($compositionRootSource)) {
            throw new RuntimeException(
                'Template guiche.php do pacote não foi encontrado.'
            );
        }

        $this->ensureDirectory(
            $promptDirectory,
            'prompts'
        );
        $this->ensureDirectory(
            $toolDirectory,
            'src/Tool'
        );
        $this->ensureDirectory(
            $pluginDirectory,
            'src/Plugin'
        );
        $this->ensureDirectory(
            $storage,
            'storage/conversations'
        );

        $createdEnv = false;
        $createdLegacyPrompt = false;
        $createdGlobalPrompt = false;
        $createdCompositionRoot = false;

        if (!is_file($target)) {
            if (!copy($source, $target)) {
                throw new RuntimeException(
                    'Não foi possível criar o arquivo .env.'
                );
            }

            $createdEnv = true;
        }

        /*
         * Mantido por compatibilidade com instalações anteriores.
         * O Composition Root novo prioriza prompts/comportamento.md.
         */
        if (!is_file($legacyPromptTarget)) {
            if (!copy(
                $promptSource,
                $legacyPromptTarget
            )) {
                throw new RuntimeException(
                    'Não foi possível criar guiche.prompt.md.'
                );
            }

            $createdLegacyPrompt = true;
        }

        if (!is_file($globalPromptTarget)) {
            if (!copy(
                $promptSource,
                $globalPromptTarget
            )) {
                throw new RuntimeException(
                    'Não foi possível criar prompts/comportamento.md.'
                );
            }

            $createdGlobalPrompt = true;
        }

        if (!is_file($compositionRootTarget)) {
            if (!copy(
                $compositionRootSource,
                $compositionRootTarget
            )) {
                throw new RuntimeException(
                    'Não foi possível criar guiche.php.'
                );
            }

            $createdCompositionRoot = true;
        }

        $composerStatus =
            $this->ensureProjectPsr4();

        echo "Guichê inicializado." . PHP_EOL;
        echo $createdEnv
            ? "  [OK] .env criado a partir de .env.example" . PHP_EOL
            : "  [OK] .env existente preservado" . PHP_EOL;
        echo $createdGlobalPrompt
            ? "  [OK] prompts/comportamento.md criado" . PHP_EOL
            : "  [OK] prompts/comportamento.md existente preservado" . PHP_EOL;
        echo $createdLegacyPrompt
            ? "  [OK] guiche.prompt.md legado criado para compatibilidade" . PHP_EOL
            : "  [OK] guiche.prompt.md existente preservado" . PHP_EOL;
        echo $createdCompositionRoot
            ? "  [OK] guiche.php criado como Composition Root" . PHP_EOL
            : "  [OK] guiche.php existente preservado" . PHP_EOL;
        echo "  [OK] src/Tool disponível" . PHP_EOL;
        echo "  [OK] src/Plugin disponível" . PHP_EOL;
        echo "  [OK] storage/conversations disponível" . PHP_EOL;

        match ($composerStatus) {
            'added' => print(
                "  [OK] composer.json atualizado com App\\\\ => src/" . PHP_EOL
            ),
            'ok' => print(
                "  [OK] composer.json já possui App\\\\ => src/" . PHP_EOL
            ),
            'missing' => print(
                "  [WARN] composer.json não encontrado; App\\\\ => src/ não configurado" . PHP_EOL
            ),
            'conflict' => print(
                "  [WARN] composer.json já possui App\\\\ com outro caminho; mapeamento preservado" . PHP_EOL
            ),
            default => throw new RuntimeException(
                'Status inesperado ao configurar PSR-4.'
            ),
        };

        echo PHP_EOL;
        echo "Próximo passo: configure AI_API_KEY no .env e execute:" . PHP_EOL;
        echo "  vendor/bin/guiche doctor" . PHP_EOL;
        echo "  vendor/bin/guiche chat" . PHP_EOL;

        return 0;
    }
    private function doctor(): int
    {
        $this->loadEnvironment();

        $failures = 0;
        $warnings = 0;

        $runtimePhpVersion = (string) phpversion();
        $phpVersionOk = version_compare(
            $runtimePhpVersion,
            '8.4.0',
            '>='
        );

        $this->doctorLine(
            $phpVersionOk ? 'OK' : 'FAIL',
            'PHP',
            $runtimePhpVersion
        );

        if (!$phpVersionOk) {
            $failures++;
        }

        foreach (['curl', 'mbstring', 'json'] as $extension) {
            $loaded = extension_loaded($extension);

            $this->doctorLine(
                $loaded ? 'OK' : 'FAIL',
                'ext-' . $extension,
                $loaded ? 'carregada' : 'ausente'
            );

            if (!$loaded) {
                $failures++;
            }
        }

        $envFile = $this->projectRoot . '/.env';
        $hasEnv = is_file($envFile);

        $this->doctorLine(
            $hasEnv ? 'OK' : 'WARN',
            '.env',
            $hasEnv
                ? 'encontrado'
                : 'não encontrado; variáveis do processo ainda podem ser usadas'
        );

        if (!$hasEnv) {
            $warnings++;
        }

        $apiKey = $this->apiKey();

        $this->doctorLine(
            $apiKey !== '' ? 'OK' : 'FAIL',
            'AI_API_KEY',
            $apiKey !== '' ? 'configurada' : 'não configurada'
        );

        if ($apiKey === '') {
            $failures++;
        }

        $model = $this->model();

        $this->doctorLine(
            $model !== '' ? 'OK' : 'FAIL',
            'AI_MODEL',
            $model !== '' ? $model : 'não configurado'
        );

        if ($model === '') {
            $failures++;
        }

        $globalPrompt = $this->projectRoot
            . '/prompts/comportamento.md';
        $legacyPrompt = $this->projectRoot
            . '/'
            . PromptResolver::PROJECT_PROMPT_FILE;

        if (is_file($globalPrompt)) {
            $content = file_get_contents(
                $globalPrompt
            );
            $promptOk = is_string($content)
                && trim($content) !== '';

            $this->doctorLine(
                $promptOk ? 'OK' : 'FAIL',
                'prompts/comportamento.md',
                $promptOk
                    ? 'prompt global disponível'
                    : 'arquivo vazio ou ilegível'
            );

            if (!$promptOk) {
                $failures++;
            }
        } elseif (is_file($legacyPrompt)) {
            $content = file_get_contents(
                $legacyPrompt
            );
            $legacyOk = is_string($content)
                && trim($content) !== '';

            $this->doctorLine(
                $legacyOk ? 'WARN' : 'FAIL',
                'prompt global',
                $legacyOk
                    ? 'usando guiche.prompt.md legado'
                    : 'guiche.prompt.md legado vazio ou ilegível'
            );

            if ($legacyOk) {
                $warnings++;
            } else {
                $failures++;
            }
        } else {
            $this->doctorLine(
                'FAIL',
                'prompt global',
                'prompts/comportamento.md e guiche.prompt.md ausentes'
            );
            $failures++;
        }

        foreach (
            [
                'src/Tool' =>
                    $this->projectRoot . '/src/Tool',
                'src/Plugin' =>
                    $this->projectRoot . '/src/Plugin',
            ] as $label => $directory
        ) {
            $exists = is_dir($directory);

            $this->doctorLine(
                $exists ? 'OK' : 'WARN',
                $label,
                $exists
                    ? 'disponível'
                    : 'ausente; loaders por convenção podem não funcionar'
            );

            if (!$exists) {
                $warnings++;
            }
        }

        try {
            $composerStatus =
                $this->inspectProjectPsr4();
        } catch (RuntimeException $exception) {
            $composerStatus = 'invalid';

            $this->doctorLine(
                'FAIL',
                'composer PSR-4',
                $exception->getMessage()
            );
            $failures++;
        }

        if ($composerStatus === 'ok') {
            $this->doctorLine(
                'OK',
                'composer PSR-4',
                'App\\\\ => src/'
            );
        } elseif ($composerStatus === 'missing') {
            $this->doctorLine(
                'WARN',
                'composer PSR-4',
                'composer.json não encontrado'
            );
            $warnings++;
        } elseif ($composerStatus === 'missing_mapping') {
            $this->doctorLine(
                'FAIL',
                'composer PSR-4',
                'App\\\\ => src/ ausente'
            );
            $failures++;
        } elseif ($composerStatus === 'conflict') {
            $this->doctorLine(
                'FAIL',
                'composer PSR-4',
                'App\\\\ existe, mas não aponta para src/'
            );
            $failures++;
        } elseif ($composerStatus !== 'invalid') {
            throw new RuntimeException(
                'Status inesperado ao validar PSR-4.'
            );
        }
        $storage = $this->storagePath();
        $storageOk = is_dir($storage)
            && is_writable($storage);

        $this->doctorLine(
            $storageOk ? 'OK' : 'FAIL',
            'storage/conversations',
            $storageOk
                ? 'gravável'
                : 'ausente ou sem permissão de escrita'
        );

        if (!$storageOk) {
            $failures++;
        }

        echo PHP_EOL;

        if ($failures > 0) {
            echo "Diagnóstico: {$failures} falha(s), {$warnings} aviso(s)." . PHP_EOL;

            return 1;
        }

        echo "Diagnóstico: ambiente pronto";
        echo $warnings > 0
            ? " com {$warnings} aviso(s)."
            : ".";
        echo PHP_EOL;

        return 0;
    }
    /**
     * @param array<int, string> $arguments
     */
    private function chat(array $arguments): int
    {
        $this->loadEnvironment();

        [$sessionId, $message] = $this->chatArguments(
            $arguments
        );

        $guiche = $this->buildGuiche();

        if ($message !== '') {
            $this->respond(
                $guiche,
                $sessionId,
                $message
            );

            return 0;
        }

        echo "Guichê — chat" . PHP_EOL;
        echo "Modelo: " . $this->model() . PHP_EOL;
        echo "Sessão: {$sessionId}" . PHP_EOL;
        echo "Comandos: /context, /usage, /tools, /reset, /sair" . PHP_EOL;
        echo PHP_EOL;

        while (true) {
            $line = readline('Você: ');

            if ($line === false) {
                echo PHP_EOL;

                break;
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (in_array(
                $line,
                ['/sair', '/exit', '/quit'],
                true
            )) {
                break;
            }

            if ($line === '/context') {
                $this->printJson(
                    $guiche->context($sessionId)
                );

                continue;
            }

            if ($line === '/usage') {
                $context = $guiche->context($sessionId);

                $this->printJson(
                    $context['usage'] ?? []
                );

                continue;
            }

            if ($line === '/tools') {
                $this->printJson(
                    $guiche->tools()
                );

                continue;
            }

            if ($line === '/reset') {
                $guiche->reset($sessionId);

                echo "Guichê: conversa reiniciada." . PHP_EOL;

                continue;
            }

            try {
                $this->respond(
                    $guiche,
                    $sessionId,
                    $line
                );
            } catch (Throwable $exception) {
                fwrite(
                    STDERR,
                    'Erro: ' . $exception->getMessage() . PHP_EOL
                );
            }
        }

        return 0;
    }

    /**
     * @param array<int, string> $arguments
     */
    private function usage(array $arguments): int
    {
        $this->loadEnvironment();

        $sessionId = $this->sessionId($arguments);
        $timezone = (string) $this->env(
            'GUICHE_TIMEZONE',
            'America/Sao_Paulo'
        );

        $store = new ContextStore(
            $this->storagePath(),
            $timezone
        );

        $context = $store->load($sessionId);
        $usage = is_array($context['usage'] ?? null)
            ? $context['usage']
            : [];

        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? ($input + $output));
        $interactions = is_array($usage['interactions'] ?? null)
            ? count($usage['interactions'])
            : 0;

        echo "Sessão: {$sessionId}" . PHP_EOL;
        echo "Interações: {$interactions}" . PHP_EOL;
        echo "Input tokens: {$input}" . PHP_EOL;
        echo "Output tokens: {$output}" . PHP_EOL;
        echo "Total tokens: {$total}" . PHP_EOL;

        return 0;
    }

    private function buildFromCompositionRoot(): ?Guiche
    {
        $file = $this->projectRoot
            . '/guiche.php';

        if (!is_file($file)) {
            return null;
        }

        $factory = require $file;

        if (!is_callable($factory)) {
            throw new RuntimeException(
                'guiche.php precisa retornar uma factory callable.'
            );
        }

        $overrides = [];

        if ($this->aiProvider !== null) {
            $overrides['ai_provider'] =
                $this->aiProvider;
        }

        $guiche = $factory($overrides);

        if (!$guiche instanceof Guiche) {
            throw new RuntimeException(
                'A factory de guiche.php precisa retornar Asmpkg\\Guiche\\Guiche.'
            );
        }

        return $guiche;
    }

    private function buildGuiche(): Guiche
    {
        $composed = $this->buildFromCompositionRoot();

        if ($composed !== null) {
            return $composed;
        }

        $storage = $this->storagePath();

        if (
            !is_dir($storage)
            && !mkdir($storage, 0775, true)
            && !is_dir($storage)
        ) {
            throw new RuntimeException(
                'Não foi possível criar storage/conversations.'
            );
        }

        $timezone = (string) $this->env(
            'GUICHE_TIMEZONE',
            'America/Sao_Paulo'
        );

        $historyMessagesLimit = max(
            0,
            (int) $this->env(
                'GUICHE_HISTORY_MESSAGES_LIMIT',
                8
            )
        );

        $provider = $this->aiProvider;

        if ($provider === null) {
            $apiKey = $this->apiKey();

            if ($apiKey === '') {
                throw new RuntimeException(
                    'AI_API_KEY não configurada. Execute vendor/bin/guiche init e configure o .env.'
                );
            }

            $provider = new OpenAiResponsesProvider(
                $apiKey,
                $this->model()
            );
        }

        $builder = new GuicheBuilder();
        $builder->withContextStore(
            new ContextStore(
                $storage,
                $timezone
            )
        );
        $builder->withAiProvider($provider);
        $builder->withInstructions(
            (new PromptResolver(
                $this->packageRoot,
                $this->projectRoot
            ))->resolve()
        );
        $builder->withHistoryMessagesLimit(
            $historyMessagesLimit
        );

        return $builder->build();
    }

    private function respond(
        Guiche $guiche,
        string $sessionId,
        string $message
    ): void {
        $result = $guiche->handle(
            $sessionId,
            $message
        );

        echo 'Guichê: '
            . (string) ($result['message'] ?? '')
            . PHP_EOL;
    }

    private function printJson(mixed $value): void
    {
        echo json_encode(
            $value,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    }

    private function unknown(string $command): int
    {
        fwrite(
            STDERR,
            "Comando desconhecido: {$command}" . PHP_EOL
            . "Use: vendor/bin/guiche help" . PHP_EOL
        );

        return 1;
    }

    private function loadEnvironment(): void
    {
        if (is_file($this->projectRoot . '/.env')) {
            Dotenv::createImmutable(
                $this->projectRoot
            )->safeLoad();
        }
    }

    private function apiKey(): string
    {
        return trim((string) $this->env(
            'AI_API_KEY',
            $this->env('OPENAI_API_KEY', '')
        ));
    }

    private function model(): string
    {
        $model = trim((string) $this->env(
            'AI_MODEL',
            $this->env(
                'OPENAI_MODEL',
                'gpt-5.6-luna'
            )
        ));

        return $model !== ''
            ? $model
            : 'gpt-5.6-luna';
    }

    private function ensureDirectory(
        string $directory,
        string $label
    ): void {
        if (
            is_dir($directory)
            || (
                mkdir(
                    $directory,
                    0775,
                    true
                )
                && is_dir($directory)
            )
        ) {
            return;
        }

        throw new RuntimeException(
            "Não foi possível criar {$label}."
        );
    }

    private function ensureProjectPsr4(): string
    {
        $status = $this->inspectProjectPsr4();

        if (
            $status === 'missing'
            || $status === 'ok'
            || $status === 'conflict'
        ) {
            return $status;
        }

        if ($status !== 'missing_mapping') {
            throw new RuntimeException(
                'Status inesperado do composer.json.'
            );
        }

        $path = $this->projectRoot
            . '/composer.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                'Não foi possível ler composer.json.'
            );
        }

        try {
            $composer = json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                'composer.json contém JSON inválido.',
                0,
                $exception
            );
        }

        if (!is_array($composer)) {
            throw new RuntimeException(
                'composer.json precisa conter um objeto JSON.'
            );
        }

        $autoload = $composer['autoload'] ?? [];

        if (!is_array($autoload)) {
            throw new RuntimeException(
                'composer.json: autoload precisa ser objeto.'
            );
        }

        $psr4 = $autoload['psr-4'] ?? [];

        if (!is_array($psr4)) {
            throw new RuntimeException(
                'composer.json: autoload.psr-4 precisa ser objeto.'
            );
        }

        $psr4['App\\'] = 'src/';
        $autoload['psr-4'] = $psr4;
        $composer['autoload'] = $autoload;

        $encoded = json_encode(
            $composer,
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        );

        $temporary = $path
            . '.guiche.'
            . bin2hex(random_bytes(6))
            . '.tmp';

        if (
            file_put_contents(
                $temporary,
                $encoded . PHP_EOL,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Não foi possível preparar atualização de composer.json.'
            );
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(
                'Não foi possível atualizar composer.json.'
            );
        }

        return 'added';
    }

    private function inspectProjectPsr4(): string
    {
        $path = $this->projectRoot
            . '/composer.json';

        if (!is_file($path)) {
            return 'missing';
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                'composer.json não pôde ser lido.'
            );
        }

        try {
            $composer = json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                'composer.json contém JSON inválido.',
                0,
                $exception
            );
        }

        if (!is_array($composer)) {
            throw new RuntimeException(
                'composer.json precisa conter um objeto JSON.'
            );
        }

        $autoload = $composer['autoload'] ?? [];

        if (!is_array($autoload)) {
            throw new RuntimeException(
                'composer.json: autoload precisa ser objeto.'
            );
        }

        $psr4 = $autoload['psr-4'] ?? [];

        if (!is_array($psr4)) {
            throw new RuntimeException(
                'composer.json: autoload.psr-4 precisa ser objeto.'
            );
        }

        if (!array_key_exists('App\\', $psr4)) {
            return 'missing_mapping';
        }

        return $this->psr4PointsToSrc(
            $psr4['App\\']
        )
            ? 'ok'
            : 'conflict';
    }

    private function psr4PointsToSrc(
        mixed $mapping
    ): bool {
        if (is_string($mapping)) {
            return $this->normalizePsr4Path(
                $mapping
            ) === 'src';
        }

        if (!is_array($mapping)) {
            return false;
        }

        foreach ($mapping as $path) {
            if (
                is_string($path)
                && $this->normalizePsr4Path(
                    $path
                ) === 'src'
            ) {
                return true;
            }
        }

        return false;
    }

    private function normalizePsr4Path(
        string $path
    ): string {
        return rtrim(
            str_replace('\\', '/', trim($path)),
            '/'
        );
    }
    private function storagePath(): string
    {
        return $this->projectRoot
            . '/storage/conversations';
    }

    /**
     * @param array<int, string> $arguments
     * @return array{0:string, 1:string}
     */
    private function chatArguments(array $arguments): array
    {
        $sessionId = $this->sessionId($arguments);
        $messageParts = [];

        for ($i = 0, $count = count($arguments); $i < $count; $i++) {
            $argument = $arguments[$i];

            if (str_starts_with($argument, '--session=')) {
                continue;
            }

            if ($argument === '--session') {
                $i++;

                continue;
            }

            if (str_starts_with($argument, '-')) {
                continue;
            }

            $messageParts[] = $argument;
        }

        return [
            $sessionId,
            trim(implode(' ', $messageParts)),
        ];
    }

    /**
     * @param array<int, string> $arguments
     */
    private function sessionId(array $arguments): string
    {
        $sessionId = trim((string) $this->env(
            'GUICHE_SESSION_ID',
            'guiche-cli'
        ));

        for ($i = 0, $count = count($arguments); $i < $count; $i++) {
            $argument = $arguments[$i];

            if (str_starts_with($argument, '--session=')) {
                $sessionId = trim(substr(
                    $argument,
                    strlen('--session=')
                ));

                continue;
            }

            if (
                $argument === '--session'
                && isset($arguments[$i + 1])
            ) {
                $sessionId = trim(
                    $arguments[++$i]
                );
            }
        }

        return $sessionId !== ''
            ? $sessionId
            : 'guiche-cli';
    }

    private function env(string $name, mixed $default = null): mixed
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        if ($value === null) {
            $processValue = getenv($name);
            $value = $processValue !== false
                ? $processValue
                : null;
        }

        return $value === null || $value === ''
            ? $default
            : $value;
    }

    private function doctorLine(
        string $status,
        string $label,
        string $detail
    ): void {
        echo sprintf(
            "  [%-4s] %-24s %s%s",
            $status,
            $label,
            $detail,
            PHP_EOL
        );
    }
}
