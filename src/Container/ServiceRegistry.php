<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Container;

use InvalidArgumentException;
use RuntimeException;

final class ServiceRegistry
{
    /** @var array<string, object> */
    private array $services = [];

    public function set(string $id, object $service): void
    {
        $id = $this->normalizeId($id);

        $this->services[$id] = $service;
    }

    public function has(string $id): bool
    {
        $id = trim($id);

        return $id !== '' && isset($this->services[$id]);
    }

    public function get(string $id): object
    {
        $id = $this->normalizeId($id);

        if (!isset($this->services[$id])) {
            throw new RuntimeException(
                "Serviço não registrado: {$id}"
            );
        }

        return $this->services[$id];
    }

    /**
     * @return array<string, object>
     */
    public function all(): array
    {
        return $this->services;
    }

    private function normalizeId(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            throw new InvalidArgumentException(
                'Identificador de serviço não pode ser vazio.'
            );
        }

        return $id;
    }
}
