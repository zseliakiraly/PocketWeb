#!/bin/sh
# PocketWeb indító macOS-re – a Finderben dupla kattintással indítható.
# (Első alkalommal: jobb klikk -> Megnyitás, mert a letöltött fájlokat a macOS alapból blokkolja.)
cd "$(dirname "$0")" && exec /bin/sh ./indito.sh "$@"
