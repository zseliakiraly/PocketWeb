<?php
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
// A rendszer mappa feletti főkönyvtár
$baseDir = realpath(__DIR__ . '/../');
$projectsDir = $baseDir . DIRECTORY_SEPARATOR . 'projektek';

if (!file_exists($projectsDir)) {
    mkdir($projectsDir, 0777, true);
}

if ($action === 'get_versions') {
    $phpPath = $baseDir . DIRECTORY_SEPARATOR . 'rendszer' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
    $nodePath = $baseDir . DIRECTORY_SEPARATOR . 'rendszer' . DIRECTORY_SEPARATOR . 'node' . DIRECTORY_SEPARATOR . 'node.exe';

    $phpVer = '-';
    $nodeVer = '-';

    // PHP verzió kiolvasása parancssorból
    if (file_exists($phpPath)) {
        $out = shell_exec('"' . $phpPath . '" -v');
        if (preg_match('/PHP ([\d\.]+)/', $out, $matches)) {
            $phpVer = $matches[1];
        }
    }

    // Node verzió kiolvasása
    if (file_exists($nodePath)) {
        $out = shell_exec('"' . $nodePath . '" -v');
        $nodeVer = trim($out);
    }

    echo json_encode(['php' => $phpVer, 'node' => $nodeVer]);
    exit;
}

if ($action === 'get_sites') {
    $sites = [];
    $dirs = array_filter(glob($projectsDir . '/*'), 'is_dir');

    // hálózati port lekérdezés (csak a hallgatózó portok) - EGYSZER FUT LE!
    $netstat = shell_exec('netstat -ano -p TCP | find "LISTENING" 2>NUL');

    foreach ($dirs as $dir) {
        $name = basename($dir);
        
        // Típus beazonosítása
        $typeId = '';
        if (file_exists($dir . DIRECTORY_SEPARATOR . '.ms-type')) {
            $typeId = trim(file_get_contents($dir . DIRECTORY_SEPARATOR . '.ms-type'));
        } else {
            // Visszamenőleges kompatibilitás vagy automatikus felismerés, de CSAK ha már kész van a projekt!
            if (file_exists($dir . DIRECTORY_SEPARATOR . 'artisan')) {
                $typeId = 'laravel';
            } elseif (file_exists($dir . DIRECTORY_SEPARATOR . 'wp-config.php') || file_exists($dir . DIRECTORY_SEPARATOR . 'wp-install.php')) {
                $typeId = 'wordpress';
            } elseif (file_exists($dir . DIRECTORY_SEPARATOR . 'index.html')) {
                $typeId = 'html_css';
            }
        }

        // Ha a mappa még teljesen üres / folyamatban van a letöltése (pl. Laravel composer még töltődik), 
        // akkor SKIPPELJÜK, nehogy félkész kártya jelenjen meg a vezérlőpulton!
        if ($typeId === '') {
            continue;
        }

        $typeNames = [
            'html_css' => 'HTML + CSS',
            'html_tailwind' => 'HTML + Tailwind',
            'html_bootstrap' => 'HTML + Bootstrap',
            'wordpress' => 'WordPress',
            'laravel' => 'Laravel'
        ];
        $typeName = $typeNames[$typeId] ?? 'Projekt';
        $needsServer = in_array($typeId, ['laravel', 'wordpress']);

        $port = '';
        $isRunning = false;

        if ($needsServer) {
            $portFile = $dir . DIRECTORY_SEPARATOR . '.ms-port';
            if (file_exists($portFile)) {
                $port = (int)file_get_contents($portFile);
            } else {
                $maxPort = 7999;
                foreach (glob($projectsDir . '/*/.ms-port') as $pf) {
                    $p = (int)file_get_contents($pf);
                    if ($p > $maxPort) $maxPort = $p;
                }
                $port = $maxPort + 1;
                file_put_contents($portFile, $port);
            }

            // Tűpontos ellenőrzés a saját egyedi portja alapján
            if ($port && $netstat && strpos($netstat, ':' . $port . ' ') !== false) {
                $isRunning = true;
            }
        }

        $sites[] = [
            'id' => md5($name),
            'name' => $name,
            'type' => $typeName,
            'php' => '8.x',
            'port' => $port,
            'isRunning' => $isRunning,
            'needsServer' => $needsServer
        ];
    }
    echo json_encode($sites);
    exit;
}


