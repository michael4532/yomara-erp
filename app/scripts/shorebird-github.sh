#!/usr/bin/env bash
# Yomara ERP — lance sur GitHub la construction Shorebird des logiciels Windows, macOS (DMG) et Linux.
#   ./scripts/shorebird-github.sh version   1.0.2+4  [SORTIE]   → version de base (+ paquet MSIX téléchargé dans SORTIE)
#   ./scripts/shorebird-github.sh correctif 1.0.2+4             → correctif pour cette version
# GitHub compile les sources publiées sur le dépôt michael4532/yomara-erp (branche main).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/scripts/shorebird-commun.sh"
MODE="$1"; V="$2"; OUT="${3:-}"; PLATEFORMES="${PLATEFORMES:-windows,macos,linux}"

command -v gh >/dev/null 2>&1 || { command -v brew >/dev/null 2>&1 && brew install gh; }
gh auth status >/dev/null 2>&1 || gh auth login --hostname github.com --git-protocol https --web

echo "GitHub : $MODE $V ($PLATEFORMES)"
AVANT="$(gh run list -R "$SB_REPO" -w shorebird.yml -L 1 --json databaseId -q '.[0].databaseId' 2>/dev/null || true)"
gh workflow run shorebird.yml -R "$SB_REPO" --ref main -f mode="$MODE" -f release_version="$V" -f plateformes="$PLATEFORMES" -f icones_completes="${ICONES:-oui}"
RUN=""
for _ in $(seq 1 30); do
  sleep 5
  RUN="$(gh run list -R "$SB_REPO" -w shorebird.yml -L 1 --json databaseId -q '.[0].databaseId')"
  [ -n "$RUN" ] && [ "$RUN" != "$AVANT" ] && break
done
[ -n "$RUN" ] && [ "$RUN" != "$AVANT" ] || { echo "La construction n'a pas démarré sur GitHub." >&2; exit 1; }
echo "Suivi : https://github.com/$SB_REPO/actions/runs/$RUN (15 à 30 minutes)"
gh run watch "$RUN" -R "$SB_REPO" --exit-status --interval 30 >/dev/null || { echo "Échec sur GitHub : voir le lien ci-dessus." >&2; exit 1; }
if [ "$MODE" = version ] && [ -n "$OUT" ]; then
  mkdir -p "$OUT"
  gh run download "$RUN" -R "$SB_REPO" -n msix -D "$OUT" 2>/dev/null && echo "Paquet Microsoft Store : $(ls "$OUT"/*.msix | head -1)"
  # Tous les logiciels de bureau (TOUT=1) : installateur Windows, DMG Mac, paquets Linux
  if [ "${TOUT:-0}" = 1 ]; then
    for a in windows macos linux; do
      gh run download "$RUN" -R "$SB_REPO" -n "$a" -D "$OUT/$a" 2>/dev/null && echo "Logiciels $a : $OUT/$a" || echo "Pas de fichiers $a sur GitHub."
    done
  fi
fi
echo "GitHub : terminé."
