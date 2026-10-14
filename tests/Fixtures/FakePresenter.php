<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Fixtures;

use Asmpkg\Guiche\Contracts\ResultPresenterInterface;

final class FakePresenter implements ResultPresenterInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function __construct(
        private readonly ?string $presentation = 'Presented'
    ) {
    }

    public function present(array $result): ?string
    {
        $this->calls[] = $result;

        return $this->presentation;
    }
}
