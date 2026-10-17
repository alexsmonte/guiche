<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Prompt;

use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Compõe o prompt global do projeto com um prompt específico do tenant.
 *
 * Estrutura esperada:
 *
 * prompts/
 * ├── comportamento.md
 * ├── 123.md
 * └── tenant-x.md
 *
 * Regras:
 * - comportamento.md é obrigatório;
 * - o prompt específico do tenant é opcional;
 * - o global sempre vem antes do específico;
 * - o tenant é obtido somente do PromptContext confiável;
 * - a chave usada como nome de arquivo é sanitizada.
 *
 * Os arquivos são relidos a cada resolve(), permitindo atualização
 * de prompt sem reconstruir o runtime.
 */
final class TenantPromptResolver
    implements PromptResolverInterface
{
    private string $promptDirectory;

    public function __construct(string $projectRoot)
    {
        $projectRoot = trim($projectRoot);

        if ($projectRoot === '') {
            throw new InvalidArgumentException(
                'TenantPromptResolver exige a raiz do projeto.'
            );
        }

        $this->promptDirectory = rtrim(
            $projectRoot,
            '/\\'
        ) . DIRECTORY_SEPARATOR . 'prompts';
    }

    public function resolve(
        PromptContext $context
    ): string {
        $globalPath = $this->promptDirectory
            . DIRECTORY_SEPARATOR
            . 'comportamento.md';

        $global = $this->readRequiredPrompt(
            $globalPath,
            'Prompt global'
        );

        $tenantId = $context->tenantId();

        if ($tenantId === null) {
            return $global;
        }

        $tenantKey = preg_replace(
            '/[^A-Za-z0-9._-]/',
            '_',
            (string)$tenantId
        );

        if ($tenantKey === null || $tenantKey === '') {
            return $global;
        }

        $tenantPath = $this->promptDirectory
            . DIRECTORY_SEPARATOR
            . $tenantKey
            . '.md';

        if (!is_file($tenantPath)) {
            return $global;
        }

        $tenant = file_get_contents($tenantPath);

        if ($tenant === false) {
            throw new RuntimeException(
                "Não foi possível ler o prompt do tenant: {$tenantPath}"
            );
        }

        $tenant = trim($tenant);

        if ($tenant === '') {
            return $global;
        }

        return $global
            . PHP_EOL
            . PHP_EOL
            . $tenant;
    }

    private function readRequiredPrompt(
        string $path,
        string $label
    ): string {
        if (!is_file($path)) {
            throw new RuntimeException(
                "{$label} não encontrado: {$path}"
            );
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(
                "Não foi possível ler {$label}: {$path}"
            );
        }

        $content = trim($content);

        if ($content === '') {
            throw new RuntimeException(
                "{$label} está vazio: {$path}"
            );
        }

        return $content;
    }
}
