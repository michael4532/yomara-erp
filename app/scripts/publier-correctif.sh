#!/usr/bin/env bash
# Yomara ERP — publie la version actuelle du code comme correctif des applications déjà installées
# (Android, iPhone, Windows, Mac DMG, Linux) : pas de store, pas de réinstallation.
# Les applications le téléchargent en arrière-plan et l'appliquent au redémarrage.
#
#   ./scripts/publier-correctif.sh                   → correctif pour la version de pubspec.yaml (ex. 1.0.2+4)
#   SANS="ios github" ./scripts/publier-correctif.sh → saute certains éléments (android ios github)
#
# Un correctif ne peut changer que le code Dart (écrans, règles, textes). Si une extension native, une
# permission, une image de l'application ou la version de Flutter change, Shorebird refuse : il faut alors
# une nouvelle version complète (CONSTRUIRE_TOUS_LES_STORES).
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
. "$ROOT/scripts/shorebird-commun.sh"
SANS=" ${SANS:-} "; veut() { [[ "$SANS" != *" $1 "* ]]; }
sb_actif || { echo "Mises à jour à distance pas encore activées : lancez d'abord ACTIVER-MISES-A-JOUR-A-DISTANCE.command." >&2; exit 1; }

V="$(grep -E '^version:' pubspec.yaml | awk '{print $2}')"
APPV="$(grep -E "appVersion = '" lib/core/config.dart | sed -E "s/.*'(.*)'.*/\1/")"
echo "Correctif Yomara ERP $APPV pour les applications installées en version $V"
echo

# GitHub (Windows, Mac DMG, Linux) compile les sources publiées : elles doivent être celles de ce Mac
if veut github; then
  command -v gh >/dev/null 2>&1 || { command -v brew >/dev/null 2>&1 && brew install gh; }
  gh auth status >/dev/null 2>&1 || gh auth login --hostname github.com --git-protocol https --web
  RAW() { gh api "repos/$SB_REPO/contents/app/$1" -H 'Accept: application/vnd.github.raw' 2>/dev/null; }
  GV="$(RAW pubspec.yaml | grep -E '^version:' | awk '{print $2}')"
  GA="$(RAW lib/core/config.dart | grep -E "appVersion = '" | sed -E "s/.*'(.*)'.*/\1/")"
  if [ "$GV" != "$V" ] || [ "$GA" != "$APPV" ]; then
    echo "Attention : GitHub a les sources $GA ($GV), ce Mac $APPV ($V)."
    echo "Windows, Mac DMG et Linux sont sautés ; publiez d'abord les sources, puis : SANS=\"android ios\" $0"
    SANS="$SANS github "
  fi
fi

read -r -p "Publier ce correctif pour tous les utilisateurs ? (o/N) " R
[[ "$R" =~ ^[oOyY] ]] || exit 0

LOGS="$(cd .. && pwd)/sorties/correctifs/$APPV"; mkdir -p "$LOGS"
ok=(); ko=(); GH_PID=""
if veut github; then
  echo "=================== Windows, Mac DMG, Linux : correctif lancé sur GitHub (journal : $LOGS/github.log)"
  ./scripts/shorebird-github.sh correctif "$V" >"$LOGS/github.log" 2>&1 &
  GH_PID=$!
fi
etape() {
  echo; echo "=================== $1"
  if "${@:2}" 2>&1 | tee "$LOGS/$(echo "$1" | tr ' ' '-' | tr '[:upper:]' '[:lower:]').log"; [ "${PIPESTATUS[0]}" -eq 0 ]; then ok+=("$1"); else ko+=("$1"); fi
}
veut android && etape "Android" shorebird patch android --release-version="$V" --track=stable
if veut ios && [ "$(uname)" = Darwin ]; then
  TEAM="${APPLE_TEAM_ID:-$(cat "$HOME/.yomara-keys/apple-team-id" 2>/dev/null || true)}"
  if [ -n "$TEAM" ]; then
    sb_export_ios "$ROOT/build/ExportOptions.plist" "$TEAM"
    etape "iPhone" shorebird patch ios --release-version="$V" --track=stable --export-options-plist="$ROOT/build/ExportOptions.plist"
  else
    ko+=("iPhone (Team ID Apple inconnu : lancez une fois Construire-iphone-signe)")
  fi
fi
if [ -n "$GH_PID" ]; then
  echo; echo "=================== Attente de GitHub (Windows, Mac DMG, Linux)…"
  if wait "$GH_PID"; then ok+=("Windows, Mac DMG, Linux"); else ko+=("Windows/Mac DMG/Linux"); tail -5 "$LOGS/github.log"; fi
fi
echo
echo "================================================================"
echo "Correctif Yomara ERP $APPV (applications $V)"
echo "Publié     : ${ok[*]:-aucun}"
[ ${#ko[@]} -gt 0 ] && echo "En échec   : ${ko[*]}  (détails dans $LOGS)"
echo "Les applications le reçoivent dans l'heure et l'appliquent au redémarrage."
echo "Mac App Store : pas de correctif à distance, il faut une nouvelle version store."
[ ${#ko[@]} -eq 0 ]
