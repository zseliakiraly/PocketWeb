<?php
/*
 * PocketWeb vezérlőpult API.
 *
 * Hosszan futó programot (szervert, telepítőt, szerkesztőt, parancssort) ez a fájl nem indít közvetlenül:
 * a feladatot a felügyelőnek (pocketweb.php) küldi el, a kimenetet pedig a Terminál panel a "jobs"
 * művelettel kérdezi le.
 */
require __DIR__ . '/lib/bootstrap.php';

ini_set('display_errors', '0');   // a PHP figyelmeztetések ne rontsák el a JSON választ
set_exception_handler(function (Throwable $e) {
    fail('Váratlan hiba: ' . $e->getMessage(), 500);
});

$action = (string)($_GET['action'] ?? '');

// ----------------------------------------------------------------------
// Segédfüggvények
// ----------------------------------------------------------------------

function respond($data, int $status = 200): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen((string)$json));   // a php -S e nélkül a kapcsolat bontásával jelezné a válasz végét
    echo $json;
    exit;
}

function fail(string $message, int $status = 400): void
{
    respond(['success' => false, 'error' => $message, 'message' => $message], $status);
}

/** Kérésparaméter: JSON törzsből, POST-ból vagy az URL-ből. */
function input(string $key, $default = null)
{
    static $body = null;
    if ($body === null) {
        $body = [];
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
            $decoded = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($decoded)) $body = $decoded;
        }
    }
    return $body[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
}

/** A kérésben megadott (létező) projekt: [név, mappa]. */
function require_site(): array
{
    $name = (string)input('site', '');
    $dir = Sites::dir($name);
    if ($dir === null) fail('A projekt mappa nem található.', 404);
    return [$name, $dir];
}

function require_supervisor(): void
{
    if (!Runtime::supervisorAlive()) {
        fail('A PocketWeb háttérfolyamata nem fut. Zárd be ezt az ablakot, és indítsd újra a PocketWebet!', 503);
    }
}

/** Asztali program indítása a felügyelőn keresztül. */
function launch($spec): void
{
    if (is_string($spec)) fail($spec);
    require_supervisor();
    Jobs::send(['op' => 'launch', 'spec' => $spec]);
    respond(['success' => true]);
}

/** A Terminál panel szélessége – a Laravel ehhez igazítja a sorokat. */
function terminal_env(): array
{
    $cols = max(40, min(400, (int)input('cols', 120)));
    return ['COLUMNS' => (string)$cols, 'LINES' => '30'];
}

function site_jobs(string $site, ?string $kind = null): array
{
    return array_values(array_filter(Jobs::all(), function ($job) use ($site, $kind) {
        return ($job['site'] ?? null) === $site && Jobs::isActive($job) && ($kind === null || $job['kind'] === $kind);
    }));
}

/** A projekt futó folyamatainak (szerver, telepítő, képernyőkép) leállítása – Windowson a futó program zárolja a fájljait. */
function stop_site_processes(string $name, string $dir): void
{
    $active = array_merge(site_jobs($name, 'server'), site_jobs($name, 'install'), site_jobs($name, 'thumbnail'));
    if ($active) {
        require_supervisor();
        foreach ($active as $job) Jobs::send(['op' => 'stop', 'id' => $job['id']]);
        $deadline = microtime(true) + 10;
        while (site_jobs($name) && microtime(true) < $deadline) usleep(200000);
    }
    $portFile = pw_path($dir, '.ms-port');
    if (is_file($portFile)) {
        $port = (int)file_get_contents($portFile);
        if (Platform::isListening($port, Platform::listeningPorts())) Platform::killTree(Platform::pidsOnPort($port));
    }
}

// ----------------------------------------------------------------------
// Műveletek, amelyek nem a vezérlőpult JavaScriptjéből jönnek
// ----------------------------------------------------------------------

// Az indító ellenőrzi vele, hogy fut-e már PocketWeb ezen a porton
if ($action === 'ping') {
    $info = Runtime::supervisor();
    respond([
        'app' => 'PocketWeb',
        'version' => PW_VERSION,
        'supervisor' => Runtime::supervisorAlive(),
        'shuttingDown' => !empty($info['shuttingDown']) && empty($info['stopped']),
    ]);
}

// A vezérlőpult lapjának bezárásakor érkezik (navigator.sendBeacon)
if ($action === 'bye') {
    $info = Runtime::supervisor();
    $token = trim((string)file_get_contents('php://input'));
    if ($info && !empty($info['token']) && hash_equals((string)$info['token'], $token)) {
        Runtime::touch('bye');
    }
    http_response_code(204);
    exit;
}

