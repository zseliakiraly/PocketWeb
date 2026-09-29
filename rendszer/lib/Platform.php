<?php
/*
 * PocketWeb – az operációs rendszertől függő műveletek egy helyen.
 * Támogatott: Windows 10/11, Linux (Ubuntu, Debian, Mint, Fedora, Arch, openSUSE ...) és macOS.
 *
 * Fontos Windows-sajátosság: a proc_open() minden örökölhető handle-t továbbad a gyereknek,
 * a beépített PHP szerver socketjeit is. Ezért hosszan futó programot (szervert, szerkesztőt,
 * parancssor ablakot) soha nem az api.php indít, hanem a felügyelő (pocketweb.php) – az api.php
 * csak a "launch spec"-et állítja össze és üzenetsoron átküldi.
 */
class Platform
{
    // ------------------------------------------------------------------
    // Alapok
    // ------------------------------------------------------------------

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function isMac(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /** 'windows' | 'macos' | 'linux' */
    public static function os(): string
    {
        if (self::isWindows()) return 'windows';
        if (self::isMac()) return 'macos';
        return 'linux';
    }

    public static function osLabel(): string
    {
        return ['windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux'][self::os()];
    }

    public static function home(): string
    {
        return (string)(getenv('HOME') ?: getenv('USERPROFILE') ?: '');
    }

    /** Program keresése a PATH-ban (mint a "which" / "where" parancs). */
    public static function which(string $name): ?string
    {
        $exts = [''];
        if (self::isWindows() && pathinfo($name, PATHINFO_EXTENSION) === '') {
            // Windowson a kiterjesztés nélküli fájl (pl. a VS Code "code" bash szkriptje) nem futtatható
            $exts = explode(';', strtolower((string)(getenv('PATHEXT') ?: '.com;.exe;.bat;.cmd')));
        }
        foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
            $dir = trim($dir, " \"");
            if ($dir === '') continue;
            foreach ($exts as $ext) {
                $file = $dir . DIRECTORY_SEPARATOR . $name . $ext;
                if (is_file($file) && (self::isWindows() || is_executable($file))) return $file;
            }
        }
        return null;
    }

    /** Igaz, ha $path a $dir mappán belül van. */
    public static function isInside(string $path, string $dir): bool
    {
        $norm = function (string $p): string {
            $p = realpath($p) ?: $p;   // pl. Windows rövid (8.3) nevek, szimbolikus linkek
            $p = rtrim(str_replace('\\', '/', $p), '/') . '/';
            return Platform::isWindows() ? strtolower($p) : $p;
        };
        return strpos($norm($path), $norm($dir)) === 0;
    }

    // ------------------------------------------------------------------
    // Mellékelt programok (php, node, composer) és környezeti változók
    // ------------------------------------------------------------------

    /** Az éppen futó PHP – a felügyelő, a vezérlőpult és a feladatok is ugyanezt használják. */
    public static function php(): string
    {
        return PHP_BINARY;
    }

    public static function composerPhar(): string
    {
        return pw_path(PW_SYS, 'php', 'composer.phar');
    }

    /** Mellékelt Node.js (rendszer/node), ha nincs, a rendszerre telepített. */
    public static function node(): ?string
    {
        $candidates = self::isWindows()
            ? [pw_path(PW_SYS, 'node', 'node.exe')]
            : [pw_path(PW_SYS, 'node', 'bin', 'node'), pw_path(PW_SYS, 'node', 'node')];
        foreach ($candidates as $file) {
            if (is_file($file) && (self::isWindows() || is_executable($file))) return $file;
        }
        return self::which('node');
    }

    /** A PATH elejére kerülő mappák: rendszer/bin (composer), a mellékelt php és node. */
    public static function pathPrefix(): array
    {
        $dirs = [pw_path(PW_SYS, 'bin')];
        if (self::isInside(PHP_BINARY, PW_SYS)) $dirs[] = dirname(PHP_BINARY);
        $node = self::node();
        if ($node && self::isInside($node, PW_SYS)) $dirs[] = dirname($node);
        return $dirs;
    }

