# PocketWeb
verzió 3.0 – Windows változat

A PocketWeb egy hordozható, faék egyszerűségű webfejlesztő eszköz Windowsra. Telepítés nélkül, rendszergazdai jogok nélkül futtatható. Csak kibontod és már használhatod is!

Mellékelt eszközök: php, node.js, Composer és Adminer

> A GitHubon a `rendszer\php` és a `rendszer\node` mappa csak a leírást tartalmazza: a php.exe és a node.exe a kész (letölthető) csomagban van benne. Ha a forrásból állítod össze, lásd a mappákban lévő `DOWNLOAD.me` fájlokat.

## Újdonságok a 3.0-ban

- **Terminál panel**: nincs több felugró fekete ablak. A projektek szervereinek (`php artisan serve`, `php -S`) és a Laravel / WordPress telepítőknek a kimenete a Vezérlőpult alján, VS Code-szerű panelen jelenik meg, színesen, külön fülön minden folyamat. A telepítők végén nem kell gombot nyomni: a fül jelzi, hogy kész, és értesítés is érkezik.
- **Névjegy** ablak a verziókkal és **visszajelzés küldésével** a feedback@zseli.hu címre: a napló csatolmányként megy, ha a levelezőprogram tudja (klasszikus Outlook, Thunderbird), különben a kivonata kerül a levélbe.
- Az Intéző, a böngésző és a szerkesztő **előtérben nyílik meg**, nem a Vezérlőpult mögött.
- **Kilépés gomb**, a futó szerverek állapota a kártyákon (indul / fut / máshonnan indítva / leállítva).
- A Vezérlőpult internet nélkül is működik (helyi Tailwind), a szerkesztőket (VS Code, PhpStorm…) a szokásos telepítési helyeken is megkeresi.
- Biztonság: más weboldalak nem tudnak a háttérben projekteket törölni vagy programot indítani a gépeden.
- Kíméletes a géppel: a Vezérlőpult csak akkor kérdezi le a háttérfolyamatokat, ha fut valami (szerver, telepítés), és a projektek listáját csak induláskor, a Frissítés gombra és egy új projekt elkészülésekor tölti újra.

## Indítás és használat

Indítás: kattints duplán a gyökérmappában található **indito.bat** fájlra.

Egy pillanatra megjelenik egy ablak, ami ellenőrzi a környezetet, majd eltűnik: a PocketWeb a háttérben fut, a Vezérlőpult pedig egy izolált Edge (ha nincs, Chrome / Brave) ablakban nyílik meg.

Ha végeztél, egyszerűen zárd be a Vezérlőpultot, vagy kattints a **Kilépés** gombra: a rendszer automatikusan leállít minden háttérfolyamatot, a Kilépés után a Vezérlőpult ablaka is bezárul.

> Ha a gépen a PowerShell le van tiltva, a PocketWeb a korábbi módon, egy nyitva maradó fekete ablakban fut. Ezt ne zárd be, amíg dolgozol!

## A Vezérlőpult használata
___________________________
1. Új projekt létrehozása

A jobb felső sarokban található Új Projekt gombra kattintva válaszd ki a kívánt technológiát.

Add meg a projekt nevét (szóközök és ékezetek nélkül, pl. elso-webshop).

A rendszer létrehozza a projektek\ mappában a szükséges fájlokat. Laravel és WordPress esetén a telepítés a háttérben fut (1-2 perc), a folyamata a **Terminál panelen** követhető. Amíg tart, a kártyán „Telepítés folyamatban…” látszik; a végéről értesítés érkezik, gombot nyomni nem kell.
___________________________
2. Kártyák és Műveletek

Minden létrehozott projekted egy kártyaként jelenik meg. A kártya alján az alábbi gyorsgombokat találod:

Mappa megnyitása: közvetlenül megnyitja a projekt fájljait a Windows Intézőben.

Parancssor: külön parancssor ablakot nyit a projekt mappájában (a php, composer, node és npm parancsok itt azonnal működnek!).

