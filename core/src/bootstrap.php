<?php

declare(strict_types=1);

$vendorCandidates = [
    dirname(__DIR__, 2) . '/vendor/autoload.php', // site layout: <root>/core/src
    dirname(__DIR__) . '/vendor/autoload.php', // core repo layout: <root>/src
];

foreach ($vendorCandidates as $vendor) {
    if (is_file($vendor)) {
        require $vendor;
        break;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Atoll\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
