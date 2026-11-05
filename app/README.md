# Yomara ERP — Application (Flutter)

Une seule application pour **Windows, macOS, Linux et Web** (Android / iOS prêts pour plus tard).

## Fonctionnement hors ligne

- Toutes les données de la société sont copiées dans une base locale (Hive : fichiers sur ordinateur, IndexedDB dans le navigateur).
- Toute saisie est enregistrée localement puis placée dans une file d'envoi ; la synchronisation part dès que le serveur répond (toutes les 20 s, au retour du réseau, ou via le bouton).
- Une facture validée hors ligne affiche « Validé · en attente » : le numéro définitif est attribué par le serveur à la synchronisation.
- Le stock affiché hors ligne tient compte des ventes non encore envoyées.
- Les factures et devis s'impriment en PDF sans connexion.
- La caisse (module POS) vend, encaisse, imprime les tickets 80 mm et clôture la session sans connexion ; un lecteur de code-barres USB fonctionne directement (il tape le code puis Entrée).
- Les écrans d'administration (super admin, utilisateurs) nécessitent la connexion.

## Imprimante de caisse

Réglée sur chaque poste (icône imprimante en haut de la caisse) :

| Mode | Pour quoi | Tiroir-caisse |
|---|---|---|
| **Réseau (ESC/POS)** | Imprimante thermique Ethernet/Wi-Fi (Epson TM, Xprinter, Rongta…), IP + port 9100 | Ouvert par l'application à chaque paiement en espèces, bouton d'ouverture manuelle |
| **Imprimante PC** | Imprimante installée sur Windows/Mac (USB), impression sans fenêtre | À activer dans les préférences du pilote (« ouvrir le tiroir avant impression ») |
| **PDF** | Aperçu d'impression, seul mode disponible dans le navigateur | — |

Options : papier 80 ou 58 mm, impression automatique après chaque vente, ticket de test. Le ticket ESC/POS est encodé en PC850 (accents français), coupé automatiquement. Si l'imprimante ne répond pas, la vente n'est jamais bloquée : le ticket bascule en PDF avec un avertissement.

## Écran client

Icône écran en haut de la caisse. Le client voit le panier en direct, le total, puis « Merci » avec la monnaie rendue ; retour automatique à l'accueil après 12 s.

- **Application Windows/Mac** : la caisse sert l'écran sur le réseau local (port 8765 par défaut). Sur une tablette ou un second PC du même Wi-Fi, ouvrir l'adresse affichée (ex. `http://192.168.1.20:8765`) — aucune connexion Internet requise. Pour un second moniteur branché sur la caisse : « Ouvrir sur ce poste », glisser la fenêtre sur le second écran, F11.
- **Navigateur** : « Ouvrir la fenêtre client » ouvre une seconde fenêtre reliée à la caisse (même navigateur), à placer sur le second écran.
- Windows peut demander d'autoriser l'application dans le pare-feu au premier lancement : accepter pour les réseaux privés.

## Comptabilité

Menu « Comptabilité » (module `compta`). Les écritures sont tenues par le serveur : cet écran nécessite la connexion, mais les ventes et tickets saisis hors ligne sont comptabilisés automatiquement dès leur synchronisation.

