<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (preg_match('#^/core/(src|tools|installer|bin)(/|$)#', $path) === 1) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }
    $file = __DIR__ . $path;
    if (is_file($file)) {
        return false;
    }
}

require __DIR__ . '/core/src/bootstrap.php';

$app = new Atoll\App(__DIR__);
$response = $app->handle();
$response->send();