if ($action === 'get_db_path') {
    $site = basename($_GET['site'] ?? '');
    $type = $_GET['type'] ?? '';
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;
    
    $dbFile = '';
    if ($type === 'Laravel') {
        $dbFile = $path . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database.sqlite';
    } elseif ($type === 'WordPress') {
        $dbFile = $path . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . '.ht.sqlite';
    }
    
    if ($dbFile && file_exists($dbFile)) {
        // Átalakítjuk a Windows útvonalat forward slashekre a biztonságos URL paraméterezéshez
        $normalizedPath = str_replace('\\', '/', realpath($dbFile));
        echo json_encode(['success' => true, 'path' => $normalizedPath]);
    } else {
        echo json_encode(['success' => false, 'path' => '']);
    }
    exit;
}

if ($action === 'open_folder') {
    $site = $_GET['site'] ?? '';
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;
    if (is_dir($path)) {
        $cmd = 'cmd.exe /C start "" "' . $path . '"';
        pclose(popen($cmd, 'r'));
        echo json_encode(['success' => true]);
    }
    exit;
}

if ($action === 'open_terminal') {
    $site = $_GET['site'] ?? '';
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;
    if (is_dir($path)) {
        $phpPath = $baseDir . DIRECTORY_SEPARATOR . 'rendszer' . DIRECTORY_SEPARATOR . 'php';
        $cmd = 'start "Terminal - '.$site.'" cmd.exe /K "set PATH='.$phpPath.';%PATH% & cd /d "'.$path.'""';
        pclose(popen($cmd, 'r'));
        echo json_encode(['success' => true]);
    }
    exit;
}

