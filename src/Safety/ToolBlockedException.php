<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Safety;

use RuntimeException;
use Throwable;

/**
 * Exceção estruturada para bloqueios deliberados da Safety Layer.
 *
 * Não representa falha interna da Tool. Representa uma decisão explícita
 * do runtime de NÃO permitir que a execução alcance ToolInterface::execute().
 */
final class ToolBlockedException extends RuntimeException
{
    /**
     * @param array<mixed> $details
     */
    public function __construct(
        private readonly string $tool,
        private readonly string $reasonCode,
        string $message,
        private readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function tool(): string
    {
        return $this->tool;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    /**
     * @return array<mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'tool' => $this->tool,
            'blocked' => true,
            'reason' => $this->reasonCode,
            'error' => $this->getMessage(),
            'details' => $this->details,
        ];
    }
}
