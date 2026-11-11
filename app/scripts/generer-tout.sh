#!/usr/bin/env bash
# Yomara ERP — génère TOUTES les applications d'une version, en une commande, depuis le Mac :
#   Android   : APK (AppGallery, téléchargement direct) + App Bundle .aab (Google Play)
#   iPhone    : IPA (App Store Connect / Transporter)
#   Mac       : PKG (Mac App Store) + DMG (téléchargement direct)
#   Windows   : MSIX (Microsoft Store) + installateur .exe et ZIP (téléchargement direct)
#   Linux     : .tar.gz + .deb + AppImage
# Windows et Linux se compilent sur GitHub (Mac impossible pour ces systèmes) pendant que le Mac fait le reste.
#
#   ./scripts/generer-tout.sh              → version de pubspec.yaml (1.0.9+11 préparée par Claude), confirmée
#   ./scripts/generer-tout.sh 1.0.10+12    → autre version
#   SANS="ios" ./scripts/generer-tout.sh   → saute des éléments (android ios mac msix)
#
# Résultat : ../sorties/stores-<version>/ avec un dossier par plateforme et une liste récapitulative.
# Rien n'est envoyé aux stores : chaque fichier s'envoie ensuite depuis sa console.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
. "$ROOT/scripts/shorebird-commun.sh"
[ "$(uname)" = "Darwin" ] || { echo "Cette commande se lance sur le Mac." >&2; exit 1; }

ACTUELLE="$(grep -E '^version:' pubspec.yaml | awk '{print $2}')"
V="${1:-}"
if [ -z "$V" ]; then
  read -r -p "Version à générer [$ACTUELLE] (Entrée pour accepter) : " V
  V="${V:-$ACTUELLE}"
fi
[[ "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+\+[0-9]+$ ]] || { echo "Version invalide : $V (forme attendue 1.0.9+11)." >&2; exit 1; }
NOM="${V%%+*}"
SORTIE="${SORTIE:-$(cd .. && pwd)/sorties/stores-$NOM}"
export SORTIE

# Le paquet serveur et l'application doivent annoncer la même version au moment de la publication
echo "Yomara ERP $V — génération de toutes les applications dans $SORTIE"
echo

# 1. Android, iPhone, Mac PKG sur ce Mac ; Microsoft, DMG et Linux sur GitHub (avec Shorebird)
TOUT=1 ./scripts/construire-stores.sh "$V"
CODE=$?

# 2. Sans Shorebird : les logiciels de bureau viennent du workflow « Applications »
if ! sb_actif && command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
  echo; echo "=================== Windows, Mac DMG, Linux : compilation sur GitHub (workflow Applications)"
  AVANT="$(gh run list -R "$SB_REPO" -w build-apps.yml -L 1 --json databaseId -q '.[0].databaseId' 2>/dev/null || true)"
  gh workflow run build-apps.yml -R "$SB_REPO" --ref main
  RUN=""
  for _ in $(seq 1 30); do sleep 5; RUN="$(gh run list -R "$SB_REPO" -w build-apps.yml -L 1 --json databaseId -q '.[0].databaseId')"; [ -n "$RUN" ] && [ "$RUN" != "$AVANT" ] && break; done
  if [ -n "$RUN" ] && [ "$RUN" != "$AVANT" ] && gh run watch "$RUN" -R "$SB_REPO" --exit-status --interval 30 >/dev/null; then
    for a in windows macos linux; do gh run download "$RUN" -R "$SB_REPO" -n "$a" -D "$SORTIE/$a" 2>/dev/null; done
  else
    echo "Échec de la compilation GitHub des logiciels de bureau."; CODE=1
  fi
fi

# 3. Récapitulatif complet
echo
echo "================================================================"
echo "Yomara ERP $V — fichiers générés"
LISTE="$SORTIE/LISTE-DES-FICHIERS.txt"
{
  echo "Yomara ERP $V — $(date '+%d/%m/%Y %H:%M')"
  echo
  for ext in aab apk ipa pkg dmg msix exe zip tar.gz deb AppImage; do
    find "$SORTIE" -type f -name "*.$ext" -not -path '*/journaux/*' 2>/dev/null | sort | while read -r f; do
      printf '%-10s %8s  %s\n' "$ext" "$(du -h "$f" | cut -f1)" "${f#"$SORTIE"/}"
    done
  done
  echo
  echo "Où envoyer :"
  echo "  .aab        → Google Play Console (Production › Créer une version)"
  echo "  .apk        → Huawei AppGallery et téléchargement direct"
  echo "  .ipa, .pkg  → Transporter (App Store Connect), iPhone et Mac App Store"
  echo "  .msix       → partner.microsoft.com › Yomara ERP › Packages"
  echo "  .exe, .dmg, .tar.gz, .deb, AppImage → page « Télécharger » du serveur (Super admin › Versions)"
} | tee "$LISTE"
open "$SORTIE" 2>/dev/null || true
exit $CODE
