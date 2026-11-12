#!/usr/bin/env bash
# Yomara ERP — génère TOUTES les applications d'une version en une seule lancée, depuis le Mac :
#   Android   : App Bundle .aab (Google Play) + APK (AppGallery, téléchargement direct)
#   iPhone    : IPA (App Store Connect / Transporter)
#   Mac       : PKG (Mac App Store) + DMG (téléchargement direct)
#   Windows   : MSIX (Microsoft Store) + installateur .exe (téléchargement direct)
#   Linux     : .tar.gz + .deb + AppImage
#
# Windows et Linux ne se compilent pas sur un Mac : GitHub les compile EN MÊME TEMPS que le Mac fait le reste.
# Chaque étape continue même si une autre échoue ; le récapitulatif final dit ce qui manque et pourquoi.
#
#   ./scripts/generer-tout.sh              → version de pubspec.yaml, confirmée
#   ./scripts/generer-tout.sh 1.0.10+12    → autre version
#   SANS="ios github" ./scripts/generer-tout.sh   → saute des étapes (android ios mac-pkg dmg github)
#
# Résultat : ../sorties/stores-<version>/ (un dossier par plateforme) + LISTE-DES-FICHIERS.txt.
# Rien n'est envoyé aux stores : chaque fichier s'envoie ensuite depuis sa console.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
. "$ROOT/scripts/shorebird-commun.sh"
[ "$(uname)" = "Darwin" ] || { echo "Cette commande se lance sur le Mac." >&2; exit 1; }
command -v flutter >/dev/null 2>&1 || { echo "Flutter n'est pas installé." >&2; exit 1; }
SANS=" ${SANS:-} "
veut() { [[ "$SANS" != *" $1 "* ]]; }

# ---------------------------------------------------------------- 1. Version
ACTUELLE="$(grep -E '^version:' pubspec.yaml | awk '{print $2}')"
V="${1:-}"
if [ -z "$V" ]; then
  read -r -p "Version à générer [$ACTUELLE] (Entrée pour accepter) : " V
  V="${V:-$ACTUELLE}"
