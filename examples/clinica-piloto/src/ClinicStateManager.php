<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto;

use Asmpkg\Guiche\Contracts\StateManagerInterface;
use DateTimeImmutable;

final class ClinicStateManager implements StateManagerInterface
{
    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public function onUserMessage(array $context, string $message): array
    {
        $state = $context['state'] ?? [];
        $stage = $state['etapa'] ?? null;

        $patch = [
            'intent' => null,
            'state' => [],
            'unset_state' => [],
        ];

        /*
         * Fatos inequívocos podem ser registrados antes de existir uma
         * etapa clínica, sem transformar o StateManager em Tool ou prompt.
         */
        $this->applyExplicitUserFacts(
            $state,
            $message,
            $patch
        );

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

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $context
     * @param array<mixed> $result
     * @return array<mixed>
     */
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

            'AgendarConsulta',
            'agendarConsulta' => $this->afterCompatibleBooking(
                $context,
                $arguments,
                $result
            ),

            'ConsultarPaciente' => $this->afterPatient(
                $context,
                $arguments,
                $result
            ),

            'ConsultaPaciente' => $this->afterLegacyPatient(
                $context,
                $arguments,
                $result
            ),

            'CadastrarPaciente' => $this->afterPatientRegistration(
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

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $context
     * @param array<mixed> $result
     * @return array<mixed>
     */
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

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $context
     * @param array<mixed> $result
     * @return array<mixed>
     */
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
            $cpf = preg_replace(
                '/\D+/',
                '',
                (string)(
                    $data['cpf']
                    ?? $arguments['cpf']
                    ?? ''
                )
            );

            $missingState = [
                'etapa' => 'aguardando_cpf',
                'paciente_nao_encontrado' => true,
                'paciente_cadastrado' => false,
                'status' => 'paciente_nao_cadastrado',
            ];

            if (is_string($cpf) && strlen($cpf) === 11) {
                $missingState['cpf'] = $cpf;
            }

            return $this->cleanPatch([
                'state' => $missingState,
                'unset_state' => [
                    'paciente_id',
                    'paciente_nome',
                    'paciente_validado',
                    'agendamento_id',
                    'agendamento_existente',
                    'agendamentos_existentes',
                    'confirmado_pelo_usuario',
                ],
            ]);
        }

        $appointments = is_array($data['agendamentos'] ?? null)
            ? array_values(array_filter(
                $data['agendamentos'],
                static fn(mixed $appointment): bool => is_array($appointment)
            ))
            : [];

        $existingBookingId = null;

        foreach ($appointments as $appointment) {
            $candidate = $appointment['agendamento_id']
                ?? $appointment['id_agendamento']
                ?? null;

            if ($candidate !== null && $candidate !== '') {
                $existingBookingId = is_numeric($candidate)
                    ? (int)$candidate
                    : (string)$candidate;
                break;
            }
        }

        if (
            ($data['agendamento_encontrado'] ?? false) === true
            && $existingBookingId !== null
        ) {
            $state = [
                'etapa' => 'agendado',
                'paciente_id' => is_numeric($patientId)
                    ? (int)$patientId
                    : $patientId,
                'paciente_validado' => true,
                'paciente_cadastrado' => true,
                'agendamento_id' => $existingBookingId,
                'agendamento_existente' => true,
                'agendamentos_existentes' => $appointments,
            ];

            $existingCpf = preg_replace(
                '/\D+/',
                '',
                (string)($data['cpf'] ?? $arguments['cpf'] ?? '')
            );

            if ($existingCpf !== '') {
                $state['cpf'] = $existingCpf;
            }

            $existingName = trim((string)($data['nome'] ?? ''));

            if ($existingName !== '') {
                $state['paciente_nome'] = $existingName;
            }

            return $this->cleanPatch([
                'state' => $state,
                'unset_state' => [
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
                'paciente_cadastrado' => true,
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

    /**
     * Compatibilidade com a Tool externa/histórica ConsultaPaciente.
     *
     * Formato observado:
     * data[0].resposta_ia =
     * "paciente_id: 123\nNome do paciente: Nome..."
     *
     * @param array<mixed> $context
     * @param array<mixed> $arguments
     * @param array<mixed> $result
     * @return array<mixed>
     */
    private function afterLegacyPatient(
        array $context,
        array $arguments,
        array $result
    ): array {
        $row = $this->firstDataRow($result);
        $response = trim(
            (string)($row['resposta_ia'] ?? '')
        );

        $cpf = preg_replace(
            '/\D+/',
            '',
            (string)($arguments['cpf'] ?? '')
        );

        $normalized = $this->normalize($response);

        if (
            $response === ''
            || str_contains(
                $normalized,
                'paciente nao tem cadastro'
            )
            || str_contains(
                $normalized,
                'nao encontrei cadastro'
            )
        ) {
            $state = [
                'paciente_cadastrado' => false,
                'paciente_nao_encontrado' => true,
                'status' => 'paciente_nao_cadastrado',
            ];

            if (is_string($cpf) && strlen($cpf) === 11) {
                $state['cpf'] = $cpf;
            }

            return $this->cleanPatch([
                'state' => $state,
                'unset_state' => [
                    'paciente_id',
                    'paciente_nome',
                    'paciente_validado',
                    'agendamento_id',
                    'agendamento_existente',
                    'agendamentos_existentes',
                    'confirmado_pelo_usuario',
                ],
            ]);
        }

        if (
            preg_match(
                '/paciente_id:\s*(\d+)/i',
                $response,
                $patientMatch
            ) !== 1
        ) {
            return [];
        }

        $patientId = (int)$patientMatch[1];

        $state = [
            'paciente_id' => $patientId,
            'paciente_validado' => true,
            'paciente_cadastrado' => true,
            'etapa' => 'aguardando_confirmacao',
        ];

        if (is_string($cpf) && strlen($cpf) === 11) {
            $state['cpf'] = $cpf;
        }

        if (
            preg_match(
                '/Nome do paciente:\s*([^\r\n]+)/i',
                $response,
                $nameMatch
            ) === 1
        ) {
            $state['paciente_nome'] = trim(
                $nameMatch[1]
            );
        }

        if (
            preg_match(
                '/agendamento_id:\s*(\d+)/i',
                $response,
                $bookingMatch
            ) === 1
        ) {
            $state['agendamento_id'] =
                (int)$bookingMatch[1];

            $state['agendamento_existente'] = true;
            $state['etapa'] = 'agendado';
        }

        return $this->cleanPatch([
            'state' => $state,
            'unset_state' => [
                'paciente_nao_encontrado',
                'confirmado_pelo_usuario',
            ],
        ]);
    }

    /**
     * Resultado de CadastrarPaciente vindo de uma Tool externa.
     *
     * O StateManager não cadastra paciente; apenas persiste fatos
     * retornados por uma Tool já executada.
     *
     * @param array<mixed> $context
     * @param array<mixed> $arguments
     * @param array<mixed> $result
     * @return array<mixed>
     */
    private function afterPatientRegistration(
        array $context,
        array $arguments,
        array $result
    ): array {
        $row = $this->firstDataRow($result);

        $patientId =
            $row['novo_paciente_id']
            ?? $row['paciente_id']
            ?? $row['id']
            ?? null;

        if ($patientId === null || $patientId === '') {
            return [];
        }

        $cpf = preg_replace(
            '/\D+/',
            '',
            (string)(
                $arguments['cpf']
                ?? $context['state']['cpf']
                ?? ''
            )
        );

        $name = trim(
            (string)(
                $row['nome']
                ?? $row['nome_completo']
                ?? $arguments['nome_completo']
                ?? $arguments['nome']
                ?? ''
            )
        );

        $state = [
            'paciente_id' => is_numeric($patientId)
                ? (int)$patientId
                : $patientId,
            'paciente_validado' => true,
            'paciente_cadastrado' => true,
            'status' => 'paciente_cadastrado',
            'etapa' => 'aguardando_confirmacao',
        ];

        if (is_string($cpf) && strlen($cpf) === 11) {
            $state['cpf'] = $cpf;
        }

        if ($name !== '') {
            $state['paciente_nome'] = $name;
        }

        return $this->cleanPatch([
            'state' => $state,
            'unset_state' => [
                'paciente_nao_encontrado',
                'agendamento_id',
                'agendamento_existente',
                'agendamentos_existentes',
                'confirmado_pelo_usuario',
            ],
        ]);
    }

    /**
     * Compatibilidade de resultado com AgendarConsulta/agendarConsulta.
     *
     * Não registra uma segunda WRITE no ClinicPlugin. A Safety Layer
     * e a idempotência atuais de CriarAgendamento permanecem intactas.
     *
     * @param array<mixed> $context
     * @param array<mixed> $arguments
     * @param array<mixed> $result
     * @return array<mixed>
     */
    private function afterCompatibleBooking(
        array $context,
        array $arguments,
        array $result
    ): array {
        $row = $this->firstDataRow($result);

        $bookingId =
            $row['agendamento_id']
            ?? $row['id_agendamento']
            ?? $row['id']
            ?? null;

        if ($bookingId === null || $bookingId === '') {
            return [];
        }

        $state = [
            'etapa' => 'agendado',
            'status' => 'agendado',
            'agendamento_id' => is_numeric($bookingId)
                ? (int)$bookingId
                : $bookingId,
        ];

        $date = trim(
            (string)(
                $row['data_inicio']
                ?? $arguments['data_inicio']
                ?? $arguments['data_escolhida']
                ?? ''
            )
        );

        if ($date !== '') {
            $state['data_agendamento'] = $date;
        }

        $time = trim(
            (string)(
                $row['hora_inicio']
                ?? $arguments['hora_inicio']
                ?? $arguments['hora_escolhida']
                ?? ''
            )
        );

        if ($time !== '') {
            $state['horario'] = $time;
        }

        return $this->cleanPatch([
            'state' => $state,
        ]);
    }

    /**
     * @param array<mixed> $result
     * @return array<mixed>
     */
    private function firstDataRow(array $result): array
    {
        $data = $result['data'] ?? [];

        if (!is_array($data) || $data === []) {
            return [];
        }

        if (array_is_list($data)) {
            return is_array($data[0] ?? null)
                ? $data[0]
                : [];
        }

        return $data;
    }

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $context
     * @param array<mixed> $result
     * @return array<mixed>
     */
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

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $context
     * @param array<mixed> $result
     * @return array<mixed>
     */
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

    /**
     * @param array<mixed> $arguments
     * @param array<mixed> $context
     * @param array<mixed> $result
     * @return array<mixed>
     */
    private function afterSchedules(
        array $context,
        array $arguments,
        array $result
    ): array {
        $state = $context['state'] ?? [];

        $newStart = (string) (
            $arguments['data_inicio']
            ?? $arguments['data_inicial']
            ?? ''
        );
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

    /**
     * Registra somente fatos inequívocos presentes na mensagem.
     *
     * Não inventa IDs, não consulta dados externos e não escolhe Tool.
     *
     * @param array<mixed> $state
     * @param array<mixed> $patch
     */
    private function applyExplicitUserFacts(
        array $state,
        string $message,
        array &$patch
    ): void {
        $text = $this->normalize($message);

        if (
            preg_match(
                '/\b(?:reagendar|remarcar)\b/u',
                $text
            ) === 1
        ) {
            $patch['intent'] = 'reagendamento';
        } elseif (
            preg_match(
                '/\b(?:cancelar|desmarcar)\b/u',
                $text
            ) === 1
        ) {
            $patch['intent'] = 'cancelamento';
        } elseif (
            preg_match(
                '/\b(?:agendar|marcar)\b/u',
                $text
            ) === 1
        ) {
            $patch['intent'] = 'agendamento';
        }

        $particular =
            str_contains($text, 'sem convenio')
            || str_contains($text, 'nao tenho convenio')
            || preg_match('/\bparticular\b/u', $text) === 1;

        if (!$particular) {
            return;
        }

        $oldConvenio = trim(
            (string)($state['convenio']['nome'] ?? '')
        );

        if (
            $oldConvenio !== ''
            && !$this->sameText(
                $oldConvenio,
                'Particular'
            )
        ) {
            $patch['unset_state'] = array_merge(
                $patch['unset_state'] ?? [],
                $this->downstreamStateKeys()
            );
        }

        $patch['state']['convenio'] = [
            'nome' => 'Particular',
        ];
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


    /**
     * @param array<mixed> $available
     * @return array<mixed>
     */
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

    /**
     * @param array<mixed> $matches
     * @return array<mixed>
     */
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

    /**
     * @param array<mixed> $candidates
     */
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

    /**
     * @param array<mixed> $patch
     * @return array<mixed>
     */
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

    /**
     * @return array<mixed>
     */
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
