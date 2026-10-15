<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Safety;

use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use GuicheExample\ClinicaPiloto\Tools\ConsultarHorariosTool;
use Throwable;

final class ClinicalSlotRevalidator
{
    public function __construct(
        private readonly SqlExecutorInterface $sql,
        private readonly string $procedure,
        private readonly int $companyId = 0,
        private readonly int $hoursPerDate = 3
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{available: bool, reason: string}
     */
    public function revalidate(
        array $arguments,
        ?int $companyId = null
    ): array {
        $effectiveCompanyId =
            ($companyId ?? 0) > 0
                ? (int)$companyId
                : $this->companyId;

        $date = trim((string)($arguments['data_inicio'] ?? ''));
        $time = trim((string)($arguments['hora_inicio'] ?? ''));
        $professionalId = (int)($arguments['profissional_id'] ?? 0);

        if (
            $effectiveCompanyId <= 0
            || $professionalId <= 0
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1
            || preg_match('/^[A-Za-z0-9_]+$/', $this->procedure) !== 1
        ) {
            return [
                'available' => false,
                'reason' => 'slot_revalidation_invalid_input',
            ];
        }

        try {
            $result = (new ConsultarHorariosTool(
                $this->sql,
                $this->procedure,
                $this->hoursPerDate
            ))->execute([
                'empresa_id' => $effectiveCompanyId,
                'profissional_id' => $professionalId,
                'data_inicio' => $date,
                'data_final' => $date,
                'pagina' => 0,
            ]);
        } catch (Throwable) {
            return [
                'available' => false,
                'reason' => 'slot_revalidation_failed',
            ];
        }

        if (($result['success'] ?? false) !== true) {
            return [
                'available' => false,
                'reason' => 'slot_revalidation_failed',
            ];
        }

        foreach (($result['data'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupDate = trim((string)($group['data'] ?? ''));

            if ($groupDate !== $date) {
                continue;
            }

            foreach (($group['horarios'] ?? []) as $hour) {
                if (trim((string)$hour) === $time) {
                    return [
                        'available' => true,
                        'reason' => 'slot_available',
                    ];
                }
            }

            if (
                trim((string)($group['hora'] ?? '')) === $time
                || trim((string)($group['horario'] ?? '')) === $time
            ) {
                return [
                    'available' => true,
                    'reason' => 'slot_available',
                ];
            }
        }

        return [
            'available' => false,
            'reason' => 'slot_unavailable',
        ];
    }
}
