<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tenant;

use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use InvalidArgumentException;
use RuntimeException;

final class SqlTenantResolver implements TenantResolverInterface
{
    /**
     * @param array<string, string> $identityParameterMap
     */
    public function __construct(
        private readonly SqlExecutorInterface $db,
        private readonly string $sql,
        private readonly array $identityParameterMap,
        private readonly string $tenantIdField = 'tenant_id'
    ) {
        if (trim($this->sql) === '') {
            throw new InvalidArgumentException(
                'SQL de resolução de tenant não pode ser vazio.'
            );
        }

        if ($this->identityParameterMap === []) {
            throw new InvalidArgumentException(
                'identityParameterMap não pode ser vazio.'
            );
        }

        if (trim($this->tenantIdField) === '') {
            throw new InvalidArgumentException(
                'tenantIdField não pode ser vazio.'
            );
        }

        foreach (
            $this->identityParameterMap as $parameter => $identityPath
        ) {
            if (
                trim($parameter) === ''
                || trim($identityPath) === ''
            ) {
                throw new InvalidArgumentException(
                    'identityParameterMap contém entrada inválida.'
                );
            }
        }
    }

    /**
     * @param array<mixed> $identity
     * @return array<mixed>
     */
    public function resolve(array $identity): array
    {
        $parameters = [];

        foreach (
            $this->identityParameterMap as $parameter => $identityPath
        ) {
            $parameters[$parameter] = $this->readIdentityPath(
                $identity,
                $identityPath
            );
        }

        $rows = $this->db->fetchAll(
            $this->sql,
            $parameters
        );

        if ($rows === []) {
            return [];
        }

        if (count($rows) !== 1) {
            throw new RuntimeException(
                'Resolução SQL de tenant retornou resultado ambíguo.'
            );
        }

        $row = $rows[0];

        if (!array_key_exists($this->tenantIdField, $row)) {
            throw new RuntimeException(
                "Campo {$this->tenantIdField} ausente na resolução SQL de tenant."
            );
        }

        return [
            'tenant_id' => $row[$this->tenantIdField],
        ];
    }

    /**
     * @param array<mixed> $identity
     */
    private function readIdentityPath(
        array $identity,
        string $path
    ): mixed {
        $current = $identity;

        foreach (explode('.', $path) as $segment) {
            if (
                !is_array($current)
                || !array_key_exists($segment, $current)
            ) {
                throw new RuntimeException(
                    "Identidade não contém o caminho obrigatório: {$path}."
                );
            }

            $current = $current[$segment];
        }

        if (
            is_array($current)
            || is_object($current)
            || is_resource($current)
        ) {
            throw new RuntimeException(
                "Identidade contém valor não escalar no caminho: {$path}."
            );
        }

        return $current;
    }
}
