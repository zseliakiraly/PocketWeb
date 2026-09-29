#!/bin/sh
# PocketWeb indító – Linux (és macOS: az indito.command is ezt hívja)
#
#   sh indito.sh                  a PocketWeb indítása
#   sh indito.sh --parancsikon    PocketWeb ikon az alkalmazások menübe (Linux)
#
# Leállítás: zárd be a vezérlőpult ablakát, használd a Kilépés gombot, vagy nyomj Ctrl+C-t.

cd "$(dirname "$0")" || exit 1
BASEDIR=$(pwd)

if [ "$1" = "--parancsikon" ]; then
    mkdir -p "$HOME/.local/share/applications"
    cat > "$HOME/.local/share/applications/pocketweb.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=PocketWeb
Comment=Hordozható webfejlesztő környezet
Exec=sh "$BASEDIR/indito.sh"
Icon=$BASEDIR/rendszer/icon.png
Terminal=true
Categories=Development;
EOF
    echo "Kész: a PocketWeb megjelent az alkalmazások között."
    exit 0
fi

printf '\033[33m'
cat <<'EOF'
=====================================================
    ____             __        __ _       __     __
   / __ \____  _____/ /_____  / /| |     / /__  / /_
  / /_/ / __ \/ ___/ //_/ _ \/ __/ | /| / / _ \/ __ \
 / ____/ /_/ / /__/ ,< /  __/ /_ | |/ |/ /  __/ /_/ /
/_/    \____/\___/_/|_|\___/\__/ |__/|__/\___/_.___/
=====================================================
EOF
printf '\033[0m\n'

# PHP: a mellékelt (rendszer/php), ha ezen a gépen futtatható, különben a rendszerre telepített
PHP=""
for candidate in "$BASEDIR/rendszer/php/bin/php" "$BASEDIR/rendszer/php/php"; do
    if [ -f "$candidate" ]; then
        chmod +x "$candidate" 2>/dev/null
        if "$candidate" -v >/dev/null 2>&1; then
            PHP="$candidate"
            break
        fi
    fi
done
[ -z "$PHP" ] && PHP=$(command -v php 2>/dev/null)

if [ -z "$PHP" ]; then
    echo "HIBA: nem található PHP. Telepítsd a csomagkezelővel, például:"
    echo "  Ubuntu, Debian, Mint:  sudo apt install php-cli php-sqlite3 php-mbstring php-xml php-curl php-zip unzip"
    echo "  Fedora:                sudo dnf install php-cli php-pdo php-mbstring php-xml php-process php-pecl-zip unzip"
    echo "  Arch, Manjaro:         sudo pacman -S php php-sqlite unzip   (és a php.ini-ben: extension=pdo_sqlite)"
    echo "  openSUSE:              sudo zypper install php8 php8-sqlite php8-mbstring php8-curl php8-zip php8-pcntl unzip"
    echo "  macOS (Homebrew):      brew install php"
    echo "vagy tegyél egy hordozható (statikus) PHP-t a rendszer/php mappába: https://static-php.dev"
    exit 1
fi

chmod +x "$BASEDIR/rendszer/bin/"* 2>/dev/null
exec "$PHP" "$BASEDIR/rendszer/pocketweb.php" "$@"