fi
[[ "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+\+[0-9]+$ ]] || { echo "Version invalide : $V (forme attendue 1.0.9+11)." >&2; exit 1; }
NOM="${V%%+*}"
sed -i '' -E "s/^version: .*/version: $V/" pubspec.yaml
sed -i '' -E "s/^([[:space:]]*msix_version:).*/\1 $NOM.0/" pubspec.yaml

SORTIE="${SORTIE:-$(cd .. && pwd)/sorties/stores-$NOM}"
LOGS="$SORTIE/journaux"
mkdir -p "$SORTIE" "$LOGS"
# Repère de début : seuls les fichiers produits pendant CETTE lancée comptent dans le récapitulatif
touch "$LOGS/.debut"
export SORTIE
DEBUT=$(date +%s)
echo "Yomara ERP $V — toutes les applications dans :"
echo "  $SORTIE"
echo

# ---------------------------------------------------------------- 2. GitHub (Windows, Linux, DMG) en arrière-plan
GH_PID=""
if veut github; then
  if ! command -v gh >/dev/null 2>&1 && command -v brew >/dev/null 2>&1; then
    echo "Installation de GitHub CLI (une seule fois)…"; brew install gh
  fi
  if command -v gh >/dev/null 2>&1 && ! gh auth status >/dev/null 2>&1; then
    echo "Connexion à GitHub (une seule fois) : GitHub.com, HTTPS, « Login with a web browser »."
    gh auth login --hostname github.com --git-protocol https --web
  fi
  if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
    EN_LIGNE="$(gh api "repos/$SB_REPO/contents/app/pubspec.yaml" -H 'Accept: application/vnd.github.raw' 2>/dev/null | grep -E '^version:' | awk '{print $2}')"
    [ "$EN_LIGNE" = "$V" ] || echo "Note : GitHub compile la version « ${EN_LIGNE:-inconnue} » publiée sur le dépôt (demandée : $V)."
    (
      # a) Avec les mises à jour à distance et la même version : construction Shorebird (correctifs possibles ensuite)
      if sb_actif && [ "$EN_LIGNE" = "$V" ]; then
        echo "Construction Shorebird (Windows, Mac, Linux)…"
        if TOUT=1 PLATEFORMES="windows,macos,linux" ./scripts/shorebird-github.sh version "$V" "$SORTIE"; then
          echo "$V" > "$ROOT/scripts/version-bureau.txt"; echo oui > "$ROOT/scripts/icones-bureau.txt"
          exit 0
        fi
        echo "Shorebird a échoué : construction classique à la place."
      fi
      # b) Construction classique (workflow « Applications ») : installateur + MSIX Windows, DMG, Linux
      AVANT="$(gh run list -R "$SB_REPO" -w build-apps.yml -L 1 --json databaseId -q '.[0].databaseId' 2>/dev/null || true)"
      gh workflow run build-apps.yml -R "$SB_REPO" --ref main || exit 1
      RUN=""
      for _ in $(seq 1 40); do
        sleep 5
        RUN="$(gh run list -R "$SB_REPO" -w build-apps.yml -L 1 --json databaseId -q '.[0].databaseId' 2>/dev/null)"
        [ -n "$RUN" ] && [ "$RUN" != "$AVANT" ] && break
      done
      [ -n "$RUN" ] && [ "$RUN" != "$AVANT" ] || { echo "La compilation n'a pas démarré sur GitHub."; exit 1; }
      echo "Suivi : https://github.com/$SB_REPO/actions/runs/$RUN"
      gh run watch "$RUN" -R "$SB_REPO" --exit-status --interval 30 >/dev/null
      W=$?
      # On récupère tout ce qui a réussi, même si une plateforme a échoué
      for a in windows macos linux; do gh run download "$RUN" -R "$SB_REPO" -n "$a" -D "$SORTIE/$a" 2>/dev/null && echo "Téléchargé : $a"; done
      exit $W
    ) >"$LOGS/github.log" 2>&1 &
    GH_PID=$!
    echo "GitHub compile Windows et Linux en parallèle (journal : journaux/github.log, 15 à 30 minutes)."
  else
    echo "GitHub CLI non connecté : Windows et Linux ne pourront pas être générés."
  fi
fi

# ---------------------------------------------------------------- 3. Sur le Mac, l'une après l'autre (un seul Flutter à la fois)
ECHECS=""   # bash 3.2 du Mac : pas de tableau associatif
etape() { # etape <clé> "Titre" commande…
  local cle="$1" titre="$2"; shift 2
  echo; echo "=================== $titre"
  if "$@" 2>&1 | tee "$LOGS/$cle.log"; [ "${PIPESTATUS[0]}" -eq 0 ]; then :; else ECHECS="$ECHECS $cle"; echo "→ $titre : échec (on continue)."; fi
}
veut android && etape android "Android : App Bundle (.aab) et APK"  env SORTIE="$SORTIE/android" ./scripts/construire-mobiles.sh android
veut ios     && etape ios     "iPhone : IPA"                         env SORTIE="$SORTIE/iphone"  ./scripts/construire-mobiles.sh ios
veut mac-pkg && etape mac-pkg "Mac App Store : PKG"                  env SORTIE="$SORTIE/mac"     ./scripts/envoyer-app-store.sh mac-pkg

# ---------------------------------------------------------------- 4. Attente de GitHub
if [ -n "$GH_PID" ]; then
  echo; echo "=================== Windows et Linux : attente de GitHub…"
  if wait "$GH_PID"; then :; else ECHECS="$ECHECS github"; fi
fi

# DMG : celui de GitHub s'il existe, sinon construit ici (signé avec votre certificat s'il est installé)
if veut dmg && ! find "$SORTIE" -type f -name '*.dmg' -not -path '*/journaux/*' -newer "$LOGS/.debut" | grep -q .; then
  etape dmg "Mac : DMG (téléchargement direct)" env SORTIE="$SORTIE/mac" ./scripts/construire-macos.sh
fi

# ---------------------------------------------------------------- 5. Récapitulatif : chaque format attendu, présent ou non
trouve() { find "$SORTIE" -type f -name "*.$1" -not -path '*/journaux/*' -newer "$LOGS/.debut" 2>/dev/null | sort; }
LISTE="$SORTIE/LISTE-DES-FICHIERS.txt"
{
  echo "Yomara ERP $V — $(date '+%d/%m/%Y %H:%M') — durée $(( ($(date +%s) - DEBUT) / 60 )) min"
  echo
  while IFS='|' read -r ext quoi ou journal; do
    f="$(trouve "$ext")"
    if [ -n "$f" ]; then
      echo "$f" | while read -r x; do printf '  OK      %-9s %7s  %s\n' "$ext" "$(du -h "$x" | cut -f1)" "${x#"$SORTIE"/}"; done
    else
      printf '  MANQUE  %-9s %s — voir journaux/%s.log\n' "$ext" "$quoi" "$journal"
    fi
  done <<'L'
aab|Google Play|Google Play Console|android
apk|AppGallery, téléchargement direct|AppGallery|android
ipa|App Store iPhone|Transporter|ios
pkg|Mac App Store|Transporter|mac-pkg
dmg|Mac, téléchargement direct|serveur|dmg
msix|Microsoft Store|Partner Center|github
exe|Windows, téléchargement direct|serveur|github
tar.gz|Linux|serveur|github
deb|Linux (Debian, Ubuntu)|serveur|github
AppImage|Linux (toutes distributions)|serveur|github
L
  echo
  echo "Où envoyer :"
  echo "  .aab        → Google Play Console (Production › Créer une version)"
  echo "  .apk        → Huawei AppGallery et téléchargement direct"
  echo "  .ipa, .pkg  → Transporter (App Store Connect) : iPhone et Mac App Store"
  echo "  .msix       → partner.microsoft.com › Yomara ERP › Packages"
  echo "  .exe, .dmg, .tar.gz, .deb, .AppImage → Super admin › Versions (page « Télécharger »)"
} | tee "$LISTE"

# Causes connues, expliquées simplement
conseil() { grep -qE "$2" "$LOGS/$1.log" 2>/dev/null && { echo; echo "→ $3"; }; }
SUIVANTE="${NOM}+$(( ${V##*+} + 1 ))"
conseil ios     "existing ios release for version" "iPhone : ce numéro est déjà enregistré pour les mises à jour à distance. Relancez avec le numéro suivant : $SUIVANTE (tapez-le quand la version est demandée)."
conseil android "existing android release for version" "Android : ce numéro est déjà enregistré pour les mises à jour à distance. Relancez avec le numéro suivant : $SUIVANTE."
conseil mac-pkg "No Accounts|No signing certificate \"Mac Installer Distribution\"" "Mac App Store : dans Xcode › Réglages › Comptes, ajoutez votre identifiant Apple (équipe Yomara), puis « Gérer les certificats » › + › « Mac Installer Distribution » (et « Apple Distribution » s'il manque). Relancez ensuite."
conseil ios     "No Accounts|No signing certificate|No profiles for" "iPhone : dans Xcode › Réglages › Comptes, connectez votre identifiant Apple puis « Gérer les certificats » › + › « Apple Distribution ». Relancez ensuite."
conseil github  "Échec sur GitHub" "Windows : la compilation GitHub a échoué ; ouvrez le lien de suivi indiqué dans journaux/github.log et envoyez une capture à Claude."

# Détail des échecs : les dernières lignes de chaque journal en échec
for k in $ECHECS; do
  echo; echo "---- $k : dernières lignes du journal ($LOGS/$k.log)"; tail -8 "$LOGS/$k.log"
done
open "$SORTIE" 2>/dev/null || true
grep -q "MANQUE" "$LISTE" && exit 1 || exit 0
