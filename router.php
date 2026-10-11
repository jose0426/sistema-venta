<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/') {
    require __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
    exit;
}

if (is_string($path) && preg_match('~^/storage(?:/|$)~', $path)) {
    http_response_code(404);
    exit;
}

return false;
