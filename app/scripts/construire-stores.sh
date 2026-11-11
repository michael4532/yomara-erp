#!/usr/bin/env bash
# Yomara ERP — construit en une fois tous les fichiers à envoyer sur les stores, depuis un Mac :
#   - Android : APK + App Bundle (.aab) signés          → Google Play / AppGallery
#   - iPhone  : IPA                                      → App Store Connect (Transporter)
#   - Mac     : PKG Mac App Store                        → App Store Connect (Transporter)
#   - Windows : MSIX Microsoft Store (compilé sur GitHub) → partner.microsoft.com
#
#   ./scripts/construire-stores.sh            → demande la nouvelle version (Entrée = version suivante proposée)
#   ./scripts/construire-stores.sh 1.0.2+4    → version imposée
#   SANS="msix ios" ./scripts/construire-stores.sh   → saute certains éléments (android ios mac msix)
#
# Les fichiers arrivent dans  SORTIE  (par défaut ../sorties/stores-<version>).
# Rien n'est envoyé : chaque fichier s'envoie ensuite sur sa console (Transporter, Play Console, Partner Center).
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
. "$ROOT/scripts/shorebird-commun.sh"
[ "$(uname)" = "Darwin" ] || { echo "Cette commande se lance sur un Mac (iPhone et Mac ne se construisent que sur Mac)." >&2; exit 1; }
command -v flutter >/dev/null 2>&1 || { echo "Flutter n'est pas installé." >&2; exit 1; }
SANS=" ${SANS:-} "
veut() { [[ "$SANS" != *" $1 "* ]]; }

# 1. Version : les stores refusent un numéro déjà reçu
ACTUELLE="$(grep -E '^version:' pubspec.yaml | awk '{print $2}')"
NOM="${ACTUELLE%%+*}"; BUILD="${ACTUELLE##*+}"
IFS=. read -r MA MI PA <<<"$NOM"
PROPOSEE="$MA.$MI.$((PA + 1))+$((BUILD + 1))"
NOUVELLE="${1:-}"
if [ -z "$NOUVELLE" ]; then
  echo "Version actuelle : $ACTUELLE"
  read -r -p "Nouvelle version [$PROPOSEE] (Entrée pour accepter, ou « garder ») : " NOUVELLE
  NOUVELLE="${NOUVELLE:-$PROPOSEE}"
  [ "$NOUVELLE" = garder ] && NOUVELLE="$ACTUELLE"
fi
[[ "$NOUVELLE" =~ ^[0-9]+\.[0-9]+\.[0-9]+\+[0-9]+$ ]] || { echo "Version invalide : $NOUVELLE (forme attendue 1.0.2+4)." >&2; exit 1; }
V="${NOUVELLE%%+*}"
sed -i '' -E "s/^version: .*/version: $NOUVELLE/" pubspec.yaml
sed -i '' -E "s/^([[:space:]]*msix_version:).*/\1 $V.0/" pubspec.yaml
echo "Version utilisée : $NOUVELLE (Microsoft : $V.0)"

SORTIE="${SORTIE:-$(cd .. && pwd)/sorties/stores-$V}"
mkdir -p "$SORTIE"
LOGS="$SORTIE/journaux"; mkdir -p "$LOGS"
ok=(); ko=()

