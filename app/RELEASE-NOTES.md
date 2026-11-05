# Notes de version

## 0.99.19
- Android : textes manquants corrigés (puces de filtre vides sur certains téléphones) : rendu Skia au lieu d'Impeller, comme sur Mac.
- Téléphone : titres courts dans la barre du haut et la barre du bas (« Ventes », « Modules »… au lieu de « Ventes d… » ou d'un libellé sur deux lignes).
- Formules (super admin) : réglage de l'essai gratuit lisible sur téléphone (un champ par ligne), cartes des formules en colonnes égales.
- Stores : App Bundle vérifié (Android 16 KB, cible SDK 36, versionCode tiré de pubspec) ; iPhone : descriptions d'accès aux photos et aux fichiers audio exigées par Apple ; projets Xcode prêts pour l'archive.

## 0.99.18
- Mac App Store : identifiant Mac aligné sur l'iPhone (com.yomaratechnologies.yomaraerp, achat universel), catégorie « Entreprise », déclaration de chiffrement (HTTPS seulement).
- Versions App Store (iPhone, iPad et Mac App Store) : boutique de modules, achats, renouvellements et « Mes installations » masqués (règle d'Apple sur les achats intégrés) ; les modules achetés sur le site ou la version web fonctionnent normalement.
- « Continuer avec Google » réservé à Android (sur iPhone, Apple exigerait aussi « Se connecter avec Apple »).
- Projets Xcode prêts pour l'archive : équipe de signature, signature automatique « Apple Development » sur Mac (au lieu de la signature locale), exécution renforcée, droits de notifications push sur iPhone (Runner.entitlements), iOS 15 minimum pour Firebase.
- Commande scripts/envoyer-app-store.sh (et Envoyer-sur-App-Store.command) : construit, signe et envoie la version Mac et/ou iPhone sur App Store Connect.
- Configuration du serveur › Notifications push et connexions externes : connexion des boîtes Gmail et Outlook désactivables (les boutons disparaissent, les comptes concernés ne sont plus relevés, l'IMAP reste possible), en plus de « Se connecter avec Google ».
- Préférences qui suivent l'utilisateur sur tous ses appareils : thème de la Boîte mail et tableau de bord choisi dans Rapports, en plus des favoris et du menu (les comptes mail, signatures et modèles étaient déjà sur le serveur).

## 0.99.17
- Prix des modules : 5 000 F/mois par défaut (palier Essentiel). Standard 10 000 F pour les modules qui coûtent à la plateforme : SMS, WhatsApp, e-mailing, site vitrine, boutique en ligne, messagerie hébergée, boîte mail, documents (GED), signature électronique, avis Google, réseaux sociaux. Premium 25 000 F : Assistant IA et visio (Yomeet). Agenda, rapports d'activité et migration restent gratuits.
- Mise à jour : seuls les modules encore au prix d'origine changent ; un prix déjà modifié par le super admin est conservé (annuel = 10 mois, achat définitif = 24 mois).
- Application macOS : texte brouillé corrigé sur les Mac à processeur graphique Intel/AMD (rendu Skia au lieu d'Impeller).
- iPhone et Mac : les commandes de construction corrigent toutes seules le format du projet réenregistré par Xcode 26 (erreur CocoaPods « shellScript »).

## 0.99.16
- Notifications push sur Android et iPhone (Firebase) : chaque notification de la cloche arrive aussi sur le téléphone, même appli fermée ; la toucher ouvre l'écran concerné. À activer dans Configuration du serveur › Notifications push et connexion Google (clé JSON du compte de service Firebase). Bouton « Tester les notifications » dans Paramètres.
- « Continuer avec Google » (facultatif) sur l'écran de connexion des téléphones : ouvre le compte qui a déjà la même adresse e-mail ; la connexion par e-mail professionnel et mot de passe reste la voie normale. Désactivable dans Configuration du serveur.
- Commande de création du keystore Android : scripts/generer-keystore.sh (Mac/Linux) et scripts/generer-keystore.ps1 (Windows) ; configure android/key.properties et affiche les empreintes SHA-1/SHA-256 à déclarer dans Firebase.
- iOS : identifiant de l'application aligné sur Firebase (com.yomaratechnologies.yomaraerp).
- Applications de bureau : commandes scripts/construire-macos.sh (app signée Developer ID ou locale, .dmg, notarisation Apple automatique si configurée) et scripts/construire-linux.sh (.tar.gz et .deb). Linux : démarre aussi sur un poste sans dossier « Documents ».
- « Personnes connectées » modernisé : bandeau en direct (sociétés, navigateur, mobile, ordinateur), recherche, filtre par société, cartes par personne avec « à l'instant / il y a X min ».
- Réseaux sociaux : le message d'un réseau verrouillé indique où le super admin renseigne les clés (Réseaux sociaux › Comptes › Applications des réseaux).

## 0.99.15
- Google Play : page publique « Politique de confidentialité » (/confidentialite) et page « Supprimer mon compte » (/suppression-compte) avec formulaire, liens dans le bas du site et à l'inscription.
- Application : « Supprimer mon compte » dans le menu du compte (et sur mobile en touchant son nom en haut du menu, ou dans Paramètres) : le compte est désactivé et déconnecté tout de suite ; l'administrateur peut demander la suppression de toute l'entreprise. Les demandes arrivent dans les demandes du site et par e-mail, à traiter sous 30 jours.
- Page de retour Google : une longue adresse ne déborde plus de la carte.

## 0.99.14
- Liens courts : domaine court au choix (Configuration du serveur › Liens courts, ex. yma.ci/ab12c) ; les liens ne montrent plus l'adresse du logiciel ; codes de 5 caractères ; avec le masquage, la destination reste cachée.
- Actualiser la page garde l'écran ouvert (l'adresse contient l'écran).
- Bouton Support : pastille ronde placée au-dessus des boutons des écrans (plus de chevauchement).
- Modernisés : statistiques d'un lien (grande fenêtre, en-tête, répartitions en cartes), panneau « Ma journée » (chiffres du jour, sélecteur Agenda / Tâches), panneau des notifications (filtre Toutes / Non lues, regroupées par jour).

## 0.99.13
- Avis Google : la fenêtre « Connexion à Google » attend la réponse de Google et se ferme toute seule dès que la fiche est connectée ; message clair sinon.
- Page de retour Google : indique la société et le compte Google connectés, et le problème éventuel (accès aux API Business pas encore accordé…).
- « Déconnecter » la fiche Google demande confirmation.

## 0.99.12
- Organigramme automatique « Par responsable » : dès qu'un employé a un responsable direct, il apparaît sous lui ; vue « Par fonction » aussi.
- Impression de l'organigramme, téléchargement en PDF et en Word (.docx).
- Fonctions & organigramme modernisés : bandeau avec chiffres clés, recherche, filtre par module, cartes avec équipes, arbre visuel relié.
- Fiche utilisateur : filtre des modules (tous, ouverts, fermés, par domaine) en plus de la recherche ; même filtre dans l'édition d'une fonction.
- Mise à jour : bandeau « Mise à jour du serveur à terminer » sur tous les écrans du super admin tant que la base n'est pas à jour ; l'interface web propose « Nouvelle interface » (recharger) dès qu'une nouvelle version est déposée.

## 0.99.11
- Fonctions & organigramme : chaque entreprise crée ses propres fonctions (Directeur commercial, Chef d'équipe…), les range sous leur supérieure, choisit leurs droits module par module, les duplique ; vue organigramme avec les personnes.
- Nouvelle fiche utilisateur en grand : identité, mot de passe généré, fonction, intitulé du poste, responsable direct, agences, et accès aux modules cochés directement (selon la fonction, ajoutés ou masqués pour la personne).
- Approbations : étape « Responsable hiérarchique du demandeur » (responsable direct, sinon la fonction au-dessus).
- Avis Google : explication claire quand le bouton « Connecter » est grisé.
- Favoris du menu (et menu réduit, groupes ouverts, épingles du tableau de bord) enregistrés sur le serveur pour chaque utilisateur : ils ne disparaissent plus à la déconnexion et suivent la personne sur tous ses appareils.
- Rapports d'activité : le responsable direct est proposé comme destinataire par défaut.

## 0.99.10
- Vérification complète : 224 écrans (ordinateur et téléphone), 39 comptes (super admin, administrateur, 37 rôles d'employés) et les pages publiques ; aucune erreur serveur ni d'affichage.
- Indicateurs sur toute la largeur de l'écran dans 30 modules, tableaux avec défilement au lieu de colonnes coupées, onglets plus lisibles, boutons secondaires plus nets.
- Écrans refaits sur téléphone : notes de frais (Approuver / Refuser), billetterie, questionnaires et fiches, Ma configuration, feuilles de temps, agenda (vue jour), boîte mail, rappel d'échéance plus court.
- Console éditeur, configuration du serveur (deux colonnes), moyens de paiement, plan de salle du restaurant, boutique en ligne modernisés.
- Sécurité : marge, dépenses et trésorerie du tableau de bord réservées aux personnes qui ont les droits finances ou rapports.
- Site public : bouton secondaire illisible sur fond sombre corrigé ; portail client sans débordement sur téléphone, menu complet sur ordinateur.

## 0.99.9
- Notifications de commande automatiques : accusé de réception au client (e-mail avec le détail de la commande, WhatsApp ou SMS), ticket du service client avec sa référence (SUP-…) pour suivre ses réponses, e-mails de confirmation et d'annulation, alerte de l'équipe. Réglages : Ventes › Notifications de commande.
- WordPress / WooCommerce : connexion de la boutique en un clic (approbation sur le site, plus de clés à copier).
- Sécurité : lien d'animateur de webinaire protégé, exports Excel/CSV sans formules piégées, fiches « mes fiches » invisibles des autres utilisateurs, limite d'agences des gestionnaires.

## 0.99.8
- Outils PDF : nouvel outil « Modifier le PDF » (corriger un texte, masquer, surligner, formes, flèches, dessin, signature, cachet, coches, date, supprimer et pivoter des pages).
- Boîte mail : noms de dossiers lisibles (Envoyés, Brouillons, Corbeille, Spam…), création, renommage et suppression de dossiers et sous-dossiers, rangement des discussions par glisser-déposer.
- Site public en français et en anglais (choix FR / EN, langue du navigateur) ; e-mails aux clients dans leur langue.
- Super admin : « Ma configuration » (tout ce qu'il faut régler pour vos clients), Marketing (e-mails clients et promotions, envoi d'un code promo en un clic).
- Mises à jour automatiques de l'édition libre (installation la nuit, sauvegarde et retour arrière) ; publication automatique des versions par la plateforme.
- Sécurité : fichiers WhatsApp, e-mails marketing, filigrane PDF, accès au réseau interne (WooCommerce, webhooks, boîtes mail), réglages sensibles de la société, liens de suivi.
- Licence AGPL-3.0, mentions de copyright, lien vers le code source.
