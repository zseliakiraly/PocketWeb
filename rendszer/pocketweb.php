<?php
/*
 * PocketWeb felügyelő – ez a folyamat fut a háttérben, amíg a vezérlőpult nyitva van.
 *
 *  - elindítja a vezérlőpult webszerverét (php -S 127.0.0.1:8181)
 *  - megnyitja a vezérlőpultot egy böngésző "alkalmazás" ablakban (Edge, Chrome, Chromium, Brave ...)
 *  - elindítja és leállítja a projektek szervereit és a telepítőket – külön ablakok helyett
 *    a kimenetük a vezérlőpult Terminál paneljén látszik
 *  - ha a vezérlőpult ablakát bezárják, mindent leállít
 *
 * Indítás: indito.bat (a mellékelt rendszer\php\php.exe-vel)
 * Kapcsolók:
 *   --preflight   csak ellenőriz; ha már fut egy példány, megnyitja a vezérlőpultot (kilépési kód: 0 = indítható, 2 = már fut, 1 = hiba)
 *   --no-browser  nem nyit böngészőt (a vezérlőpult kézzel nyitható meg)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';

error_reporting(E_ALL);
set_time_limit(0);

final class Supervisor
{
    /** Minden feladat kapja: színes kimenet akkor is, ha nem terminálba ír. */
    const JOB_ENV = ['FORCE_COLOR' => '1', 'CLICOLOR_FORCE' => '1', 'COMPOSER_NO_INTERACTION' => '1', 'NO_COLOR' => null];
    /** A vezérlőpult ablakának címe (index.html <title>) – ezt sosem hozzuk előre más helyett. */
    const DASHBOARD_TITLE = 'PocketWeb Vezérlőpult';

    private $dashboard = null;       // a vezérlőpult webszerverének folyamata
    private $browserProc = null;     // a vezérlőpult ablak (ha a mi gyerekünk)
    private $browser = null;         // a talált böngésző adatai
    private $mode = 'none';          // 'app' (saját ablak) | 'browser' (alapértelmezett böngésző) | 'none'
    private $launchedAt = 0.0;
    private $appSeen = false;
    private $appGoneSince = null;
    private $jobs = [];              // id => [proc, pid, spec, startedAt, stopRequested, remove]
    private $stop = false;
    private $stopReason = '';
    private $dashboardRestarts = 0;
    private $token = '';
    private $url;
    private $focusWatches = [];      // előtérbe hozandó ablakok: [hint, az indítás előtti ablakok, kezdés]

    public function __construct()
    {
        $this->url = 'http://' . PW_HOST . ':' . PW_PORT . '/';
    }

    public function run(array $argv): int
    {
        $preflight = in_array('--preflight', $argv, true);
        $noBrowser = in_array('--no-browser', $argv, true) || getenv('POCKETWEB_NO_BROWSER');

        if (PHP_VERSION_ID < 70400) {
            $this->say('Hiba: a PocketWebhez legalább PHP 7.4 kell (Laravelhez 8.2+). Ez a PHP: ' . PHP_VERSION);
            return 1;
        }
        @mkdir(PW_PROJECTS, 0777, true);
        @mkdir(PW_RUN, 0777, true);

        // 1. Fut már egy példány?
        $probe = $this->probe();
        if ($probe && $probe['pocketweb'] && $probe['shuttingDown']) {
            $this->say('Az előző PocketWeb épp leáll, megvárom...');
            $this->waitPortFree(15);
            $probe = $this->probe();
        }
        if ($probe && $probe['pocketweb'] && $probe['supervisor']) {
            $this->say('A PocketWeb már fut – megnyitom a vezérlőpultot.');
            if (!$noBrowser) $this->openDashboard(false);
            return $preflight ? 2 : 0;
        }
        if ($probe && $probe['pocketweb']) {
            $this->say('Árván maradt vezérlőpult szerver leállítása...');
            Platform::killTree(Platform::pidsOnPort(PW_PORT));
            $this->waitPortFree(10);
            $probe = $this->probe();
        }
        if ($probe) {
            $this->say('Hiba: a ' . PW_PORT . '-es porton már fut valami, ami nem válaszol PocketWebként.');
            $this->say('Ha egy másik program használja, zárd be, vagy indítsd a PocketWebet másik porttal (POCKETWEB_PORT környezeti változó).');
            return 1;
        }
        if ($preflight) return 0;

        $this->say('PocketWeb ' . PW_VERSION . ' – PHP ' . PHP_VERSION);

        // 2. Tiszta lap, az előző futás árván maradt folyamatainak leállítása
        $this->prepareRuntime();

        // 3. Vezérlőpult webszerver
        if (!$this->startDashboard()) {
            $this->cleanup();
            return 1;
        }
        $this->say('Vezérlőpult: ' . $this->url);

        // 4. Böngésző ablak
        if ($noBrowser) {
            $this->mode = 'browser';
        } else {
            $this->openDashboard(true);
        }
        $this->writeInfo();
        $this->say('Leállítás: zárd be a vezérlőpult ablakát (vagy Kilépés gomb).');

        // 5. Főciklus
        $this->installSignalHandlers();
        $this->loop();
        $this->shutdown();
        return 0;
    }

    // ------------------------------------------------------------------
    // Indítás
    // ------------------------------------------------------------------

    /** null: a port szabad; egyébként ['pocketweb' => bool, 'supervisor' => bool, 'shuttingDown' => bool] */
    private function probe(): ?array
    {
        if (!$this->portOpen()) return null;
        $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($this->url . 'api.php?action=ping', false, $context);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || ($data['app'] ?? '') !== 'PocketWeb') {
            return ['pocketweb' => false, 'supervisor' => false, 'shuttingDown' => false];
        }
        return ['pocketweb' => true, 'supervisor' => !empty($data['supervisor']), 'shuttingDown' => !empty($data['shuttingDown'])];
    }

    private function portOpen(): bool
    {
        $socket = @fsockopen(PW_HOST, PW_PORT, $errno, $errstr, 1.0);
        if (!$socket) return false;
        fclose($socket);
        return true;
    }

    private function waitPortFree(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while ($this->portOpen() && microtime(true) < $deadline) usleep(250000);
    }

    private function prepareRuntime(): void
    {
        $orphans = [];
        foreach (Jobs::all() as $job) {
            $status = $job['status'];
            if (!in_array($status['state'] ?? '', ['running', 'stopping'], true) || empty($status['pid'])) continue;
            if ($job['kind'] === 'mail') continue;   // a visszajelzés levél ablaka lehet, hogy még nyitva van
            // csak akkor lőjük le, ha az a PID még mindig ugyanaz a program (a PID-ek újrahasznosulnak)
            $expected = strtolower(basename((string)($job['cmd'][0] ?? '')));
            if ($expected !== '' && Platform::processName((int)$status['pid']) === $expected) {
                $orphans[] = (int)$status['pid'];
            }
        }
        if ($orphans) {
            $this->say('Az előző futásból maradt folyamatok leállítása...');
            Platform::killTree($orphans);
        }
        foreach (glob(pw_path(PW_RUN, 'jobs', '*'), GLOB_ONLYDIR) ?: [] as $dir) {
            Jobs::removeDir(basename($dir));
        }
        foreach (glob(pw_path(PW_RUN, 'queue', '*')) ?: [] as $file) @unlink($file);
        foreach (glob(pw_path(PW_RUN, 'terminal-*.command')) ?: [] as $file) @unlink($file);
        foreach (glob(pw_path(PW_RUN, 'mail-*.json')) ?: [] as $file) @unlink($file);
        foreach (['heartbeat', 'bye', 'supervisor.alive'] as $name) @unlink(Runtime::file($name));
        if (@filesize(Runtime::file('pocketweb.log')) > 1048576) @unlink(Runtime::file('pocketweb.log'));

        $this->token = bin2hex(random_bytes(16));
        $this->writeInfo();
        Runtime::touch('supervisor.alive');
    }

    private function startDashboard(): bool
    {
        $env = Platform::childEnv(['POCKETWEB_SUPERVISOR' => (string)getmypid()]);
        $cmd = [PHP_BINARY, '-q', '-S', PW_HOST . ':' . PW_PORT, '-t', PW_SYS, pw_path(PW_SYS, 'router.php')];
        $this->dashboard = Platform::spawn($cmd, PW_SYS, $env, Runtime::file('dashboard.log'));
        if (!$this->dashboard) {
            $this->say('Hiba: nem sikerült elindítani a vezérlőpult szerverét (' . PHP_BINARY . ').');
            return false;
        }
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            if (!proc_get_status($this->dashboard)['running']) {
                $this->say('Hiba: a vezérlőpult szervere leállt: ' . trim((string)@file_get_contents(Runtime::file('dashboard.log'))));
                return false;
            }
            if ($this->portOpen()) return true;
            usleep(100000);
        }
        $this->say('Hiba: a vezérlőpult szervere nem válaszol.');
        return false;
    }

    /** Vezérlőpult megnyitása: saját alkalmazás ablakban, ha van Chromium alapú böngésző, egyébként az alapértelmezettben. */
    private function openDashboard(bool $track): void
    {
        $browser = Platform::findBrowser();
        if ($browser) {
            $cmd = Platform::appWindowCommand($browser, $this->url);
            if (!$track) {
                // már futó példány: csak egy új ablak kell, nem figyeljük
                if (Platform::launch(['argv' => $cmd])) return;
            }
            $descriptors = [0 => ['null'], 1 => ['file', Runtime::file('browser.log'), 'w'], 2 => ['redirect', 1]];
            $pipes = [];
            $proc = @proc_open($cmd, $descriptors, $pipes, PW_SYS, null, ['bypass_shell' => true]);
            if (is_resource($proc)) {
                if ($track) {
                    $this->browserProc = $proc;
                    $this->browser = $browser;
                    $this->mode = 'app';
                    $this->launchedAt = microtime(true);
                }
                $this->say('Böngésző: ' . $browser['name'] . ' (külön ablak)');
                return;
            }
        }
        Platform::launch(Platform::openSpec($this->url));
        if ($track) $this->mode = 'browser';
        $this->say('A vezérlőpult az alapértelmezett böngészőben nyílt meg.');
    }

    private function writeInfo(array $extra = []): void
    {
        Jobs::writeJson(Runtime::file('supervisor.json'), array_merge([
            'pid' => getmypid(),
            'version' => PW_VERSION,
            'url' => $this->url,
            'mode' => $this->mode,
            'browser' => $this->browser['name'] ?? null,
            'token' => $this->token,
            'shuttingDown' => false,
        ], $extra));
    }

    private function installSignalHandlers(): void
    {
        // Ha a PocketWeb látható ablakban fut (PowerShell nélkül), a Ctrl+C is szabályosan leállít mindent
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            @sapi_windows_set_ctrl_handler(function () { $this->requestStop('Ctrl+C.'); });
        }
    }

    // ------------------------------------------------------------------
    // Főciklus
    // ------------------------------------------------------------------

    private function loop(): void
    {
        $lastAlive = 0.0;
        $lastLifecycle = 0.0;
        while (!$this->stop) {
            foreach (Jobs::receive() as $message) {
                $this->handle($message);
            }
            $this->pollJobs();
            $this->checkFocus();

            $now = microtime(true);
            if ($now - $lastAlive >= 2) {
                Runtime::touch('supervisor.alive');
                $lastAlive = $now;
            }
            if ($now - $lastLifecycle >= 1) {
                $this->checkDashboard();
                $this->checkLifecycle();
                $lastLifecycle = $now;
            }
            if (!$this->stop) usleep(150000);
        }
    }

    private function handle(array $message): void
    {
        $id = (string)($message['id'] ?? '');
        switch ($message['op']) {
            case 'start':
                if (Jobs::validId($id)) $this->startJob($id);
                break;
            case 'stop':
            case 'remove':
                if (Jobs::validId($id)) $this->stopJob($id, $message['op'] === 'remove');
                break;
            case 'launch':
                $spec = is_array($message['spec'] ?? null) ? $message['spec'] : [];
                $watch = $this->watchFocus($spec['focus'] ?? null);
                if (!Platform::launch($spec)) {
                    $what = $spec['cmdline'] ?? implode(' ', (array)($spec['argv'] ?? []));
                    $this->say('Nem sikerült elindítani: ' . $what);
                } elseif ($watch) {
                    $this->focusWatches[] = $watch;
                }
                break;
            case 'focus':   // pl. a visszajelzés levelének ablaka (a feladatot az api.php indítja)
                if ($watch = $this->watchFocus($message['focus'] ?? null)) $this->focusWatches[] = $watch;
                break;
            case 'quit':
                $this->requestStop('Kilépés a vezérlőpultról.');
                break;
        }
    }

    private function startJob(string $id): void
    {
        $spec = Jobs::spec($id);
        if (!$spec || isset($this->jobs[$id])) return;

        $cmd = $spec['cmd'] ?? null;
        $cwd = (string)($spec['cwd'] ?? '');
        if (!is_array($cmd) || !$cmd || !is_dir($cwd)) {
            Jobs::setState($id, ['state' => 'failed', 'error' => 'Hibás feladat (nincs parancs vagy nem létező mappa).', 'endedAt' => microtime(true)]);
            return;
        }
        $env = Platform::childEnv(array_merge(self::JOB_ENV, (array)($spec['env'] ?? [])));
        $proc = Platform::spawn(array_values(array_map('strval', $cmd)), $cwd, $env, Jobs::logFile($id));
        if (!$proc) {
            Jobs::setState($id, ['state' => 'failed', 'error' => 'Nem sikerült elindítani: ' . $cmd[0], 'endedAt' => microtime(true)]);
            $this->say('✖ ' . $spec['title'] . ': nem sikerült elindítani.');
            return;
        }
        $pid = (int)proc_get_status($proc)['pid'];
        $now = microtime(true);
        $this->jobs[$id] = ['proc' => $proc, 'pid' => $pid, 'spec' => $spec, 'startedAt' => $now, 'stopRequested' => false, 'remove' => false];
        Jobs::setState($id, ['state' => 'running', 'pid' => $pid, 'startedAt' => $now]);
        $this->say('▶ ' . $spec['title'] . ' (PID ' . $pid . ')');
    }

    private function stopJob(string $id, bool $remove): void
    {
        if (!isset($this->jobs[$id])) {
            if ($remove && !in_array(Jobs::state($id)['state'] ?? '', Jobs::ACTIVE, true)) {
                Jobs::removeDir($id);
            }
            return;
        }
        $job = &$this->jobs[$id];
        $job['remove'] = $job['remove'] || $remove;
        if ($job['stopRequested']) return;
        $job['stopRequested'] = true;
        Jobs::setState($id, ['state' => 'stopping', 'pid' => $job['pid'], 'startedAt' => $job['startedAt']]);
        $this->say('■ ' . $job['spec']['title'] . ' leállítása...');
        Platform::killTree([$job['pid']]);
    }

    /** Befejeződött feladatok begyűjtése. */
    private function pollJobs(): void
    {
        foreach ($this->jobs as $id => $job) {
            $status = proc_get_status($job['proc']);
            if ($status['running']) continue;

            $code = (int)$status['exitcode'];
            if (!empty($status['signaled'])) $code = 128 + (int)$status['termsig'];
            proc_close($job['proc']);
            unset($this->jobs[$id]);

            if ($job['remove']) {
                Jobs::removeDir($id);
                continue;
            }
            Jobs::setState($id, [
                'state' => $job['stopRequested'] ? 'stopped' : 'exited',
                'exitCode' => $code,
                'pid' => $job['pid'],
                'startedAt' => $job['startedAt'],
                'endedAt' => microtime(true),
            ]);
            $this->say(($code === 0 || $job['stopRequested'] ? '✔ ' : '✖ ') . $job['spec']['title'] . ' – befejeződött (kód: ' . $code . ')');
        }
    }

    // ------------------------------------------------------------------
    // Az elindított programok ablakának előtérbe hozása (WinFocus.php)
    // ------------------------------------------------------------------

    /** Figyelés előkészítése: az indítás előtti ablakok. null, ha nincs teendő vagy nem lehetséges. */
    private function watchFocus($hint): ?array
    {
        if (!is_array($hint) || !WinFocus::available()) return null;
        WinFocus::forgetProcesses();
        return ['hint' => $hint, 'before' => array_fill_keys(WinFocus::handles(), true), 'start' => microtime(true)];
    }

    private function checkFocus(): void
    {
        if (!$this->focusWatches) return;
        foreach ($this->focusWatches as $i => $watch) {
            // az alapértelmezett böngésző / levelezőprogram neve (az indítás után kérdezzük le, hogy ne késleltesse)
            if (!empty($watch['hint']['handler']) && !isset($watch['hint']['exe'])) {
                $exe = Platform::defaultHandlerExe((string)$watch['hint']['handler']);
                if ($exe === null) {
                    unset($this->focusWatches[$i]);
                    continue;
                }
                $this->focusWatches[$i]['hint']['exe'] = $exe;
            }
        }
        if (!$this->focusWatches) return;
        $windows = WinFocus::windows();
        $exclude = $this->browserProc ? [(int)proc_get_status($this->browserProc)['pid']] : [];
        foreach ($this->focusWatches as $i => $watch) {
            $age = microtime(true) - $watch['start'];
            $id = WinFocus::pick($windows, $watch['before'], $watch['hint'], $age, $exclude, [self::DASHBOARD_TITLE]);
            if ($id !== null) {
                if (!WinFocus::activate($id)) $this->say('Nem sikerült előtérbe hozni az ablakot: ' . $windows[$id]['title']);
                unset($this->focusWatches[$i]);
            } elseif ($age > (float)($watch['hint']['timeout'] ?? 10)) {
                unset($this->focusWatches[$i]);
            }
        }
    }

    /** Ha a vezérlőpult szervere váratlanul leállna, újraindítjuk. */
    private function checkDashboard(): void
    {
        if (!$this->dashboard || proc_get_status($this->dashboard)['running']) return;
        $this->say('A vezérlőpult szervere váratlanul leállt, újraindítom...');
        if ($this->dashboardRestarts++ >= 3 || !$this->startDashboard()) {
            $this->requestStop('A vezérlőpult szervere nem működik.');
        }
    }

    /** Be lett-e zárva a vezérlőpult? */
    private function checkLifecycle(): void
    {
        $now = microtime(true);

        // A lap bezárásakor érkező "bye" jelzés után nem jött új életjel → bezárták (nem csak újratöltötték)
        $bye = Runtime::stamp('bye');
        $heartbeat = Runtime::stamp('heartbeat');
        if ($bye !== null && ($heartbeat === null || $heartbeat <= $bye) && $now - $bye > 15) {
            $this->requestStop('A vezérlőpult be lett zárva.');
            return;
        }

        if ($this->mode !== 'app') return;

        $alive = ($this->browserProc && proc_get_status($this->browserProc)['running'])
            || Platform::browserRunning(Platform::browserProfile($this->browser));
        if ($alive) {
            $this->appSeen = true;
            $this->appGoneSince = null;
            return;
        }
        if (!$this->appSeen) {
            if ($now - $this->launchedAt > 30) {
                // a saját ablak nem jelent meg (pl. sandboxolt böngésző) → alapértelmezett böngésző
                $this->say('A böngészőablak nem nyílt meg, megnyitom az alapértelmezett böngészőben.');
                Platform::launch(Platform::openSpec($this->url));
                $this->mode = 'browser';
                $this->writeInfo();
            }
            return;
        }
        $this->appGoneSince = $this->appGoneSince ?? $now;
        if ($now - $this->appGoneSince >= 2) {
            $this->requestStop('A vezérlőpult ablaka bezárult.');
        }
    }

    private function requestStop(string $reason): void
    {
        if (!$this->stop) $this->stopReason = $reason;
        $this->stop = true;
    }

    // ------------------------------------------------------------------
    // Leállítás
    // ------------------------------------------------------------------

    private function shutdown(): void
    {
        $this->say($this->stopReason . ' Minden folyamat leállítása...');
        $this->writeInfo(['shuttingDown' => true]);

        $pids = [];
        foreach ($this->jobs as $id => $job) {
            // a visszajelzés levelét nem állítjuk le: a levelezőprogram ablaka nyitva marad, a feladat magától kilép
            if ($job['spec']['kind'] === 'mail') continue;
            $pids[] = $job['pid'];
            Jobs::setState($id, ['state' => 'stopped', 'pid' => $job['pid'], 'startedAt' => $job['startedAt'], 'endedAt' => microtime(true)]);
        }
        if ($this->dashboard) {
            $pids[] = (int)proc_get_status($this->dashboard)['pid'];
        }
        Platform::killTree($pids);
        $this->cleanup();
        $this->say('A PocketWeb leállt.');
    }

    private function cleanup(): void
    {
        foreach (glob(pw_path(PW_RUN, 'queue', '*')) ?: [] as $file) @unlink($file);
        foreach (['supervisor.alive', 'heartbeat', 'bye'] as $name) @unlink(Runtime::file($name));
        $this->writeInfo(['shuttingDown' => true, 'stopped' => true]);
    }

    private function say(string $message): void
    {
        $line = '[' . date('H:i:s') . '] ' . $message;
        if (defined('STDOUT')) @fwrite(STDOUT, $line . PHP_EOL);
        @file_put_contents(Runtime::file('pocketweb.log'), $line . PHP_EOL, FILE_APPEND);
    }
}

exit((new Supervisor())->run($argv));
