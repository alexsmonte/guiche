<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

/**
 * Converte um resultado de Tool em apresentação determinística opcional.
 *
 * null significa que o Presenter não produziu uma apresentação aplicável.
 */
interface ResultPresenterInterface
{
    /**
     * @param array<mixed> $result
     */
    public function present(array $result): ?string;
}
