<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Fixtures;

use Asmpkg\Guiche\Contracts\ClockInterface;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class FrozenClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $current
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function set(DateTimeImmutable $current): void
    {
        $this->current = $current;
    }

    public function advanceSeconds(int $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Seconds must be zero or positive.');
        }

        $this->current = $this->current->add(new DateInterval('PT' . $seconds . 'S'));
    }
}
