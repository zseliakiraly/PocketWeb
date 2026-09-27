@echo off
TITLE PocketWeb Betolto
chcp 65001 > NUL
SET BASEDIR=%~dp0
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
if not exist "%BASEDIR%projektek" mkdir "%BASEDIR%projektek"

start /B "PocketWeb_UI" "%BASEDIR%rendszer\php\php.exe" -S 127.0.0.1:8181 -t "%BASEDIR%rendszer" > NUL 2>&1
timeout /T 1 /NOBREAK > NUL
echo PHP szerver indul...
timeout /T 1 /NOBREAK > NUL
echo Vezerlopult betoltes...
timeout /T 1 /NOBREAK > NUL
echo --------------------------
echo Ne zard be ezt az ablakot!
echo --------------------------

start /WAIT msedge.exe --app="http://127.0.0.1:8181/index.html" --user-data-dir="%BASEDIR%rendszer\.edge_profile"

:WAIT_EDGE timeout /T 1 /NOBREAK > NUL
powershell -NoProfile -Command "if (Get-CimInstance Win32_Process -Filter \"Name='msedge.exe' and CommandLine like '%%.edge_profile%%'\") { exit 1 } else { exit 0 }" > NUL 2>&1
IF %ERRORLEVEL% EQU 1 GOTO WAIT_EDGE
echo Cleanup...
for /f "tokens=5" %%a in ('netstat -ano ^| find "127.0.0.1:8181" ^| find "LISTENING"') do ( taskkill /PID %%a /F /T > NUL 2>&1 )
for /f "tokens=5" %%a in ('netstat -ano ^| find "LISTENING" ^| findstr ":800"') do ( taskkill /PID %%a /F /T > NUL 2>&1 )
exit
