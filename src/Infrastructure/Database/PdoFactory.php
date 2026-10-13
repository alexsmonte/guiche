<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Infrastructure\Database;

use PDO;
use RuntimeException;
use Throwable;

final class PdoFactory
{
    public static function fromConfig(array $config): PDO
    {
        $driver = trim(
            (string) ($config['driver'] ?? 'mysql')
        );

        $dsn = trim(
            (string) ($config['dsn'] ?? '')
        );

        if ($dsn === '') {
            $dsn = self::buildDsn(
                $driver,
                $config
            );
        }

        $username = (string) (
            $config['username'] ?? ''
        );

        $password = (string) (
            $config['password'] ?? ''
        );

        try {
            return new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Falha ao conectar ao banco: '
                . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    private static function buildDsn(
        string $driver,
        array $config
    ): string {
        return match ($driver) {
            'mysql' => self::mysqlDsn($config),
            default => throw new RuntimeException(
                "Driver PDO ainda não configurado no piloto: {$driver}"
            ),
        };
    }

    private static function mysqlDsn(
        array $config
    ): string {
        $host = (string) (
            $config['host'] ?? '127.0.0.1'
        );

        $port = (int) (
            $config['port'] ?? 3306
        );

        $database = trim(
            (string) ($config['database'] ?? '')
        );

        $charset = trim(
            (string) ($config['charset'] ?? 'utf8mb4')
        );

        if ($database === '') {
            throw new RuntimeException(
                'DB_DATABASE não foi configurado.'
            );
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );
    }
}
