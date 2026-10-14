<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\PresenterRegistry;
use Asmpkg\Guiche\Tests\Fixtures\FakePresenter;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PresenterRegistryTest extends TestCase
{
    public function testRegistersResolvesPresentsAndLists(): void
    {
        $presenter = new FakePresenter('Presented: test');
        $registry = new PresenterRegistry();

        self::assertSame($registry, $registry->register('Echo', $presenter));
        self::assertTrue($registry->has('Echo'));
        self::assertSame($presenter, $registry->get('Echo'));
        self::assertSame('Presented: test', $registry->present('Echo', ['value' => 'test']));
        self::assertSame([['value' => 'test']], $presenter->calls);
        self::assertSame(['Echo'], $registry->names());
        self::assertSame(['Echo' => $presenter], $registry->all());
    }

    public function testMissingPresenterIsOptionalForPresent(): void
    {
        self::assertNull(
            (new PresenterRegistry())->present('Missing', ['success' => true])
        );
    }

    public function testMissingPresenterGetIsExplicitFailure(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PresenterRegistry())->get('Missing');
    }

    public function testDuplicatePresenterIsRejected(): void
    {
        $registry = new PresenterRegistry();
        $registry->register('Echo', new FakePresenter());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Echo');

        $registry->register('Echo', new FakePresenter());
    }

    public function testBlankToolNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PresenterRegistry())->register('   ', new FakePresenter());
    }
}
