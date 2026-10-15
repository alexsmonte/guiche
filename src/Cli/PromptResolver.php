<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Cli;

use RuntimeException;

final class PromptResolver
{
    public const PROJECT_PROMPT_FILE = 'guiche.prompt.md';

    public function __construct(
        private readonly string $packageRoot,
        private readonly string $projectRoot
    ) {
    }

    public function resolve(): string
    {
        $configuredFile = trim((string) $this->env(
            'GUICHE_PROMPT_FILE',
            ''
        ));

        if ($configuredFile !== '') {
            return $this->readPrompt(
                $this->promptPath($configuredFile),
                'GUICHE_PROMPT_FILE'
            );
        }

        $inline = trim((string) $this->env(
            'GUICHE_PROMPT',
            ''
        ));

        if ($inline !== '') {
            return $inline;
        }

        $projectPrompt = $this->projectRoot
            . '/'
            . self::PROJECT_PROMPT_FILE;

        if (is_file($projectPrompt)) {
            return $this->readPrompt(
                $projectPrompt,
                self::PROJECT_PROMPT_FILE
            );
        }

        $packagePrompt = $this->packageRoot
            . '/resources/guiche.prompt.md';

        return $this->readPrompt(
            $packagePrompt,
            'prompt padrão do pacote'
        );
    }

    public function source(): string
    {
        $configuredFile = trim((string) $this->env(
            'GUICHE_PROMPT_FILE',
            ''
        ));

        if ($configuredFile !== '') {
            return 'file:' . $this->promptPath(
                $configuredFile
            );
        }

        if (trim((string) $this->env(
            'GUICHE_PROMPT',
            ''
        )) !== '') {
            return 'env:GUICHE_PROMPT';
        }

        $projectPrompt = $this->projectRoot
            . '/'
            . self::PROJECT_PROMPT_FILE;

        if (is_file($projectPrompt)) {
            return 'file:' . $projectPrompt;
        }

        return 'package:resources/guiche.prompt.md';
    }

    private function promptPath(string $path): string
    {
        if (
            str_starts_with($path, '/')
            || preg_match(
                '/^[A-Za-z]:[\\\\\/]/',
                $path
            ) === 1
        ) {
            return $path;
        }

        return $this->projectRoot
            . '/'
            . ltrim($path, '/\\');
    }

    private function readPrompt(
        string $path,
        string $source
    ): string {
        if (!is_file($path)) {
            throw new RuntimeException(
                "Prompt não encontrado em {$source}: {$path}"
            );
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(
                "Não foi possível ler o prompt em {$source}: {$path}"
            );
        }

        $content = trim($content);

        if ($content === '') {
            throw new RuntimeException(
                "Prompt vazio em {$source}: {$path}"
            );
        }

        return $content;
    }

    private function env(string $name, mixed $default = null): mixed
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        if ($value === null) {
            $processValue = getenv($name);
            $value = $processValue !== false
                ? $processValue
                : null;
        }

        return $value === null || $value === ''
            ? $default
            : $value;
    }
}