// Kártya háttérkép (<img> tölti be). A cím a kép változatát (módosítási idejét) is tartalmazza, így a böngésző
// gyorsítótárazhatja: a kártyák újrarajzolásakor nem kéri le újra.
if ($action === 'thumbnail') {
    $name = (string)($_GET['site'] ?? '');
    $dir = Sites::dir($name);
    header(isset($_GET['t']) ? 'Cache-Control: private, max-age=31536000, immutable' : 'Cache-Control: no-store');
    if ($dir) {
        foreach (['png' => 'png', 'jpg' => 'jpeg', 'jpeg' => 'jpeg'] as $ext => $mime) {
            $img = pw_path($dir, 'thumbnail.' . $ext);
            if (is_file($img)) {
                header('Content-Type: image/' . $mime);
                header('Content-Length: ' . filesize($img));
                readfile($img);
                exit;
            }
        }
    }
    $colors = ['laravel' => '#e68c3a', 'wordpress' => '#2563eb', 'html_tailwind' => '#06b6d4', 'html_bootstrap' => '#9333ea'];
    $color = $colors[$dir ? Sites::type($dir) : ''] ?? '#545454';
    $first = function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));
    $letter = htmlspecialchars($first !== '' ? $first : '?', ENT_XML1);
    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg width="400" height="200" xmlns="http://www.w3.org/2000/svg">'
        . '<defs><linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">'
        . '<stop offset="0%" style="stop-color:#282828;stop-opacity:1" /><stop offset="100%" style="stop-color:#141414;stop-opacity:1" />'
        . '</linearGradient></defs><rect width="100%" height="100%" fill="url(#grad)"/>'
        . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="72" font-weight="bold" fill="' . $color . '">' . $letter . '</text>'
        . '</svg>';
    header('Content-Type: image/svg+xml');
    header('Content-Length: ' . strlen($svg));
    echo $svg;
    exit;
}

// ----------------------------------------------------------------------
// Innentől csak a vezérlőpult hívhatja: az egyedi fejlécet egy idegen weboldal nem tudja beállítani
// (CORS előellenőrzés nélkül), így más oldal nem törölhet vagy indíthat semmit a gépen.
// ----------------------------------------------------------------------

if (($_SERVER['HTTP_X_POCKETWEB'] ?? '') !== '1') {
    fail('Érvénytelen kérés.', 403);
}
Runtime::touch('heartbeat');   // a felügyelő ebből tudja, hogy a vezérlőpult még nyitva van

if ($action === 'bootstrap' || $action === 'get_versions') {
    $laravelExtensions = ['openssl', 'mbstring', 'pdo_sqlite', 'fileinfo', 'tokenizer', 'dom', 'xml', 'ctype', 'filter', 'curl', 'zip'];
    $info = Runtime::supervisor();
    respond([
        'version' => PW_VERSION,
        'php' => PHP_VERSION,
        'node' => Feedback::nodeVersion(),
        'missingExtensions' => array_values(array_filter($laravelExtensions, function ($e) { return !extension_loaded($e); })),
        'projectsDir' => PW_PROJECTS,
        'supervisor' => Runtime::supervisorAlive(),
        'mode' => $info['mode'] ?? null,
        'token' => $info['token'] ?? '',
    ]);
}

// A kártyák adatai – csak induláskor, a Frissítés gombra és egy új projekt elkészülésekor kéri a vezérlőpult
// (a netstat lassú lehet, és a beépített PHP szerver egyszerre csak egy kérést szolgál ki)
if ($action === 'get_sites') {
    $ports = false;   // a netstat csak akkor fut, ha van projekt, amelynek a szerverét nem a PocketWeb indította
    $jobs = array_filter(Jobs::all(), [Jobs::class, 'isActive']);
    $sites = [];

    foreach (glob(pw_path(PW_PROJECTS, '*'), GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if ($name === '' || $name[0] === '.' || Sites::dir($name) === null) continue;

        $install = $server = null;
        foreach ($jobs as $job) {
            if (($job['site'] ?? null) !== $name) continue;
            if ($job['kind'] === 'install') $install = $job;
            if ($job['kind'] === 'server') $server = $job;
        }

        $typeId = $install ? (string)($install['installType'] ?? '') : Sites::type($dir);
        // Üres vagy ismeretlen tartalmú mappa (pl. megszakadt telepítés) nem kap kártyát
        if ($typeId === '') continue;

        $needsServer = Sites::needsServer($typeId);
        // telepítés közben nem írunk a mappába (a Composer üres mappát vár)
        $port = ($needsServer && !$install) ? Sites::port($dir) : '';
        $url = null;
        if ($server) {
            $url = Jobs::serverUrl($server['id']);
            $listening = $url !== null;
            $serverState = $server['status']['state'] === 'stopping' ? 'stopping' : ($listening ? 'running' : 'starting');
        } else {
            if ($needsServer && !$install && $ports === false) $ports = Platform::listeningPorts();
            $listening = $needsServer && !$install && Platform::isListening((int)$port, is_array($ports) ? $ports : []);
            $serverState = $listening ? 'external' : 'stopped';   // external: pl. parancssorból indították
        }
        $thumb = 0;
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            if ($thumb === 0 && is_file($image = pw_path($dir, 'thumbnail.' . $ext))) $thumb = (int)filemtime($image);
        }

        $sites[] = [
            'id' => md5($name),
            'name' => $name,
            'typeId' => $typeId,
            'type' => Sites::TYPES[$typeId] ?? 'Projekt',
            'port' => $port,
            'needsServer' => $needsServer,
            'isRunning' => $listening,
            'serverState' => $serverState,
            'serverJob' => $server['id'] ?? null,
            'url' => $url,
            'installing' => $install !== null,
            'installJob' => $install['id'] ?? null,
            'thumb' => $thumb,
        ];
    }
    respond($sites);
}

