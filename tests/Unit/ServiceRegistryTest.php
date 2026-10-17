<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Container\ServiceRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class ServiceRegistryTest extends TestCase
{
    public function testSetHasGetAndAll(): void
    {
        $registry = new ServiceRegistry();
        $service = new stdClass();

        $registry->set(stdClass::class, $service);

        self::assertTrue($registry->has(stdClass::class));
        self::assertSame($service, $registry->get(stdClass::class));
        self::assertSame(
            [stdClass::class => $service],
            $registry->all()
        );
    }

    public function testSetCanReplaceExistingService(): void
    {
        $registry = new ServiceRegistry();
        $first = new stdClass();
        $second = new stdClass();

        $registry->set('service', $first);
        $registry->set('service', $second);

        self::assertSame($second, $registry->get('service'));
    }

    public function testGetFailsForUnknownService(): void
    {
        $registry = new ServiceRegistry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Serviço não registrado: missing');

        $registry->get('missing');
    }

    public function testRejectsEmptyServiceId(): void
    {
        $registry = new ServiceRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Identificador de serviço não pode ser vazio.'
        );

        $registry->set('   ', new stdClass());
    }

    public function testHasReturnsFalseForEmptyId(): void
    {
        $registry = new ServiceRegistry();

        self::assertFalse($registry->has(''));
        self::assertFalse($registry->has('   '));
    }
}
