#!/usr/bin/env bash
# Yomara ERP — construit l'application Linux (64 bits) et ses deux paquets :
#   Yomara-ERP-linux-x64-v<version>.tar.gz  (toutes distributions : ./install.sh, sans droits administrateur)
#   yomara-erp_<version>_amd64.deb          (Ubuntu, Debian : double-clic ou « sudo apt install ./fichier.deb »)
# À lancer sur une machine Linux : ./scripts/construire-linux.sh   (SORTIE=/chemin pour changer le dossier)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
[ "$(uname)" = "Linux" ] || { echo "L'application Linux se construit sur Linux (Ubuntu 22.04+ conseillé)." >&2; exit 1; }
command -v flutter >/dev/null 2>&1 || { echo "Flutter n'est pas installé." >&2; exit 1; }
VERSION="$(grep -E '^version:' pubspec.yaml | awk '{print $2}' | cut -d+ -f1)"
OUT="${SORTIE:-$ROOT/build/livrables/$VERSION}"
mkdir -p "$OUT"
P="$ROOT/linux/packaging"

flutter pub get >/dev/null
[ "${SKIP_BUILD:-}" = 1 ] || flutter build linux --release
B="$ROOT/build/linux/x64/release/bundle"

# --- archive .tar.gz
T="$(mktemp -d)"; D="$T/Yomara-ERP-linux-x64"
mkdir -p "$D/app"
cp -r "$B/." "$D/app/"
cp "$P/install.sh" "$P/uninstall.sh" "$P/yomara-erp.desktop" "$P/yomara-erp.png" "$P/LISEZMOI.txt" "$D/"
chmod +x "$D/install.sh" "$D/uninstall.sh" "$D/app/yomara_erp"
tar -C "$T" -czf "$OUT/Yomara-ERP-linux-x64-v$VERSION.tar.gz" Yomara-ERP-linux-x64

# --- paquet .deb (installé dans /opt/yomara-erp)
if command -v dpkg-deb >/dev/null 2>&1; then
  K="$T/deb"
  mkdir -p "$K/DEBIAN" "$K/opt/yomara-erp" "$K/usr/bin" "$K/usr/share/applications" "$K/usr/share/icons/hicolor/256x256/apps"
  cp -r "$B/." "$K/opt/yomara-erp/"
  ln -s /opt/yomara-erp/yomara_erp "$K/usr/bin/yomara-erp"
  sed "s|@EXEC@|/opt/yomara-erp/yomara_erp|" "$P/yomara-erp.desktop" > "$K/usr/share/applications/yomara-erp.desktop"
  cp "$P/yomara-erp.png" "$K/usr/share/icons/hicolor/256x256/apps/yomara-erp.png"
  SIZE="$(du -sk "$K/opt" | cut -f1)"
  cat > "$K/DEBIAN/control" <<CTRL
Package: yomara-erp
Version: $VERSION
Section: office
Priority: optional
Architecture: amd64
Installed-Size: $SIZE
Depends: libgtk-3-0 | libgtk-3-0t64, libstdc++6
Maintainer: Yomara Technologies <yomaratechnologies@gmail.com>
Homepage: https://erp.yomaratechnologies.com
Description: Yomara ERP — gestion d'entreprise
 Ventes, caisse, stock, clients, RH, paie et comptabilité dans une seule
 application, utilisable même sans connexion.
CTRL
  printf '#!/bin/sh\ncommand -v update-desktop-database >/dev/null && update-desktop-database -q /usr/share/applications || true\n' > "$K/DEBIAN/postinst"
  chmod 755 "$K/DEBIAN/postinst"
  find "$K/opt" -type d -exec chmod 755 {} +
  dpkg-deb --build --root-owner-group "$K" "$OUT/yomara-erp_${VERSION}_amd64.deb" >/dev/null
else
  echo "dpkg-deb absent : seul le .tar.gz est créé (sudo apt install dpkg-dev pour le .deb)."
fi
rm -rf "$T"
echo "Paquets Linux prêts dans : $OUT"
ls -lh "$OUT"
