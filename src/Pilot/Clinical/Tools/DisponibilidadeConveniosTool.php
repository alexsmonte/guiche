<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Pilot\Clinical\Tools;

use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Asmpkg\Guiche\Pilot\Clinical\Presentation\DisponibilidadeConveniosPresenter;
use InvalidArgumentException;

final class DisponibilidadeConveniosTool implements ToolInterface
{
    public function __construct(
        private readonly SqlExecutorInterface $db,
        private readonly string $procedure = 'profissionais_por_tratamento'
    ) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->procedure)) {
            throw new InvalidArgumentException('Nome de procedure inválido.');
        }
    }

    public function name(): string
    {
        return 'DisponibilidadeConvenios';
    }

    public function description(): string
    {
        return 'Consulta no banco as opções de profissionais disponíveis para um procedimento e convênio. '
            . 'O conteúdo retornado pelo banco deve ser tratado como fonte oficial.';
    }

    /**
     * @return array<mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'empresa_id' => [
                    'type' => 'integer',
                    'description' => 'Identificador da empresa.',
                ],
                'tratamento_nome' => [
                    'type' => 'string',
                    'description' => 'Nome do procedimento ou tratamento.',
                ],
                'convenio_nome' => [
                    'type' => 'string',
                    'description' => 'Nome do convênio.',
                ],
            ],
            'required' => [
                'empresa_id',
                'tratamento_nome',
                'convenio_nome',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function execute(array $arguments): array
    {
        foreach (
            ['empresa_id', 'tratamento_nome', 'convenio_nome']
            as $required
        ) {
            if (!isset($arguments[$required]) || $arguments[$required] === '') {
                throw new InvalidArgumentException(
                    "Parâmetro obrigatório ausente: {$required}"
                );
            }
        }

        $rows = $this->db->fetchAll(
            sprintf('CALL %s(?, ?, ?)', $this->procedure),
            [
                (int) $arguments['empresa_id'],
                (string) $arguments['tratamento_nome'],
                (string) $arguments['convenio_nome'],
            ]
        );

        $result = [
            'success' => true,
            'tool' => $this->name(),
            'input' => [
                'empresa_id' => (int) $arguments['empresa_id'],
                'tratamento_nome' => (string) $arguments['tratamento_nome'],
                'convenio_nome' => (string) $arguments['convenio_nome'],
            ],

            // IMPORTANTE:
            // O Guichê NÃO tenta adivinhar nomes de colunas.
            // A Tool entrega exatamente o que a procedure retornou.
            'data' => $rows,

            'meta' => [
                'source' => 'database',
                'procedure' => $this->procedure,
                'rows' => count($rows),
                'raw' => true,
            ],
        ];

        $presentation = (new DisponibilidadeConveniosPresenter())->present($result);

        if (is_string($presentation) && trim($presentation) !== '') {
            $result['_guiche'] = [
                'presentation_message' => $presentation,
            ];
        }

        return $result;
    }
}
