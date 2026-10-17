<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\GuicheBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class GuicheBuilderServiceRegistryTest extends TestCase
{
    public function testBuilderRegistersAndResolvesSharedService(): void
    {
        $service = new stdClass();

        $builder = (new GuicheBuilder())
            ->setService(stdClass::class, $service);

        self::assertTrue($builder->hasService(stdClass::class));
        self::assertSame(
            $service,
            $builder->getService(stdClass::class)
        );
    }

    public function testBuilderReportsMissingService(): void
    {
        $builder = new GuicheBuilder();

        self::assertFalse($builder->hasService('missing'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Serviço não registrado: missing'
        );

        $builder->getService('missing');
    }
}
