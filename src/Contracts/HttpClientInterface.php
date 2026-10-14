<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

interface HttpClientInterface
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
    ): array;
}
