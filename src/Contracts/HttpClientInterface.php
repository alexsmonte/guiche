<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

interface HttpClientInterface
{
    public function postForm(
        string $url,
        array $data,
        array $headers = [],
        int $timeout = 15
    ): array;
}
