#!/usr/bin/env bash
# Yomara ERP — génère le paquet Microsoft Store (.msix) depuis un Mac ou Linux.
# Le paquet Windows ne peut être construit que sous Windows : la commande lance la
# compilation sur GitHub (dépôt public michael4532/yomara-erp), attend la fin
# puis télécharge le fichier dans  sorties/msix/.
#
#   ./scripts/generer-msix.sh
#
# Prérequis (une seule fois) :
#   brew install gh   puis   gh auth login   (compte GitHub michael4532)
# La version envoyée est celle de pubspec.yaml sur la branche main du dépôt GitHub
# (pensez à publier les sources avant : la version du .msix doit être plus élevée
# que la précédente envoyée au Store).
set -euo pipefail
REPO="${YOMARA_REPO:-michael4532/yomara-erp}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${MSIX_OUT:-$ROOT/sorties/msix}"

if ! command -v gh >/dev/null 2>&1; then
  if command -v brew >/dev/null 2>&1; then echo "Installation de GitHub CLI (une seule fois)…"; brew install gh
  else echo "Installez GitHub CLI : https://cli.github.com (ou brew install gh)" >&2; exit 1; fi
fi
if ! gh auth status >/dev/null 2>&1; then
  echo "Connexion à GitHub (une seule fois) : choisissez GitHub.com, HTTPS, puis « Login with a web browser »."
  gh auth login --hostname github.com --git-protocol https --web
fi

# Version : MSIX_VERSION=1.0.2 impose le numéro (même que le Mac), sinon celui de pubspec.yaml sur GitHub
V="${MSIX_VERSION:-$(gh api "repos/$REPO/contents/app/pubspec.yaml" -H 'Accept: application/vnd.github.raw' | grep -E '^version:' | awk '{print $2}' | cut -d+ -f1)}"
echo "Yomara ERP $V — paquet Microsoft Store ($V.0)"
echo "Lancement de la compilation Windows sur GitHub…"
AVANT="$(gh run list -R "$REPO" -w msix.yml -L 1 --json databaseId -q '.[0].databaseId' 2>/dev/null || true)"
gh workflow run msix.yml -R "$REPO" --ref main -f version="$V"
RUN=""
for _ in $(seq 1 30); do
  sleep 5
  RUN="$(gh run list -R "$REPO" -w msix.yml -L 1 --json databaseId -q '.[0].databaseId')"
  [ -n "$RUN" ] && [ "$RUN" != "$AVANT" ] && break
done
[ -n "$RUN" ] && [ "$RUN" != "$AVANT" ] || { echo "La compilation n'a pas démarré sur GitHub." >&2; exit 1; }
echo "Compilation n° $RUN (environ 10 minutes) : https://github.com/$REPO/actions/runs/$RUN"
gh run watch "$RUN" -R "$REPO" --exit-status --interval 30 >/dev/null || { echo "La compilation a échoué : voir le lien ci-dessus." >&2; exit 1; }

mkdir -p "$OUT"
gh run download "$RUN" -R "$REPO" -n msix -D "$OUT"
F="$(ls -t "$OUT"/*"$V"*.msix 2>/dev/null | head -1)"
[ -n "$F" ] || F="$(ls -t "$OUT"/*.msix | head -1)"
echo
echo "Paquet prêt : $F ($(du -h "$F" | cut -f1))"
echo "À envoyer sur partner.microsoft.com › Yomara ERP › mise à jour › Packages."
[ "$(uname)" = "Darwin" ] && open -R "$F" || true
