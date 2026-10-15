<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Providers;

use Asmpkg\Guiche\Contracts\AiProviderInterface;
use JsonException;
use RuntimeException;

final class OpenAiResponsesProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-5.6-luna',
        private readonly string $baseUrl = 'https://api.openai.com/v1'
    ) {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('OPENAI_API_KEY não configurada.');
        }
    }

    /**
     * @param array<mixed> $messages
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function respond(string $instructions, array $messages, array $tools = []): array
    {
        $payload = [
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $messages,
            'tools' => $this->normalizeTools($tools),
            'tool_choice' => 'auto',
        ];

        return $this->normalizeResponse(
            $this->request('/responses', $payload)
        );
    }

    /**
     * @param array<mixed> $toolOutputs
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        $input = [];

        foreach ($toolOutputs as $toolOutput) {
            $input[] = [
                'type' => 'function_call_output',
                'call_id' => $toolOutput['call_id'],
                'output' => json_encode(
                    $toolOutput['output'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ];
        }

        $payload = [
            'model' => $this->model,
            'previous_response_id' => $previousResponseId,
            'instructions' => $instructions,
            'input' => $input,
            'tools' => $this->normalizeTools($tools),
            'tool_choice' => 'auto',
        ];

        return $this->normalizeResponse(
            $this->request('/responses', $payload)
        );
    }

    /**
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    private function normalizeTools(array $tools): array
    {
        $normalized = [];

        foreach ($tools as $tool) {
            $parameters = $tool['parameters'] ?? [
                'type' => 'object',
                'properties' => [],
                'required' => [],
                'additionalProperties' => false,
            ];

            if (!array_key_exists('additionalProperties', $parameters)) {
                $parameters['additionalProperties'] = false;
            }

            if (
                ($parameters['type'] ?? null) === 'object'
                && (
                    !array_key_exists('properties', $parameters)
                    || $parameters['properties'] === []
                )
            ) {
                $parameters['properties'] = new \stdClass();
            }

            $normalized[] = [
                'type' => 'function',
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $parameters,
                'strict' => false,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $response
     * @return array<mixed>
     */
    private function normalizeResponse(array $response): array
    {
        $toolCalls = [];
        $texts = [];

        foreach ($response['output'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'function_call') {
                $arguments = [];

                try {
                    $arguments = json_decode(
                        $item['arguments'] ?? '{}',
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                } catch (JsonException $exception) {
                    throw new RuntimeException(
                        'A IA retornou argumentos JSON inválidos para a ferramenta '
                        . ($item['name'] ?? 'desconhecida')
                        . ': '
                        . $exception->getMessage()
                    );
                }

                $toolCalls[] = [
                    'call_id' => $item['call_id'] ?? '',
                    'name' => $item['name'] ?? '',
                    'arguments' => is_array($arguments) ? $arguments : [],
                ];

                continue;
            }

            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    $texts[] = (string) $content['text'];
                }
            }
        }

        return [
            'id' => (string) ($response['id'] ?? ''),
            'text' => trim(implode("\n", $texts)),
            'tool_calls' => $toolCalls,
            'usage' => $response['usage'] ?? [],
        ];
    }

    /**
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function request(string $path, array $payload): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException(
                'A extensão cURL do PHP 8.4 não está habilitada. '
                . 'Verifique o pacote/extensão php8.4-curl.'
            );
        }

        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);

        if ($ch === false) {
            throw new RuntimeException('Não foi possível inicializar o cURL.');
        }

        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new RuntimeException('Erro HTTP/cURL ao chamar a IA: ' . $error);
        }

        if (!is_string($raw)) {
            curl_close($ch);

            throw new RuntimeException(
                'Resposta HTTP inválida ao chamar a IA.'
            );
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Resposta inválida da API de IA: ' . $exception->getMessage()
            );
        }

        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message']
                ?? $decoded['message']
                ?? 'Erro desconhecido';

            throw new RuntimeException(
                "OpenAI API HTTP {$status}: {$message}"
            );
        }

        return $decoded;
    }
}
