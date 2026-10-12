<?php
/*
 * PocketWeb – Névjegy és visszajelzés küldése.
 *
 * A levelet a felhasználó saját levelezője (vagy webes levelezője) küldi el a feedback@zseli.hu
 * címre, így nem kell jelszó vagy szerver. A napló egy ZIP-be kerül a visszajelzes\ mappába:
 *  - ha a levelezőprogram tudja (MAPI: klasszikus Outlook, Thunderbird), csatolmányként megy (Mapi.php);
 *  - különben a napló kivonata a levél szövegébe kerül. A linkekbe (mailto:, Gmail, Outlook) csak rövid
 *    szöveg fér: ami nem fér bele, azt a vezérlőpult a vágólapra teszi, és a levélben egy sor jelzi,
 *    hova kell beilleszteni.
 */
class Feedback
{
    /** A link legnagyobb hossza: a Windows (ShellExecute) kb. 2048 karakternél levágja a hosszabbat. */
    const MAILTO_LIMIT = 1800;
    const WEBMAIL_LIMIT = 1900;
    /** A levél szövegébe kerülő napló kivonat legnagyobb hossza (karakter). */
    const DIGEST_LIMIT = 6000;
    /** Ennyi napló-ZIP marad meg a visszajelzes\ mappában. */
    const KEEP_ARCHIVES = 5;