// Terminál panel: a feladatok állapota és a kimenetük új része (csak azoké, amelyek kimenetét a vezérlőpult kéri)
if ($action === 'jobs') {
    $offsets = input('offsets', []);
    $offsets = is_array($offsets) ? $offsets : [];
    $budget = 512 * 1024;
    $list = [];

    foreach (Jobs::all() as $job) {
        $id = $job['id'];
        $status = $job['status'];
        $item = [
            'id' => $id,
            'kind' => $job['kind'],
            'title' => $job['title'],
            'command' => $job['display'] ?? '',
            'site' => $job['site'],
            'port' => $job['port'],
            'hidden' => !empty($job['hidden']),
            'created' => $job['created'],
            'state' => $status['state'] ?? 'queued',
            'exitCode' => $status['exitCode'] ?? null,
            'error' => $status['error'] ?? null,
            'startedAt' => $status['startedAt'] ?? null,
            'endedAt' => $status['endedAt'] ?? null,
        ];
        if ($job['kind'] === 'server' && $item['state'] === 'running') {
            $item['url'] = Jobs::serverUrl($id);
            $item['listening'] = $item['url'] !== null;
        }
        if (!$item['hidden'] && array_key_exists($id, $offsets)) {
            $chunk = Jobs::readLog($id, (int)$offsets[$id], max(4096, min(131072, $budget)));
            $budget -= strlen($chunk['data']);
            $item['output'] = base64_encode($chunk['data']);
            $item['offset'] = $chunk['offset'];
        }
        // a rejtett feladatok, a hibával leállt szerverek / telepítők és az exportok / importok utolsó sora (az értesítéshez)
        $failed = in_array($job['kind'], ['server', 'install'], true) && ($status['exitCode'] ?? 0) !== 0;
        if (!Jobs::isActive($job) && ($item['hidden'] || $failed || in_array($job['kind'], ['export', 'import'], true))) {
            $item['message'] = Jobs::lastLine($id);
        }
        $list[] = $item;
    }
    respond(['jobs' => $list, 'supervisor' => Runtime::supervisorAlive()]);
}

if ($action === 'job_stop' || $action === 'job_remove') {
    $id = input('id');
    if (!Jobs::validId($id) || !Jobs::spec($id)) fail('Ismeretlen feladat.', 404);
    require_supervisor();
    Jobs::send(['op' => $action === 'job_stop' ? 'stop' : 'remove', 'id' => $id]);
    respond(['success' => true]);
}

if ($action === 'quit') {
    require_supervisor();
    Jobs::send(['op' => 'quit']);
    respond(['success' => true]);
}

if ($action === 'get_db_path') {
    [$name, $dir] = require_site();
    $dbFile = Sites::dbFile($dir, Sites::type($dir));
    if (!$dbFile || !is_file($dbFile)) {
        fail('Az adatbázis fájl még nem létezik (lehet, hogy előbb el kell indítani vagy telepíteni az oldalt).', 404);
    }
    // perjelekkel, hogy biztonságosan mehessen URL paraméterben
    respond(['success' => true, 'path' => str_replace('\\', '/', (string)realpath($dbFile))]);
}

if ($action === 'open_folder') {
    [$name, $dir] = require_site();
    launch(Platform::openFolderSpec($dir));
}

if ($action === 'open_terminal') {
    [$name, $dir] = require_site();
    launch(Platform::terminalSpec($dir, 'PocketWeb - ' . $name));
}

