<?php
/*
 * PocketWeb – az elindított program ablakának előtérbe hozása (Intéző, böngésző, szerkesztő, levelezőprogram).
 *
 * A Windows csak annak a programnak engedi, hogy előtérbe hozza az ablakát, amelyikkel a felhasználó épp
 * dolgozik – itt a vezérlőpult böngészője. A programokat viszont a háttérben futó felügyelő indítja, ezért
 * az ablakuk a vezérlőpult mögött nyílna meg (csak a tálcán villogna). A parancssor ablakra ez nem igaz,
 * azt a Windows mindig előre hozza.
 *
 * Ezért a felügyelő az indítás után megkeresi a program ablakát (az indítás előtti ablaklistához képest új
 * ablakot, vagy ha a program egy már nyitott ablakát használja, azt), és a vezérlőpult szálához kapcsolódva
 * (AttachThreadInput) előtérbe hozza. A user32.dll-t a PHP FFI kiterjesztésével hívja: ehhez a felügyelő
 * "-d extension=ffi" kapcsolóval indul (indito.bat), ha a rendszer\php\ext\php_ffi.dll megvan. Ha nincs,
 * minden működik, csak az ablakok maradhatnak a vezérlőpult mögött.
 */
final class WinFocus
{
    const SW_RESTORE = 9;
    const GW_OWNER = 4;
    const GWL_EXSTYLE = -20;
    const WS_EX_TOOLWINDOW = 0x80;
    const PROCESS_QUERY_LIMITED_INFORMATION = 0x1000;

    /** @var FFI|false|null */
    private static $user32 = null;
    /** @var FFI|null */
    private static $kernel32 = null;
    private static $exeCache = [];

