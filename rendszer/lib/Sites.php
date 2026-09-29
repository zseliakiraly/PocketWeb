<?php
/*
 * PocketWeb – projektek (a projektek/ mappa közvetlen almappái).
 */
class Sites
{
    const TYPES = [
        'html_css'       => 'HTML + CSS',
        'html_tailwind'  => 'HTML + Tailwind',
        'html_bootstrap' => 'HTML + Bootstrap',
        'wordpress'      => 'WordPress',
        'laravel'        => 'Laravel',
    ];

    /** Új projekt neve: kisbetű, szám, kötőjel, aláhúzás (a Windows fenntartott nevei nem). */
    public static function validNewName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) === 1
            && preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])$/', $name) !== 1;
    }

    /** Meglévő projekt mappája – csak a projektek/ közvetlen, nem rejtett almappája lehet. */
    public static function dir(string $name): ?string
    {
        if ($name === '' || $name[0] === '.' || preg_match('/[\x00-\x1f"%*?<>|:\\\\\/]/', $name)) return null;
        $dir = pw_path(PW_PROJECTS, $name);
        return is_dir($dir) ? $dir : null;
    }

    /** A projekt típusa (.ms-type fájlból vagy felismeréssel); '' ha még nem kész (pl. épp települ). */
    public static function type(string $dir): string
    {
        $typeFile = pw_path($dir, '.ms-type');
        if (is_file($typeFile)) return trim((string)file_get_contents($typeFile));
        if (is_file(pw_path($dir, 'artisan'))) return 'laravel';
        if (is_file(pw_path($dir, 'wp-config.php'))) return 'wordpress';
        if (is_file(pw_path($dir, 'index.html'))) return 'html_css';
        return '';
    }

    public static function needsServer(string $type): bool
    {
        return in_array($type, ['laravel', 'wordpress'], true);
    }

    /** A projekt saját portja (.ms-port); ha még nincs, kiosztunk egyet 8000-től felfelé. */
    public static function port(string $dir): int
    {
        $portFile = pw_path($dir, '.ms-port');
        if (is_file($portFile)) return (int)file_get_contents($portFile);

        $max = 7999;
        foreach (glob(pw_path(PW_PROJECTS, '*', '.ms-port')) ?: [] as $file) {
            $max = max($max, (int)file_get_contents($file));
        }
        $port = $max + 1;
        if ($port === PW_PORT) $port++;
        file_put_contents($portFile, (string)$port);
        return $port;
    }

    /** Az SQLite adatbázis fájl helye (Laravel, WordPress). */
    public static function dbFile(string $dir, string $type): ?string
    {
        if ($type === 'laravel') return pw_path($dir, 'database', 'database.sqlite');
        if ($type === 'wordpress') return pw_path($dir, 'wp-content', 'database', '.ht.sqlite');
        return null;
    }

    /** Mappa törlése tartalmával együtt (a szimbolikus linkeket/junctionöket nem követi). */
    public static function deleteDir(string $dir): bool
    {
        if (is_link($dir)) return @unlink($dir) || @rmdir($dir);   // Windowson a junction rmdir-rel törölhető
        if (!is_dir($dir)) return @unlink($dir);
        foreach (scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path) && !is_link($path)) {
                self::deleteDir($path);
            } elseif (!@unlink($path) && !@rmdir($path)) {
                @chmod($path, 0666);   // Windows: az írásvédett fájlokat (pl. .git) így lehet törölni
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }
}
