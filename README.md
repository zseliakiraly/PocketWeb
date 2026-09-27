# PocketWeb
verzió 2.3

A PocketWeb egy hordozható faék egyszerűségű webfejlesztő eszköz. Telepítés nélkül, rendszergazdai jogok nélkül futtatható. Csak kibontod és már használhatod is!

Mellékelt eszközök: php, node.js, Composer és Adminer

## Indítás és használat

Indítás: Kattints duplán a gyökérmappában található indito.bat fájlra.

A háttérben elindulnak a szükséges folyamatok, és automatikusan megnyílik a Vezérlőpult egy izolált Edge böngészőben.

A parancssori (fekete) ablakot ne zárd be, amíg dolgozol! Ha végeztél, egyszerűen zárd be a Vezérlőpultot, a rendszer automatikusan leállít minden háttérfolyamatot.

## A Vezérlőpult használata
___________________________
1. Új projekt létrehozása

A jobb felső sarokban található Új Projekt gombra kattintva válaszd ki a kívánt technológiát.

Add meg a projekt nevét (szóközök és ékezetek nélkül, pl. elso-webshop).

A rendszer létrehozza a projektek/ mappában a szükséges fájlokat. Laravel és WordPress esetén a telepítés egy új parancssori ablakban lefut (ez eltarthat 1-2 percig, várd meg, amíg jelzi a bezárást!).
___________________________
2. Kártyák és Műveletek

Minden létrehozott projekted egy kártyaként jelenik meg. A kártya alján az alábbi gyorsgombokat találod:

Mappa megnyitása: Közvetlenül megnyitja a projekt fájljait a Windows Intézőben.

Terminál: Nyit egy parancssort közvetlenül a projekt mappájában (a PHP és a Node.js parancsok itt azonnal működnek!).

Megnyitás szerkesztőben: a projektet megnyitja a beállításokban kiválasztott programban (VS code az alapértelmezett)

Megnyitás böngészőben: Megnyitja az elkészült weboldalt (statikus fájloknál helyiként, PHP/Laravel esetén a 127.0.0.1-es címen, ha fut a szerver).

Adatbázis megnyitása: külön ablakban paraméterezve megnyitja az Adminer felületét. A jelszót a Vezérlőpultból tudod kimásolni.

Szerver futtatása/leállítása: Laravel és Wordpress projekteknél saját webszervert indít. Az ehhez megnyíló parancsort ne zárd be kézzel, mindig a vezérlőpultról állítsd le a szervereket!

Törlés: törli a projekt mappáját a fájlokkal együtt.

Ezeken túl a kártya tetején lehetőség van snapshotot csinálni a weboldalakról, amit betesz a kártya hátterének. Az új kép felülírja a korábbit!
___________________________
3. Adatbázis kezelése

A Vezérlőpulthoz van mellékelve az Adminer nevű adatbáziskezelő program. Menüből is megnyitható, de a projekteknél külön gomb is kikerült, hogy közvetlenül meg tudd nyitni az adatbázisokat.

A manuális megnyitáshoz a szükséges adatok:

System: SQLite
User: root
Password: (rákattintással másolható!)
Database: (az adatbázis fájl helye)

Laravel adatbázis helye: .../PocketWeb/projektek/LaraProjekted/database/database.sqlite
Wordpress adatbázis helye: ...PocketWeb/projektek/WpProjekted/wp-content/database/.ht.sqlite
___________________________
4. Beállítások

A beállításokban három dolgot lehet csinálni:

- Kiválasztani a témaszínt a default narancs helyett.

- Kiválasztani a külső szerkesztőt
* A JetBrains szerkesztők megnyitása még bugos, a környezeti változók miatt.

- Linkek a csomagolt php, nodejs, composer és adminer letöltőoldalaihoz.
