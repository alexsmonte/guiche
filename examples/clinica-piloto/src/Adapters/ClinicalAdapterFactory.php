<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Adapters;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Infrastructure\Database\PdoFactory;
use Asmpkg\Guiche\Infrastructure\Database\PdoSqlExecutor;
use Asmpkg\Guiche\Infrastructure\Http\CurlHttpClient;
use Asmpkg\Guiche\Providers\OpenAiResponsesProvider;
use Asmpkg\Guiche\Providers\TerminalToolOutputAiProvider;
use RuntimeException;

final class ClinicalAdapterFactory
{
    public static function mode(array $config): string
    {
        $mode = strtolower(trim((string)($config['mode'] ?? 'fake')));

        if (!in_array($mode, ['fake', 'real'], true)) {
            throw new RuntimeException(
                'GUICHE_CLINICAL_MODE deve ser "fake" ou "real".'
            );
        }

        return $mode;
    }

    public static function sql(array $config): SqlExecutorInterface
    {
        if (self::mode($config) === 'fake') {
            return new FakeSqlExecutor(self::fixtures($config));
        }

        return new PdoSqlExecutor(
            PdoFactory::fromConfig(
                is_array($config['database'] ?? null)
                    ? $config['database']
                    : []
            )
        );
    }

    public static function http(array $config): HttpClientInterface
    {
        if (self::mode($config) === 'fake') {
            return new FakeHttpClient(self::fixtures($config));
        }

        return new CurlHttpClient();
    }

    public static function ai(array $config): AiProviderInterface
    {
        if (self::mode($config) === 'fake') {
            return new FakeAiProvider();
        }

        $ai = is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $apiKey = trim((string)($ai['api_key'] ?? ''));
        $model = trim((string)($ai['model'] ?? 'gpt-5.6-luna'));

        if ($apiKey === '') {
            throw new RuntimeException(
                'AI_API_KEY é obrigatória em GUICHE_CLINICAL_MODE=real.'
            );
        }

        return new TerminalToolOutputAiProvider(
            new OpenAiResponsesProvider(
                $apiKey,
                $model !== '' ? $model : 'gpt-5.6-luna'
            )
        );
    }

    public static function fixtures(array $config): array
    {
        $fake = is_array($config['fake'] ?? null) ? $config['fake'] : [];
        $path = trim((string)($fake['fixture'] ?? ''));

        if ($path === '') {
            $path = dirname(__DIR__, 2) . '/fixtures/clinical.php';
        }

        if (!is_file($path)) {
            throw new RuntimeException(
                sprintf('Fixture clínica não encontrada: %s', $path)
            );
        }

        $fixtures = require $path;

        if (!is_array($fixtures)) {
            throw new RuntimeException('Fixture clínica deve retornar array.');
        }

        return $fixtures;
    }
}