Megnyitás szerkesztőben: a projektet megnyitja a beállításokban kiválasztott programban (VS Code az alapértelmezett).

Megnyitás böngészőben: megnyitja az elkészült weboldalt az alapértelmezett böngészőben (statikus fájloknál az index.html-t, PHP/Laravel esetén a szerver címét, ha fut a szerver).

Adatbázis megnyitása: külön ablakban paraméterezve megnyitja az Adminer felületét. A jelszót a Vezérlőpultból tudod kimásolni.

Szerver futtatása/leállítása: Laravel és WordPress projekteknél saját webszervert indít. A szerver kimenete (a beérkező kérések, a hibák) a Terminál panelen jelenik meg; a kártya állapotjelvényére kattintva is megnyílik. Ha a projekt portján máshonnan (pl. a parancssorból) indítottál szervert, a kártyán „máshonnan indítva” látszik, és innen is leállíthatod.

Törlés: törli a projekt mappáját a fájlokkal együtt (előtte leállítja a projekt szerverét).

Ezeken túl a kártya tetején lehetőség van snapshotot csinálni a weboldalakról, amit betesz a kártya hátterének. Az új kép felülírja a korábbit!
___________________________
3. Terminál panel

A Vezérlőpult alján, a VS Code termináljához hasonló panel, ami a háttérben futó programok kimenetét mutatja (gépelni nem kell bele). Minden elindított szervernek és telepítőnek saját füle van, a fül előtti pötty mutatja az állapotát (zöld: fut, sárga: indul / leáll, piros: hibával állt le).

- Megnyitás / elrejtés: a fejléc Terminál gombja, az oldalsáv Terminál menüpontja vagy **Ctrl+`**.
- A panel magassága a felső szélénél húzva állítható.
- Gombok: folyamat leállítása, kimenet törlése, teljes méret, panel elrejtése.
- A fül bezárása (×) a még futó folyamatot leállítja.
- A kimenetben lévő linkekre (pl. `http://127.0.0.1:8000`) kattintva a weboldal a böngészőben nyílik meg.
___________________________
4. Adatbázis kezelése

A Vezérlőpulthoz van mellékelve az Adminer nevű adatbáziskezelő program. Menüből is megnyitható, de a projekteknél külön gomb is kikerült, hogy közvetlenül meg tudd nyitni az adatbázisokat.

A manuális megnyitáshoz a szükséges adatok:

System: SQLite
User: root
Password: (rákattintással másolható!)
Database: (az adatbázis fájl helye)

Laravel adatbázis helye: ...\PocketWeb\projektek\LaraProjekted\database\database.sqlite
Wordpress adatbázis helye: ...\PocketWeb\projektek\WpProjekted\wp-content\database\.ht.sqlite
___________________________
5. Beállítások

A beállításokban három dolgot lehet csinálni:

- Kiválasztani a témaszínt a default narancs helyett.

- Kiválasztani a külső szerkesztőt (VS Code, Sublime Text, Notepad++, PhpStorm, WebStorm). A PocketWeb a szokásos telepítési helyeken és a PATH-ban is keresi őket.

- Linkek a csomagolt php, nodejs, composer és adminer letöltőoldalaihoz.
___________________________
6. Névjegy és visszajelzés

A Névjegy ablakban látszanak a PocketWeb és az összetevők (PHP, Node.js, Composer, Adminer) verziói, valamint innen küldhetsz visszajelzést a feedback@zseli.hu címre:

