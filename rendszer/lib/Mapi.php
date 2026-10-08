<?php
/*
 * PocketWeb – levél megnyitása csatolmánnyal a gépen beállított levelezőprogramban.
 *
 * A Windows "Simple MAPI" felületét használja (MAPISendMailW a MAPI32.dll-ben) – ugyanezt hívja az Intéző
 * "Küldés → Levél címzettje" parancsa és a LibreOffice is. A klasszikus Outlook és a Thunderbird támogatja;
 * az új Outlook és a webes levelezők (Gmail, Outlook a böngészőben) nem: ilyenkor marad a mailto: link,
 * a napló kivonata pedig a levél szövegébe kerül.
 *
 * A hívás a PHP FFI kiterjesztésével történik, ezért csak parancssori PHP-ból (a tasks\send-mail.php
 * feladatból) működik: az FFI alapbeállítása (ffi.enable=preload) a webszerverben nem engedi, és a hívás
 * addig tart, amíg a levél ablaka nyitva van.
 */
class Mapi
{
    const MAPI_TO = 1;
    const MAPI_LOGON_UI = 0x1;
    const MAPI_DIALOG = 0x8;   // MAPI_DIALOG_MODELESS nem kell: a LibreOffice tapasztalata szerint az Outlook 2016 összeomlik tőle

    const SUCCESS = 0;
    const USER_ABORT = 1;

    // ------------------------------------------------------------------
    // Melyik levelezőprogram tudja csatolni a naplót?
    // ------------------------------------------------------------------

    /**
     * A levelezőprogram neve (pl. "Microsoft Outlook"), ha a naplót csatolni tudja, egyébként null.
     * Feltételek: be van állítva MAPI levelezőprogramként, a mailto: linkeket is ő nyitja meg (aki Gmailt
     * használ, annak ne egy beállítatlan Outlook nyíljon meg), és a MAPI DLL-je ugyanolyan bites, mint a PHP
     * (64 bites PHP-ba a 32 bites Outlook DLL-je nem tölthető be).
     */
    public static function client(): ?string
    {
        $name = Platform::regValue('HKCU\\Software\\Clients\\Mail') ?? Platform::regValue('HKLM\\SOFTWARE\\Clients\\Mail');
        if ($name === null) return null;

        $handler = Platform::regValue('HKCU\\Software\\Microsoft\\Windows\\Shell\\Associations\\UrlAssociations\\mailto\\UserChoice', 'ProgId')
            ?? Platform::regValue('HKCR\\mailto\\shell\\open\\command');
        if ($handler === null || !self::sameProgram($name, $handler)) return null;

        foreach (['HKCU\\Software\\Clients\\Mail\\', 'HKLM\\SOFTWARE\\Clients\\Mail\\'] as $root) {
            $dll = Platform::regValue($root . $name, 'DLLPathEx') ?? Platform::regValue($root . $name, 'DLLPath');
            if ($dll === null) continue;
            $machine = self::peMachine(self::expandEnv($dll));
            if ($machine !== null && $machine !== self::phpMachine()) return null;
            break;
        }
        return $name;
    }

