<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__, 2);
$vendorAutoload = $projectRoot . '/vendor/autoload.php';

if (!is_file($vendorAutoload)) {
    throw new RuntimeException(
        'vendor/autoload.php não encontrado. Execute composer install no projeto consumidor.'
    );
}

require_once $vendorAutoload;

spl_autoload_register(static function (string $class): void {
    $prefix = 'GuicheExample\\ClinicaPiloto\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/*
 * O .env específico do exemplo tem precedência.
 * O .env da raiz complementa apenas chaves ainda ausentes.
 */
Dotenv::createImmutable(__DIR__)->safeLoad();
Dotenv::createImmutable($projectRoot)->safeLoad();
