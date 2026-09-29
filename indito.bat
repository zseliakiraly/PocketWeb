@echo off
TITLE PocketWeb Betolto
chcp 65001 > NUL
SET "BASEDIR=%~dp0"
color 06
echo.
echo =====================================================
echo     ____             __        __ _       __     __
echo    / __ ^\____  _____/ /_____  / /^| ^|     / /__  / /_
echo   / /_/ / __ ^\/ ___/ //_/ _ \/ __/ ^| /^| / / _ ^\/ __ ^\
echo  / ____/ /_/ / /__/ ,^< /  __/ /_ ^| ^|/ ^|/ /  __/ /_/ /
echo /_/    ^\____/^\___/_/^|_^|^\___/^\__/ ^|__/^|__/^\___/_.___/
echo.
echo =====================================================
echo.
echo Inditas folyamatban...

SET "PW_ROOT=%BASEDIR%"
SET "PW_PHP=%BASEDIR%rendszer\php\php.exe"
SET "PW_SCRIPT=%BASEDIR%rendszer\pocketweb.php"

if not exist "%PW_PHP%" (
    echo.
    echo HIBA: nem talalhato a PHP: "%PW_PHP%"
    echo Toltsd le a PHP-t ^(lasd: rendszer\php\DOWNLOAD.me^), es csomagold ki a rendszer\php mappaba!
    echo.
    pause
    exit /b 1
)

REM 1. Ellenorzes ebben az ablakban, hogy az esetleges hibauzenet olvashato legyen.
REM    Kilepesi kod: 0 = indithato, 2 = mar fut (a vezerlopult megnyilt), egyeb = hiba
"%PW_PHP%" "%PW_SCRIPT%" --preflight
if %ERRORLEVEL% EQU 2 exit /b 0
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo Az inditas nem sikerult, a hiba oka fent olvashato.
    echo.
    pause
    exit /b 1
)

REM 2. A PocketWeb rejtett ablakban indul, igy a szerverek es telepitok ablakai sem ugranak fel:
REM    a kimenetuk a vezerlopult Terminal paneljen latszik. Leallitas: a vezerlopult bezarasa.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath $env:PW_PHP -ArgumentList ([char]34 + $env:PW_SCRIPT + [char]34) -WorkingDirectory $env:PW_ROOT -WindowStyle Hidden"
if %ERRORLEVEL% EQU 0 exit /b 0

REM Ha a PowerShell nem hasznalhato, a PocketWeb ebben az ablakban fut (mint a korabbi verziokban)
echo.
echo --------------------------
echo Ne zard be ezt az ablakot!
echo --------------------------
"%PW_PHP%" "%PW_SCRIPT%"
exit /b 0
