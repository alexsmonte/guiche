<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class DeveloperExperienceEnvironmentTest extends TestCase
{
    public function testCoreConfigPrefersGenericAiVariables(): void
    {
        $root = dirname(__DIR__, 2);

        $keys = [
            'AI_API_KEY',
            'AI_MODEL',
            'OPENAI_API_KEY',
            'OPENAI_MODEL',
        ];

        $backupEnv = [];
        $backupServer = [];

        foreach ($keys as $key) {
            $backupEnv[$key] = $_ENV[$key] ?? null;
            $backupServer[$key] = $_SERVER[$key] ?? null;
        }

        try {
            $_ENV['AI_API_KEY'] = 'generic-key';
            $_ENV['AI_MODEL'] = 'generic-model';
            $_ENV['OPENAI_API_KEY'] = 'legacy-key';
            $_ENV['OPENAI_MODEL'] = 'legacy-model';

            $config = require $root . '/config/guiche.php';

            self::assertSame(
                'generic-key',
                $config['ai']['api_key']
            );
            self::assertSame(
                'generic-model',
                $config['ai']['model']
            );
        } finally {
            foreach ($keys as $key) {
                if ($backupEnv[$key] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $backupEnv[$key];
                }

                if ($backupServer[$key] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $backupServer[$key];
                }
            }
        }
    }

    public function testBasicExampleLoadsDotEnvAndKeepsUsageLocal(): void
    {
        $root = dirname(__DIR__, 2);
        $chat = $root . '/examples/basic/chat.php';
        $envPath = $root . '/.env';

        self::assertFileExists($chat);

        $hadEnv = is_file($envPath);
        $previousEnv = $hadEnv
            ? file_get_contents($envPath)
            : null;

        self::assertTrue(
            !$hadEnv || is_string($previousEnv)
        );

        file_put_contents(
            $envPath,
            "AI_API_KEY=phpunit-fake-key\n"
            . "AI_MODEL=gpt-5.6-luna\n"
            . "GUICHE_SESSION_ID=phpunit-dx\n"
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = getenv();

        if (!is_array($environment)) {
            $environment = [];
        }

        unset(
            $environment['AI_API_KEY'],
            $environment['AI_MODEL'],
            $environment['OPENAI_API_KEY'],
            $environment['OPENAI_MODEL'],
            $environment['GUICHE_SESSION_ID']
        );

        try {
            $process = proc_open(
                [PHP_BINARY, $chat],
                $descriptorSpec,
                $pipes,
                $root,
                $environment,
                ['bypass_shell' => true]
            );

            self::assertIsResource($process);

            fwrite($pipes[0], "/usage\n/sair\n");
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);
            $output = (string)$stdout . (string)$stderr;

            self::assertSame(0, $exitCode, $output);
            self::assertStringContainsString(
                'Comandos: /context, /usage, /tools, /reset, /sair',
                $output
            );
            self::assertStringNotContainsString(
                'Defina AI_API_KEY',
                $output
            );
            self::assertStringNotContainsString(
                'api.openai.com',
                $output
            );
            self::assertStringNotContainsString(
                'Erro:',
                $output
            );
        } finally {
            if ($hadEnv && is_string($previousEnv)) {
                file_put_contents($envPath, $previousEnv);
            } else {
                @unlink($envPath);
            }
        }
    }

    public function testDocumentedEnvironmentUsesGenericAiNames(): void
    {
        $root = dirname(__DIR__, 2);

        $files = [
            $root . '/.env.example',
            $root . '/examples/basic/.env.example',
            $root . '/examples/clinica-piloto/.env.example',
        ];

        foreach ($files as $file) {
            self::assertFileExists($file);

            $contents = file_get_contents($file);

            self::assertIsString($contents);
            self::assertStringContainsString(
                'AI_API_KEY=',
                $contents
            );
            self::assertStringContainsString(
                'AI_MODEL=',
                $contents
            );
            self::assertStringNotContainsString(
                'OPENAI_API_KEY=',
                $contents
            );
            self::assertStringNotContainsString(
                'OPENAI_MODEL=',
                $contents
            );
        }
    }
}
