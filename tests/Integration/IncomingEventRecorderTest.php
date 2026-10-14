<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Conversation\ConversationKey;
use Asmpkg\Guiche\Conversation\IncomingEvent;
use Asmpkg\Guiche\Conversation\IncomingEventRecorder;
use Asmpkg\Guiche\Conversation\JsonConversationStore;
use Asmpkg\Guiche\Tests\Fixtures\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IncomingEventRecorderTest extends TestCase
{
    private string $directory;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v06-incoming-'
            . bin2hex(random_bytes(8));

        $this->clock = new FrozenClock(
            new DateTimeImmutable(
                '2026-10-14T02:00:00Z'
            )
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testRecordsSequenceDeduplicatesAndPreservesUnknownFields(): void
    {
        $store = new JsonConversationStore(
            $this->directory
        );

        $key = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'conversation'
        );

        $store->mutate(
            $key,
            static function (array $state): array {
                $state['plugin_unknown'] = ['keep' => true];

                return $state;
            }
        );

        $recorder = new IncomingEventRecorder(
            $store,
            $this->clock
        );

        $first = $recorder->record(
            $key,
            new IncomingEvent(
                'external-1',
                'message',
                ['text' => 'Oi']
            )
        );

        self::assertTrue($first['accepted']);
        self::assertFalse($first['duplicate']);
        self::assertSame(1, $first['sequence']);

        $duplicate = $recorder->record(
            $key,
            new IncomingEvent(
                'external-1',
                'message',
                ['text' => 'DUPLICATE']
            )
        );

        self::assertFalse($duplicate['accepted']);
        self::assertTrue($duplicate['duplicate']);
        self::assertSame(1, $duplicate['sequence']);

        $this->clock->advanceSeconds(5);

        $second = $recorder->record(
            $key,
            new IncomingEvent(
                'external-2',
                'message',
                ['text' => 'second']
            )
        );

        self::assertSame(2, $second['sequence']);

        $state = $store->load($key);

        self::assertSame(1, $state['schema_version']);
        self::assertSame(2, $state['sequence']);
        self::assertCount(2, $state['events']);
        self::assertSame(
            ['Oi', 'second'],
            array_column(
                array_column($state['events'], 'payload'),
                'text'
            )
        );
        self::assertTrue(
            $state['plugin_unknown']['keep']
        );
    }

    public function testDedupeSurvivesRestartAndConversationIsolation(): void
    {
        $keyA = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'a'
        );

        $keyB = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'b'
        );

        $store = new JsonConversationStore(
            $this->directory
        );

        $recorder = new IncomingEventRecorder(
            $store,
            $this->clock
        );

        $recorder->record(
            $keyA,
            new IncomingEvent(
                'same-id',
                'message',
                ['text' => 'A']
            )
        );

        unset($recorder, $store);

        $restarted = new JsonConversationStore(
            $this->directory
        );

        $afterRestart = new IncomingEventRecorder(
            $restarted,
            $this->clock
        );

        self::assertTrue(
            $afterRestart->record(
                $keyA,
                new IncomingEvent(
                    'same-id',
                    'message',
                    ['text' => 'retry']
                )
            )['duplicate']
        );

        self::assertTrue(
            $afterRestart->record(
                $keyB,
                new IncomingEvent(
                    'same-id',
                    'message',
                    ['text' => 'B']
                )
            )['accepted']
        );
    }

    public function testCorruptedJsonFailsWithoutOverwrite(): void
    {
        $store = new JsonConversationStore(
            $this->directory
        );

        $key = ConversationKey::fromParts(
            'provider',
            'tenant',
            'inbox',
            'corrupt'
        );

        $file = $this->directory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $key)
            . '.json';

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0770, true);
        }

        file_put_contents($file, '{"broken":');

        try {
            $store->load($key);
            self::fail('Corrupted JSON must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'corrupted',
                $exception->getMessage()
            );
        }

        self::assertSame(
            '{"broken":',
            file_get_contents($file)
        );
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
