@echo off
REM "composer" parancs a PocketWeb melle csomagolt composer.phar-hoz (Windows)
setlocal
set "PW_PHP=%~dp0..\php\php.exe"
if not exist "%PW_PHP%" set "PW_PHP=php"
"%PW_PHP%" "%~dp0..\php\composer.phar" %*
