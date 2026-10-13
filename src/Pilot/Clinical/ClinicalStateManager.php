<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Pilot\Clinical;

use Asmpkg\Guiche\Contracts\StateManagerInterface;
use DateTimeImmutable;

final class ClinicalStateManager implements StateManagerInterface
{
    public function onUserMessage(array $context, string $message): array
    {
        $state = $context['state'] ?? [];
        $stage = $state['etapa'] ?? null;

        $patch = [
            'intent' => null,
            'state' => [],
            'unset_state' => [],
        ];

        if ($stage === 'aguardando_horario') {
            $time = $this->extractTime($message);

            if ($time !== null) {
                $shown = $state['horarios_apresentados']
                    ?? $state['horarios_disponiveis']
                    ?? [];

                $matches = $this->findTimeMatches($time, $shown);
                $candidates = $this->uniqueDateCandidates($matches);

                if (count($candidates) === 1) {
                    $slot = $candidates[0];

                    $patch['state']['horario'] = $time;
                    $patch['state']['data_agendamento'] = $slot['data'];
                    $patch['state']['etapa'] = 'aguardando_cpf';

                    $patch['unset_state'][] = 'horario_pendente';
                    $patch['unset_state'][] = 'confirmado_pelo_usuario';
                } elseif (count($candidates) > 1) {
                    $patch['state']['horario_pendente'] = [
                        'hora' => $time,
                        'datas' => $candidates,
                    ];

                    $patch['state']['etapa'] = 'aguardando_data_horario';

                    $patch['unset_state'][] = 'horario';
                    $patch['unset_state'][] = 'data_agendamento';
                    $patch['unset_state'][] = 'confirmado_pelo_usuario';
                }
            }
        }

        if ($stage === 'aguardando_data_horario') {
            $pending = $state['horario_pendente'] ?? [];
            $candidates = $pending['datas'] ?? [];
            $date = $this->resolveCandidateDate($message, $candidates);

            if ($date !== null) {
                $patch['state']['horario'] = $pending['hora'] ?? null;
                $patch['state']['data_agendamento'] = $date;
                $patch['state']['etapa'] = 'aguardando_cpf';

                $patch['unset_state'][] = 'horario_pendente';
                $patch['unset_state'][] = 'confirmado_pelo_usuario';
            }
        }

        if ($stage === 'aguardando_cpf') {
            $cpf = $this->extractCpf($message);

            if ($cpf !== null) {
                $patch['state']['cpf'] = $cpf;
                $patch['unset_state'][] = 'paciente_id';
                $patch['unset_state'][] = 'paciente_nome';
                $patch['unset_state'][] = 'paciente_validado';
                $patch['unset_state'][] = 'paciente_nao_encontrado';
                $patch['unset_state'][] = 'confirmado_pelo_usuario';
            }
        }

        if (
            $stage === 'aguardando_confirmacao'
            && $this->isAffirmative($message)
        ) {
            $patch['state']['confirmado_pelo_usuario'] = true;
            $patch['state']['etapa'] = 'pronto_para_agendar';
        }

        return $this->cleanPatch($patch);
    }

    public function onToolResult(
        array $context,
        string $tool,
        array $arguments,
        array $result
    ): array {
        if (($result['success'] ?? false) !== true) {
            return [];
        }

        return match ($tool) {
            'DisponibilidadeConvenios' => $this->afterAvailability(
                $context,
                $arguments,
                $result
            ),

            'CriarAgendamento' => $this->afterBooking(
                $context,
                $arguments,
                $result
            ),

            'ConsultarPaciente' => $this->afterPatient(
                $context,
                $arguments,
                $result
            ),

            'ConsultarProfissional' => $this->afterProfessional(
                $context,
                $arguments,
                $result
            ),

            'ConsultarHorarios' => $this->afterSchedules(
                $context,
                $arguments,
                $result
            ),

            default => [],
        };
    }

    private function afterBooking(
        array $context,
        array $arguments,
        array $result
    ): array {
        $bookingId = $result['data']['agendamento_id'] ?? null;

        if (
            ($result['success'] ?? false) !== true
            || $bookingId === null
            || $bookingId === ''
        ) {
            return [];
        }

        return $this->cleanPatch([
            'state' => [
                'etapa' => 'agendado',
                'agendamento_id' => is_numeric($bookingId)
                    ? (int)$bookingId
                    : $bookingId,
            ],
        ]);
    }

