<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContextStorePersistenceTest extends TestCase
{
    private string $directory;
    private ContextStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v06-context-'
            . bin2hex(random_bytes(8));

        $this->store = new ContextStore(
            $this->directory,
            'America/Manaus'
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testNewContextAndMutationPersistSchemaVersionOne(): void
    {
        $context = $this->store->load('new-session');

        self::assertSame(1, $context['schema_version']);
        self::assertSame('new-session', $context['session_id']);
        self::assertSame([], $context['state']);
        self::assertSame([], $context['messages']);
        self::assertSame([], $context['tool_history']);
        self::assertSame(0, $context['usage']['total_tokens']);

        $this->store->addMessage(
            'new-session',
            'user',
            'persist-me'
        );

        $persisted = json_decode(
            (string) file_get_contents(
                $this->contextFile('new-session')
            ),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(1, $persisted['schema_version']);
        self::assertSame(
            'persist-me',
            $persisted['messages'][0]['content']
        );
    }

    public function testLegacyContextIsNormalizedWithoutDataLoss(): void
    {
        $session = 'legacy';
        $file = $this->contextFile($session);

        file_put_contents(
            $file,
            json_encode(
                [
                    'session_id' => $session,
                    'intent' => 'legacy-intent',
                    'state' => [
                        'plugin_data' => ['preserve' => true],
                    ],
                    'messages' => [],
                    'tool_history' => [],
                    'custom_root' => ['unknown' => 'preserve-me'],
                    'created_at' => '2026-10-01T10:00:00-04:00',
                    'updated_at' => '2026-10-01T10:00:00-04:00',
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            )
        );

        $loaded = $this->store->load($session);

        self::assertSame(1, $loaded['schema_version']);
        self::assertSame(
            'preserve-me',
            $loaded['custom_root']['unknown']
        );
        self::assertTrue(
            $loaded['state']['plugin_data']['preserve']
        );
        self::assertSame(0, $loaded['usage']['total_tokens']);

        $this->store->mergeState(
            $session,
            ['migrated' => true]
        );

        $persisted = json_decode(
            (string) file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(1, $persisted['schema_version']);
        self::assertSame(
            'preserve-me',
            $persisted['custom_root']['unknown']
        );
        self::assertTrue($persisted['state']['migrated']);
    }

    public function testCorruptedJsonFailsExplicitly(): void
    {
        file_put_contents(
            $this->contextFile('corrupt'),
            '{"json":'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('corrompido');

        $this->store->load('corrupt');
    }

    public function testAtomicWriteLeavesNoTempAndIgnoresInterruptedTemp(): void
    {
        $session = 'atomic';

        $this->store->mergeState(
            $session,
            ['stable' => 'yes']
        );

        $file = $this->contextFile($session);

        self::assertSame([], glob($file . '.tmp.*'));

        $interrupted = $file . '.tmp.interrupted';
        file_put_contents($interrupted, '{"partial":');

        self::assertSame(
            'yes',
            $this->store->load($session)['state']['stable']
        );

        self::assertFileExists($interrupted);
        unlink($interrupted);
    }

    public function testFingerprintAndSuccessfulResultSurviveRestart(): void
    {
        $session = 'restart';
        $arguments = [
            'value' => ' ABC ',
            'nested' => ['b' => 2, 'a' => 1],
        ];

        $fingerprintA = $this->store->toolFingerprint(
            $session,
            'WriteTool',
            $arguments
        );

        $fingerprintB = $this->store->toolFingerprint(
            $session,
            'writetool',
            [
                'nested' => ['a' => 1, 'b' => 2],
                'value' => 'abc',
            ]
        );

        self::assertSame($fingerprintA, $fingerprintB);

        $result = [
            'success' => true,
            'data' => ['id' => 999],
        ];

        $this->store->addToolExecution(
            $session,
            'WriteTool',
            $arguments,
            $result
        );

        $newProcess = new ContextStore(
            $this->directory,
            'America/Manaus'
        );

        self::assertSame(
            $result,
            $newProcess->findSuccessfulToolResult(
                $session,
                'writetool',
                [
                    'nested' => ['a' => 1, 'b' => 2],
                    'value' => 'abc',
                ]
            )
        );

        self::assertCount(
            1,
            $newProcess->load($session)['tool_history']
        );
    }

    public function testDeleteRemovesPersistedContext(): void
    {
        $session = 'delete';

        $this->store->mergeState(
            $session,
            ['exists' => true]
        );

        self::assertFileExists(
            $this->contextFile($session)
        );

        $this->store->delete($session);

        self::assertFileDoesNotExist(
            $this->contextFile($session)
        );
        self::assertSame(
            [],
            $this->store->load($session)['state']
        );
    }

    private function contextFile(string $session): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $session)
            . '.json';
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
