<?php
/*
 * Új Laravel projekt: composer create-project laravel/laravel <név>
 * Futtatja: a felügyelő, a projektek/ mappában. Az api.php előre létrehozza az üres projekt mappát.
 */
require __DIR__ . '/common.php';

$name = (string)($argv[1] ?? '');
if (!Sites::validNewName($name)) fail('Érvénytelen projektnév.');
$dir = pw_path(PW_PROJECTS, $name);

step('Laravel projekt létrehozása: ' . $name);
out('    ' . $dir, '90');

$missing = array_filter(['openssl', 'mbstring', 'pdo_sqlite', 'fileinfo', 'tokenizer', 'dom', 'xml', 'ctype', 'filter', 'curl'], function ($ext) {
    return !extension_loaded($ext);
});
if ($missing) {
    warn('Hiányzó PHP kiterjesztések: ' . implode(', ', $missing));
    warn('Kapcsold be őket a rendszer\\php\\php.ini fájlban (extension=...).');
}
if (!extension_loaded('zip')) {
    warn('Nincs bekapcsolva a zip kiterjesztés (php.ini: extension=zip) – a Composer nem fogja tudni kibontani a csomagokat.');
}
$composer = Platform::composerPhar();
if (!is_file($composer)) fail('Nem található a Composer: ' . $composer);

step('Letöltés és telepítés a Composerrel (1-2 perc)...');
$code = run([Platform::php(), $composer, 'create-project', 'laravel/laravel', $name, '--prefer-dist', '--no-interaction', '--ansi'], PW_PROJECTS);

if (!is_file(pw_path($dir, 'vendor', 'autoload.php'))) {
    // A függőségek sem települtek: töröljük a félkész mappát, hogy a név újra használható legyen
    Sites::deleteDir($dir);
    fail('A Laravel telepítése nem sikerült (kilépési kód: ' . $code . '). A hiba oka fent olvasható.');
}
file_put_contents(pw_path($dir, '.ms-type'), 'laravel');

if ($code !== 0) {
    warn('A projekt elkészült, de a telepítés utáni lépések egyike hibát jelzett (lásd fent).');
    exit($code);
}
done('A Laravel projekt elkészült! A projekt kártyáján indíthatod el a szerverét.');
