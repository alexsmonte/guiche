<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

final class ToolPolicy
{
    public function __construct(
        private readonly array $stages = [],
        private readonly array $defaultTools = [],
        private readonly array $bootstrapTools = []
    ) {
    }

    public function allowedToolNames(array $context): array
    {
        $stage = trim((string)($context['state']['etapa'] ?? ''));

        if ($stage === '') {
            return $this->normalizeTools($this->bootstrapTools);
        }

        $allowed = array_key_exists($stage, $this->stages)
            ? $this->stages[$stage]
            : $this->defaultTools;

        return $this->normalizeTools($allowed);
    }

    public function allows(string $tool, array $context): bool
    {
        return in_array($tool, $this->allowedToolNames($context), true);
    }

    public function filterDefinitions(array $definitions, array $context): array
    {
        $allowed = array_fill_keys($this->allowedToolNames($context), true);

        if ($allowed === []) {
            return [];
        }

        return array_values(array_filter(
            $definitions,
            static function (array $definition) use ($allowed): bool {
                $name = trim((string)($definition['name'] ?? ''));
                return $name !== '' && isset($allowed[$name]);
            }
        ));
    }

    private function normalizeTools(mixed $tools): array
    {
        if (!is_array($tools)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $tool): string => trim((string)$tool),
                $tools
            ),
            static fn (string $tool): bool => $tool !== ''
        )));
    }
}
