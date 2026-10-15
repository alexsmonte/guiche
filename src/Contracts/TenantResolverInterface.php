<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

/**
 * Resolve o contexto multi-tenant a partir de identidade externa confiável.
 *
 * A implementação pode usar SELECT, procedure, API ou repository.
 * O resolver não recebe argumentos escolhidos pela IA.
 */
interface TenantResolverInterface
{
    /**
     * @param array<mixed> $identity Identidade externa autenticada/resolvida pelo host.
     * @return array<mixed> Contexto de execução persistível, incluindo tenant_id.
     */
    public function resolve(array $identity): array;
}
