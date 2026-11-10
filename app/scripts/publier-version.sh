#!/usr/bin/env bash
# Yomara ERP — publication d'une nouvelle version en une seule commande.
#   1. Vous installez le paquet serveur (yomara-erp-serveur-vX.zip) sur votre serveur, comme d'habitude.
#   2. Cette commande attend que le serveur annonce cette version, puis envoie aussitôt la même version
#      aux applications installées (Android, iPhone, Windows, Mac DMG, Linux) : PUBLIER-CORRECTIF sans question.
# Vous pouvez la lancer avant, pendant ou après l'installation du serveur.
#   SERVEUR=https://autre-domaine ./scripts/publier-version.sh   → autre serveur à surveiller
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
SERVEUR="${SERVEUR:-https://erp.yomaratechnologies.com}"
APPV="$(grep -E "appVersion = '" lib/core/config.dart | sed -E "s/.*'(.*)'.*/\1/")"
ATTENTE_MAX="${ATTENTE_MAX:-7200}" # 2 heures

version_serveur() { curl -fsS --max-time 15 "$SERVEUR/api/ping" 2>/dev/null | sed -nE 's/.*"version":"([^"]+)".*/\1/p'; }

echo "Yomara ERP $APPV"
echo "Serveur surveillé : $SERVEUR"
echo
debut=$(date +%s); dit=0
while :; do
  SV="$(version_serveur)"
  if [ "$SV" = "$APPV" ]; then
    echo "Serveur en $SV : envoi aux applications installées."
    break
  fi
  if [ $(( $(date +%s) - debut )) -ge "$ATTENTE_MAX" ]; then
    echo "Le serveur n'est toujours pas en $APPV (il annonce « ${SV:-injoignable} ») : rien n'a été envoyé." >&2
    exit 1
  fi
  if [ $dit = 0 ]; then
    echo "Le serveur annonce « ${SV:-injoignable} ». Installez maintenant yomara-erp-serveur-v$APPV.zip (dossier ERP/production) ;"
    echo "l'envoi aux applications partira tout seul dès que le serveur sera à jour. (Ctrl+C pour annuler)"
    dit=1
  fi
  sleep 30
done
echo
OUI=1 ./scripts/publier-correctif.sh "$@"
