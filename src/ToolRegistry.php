<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use Asmpkg\Guiche\Contracts\ToolInterface;
use InvalidArgumentException;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): self
    {
        $name = trim($tool->name());

        if ($name === '') {
            throw new InvalidArgumentException(
                'A ferramenta precisa possuir um nome.'
            );
        }

        $this->tools[$name] = $tool;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ToolInterface
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException(
                "Ferramenta não registrada: {$name}"
            );
        }

        return $this->tools[$name];
    }

    public function execute(string $name, array $arguments = []): array
    {
        return $this->get($name)->execute($arguments);
    }

    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ];
        }

        return $definitions;
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }
}