    /** Windowson a változónevek kis-/nagybetűre érzéketlenek (Path vs. PATH) – a meglévő kulcsot adja vissza. */
    public static function envKey(array $env, string $name): string
    {
        if (self::isWindows()) {
            foreach ($env as $key => $_) {
                if (strcasecmp((string)$key, $name) === 0) return (string)$key;
            }
        }
        return $name;
    }

    /** A gyerekfolyamatok környezete: az aktuális környezet + kiegészített PATH + $extra (null = törlés). */
    public static function childEnv(array $extra = []): array
    {
        $env = getenv();
        if (!is_array($env)) $env = [];
        unset($env[self::envKey($env, 'PHP_CLI_SERVER_WORKERS')]);

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
     * Háttérfolyamat indítása; a kimenet (stdout + stderr) a $logFile-ba kerül.
     * A napló mindig új fájl. Hozzáfűző ('a') módban nyitjuk: Unixon így minden írás a fájl végére
     * kerül akkor is, ha egy unoka-folyamat fájlpozíciója elállítódna. A gyerekek örökölt leírói
     * közös fájlmutatót használnak (Windowson is), ezért az unokák kimenete sorrendben követi egymást.
     * @return resource|null
     */
    public static function spawn(array $cmd, string $cwd, array $env, string $logFile)
    {
        $descriptors = [0 => ['null'], 1 => ['file', $logFile, 'a'], 2 => ['redirect', 1]];
        $options = self::isWindows() ? ['bypass_shell' => true, 'suppress_errors' => true] : [];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, $env, $options);
        return is_resource($proc) ? $proc : null;
    }

    public static function isAlive(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (self::isWindows()) {
            $out = (string)shell_exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL');
            return strpos($out, '"' . $pid . '"') !== false;
        }
        if (is_dir('/proc/self')) {
            $stat = @file_get_contents("/proc/$pid/stat");
            if ($stat === false) return false;
            $state = substr($stat, strrpos($stat, ')') + 2, 1);
            return $state !== 'Z' && $state !== 'X';   // a zombi már nem fut
        }
        $out = trim((string)shell_exec('ps -o stat= -p ' . $pid . ' 2>/dev/null'));
        return $out !== '' && strpos($out, 'Z') === false;
    }

    /** A folyamat programjának neve kisbetűvel (pl. "php.exe", "php"), vagy null. */
    public static function processName(int $pid): ?string
    {
        if ($pid <= 0) return null;
        if (self::isWindows()) {
            $out = (string)shell_exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV /NH 2>NUL');
            if (preg_match('/^"([^"]+)","' . $pid . '"/m', $out, $m)) return strtolower($m[1]);
            return null;
        }
        $out = trim((string)shell_exec('ps -p ' . $pid . ' -o comm= 2>/dev/null'));
        return $out === '' ? null : strtolower(basename($out));
    }

    /** Folyamatok leállítása az összes leszármazottjukkal együtt (pl. artisan serve + a php -S gyereke). */
    public static function killTree(array $pids): void
    {
        $pids = array_values(array_filter(array_map('intval', $pids), function ($p) { return $p > 0; }));
        if (!$pids) return;

        if (self::isWindows()) {
            foreach ($pids as $pid) {
                exec('taskkill /PID ' . $pid . ' /T /F >NUL 2>&1');
            }
            return;
        }

        // Unix: előbb összegyűjtjük a teljes fát (a szülő halála után a gyerekek "elárvulnak")
        $children = self::processChildren();
        $all = [];
        $stack = $pids;
        while ($stack) {
            $pid = array_pop($stack);
            if (isset($all[$pid])) continue;
            $all[$pid] = true;
            foreach ($children[$pid] ?? [] as $child) $stack[] = $child;
        }
        $all = array_keys($all);

        self::signal($all, 15);                          // SIGTERM – udvarias kérés
        $deadline = microtime(true) + 3.0;
        do {
            $alive = array_values(array_filter($all, [self::class, 'isAlive']));
            if (!$alive) return;
            usleep(100000);
        } while (microtime(true) < $deadline);
        self::signal($alive, 9);                         // SIGKILL – aki nem állt le magától
    }

