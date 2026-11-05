#!/usr/bin/env bash
# Installe Yomara ERP pour l'utilisateur courant (sans droits administrateur).
set -e
SRC="$(cd "$(dirname "$0")" && pwd)"
DEST="$HOME/.local/share/yomara-erp"
mkdir -p "$DEST" "$HOME/.local/bin" "$HOME/.local/share/applications" "$HOME/.local/share/icons/hicolor/256x256/apps"
cp -r "$SRC/app/." "$DEST/"
chmod +x "$DEST/yomara_erp"
ln -sf "$DEST/yomara_erp" "$HOME/.local/bin/yomara-erp"
cp "$SRC/yomara-erp.png" "$HOME/.local/share/icons/hicolor/256x256/apps/yomara-erp.png"
sed "s|@EXEC@|$DEST/yomara_erp|" "$SRC/yomara-erp.desktop" > "$HOME/.local/share/applications/yomara-erp.desktop"
command -v update-desktop-database >/dev/null && update-desktop-database "$HOME/.local/share/applications" || true
echo "Yomara ERP est installé. Lancez-le depuis le menu des applications ou avec : yomara-erp"
