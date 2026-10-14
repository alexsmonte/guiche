<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Contracts\ConversationStoreInterface;
use JsonException;
use RuntimeException;
use Throwable;

final class JsonConversationStore implements ConversationStoreInterface
{
    public function __construct(
        private readonly string $directory
    ) {
        if (trim($this->directory) === '') {
            throw new RuntimeException(
                'Conversation store directory cannot be empty.'
            );
        }

        if (
            !is_dir($this->directory)
            && !mkdir($this->directory, 0770, true)
            && !is_dir($this->directory)
        ) {
            throw new RuntimeException(
                'Unable to create conversation store directory.'
            );
        }
    }

    public function load(string $conversationKey): array
    {
        $this->assertKey($conversationKey);

        $lock = $this->openLock($conversationKey);

        try {
            if (!flock($lock, LOCK_SH)) {
                throw new RuntimeException(
                    'Unable to acquire conversation read lock.'
                );
            }

            return $this->readUnlocked($conversationKey);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function mutate(
        string $conversationKey,
        callable $mutation
    ): array {
        $this->assertKey($conversationKey);

        $lock = $this->openLock($conversationKey);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(
                    'Unable to acquire conversation write lock.'
                );
            }

            $current = $this->readUnlocked($conversationKey);
            $next = $mutation($current);

            if (!is_array($next)) {
                throw new RuntimeException(
                    'Conversation mutation must return array.'
                );
            }

            $this->writeUnlocked($conversationKey, $next);

            return $next;
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function delete(string $conversationKey): void
    {
        $this->assertKey($conversationKey);

        $lock = $this->openLock($conversationKey);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(
                    'Unable to acquire conversation delete lock.'
                );
            }

            $path = $this->dataPath($conversationKey);

            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException(
                    'Unable to delete conversation data.'
                );
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readUnlocked(string $conversationKey): array
    {
        $path = $this->dataPath($conversationKey);

        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);

        if ($json === false || trim($json) === '') {
            throw new RuntimeException(
                'Conversation data is empty or unreadable.'
            );
        }

        try {
            $data = json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Conversation data is corrupted.',
                0,
                $exception
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(
                'Conversation data must decode to array.'
            );
        }

        return $data;
    }

    private function writeUnlocked(
        string $conversationKey,
        array $data
    ): void {
        try {
            $json = json_encode(
                $data,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Conversation data cannot be encoded.',
                0,
                $exception
            );
        }

        $path = $this->dataPath($conversationKey);
        $temp = $path . '.tmp.' . bin2hex(random_bytes(8));

        $handle = @fopen($temp, 'xb');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create conversation temp file.'
            );
        }

        try {
            $payload = $json . PHP_EOL;
            $offset = 0;
            $length = strlen($payload);

            while ($offset < $length) {
                $written = fwrite(
                    $handle,
                    substr($payload, $offset)
                );

                if ($written === false || $written === 0) {
                    throw new RuntimeException(
                        'Unable to write conversation temp file.'
                    );
                }

                $offset += $written;
            }

            if (!fflush($handle)) {
                throw new RuntimeException(
                    'Unable to flush conversation temp file.'
                );
            }

            if (function_exists('fsync')) {
                @fsync($handle);
            }

            fclose($handle);
            $handle = null;

            if (!rename($temp, $path)) {
                throw new RuntimeException(
                    'Unable to publish conversation data atomically.'
                );
            }
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($temp);

            throw $exception;
        }
    }

    /**
     * @return resource
     */
    private function openLock(string $conversationKey)
    {
        $path = $this->lockPath($conversationKey);
        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to open conversation lock file.'
            );
        }

        return $handle;
    }

    private function dataPath(string $conversationKey): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $conversationKey)
            . '.json';
    }

    private function lockPath(string $conversationKey): string
    {
        return $this->directory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $conversationKey)
            . '.lock';
    }

    private function assertKey(string $conversationKey): void
    {
        if (trim($conversationKey) === '') {
            throw new RuntimeException(
                'conversationKey cannot be empty.'
            );
        }
    }
}
