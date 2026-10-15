<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Tools;

use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Contracts\ContextAwareToolInterface;
use Asmpkg\Guiche\Execution\ToolExecutionContext;
use GuicheExample\ClinicaPiloto\Support\ClinicalTenant;
use GuicheExample\ClinicaPiloto\Presentation\ConsultarHorariosPresenter;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class ConsultarHorariosTool implements ContextAwareToolInterface
{
    public function __construct(
        private readonly SqlExecutorInterface $db,
        private readonly string $procedure = 'horarios_disponiveis_por_turno',
        private readonly int $hoursPerDate = 3
    ) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->procedure)) {
            throw new InvalidArgumentException('Nome de procedure inválido.');
        }

        if ($this->hoursPerDate < 1 || $this->hoursPerDate > 20) {
            throw new InvalidArgumentException(
                'horarios_por_data deve estar entre 1 e 20.'
            );
        }
    }

    public function name(): string
    {
        return 'ConsultarHorarios';
    }

    public function description(): string
    {
        return 'Consulta horários reais e organiza o resultado por data. '
            . 'Use pagina=1 na consulta normal. '
            . 'Use pagina=2, 3... quando o paciente pedir mais horários das mesmas datas. '
            . 'Use pagina=0 SOMENTE quando o paciente pedir explicitamente todos os horários, '
            . 'horários completos ou a lista completa; pagina=0 retorna todos os horários '
            . 'das datas encontradas em uma única chamada. '
            . 'Apresente cada data uma única vez seguida dos horários.';
    }

    /**
     * @return array<mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'profissional_id' => [
                    'type' => 'integer',
                    'description' => 'Identificador real do profissional.',
                ],
                'data_inicio' => [
                    'type' => 'string',
                    'description' => 'Data inicial YYYY-MM-DD.',
                ],
                'data_final' => [
                    'type' => 'string',
                    'description' => 'Data final YYYY-MM-DD.',
                ],
                'pagina' => [
                    'type' => 'integer',
                    'description' =>
                        '0=todos os horários; 1=primeira página; 2+=próximas páginas.',
                ],
            ],
            'required' => [
                'profissional_id',
                'data_inicio',
                'data_final',
                'pagina',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function executeWithContext(
        array $arguments,
        ToolExecutionContext $context
    ): array {
        $arguments['empresa_id'] =
            ClinicalTenant::companyId($context);

        return $this->execute($arguments);
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function execute(array $arguments): array
    {
        foreach (
            [
                'empresa_id',
                'profissional_id',
                'data_inicio',
                'data_final',
                'pagina',
            ]
            as $required
        ) {
            if (
                !array_key_exists($required, $arguments)
                || $arguments[$required] === ''
                || $arguments[$required] === null
            ) {
                throw new InvalidArgumentException(
                    "Parâmetro obrigatório ausente: {$required}"
                );
            }
        }

        $empresaId = (int) $arguments['empresa_id'];
        $profissionalId = (int) $arguments['profissional_id'];
        $dataInicio = (string) $arguments['data_inicio'];
        $dataFinal = (string) $arguments['data_final'];
        $page = (int) $arguments['pagina'];

        if ($page < 0) {
            throw new InvalidArgumentException(
                'pagina deve ser 0 ou maior.'
            );
        }

        $this->assertDate(
            $dataInicio,
            'data_inicio'
        );

        $this->assertDate(
            $dataFinal,
            'data_final'
        );

        $rows = $this->db->fetchAll(
            sprintf(
                'CALL %s(?, ?, ?, ?, ?)',
                $this->procedure
            ),
            [
                $dataInicio,
                $dataFinal,
                null,
                $empresaId,
                $profissionalId,
            ]
        );

        $message = null;
        $groups = [];

        foreach ($rows as $row) {
            if (
                isset($row['mensagem'])
                && trim((string) $row['mensagem']) !== ''
            ) {
                $message = trim(
                    (string) $row['mensagem']
                );

                continue;
            }

            if (!isset($row['horario_disponivel'])) {
                continue;
            }

            $slot = $this->parseSlot(
                (string) $row['horario_disponivel']
            );

            if ($slot === null) {
                continue;
            }

            $date = $slot['data'];

            if (!isset($groups[$date])) {
                $groups[$date] = [
                    'data' => $slot['data'],
                    'data_br' => $slot['data_br'],
                    'dia_semana' => $slot['dia_semana'],
                    'todos_horarios' => [],
                ];
            }

            $groups[$date]['todos_horarios'][] =
                $slot['hora'];
        }

        ksort($groups);

        $completeMode = $page === 0;

        $offset = $completeMode
            ? 0
            : ($page - 1) * $this->hoursPerDate;

        $presentedGroups = [];
        $allDates = [];
        $hasMoreHours = false;

        foreach ($groups as $group) {
            $allHours = array_values(
                array_unique(
                    $group['todos_horarios']
                )
            );

            $pageHours = $completeMode
                ? $allHours
                : array_slice(
                    $allHours,
                    $offset,
                    $this->hoursPerDate
                );

            $totalHours = count($allHours);

            $pageHasMore = !$completeMode
                && (
                    $offset
                    + count($pageHours)
                ) < $totalHours;

            if ($pageHasMore) {
                $hasMoreHours = true;
            }

            $allDates[] = [
                'data' => $group['data'],
                'data_br' => $group['data_br'],
                'dia_semana' => $group['dia_semana'],
                'quantidade' => $totalHours,
            ];

            if ($pageHours === []) {
                continue;
            }

            $presentedGroups[] = [
                'data' => $group['data'],
                'data_br' => $group['data_br'],
                'dia_semana' => $group['dia_semana'],
                'horarios' => $pageHours,
                'total_horarios' => $totalHours,
                'tem_mais_horarios' => $pageHasMore,
            ];
        }

        $dates = array_keys($groups);
        $firstDate = $dates[0] ?? null;

        $lastDate = $dates !== []
            ? (string) $dates[count($dates) - 1]
            : null;

        $nextDateStart = null;

        if ($lastDate !== null) {
            $nextDateStart = (
                new DateTimeImmutable($lastDate)
            )
                ->add(
                    new DateInterval('P1D')
                )
                ->format('Y-m-d');
        }

        $result = [
            'success' => true,
            'tool' => $this->name(),

            'input' => [
                'empresa_id' => $empresaId,
                'profissional_id' => $profissionalId,
                'data_inicio' => $dataInicio,
                'data_final' => $dataFinal,
                'pagina' => $page,
            ],

            'data' => $presentedGroups,

            'message' => $message,

            'meta' => [
                'source' => 'database',
                'procedure' => $this->procedure,
                'turno' => null,

                'modo' => $completeMode
                    ? 'completo'
                    : 'paginado',

                'pagina_horarios' => $page,

                'horarios_por_data' =>
                    $completeMode
                        ? null
                        : $this->hoursPerDate,

                'tem_mais_horarios' =>
                    $hasMoreHours,

                'proxima_pagina_horarios' =>
                    $hasMoreHours
                        ? $page + 1
                        : null,

                'total_datas' =>
                    count($groups),

                'primeira_data_encontrada' =>
                    $firstDate,

                'ultima_data_encontrada' =>
                    $lastDate,

                'proxima_data_inicio' =>
                    $nextDateStart,

                'pode_buscar_mais_datas' =>
                    count($groups) >= 3,

                'data_solicitada_encontrada' =>
                    isset($groups[$dataInicio]),

                'datas_encontradas' =>
                    $allDates,

                'linhas_brutas' =>
                    count($rows),
            ],
        ];

        $presentation = (new ConsultarHorariosPresenter())->present($result);

        if (is_string($presentation) && trim($presentation) !== '') {
            $result['_guiche'] = [
                'terminal_message' => $presentation,
            ];
        }

        return $result;
    }

    /**
     * @return array<mixed>|null
     */
    private function parseSlot(
        string $value
    ): ?array {
        $value = trim($value);

        if (
            !preg_match(
                '/^(\d{2})\/(\d{2})\/(\d{4})\s+(.+?)\s+(\d{2}:\d{2})$/u',
                $value,
                $matches
            )
        ) {
            return null;
        }

        return [
            'data' => sprintf(
                '%04d-%02d-%02d',
                (int) $matches[3],
                (int) $matches[2],
                (int) $matches[1]
            ),

            'data_br' => sprintf(
                '%02d/%02d/%04d',
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3]
            ),

            'dia_semana' => trim(
                $matches[4]
            ),

            'hora' => $matches[5],
        ];
    }

    private function assertDate(
        string $value,
        string $field
    ): void {
        $date =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );

        if (
            $date === false
            || $date->format('Y-m-d')
                !== $value
        ) {
            throw new InvalidArgumentException(
                "{$field} deve estar no formato YYYY-MM-DD."
            );
        }
    }
}
