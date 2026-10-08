<?php
/*
 * PocketWeb – közös betöltőfájl.
 * Az api.php, a router.php, a felügyelő (pocketweb.php) és a tasks\ szkriptek is ezt töltik be.
 */

define('PW_VERSION', '3.0');
define('PW_SYS', dirname(__DIR__));                              // ...\PocketWeb\rendszer
define('PW_ROOT', dirname(PW_SYS));                              // ...\PocketWeb
define('PW_PROJECTS', PW_ROOT . DIRECTORY_SEPARATOR . 'projektek');
define('PW_RUN', PW_SYS . DIRECTORY_SEPARATOR . '.run');         // futásidejű állapot: naplók, feladatok, üzenetsor
define('PW_FEEDBACK_DIR', PW_ROOT . DIRECTORY_SEPARATOR . 'visszajelzes');   // ide kerülnek a csatolandó naplók
define('PW_FEEDBACK_EMAIL', 'feedback@zseli.hu');
define('PW_REPO_URL', 'https://github.com/zseliakiraly/PocketWeb');
define('PW_HOST', '127.0.0.1');
define('PW_PORT', (int)(getenv('POCKETWEB_PORT') ?: 8181));

require_once __DIR__ . '/Platform.php';
require_once __DIR__ . '/Jobs.php';
require_once __DIR__ . '/Sites.php';
require_once __DIR__ . '/Feedback.php';
require_once __DIR__ . '/Mapi.php';
require_once __DIR__ . '/WinFocus.php';

/** Útvonal összefűzése. */
function pw_path(string ...$parts): string
{
    return implode(DIRECTORY_SEPARATOR, $parts);
}

/**
 * Letöltés HTTPS-en. Elsősorban curl-lel: az magától kezeli a HTTPS_PROXY változót (iskolai hálózat),
 * és a Windows tanúsítványtárát használja. Ha nincs curl, vagy tanúsítványhiba van, a PHP stream
 * wrapperével próbálkozik (az is a Windows tanúsítványtárát használja).
 * Megjegyzés: a PHP 8.4 stream wrappere proxyn át összeomolhat, ha a proxy elutasítja a kapcsolatot,
 * ezért nem ez az elsődleges.
 *
 * @param string|null   $dest     ide menti a fájlt; null esetén a letöltött tartalmat adja vissza
 * @param callable|null $progress function (int $letoltve, int $osszesen)
 * @param string|null   $error    hiba esetén az oka
 * @return string|bool
 */
function pw_download(string $url, ?string $dest = null, ?callable $progress = null, ?string &$error = null)
{
    $error = null;
    if (function_exists('curl_init')) {
        $result = pw_curl_download($url, $dest, $progress, $error, $sslProblem);
        if ($result !== false || !$sslProblem) return $result;
    }

    $http = ['follow_location' => 1, 'timeout' => 60, 'user_agent' => 'PocketWeb/' . PW_VERSION];
    $proxy = getenv('https_proxy') ?: getenv('HTTPS_PROXY') ?: getenv('http_proxy') ?: getenv('HTTP_PROXY');
    if ($proxy && ($parts = parse_url($proxy)) && !empty($parts['host'])) {
        $http['proxy'] = 'tcp://' . $parts['host'] . ':' . ($parts['port'] ?? 80);
        $http['request_fulluri'] = true;
        if (isset($parts['user'])) {
            $http['header'] = 'Proxy-Authorization: Basic ' . base64_encode(rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? ''));
        }
    }
    $params = [];
    if ($progress) {
        $total = 0;
        $params['notification'] = function ($code, $severity, $message, $messageCode, $bytes, $max) use (&$total, $progress) {
            if ($code === STREAM_NOTIFY_FILE_SIZE_IS) $total = (int)$max;
            if ($code === STREAM_NOTIFY_PROGRESS) $progress((int)$bytes, $total);
        };
    }
    $context = stream_context_create(['http' => $http, 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]], $params);

    $in = @fopen($url, 'rb', false, $context);
    if (!$in) {
        $error = preg_replace('/^fopen\([^)]*\): /', '', error_get_last()['message'] ?? 'ismeretlen hiba');
        return false;
    }
    if ($dest === null) {
        $body = stream_get_contents($in);
        fclose($in);
        return $body;
    }
    $out = @fopen($dest, 'wb');
    if (!$out) {
        fclose($in);
        $error = 'Nem írható: ' . $dest;
        return false;
    }
    $bytes = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return $bytes !== false && $bytes > 0;
}

function pw_curl_download(string $url, ?string $dest, ?callable $progress, ?string &$error, ?bool &$sslProblem)
{
    $sslProblem = false;
    $ch = curl_init($url);
    $out = null;
    if ($dest !== null) {
        $out = @fopen($dest, 'wb');
        if (!$out) {
            $error = 'Nem írható: ' . $dest;
            return false;
        }
        curl_setopt($ch, CURLOPT_FILE, $out);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_FAILONERROR => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_LOW_SPEED_LIMIT => 1,     // ha 60 mp-ig nem jön adat, feladjuk
        CURLOPT_LOW_SPEED_TIME => 60,
        CURLOPT_USERAGENT => 'PocketWeb/' . PW_VERSION,
    ]);
    // a Windows tanúsítványtára (curl 7.71+); ha nem támogatott, a stream tartalék használja
    if ((curl_version()['version_number'] ?? 0) >= 0x074700) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, defined('CURLSSLOPT_NATIVE_CA') ? CURLSSLOPT_NATIVE_CA : 16);
    }
    if ($progress) {
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function ($handle, $total, $done) use ($progress) {
            $progress((int)$done, (int)$total);
            return 0;
        });
    }
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    if ($errno) {
        $error = curl_error($ch);
        // tanúsítvány / TLS hibák: ilyenkor a stream tartalékkal még próbálkozunk
        $sslProblem = in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83], true);
    }
    unset($ch);
    if ($out) fclose($out);
    if ($errno) {
        if ($dest !== null) @unlink($dest);
        return false;
    }
    return $dest !== null ? true : (string)$body;
}