if ($action === 'open_browser') {
    $site = $_GET['site'] ?? '';
    $mode = $_GET['mode'] ?? 'server';
    $port = $_GET['port'] ?? '';
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;

    if ($mode === 'local' && is_dir($path)) {
        $indexPath = $path . DIRECTORY_SEPARATOR . 'index.html';
        if (file_exists($indexPath)) {
            pclose(popen('cmd.exe /C start "" "' . $indexPath . '"', 'r'));
        } else {
            pclose(popen('cmd.exe /C start "" "' . $path . '"', 'r'));
        }
    } elseif ($mode === 'server' && $port) {
        pclose(popen('cmd.exe /C start "" "http://127.0.0.1:' . $port . '"', 'r'));
    }
    
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'open_editor') {
    $site = $_POST['site'] ?? '';
    $editor = $_POST['editor'] ?? 'vscode';
    $sitePath = realpath(__DIR__ . '/../projektek/' . $site);
    
    if ($sitePath && is_dir($sitePath)) {
        $allowedEditors = [
            'vscode' => 'code',
            'sublime' => '"C:\Program Files\Sublime Text\sublime_text.exe"',
            'notepadpp' => 'notepad++ -openFoldersAsWorkspace',
            'webstorm' => 'webstorm64',
            'phpstorm' => 'phpstorm64.exe',
        ];

        if (array_key_exists($editor, $allowedEditors)) {
            $cmd = $allowedEditors[$editor];

            $execCommand = 'start /B "" ' . $cmd . ' "' . $sitePath . '"';
            pclose(popen($execCommand, "r"));
            
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Érvénytelen szerkesztő.']);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'A projekt mappa nem található.']);
    exit;
}

if ($action === 'toggle_serve') {
    $site = $_GET['site'] ?? '';
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;
    
    $portFile = $path . DIRECTORY_SEPARATOR . '.ms-port';
    $port = 8000;
    if (file_exists($portFile)) {
        $port = (int)file_get_contents($portFile);
    } else {
        $maxPort = 7999;
        foreach (glob($projectsDir . '/*/.ms-port') as $pf) {
            $p = (int)file_get_contents($pf);
            if ($p > $maxPort) $maxPort = $p;
        }
        $port = $maxPort + 1;
        file_put_contents($portFile, $port);
    }

    $output = shell_exec('netstat -ano -p TCP | find "LISTENING" | find ":'.$port.' " 2>NUL');
    
    if (trim($output) !== '') {
        if (preg_match('/LISTENING\s+(\d+)/i', $output, $matches)) {
            $pid = $matches[1];
            shell_exec("taskkill /PID $pid /T /F");
            echo json_encode(['success' => true, 'state' => 'stopped']);
            exit;
        }
    }

    $phpPath = $baseDir . DIRECTORY_SEPARATOR . 'rendszer' . DIRECTORY_SEPARATOR . 'php';
    
    $typeId = 'laravel';
    if (file_exists($path . DIRECTORY_SEPARATOR . '.ms-type')) {
        $typeId = trim(file_get_contents($path . DIRECTORY_SEPARATOR . '.ms-type'));
    }

    if ($typeId === 'laravel') {
        $taskName = "Laravel_Serve_" . $site;
        $cmd = 'start "'.$taskName.'" cmd.exe /C "color 06 & title '.$taskName.' & echo. & echo   [*] Laravel webszerver inditasa... & echo   [*] Kerlek varj. & set PATH='.$phpPath.';%PATH% & cd /d "'.$path.'" & php artisan serve --host=127.0.0.1 --port='.$port.'"';
    } else {
        $taskName = "PHP_Serve_" . $site;
        $cmd = 'start "'.$taskName.'" cmd.exe /C "color 0b & title '.$taskName.' & echo. & echo   [*] PHP webszerver inditasa... & echo   [*] Kerlek varj. & set PATH='.$phpPath.';%PATH% & cd /d "'.$path.'" & php -S 127.0.0.1:'.$port.'"';
    }

    pclose(popen($cmd, 'r'));
    echo json_encode(['success' => true, 'state' => 'started']);
    exit;
}

if ($action === 'create_project') {
    $site = $_GET['site'] ?? '';
    $type = $_GET['type'] ?? 'html_css';
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;
    
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }

    $phpPath = $baseDir . DIRECTORY_SEPARATOR . 'rendszer' . DIRECTORY_SEPARATOR . 'php';
    
    // 1. Sima HTML + CSS
    if ($type === 'html_css') {
        file_put_contents($path . DIRECTORY_SEPARATOR . '.ms-type', $type);
        mkdir($path . '/css');
        file_put_contents($path . '/index.html', "<!DOCTYPE html>\n<html lang=\"hu\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>$site</title>\n    <link rel=\"stylesheet\" href=\"css/style.css\">\n</head>\n<body>\n    <h1>Helló Világ! ($site)</h1>\n</body>\n</html>");
        file_put_contents($path . '/css/style.css', "body {\n    font-family: sans-serif;\n    background-color: #f4f4f9;\n    color: #333;\n    padding: 2rem;\n}");
    }
    
    // 2. HTML + Tailwind
    if ($type === 'html_tailwind') {
        file_put_contents($path . DIRECTORY_SEPARATOR . '.ms-type', $type);
        file_put_contents($path . '/index.html', "<!DOCTYPE html>\n<html lang=\"hu\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>$site - Tailwind</title>\n    <script src=\"https://cdn.tailwindcss.com\"></script>\n</head>\n<body class=\"bg-gray-100 text-gray-800 flex items-center justify-center h-screen\">\n    <div class=\"bg-white p-8 rounded-xl shadow-lg\">\n        <h1 class=\"text-3xl font-bold text-blue-600 mb-4\">Tailwind Működik!</h1>\n        <p class=\"text-gray-600\">Ez a projekt a Tailwind CSS CDN verzióját használja.</p>\n    </div>\n</body>\n</html>");
    }

    // 3. HTML + Bootstrap
    if ($type === 'html_bootstrap') {
        file_put_contents($path . DIRECTORY_SEPARATOR . '.ms-type', $type);
        mkdir($path . '/css');
        mkdir($path . '/js');
        file_put_contents($path . '/css/bootstrap.min.css', file_get_contents('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'));
        file_put_contents($path . '/js/bootstrap.bundle.min.js', file_get_contents('https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'));
        
        file_put_contents($path . '/index.html', "<!DOCTYPE html>\n<html lang=\"hu\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>$site - Bootstrap</title>\n    <link rel=\"stylesheet\" href=\"css/bootstrap.min.css\">\n</head>\n<body class=\"bg-light\">\n    <div class=\"container mt-5\">\n        <div class=\"card shadow-sm\">\n            <div class=\"card-body\">\n                <h1 class=\"card-title text-primary\">Bootstrap 5 Kész!</h1>\n                <p class=\"card-text\">A CSS és a JS fájlok lokálisan le lettek töltve a mappádba.</p>\n                <button class=\"btn btn-success\">Példa Gomb</button>\n            </div>\n        </div>\n    </div>\n    <script src=\"js/bootstrap.bundle.min.js\"></script>\n</body>\n</html>");
    }

// 4. WordPress (Öntelepítő konzolos visszajelzéssel)
    if ($type === 'wordpress') {
        $installerCode = '<?php
echo "\n[*] WordPress letoltese inditasa...\n";
function downloadFile($url, $dest) {
    echo "    - Letoltes: " . basename($dest) . "...\n";
    $ch = curl_init($url);
    $fp = fopen($dest, "w+");
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    curl_exec($ch);
    fclose($fp);
}

downloadFile("https://wordpress.org/latest.zip", "wp.zip");
echo "[*] WordPress kibontasa...\n";
$zip = new ZipArchive;
if ($zip->open("wp.zip") === TRUE) {
    $zip->extractTo(".");
    $zip->close();
}
unlink("wp.zip");

if (is_dir("wordpress")) {
    foreach (scandir("wordpress") as $file) {
        if ($file != "." && $file != "..") {
            rename("wordpress/$file", "$file");
        }
    }
    rmdir("wordpress");
}

if (!is_dir("wp-content/plugins")) {
    mkdir("wp-content/plugins", 0777, true);
}

echo "[*] SQLite adatbazisplugin letoltese...\n";
downloadFile("https://downloads.wordpress.org/plugin/sqlite-database-integration.zip", "sqlite.zip");
if (file_exists("sqlite.zip") && filesize("sqlite.zip") > 1000) {
    if ($zip->open("sqlite.zip") === TRUE) {
        $zip->extractTo("wp-content/plugins/");
        $zip->close();
    }
}
unlink("sqlite.zip");

$pluginDirs = glob("wp-content/plugins/sqlite-database-integration*");
if (!empty($pluginDirs)) {
    $pluginDir = $pluginDirs[0];
    $pluginName = basename($pluginDir);
    $dbCopyPath = $pluginDir . "/db.copy";
    if (file_exists($dbCopyPath)) {
        $dbContent = file_get_contents($dbCopyPath);
        $sq = chr(39);
        $dbContent = str_replace($sq . "{SQLITE_IMPLEMENTATION_FOLDER_PATH}" . $sq, "__DIR__." . $sq . "/plugins/" . $pluginName . $sq, $dbContent);
        $dbContent = str_replace("{SQLITE_PLUGIN}", $pluginName . "/load.php", $dbContent);
        file_put_contents("wp-content/db.php", $dbContent);
    }
}

if (file_exists("wp-config-sample.php")) {
    copy("wp-config-sample.php", "wp-config.php");
}

file_put_contents(".ms-type", "wordpress");
unlink(__FILE__);
echo "\n[+] WordPress sikeresen telepítve es beállítva!\n";
';
        file_put_contents($path . '/wp-install.php', $installerCode);
        $cmd = 'start "WordPress Telepito - '.$site.'" cmd.exe /C "color 0b & title WordPress Telepito - '.$site.' & echo. & echo   [*] WordPress telepites inditasa... & set PATH='.$phpPath.';%PATH% & cd /d "'.$path.'" & php wp-install.php & echo. & echo Telepites kesz! Nyomj egy gombot a bezarashoz... & pause"';
        pclose(popen($cmd, 'r'));
    }
    // 5. Laravel
    if ($type === 'laravel') {
        $composerPath = $phpPath . DIRECTORY_SEPARATOR . 'composer.phar';
        $typeFilePath = $path . DIRECTORY_SEPARATOR . '.ms-type';
        // Ha a mappa nem üres (már létezne valami), töröljük a biztonság kedvéért, hogy a composer ne hibázzon
        $cmd = 'start "Laravel Telepites - '.$site.'" cmd.exe /C "color 0a & echo. & echo   [*] Laravel letoltese es inicializalasa... & echo. & set PATH='.$phpPath.';%PATH% & cd /d "'.$projectsDir.'" & php "'.$composerPath.'" create-project laravel/laravel "'.$site.'" && echo laravel>'.escapeshellarg($typeFilePath).' & echo. & echo Telepites kesz! Nyomj egy gombot... & pause"';
        pclose(popen($cmd, 'r'));
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_site') {
    $site = basename($_GET['site'] ?? '');
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;

    if ($site && $site !== '.' && $site !== '..' && is_dir($path)) {
        $deleteDir = function($dir) use (&$deleteDir) {
            if (!is_dir($dir)) return;
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file != "." && $file != "..") {
                    if (is_dir("$dir/$file") && !is_link("$dir/$file")) {
                        $deleteDir("$dir/$file");
                    } else {
                        unlink("$dir/$file");
                    }
                }
            }
            rmdir($dir);
        };
        
        $deleteDir($path);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'A mappa nem találhato']);
    }
    exit;
}

