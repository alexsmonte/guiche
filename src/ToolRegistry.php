<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use InvalidArgumentException;
use LogicException;

/**
 * Registro central de ferramentas disponíveis no runtime.
 *
 * Compatibilidade:
 * - continua aceitando ToolInterface diretamente, como na v0.2;
 * - passa a aceitar ToolRegistration para carregar metadados declarativos.
 *
 * Regra de conflito:
 * nomes duplicados são rejeitados explicitamente. Um plugin não pode
 * sobrescrever silenciosamente uma Tool registrada anteriormente.
 */
final class ToolRegistry
{
    /** @var array<string, ToolRegistration> */
    private array $registrations = [];

    public function register(ToolInterface|ToolRegistration $tool): self
    {
        $registration = $tool instanceof ToolRegistration
            ? $tool
            : new ToolRegistration($tool);

        $name = $registration->name();

        if (isset($this->registrations[$name])) {
            throw new LogicException(
                "Conflito de ferramenta: '{$name}' já está registrada."
            );
        }

        $this->registrations[$name] = $registration;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->registrations[$name]);
    }

    public function get(string $name): ToolInterface
    {
        return $this->registration($name)->tool();
    }

    public function registration(string $name): ToolRegistration
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException(
                "Ferramenta não registrada: {$name}"
            );
        }

        return $this->registrations[$name];
    }

    public function execute(string $name, array $arguments = []): array
    {
        return $this->get($name)->execute($arguments);
    }

    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->registrations as $registration) {
            $definitions[] = $registration->definition();
        }

        return $definitions;
    }

    public function names(): array
    {
        return array_keys($this->registrations);
    }

    /**
     * @return array<string, ToolRegistration>
     */
    public function registrations(): array
    {
        return $this->registrations;
    }

    public function metadata(string $name): array
    {
        return $this->registration($name)->metadata();
    }
}
