<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Example\Basic;

use Asmpkg\Guiche\Contracts\ToolInterface;

final class CatalogTool implements ToolInterface
{
    /**
     * @var list<array{
     *     id: string,
     *     name: string,
     *     category: string,
     *     description: string,
     *     price: float
     * }>
     */
    private const ITEMS = [
        [
            'id' => 'srv-essential',
            'name' => 'Suporte Essencial',
            'category' => 'suporte',
            'description' => 'Atendimento por e-mail em horário comercial.',
            'price' => 49.90,
        ],
        [
            'id' => 'srv-pro',
            'name' => 'Suporte Pro',
            'category' => 'suporte',
            'description' => 'Atendimento prioritário por e-mail e chat.',
            'price' => 99.90,
        ],
        [
            'id' => 'srv-business',
            'name' => 'Suporte Business',
            'category' => 'suporte',
            'description' => 'Atendimento prioritário com acompanhamento dedicado.',
            'price' => 199.90,
        ],
    ];

    public function name(): string
    {
        return 'CatalogSearch';
    }

    public function description(): string
    {
        return 'Consulta o catálogo local do exemplo por nome, categoria ou descrição. Use esta Tool para responder perguntas sobre itens e preços do catálogo.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Texto opcional para filtrar o catálogo. Se omitido ou vazio, retorna todos os itens.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $items = self::ITEMS;

        if ($query !== '') {
            $needle = $this->normalize($query);

            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => str_contains(
                    $this->normalize(
                        $item['name'] . ' ' . $item['category'] . ' ' . $item['description']
                    ),
                    $needle
                )
            ));
        }

        return [
            'success' => true,
            'query' => $query,
            'count' => count($items),
            'items' => $items,
            'source' => 'local-example-catalog',
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
