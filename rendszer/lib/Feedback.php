<?php
/*
 * PocketWeb – Névjegy és visszajelzés küldése.
 *
 * A levelet a felhasználó saját levelezője (vagy webes levelezője) küldi el a feedback@zseli.hu
 * címre, így nem kell jelszó vagy szerver. A mailto: linkhez fájl nem csatolható automatikusan,
 * ezért a naplót egy ZIP-be tesszük a visszajelzes\ mappába, és az Intézőben kijelöljük:
 * onnan egy mozdulattal a levélbe húzható.
 */
class Feedback
{
    /** A túl hosszú mailto: linket egyes levelezők (pl. Outlook) nem nyitják meg. */
    const MAILTO_LIMIT = 1800;
    /** A webes levelezők linkje is legyen ésszerű hosszú. */
    const WEBMAIL_LIMIT = 6000;
    /** Ennyi napló-ZIP marad meg a visszajelzes\ mappában. */
    const KEEP_ARCHIVES = 5;

    // ------------------------------------------------------------------
    // Verziók (Névjegy ablak, levél alja)
    // ------------------------------------------------------------------

    public static function nodeVersion(): ?string
    {
        $node = Platform::node();
        if (!$node) return null;
        $out = trim((string)@shell_exec(escapeshellarg($node) . ' -v 2>&1'));
        return preg_match('/^v?\d+\.\d+\.\d+$/', $out) ? $out : null;
    }

    public static function composerVersion(): ?string
    {
        if (!is_file(Platform::composerPhar())) return null;
        $out = (string)@shell_exec(escapeshellarg(Platform::php()) . ' ' . escapeshellarg(Platform::composerPhar()) . ' --version --no-ansi 2>&1');
        return preg_match('/Composer (?:version )?(\d+\.\d+\.\d+)/', $out, $m) ? $m[1] : null;
    }

    public static function adminerVersion(): ?string
    {
        $head = (string)@file_get_contents(pw_path(PW_SYS, 'adminer.php'), false, null, 0, 4096);
        return preg_match('/@version\s+(\d+(?:\.\d+)+)/', $head, $m) ? $m[1] : null;
    }

    /** A levél aljára kerülő rövid rendszerleírás. */
    public static function systemSummary(): array
    {
        $info = Runtime::supervisor();
        $browser = $info['browser'] ?? null;
        if (!$browser) $browser = ($info['mode'] ?? '') === 'browser' ? 'alapértelmezett böngésző' : '?';
        return [
            'PocketWeb' => PW_VERSION,
            'Windows' => Platform::windowsVersion(),
            'PHP' => PHP_VERSION,
            'Node.js' => self::nodeVersion() ?? 'nincs bemásolva',
            'Böngésző' => $browser,
        ];
    }

    // ------------------------------------------------------------------
    // A levél
    // ------------------------------------------------------------------

