<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Contracts;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;
use Asmpkg\Guiche\Observability\JsonlRuntimeEventSink;
use Asmpkg\Guiche\Observability\NullRuntimeEventSink;
use PHPUnit\Framework\TestCase;

final class PersistenceContractsTest extends TestCase
{
    public function testContextStoreImplementsFullContract(): void
    {
        self::assertTrue(
            is_subclass_of(ContextStore::class, ContextStoreInterface::class)
        );

        foreach ([
            'load',
            'save',
            'delete',
            'addMessage',
            'setIntent',
            'mergeState',
            'applyPatch',
            'addToolExecution',
            'findSuccessfulToolResult',
            'recordInteractionUsage',
            'toolFingerprint',
        ] as $method) {
            self::assertTrue(
                method_exists(ContextStoreInterface::class, $method),
                $method
            );
        }
    }

    public function testRuntimeEventSinksImplementContract(): void
    {
        self::assertTrue(
            is_subclass_of(
                NullRuntimeEventSink::class,
                RuntimeEventSinkInterface::class
            )
        );
        self::assertTrue(
            is_subclass_of(
                JsonlRuntimeEventSink::class,
                RuntimeEventSinkInterface::class
            )
        );
    }
}
