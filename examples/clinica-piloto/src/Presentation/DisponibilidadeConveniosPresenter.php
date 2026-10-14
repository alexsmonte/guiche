<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Presentation;



use Asmpkg\Guiche\Contracts\ResultPresenterInterface;final class DisponibilidadeConveniosPresenter implements ResultPresenterInterface
{
    /**
     * @param array<mixed> $result
     */
    public function present(array $result): ?string
    {
        if (($result['success'] ?? false) !== true) {
            return null;
        }

        $rows = $result['data'] ?? [];

        if (!is_array($rows) || $rows === []) {
            return 'Não encontrei profissionais disponíveis para esse convênio e procedimento.';
        }

        $names = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = $this->extractName($row);

            if ($name === null || $name === '') {
                continue;
            }

            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return null;
        }

        $input = is_array($result['input'] ?? null)
            ? $result['input']
            : [];

        $procedure = trim((string)($input['tratamento_nome'] ?? ''));
        $insurance = trim((string)($input['convenio_nome'] ?? ''));

        if ($procedure !== '' && $insurance !== '') {
            $heading = sprintf(
                'Para %s pelo convênio %s, qual profissional você prefere?',
                $procedure,
                $insurance
            );
        } else {
            $heading = 'Qual profissional você prefere?';
        }

        return $heading
            . PHP_EOL
            . PHP_EOL
            . implode(
                PHP_EOL,
                array_map(
                    static fn (string $name): string => '- ' . $name,
                    $names
                )
            );
    }

    /**
     * @param array<mixed> $row
     */
    private function extractName(array $row): ?string
    {
        /*
         * Formato atual da procedure do piloto:
         *
         * Dra. Ana Exemplo
         * (atende segunda-feira, terça-feira)
         *
         * O Presenter pertence ao piloto, portanto pode conhecer
         * esse formato. O CORE do Guichê não conhece esse schema.
         */
        $value = $row['profissional_atendimento']
            ?? $row['nome_completo']
            ?? $row['nome']
            ?? $row['profissional']
            ?? null;

        if (!is_scalar($value)) {
            return null;
        }

        $name = trim((string)$value);

        if ($name === '') {
            return null;
        }

        $name = preg_replace(
            '/\s*\(\s*atende\b.*\)\s*$/ui',
            '',
            $name
        );

        return trim((string)$name);
    }
}
