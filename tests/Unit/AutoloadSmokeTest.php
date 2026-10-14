<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ClockInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\RuntimeContext;
use Asmpkg\Guiche\ToolPolicy;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class AutoloadSmokeTest extends TestCase
{
    public function testCoreAndContractsRemainAutoloadable(): void
    {
        $classes = [
            Guiche::class,
            GuicheBuilder::class,
            RuntimeContext::class,
            ToolPolicy::class,
            ToolRegistry::class,
        ];

        foreach ($classes as $class) {
            self::assertTrue(class_exists($class), $class . ' must be autoloadable');
        }

        $interfaces = [
            AiProviderInterface::class,
            ClockInterface::class,
            StateManagerInterface::class,
            ToolInterface::class,
        ];

        foreach ($interfaces as $interface) {
            self::assertTrue(interface_exists($interface), $interface . ' must be autoloadable');
        }
    }
}
