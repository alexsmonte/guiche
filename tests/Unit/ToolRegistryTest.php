<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Tests\Fixtures\FakeTool;
use Asmpkg\Guiche\ToolRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    public function testDirectToolRegistrationMaintainsLegacyApi(): void
    {
        $tool = new FakeTool('LegacyNeutral', ['success' => true, 'value' => 'abc']);
        $registry = new ToolRegistry();

        self::assertSame($registry, $registry->register($tool));
        self::assertTrue($registry->has('LegacyNeutral'));
        self::assertSame($tool, $registry->get('LegacyNeutral'));
        self::assertSame(['LegacyNeutral'], $registry->names());
        self::assertSame(
            ['success' => true, 'value' => 'abc'],
            $registry->execute('LegacyNeutral', ['input' => 1])
        );
        self::assertSame([['input' => 1]], $tool->executions);
        self::assertSame('LegacyNeutral', $registry->definitions()[0]['name']);
    }

    public function testToolRegistrationMetadataStaysInternal(): void
    {
        $tool = new FakeTool('RegisteredNeutral');
        $registration = new ToolRegistration(
            $tool,
            ['source' => 'neutral-plugin', 'operation' => 'read']
        );
        $registry = new ToolRegistry();
        $registry->register($registration);

        self::assertSame($registration, $registry->registration('RegisteredNeutral'));
        self::assertSame($tool, $registry->get('RegisteredNeutral'));
        self::assertSame(
            ['source' => 'neutral-plugin', 'operation' => 'read'],
            $registry->metadata('RegisteredNeutral')
        );
        self::assertArrayNotHasKey('metadata', $registry->definitions()[0]);
        self::assertSame(
            ['RegisteredNeutral'],
            array_keys($registry->registrations())
        );
    }

    public function testDuplicateToolIsRejected(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('Duplicate'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate');

        $registry->register(new FakeTool('Duplicate'));
    }

    public function testMissingToolIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ToolRegistry())->get('Missing');
    }
}
