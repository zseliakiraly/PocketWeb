<?php
/*
 * Projektek importálása egy ZIP fájlból (Import / Export ablak).
 * A ZIP-et először átmásolja a gép ideiglenes mappájába (a hálózati meghajtóról nem bontunk ki közvetlenül),
 * onnan egy rejtett munkamappába (projektek\.import-...) bontja ki a kiválasztott projekteket, és a kész mappát
 * nevezi át a helyére. Felülírásnál a régi projekt csak a sikeres csere után törlődik.
 * Ha a projektet még be kell üzemelni (pl. a függőségei nélkül exportálták: composer install, npm install),
 * az külön fülön folytatódik (tasks\setup-project.php): addig a kártyán "Telepítés folyamatban..." látszik.
 * Paraméter: a feladat leírása (rendszer\.run\transfer-*.json: source, items: prefix, target, overwrite).
 * Az utolsó sor a vezérlőpult értesítése.
 */
require __DIR__ . '/common.php';
ini_set('memory_limit', '1024M');   // sok ezer fájl (vendor, node_modules) adatai

$specFile = (string)($argv[1] ?? '');
$spec = is_file($specFile) ? json_decode((string)file_get_contents($specFile), true) : null;
@unlink($specFile);
if (!is_array($spec) || empty($spec['source']) || empty($spec['items'])) fail('Hibás importálási feladat.');
if (!class_exists('ZipArchive')) fail('A ZIP fájlhoz a PHP zip kiterjesztése kell (rendszer\\php\\php.ini: extension=zip).');

$source = (string)$spec['source'];
Transfer::recover();
Transfer::cleanTemp();
$tmpZip = pw_path(Transfer::tempDir(), 'import-' . bin2hex(random_bytes(4)) . '.zip');
$staging = pw_path(PW_PROJECTS, Transfer::STAGING_PREFIX . bin2hex(random_bytes(4)));
register_shutdown_function(function () use ($tmpZip) {
    @unlink($tmpZip);
    Transfer::recover();   // a munkamappa törlése (egy félbemaradt felülírásnál a régi projekt visszakerül)
});

/** A projekt kibontása a ZIP-ből a $dir mappába. @return string|null hibaüzenet */
function extract_project(ZipArchive $zip, array $project, string $dir): ?string
{
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return 'nem sikerült létrehozni a mappát: ' . $dir;
    $total = max(1, $project['bytes']);
    $done = $count = 0;
    foreach ($project['entries'] as [$index, $rel, $isDir, $size, $mtime]) {
        $path = pw_path($dir, ...explode('/', $rel));
        if ($isDir) {
            if (!is_dir($path) && !@mkdir($path, 0777, true)) return 'nem sikerült létrehozni: ' . $rel;
            continue;
        }
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0777, true)) return 'nem sikerült létrehozni: ' . dirname($rel);
        $in = method_exists($zip, 'getStreamIndex') ? $zip->getStreamIndex($index) : $zip->getStream((string)$zip->getNameIndex($index));
        if (!$in) return 'nem olvasható a ZIP-ből: ' . $rel . ' (sérült vagy jelszóval védett ZIP?)';
        $out = @fopen($path, 'wb');
        if (!$out) {
            fclose($in);
            return 'nem hozható létre: ' . $rel;
        }
        $bytes = stream_copy_to_stream($in, $out);
        fclose($in);
        if (!fclose($out) || $bytes !== $size) return 'nem sikerült kibontani: ' . $rel . ' (megtelt a meghajtó, vagy sérült a ZIP fájl)';
        if ($mtime > 0) @touch($path, $mtime);
        $done += $size;
        $count++;
        progress(sprintf('%d%% (%d / %d fájl)', $done * 100 / $total, $count, $project['files']));
    }
    progress(sprintf('100%% (%d / %d fájl)', $count, $project['files']), true);
    return null;
}

// 1. A ZIP a gépre
step('A ZIP fájl másolása a gépre: ' . $source);
$size = (int)@filesize($source);
$free = @disk_free_space(dirname($tmpZip));
if ($free !== false && $free < $size) fail('Nincs elég hely a gép ideiglenes mappájában (' . dirname($tmpZip) . ').');
$copied = Transfer::copyFile($source, $tmpZip, function (int $done, int $total) {
    progress(sprintf('%s / %s (%d%%)', format_bytes($done), format_bytes($total), $total > 0 ? $done * 100 / $total : 100));
}, $error);
if (!$copied) {
    fwrite(STDOUT, "\n");
    fail('A másolás nem sikerült: ' . $error);
}
progress(format_bytes($size) . ' átmásolva', true);

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::RDONLY) !== true) fail('A fájl nem ZIP fájl, vagy sérült: ' . basename($source));
$found = Transfer::inspect($zip, basename($source));
$byPrefix = [];
foreach ($found['projects'] as $project) $byPrefix[$project['prefix']] = $project;

