<?php
/*
 * Visszajelzés levél megnyitása a levelezőprogramban, a napló csatolva (Simple MAPI, lásd lib\Mapi.php).
 * A felügyelő futtatja rejtett feladatként; a hívás addig tart, amíg a levél ablaka nyitva van.
 *
 * Paraméter: a levél adatait tartalmazó JSON fájl (Mapi::saveMessage).
 * Kilépési kód: 0 = elküldve (vagy átadva a levelezőprogramnak), 1 = bezárva küldés nélkül,
 *               2 = a levelezőprogram hibát jelzett, 3 = a MAPI nem érhető el (nincs FFI vagy MAPI32.dll).
 * Az utolsó kiírt sor a vezérlőpulton megjelenő üzenet; hiba esetén a vezérlőpult mailto: linkre vált.
 */
require __DIR__ . '/common.php';

$mail = Jobs::readJson((string)($argv[1] ?? ''));
if (!is_array($mail) || empty($mail['to']) || !isset($mail['subject'], $mail['body'])) fail('A levél adatai nem találhatók.', 2);
$client = (string)($mail['client'] ?? 'levelezőprogram');
$attachment = isset($mail['attachment']) ? (string)$mail['attachment'] : null;
if ($attachment !== null && !is_file($attachment)) fail('A napló fájl nem található: ' . $attachment, 2);
if (!class_exists('FFI')) fail('A csatoláshoz a PHP FFI kiterjesztése kell (rendszer\\php\\ext\\php_ffi.dll).', 3);

step('Levél megnyitása: ' . $client . ($attachment !== null ? ', csatolva: ' . basename($attachment) : ''));
try {
    $code = Mapi::sendMail((string)$mail['to'], (string)$mail['subject'], (string)$mail['body'], $attachment);
} catch (Throwable $e) {   // FFI\Exception: nincs MAPI32.dll, vagy nincs benne MAPISendMailW
    warn('Részletek: ' . $e->getMessage());
    fail('A levelezőprogram nem tudja csatolni a naplót.', 3);
}

if ($code === Mapi::SUCCESS) {
    done('A levél elküldve.');
} elseif ($code === Mapi::USER_ABORT) {
    warn('A levél küldés nélkül bezárult.');
    exit(1);
} else {
    fail('A(z) ' . $client . ' nem tudta megnyitni a levelet: ' . Mapi::errorText($code) . '.', 2);
}
