#!/bin/bash
# Nouvelle version en une fois : attend que votre serveur soit à jour, puis l'envoie aux applications installées
# (Android, iPhone, Windows, Mac DMG, Linux). Lancez-la, puis installez le paquet serveur comme d'habitude.
cd "$(dirname "$0")/source" || exit 1
chmod +x scripts/*.sh 2>/dev/null
./scripts/publier-version.sh "$@"
read -r -p "Entree pour fermer."