// Megnyitás böngészőben – a Beállításokban választott böngészőben (alapból a rendszer alapértelmezettjében)
if ($action === 'open_browser') {
    [$name, $dir] = require_site();
    $browser = (string)input('browser', 'default');
    if (input('mode') === 'local') {
        // a fájlt magát nyitjuk meg (mint dupla kattintásra): így az ékezetes mappanév sem gond
        $index = pw_path($dir, 'index.html');
        launch(is_file($index) ? Platform::browserSpec($browser, $index) : Platform::openFolderSpec($dir));
    }
    $type = Sites::type($dir);
    if (!Sites::needsServer($type)) fail('Ennek a projektnek nincs szervere.');
    $url = null;
    foreach (site_jobs($name, 'server') as $job) $url = Jobs::serverUrl($job['id']);   // a szerver valódi címe
    launch(Platform::browserSpec($browser, $url ?? 'http://' . PW_HOST . ':' . Sites::port($dir) . '/'));
}

// A Terminál panelen kattintott linkek és a visszajelzés levele (mailto:, Gmail, Outlook)
if ($action === 'open_url') {
    $url = (string)input('url', '');
    if (strlen($url) > 8192 || !preg_match('#^(https?://|mailto:)[^\s"<>\x00-\x1f]+$#i', $url)) fail('Érvénytelen cím.');
    // a projektek helyi címei (a Terminál panel linkjei) a választott böngészőben, minden más az alapértelmezettben
    $local = preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?(/|$)#i', $url) === 1;
    launch($local ? Platform::browserSpec((string)input('browser', 'default'), $url) : Platform::openSpec($url));
}

// Névjegy ablak: verziók
if ($action === 'about') {
    $info = Runtime::supervisor();
    respond([
        'version' => PW_VERSION,
        'windows' => Platform::windowsVersion(),
        'php' => PHP_VERSION,
        'node' => Feedback::nodeVersion(),
        'composer' => Feedback::composerVersion(),
        'adminer' => Feedback::adminerVersion(),
        'browser' => $info['browser'] ?? null,
        'email' => PW_FEEDBACK_EMAIL,
        'repo' => PW_REPO_URL,
    ]);
}

// Visszajelzés: a levél szövege és linkjei. Kérésre napló: ZIP a visszajelzes\ mappába (ezt a levelezőprogram
// csatolja, ha tudja) és kivonat a levél szövegébe (ha a csatolás nem megy).
if ($action === 'feedback_prepare') {
    $message = trim((string)input('message', ''));
    $contact = trim((string)input('contact', ''));
    if ($message === '') fail('Írd le röviden, mi a visszajelzésed!');
    if (strlen($message) > 20000) fail('A szöveg túl hosszú (legfeljebb kb. 20 000 karakter).');
    if (strlen($contact) > 200) fail('Az elérhetőség túl hosszú.');
    $file = null;
    $digest = '';
    if (input('attachLog')) {
        try {
            $file = Feedback::createLogArchive();
        } catch (Throwable $e) {
            fail('Nem sikerült elkészíteni a napló fájlt: ' . $e->getMessage(), 500);
        }
        $digest = Feedback::logDigest();
    }
    $mail = Feedback::compose($message, $contact, $file, $digest);

    // A napló csatolása a levelezőprogrammal (MAPI), ha a gépen van erre alkalmas
    $mapi = null;
    if ($file !== null && ($client = Mapi::client()) !== null) {
        $token = Mapi::saveMessage(['to' => PW_FEEDBACK_EMAIL, 'subject' => $mail['subject'], 'body' => $mail['mapiBody'],
            'attachment' => $file, 'client' => $client]);
        $mapi = ['client' => $client, 'token' => $token];
    }
    respond([
        'success' => true,
        'email' => PW_FEEDBACK_EMAIL,
        'subject' => $mail['subject'],
        'body' => $mail['body'],
        'links' => $mail['links'],
        'file' => $file,
        'fileName' => $file ? basename($file) : null,
        'mapi' => $mapi,
    ]);
}

// A levél megnyitása a levelezőprogramban, a napló csatolva (rejtett feladat: tasks\send-mail.php)
if ($action === 'feedback_mapi') {
    $file = Mapi::messageFile((string)input('token', ''));
    if ($file === null) fail('A levél adatai már nem érhetők el, kattints újra a gombra!', 404);
    require_supervisor();
    $cmd = [Platform::php()];
    if (!extension_loaded('ffi')) array_push($cmd, '-d', 'extension=ffi');   // a mellékelt php.ini nem tölti be
    array_push($cmd, pw_path(PW_SYS, 'tasks', 'send-mail.php'), $file);
    $id = Jobs::create(['kind' => 'mail', 'hidden' => true, 'title' => 'Visszajelzés levél', 'cmd' => $cmd, 'cwd' => PW_RUN]);
    Jobs::send(['op' => 'focus', 'focus' => ['handler' => 'mailto', 'timeout' => 30]]);   // a levél ablaka előre
    respond(['success' => true, 'job' => $id]);
}

