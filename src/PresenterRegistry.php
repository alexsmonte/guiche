<?php

declare(strict_types=1);

namespace Asmpkg\Guiche;

use Asmpkg\Guiche\Contracts\ResultPresenterInterface;
use InvalidArgumentException;
use LogicException;

/**
 * Registro de presenters associados ao nome lógico de uma Tool.
 *
 * Nesta fase o registry apenas registra e resolve presenters.
 * A integração automática com o loop de execução do Guichê será feita
 * pelas próximas fases da v0.3.
 */
final class PresenterRegistry
{
    /** @var array<string, ResultPresenterInterface> */
    private array $presenters = [];

    public function register(
        string $toolName,
        ResultPresenterInterface $presenter
    ): self {
        $toolName = trim($toolName);

        if ($toolName === '') {
            throw new InvalidArgumentException(
                'O nome da ferramenta do Presenter não pode ser vazio.'
            );
        }

        if (isset($this->presenters[$toolName])) {
            throw new LogicException(
                "Conflito de Presenter: '{$toolName}' já possui Presenter registrado."
            );
        }

        $this->presenters[$toolName] = $presenter;

        return $this;
    }

    public function has(string $toolName): bool
    {
        return isset($this->presenters[$toolName]);
    }

    public function get(string $toolName): ResultPresenterInterface
    {
        if (!$this->has($toolName)) {
            throw new InvalidArgumentException(
                "Presenter não registrado para a ferramenta: {$toolName}"
            );
        }

        return $this->presenters[$toolName];
    }

    public function present(string $toolName, array $result): ?string
    {
        if (!$this->has($toolName)) {
            return null;
        }

        return $this->presenters[$toolName]->present($result);
    }

    public function names(): array
    {
        return array_keys($this->presenters);
    }

    /**
     * @return array<string, ResultPresenterInterface>
     */
    public function all(): array
    {
        return $this->presenters;
    }
}
