#!/usr/bin/env bash
# Yomara ERP — construit l'application macOS, la signe et crée le .dmg à distribuer.
#
#   ./scripts/construire-macos.sh        (SORTIE=/chemin pour changer le dossier de sortie)
#
# Signature :
#   - avec un certificat « Developer ID Application » dans le trousseau → signée, prête à distribuer ;
#   - sinon → signature locale (ad hoc) : l'app marche, mais macOS affiche un avertissement au premier lancement.
# Notarisation Apple (supprime tout avertissement) : faites une fois
#   xcrun notarytool store-credentials yomara-notary --apple-id VOTRE_ID_APPLE --team-id VOTRE_TEAM_ID
#   (mot de passe d'application créé sur appleid.apple.com), le script notarise ensuite tout seul.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# Ancienne compilation Mac non modifiable (souvent : un .pkg installé s'est posé dessus, fichiers de root) :
# on la met de côté au lieu d'échouer.
if [ -d build/macos ] && find build/macos ! -writable -print -quit 2>/dev/null | grep -q .; then
  A="$ROOT/build-ancien-$(date +%Y%m%d-%H%M%S)"
  mv build/macos "$A" && echo "Ancienne compilation Mac non modifiable déplacée dans : $A (à supprimer à la main)"
fi
[ "$(uname)" = "Darwin" ] || { echo "L'application macOS se construit sur un Mac." >&2; exit 1; }
command -v flutter >/dev/null 2>&1 || { echo "Flutter n'est pas installé." >&2; exit 1; }
command -v xcodebuild >/dev/null 2>&1 || { echo "Xcode n'est pas installé (App Store › Xcode)." >&2; exit 1; }

VERSION="$(grep -E '^version:' pubspec.yaml | awk '{print $2}' | cut -d+ -f1)"
OUT="${SORTIE:-$ROOT/build/livrables/$VERSION}"
mkdir -p "$OUT"
APP_NAME="Yomara ERP"

flutter pub get >/dev/null
# Xcode 26 : projet remis au format lu par CocoaPods (voir construire-mobiles.sh)
P=macos/Runner.xcodeproj/project.pbxproj
perl -0pi -e 's/shellScript = \(\n(.*?)\n\s*\);/my $b=$1; my @l = $b =~ m{^\s*"((?:[^"\\]|\\.)*)",?\s*$}mg; "shellScript = \"".join("\\n",@l)."\\n\";"/gse; s/objectVersion = 100;/objectVersion = 54;/; s/\n\s*preferredProjectObjectVersion = \d+;//' "$P"
flutter build macos --release
APP="$ROOT/build/macos/Build/Products/Release/$APP_NAME.app"
[ -d "$APP" ] || { echo "Application introuvable : $APP" >&2; exit 1; }

ID="${MAC_SIGN_IDENTITY:-$(security find-identity -v -p codesigning 2>/dev/null | grep 'Developer ID Application' | head -1 | sed -E 's/.*"(.*)"/\1/' || true)}"
ENT="$ROOT/macos/Runner/Release.entitlements"
if [ -n "$ID" ]; then
  echo "Signature avec : $ID"
  # Bibliothèques et frameworks d'abord, puis l'application (exécution renforcée, horodatage Apple)
  find "$APP/Contents/Frameworks" -maxdepth 1 \( -name "*.framework" -o -name "*.dylib" \) -print0 2>/dev/null | while IFS= read -r -d '' f; do
    codesign --force --options runtime --timestamp -s "$ID" "$f"
  done
  codesign --force --options runtime --timestamp --entitlements "$ENT" -s "$ID" "$APP"
else
  echo "Aucun certificat « Developer ID Application » : signature locale (ad hoc)."
  codesign --force --deep -s - "$APP"
fi
codesign --verify --deep --strict "$APP" && echo "Signature vérifiée."

# --- DMG : l'application + un raccourci vers Applications (glisser-déposer)
DMG="$OUT/Yomara-ERP-macos-v$VERSION.dmg"
STAGE="$(mktemp -d)"
cp -R "$APP" "$STAGE/"
ln -s /Applications "$STAGE/Applications"
rm -f "$DMG"
hdiutil create -volname "$APP_NAME $VERSION" -srcfolder "$STAGE" -ov -format UDZO "$DMG" >/dev/null
rm -rf "$STAGE"
[ -n "$ID" ] && codesign --force --timestamp -s "$ID" "$DMG"

# --- Notarisation (si les identifiants ont été enregistrés)
if [ -n "$ID" ] && xcrun notarytool history --keychain-profile yomara-notary >/dev/null 2>&1; then
  echo "Notarisation Apple en cours (quelques minutes)…"
  xcrun notarytool submit "$DMG" --keychain-profile yomara-notary --wait
  xcrun stapler staple "$DMG"
  echo "DMG notarisé : aucun avertissement à l'ouverture."
elif [ -n "$ID" ]; then
  echo "Astuce : enregistrez vos identifiants de notarisation (voir en tête du script) pour supprimer l'avertissement de macOS."
fi

# Archive .zip de l'application (mise à jour automatique, envoi par e-mail)
(cd "$(dirname "$APP")" && ditto -c -k --keepParent "$APP_NAME.app" "$OUT/Yomara-ERP-macos-v$VERSION.zip")
echo "Application macOS prête dans : $OUT"
ls -lh "$OUT"
open "$OUT"