// A csatolandó napló megmutatása az Intézőben (kijelölve, hogy a levélbe húzható legyen)
if ($action === 'reveal_file') {
    $file = (string)input('file', '');
    if (!Feedback::isArchive($file)) fail('A napló fájl nem található.', 404);
    launch(Platform::revealFileSpec((string)realpath($file)));
}

// A projekt megnyitása a szerkesztőben; a Terminál panel hibahelyére kattintva a fájl a hibás sornál nyílik meg
if ($action === 'open_editor') {
    [$name, $dir] = require_site();
    $file = null;
    $line = null;
    if ((string)input('file', '') !== '') {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)input('file'));
        if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $path)) $path = pw_path($dir, $path);   // a projekthez viszonyított út
        $real = realpath($path);
        $base = realpath($dir);
        if ($real === false || !is_file($real) || $base === false
            || strncasecmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            fail('A fájl nem található a projektben: ' . (string)input('file'), 404);
        }
        $file = $real;
        $line = max(1, min(1000000, (int)input('line', 1)));
    }
    launch(Platform::editorSpec((string)input('editor', 'vscode'), $dir, $file, $line));
}

if ($action === 'toggle_serve') {
    [$name, $dir] = require_site();
    $type = Sites::type($dir);
    if (!Sites::needsServer($type)) fail('Ehhez a projekthez nem kell szerver.');
    if (site_jobs($name, 'install')) fail('A projekt még települ, várd meg a végét!');
    $port = Sites::port($dir);

    // A PocketWeb által indított szerver leállítása
    foreach (site_jobs($name, 'server') as $job) {
        require_supervisor();
        Jobs::send(['op' => 'stop', 'id' => $job['id']]);
        respond(['success' => true, 'state' => 'stopping', 'job' => $job['id']]);
    }

    // Máshonnan (pl. a parancssorból) indított szerver a projekt portján
    if (Platform::isListening($port, Platform::listeningPorts())) {
        $pids = Platform::pidsOnPort($port);
        if (!$pids) fail('A ' . $port . '-es porton futó programot nem sikerült azonosítani.');
        Platform::killTree($pids);
        respond(['success' => true, 'state' => 'stopped']);
    }

    require_supervisor();
    // a projekt korábbi (már leállt) szerverének fülét lecseréljük az újra
    foreach (Jobs::all() as $job) {
        if ($job['kind'] === 'server' && ($job['site'] ?? null) === $name && !Jobs::isActive($job)) {
            Jobs::send(['op' => 'remove', 'id' => $job['id']]);
        }
    }
    if ($type === 'laravel') {
        // a tasks\serve.php indítja a "php artisan serve"-t, és kiírja, hol van a hiba (szintaxis, Laravel napló)
        $cmd = [Platform::php(), pw_path(PW_SYS, 'tasks', 'serve.php'), $name, (string)$port];
        $display = 'php artisan serve --port=' . $port;
    } else {
        $cmd = [Platform::php(), '-S', PW_HOST . ':' . $port];
        $display = 'php -S ' . PW_HOST . ':' . $port;
    }
    $id = Jobs::create([
        'kind' => 'server', 'site' => $name, 'port' => $port,
        'title' => $name . ' · szerver :' . $port, 'display' => $display,
        'cmd' => $cmd, 'cwd' => $dir, 'env' => terminal_env(),
    ]);
    respond(['success' => true, 'state' => 'starting', 'job' => $id, 'port' => $port]);
}

