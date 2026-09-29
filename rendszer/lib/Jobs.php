<?php
/*
 * PocketWeb – háttérfeladatok (webszerverek, telepítők) nyilvántartása és üzenetsor a felügyelő felé.
 *
 * rendszer/.run/
 *   jobs/<id>/spec.json    – a feladat leírása (az api.php írja, utána nem változik)
 *   jobs/<id>/state.json   – állapot (csak a felügyelő írja): running / stopping / exited / stopped / failed
 *   jobs/<id>/output.log   – a program kimenete (stdout + stderr) – ezt mutatja a Terminál panel
 *   queue/*.json           – kérések a felügyelőnek: start, stop, remove, launch, quit
 *   supervisor.json        – a felügyelő adatai (PID, böngésző mód, token)
 *   supervisor.alive       – a felügyelő 2 másodpercenként frissíti
 *   heartbeat / bye        – a vezérlőpult jelzései (ebből tudja a felügyelő, hogy be lett-e zárva)
 */
class Jobs
{
    const ACTIVE = ['queued', 'running', 'stopping'];

    public static function dir(string $id): string
    {
        return pw_path(PW_RUN, 'jobs', $id);
    }

    public static function logFile(string $id): string
    {
        return pw_path(PW_RUN, 'jobs', $id, 'output.log');
    }

    public static function validId($id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{8}$/', $id) === 1;
    }

    /**
     * Új feladat: leírás mentése + indítási kérés a felügyelőnek.
     * $spec: cmd (tömb, az első elem a program teljes útvonala), cwd, env, kind, title, site, port, hidden
     */
    public static function create(array $spec): string
    {
        do {
            $id = bin2hex(random_bytes(4));
        } while (is_dir(self::dir($id)));

        $spec = array_merge([
            'kind' => 'task', 'title' => '', 'site' => null, 'port' => null,
            'cwd' => PW_ROOT, 'env' => [], 'hidden' => false,
        ], $spec, ['id' => $id, 'created' => microtime(true)]);

        @mkdir(self::dir($id), 0777, true);
        self::writeJson(pw_path(self::dir($id), 'spec.json'), $spec);
        self::send(['op' => 'start', 'id' => $id]);
        return $id;
    }

    public static function spec(string $id): ?array
    {
        $spec = self::readJson(pw_path(self::dir($id), 'spec.json'));
        return is_array($spec) ? $spec : null;
    }

    public static function state(string $id): array
    {
        $state = self::readJson(pw_path(self::dir($id), 'state.json'));
        return is_array($state) ? $state : ['state' => 'queued'];
    }

    public static function setState(string $id, array $state): void
    {
        if (is_dir(self::dir($id))) {
            self::writeJson(pw_path(self::dir($id), 'state.json'), $state);
        }
    }

    /** Az összes feladat indítási sorrendben; mindegyikben 'status' = state.json tartalma. */
    public static function all(): array
    {
        $jobs = [];
        foreach (glob(pw_path(PW_RUN, 'jobs', '*', 'spec.json')) ?: [] as $file) {
            $spec = self::readJson($file);
            if (!is_array($spec) || !self::validId($spec['id'] ?? null)) continue;
            $spec['status'] = self::state($spec['id']);
            $jobs[] = $spec;
        }
        usort($jobs, function ($a, $b) { return $a['created'] <=> $b['created']; });
        return $jobs;
    }

    public static function isActive(array $job): bool
    {
        return in_array($job['status']['state'] ?? 'queued', self::ACTIVE, true);
    }

