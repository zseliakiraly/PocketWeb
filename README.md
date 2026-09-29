# PocketWeb
verzió 3.0

A PocketWeb egy hordozható, faék egyszerűségű webfejlesztő eszköz. Telepítés nélkül, rendszergazdai jogok nélkül futtatható. Csak kibontod és már használhatod is!

Működik **Windowson**, a nagyobb **Linux** disztribúciókon (Ubuntu, Debian, Mint, Fedora, Arch, openSUSE…) és **macOS-en** is.

Mellékelt eszközök: php, node.js, Composer és Adminer

## Újdonságok a 3.0-ban

- **Terminál panel**: nincs több felugró fekete ablak. A projektek szervereinek (`php artisan serve`, `php -S`) és a Laravel / WordPress telepítőknek a kimenete a Vezérlőpult alján, VS Code-szerű panelen jelenik meg, színesen, külön fülön minden folyamat.
- **Linux és macOS** támogatás (`indito.sh`, `indito.command`).
- **Kilépés gomb**, a futó szerverek állapota a kártyákon (indul / fut / máshonnan indítva / leállítva).
- A Vezérlőpult internet nélkül is működik (helyi Tailwind), a szerkesztőket (VS Code, PhpStorm…) a szokásos telepítési helyeken is megkeresi.
- Biztonság: más weboldalak nem tudnak a háttérben projekteket törölni vagy programot indítani a gépeden.

## Indítás és használat

### Windows

Kattints duplán a gyökérmappában található **indito.bat** fájlra.

Egy pillanatra megjelenik egy ablak, ami ellenőrzi a környezetet, majd eltűnik: a PocketWeb a háttérben fut, a Vezérlőpult pedig egy izolált Edge (ha nincs, Chrome / Brave) ablakban nyílik meg.

Ha végeztél, egyszerűen zárd be a Vezérlőpultot (vagy kattints a **Kilépés** gombra): a rendszer automatikusan leállít minden háttérfolyamatot.

> Ha a gépen a PowerShell le van tiltva, a PocketWeb a korábbi módon, egy nyitva maradó fekete ablakban fut. Ezt ne zárd be, amíg dolgozol!

### Linux

Nyiss egy terminált a PocketWeb mappájában, és indítsd:

```
sh indito.sh
```

Tipp: a `sh indito.sh --parancsikon` paranccsal a PocketWeb bekerül az alkalmazások menüjébe, onnantól ikonnal is indítható.

PHP-ból a rendszerre telepítettet használja, ha a `rendszer/php` mappában nincs saját (hordozható) PHP. Telepítés, ha még nincs:

| Disztribúció | Parancs |
|---|---|
| Ubuntu, Debian, Mint | `sudo apt install php-cli php-sqlite3 php-mbstring php-xml php-curl php-zip unzip` |
| Fedora | `sudo dnf install php-cli php-pdo php-mbstring php-xml php-process php-pecl-zip unzip` |
| Arch, Manjaro | `sudo pacman -S php php-sqlite unzip` (majd a `/etc/php/php.ini`-ben: `extension=pdo_sqlite`) |
| openSUSE | `sudo zypper install php8 php8-sqlite php8-mbstring php8-curl php8-zip php8-pcntl unzip` |

