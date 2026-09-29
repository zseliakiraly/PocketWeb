<?php
/*
 * PocketWeb – közös segédfüggvények a háttérfeladatokhoz (tasks/*.php).
 * Ezeket a felügyelő futtatja; minden kiírás a vezérlőpult Terminál paneljén jelenik meg.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_time_limit(0);

function out(string $text, string $color = '0'): void
{
    fwrite(STDOUT, "\033[" . $color . 'm' . $text . "\033[0m\n");
}

function step(string $text): void
{
    out('[*] ' . $text, '36');
}

function done(string $text): void
{
    out('[+] ' . $text, '32');
}

function warn(string $text): void
{
    out('[!] ' . $text, '33');
}

function fail(string $text, int $code = 1): void
{
    out('[x] ' . $text, '31');
    exit($code);
}

/**
 * Program futtatása úgy, hogy a kimenete közvetlenül a Terminál panelre kerüljön. Visszaad: kilépési kód.
 * A stdout/stderr leírót szándékosan nem adjuk meg: így a gyerek változatlanul örökli a miénket.
 * (Ha STDOUT-ot adnánk át, a PHP a közös fájlpozíciót 0-ra állítaná, és a kimenet felülírná a napló elejét.)
 */
function run(array $cmd, ?string $cwd = null): int
{
    $pipes = [];
    $proc = proc_open($cmd, [0 => ['null']], $pipes, $cwd);
    return is_resource($proc) ? proc_close($proc) : 127;
}

/** Fájl letöltése folyamatjelzővel. */
function download(string $url, string $dest): bool
{
    $shown = 0.0;
    $ok = pw_download($url, $dest, function (int $bytes, int $total) use (&$shown) {
        if (microtime(true) - $shown < 0.3) return;
        $shown = microtime(true);
        $text = $total > 0
            ? sprintf('%.1f / %.1f MB (%d%%)', $bytes / 1048576, $total / 1048576, $bytes * 100 / $total)
            : sprintf('%.1f MB', $bytes / 1048576);
        fwrite(STDOUT, "\r    " . $text . '   ');
    }, $error);
    if (!$ok) {
        fwrite(STDOUT, "\n");
        warn('Letöltési hiba: ' . $error);
        return false;
    }
    fwrite(STDOUT, "\r    " . sprintf('%.1f MB letöltve', filesize($dest) / 1048576) . "                    \n");
    return true;
}

/** ZIP kibontása: PHP zip kiterjesztéssel, ha nincs, az unzip paranccsal (Linux/macOS). */
function unzip(string $zipFile, string $target): bool
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) return false;
        $ok = $zip->extractTo($target);
        $zip->close();
        return $ok;
    }
    if (!Platform::isWindows() && Platform::which('unzip')) {
        return run(['unzip', '-q', '-o', $zipFile, '-d', $target]) === 0;
    }
    warn('A kibontáshoz a PHP zip kiterjesztése (vagy Linuxon/macOS-en az unzip parancs) szükséges.');
    return false;
}
