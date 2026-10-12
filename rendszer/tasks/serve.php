<?php
/*
 * Laravel szerver (php artisan serve) a Terminál panelhez, a tanulóknak szóló hibajelzésekkel:
 *  - indítás előtt szintaxis-ellenőrzés a projekt saját PHP fájljain (app, bootstrap, config, database,
 *    routes...): ha valamelyikben hiba van, a fájl, a sor és a kódrészlet megjelenik a kimenetben;
 *  - futás közben a Laravel naplójába (storage\logs) kerülő hibák is megjelennek a helyükkel együtt
 *    (a php artisan serve ezeket nem írja ki, csak a böngészőben látszanak);
 *  - ha a szerver hibával áll le, az utolsó sor összefoglalja az okát (ezt mutatja a vezérlőpult is).
 * Paraméterek: projekt név, port. A felügyelő futtatja, a projekt mappájában.
 */
require __DIR__ . '/common.php';

$name = (string)($argv[1] ?? '');
$port = (int)($argv[2] ?? 0);
$dir = Sites::dir($name);
if ($dir === null || $port <= 0) fail('Érvénytelen projekt vagy port.');

/** Egy hiba kiírása: hely (kattintható), üzenet, és a saját fájloknál kódrészlet. */
function show_problem(string $dir, string $title, string $message, ?string $file, ?int $line, string $color): void
{
    $where = $file !== null ? ' – ' . CodeCheck::relative($dir, $file) . ($line ? ', ' . $line . '. sor' : '') : '';
    out($title . $where, $color);
    out('    ' . $message, $color);
    if ($file === null || !$line || !CodeCheck::isOwnFile($dir, $file)) return;
    $rows = CodeCheck::snippet($file, $line);
    $width = $rows ? strlen((string)end($rows)['number']) : 1;
    foreach ($rows as $row) {
        $text = sprintf('    %s %' . $width . 'd │ %s', $row['error'] ? '→' : ' ', $row['number'], $row['text']);
        out($text, $row['error'] ? '1;31' : '90');
    }
}

// 1. Szintaxis-ellenőrzés (gyors: egyetlen folyamatban, semmit nem futtat)
$shown = [];      // a már megmutatott hibák helye (fájl:sor), hogy a napló ne ismételje
$firstSyntax = null;
foreach (CodeCheck::files($dir) as $file) {
    $error = CodeCheck::syntaxError($file);
    if ($error === null) continue;
    if (count($shown) >= 5) {
        warn('…és még több fájlban van szintaxishiba.');
        break;
    }
    show_problem($dir, '[!] Szintaxishiba', $error['message'], $file, $error['line'], '33');
    $shown[strtolower($file) . ':' . $error['line']] = true;
    $firstSyntax = $firstSyntax ?? ['message' => $error['message'], 'file' => $file, 'line' => $error['line']];
}
if ($shown) out('');

// 2. A szerver; közben a Laravel napló figyelése
$logs = CodeCheck::logSizes($dir);
$pipes = [];
$proc = proc_open([Platform::php(), 'artisan', 'serve', '--host=' . PW_HOST, '--port=' . $port, '--ansi'], [0 => ['null']], $pipes, $dir);
if (!is_resource($proc)) fail('Nem sikerült elindítani: php artisan serve');

$running = false;     // figyel-e már a porton (a kapcsolódás próbát a Laravel nem írja ki)
$lastError = null;
$previous = '';
do {
    usleep(400000);
    $status = proc_get_status($proc);
    if (!$running && $status['running'] && ($socket = @fsockopen(PW_HOST, $port, $errno, $errstr, 0.3))) {
        fclose($socket);
        $running = true;
    }
    foreach (CodeCheck::logErrors(CodeCheck::newLogText($dir, $logs)) as $error) {
        $lastError = $error;
        $key = $error['file'] !== null ? strtolower($error['file']) . ':' . $error['line'] : null;
        if ($key !== null && isset($shown[$key])) continue;   // a szintaxis-ellenőrzés már megmutatta
        $signature = $error['message'] . '|' . $key;
        if ($signature === $previous) continue;               // ugyanaz a hiba közvetlenül egymás után
        $previous = $signature;
        show_problem($dir, '[x] Hiba (' . $error['time'] . ')', $error['message'], $error['file'], $error['line'], '31');
    }
} while ($status['running']);
$code = (int)$status['exitcode'];
proc_close($proc);
if ($code === 0) exit(0);

// 3. Összefoglaló az utolsó sorban (a vezérlőpult ezt mutatja értesítésként)
$cause = $lastError ?? $firstSyntax;
$what = $running ? 'A szerver hibával leállt' : 'A szerver nem indult el';
if ($cause !== null) {
    $where = $cause['file'] !== null ? ' (' . CodeCheck::relative($dir, $cause['file']) . ($cause['line'] ? ', ' . $cause['line'] . '. sor' : '') . ')' : '';
    fail($what . ': ' . $cause['message'] . $where, $code);
}
fail($what . ' (kilépési kód: ' . $code . '). Az oka fent olvasható.', $code);
