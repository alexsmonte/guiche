<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Infrastructure\Http;

use Asmpkg\Guiche\Contracts\HttpClientInterface;
use RuntimeException;

final class CurlHttpClient implements HttpClientInterface
{
    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     * @return array<mixed>
     */
    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array {
        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException(
                'URL HTTP não configurada.'
            );
        }

        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException(
                'Não foi possível inicializar cURL.'
            );
        }

        $headerLines = [];

        foreach ($headers as $name => $value) {
            $name = trim((string)$name);
            $value = trim((string)$value);

            if ($name === '' || $value === '') {
                continue;
            }

            $headerLines[] = $name . ': ' . $value;
        }

        $hasContentType = false;

        foreach ($headerLines as $headerLine) {
            if (
                str_starts_with(
                    strtolower($headerLine),
                    'content-type:'
                )
            ) {
                $hasContentType = true;
                break;
            }
        }

        if (!$hasContentType) {
            $headerLines[] =
                'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(
                    $data,
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                ),
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(
                    max($timeout, 1),
                    30
                ),
                CURLOPT_TIMEOUT => max($timeout, 1),
                CURLOPT_HEADER => false,
            ]
        );

        $body = curl_exec($curl);

        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);

            throw new RuntimeException(
                'Erro HTTP: ' . $error
            );
        }

        $status = (int)curl_getinfo(
            $curl,
            CURLINFO_RESPONSE_CODE
        );

        curl_close($curl);

        $json = null;

        if (trim((string)$body) !== '') {
            $decoded = json_decode(
                (string)$body,
                true
            );

            if (
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
            ) {
                $json = $decoded;
            }
        }

        return [
            'status' => $status,
            'body' => (string)$body,
            'json' => $json,
        ];
    }
}