- **Tableau de bord** : chiffre d'affaires (et N-1), marge commerciale, EBE, résultat net, trésorerie, créances clients, dettes fournisseurs, TVA, produits et charges sur 12 mois, principales charges, trésorerie par compte, plus gros débiteurs et créanciers, factures fournisseurs à traiter.
- **Factures fournisseurs** : si le circuit est activé (Paramètres), chaque facture validée dans Achats attend la comptabilité. Nature d'achat proposée ligne par ligne (mémorisée par article, fournisseur ou catégorie), puis **Approuver**, **Refuser** avec motif (le service achats voit le refus sur la facture et **répond**) ou **Comptabiliser** ; « Comptabiliser les approuvées » d'un coup.
- **États** : au modèle du référentiel de la société : SYSCOHADA (compte de résultat TA…XI avec soldes intermédiaires, bilan, flux de trésorerie), SMT (recettes / dépenses, bilan simplifié, état des recettes et dépenses), SYCEBNL (excédent ou déficit, fonds affectés), PCG (compte de résultat en liste, bilan), CGNC (CPC et bilan) ; N et N-1, balance générale, TVA ; PDF et Excel.
- **Écritures** : par journal (VE, AC, CA, BQ, MM, OD, AN), saisie d'écritures avec contrôle d'équilibre et compte de tiers sur les lignes 40/41, contre-passation.
- **Tiers** : balance des comptes clients et fournisseurs (codes CLI00001, FRS00001 attribués automatiquement), extrait de compte par tiers en PDF, export Excel.
- **Ventes** : la **facture proforma** (PRO-…) se crée comme un devis et se transforme en facture définitive en un clic.
- **Grand livre** : par compte, avec report à nouveau et solde progressif.
- **Clôture & liasse** : par exercice, contrôles avant clôture (avec le bouton pour corriger), impôt sur le résultat (réintégrations, déductions, IS ou minimum forfaitaire, taux modifiables) et son écriture, clôture et réouverture, affectation du résultat (réserve légale proposée, dividendes, report à nouveau) ; liasse DSF en PDF et Excel. Dans **États › TVA** : état annexé à la déclaration (PDF, Excel).
- **Assistant IA** (v0.59) : questions (suggestions, conversations enregistrées, tableaux et listes dans les réponses, données utilisées affichées, copie) ; rédaction (relance à partir d'un n° de facture, messages d'envoi, réponse client, fiche article, publication ; e-mail, WhatsApp ou SMS ; ton) ; paramètres (fournisseur, clé, modèle, adresse compatible OpenAI, quota).
- **Boutique en ligne** (v0.60) : commandes du site et des réseaux (filtres par étape et canal, recherche, ruptures signalées, commandes en attente) ; saisie d'une commande WhatsApp, Facebook, Instagram, TikTok ou téléphone ; bon de préparation PDF des commandes cochées ; expédition (livreur, date, colis), retrait en boutique, encaissement (Mobile Money avec n° de transaction), livraison, annulation, note interne ; statistiques (CA par jour, panier moyen, conversion, paniers abandonnés, meilleurs articles, canaux, paiements, zones, codes promo) ; canaux et réglages (flux Meta et Google, lien WhatsApp, réservation du stock, bon de livraison automatique, messages au client).
- **Gestion locative** (v0.61) : tableau de bord (occupation, loyers appelés et encaissés sur 12 mois, recouvrement, impayés par locataire avec relance, cautions, manque à gagner, baux qui finissent, révisions) ; biens et plan des lots (libre, loué, en travaux) ; création du bail depuis un lot libre (locataire existant ou nouveau, caution, avance, périodicité, révision, garant, paiement à la signature) ; fiche du bail (loyers, relance, WhatsApp, caution, fin de bail avec retenue) ; loyers du mois ou impayés échus avec encaissement partiel et quittance / avis d'échéance PDF ; dépenses ; relevé de gérance par propriétaire (PDF) et reversements.
- **BTP & chantiers** (v0.62) : portefeuille (en cours, études, réceptionnés ; reste à réaliser, facturé, retenues, dérives) ; fiche chantier en 5 onglets : synthèse (devis, marché signé, avance, réception, libération de la retenue, PDF client et étude interne), bordereau par lots (ouvrage, unité, quantité, déboursé, prix, raccourcis de coefficient), situations (saisie des quantités cumulées, 50 % / 100 %, calcul puis facturation, décompte PDF), journal de chantier, coûts réels face au déboursé.
- **Ergonomie** (v0.63) : menu en espaces de travail repliables (`lib/ui/navigation.dart` : `navGroups`, favoris par étoile, espaces ouverts et pages récentes mémorisés, mode réduit en icônes) ; palette de commandes Ctrl+K / Cmd+K (tous les modules, synonymes, actions rapides : nouvelle facture, devis, facture fournisseur, caisse) ; tableau de bord épuré : actions rapides, 4 indicateurs essentiels (évolution et courbe), chiffre d'affaires sur 6 mois, liste « À traiter » (tâches en attente et alertes, cliquables vers le module), indicateurs par espace repliés, choix personnel des indicateurs (Personnaliser) ; boutique des modules rangée par espace.
- **Tableau de bord analytique** (v0.63) : barre supérieure (repli du menu, fil d'Ariane, recherche globale, notifications, synchro, compte) et menu latéral clair (sombre en option ou en thème sombre) ; tableau de bord inspiré des cockpits de direction (`lib/ui/screens/insights_view.dart`) : carte vedette du chiffre d'affaires et 3 indicateurs avec évolutions, courbe 12 mois comparée à l'année précédente avec survol, jauges de pilotage, score de santé et alertes intelligentes cliquables, répartition du CA en anneau, performance par agence ou meilleurs clients ; données mises en cache pour le hors-ligne.
- **Tableaux de bord Excel** (v0.63) : galerie de modèles avec aperçu dessiné dans le thème choisi (6 thèmes), réglages (titre, exercice, agence, protection), téléchargement du classeur interactif, « Mes tableaux » enregistrés avec envoi automatique par e-mail (lundi ou 1er du mois), envoi immédiat.
- **Migration** (v0.64) : écran « Migrer depuis un autre logiciel » (Administration) : connexion directe Dolibarr, Odoo, ERPNext (aide pour obtenir la clé d'accès), ou dépôt des fichiers d'export de 12 éditeurs ; aperçu (volumes, colonnes reconnues), choix de ce qui est repris, taux de conversion, date de début ; suivi en direct de la migration en arrière-plan, compteurs et avertissements (`ApiClient.uploadFiles` pour l'envoi de plusieurs fichiers).
- **Mises à jour** (v0.65) : bouton « Mettre à jour » dans la barre du haut (`widgets/app_update.dart`) quand une version est diffusée (rouge si importante) ; assistant avec nouveautés, vérifications, étapes en direct, rechargement de l'interface ; restauration de secours via `restore.php` si la nouvelle version ne répond pas. « Nouvelle interface » (web) quand le serveur est plus récent que l'application chargée, « Nouvelle application » (Windows, Mac, Android) avec les liens fournis. `AppConfig.appVersion` doit suivre la version. Super admin › Mises à jour (`admin_updates_screen.dart`) : diffuser, envoyer un paquet, installer sur ce serveur, parc installé. Service worker en `no-cache`.
- **Ma journée** (v0.65) : accueil du tableau de bord (`widgets/my_day.dart`) avec salutation selon l'heure, horloge, météo de la ville de la société (Open-Meteo), faits marquants qui défilent (inspirés des « headlines » de Dynamics 365), prochain rendez-vous, résumé (rendez-vous, tâches, échéances) ; icônes calendrier (jour du mois) et tâches (compteur) dans la barre du haut ; panneau latéral « Ma journée » à la manière d'Outlook : mini-calendrier avec pastilles, rendez-vous du jour, tâches de projet, liste de tâches personnelles (importante, échéance, glisser pour supprimer, terminées repliées).
- **Réunions & webinaires** (v0.66, `screens/visio_screen.dart`) : liste des réunions et webinaires (à venir / passées, recherche), réunion instantanée (lien copié), planification avec membres de l'équipe, invités externes, options de salle et invitations e-mail ; fiche avec compte rendu (résumé IA modifiable, décisions, actions, présence, sondages, questions, transcription, envoi aux participants), participants (liens personnels, relance), enregistrements (téléchargement, rediffusion) ; paramètres ZEGOCLOUD / Agora / pair-à-pair avec ordre de bascule glisser-déposer, quotas, état de santé et test des clés. La salle s'ouvre dans l'application web (iframe autorisée caméra, micro, partage d'écran : `widgets/visio_frame*.dart`) ou dans le navigateur par défaut sur Windows, Mac et mobile.
- **Synchronisation bancaire** (v0.67, `screens/bank_sync_tab.dart`, onglet de Banque & trésorerie) : connexions Wave Business, banque européenne (choix de la banque puis autorisation sur son site) et adresse de relevés par e-mail ; état, solde à la source, synchronisation manuelle, pause ; règles de classement (suggestions en un clic, aperçu du nombre de lignes concernées, application immédiate). Dépôt de relevés OFX, MT940, CAMT.053, CFONB : aperçu sans correspondance de colonnes.
- **Mobile Money direct** (v0.67, `widgets/mobile_money_collect.dart`) : en caisse (moyen « Mobile Money » → « Demander le paiement ») et sur une facture (« Mobile Money ») : demande MTN MoMo sur le téléphone du client ou QR code Wave / Orange Money dessiné localement, suivi toutes les 3 s, paiement ajouté dès la confirmation.
- **Intégrations & API** (v0.68, `screens/integrations_screen.dart`, Société › Intégrations & API, droit « Paramètres ») : webhooks (adresse Zapier / Make / n8n, choix des événements par groupe ou « tous », secret affiché une fois et régénérable, test immédiat, statistiques 7 jours, envois récents avec contenu JSON et renvoi), clés d'API lecture seule ou lecture-écriture (affichées une fois, dernière utilisation, révocation), flux de données (formule Google Sheets, liens CSV et JSON copiés en un clic, nombre de lectures), lien vers la documentation de l'API.
- **Boîte mail** (v0.69, `screens/mailbox/`, Collaboration › Boîte mail) : trois volets façon Outlook (dossiers avec boîte de réception unifiée, non lus, suivis ; liste groupée par date avec recherche sans accents et filtres ; lecture avec pièces jointes, fiche client liée, conversation), sélection multiple, rédaction riche (`flutter_quill`, conversion HTML `vsc_quill_delta_to_html`) avec destinataires auto-complétés (clients, correspondants, collègues), signatures, modèles, pièces jointes de l'ordinateur ou des Documents, accusé de lecture, importance, envoi programmé ; copie locale (`mail_store.dart`, boîte Hive `<société>_mailbox`) : lecture, classement et rédaction hors ligne, actions et envois rejoués au retour du réseau. Affichage HTML : cadre isolé sans script sur le Web (`mail_html_web.dart`), rendu natif sur ordinateur (`flutter_widget_from_html_core`). Ajout de compte guidé (détection, Microsoft / Google, IMAP / POP3 avec identifiant SMTP distinct, adresse fournie par Yomara), réglages par compte (dossiers synchronisés, boîte partagée Microsoft 365, import des contacts, test de délivrabilité, configuration téléphone), générateur de signatures avec aperçu, paramètres Microsoft 365 / Google avec guide. Agenda : boutons « Réunion Teams » / « Google Meet ».
- **Boîte mail — finitions** (v0.70) : barre de commandes façon Outlook aux couleurs du thème (Nouveau courrier, Supprimer, Archiver, Déplacer, Indicateur, Marquer comme lu / non lu, Synchroniser), 12 thèmes d'arrière-plan avec motifs (`mail_theme.dart` : Corail, Lagune Ébrié, Savane, Wax, Kente, Nuit…) mémorisés sur le poste, volets redimensionnables par glisser (double-clic : largeur par défaut) et volet des dossiers repliable, largeurs mémorisées ; téléphone : une vue à la fois, balayage pour archiver / supprimer, tirer pour actualiser, rédaction plein écran. Rédaction en HTML (code + aperçu en direct, modèle d'e-mail responsive aux couleurs du thème, images envoyées sur le serveur). Signatures : chargement du logo et d'une photo ronde, HTML personnalisé avec insertion d'images, import d'un fichier .html. Photo de profil et nom affiché par adresse. QR code d'installation de l'application mobile après l'ajout d'une boîte (et depuis la barre de commandes).
- **WhatsApp** (v0.71, `screens/whatsapp_screen.dart`, Collaboration › WhatsApp) : façon WhatsApp Web — liste des conversations (mes conversations, non attribuées, non lues, toutes, clôturées ; recherche ; étiquettes, agent, non lus) redimensionnable, conversation avec bulles, séparateurs de jours, photos et documents, coches distribué / lu, messages automatiques signalés ; saisie avec réponses rapides « / », photo, document, modèle approuvé (variables et aperçu), Entrée pour envoyer ; fenêtre de 24 h affichée (temps restant, modèle imposé une fois fermée) ; attribution, étiquettes, attente, clôture, création de la fiche client ; volet contact (reste à payer, dernière facture). Paramètres : adresse du webhook et jeton, attribution automatique, accueil et absence, horaires, réponses rapides, modèles (synchroniser, proposer à Meta), lien et QR code « Discuter sur WhatsApp » avec bouton HTML pour le site. Actualisation toutes les 4 s (conversation) et 10 s (liste).
- **Avis Google** (v0.72, `screens/avis_google_screen.dart`, Marketing & web › Avis Google) : bandeau note moyenne, étoiles, avis sans réponse, taux et délai de réponse, avis sur 30 jours, QR code et affiche ; accueil (connexion de la fiche Google Business ou mode rapide par recherche de l'établissement). Onglets **Avis** (filtres sans réponse / 1–2 / 3 / 4–5 étoiles, recherche, réponse en ligne publiée sur Google, proposition de l'IA, modification ; répartition des notes), **Demandes d'avis** (état des envois automatiques, demande manuelle à un client ou à des coordonnées, statuts programmée / envoyée / ouverte / retour privé / échec), **Publications** (actualité, offre avec code promo, événement, bouton, photo), **Fiche & statistiques** (vues, appels, itinéraires, clics, scans du QR code ; courbes des vues et de la note ; description, téléphone, site). Paramètres : connexion, demandes automatiques (déclencheurs, canaux, délai, fréquence, message avec `{lien}`), alertes.
- **WooCommerce** (v0.73, `screens/woocommerce_screen.dart`, Marketing & web › WooCommerce) : accueil et connexion guidée (adresse, clé client, clé secrète, import des commandes existantes) ; bandeau de la boutique (sélecteur si plusieurs, produits en ligne, commandes sur 30 jours, état et heure des dernières synchronisations, réception en direct ou relevé). Onglets **Produits sur le site** (filtres en ligne / pas encore en ligne / en erreur / tous, recherche, sélection multiple pour mettre en ligne ou retirer, mise en ligne de tous les produits actifs ou d'une catégorie, rapprochement par SKU avec import des manquants ; prix TTC, stock disponible, état), **Commandes du site** (numéro, client, paiement, commande et facture de l'ERP, statut ; ouvrir dans WooCommerce, marquer terminée / en cours), **Journal**. Réglages : prix, stock, marge de sécurité, dépôt, statut des nouveaux produits, statuts importés, facturation des commandes payées, renvoi du statut, préfixe, pause, webhooks, déconnexion. Les applications natives proposent `https://erp.yomaratechnologies.com` comme serveur par défaut (`AppConfig.defaultServer`, modifiable par `--dart-define=SERVER=…`).
- **Shopify** (v0.74, Marketing & web › Shopify) : même écran que WooCommerce (`WoocommerceScreen(platform: 'shopify')`), couleurs et textes Shopify ; connexion par adresse `…myshopify.com`, jeton de l'API Admin et clé secrète ; actions « marquer payée » et « clôturer ».
- **Liens de paiement** (v0.74, `screens/payment_links_screen.dart`, Ventes & clients) : création (objet, montant fixe ou libre avec minimum, client, lien permanent), partage par QR code, WhatsApp ou copie, liste avec statut, paiements et montant encaissé, désactivation. Encaissements de vos clients : compte de trésorerie par prestataire. PDF des factures : QR code de paiement.
- **Sauvegardes** (v0.74, `screens/backups_screen.dart`, Administration › Sauvegardes, ou Plateforme en mode SaaS) : état de la dernière sauvegarde, « Sauvegarder maintenant », planification (heure, copies gardées, documents inclus), clé de sauvegarde (copier, changer), Google Drive et Dropbox (connexion, application Dropbox, copies distantes), copies du serveur (télécharger, restaurer, supprimer), restauration d'une archive (confirmation « RESTAURER », clé, documents). Rendez-vous : réglages « dans l'Agenda du praticien » et « son agenda bloque les créneaux ».
- **Interfaces modernisées** (v0.75) : police Inter embarquée (chiffres tabulaires), thème revu (cartes, champs, boutons, puces, onglets, segments, tableaux à en-têtes discrets et survol), `NavScope` fourni par le menu : chaque `PageHeader` affiche la pastille d'icône aux couleurs de l'espace et le surtitre ; nouveaux widgets `StatTile` / `StatStrip` (indicateurs) et `InitialsAvatar` ; `StatusChip` à pastille. Ventes et achats (indicateurs total, encaissé, reste dû et retards ; colonne reste dû ; sélecteur de type segmenté), clients (indicateurs, avatars, solde dû par client), stock (valeur, sous le seuil, ruptures ; quantités en pastilles de couleur), caisse (tuiles produits avec pastille, stock et rupture).
- **Droits et boîte mail interactive** (v0.76) : Paramètres › Utilisateurs › « Modules et droits » (`user_access_dialog.dart` : modules gratuits / payants avec interrupteur, actions cochées héritées du rôle, ajoutées ou retirées) ; `Session.can` applique `denied_permissions` ; documents : « Valider » réservé (`DocConfig.validatePerm`), suppression selon `deletePerm`. Boîte mail : bandeau `MailActionsBar` (invitation à ajouter à l'agenda ou rejoindre, dates citées cliquables pour planifier, suggestions selon la demande) et dialogue « Planifier » (Teams, Meet, visio Yomara, présentiel, webinaire ; date, heure, durée, invités) ; menu « Créer à partir de ce message » (devis ouvert dans l'éditeur, ticket, opportunité, appel de fonds, tâche). Envoi des documents : « Envoyer depuis » sa boîte mail, copie à, message pré-rédigé (date, validité, échéance, lien de paiement).
- **Espaces par service** (v0.77) : `widgets/records.dart` (`RecordSchema`, `RecordsView`, `editRecord`, `ModuleDashboard`) affiche liste, filtres et formulaire à partir du schéma serveur. `contenus_screen.dart` (calendrier éditorial mois / liste avec couleurs de statut et boutons Soumettre / Valider / Publiée, stratégies, actions, veille, concurrents, presse), `juridique_screen.dart` (contrats avec « Rédiger depuis un modèle », PDF sur papier à en-tête et « Faire signer », échéances, contentieux, modèles), `flotte_screen.dart` (Aujourd'hui : véhicules en sortie, à surveiller, carburant par véhicule ; demandes et sorties avec Approuver / Refuser / Départ / Retour, véhicules, carburant, entretien), `employee_dossier.dart` (dossier salarié depuis la liste RH). Nouvel espace « Juridique » dans la navigation.
- **Force de vente** (v0.78) : `commercial_screen.dart` — Performances (mois précédent / suivant, CA de l'équipe contre objectif, classement des commerciaux avec barre d'atteinte, visites, clients, devis, opportunités ; visites du jour ; résultats des visites), visites et comptes rendus, tournées (« Demander un véhicule » au parc automobile), objectifs.
- **Rapports d'activité** (v0.78) : `activite_screen.dart` (Mon rapport : semaine précédente / suivante ou période au choix, lignes par rubrique à garder ou retirer, activités ajoutées, synthèse / difficultés / priorités, PDF, Word, « Envoyer à mon supérieur » ; Mes rapports ; Rapports reçus avec Valider / À compléter) ; `widgets/add_to_report.dart` : bouton « Ajouter au rapport » dans chaque fiche (`RecordForm`) et dans l'éditeur de documents.
- **Développement** (v0.78) : `dev_screen.dart` — tableau kanban par sprint (glisser-déposer entre statuts), backlog, sprints, versions (« Générer les notes »), applications.
- **Intégrations des services** (v0.79) : onglet « Réseaux sociaux » de l'espace marketing (connexion Facebook & Instagram, LinkedIn, statistiques) et bouton « Publier maintenant » ; case « Publier automatiquement » sur chaque publication ; applications du module Développement : adresse et secret du webhook GitHub / GitLab, « Nouveau secret Git », activité Git sur chaque tâche ; Intégrations › « Canaux d'équipe » (Slack, Teams, Discord, Telegram, Google Chat, rubriques relayées, test).
- **Hors ligne des espaces de service** (v0.80) : `core/records_offline.dart` — lectures mises en cache (`RecordsOffline.get` : listes, schémas, tableaux de bord, recherches) et resservies sans réseau ; écritures faites sans réseau (`RecordsOffline.send` : fiches, circuit des publications, kanban, départ / retour de véhicule avec l'heure réelle, « Ajouter au rapport ») mises en file, affichées « en attente » dans les listes, modifiables ou supprimables avant envoi, rejouées dans l'ordre par `SyncEngine.sync()` ; refus du serveur gardés et montrés dans la liste concernée ; compteur de saisies en attente intégré à celui de la synchronisation. Test : `test/records_offline_test.dart`.
- **Multi-agences** (v0.58) : sélecteur d'agence de travail en haut du menu (et dans la barre sur mobile) ; documents, tickets et caisses créés dans l'agence choisie, listes filtrées ; écran « Agences » : tableau de bord comparatif (CA, marge, dépenses, résultat estimé, trésorerie, stock, créances, graphique et évolution mensuelle) et paramétrage (dépôt et caisse créés à la demande, responsable, équipe) ; agences accessibles dans la fiche utilisateur ; resynchronisation automatique quand les agences d'un utilisateur changent.
- **Consolidation** (v0.54) : périmètre du groupe (filiales rattachées par code, méthode, % d'intérêts, coût des titres, capitaux propres à l'acquisition, cours), génération du code côté filiale, états consolidés sur la période (indicateurs part du groupe / minoritaires / écarts d'acquisition / opérations internes, bilan, compte de résultat, retraitements, Excel). v0.55 : cours de clôture / moyen / historique par filiale en devise, indicateurs écart de conversion, dividendes et marges internes, carte « Retraitements saisis » (marge interne sur stocks, dividendes internes, écriture de consolidation), « Arrêter les comptes » / « Rouvrir », liasse consolidée PDF. v0.56 : vues « Flux » et « Capitaux propres », taux d'impôt différé modifiable, amortissement (années) et dépréciation de l'écart d'acquisition dans la fiche filiale. v0.57 : bouton « Achats, cessions et sortie » par filiale (historique des titres, achat complémentaire, cession partielle, sortie du périmètre avec prix de cession). Paramètres › Exercice comptable : mois de début de l'exercice (exercice décalé) ; la clôture et les périodes « Exercice » suivent ce calendrier.
- **Paramètres** : **référentiel comptable** (SYSCOHADA normal ou SMT, SYCEBNL, PCG, CGNC ; choisi d'après le pays, modifiable avant la première écriture), circuit de vérification des factures fournisseurs, natures d'achat mémorisées (ajout par catégorie), comptes utilisés par les écritures automatiques, sous-comptes, verrouillage de période, reprise de l'historique, export CSV (Excel) pour l'expert-comptable.

## Importations, comparatifs & locations

Menu « Importations & locations » (module `appro`, connexion nécessaire).

- **Tableau de bord** : importations en cours et en retard, coefficient d'approche moyen, comparatifs ouverts et économies, locations en cours, dépassées et coût du mois, principaux fournisseurs.
- **Importations** : articles en devise (code SH, poids, taux de douane), frais d'approche en monnaie locale et leur répartition ; « Enregistrer et calculer » affiche le coût de revient par article et la marge ; report du coût sur les articles, bon de commande en devise, PDF et Excel.
- **Comparatifs** : grille des offres (prix par ligne dans la devise du fournisseur, délai, frais, conditions, conformité), meilleur prix surligné, derniers prix payés ; « Retenir » prépare le bon de commande.
- **Consultation en ligne** : « Consulter des fournisseurs » envoie à chacun un lien (e-mail, WhatsApp, ou lien à copier) ; leurs offres arrivent dans la grille (« Réponse en ligne », remarques par ligne, devis joint). Bouton « Questions et réponses » par fournisseur (question envoyée avec le lien, ou réponse reçue par téléphone à noter).
- **Comparer les prix** : bouton sur une demande d'achat, une demande de prix, un appel de fonds (matériel, prestations) et un appel d'offres ; ouvre le comparatif lié ou le crée avec les lignes.
- **Cahiers des charges** : spécifications et quantités, rubriques, PDF, envoi aux fournisseurs, création du comparatif.
- **Locations & services** : matériel, véhicules, prestations et sous-traitance ; prolongation, retour, caution, facture fournisseur ; export Excel.
- **Fournisseurs** : note, délai moyen, livraisons à l'heure et complètes, évolution des achats ; fiche historique avec l'évolution des prix par article ; export Excel.

## Appels de fonds

Menu « Appels de fonds » (module `fonds`, connexion nécessaire).

- **Nouvel appel de fonds** : objet, service demandeur, destinataire (direction, siège), responsable qui décide, date souhaitée, urgence, justification et besoins chiffrés (fonds, matériel, prestations) ; envoi au responsable (e-mail avec le PDF).
- **À traiter** : demandes à décider, à fournir, explications à donner, réceptions à confirmer. **Mes demandes**, **Toutes** (direction, comptabilité).
- **Fiche** : approuver (montants accordés ligne par ligne, personne et service chargés de fournir), demander une explication, refuser avec motif ; mettre à disposition (moyen, référence, virement entre comptes de trésorerie) ; confirmer la réception ; messages ; PDF ; historique complet.
- **Tableau de bord** : demandé, accordé, fourni, taux d'accord, délais de décision et de mise à disposition, retards, par service, urgences. **Réglages** : services, responsable par service, relances, e-mail.

## Recouvrement

Dans « Relances & recouvrement » : onglets **Dossiers de recouvrement** (clients à mettre en contentieux, dossiers en cours ou clos) et **Tableau de bord du recouvrement**. Fiche dossier : encaissement (imputé aux factures les plus anciennes), appel, visite, promesse de paiement, échéancier négocié, mise en demeure (PDF, envoi par e-mail), huissier, frais, procédure, passage en perte, clôture ; factures du dossier, échéancier et historique.

## Ressources humaines et paie

Menus « Salariés & congés » (module `rh`) et « Paie » (module `paie`), connexion nécessaire.

- **Salariés** : fiche complète, primes fixes, parts fiscales calculées, solde de congés.
- **Congés** : saisie, approbation en un clic ; une absence non payée approuvée est retirée automatiquement des jours payés.
- **Paie** : « Nouvelle paie » prépare les bulletins du mois ; on saisit les jours et les éléments variables (heures supplémentaires, primes, avances…), puis Valider (écriture comptable) et Payer.
- **Documents** : bulletins PDF (un par page), livre de paie PDF et Excel, déclarations CNPS / DGI / FDFP imprimables.
- **Simulateur** : du brut au net ou du net au brut, pour préparer une embauche.

## Langues

- Menu du compte (ou bouton sur l'écran de connexion) → **Langue** : français ou anglais, appliqué immédiatement sans redémarrage. Un administrateur peut en faire la langue par défaut de la société.
- Les textes de l'interface passent par `tr('texte français')` (`lib/i18n/i18n.dart`) ; les traductions sont dans `assets/i18n/en.json`. `trn()` pour un texte facultatif, `verbatim()` pour une donnée saisie par l'utilisateur (jamais traduite).
- La langue est envoyée au serveur (`X-Locale`) : messages d'erreur, PDF et e-mails suivent la même langue. Les dates et nombres sont formatés selon la langue.

## Interface

Transitions animées entre les pages, cartes et listes qui apparaissent en cascade, survol des cartes, compteurs animés du tableau de bord, chargements en squelette, menu latéral animé (barre de l'élément actif, survol), notifications flottantes. Les animations sont coupées si le système demande de réduire les animations.

## Boutique de modules

Onglet **Mes abonnements** : chaque module acheté ou à l'essai avec sa date de fin, une jauge du temps restant et « Renouveler » (la nouvelle période démarre à la fin de l'actuelle). Un bandeau « Renouveler » apparaît dans l'application 14 jours avant l'échéance. Super admin → Modules & tarifs → « Tarif unique ».

## Commerce, collaboration et marketing

- **Conditions de paiement** (Paramètres) : comptant, 30 jours, 45 jours fin de mois, acompte et solde… (échéances en %, jours, fin de mois), condition par défaut des ventes et des achats. Sur la fiche contact : condition du client et du fournisseur. Dans les devis, commandes et factures : champ « Conditions de paiement » (repris du tiers) ; la facture validée affiche son **échéancier** (échéances réglées, en retard, à venir), imprimé sur le PDF.
- **CRM** : pipeline en colonnes (glisser une carte pour changer d'étape), liste, activités, statistiques ; « Créer un devis » depuis une opportunité, même hors ligne.
- **Boutique de modules** (menu Société) : fiches avec captures, essai gratuit, abonnement ou achat définitif, paiement en ligne ou hors ligne, suivi des commandes, contact de l'éditeur. Super admin : « Ventes de modules » (commandes, moyens de paiement, contacts, serveur e-mail de l'ERP) et « Modules & tarifs » (mode de vente, prix, essai, fiche, captures).
- **Encaissements** (Paramètres) : moyens de paiement en ligne de la société avec ses propres clés, comptes bancaires imprimés sur les factures ; sur une facture validée, menu ⋮ → « Lien Payer en ligne ».
- **Demandes internes** : nouvelle demande vers un service, suivi des étapes et durées, onglets À traiter / Mon périmètre / Statistiques / Rapports / Organisation ; cloche de notifications.
- **Marketing** : campagnes e-mail conçues par **glisser-déposer** (palette de blocs et de sections, réglages du bloc sélectionné, apparence, aperçu ordinateur / mobile, annuler / rétablir, mises en page prêtes) ou en HTML (aperçu exact dans la version Web), SMS et WhatsApp (aperçu téléphone, compteur de SMS), test A/B de l'objet, rapport (entonnoir, A/B, ouvertures heure par heure, carte des clics). Onglets **Automatisations** (scénario visuel avec branches oui / non, contacts en cours et historique), **Contacts & segments** (segments dynamiques avec comptage en direct, score d'engagement, activité de chaque contact), **Formulaires & pages** (page de destination conçue avec le même éditeur, champs, double opt-in, lien, WhatsApp, code à intégrer, affiche QR code en PDF) et **Modèles** (miniatures).
- **Canaux d'envoi** (Paramètres) : votre serveur SMTP ou celui de l'ERP, fournisseur SMS, fournisseur WhatsApp, bouton de test. Sur une facture ou un devis validé, l'icône ✈ envoie le PDF par e-mail, WhatsApp ou SMS (lien).

## Projets

Menu « Projets » (module `projets`), **utilisable hors ligne** : les projets, tâches, jalons et temps se synchronisent comme les ventes.

- **Projets** : cartes avec avancement, tâches en retard, temps passé / budget, équipe ; montant à facturer pour les gestionnaires.
- **Fiche projet** : tableau Kanban (glisser une tâche d'une colonne à l'autre), liste, **planning** (barres de Gantt, jalons, aujourd'hui), temps (par personne et détail), jalons & facturation (« Créer la facture » du temps passé ou d'un jalon atteint : la facture brouillon s'ouvre dans les Ventes).
- **Tâche** : responsable, priorité, dates, estimation, jalon, liste de contrôle, motif de blocage, temps passé, **chronomètre** (il reste actif même si l'application est fermée ; à l'arrêt, le temps est proposé à la saisie).
- **Mes tâches** (tous projets, par échéance) et **Mes temps** (feuille de la semaine : touchez une case pour saisir).
- **Rentabilité** (onglet de la fiche projet, chefs de projet ; en ligne) : budget consommé, coûts réalisés et engagés, CA facturé, marge, coût à terminaison ; barre main-d'œuvre / achats / commandes en cours ; détail par personne ; bons de commande et factures rattachés ; « Coûts horaires » (général et par personne).
- **Rattacher au projet** : champ « Projet » des devis, commandes, factures et documents d'achat (modifiable aussi sur un document validé) et des appels d'offres.

## Livraisons

Menu « Livraisons » (module `logistique`), **utilisable hors ligne** par les livreurs.

- **Planning du jour** : colonne « À attribuer » et une colonne par tournée (livreur + véhicule). Glissez les livraisons dans une tournée, réordonnez les arrêts par la pastille numérotée ; menu de la tournée : itinéraire Google Maps, feuille de route PDF.
- **Créer une livraison** : bouton « Livraison », ou depuis une commande / facture validée (menu ⋮ › « Créer la livraison ») : client, téléphone, adresse, articles et reste à payer sont repris ; la zone se devine d'après la commune (frais et délai).
- **Livreur** (rôle « Livreur ») : il arrive directement sur « Mes livraisons » ; appel, WhatsApp, itinéraire, « Je pars livrer », « Livrée » (signature au doigt, nom, montant encaissé et moyen, photo) ou « Échec » (motif). Tout est enregistré sans réseau et part à la reconnexion.
- **Encaissements** : montants encaissés par livreur, « Remis » à la réception de la caisse. **Statistiques** sur la période.

## Notes de frais

- **Mon espace → Mes notes de frais** : « Nouvelle dépense » (avec le « Projet » concerné si vous voyez les projets : le coût apparaît dans la rentabilité du projet), joignez la photo du ticket, « Envoyer ». Sans réseau, la dépense est gardée et la photo part (puis la dépense est envoyée) dès le retour de la connexion. Une dépense refusée se corrige et se renvoie.
- **Notes de frais** (menu Finance, valideurs) : « À valider » (voir le justificatif, approuver ou refuser avec un motif), « À rembourser » (par salarié : depuis la caisse, la banque ou le Mobile Money, ou sur la paie en brouillon), « Historique », « Catégories » (compte de charge, plafond, justificatif obligatoire).

## Recrutement

Menu « Recrutement » (module `recrutement`), section RH. Offres, candidatures et entretiens fonctionnent hors ligne ; CV, messages, embauche et statistiques demandent la connexion.

- **Page Emplois** : bouton en haut de l'écran, lien à copier et partager (Facebook, LinkedIn, WhatsApp, votre site), présentation de l'entreprise, durée de conservation des candidatures non retenues.
- **Offres** : « Nouvelle offre », « Ouverte » + « Publier sur la page Emplois » pour la mettre en ligne.
- **Candidatures** : colonnes par étape, faites glisser une carte pour la faire avancer (filtre par offre, candidatures non retenues masquées par défaut). Touchez une carte : coordonnées, CV, note, notes internes, étape, « Planifier » un entretien, « Écrire au candidat » (modèles convocation / réponse négative / proposition), « Embaucher » (crée la fiche salarié puis propose d'établir le contrat). « Ajouter un candidat » pour une candidature reçue autrement (CV joint).
- **Entretiens** : avis attendus, à venir, réalisés ; « Donner mon avis » (note, recommandation, compte rendu). Un intervenant (chef de projet, commercial…) ne voit que cet onglet et les candidats qu'il rencontre.
- **Statistiques** : candidatures, embauches, délai moyen, sources, motifs de non-sélection.

## Approbations

Menu « Approbations » (module `approbations`), section Collaboration.

- **À approuver** (avec le nombre en attente), **Mes demandes**, **Toutes** (responsables), **Circuits** (responsables), **Délégations**.
- Fiche d'une demande : étapes (validées, en cours), historique (accords, refus, « pour le compte de », escalades), ouverture du document, annulation, décision (accord ou refus motivé).
- **Circuits** : types de documents, montant HT, remise de ligne, catégories de frais, étapes (rôles ou personnes, « l'un d'eux » ou « tous », escalade vers un rôle), validation automatique, relances, ordre.
- **Documents** : en validant un document concerné, l'application annonce qu'il part en approbation (contrôle local, le serveur fait foi) ; bandeau « En attente d'approbation — étape x/y » ou « refusée par … » avec le bouton « Décider » pour l'approbateur ; pastille « En approbation » dans les listes. Même bandeau sur les notes de frais.

## Champs personnalisés

Menu « Champs personnalisés » (module `champs`, responsables), section Société : champs par objet, création et modification (type, choix, obligatoire, défaut, aide, impression, listes, ordre, activation) avec aperçu de la saisie.

Les champs apparaissent dans les fiches client / fournisseur, article, salarié, opportunité, projet et dans les devis, factures et achats (« Informations complémentaires », sur deux colonnes en grand écran). Saisie hors ligne, contrôle des champs obligatoires avant l'enregistrement, valeurs par défaut à la création. Sur un document validé, les informations restent modifiables (bouton « Enregistrer les informations »). Les champs « imprimés » figurent sur le PDF du document (et ceux du client dans son bloc d'adresse) ; les champs « affichés dans les listes » sous le nom du contact sur mobile.

## Lots & séries

Menu « Lots & séries » (module `lots`), section Commerce.

- **Fiche article** : traçabilité Aucune / Par lot / N° de série, date de péremption, durée de vie (jours) et alerte avant péremption.
- **Écran Lots & séries** : indicateurs (lots en stock, périmés, à écouler, valeur), recherche par n° de lot ou de série, filtres de statut, stock par lot et par entrepôt avec péremption en couleur ; **traçabilité** d'un lot (fournisseur, clients, mouvements et documents) ; **rebut des périmés** en un clic ; réglages (délai d'alerte, blocage des périmés). Hors ligne : stock par lot calculé sur l'appareil.
- **Documents** : sous chaque ligne d'un article suivi, bouton « Lots » — en vente, choix des lots en stock (du plus urgent au moins urgent, périmés barrés) ou FEFO automatique ; à l'achat et sur les avoirs, saisie des lots (n°, péremption, quantité) ou des numéros de série (un par ligne, lecteur de code-barres possible, obligatoires sur facture fournisseur).
- **Réceptions** de bons de commande et **mouvements manuels** (n° de lot et péremption à l'entrée, lot à sortir ou à transférer).

## Tarifs & conditionnements

Menu « Listes de prix » (module `tarifs`), section Commerce : listes avec leurs règles (article / catégorie / tout, conditionnement, « à partir de », prix fixe ou remise), dates, liste par défaut, **simulateur** (client, article, unité, quantité → prix appliqué et règle).

- **Fiche client** : liste de prix attitrée. **Fiche article** : conditionnements (nom, contenance, code-barres, prix de vente et d'achat).
- **Documents** : liste « Unité » sur chaque ligne d'un article conditionné ; prix et remise recalculés selon la liste du client, la quantité et le conditionnement (au choix du client, à l'ajout, au changement de quantité), mention « Tarif : … » ; un prix saisi à la main n'est plus recalculé. Le code-barres d'un carton dans la recherche d'article ajoute le carton.
- **Caisse** : scan d'un conditionnement = un carton / pack sur le ticket, liste de prix du client (ou liste par défaut) appliquée hors ligne, remise manuelle prioritaire.

## Variantes

Module `variantes` : interrupteur « Modèle à variantes » sur la fiche article, attributs (3 au plus) et valeurs, suppléments de prix par valeur, nombre de combinaisons ; liste des variantes avec référence, code-barres, prix et stock ; **Générer** (combinaisons manquantes) et **Répercuter…** (prix, TVA, unité, catégorie, seuil, description, actif). Les modèles n'apparaissent ni dans le choix d'articles des documents ni à la caisse ; badges « Modèle », lot, série et conditionnement dans la liste des articles.

## Événements & salons

Menu « Événements & salons » (module `evenements`), section Commerce : événements avec dates, lieu, état et barre de remplissage (confirmé / option / libre).

- **Plan** : grille des emplacements colorés selon l'état (libre avec la couleur de la catégorie, option, confirmé, indisponible) et le nom de l'exposant ; touchez un emplacement pour le réserver, ouvrir la réservation ou le modifier ; « Emplacement » et « Rangée » (génération A01…A10, en ligne) pour les responsables.
- **Réservations** : filtres (en cours, options, confirmées, expirées, annulées), recherche ; fiche : exposant, enseigne, contact, emplacements libres à cocher, sponsors et options avec quantités restantes, remise, date limite de l'option, totaux HT / TTC et surface, factures liées (ouvrables), boutons Confirmer, Facturer le solde, Exposant arrivé, Annuler. Saisie hors ligne ; le serveur refuse une double réservation à la synchronisation.
- **Plan en six états** : libre, indisponible, option, réservé (à encaisser), acompte payé, payé ; dimensions réelles du stand (largeur × profondeur en mètres, surface calculée) dans le formulaire de l'emplacement et l'infobulle ; **Marquer payé** depuis le plan ou la réservation (espèces, Mobile Money, virement, chèque, carte, référence).
- **Fiche exposant / sponsor** depuis la réservation : logo, accroche, présentation, offre salon, site et réseaux, publication dans l'annuaire ; lien de l'**espace en ligne** à envoyer à l'exposant (WhatsApp, copier).
- **Sponsors & options** (contreparties, quantité, page publique), **Bilan** (remplissage, surface, confirmé, options, facturé, encaissé, accueillis, par catégorie), **Réglages** (plan, TVA, acompte, solde, durée des options, catégories, page publique) et lien de la page publique (copier, WhatsApp).

## Hébergement web

Menu « Hébergement web » (module `hebergement`), section Commerce : indicateurs (actifs, échéances à 30 jours, expirés, revenu mensuel, marge), onglets **Échéances (60 j)**, **À faire** (factures à encaisser, renouvellements payés à faire chez le fournisseur) et **Tous**, filtre par type, recherche.

- **Fiche service** : client, échéance, prix et coût, fournisseur et offre, renouvellement, détails techniques, facture en attente (ouvrable), historique ; actions Facturer le renouvellement, Envoyer un rappel, Prolonger sans facture, Renouvelé chez le fournisseur, Modifier.
- **Formulaire** : client, type, nom (domaine), fournisseur, offre, fiche fournisseur, prix de vente, coût, TVA, période, début et échéance, renouvellement automatique et délai, rappels, état, détails et notes. Réglage des rappels (paliers en jours, envoi automatique des factures).

## Billetterie

Menu « Billetterie » (module `billetterie`, avec Événements & salons), section Commerce : un bouton **Guichet** et un bouton **Contrôle** par événement. Dans l'événement, onglet **Billetterie** : billets vendus, entrées du jour, chiffre d'affaires, jauge ; **tarifs** (visiteur, VIP, exposant, organisation, presse) avec prix TTC, quota, dates de validité, ré-entrée, couleur et vente en ligne ; liste des billets et badges avec réimpression et annulation. Le formulaire de l'événement active la **billetterie en ligne**, la **jauge** et l'**acompte payable en ligne** des exposants.

- **Guichet** : vente hors connexion, billets générés sur l'appareil (code court `ABCD-1234` et QR code) et imprimés sur A4 (8 par page) ou en ticket 80 mm ; le serveur facture et encaisse à la synchronisation.
- **Badges** : équipe et presse en un clic ; badges des exposants depuis leur réservation de stand (bouton Badges, connexion requise).
- **Contrôle** : **caméra** du téléphone, de la tablette, du Mac ou du navigateur (bouton caméra ; activée par défaut sur Android et iPhone ; lampe et changement de caméra ; un même QR code laissé devant la caméra n'est compté qu'une fois), douchette USB / Bluetooth ou saisie du code, résultat plein écran vert (entrée validée), orange (déjà entré aujourd'hui à HH:MM) ou rouge (inconnu, autre événement, annulé, hors dates), point de contrôle, historique ; fonctionne hors ligne, le serveur réévalue chaque passage à la synchronisation.

- **Inscriptions & avis** (onglet de l'événement, connexion requise) : formulaire d'inscription (e-mail obligatoire ou facultatif, entreprise, fonction, questions personnalisées, consentement Marketing, message de confirmation, demande d'avis après l'événement) ; indicateurs animés (inscriptions, gratuites / payées, présence, contacts de la liste, note, NPS), courbe des inscriptions par jour, provenance, entreprises, réponses à chaque question, étoiles, commentaires, entrées par heure ; boutons Demander les avis (après la fin), Page d'inscription, Annuaire exposants.
- **Super admin → Événements (plateforme)** : tous les événements de toutes les sociétés (à venir, passés), inscrits, présence, stands occupés, note, recettes.

## Site web (sites vitrines)

Menu « Site web » (module `sites`), section Commerce :

- **Modèles** : 54 vignettes dessinées (couleurs et mise en page de chaque modèle), filtres par secteur et par style, **Aperçu** dans le navigateur avec les informations de la société, **Choisir** (envoie la demande) ou **Appliquer** (change le modèle du site en gardant textes et photos).
- **Site sur mesure** : projet, pages (ajout / retrait), fonctionnalités avec prix, express, logo et contenus fournis, budget, date ; **estimation immédiate** animée (HT, TVA, TTC, détail) puis « Demander le devis ».
- **Demandes** : état de chaque demande, **devis** reçu (lignes, remise, total, validité, conditions) à **accepter** ou **refuser** (motif), annulation.
- **Mon site** (une fois créé) : état (brouillon, en ligne, suspendu), adresse, Publier / Dépublier, Ouvrir, Partager ; visites sur 30 jours (barres animées), provenance, messages.
- **Personnaliser** : vignette en direct, couleurs (nuancier ou code), bannière, polices, photos (bannière, présentation, logo, galerie), sections activables et réordonnables, textes d'accueil et bouton principal (contact, WhatsApp, appel, rendez-vous, produits, événements), services avec pictogramme, chiffres clés, témoignages, FAQ, coordonnées, réseaux, référencement ; boutons flottants Aperçu et Enregistrer.
- **Boutique** : ouverture / fermeture, lien de la boutique, nouvelles commandes, en cours, commandes et ventes du mois ; **commandes** (à traiter, livrées, annulées) avec détail (articles, livraison, adresse, paiement, document de vente), passage à l'étape suivante (confirmer, en préparation, expédiée / prête, livrée), encaissement à la livraison, WhatsApp au client, lien de suivi, annulation ; **réglages** : retrait en boutique et adresse, zones de livraison et frais, livraison offerte dès, minimum, paiement en ligne / à la livraison / Mobile Money (instructions), catégories vendues, produits en rupture masqués, message d'accueil.
- **Fiches produits** (Boutique) : un produit par ligne (modèles à variantes regroupés, prix « à partir de », photos, avis) ; la fiche se règle dans une fenêtre : photos (ajout multiple, ordre, retrait, étiquette de variante pour que la photo suive la couleur choisie), badge, prix barré, description détaillée (## intertitres, **gras**, listes) et produits liés « Vous aimerez aussi » ; lien direct vers la fiche publique.
- **Codes promo** (Boutique) : création (code, %, montant ou livraison offerte, achat minimum, quota, dates, une fois par client, actif), utilisations, ventes et remises par code. **Avis** : note moyenne, avis avec étoiles par article, publier / masquer, réponse publique. **Paniers** : paniers en attente et récupérés, relance WhatsApp en un clic. Réglages : demande d'avis (délai), publication sans vérification, relance des paniers (délai).
- **Blog** : articles (image, publié / brouillon, lectures), éditeur avec titre, résumé, texte mis en forme (## intertitre, **gras**, *italique*, listes, liens), image de couverture, publication, suppression.
- **Messages** : messages du formulaire (non lus en gras), réponse par e-mail ou WhatsApp, opportunité CRM créée.
- **Domaine** : adresse du site, domaine personnel à relier, **enregistrements DNS à créer** (TXT, A, CNAME, copiables), état et vérification.
- **Super admin → Sites vitrines** : demandes (pages et fonctionnalités, estimation, budget), **Établir / Réviser le devis** (lignes modifiables, remise, note), Passer en réalisation, **Créer le site** (modèle de départ), Refuser ; liste des sites (domaine, visites, messages ; ouvrir, vérifier le domaine, suspendre, rétablir) ; réglages (domaine des sites, IP du serveur, cible CNAME, création automatique, société prestataire, TVA).

## Yomara Scan (application de contrôle d'entrée)

Point d'entrée `lib/main_scan.dart` : même serveur, mêmes comptes et mêmes données que l'ERP, base locale et synchronisation identiques (les scans fonctionnent hors ligne). Accueil : événements en cours et à venir, entrées / billets et exposants arrivés en direct, bouton **Scanner les billets** (caméra, douchette ou saisie), synchronisation et entrées en attente, déconnexion. Réservé aux comptes autorisés au contrôle (`billetterie.scan` ou `billetterie.manage`).

- Web : `flutter build web --release -t lib/main_scan.dart --base-href /scan/ --no-web-resources-cdn -o build/scan`, copié dans `public/scan` du serveur (ouvrir `https://votre-serveur/scan/`, installable comme application sur le téléphone).
- Android / iOS : `flutter build apk -t lib/main_scan.dart` (ou `ipa`) ; pour un nom et une icône distincts de l'ERP, utiliser un *flavor* ou un projet séparé qui importe ce fichier.

## Forfaits & livrables

Menu « Forfaits & livrables » (module `prestations`), section Opérations.

- **Forfaits d'heures** : cartes avec temps consommé, jauge colorée (vert, orange au seuil d'alerte, rouge en dépassement), reste et échéance ; fiche du forfait avec facture du forfait, facture du dépassement au taux horaire, renouvellement et détail des temps. **Saisir du temps** : date, durée, description, facturable, même hors connexion.
- **Livrables** : titre, client, forfait, description, lien ou fichier (envoi du fichier en ligne), date de réponse attendue ; **Envoyer au client** (lien par e-mail / WhatsApp, lien copiable), **nouvelle version**, relance, historique des décisions. Le client valide ou demande des modifications depuis son lien ou son espace client.
- **Rapports clients** : rapport d'activité mensuel en PDF par client (temps passé, livrables, reste des forfaits), généré à la demande pour un mois au choix, envoyé au client, lien copiable ; réglages : envoi automatique le 1er du mois, temps des projets inclus ou non.
- **Projets** : dans la fiche projet, « Forfait d'heures du client » rattache le projet à un forfait ; le temps facturable du projet est décompté du forfait (pastille « Sur forfait ») et n'est plus refacturé par le projet.

## Planning des ressources

Menu « Planning des ressources » (module `planning`), section Collaboration : indicateurs (occupation de l'équipe, prévu / capacité, disponible, réalisé, personnes en surcharge), frise de l'équipe sur 2, 4 ou 8 semaines avec les affectations en couleur (empilées si elles se chevauchent, option en clair), jours de repos et fériés grisés, congés en orange, surcharges en rouge, ligne du jour ; taux d'occupation et heures libres de chaque personne ; recherche par personne, projet ou client. Un clic sur un jour crée une affectation, un clic sur une affectation l'ouvre (personne, projet, client, intitulé, dates, heures par jour, option, couleur, notes, « Répéter après », suppression). Saisie hors ligne ; la capacité exacte (horaires, congés, fériés) et le réalisé viennent du serveur, la dernière réponse reste consultable hors ligne (sinon semaine de 40 h). Bouton « Semaine type » pour les responsables.

## Appels d'offres & marchés

Menu Métiers › « Appels d'offres & marchés » (module `marches_publics`).

- **Appels d'offres** : autorité contractante, procédure, nature, date et heure limite de dépôt avec compte à rebours. Passez l'étape à « On soumissionne » : la liste des pièces du dossier est créée. Boutons « Pièces du dossier », « Bordereau des pièces » (PDF) et « Marché gagné ».
- **Pièces administratives** : attestations saisies une fois avec leur validité, reprises dans chaque dossier, alertes avant expiration.
- **Marchés** et **Décomptes** : avance, retenue de garantie, pénalités, réceptions ; « Facturer » crée la facture brouillon dans Ventes. **Cautions** : de la demande à la mainlevée.

## Location de matériel

Menu Métiers › « Location de matériel » (module `location`).

- **Contrats** : devis (client, départ, retour), puis « Matériel du contrat » ; le prix suit la durée. Boutons « Réserver », « Sortie du matériel », « Retour du matériel » (retard et retenue sur caution), « Facturer », « Bon de location » (PDF).
- **Disponibilités** : choisissez une période, la quantité libre de chaque matériel s'affiche. **Matériel** : tarifs jour, semaine, mois et caution.

## Maintenance

Menu Opérations › « Maintenance » (module `maintenance`). Tout salarié peut « Signaler une panne ».

- **Ordres de travail** : « Approuver et planifier », « Démarrer », « En attente de pièces », « Terminer » (rapport, cause, temps, pièces, coûts, compteur).
- **Équipements** (bouton « Historique »), **Plans préventifs** (par date ou compteur ; « Créer les OT à échéance »), **Relevés de compteur**.

## Configuration du serveur et moyens de paiement

- Super admin › **Configuration du serveur** : e-mail d'envoi (bouton « Tester »), adresse publique, assistant IA, services externes… sans modifier le fichier `.env`.
- Administration › **Moyens de paiement** : vos comptes CinetPay, Wave, Orange Money, MTN MoMo, PayPal… ou un lien marchand ; « Tester la connexion » vérifie les clés.
- Version Web : `…/app/?ecran=<identifiant>` ouvre directement un écran (ex. `?ecran=maintenance`).

## Pilotage & prévisions

Menu Pilotage › « Pilotage & prévisions » (module `pilotage`) : **Soldes de gestion** (marge, valeur ajoutée, EBE, résultat, comparaison N-1), **Prévisions** (12 mois, atterrissage, objectifs), **Seuil de rentabilité** (point mort et simulateur), **Rentabilité** (clients, articles, catégories), **Objectifs**.

## Qualité

Menu Opérations › « Qualité » (module `qualite`). Tout salarié peut « Signaler un problème ». Contrôles : créez le contrôle puis « Remplir le contrôle » critère par critère ; une non-conformité est ouverte sous le seuil. Bouton « Actions » sur une non-conformité.

## Sondages & formulaires

Menu Marketing › « Sondages & formulaires » (module `questionnaires`) : « Questions » (éditeur, glisser pour réordonner), « Partager » (lien, QR code, WhatsApp), « Saisir une réponse », « Résultats » (satisfaction, NPS, répartition), « Excel ».

## Service client

Menu « Service client » (module `helpdesk`), section Opérations.

- **Tickets** : indicateurs (ouverts, en retard, délais tenus, 1re réponse moyenne, satisfaction), filtres (ouverts, mes tickets, non attribués, en retard, en attente du client, résolus), recherche ; chaque ligne montre la priorité (couleur), le canal, le client, l'agent et le délai restant (« Répondre sous 25 min », « Résolution en retard de 1 h »). Bouton « Ticket » pour un appel, un e-mail ou un message WhatsApp.
- **Fiche du ticket** : conversation (client, réponses, notes internes en jaune, journal), réponse au client ou note interne, réponses types, temps passé (minutes) saisi avec le message ; statut (en cours, en attente du client, résolu, fermer, rouvrir), attribution, priorité, catégorie, forfait du client, coordonnées, délais, temps passé ; lien de suivi du client (copier, ouvrir). Tout fonctionne hors ligne et part à la synchronisation.
- **Pièces jointes** : reçues par e-mail ou WhatsApp, affichées sous chaque message (enregistrer en un clic) ; bouton trombone pour joindre un fichier, envoyé avec la réponse suivante.
- **Centre d'aide** : articles (rubrique, mots-clés, contenu avec mise en forme simple, publié ou brouillon), lectures et taux « utile », lien du centre d'aide public.
- **Réglages** (responsables) : horaires et jours ouvrés, fermeture automatique, attribution automatique, accusé de réception, avis du client, centre d'aide et formulaire ; délais garantis par priorité (heures ouvrées ou continues, par défaut, clients sous contrat), catégories (responsable, couleur, proposée au client), réponses types ; **réception des e-mails et de WhatsApp** : boîte IMAP (serveur, port, chiffrement, identifiant, mot de passe, dossier, relève automatique, « Relever maintenant », dernière relève et erreur), adresse du webhook e-mail, adresse du webhook WhatsApp et jeton de vérification Meta (copier).

## Feuilles de temps

Menu « Feuilles de temps » (module `feuilles_temps`), section Collaboration.

- **Ma semaine** : navigation par semaine, statut (brouillon, envoyée, validée, à corriger avec le commentaire), heures par jour en barres, total et facturable, détail des saisies (projets, forfaits, tickets), « Ajouter du temps » (projet, forfait ou ticket), « Envoyer pour validation », « Retirer pour modifier ». Semaine envoyée ou validée verrouillée.
- **À valider** (responsables) : feuilles envoyées par l'équipe avec le total, le facturable et les heures de chaque jour, détail dépliable, « Valider » ou « Renvoyer » avec un commentaire.

## Portail fournisseurs

Menu « Portail fournisseurs » (module `portail_fournisseurs`), section Commerce.

- **Appels d'offres** : liste (articles, fournisseurs invités, offres reçues, date limite, statut) ; création : objet, description, date et heure limites, lieu de livraison, articles (désignation, quantité, unité), fournisseurs invités ; « Enregistrer le brouillon » ou « Publier » (les fournisseurs reçoivent leur lien). Fiche : publier, clore, annuler, **comparaison des offres** (prix de chaque fournisseur par ligne, meilleur prix en vert, total, offre partielle, délai, commentaires, trophée sur l'offre complète la moins chère) et **Attribuer** (bon de commande brouillon créé dans les Achats).
- **Factures déposées** : « Importer une facture » (PDF ou photo : lecture automatique, formulaire pré-rempli — fournisseur, n°, date, montant, bon de commande — à vérifier), réglage de la tolérance ; chaque facture affiche le **rapprochement** (pastille conforme / réception partielle / écart…, « Détail » : commandé, reçu, déjà facturé, attendu, écart, lignes) et les écarts relevés dans le document ; fournisseur, n°, date, montant, bon de commande, PDF à ouvrir, « Accepter » (facture fournisseur créée dans les Achats) ou « Refuser » avec un motif.
- **Confirmations** : réponses des fournisseurs aux bons de commande (date de livraison, refus motivé).
- **Fournisseurs** : inviter (lien envoyé et copié), dernière visite, copier le lien, révoquer.

## Rapports & analyses

Menu « Rapports & analyses » (module `rapports`), juste sous le tableau de bord.

- **Tableaux de bord** : choix du tableau, période imposée à tous les rapports (ou celle de chacun), actualisation, export PDF. Mode « Modifier la disposition » : ajouter un rapport, le déplacer, régler largeur (1 à 4 colonnes) et hauteur, renommer, retirer ; paramètres et partage du tableau. Grille 4 / 2 / 1 colonnes selon la largeur d'écran. Les derniers chiffres affichés restent consultables hors ligne (bandeau « Hors connexion — chiffres du … »).
- **Rapports** : liste par dossier avec recherche ; « Nouveau rapport » depuis 25 modèles ou une page blanche. Fiche : période, changement de présentation à la volée, graphique + tableau des données + comparaison, export Excel / PDF, duplication, ajout à un tableau de bord, programmation de l'envoi, suppression.
- **Constructeur** : données, regroupements, mesures, période et comparaison, filtres (valeurs proposées par le serveur, recherche), tri, top N, cumul, présentation, description, dossier, partage ; **aperçu en direct** à droite (en dessous sur mobile). Les définitions s'enregistrent hors ligne et partent à la synchronisation.
- **Envois programmés** (responsables) : liste avec dernier envoi et erreurs, activation, envoi immédiat, modification, suppression.
- Graphiques dessinés par l'application (palette validée pour le daltonisme, un seul axe, info-bulle au survol ou au toucher, légende dès deux séries, « Autres » au-delà de 8).

## Devises & cours

Menu « Devises & cours » (module `devises`), section Finance. Cartes des devises proposées avec leur dernier cours (l'euro à parité fixe), historique des cours, saisie d'un nouveau cours et choix des devises proposées (direction). Les cours sont synchronisés : un document en devise se saisit hors ligne avec le dernier cours connu.

- **Documents** : champ « Devise et cours » dans l'en-tête (devise reprise de la fiche du partenaire), montants dans la devise, ligne « Contrevaleur » en F CFA ; le PDF affiche la devise et la contrevaleur. Les listes de documents totalisent en F CFA.
- **Clients & fournisseurs** : devise habituelle et interrupteur « Suspendre les relances ».

## Relances clients

Menu « Relances clients » (module `relances`), section Commerce : « Paliers & messages » → **Rappel avant échéance** (jours avant, message, canaux) ; onglet **Priorités** (point bas de trésorerie prévu et seuil ; clients en retard classés par effet sur le point bas — pastille « Évite le passage sous le seuil » —, montant échu, retard, délai de paiement habituel, date attendue ; toucher un client ouvre sa fiche de relance), balance âgée par client (filtre « En retard seulement »), détail d'un client avec ses factures échues, aperçu du message et relance immédiate ou suspension, historique des relances, onglet « Paliers & messages » (délais, objet, texte à variables, canaux). Demande la connexion.

## Contrats & abonnements

Menu « Contrats & abonnements » (module `abonnements`), section Commerce : indicateurs MRR / ARR, liste des contrats (actifs, en pause, terminés), fiche avec lignes, périodicité, début ou fin de période, fin du contrat, validation et envoi automatiques ; détail avec les factures générées, « Facturer maintenant », pause, reprise et résiliation. Les contrats se saisissent hors ligne ; la facturation se fait sur le serveur.

## Immobilisations

Menu « Immobilisations » (module `immobilisations`), section Finance. Registre, fiches et catégories fonctionnent hors ligne (saisie et mise en service envoyées à la synchronisation) ; plan détaillé, dotations, sorties et tableau demandent la connexion.

- **Registre** : indicateurs (biens en service, valeur brute, valeur nette, % amorti), filtres En service / Brouillons / Sorties, recherche par code, désignation, n° de série ou emplacement.
- **Nouvelle immobilisation** : catégorie (compte, durée et méthode remplis), valeur, dates, méthode, dotations annuelles ou mensuelles, « Voir le plan d'amortissement », reprise d'un registre existant, comptabilisation de l'acquisition, n° de série, emplacement, responsable, fournisseur, ventilation analytique ; « Enregistrer » (brouillon) ou « Mettre en service ».
- **Fiche** : valeur, amortissements, valeur nette, plan période par période (comptabilisée, à passer, prévue, reprise) ; « Comptabiliser » les dotations échues ; menu : modifier, céder / mettre au rebut, annuler la dernière dotation, annuler la sortie.
- **Comptabiliser les dotations** (en-tête) : toutes les dotations des périodes terminées jusqu'à la date choisie (31/12 de l'an dernier, fin du mois dernier…).
- **Tableau des immobilisations** : exercice, totaux par catégorie et par bien, export Excel ; **Catégories** : comptes, méthode et durée par défaut ; **Paramètres** : périodicité, comptabilisation automatique, comptes de contrepartie et de cession.
- **Facture fournisseur validée** : menu « … » → « Créer des immobilisations » (lignes à cocher, catégorie).

## Analytique & budgets

Menu « Analytique & budgets » (module `analytique`), section Finance. Axes, sections et règles fonctionnent hors ligne ; rapport, budgets et saisies manuelles demandent la connexion.

- **Rapport analytique** : période (mois, trimestre, année, année précédente ou dates au choix), axe ; produits, charges, marge et part non ventilée ; graphique mensuel ; sections et sous-sections (cumul) avec marge et taux. Touchez une section : détail par compte, par mois et ligne à ligne (document d'origine, pourcentage).
- **Budgets** : « Nouveau budget » (période, préremplissage avec le réalisé de la période précédente ± %) ; fiche : prévu, réalisé, engagé (bons de commande), reste et % par ligne, graphique prévu / réalisé ; « + Ligne » : charges ou produits, section et/ou début de compte (60, 62, 66, 70…), montant total réparti ou saisie mois par mois ; Approuver, Clôturer, Repasser en brouillon, Supprimer.
- **Axes & sections** : axes (obligatoire pour les achats et notes de frais, actif), sections et sous-sections ; l'axe « Projets » se remplit tout seul. « Saisie manuelle » répartit un montant (loyer, frais généraux) ; « Tout recalculer » applique les règles actuelles à l'historique.
- **Règles de ventilation** : produit, catégorie, client ou fournisseur, catégorie de frais, service du salarié → sections.
- **Documents** (ventes et achats) : champ « Analytique » sous l'en-tête (corrigeable même après validation) et icône de ventilation sur chaque ligne ; en achat, le budget restant des sections choisies s'affiche (en rouge s'il est dépassé). **Notes de frais** : champ « Analytique » (le responsable peut le compléter à la validation).

## Facture normalisée (FNE)

Menu « Facture normalisée (FNE) » (module `fne`), section Commerce. Les statuts, références et QR codes sont synchronisés et consultables hors ligne ; paramètres, certification manuelle et relances demandent la connexion.

- **Paramètres DGI** (direction, comptabilité) : activation, environnement Test / Production (URL de production communiquée par la DGI), clé API (masquée après enregistrement), établissement, point de vente, modèle par défaut, code des lignes à 0 % (TVAD / TVAC), certification automatique des factures, tickets et avoirs, message commercial, pied de facture, seuil d'alerte de stickers.
- **Suivi** : indicateurs (certifiées ce mois, en attente, refusées, non certifiées sur 90 jours, stickers restants) ; filtres À traiter, Certifiées, Non certifiées (bouton « Certifier »), Toutes ; touchez une ligne pour le détail, la relance, la saisie manuelle de la référence DGI ou les données échangées avec la DGI. « Envoyer la file maintenant » force l'envoi des factures en attente.
- **Fiche facture / ticket / avoir** : bloc « Facture normalisée » avec QR code, N° FNE, lien de vérification, ou motif du refus avec « Relancer » (modèle et moyen de paiement modifiables) ; facture fournisseur : « Certifier » émet un bordereau d'achat. « Annuler le document » disparaît une fois la facture certifiée (créez un avoir).
- **Impression** : facture PDF, ticket PDF et ticket thermique portent la mention « Facture normalisée électronique DGI », le N° FNE et le QR code (« Certification FNE en cours » tant que la DGI n'a pas répondu ; réimprimez le ticket ensuite).

## Agenda

Menu « Agenda » (module `agenda`, gratuit), section Collaboration. Les événements fonctionnent hors ligne ; congés, rendez-vous et échéances des autres modules, liste des collègues et abonnement demandent la connexion (les derniers éléments chargés restent affichés).

- **Vues** Jour, Semaine, Mois, Liste ; « Aujourd'hui », flèches ; touchez un créneau libre (jour / semaine) pour créer un événement à cette heure.
- **Mon agenda** (ce que j'organise ou auquel je participe) ou **Équipe** (tous, ou les collègues choisis) ; « Congés, RDV, échéances » affiche les autres modules.
- **Nouvel événement** : titre, catégorie, horaires ou journée entière, répétition, salle ou véhicule (conflit signalé avant l'enregistrement), lieu, lien de visio, participants (collègues déjà pris signalés), invités externes, description, rappel, privé.
- Touchez un événement : détail, réponses des participants, **votre réponse**, export `.ics`, modifier, supprimer (une occurrence ou toute la série).
- **Google / Outlook** : lien d'abonnement personnel et marche à suivre ; **Salles & véhicules** (direction) : ressources réservables.

## Documents (GED)

Menu « Documents » (module `documents`), section Collaboration. Dossiers et propriétés des documents fonctionnent hors ligne ; dépôt, ouverture, recherche dans le contenu, versions, partage et corbeille demandent la connexion.

- **Parcourir** : arborescence à gauche (écran large) ou fil d'Ariane ; touchez un document pour l'ouvrir (PDF en aperçu, photo à l'écran, autres formats enregistrés).
- **Déposer des fichiers** (plusieurs à la fois) dans le dossier ouvert ; **Nouveau dossier** ; menu « ⋮ » du dossier : renommer, couleur, **droits d'accès** (administrateur), partage, suppression.
- Menu « ⋮ » d'un document : télécharger, **Renommer, classer, échéance…** (dossier, description, mots-clés, client ou salarié lié, date d'échéance), nouvelle version, historique des versions (ouvrir, revenir à une version), **Partager par lien** (durée, mot de passe, téléchargements max., copier, WhatsApp, désactiver), verrouiller, supprimer.
- **Recherche** : tapez un mot puis Entrée — noms, mots-clés et contenu des PDF, Word et Excel (hors ligne : noms et mots-clés).
- **Échéances** : documents échus ou à échéance dans les 30 jours ; **Corbeille** : restaurer, ou supprimer définitivement (administrateur).
- Fiches **client / fournisseur** et **salarié** : bloc « Documents » avec « Joindre ».

## Signature électronique

Menu « Signature électronique » (module `signature`), section Collaboration. Nécessite la connexion.

- **Nouvelle demande** : choisissez un PDF, un titre, les signataires (« Externe » : nom, qualité, téléphone et/ou e-mail ; « Utilisateur » : signe dans l'application), l'ordre de signature et le délai, puis « Envoyer ».
- **Faire signer** depuis un devis, une commande ou une facture validés (menu « … » du document) ou depuis un contrat, un solde de tout compte ou un avertissement émis (Salariés & congés → Documents) : le PDF et les signataires sont préparés.
- **Demandes** : en cours, signées, refusées / expirées / annulées, brouillons. Touchez une demande : état de chaque signataire, copier le lien ou l'envoyer par WhatsApp quand aucun canal n'a pu le joindre, relancer, journal, original et PDF signé.
- **À signer** : documents que vous devez signer ; « Signer », tracez votre signature, confirmez.
- **Vérifier un document** ouvre la page publique de vérification ; **Paramètres** : code de vérification, validité, relances automatiques.

## Pointage & présence

Menu « Présence » (module `pointage`), section RH. Tout fonctionne hors ligne sauf la feuille de présence, l'export et l'envoi en paie.

- **Borne** : sur une tablette à l'entrée, connectez-vous avec un compte au rôle « Borne de pointage » (elle s'ouvre directement sur la borne), ou « Ouvrir la borne » depuis l'écran Présence. Le salarié touche son nom et tape son code (clavier à l'écran ou clavier physique).
- **Aujourd'hui** : qui est sur place, en retard, parti, absent, en congé ou en repos ; touchez un salarié pour voir ses pointages et les corriger.
- **Feuille de présence** : semaine ou mois, heures travaillées, retards, absences, heures supplémentaires ; « Excel » ; « Envoyer les heures sup. en paie » (paie du mois en brouillon).
- **Pointages** : liste du jour, saisies manuelles (motif obligatoire), pointages reçus en différé signalés.
- **Horaires & codes** : horaires par équipe, horaire de chaque salarié, pointage mobile autorisé ou non, codes PIN, durée hebdomadaire et jours fériés variables.
- **Mon espace** : « Pointer mon arrivée / mon départ » et « Créer / changer mon code borne ».

## Salariés, congés et espace salarié

- **Salariés & congés** (rôle RH) : fiches et congés fonctionnent hors ligne. Dans la fiche, « Compte de connexion » relie le salarié à son compte : il obtient alors son espace. L'onglet « Congés & absences » s'ouvre sur les demandes à traiter : approuver, ou refuser / annuler avec un motif envoyé au salarié.
- **Mon espace** (tout salarié relié) : solde de congés payés, prochain congé, « Demander un congé » (part au service RH dès le retour du réseau), suivi des demandes et annulation tant qu'elles ne sont pas traitées, bulletins de paie validés (imprimer, télécharger en PDF — connexion nécessaire), fiche et « Mes coordonnées ».
- **Documents** (onglet de « Salariés & congés ») : « Nouveau document », choisissez le salarié et le modèle ; le texte se remplit depuis la fiche, complétez les champs demandés, relisez, « Émettre » (numéro attribué, document figé) puis imprimez ou enregistrez le PDF. Les demandes des salariés apparaissent en tête (« Demandes »). « Modèles et signataire » pour adapter les textes et le nom de la personne qui signe. Solde de tout compte : motif de sortie, sommes proposées à vérifier et modifier.
- **Mon espace → Mes documents** : « Obtenir un document » (attestation immédiate si la fiche est complète, sinon demande à la RH ; certificat ou autre document sur demande), impression et PDF.
- Rôle « Salarié » : uniquement l'espace salarié et les demandes d'achat. Chaque rôle existant reçoit aussi l'espace salarié.

## Banque & trésorerie

Menu « Banque & trésorerie » (module `tresorerie`), section Finance.

- **Comptes** : la trésorerie disponible et le solde de chaque compte (banque, caisse, Mobile Money), calculés sur l'appareil même hors ligne. Les comptes Caisse, Banque et Mobile Money sont créés au premier passage ; renommez-les, ajoutez-en (plusieurs banques, plusieurs numéros marchands) et indiquez quels moyens de paiement chacun reçoit par défaut. Le formulaire de paiement d'une facture permet aussi de choisir le compte.
- **Recette / Dépense** : loyer, frais bancaires, salaires, carburant… avec catégorie ; **Virement** : versement d'espèces en banque, alimentation de la caisse (sortie d'un compte, entrée dans l'autre).
- **Mouvements** : encaissements, règlements et opérations d'un compte avec le solde après chaque ligne.
- **Rapprochement bancaire** (connexion nécessaire) : « Importer un relevé » (Excel ou CSV de la banque ou de l'espace Mobile Money), vérifiez les colonnes, importez : les lignes déjà enregistrées se rapprochent seules. « Traiter » une ligne restante pour l'associer à un mouvement, créer le paiement de la facture concernée, créer une opération (frais, agios) ou l'ignorer. L'écart entre le livre et le relevé s'affiche à la date du relevé.
- **Mobile Money** (gestionnaires, connexion nécessaire) : « Configurer » donne, pour chaque compte Mobile Money, l'adresse où une application de transfert de SMS du téléphone marchand envoie les confirmations Wave, Orange Money, MTN et Moov ; chaque encaissement reconnu solde la facture ou l'échéance du client ; onglets À traiter / Rapprochés / Ignorés / Sorties ; « Affecter » à une facture suggérée, « Recette hors facture », « Ignorer » ; « Coller un SMS » avec aperçu de la lecture.
- **Prévisions** (connexion nécessaire) : solde prévu sur 13 semaines en trois scénarios (Optimiste / Réaliste / Pessimiste) — factures clients au retard moyen de chaque client, commandes à facturer, abonnements, fournisseurs, bons de commande, paie et charges, notes de frais, opportunités du CRM et prévisions saisies. Graphique (entrées, sorties, courbes des trois scénarios, seuil en pointillés), indicateurs (point bas, fin de période, première semaine sous le seuil), détail d'une semaine par catégorie, tableau par catégorie. « Paramètres » : seuil d'alerte, horizon, hypothèses du scénario pessimiste, délais, opportunités. Une prévision saisie peut ne concerner que certains scénarios.

## Import / export

Menu « Import / export » (module `import_export`), section Société. Connexion internet nécessaire.

- **Importer** : choisissez ce que vous importez (clients & fournisseurs, articles, inventaire, factures impayées), déposez le fichier Excel ou CSV tel qu'il sort de l'ancien logiciel (ou partez du modèle Excel). Vérifiez la correspondance des colonnes proposée, les options (ajouter / mettre à jour, fiche reconnue par…, tout ou rien), puis « Vérifier sans rien enregistrer » avant « Importer ». Les lignes refusées s'affichent avec leur motif ; « Fichier des lignes à corriger » les renvoie dans un Excel à corriger et réimporter.
- **Exporter** : clients, articles, stock, mouvements, ventes, lignes de vente, encaissements, achats, en Excel ou CSV, sur une période pour les documents.
- **Historique** : les imports réalisés et leur résultat.

## Étiquettes & codes-barres

Menu « Étiquettes » (module `etiquettes`), bouton « Étiquettes » sur la fiche produit, sur une réception d'achat et sur un ordre de fabrication terminé.

- **Articles** : ajoutez des articles (par catégorie, tout cocher), ou « Préparer depuis… » une réception, une fabrication, les **prix modifiés** depuis la dernière impression, ou les articles jamais étiquetés. Réglez le nombre d'étiquettes par article.
- **Format** : planche A4 ou rouleau ; présentation Produit, Rayon ou Code seul ; champs à afficher. Sur planche, « Commencer à l'étiquette n° … » réutilise une planche entamée. L'aperçu montre la première planche.
- **Codes-barres** : « Attribuer des codes » donne un EAN-13 interne aux articles qui n'en ont pas (et un code balance aux articles vendus au kg).
- **Pesée** : pour un article au kg avec code balance, saisissez le poids du colis ; l'étiquette porte le poids, le prix et un code que la **caisse** lit pour ajouter le bon poids.
- Imprimez à l'échelle 100 %. Pour une imprimante d'étiquettes (Zebra, Xprinter, Godex…), choisissez-la dans la fenêtre d'impression avec le format du rouleau.

## Fabrication

Menu « Fabrication » (module `production`).

- **Nomenclatures & recettes** : pour chaque produit, les composants (avec perte en %), le temps de travail et les frais. « Fabrication » = produit fabriqué à l'avance par lot ; « Recette vendue » = plat ou kit dont les ingrédients sortent du stock à chaque vente. Le coût de revient, la marge et la part du coût dans le prix se calculent en direct. Réglez le coût horaire de la main-d'œuvre avec l'icône de réglages en haut de l'écran.
- **Ordres de fabrication** : « + Ordre de fabrication », choisissez le produit et la quantité ; les composants sont calculés. Confirmer → Démarrer → Terminer : à la fin, indiquez la quantité réellement produite et ce qui a été consommé. Le rôle « Opérateur de production » arrive directement sur cet écran.
- **Besoins** : ce qu'il faut pour les ordres confirmés et en cours, face au stock, avec les manques.

## Restaurant & bar

Menu « Restaurant » (module `restaurant`, avec la caisse).

- **Salle** : plan de la salle (sur téléphone, une grille de tables). Touchez une table libre, indiquez les couverts : la commande s'ouvre. Le responsable dessine le plan avec le crayon (ajout, déplacement par glisser-déposer, forme, places).
- **Commande** : touchez un plat pour l'ajouter, appui long pour la quantité, la suite et les précisions. « Envoyer » part en cuisine et au bar ; avec plusieurs suites, choisissez ce qui part maintenant (les plats peuvent attendre la fin des entrées). Touchez une ligne pour la marquer servie ou l'annuler (motif obligatoire). Menu ⋮ : demander l'addition, couverts, transfert de table, note imprimée, annulation.
- **Addition** : il faut une caisse ouverte sur l'appareil. Toute la table, ou les articles de chaque convive, puis l'encaissement habituel (parts égales proposées). La table se libère quand tout est réglé.
- **Cuisine & bar** : un bon par envoi, du plus ancien au plus récent ; touchez un plat pour le lancer puis le marquer prêt, ou « Tout est prêt ». Le serveur reçoit une notification. Filtre par poste, mémorisé sur l'appareil. Le rôle « Cuisine / bar » arrive directement sur cet écran.
- **Réglages** : salles, postes (cuisine, grill, bar…) et catégories qu'ils préparent, impression des bons sur ce poste. **Statistiques** pour le responsable.

## Fidélité & cartes cadeaux

Menu « Fidélité » (module `fidelite`).

- **En caisse** : dès qu'un client est choisi, ses points, son niveau et leur valeur s'affichent ; « Inscrire au programme » crée sa carte (hors ligne aussi). À l'encaissement, « Points fidélité » propose le montant payable en points, « Carte cadeau » demande le code et affiche le solde restant. Paiements mixtes possibles.
- **Sur une facture** : « Enregistrer un paiement » propose les mêmes moyens (carte du client par défaut).
- **Clients fidèles** : recherche par n° de carte, nom ou téléphone ; fiche avec carte, historique et échéances des points, ajout / retrait de points motivé, blocage.
- **Cartes cadeaux** : émission (carte vendue ou bon offert), code à copier, solde, annulation.
- **Récompenses**, **Programme** (taux, valeur du point, minimum, validité, bienvenue, niveaux) et **Statistiques** pour les responsables.

## Rendez-vous

Menu « Rendez-vous » (module `rendez_vous`).

- **Agenda** : une colonne par membre de l'équipe ; touchez une plage pour créer un rendez-vous, touchez un rendez-vous pour le confirmer, le marquer honoré ou absent, le déplacer, l'annuler (le client est prévenu) ou le facturer.
- **Prestations** (durée, battement, prix TTC, membres, en ligne ou non, sur confirmation) et **Équipe & horaires** (plages de chaque jour, utilisateur lié, fermetures et absences).
- **Page de réservation** : lien à partager, ouverture / fermeture, confirmation automatique, pas des créneaux, délais de réservation, d'annulation et de rappel, message d'accueil.
- Un **praticien** (rôle « Praticien », lié à sa fiche dans l'équipe) ne voit et ne gère que son agenda.

## Achats

Écran « Achats » (et « Demandes d'achat » dans le menu pour les autres services).

- **Demandes d'achat** : chacun décrit son besoin (articles du catalogue ou libres, prix estimé), puis « Envoyer ». L'acheteur approuve en deçà du seuil, la direction au-delà (Paramètres › Société : « Seuil d'approbation des achats »). Une demande approuvée devient en un clic un bon de commande par fournisseur.
- **Bon de commande** au-delà du seuil : bandeau « Demander l'approbation » / « Approuver » ; une fois validé, panneau **Réceptions** : « Réceptionner » (quantités reçues, n° de BL), suivi ligne par ligne, annulation d'une réception.
- Menu « … » : **Réapprovisionner** (articles sous le seuil, fournisseur et prix proposés, création des bons), **Réceptions** (commandes attendues et historique), **Tarifs fournisseurs**.

## Interventions

Menu « Interventions » (module `interventions`), **utilisable hors ligne** par les techniciens.

- **Planning** : colonne « À planifier » (demandes, y compris celles des clients via le portail) et grille de la semaine par technicien ; glissez une intervention sur un technicien et un jour. « Générer les visites des contrats » crée tout de suite les visites préventives des 14 prochains jours (sinon c'est fait chaque matin).
- **Fiche** : client, contact, équipement et son historique, points de contrôle, pièces, photos, rapport. Le responsable facture en un clic (« Facturer ») ou rouvre l'intervention.
- **Technicien** (rôle « Technicien ») : il arrive sur « Mes interventions » ; « Je pars », « Démarrer sur place », ajout des pièces, puis « Terminer et faire signer » (travaux, recommandations, temps, signature au doigt, satisfaction). Le rapport part au client à la synchronisation.
- **Équipements** et **Contrats** (fréquence, technicien attitré, délai garanti, forfait, « Facturer la période »), **Statistiques**.

## Portail client

Module `portail`. Depuis la fiche d'un client : « Espace client » → inviter par e-mail, WhatsApp ou SMS (ou copier le lien), voir ses visites et ce qu'il a consulté, lire et répondre à ses messages, couper l'accès. « Portail client » en haut de la liste des contacts ouvre la boîte de réception (messages, devis acceptés ou refusés). Les nouvelles réponses arrivent aussi dans la cloche de notifications.

## Développement

```bash
flutter pub get
flutter run -d windows   # ou macos, linux, chrome
```

À la première ouverture, saisir l'adresse du serveur (ex. `erp.monentreprise.ci`). La version Web servie par le serveur la déduit automatiquement.

## Compiler

| Cible | Commande | Où compiler |
|---|---|---|
| Web | `flutter build web --release --base-href /app/ --no-web-resources-cdn` puis copier `build/web` dans `public/app` du serveur | n'importe où |
| Windows | `flutter build windows --release` | un PC Windows (Visual Studio 2022, charge « Développement Desktop en C++ ») |
| macOS | `flutter build macos --release` | un Mac (Xcode) |
| Linux | `flutter build linux --release` | Linux avec `libgtk-3-dev` |

**Sans machine dédiée** : poussez le projet sur GitHub, le workflow `.github/workflows/build.yml` compile les quatre versions automatiquement (onglet *Actions*, ou en créant un tag `v0.1.0` pour publier une *Release* avec les fichiers à télécharger).

## Tests

```bash
flutter test                                        # démarrage de l'interface
YOMARA_TEST_SERVER=http://127.0.0.1:8000 flutter test   # + scénario hors ligne complet contre un serveur de démo
```

`test/escpos_test.dart` vérifie la mise en page des tickets 80/58 mm, l'encodage des accents, la coupe, le tiroir et l'envoi à une imprimante réseau simulée.

`test/rh_paie_test.dart` vérifie la fiche salarié (création, modification, parts fiscales) et la génération du bulletin de paie PDF.

`test/customer_display_test.dart` démarre le serveur local de l'écran client et vérifie la page, l'état initial et la diffusion en direct (panier, paiement, retour à l'accueil).

`test/approbations_champs_test.dart` vérifie les champs personnalisés (champs actifs et ordre, affichage, obligatoires) et le choix local d'un circuit (cible, remise, montant, priorité, accord implicite, « à moi de décider »), puis la saisie : valeur par défaut, liste, case à cocher, montant à la française.

`test/report_charts_test.dart` vérifie la préparation des graphiques de rapports (une série par mesure de même format, ligne « Autres », séries de la 2e dimension regroupées au-delà de 8), la mise en forme des valeurs et l'affichage de chaque présentation (tableau, histogramme, barres, courbe, camembert, indicateur, tableau croisé).

`test/pos_offline_test.dart` simule une journée de caisse complète sans réseau (ouverture, tickets, paiements mixtes, clôture) puis vérifie les chiffres recalculés par le serveur.

Le scénario hors ligne général coupe le réseau, crée produit, entrepôt, stock, client, facture validée et paiement, vérifie le stock calculé localement, rétablit le réseau puis contrôle la numérotation serveur, les totaux, le statut de paiement, le mouvement de stock, la détection d'un conflit et la propagation d'une suppression.

## Structure

```
lib/
├── core/        api_client, local_db (Hive), sync_engine, stock_calc
├── state/       session (serveur, connexion, modules, permissions, présence)
├── pdf/         génération des factures / devis
└── ui/          thème, shell (menu selon modules et droits), écrans
    └── screens/admin/   super admin : vue d'ensemble, connectés, modules & tarifs, sociétés, formules
```

Le menu n'affiche que les modules activés pour la société et autorisés pour le rôle de l'utilisateur.