    /**
     * A levél tárgya, szövege és a megnyitásához szükséges linkek.
     * @return array{subject:string, body:string, mailto:string, gmail:string, outlook:string, truncated:bool}
     */
    public static function compose(string $message, string $contact, ?string $attachment): array
    {
        $subject = 'PocketWeb visszajelzés (v' . PW_VERSION . ')';
        $footer = "\r\n\r\n";
        if ($contact !== '') $footer .= 'Kapcsolat: ' . $contact . "\r\n";
        $footer .= "--\r\n";
        foreach (self::systemSummary() as $name => $value) $footer .= $name . ': ' . $value . "\r\n";
        $footer .= $attachment !== null
            ? 'Csatolt napló: ' . basename(str_replace('\\', '/', $attachment)) . "\r\n"
            : "Csatolt napló: nincs\r\n";

        $message = str_replace(["\r\n", "\r"], "\n", trim($message));
        $message = str_replace("\n", "\r\n", $message);
        $body = $message . $footer;

        // A mailto: linkbe csak annyi fér, amennyit a levelezők biztosan megnyitnak
        $note = "\r\n[…] (a teljes szöveg a vágólapon van, illeszd be ide)";
        $mailtoMessage = $message;
        $truncated = false;
        while ($mailtoMessage !== '' && strlen(self::mailto($subject, $mailtoMessage . ($truncated ? $note : '') . $footer)) > self::MAILTO_LIMIT) {
            $mailtoMessage = self::cut($mailtoMessage, (int)(self::length($mailtoMessage) * 0.85));
            $truncated = true;
        }
        if ($truncated) $mailtoMessage .= $note;

        $webBody = $body;
        if (strlen(rawurlencode($webBody)) > self::WEBMAIL_LIMIT) {
            $webBody = self::cut($message, 1500) . "\r\n[…]" . $footer;
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'mailto' => self::mailto($subject, $mailtoMessage . $footer),
            'gmail' => 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode(PW_FEEDBACK_EMAIL)
                . '&su=' . rawurlencode($subject) . '&body=' . rawurlencode($webBody),
            'outlook' => 'https://outlook.office.com/mail/deeplink/compose?to=' . rawurlencode(PW_FEEDBACK_EMAIL)
                . '&subject=' . rawurlencode($subject) . '&body=' . rawurlencode($webBody),
            'truncated' => $truncated,
        ];
    }