    /**
     * A napló egy darabja $offset-től. Negatív offset: új néző, csak a napló vége kell (sor elejétől).
     * @return array{data:string, offset:int}
     */
    public static function readLog(string $id, int $offset, int $max = 131072): array
    {
        $file = self::logFile($id);
        clearstatcache(true, $file);
        $size = is_file($file) ? (int)filesize($file) : 0;
        $data = '';
        $cutToLine = false;

        if ($offset < 0) {
            $offset = max(0, $size - 65536);
            $cutToLine = $offset > 0;
        }
        if ($offset > $size) {
            $offset = $size;
        }
        if ($size > $offset && ($fh = @fopen($file, 'rb'))) {
            fseek($fh, $offset);
            $data = (string)fread($fh, min($max, $size - $offset));
            fclose($fh);
            if ($cutToLine && ($nl = strpos($data, "\n")) !== false) {
                // a levágott napló elején ne legyen félbevágott sor / escape szekvencia
                $offset += $nl + 1;
                $data = substr($data, $nl + 1);
            }
        }
        return ['data' => $data, 'offset' => $offset + strlen($data)];
    }

    /** A napló utolsó nem üres sora, színkódok nélkül (rövid üzenetekhez, pl. képernyőkép hibája). */
    public static function lastLine(string $id): string
    {
        $log = self::readLog($id, -1, 65536)['data'];
        $lines = array_filter(array_map('trim', preg_split('/[\r\n]+/', preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $log))));
        return (string)end($lines);
    }

    /** Feladat mappájának törlése (csak befejezett feladatnál). */
    public static function removeDir(string $id): void
    {
        $dir = self::dir($id);
        foreach (glob(pw_path($dir, '*')) ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------
    // Üzenetsor (api.php → felügyelő)
    // ------------------------------------------------------------------

    public static function send(array $message): void
    {
        $dir = pw_path(PW_RUN, 'queue');
        @mkdir($dir, 0777, true);
        $name = sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(3)) . '.json';
        self::writeJson(pw_path($dir, $name), $message);
    }

    /** A felügyelő hívja: az összes várakozó üzenet érkezési sorrendben (a fájlokat törli). */
    public static function receive(): array
    {
        $files = glob(pw_path(PW_RUN, 'queue', '*.json')) ?: [];
        sort($files, SORT_STRING);
        $messages = [];
        foreach ($files as $file) {
            $message = self::readJson($file);
            @unlink($file);
            if (is_array($message) && isset($message['op'])) $messages[] = $message;
        }
        return $messages;
    }

    // ------------------------------------------------------------------
    // JSON fájlok (atomi írás: ideiglenes fájl + átnevezés)
    // ------------------------------------------------------------------

    public static function writeJson(string $file, $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if ($json === false || @file_put_contents($tmp, $json) === false) return false;
        for ($i = 0; $i < 25; $i++) {
            if (@rename($tmp, $file)) return true;
            usleep(20000);   // Windows: ha épp olvassa valaki, kicsit később sikerül
        }
        @unlink($tmp);
        return false;
    }

    public static function readJson(string $file)
    {
        $raw = @file_get_contents($file);
        return ($raw === false || $raw === '') ? null : json_decode($raw, true);
    }
}

/** A felügyelő és a vezérlőpult közös állapotfájljai. */
class Runtime
{
    public static function file(string $name): string
    {
        return pw_path(PW_RUN, $name);
    }

    public static function supervisor(): ?array
    {
        $data = Jobs::readJson(self::file('supervisor.json'));
        return is_array($data) ? $data : null;
    }

    /** Hány másodperce frissült a fájl (null, ha nem létezik). */
    public static function age(string $name): ?float
    {
        $file = self::file($name);
        clearstatcache(true, $file);
        $mtime = @filemtime($file);
        return $mtime === false ? null : max(0, time() - $mtime);
    }

    public static function touch(string $name): void
    {
        @mkdir(PW_RUN, 0777, true);
        @file_put_contents(self::file($name), (string)microtime(true));
    }

    /** A touch() által beírt pontos időpont (null, ha nincs ilyen jelzés). */
    public static function stamp(string $name): ?float
    {
        $raw = @file_get_contents(self::file($name));
        return ($raw === false || !is_numeric(trim($raw))) ? null : (float)trim($raw);
    }

    /** Fut-e a felügyelő (és nem épp leállás közben van)? */
    public static function supervisorAlive(): bool
    {
        $age = self::age('supervisor.alive');
        $info = self::supervisor();
        return $age !== null && $age < 10 && $info !== null && empty($info['shuttingDown']);
    }
}
