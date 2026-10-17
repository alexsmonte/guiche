<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Container\ClassResolver;
use Asmpkg\Guiche\Container\ServiceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

interface ResolverDependencyInterface
{
}

final class ResolverDependency implements ResolverDependencyInterface
{
}

final class ResolverWithoutConstructor
{
}

final class ResolverNeedsDependency
{
    public function __construct(
        public readonly ResolverDependencyInterface $dependency
    ) {
    }
}

final class ResolverWithDefault
{
    public function __construct(
        public readonly int $limit = 10
    ) {
    }
}

final class ResolverWithNullable
{
    public function __construct(
        public readonly ?ResolverDependencyInterface $dependency
    ) {
    }
}

final class ResolverWithRequiredBuiltin
{
    public function __construct(
        public readonly string $dsn
    ) {
    }
}

final class ResolverWithUntypedRequired
{
    public mixed $value;

    public function __construct($value)
    {
        $this->value = $value;
    }
}

final class ResolverWithUntypedDefault
{
    public mixed $value;

    public function __construct($value = 'default')
    {
        $this->value = $value;
    }
}

final class ResolverDependencyA
{
}

final class ResolverDependencyB
{
}

final class ResolverWithUnion
{
    public function __construct(
        public readonly ResolverDependencyA|ResolverDependencyB $dependency
    ) {
    }
}

abstract class ResolverAbstractClass
{
}

final class ClassResolverTest extends TestCase
{
    public function testResolvesClassWithoutConstructor(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        self::assertInstanceOf(
            ResolverWithoutConstructor::class,
            $resolver->resolve(ResolverWithoutConstructor::class)
        );
    }

    public function testInjectsRegisteredServiceByDeclaredType(): void
    {
        $services = new ServiceRegistry();
        $dependency = new ResolverDependency();

        $services->set(
            ResolverDependencyInterface::class,
            $dependency
        );

        $resolver = new ClassResolver($services);

        $instance = $resolver->resolve(
            ResolverNeedsDependency::class
        );

        self::assertInstanceOf(
            ResolverNeedsDependency::class,
            $instance
        );
        self::assertSame(
            $dependency,
            $instance->dependency
        );
    }

    public function testUsesConstructorDefault(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $instance = $resolver->resolve(
            ResolverWithDefault::class
        );

        self::assertSame(10, $instance->limit);
    }

    public function testNullableDependencyFallsBackToNull(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $instance = $resolver->resolve(
            ResolverWithNullable::class
        );

        self::assertNull($instance->dependency);
    }

    public function testRegisteredNullableDependencyWinsOverNull(): void
    {
        $services = new ServiceRegistry();
        $dependency = new ResolverDependency();

        $services->set(
            ResolverDependencyInterface::class,
            $dependency
        );

        $resolver = new ClassResolver($services);

        $instance = $resolver->resolve(
            ResolverWithNullable::class
        );

        self::assertSame(
            $dependency,
            $instance->dependency
        );
    }

    public function testUntypedDefaultIsSupported(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $instance = $resolver->resolve(
            ResolverWithUntypedDefault::class
        );

        self::assertSame('default', $instance->value);
    }

    public function testFailsWhenClassDoesNotExist(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Classe não encontrada: App\\Missing\\UnknownClass'
        );

        $resolver->resolve(
            'App\\Missing\\UnknownClass'
        );
    }

    public function testFailsForNonInstantiableClass(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Classe não instanciável: '
            . ResolverAbstractClass::class
        );

        $resolver->resolve(
            ResolverAbstractClass::class
        );
    }

    public function testFailsForMissingObjectDependency(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Registre a dependência com GuicheBuilder::setService().'
        );

        $resolver->resolve(
            ResolverNeedsDependency::class
        );
    }

    public function testFailsForRequiredBuiltinParameter(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('$dsn (string)');

        $resolver->resolve(
            ResolverWithRequiredBuiltin::class
        );
    }

    public function testFailsForUntypedRequiredParameter(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('$value (sem tipo)');

        $resolver->resolve(
            ResolverWithUntypedRequired::class
        );
    }

    public function testDoesNotAutowireUnregisteredObjectRecursively(): void
    {
        $resolver = new ClassResolver(
            new ServiceRegistry()
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            ResolverDependencyInterface::class
        );

        $resolver->resolve(
            ResolverNeedsDependency::class
        );
    }

    public function testFailsForUnresolvableUnionType(): void
    {
        $services = new ServiceRegistry();
        $services->set(
            ResolverDependencyA::class,
            new ResolverDependencyA()
        );

        $resolver = new ClassResolver($services);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            ResolverWithUnion::class
        );

        $resolver->resolve(
            ResolverWithUnion::class
        );
    }
}