if ($action === 'create_project') {
    $name = (string)input('site', '');
    $type = (string)input('type', 'html_css');
    if (!Sites::validNewName($name)) {
        fail('Érvénytelen projektnév: csak kisbetű, szám, kötőjel és aláhúzás lehet benne (pl. elso-webshop).');
    }
    if (!isset(Sites::TYPES[$type])) fail('Ismeretlen projekttípus.');
    $path = pw_path(PW_PROJECTS, $name);
    if (file_exists($path)) fail('Már létezik ilyen nevű projekt: ' . $name);

    // Laravel és WordPress: a telepítő a háttérben fut, a kimenete a Terminál panelen látszik
    if ($type === 'laravel' || $type === 'wordpress') {
        require_supervisor();
        if (!@mkdir($path, 0777, true)) fail('Nem sikerült létrehozni a mappát: ' . $path);
        $laravel = $type === 'laravel';
        $id = Jobs::create([
            'kind' => 'install', 'site' => $name, 'installType' => $type,
            'title' => $name . ' · ' . ($laravel ? 'Laravel' : 'WordPress') . ' telepítés',
            'display' => $laravel ? 'composer create-project laravel/laravel ' . $name : 'WordPress + SQLite telepítő',
            'cmd' => [Platform::php(), pw_path(PW_SYS, 'tasks', $laravel ? 'new-laravel.php' : 'new-wordpress.php'), $name],
            'cwd' => $laravel ? PW_PROJECTS : $path,
            'env' => terminal_env(),
        ]);
        respond(['success' => true, 'job' => $id]);
    }

    // Egyszerű HTML projektek: azonnal elkészülnek
    $title = htmlspecialchars($name);
    $head = "<!DOCTYPE html>\n<html lang=\"hu\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
    $files = [];
    if ($type === 'html_css') {
        $files['index.html'] = $head . "    <title>$title</title>\n    <link rel=\"stylesheet\" href=\"css/style.css\">\n</head>\n<body>\n    <h1>Helló Világ! ($title)</h1>\n</body>\n</html>";
        $files['css/style.css'] = "body {\n    font-family: sans-serif;\n    background-color: #f4f4f9;\n    color: #333;\n    padding: 2rem;\n}";
    }
    if ($type === 'html_tailwind') {
        $files['index.html'] = $head . "    <title>$title - Tailwind</title>\n    <script src=\"https://cdn.tailwindcss.com\"></script>\n</head>\n<body class=\"bg-gray-100 text-gray-800 flex items-center justify-center h-screen\">\n    <div class=\"bg-white p-8 rounded-xl shadow-lg\">\n        <h1 class=\"text-3xl font-bold text-blue-600 mb-4\">Tailwind Működik!</h1>\n        <p class=\"text-gray-600\">Ez a projekt a Tailwind CSS CDN verzióját használja.</p>\n    </div>\n</body>\n</html>";
    }
    if ($type === 'html_bootstrap') {
        $css = pw_download('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css', null, null, $error);
        $js = $css ? pw_download('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js', null, null, $error) : false;
        if (!$css || !$js) fail('Nem sikerült letölteni a Bootstrap fájlokat (' . $error . '). Van internetkapcsolat?');
        $files['css/bootstrap.min.css'] = $css;
        $files['js/bootstrap.bundle.min.js'] = $js;
        $files['index.html'] = $head . "    <title>$title - Bootstrap</title>\n    <link rel=\"stylesheet\" href=\"css/bootstrap.min.css\">\n</head>\n<body class=\"bg-light\">\n    <div class=\"container mt-5\">\n        <div class=\"card shadow-sm\">\n            <div class=\"card-body\">\n                <h1 class=\"card-title text-primary\">Bootstrap 5 Kész!</h1>\n                <p class=\"card-text\">A CSS és a JS fájlok lokálisan le lettek töltve a mappádba.</p>\n                <button class=\"btn btn-success\">Példa Gomb</button>\n            </div>\n        </div>\n    </div>\n    <script src=\"js/bootstrap.bundle.min.js\"></script>\n</body>\n</html>";
    }

    if (!@mkdir($path, 0777, true)) fail('Nem sikerült létrehozni a mappát: ' . $path);
    foreach ($files as $file => $content) {
        $target = pw_path($path, ...explode('/', $file));
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
        file_put_contents($target, $content);
    }
    file_put_contents(pw_path($path, '.ms-type'), $type);
    respond(['success' => true]);
}

if ($action === 'delete_site') {
    [$name, $dir] = require_site();
    stop_site_processes($name, $dir);
    Sites::deleteDir($dir);
    if (is_dir($dir)) {
        fail('Nem sikerült minden fájlt törölni. Lehet, hogy egy program (pl. szerkesztő vagy parancssor) még használja a mappát.');
    }
    respond(['success' => true]);
}

if ($action === 'capture_thumbnail') {
    [$name, $dir] = require_site();
    require_supervisor();
    $id = Jobs::create([
        'kind' => 'thumbnail', 'hidden' => true, 'site' => $name,
        'title' => $name . ' · képernyőkép',
        'cmd' => [Platform::php(), pw_path(PW_SYS, 'tasks', 'thumbnail.php'), $name],
        'cwd' => $dir,
    ]);
    respond(['success' => true, 'job' => $id]);
}

// ----------------------------------------------------------------------
// Import / Export: a projektek ZIP fájlba mentése (pl. a hálózati meghajtóra) és visszatöltése.
// A böngésző nem adja meg a kiválasztott mappa valódi útvonalát, ezért a vezérlőpult saját tallózó ablaka
// ezekkel a műveletekkel járja be a meghajtókat. A ZIP-et a háttérben a tasks\export.php és import.php kezeli.
// ----------------------------------------------------------------------

// sok ezer fájlos projekteknél (vendor, node_modules) a ZIP tartalomjegyzéke sok memóriát és időt kérhet
if (in_array($action, ['export_start', 'import_inspect', 'import_start'], true)) {
    ini_set('memory_limit', '512M');
    set_time_limit(120);
}

