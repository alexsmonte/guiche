<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Pilot\Clinical\Tools;

use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use InvalidArgumentException;
use RuntimeException;

final class ConsultarProfissionalTool implements ToolInterface
{
    public function __construct(
        private readonly SqlExecutorInterface $db,
        private readonly string $procedure = 'sp_consulta_profissional'
    ) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->procedure)) {
            throw new InvalidArgumentException(
                'Nome de procedure inválido.'
            );
        }
    }

    public function name(): string
    {
        return 'ConsultarProfissional';
    }

    public function description(): string
    {
        return 'Resolve no banco o profissional escolhido pelo paciente '
            . 'e retorna seu identificador real.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'empresa_id' => [
                    'type' => 'integer',
                    'description' => 'Identificador da empresa.',
                ],
                'nome_profissional' => [
                    'type' => 'string',
                    'description' => 'Nome do profissional escolhido.',
                ],
            ],
            'required' => [
                'empresa_id',
                'nome_profissional',
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments): array
    {
        foreach (
            ['empresa_id', 'nome_profissional']
            as $required
        ) {
            if (
                !isset($arguments[$required])
                || $arguments[$required] === ''
            ) {
                throw new InvalidArgumentException(
                    "Parâmetro obrigatório ausente: {$required}"
                );
            }
        }

        $empresaId = (int) $arguments['empresa_id'];
        $nome = trim((string) $arguments['nome_profissional']);

        $rows = $this->db->fetchAll(
            sprintf(
                'CALL %s(?, ?)',
                $this->procedure
            ),
            [
                $empresaId,
                $nome,
            ]
        );

        if ($rows === []) {
            return [
                'success' => false,
                'tool' => $this->name(),
                'input' => [
                    'empresa_id' => $empresaId,
                    'nome_profissional' => $nome,
                ],
                'data' => null,
                'error' => 'Profissional não encontrado.',
                'meta' => [
                    'source' => 'database',
                    'procedure' => $this->procedure,
                    'rows' => 0,
                    'raw_rows' => [],
                ],
            ];
        }

        $parsed = $this->parseRows($rows);

        if ($parsed === null) {
            throw new RuntimeException(
                'A procedure respondeu, mas o Guichê não conseguiu extrair '
                . 'profissional_id e nome_completo do retorno.'
            );
        }

        return [
            'success' => true,
            'tool' => $this->name(),
            'input' => [
                'empresa_id' => $empresaId,
                'nome_profissional' => $nome,
            ],
            'data' => [
                'profissional_id' => $parsed['profissional_id'],
                'nome' => $parsed['nome'],
            ],
            'meta' => [
                'source' => 'database',
                'procedure' => $this->procedure,
                'rows' => count($rows),
                'raw_rows' => $rows,
            ],
        ];
    }

    private function parseRows(array $rows): ?array
    {
        foreach ($rows as $row) {
            /*
             * Formato real observado:
             *
             * [
             *   "profissionais" =>
             *   "[ profissional_id: 574 nome_completo: Larissa Uemi Yoqui ]"
             * ]
             */

            if (isset($row['profissionais'])) {
                $parsed = $this->parseProfessionalString(
                    (string) $row['profissionais']
                );

                if ($parsed !== null) {
                    return $parsed;
                }
            }

            /*
             * Fallback: caso a procedure seja melhorada futuramente
             * e passe a devolver colunas estruturadas.
             */
            $id = $row['profissional_id']
                ?? $row['id_profissional']
                ?? $row['id']
                ?? null;

            $name = $row['nome_completo']
                ?? $row['nome_profissional']
                ?? $row['nome']
                ?? null;

            if (
                $id !== null
                && $name !== null
                && trim((string) $name) !== ''
            ) {
                return [
                    'profissional_id' => is_numeric($id)
                        ? (int) $id
                        : $id,
                    'nome' => trim((string) $name),
                ];
            }
        }

        return null;
    }

    private function parseProfessionalString(string $value): ?array
    {
        $value = trim($value);

        if (
            !preg_match(
                '/profissional_id\s*:\s*(\d+)\s+nome_completo\s*:\s*(.*?)\s*\]?\s*$/ui',
                $value,
                $matches
            )
        ) {
            return null;
        }

        return [
            'profissional_id' => (int) $matches[1],
            'nome' => trim($matches[2]),
        ];
    }
}
