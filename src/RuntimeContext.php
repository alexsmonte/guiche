<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class RuntimeContext
{
    private DateTimeZone $timezone;

    public function __construct(string $timezone = 'America/Manaus')
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * @return array<mixed>
     */
    public function current(): array
    {
        $now = new DateTimeImmutable('now', $this->timezone);

        return [
            'timezone' => $this->timezone->getName(),
            'data_atual' => $now->format('Y-m-d'),
            'data_atual_br' => $now->format('d/m/Y'),
            'hora_atual' => $now->format('H:i:s'),
            'data_hora_atual' => $now->format(DATE_ATOM),
            'ano_atual' => (int) $now->format('Y'),
            'mes_atual' => (int) $now->format('m'),
            'dia_atual' => (int) $now->format('d'),
            'dia_semana' => $this->weekdayPtBr((int) $now->format('N')),
        ];
    }

    private function weekdayPtBr(int $isoDay): string
    {
        return match ($isoDay) {
            1 => 'segunda-feira',
            2 => 'terça-feira',
            3 => 'quarta-feira',
            4 => 'quinta-feira',
            5 => 'sexta-feira',
            6 => 'sábado',
            7 => 'domingo',
            default => throw new RuntimeException(
                'Dia ISO da semana inválido.'
            ),
        };
    }
}
