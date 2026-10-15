<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tenant;

use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use Asmpkg\Guiche\Execution\ExecutionContextSeeder;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use InvalidArgumentException;
use RuntimeException;

final class TenantResolutionService
{
    private ExecutionContextSeeder $seeder;

    public function __construct(
        private readonly ContextStoreInterface $contextStore,
        private readonly TenantResolverInterface $resolver,
        ?ExecutionContextSeeder $seeder = null
    ) {
        $this->seeder = $seeder
            ?? new ExecutionContextSeeder($this->contextStore);
    }

    public function resolve(
        string $sessionId
    ): ToolExecutionContext {
        $sessionId = trim($sessionId);

        if ($sessionId === '') {
            throw new InvalidArgumentException(
                'sessionId não pode ser vazio.'
            );
        }

        $stored = $this->contextStore->load($sessionId);
        $executionContext =
            $stored['execution_context'] ?? [];

        if (!is_array($executionContext)) {
            throw new RuntimeException(
                'execution_context persistido é inválido.'
            );
        }

        $identity = $executionContext['identity'] ?? null;

        if (
            !is_array($identity)
            || $identity === []
            || array_is_list($identity)
        ) {
            throw new RuntimeException(
                "Identidade confiável ausente para a sessão {$sessionId}."
            );
        }

        if (array_key_exists('tenant_id', $executionContext)) {
            $tenantId = $this->normalizeTenantId(
                $executionContext['tenant_id']
            );

            return new ToolExecutionContext(
                $sessionId,
                $executionContext + ['tenant_id' => $tenantId]
            );
        }

        /*
         * Somente identity é entregue ao resolver.
         *
         * State, mensagens, argumentos de Tool e qualquer conteúdo
         * produzido pela IA ficam fora da fronteira de resolução.
         */
        $resolved = $this->resolver->resolve($identity);

        if (!array_key_exists('tenant_id', $resolved)) {
            throw new RuntimeException(
                "Tenant não resolvido para a sessão {$sessionId}."
            );
        }

        $tenantId = $this->normalizeTenantId(
            $resolved['tenant_id']
        );

        /*
         * Persistimos somente tenant_id.
         *
         * Campos extras retornados por banco/API/repository não são
         * automaticamente promovidos a execution_context.
         */
        return $this->seeder->seed(
            $sessionId,
            [
                'identity' => $identity,
                'tenant_id' => $tenantId,
            ]
        );
    }

    private function normalizeTenantId(
        mixed $tenantId
    ): string|int {
        if (is_int($tenantId)) {
            return $tenantId;
        }

        if (is_string($tenantId)) {
            $tenantId = trim($tenantId);

            if ($tenantId !== '') {
                return $tenantId;
            }
        }

        throw new RuntimeException(
            'tenant_id resolvido é inválido.'
        );
    }
}
