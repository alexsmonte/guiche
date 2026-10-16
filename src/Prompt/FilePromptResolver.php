<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Prompt;

use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolve prompt a partir de arquivo.
 *
 * O arquivo é lido em cada resolve(), permitindo atualizar o prompt
 * sem reconstruir o objeto Guiche.
 */
final class FilePromptResolver
    implements PromptResolverInterface
{
    private string $path;
    private ?string $basePath;

    public function __construct(
        string $path,
        ?string $basePath = null
    ) {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException(
                'FilePromptResolver exige um caminho não vazio.'
            );
        }

        $this->path = $path;

        if ($basePath === null) {
            $this->basePath = null;

            return;
        }

        $basePath = trim($basePath);

        $this->basePath = $basePath !== ''
            ? rtrim($basePath, '/\\')
            : null;
    }

    public function resolve(
        PromptContext $context
    ): string {
        $path = $this->resolvedPath();

        if (!is_file($path)) {
            throw new RuntimeException(
                "Prompt não encontrado em arquivo: {$path}"
            );
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(
                "Não foi possível ler o prompt em arquivo: {$path}"
            );
        }

        $content = trim($content);

        if ($content === '') {
            throw new RuntimeException(
                "Prompt vazio em arquivo: {$path}"
            );
        }

        return $content;
    }

    private function resolvedPath(): string
    {
        if ($this->isAbsolutePath($this->path)) {
            return $this->path;
        }

        if ($this->basePath === null) {
            return $this->path;
        }

        return $this->basePath
            . DIRECTORY_SEPARATOR
            . ltrim(
                $this->path,
                '/\\'
            );
    }

    private function isAbsolutePath(
        string $path
    ): bool {
        return str_starts_with($path, '/')
            || preg_match(
                '/^[A-Za-z]:[\\\\\/]/',
                $path
            ) === 1;
    }
}