    private function afterPatient(
        array $context,
        array $arguments,
        array $result
    ): array {
        $data = is_array($result['data'] ?? null)
            ? $result['data']
            : [];

        $patientId = $data['paciente_id'] ?? null;
        $found = ($data['cadastro_encontrado'] ?? false) === true
            && $patientId !== null
            && $patientId !== '';

        if (!$found) {
            return $this->cleanPatch([
                'state' => [
                    'etapa' => 'aguardando_cpf',
                    'paciente_nao_encontrado' => true,
                ],
                'unset_state' => [
                    'cpf',
                    'paciente_id',
                    'paciente_nome',
                    'paciente_validado',
                    'confirmado_pelo_usuario',
                ],
            ]);
        }

        $cpf = preg_replace(
            '/\D+/',
            '',
            (string)(
                $data['cpf']
                ?? $arguments['cpf']
                ?? ''
            )
        );

        $patch = [
            'state' => [
                'etapa' => 'aguardando_confirmacao',
                'paciente_id' => is_numeric($patientId)
                    ? (int)$patientId
                    : $patientId,
                'paciente_validado' => true,
            ],
            'unset_state' => [
                'paciente_nao_encontrado',
                'confirmado_pelo_usuario',
            ],
        ];

        if (is_string($cpf) && strlen($cpf) === 11) {
            $patch['state']['cpf'] = $cpf;
        }

        $name = trim((string)($data['nome'] ?? ''));

        if ($name !== '') {
            $patch['state']['paciente_nome'] = $name;
        } else {
            $patch['unset_state'][] = 'paciente_nome';
        }

        return $this->cleanPatch($patch);
    }

    private function afterAvailability(
        array $context,
        array $arguments,
        array $result
    ): array {
        $state = $context['state'] ?? [];

        $newProcedure = (string) ($arguments['tratamento_nome'] ?? '');
        $newConvenio = (string) ($arguments['convenio_nome'] ?? '');

        $oldProcedure = (string) ($state['procedimento']['nome'] ?? '');
        $oldConvenio = (string) ($state['convenio']['nome'] ?? '');

        $changedScope =
            ($oldProcedure !== '' || $oldConvenio !== '')
            && (
                !$this->sameText($newProcedure, $oldProcedure)
                || !$this->sameText($newConvenio, $oldConvenio)
            );

        $patch = [
            'intent' => 'agendamento',
            'state' => [
                'procedimento' => [
                    'nome' => $newProcedure,
                ],
                'convenio' => [
                    'nome' => $newConvenio,
                ],
                'profissionais_disponiveis' => $result['data'] ?? [],
            ],
            'unset_state' => [],
        ];

        if ($changedScope) {
            $patch['unset_state'] = $this->downstreamStateKeys();
        }

        if (
            $changedScope
            || empty($state['profissional']['id'])
        ) {
            $patch['state']['etapa'] = 'aguardando_profissional';
        }

        return $patch;
    }

    private function afterProfessional(
        array $context,
        array $arguments,
        array $result
    ): array {
        $state = $context['state'] ?? [];
        $data = $result['data'] ?? [];

        $newId = $data['profissional_id'] ?? null;
        $oldId = $state['profissional']['id'] ?? null;

        $changed =
            $oldId !== null
            && $newId !== null
            && (string) $oldId !== (string) $newId;

        $patch = [
            'intent' => 'agendamento',
            'state' => [
                'profissional' => [
                    'id' => $newId,
                    'nome' => $data['nome']
                        ?? $arguments['nome_profissional']
                        ?? null,
                ],
                'etapa' => 'aguardando_data',
            ],
            'unset_state' => [],
        ];

        if ($changed) {
            $patch['unset_state'] = [
                'data_inicio',
                'data_final',
                'horarios_disponiveis',
                'horarios_apresentados',
                'horarios_por_data',
                'horarios_paginacao',
                'horario',
                'horario_pendente',
                'data_agendamento',
                'confirmado_pelo_usuario',
            ];
        }

        return $patch;
    }

