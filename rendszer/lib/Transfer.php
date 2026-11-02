<?php
/*
 * PocketWeb – projektek exportálása ZIP fájlba és importálása ZIP fájlból (pl. a hálózati meghajtóra / -ról).
 *
 * A hálózati meghajtón közvetlenül nem dolgozunk:
 *  - az export a ZIP-et a gép ideiglenes mappájában (%TEMP%\PocketWeb) készíti el, és csak a kész fájlt
 *    másolja át a célmappába (<név>.zip.part néven, a végén átnevezve – félkész ZIP nem marad ott);
 *  - az import először átmásolja a ZIP-et a gépre, onnan bont ki egy rejtett munkamappába
 *    (projektek\.import-...), és a kész projekt mappát a végén nevezi át a helyére.
 * A ZIP-et a PHP zip kiterjesztése kezeli (a mellékelt php.ini bekapcsolja): nem kell hozzá 7-Zip vagy WinZip.
 *
 * Az api.php (mappák böngészése, a ZIP tartalma) és a tasks\export.php, tasks\import.php használja.
 */
final class Transfer
{
    const MANIFEST = 'pocketweb-export.json';
    const STAGING_PREFIX = '.import-';
    const MAX_LIST = 3000;
    /** Ezek már tömörítettek: újratömörítés helyett csak eltároljuk őket (gyorsabb). */
    const STORE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar',
        'mp3', 'mp4', 'm4a', 'ogg', 'webm', 'mov', 'avi', 'woff', 'woff2', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'jar', 'phar'];
    /** A mappák böngészésekor nem mutatjuk (rendszermappák és a régi Windows-kompatibilitási hivatkozások). */
    const HIDDEN_NAMES = ['system volume information', 'recovery', 'config.msi', 'appdata', 'application data', 'local settings',
        'my documents', 'my music', 'my pictures', 'my videos', 'nethood', 'printhood', 'recent', 'sendto', 'start menu',
        'templates', 'cookies', 'documents and settings', 'desktop.ini', 'thumbs.db', 'ntuser.dat'];
    /** Ezekből ismerjük fel, hogy egy mappa projekt (a ZIP szerkezetének felismeréséhez). */
    const PROJECT_MARKERS = ['index.html', 'index.htm', 'index.php', 'artisan', 'wp-config.php', 'wp-load.php', 'composer.json',
        'package.json', '.ms-type'];

    // ------------------------------------------------------------------
    // Útvonalak
    // ------------------------------------------------------------------

    /**
     * Teljes útvonal egységes alakban (C:\Mappa\Almappa, \\szerver\megosztás\mappa), a . és .. elemek nélkül;
     * null, ha nem teljes útvonal (a relatív útvonal a webszerver mappájához képest értelmeződne).
     */
    public static function normalizePath(string $path, ?bool $windows = null): ?string
    {
        $windows = $windows ?? DIRECTORY_SEPARATOR === '\\';
        $path = trim($path, " \t\"");
        if ($path === '' || strlen($path) > 4000 || strpbrk($path, "\0\r\n") !== false) return null;
        if ($windows) {
            $path = str_replace('/', '\\', $path);
            if (preg_match('/^([A-Za-z]):(?:\\\\(.*))?$/s', $path, $m)) {
                $root = strtoupper($m[1]) . ':\\';
                $rest = $m[2] ?? '';
            } elseif (preg_match('/^\\\\\\\\([^\\\\?.][^\\\\]*)\\\\([^\\\\]+)(.*)$/s', $path, $m)) {
                $root = '\\\\' . $m[1] . '\\' . $m[2];   // \\szerver\megosztás (a \\?\ és \\.\ eszközutak nem)
                $rest = $m[3];
            } else {
                return null;
            }
        } else {
            if ($path[0] !== '/') return null;
            $root = '/';
            $rest = $path;
        }
        $parts = [];
        foreach (preg_split($windows ? '#[\\\\/]+#' : '#/+#', $rest, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if ($part === '.') continue;
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        if (!$parts) return $root;
        $separator = $windows ? '\\' : '/';
        return rtrim($root, '\\/') . $separator . implode($separator, $parts);
    }

    /** A szülőmappa, vagy null, ha ez egy meghajtó / megosztás gyökere. */
    public static function parent(string $path, ?bool $windows = null): ?string
    {
        $parent = self::normalizePath($path . '/..', $windows);
        return ($parent === null || $parent === $path) ? null : $parent;
    }

    public static function join(string $dir, string $name): string
    {
        return rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $name;
    }

    /** Fájl- vagy mappanév, amit a Windows elfogad (nincs benne \ / : * ? " < > |, nem CON, NUL...). */
    public static function validFileName(string $name): bool
    {
        return $name !== '' && strlen($name) <= 200 && $name === trim($name) && substr($name, -1) !== '.'
            && !preg_match('/[\x00-\x1f<>:"\/\\\\|?*]/', $name)
            && !preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(\..*)?$/i', $name)
            && $name !== '.' && $name !== '..';
    }

    // ------------------------------------------------------------------
    // Mappák böngészése (a vezérlőpult saját tallózó ablaka – a böngésző nem adja meg a valódi útvonalat)
    // ------------------------------------------------------------------

    /** A gyorselérés mappái: Asztal, Dokumentumok, Letöltések (a Windows átirányítását is követve), OneDrive. */
    public static function places(): array
    {
        $places = [];
        $add = function (string $label, $path, string $icon) use (&$places) {
            $path = is_string($path) ? self::normalizePath($path) : null;
            if ($path === null || !@is_dir($path)) return;
            foreach ($places as $place) {
                if (strcasecmp($place['path'], $path) === 0) return;
            }
            $places[] = ['label' => $label, 'path' => $path, 'icon' => $icon];
        };
        $home = Platform::home();
        $shell = self::shellFolders();
        $add('Asztal', $shell['desktop'] ?? ($home !== '' ? pw_path($home, 'Desktop') : null), 'desktop');
        $add('Dokumentumok', $shell['personal'] ?? ($home !== '' ? pw_path($home, 'Documents') : null), 'documents');
        $add('Letöltések', $shell['{374de290-123f-4565-9164-39c4925e467b}'] ?? ($home !== '' ? pw_path($home, 'Downloads') : null), 'downloads');
        $add('OneDrive', getenv('OneDrive') ?: null, 'cloud');
        $add('Saját mappa', $home !== '' ? $home : null, 'home');
        return $places;
    }

    /** A felhasználói mappák valódi helye a registryből (iskolai gépen gyakran a hálózatra van átirányítva). */
    private static function shellFolders(): array
    {
        $out = (string)@shell_exec('reg query "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\User Shell Folders" 2>NUL');
        $folders = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (!preg_match('/^\s+(.+?)\s{4}REG_(?:EXPAND_)?SZ\s{4}(.+?)\s*$/', $line, $m)) continue;
            $path = Platform::expandEnv($m[2]);
            if (strpos($path, '%') === false && self::isUtf8($path)) $folders[strtolower($m[1])] = $path;
        }
        return $folders;
    }

    /**
     * A meghajtók: helyi lemezek, pendrive-ok és a csatlakoztatott hálózati meghajtók (net use).
     * A hálózati meghajtókat nem próbáljuk elérni: egy megszakadt kapcsolatú meghajtó percekig várakoztatna.
     */
    public static function drives(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') return [];
        $network = self::networkDrives();
        $ownDrive = preg_match('/^([A-Za-z]):/', PW_ROOT, $m) ? strtoupper($m[1]) : '';
        $drives = [];
        foreach (range('C', 'Z') as $letter) {
            $path = $letter . ':\\';
            if (isset($network[$letter])) {
                $drives[] = ['path' => $path, 'label' => 'Hálózati meghajtó (' . $letter . ':)', 'detail' => $network[$letter]['remote'],
                    'icon' => 'network', 'available' => $network[$letter]['available']];
            } elseif (@is_dir($path)) {
                $label = $letter === $ownDrive ? 'PocketWeb meghajtója' : ($letter === 'C' ? 'Helyi lemez' : 'Meghajtó');
                $drives[] = ['path' => $path, 'label' => $label . ' (' . $letter . ':)', 'detail' => '', 'icon' => 'drive', 'available' => true];
            }
        }
        return $drives;
    }

    /** A "net use" kimenetéből: betűjel => [remote, available]. */
    public static function networkDrives(?string $output = null): array
    {
        $output = $output ?? (string)@shell_exec('net use 2>NUL');
        $drives = [];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            // Állapot  Helyi  Távoli  Hálózat – az állapot ("OK", "Unavailable", "Nem érhető el"...) hiányozhat is
            if (!preg_match('/^(.*?)\s*\b([A-Za-z]):\s+(\\\\\\\\.+?)(?:\s{2,}.*)?$/', rtrim($line), $m)) continue;
            $status = trim($m[1]);
            // ha a távoli útvonal kitölti az oszlopot, a hálózat neve egyetlen szóközzel követi
            $remote = (string)preg_replace('/ (Microsoft Windows\b.*|Web Client Network)$/i', '', $m[3]);
            $remote = self::isUtf8($remote) ? $remote : (function_exists('iconv') ? (string)@iconv('CP852', 'UTF-8//IGNORE', $remote) : '');
            $drives[strtoupper($m[2])] = ['remote' => $remote, 'available' => $status === '' || strcasecmp($status, 'OK') === 0];
        }
        return $drives;
    }

    /**
     * Egy mappa almappái és ZIP fájljai (a rejtett és rendszermappák nélkül), név szerint rendezve.
     * @return array{entries: array, truncated: bool}|null null, ha a mappa nem olvasható
     */
    public static function listDir(string $path): ?array
    {
        $handle = @opendir($path);
        if (!$handle) return null;
        $dirs = $zips = [];
        $truncated = false;
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..' || $name[0] === '.' || $name[0] === '$' || $name[0] === '~'
                || in_array(strtolower($name), self::HIDDEN_NAMES, true)) {
                continue;
            }
            if (count($dirs) + count($zips) >= self::MAX_LIST) {
                $truncated = true;
                break;
            }
            $full = self::join($path, $name);
            if (@is_dir($full)) {
                $dirs[] = ['name' => $name, 'type' => 'dir'];
            } elseif (strcasecmp((string)pathinfo($name, PATHINFO_EXTENSION), 'zip') === 0) {
                $zips[] = ['name' => $name, 'type' => 'zip', 'size' => (int)@filesize($full), 'mtime' => (int)@filemtime($full)];
            }
        }
        closedir($handle);
        $byName = function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); };
        usort($dirs, $byName);
        usort($zips, $byName);
        return ['entries' => array_merge($dirs, $zips), 'truncated' => $truncated];
    }

    // ------------------------------------------------------------------
    // Export: a projekt fájljai
    // ------------------------------------------------------------------

    /**
     * Egy projekt exportálandó fájljai és üres mappái (a projekt mappájához viszonyítva, / elválasztóval).
     * Kimarad: a szimbolikus linkek (pl. a Laravel public\storage), a Laravel újragenerálható gyorsítótárai
     * és naplói, kérésre a vendor és a node_modules (importáláskor a PocketWeb újratelepíti őket).
     * @return array{files: array<int,array{0:string,1:string}>, dirs: string[], bytes: int, skipped: string[], links: string[], unreadable: string[]}
     */
    public static function collect(string $dir, string $type, bool $skipDeps): array
    {
        $result = ['files' => [], 'dirs' => [], 'bytes' => 0, 'skipped' => [], 'links' => [], 'unreadable' => []];
        $exclude = [];
        if ($skipDeps) {
            if (is_dir(pw_path($dir, 'vendor')) && is_file(pw_path($dir, 'composer.json'))) $exclude['vendor'] = true;
            if (is_dir(pw_path($dir, 'node_modules')) && is_file(pw_path($dir, 'package.json'))) $exclude['node_modules'] = true;
            $result['skipped'] = array_keys($exclude);
        }
        self::walk($dir, '', $exclude, $type === 'laravel', $result);
        return $result;
    }

    /** @return int a mappából exportált elemek száma (0: üres mappaként kerül a ZIP-be) */
    private static function walk(string $abs, string $rel, array $exclude, bool $laravel, array &$result): int
    {
        $entries = @scandir($abs);
        if ($entries === false) {
            $result['unreadable'][] = $rel;
            return 1;
        }
        $count = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ($rel === '' && isset($exclude[$entry]))) continue;
            $path = $abs . DIRECTORY_SEPARATOR . $entry;
            $relPath = $rel === '' ? $entry : $rel . '/' . $entry;
            if (is_link($path)) {
                $result['links'][] = $relPath;
                continue;
            }
            if (is_dir($path)) {
                if ($laravel && preg_match('#^storage/framework/cache/data/.#', $relPath)) continue;
                if (self::walk($path, $relPath, $exclude, $laravel, $result) === 0) $result['dirs'][] = $relPath;
                $count++;
                continue;
            }
            if ($laravel && self::isLaravelCache($relPath)) continue;
            $fh = @fopen($path, 'rb');   // a zárolt fájl (amit egy program kizárólagosan használ) miatt ne hiúsuljon meg az egész
            if (!$fh) {
                $result['unreadable'][] = $relPath;
                continue;
            }
            fclose($fh);
            $result['files'][] = [$path, $relPath];
            $result['bytes'] += (int)@filesize($path);
            $count++;
        }
        return $count;
    }

    /** A Laravel újragenerálható fájljai: lefordított nézetek, munkamenetek, gyorsítótár, naplók, config cache. */
    public static function isLaravelCache(string $rel): bool
    {
        if ($rel === 'bootstrap/cache/config.php') return true;   // teljes útvonalak vannak benne
        return preg_match('#^storage/(?:framework/(?:views|sessions|cache/data)|logs)/[^/]+$#', $rel) === 1
            && basename($rel) !== '.gitignore';
    }

    /** Tömörítés nélkül tároljuk-e (a már tömörített fájlok). */
    public static function storeOnly(string $file): bool
    {
        return in_array(strtolower((string)pathinfo($file, PATHINFO_EXTENSION)), self::STORE_EXTS, true);
    }

    // ------------------------------------------------------------------
    // Import: a ZIP szerkezete
    // ------------------------------------------------------------------

    /**
     * A ZIP-ben lévő projektek. Felismeri a PocketWeb exportját (projekt mappák + pocketweb-export.json), egy vagy
     * több projekt mappájának tömörítését, egy projekt tartalmának tömörítését (fájlok a gyökérben: a projekt neve
     * a ZIP neve lesz) és a teljes projektek mappa tömörítését (egyetlen mappa, benne a projektekkel).
     * @return array{manifest: ?array, projects: array, unsafe: int}
     *   projects: prefix (a projekt mappája a ZIP-ben, '' = a gyökér), original, name, type, port, files, bytes,
     *   skipped, links, entries ([index, relatív út, mappa-e, méret, idő])
     */
    public static function inspect(ZipArchive $zip, string $zipName): array
    {
        $items = [];
        $unsafe = 0;
        $manifest = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if (!is_array($stat)) continue;
            $entry = self::safeEntry(self::decodeName((string)$stat['name']));
            if ($entry === null) {
                $unsafe++;
                continue;
            }
            [$path, $isDir] = $entry;
            if ($path === '' || strpos($path . '/', '__MACOSX/') === 0 || basename($path) === '.DS_Store') continue;
            if ($path === self::MANIFEST && !$isDir) {
                $data = (int)$stat['size'] < 1048576 ? json_decode((string)$zip->getFromIndex($i), true) : null;
                if (is_array($data) && ($data['app'] ?? '') === 'PocketWeb') $manifest = $data;
                continue;
            }
            $items[] = [$i, $path, $isDir, (int)$stat['size'], (int)($stat['mtime'] ?? 0)];
        }

        $rootFiles = false;
        $tops = [];
        foreach ($items as $item) {
            if (strpos($item[1], '/') === false && !$item[2]) {
                $rootFiles = true;
            } else {
                $tops[explode('/', $item[1])[0]] = true;
            }
        }
        if ($rootFiles) {
            $prefixes = [''];
        } else {
            $prefixes = array_map(function ($top) { return $top . '/'; }, array_keys($tops));
            if (count($prefixes) === 1 && $manifest === null && !self::looksLikeProject($items, $prefixes[0])) {
                $subs = self::projectSubdirs($items, $prefixes[0]);
                if ($subs) $prefixes = $subs;
            }
        }

        $known = [];
        foreach ((array)($manifest['projects'] ?? []) as $info) {
            if (is_array($info) && isset($info['name'])) $known[(string)$info['name']] = $info;
        }
        $projects = [];
        foreach ($prefixes as $prefix) {
            $original = $prefix === '' ? (string)preg_replace('/\.zip$/i', '', $zipName) : basename(rtrim($prefix, '/'));
            $project = ['prefix' => $prefix, 'original' => $original, 'name' => self::projectName($original), 'type' => '',
                'port' => null, 'files' => 0, 'bytes' => 0, 'skipped' => [], 'links' => [], 'entries' => []];
            foreach ($items as $item) {
                if ($prefix !== '' && strpos($item[1], $prefix) !== 0) continue;
                $rel = (string)substr($item[1], strlen($prefix));
                if ($rel === '') continue;
                $project['entries'][] = [$item[0], $rel, $item[2], $item[3], $item[4]];
                if (!$item[2]) {
                    $project['files']++;
                    $project['bytes'] += $item[3];
                }
            }
            if (!$project['entries']) continue;
            $info = $known[$original] ?? [];
            $project['skipped'] = array_values(array_intersect((array)($info['skipped'] ?? []), ['vendor', 'node_modules']));
            $project['links'] = array_values(array_filter((array)($info['links'] ?? []), 'is_string'));
            $project['type'] = self::detectType($zip, $project, (string)($info['type'] ?? ''));
            $projects[] = $project;
        }
        return ['manifest' => $manifest, 'projects' => $projects, 'unsafe' => $unsafe];
    }

    private static function looksLikeProject(array $items, string $prefix): bool
    {
        foreach ($items as $item) {
            if (!$item[2] && in_array(substr($item[1], strlen($prefix)), self::PROJECT_MARKERS, true) && strpos($item[1], $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Az egyetlen gyökérmappa projektjei (ha a projektek mappát tömörítették be), vagy üres tömb. */
    private static function projectSubdirs(array $items, string $prefix): array
    {
        $subs = [];
        foreach ($items as $item) {
            if (strpos($item[1], $prefix) !== 0) continue;
            $parts = explode('/', substr($item[1], strlen($prefix)));
            if (count($parts) === 1 && !$item[2] && $parts[0] !== '') return [];   // fájl a gyökérmappában: ez maga a projekt
            if (count($parts) >= 2 || $item[2]) $subs[$prefix . $parts[0] . '/'] = true;
        }
        $subs = array_keys($subs);
        $projects = array_values(array_filter($subs, function ($sub) use ($items) { return self::looksLikeProject($items, $sub); }));
        return $projects ? $subs : [];
    }

    private static function detectType(ZipArchive $zip, array $project, string $type): string
    {
        if (isset(Sites::TYPES[$type])) return $type;
        $names = [];
        foreach ($project['entries'] as $entry) {
            if (!$entry[2] && strpos($entry[1], '/') === false) $names[$entry[1]] = $entry[0];
        }
        if (isset($names['.ms-type'])) {
            $type = trim((string)$zip->getFromIndex($names['.ms-type'], 64));
            if (isset(Sites::TYPES[$type])) return $type;
        }
        if (isset($names['artisan'])) return 'laravel';
        if (isset($names['wp-config.php']) || isset($names['wp-load.php'])) return 'wordpress';
        if (isset($names['index.html']) || isset($names['index.htm'])) return 'html_css';
        return '';
    }

    /**
     * A ZIP-ben tárolt név UTF-8-ra: a mai ZIP-ek UTF-8-at használnak, a régebbi Windows "Tömörített mappa"
     * a konzol kódlapját (magyar Windowson CP852).
     */
    public static function decodeName(string $raw): string
    {
        if (self::isUtf8($raw)) return $raw;
        if (function_exists('iconv') && ($name = @iconv('CP852', 'UTF-8', $raw)) !== false) return $name;
        return (string)preg_replace('/[\x80-\xff]/', '_', $raw);
    }

    /**
     * A ZIP bejegyzés neve biztonságos relatív útként ([út, mappa-e]), vagy null, ha a projekt mappáján kívülre
     * mutatna (.., meghajtó, abszolút út), vagy a Windowson nem létrehozható név lenne benne.
     */
    public static function safeEntry(string $name): ?array
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || $name[0] === '/' || strpos($name, "\0") !== false) return null;
        $isDir = substr($name, -1) === '/';
        $parts = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..' || !self::validFileName($part)) return null;
            $parts[] = $part;
        }
        return [implode('/', $parts), $isDir];
    }

    /** Érvényes projektnév a ZIP-ben lévő mappanévből (kisbetű, ékezet nélkül, a szóköz helyett kötőjel). */
    public static function projectName(string $original): string
    {
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ö' => 'o', 'Ő' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ű' => 'u'];
        $name = strtr($original, $map);
        if (function_exists('iconv') && preg_match('/[\x80-\xff]/', $name)) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($ascii)) $name = $ascii;
        }
        $name = strtolower($name);
        $name = (string)preg_replace('/[^a-z0-9_-]+/', '-', $name);
        $name = trim(substr(trim($name, '-_'), 0, 60), '-_');
        if ($name === '') $name = 'projekt';
        return Sites::validNewName($name) ? $name : $name . '-1';
    }

    /** Szabad projektnév: a név maga, vagy név-2, név-3... ($taken: a már kiosztott nevek). */
    public static function uniqueName(string $name, array $taken = []): string
    {
        $free = function ($candidate) use ($taken) {
            return !file_exists(pw_path(PW_PROJECTS, $candidate)) && !in_array($candidate, $taken, true);
        };
        if ($free($name)) return $name;
        for ($i = 2; ; $i++) {
            $candidate = substr($name, 0, 58) . '-' . $i;
            if ($free($candidate)) return $candidate;
        }
    }

    // ------------------------------------------------------------------
    // Fájlműveletek
    // ------------------------------------------------------------------

    /** Az ideiglenes ZIP-ek helye a gép saját lemezén (%TEMP%\PocketWeb), tartalékként a rendszer\.run\tmp. */
    public static function tempDir(): string
    {
        foreach ([pw_path(sys_get_temp_dir(), 'PocketWeb'), pw_path(PW_RUN, 'tmp')] as $dir) {
            if ((is_dir($dir) || @mkdir($dir, 0777, true)) && is_writable($dir)) return $dir;
        }
        throw new RuntimeException('Nincs írható ideiglenes mappa.');
    }

    /** A célmappába másolás alatt álló fájl (<név>.zip.part) helye: ha az export megszakad, a következő indításkor töröljük. */
    public static function partRecord(): string
    {
        return pw_path(PW_RUN, 'export-part.txt');
    }

    /** Az előző (megszakadt) exportok és importok ideiglenes ZIP-jei, és a célmappában maradt félkész fájl. */
    public static function cleanTemp(): void
    {
        $part = trim((string)@file_get_contents(self::partRecord()));
        if (substr($part, -9) === '.zip.part' && is_file($part)) @unlink($part);
        @unlink(self::partRecord());
        foreach ([pw_path(sys_get_temp_dir(), 'PocketWeb'), pw_path(PW_RUN, 'tmp')] as $dir) {
            foreach (array_merge(glob(pw_path($dir, 'export-*.zip*')) ?: [], glob(pw_path($dir, 'import-*.zip')) ?: []) as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Fájl másolása darabokban, folyamatjelzéssel. A célt hiba esetén törli.
     * @param callable|null $progress function (int $kesz, int $osszesen)
     */
    public static function copyFile(string $src, string $dst, ?callable $progress, ?string &$error = null): bool
    {
        $error = null;
        $in = @fopen($src, 'rb');
        if (!$in) {
            $error = 'nem olvasható: ' . $src;
            return false;
        }
        $out = @fopen($dst, 'wb');
        if (!$out) {
            fclose($in);
            $error = 'nem hozható létre: ' . $dst . ' (van írási jogod ehhez a mappához?)';
            return false;
        }
        $total = (int)@filesize($src);
        $done = 0;
        while (!feof($in)) {
            $chunk = fread($in, 1048576);
            if ($chunk === false) {
                $error = 'olvasási hiba: ' . $src;
                break;
            }
            for ($written = 0; $written < strlen($chunk); $written += $bytes) {
                $bytes = @fwrite($out, substr($chunk, $written));
                if (!$bytes) {
                    $error = 'írási hiba: ' . $dst . ' (megtelt a meghajtó, vagy elfogyott a tárhelyed?)';
                    break 2;
                }
            }
            $done += strlen($chunk);
            if ($progress) $progress($done, $total);
        }
        fclose($in);
        if (!fclose($out) && $error === null) $error = 'írási hiba: ' . $dst;
        clearstatcache(true, $dst);
        if ($error === null && (int)@filesize($dst) !== $total) $error = 'a másolat mérete eltér az eredetiétől: ' . $dst;
        if ($error !== null) @unlink($dst);
        return $error === null;
    }

    /** Átnevezés, néhány újrapróbálással (Windowson a víruskereső egy pillanatra megnyithatja az új fájlokat). */
    public static function rename(string $from, string $to, int $tries = 15): bool
    {
        for ($i = 0; $i < $tries; $i++) {
            if (@rename($from, $to)) return true;
            if (!file_exists($from)) return false;
            usleep(200000);
        }
        return false;
    }

    /**
     * A kibontott projekt mappa ($new, a munkamappában) a helyére: projektek\<target>. Felülírásnál a régi mappa
     * a munkamappába kerül (<target>.old), és csak a sikeres csere után töröljük. Ha a régi mappa nem nevezhető át
     * (pl. egy nyitott parancssor vagy szerkesztő használja), a tartalmát cseréljük ki.
     * @return string|null hibaüzenet
     */
    public static function moveIn(string $new, string $target, bool $overwrite): ?string
    {
        $final = pw_path(PW_PROJECTS, $target);
        if (!file_exists($final)) {
            return self::rename($new, $final) ? null : 'nem sikerült a projekt mappát a helyére tenni';
        }
        if (!$overwrite) return 'közben létrejött egy ugyanilyen nevű projekt';
        $backup = $new . '.old';
        if (self::rename($final, $backup, 5)) {
            if (self::rename($new, $final)) {
                Sites::deleteDir($backup);
                return null;
            }
            self::rename($backup, $final);
            return 'nem sikerült a projekt mappát a helyére tenni';
        }
        return self::replaceContents($final, $new, $backup);
    }

    private static function replaceContents(string $final, string $new, string $backup): ?string
    {
        if (!is_dir($backup) && !@mkdir($backup)) return 'nem sikerült a régi projektet félretenni';
        $moved = [];
        foreach (self::children($final) as $entry) {
            if (!self::rename(pw_path($final, $entry), pw_path($backup, $entry), 5)) {
                foreach ($moved as $back) self::rename(pw_path($backup, $back), pw_path($final, $back));
                @rmdir($backup);
                return 'a projekt egyik fájlját (' . $entry . ') egy program használja – zárd be (pl. a szerkesztőt vagy a parancssort), és próbáld újra';
            }
            $moved[] = $entry;
        }
        $placed = [];
        foreach (self::children($new) as $entry) {
            if (!self::rename(pw_path($new, $entry), pw_path($final, $entry))) {
                foreach ($placed as $back) self::rename(pw_path($final, $back), pw_path($new, $back));
                foreach ($moved as $back) self::rename(pw_path($backup, $back), pw_path($final, $back));
                return 'nem sikerült az új fájlokat a helyükre tenni (' . $entry . ')';
            }
            $placed[] = $entry;
        }
        Sites::deleteDir($backup);
        @rmdir($new);
        return null;
    }

    private static function children(string $dir): array
    {
        return array_values(array_diff(@scandir($dir) ?: [], ['.', '..']));
    }

    /**
     * A megszakadt importok (pl. kilépés közben) munkamappáinak törlése. Ha a felülírás félbemaradt, a régi
     * projektet visszateszi a helyére. A felügyelő induláskor és az import kezdetekor hívja.
     */
    public static function recover(): void
    {
        foreach (glob(pw_path(PW_PROJECTS, self::STAGING_PREFIX . '*'), GLOB_ONLYDIR) ?: [] as $staging) {
            foreach (glob(pw_path($staging, '*.old'), GLOB_ONLYDIR) ?: [] as $backup) {
                $final = pw_path(PW_PROJECTS, substr(basename($backup), 0, -4));
                if (!file_exists($final)) {
                    self::rename($backup, $final, 3);
                    continue;
                }
                foreach (self::children($backup) as $entry) {
                    if (!file_exists(pw_path($final, $entry))) self::rename(pw_path($backup, $entry), pw_path($final, $entry), 3);
                }
            }
            Sites::deleteDir($staging);
        }
    }

    // ------------------------------------------------------------------
    // Az importált projekt előkészítése
    // ------------------------------------------------------------------

    /** Kibontás után, még a munkamappában: típusjelölő, a Laravel kötelező mappái, a régi gépre mutató config cache. */
    public static function prepare(string $dir, string $type): void
    {
        if (!is_file(pw_path($dir, '.ms-type'))) file_put_contents(pw_path($dir, '.ms-type'), $type !== '' ? $type : 'html_css');
        if ($type !== 'laravel') return;
        @unlink(pw_path($dir, 'bootstrap', 'cache', 'config.php'));
        foreach ([['storage', 'framework', 'cache', 'data'], ['storage', 'framework', 'sessions'], ['storage', 'framework', 'views'],
                     ['storage', 'logs'], ['bootstrap', 'cache']] as $parts) {
            $path = pw_path($dir, ...$parts);
            if (!is_dir($path)) @mkdir($path, 0777, true);
        }
    }

    /**
     * Mi kell még az importált projekt beüzemeléséhez (tasks\setup-project.php): composer (install), env (.env a
     * .env.example-ből), key (APP_KEY), migrate (SQLite adatbázis), storage-link, npm (install). Üres: semmi.
     */
    public static function setupSteps(string $dir, string $type, array $skipped, array $links): array
    {
        $steps = [];
        if (is_file(pw_path($dir, 'composer.json')) && !is_file(pw_path($dir, 'vendor', 'autoload.php'))) $steps[] = 'composer';
        if ($type === 'laravel') {
            $env = pw_path($dir, '.env');
            if (!is_file($env)) {
                if (is_file(pw_path($dir, '.env.example'))) array_push($steps, 'env', 'key', 'migrate');
            } else {
                if (preg_match('/^APP_KEY=\s*$/m', (string)file_get_contents($env))) $steps[] = 'key';
                $db = self::laravelSqliteFile($dir);
                if ($db !== null && !is_file($db)) $steps[] = 'migrate';
            }
            if (in_array('public/storage', $links, true) && !file_exists(pw_path($dir, 'public', 'storage'))) $steps[] = 'storage-link';
        }
        if (in_array('node_modules', $skipped, true) && is_file(pw_path($dir, 'package.json')) && !is_dir(pw_path($dir, 'node_modules'))) {
            $steps[] = 'npm';
        }
        return $steps;
    }

    /** A Laravel alapértelmezett SQLite adatbázisa (database\database.sqlite), ha a .env szerint azt használja. */
    public static function laravelSqliteFile(string $dir): ?string
    {
        $env = @file_get_contents(pw_path($dir, '.env'));
        if (!is_string($env)) return null;
        $value = function (string $key) use ($env) {
            return preg_match('/^' . $key . '=(.*)$/m', $env, $m) ? trim(trim($m[1]), '"\'') : '';
        };
        $connection = $value('DB_CONNECTION');
        if (($connection !== '' && $connection !== 'sqlite') || $value('DB_DATABASE') !== '') return null;   // saját beállítás: nem nyúlunk hozzá
        return pw_path($dir, 'database', 'database.sqlite');
    }

    // ------------------------------------------------------------------
    // Az importált projekt portja
    // ------------------------------------------------------------------

    /**
     * Az importált projekt portja: a ZIP-ben lévő .ms-port, ha egy másik projekt sem használja, különben új port.
     * @return array{0: ?int, 1: ?int} [a projekt portja, a régi port, ha változott]
     */
    public static function assignPort(string $dir, string $target): array
    {
        $portFile = pw_path($dir, '.ms-port');
        $port = is_file($portFile) ? (int)file_get_contents($portFile) : 0;
        $used = [];
        foreach (glob(pw_path(PW_PROJECTS, '*', '.ms-port')) ?: [] as $file) {
            if (basename(dirname($file)) !== $target) $used[(int)file_get_contents($file)] = true;
        }
        if ($port <= 0) return [null, null];   // a PocketWeb majd kioszt egyet, amikor kell
        if ($port < 65536 && $port !== PW_PORT && !isset($used[$port])) return [$port, null];
        $new = max(7999, $used ? max(array_keys($used)) : 0) + 1;   // mint a Sites::port()
        while (isset($used[$new]) || $new === PW_PORT) $new++;
        file_put_contents($portFile, (string)$new);
        return [$new, $port];
    }

    /**
     * A WordPress a saját címét (http://127.0.0.1:<port>) az adatbázisában tárolja: új port esetén ezt is át kell
     * írni, különben a régi címre irányítana át. Azonos hosszú portnál (8001 → 8005) a bejegyzésekben és a
     * szerializált beállításokban is cserélünk (a szöveg hossza nem változik, így a szerializálás érvényes marad).
     * @return string|null hibaüzenet
     */
    public static function wordpressChangePort(string $dir, int $old, int $new): ?string
    {
        $db = pw_path($dir, 'wp-content', 'database', '.ht.sqlite');
        if (!is_file($db)) return null;   // a WordPress még nincs beállítva: nincs mit átírni
        if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) return 'a PHP pdo_sqlite kiterjesztése nincs bekapcsolva';
        $prefix = 'wp_';
        if (preg_match('/\$table_prefix\s*=\s*[\'"]([A-Za-z0-9_]+)[\'"]/', (string)@file_get_contents(pw_path($dir, 'wp-config.php')), $m)) {
            $prefix = $m[1];
        }
        $sameLength = strlen((string)$old) === strlen((string)$new);
        $targets = $sameLength
            ? [['options', 'option_value'], ['posts', 'post_content'], ['posts', 'post_excerpt'], ['posts', 'guid'], ['postmeta', 'meta_value'], ['comments', 'comment_content']]
            : [['options', 'option_value']];
        try {
            $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
            $pdo->beginTransaction();
            foreach ($targets as [$table, $column]) {
                if (!in_array($prefix . $table, $tables, true)) continue;
                $where = $sameLength ? '' : " AND option_name IN ('siteurl', 'home')";
                $update = $pdo->prepare('UPDATE "' . $prefix . $table . '" SET "' . $column . '" = REPLACE("' . $column . '", ?, ?) WHERE "' . $column . '" LIKE ?' . $where);
                foreach (['127.0.0.1', 'localhost'] as $host) {
                    $update->execute([$host . ':' . $old, $host . ':' . $new, '%' . $host . ':' . $old . '%']);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }

    private static function isUtf8(string $text): bool
    {
        return function_exists('mb_check_encoding') ? mb_check_encoding($text, 'UTF-8') : preg_match('//u', $text) === 1;
    }
}