/** Egyszerre egy export / import futhat (mindkettő a gép ideiglenes mappáját és a projektek mappát használja). */
function transfer_busy(): void
{
    foreach (Jobs::all() as $job) {
        if (in_array($job['kind'], ['export', 'import'], true) && Jobs::isActive($job)) {
            fail('Már fut egy ' . ($job['kind'] === 'export' ? 'exportálás' : 'importálás') . ': várd meg a végét (a Terminál panelen követheted)!');
        }
    }
}

/** Teljes útvonal a kérésből (C:\..., H:\..., \\szerver\megosztás\...). */
function input_path(string $key): string
{
    $path = Transfer::normalizePath((string)input($key, ''));
    if ($path === null) fail('Érvénytelen útvonal: teljes útvonalat adj meg (pl. H:\\Mentések).');
    return $path;
}

/** A feladat leírása fájlban megy át: az ékezetes útvonalak a parancssorban elromolhatnának. */
function transfer_spec(array $data): string
{
    @mkdir(PW_RUN, 0777, true);
    $file = pw_path(PW_RUN, 'transfer-' . bin2hex(random_bytes(8)) . '.json');
    if (!Jobs::writeJson($file, $data)) fail('Nem sikerült elmenteni a feladat adatait.', 500);
    return $file;
}

/** A kérésben megadott ZIP fájl megnyitva: [útvonal, ZipArchive]. */
function open_import_zip(): array
{
    if (!class_exists('ZipArchive')) fail('A ZIP fájlhoz a PHP zip kiterjesztése kell (rendszer\\php\\php.ini: extension=zip).');
    $file = input_path('file');
    if (!@is_file($file)) fail('A fájl nem található: ' . $file, 404);
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::RDONLY) !== true) fail('A fájl nem ZIP fájl, vagy sérült: ' . basename($file));
    return [$file, $zip];
}

// Tallózás: meghajtók (a hálózatiak is) és a gyorselérés mappái (Asztal, Dokumentumok, Letöltések, OneDrive)
if ($action === 'fs_places') {
    respond(['drives' => Transfer::drives(), 'places' => Transfer::places(), 'separator' => DIRECTORY_SEPARATOR]);
}

if ($action === 'fs_list') {
    $path = input_path('path');
    if (!@is_dir($path)) fail('A mappa nem található, vagy nem érhető el: ' . $path, 404);
    $list = Transfer::listDir($path);
    if ($list === null) fail('A mappa tartalma nem olvasható (nincs hozzá jogod?): ' . $path, 403);
    respond(['path' => $path, 'parent' => Transfer::parent($path), 'entries' => $list['entries'], 'truncated' => $list['truncated']]);
}

if ($action === 'fs_mkdir') {
    $path = input_path('path');
    $name = trim((string)input('name', ''));
    if (!Transfer::validFileName($name)) fail('Érvénytelen mappanév (nem lehet benne: \\ / : * ? " < > |).');
    $new = Transfer::join($path, $name);
    if (file_exists($new)) fail('Már van ilyen nevű mappa vagy fájl: ' . $name);
    if (!@mkdir($new)) fail('Nem sikerült létrehozni a mappát (van írási jogod ide?): ' . $new);
    respond(['success' => true, 'path' => $new]);
}

// Exportálás: a kijelölt projektek egy ZIP fájlba (a gépen készül, a végén kerül át a célmappába)
if ($action === 'export_start') {
    require_supervisor();
    transfer_busy();
    $names = array_values(array_unique(array_map('strval', (array)input('sites', []))));
    if (!$names) fail('Jelölj ki legalább egy projektet!');
    foreach ($names as $name) {
        if (Sites::dir($name) === null) fail('A projekt nem található: ' . $name, 404);
        if (site_jobs($name, 'install')) fail($name . ': a projekt még települ, várd meg a végét!');
    }
    $dir = input_path('dir');
    if (!@is_dir($dir)) fail('A célmappa nem található, vagy nem érhető el: ' . $dir, 404);
    $fileName = trim((string)input('fileName', ''));
    if (strcasecmp((string)pathinfo($fileName, PATHINFO_EXTENSION), 'zip') !== 0) $fileName .= '.zip';
    if (!Transfer::validFileName($fileName)) fail('Érvénytelen fájlnév (nem lehet benne: \\ / : * ? " < > |).');
    $dest = Transfer::join($dir, $fileName);
    if (is_dir($dest)) fail('A célmappában ezen a néven egy mappa van: ' . $fileName);
    if (file_exists($dest) && !input('overwrite')) respond(['success' => true, 'exists' => true, 'dest' => $dest]);
    $spec = transfer_spec(['sites' => $names, 'dest' => $dest, 'skipDeps' => (bool)input('skipDeps', false)]);
    $id = Jobs::create([
        'kind' => 'export', 'title' => 'Exportálás · ' . (count($names) === 1 ? $names[0] : count($names) . ' projekt'),
        'display' => 'ZIP → ' . $dest,
        'cmd' => [Platform::php(), pw_path(PW_SYS, 'tasks', 'export.php'), $spec],
        'cwd' => PW_RUN, 'env' => terminal_env(),
    ]);
    respond(['success' => true, 'job' => $id, 'dest' => $dest]);
}

