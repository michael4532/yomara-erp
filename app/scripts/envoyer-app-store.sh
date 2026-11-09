#!/usr/bin/env bash
# Yomara ERP — construit et ENVOIE sur App Store Connect la version Mac App Store et/ou iPhone.
#
#   ./scripts/envoyer-app-store.sh          → Mac et iPhone
#   ./scripts/envoyer-app-store.sh mac      → Mac seulement
#   ./scripts/envoyer-app-store.sh iphone   → iPhone seulement
#   ./scripts/envoyer-app-store.sh mac-pkg  → Mac : crée seulement le fichier .pkg à envoyer
#                                             (avec Transporter ou plus tard), sans l'envoyer
#   ./scripts/envoyer-app-store.sh iphone-ipa → iPhone : crée seulement le fichier .ipa
#   (dossier du fichier : SORTIE=/chemin, par défaut build/appstore/fichiers)
#
# Prérequis (une seule fois) :
#   - Xcode connecté à votre compte Apple Developer (Xcode › Réglages › Comptes) ;
#   - la fiche de l'app créée sur appstoreconnect.apple.com (identifiant com.yomaratechnologies.yomaraerp).
# Version Mac App Store : bac à sable Apple, boutique de modules masquée (achats sur le site).
# Augmentez le chiffre après « + » dans pubspec.yaml à chaque envoi : Apple refuse un numéro déjà envoyé.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
# Ancienne compilation Mac non modifiable (souvent : un .pkg installé s'est posé dessus, fichiers de root) :
# on la met de côté au lieu d'échouer.
if [ -d build/macos ] && find build/macos ! -writable -print -quit 2>/dev/null | grep -q .; then
  A="$ROOT/build-ancien-$(date +%Y%m%d-%H%M%S)"
  mv build/macos "$A" && echo "Ancienne compilation Mac non modifiable déplacée dans : $A (à supprimer à la main)"
fi
WHAT="${1:-tout}"
DEST=upload
case "$WHAT" in mac-pkg) WHAT=mac; DEST=export ;; iphone-ipa) WHAT=iphone; DEST=export ;; esac
SORTIE="${SORTIE:-$ROOT/build/appstore/fichiers}"
[ "$(uname)" = "Darwin" ] || { echo "L'envoi sur l'App Store se fait depuis un Mac." >&2; exit 1; }
command -v flutter >/dev/null 2>&1 || { echo "Flutter n'est pas installé." >&2; exit 1; }
command -v xcodebuild >/dev/null 2>&1 || { echo "Xcode n'est pas installé." >&2; exit 1; }

VERSION="$(grep -E '^version:' pubspec.yaml | awk '{print $2}')"
if [ "$DEST" = export ]; then echo "Yomara ERP $VERSION — création du fichier pour App Store Connect"; else echo "Yomara ERP $VERSION — envoi sur App Store Connect"; fi

TEAMFILE="$HOME/.yomara-keys/apple-team-id"
TEAM="${APPLE_TEAM_ID:-$(cat "$TEAMFILE" 2>/dev/null || true)}"
if [ -z "$TEAM" ]; then
  echo "Team ID Apple (10 caractères) : developer.apple.com › Account › Membership details"
  read -r -p "Team ID : " TEAM
  [[ "$TEAM" =~ ^[A-Z0-9]{10}$ ]] || { echo "Team ID invalide." >&2; exit 1; }
  mkdir -p "$(dirname "$TEAMFILE")"; echo "$TEAM" > "$TEAMFILE"
fi