    public static function available(): bool
    {
        if (self::$user32 === null) {
            self::$user32 = false;
            if (!class_exists('FFI')) return false;
            try {
                // az ablak- és folyamatazonosítókat egész számként kezeljük (64 bites Windowson ugyanúgy adódnak át)
                $user32 = FFI::cdef('
                    typedef uintptr_t HWND;
                    HWND FindWindowExW(HWND hWndParent, HWND hWndChildAfter, const uint16_t *lpszClass, const uint16_t *lpszWindow);
                    int IsWindowVisible(HWND hWnd);
                    int IsIconic(HWND hWnd);
                    HWND GetWindow(HWND hWnd, uint32_t uCmd);
                    int32_t GetWindowLongW(HWND hWnd, int nIndex);
                    int GetWindowTextW(HWND hWnd, uint16_t *lpString, int nMaxCount);
                    int GetClassNameW(HWND hWnd, uint16_t *lpClassName, int nMaxCount);
                    uint32_t GetWindowThreadProcessId(HWND hWnd, uint32_t *lpdwProcessId);
                    HWND GetForegroundWindow(void);
                    int AttachThreadInput(uint32_t idAttach, uint32_t idAttachTo, int fAttach);
                    int SetForegroundWindow(HWND hWnd);
                    int BringWindowToTop(HWND hWnd);
                    int ShowWindow(HWND hWnd, int nCmdShow);
                    void SwitchToThisWindow(HWND hWnd, int fAltTab);
                    int IsGUIThread(int bConvert);
                ', 'user32.dll');
                $kernel32 = FFI::cdef('
                    typedef uintptr_t HANDLE;
                    uint32_t GetCurrentThreadId(void);
                    HANDLE OpenProcess(uint32_t dwDesiredAccess, int bInheritHandle, uint32_t dwProcessId);
                    int QueryFullProcessImageNameW(HANDLE hProcess, uint32_t dwFlags, uint16_t *lpExeName, uint32_t *lpdwSize);
                    int CloseHandle(HANDLE hObject);
                ', 'kernel32.dll');
            } catch (Throwable $e) {   // nincs FFI, vagy nem Windows
                return false;
            }
            $user32->IsGUIThread(1);   // az AttachThreadInput-hoz saját üzenetsor kell
            self::$user32 = $user32;
            self::$kernel32 = $kernel32;
        }
        return self::$user32 !== false;
    }

    /**
     * A látható főablakok (nem eszköz- vagy párbeszédablak, van címe) Z-sorrendben, felülről:
     * ablakazonosító => ['class' => ..., 'title' => ..., 'pid' => ..., 'exe' => 'chrome.exe']
     */
    public static function windows(): array
    {
        if (!self::available()) return [];
        $u = self::$user32;
        $titleBuf = $u->new('uint16_t[512]');
        $classBuf = $u->new('uint16_t[256]');
        $pid = $u->new('uint32_t');
        $list = [];
        $hwnd = 0;
        for ($i = 0; $i < 10000; $i++) {
            $hwnd = (int)$u->FindWindowExW(0, $hwnd, null, null);
            if ($hwnd === 0) break;
            if (!$u->IsWindowVisible($hwnd) || $u->GetWindow($hwnd, self::GW_OWNER) !== 0
                || ($u->GetWindowLongW($hwnd, self::GWL_EXSTYLE) & self::WS_EX_TOOLWINDOW)) {
                continue;
            }
            $length = $u->GetWindowTextW($hwnd, $titleBuf, 512);
            if ($length <= 0) continue;
            $classLength = $u->GetClassNameW($hwnd, $classBuf, 256);
            $u->GetWindowThreadProcessId($hwnd, FFI::addr($pid));
            $list[$hwnd] = [
                'class' => self::utf8($classBuf, $classLength),
                'title' => self::utf8($titleBuf, $length),
                'pid' => (int)$pid->cdata,
                'exe' => self::exe((int)$pid->cdata),
            ];
        }
        return $list;
    }

    /** A látható főablakok azonosítói (gyors pillanatkép az indítás elé). */
    public static function handles(): array
    {
        if (!self::available()) return [];
        $list = [];
        $hwnd = 0;
        for ($i = 0; $i < 10000; $i++) {
            $hwnd = (int)self::$user32->FindWindowExW(0, $hwnd, null, null);
            if ($hwnd === 0) break;
            if (self::$user32->IsWindowVisible($hwnd)) $list[] = $hwnd;
        }
        return $list;
    }

    /** A folyamatazonosítók újrahasznosulnak: új figyelés előtt elfelejtjük a korábbi program-neveket. */
    public static function forgetProcesses(): void
    {
        self::$exeCache = [];
    }

    /**
     * A $hint szerinti ablak kiválasztása. $before: az indítás előtt is meglévő ablakok, $age: az indítás óta
     * eltelt idő. Az új ablak mindig jó; egy régi csak 'existingAfter' másodperc után, és ha a címe is egyezik
     * (ha a program egy már nyitott ablakát használja, pl. a böngésző új lapot nyit benne).
     */
    public static function pick(array $windows, array $before, array $hint, float $age, array $excludePids = [], array $excludeTitles = []): ?int
    {
        $existing = null;
        foreach ($windows as $id => $window) {
            if (in_array($window['pid'], $excludePids, true) || in_array($window['title'], $excludeTitles, true)) continue;
            if (!empty($hint['exe']) && strcasecmp($window['exe'], $hint['exe']) !== 0) continue;
            if (!empty($hint['class']) && strcasecmp($window['class'], $hint['class']) !== 0) continue;
            if (!isset($before[$id])) return (int)$id;
            if ($existing === null && (empty($hint['title']) || self::contains($window['title'], (string)$hint['title']))) {
                $existing = (int)$id;
            }
        }
        $after = $hint['existingAfter'] ?? null;
        return ($existing !== null && $after !== null && $age >= (float)$after) ? $existing : null;
    }

    /** Az ablak előtérbe hozása; visszaad: sikerült-e. */
    public static function activate(int $id): bool
    {
        if (!self::available()) return false;
        $u = self::$user32;
        if ($u->IsIconic($id)) $u->ShowWindow($id, self::SW_RESTORE);
        if ((int)$u->GetForegroundWindow() === $id) return true;

        // a vezérlőpult (az előtérben lévő ablak) szálához kapcsolódva a Windows engedi a váltást
        $foreground = (int)$u->GetForegroundWindow();
        $other = $foreground === 0 ? 0 : (int)$u->GetWindowThreadProcessId($foreground, null);
        $self = (int)self::$kernel32->GetCurrentThreadId();
        $attached = $other !== 0 && $other !== $self && $u->AttachThreadInput($self, $other, 1);
        $u->BringWindowToTop($id);
        $u->SetForegroundWindow($id);
        if ($attached) $u->AttachThreadInput($self, $other, 0);

        if ((int)$u->GetForegroundWindow() !== $id) $u->SwitchToThisWindow($id, 1);   // ahogy az Alt+Tab is vált
        return (int)$u->GetForegroundWindow() === $id;
    }

    private static function exe(int $pid): string
    {
        if (!isset(self::$exeCache[$pid])) {
            $name = '';
            $k = self::$kernel32;
            $process = (int)$k->OpenProcess(self::PROCESS_QUERY_LIMITED_INFORMATION, 0, $pid);
            if ($process !== 0) {
                $buffer = $k->new('uint16_t[1024]');
                $size = $k->new('uint32_t');
                $size->cdata = 1024;
                if ($k->QueryFullProcessImageNameW($process, 0, $buffer, FFI::addr($size))) {
                    $name = strtolower(basename(str_replace('\\', '/', self::utf8($buffer, (int)$size->cdata))));
                }
                $k->CloseHandle($process);
            }
            self::$exeCache[$pid] = $name;
        }
        return self::$exeCache[$pid];
    }

    private static function utf8($buffer, int $length): string
    {
        if ($length <= 0) return '';
        $raw = FFI::string($buffer, $length * 2);
        return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string)iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return function_exists('mb_stripos') ? mb_stripos($haystack, $needle, 0, 'UTF-8') !== false : stripos($haystack, $needle) !== false;
    }
}
