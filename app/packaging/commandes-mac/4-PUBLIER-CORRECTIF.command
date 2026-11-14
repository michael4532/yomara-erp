#!/bin/bash
# Envoie la nouvelle version du code aux applications déjà installées (Android, iPhone, Windows, Mac DMG, Linux),
# sans store ni réinstallation : elles la reçoivent dans l'heure et l'appliquent au redémarrage.
cd "$(dirname "$0")/source" || exit 1
chmod +x scripts/*.sh 2>/dev/null
./scripts/publier-correctif.sh "$@"
read -r -p "Entree pour fermer."