    private function afterSchedules(
        array $context,
        array $arguments,
        array $result
    ): array {
        $state = $context['state'] ?? [];

        $newStart = (string) ($arguments['data_inicio'] ?? '');
        $newEnd = (string) ($arguments['data_final'] ?? '');
        $page = (int) ($arguments['pagina'] ?? 1);

        $oldStart = (string) ($state['data_inicio'] ?? '');
        $oldEnd = (string) ($state['data_final'] ?? '');

        $dateChanged =
            ($oldStart !== '' || $oldEnd !== '')
            && (
                $newStart !== $oldStart
                || $newEnd !== $oldEnd
            );

        $groups = $result['data'] ?? [];
        $pageSlots = [];

        foreach ($groups as $group) {
            foreach ($group['horarios'] ?? [] as $hour) {
                $pageSlots[] = [
                    'data' => $group['data'] ?? null,
                    'data_br' => $group['data_br'] ?? null,
                    'dia_semana' => $group['dia_semana'] ?? null,
                    'hora' => $hour,
                ];
            }
        }

        $shown = (!$dateChanged && $page > 1)
            ? ($state['horarios_apresentados'] ?? [])
            : [];

        foreach ($pageSlots as $slot) {
            $key = ($slot['data'] ?? '')
                . '|'
                . ($slot['hora'] ?? '');

            $exists = false;

            foreach ($shown as $existing) {
                $existingKey = ($existing['data'] ?? '')
                    . '|'
                    . ($existing['hora'] ?? '');

                if ($existingKey === $key) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $shown[] = $slot;
            }
        }

        $patch = [
            'intent' => 'agendamento',
            'state' => [
                'data_inicio' => $newStart,
                'data_final' => $newEnd,
                'horarios_disponiveis' => $pageSlots,
                'horarios_apresentados' => $shown,
                'horarios_por_data' => $groups,

                'horarios_paginacao' => [
                    'pagina' =>
                        $result['meta']['pagina_horarios']
                        ?? $page,

                    'horarios_por_data' =>
                        $result['meta']['horarios_por_data']
                        ?? null,

                    'tem_mais_horarios' =>
                        $result['meta']['tem_mais_horarios']
                        ?? false,

                    'proxima_pagina' =>
                        $result['meta']['proxima_pagina_horarios']
                        ?? null,

                    'total_datas' =>
                        $result['meta']['total_datas']
                        ?? 0,

                    'primeira_data_encontrada' =>
                        $result['meta']['primeira_data_encontrada']
                        ?? null,

                    'ultima_data_encontrada' =>
                        $result['meta']['ultima_data_encontrada']
                        ?? null,

                    'proxima_data_inicio' =>
                        $result['meta']['proxima_data_inicio']
                        ?? null,

                    'pode_buscar_mais_datas' =>
                        $result['meta']['pode_buscar_mais_datas']
                        ?? false,

                    'data_solicitada_encontrada' =>
                        $result['meta']['data_solicitada_encontrada']
                        ?? null,

                    'datas_encontradas' =>
                        $result['meta']['datas_encontradas']
                        ?? [],
                ],

                'etapa' => 'aguardando_horario',
            ],
            'unset_state' => [],
        ];

        if (
            $dateChanged
            || $page === 1
            || isset($state['horario'])
            || isset($state['horario_pendente'])
            || isset($state['confirmado_pelo_usuario'])
        ) {
            $patch['unset_state'][] = 'horario';
            $patch['unset_state'][] = 'horario_pendente';
            $patch['unset_state'][] = 'data_agendamento';
            $patch['unset_state'][] = 'confirmado_pelo_usuario';
        }

        return $patch;
    }

    private function extractCpf(string $message): ?string
    {
        if (
            !preg_match(
                '/(?<!\d)(\d{3}[. ]?\d{3}[. ]?\d{3}[- ]?\d{2})(?!\d)/u',
                trim($message),
                $matches
            )
        ) {
            return null;
        }

        $cpf = preg_replace(
            '/\D+/',
            '',
            (string)$matches[1]
        );

        return is_string($cpf)
            && strlen($cpf) === 11
                ? $cpf
                : null;
    }

    private function extractTime(string $message): ?string
    {
        $message = trim($message);

        /*
         * Durante a escolha do horário, respostas curtas como
         * 9, 10 ou 14 significam 09:00, 10:00 ou 14:00.
         *
         * A mensagem inteira precisa ser somente o número.
         * Assim "dia 10" não é interpretado como 10:00.
         */
        if (
            preg_match(
                '/^([01]?\d|2[0-3])$/u',
                $message,
                $bareHour
            )
        ) {
            return sprintf(
                '%02d:00',
                (int) $bareHour[1]
            );
        }

        /*
         * Formatos naturais aceitos:
         * 9h
         * 10h
         * 10h30
         * 09:00
         * 10:30
         */
        if (
            preg_match(
                '/\b([01]?\d|2[0-3])(?::([0-5]\d)|h(?:([0-5]\d))?)\b/ui',
                $message,
                $matches
            )
        ) {
            $colonMinutes = $matches[2] ?? '';
            $hourMinutes = $matches[3] ?? '';

            $minutes = $colonMinutes !== ''
                ? $colonMinutes
                : ($hourMinutes !== '' ? $hourMinutes : '00');

            return sprintf(
                '%02d:%02d',
                (int) $matches[1],
                (int) $minutes
            );
        }

        return null;
    }


