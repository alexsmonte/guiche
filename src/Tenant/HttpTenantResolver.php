<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tenant;

use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use InvalidArgumentException;
use RuntimeException;

final class HttpTenantResolver implements TenantResolverInterface
{
    /**
     * @param array<string, string> $identityRequestMap
     * @param array<mixed> $headers
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $url,
        private readonly array $identityRequestMap,
        private readonly string $tenantIdPath = 'tenant_id',
        private readonly array $headers = [],
        private readonly int $timeout = 15
    ) {
        if (trim($this->url) === '') {
            throw new InvalidArgumentException(
                'URL de resolução de tenant não pode ser vazia.'
            );
        }

        if ($this->identityRequestMap === []) {
            throw new InvalidArgumentException(
                'identityRequestMap não pode ser vazio.'
            );
        }

        if (trim($this->tenantIdPath) === '') {
            throw new InvalidArgumentException(
                'tenantIdPath não pode ser vazio.'
            );
        }

        if ($this->timeout <= 0) {
            throw new InvalidArgumentException(
                'timeout precisa ser maior que zero.'
            );
        }

        foreach (
            $this->identityRequestMap as $field => $identityPath
        ) {
            if (
                trim($field) === ''
                || trim($identityPath) === ''
            ) {
                throw new InvalidArgumentException(
                    'identityRequestMap contém entrada inválida.'
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
        $data = [];

        foreach (
            $this->identityRequestMap as $field => $identityPath
        ) {
            $data[$field] = $this->readPath(
                $identity,
                $identityPath,
                'Identidade'
            );
        }

        $response = $this->http->postForm(
            $this->url,
            $data,
            $this->headers,
            $this->timeout
        );

        $tenantId = $this->readPath(
            $response,
            $this->tenantIdPath,
            'Resposta da API'
        );

        return [
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * @param array<mixed> $source
     */
    private function readPath(
        array $source,
        string $path,
        string $label
    ): mixed {
        $current = $source;

        foreach (explode('.', $path) as $segment) {
            if (
                !is_array($current)
                || !array_key_exists($segment, $current)
            ) {
                throw new RuntimeException(
                    "{$label} não contém o caminho obrigatório: {$path}."
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
                "{$label} contém valor não escalar no caminho: {$path}."
            );
        }

        return $current;
    }
}