    const DIGEST_TITLE = "\r\n===== Napló kivonat =====\r\n";
    const PASTE_DIGEST = '[Ide illeszd be a vágólapról a napló kivonatát: Ctrl+V]';
    const PASTE_MESSAGE = '[Ide illeszd be a vágólapról a levél szövegét: Ctrl+V]';

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
        static $summary = null;   // egy kérésen belül elég egyszer lekérdezni (node -v)
        if ($summary !== null) return $summary;
        $info = Runtime::supervisor();
        $browser = $info['browser'] ?? null;
        if (!$browser) $browser = ($info['mode'] ?? '') === 'browser' ? 'alapértelmezett böngésző' : '?';
        return $summary = [
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
     *  - body:     a teljes levél, a napló kivonatával (Szöveg másolása)
     *  - mapiBody: a levél a csatolt naplóhoz (a levelezőprogram csatolja a ZIP-et), ha van napló
     *  - links:    mail (mailto:), gmail, outlook → ['url' => link, 'paste' => ami nem fért bele (a vágólapra kerül),
     *              'pasteKind' => 'digest' (a napló kivonata) | 'message' (a levél szövege) | null]
     */
    public static function compose(string $message, string $contact, ?string $archive = null, string $digest = ''): array
    {
        $subject = 'PocketWeb visszajelzés (v' . PW_VERSION . ')';
        $message = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", trim($message)));
        $digest = rtrim($digest);
        $name = $archive !== null ? basename(str_replace('\\', '/', $archive)) : null;
        if ($digest !== '') {
            $footer = self::footer($contact, 'Napló: kivonat a levél végén' . ($name !== null ? ' (a teljes napló: ' . $name . ')' : ''));
        } else {
            $footer = self::footer($contact, 'Napló: ' . ($name ?? 'nincs mellékelve'));
        }

        $gmail = function (string $body) use ($subject): string {
            return 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode(PW_FEEDBACK_EMAIL)
                . '&su=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
        };
        $outlook = function (string $body) use ($subject): string {
            return 'https://outlook.office.com/mail/deeplink/compose?to=' . rawurlencode(PW_FEEDBACK_EMAIL)
                . '&subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
        };
        $mailto = function (string $body) use ($subject): string {
            return self::mailto($subject, $body);
        };

        return [
            'subject' => $subject,
            'body' => $message . $footer . ($digest !== '' ? self::DIGEST_TITLE . $digest . "\r\n" : ''),
            'mapiBody' => $name !== null ? $message . self::footer($contact, 'Csatolt napló: ' . $name) : null,
            'links' => [
                'mail' => self::link($mailto, self::MAILTO_LIMIT, $message, $footer, $digest),
                'gmail' => self::link($gmail, self::WEBMAIL_LIMIT, $message, $footer, $digest),
                'outlook' => self::link($outlook, self::WEBMAIL_LIMIT, $message, $footer, $digest),
            ],
        ];
    }

    /** mailto: link (RFC 6068: a szóköz %20, a sortörés %0D%0A). */
    public static function mailto(string $subject, string $body): string
    {
        return 'mailto:' . PW_FEEDBACK_EMAIL . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
    }

    /** A levél alja: elérhetőség, verziók, a napló sorsa. */
    private static function footer(string $contact, string $logLine): string
    {
        $footer = "\r\n\r\n";
        if ($contact !== '') $footer .= 'Kapcsolat: ' . $contact . "\r\n";
        $footer .= "--\r\n";
        foreach (self::systemSummary() as $name => $value) $footer .= $name . ': ' . $value . "\r\n";
        return $footer . $logLine . "\r\n";
    }

    /**
     * Link a levélhez legfeljebb $limit hosszan. Ha minden nem fér bele, előbb a napló kivonata, aztán a
     * levél szövege kerül a vágólapra ('paste'); a levélben a helyén egy sor jelzi, hova kell beilleszteni.
     * @return array{url:string, paste:?string, pasteKind:?string}
     */
    private static function link(callable $url, int $limit, string $message, string $footer, string $digest): array
    {
        $digestBlock = $digest !== '' ? self::DIGEST_TITLE . $digest . "\r\n" : '';
        $link = $url($message . $footer . $digestBlock);
        if (strlen($link) <= $limit) return ['url' => $link, 'paste' => null, 'pasteKind' => null];
        if ($digest !== '') {
            $link = $url($message . $footer . self::DIGEST_TITLE . self::PASTE_DIGEST . "\r\n");
            if (strlen($link) <= $limit) return ['url' => $link, 'paste' => $digest . "\r\n", 'pasteKind' => 'digest'];
        }
        $link = $url(self::PASTE_MESSAGE . $footer);
        if (strlen($link) <= $limit) {
            return ['url' => $link, 'paste' => $message . "\r\n" . $digestBlock, 'pasteKind' => 'message'];
        }
        return ['url' => $url(self::PASTE_MESSAGE), 'paste' => $message . $footer . $digestBlock, 'pasteKind' => 'message'];
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

    /**
     * A napló rövid kivonata a levél szövegébe (ha a levelezőprogram nem tudja csatolni a ZIP-et):
     * a hibával leállt feladatok utolsó 15 sora, a PocketWeb naplójának vége, a többi feladat utolsó sorai.
     */
    public static function logDigest(int $limit = self::DIGEST_LIMIT): string
    {
        $failed = $others = [];
        foreach (array_reverse(Jobs::all()) as $job) {   // a legutóbbi elöl
            if ($job['kind'] === 'mail') continue;
            $status = $job['status'];
            $state = (string)($status['state'] ?? 'queued');
            $code = $status['exitCode'] ?? null;
            $bad = $state === 'failed' || ($state === 'exited' && $code !== 0);
            $block = '# ' . $job['title'] . ' – ' . self::stateText($state, $code, $status['error'] ?? null) . "\r\n";
            if (!empty($job['display'])) $block .= '> ' . $job['display'] . "\r\n";
            $block .= self::indent(self::lastLines(self::plainText(self::tail(Jobs::logFile($job['id']), 65536)), $bad ? 15 : 4));
            if ($bad) $failed[] = $block;
            else $others[] = $block;
        }
        $log = self::lastLines(self::plainText(self::tail(Runtime::file('pocketweb.log'), 16384)), 8);
        $blocks = array_merge($failed, $log ? ["# PocketWeb napló (vége)\r\n" . self::indent($log)] : [], $others);

        $text = '';
        foreach ($blocks as $block) {
            if ($text !== '' && self::length($text . "\r\n" . $block) > $limit) {
                $text .= "\r\n[…] (a többi a teljes naplóban)\r\n";
                break;
            }
            $text .= ($text !== '' ? "\r\n" : '') . $block;
        }
        return $text;
    }

    private static function stateText(string $state, $code, ?string $error): string
    {
        switch ($state) {
            case 'queued': return 'várakozik';
            case 'running': return 'fut';
            case 'stopping': return 'leáll';
            case 'stopped': return 'leállítva';
            case 'failed': return 'nem indult el' . ($error ? ': ' . $error : '');
        }
        return $code === 0 ? 'sikeresen befejeződött' : 'hibával leállt (kilépési kód: ' . $code . ')';
    }

    /** A szöveg utolsó $count sora (a túl hosszú sorok levágva). */
    private static function lastLines(string $text, int $count): array
    {
        $text = rtrim($text);
        if ($text === '') return [];
        return array_map(function (string $line): string {
            return self::length($line) > 200 ? self::cut($line, 200) . '…' : $line;
        }, array_slice(preg_split('/\r?\n/', $text), -$count));
    }

    private static function indent(array $lines): string
    {
        $out = '';
        foreach ($lines as $line) $out .= '  ' . $line . "\r\n";
        return $out;
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
