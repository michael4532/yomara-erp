# Notes de version

## 0.99.39 — signatures en image, recherche façon Outlook, e-mail de bienvenue, campagnes repensées
- Signatures : nouveau mode « Image » (bannière ou carte de visite à la place du texte, largeur réglable de 200 à 800 px, lien au clic facultatif) ; chaque ligne du générateur peut être retirée (×) ; les images insérées en HTML sont à taille réelle (600 px, adaptées à l'écran) au lieu de 160 px.
- Recherche dans la messagerie : dans toutes les boîtes par défaut (ou seulement le dossier ouvert), « Meilleurs résultats » puis « Tous les résultats », dossier de chaque message (Boîte de réception, Éléments envoyés…), étiquette « Brouillon » et mots recherchés surlignés.
- E-mail de bienvenue : logo Yomara et toutes les applications (Microsoft Store, Mac App Store, Google Play, App Store, plus les fichiers à télécharger quand ils sont sur le serveur).
- Super admin › Nouvelle campagne : étapes numérotées (Destinataires, Canaux, Message, Quand ?), choix en tuiles au lieu des listes, compteurs sociétés / personnes / e-mails, aperçu en direct de la notification et de l'e-mail à droite (grand écran) avec récapitulatif, et résumé de l'envoi dans la barre du bas.

## 0.99.38 — barre du bas personnalisable
Audit avant mise à jour
- Mise à jour automatique (navigateur) : jamais de rechargement pendant une saisie, un message en rédaction ou une réunion vidéo, ni quand l'onglet est en arrière-plan (le compte à rebours attend) ; « Plus tard » est respecté jusqu'à la prochaine ouverture ; un onglet ne bloque plus la mise à jour des autres ; les paramètres de l'adresse (lien d'invitation…) sont conservés.
- Applications installées : redémarrage après mise à jour fiable (base locale fermée avant la relance, AppImage Linux).
- Alertes de nouveaux e-mails effacées à la déconnexion (ordinateur partagé).
- Fenêtres centrées sur tablette : l'espace du clavier n'est plus compté deux fois.
- Messagerie sur téléphone : la barre d'actions du message défile au lieu de déborder.
- Publications : recherche instantanée sans rappel du serveur, filtres conservés après une validation, texte propre à chaque réseau affiché correctement, plus d'erreur quand un réseau ne renvoie aucune statistique, cartes et grille adaptées au téléphone, recherche sur toute la largeur.
- Google Business : toutes les fiches sont récupérées (comptes et établissements au-delà de la première page), message plus juste quand Google limite l'accès, détail technique gardé dans le journal du serveur.
- TikTok : plus de lien de vidéo invalide quand le nom d'utilisateur n'est pas fourni.
- Constructions : mises à jour à distance Mac (DMG) signées correctement, version Mac App Store sans l'exception inutile, réglages Swift Package Manager appliqués partout.
- Téléphone : chacun choisit les raccourcis de la barre du bas (jusqu'à 3, dans l'ordre voulu) entre « Accueil » et « Plus », qui restent fixes. Restez appuyé sur la barre, ou menu › Barre du bas. Le choix suit l'utilisateur sur tous ses appareils ; « Par défaut » revient au choix automatique.

## 0.99.37 — Google Business et TikTok
- Google Business Profile : si Google refuse l'accès aux fiches (accès API pas encore accordé, API non activées, aucune fiche gérée par le compte), la page de connexion l'explique clairement au lieu d'indiquer seulement « connecté » pour YouTube.
- TikTok : la connexion ne demande plus que les informations couvertes par l'autorisation de base (nom affiché, photo), pour éviter un refus.
- Applications : mises à jour à distance (Shorebird) activées pour les prochaines versions installées.

