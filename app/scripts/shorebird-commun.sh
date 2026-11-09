# Yomara ERP — réglages communs des mises à jour à distance (Shorebird). Fichier inclus par les autres scripts.
# Les mises à jour à distance sont actives quand :
#   - shorebird.yaml existe à la racine du projet (créé par ACTIVER-MISES-A-JOUR-A-DISTANCE.command),
#   - la commande shorebird est installée,
#   - et SHOREBIRD n'est pas mis à 0 (SHOREBIRD=0 ./scripts/… pour une construction Flutter classique).
# Version de Flutter utilisée par Shorebird : la même pour toutes les versions et tous les correctifs.
SB_FLUTTER="${SB_FLUTTER:-3.47.9}"
SB_REPO="${YOMARA_REPO:-michael4532/yomara-erp}"

# Version de base des logiciels Windows, Mac DMG et Linux (construits sur GitHub), qui peut différer
# de celle d'Android et iPhone (pubspec.yaml) : écrite par construire-stores, lue par publier-correctif.
sb_version_bureau() { cat "${ROOT:-.}/scripts/version-bureau.txt" 2>/dev/null || grep -E '^version:' "${ROOT:-.}/pubspec.yaml" | awk '{print $2}'; }
[ -d "$HOME/.shorebird/bin" ] && export PATH="$HOME/.shorebird/bin:$PATH"

sb_actif() {
  [ "${SHOREBIRD:-1}" != 0 ] && [ -f "${ROOT:-.}/shorebird.yaml" ] && command -v shorebird >/dev/null 2>&1
}

# Fichier d'export iPhone (App Store Connect), identique pour la version et ses correctifs
sb_export_ios() { # sb_export_ios <fichier> <team id>
  mkdir -p "$(dirname "$1")"
  cat > "$1" <<PL
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>method</key><string>app-store-connect</string>
  <key>teamID</key><string>$2</string>
  <key>signingStyle</key><string>automatic</string>
  <key>uploadSymbols</key><true/>
</dict></plist>
PL
}
