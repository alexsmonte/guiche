<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Contracts;

use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\ConversationStoreInterface;
use Asmpkg\Guiche\Contracts\ScheduledEventStoreInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ConversationContractsTest extends TestCase
{
    public function testConversationStoreContractIsStable(): void
    {
        self::assertTrue(
            interface_exists(ConversationStoreInterface::class)
        );

        foreach (['load', 'mutate', 'delete'] as $method) {
            self::assertTrue(
                method_exists(
                    ConversationStoreInterface::class,
                    $method
                ),
                $method
            );
        }

        self::assertSame(
            2,
            (new ReflectionMethod(
                ConversationStoreInterface::class,
                'mutate'
            ))->getNumberOfParameters()
        );
    }

    public function testClockContractReturnsDateTimeImmutable(): void
    {
        $method = new ReflectionMethod(
            ClockInterface::class,
            'now'
        );

        self::assertSame(
            DateTimeImmutable::class,
            (string) $method->getReturnType()
        );
    }

    public function testScheduledEventStoreContractIsAvailable(): void
    {
        self::assertTrue(
            interface_exists(ScheduledEventStoreInterface::class)
        );

        foreach ([
            'schedule',
            'find',
            'claimDue',
            'renewLease',
            'complete',
            'cancel',
            'markStale',
            'markIndeterminate',
        ] as $method) {
            self::assertTrue(
                method_exists(
                    ScheduledEventStoreInterface::class,
                    $method
                ),
                $method
            );
        }
    }
}