    private function findTimeMatches(
        string $time,
        array $available
    ): array {
        $matches = [];

        foreach ($available as $schedule) {
            if (($schedule['hora'] ?? null) === $time) {
                $matches[] = $schedule;
            }
        }

        return $matches;
    }

    private function uniqueDateCandidates(array $matches): array
    {
        $dates = [];

        foreach ($matches as $match) {
            $date = (string) ($match['data'] ?? '');

            if ($date === '') {
                continue;
            }

            $dates[$date] = [
                'data' => $date,
                'data_br' =>
                    $match['data_br']
                    ?? $this->formatDateBr($date),

                'dia_semana' =>
                    $match['dia_semana']
                    ?? null,
            ];
        }

        return array_values($dates);
    }

    private function resolveCandidateDate(
        string $message,
        array $candidates
    ): ?string {
        $message = trim(
            mb_strtolower(
                $message,
                'UTF-8'
            )
        );

        $day = null;
        $month = null;
        $year = null;

        if (
            preg_match(
                '/\b(?:dia\s+)?(\d{1,2})\s*\/\s*(\d{1,2})(?:\s*\/\s*(\d{4}))?\b/u',
                $message,
                $matches
            )
        ) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = isset($matches[3])
                && $matches[3] !== ''
                ? (int) $matches[3]
                : null;
        } elseif (
            preg_match(
                '/^\s*(?:dia\s+)?(\d{1,2})\s*$/u',
                $message,
                $matches
            )
        ) {
            $day = (int) $matches[1];
        }

        if ($day === null) {
            return null;
        }

        $matches = [];

        foreach ($candidates as $candidate) {
            $dateValue = (string) ($candidate['data'] ?? '');

            if ($dateValue === '') {
                continue;
            }

            try {
                $date = new DateTimeImmutable($dateValue);
            } catch (\Throwable) {
                continue;
            }

            if ((int) $date->format('d') !== $day) {
                continue;
            }

            if (
                $month !== null
                && (int) $date->format('m') !== $month
            ) {
                continue;
            }

            if (
                $year !== null
                && (int) $date->format('Y') !== $year
            ) {
                continue;
            }

            $matches[] = $dateValue;
        }

        $matches = array_values(
            array_unique($matches)
        );

        return count($matches) === 1
            ? $matches[0]
            : null;
    }

    private function isAffirmative(string $message): bool
    {
        return in_array(
            $this->normalize($message),
            [
                'sim',
                's',
                'ok',
                'okay',
                'pode',
                'pode ser',
                'quero',
                'isso',
                'isso mesmo',
                'confirmo',
                'pode agendar',
                'pode marcar',
            ],
            true
        );
    }

    private function sameText(string $a, string $b): bool
    {
        return $this->normalize($a)
            === $this->normalize($b);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(
            trim($value),
            'UTF-8'
        );

        $value = trim(
            $value,
            " \t\n\r\0\x0B.!?,"
        );

        if (function_exists('iconv')) {
            $converted = iconv(
                'UTF-8',
                'ASCII//TRANSLIT//IGNORE',
                $value
            );

            if ($converted !== false) {
                $value = $converted;
            }
        }

        return trim(
            preg_replace(
                '/\s+/',
                ' ',
                $value
            ) ?? $value
        );
    }

    private function cleanPatch(array $patch): array
    {
        $patch['unset_state'] = array_values(
            array_unique(
                $patch['unset_state'] ?? []
            )
        );

        if (
            ($patch['intent'] ?? null) === null
            && empty($patch['state'])
            && empty($patch['unset_state'])
        ) {
            return [];
        }

        return $patch;
    }

    private function downstreamStateKeys(): array
    {
        return [
            'profissional',
            'data_inicio',
            'data_final',
            'horarios_disponiveis',
            'horarios_apresentados',
            'horarios_por_data',
            'horarios_paginacao',
            'horario',
            'horario_pendente',
            'data_agendamento',
            'confirmado_pelo_usuario',
        ];
    }

    private function formatDateBr(string $date): string
    {
        try {
            return (
                new DateTimeImmutable($date)
            )->format('d/m/Y');
        } catch (\Throwable) {
            return $date;
        }
    }
}
