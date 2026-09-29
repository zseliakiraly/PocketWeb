#!/bin/sh
# PocketWeb – parancssor egy projekt mappájában (Linux/macOS).
# A mellékelt php, node és a composer parancs a PATH elejére kerül, majd a felhasználó saját shellje indul.

PW_SYS=$(cd "$(dirname "$0")/.." && pwd)
PW_PATH="$PW_SYS/bin"
for dir in "$PW_SYS/php/bin" "$PW_SYS/php"; do
    if [ -x "$dir/php" ] && "$dir/php" -v >/dev/null 2>&1; then
        PW_PATH="$PW_PATH:$dir"
        break
    fi
done
[ -x "$PW_SYS/node/bin/node" ] && PW_PATH="$PW_PATH:$PW_SYS/node/bin"
export PATH="$PW_PATH:$PATH"

cd "${1:-$PW_SYS/../projektek}" || exit 1
printf '\033[33mPocketWeb\033[0m – %s\n' "$(pwd)"
printf 'Elérhető parancsok: php, composer'
command -v node >/dev/null 2>&1 && printf ', node, npm'
printf '\n\n'
exec "${SHELL:-/bin/sh}" -i
