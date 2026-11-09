#!/usr/bin/env bash
# Yomara ERP — active les mises à jour à distance (Shorebird), une seule fois, sur le Mac.
#   1. installe la commande shorebird ;
#   2. connexion à votre compte Shorebird (navigateur) ;
#   3. enregistre l'application chez Shorebird (fichier shorebird.yaml, sans secret) ;
#   4. enregistre la clé Shorebird dans GitHub (secret SHOREBIRD_TOKEN) pour Windows, Mac DMG et Linux.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
. "$ROOT/scripts/shorebird-commun.sh"

if ! command -v shorebird >/dev/null 2>&1; then
  echo "== Installation de Shorebird (quelques minutes, une seule fois)"
  curl --proto '=https' --tlsv1.2 -sSf https://raw.githubusercontent.com/shorebirdtech/install/main/install.sh | bash
  export PATH="$HOME/.shorebird/bin:$PATH"
fi
command -v shorebird >/dev/null 2>&1 || { echo "Installation de Shorebird impossible." >&2; exit 1; }

echo; echo "== Connexion à Shorebird (le navigateur s'ouvre ; créez le compte si besoin)"
shorebird login || exit 1

if [ ! -f shorebird.yaml ]; then
  echo; echo "== Enregistrement de l'application « Yomara ERP » chez Shorebird"
  shorebird init || exit 1
fi
echo "Identifiant de l'application : $(grep -E '^app_id' shorebird.yaml)"
shorebird doctor || true

echo
echo "== Clé pour GitHub (Windows, Mac DMG, Linux)"
echo "Dans la console Shorebird (console.shorebird.dev) : Settings › Service accounts › créer « github » (rôle Developer)"
echo "› API keys › Create key. Copiez la clé (elle n'est montrée qu'une fois)."
command -v gh >/dev/null 2>&1 || { command -v brew >/dev/null 2>&1 && brew install gh; }
gh auth status >/dev/null 2>&1 || gh auth login --hostname github.com --git-protocol https --web
read -r -p "Clé copiée ? Entrée pour la coller (elle reste masquée), ou « passer » : " R
if [ "$R" != passer ]; then
  gh secret set SHOREBIRD_TOKEN -R "$SB_REPO" && echo "Clé enregistrée dans GitHub (secret SHOREBIRD_TOKEN)."
fi
echo
echo "Terminé. Dites à Claude que Shorebird est activé : il publiera shorebird.yaml avec les sources."
echo "Ensuite : CONSTRUIRE_TOUS_LES_STORES (une fois, version de base), puis PUBLIER-CORRECTIF à chaque mise à jour."
