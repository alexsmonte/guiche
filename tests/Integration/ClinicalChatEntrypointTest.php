<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ClinicalChatEntrypointTest extends TestCase
{
    public function testClinicalChatEntrypointStartsInFakeMode(): void
    {
        $root = dirname(__DIR__, 2);
        $chat = $root . '/examples/clinica-piloto/chat.php';

        self::assertFileExists($chat);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $environment = getenv();

        if (!is_array($environment)) {
            $environment = [];
        }

        $environment['GUICHE_CLINICAL_MODE'] = 'fake';
        $environment['GUICHE_CLINIC_COMPANY_ID'] = '1';
        $environment['GUICHE_CLINIC_NAME'] = 'Clínica Exemplo';
        $environment['GUICHE_DEBUG'] = 'false';

        $process = proc_open(
            [PHP_BINARY, $chat, 'phpunit-chat-smoke'],
            $descriptorSpec,
            $pipes,
            $root,
            $environment,
            ['bypass_shell' => true]
        );

        self::assertIsResource($process);

        fwrite($pipes[0], "/sair\n");
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = (string)$stdout . (string)$stderr;

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString(
            'Sessão: phpunit-chat-smoke',
            $output
        );
        self::assertStringContainsString(
            'DisponibilidadeConvenios: FAKE',
            $output
        );
        self::assertStringContainsString(
            'ConsultarProfissional: FAKE',
            $output
        );
        self::assertStringContainsString(
            'ConsultarHorarios: FAKE',
            $output
        );
        self::assertStringContainsString(
            'Comandos: /context /usage /runtime /tools /reset /sair',
            $output
        );
        self::assertStringNotContainsString(
            'Failed opening required',
            $output
        );
        self::assertStringNotContainsString(
            'Undefined variable $config',
            $output
        );
    }
}
