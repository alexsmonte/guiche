<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

use Asmpkg\Guiche\GuicheBuilder;

/**
 * Contrato mínimo de extensão do Guichê.
 *
 * Na v0.3 o Plugin descreve/registra capacidades através do Builder.
 * Enforcement de políticas e hard execution gate pertencem à Safety Layer.
 */
interface PluginInterface
{
    public function register(GuicheBuilder $builder): void;
}