    /** A levelezőprogram neve és a mailto: kezelője (ProgId vagy parancs) ugyanarra a programra utal-e. */
    public static function sameProgram(string $client, string $handler): bool
    {
        $handler = strtolower($handler);
        foreach (preg_split('/[^a-z0-9]+/', strtolower($client)) as $word) {
            if (strlen($word) >= 4 && !in_array($word, ['microsoft', 'mozilla', 'windows', 'live', 'mail', 'client'], true)
                && strpos($handler, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /** A DLL processzor-típusa a PE fejlécből (0x8664: 64 bites, 0x14c: 32 bites), vagy null, ha nem olvasható. */
    public static function peMachine(string $file): ?int
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) return null;
        $machine = null;
        $dos = (string)fread($fh, 64);
        if (strlen($dos) === 64 && substr($dos, 0, 2) === 'MZ') {
            $offset = unpack('V', substr($dos, 60, 4))[1];
            if ($offset >= 64 && $offset < 65536 && fseek($fh, $offset) === 0) {
                $pe = (string)fread($fh, 6);
                if (strlen($pe) === 6 && substr($pe, 0, 4) === "PE\0\0") $machine = unpack('v', substr($pe, 4, 2))[1];
            }
        }
        fclose($fh);
        return $machine;
    }

    private static function phpMachine(): int
    {
        return PHP_INT_SIZE === 8 ? 0x8664 : 0x14c;
    }

    private static function expandEnv(string $path): string
    {
        return trim(preg_replace_callback('/%([^%]+)%/', function ($m) {
            $value = getenv($m[1]);
            return $value !== false ? $value : $m[0];
        }, $path), '"');
    }

    // ------------------------------------------------------------------
    // A levél átadása a send-mail feladatnak
    // ------------------------------------------------------------------

    /** A levél adatai a feladatnak (rendszer\.run\mail-<azonosító>.json); a korábbiakat törli. */
    public static function saveMessage(array $mail): string
    {
        foreach (glob(pw_path(PW_RUN, 'mail-*.json')) ?: [] as $old) @unlink($old);
        @mkdir(PW_RUN, 0777, true);
        $token = bin2hex(random_bytes(8));
        if (!Jobs::writeJson(pw_path(PW_RUN, 'mail-' . $token . '.json'), $mail)) {
            throw new RuntimeException('Nem sikerült elmenteni a levél adatait.');
        }
        return $token;
    }

    /** A saveMessage() által mentett fájl, vagy null (érvénytelen azonosító, vagy már törölve). */
    public static function messageFile(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) return null;
        $file = pw_path(PW_RUN, 'mail-' . $token . '.json');
        return is_file($file) ? $file : null;
    }

    // ------------------------------------------------------------------
    // A hívás (csak parancssori PHP-ból, FFI-vel)
    // ------------------------------------------------------------------

    /**
     * A levelezőprogram új levél ablakának megnyitása kitöltve, csatolmánnyal (MAPI_DIALOG: a felhasználó küldi el).
     * A hívás a legtöbb levelezőprogramnál addig tart, amíg a levél ablaka nyitva van.
     * @return int MAPI eredménykód: 0 = elküldve, 1 = bezárva küldés nélkül, egyéb = hiba
     * @throws FFI\Exception ha a MAPI32.dll vagy a MAPISendMailW nem érhető el
     */
    public static function sendMail(string $to, string $subject, string $body, ?string $attachment): int
    {
        $stdcall = PHP_INT_SIZE === 4 ? '__stdcall ' : '';   // 64 bites Windowson egyféle hívási konvenció van
        $ffi = FFI::cdef('
            typedef struct {
                uint32_t ulReserved; uint32_t ulRecipClass;
                uint16_t *lpszName; uint16_t *lpszAddress;
                uint32_t ulEIDSize; void *lpEntryID;
            } MapiRecipDescW;
            typedef struct {
                uint32_t ulReserved; uint32_t flFlags; uint32_t nPosition;
                uint16_t *lpszPathName; uint16_t *lpszFileName; void *lpFileType;
            } MapiFileDescW;
            typedef struct {
                uint32_t ulReserved;
                uint16_t *lpszSubject; uint16_t *lpszNoteText; uint16_t *lpszMessageType;
                uint16_t *lpszDateReceived; uint16_t *lpszConversationID;
                uint32_t flFlags; MapiRecipDescW *lpOriginator;
                uint32_t nRecipCount; MapiRecipDescW *lpRecips;
                uint32_t nFileCount; MapiFileDescW *lpFiles;
            } MapiMessageW;
            uint32_t ' . $stdcall . 'MAPISendMailW(uintptr_t lhSession, uintptr_t ulUIParam, MapiMessageW *lpMessage, uint32_t flFlags, uint32_t ulReserved);
        ', 'MAPI32.dll');

        $keep = [];   // a C oldali szövegeknek a hívás végéig meg kell maradniuk
        $wide = function (string $text) use ($ffi, &$keep) {
            $utf16 = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')
                : iconv('UTF-8', 'UTF-16LE//IGNORE', $text);
            $utf16 .= "\0\0";
            $buffer = $ffi->new('uint16_t[' . (strlen($utf16) >> 1) . ']');
            FFI::memcpy($buffer, $utf16, strlen($utf16));
            $keep[] = $buffer;
            return FFI::cast('uint16_t*', FFI::addr($buffer));
        };

        $recipient = $ffi->new('MapiRecipDescW');
        $recipient->ulRecipClass = self::MAPI_TO;
        $recipient->lpszName = $wide($to);
        $recipient->lpszAddress = $wide('SMTP:' . $to);

        $message = $ffi->new('MapiMessageW');
        $message->lpszSubject = $wide($subject);
        $message->lpszNoteText = $wide(str_replace(["\r\n", "\n"], ["\n", "\r\n"], $body));
        $message->nRecipCount = 1;
        $message->lpRecips = FFI::addr($recipient);

        if ($attachment !== null) {
            $file = $ffi->new('MapiFileDescW');
            $file->nPosition = 0xFFFFFFFF;   // nem a szövegbe, hanem csatolmányként
            $file->lpszPathName = $wide($attachment);
            $file->lpszFileName = $wide(basename(str_replace('\\', '/', $attachment)));   // az Outlook 2013 nem fogad el NULL-t
            $message->nFileCount = 1;
            $message->lpFiles = FFI::addr($file);
        }

        return (int)$ffi->MAPISendMailW(0, 0, FFI::addr($message), self::MAPI_LOGON_UI | self::MAPI_DIALOG, 0);
    }

    /** A MAPI hibakód magyarul. */
    public static function errorText(int $code): string
    {
        $texts = [
            2 => 'általános hiba', 3 => 'nem sikerült bejelentkezni a levelezőprogramba', 4 => 'megtelt a lemez',
            5 => 'kevés a memória', 6 => 'hozzáférés megtagadva', 9 => 'túl sok csatolmány',
            11 => 'a csatolmány nem található', 12 => 'a csatolmány nem nyitható meg', 14 => 'ismeretlen címzett',
            18 => 'túl hosszú a levél szövege', 23 => 'hálózati hiba',
            26 => 'a levelezőprogram nem támogatja ezt a műveletet', 27 => 'a levelezőprogram nem támogatja az ékezetes szöveget',
        ];
        return ($texts[$code] ?? 'ismeretlen hiba') . ' (MAPI hibakód: ' . $code . ')';
    }
}
