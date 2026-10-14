<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Contracts;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use Asmpkg\Guiche\Contracts\StateManagerInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Guiche;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class CoreExtensionContractsTest extends TestCase
{
    public function testPublicExtensionContractsRemainAvailable(): void
    {
        foreach ([
            AiProviderInterface::class,
            ContextStoreInterface::class,
            PluginInterface::class,
            ResultPresenterInterface::class,
            StateManagerInterface::class,
            ToolInterface::class,
        ] as $interface) {
            self::assertTrue(interface_exists($interface), $interface);
        }
    }

    public function testGuicheDependsOnStateManagerInterface(): void
    {
        $constructor = (new ReflectionClass(Guiche::class))->getConstructor();
        self::assertNotNull($constructor);

        $parameter = null;

        foreach ($constructor->getParameters() as $candidate) {
            if ($candidate->getName() === 'stateManager') {
                $parameter = $candidate;
                break;
            }
        }

        self::assertNotNull($parameter);

        $type = $parameter->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(StateManagerInterface::class, $type->getName());
        self::assertTrue($type->allowsNull());
    }
}