    /** mailto: link (RFC 6068: a szóköz %20, a sortörés %0D%0A). */
    public static function mailto(string $subject, string $body): string
    {
        return 'mailto:' . PW_FEEDBACK_EMAIL . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function cut(string $text, int $chars): string
    {
        if (function_exists('mb_substr')) return mb_substr($text, 0, max(0, $chars), 'UTF-8');
        return substr($text, 0, max(0, $chars));   // mbstring nélkül egy ékezet elvághat – ritka eset
    }

    // ------------------------------------------------------------------
    // Napló csatolmány
    // ------------------------------------------------------------------

    /**
     * Napló ZIP a visszajelzes\ mappába: rendszerinfó, a PocketWeb és a vezérlőpult naplója,
     * valamint a mostani futás feladatainak (szerverek, telepítők) kimenete.
     * @return string a létrehozott fájl teljes útvonala
     */
    public static function createLogArchive(): string
    {
        if (!is_dir(PW_FEEDBACK_DIR) && !@mkdir(PW_FEEDBACK_DIR, 0777, true)) {
            throw new RuntimeException('Nem sikerült létrehozni a mappát: ' . PW_FEEDBACK_DIR);
        }
        $files = ['rendszer-info.txt' => self::systemReport()];
        foreach (['pocketweb.log' => 524288, 'dashboard.log' => 131072, 'browser.log' => 32768] as $log => $limit) {
            $content = self::tail(Runtime::file($log), $limit);
            if ($content !== '') $files[$log] = $content;
        }
        $n = 0;
        foreach (Jobs::all() as $job) {
            $status = $job['status'];
            $header = 'Feladat: ' . $job['title'] . "\r\n"
                . 'Parancs: ' . ($job['display'] ?? '') . "\r\n"
                . 'Állapot: ' . ($status['state'] ?? '?') . (isset($status['exitCode']) ? ' (kilépési kód: ' . $status['exitCode'] . ')' : '') . "\r\n"
                . 'Indult: ' . (isset($status['startedAt']) ? date('Y-m-d H:i:s', (int)$status['startedAt']) : '-') . "\r\n"
                . str_repeat('-', 60) . "\r\n";
            $files[sprintf('feladatok/%02d-%s.txt', ++$n, self::slug($job['title']))] = $header . self::plainText(self::tail(Jobs::logFile($job['id']), 262144));
        }

        $name = 'pocketweb-naplo-' . date('Ymd-His') . '.zip';
        $path = pw_path(PW_FEEDBACK_DIR, $name);
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Nem sikerült létrehozni a napló fájlt: ' . $path);
            }
            foreach ($files as $file => $content) $zip->addFromString($file, $content);
            $zip->close();
        } else {
            // zip kiterjesztés nélkül egyetlen szöveges fájl
            $path = substr($path, 0, -4) . '.txt';
            $text = '';
            foreach ($files as $file => $content) $text .= "===== $file =====\r\n" . $content . "\r\n\r\n";
            file_put_contents($path, $text);
        }
        self::removeOldArchives();
        return $path;
    }

    /** A visszajelzes\ mappában lévő napló-e a fájl (csak ilyet engedünk megmutatni az Intézőben). */
    public static function isArchive(string $path): bool
    {
        $real = realpath($path);
        $dir = realpath(PW_FEEDBACK_DIR);
        return $real !== false && $dir !== false && is_file($real)
            && strcasecmp(dirname($real), $dir) === 0
            && preg_match('/^pocketweb-naplo-[0-9-]+\.(zip|txt)$/', basename($real)) === 1;
    }

    /** Részletes rendszerinfó a csatolt naplóba. */
    private static function systemReport(): string
    {
        $lines = ['PocketWeb visszajelzés napló – ' . date('Y-m-d H:i:s'), ''];
        $pad = function (string $label): string {
            return $label . str_repeat(' ', max(1, 12 - self::length($label)));   // ékezetes címkéknél is egy oszlopba
        };
        foreach (self::systemSummary() as $name => $value) $lines[] = $pad($name . ':') . $value;
        $lines[] = $pad('Composer:') . (self::composerVersion() ?? '-');
        $lines[] = $pad('Mappa:') . PW_ROOT;
        $lines[] = '';
        $lines[] = 'PHP kiterjesztések: ' . implode(', ', get_loaded_extensions());
        $lines[] = '';
        $lines[] = 'Projektek:';
        foreach (glob(pw_path(PW_PROJECTS, '*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $type = Sites::type($dir);
            $port = is_file(pw_path($dir, '.ms-port')) ? ' :' . trim((string)file_get_contents(pw_path($dir, '.ms-port'))) : '';
            $lines[] = '  - ' . basename($dir) . ' (' . ($type !== '' ? $type : 'ismeretlen') . $port . ')';
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /** A fájl vége legfeljebb $bytes bájtban (teljes sorokkal). */
    private static function tail(string $file, int $bytes): string
    {
        clearstatcache(true, $file);
        $size = is_file($file) ? (int)filesize($file) : 0;
        if ($size === 0) return '';
        $fh = @fopen($file, 'rb');
        if (!$fh) return '';
        if ($size > $bytes) fseek($fh, $size - $bytes);
        $data = (string)stream_get_contents($fh);
        fclose($fh);
        if ($size > $bytes && ($nl = strpos($data, "\n")) !== false) $data = '[…]' . substr($data, $nl);
        return $data;
    }

    /** A terminál kimenet olvasható szövegként: színkódok nélkül, a felülírt (\r) sorokból csak a végleges. */
    public static function plainText(string $data): string
    {
        $data = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e[()][0-9A-Za-z]|\e[=>78]/', '', $data);
        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $data)) as $line) {
            $parts = explode("\r", $line);
            $last = '';
            foreach (array_reverse($parts) as $part) {
                if ($part !== '') {
                    $last = $part;
                    break;
                }
            }
            $lines[] = rtrim($last);
        }
        return implode("\r\n", $lines);
    }

    /** Ékezet nélküli fájlnév-rész (pl. "shop · szerver :8001" → "shop-szerver-8001"). */
    public static function slug(string $text): string
    {
        $text = strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ö' => 'O', 'Ő' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U',
        ]);
        $slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-');
        return $slug !== '' ? strtolower(substr($slug, 0, 60)) : 'feladat';
    }

    private static function removeOldArchives(): void
    {
        $files = glob(pw_path(PW_FEEDBACK_DIR, 'pocketweb-naplo-*')) ?: [];
        rsort($files, SORT_STRING);   // a név dátumot tartalmaz: a legújabb elöl
        foreach (array_slice($files, self::KEEP_ARCHIVES) as $old) @unlink($old);
    }
}
