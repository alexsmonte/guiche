<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Safety;

use Asmpkg\Guiche\Execution\ToolExecutionContext;
use InvalidArgumentException;
use Throwable;

/**
 * Compila e aplica parâmetros que não podem ser controlados pela IA.
 *
 * O contrato é declarado em ToolRegistration::metadata():
 *
 * trusted_parameters => [
 *     'empresa_id' => [
 *         'source' => 'context',
 *         'path' => 'tenant_id',
 *         'visible' => false,
 *     ],
 * ]
 *
 * Fontes suportadas:
 * - ai: valor permanece controlado pela chamada da IA;
 * - context: ToolExecutionContext confiável da sessão;
 * - fixed: valor literal declarado no registro;
 * - config: valor lido de trusted_parameter_config;
 * - service: callable confiável declarado como resolver.
 *
 * @internal
 */
final class TrustedParameterBinder
{
    public const BINDINGS_KEY = 'trusted_parameters';
    public const CONFIG_KEY = 'trusted_parameter_config';

    /** @param array<mixed> $metadata */
    public function hasBindings(array $metadata): bool
    {
        return $this->bindings($metadata) !== [];
    }

    /** @param array<mixed> $metadata */
    public function requiresExecutionContext(array $metadata): bool
    {
        foreach ($this->bindings($metadata) as $binding) {
            $source = $binding['source'];

            if (
                $source === 'context'
                || $source === 'service'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna o schema que pode ser apresentado à IA.
     *
     * Parâmetros protegidos com visible=false são removidos de
     * properties e required, mas continuam no schema real da Tool,
     * usado pelo gate depois do binding.
     *
     * @param array<mixed> $parameters
     * @param array<mixed> $metadata
     * @return array<mixed>
     */
    public function visibleParameters(
        array $parameters,
        array $metadata
    ): array {
        $bindings = $this->bindings($metadata);

        if ($bindings === []) {
            return $parameters;
        }

        $properties = $parameters['properties'] ?? [];

        if (!is_array($properties)) {
            throw new InvalidArgumentException(
                'trusted_parameters exige schema de Tool com properties.'
            );
        }

        $hidden = [];

        foreach ($bindings as $parameter => $binding) {
            if (!array_key_exists($parameter, $properties)) {
                throw new InvalidArgumentException(
                    "Trusted Parameter não existe no schema da Tool: {$parameter}"
                );
            }

            $source = $binding['source'];
            $visible = $binding['visible'];

            if ($source === 'ai' && !$visible) {
                throw new InvalidArgumentException(
                    "Trusted Parameter {$parameter}: source=ai exige visible=true."
                );
            }

            if ($source !== 'ai' && !$visible) {
                $hidden[$parameter] = true;
                unset($properties[$parameter]);
            }
        }

        $parameters['properties'] = $properties;

        if (
            isset($parameters['required'])
            && is_array($parameters['required'])
        ) {
            $parameters['required'] = array_values(
                array_filter(
                    $parameters['required'],
                    static fn (mixed $parameter): bool =>
                        !isset($hidden[(string)$parameter])
                )
            );
        }

        return $parameters;
    }

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $metadata
     * @param array<mixed> $conversationContext
     * @return array<mixed>
     */
    public function bind(
        string $toolName,
        array $arguments,
        array $metadata,
        ?ToolExecutionContext $executionContext,
        array $conversationContext = []
    ): array {
        foreach (
            $this->bindings($metadata)
            as $parameter => $binding
        ) {
            $source = $binding['source'];

            if ($source === 'ai') {
                continue;
            }

            $arguments[$parameter] = match ($source) {
                'context' => $this->fromContext(
                    $toolName,
                    $parameter,
                    $binding,
                    $executionContext
                ),
                'fixed' => $this->fromFixed(
                    $toolName,
                    $parameter,
                    $binding
                ),
                'config' => $this->fromConfig(
                    $toolName,
                    $parameter,
                    $binding,
                    $metadata
                ),
                'service' => $this->fromService(
                    $toolName,
                    $parameter,
                    $binding,
                    $executionContext,
                    $conversationContext
                ),
                default => throw new InvalidArgumentException(
                    "Fonte de Trusted Parameter não suportada: {$source}"
                ),
            };
        }

        return $arguments;
    }

    /**
     * @param array<mixed> $metadata
     * @return array<string, array{
     *     source: string,
     *     visible: bool,
     *     path?: mixed,
     *     value?: mixed,
     *     resolver?: mixed
     * }>
     */
    private function bindings(array $metadata): array
    {
        $raw = $metadata[self::BINDINGS_KEY] ?? [];

        if ($raw === []) {
            return [];
        }

        if (!is_array($raw) || array_is_list($raw)) {
            throw new InvalidArgumentException(
                'trusted_parameters precisa ser um mapa associativo.'
            );
        }

        $bindings = [];

        foreach ($raw as $parameter => $definition) {
            $parameter = trim((string)$parameter);

            if ($parameter === '') {
                throw new InvalidArgumentException(
                    'Trusted Parameter possui nome vazio.'
                );
            }

            if (!is_array($definition)) {
                throw new InvalidArgumentException(
                    "Trusted Parameter {$parameter} possui definição inválida."
                );
            }

            $source = strtolower(
                trim((string)($definition['source'] ?? ''))
            );

            if (
                !in_array(
                    $source,
                    ['ai', 'context', 'fixed', 'config', 'service'],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    "Trusted Parameter {$parameter} possui source inválido."
                );
            }

            if (
                array_key_exists('visible', $definition)
                && !is_bool($definition['visible'])
            ) {
                throw new InvalidArgumentException(
                    "Trusted Parameter {$parameter}: visible precisa ser bool."
                );
            }

            $definition['source'] = $source;
            $definition['visible'] =
                $definition['visible']
                ?? ($source === 'ai');

            $bindings[$parameter] = $definition;
        }

        return $bindings;
    }

    /** @param array<mixed> $binding */
    private function fromContext(
        string $toolName,
        string $parameter,
        array $binding,
        ?ToolExecutionContext $context
    ): mixed {
        if ($context === null) {
            throw $this->unavailable(
                $toolName,
                $parameter,
                'context',
                'Contexto confiável da sessão não está disponível.'
            );
        }

        $path = trim(
            (string)($binding['path'] ?? '')
        );

        if ($path === '') {
            throw new InvalidArgumentException(
                "Trusted Parameter {$parameter}: source=context exige path."
            );
        }

        if ($path === 'session_id') {
            return $context->sessionId();
        }

        [$exists, $value] = $this->readPath(
            $context->all(),
            $path
        );

        if (!$exists) {
            throw $this->unavailable(
                $toolName,
                $parameter,
                'context',
                'Valor confiável não encontrado no ToolExecutionContext.',
                ['path' => $path]
            );
        }

        return $value;
    }

    /** @param array<mixed> $binding */
    private function fromFixed(
        string $toolName,
        string $parameter,
        array $binding
    ): mixed {
        if (!array_key_exists('value', $binding)) {
            throw $this->unavailable(
                $toolName,
                $parameter,
                'fixed',
                'Trusted Parameter fixed não possui value.'
            );
        }

        return $binding['value'];
    }

    /**
     * @param array<mixed> $binding
     * @param array<mixed> $metadata
     */
    private function fromConfig(
        string $toolName,
        string $parameter,
        array $binding,
        array $metadata
    ): mixed {
        $path = trim(
            (string)($binding['path'] ?? '')
        );

        if ($path === '') {
            throw new InvalidArgumentException(
                "Trusted Parameter {$parameter}: source=config exige path."
            );
        }

        $config = $metadata[self::CONFIG_KEY] ?? [];

        if (!is_array($config)) {
            throw new InvalidArgumentException(
                'trusted_parameter_config precisa ser array.'
            );
        }

        [$exists, $value] = $this->readPath(
            $config,
            $path
        );

        if (!$exists) {
            throw $this->unavailable(
                $toolName,
                $parameter,
                'config',
                'Valor configurado para Trusted Parameter não foi encontrado.',
                ['path' => $path]
            );
        }

        return $value;
    }

    /**
     * @param array<mixed> $binding
     * @param array<mixed> $conversationContext
     */
    private function fromService(
        string $toolName,
        string $parameter,
        array $binding,
        ?ToolExecutionContext $executionContext,
        array $conversationContext
    ): mixed {
        $resolver = $binding['resolver'] ?? null;

        if (!is_callable($resolver)) {
            throw new InvalidArgumentException(
                "Trusted Parameter {$parameter}: source=service exige resolver callable."
            );
        }

        try {
            return $resolver(
                $executionContext,
                $conversationContext,
                $toolName,
                $parameter
            );
        } catch (Throwable $exception) {
            throw $this->unavailable(
                $toolName,
                $parameter,
                'service',
                'Service resolver falhou ao produzir Trusted Parameter.',
                [
                    'exception_class' =>
                        $exception::class,
                ],
                $exception
            );
        }
    }

    /**
     * @param array<mixed> $values
     * @return array{0: bool, 1: mixed}
     */
    private function readPath(
        array $values,
        string $path
    ): array {
        $value = $values;

        foreach (explode('.', $path) as $segment) {
            $segment = trim($segment);

            if (
                $segment === ''
                || !is_array($value)
                || !array_key_exists($segment, $value)
            ) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }

    /**
     * @param array<mixed> $details
     */
    private function unavailable(
        string $toolName,
        string $parameter,
        string $source,
        string $message,
        array $details = [],
        ?Throwable $previous = null
    ): ToolBlockedException {
        return new ToolBlockedException(
            $toolName,
            'trusted_parameter_unavailable',
            $message,
            array_merge(
                [
                    'parameter' => $parameter,
                    'source' => $source,
                ],
                $details
            ),
            $previous
        );
    }
}
