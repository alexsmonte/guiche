<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

interface RuntimeEventSinkInterface
{
    /**
     * @param array<mixed> $event
     */
    public function record(array $event): void;
}
