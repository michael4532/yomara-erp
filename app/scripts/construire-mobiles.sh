#!/usr/bin/env bash
# Yomara ERP — construit les applications mobiles SIGNÉES, prêtes pour les stores.
#
#   ./scripts/construire-mobiles.sh            → APK + App Bundle (Android) et IPA (iPhone, sur Mac)
#   ./scripts/construire-mobiles.sh android    → Android seulement (APK + AAB)
#   ./scripts/construire-mobiles.sh ios        → iPhone seulement (IPA)
#   SORTIE=/chemin ./scripts/construire-mobiles.sh   → dossier où copier les fichiers (défaut : build/livrables/<version>)
#
# Android : signé avec le keystore de android/key.properties (créé par scripts/generer-keystore.sh).
# iPhone  : signé par Xcode avec votre équipe Apple (Team ID demandé une fois, gardé dans ~/.yomara-keys/apple-team-id).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
WHAT="${1:-tout}"
command -v flutter >/dev/null 2>&1 || { echo "Flutter n'est pas installé (https://docs.flutter.dev/get-started/install)." >&2; exit 1; }

VERSION="$(grep -E '^version:' pubspec.yaml | awk '{print $2}')"
NAME="${VERSION%%+*}"; BUILD="${VERSION##*+}"
OUT="${SORTIE:-$ROOT/build/livrables/$NAME}"
mkdir -p "$OUT"
echo "Yomara ERP $NAME (build $BUILD)"
echo "Rappel : Google Play et l'App Store refusent un numéro de build déjà envoyé ; augmentez le chiffre après « + » dans pubspec.yaml à chaque envoi."
echo

flutter pub get >/dev/null

if [ "$WHAT" = "tout" ] || [ "$WHAT" = "android" ]; then
  if [ ! -f android/key.properties ]; then
    echo "Aucune clé de signature Android : lancement de la création du keystore…"
    "$ROOT/scripts/generer-keystore.sh"
  fi
  STORE="$(grep -E '^storeFile=' android/key.properties | cut -d= -f2-)"
  [ -f "$STORE" ] || { echo "Le keystore indiqué dans android/key.properties est introuvable : $STORE" >&2; exit 1; }
  echo "== Android : APK signé"
  flutter build apk --release
  echo "== Android : App Bundle signé (Google Play)"
  flutter build appbundle --release
  cp build/app/outputs/flutter-apk/app-release.apk "$OUT/yomara-erp-$NAME.apk"
  cp build/app/outputs/bundle/release/app-release.aab "$OUT/yomara-erp-$NAME.aab"
  # Vérifie la signature (empreinte SHA-256 du certificat)
  if command -v keytool >/dev/null 2>&1; then
    echo "Certificat de signature de l'App Bundle :"
    keytool -printcert -jarfile "$OUT/yomara-erp-$NAME.aab" 2>/dev/null | grep -E "Owner|Propriétaire|SHA256" | sed 's/^/  /' || true
  fi
fi

if [ "$WHAT" = "tout" ] || [ "$WHAT" = "ios" ]; then
  if [ "$(uname)" != "Darwin" ]; then
    echo "L'application iPhone (IPA) se construit uniquement sur un Mac avec Xcode."
  else
    command -v xcodebuild >/dev/null 2>&1 || { echo "Xcode n'est pas installé (App Store › Xcode)." >&2; exit 1; }
    TEAMFILE="$HOME/.yomara-keys/apple-team-id"
    TEAM="${APPLE_TEAM_ID:-$(cat "$TEAMFILE" 2>/dev/null || true)}"
    if [ -z "$TEAM" ]; then
      echo "Team ID Apple (10 caractères) : developer.apple.com › Account › Membership details"
      read -r -p "Team ID : " TEAM
      [[ "$TEAM" =~ ^[A-Z0-9]{10}$ ]] || { echo "Team ID invalide." >&2; exit 1; }
      mkdir -p "$(dirname "$TEAMFILE")"; echo "$TEAM" > "$TEAMFILE"
    fi
    # Équipe de signature de l'app (signature automatique Xcode)
    PBX=ios/Runner.xcodeproj/project.pbxproj
    if ! grep -q "DEVELOPMENT_TEAM = $TEAM;" "$PBX"; then
      perl -0pi -e 's/\s*DEVELOPMENT_TEAM = [A-Z0-9]+;//g' "$PBX"
      perl -pi -e 's/^(\s*)(PRODUCT_BUNDLE_IDENTIFIER = com\.yomaratechnologies\.yomaraerp;)/$1DEVELOPMENT_TEAM = '"$TEAM"';\n$1$2/' "$PBX"
    fi
    PLIST="$ROOT/build/ExportOptions.plist"
    mkdir -p "$ROOT/build"
    cat > "$PLIST" <<PL
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>method</key><string>app-store-connect</string>
  <key>teamID</key><string>$TEAM</string>
  <key>signingStyle</key><string>automatic</string>
  <key>uploadSymbols</key><true/>
</dict></plist>
PL
    # Xcode 26 réenregistre le projet dans un format que CocoaPods ne lit pas encore
    # (« Type checking error: got Array for attribute shellScript ») : on le remet au format classique.
    for P in ios/Runner.xcodeproj/project.pbxproj macos/Runner.xcodeproj/project.pbxproj; do
      [ -f "$P" ] || continue
      perl -0pi -e 's/shellScript = \(\n(.*?)\n\s*\);/my $b=$1; my @l = $b =~ m{^\s*"((?:[^"\\]|\\.)*)",?\s*$}mg; "shellScript = \"".join("\\n",@l)."\\n\";"/gse; s/objectVersion = 100;/objectVersion = 54;/; s/\n\s*preferredProjectObjectVersion = \d+;//' "$P"
    done
    echo "== iPhone : IPA signé (App Store Connect)"
    (cd ios && pod install --repo-update >/dev/null 2>&1 || pod install)
    flutter build ipa --release --export-options-plist="$PLIST"
    IPA="$(ls build/ios/ipa/*.ipa | head -1)"
    cp "$IPA" "$OUT/yomara-erp-$NAME.ipa"
    echo "Envoi sur l'App Store : ouvrez l'app « Transporter » et déposez $OUT/yomara-erp-$NAME.ipa"
  fi
fi

echo
echo "Fichiers prêts dans : $OUT"
ls -lh "$OUT"
[ "$(uname)" = "Darwin" ] && open "$OUT" || true
