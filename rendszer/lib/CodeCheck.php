<?php
/*
 * PocketWeb – hibakeresés a tanulóknak: hol van a hiba?
 *  - szintaxis-ellenőrzés a projekt saját PHP fájljain (egyetlen PHP folyamatban, gyorsan: token_get_all);
 *  - a Laravel naplójának (storage\logs\*.log) hibái helyükkel együtt;
 *  - kódrészlet a hibás sor körül.
 * A tasks\serve.php (a Laravel szerver indítója) használja.
 */
final class CodeCheck
{
    const MAX_FILES = 2000;
    const MAX_FILE_SIZE = 1048576;
    /** Ezekben a mappákban van a tanulók saját kódja (a vendor, storage, node_modules kimarad). */
    const LARAVEL_DIRS = ['app', 'bootstrap', 'config', 'database', 'routes', 'lang', 'public'];

    /** A projekt saját PHP fájljai (a Blade nézetek nem: azok hibáit a Laravel naplója mutatja). */
    public static function files(string $dir): array
    {
        $files = [];
        foreach (self::LARAVEL_DIRS as $sub) self::collect(pw_path($dir, $sub), $files);
        foreach (glob(pw_path($dir, '*.php')) ?: [] as $file) $files[] = $file;
        if (is_file(pw_path($dir, 'artisan'))) $files[] = pw_path($dir, 'artisan');
        return array_slice($files, 0, self::MAX_FILES);
    }

    private static function collect(string $path, array &$files): void
    {
        if (!is_dir($path) || is_link($path)) return;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || count($files) >= self::MAX_FILES) continue;
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                if (!in_array($entry, ['vendor', 'node_modules', 'storage', 'cache'], true)) self::collect($full, $files);
            } elseif (substr($entry, -4) === '.php' && substr($entry, -10) !== '.blade.php') {
                $files[] = $full;
            }
        }
    }

    /** A fájl szintaxishibája: ['line' => ..., 'message' => ...], vagy null. */
    public static function syntaxError(string $file): ?array
    {
        if (!function_exists('token_get_all')) return null;
        $size = @filesize($file);
        if ($size === false || $size > self::MAX_FILE_SIZE) return null;
        $code = @file_get_contents($file);
        if (!is_string($code)) return null;
        try {
            token_get_all($code, TOKEN_PARSE);   // a PHP értelmezője, de semmit nem futtat
        } catch (Throwable $e) {                 // ParseError
            return ['line' => $e->getLine(), 'message' => $e->getMessage()];
        }
        return null;
    }

    /**
     * Kódrészlet a hibás sor körül: [['number' => 12, 'text' => '...', 'error' => true], ...]
     * (a fájl végén túli sort – "unexpected end of file" – az utolsó sorra igazítjuk)
     */
    public static function snippet(string $file, int $line, int $before = 2, int $after = 1): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || !$lines || $line < 1) return [];
        $line = min($line, count($lines));
        $rows = [];
        for ($n = max(1, $line - $before); $n <= min(count($lines), $line + $after); $n++) {
            $text = rtrim(str_replace("\t", '    ', $lines[$n - 1]));
            if (self::length($text) > 150) $text = self::cut($text, 150) . '…';
            $rows[] = ['number' => $n, 'text' => $text, 'error' => $n === $line];
        }
        return $rows;
    }

    /** A projekthez viszonyított útvonal (pl. routes\web.php); a projekten kívüli marad teljes. */
    public static function relative(string $dir, string $file): string
    {
        $base = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $dir), '\\/') . DIRECTORY_SEPARATOR;
        $path = str_replace('/', DIRECTORY_SEPARATOR, $file);
        $same = DIRECTORY_SEPARATOR === '\\' ? strncasecmp($path, $base, strlen($base)) === 0 : strncmp($path, $base, strlen($base)) === 0;
        return $same ? substr($path, strlen($base)) : $path;
    }

    /** A tanuló saját fájlja-e (nem a vendor vagy a lefordított nézetek közül): ehhez mutatunk kódrészletet. */
    public static function isOwnFile(string $dir, string $file): bool
    {
        $relative = str_replace('\\', '/', self::relative($dir, $file));
        return $relative !== str_replace('\\', '/', $file) && !preg_match('#^(vendor|storage|node_modules|bootstrap/cache)/#', $relative);
    }

    // ------------------------------------------------------------------
    // Laravel napló
    // ------------------------------------------------------------------

    /** A napló fájlok jelenlegi mérete (innen kezdve figyeljük az új bejegyzéseket). */
    public static function logSizes(string $dir): array
    {
        $sizes = [];
        foreach (glob(pw_path($dir, 'storage', 'logs', '*.log')) ?: [] as $file) $sizes[$file] = (int)@filesize($file);
        return $sizes;
    }

    /** A napló fájlok új, teljes sorai a legutóbbi hívás óta ($sizes-t frissíti). */
    public static function newLogText(string $dir, array &$sizes): string
    {
        $text = '';
        foreach (glob(pw_path($dir, 'storage', 'logs', '*.log')) ?: [] as $file) {
            clearstatcache(true, $file);
            $size = (int)@filesize($file);
            $from = $sizes[$file] ?? 0;
            if ($size < $from) $from = 0;   // új / kiürített napló
            if ($size === $from) continue;
            $fh = @fopen($file, 'rb');
            if (!$fh) continue;
            fseek($fh, $from);
            $chunk = (string)fread($fh, min($size - $from, 4194304));
            fclose($fh);
            $end = strrpos($chunk, "\n");
            if ($end === false) continue;   // még csak egy félig kiírt sor
            $text .= substr($chunk, 0, $end + 1);
            $sizes[$file] = $from + $end + 1;
        }
        return $text;
    }

    /**
     * A Laravel napló hibái (ERROR és súlyosabb): [['time', 'level', 'message', 'file', 'line'], ...]
     * Egy bejegyzés első sora: [2026-10-12 14:30:01] local.ERROR: <üzenet> {"exception":"[object] (ParseError(code: 0):
     * <üzenet> at C:\\...\\routes\\web.php:8)
     */
    public static function logErrors(string $text): array
    {
        $errors = [];
        foreach (preg_split('/\r?\n/', $text) as $row) {
            if (!preg_match('/^\[\d{4}-\d{2}-\d{2}[ T](\d{2}:\d{2}:\d{2})[^\]]*\] [\w.-]+\.(EMERGENCY|ALERT|CRITICAL|ERROR): (.*)$/', $row, $m)) continue;
            $message = $m[3];
            $file = null;
            $line = null;
            $cut = strpos($message, ' {"exception":');
            if ($cut !== false) {
                // a kivétel helye: "... at C:\\...\\routes\\web.php:8)" (JSON-ban: duplázott \), a stack trace előtt
                $exception = substr($message, $cut);
                $stack = strpos($exception, '[stacktrace]');
                if ($stack !== false) $exception = substr($exception, 0, $stack);
                if (preg_match('/.* at ((?:[A-Za-z]:)?[^:"]+?):(\d+)\)/s', $exception, $at)) {
                    $file = str_replace(['\\\\', '\\/'], ['\\', '/'], $at[1]);
                    $line = (int)$at[2];
                }
                $message = substr($message, 0, $cut);
            }
            $errors[] = ['time' => $m[1], 'level' => $m[2], 'message' => trim($message), 'file' => $file, 'line' => $line];
        }
        return $errors;
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function cut(string $text, int $chars): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $chars, 'UTF-8') : substr($text, 0, $chars);
    }
}
