<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Contracts;

/**
 * Contrato genérico para execução SQL.
 *
 * O restante do Guichê não precisa conhecer PDO diretamente.
 */
interface SqlExecutorInterface
{
    /**
     * Executa uma consulta e devolve todas as linhas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(
        string $sql,
        array $parameters = []
    ): array;

    /**
     * Executa INSERT/UPDATE/DELETE ou comando sem retorno tabular.
     */
    public function execute(
        string $sql,
        array $parameters = []
    ): int;
}
