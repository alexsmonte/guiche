<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Contracts;

use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class DynamicMultitenantContextContractsTest extends TestCase
{
    public function testLegacyToolContractRemainsUnchanged(): void
    {
        $method = (new ReflectionClass(ToolInterface::class))
            ->getMethod('execute');

        self::assertCount(1, $method->getParameters());
        self::assertSame('arguments', $method->getParameters()[0]->getName());

        $type = $method->getParameters()[0]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('array', $type->getName());

        $returnType = $method->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
    }

    public function testContextAwareToolExtendsLegacyToolContract(): void
    {
        self::assertTrue(interface_exists(ContextAwareToolInterface::class));
        self::assertTrue(
            is_subclass_of(
                ContextAwareToolInterface::class,
                ToolInterface::class
            )
        );

        $method = (new ReflectionClass(ContextAwareToolInterface::class))
            ->getMethod('executeWithContext');

        self::assertCount(2, $method->getParameters());
        self::assertSame('arguments', $method->getParameters()[0]->getName());
        self::assertSame('context', $method->getParameters()[1]->getName());

        $contextType = $method->getParameters()[1]->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $contextType);
        self::assertSame(ToolExecutionContext::class, $contextType->getName());
    }

    public function testTenantResolverContractIsGenericAndHostDriven(): void
    {
        self::assertTrue(interface_exists(TenantResolverInterface::class));

        $method = (new ReflectionClass(TenantResolverInterface::class))
            ->getMethod('resolve');

        self::assertCount(1, $method->getParameters());

        $inputType = $method->getParameters()[0]->getType();
        $returnType = $method->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $inputType);
        self::assertSame('array', $inputType->getName());
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('array', $returnType->getName());
    }

    public function testToolExecutionContextKeepsSessionAndValues(): void
    {
        $context = new ToolExecutionContext(
            '  session-123  ',
            [
                'tenant_id' => 142,
                'empresa_id' => 142,
                'channel' => 'whatsapp',
            ]
        );

        self::assertSame('session-123', $context->sessionId());
        self::assertSame(142, $context->tenantId());
        self::assertTrue($context->has('empresa_id'));
        self::assertSame(142, $context->get('empresa_id'));
        self::assertSame('fallback', $context->get('missing', 'fallback'));
        self::assertSame(
            [
                'tenant_id' => 142,
                'empresa_id' => 142,
                'channel' => 'whatsapp',
            ],
            $context->all()
        );
    }

    public function testToolExecutionContextAcceptsStringTenantId(): void
    {
        $context = new ToolExecutionContext(
            'session-string',
            ['tenant_id' => ' tenant-a ']
        );

        self::assertSame('tenant-a', $context->tenantId());
    }

    public function testToolExecutionContextDoesNotInventTenant(): void
    {
        $context = new ToolExecutionContext('session-without-tenant');

        self::assertNull($context->tenantId());
        self::assertFalse($context->has('tenant_id'));
    }

    public function testToolExecutionContextRejectsEmptySession(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolExecutionContext('   ');
    }
}
