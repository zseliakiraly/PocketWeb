<?php
/*
 * PocketWeb – a vezérlőpult webszerverének (php -S) útválasztója.
 *
 * - Csak a 127.0.0.1 / localhost címre érkező kéréseket szolgálja ki (DNS rebinding elleni védelem).
 * - Csak a vezérlőpult fájljai érhetők el; a lib/, tasks/, .run/, php/, node/ stb. nem.
 */

$port = (int)($_SERVER['SERVER_PORT'] ?? 0);
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host !== '127.0.0.1:' . $port && $host !== 'localhost:' . $port) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$path = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$public = ['/', '/index.html', '/api.php', '/adminer.php', '/icon.png', '/tailwindcss.js'];

if (in_array($path, $public, true)) {
    return false;   // a beépített szerver szolgálja ki (a .php fájlokat futtatja)
}
if (strpos($path, '..') === false && preg_match('#^/vendor/[A-Za-z0-9_./-]+\.(js|css)$#', $path)) {
    return false;
}

http_response_code(404);
echo 'Not found';
return true;
