<?php
/*
 * Képernyőkép a projekt weboldaláról (thumbnail.png) egy fej nélküli (headless) böngészővel.
 * Az utolsó kiírt sor a vezérlőpulton felugró üzenet lesz.
 */
require __DIR__ . '/common.php';

$name = (string)($argv[1] ?? '');
$dir = Sites::dir($name);
if (!$dir) fail('A projekt nem található.');

$type = Sites::type($dir);
if (Sites::needsServer($type)) {
    $port = (int)@file_get_contents(pw_path($dir, '.ms-port'));
    if (!$port || !Platform::isListening($port, Platform::listeningPorts())) fail('A weboldal nem fut, indítsd el a szervert!');
    $url = 'http://' . PW_HOST . ':' . $port . '/';
} else {
    $index = pw_path($dir, 'index.html');
    if (!is_file($index)) fail('A projektben nincs index.html.');
    $url = Platform::fileUrl($index);
}

$browser = Platform::findBrowser();
if (!$browser) fail('A képernyőképhez Microsoft Edge vagy Google Chrome kell.');

@mkdir(PW_RUN, 0777, true);
$tmp = pw_path(PW_RUN, 'thumb-' . getmypid() . '.png');
@unlink($tmp);
$cmd = [$browser['path'], '--headless', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check',
    '--allow-file-access-from-files', '--window-size=1024,768', '--user-data-dir=' . pw_path(PW_RUN, 'thumb-profile'),
    '--screenshot=' . $tmp, $url];

$pipes = [];
$proc = proc_open($cmd, [0 => ['null'], 1 => ['null'], 2 => ['null']], $pipes, PW_RUN);
if (!is_resource($proc)) fail('Nem sikerült elindítani a böngészőt.');
$deadline = microtime(true) + 40;
while (($status = proc_get_status($proc))['running'] && microtime(true) < $deadline) usleep(200000);
if ($status['running']) {
    Platform::killTree([$status['pid']]);
    fail('A böngésző nem végzett időben a képernyőképpel.');
}

if (!is_file($tmp) || filesize($tmp) < 100) fail('A böngésző nem tudta elkészíteni a képet.');
$dest = pw_path($dir, 'thumbnail.png');
if (!@rename($tmp, $dest)) {
    copy($tmp, $dest);
    @unlink($tmp);
}
done('Képernyőkép sikeresen frissítve!');
