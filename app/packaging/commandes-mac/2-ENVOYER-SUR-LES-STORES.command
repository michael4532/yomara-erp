#!/bin/bash
# Étape 2, après 1-GENERER-TOUTES-LES-APPS : ouvre le dossier des fichiers produits et les sites où les envoyer.
# Rien n'est envoyé tout seul : vous déposez chaque fichier, connecté avec vos propres comptes.
cd "$(dirname "$0")" || exit 1
D="$(ls -dt sorties/stores-* 2>/dev/null | head -1)"
if [ -z "$D" ]; then
  echo "Aucun dossier sorties/stores-… : lancez d'abord 1-GENERER-TOUTES-LES-APPS."
  read -r -p "Entree pour fermer."; exit 1
fi
echo "Fichiers de la version : $D"
echo
[ -f "$D/LISTE-DES-FICHIERS.txt" ] && cat "$D/LISTE-DES-FICHIERS.txt" && echo
f() { find "$D" -name "*.$1" 2>/dev/null | head -1; }
echo "À faire, dans l'ordre :"
echo "  a. iPhone et Mac App Store : glissez dans Transporter"
echo "       $(f ipa)"
echo "       $(f pkg)"
echo "  b. Google Play : Play Console › Yomara ERP › Production › Créer une version › importer"
echo "       $(f aab)"
echo "  c. Microsoft Store : Partner Center › Yomara ERP › Packages › importer"
echo "       $(f msix)"
echo "  d. Téléchargement direct : Super admin › Versions › ajoutez .exe, .dmg, .deb, .AppImage, .tar.gz"
echo "  e. Mettez le serveur en ligne : production/yomara-erp-serveur-v….zip"
echo "     puis vérifiez https://erp.yomaratechnologies.com/api/ping (« version »)."
echo
echo "Ensuite, pour les mises à jour suivantes sans passer par les stores : 3-PUBLIER-VERSION."
open "$D"
if [ -d "/Applications/Transporter.app" ]; then open -a Transporter; else open "macappstore://apps.apple.com/app/transporter/id1450874784"; fi
open "https://play.google.com/console"
open "https://partner.microsoft.com/dashboard/apps-and-games/overview"
open "https://appstoreconnect.apple.com/apps"
read -r -p "Entree pour fermer."
