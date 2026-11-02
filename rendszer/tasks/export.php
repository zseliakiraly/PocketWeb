<?php
/*
 * Projektek exportálása egy ZIP fájlba (Import / Export ablak).
 * A ZIP a gép ideiglenes mappájában (%TEMP%\PocketWeb) készül el, és csak a kész fájl kerül át a célmappába
 * (pl. a hálózati meghajtóra): ott először <név>.zip.part néven, a másolás végén átnevezve – így félkész ZIP
 * nem marad a célmappában, és a korábbi, azonos nevű ZIP csak a sikeres másolás után cserélődik le.
 * A ZIP gyökerében a projektek mappái vannak, mellettük a pocketweb-export.json (a projektek adatai az importhoz).
 * Paraméter: a feladat leírása (rendszer\.run\transfer-*.json: sites, dest, skipDeps). Az utolsó sor a
 * vezérlőpult értesítése.
 */
require __DIR__ . '/common.php';
ini_set('memory_limit', '1024M');   // sok ezer fájl (vendor, node_modules) adatai

$specFile = (string)($argv[1] ?? '');
$spec = is_file($specFile) ? json_decode((string)file_get_contents($specFile), true) : null;
@unlink($specFile);
if (!is_array($spec) || empty($spec['sites']) || empty($spec['dest'])) fail('Hibás exportálási feladat.');
if (!class_exists('ZipArchive')) fail('A ZIP fájlhoz a PHP zip kiterjesztése kell (rendszer\\php\\php.ini: extension=zip).');

$dest = (string)$spec['dest'];
$skipDeps = !empty($spec['skipDeps']);
$part = $dest . '.part';
Transfer::cleanTemp();
$tmpZip = pw_path(Transfer::tempDir(), 'export-' . bin2hex(random_bytes(4)) . '.zip');
register_shutdown_function(function () use ($tmpZip, $part) {
    @unlink($tmpZip);
    if (is_file($part)) @unlink($part);   // hiba esetén ne maradjon félkész fájl a célmappában
    @unlink(Transfer::partRecord());
});

// 1. A projektek fájljai az ideiglenes ZIP-be
step('Projektek összegyűjtése...');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) fail('Nem sikerült létrehozni az ideiglenes ZIP fájlt: ' . $tmpZip);
$manifest = ['app' => 'PocketWeb', 'version' => PW_VERSION, 'created' => date('c'), 'projects' => []];
$totalFiles = $totalBytes = 0;
foreach ((array)$spec['sites'] as $name) {
    $name = (string)$name;
    $dir = Sites::dir($name);
    if ($dir === null) {
        warn($name . ': a projekt nem található, kimarad.');
        continue;
    }
    $type = Sites::type($dir);
    $found = Transfer::collect($dir, $type, $skipDeps);
    foreach ($found['dirs'] as $rel) $zip->addEmptyDir($name . '/' . $rel);
    foreach ($found['files'] as [$file, $rel]) {
        $entry = $name . '/' . $rel;
        if (!$zip->addFile($file, $entry)) fail('Nem sikerült a ZIP-be tenni: ' . $entry . ' (' . $zip->getStatusString() . ')');
        $zip->setCompressionName($entry, Transfer::storeOnly($rel) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE, 6);
    }
    $totalFiles += count($found['files']);
    $totalBytes += $found['bytes'];
    out(sprintf('    %s – %d fájl, %s%s', $name, count($found['files']), format_bytes($found['bytes']),
        $found['skipped'] ? ' (kihagyva: ' . implode(', ', $found['skipped']) . ')' : ''), '90');
    if ($found['unreadable']) {
        warn('    Nem olvasható, kimarad (egy program használja?): ' . implode(', ', array_slice($found['unreadable'], 0, 5))
            . (count($found['unreadable']) > 5 ? ' és még ' . (count($found['unreadable']) - 5) : ''));
    }
    $port = (int)@file_get_contents(pw_path($dir, '.ms-port'));
    $manifest['projects'][] = ['name' => $name, 'type' => $type, 'port' => $port ?: null,
        'skipped' => $found['skipped'], 'links' => $found['links']];
}
if (!$manifest['projects']) fail('Nincs exportálható projekt.');
$zip->addFromString(Transfer::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

step(sprintf('Tömörítés: %d fájl, %s...', $totalFiles, format_bytes($totalBytes)));
$withProgress = method_exists($zip, 'registerProgressCallback');
if ($withProgress) {
    $zip->registerProgressCallback(0.01, function ($rate) {
        progress(sprintf('%d%%', (int)($rate * 100)));
    });
}
if (!$zip->close()) {
    if ($withProgress) fwrite(STDOUT, "\n");
    fail('Nem sikerült elkészíteni a ZIP fájlt: ' . $zip->getStatusString());
}
clearstatcache(true, $tmpZip);
$size = (int)filesize($tmpZip);
progress('100% – ' . format_bytes($size) . ' ZIP fájl', true);

// 2. A kész ZIP a célmappába
step('Másolás a célmappába: ' . $dest);
$free = @disk_free_space(dirname($dest));   // hálózati meghajtón a tárhely-keretet (kvótát) is figyelembe veszi
if ($free !== false && $free < $size) {
    fail('Nincs elég hely a célmappában: ' . format_bytes($size) . ' kellene, ' . format_bytes((int)$free) . ' szabad.');
}
file_put_contents(Transfer::partRecord(), $part);   // ha közben leállítják (pl. Kilépés), a következő indításkor töröljük
$copied = Transfer::copyFile($tmpZip, $part, function (int $done, int $total) {
    progress(sprintf('%s / %s (%d%%)', format_bytes($done), format_bytes($total), $total > 0 ? $done * 100 / $total : 100));
}, $error);
if (!$copied) {
    fwrite(STDOUT, "\n");
    fail('A másolás nem sikerült: ' . $error);
}
progress(format_bytes($size) . ' átmásolva', true);
if (!Transfer::rename($part, $dest)) {
    fail('Nem sikerült a fájlt a végleges nevére átnevezni: ' . basename($dest) . ' (lehet, hogy egy program használja a régit).');
}
$check = new ZipArchive();
if ($check->open($dest, ZipArchive::RDONLY) !== true) fail('A célmappába került ZIP fájl nem olvasható vissza: ' . $dest);
$check->close();

done('Az exportálás elkészült: ' . $dest);