## 0.99.36 — mises à jour automatiques
- Navigateur : quand le serveur est mis à jour, l'application web vide son cache et se recharge toute seule dans la nouvelle version (aussitôt à l'ouverture ou si l'onglet est en arrière-plan ; sinon après un compte à rebours de 20 s, avec « Plus tard »). Connexion et saisies hors ligne en attente sont conservées.
- Serveur : l'interface web n'est plus gardée en cache par les navigateurs sans vérification (en-têtes Cache-Control).
- Applications installées (Android, iPhone, Windows, Mac, Linux) : prêtes pour les mises à jour à distance (Shorebird). Une fois activées, chaque nouvelle version leur arrive en arrière-plan sans store ni réinstallation, puis s'applique au redémarrage (bouton « Redémarrer » sur ordinateur).
- Commandes Mac : ACTIVER-MISES-A-JOUR-A-DISTANCE (une seule fois) et PUBLIER-CORRECTIF (à chaque mise à jour) ; CONSTRUIRE_TOUS_LES_STORES enregistre les versions de base.

## 0.99.35 — messagerie et Publications modernisées, fenêtres centrées
- Messagerie : nouvel affichage (dossiers, liste, lecture), plus clair et plus lisible, en clair et en sombre.
- Nouveaux e-mails : ils apparaissent dans la cloche de notifications en haut (expéditeur, objet, heure), au lieu d'une barre en bas de l'écran ; un clic ouvre le message.
- Sur ordinateur et tablette, les panneaux qui montaient du bas de l'écran (détail d'une publication, choix, formulaires courts…) s'ouvrent en fenêtre centrée ; sur téléphone rien ne change.
- Réseaux sociaux › Publications : filtre « Toutes » en premier avec le nombre par statut, recherche, filtre par réseau, vue liste ou grille, totaux (vues, j'aime, commentaires), vignettes (y compris la miniature YouTube) et aperçu à droite tel que sur le réseau, avec le résultat par compte.
- Ma journée : section « Terminées » qui montre ce qui a été fait.
- Messagerie et Visio dans le navigateur : un clic sur un panneau ou un menu ouvert par-dessus un e-mail n'ouvre plus le lien de l'e-mail situé dessous.
- TikTok : la connexion demande aussi le droit de publier des vidéos.

## 0.99.34 — pages légales pour les réseaux sociaux, Mac
- Nouvelle page « Conditions d'utilisation » (/conditions), liée en pied de page et dans le plan du site : demandée par YouTube, TikTok et Pinterest.
- Politique de confidentialité : section « Réseaux sociaux connectés » (données reçues, usage, conservation, déconnexion) et encadré Services API YouTube (conditions YouTube, règles de Google, retrait de l'accès).
- Page d'accueil : publication sur les réseaux sociaux et paie aux règles locales.
- Applications Mac, iPhone et Linux : la limite de fichiers ouverts est relevée au démarrage (corrige « Too many open files » au lancement sur Mac) ; message d'erreur plus clair si cela se reproduisait.

## 0.99.33 — audit complet : failles et bugs corrigés
Sécurité serveur
- Mise à jour : un paquet venant du serveur de mises à jour est toujours contrôlé par sa signature Yomara ; numéro de version vérifié.
- Séparation des sociétés renforcée partout : clients, comptes de trésorerie, agences, sessions de caisse, véhicules, propriétaires (Immobilier), contacts WhatsApp, règles bancaires, écritures comptables, invités d'agenda, utilisateurs (Analytique)… ne peuvent plus pointer vers une autre société ni en afficher les données.
- Synchronisation : une saisie qui vise un élément d'une autre société ou invisible pour la personne est refusée proprement ; clé API limitée à la société de son créateur ; super administrateur désactivé refusé.
- Appels sortants protégés contre les adresses internes : FNE, Shopify (jeton envoyé seulement à la boutique), serveur d'envoi SMTP des sociétés (SaaS), transcription Visio.
- Fichiers déposés (CV, justificatifs, factures fournisseurs, pièces RH) : servis selon leur extension, jamais exécutés dans le navigateur.
- Import : il faut aussi la permission du module visé (stock, ventes…).
- Pages publiques : mot de passe des liens limité en essais, panier du site qui ne révèle plus les coordonnées d'un client, réservations et questionnaires qui ne plantent plus sur des données mal formées.
- Rejeu après reconnexion : une saisie hors ligne renvoyée après une nouvelle connexion n'est pas créée deux fois.
Argent et métiers
- Restaurant : un serveur ne peut plus changer le prix d'un plat.
- Paie : les heures supplémentaires, soldes de tout compte et remboursements ne sont plus payés deux fois après modification d'un bulletin ; paiement de paie et de notes de frais protégé du double clic.
- Caisse : un caissier ne voit et ne clôture que ses sessions et ne rattache ses tickets qu'à elles (gérant : toutes).
- Achats : une commande approuvée repasse en approbation si son montant, son fournisseur ou sa devise change ; réception au-delà de la commande impossible.
- Abonnements, signatures, Mobile Money, acceptation de devis : plus de double facturation, double encaissement ou double clôture quand deux actions arrivent en même temps.
- CinetPay : paiements arrondis au multiple de 5 acceptés.
Application
- Plus de doublons au double appui sur les boutons d'enregistrement (environ 90 formulaires).
- Caisse : montants avec décimales pour les devises qui en ont.
- Données hors ligne : rien n'est perdu si l'application s'arrête pendant le chiffrement ou si le coffre du système est momentanément indisponible ; déconnexion qui attend la fin de l'envoi sans jamais rester bloquée.
- Noms de fichiers reçus nettoyés avant enregistrement ; sauvegarde Android et transfert d'appareil exclus.
- Sélecteurs de date qui s'ouvrent toujours, recherches moins bavardes, textes lisibles en mode sombre.
- Message clair si l'application est déjà ouverte dans une autre fenêtre.

## 0.99.32 — données protégées sur l'appareil, mode sombre, filtres sur téléphone
- Ordinateur et téléphone : le jeton de connexion est rangé dans le coffre du système (Trousseau Mac et iPhone, Keystore Android, Gestionnaire d'identifiants Windows, trousseau Linux) et les données de la société sur l'appareil sont chiffrées (AES, clé dans ce coffre). Le passage se fait tout seul à la première ouverture : les saisies en attente d'envoi sont gardées, le reste est rechargé depuis le serveur. Sans coffre disponible, l'application fonctionne comme avant.
- Déconnexion : case « Effacer les données de la société sur cet appareil » (cochée par défaut dans le navigateur, choix mémorisé), avec avertissement si des saisies n'ont pas encore été envoyées ; seules les données de cette personne dans cette société sont effacées.
- Mode sombre : menu du compte › Apparence (Clair, Sombre, Selon l'appareil) ; étiquettes de statut et bandeau d'échéance lisibles en sombre.
- Téléphone : les filtres des listes sont regroupés derrière un bouton « Filtres (n) » à côté de la recherche ; la liste commence beaucoup plus haut.
- 74 formulaires de plus s'ouvrent dans le panneau latéral (interventions, recouvrement, consolidation, recrutement, immobilier, achats, trésorerie, analytique, pointage, logistique, fidélité, rendez-vous, boîte mail, boutique en ligne…).
- Caisse : l'écran ne se redessine plus à chaque étape d'une synchronisation (seulement à la fin, au passage en ligne ou hors ligne). Copie hors ligne des listes consultées limitée à 400 entrées (les plus anciennes sont retirées, les recherches tapées ne sont pas gardées).
- Serveur : les liens d'un document (client, entrepôt, agence, document d'origine, produits, session de caisse, conditions de paiement) doivent appartenir à la même société ; rapports, écritures comptables et agences limités à la société ; connexion aux serveurs de messagerie sur l'adresse vérifiée (protection contre le changement d'adresse DNS entre la vérification et la connexion).

## 0.99.31 — audit : sécurité, synchronisation hors ligne, référencement
- Synchronisation : une saisie refusée par le serveur (droits, module, contrôle) reste sur l'appareil, marquée, au lieu d'être effacée (ex. vente de caisse faite hors ligne) ; les alertes de synchro sont conservées après un redémarrage.
- Envois hors ligne (fiches, demandes) : plus de doublon quand la réponse du serveur se perd (clé unique par envoi, reconnue par le serveur 24 h) ; serveur en maintenance (erreur 5xx) ou trop de demandes : la file attend et réessaie au lieu d'abandonner les envois.
- Déconnexion : attend la fin d'une synchronisation en cours ; les listes d'utilisateurs, le catalogue des rapports et les préférences non envoyées de la session précédente ne passent plus dans le compte suivant ; le message n'annonce plus à tort la perte des saisies non envoyées.
- Droits : la synchronisation hors ligne ne permet plus de supprimer ce que l'API refuse (note de frais d'un collègue, élément non visible) ; évaluations, listes d'intégration et entretiens de recrutement : suppression réservée aux responsables.
- Support (Helpdesk) : la relève IMAP refuse les adresses du réseau interne de la plateforme (même garde que la Boîte mail).
- Panneau latéral des formulaires : un clic à côté ne le ferme plus (la saisie n'est plus perdue) ; Mac App Store : enregistrement des exports dans Téléchargements autorisé ; App Store : plus de texte sur l'achat de modules ; Android : une version publiable n'est plus signée avec la clé de débogage par erreur.
- Écrans Webhooks, WooCommerce, Avis Google : message d'erreur au lieu d'un chargement sans fin hors ligne ; feuilles de temps : une ligne sans date ne bloque plus l'écran.
- Site : plan du site (sitemap.xml), robots.txt avec son adresse, lien canonique, textes alternatifs des captures de l'accueil.

## 0.99.30 — nouveau style de l'application
- Menu latéral bleu nuit par défaut (le menu clair reste disponible dans le menu du compte) : sections « Vue d'ensemble » et « Espaces de travail », élément actif surligné avec le trait jaune, « Modules & tarifs » et « Paramètres » en bas, carte de l'utilisateur (nom, rôle) qui ouvre le compte.
- En-têtes de page : fil d'Ariane « Espace / Écran », grand titre avec sa pastille d'icône ; la barre du haut ne répète plus le titre.
- Indicateurs en cartes plus lisibles (grande icône, grand chiffre, variation en pastille verte ou rouge), toutes à la même hauteur ; cartes du tableau de bord toutes blanches.
- Onglets soulignés en bleu, tableaux avec en-têtes en minuscules et lignes plus aérées, initiales en ronds, étiquettes de statut pastel (Client en vert, Prospect en orange, Fournisseur en bleu).
- Formulaires de création et de modification (nouveau contact, opportunité, produit…) dans un panneau qui s'ouvre sur la droite, au lieu d'une fenêtre au milieu de l'écran ; boutons « Entreprise / Particulier » et autres choix exclusifs en bleu nuit.
- CRM : indicateurs du pipeline (opportunités ouvertes, montant, prévision pondérée, taux de conversion), colonnes avec nombre et total, cartes avec montant, prochaine action, « Relance en retard » et initiales du commercial, bouton « Ajouter » en bas de chaque étape (l'opportunité est créée dans cette étape).

## 0.99.29 — puces de filtre lisibles dans les logiciels, accueil du site
- Logiciels Windows, Mac, Linux et applications : les puces de filtre non sélectionnées (Commandes, Boîte de réception, listes…) s'affichaient vides ; leur texte est de nouveau visible. Applications en version 1.0.1.
- Site : titre de l'accueil fixe (plus de texte qui défile), bouton « Télécharger » à côté de « Créer mon entreprise », bouton WhatsApp à gauche (la discussion reste à droite).

## 0.99.28 — formulaire d'appel de fonds personnalisable
- Appels de fonds : formulaire complet, sur le modèle du « Formulaire de demande d'appel de fonds » papier :
  - motif (fournisseur, salaires, mission, charges courantes, impôts, avance, investissement, autre) ;
  - émetteur et bénéficiaire (fonction, projet / activité, bénéficiaire, structure) ;
  - remise en pourcentage ou en montant, TVA et autres taxes calculées sur le net ou sur le brut, total à décaisser ; le montant accordé suit les mêmes règles ;
  - modalités de paiement, niveau d'urgence (normal, urgent, très urgent) et pièces jointes ;
  - cadre réservé à la comptabilité (budget disponible, vérification des pièces, observation), rempli par la comptabilité ou le responsable ;
  - remarques / suggestions.
- PDF du formulaire refait : logo centré entre deux filets (ou à gauche), bandeaux de couleur, cases cochées, tableau des dépenses avec remise et taxes, visas remplis d'après le circuit (demandeur, responsable, bénéficiaire), pied de page légal sur chaque page. Disponible aussi pour un brouillon.
- Nouvel onglet « Modèle du formulaire » : chaque société règle son logo (celui de la société, un logo propre au formulaire ou aucun ; position et taille), ses trois couleurs, le titre, le préfixe des numéros (ex. NSI-AF-2026-00001), les rubriques affichées, les listes de motifs, de modes de paiement, de pièces jointes et de visas, la remise et la TVA proposées par défaut, des taxes types, et le pied de page (adresses, téléphone, e-mail, site, RCCM, CC, mentions). Bouton « Aperçu PDF » avec des données d'exemple.

## 0.99.27 — fiches contacts façon Dolibarr et Odoo
- Prospects (comme Dolibarr) : statut de prospection (Jamais contacté, À contacter, Contact en cours, Contacté, Ne pas contacter) et potentiel (Faible, Moyen, Élevé). Un appel, un rendez-vous ou une visite marqué « fait » fait passer un prospect pas encore contacté à « Contact en cours ». Il devient toujours client à sa première facture validée.
- Code automatique à la création : C2611-0001 pour un client ou un prospect, F2611-0001 pour un fournisseur ; le code est gardé quand le prospect devient client. Recherche par code.
- Fiche : Entreprise ou Particulier, site web, interlocuteurs (nom, fonction, téléphone, e-mail), origine du contact et commercial attribué (vous par défaut).
- Doublons (comme Dolibarr et Odoo) : avant d'enregistrer, la fiche signale un contact au même nom, e-mail, téléphone ou n° contribuable ; « Utiliser » la fiche existante ou « Créer quand même ». Fonctionne hors ligne.
- CRM (comme Odoo) : « Créer le devis » depuis une opportunité rattache le contact existant (même e-mail, téléphone ou nom) au lieu de créer un doublon ; sinon il crée un prospect (et non plus un client), avec l'origine, le commercial et l'interlocuteur de l'opportunité.
- Boîte mail : un contact créé depuis un e-mail est un prospect.
- Liste : filtres Prospection, Potentiel, Commercial et Origine ; statut de prospection affiché ; « Nouveau prospect » depuis l'onglet Prospects.
- Boîte mail : quand la connexion Google ou Microsoft est désactivée, une adresse Gmail, Outlook ou Hotmail reçoit bien ses réglages IMAP (au lieu d'une proposition de connexion Google).
- Applications : configuration du paquet Microsoft Store (MSIX, signé par Microsoft) ; le workflow GitHub le produit quand l'identité du Partner Center est renseignée.

## 0.99.26 — liens des logiciels et code source public
- Administration › Boutique › Liens des logiciels : pour chaque plateforme, la fiche de boutique (Microsoft Store, Mac App Store, Google Play, App Store) et le fichier direct (.exe, .dmg, AppImage, APK). Bouton « Liens officiels des boutiques » qui remplit les fiches Yomara ERP, envoi des fichiers sur le serveur depuis l'administration (morceaux de 4 Mo, adaptés aux hébergements mutualisés), version des logiciels affichée, compteur de téléchargements par plateforme.
- Page « Télécharger » : le bouton principal mène à la boutique quand elle existe (installation et mises à jour automatiques), avec le fichier direct en second (« ou le fichier APK ») ; lien vers le dépôt du code source.
- Code source (AGPL) : lien vers le dépôt public (GitHub) sur la page « Code source » et la page « Télécharger ».

## 0.99.25 — prospects → clients automatiques
- Contacts : nouveau type « Prospect ». Un prospect reste prospect avec des devis, proformas ou commandes ; dès qu'une facture (ou un ticket de caisse) validée lui est attribuée, il devient client automatiquement, comme dans Dolibarr. Onglet « Prospects » et compteur dans Clients & fournisseurs ; les prospects apparaissent dans les listes de clients (devis, factures, CRM…) mais plus dans celles des fournisseurs.
- Marketing : ciblage des prospects seuls dans les envois aux contacts.
- Web : le bouton « Nouvelle interface » restait affiché et ne faisait que recharger (il comparait la version de l'ERP à celle des stores, 1.0.0) ; il n'apparaît plus que lorsque le serveur est réellement plus récent que l'interface, et jamais en boucle.

## 0.99.24 — audit de sécurité (5 novembre 2026), applications 1.0.0 build 2
- Serveur :
  - synchronisation : un refus (droits insuffisants, conflit) ne renvoie plus la fiche demandée à qui n'a pas le droit de la voir — un compte Caisse ne peut plus lire une fiche salarié (salaire, CNPS, compte de paiement) en tentant de la modifier ; le rejeu d'une modification et un identifiant déjà utilisé par une autre société sont refusés proprement ;
  - accès en ligne aux fiches : mêmes règles de visibilité par personne que la synchronisation (ses notes de frais, ses pointages, ses feuilles de temps…) ;
  - pages publiques marketing (/f/…) et e-mails : un lien du type « {{prenom}}javascript:… » est refusé ; seules les variables de lien de la plateforme ({{lien_desinscription}}, {{lien_app}}…) sont acceptées seules ;
  - changement de son propre e-mail : mot de passe actuel exigé ; son propre mot de passe se change seulement par « Mon compte » ; identifiants du compte démo des stores verrouillés partout ;
  - clés d'API : plus d'accès aux moyens de paiement, canaux d'envoi, boîtes mail, licences, achats de modules, compte, ni modification de la société ;
  - pièces jointes des demandes internes : extension déduite du contenu.
- Applications :
  - poste partagé (caisse, accueil) : chaque utilisateur a son propre espace hors ligne (données, saisies en attente, e-mails, tableaux de bord, justificatifs) — plus de données ni d'envois d'un collègue après un changement de compte ;
  - liens des e-mails, messages et fiches : seuls les liens web, e-mail, téléphone et SMS s'ouvrent (plus de fichiers locaux, chemins réseau ou protocoles d'applications Windows) ;
  - connexion à un serveur en http:// sur internet refusée (mot de passe en clair) ; le http reste possible sur le réseau local ;
  - Windows et Linux : données locales déplacées hors du dossier Documents (souvent synchronisé avec OneDrive), automatiquement ;
  - Android : données exclues des sauvegardes en ligne ; Mac : https obligatoire hors réseau local, écran client autorisé ;
  - déconnexion (même session expirée) : les notifications du compte précédent ne s'affichent plus sur l'appareil ;
  - codes d'accès Yomeet tirés au hasard de façon sûre.

## 0.99.23 — compte démo pour les stores, applications mobiles 1.0.0
- Administration › Sociétés › « Compte démo stores » : crée en un clic le compte que demandent Apple et Google pour tester l'application :
  - société à part (« Yomara Démo ») avec la formule la plus complète, tous ses modules et des données d'exemple (articles, stock, clients, factures, devis, salariés, opportunités) ;
  - utilisateur administrateur de cette société seulement, sans aucun accès à l'administration de la plateforme ;
  - e-mail et mot de passe affichés avec un bouton Copier, plus le texte à coller dans App Store Connect (« Informations de connexion ») et la Play Console (« Accès aux applications ») ;
  - « Nouveau mot de passe » réactive le compte (utile si la relecture a testé « Supprimer mon compte »), « Désactiver » et « Supprimer » ;
  - le mot de passe ne peut pas être changé depuis l'application, et la société n'entre pas dans le chiffre d'affaires de la plateforme.
- Applications mobiles : première version publique 1.0.0 (build 1) ; nom affiché « Yomara ERP » sur iPhone ; projet iOS prêt pour l'archivage Xcode (réglages CocoaPods chargés dans Debug et Release, cible iOS 15 pour toutes les dépendances).

## 0.99.22 — audit de sécurité et finitions
- Sécurité (aucune action requise après la mise à jour) :
  - lecture de pages et de fichiers distants (veille, migration Dolibarr / Odoo / ERPNext, médias WhatsApp et service client, passerelle SMS, IA à adresse personnalisée) : adresses internes refusées, y compris cachées en IPv6, et adresse vérifiée figée pendant l'appel ;
  - studio visuel : plus aucune lecture de fichier du serveur, seules les images publiques et les fichiers déposés sont acceptés ;
  - webhook WhatsApp : seuls les messages au format du fournisseur configuré sont acceptés ;
  - réglages de la société : seules les clés prévues peuvent être modifiées (les quotas et la clôture comptable ne passent plus par cette route) ;
  - notes de frais : une dépense ne peut plus être reprise par un collègue ;
  - fiches : liens « javascript: » ou « data: » refusés, clients, salariés, articles, dépôts et lots vérifiés comme appartenant à la société ;
  - publicité & acquisition : journal des prospects en lecture seule, adresse secrète et clé des sources visibles des seuls gestionnaires, réponses automatiques limitées (2 par destinataire et 300 par société et par jour) ;
  - en-têtes de sécurité sur toutes les réponses (nosniff, Referrer-Policy, HSTS en https) ; dépendances Symfony mises à jour (composer audit sans alerte).
- Fiabilité : relances CRM réservées avant envoi (pas de double message si deux tâches planifiées se croisent), scénarios « Achat » rattachés à la bonne société, segments fiables avec SQLite et MySQL.
- Applications (téléphone) : les indicateurs en haut des écrans (Comptabilité, Service client, Boutique en ligne, Marketing, CRM, Trésorerie, Projets…) défilent en largeur au lieu d'occuper tout l'écran ; le contenu apparaît tout de suite.

## 0.99.21
- Nouveau module « Publicité & acquisition » (palier Standard, Pack Marketing) :
  - prospects des publicités Facebook et Instagram reçus en direct (webhook Meta) et versés dans le CRM avec la campagne, la publicité et les réponses au formulaire ;
  - formulaires Google Ads (clé vérifiée), TikTok, LinkedIn, Zapier, Make, WordPress : une adresse de réception par source ;
  - à l'arrivée : commercial désigné ou à tour de rôle, notification, réponse automatique WhatsApp / SMS / e-mail, liste et scénario marketing, relance automatique ; doublons rattachés à l'affaire en cours ;
  - pages de capture (/lp/…) : titre, avantages, offre, compte à rebours, formulaire, bouton WhatsApp, visites et taux de conversion ;
  - comptes Meta, Google Ads (connexion Google), TikTok et LinkedIn : dépenses par campagne et par jour, relevées toutes les 6 heures ; dépenses saisies pour l'affichage, la radio, les influenceurs ;
  - résultats : dépense, prospects, coût par prospect, ventes (affaires gagnées et commandes en ligne), chiffre d'affaires, retour par campagne et par plateforme ;
  - ventes renvoyées à Meta (API Conversions) ; Search Console ; balises Google Analytics, Tag Manager, Google Ads, Meta, TikTok, LinkedIn, Clarity sur le site et les pages de capture.
- Réseaux sociaux :
  - boîte de réception des commentaires et messages Facebook et Instagram : réponse publique ou privée, masquage, attribution, « traité », prospect CRM en un clic ;
  - réponses automatiques par mot-clé (réponse publique + message privé) ;
  - studio visuel : charte de marque (couleurs, ton, coordonnées) et visuels générés depuis le catalogue (promotion, nouveauté, annonce, avis client, liste de prix ; carré, portrait, story, paysage), ajoutés aux médias et prêts à publier ;
  - l'IA suit la charte (ton, slogan, hashtags, mots à éviter).
- CRM : note de chaque prospect sur 100 (chaud, tiède, froid), prospects à rappeler en premier, opportunités par campagne, origine (utm) ; relances automatiques (e-mail, SMS, WhatsApp, tâches, appels) qui s'arrêtent quand l'affaire est gagnée ou perdue.
- Marketing : scénarios « Prospect reçu », « Achat » (montant minimum, premier achat) et « Client sans achat depuis… » ; conditions « a acheté » et « a dépensé au moins » ; segments par achats et profils RFM (meilleurs clients, fidèles, prometteurs, à risque, perdus).
- Sites : origine des visiteurs (utm, gclid, fbclid) gardée et rattachée aux demandes de contact et aux commandes ; balises de suivi par site ; achats signalés à Meta, Google et TikTok.
- Administration (super admin) : Réseaux sociaux › Applications › Meta : option « Messages, commentaires et prospects » (après validation par Meta), adresse du webhook et jeton de vérification. Configuration du serveur › Publicité en ligne : jeton développeur Google Ads.
- Tâches planifiées (cron existant) : erp:acquisition (10 min), erp:crm-sequences (15 min, notes chaque matin).

## 0.99.20
- Nouveau module « Veille concurrentielle » (Marketing & communication, palier Standard, inclus dans le Pack Marketing) :
  - fiches concurrents : offre, positionnement, réseaux, note et avis Google, part de marché, SWOT, notes sur 6 critères, fiche de combat pour les commerciaux ; votre entreprise en type « Nous » pour vous comparer ;
  - observations du terrain avec photo (rôle Commercial : droit « remonter la veille »), alerte immédiate sur un fort impact ;
  - relevés de prix comparés à vos articles (prix repris du catalogue), écart, évolution, indice de prix par concurrent ;
  - publicités repérées (Meta, Google, TikTok, LinkedIn, affichage, radio…) et liens directs vers les bibliothèques publicitaires de Meta, Google et LinkedIn ;
  - audience des réseaux sociaux et progression ;
  - surveillance automatique de pages de sites concurrents (chaque jour ou semaine) : ce qui a été ajouté ou retiré, filtre par mots-clés, observation créée et notification ;
  - analyse des sites : référencement sur 100, rapidité, réseaux reliés, outils détectés (Google Analytics, Tag Manager, Meta Pixel, Google Ads, TikTok, LinkedIn, Hotjar, Mailchimp, Brevo, HubSpot, Klaviyo, WhatsApp…), refaite chaque mois avec alerte si un pixel publicitaire apparaît ;
  - mots-clés suivis dans Google Actualités et tout flux RSS (alertes Google) : mentions et revue de presse ;
  - comparatif : carte de positionnement prix / note, tableau des notes, présence en ligne ; affaires perdues face à chaque concurrent (CRM) ;
  - avec l'Assistant IA : fiche de combat et synthèse avec plan d'action ; résumé de la semaine chaque lundi.
- Les concurrents et observations déjà saisis dans « Stratégie marketing & communication » sont repris à l'activation du module.
- Tâche planifiée erp:veille (toutes les heures, via le cron existant).

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
