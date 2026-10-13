<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tools;

use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use RuntimeException;
use Throwable;

final class ConsultarPacienteTool implements ToolInterface
{
    public function __construct(
        private readonly SqlExecutorInterface $db,
        private readonly string $procedure
    ) {
        if (
            $this->procedure === ''
            || !preg_match('/^[A-Za-z0-9_]+$/', $this->procedure)
        ) {
            throw new RuntimeException(
                'Procedure de ConsultarPaciente inválida ou não configurada.'
            );
        }
    }

    public function name(): string
    {
        return 'ConsultarPaciente';
    }

    public function getName(): string
    {
        return $this->name();
    }

    public function description(): string
    {
        return 'Consulta um paciente real pelo CPF e empresa. '
            . 'Use para obter paciente_id real antes de qualquer agendamento. '
            . 'Nunca invente paciente_id.';
    }

    public function getDescription(): string
    {
        return $this->description();
    }

    public function schema(): array
    {
        return $this->schemaData();
    }

    public function inputSchema(): array
    {
        return $this->schemaData();
    }

    public function getInputSchema(): array
    {
        return $this->schemaData();
    }

    public function parameters(): array
    {
        return $this->schemaData();
    }

    public function getParameters(): array
    {
        return $this->schemaData();
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'name' => $this->name(),
            'description' => $this->description(),
            'parameters' => $this->schemaData(),
        ];
    }

    public function execute(array $arguments): array
    {
        $empresaId = (int)($arguments['empresa_id'] ?? 0);
        $cpf = preg_replace('/\D+/', '', (string)($arguments['cpf'] ?? ''));
        $sessaoId = trim((string)($arguments['sessao_id'] ?? ''));

        if ($empresaId <= 0) {
            return $this->failure($arguments, 'empresa_id inválido.');
        }

        if (!is_string($cpf) || strlen($cpf) !== 11) {
            return $this->failure(
                $arguments,
                'CPF deve conter exatamente 11 dígitos.'
            );
        }

        try {
            $procedureParameters = $this->procedureParameters();

            if ($procedureParameters === []) {
                return $this->failure(
                    [
                        'empresa_id' => $empresaId,
                        'cpf' => $cpf,
                        'sessao_id' => $sessaoId,
                    ],
                    sprintf(
                        'A procedure %s não foi encontrada ou não possui parâmetros identificáveis.',
                        $this->procedure
                    )
                );
            }

            $callValues = [];

            foreach ($procedureParameters as $parameter) {
                $parameterName = strtolower(
                    ltrim((string)($parameter['PARAMETER_NAME'] ?? ''), '@')
                );

                $mapped = $this->mapProcedureParameter(
                    $parameterName,
                    $empresaId,
                    $cpf,
                    $sessaoId
                );

                if (!$mapped['supported']) {
                    return $this->failure(
                        [
                            'empresa_id' => $empresaId,
                            'cpf' => $cpf,
                            'sessao_id' => $sessaoId,
                        ],
                        sprintf(
                            'Parâmetro da procedure não reconhecido com segurança: %s',
                            $parameterName !== '' ? $parameterName : '(sem nome)'
                        )
                    );
                }

                $callValues[] = $mapped['value'];
            }

            $sql = sprintf(
                'CALL `%s`(%s)',
                $this->procedure,
                implode(', ', array_fill(0, count($callValues), '?'))
            );

            $rows = $this->db->fetchAll($sql, $callValues);
            $data = $this->normalizeRows($rows, $cpf);

            $found = ($data['cadastro_encontrado'] ?? false) === true
                && ($data['paciente_id'] ?? null) !== null;

            if ($found) {
                $name = trim((string)($data['nome'] ?? ''));

                $terminalMessage = $name !== ''
                    ? sprintf(
                        'Cadastro localizado para %s. Posso confirmar o agendamento?',
                        $name
                    )
                    : 'Cadastro localizado. Posso confirmar o agendamento?';
            } else {
                $terminalMessage =
                    'Não encontrei um paciente cadastrado com esse CPF. '
                    . 'Confira o CPF e envie novamente.';
            }

            return [
                'success' => true,
                'tool' => $this->name(),
                'input' => [
                    'empresa_id' => $empresaId,
                    'cpf' => $cpf,
                    'sessao_id' => $sessaoId,
                ],
                'data' => $data,
                'meta' => [
                    'source' => 'database',
                    'procedure' => $this->procedure,
                    'procedure_parameters' => array_map(
                        static fn (array $parameter): string =>
                            (string)($parameter['PARAMETER_NAME'] ?? ''),
                        $procedureParameters
                    ),
                    'rows' => count($rows),
                    'raw_rows' => $rows,
                ],
                '_guiche' => [
                    'terminal_message' => $terminalMessage,
                ],
            ];
        } catch (Throwable $e) {
            return $this->failure(
                [
                    'empresa_id' => $empresaId,
                    'cpf' => $cpf,
                    'sessao_id' => $sessaoId,
                ],
                $e->getMessage()
            );
        }
    }

    private function schemaData(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'empresa_id' => [
                    'type' => 'integer',
                    'description' => 'ID da empresa no contexto.',
                ],
                'cpf' => [
                    'type' => 'string',
                    'description' => 'CPF informado pelo paciente.',
                ],
            ],
            'required' => [
                'empresa_id',
                'cpf',
            ],
            'additionalProperties' => false,
        ];
    }

    private function procedureParameters(): array
    {
        return $this->db->fetchAll(
            'SELECT PARAMETER_NAME, ORDINAL_POSITION, DATA_TYPE, PARAMETER_MODE
             FROM information_schema.PARAMETERS
             WHERE SPECIFIC_SCHEMA = DATABASE()
               AND SPECIFIC_NAME = ?
             ORDER BY ORDINAL_POSITION ASC',
            [$this->procedure]
        );
    }

    private function mapProcedureParameter(
        string $name,
        int $empresaId,
        string $cpf,
        string $sessaoId
    ): array {
        if (
            str_contains($name, 'empresa')
            || str_contains($name, 'company')
        ) {
            return [
                'supported' => true,
                'value' => $empresaId,
            ];
        }

        if (str_contains($name, 'cpf')) {
            return [
                'supported' => true,
                'value' => $cpf,
            ];
        }

        if (
            str_contains($name, 'sessao')
            || str_contains($name, 'session')
        ) {
            return [
                'supported' => true,
                'value' => $sessaoId,
            ];
        }

        return [
            'supported' => false,
            'value' => null,
        ];
    }

    private function normalizeRows(array $rows, string $inputCpf): array
    {
        $pacienteId = $this->findValue(
            $rows,
            ['paciente_id', 'id_paciente', 'pacienteid'],
            '/\bpaciente[_\s-]*id\s*[:=]\s*(\d+)/ui'
        );

        $nome = $this->findValue(
            $rows,
            ['nome_completo', 'nome_paciente', 'paciente_nome'],
            '/\b(?:nome(?:_completo)?|nome[\s_-]+do[\s_-]+paciente|nome[\s_-]+paciente|paciente[\s_-]+nome)\s*[:=]\s*([^\r\n\]\|;,]+)/ui'
        );

        $cpf = $this->findValue(
            $rows,
            ['cpf', 'cpf_cnpj'],
            '/\bcpf(?:_cnpj)?\s*[:=]\s*([0-9.\-]+)/ui'
        );

        $agendamentos = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $agendamentoId = $this->findValue(
                [$row],
                ['agendamento_id', 'id_agendamento', 'agendamentoid'],
                '/\bagendamento[_\s-]*id\s*[:=]\s*(\d+)/ui'
            );

            if ($agendamentoId === null) {
                continue;
            }

            $agendamento = [
                'agendamento_id' =>
                    is_numeric($agendamentoId)
                        ? (int)$agendamentoId
                        : $agendamentoId,
            ];

            $data = $this->findValue(
                [$row],
                ['data', 'data_agendamento', 'data_inicio'],
                '/\bdata(?:_agendamento|_inicio)?\s*[:=]\s*([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{2}\/[0-9]{2}\/[0-9]{4})/ui'
            );

            $hora = $this->findValue(
                [$row],
                ['hora', 'hora_agendamento', 'hora_inicio'],
                '/\bhora(?:_agendamento|_inicio)?\s*[:=]\s*([0-9]{1,2}:[0-9]{2})/ui'
            );

            $profissional = $this->findValue(
                [$row],
                ['profissional', 'profissional_nome', 'nome_profissional'],
                '/\b(?:nome_)?profissional\s*[:=]\s*([^\]\|;,]+)/ui'
            );

            $servico = $this->findValue(
                [$row],
                ['servico', 'servico_nome', 'nome_servico', 'tratamento'],
                '/\b(?:nome_)?(?:servico|tratamento)\s*[:=]\s*([^\]\|;,]+)/ui'
            );

            if ($data !== null) {
                $agendamento['data'] = $data;
            }

            if ($hora !== null) {
                $agendamento['hora'] = $hora;
            }

            if ($profissional !== null) {
                $agendamento['profissional'] = $profissional;
            }

            if ($servico !== null) {
                $agendamento['servico'] = $servico;
            }

            $agendamentos[] = $agendamento;
        }

        $pacienteIdNormalized = null;

        if ($pacienteId !== null) {
            $pacienteIdNormalized =
                is_numeric($pacienteId)
                    ? (int)$pacienteId
                    : $pacienteId;
        }

        $cpfNormalized = preg_replace(
            '/\D+/',
            '',
            (string)($cpf ?? $inputCpf)
        );

        return [
            'cadastro_encontrado' => $pacienteIdNormalized !== null,
            'paciente_id' => $pacienteIdNormalized,
            'nome' => $nome !== null ? trim((string)$nome) : null,
            'cpf' => $cpfNormalized !== '' ? $cpfNormalized : $inputCpf,
            'agendamento_encontrado' => $agendamentos !== [],
            'agendamentos' => $agendamentos,
        ];
    }

    private function findValue(
        mixed $value,
        array $keys,
        string $pattern
    ): mixed {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $normalizedKey = strtolower(
                    preg_replace(
                        '/[^a-z0-9_]+/i',
                        '_',
                        (string)$key
                    )
                );

                if (
                    in_array($normalizedKey, $keys, true)
                    && is_scalar($item)
                    && trim((string)$item) !== ''
                ) {
                    return trim((string)$item);
                }

                $found = $this->findValue($item, $keys, $pattern);

                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string)$value);

        if ($text === '') {
            return null;
        }

        if (
            (
                str_starts_with($text, '{')
                && str_ends_with($text, '}')
            )
            || (
                str_starts_with($text, '[')
                && str_ends_with($text, ']')
            )
        ) {
            $decoded = json_decode($text, true);

            if (is_array($decoded)) {
                $found = $this->findValue($decoded, $keys, $pattern);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        if (preg_match($pattern, $text, $matches)) {
            return trim((string)($matches[1] ?? ''));
        }

        return null;
    }

    private function failure(array $input, string $message): array
    {
        return [
            'success' => false,
            'tool' => $this->name(),
            'input' => $input,
            'data' => null,
            'message' => $message,
            'meta' => [
                'source' => 'database',
                'procedure' => $this->procedure,
            ],
        ];
    }
}
