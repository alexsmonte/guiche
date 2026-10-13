<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Plugin;

use Asmpkg\Guiche\Contracts\ToolInterface;
use InvalidArgumentException;

/**
 * Registro imutável de uma Tool e seus metadados declarativos.
 *
 * Os metadados são apenas transportados na v0.3.
 * Eles não autorizam nem bloqueiam execução nesta fase.
 */
final class ToolRegistration
{
    public function __construct(
        private readonly ToolInterface $tool,
        private readonly array $metadata = []
    ) {
        if (trim($this->tool->name()) === '') {
            throw new InvalidArgumentException('A ferramenta precisa possuir um nome.');
        }
    }

    public function tool(): ToolInterface
    {
        return $this->tool;
    }

    public function name(): string
    {
        return trim($this->tool->name());
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->tool->description(),
            'parameters' => $this->tool->parameters(),
        ];
    }
}
