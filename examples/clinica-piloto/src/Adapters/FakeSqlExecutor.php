<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Adapters;

use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use RuntimeException;

final class FakeSqlExecutor implements SqlExecutorInterface
{
    private array $calls = [];

    public function __construct(private readonly array $fixtures)
    {
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        $this->calls[] = ['sql' => $sql, 'parameters' => $parameters];

        if (stripos($sql, 'information_schema.PARAMETERS') !== false) {
            $procedure = trim((string)($parameters[0] ?? ''));

            return $this->rows(
                $this->fixtures['procedure_parameters'][$procedure] ?? []
            );
        }

        if (preg_match('/\bCALL\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches) !== 1) {
            throw new RuntimeException(
                'FakeSqlExecutor aceita somente CALL de fixture ou metadados de procedure.'
            );
        }

        $procedure = (string)$matches[1];

        if (!array_key_exists($procedure, $this->fixtures['sql'] ?? [])) {
            throw new RuntimeException(
                sprintf('Fixture SQL não configurada para "%s".', $procedure)
            );
        }

        return $this->rows($this->fixtures['sql'][$procedure]);
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->calls[] = ['sql' => $sql, 'parameters' => $parameters];

        return 1;
    }

    public function calls(): array
    {
        return $this->calls;
    }

    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Fixture SQL deve ser array.');
        }

        return array_values($value);
    }
}
