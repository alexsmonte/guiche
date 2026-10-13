<?php

declare(strict_types=1);

namespace Example\GuicheNeutral;

use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;

final class NeutralPlugin implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder
            ->registerTool(
                new ToolRegistration(
                    new NeutralPingTool(),
                    [
                        'plugin' => self::class,
                        'domain' => 'neutral',
                        'operation' => 'read',
                    ]
                )
            )
            ->registerPresenter(
                'NeutralPing',
                new NeutralPresenter()
            )
            ->useStateManager(
                new NeutralStateManager()
            )
            ->addInstructionFragment(
                'neutral.instructions',
                'PLUGIN NEUTRO ATIVO. Use NeutralPing quando precisar validar a integração.'
            );
    }
}
