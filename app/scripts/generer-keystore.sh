#!/usr/bin/env bash
# Yomara ERP — crée la clé de signature Android (keystore) à son emplacement et configure le projet.
#
#   ./scripts/generer-keystore.sh                 → ~/.yomara-keys/yomara-erp-upload.jks
#   ./scripts/generer-keystore.sh /chemin/cle.jks → à l'emplacement choisi
#
# Le script :
#   1. crée le keystore (les mots de passe sont demandés, jamais écrits à l'écran) ;
#   2. écrit android/key.properties (utilisé par « flutter build appbundle ») ;
#   3. affiche les empreintes SHA-1 et SHA-256 à ajouter dans Firebase (connexion Google).
# Gardez une copie du keystore et des mots de passe en lieu sûr : sans eux, plus de mise à jour sur Google Play.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STORE="${1:-$HOME/.yomara-keys/yomara-erp-upload.jks}"
ALIAS="${ALIAS:-yomara-erp}"
PROPS="$ROOT/android/key.properties"

command -v keytool >/dev/null 2>&1 || {
  # keytool est fourni avec Java ; Android Studio en embarque un
  for j in "/Applications/Android Studio.app/Contents/jbr/Contents/Home/bin" "$HOME/Applications/Android Studio.app/Contents/jbr/Contents/Home/bin" "/opt/android-studio/jbr/bin"; do
    [ -x "$j/keytool" ] && PATH="$j:$PATH" && break
  done
}
command -v keytool >/dev/null 2>&1 || { echo "keytool introuvable : installez Java (JDK 17) ou Android Studio." >&2; exit 1; }

if [ -f "$STORE" ]; then
  echo "Un keystore existe déjà : $STORE"
  read -r -p "Le garder et seulement (re)configurer le projet ? [O/n] " keep
  if [[ "${keep:-O}" =~ ^[nN] ]]; then echo "Rien n'a été modifié. Choisissez un autre chemin en argument."; exit 1; fi
  read -r -s -p "Mot de passe du keystore : " PASS; echo
else
  mkdir -p "$(dirname "$STORE")"
  chmod 700 "$(dirname "$STORE")" 2>/dev/null || true
  while :; do
    read -r -s -p "Mot de passe du keystore (6 caractères minimum) : " PASS; echo
    read -r -s -p "Confirmez : " PASS2; echo
    [ "$PASS" = "$PASS2" ] && [ ${#PASS} -ge 6 ] && break
    echo "Les mots de passe ne correspondent pas ou sont trop courts, recommencez."
  done
  read -r -p "Nom de l'entreprise [Yomara Technologies] : " ORG; ORG="${ORG:-Yomara Technologies}"
  read -r -p "Ville [Abidjan] : " CITY; CITY="${CITY:-Abidjan}"
  keytool -genkeypair -v -keystore "$STORE" -alias "$ALIAS" -keyalg RSA -keysize 2048 -validity 10000 \
    -storepass "$PASS" -keypass "$PASS" -dname "CN=$ORG, O=$ORG, L=$CITY, C=CI" >/dev/null
  chmod 600 "$STORE" 2>/dev/null || true
  echo "Keystore créé : $STORE"
fi

# Vérifie le mot de passe et l'alias avant d'écrire la configuration
keytool -list -keystore "$STORE" -storepass "$PASS" -alias "$ALIAS" >/dev/null 2>&1 || {
  echo "Mot de passe incorrect ou alias « $ALIAS » absent (ALIAS=autre ./scripts/generer-keystore.sh pour changer)." >&2; exit 1; }

umask 077
cat > "$PROPS" <<PROP
storePassword=$PASS
keyPassword=$PASS
keyAlias=$ALIAS
storeFile=$STORE
PROP
echo "Projet configuré : android/key.properties (jamais dans le code source livré)."

echo
echo "Empreintes à ajouter dans Firebase › Paramètres du projet › Application Android › Ajouter une empreinte :"
keytool -list -v -keystore "$STORE" -storepass "$PASS" -alias "$ALIAS" | grep -E "SHA1:|SHA256:" | sed 's/^[[:space:]]*/  /'
echo
echo "Pensez aussi à ajouter l'empreinte de la « clé de signature d'application » de Google Play"
echo "(Play Console › Test et publication › Configuration › Intégrité de l'application)."
echo
echo "Construire l'application pour Google Play :  flutter build appbundle --release"
