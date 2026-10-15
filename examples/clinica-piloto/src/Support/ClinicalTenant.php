<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Support;

use Asmpkg\Guiche\Execution\ToolExecutionContext;
use RuntimeException;

final class ClinicalTenant
{
    public static function companyId(
        ToolExecutionContext $context
    ): int {
        $tenantId = $context->tenantId();

        if (
            is_int($tenantId)
            && $tenantId > 0
        ) {
            return $tenantId;
        }

        if (
            is_string($tenantId)
            && ctype_digit($tenantId)
            && (int)$tenantId > 0
        ) {
            return (int)$tenantId;
        }

        throw new RuntimeException(
            'Tenant clínico inválido: empresa_id precisa ser inteiro positivo.'
        );
    }
}