    /** @return array<int,int[]> szülő PID => gyerek PID-ek */
    private static function processChildren(): array
    {
        $children = [];
        if (is_dir('/proc/self')) {
            foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
                $stat = @file_get_contents($file);
                if ($stat === false) continue;
                $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
                $children[(int)($fields[1] ?? 0)][] = (int)basename(dirname($file));
            }
            return $children;
        }
        $out = (string)shell_exec('ps -A -o pid= -o ppid= 2>/dev/null');
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)/', $line, $m)) $children[(int)$m[2]][] = (int)$m[1];
        }
        return $children;
    }

    private static function signal(array $pids, int $signal): void
    {
        if (!$pids) return;
        if (function_exists('posix_kill')) {
            foreach ($pids as $pid) @posix_kill((int)$pid, $signal);
            return;
        }
        exec('kill -' . $signal . ' ' . implode(' ', array_map('intval', $pids)) . ' >/dev/null 2>&1');
    }

    /**
     * A gépen figyelő (LISTEN) TCP portok.
     * @return array<int,int>|null port => PID (0, ha nem ismert); null, ha nem kérdezhető le (ilyenkor isListening() próbálkozik)
     */
    public static function listeningPorts(): ?array
    {
        if (self::isWindows()) {
            $out = shell_exec('netstat -ano 2>NUL');
            if (!is_string($out) || trim($out) === '') return null;
            $ports = [];
            foreach (preg_split('/\r?\n/', $out) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) < 5 || strtoupper($cols[0]) !== 'TCP') continue;
                // Figyelő socket: a távoli cím portja 0. Így nem függünk a nyelvtől ("LISTENING" / "FIGYELÉS").
                if (!preg_match('/:0$/', $cols[2]) || !preg_match('/:(\d+)$/', $cols[1], $m)) continue;
                $ports[(int)$m[1]] = (int)end($cols);
            }
            return $ports;
        }
        if (is_readable('/proc/net/tcp')) {
            $ports = [];
            foreach (self::procListenSockets() as $socket) {
                $ports[$socket['port']] = 0;
            }
            return $ports;
        }
        $out = shell_exec('netstat -an -p tcp 2>/dev/null');   // macOS / BSD
        if (!is_string($out) || trim($out) === '') return null;
        $ports = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (preg_match('/^tcp\S*\s+\d+\s+\d+\s+(\S+)\s+\S+\s+LISTEN/i', trim($line), $m)) {
                $ports[(int)substr($m[1], strrpos($m[1], '.') + 1)] = 0;
            }
        }
        return $ports;
    }

    /** Figyel-e valami a porton? $ports: a listeningPorts() előre lekért eredménye. */
    public static function isListening(int $port, ?array $ports = null): bool
    {
        if ($port <= 0) return false;
        if ($ports !== null) return isset($ports[$port]);
        $fp = @fsockopen(PW_HOST, $port, $errno, $errstr, 0.3);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /** A porton figyelő folyamat(ok) PID-je. */
    public static function pidsOnPort(int $port): array
    {
        if (self::isWindows()) {
            $ports = self::listeningPorts() ?? [];
            return !empty($ports[$port]) ? [$ports[$port]] : [];
        }
        if (is_readable('/proc/net/tcp')) {
            $inodes = [];
            foreach (self::procListenSockets() as $socket) {
                if ($socket['port'] === $port) $inodes['socket:[' . $socket['inode'] . ']'] = true;
            }
            if (!$inodes) return [];
            $pids = [];
            foreach (glob('/proc/[0-9]*/fd/*') ?: [] as $fd) {
                $link = @readlink($fd);
                if ($link !== false && isset($inodes[$link])) $pids[] = (int)explode('/', $fd)[2];
            }
            return array_values(array_unique($pids));
        }
        $out = (string)shell_exec('lsof -nP -t -iTCP:' . $port . ' -sTCP:LISTEN 2>/dev/null');
        return array_values(array_filter(array_map('intval', preg_split('/\s+/', trim($out)))));
    }

    /** Linux: a /proc/net/tcp(6) LISTEN állapotú sorai. */
    private static function procListenSockets(): array
    {
        $sockets = [];
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
            foreach (@file($file) ?: [] as $i => $line) {
                if ($i === 0) continue;
                $cols = preg_split('/\s+/', trim($line));
                if (count($cols) < 10 || $cols[3] !== '0A') continue;   // 0A = LISTEN
                $sockets[] = ['port' => (int)hexdec(substr($cols[1], strrpos($cols[1], ':') + 1)), 'inode' => $cols[9]];
            }
        }
        return $sockets;
    }

    // ------------------------------------------------------------------
    // Asztali programok indítása ("launch spec": ['argv' => [...]] vagy Windowson ['cmdline' => '...'])
    // ------------------------------------------------------------------

    /** Launch spec végrehajtása – a felügyelő hívja, hogy a program ne örökölje a webszerver socketjeit. */
    public static function launch(array $spec): bool
    {
        $cwd = (isset($spec['cwd']) && is_dir($spec['cwd'])) ? $spec['cwd'] : null;
        $env = (isset($spec['env']) && is_array($spec['env'])) ? $spec['env'] : null;
        $null = [0 => ['null'], 1 => ['null'], 2 => ['null']];
        $pipes = [];

        if (self::isWindows()) {
            $cmd = $spec['cmdline'] ?? ($spec['argv'] ?? null);
            if (!$cmd) return false;
            $proc = @proc_open($cmd, $null, $pipes, $cwd, $env, ['bypass_shell' => true, 'suppress_errors' => true]);
            if (!is_resource($proc)) return false;
            if (isset($spec['cmdline'])) proc_close($proc);   // a "cmd /c start" azonnal visszatér
            return true;                                      // a közvetlenül indított programot nem várjuk meg
        }

        if (empty($spec['argv'])) return false;
        // Leválasztjuk (setsid/nohup), hogy a PocketWeb leállása ne zárja be a megnyitott programot
        $detach = self::which('setsid') ? 'setsid' : 'nohup';
        $argv = array_merge(['/bin/sh', '-c', $detach . ' "$@" </dev/null >/dev/null 2>&1 &', 'pocketweb'], $spec['argv']);
        $proc = @proc_open($argv, $null, $pipes, $cwd, $env);
        if (!is_resource($proc)) return false;
        proc_close($proc);
        return true;
    }

    /** Windows: "cmd.exe /d /s /c "start "cím" [/D "mappa"] [/B] "arg1" "arg2"..."" parancssor. */
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

    /** Mappa megnyitása a fájlkezelőben. */
    public static function openFolderSpec(string $dir): array
    {
        if (self::isWindows()) return ['argv' => ['explorer.exe', $dir]];
        if (self::isMac()) return ['argv' => ['open', $dir]];
        return ['argv' => ['xdg-open', $dir]];
    }

    /** Fájl vagy URL megnyitása az alapértelmezett programmal (böngészővel). */
    public static function openSpec(string $target): array
    {
        if (self::isWindows()) {
            if (preg_match('#^(https?|file)://#i', $target)) {
                // URL-eknél nem használunk cmd.exe-t: a %-kódolt karaktereket környezeti változónak nézné
                return ['argv' => ['rundll32.exe', 'url.dll,FileProtocolHandler', $target]];
            }
            return ['cmdline' => self::cmdStart('', [$target], null, false)];
        }
        if (self::isMac()) return ['argv' => ['open', $target]];
        return ['argv' => ['xdg-open', $target]];
    }

    /**
     * Rendszer parancssor ablak megnyitása a mappában, a PATH-ban a mellékelt php/node/composer.
     * @return array|string launch spec vagy hibaüzenet
     */
    public static function terminalSpec(string $dir, string $title)
    {
        $env = self::childEnv();
        if (self::isWindows()) {
            return ['cmdline' => self::cmdStart($title, ['cmd.exe'], $dir, false), 'cwd' => $dir, 'env' => $env];
        }

        $shell = ['/bin/sh', pw_path(PW_SYS, 'bin', 'pw-shell.sh'), $dir];
        if (self::isMac()) {
            // A Terminal.app nem veszi át a környezetet, ezért egy .command fájlt nyitunk meg vele
            @mkdir(PW_RUN, 0777, true);
            $file = pw_path(PW_RUN, 'terminal-' . substr(md5($dir), 0, 8) . '.command');
            $q = function (string $s): string { return "'" . str_replace("'", "'\\''", $s) . "'"; };
            file_put_contents($file, "#!/bin/sh\nexec " . implode(' ', array_map($q, $shell)) . "\n");
            @chmod($file, 0755);
            return ['argv' => ['open', '-a', 'Terminal', $file], 'env' => $env];
        }

        $join = function (array $cmd): string {
            return implode(' ', array_map(function ($s) { return "'" . str_replace("'", "'\\''", $s) . "'"; }, $cmd));
        };
        $terminals = [
            'gnome-terminal' => function ($d, $c) { return array_merge(['gnome-terminal', '--working-directory=' . $d, '--'], $c); },
            'ptyxis'         => function ($d, $c) { return array_merge(['ptyxis', '--new-window', '--working-directory=' . $d, '--'], $c); },
            'konsole'        => function ($d, $c) { return array_merge(['konsole', '--workdir', $d, '-e'], $c); },
            'xfce4-terminal' => function ($d, $c) { return array_merge(['xfce4-terminal', '--working-directory=' . $d, '-x'], $c); },
            'mate-terminal'  => function ($d, $c) { return array_merge(['mate-terminal', '--working-directory=' . $d, '-x'], $c); },
            'tilix'          => function ($d, $c) use ($join) { return ['tilix', '--working-directory=' . $d, '-e', $join($c)]; },
            'terminator'     => function ($d, $c) { return array_merge(['terminator', '--working-directory=' . $d, '-x'], $c); },
            'lxterminal'     => function ($d, $c) use ($join) { return ['lxterminal', '--working-directory=' . $d, '-e', $join($c)]; },
            'qterminal'      => function ($d, $c) { return array_merge(['qterminal', '-w', $d, '-e'], $c); },
            'alacritty'      => function ($d, $c) { return array_merge(['alacritty', '--working-directory', $d, '-e'], $c); },
            'kitty'          => function ($d, $c) { return array_merge(['kitty', '--directory', $d], $c); },
            'wezterm'        => function ($d, $c) { return array_merge(['wezterm', 'start', '--cwd', $d, '--'], $c); },
            'foot'           => function ($d, $c) { return array_merge(['foot', '--working-directory=' . $d], $c); },
            'x-terminal-emulator' => function ($d, $c) { return array_merge(['x-terminal-emulator', '-e'], $c); },
            'xterm'          => function ($d, $c) { return array_merge(['xterm', '-e'], $c); },
        ];
        // Az asztali környezet saját terminálja előre kerül
        $desktop = strtolower((string)getenv('XDG_CURRENT_DESKTOP'));
        $preferred = [];
        if (strpos($desktop, 'kde') !== false) $preferred = ['konsole'];
        elseif (strpos($desktop, 'xfce') !== false) $preferred = ['xfce4-terminal'];
        elseif (strpos($desktop, 'mate') !== false) $preferred = ['mate-terminal'];
        elseif (strpos($desktop, 'lxqt') !== false) $preferred = ['qterminal'];
        elseif (strpos($desktop, 'lxde') !== false) $preferred = ['lxterminal'];
        foreach (array_unique(array_merge($preferred, array_keys($terminals))) as $name) {
            if (self::which($name)) {
                return ['argv' => $terminals[$name]($dir, $shell), 'cwd' => $dir, 'env' => $env];
            }
        }
        return 'Nem található terminál program (pl. gnome-terminal, konsole, xfce4-terminal).';
    }

    /**
     * A kiválasztott szerkesztő elindítása a projekt mappájával.
     * @return array|string launch spec vagy hibaüzenet
     */
    public static function editorSpec(string $editor, string $dir)
    {
        $names = ['vscode' => 'VS Code', 'sublime' => 'Sublime Text', 'notepadpp' => 'Notepad++', 'phpstorm' => 'PhpStorm', 'webstorm' => 'WebStorm'];
        if (!isset($names[$editor])) return 'Érvénytelen szerkesztő.';
        $notFound = $names[$editor] . ' nem található ezen a gépen.';
        $env = self::childEnv();

        if (self::isWindows()) {
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
                return $found;
            };
            $options = [
                'vscode'    => [[$local . '\\Programs\\Microsoft VS Code\\Code.exe', $pf . '\\Microsoft VS Code\\Code.exe'], ['code.cmd'], []],
                'sublime'   => [[$pf . '\\Sublime Text\\sublime_text.exe', $pf . '\\Sublime Text 4\\sublime_text.exe', $pf . '\\Sublime Text 3\\sublime_text.exe'], ['subl.exe', 'sublime_text.exe'], []],
                'notepadpp' => [[$pf . '\\Notepad++\\notepad++.exe', $pf86 . '\\Notepad++\\notepad++.exe'], ['notepad++.exe'], ['-openFoldersAsWorkspace']],
                'phpstorm'  => [$jetbrains('PhpStorm', 'phpstorm64.exe'), ['phpstorm64.exe', 'phpstorm.cmd', 'phpstorm.bat'], []],
                'webstorm'  => [$jetbrains('WebStorm', 'webstorm64.exe'), ['webstorm64.exe', 'webstorm.cmd', 'webstorm.bat'], []],
            ];
            [$paths, $commands, $args] = $options[$editor];
            if ($editor === 'phpstorm' || $editor === 'webstorm') {
                $paths[] = $local . '\\JetBrains\\Toolbox\\scripts\\' . ($editor === 'phpstorm' ? 'phpstorm.cmd' : 'webstorm.cmd');
            }
            $exe = null;
            foreach ($paths as $p) if (is_file($p)) { $exe = $p; break; }
            if (!$exe) foreach ($commands as $c) if ($exe = self::which($c)) break;
            if (!$exe) return $notFound;
            $argv = array_merge([$exe], $args, [$dir]);
            if (preg_match('/\.exe$/i', $exe)) return ['argv' => $argv, 'env' => $env];
            return ['cmdline' => self::cmdStart('', $argv), 'env' => $env];   // .cmd/.bat csak cmd.exe-n keresztül
        }

        if (self::isMac()) {
            $apps = ['vscode' => 'Visual Studio Code', 'sublime' => 'Sublime Text', 'phpstorm' => 'PhpStorm', 'webstorm' => 'WebStorm'];
            if (!isset($apps[$editor])) return 'A Notepad++ csak Windowson érhető el.';
            foreach (['/Applications', self::home() . '/Applications'] as $base) {
                if (is_dir($base . '/' . $apps[$editor] . '.app')) {
                    $argv = in_array($editor, ['phpstorm', 'webstorm'], true)
                        ? ['open', '-na', $apps[$editor], '--args', $dir]   // a JetBrains által javasolt mód
                        : ['open', '-a', $apps[$editor], $dir];
                    return ['argv' => $argv, 'env' => $env];
                }
            }
            $cli = ['vscode' => 'code', 'sublime' => 'subl', 'phpstorm' => 'phpstorm', 'webstorm' => 'webstorm'][$editor];
            if ($path = self::which($cli)) return ['argv' => [$path, $dir], 'env' => $env];
            return $notFound;
        }

        // Linux
        $home = self::home();
        $options = [
            'vscode'    => ['code', 'codium', 'code-oss'],
            'sublime'   => ['subl', 'sublime_text'],
            'notepadpp' => [],
            'phpstorm'  => ['phpstorm', 'phpstorm.sh'],
            'webstorm'  => ['webstorm', 'webstorm.sh'],
        ];
        if ($editor === 'notepadpp') return 'A Notepad++ csak Windowson érhető el.';
        foreach ($options[$editor] as $cmd) {
            if ($path = self::which($cmd)) return ['argv' => [$path, $dir], 'env' => $env];
        }
        if ($editor === 'phpstorm' || $editor === 'webstorm') {
            $product = $editor === 'phpstorm' ? 'phpstorm' : 'webstorm';
            $found = array_merge(
                glob($home . '/.local/share/JetBrains/Toolbox/scripts/' . $product) ?: [],
                glob($home . '/.local/share/JetBrains/Toolbox/apps/*' . $product . '*/bin/' . $product . '.sh') ?: [],
                glob('/opt/*[Pp][Hh][Pp][Ss]torm*/bin/phpstorm.sh') ?: [],
                glob('/opt/*[Ww]eb[Ss]torm*/bin/webstorm.sh') ?: []
            );
            foreach ($found as $path) {
                if (strpos(strtolower($path), $product) !== false && is_file($path)) return ['argv' => [$path, $dir], 'env' => $env];
            }
        }
        return $notFound;
    }

    // ------------------------------------------------------------------
    // Böngésző (vezérlőpult ablak, képernyőképek)
    // ------------------------------------------------------------------

    /** Chromium alapú böngésző keresése (Edge, Chrome, Chromium, Brave, Vivaldi). POCKETWEB_BROWSER felülírja. */
    public static function findBrowser(): ?array
    {
        $forced = getenv('POCKETWEB_BROWSER');
        if (is_string($forced) && $forced !== '') {
            if (in_array(strtolower($forced), ['none', 'default', 'system'], true)) return null;
            $path = is_file($forced) ? $forced : self::which($forced);
            if ($path) return ['id' => 'custom', 'name' => basename($path), 'path' => $path];
        }
        foreach (self::browserCandidates() as $candidate) {
            [$id, $name, $path] = $candidate;
            if ($path && is_file($path)) return ['id' => $id, 'name' => $name, 'path' => $path];
        }
        return null;
    }

    private static function browserCandidates(): array
    {
        $list = [];
        if (self::isWindows()) {
            $pf = (string)(getenv('ProgramFiles') ?: 'C:\\Program Files');
            $pf86 = (string)(getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)');
            $local = (string)(getenv('LOCALAPPDATA') ?: '');
            foreach ([$pf86, $pf, $local] as $base) $list[] = ['edge', 'Microsoft Edge', $base . '\\Microsoft\\Edge\\Application\\msedge.exe'];
            foreach ([$pf, $pf86, $local] as $base) $list[] = ['chrome', 'Google Chrome', $base . '\\Google\\Chrome\\Application\\chrome.exe'];
            foreach ([$pf, $local] as $base) $list[] = ['brave', 'Brave', $base . '\\BraveSoftware\\Brave-Browser\\Application\\brave.exe'];
            $list[] = ['vivaldi', 'Vivaldi', $local . '\\Vivaldi\\Application\\vivaldi.exe'];
            return $list;
        }
        if (self::isMac()) {
            $apps = [
                ['chrome', 'Google Chrome', 'Google Chrome.app/Contents/MacOS/Google Chrome'],
                ['edge', 'Microsoft Edge', 'Microsoft Edge.app/Contents/MacOS/Microsoft Edge'],
                ['chromium', 'Chromium', 'Chromium.app/Contents/MacOS/Chromium'],
                ['brave', 'Brave', 'Brave Browser.app/Contents/MacOS/Brave Browser'],
                ['vivaldi', 'Vivaldi', 'Vivaldi.app/Contents/MacOS/Vivaldi'],
            ];
            foreach ($apps as [$id, $name, $rel]) {
                $list[] = [$id, $name, '/Applications/' . $rel];
                $list[] = [$id, $name, self::home() . '/Applications/' . $rel];
            }
            return $list;
        }
        $bins = [
            ['chrome', 'Google Chrome', 'google-chrome-stable'], ['chrome', 'Google Chrome', 'google-chrome'],
            ['chromium', 'Chromium', 'chromium'], ['chromium', 'Chromium', 'chromium-browser'],
            ['edge', 'Microsoft Edge', 'microsoft-edge-stable'], ['edge', 'Microsoft Edge', 'microsoft-edge'],
            ['brave', 'Brave', 'brave-browser'], ['brave', 'Brave', 'brave'],
            ['vivaldi', 'Vivaldi', 'vivaldi-stable'], ['vivaldi', 'Vivaldi', 'vivaldi'],
        ];
        foreach ($bins as [$id, $name, $bin]) $list[] = [$id, $name, self::which($bin)];
        return $list;
    }

    /** A vezérlőpult ablak saját, elkülönített böngészőprofilja. */
    public static function browserProfile(array $browser): string
    {
        if ($browser['id'] === 'edge' && self::isWindows()) return pw_path(PW_SYS, '.edge_profile');   // a korábbi verziókkal azonos
        return pw_path(PW_SYS, '.browser_profile', $browser['id']);
    }

    /** Parancssor a vezérlőpult "alkalmazás" ablakához (címsor és fülek nélkül). */
    public static function appWindowCommand(array $browser, string $url): array
    {
        $profile = self::browserProfile($browser);
        $cmd = [$browser['path'], '--app=' . $url, '--user-data-dir=' . $profile, '--no-first-run', '--no-default-browser-check'];
        if (!is_dir($profile)) $cmd[] = '--window-size=1280,860';
        if (self::runningAsRoot()) $cmd[] = '--no-sandbox';
        return $cmd;
    }

    public static function runningAsRoot(): bool
    {
        return !self::isWindows() && function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * Fut-e még a vezérlőpult böngészője (a profil zárfájlja alapján)?
     * Windows: a Chromium a "lockfile"-t FILE_FLAG_DELETE_ON_CLOSE-zal, csak olvasást engedve nyitja meg,
     *          így amíg fut, a fájl létezik és írásra nem nyitható meg.
     * Linux/macOS: a "SingletonLock" szimbolikus link célja "gépnév-PID".
     */
    public static function browserRunning(string $profile): bool
    {
        if (self::isWindows()) {
            $lock = $profile . '\\lockfile';
            if (!is_file($lock)) return false;
            $handle = @fopen($lock, 'r+');
            if ($handle === false) return true;
            fclose($handle);
            return false;
        }
        $lock = self::singletonLock($profile);
        return $lock !== null && $lock['host'] === gethostname() && self::isAlive($lock['pid']);
    }

    /** Linux/macOS: beragadt SingletonLock törlése (összeomlás után vagy másik gépről), hogy a böngésző ne kérdezzen rá. */
    public static function clearStaleBrowserLock(string $profile): void
    {
        if (self::isWindows()) return;
        $lock = self::singletonLock($profile);
        if ($lock === null || ($lock['host'] === gethostname() && self::isAlive($lock['pid']))) return;
        foreach (['SingletonLock', 'SingletonSocket', 'SingletonCookie'] as $name) {
            @unlink($profile . '/' . $name);
        }
    }

    private static function singletonLock(string $profile): ?array
    {
        $target = @readlink($profile . '/SingletonLock');
        if (!is_string($target) || ($pos = strrpos($target, '-')) === false) return null;
        return ['host' => substr($target, 0, $pos), 'pid' => (int)substr($target, $pos + 1)];
    }

    /** Helyi fájl URL-je (file:///...), a böngészőknek. */
    public static function fileUrl(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        foreach ($parts as $i => $part) {
            if (!($i === 0 && preg_match('/^[A-Za-z]:$/', $part))) $parts[$i] = rawurlencode($part);
        }
        $url = implode('/', $parts);
        return 'file://' . ($url[0] === '/' ? '' : '/') . $url;
    }
}
