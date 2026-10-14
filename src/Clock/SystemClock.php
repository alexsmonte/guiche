<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Clock;

use Asmpkg\Guiche\Contracts\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );
    }
}
