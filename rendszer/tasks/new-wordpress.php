<?php
/*
 * Új WordPress projekt SQLite adatbázissal.
 * Futtatja: a felügyelő, a (már létrehozott, üres) projekt mappában.
 */
require __DIR__ . '/common.php';

$name = (string)($argv[1] ?? '');
if (!Sites::validNewName($name) || !($dir = Sites::dir($name))) fail('A projekt mappa nem található.');
chdir($dir);

function abort(string $message): void
{
    global $dir;
    Sites::deleteDir($dir);   // a félkész mappát töröljük, hogy a név újra használható legyen
    fail($message);
}

step('WordPress letöltése...');
if (!download('https://wordpress.org/latest.zip', 'wp.zip')) abort('Nem sikerült letölteni a WordPresst. Van internetkapcsolat?');

step('WordPress kibontása...');
if (!unzip('wp.zip', '.')) abort('Nem sikerült kibontani a WordPresst.');
@unlink('wp.zip');

if (is_dir('wordpress')) {
    foreach (scandir('wordpress') as $file) {
        if ($file !== '.' && $file !== '..') rename('wordpress/' . $file, $file);
    }
    rmdir('wordpress');
}
if (!is_dir('wp-content/plugins')) mkdir('wp-content/plugins', 0777, true);

step('SQLite adatbázis bővítmény letöltése...');
if (!download('https://downloads.wordpress.org/plugin/sqlite-database-integration.zip', 'sqlite.zip') || filesize('sqlite.zip') < 1000) {
    abort('Nem sikerült letölteni az SQLite bővítményt.');
}
if (!unzip('sqlite.zip', 'wp-content/plugins/')) abort('Nem sikerült kibontani az SQLite bővítményt.');
@unlink('sqlite.zip');

// A bővítmény db.copy sablonjából készül a wp-content/db.php, ami a WordPresst SQLite-ra állítja
$pluginDirs = glob('wp-content/plugins/sqlite-database-integration*');
if (!$pluginDirs || !is_file($pluginDirs[0] . '/db.copy')) abort('Az SQLite bővítmény nem a várt szerkezetű.');
$pluginName = basename($pluginDirs[0]);
$dbContent = file_get_contents($pluginDirs[0] . '/db.copy');
$dbContent = str_replace("'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'", "__DIR__.'/plugins/" . $pluginName . "'", $dbContent);
$dbContent = str_replace('{SQLITE_PLUGIN}', $pluginName . '/load.php', $dbContent);
file_put_contents('wp-content/db.php', $dbContent);

if (is_file('wp-config-sample.php')) copy('wp-config-sample.php', 'wp-config.php');
file_put_contents('.ms-type', 'wordpress');

done('A WordPress települt! Indítsd el a szervert a projekt kártyáján, majd nyisd meg a böngészőben a beállítás befejezéséhez.');
