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
- **Hol a hiba?** Ha egy Laravel projektben szintaxishiba van, vagy egy oldal hibát dob, a Terminál panel megmutatja a fájlt, a sort és a kódrészletet; a hiba helyére kattintva a szerkesztő a hibás sornál nyílik meg.
- **Választható böngésző** a projektekhez (rendszer alapértelmezett, Chrome, Firefox, Edge); a Vezérlőpult továbbra is a saját Edge ablakában fut.
- **Import / Export**: a projektek egy vagy több projektet tartalmazó ZIP fájlba menthetők (pl. a hálózati meghajtóra), és onnan visszatölthetők – 7-Zip vagy WinZip nélkül.

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

Megnyitás szerkesztőben: a projektet megnyitja a beállításokban kiválasztott programban (VS Code az alapértelmezett). Az így megnyitott szerkesztő a PocketWeb PHP-ját, Composerét és Node.js-ét használja (lásd: [VS Code és a mellékelt PHP](#vs-code-és-a-mellékelt-php)).

Megnyitás böngészőben: megnyitja az elkészült weboldalt a beállításokban választott böngészőben (statikus fájloknál az index.html-t, PHP/Laravel esetén a szerver címét, ha fut a szerver).

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
- A kimenetben lévő linkekre (pl. `http://127.0.0.1:8000`) kattintva a weboldal a választott böngészőben nyílik meg.

**Hibakeresés (Laravel):** a szerver indításakor a PocketWeb gyorsan átnézi a projekt PHP fájljait (app, bootstrap, config, database, routes…), és ha valamelyikben szintaxishiba van, kiírja a fájlt, a sort és a hibás kódrészletet. Ha a szerver emiatt nem indul el, a kártyán „Hibával leállt” jelzés és értesítés jelenik meg az okkal. Futás közben a Laravel naplójába kerülő hibák (pl. egy kontrollerben vagy nézetben) is megjelennek a panelen, a helyükkel együtt – nem csak a böngészőben. A hiba helyére (pl. `routes\web.php, 8. sor`) kattintva a fájl a kiválasztott szerkesztőben, a hibás sornál nyílik meg.
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

A beállításokban négy dolgot lehet csinálni:

- Kiválasztani a témaszínt a default narancs helyett.

- Kiválasztani a külső szerkesztőt (VS Code, Sublime Text, Notepad++, PhpStorm, WebStorm). A PocketWeb a szokásos telepítési helyeken és a PATH-ban is keresi őket.

- Kiválasztani a böngészőt, amelyben a projektek (és a Terminál panel helyi linkjei) megnyílnak: a rendszer alapértelmezettje, Google Chrome, Mozilla Firefox vagy Microsoft Edge. A Vezérlőpult ettől függetlenül mindig a saját Edge ablakában fut.

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

___________________________
7. Import / Export (mentés a hálózati meghajtóra)

A fejléc **Import / Export** gombjával a projekteket egy ZIP fájlba mentheted (pl. a hálózati meghajtódra vagy egy pendrive-ra), és egy másik gépen – vagy a következő órán – visszatöltheted. Közvetlenül a hálózati meghajtón dolgozni lassú és kockázatos; így a projekt mindig a gépen (a PocketWeb mappájában) van, a hálózati meghajtóra csak a mentés kerül.

**Exportálás**
- Jelöld ki a menteni kívánt projekteket (akár többet is, vagy a **Mind** jelölőnégyzettel az összeset): mindegyik a saját mappájával kerül a ZIP-be.
- A **Tallózás…** gombbal válaszd ki a célmappát. A tallózó a gyorselérés mappáit (Asztal, Dokumentumok, Letöltések, OneDrive) és a meghajtókat mutatja – a csatlakoztatott hálózati meghajtókat is (pl. `H:`). Az **Új mappa** gombbal mappát is létrehozhatsz. Útvonalat be is írhatsz (pl. `H:\Mentések` vagy `\\szerver\megosztás\mappa`).
- A fájlnév alapból a projekt neve és az időpont (pl. `webshop_2026-11-02_1530.zip`), de átírhatod. Ha már van ilyen fájl, a PocketWeb rákérdez, felülírja-e.
- **Függőségek nélkül**: a `vendor` és a `node_modules` mappa kimarad, így a ZIP jóval kisebb és gyorsabban elkészül. Importáláskor a PocketWeb újratelepíti őket (`composer install`, `npm install`) – ehhez internet kell.
- A ZIP előbb a gépen készül el (`%TEMP%\PocketWeb`), és csak a kész fájl kerül át a célmappába: a hálózati meghajtón nem marad félkész fájl, és a korábbi, azonos nevű mentés csak a sikeres másolás után cserélődik le. A folyamat a Terminál panelen követhető; a végén az értesítés **Megmutatás** gombja kijelöli a fájlt az Intézőben.
- Ami kimarad (újra létrejön magától): a Laravel lefordított nézetei, munkamenetei, gyorsítótára és naplói, valamint a `public\storage` link.

**Importálás**
- Válaszd ki a ZIP fájlt (**Tallózás…**, vagy írd be az útvonalát): a PocketWeb kilistázza a benne lévő projekteket.
- Ha egy projekt már létezik, választhatsz: **Új néven** (pl. `webshop-2`, a név átírható), vagy **Felülírás** – ekkor a meglévő projekt tartalma törlődik, és a ZIP-ben lévő változat kerül a helyére (a PocketWeb előtte megerősítést kér, és leállítja a projekt szerverét).
- A ZIP-et a PocketWeb előbb átmásolja a gépre, és onnan bontja ki egy rejtett munkamappába; a kész projekt csak a végén kerül a helyére. Felülírásnál a régi projekt csak a sikeres csere után törlődik – ha közben megszakad (pl. kilépés), a következő indításkor visszakerül.
- Ha a projektnek függőségek kellenek (pl. függőségek nélkül exportálták, vagy a GitHubról letöltött Laravel projekt), a beüzemelés külön fülön folytatódik (`composer install`, `.env`, alkalmazás kulcs, SQLite adatbázis, `npm install`); addig a kártyán „Telepítés folyamatban…” látszik.
- Ha egy importált projekt portját a gépen már egy másik projekt használja, új portot kap; WordPressnél a PocketWeb az oldal címét is átírja az adatbázisban.
- Nem csak PocketWeb exportot lehet importálni: a ZIP-be tömörített projekt mappa, a projekt tartalma (ilyenkor a ZIP neve lesz a projekt neve) vagy az egész `projektek` mappa is működik.

## VS Code és a mellékelt PHP

A PocketWeb a saját, hordozható PHP-ját (`rendszer\php\php.exe`), Composerét és Node.js-ét használja, a gépre nem kell PHP-t telepíteni. Ahhoz, hogy a VS Code is ezeket használja, **mindig a PocketWebből nyisd meg a projektet**: a kártya **Megnyitás szerkesztőben** gombjával.

Ez a gomb úgy indítja el a VS Code-ot, hogy a keresési útvonal (PATH) elején a PocketWeb mappái vannak: `rendszer\bin` (composer), `rendszer\php` és `rendszer\node`. Így semmit nem kell beállítani, és működik:

- a PHP szintaxis-ellenőrzés (a hibák pirossal aláhúzva és a Problems / Problémák panelen);
- a VS Code termináljában a `php`, `php artisan …`, `composer`, `node` és `npm` parancs;
- a Laravel bővítmény (a `php` parancsot keresi).

Ha a VS Code-ot máshonnan indítod (Start menü, asztali ikon, „Open with Code”), nem tudja, hol van a PocketWeb PHP-ja: ilyenkor a jobb alsó sarokban a *„Cannot validate since a PHP installation could not be found…”* üzenet jelenik meg, a terminálban pedig a `php` parancs nem található (vagy a gépen lévő másik PHP indul el, pl. a XAMPP-é). Ha a projekt már nyitva volt így, zárd be teljesen a VS Code-ot, és nyisd meg újra a PocketWebből.

**Ellenőrzés:** nyiss új terminált a VS Code-ban (Terminal → New Terminal), és írd be:

```
where.exe php
php -v
```

A `where.exe php` első sora a PocketWeb PHP-ja legyen (`…\PocketWeb\rendszer\php\php.exe`). (PowerShellben a sima `where` mást jelent, ezért kell a `.exe`.)

**Tippek:**

- **PowerShell és npm:** ha a VS Code termináljában az `npm` parancsra *„…npm.ps1 cannot be loaded because running scripts is disabled on this system”* hibát kapsz, a Windows a PowerShell-szkripteket tiltja. Válts Parancssorra (a terminál **+** gombja melletti nyíl → *Command Prompt*), vagy írd az `npm` helyett: `npm.cmd`.
- **Intelephense** (népszerű PHP bővítmény): PHP-t nem igényel, de érdemes megadni neki a mellékelt PHP verzióját (a Névjegy ablakban látható), hogy aszerint ellenőrizze a kódot, pl. `"intelephense.environment.phpVersion": "8.4.0"`.

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
- Import / Export: a tallózást és a ZIP tartalmának beolvasását az `api.php` végzi (`rendszer\lib\Transfer.php`), a ZIP készítését és kibontását háttérfeladat (`rendszer\tasks\export.php`, `import.php`, a beüzemelést `setup-project.php`), a PHP zip kiterjesztésével. A ZIP gyökerében a projektek mappái vannak, mellettük a `pocketweb-export.json` (a projektek típusa, portja, a kihagyott függőségek).
- A Laravel szervert a `rendszer\tasks\serve.php` indítja (`php artisan serve`): előtte szintaxis-ellenőrzés, közben a `storage\logs` figyelése (`rendszer\lib\CodeCheck.php`).
- Közös kód: `rendszer\lib\` (a Windows-műveletek: `Platform.php`, visszajelzés: `Feedback.php`), telepítők: `rendszer\tasks\`, terminál megjelenítő: `rendszer\vendor\xterm` (xterm.js, MIT licenc).
