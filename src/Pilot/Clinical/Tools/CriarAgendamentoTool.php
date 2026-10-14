<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Pilot\Clinical\Tools;

use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\ToolInterface;
use Throwable;

final class CriarAgendamentoTool implements ToolInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $url,
        private readonly string $token = '',
        private readonly int $timeout = 15
    ) {
    }

    public function name(): string
    {
        return 'CriarAgendamento';
    }

    public function getName(): string
    {
        return $this->name();
    }

    public function description(): string
    {
        return
            'Efetiva um agendamento já validado e confirmado '
            . 'pelo paciente. Use somente após o fluxo chegar '
            . 'a pronto_para_agendar.';
    }

    public function getDescription(): string
    {
        return $this->description();
    }

    /**
     * @return array<mixed>
     */
    public function schema(): array
    {
        return $this->schemaData();
    }

    /**
     * @return array<mixed>
     */
    public function inputSchema(): array
    {
        return $this->schemaData();
    }

    /**
     * @return array<mixed>
     */
    public function getInputSchema(): array
    {
        return $this->schemaData();
    }

    /**
     * @return array<mixed>
     */
    public function parameters(): array
    {
        return $this->schemaData();
    }

    /**
     * @return array<mixed>
     */
    public function getParameters(): array
    {
        return $this->schemaData();
    }

    /**
     * @return array<mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'parameters' => $this->schemaData(),
        ];
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    public function execute(array $arguments): array
    {
        $patientId = (int)($arguments['paciente_id'] ?? 0);
        $date = trim(
            (string)($arguments['data_inicio'] ?? '')
        );
        $time = trim(
            (string)($arguments['hora_inicio'] ?? '')
        );
        $professionalId = trim(
            (string)($arguments['profissional_id'] ?? '')
        );
        $serviceId = trim(
            (string)($arguments['servico_id'] ?? '')
        );
        $title = trim(
            (string)($arguments['titulo'] ?? '')
        );

        if ($patientId <= 0) {
            return $this->failure(
                'paciente_id inválido.',
                $arguments
            );
        }

        if (
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $date
            )
        ) {
            return $this->failure(
                'data_inicio inválida.',
                $arguments
            );
        }

        if (
            !preg_match(
                '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
                $time
            )
        ) {
            return $this->failure(
                'hora_inicio inválida.',
                $arguments
            );
        }

        if ($title === '') {
            return $this->failure(
                'titulo do agendamento não informado.',
                $arguments
            );
        }

        $url = trim($this->url);

        if ($url === '') {
            return $this->failure(
                'API de agendamento não configurada.',
                $arguments
            );
        }

        $payload = [
            'paciente_id' => $patientId,
            'titulo' => $title,
            'profissional_id' => $professionalId,
            'servico_id' => $serviceId,
            'data_inicio' => $date,
            'hora_inicio' => $time,
            'situacao_id' => 1,
            'retorno_atendimento' => 0,
        ];

        $headers = [
            'Content-Type' =>
                'application/x-www-form-urlencoded',
        ];

        $token = trim($this->token);

        if ($token !== '') {
            $headers['Authorization'] =
                str_starts_with(
                    strtolower($token),
                    'bearer '
                )
                    ? $token
                    : 'Bearer ' . $token;
        }

        try {
            $response = $this->http->postForm(
                $url,
                $payload,
                $headers,
                $this->timeout
            );
        } catch (Throwable $e) {
            return $this->failure(
                $e->getMessage(),
                $arguments
            );
        }

        $status = (int)($response['status'] ?? 0);
        $json = is_array($response['json'] ?? null)
            ? $response['json']
            : null;
        $body = (string)($response['body'] ?? '');

        $bookingId = $this->findBookingId(
            $json ?? $body
        );

        $httpOk = $status >= 200 && $status < 300;

        if (!$httpOk || $bookingId === null) {
            return [
                'success' => false,
                'tool' => $this->name(),
                'input' => [
                    'paciente_id' => $patientId,
                    'data_inicio' => $date,
                    'hora_inicio' => $time,
                    'profissional_id' => $professionalId,
                    'servico_id' => $serviceId,
                ],
                'error' => !$httpOk
                    ? sprintf(
                        'API retornou HTTP %d.',
                        $status
                    )
                    : 'API não retornou agendamento_id.',
                'meta' => [
                    'source' => 'http',
                    'status' => $status,
                    'raw' => $json ?? $body,
                ],
            ];
        }

        return [
            'success' => true,
            'tool' => $this->name(),
            'input' => [
                'paciente_id' => $patientId,
                'data_inicio' => $date,
                'hora_inicio' => $time,
                'profissional_id' => $professionalId,
                'servico_id' => $serviceId,
                'titulo' => $title,
            ],
            'data' => [
                'agendamento_id' => $bookingId,
                'paciente_id' => $patientId,
                'data_inicio' => $date,
                'hora_inicio' => $time,
                'profissional_id' => $professionalId,
                'servico_id' => $serviceId,
                'titulo' => $title,
            ],
            'meta' => [
                'source' => 'http',
                'status' => $status,
                'raw' => $json ?? $body,
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    private function schemaData(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'paciente_id' => [
                    'type' => 'integer',
                    'description' =>
                        'ID real do paciente obtido de ConsultarPaciente.',
                ],
                'data_inicio' => [
                    'type' => 'string',
                    'description' =>
                        'Data confirmada no formato YYYY-MM-DD.',
                ],
                'hora_inicio' => [
                    'type' => 'string',
                    'description' =>
                        'Hora confirmada no formato HH:MM.',
                ],
                'profissional_id' => [
                    'type' => 'string',
                    'description' =>
                        'ID do profissional como string ou vazio.',
                ],
                'servico_id' => [
                    'type' => 'string',
                    'description' =>
                        'ID do serviço como string ou vazio.',
                ],
                'titulo' => [
                    'type' => 'string',
                    'description' =>
                        'Nome real do paciente obtido de ConsultarPaciente.',
                ],
            ],
            'required' => [
                'paciente_id',
                'data_inicio',
                'hora_inicio',
                'profissional_id',
                'servico_id',
                'titulo',
            ],
            'additionalProperties' => false,
        ];
    }

    private function findBookingId(mixed $value): int|string|null
    {
        if (is_array($value)) {
            foreach (
                [
                    'agendamento_id',
                    'id_agendamento',
                ]
                as $key
            ) {
                if (
                    isset($value[$key])
                    && $value[$key] !== ''
                ) {
                    return is_numeric($value[$key])
                        ? (int)$value[$key]
                        : (string)$value[$key];
                }
            }

            foreach ($value as $nested) {
                $found = $this->findBookingId($nested);

                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        if (
            preg_match(
                '/\bagendamento_id\s*[:=]\s*["\']?([A-Za-z0-9_-]+)/ui',
                $value,
                $matches
            )
        ) {
            return is_numeric($matches[1])
                ? (int)$matches[1]
                : $matches[1];
        }

        return null;
    }

    /**
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    private function failure(
        string $message,
        array $arguments
    ): array {
        return [
            'success' => false,
            'tool' => $this->name(),
            'input' => [
                'paciente_id' =>
                    (int)($arguments['paciente_id'] ?? 0),
                'data_inicio' =>
                    (string)($arguments['data_inicio'] ?? ''),
                'hora_inicio' =>
                    (string)($arguments['hora_inicio'] ?? ''),
                'profissional_id' =>
                    (string)($arguments['profissional_id'] ?? ''),
                'servico_id' =>
                    (string)($arguments['servico_id'] ?? ''),
                'titulo' =>
                    (string)($arguments['titulo'] ?? ''),
            ],
            'error' => $message,
        ];
    }
}