Hordozható megoldás: egy statikus PHP (https://static-php.dev) a `rendszer/php` mappába másolva, telepítés nélkül működik.

A Vezérlőpult külön ablakban nyílik, ha van Chrome, Chromium, Edge vagy Brave a gépen. Ha csak Firefox van, az alapértelmezett böngészőben nyílik meg; ilyenkor a **Kilépés** gombbal (vagy a terminálban Ctrl+C-vel) állítható le.

### macOS

Kattints duplán az **indito.command** fájlra. (Első alkalommal: jobb klikk → Megnyitás, mert a letöltött fájlokat a macOS alapból blokkolja. Ha a letöltött PHP/Node sem indul, a PocketWeb mappájában: `xattr -dr com.apple.quarantine .`)

PHP: `brew install php`, vagy statikus PHP (https://static-php.dev) a `rendszer/php` mappába. Node.js: `brew install node`, vagy a hivatalos macOS binary a `rendszer/node` mappába.

## A Vezérlőpult használata
___________________________
1. Új projekt létrehozása

A jobb felső sarokban található Új Projekt gombra kattintva válaszd ki a kívánt technológiát.

Add meg a projekt nevét (szóközök és ékezetek nélkül, pl. elso-webshop).

A rendszer létrehozza a projektek/ mappában a szükséges fájlokat. Laravel és WordPress esetén a telepítés a háttérben fut (1-2 perc), a folyamata a **Terminál panelen** követhető. Amíg tart, a kártyán „Telepítés folyamatban…” látszik; a végéről értesítés érkezik.
___________________________
2. Kártyák és Műveletek

Minden létrehozott projekted egy kártyaként jelenik meg. A kártya alján az alábbi gyorsgombokat találod:

Mappa megnyitása: közvetlenül megnyitja a projekt fájljait a fájlkezelőben (Windows Intéző, Finder, Fájlok…).

Parancssor: külön parancssor ablakot nyit a projekt mappájában (a php, composer, node és npm parancsok itt azonnal működnek!).

Megnyitás szerkesztőben: a projektet megnyitja a beállításokban kiválasztott programban (VS Code az alapértelmezett).

Megnyitás böngészőben: megnyitja az elkészült weboldalt (statikus fájloknál helyiként, PHP/Laravel esetén a 127.0.0.1-es címen, ha fut a szerver).

Adatbázis megnyitása: külön ablakban paraméterezve megnyitja az Adminer felületét. A jelszót a Vezérlőpultból tudod kimásolni.

Szerver futtatása/leállítása: Laravel és WordPress projekteknél saját webszervert indít. A szerver kimenete (a beérkező kérések, a hibák) a Terminál panelen jelenik meg; a kártya állapotjelvényére kattintva is megnyílik. Ha a projekt portján máshonnan (pl. a parancssorból) indítottál szervert, a kártyán „máshonnan indítva” látszik, és innen is leállíthatod.

Törlés: törli a projekt mappáját a fájlokkal együtt (előtte leállítja a projekt szerverét).

Ezeken túl a kártya tetején lehetőség van snapshotot csinálni a weboldalakról, amit betesz a kártya hátterének. Az új kép felülírja a korábbit!
___________________________
3. Terminál panel

A Vezérlőpult alján, a VS Code termináljához hasonló panel. Minden elindított szervernek és telepítőnek saját füle van, a fül előtti pötty mutatja az állapotát (zöld: fut, sárga: indul / leáll, piros: hibával állt le).

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

Laravel adatbázis helye: .../PocketWeb/projektek/LaraProjekted/database/database.sqlite
Wordpress adatbázis helye: .../PocketWeb/projektek/WpProjekted/wp-content/database/.ht.sqlite
___________________________
5. Beállítások

A beállításokban három dolgot lehet csinálni:

- Kiválasztani a témaszínt a default narancs helyett.

- Kiválasztani a külső szerkesztőt (VS Code, Sublime Text, Notepad++ – csak Windowson –, PhpStorm, WebStorm). A PocketWeb a szokásos telepítési helyeken és a PATH-ban is keresi őket.

- Linkek a csomagolt php, nodejs, composer és adminer letöltőoldalaihoz (az aktuális operációs rendszernek megfelelően).

## Hibaelhárítás

- A PocketWeb naplója: `rendszer/.run/pocketweb.log` (Windowson a háttérben futó folyamat üzenetei is ide kerülnek).
- Ha a 8181-es port foglalt, másik porttal is indítható: a `POCKETWEB_PORT` környezeti változóval (pl. Linuxon `POCKETWEB_PORT=8282 sh indito.sh`).
- A Vezérlőpult böngészője a `POCKETWEB_BROWSER` környezeti változóval választható (a böngésző útvonala, vagy `none` az alapértelmezett böngészőhöz).
- Ha a Laravelhez szükséges PHP kiterjesztések hiányoznak, azt a Vezérlőpult bal alsó sarka jelzi, a telepítő pedig kiírja a Terminál panelre.

## Felépítés (fejlesztőknek)

- `indito.bat` / `indito.sh` / `indito.command` → elindítja a **felügyelőt** (`rendszer/pocketweb.php`).
- A felügyelő indítja a Vezérlőpult szerverét (`php -S 127.0.0.1:8181`, útválasztó: `rendszer/router.php`), megnyitja a böngészőablakot, futtatja a háttérfeladatokat (szerverek, telepítők), és a Vezérlőpult bezárásakor mindent leállít.
- A Vezérlőpult (`rendszer/index.html`) az `api.php`-n keresztül kéri a feladatokat; az `api.php` ezeket üzenetsoron (`rendszer/.run/queue`) adja át a felügyelőnek, a kimenetük pedig a `rendszer/.run/jobs/` alatti naplófájlokból jut el a Terminál panelre.
- Közös kód: `rendszer/lib/` (operációs rendszer függő műveletek: `Platform.php`), telepítők: `rendszer/tasks/`, terminál megjelenítő: `rendszer/vendor/xterm` (xterm.js, MIT licenc).
