<?php

declare(strict_types=1);

namespace Example\GuicheNeutral;

use Asmpkg\Guiche\Contracts\ResultPresenterInterface;

final class NeutralPresenter implements ResultPresenterInterface
{
    public function present(array $result): ?string
    {
        if (($result['success'] ?? false) !== true) {
            return null;
        }

        $echo = trim(
            (string)($result['data']['echo'] ?? '')
        );

        if ($echo === '') {
            return null;
        }

        return 'Eco neutro: ' . $echo;
    }
}