// Importálás 1.: a ZIP-ben lévő projektek (csak a ZIP tartalomjegyzékét olvassa, a kibontás a gépre másolás után jön)
if ($action === 'import_inspect') {
    [$file, $zip] = open_import_zip();
    $found = Transfer::inspect($zip, basename($file));
    $zip->close();
    $taken = [];
    $projects = [];
    foreach ($found['projects'] as $project) {
        $suggested = Transfer::uniqueName($project['name'], $taken);
        $taken[] = $suggested;
        $existing = Sites::dir($project['name']);
        $projects[] = [
            'prefix' => $project['prefix'], 'original' => $project['original'], 'name' => $project['name'],
            'type' => $project['type'], 'typeLabel' => Sites::TYPES[$project['type']] ?? 'Ismeretlen típus',
            'files' => $project['files'], 'bytes' => $project['bytes'], 'skipped' => $project['skipped'],
            'exists' => file_exists(pw_path(PW_PROJECTS, $project['name'])),
            'canOverwrite' => $existing !== null && !site_jobs($project['name'], 'install'),
            'suggested' => $suggested,
        ];
    }
    $manifest = $found['manifest'];
    respond([
        'success' => true, 'file' => $file, 'size' => (int)@filesize($file), 'unsafe' => $found['unsafe'], 'projects' => $projects,
        'manifest' => $manifest ? ['created' => (string)($manifest['created'] ?? ''), 'version' => (string)($manifest['version'] ?? '')] : null,
    ]);
}

// Importálás 2.: a kiválasztott projektek (új néven, vagy a meglévő felülírásával)
if ($action === 'import_start') {
    require_supervisor();
    transfer_busy();
    [$file, $zip] = open_import_zip();
    $found = Transfer::inspect($zip, basename($file));
    $zip->close();
    $byPrefix = array_column($found['projects'], null, 'prefix');
    $items = [];
    $targets = [];
    foreach ((array)input('items', []) as $item) {
        if (!is_array($item) || !isset($byPrefix[(string)($item['prefix'] ?? '')])) fail('A kiválasztott projekt nem található a ZIP-ben.');
        $project = $byPrefix[(string)$item['prefix']];
        $overwrite = !empty($item['overwrite']);
        $target = $overwrite ? $project['name'] : trim((string)($item['target'] ?? ''));
        if (!Sites::validNewName($target)) fail('Érvénytelen projektnév: ' . $target . ' (csak kisbetű, szám, kötőjel és aláhúzás lehet benne).');
        if (isset($targets[$target])) fail('Két projekt nem kaphatja ugyanazt a nevet: ' . $target);
        if ($overwrite && Sites::dir($target) === null) $overwrite = false;   // közben törölték: egyszerűen létrejön
        if ($overwrite && site_jobs($target, 'install')) fail($target . ': a projekt még települ, várd meg a végét!');
        if (!$overwrite && file_exists(pw_path(PW_PROJECTS, $target))) fail('Már van ilyen nevű projekt: ' . $target . '. Adj meg másik nevet, vagy válaszd a felülírást!');
        $targets[$target] = true;
        $items[] = ['prefix' => $project['prefix'], 'target' => $target, 'overwrite' => $overwrite];
    }
    if (!$items) fail('Jelölj ki legalább egy projektet!');
    foreach ($items as $item) {
        if ($item['overwrite']) stop_site_processes($item['target'], pw_path(PW_PROJECTS, $item['target']));
    }
    $spec = transfer_spec(['source' => $file, 'items' => $items]);
    $id = Jobs::create([
        'kind' => 'import', 'title' => 'Importálás · ' . basename($file),
        'display' => 'ZIP ← ' . $file,
        'cmd' => [Platform::php(), pw_path(PW_SYS, 'tasks', 'import.php'), $spec],
        'cwd' => PW_RUN, 'env' => terminal_env(),
    ]);
    respond(['success' => true, 'job' => $id]);
}

// Az exportált ZIP megmutatása az Intézőben (kijelölve)
if ($action === 'reveal_path') {
    $path = input_path('path');
    if (!@is_file($path) || strcasecmp((string)pathinfo($path, PATHINFO_EXTENSION), 'zip') !== 0) fail('A fájl nem található: ' . $path, 404);
    launch(Platform::revealFileSpec($path));
}

fail('Ismeretlen művelet.', 404);
