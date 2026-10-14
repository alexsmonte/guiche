<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

final class ToolPolicy
{
    /**
     * @param array<mixed> $bootstrapTools
     * @param array<mixed> $defaultTools
     * @param array<mixed> $stages
     */
    public function __construct(
        private readonly array $stages = [],
        private readonly array $defaultTools = [],
        private readonly array $bootstrapTools = [],
        private readonly string $contextPath = 'state.stage'
    ) {
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public function allowedToolNames(array $context): array
    {
        $stage = trim((string)$this->readContextValue($context));

        if ($stage === '') {
            return $this->normalizeTools($this->bootstrapTools);
        }

        $allowed = array_key_exists($stage, $this->stages)
            ? $this->stages[$stage]
            : $this->defaultTools;

        return $this->normalizeTools($allowed);
    }

    /**
     * @param array<mixed> $context
     */
    public function allows(string $tool, array $context): bool
    {
        return in_array($tool, $this->allowedToolNames($context), true);
    }

    /**
     * @param array<mixed> $context
     * @param array<mixed> $definitions
     * @return array<mixed>
     */
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

    /**
     * @param array<mixed> $context
     */
    private function readContextValue(array $context): mixed
    {
        $path = trim($this->contextPath);

        if ($path === '') {
            return null;
        }

        $value = $context;

        foreach (explode('.', $path) as $segment) {
            $segment = trim($segment);

            if (
                $segment === ''
                || !is_array($value)
                || !array_key_exists($segment, $value)
            ) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
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
