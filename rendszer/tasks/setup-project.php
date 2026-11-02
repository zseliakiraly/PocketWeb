<?php
/*
 * Importált projekt beüzemelése – az import indítja, ha kell (pl. a projektet a függőségei nélkül exportálták,
 * vagy nem a PocketWebből származik): composer install, npm install, Laravelnél a .env fájl, az alkalmazás
 * kulcsa (APP_KEY), az SQLite adatbázis és a public\storage link.
 * Paraméterek: projekt név, --npm (npm install), --storage-link (php artisan storage:link).
 */
require __DIR__ . '/common.php';

$name = (string)($argv[1] ?? '');
$dir = Sites::dir($name);
if ($dir === null) fail('A projekt nem található: ' . $name);
$flags = array_slice($argv, 2);
$type = Sites::type($dir);
$php = Platform::php();
$problems = [];
step('A(z) ' . $name . ' projekt beüzemelése');

if (is_file(pw_path($dir, 'composer.json')) && !is_file(pw_path($dir, 'vendor', 'autoload.php'))) {
    step('PHP függőségek telepítése: composer install (internet kell, 1-2 perc)...');
    $composer = Platform::composerPhar();
    if (!is_file($composer)) {
        $problems[] = 'nem található a Composer (' . $composer . ')';
    } elseif (run([$php, $composer, 'install', '--no-interaction', '--ansi'], $dir) !== 0 || !is_file(pw_path($dir, 'vendor', 'autoload.php'))) {
        $problems[] = 'a composer install nem sikerült';
    }
}

if ($type === 'laravel') {
    $env = pw_path($dir, '.env');
    if (!is_file($env) && is_file(pw_path($dir, '.env.example'))) {
        step('A .env fájl létrehozása a .env.example alapján...');
        copy(pw_path($dir, '.env.example'), $env);
    }
    if (is_file(pw_path($dir, 'vendor', 'autoload.php')) && is_file(pw_path($dir, 'artisan'))) {
        if (is_file($env) && preg_match('/^APP_KEY=\s*$/m', (string)file_get_contents($env))) {
            step('Alkalmazás kulcs: php artisan key:generate');
            if (run([$php, 'artisan', 'key:generate', '--ansi'], $dir) !== 0) $problems[] = 'a key:generate nem sikerült';
        }
        $db = Transfer::laravelSqliteFile($dir);
        if ($db !== null && !is_file($db)) {
            step('Az SQLite adatbázis létrehozása: php artisan migrate');
            if (!is_dir(dirname($db))) @mkdir(dirname($db), 0777, true);
            touch($db);
            if (run([$php, 'artisan', 'migrate', '--force', '--ansi'], $dir) !== 0) $problems[] = 'a migrate nem sikerült';
        }
        if (in_array('--storage-link', $flags, true) && !file_exists(pw_path($dir, 'public', 'storage'))) {
            step('A feltöltött fájlok linkje: php artisan storage:link');
            if (run([$php, 'artisan', 'storage:link', '--ansi'], $dir) !== 0) $problems[] = 'a storage:link nem sikerült';
        }
    }
}

if (in_array('--npm', $flags, true) && is_file(pw_path($dir, 'package.json')) && !is_dir(pw_path($dir, 'node_modules'))) {
    $node = Platform::node();
    $npm = $node !== null ? pw_path(dirname($node), 'node_modules', 'npm', 'bin', 'npm-cli.js') : null;
    if ($npm === null || !is_file($npm)) {
        $problems[] = 'az npm install-hoz be kell másolni a Node.js-t (rendszer\\node)';
    } else {
        step('JavaScript függőségek telepítése: npm install...');
        if (run([$node, $npm, 'install', '--no-fund', '--no-audit'], $dir) !== 0) $problems[] = 'az npm install nem sikerült';
    }
}

if ($problems) {
    fail('A(z) ' . $name . ' beüzemelése nem sikerült teljesen: ' . implode('; ', $problems)
        . '. Van internetkapcsolat? Később a projekt parancssorából is futtathatod (pl. composer install).');
}
done('A(z) ' . $name . ' projekt használatra kész!');
