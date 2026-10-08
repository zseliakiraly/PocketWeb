<?php
/*
 * PocketWeb – a Windowstól függő műveletek egy helyen (Windows 10/11).
 *
 * A PocketWeb a rendszer\php és rendszer\node mappába mellékelt programokat használja
 * (php.exe, node.exe – ezek a GitHubon nincsenek fent, a kész csomagban vannak benne).
 *
 * Fontos Windows-sajátosság: a proc_open() minden örökölhető handle-t továbbad a gyereknek,
 * a beépített PHP szerver socketjeit is. Ezért hosszan futó programot (szervert, szerkesztőt,
 * parancssor ablakot) soha nem az api.php indít, hanem a felügyelő (pocketweb.php) – az api.php
 * csak a "launch spec"-et állítja össze és üzenetsoron átküldi.
 */
class Platform
{
    // ------------------------------------------------------------------
    // Mellékelt programok (php, node, composer)
    // ------------------------------------------------------------------

    /** Az éppen futó PHP (rendszer\php\php.exe) – a felügyelő, a vezérlőpult és a feladatok is ezt használják. */
    public static function php(): string
    {
        return PHP_BINARY;
    }

    public static function composerPhar(): string
    {
        return pw_path(PW_SYS, 'php', 'composer.phar');
    }

    /** A mellékelt Node.js (rendszer\node\node.exe), ha be van másolva. */
    public static function node(): ?string
    {
        $node = pw_path(PW_SYS, 'node', 'node.exe');
        return is_file($node) ? $node : null;
    }

    public static function home(): string
    {
        return (string)(getenv('USERPROFILE') ?: '');
    }