- Írd le, mi történt, és ha választ szeretnél, add meg a neved vagy az e-mail címed.
- A **Napló mellékelése** bepipálásával a PocketWeb egy ZIP fájlt készít a `visszajelzes\` mappába (a PocketWeb, a szerverek és a telepítők naplója, a verziók és a projektek neve).
- A **Levél megírása** gomb a gépen beállított levelezőprogramban nyitja meg a kitöltött levelet:
  - ha ez a klasszikus Outlook vagy a Thunderbird, a napló ZIP **csatolva** van, csak el kell küldeni;
  - más levelezőknél (új Outlook, Gmail a böngészőben) a napló **kivonata** (a hibával leállt telepítők utolsó sorai, a PocketWeb naplójának vége) kerül a levél végére. Mivel egy levél-linkbe csak rövid szöveg fér, a kivonat a vágólapra kerül, a levélben pedig egy sor jelzi, hova kell beilleszteni (**Ctrl+V**).
- A **Gmail** és az **Outlook (web)** gomb a böngészőben nyitja meg a levelet, a **Szöveg másolása** a teljes levelet (a kivonattal együtt) a vágólapra teszi. A teljes napló ZIP-et a **Napló mutatása** gombbal kijelölve meg is mutatja az Intéző, onnan a levélbe húzható.

A levelet mindig te küldöd el, a saját fiókodból: a PocketWeb semmit nem küld el a tudtod nélkül.

## Hibaelhárítás

- A PocketWeb naplója: `rendszer\.run\pocketweb.log` (a háttérben futó folyamat üzenetei ide kerülnek).
- Ha a 8181-es port foglalt, másik porttal is indítható a `POCKETWEB_PORT` környezeti változóval (pl. parancssorból: `set POCKETWEB_PORT=8282` majd `indito.bat`).
- A Vezérlőpult böngészője a `POCKETWEB_BROWSER` környezeti változóval választható (a böngésző .exe útvonala, vagy `none` az alapértelmezett böngészőhöz).
- Ha a Laravelhez szükséges PHP kiterjesztések hiányoznak, azt a Vezérlőpult bal alsó sarka jelzi, a telepítő pedig kiírja a Terminál panelre (a `rendszer\php\php.ini`-ben kell bekapcsolni őket).
- Ha a megnyitott ablakok (Intéző, böngésző, szerkesztő) a Vezérlőpult mögött jelennek meg, vagy a visszajelzéshez nem csatolódik a napló: ellenőrizd, hogy megvan-e a `rendszer\php\ext\php_ffi.dll` (a PHP ZIP része, ne töröld ki).

## Felépítés (fejlesztőknek)

- `indito.bat` → a mellékelt `rendszer\php\php.exe`-vel elindítja a **felügyelőt** (`rendszer\pocketweb.php`), rejtett ablakban (ha megvan a `php_ffi.dll`, `-d extension=ffi` kapcsolóval).
- A felügyelő indítja a Vezérlőpult szerverét (`php -S 127.0.0.1:8181`, útválasztó: `rendszer\router.php`), megnyitja a böngészőablakot, futtatja a háttérfeladatokat (szerverek, telepítők), és a Vezérlőpult bezárásakor mindent leállít.
- A Vezérlőpult (`rendszer\index.html`) az `api.php`-n keresztül kéri a feladatokat; az `api.php` ezeket üzenetsoron (`rendszer\.run\queue`) adja át a felügyelőnek, a kimenetük pedig a `rendszer\.run\jobs\` alatti naplófájlokból jut el a Terminál panelre. A beépített PHP szerver egyszerre csak egy kérést szolgál ki, ezért a Vezérlőpult csak akkor kérdez le, ha fut valami.
- A programokat (Intéző, böngésző, szerkesztő) a felügyelő indítja; mivel háttérfolyamat, a Windows nem engedné az ablakukat előtérbe hozni. Ezért a felügyelő megkeresi az új ablakot, és a user32.dll-lel (PHP FFI) előre hozza (`rendszer\lib\WinFocus.php`). URL-t és fájlt a `start` paranccsal nyit meg.
- A visszajelzés napló csatolása a Windows Simple MAPI felületével történik (`rendszer\lib\Mapi.php`, `rendszer\tasks\send-mail.php`), ugyanígy, ahogy az Intéző „Küldés → Levél címzettje” parancsa.
- Közös kód: `rendszer\lib\` (a Windows-műveletek: `Platform.php`, visszajelzés: `Feedback.php`), telepítők: `rendszer\tasks\`, terminál megjelenítő: `rendszer\vendor\xterm` (xterm.js, MIT licenc).
