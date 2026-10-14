<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$vendorAutoload = $projectRoot . '/vendor/autoload.php';

if (!is_file($vendorAutoload)) {
    throw new RuntimeException('vendor/autoload.php não encontrado. Execute composer install no projeto consumidor.');
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

\GuicheExample\ClinicaPiloto\Support\EnvLoader::load(__DIR__ . '/.env');