$needed = 0;
foreach ($spec['items'] as $item) $needed += $byPrefix[(string)($item['prefix'] ?? '')]['bytes'] ?? 0;
$free = @disk_free_space(PW_PROJECTS);
if ($free !== false && $free < $needed + 10 * 1048576) {
    fail('Nincs elég hely a projektek meghajtóján: ' . format_bytes($needed) . ' kellene, ' . format_bytes((int)$free) . ' szabad.');
}
if ($found['unsafe']) warn($found['unsafe'] . ' fájl kimarad: a neve érvénytelen, vagy a projekt mappáján kívülre mutatna.');

// 2. A projektek kibontása a munkamappába, majd a helyükre
$imported = $failed = $setups = [];
foreach ($spec['items'] as $item) {
    $project = $byPrefix[(string)($item['prefix'] ?? '')] ?? null;
    $target = (string)($item['target'] ?? '');
    $overwrite = !empty($item['overwrite']);
    if ($project === null || !Sites::validNewName($target)) {
        warn(($target !== '' ? $target : '?') . ': nem található a ZIP-ben, kimarad.');
        $failed[] = $target;
        continue;
    }
    $type = $project['type'];
    step(sprintf('%s%s – %d fájl, %s...', $target, $overwrite ? ' (felülírás)' : '', $project['files'], format_bytes($project['bytes'])));
    $dir = pw_path($staging, $target);
    $error = extract_project($zip, $project, $dir);
    if ($error === null) {
        Transfer::prepare($dir, $type);
        [$port, $oldPort] = Transfer::assignPort($dir, $target);
        if ($oldPort !== null) {
            out('    A ' . $oldPort . '-es portot egy másik projekt használja, az új port: ' . $port, '90');
            if ($type === 'wordpress' && ($wpError = Transfer::wordpressChangePort($dir, $oldPort, $port)) !== null) {
                out('    A WordPress címét nem sikerült átírni az új portra (' . $wpError . ').', '33');
            }
        }
        $error = Transfer::moveIn($dir, $target, $overwrite);
        if ($error !== null && !$overwrite && file_exists(pw_path(PW_PROJECTS, $target))) {
            $target = Transfer::uniqueName($target);   // közben létrehoztak egy ugyanilyen nevű projektet
            $error = Transfer::moveIn($dir, $target, false);
        }
    }
    if ($error !== null) {
        out('    ' . $target . ': ' . $error, '31');
        Sites::deleteDir($dir);
        $failed[] = $target;
        continue;
    }
    $final = pw_path(PW_PROJECTS, $target);
    $label = Sites::TYPES[$type] ?? '';
    out('    ' . $target . ' – kész' . ($label !== '' ? ' (' . $label . ')' : ''), '32');
    $imported[] = $target;

    // A beüzemelés (függőségek) külön fülön, a kártyán "Telepítés folyamatban..." jelzéssel
    $steps = Transfer::setupSteps($final, $type, $project['skipped'], $project['links']);
    if ($steps) {
        $commands = ['composer' => 'composer install', 'env' => '.env', 'key' => 'php artisan key:generate', 'migrate' => 'php artisan migrate',
            'storage-link' => 'php artisan storage:link', 'npm' => 'npm install'];
        Jobs::create([
            'kind' => 'install', 'site' => $target, 'installType' => $type !== '' ? $type : 'html_css',
            'title' => $target . ' · beüzemelés',
            'display' => implode(' + ', array_map(function ($step) use ($commands) { return $commands[$step]; }, $steps)),
            'cmd' => array_merge([Platform::php(), pw_path(PW_SYS, 'tasks', 'setup-project.php'), $target],
                array_map(function ($step) { return '--' . $step; }, array_intersect($steps, ['storage-link', 'npm']))),
            'cwd' => $final,
            'env' => ['COLUMNS' => (string)(getenv('COLUMNS') ?: '120'), 'LINES' => '30'],
        ]);
        $setups[] = $target;
    }
}
$zip->close();

if (!$imported) fail('Egyetlen projektet sem sikerült importálni.');
$summary = 'Importálva: ' . implode(', ', $imported);
if ($setups) $summary .= '. A beüzemelésük (függőségek telepítése) külön fülön folytatódik: ' . implode(', ', $setups);
if ($failed) fail($summary . '. Nem sikerült: ' . implode(', ', $failed) . ' – az ok fent olvasható.');
done($summary . '.');
