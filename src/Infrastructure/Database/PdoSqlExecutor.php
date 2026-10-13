<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Infrastructure\Database;

use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class PdoSqlExecutor implements SqlExecutorInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function fetchAll(
        string $sql,
        array $parameters = []
    ): array {
        try {
            $statement = $this->prepareAndExecute(
                $sql,
                $parameters
            );

            $rows = $statement->fetchAll();

            // Stored procedures MySQL/MariaDB podem deixar
            // result sets pendentes. Drenamos todos para que
            // a próxima consulta não resulte em "commands out of sync".
            $this->drainRowsets($statement);

            return is_array($rows)
                ? $rows
                : [];
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Erro ao executar consulta SQL: '
                . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    public function execute(
        string $sql,
        array $parameters = []
    ): int {
        try {
            $statement = $this->prepareAndExecute(
                $sql,
                $parameters
            );

            $affected = $statement->rowCount();

            $this->drainRowsets($statement);

            return $affected;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Erro ao executar comando SQL: '
                . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    private function prepareAndExecute(
        string $sql,
        array $parameters
    ): PDOStatement {
        $statement = $this->pdo->prepare($sql);

        foreach (
            array_values($parameters)
            as $index => $value
        ) {
            $position = $index + 1;

            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue(
                $position,
                $value,
                $type
            );
        }

        $statement->execute();

        return $statement;
    }

    private function drainRowsets(
        PDOStatement $statement
    ): void {
        try {
            while ($statement->nextRowset()) {
                // Apenas drena os result sets restantes.
            }
        } catch (Throwable) {
            // Alguns drivers não suportam nextRowset().
            // Não transformamos isso em falha da consulta principal.
        }

        $statement->closeCursor();
    }
}
