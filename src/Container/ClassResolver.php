<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Container;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;
use Throwable;

final class ClassResolver
{
    public function __construct(
        private readonly ServiceRegistry $services
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function resolve(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException(
                "Classe não encontrada: {$class}"
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException(
                "Classe não instanciável: {$class}"
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            /** @var T */
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter(
                $class,
                $parameter
            );
        }

        try {
            /** @var T */
            return $reflection->newInstanceArgs($arguments);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Não foi possível instanciar a classe: {$class}",
                0,
                $exception
            );
        }
    }

    private function resolveParameter(
        string $class,
        ReflectionParameter $parameter
    ): mixed {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType) {
            if (!$type->isBuiltin()) {
                $serviceId = $type->getName();

                if ($this->services->has($serviceId)) {
                    return $this->services->get($serviceId);
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($type->allowsNull()) {
                return null;
            }

            $this->throwUnresolvableParameter(
                $class,
                $parameter,
                $type->getName()
            );
        }

        if (
            $type instanceof ReflectionUnionType
            || $type instanceof ReflectionIntersectionType
        ) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            if ($type->allowsNull()) {
                return null;
            }

            $this->throwUnresolvableParameter(
                $class,
                $parameter,
                (string)$type
            );
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $this->throwUnresolvableParameter(
            $class,
            $parameter,
            'sem tipo'
        );
    }

    /**
     * @return never
     */
    private function throwUnresolvableParameter(
        string $class,
        ReflectionParameter $parameter,
        string $type
    ): never {
        $name = $parameter->getName();

        throw new RuntimeException(
            "Não foi possível resolver o parâmetro \${$name} "
            . "({$type}) de {$class}. "
            . 'Registre a dependência com GuicheBuilder::setService().'
        );
    }
}
