<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Pilot\Clinical\Presentation;

use DateTimeImmutable;

final class ConsultarHorariosPresenter
{
    public function present(array $result): ?string
    {
        if (($result['success'] ?? false) !== true) {
            return null;
        }

        $groups = $result['data'] ?? [];

        if (!is_array($groups) || $groups === []) {
            $message = trim((string)($result['message'] ?? ''));

            return $message !== ''
                ? $message
                : 'Não encontrei horários disponíveis.';
        }

        $input = is_array($result['input'] ?? null) ? $result['input'] : [];
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];

        $page = (int)($input['pagina'] ?? 1);
        $start = (string)($input['data_inicio'] ?? '');
        $end = (string)($input['data_final'] ?? $start);

        $completeMode = ($meta['modo'] ?? null) === 'completo' || $page === 0;

        // Data específica encontrada: mostra somente a data solicitada.
        if (
            !$completeMode
            && $page === 1
            && $start !== ''
            && $start === $end
            && ($meta['data_solicitada_encontrada'] ?? false) === true
        ) {
            foreach ($groups as $group) {
                if (is_array($group) && ($group['data'] ?? null) === $start) {
                    return $this->render(
                        [$group],
                        'Horários disponíveis para ' . $this->dateLabel($group) . ':',
                        false
                    );
                }
            }
        }

        if ($completeMode) {
            return $this->render($groups, 'Horários completos disponíveis:', false);
        }

        if ($page > 1) {
            return $this->render(
                $groups,
                'Horários adicionais disponíveis:',
                (bool)($meta['tem_mais_horarios'] ?? false)
            );
        }

        // Data específica não encontrada: mostra próximas opções da procedure.
        if (
            $start !== ''
            && $start === $end
            && ($meta['data_solicitada_encontrada'] ?? true) === false
        ) {
            return $this->render(
                $groups,
                'Não encontrei horários no dia ' . $this->formatDate($start) . '. As próximas opções são:',
                (bool)($meta['tem_mais_horarios'] ?? false)
            );
        }

        // Período sem nenhum resultado dentro dele: mantém próximas opções.
        if (
            $start !== ''
            && $end !== ''
            && !$this->hasDateInsideRange($groups, $start, $end)
        ) {
            return $this->render(
                $groups,
                'Não encontrei horários no período solicitado. As próximas opções são:',
                (bool)($meta['tem_mais_horarios'] ?? false)
            );
        }

        return $this->render(
            $groups,
            'Horários disponíveis:',
            (bool)($meta['tem_mais_horarios'] ?? false)
        );
    }

    private function render(array $groups, string $heading, bool $hasMore): string
    {
        $blocks = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $hours = $group['horarios'] ?? [];

            if (!is_array($hours) || $hours === []) {
                continue;
            }

            // Somente preserva/deduplica dados que vieram da Tool.
            $hours = array_values(array_unique(array_map(
                static fn ($hour): string => trim((string)$hour),
                $hours
            )));

            $hours = array_values(array_filter(
                $hours,
                static fn (string $hour): bool => $hour !== ''
            ));

            if ($hours === []) {
                continue;
            }

            $blocks[] = $this->dateLabel($group)
                . PHP_EOL
                . implode(' · ', $hours);
        }

        if ($blocks === []) {
            return 'Não encontrei horários disponíveis.';
        }

        $message = $heading
            . PHP_EOL . PHP_EOL
            . implode(PHP_EOL . PHP_EOL, $blocks);

        if ($hasMore) {
            $message .= PHP_EOL . PHP_EOL . 'Também existem mais horários nessas datas.';
        }

        $message .= PHP_EOL . PHP_EOL
            . (count($blocks) === 1
                ? 'Qual horário você prefere?'
                : 'Qual data e horário você prefere?');

        return $message;
    }

    private function dateLabel(array $group): string
    {
        $date = trim((string)($group['data_br'] ?? ''));
        $weekday = trim((string)($group['dia_semana'] ?? ''));

        if ($date !== '' && $weekday !== '') {
            return $date . ' (' . $weekday . ')';
        }

        return $date !== '' ? $date : (string)($group['data'] ?? 'Data');
    }

    private function formatDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return $value;
        }

        return $date->format('d/m/Y');
    }

    private function hasDateInsideRange(array $groups, string $start, string $end): bool
    {
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $date = (string)($group['data'] ?? '');

            if ($date !== '' && $date >= $start && $date <= $end) {
                return true;
            }
        }

        return false;
    }
}