fix_project() { # Xcode 26 : format de projet que CocoaPods ne lit pas encore
  local P="$1"; [ -f "$P" ] || return 0
  perl -0pi -e 's/shellScript = \(\n(.*?)\n\s*\);/my $b=$1; my @l = $b =~ m{^\s*"((?:[^"\\]|\\.)*)",?\s*$}mg; "shellScript = \"".join("\\n",@l)."\\n\";"/gse; s/objectVersion = 100;/objectVersion = 54;/; s/\n\s*preferredProjectObjectVersion = \d+;//' "$P"
}
set_team() { # équipe de signature automatique sur la cible Runner
  local P="$1"
  perl -0pi -e 's/\s*DEVELOPMENT_TEAM = [A-Z0-9]+;//g' "$P"
  perl -pi -e 's/^(\s*)(PRODUCT_BUNDLE_IDENTIFIER = com\.yomaratechnologies\.yomaraerp;)/$1DEVELOPMENT_TEAM = '"$TEAM"';\n$1$2/' "$P"
  perl -pi -e 's/^(\s*)(INFOPLIST_FILE = Runner\/Info\.plist;)/$1DEVELOPMENT_TEAM = '"$TEAM"';\n$1$2/ if $ARGV =~ m{macos}' "$P"
}
export_plist() { # $1 = fichier, envoi direct sur App Store Connect
  cat > "$1" <<PL
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>method</key><string>app-store-connect</string>
  <key>destination</key><string>$DEST</string>
  <key>teamID</key><string>$TEAM</string>
  <key>signingStyle</key><string>automatic</string>
  <key>uploadSymbols</key><true/>
</dict></plist>
PL
}

mkdir -p build/appstore; rm -rf build/appstore/mac build/ios/ipa
flutter pub get >/dev/null
ok=()

if [ "$WHAT" = "tout" ] || [ "$WHAT" = "mac" ]; then
  echo; echo "== Mac App Store"
  fix_project macos/Runner.xcodeproj/project.pbxproj
  grep -q "DEVELOPMENT_TEAM = $TEAM;" macos/Runner.xcodeproj/project.pbxproj || set_team macos/Runner.xcodeproj/project.pbxproj
  # Compilation Flutter (version store : achats masqués), puis archive Xcode et envoi
  flutter build macos --release --dart-define=APP_STORE=true
  export_plist build/appstore/ExportMac.plist
  xcodebuild -workspace macos/Runner.xcworkspace -scheme Runner -configuration Release -destination 'generic/platform=macOS' \
    -archivePath build/appstore/YomaraERP-mac.xcarchive DEVELOPMENT_TEAM="$TEAM" CODE_SIGN_STYLE=Automatic -allowProvisioningUpdates archive
  xcodebuild -exportArchive -archivePath build/appstore/YomaraERP-mac.xcarchive -exportOptionsPlist build/appstore/ExportMac.plist \
    -exportPath build/appstore/mac -allowProvisioningUpdates
  ok+=("Mac")
fi

if [ "$WHAT" = "tout" ] || [ "$WHAT" = "iphone" ]; then
  echo; echo "== iPhone / iPad"
  fix_project ios/Runner.xcodeproj/project.pbxproj
  grep -q "DEVELOPMENT_TEAM = $TEAM;" ios/Runner.xcodeproj/project.pbxproj || set_team ios/Runner.xcodeproj/project.pbxproj
  export_plist build/appstore/ExportIos.plist
  (cd ios && pod install >/dev/null 2>&1 || (cd ios && pod install --repo-update))
  flutter build ipa --release --dart-define=APP_STORE=true --export-options-plist=build/appstore/ExportIos.plist
  ok+=("iPhone")
fi

if [ "$DEST" = export ]; then
  mkdir -p "$SORTIE"
  V="$(echo "$VERSION" | tr '+' '-')"
  for f in build/appstore/mac/*.pkg; do
    if [ -f "$f" ]; then cp "$f" "$SORTIE/Yomara-ERP-mac-$V.pkg"; echo "Fichier Mac : $SORTIE/Yomara-ERP-mac-$V.pkg"; fi
  done
  for f in build/ios/ipa/*.ipa; do
    if [ -f "$f" ]; then cp "$f" "$SORTIE/Yomara-ERP-ios-$V.ipa"; echo "Fichier iPhone : $SORTIE/Yomara-ERP-ios-$V.ipa"; fi
  done
  echo
  echo "Pour l'envoyer : app Transporter (Mac App Store, gratuite) › connectez-vous › glissez le fichier › Livrer."
  open -R "$SORTIE" 2>/dev/null || true
  exit 0
fi
echo
echo "Envoyé sur App Store Connect : ${ok[*]}"
echo "Dans 10 à 30 minutes, la version apparaît dans App Store Connect › votre app › TestFlight, puis"
echo "sélectionnez-la dans la fiche de la version et cliquez « Soumettre pour examen »."