# 2. Microsoft : compilation sur GitHub lancée d'abord (≈10 min), pendant que le Mac construit le reste
MSIX_PID=""
if veut msix; then
  if ! command -v gh >/dev/null 2>&1; then
    if command -v brew >/dev/null 2>&1; then echo "Installation de GitHub CLI (une seule fois)…"; brew install gh; fi
  fi
  if command -v gh >/dev/null 2>&1 && ! gh auth status >/dev/null 2>&1; then
    echo "Connexion à GitHub (une seule fois) : GitHub.com, HTTPS, « Login with a web browser »."
    gh auth login --hostname github.com --git-protocol https --web
  fi
  if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
    if sb_actif; then
      # Mises à jour à distance : Windows (installateur + MSIX), DMG Mac et Linux construits par Shorebird sur GitHub.
      # GitHub compile les sources publiées : la version doit déjà y être (pubspec.yaml du dépôt).
      EN_LIGNE="$(gh api "repos/$SB_REPO/contents/app/pubspec.yaml" -H 'Accept: application/vnd.github.raw' 2>/dev/null | grep -E '^version:' | awk '{print $2}')"
      if [ "$EN_LIGNE" != "$NOUVELLE" ]; then
        echo "Attention : GitHub a la version « $EN_LIGNE » et non « $NOUVELLE » : Windows, DMG Mac et Linux sont sautés."
        echo "Demandez à Claude de publier les sources en $NOUVELLE, puis lancez seulement cette partie :"
        echo "   SANS=\"android ios mac\" ./scripts/construire-stores.sh $NOUVELLE"
        ko+=("Windows/Linux/DMG (sources GitHub pas en $NOUVELLE)")
      else
        echo; echo "=================== Windows, DMG Mac, Linux : construction Shorebird lancée sur GitHub (journal : $LOGS/microsoft.log)"
        TOUT="${TOUT:-0}" ./scripts/shorebird-github.sh version "$NOUVELLE" "$SORTIE" >"$LOGS/microsoft.log" 2>&1 &
        MSIX_PID=$!
      fi
    else
      echo; echo "=================== Microsoft Store : compilation lancée sur GitHub (journal : $LOGS/microsoft.log)"
      MSIX_VERSION="$V" MSIX_OUT="$SORTIE" ./scripts/generer-msix.sh >"$LOGS/microsoft.log" 2>&1 &
      MSIX_PID=$!
    fi
  else
    ko+=("Microsoft (GitHub CLI non connecté)")
  fi
fi

etape() { # etape "Nom" commande…
  echo; echo "=================== $1"
  if "${@:2}" 2>&1 | tee "$LOGS/$(echo "$1" | tr ' ' '-' | tr '[:upper:]' '[:lower:]').log"; [ "${PIPESTATUS[0]}" -eq 0 ]; then ok+=("$1"); else ko+=("$1"); fi
}

# 3. Mac : Android, iPhone, Mac App Store (l'un après l'autre : un seul Flutter à la fois)
veut android && etape "Android APK et AAB" env SORTIE="$SORTIE" ./scripts/construire-mobiles.sh android
veut ios     && etape "iPhone IPA"         env SORTIE="$SORTIE" ./scripts/construire-mobiles.sh ios
veut mac     && etape "Mac PKG"            env SORTIE="$SORTIE" ./scripts/envoyer-app-store.sh mac-pkg

# 4. Attente du paquet Microsoft
if [ -n "$MSIX_PID" ]; then
  echo; echo "=================== Microsoft Store : attente de GitHub…"
  if wait "$MSIX_PID"; then
    if sb_actif; then echo "$NOUVELLE" > "$ROOT/scripts/version-bureau.txt"; echo oui > "$ROOT/scripts/icones-bureau.txt"; fi
    ok+=("Microsoft MSIX"); else ko+=("Microsoft MSIX"); tail -5 "$LOGS/microsoft.log"; fi
fi

echo
echo "================================================================"
echo "Yomara ERP $NOUVELLE"
echo "Réussi    : ${ok[*]:-aucun}"
[ ${#ko[@]} -gt 0 ] && echo "En échec  : ${ko[*]}  (détails dans $LOGS)"
echo "Fichiers  :"
find "$SORTIE" -maxdepth 2 -type f \( -name '*.apk' -o -name '*.aab' -o -name '*.ipa' -o -name '*.pkg' -o -name '*.msix' \) -exec ls -lh {} \; | awk '{print "  " $5 "\t" $NF}'
echo
echo "À envoyer : .aab → Google Play (et .apk → AppGallery) · .ipa et .pkg → Transporter · .msix → Partner Center"
open "$SORTIE" 2>/dev/null || true
[ ${#ko[@]} -eq 0 ]
