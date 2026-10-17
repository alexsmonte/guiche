<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Plugin;

use Asmpkg\Guiche\Cache\ToolReusePolicy;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Safety\TrustedParameterBinder;
use InvalidArgumentException;

/**
 * Registro imutável de uma Tool e seus metadados declarativos.
 *
 * Os metadados são apenas transportados na v0.3.
 * Eles não autorizam nem bloqueiam execução nesta fase.
 */
final class ToolRegistration
{
    /**
     * @param array<mixed> $metadata
     */
    public function __construct(
        private readonly ToolInterface $tool,
        private readonly array $metadata = []
    ) {
        if (trim($this->tool->name()) === '') {
            throw new InvalidArgumentException('A ferramenta precisa possuir um nome.');
        }

        /*
         * Valida cedo a configuração de Trusted Parameters.
         * Tool sem trusted_parameters mantém comportamento legado.
         */
        (new TrustedParameterBinder())->visibleParameters(
            $this->tool->parameters(),
            $this->metadata
        );

        /*
         * Reuso contextual também é validado no registro.
         * Ausência de metadata reuse preserva semântica legada.
         */
        (new ToolReusePolicy())->dependencyPaths(
            $this->metadata
        );
    }

    public function tool(): ToolInterface
    {
        return $this->tool;
    }

    public function name(): string
    {
        return trim($this->tool->name());
    }

    /**
     * @return array<mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->tool->description(),
            'parameters' =>
                (new TrustedParameterBinder())
                    ->visibleParameters(
                        $this->tool->parameters(),
                        $this->metadata
                    ),
        ];
    }
}
