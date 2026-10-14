<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Example\Basic;

use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;

final class BasicPlugin implements PluginInterface
{
    public function register(GuicheBuilder $builder): void
    {
        $builder->registerTool(
            new ToolRegistration(
                new CatalogTool(),
                [
                    'operation' => 'read',
                    'domain' => 'basic-example',
                ]
            )
        );

        $builder->addInstructionFragment('basic.catalog', self::instructions());
    }

    private static function instructions(): string
    {
        return <<<'PROMPT'
Você está executando o exemplo básico do Guichê.

REGRAS DO EXEMPLO
- Seja breve e objetivo.
- Quando a pergunta envolver itens, serviços, catálogo ou preços, use a Tool CatalogSearch.
- Considere a Tool CatalogSearch como a fonte oficial do catálogo.
- Não invente itens, preços ou descrições que não tenham sido retornados pela Tool.
- CatalogSearch é somente leitura e não produz side effects.
PROMPT;
    }
}
