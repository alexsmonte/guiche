<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Adapters;

use Asmpkg\Guiche\Contracts\HttpClientInterface;
use JsonException;

final class FakeHttpClient implements HttpClientInterface
{
    private array $calls = [];

    public function __construct(private readonly array $fixtures)
    {
    }

    /**
     * @throws JsonException
     */
    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        $this->calls[] = [
            'url' => $url,
            'data' => $data,
            'headers' => $headers,
            'timeout' => $timeout,
        ];

        $http = is_array($this->fixtures['http'] ?? null)
            ? $this->fixtures['http']
            : [];

        $json = is_array($http['json'] ?? null)
            ? $http['json']
            : ['result' => 'success', 'agendamento_id' => 'fake-agendamento-001'];

        return [
            'status' => (int)($http['status'] ?? 200),
            'body' => json_encode(
                $json,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'json' => $json,
            'headers' => [],
        ];
    }

    public function calls(): array
    {
        return $this->calls;
    }
}
