#!/bin/bash
# Active les mises à jour à distance (Shorebird), une seule fois : installation, connexion, enregistrement de l'application.
cd "$(dirname "$0")/source" || exit 1
chmod +x scripts/*.sh 2>/dev/null
./scripts/activer-shorebird.sh
read -r -p "Entree pour fermer."
