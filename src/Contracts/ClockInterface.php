<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
