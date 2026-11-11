#!/bin/bash
# Génère toutes les applications de la version : Android (APK + AAB), iPhone (IPA), Mac (PKG + DMG),
# Windows (MSIX Microsoft Store + installateur), Linux (.tar.gz, .deb, AppImage).
# Double-cliquez, confirmez la version, puis envoyez chaque fichier sur son store (liste fournie à la fin).
cd "$(dirname "$0")/source" || exit 1
chmod +x scripts/*.sh 2>/dev/null
./scripts/generer-tout.sh "$@"
read -r -p "Entree pour fermer."
