<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Observability;

use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;

final class NullRuntimeEventSink implements RuntimeEventSinkInterface
{
    public function record(array $event): void
    {
        // Intencionalmente vazio.
    }
}
