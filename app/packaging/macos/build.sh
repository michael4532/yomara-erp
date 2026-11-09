#!/bin/bash
# Paquet macOS : signature (Developer ID si fournie, sinon signature locale « ad hoc »), notarisation facultative, DMG.
# Variables facultatives : MACOS_SIGN_IDENTITY, APPLE_ID, APPLE_TEAM_ID, APPLE_APP_PASSWORD
set -euo pipefail
V="$1"; OUT="${2:-dist}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
APP="$ROOT/build/macos/Build/Products/Release/Yomara ERP.app"
test -d "$APP" || { echo "Compilez d'abord : flutter build macos --release"; exit 1; }
mkdir -p "$OUT"
cp "$ROOT/LICENSE" "$ROOT/NOTICE.md" "$APP/Contents/Resources/"
if [ -n "${MACOS_SIGN_IDENTITY:-}" ]; then
  codesign --force --deep --options runtime --timestamp --preserve-metadata=entitlements --sign "$MACOS_SIGN_IDENTITY" "$APP"
else
  codesign --force --deep --preserve-metadata=entitlements --sign - "$APP"   # sans certificat Apple : macOS demandera « Ouvrir quand même »
fi
STAGE="$(mktemp -d)"; cp -R "$APP" "$STAGE/"; ln -s /Applications "$STAGE/Applications"; cp "$ROOT/LICENSE" "$STAGE/LICENSE.txt"
DMG="$OUT/Yomara-ERP-macOS-v$V.dmg"
hdiutil create -volname "Yomara ERP $V" -srcfolder "$STAGE" -ov -format UDZO "$DMG"
if [ -n "${MACOS_SIGN_IDENTITY:-}" ]; then
  codesign --sign "$MACOS_SIGN_IDENTITY" --timestamp "$DMG"
  if [ -n "${APPLE_ID:-}" ]; then
    xcrun notarytool submit "$DMG" --apple-id "$APPLE_ID" --team-id "$APPLE_TEAM_ID" --password "$APPLE_APP_PASSWORD" --wait
    xcrun stapler staple "$DMG"
  fi
fi
(cd "$OUT" && shasum -a 256 "$(basename "$DMG")" > "SHA256SUMS-macos-v$V.txt")
