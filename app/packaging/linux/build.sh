#!/bin/bash
# Paquets Linux de Yomara ERP : archive .tar.gz, paquet .deb, AppImage, sommes SHA-256 et signatures GPG.
# Usage : packaging/linux/build.sh <version> [dossier_sortie]   (après « flutter build linux --release »)
set -euo pipefail
V="$1"; OUT="${2:-dist}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
B="$ROOT/build/linux/x64/release/bundle"
test -x "$B/yomara_erp" || { echo "Compilez d'abord : flutter build linux --release"; exit 1; }
mkdir -p "$OUT"; OUT="$(cd "$OUT" && pwd)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
cp "$ROOT/LICENSE" "$ROOT/NOTICE.md" "$B/"

# 1. Archive portable
tar -czf "$OUT/Yomara-ERP-linux-x64-v$V.tar.gz" -C "$B" .

# 2. Paquet Debian / Ubuntu
D="$TMP/deb"; mkdir -p "$D/DEBIAN" "$D/opt/yomara-erp" "$D/usr/bin" "$D/usr/share/applications" "$D/usr/share/icons/hicolor/512x512/apps" "$D/usr/share/metainfo" "$D/usr/share/doc/yomara-erp"
cp -r "$B/." "$D/opt/yomara-erp/"
ln -s /opt/yomara-erp/yomara_erp "$D/usr/bin/yomara-erp"
cp "$ROOT/packaging/linux/yomara-erp.desktop" "$D/usr/share/applications/"
cp "$ROOT/assets/brand/mark.png" "$D/usr/share/icons/hicolor/512x512/apps/yomara-erp.png"
cp "$ROOT/packaging/linux/com.yomaratechnologies.erp.metainfo.xml" "$D/usr/share/metainfo/"
cp "$ROOT/NOTICE.md" "$D/usr/share/doc/yomara-erp/"; cp "$ROOT/LICENSE" "$D/usr/share/doc/yomara-erp/copyright"
SIZE=$(du -sk "$D/opt" | cut -f1)
cat > "$D/DEBIAN/control" <<CTL
Package: yomara-erp
Version: $V
Section: office
Priority: optional
Architecture: amd64
Installed-Size: $SIZE
Depends: libgtk-3-0, libepoxy0, zlib1g, libfontconfig1
Maintainer: Yomara Technologies <yomaratechnologies@gmail.com>
Homepage: https://erp.yomaratechnologies.com
Description: Yomara ERP - logiciel de gestion des entreprises
 Ventes, caisse, stock, comptabilité SYSCOHADA, paie, CRM et messagerie,
 même sans connexion. Copyright 2026 Yomara Technologies. Licence AGPL-3.0.
CTL
find "$D" -type d -exec chmod 755 {} +
dpkg-deb --root-owner-group --build "$D" "$OUT/yomara-erp_${V}_amd64.deb" >/dev/null

# 3. AppImage (si appimagetool est présent)
if command -v appimagetool >/dev/null 2>&1; then
  A="$TMP/AppDir"; mkdir -p "$A/usr/bin"
  cp -r "$B/." "$A/usr/bin/"
  cp "$ROOT/packaging/linux/yomara-erp.desktop" "$A/"; sed -i 's/^Exec=.*/Exec=yomara_erp %U/' "$A/yomara-erp.desktop"
  cp "$ROOT/assets/brand/mark.png" "$A/yomara-erp.png"
  printf '#!/bin/sh\nHERE="$(dirname "$(readlink -f "$0")")"\nexec "$HERE/usr/bin/yomara_erp" "$@"\n' > "$A/AppRun"; chmod +x "$A/AppRun"
  ARCH=x86_64 appimagetool --no-appstream "$A" "$OUT/Yomara-ERP-v$V-x86_64.AppImage" >/dev/null 2>&1 || echo "AppImage non créée"
fi

# 4. Sommes de contrôle et signatures (clé GPG de publication de Yomara, si présente)
cd "$OUT"
sha256sum Yomara-ERP-linux-x64-v$V.tar.gz yomara-erp_${V}_amd64.deb $(ls Yomara-ERP-v$V-x86_64.AppImage 2>/dev/null) > SHA256SUMS-linux-v$V.txt
if [ -n "${GPG_KEY_ID:-}" ]; then
  for f in Yomara-ERP-linux-x64-v$V.tar.gz yomara-erp_${V}_amd64.deb $(ls Yomara-ERP-v$V-x86_64.AppImage 2>/dev/null) SHA256SUMS-linux-v$V.txt; do
    gpg --batch --yes --local-user "$GPG_KEY_ID" --armor --detach-sign "$f"
  done
fi
ls -la "$OUT"