if ($action === 'capture_thumbnail') {
    $site = $_GET['site'] ?? '';
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;
    $typeId = 'html_css';
    
    if (file_exists($path . DIRECTORY_SEPARATOR . '.ms-type')) {
        $typeId = trim(file_get_contents($path . DIRECTORY_SEPARATOR . '.ms-type'));
    }

    $url = '';
    if (in_array($typeId, ['laravel', 'wordpress'])) {
        $portFile = $path . DIRECTORY_SEPARATOR . '.ms-port';
        if (file_exists($portFile)) {
            $port = (int)file_get_contents($portFile);
            $netstat = shell_exec('netstat -ano -p TCP | find "LISTENING" | find ":'.$port.' " 2>NUL');
            if (trim($netstat) !== '') {
                $url = "http://127.0.0.1:" . $port;
            }
        }
    } else {
        $url = 'file:///' . str_replace('\\', '/', $path) . '/index.html';
    }

    if ($url) {
        $dest = $path . DIRECTORY_SEPARATOR . 'thumbnail.png';
        $destSafe = str_replace('\\', '/', $dest);
        
        if (file_exists($dest)) unlink($dest);

        $edgePath = 'msedge.exe'; 
        if (file_exists('C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe')) {
            $edgePath = '"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"';
        } elseif (file_exists('C:\Program Files\Microsoft\Edge\Application\msedge.exe')) {
            $edgePath = '"C:\Program Files\Microsoft\Edge\Application\msedge.exe"';
        }
        
        $cmd = $edgePath . ' --headless --disable-gpu --allow-file-access-from-files --window-size=1024,768 --hide-scrollbars --screenshot="' . $destSafe . '" "' . $url . '"';
        exec($cmd);
        
        if (file_exists($dest)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'A böngésző nem tudta elkészíteni a képet.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'A weboldal nem fut, indítsd el a szervert!']);
    }
    exit;
}

if ($action === 'thumbnail') {
    $site = basename($_GET['site'] ?? '');
    $type = $_GET['type'] ?? '';
    $path = $projectsDir . DIRECTORY_SEPARATOR . $site;
    
    $exts = ['png', 'jpg', 'jpeg'];
    foreach($exts as $ext) {
        $img = $path . DIRECTORY_SEPARATOR . 'thumbnail.' . $ext;
        if (file_exists($img)) {
            $mime = $ext === 'jpg' ? 'jpeg' : $ext;
            header("Content-Type: image/$mime");
            readfile($img);
            exit;
        }
    }
    
    header('Content-Type: image/svg+xml');
    
    $color = '#545454';
    if (stripos($type, 'laravel') !== false) $color = '#e68c3a';
    if (stripos($type, 'wordpress') !== false) $color = '#2563eb';
    if (stripos($type, 'tailwind') !== false) $color = '#06b6d4';
    if (stripos($type, 'bootstrap') !== false) $color = '#9333ea';

    $letter = strtoupper(substr($site, 0, 1));
    if (!$letter) $letter = '?';

    echo '<?xmlوة version="1.0" encoding="UTF-8"?>
    <svg width="400" height="200" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" style="stop-color:#282828;stop-opacity:1" />
                <stop offset="100%" style="stop-color:#141414;stop-opacity:1" />
            </linearGradient>
        </defs>
        <rect width="100%" height="100%" fill="url(#grad)"/>
        <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="72" font-weight="bold" fill="'.$color.'">'.$letter.'</text>
    </svg>';
    exit;
}