    /** Program keresése a PATH-ban (mint a "where" parancs). */
    public static function which(string $name): ?string
    {
        // a kiterjesztés nélküli fájl (pl. a VS Code "code" bash szkriptje) Windowson nem futtatható
        $exts = pathinfo($name, PATHINFO_EXTENSION) === ''
            ? explode(';', strtolower((string)(getenv('PATHEXT') ?: '.com;.exe;.bat;.cmd')))
            : [''];
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            $dir = trim($dir, " \"");
            if ($dir === '') continue;
            foreach ($exts as $ext) {
                $file = $dir . DIRECTORY_SEPARATOR . $name . $ext;
                if (is_file($file)) return $file;
            }
        }
        return null;
    }

    /** Windows verzió a névjegyhez és a visszajelzéshez (gépnév nélkül). */
    public static function windowsVersion(): string
    {
        return trim(php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('v') . ' ' . php_uname('m'));
    }

    // ------------------------------------------------------------------
    // Környezeti változók
    // ------------------------------------------------------------------

    /** A PATH elejére kerülő mappák: rendszer\bin (composer parancs), rendszer\php és rendszer\node. */
    public static function pathPrefix(): array
    {
        $dirs = [pw_path(PW_SYS, 'bin'), dirname(PHP_BINARY)];
        if ($node = self::node()) $dirs[] = dirname($node);
        return $dirs;
    }

    /** A változónevek kis-/nagybetűre érzéketlenek (Path vs. PATH) – a meglévő kulcsot adja vissza. */
    public static function envKey(array $env, string $name): string
    {
        foreach ($env as $key => $_) {
            if (strcasecmp((string)$key, $name) === 0) return (string)$key;
        }
        return $name;
    }

    /** A gyerekfolyamatok környezete: az aktuális környezet + kiegészített PATH + $extra (null = törlés). */
    public static function childEnv(array $extra = []): array
    {
        $env = getenv();
        if (!is_array($env)) $env = [];

        $key = self::envKey($env, 'PATH');
        $parts = self::pathPrefix();
        if (($env[$key] ?? '') !== '') $parts[] = $env[$key];
        $env[$key] = implode(PATH_SEPARATOR, $parts);

        foreach ($extra as $name => $value) {
            $k = self::envKey($env, (string)$name);
            if ($value === null) {
                unset($env[$k]);
            } else {
                $env[$k] = (string)$value;
            }
        }
        return $env;
    }

    // ------------------------------------------------------------------
    // Folyamatok
    // ------------------------------------------------------------------

    /**
     * Háttérfolyamat indítása ablak nélkül; a kimenet (stdout + stderr) a $logFile-ba kerül.
     * A napló mindig új fájl. A gyerek és az unokái ugyanazt a fájlmutatót öröklik, ezért a
     * kimenetük sorrendben követi egymást.
     * @return resource|null
     */
    public static function spawn(array $cmd, string $cwd, array $env, string $logFile)
    {
        $descriptors = [0 => ['null'], 1 => ['file', $logFile, 'a'], 2 => ['redirect', 1]];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, $env, ['bypass_shell' => true, 'suppress_errors' => true]);
        return is_resource($proc) ? $proc : null;
    }

    /** A folyamat programjának neve kisbetűvel (pl. "php.exe"), vagy null, ha nem fut. */
    public static function processName(int $pid): ?string
    {
        if ($pid <= 0) return null;
        return self::parseTasklist((string)shell_exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL'), $pid);
    }

    /** A "tasklist /FO CSV /NH" kimenetéből a PID-hez tartozó program neve. */
    public static function parseTasklist(string $csv, int $pid): ?string
    {
        if (preg_match('/^"([^"]+)","' . $pid . '"/m', $csv, $m)) return strtolower($m[1]);
        return null;
    }

    /** Folyamatok leállítása az összes leszármazottjukkal együtt (pl. artisan serve + a php -S gyereke). */
    public static function killTree(array $pids): void
    {
        foreach (array_unique(array_map('intval', $pids)) as $pid) {
            if ($pid > 0) exec('taskkill /PID ' . $pid . ' /T /F >NUL 2>&1');
        }
    }

    /**
     * A gépen figyelő (LISTEN) TCP portok.
     * @return array<int,int>|null port => PID; null, ha a netstat nem futott le
     */
    public static function listeningPorts(): ?array
    {
        $out = shell_exec('netstat -ano 2>NUL');
        return is_string($out) && trim($out) !== '' ? self::parseNetstat($out) : null;
    }

    /**
     * A "netstat -ano" kimenetéből a figyelő portok. Figyelő socket az, amelyiknek a távoli címe
     * 0.0.0.0:0 vagy [::]:0 – így nem függünk a magyar/angol Windows szövegeitől ("LISTENING").
     * @return array<int,int> port => PID
     */
    public static function parseNetstat(string $out): array
    {
        $ports = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 5 || strtoupper($cols[0]) !== 'TCP') continue;
            if (!preg_match('/:0$/', $cols[2]) || !preg_match('/:(\d+)$/', $cols[1], $m)) continue;
            $ports[(int)$m[1]] = (int)end($cols);
        }
        return $ports;
    }

    /** Figyel-e valami a porton? $ports: a listeningPorts() előre lekért eredménye. */
    public static function isListening(int $port, ?array $ports = null): bool
    {
        if ($port <= 0) return false;
        if ($ports === null) $ports = self::listeningPorts() ?? [];
        return isset($ports[$port]);
    }

    /** A porton figyelő folyamat PID-je (üres tömb, ha nincs). */
    public static function pidsOnPort(int $port): array
    {
        $ports = self::listeningPorts() ?? [];
        return !empty($ports[$port]) ? [$ports[$port]] : [];
    }

    // ------------------------------------------------------------------
    // Programok indítása ("launch spec": ['argv' => [...]] vagy ['cmdline' => '...'])
    // ------------------------------------------------------------------

    /** Launch spec végrehajtása – a felügyelő hívja, hogy a program ne örökölje a webszerver socketjeit. */
    public static function launch(array $spec): bool
    {
        $cmd = $spec['cmdline'] ?? ($spec['argv'] ?? null);
        if (!$cmd) return false;
        $cwd = (isset($spec['cwd']) && is_dir($spec['cwd'])) ? $spec['cwd'] : null;
        $env = (isset($spec['env']) && is_array($spec['env'])) ? $spec['env'] : null;
        $pipes = [];
        $proc = @proc_open($cmd, [0 => ['null'], 1 => ['null'], 2 => ['null']], $pipes, $cwd, $env,
            ['bypass_shell' => true, 'suppress_errors' => true]);
        if (!is_resource($proc)) return false;
        if (isset($spec['cmdline'])) proc_close($proc);   // a "cmd /c start" és az "explorer" azonnal visszatér
        return true;                                      // a közvetlenül indított programot nem várjuk meg
    }

    /** "cmd.exe /d /s /c "start "cím" [/D "mappa"] [/B] "arg1" "arg2"..."" parancssor. */
    public static function cmdStart(string $title, array $args, ?string $dir = null, bool $sameConsole = true): string
    {
        $quote = function (string $s): string {
            if (strpos($s, '"') !== false) {
                throw new InvalidArgumentException('Idézőjelet tartalmazó útvonal nem indítható.');
            }
            return '"' . $s . '"';
        };
        $line = 'start ' . $quote($title);
        if ($dir !== null) $line .= ' /D ' . $quote($dir);
        if ($sameConsole) $line .= ' /B';
        foreach ($args as $arg) $line .= ' ' . $quote((string)$arg);
        return (getenv('ComSpec') ?: 'cmd.exe') . ' /d /s /c "' . $line . '"';
    }

    /** Mappa megnyitása az Intézőben. */
    public static function openFolderSpec(string $dir): array
    {
        return ['argv' => ['explorer.exe', $dir]];
    }

    /** Az Intéző megnyitása úgy, hogy a fájl ki legyen jelölve (pl. a visszajelzéshez csatolandó napló). */
    public static function revealFileSpec(string $file): array
    {
        if (strpos($file, '"') !== false) throw new InvalidArgumentException('Érvénytelen fájlnév.');
        return ['cmdline' => 'explorer.exe /select,"' . $file . '"'];
    }

    /** URL (http, https, file, mailto) vagy fájl megnyitása az alapértelmezett programmal. */
    public static function openSpec(string $target): array
    {
        if (preg_match('#^(https?://|file://|mailto:)#i', $target)) {
            // URL-eknél nem használunk cmd.exe-t: a %-kódolt karaktereket környezeti változónak nézné
            return ['argv' => ['rundll32.exe', 'url.dll,FileProtocolHandler', $target]];
        }
        return ['cmdline' => self::cmdStart('', [$target], null, false)];
    }

    /** Parancssor ablak a projekt mappájában; a PATH-ban a mellékelt php, composer, node és npm. */
    public static function terminalSpec(string $dir, string $title): array
    {
        return ['cmdline' => self::cmdStart($title, ['cmd.exe'], $dir, false), 'cwd' => $dir, 'env' => self::childEnv()];
    }

    /**
     * A kiválasztott szerkesztő elindítása a projekt mappájával.
     * @return array|string launch spec vagy hibaüzenet
     */
    public static function editorSpec(string $editor, string $dir)
    {
        $names = ['vscode' => 'VS Code', 'sublime' => 'Sublime Text', 'notepadpp' => 'Notepad++', 'phpstorm' => 'PhpStorm', 'webstorm' => 'WebStorm'];
        if (!isset($names[$editor])) return 'Érvénytelen szerkesztő.';

        $pf = (string)(getenv('ProgramFiles') ?: 'C:\\Program Files');
        $pf86 = (string)(getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)');
        $local = (string)(getenv('LOCALAPPDATA') ?: '');
        $jetbrains = function (string $product, string $exe) use ($pf, $local): array {
            $found = array_merge(
                glob($pf . '\\JetBrains\\' . $product . '*\\bin\\' . $exe) ?: [],
                glob($local . '\\Programs\\' . $product . '*\\bin\\' . $exe) ?: [],
                glob($local . '\\JetBrains\\Toolbox\\apps\\' . $product . '\\*\\*\\bin\\' . $exe) ?: []
            );
            rsort($found);   // a legújabb verzió előre
            $found[] = $local . '\\JetBrains\\Toolbox\\scripts\\' . strtolower($product) . '.cmd';
            return $found;
        };
        // [ismert telepítési helyek, PATH-ban keresett nevek, extra argumentumok]
        $options = [
            'vscode'    => [[$local . '\\Programs\\Microsoft VS Code\\Code.exe', $pf . '\\Microsoft VS Code\\Code.exe'], ['code.cmd'], []],
            'sublime'   => [[$pf . '\\Sublime Text\\sublime_text.exe', $pf . '\\Sublime Text 4\\sublime_text.exe', $pf . '\\Sublime Text 3\\sublime_text.exe'], ['subl.exe', 'sublime_text.exe'], []],
            'notepadpp' => [[$pf . '\\Notepad++\\notepad++.exe', $pf86 . '\\Notepad++\\notepad++.exe'], ['notepad++.exe'], ['-openFoldersAsWorkspace']],
            'phpstorm'  => [$jetbrains('PhpStorm', 'phpstorm64.exe'), ['phpstorm64.exe', 'phpstorm.cmd', 'phpstorm.bat'], []],
            'webstorm'  => [$jetbrains('WebStorm', 'webstorm64.exe'), ['webstorm64.exe', 'webstorm.cmd', 'webstorm.bat'], []],
        ];
        [$paths, $commands, $args] = $options[$editor];

        $exe = null;
        foreach ($paths as $path) {
            if (is_file($path)) {
                $exe = $path;
                break;
            }
        }
        if (!$exe) {
            foreach ($commands as $command) {
                if ($exe = self::which($command)) break;
            }
        }
        if (!$exe) return $names[$editor] . ' nem található ezen a gépen.';

        $argv = array_merge([$exe], $args, [$dir]);
        $env = self::childEnv();   // így a szerkesztő termináljában is elérhető a php, composer, node
        if (preg_match('/\.exe$/i', $exe)) return ['argv' => $argv, 'env' => $env];
        return ['cmdline' => self::cmdStart('', $argv), 'env' => $env];   // .cmd/.bat csak cmd.exe-n keresztül
    }

    // ------------------------------------------------------------------
    // Böngésző (vezérlőpult ablak, képernyőképek)
    // ------------------------------------------------------------------

    /** Chromium alapú böngésző keresése: Edge, Chrome, Brave, Vivaldi. A POCKETWEB_BROWSER változó felülírja. */
    public static function findBrowser(): ?array
    {
        $forced = getenv('POCKETWEB_BROWSER');
        if (is_string($forced) && $forced !== '') {
            if (in_array(strtolower($forced), ['none', 'default'], true)) return null;
            if (is_file($forced)) return ['id' => 'custom', 'name' => basename($forced), 'path' => $forced];
        }
        $pf = (string)(getenv('ProgramFiles') ?: 'C:\\Program Files');
        $pf86 = (string)(getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)');
        $local = (string)(getenv('LOCALAPPDATA') ?: '');
        $candidates = [];
        foreach ([$pf86, $pf, $local] as $base) $candidates[] = ['edge', 'Microsoft Edge', $base . '\\Microsoft\\Edge\\Application\\msedge.exe'];
        foreach ([$pf, $pf86, $local] as $base) $candidates[] = ['chrome', 'Google Chrome', $base . '\\Google\\Chrome\\Application\\chrome.exe'];
        foreach ([$pf, $local] as $base) $candidates[] = ['brave', 'Brave', $base . '\\BraveSoftware\\Brave-Browser\\Application\\brave.exe'];
        $candidates[] = ['vivaldi', 'Vivaldi', $local . '\\Vivaldi\\Application\\vivaldi.exe'];

        foreach ($candidates as [$id, $name, $path]) {
            if (is_file($path)) return ['id' => $id, 'name' => $name, 'path' => $path];
        }
        return null;
    }

    /** A vezérlőpult ablak saját, elkülönített böngészőprofilja. */
    public static function browserProfile(array $browser): string
    {
        if ($browser['id'] === 'edge') return pw_path(PW_SYS, '.edge_profile');   // a korábbi verziókkal azonos
        return pw_path(PW_SYS, '.browser_profile', $browser['id']);
    }

    /** Parancssor a vezérlőpult "alkalmazás" ablakához (címsor és fülek nélkül). */
    public static function appWindowCommand(array $browser, string $url): array
    {
        $profile = self::browserProfile($browser);
        $cmd = [$browser['path'], '--app=' . $url, '--user-data-dir=' . $profile, '--no-first-run', '--no-default-browser-check'];
        if (!is_dir($profile)) $cmd[] = '--window-size=1280,860';
        return $cmd;
    }

    /**
     * Fut-e még a vezérlőpult böngészője? A Chromium a profil "lockfile"-ját FILE_FLAG_DELETE_ON_CLOSE-zal,
     * csak olvasást engedve nyitja meg: amíg fut, a fájl létezik és írásra nem nyitható meg;
     * kilépéskor (összeomláskor is) a Windows törli.
     */
    public static function browserRunning(string $profile): bool
    {
        $lock = pw_path($profile, 'lockfile');
        if (!is_file($lock)) return false;
        $handle = @fopen($lock, 'r+');
        if ($handle === false) return true;
        fclose($handle);
        return false;
    }

    /** Helyi fájl URL-je (file:///C:/...), a böngészőknek. */
    public static function fileUrl(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        foreach ($parts as $i => $part) {
            if (!($i === 0 && preg_match('/^[A-Za-z]:$/', $part))) $parts[$i] = rawurlencode($part);
        }
        $url = implode('/', $parts);
        return 'file://' . (substr($url, 0, 1) === '/' ? '' : '/') . $url;
    }
}
