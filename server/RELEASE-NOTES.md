# Notes de version

## 0.99.59 — modèles d'e-mails, socle modifiable, guides par écran, récupération Word / Excel

- **E-mails aux clients mis en page** : factures, devis, commandes, bons de livraison, avoirs et reçus partent dans un e-mail à votre logo, avec la fiche du document (numéro, date, montant, échéance), un bouton « Payer en ligne » quand un lien existe, et vos coordonnées en pied de page (© Yomara Technologies).
- **Modèles d'e-mails** : nouvel onglet Super admin › Modèles d'e-mails (texte par défaut de toutes les sociétés) et Paramètres › Modèles d'e-mails (texte propre à la société), avec variables ({client}, {numero}, {montant}…) et aperçu en direct.
- **Bienvenue des employés** : chaque employé ajouté reçoit une notification et un e-mail « Votre compte est prêt » avec un bouton pour choisir son mot de passe (lien valable 7 jours, utilisable une fois).
- **Socle et formules depuis Modules & tarifs** : en tête de l'écran, une carte par formule (le socle gratuit d'abord) pour cocher ses modules et modifier son texte de présentation. Une formule « sur devis » à 0 F n'est plus prise pour le socle.
- **Notifications** : toucher une notification ouvre l'écran concerné (plus de 60 modules reconnus), sur l'application comme sur le web.
- **YoMail sur téléphone** : barre d'actions fixe en bas de la lecture (Répondre ▾, non lu, supprimer, archiver), comme Outlook ; « Mail » devient « YoMail » dans la barre du bas. Les e-mails au code HTML complexe s'affichent fidèlement (boutons blancs sur fond de couleur compris).
- **Outils PDF : historique personnel** : chaque fichier produit (fusion, découpe, modification…) reste 30 jours dans « Mes fichiers récents », visible par son auteur seul, à rouvrir ou retélécharger.
- **Word et Excel en ligne : rien de perdu** : chaque modification est aussi gardée sur l'appareil jusqu'à son enregistrement. Après une fermeture imprévue, une coupure réseau ou un plantage, l'éditeur propose « Restaurer » à la réouverture ; l'application mise en arrière-plan enregistre aussitôt.
- **Guide de chaque écran** : bouton « ? » en haut de chaque écran (à quoi il sert, par où commencer, bon à savoir, écrans liés) et invitation discrète à la première visite.
- **Demandes internes** : « Confier une tâche » avec explication en trois étapes (vous décrivez, la personne est prévenue, vous suivez), et avancement visible sur chaque demande (envoyée › prise en charge › terminée).
- **Rapports d'activité** : explication « votre rapport, sans le rédiger » et avancement préparé › envoyé › validé.
- CRM : l'onglet Relances s'affiche correctement sur téléphone. Copyright Yomara Technologies sur l'écran de connexion et sous le menu.
- Application : version 1.0.10 (build 17).

## 0.99.58 — la formule gratuite devient le socle de toutes les formules

- **Socle gratuit** : les modules cochés dans la formule gratuite sont compris d'office dans toutes les formules payantes. Il n'est plus nécessaire de les recocher dans Starter, Business ou Professional ; quand vous modifiez la formule gratuite, toutes les formules suivent.
- **Page Tarifs** : chaque formule payante affiche « Tout Gratuit, plus : » suivi uniquement des modules qu'elle ajoute, avec sa valeur réelle (socle compris).
- **Super admin › Formules** : dans une formule payante, les modules du socle apparaissent cadenassés (« compris dans le socle gratuit ») ; la carte indique « Socle gratuit + N modules ».
- Boutique de modules : un module du socle ne peut plus être acheté en plus (il est déjà compris). Les rappels de fin d'essai ne listent plus les modules du socle, qui restent actifs.
- Application : version 1.0.10 (build 16).

## 0.99.57 — liens intelligents YoMail

- **Lien intelligent YoMail** (`/yomail`) : sur Android, ouvre YoMail dans l'application installée, sinon Google Play (sans message d'erreur) ; sur iPhone, iPad et Mac, page « Ouvrir dans l'application » / App Store / continuer dans le navigateur ; sur Windows et Linux, YoMail s'ouvre directement dans la version web. L'application reconnaît aussi `yomara://mail` et `yomara://ecran/<écran>`.
- **Lien de l'application sous les e-mails** (option de la société, YoMail › Paramètres) : « Envoyé avec YoMail · Télécharger l'application », vers le lien intelligent.
- **Suivi des clics** : option « Suivre les clics sur les liens » à la rédaction (cochée par défaut si la société le souhaite). Les liens passent par une redirection signée (aucune adresse choisie par un tiers ne peut être utilisée) ; dans « Éléments envoyés », le message affiche le nombre de clics par lien. Les liens de désinscription et `mailto:` ne sont pas modifiés.
- Application : version 1.0.10 (build 15).

## 0.99.56 — YoMail, lecture des e-mails en plein écran, WhatsApp intégré sur Windows

- **Lecture d'un e-mail** : tout défile d'un seul tenant, barre d'outils comprise ; plus rien de figé en haut ni en bas. Les boutons Répondre / Répondre à tous / Transférer sont à la fin du message, le bloc « Conversation » est supprimé : le message occupe tout l'écran.
- **WhatsApp intégré sur Windows** (plus de fenêtre ni d'onglet à part) : une seule vue WhatsApp pour tout le logiciel, gardée en vie quand on change d'écran, jamais dupliquée ni détruite (ce qui faisait planter WebView2). Le bouton WhatsApp d'une fiche client charge la conversation dans cette même vue et affiche l'écran WhatsApp. Même fonctionnement sur Mac.
- **« Boîte mail » devient « YoMail »** dans le menu, la boutique de modules et les messages (renommé automatiquement sur les installations existantes).
- Application : version 1.0.10 (build 14) pour les stores.

## 0.99.55 — pièces jointes ouvertes dans l'application

- **Boîte mail** : les images et les PDF reçus s'ouvrent directement dans l'application, sans passer par le navigateur. Images en plein écran avec zoom, glisser pour passer à l'image suivante, bouton Enregistrer. PDF dans le lecteur de l'application (zoom, impression, enregistrement, outils PDF). Les autres fichiers (Word, Excel…) s'ouvrent comme avant dans l'application adaptée de l'appareil.
- « Enregistrer » et « Enregistrer les pièces jointes » téléchargent les fichiers au lieu de les ouvrir l'un après l'autre.

## 0.99.54 — WhatsApp stable sur Windows et adapté au téléphone, lien intelligent des applications, audit avant mise en ligne

- **WhatsApp sur Windows ne peut plus fermer le logiciel** : WhatsApp Web ne tourne plus à l'intérieur de l'ERP sous Windows (le navigateur intégré WebView2 pouvait s'arrêter brutalement et emporter toute l'application). La conversation s'ouvre dans l'application WhatsApp de l'ordinateur si elle est installée, sinon dans une fenêtre WhatsApp dédiée (Edge ou Chrome en mode application, sans barre d'adresse, QR code scanné une seule fois). Android, iPhone/iPad et Mac gardent WhatsApp dans l'ERP.
- **WhatsApp sur téléphone, présenté comme l'application** : un seul volet à la fois, la liste des discussions en pleine largeur puis la discussion ouverte en plein écran avec une flèche de retour ; le bouton retour du téléphone ferme la discussion. Un bouton de la barre WhatsApp repasse à l'affichage ordinateur si besoin. Tablettes : affichage ordinateur inchangé.
- **Lien intelligent « Application »** (QR code de la boîte mail, `/application-mobile`) : iPhone et iPad vont sur l'App Store, Android sur Google Play, Mac sur le Mac App Store ; sur un PC Windows ou Linux, une page propose le Microsoft Store, la version Linux et l'édition libre (open source), le système de l'ordinateur en premier. Les QR codes déjà affichés ou imprimés utilisent automatiquement le nouveau lien.
- **Tableau de bord personnalisé** : les indicateurs choisis s'affichent désormais tout en haut du tableau de bord dès l'enregistrement (ils étaient placés sous les graphiques et semblaient ne pas s'appliquer). **Ordre au choix** : glisser un indicateur par sa poignée sur un autre, ou flèches « avancer / reculer » ; le numéro donne la place sur le tableau de bord.
- **Écran WhatsApp plus soigné** : barre aux couleurs de WhatsApp avec mention de la session privée et filet de chargement, écran d'ouverture dédié ; sur ordinateur, page d'accueil en deux volets avec les trois étapes (ouvrir, lier le téléphone une fois, écrire aux clients depuis l'ERP).
- **Office et visioconférence sous Windows** : un seul environnement WebView2 partagé pour tout le logiciel (en créer plusieurs sur le même profil pouvait faire planter l'application).
- **Avances et demandes de fonds** : une demande « Très urgente » provoquait une erreur sur les serveurs MySQL/MariaDB (colonne trop courte) ; corrigé par une migration.
- **Sécurité** : la route publique d'envoi de fichiers temporaires de Laravel (`storage/{path}`), inutilisée, est désactivée.
- **Audit complet avant mise en ligne** : 358 tests serveur réussis sur MariaDB et sur SQLite, 70 tests de l'application, compilations Android, iPhone et Windows, parcours de tous les écrans (société de démonstration et société vide), mise à jour d'une installation 0.99.48 vers 0.99.54 sans perte de données, dépendances sans faille connue.

## 0.99.53 — reçus d'activation, lecture des e-mails, éditeur PDF façon Acrobat, bibliothèques de modèles, widgets

- **Activation manuelle d'un module ou d'une formule** (console super admin) : l'interrupteur d'un module ouvre la fenêtre d'activation avec le prix du catalogue proposé et modifiable (plus cher ou moins cher), le mode de paiement (espèces, Orange Money, MTN, Moov, Wave, virement, chèque, carte, offert), la référence et la durée. Le client reçoit par e-mail la confirmation du paiement et le **reçu PDF** (logo et coordonnées de Yomara Technologies, RCCM, NCC, client, période, montant en chiffres et en lettres, mode de paiement, cachet « PAYÉ »). Option « Garder ce prix pour les renouvellements de ce client ». Les clients retrouvent leurs reçus dans la boutique.
- **Boîte mail** : lecture d'un seul tenant comme sur Outlook et Mail (en-tête, pièces jointes, message en pleine hauteur), sans la barre de conversation ; **pièces jointes en liste de cartes** (nom, type, taille, menu), total et « Enregistrer les pièces jointes ».
- **Éditeur PDF façon Acrobat** : à l'ouverture d'un PDF, tous ses textes sont encadrés et deviennent modifiables d'un clic, à la même place, même taille, gras et police reconnus. Le PDF enregistré reste modifiable ligne par ligne. Les PDF scannés (images) proposent « Remplacer un texte ».
- **Factures plus simples** : barre « Brouillon → Validé → Payé » avec la prochaine étape en clair ; bouton « Valider ▾ » avec « Valider et encaisser » et « Valider et envoyer au client » en un seul geste.
- **Modèles de documents** : 6 nouveaux modèles (Ardoise, Sobre, Fraîcheur gratuits ; Émeraude, Corail, Minuit premium), 25 au total. **« Personnaliser » gratuit** pour chaque modèle disponible : couleurs, polices, présentation du total, titres des documents, filigrane du logo, bandeau de pied ; le modèle personnalisé rejoint « Vos modèles ». Galerie complète ouverte au super administrateur.
- **Relances prospect prêtes à l'emploi** (CRM › Relances) : 12 modèles par situation (après un devis, prospect qui ne répond plus, après un rendez-vous, demande reçue en ligne, rencontré sur un salon, offre qui expire, ancien client) et par métier (immobilier, école et formation, restaurant et traiteur, BTP, services et logiciels), avec WhatsApp, e-mail, SMS, appel et tâche ; copiés en un clic puis adaptés.
- **Marketing** : 10 nouveaux e-mails (présentation à un prospect, relance après devis, prise de rendez-vous, merci pour le rendez-vous, vous nous manquez, merci et avis, fidélité, vœux et fêtes, nouveau catalogue, enquête), 20 au total ; 14 messages SMS et WhatsApp prêts à l'emploi.
- **Widgets d'écran d'accueil** Android et iPhone/iPad : ventes du jour, reste à encaisser, factures en retard, mis à jour à chaque synchronisation (lisibles sans Internet), effacés à la déconnexion.

## 0.99.52 — bornes d'accueil : nouvelle interface

- **Interface des bornes refaite** : sur grand écran, panneau aux couleurs de l'événement (logo, nom du salon, dates, lieu, message d'accueil, les 3 étapes) et formulaire en sections claires (Vous, Vos coordonnées, Votre activité, D'où venez-vous ?) ; sur tablette en hauteur, bandeau compact. Grands champs tactiles, réponses en tuiles larges, consentement en carte, bouton « Valider mon inscription » toujours visible.
- **Visiteur qui revient** : pavé numérique à l'écran (numéro de ticket ou téléphone affiché en grand), lecture du QR code par la caméra à côté ; clavier et douchette acceptés.
- **Confirmation** façon ticket : numéro en grand, QR code, « Impression du ticket… », retour automatique au formulaire.
- **Personnalisation depuis l'administration** (Inscriptions & avis › Formulaire d'inscription) : couleur des bornes (palette ou code couleur) et logo de la société sur les bornes et les tickets ; le logo est gardé sur la borne pour fonctionner sans Internet.
- Ticket imprimé : logo de la société en tête, mention « © Yomara ERP — un logiciel de Yomara Technologies » en pied.

## 0.99.51 — bornes d'accueil : ticket imprimé, numéro de ticket, enchaînement des visiteurs

- **Ticket imprimé après chaque inscription** (et à chaque retour d'un visiteur) : événement, nom, entreprise, ville, **numéro de ticket** en gros (01-0042), QR code, date et heure d'entrée, borne. Formats : rouleau 80 mm, rouleau 58 mm, A6 ou A4 ; choix de l'imprimante et ticket d'essai dans les réglages de la borne. Impression directe sans fenêtre sur Windows, Mac, iPad et Linux ; sur Android et dans un navigateur, la fenêtre d'impression du système s'affiche (Chrome en mode borne `--kiosk --kiosk-printing` imprime sans fenêtre).
- **Numéro de ticket court** : numéro de la borne + compteur (borne 1 : 01-0001, 01-0002… ; borne 2 : 02-0001…). Les jours suivants, le visiteur tape simplement ce numéro (ou son téléphone, ou présente le QR code). Deux bornes réglées sur le même numéro sont signalées ; si cela arrive quand même hors ligne, aucune inscription n'est perdue.
- **Enchaînement** : la borne s'ouvre directement sur le formulaire ; 5 secondes après l'inscription, elle revient au formulaire vide pour le visiteur suivant (bouton « Visiteur suivant » pour aller plus vite). « Déjà inscrit ? Mon numéro » reste accessible en haut du formulaire.
- **Aucun compteur affiché au public** : le nombre d'inscrits et d'entrées de la borne n'apparaît que dans le menu protégé par le code.

## 0.99.50 — bornes d'accueil des salons et fréquentation par jour

- **Mode borne d'accueil** (Événements › un événement › Billetterie › « Mode borne d'accueil ») : à l'entrée d'un salon, les visiteurs s'inscrivent eux-mêmes sur une tablette, un ordinateur tactile ou un téléphone, en plein écran : prénom, nom, téléphone, e-mail, entreprise, ville / commune, pays, questions personnalisées (profil, « comment avez-vous connu le salon ? »…), consentement. L'inscription vaut entrée du jour ; le visiteur reçoit un QR code à l'écran (et par e-mail s'il l'a donné).
- Visiteur qui revient un autre jour : « Déjà inscrit ? » puis son QR code (caméra) ou simplement son numéro de téléphone ; une personne déjà inscrite qui remplit à nouveau le formulaire est reconnue (même téléphone ou e-mail), sans doublon.
- Fonctionne **sans Internet** : inscriptions et entrées restent sur la borne et partent au serveur au retour de la connexion, comptées au jour et à l'heure réels. Icône d'envoi en attente sur la borne.
- Borne verrouillée : retour automatique à l'accueil après 90 s sans action, sortie protégée par un code (appui long sur le nom de la borne), compteur du jour de la borne. Plusieurs bornes en même temps, chacune avec son nom.
- **Fréquentation** (nouvel onglet de l'événement, aussi accessible depuis la borne) : inscrits et visiteurs entrés par jour (bornes, en ligne, guichet), nouveaux et revenants, visiteurs uniques sur tout l'événement, heure de pointe et entrées par heure, chaque borne jour par jour, provenance (villes, pays, canal, réponses aux questions, entreprises). Exposants et organisation comptés à part.
- **Rapport Excel** : feuilles Par jour, Par borne, Provenance et Inscrits (coordonnées, réponses, jours de présence). Réservé aux gestionnaires de l'événement, pas aux comptes des bornes.
- Formulaire d'inscription : ville / commune et pays (non demandé, facultatif ou obligatoire, pays proposé par défaut), message d'accueil des bornes ; aussi sur la page d'inscription en ligne.

## 0.99.49 — bibliothèque de modèles premium

- **11 nouveaux modèles premium** de factures, proformas, devis et offres (Paramètres › Modèles de documents), tous avec aperçu : **Onyx** (bandeau noir et or, 20 000 F), **Royal** (violet et or, double cadre, filigrane du logo, 20 000 F), **Atelier** (empattements, double cadre, 15 000 F), **Lagon** (vague bleue, 15 000 F), **Géométrie** (cercles, grand titre, 15 000 F), **Monochrome** (noir et blanc, 10 000 F), **Kente** (frise tissée or, vert et rouge, 15 000 F), **Oblique** (bandeau oblique, 15 000 F), **Azur** (bandeau bleu roi, filigrane, 10 000 F), **Savane** (vague brun et ocre, 15 000 F), **Santé** (sarcelle, filets, 10 000 F).
- **Collection premium** (60 000 F, achat définitif) : tous les modèles premium, présents et à venir, en un seul achat.
- Bibliothèque classée par famille : Gratuits, Luxe, Moderne, Corporate, Créatif, Métiers.
- Nouveaux éléments de mise en page, aussi disponibles dans le Studio de modèles : décors de page (bandeau pleine largeur, vague, oblique, cercles, frise tissée, double cadre, filets), en-tête « Grand titre » avec cases Numéro / Date / Échéance et **montant dû mis en avant**, en-tête posé sur le décor, total sur fond plein ou souligné, police des titres (Playfair, Lora, Poppins), polices Poppins et Lora pour le texte, filigrane du logo, bandeau en pied de page.

## 0.99.48 — double authentification : applications déjà installées

- Correctif : une application installée avant la double authentification (téléphone, Windows, Mac) affichait « type 'Null' is not a subtype of type 'String' » à la connexion d'un compte protégé. Elle affiche maintenant un message clair, et permet de se connecter en saisissant dans « Mot de passe » le mot de passe suivi directement du code à 6 chiffres de l'application d'authentification (« motdepasse123456 »), en attendant la mise à jour depuis les stores. Code vérifié comme d'habitude (essais limités, code rejoué refusé).

## 0.99.47 — double authentification : plus de blocage si la base n'est pas à jour

- Correctif : après le dépôt des fichiers d'une nouvelle version sans mise à jour de la base, l'activation obligatoire de la double authentification renvoyait « Server Error » et empêchait d'atteindre le bouton « Mettre à jour la base ». Tant que la base n'est pas à jour, l'obligation est suspendue (l'application s'ouvre et propose la mise à jour) ; l'écran d'activation explique la situation et propose « Mettre à jour la base de données » en un clic.
- Vérifié sur MariaDB (comme en production) en plus des tests habituels.

## 0.99.46 — correctifs : double authentification, réponses dans la conversation, mode sombre

- Double authentification : « Activation expirée » à la saisie du code corrigé. L'activation en cours et les codes de connexion sont conservés en base de données (chiffrés) et non plus dans le cache du serveur, qui peut être vidé ou isolé sur un hébergement mutualisé.
- Boîte mail : une réponse envoyée apparaît tout de suite dans « Éléments envoyés » et en haut de la conversation, y compris avec Microsoft 365, Gmail et les serveurs qui ne renvoient pas leur copie ; la copie du serveur la remplace ensuite, sans doublon. La conversation affiche les messages les plus récents en premier.
- Mode sombre : les e-mails restent lisibles. Un e-mail simple (Outlook…) suit le thème sombre, ses couleurs de texte imposées (noir) sont retirées ; une lettre d'information mise en page est affichée sur fond clair, comme dans Gmail et Outlook.

## 0.99.45 — double authentification, tableau de bord d'adoption, reprise Sage

- **Double authentification** (Paramètres › Mon compte › Sécurité) : après le mot de passe, un code à 6 chiffres — application d'authentification (Google Authenticator, Microsoft Authenticator, Authy : QR code à scanner) ou code envoyé par e-mail. 8 codes de secours à usage unique en cas de téléphone perdu. « Ne plus demander sur cet appareil pendant 30 jours » (annulé si le mot de passe change). Obligatoire pour le super administrateur ; un administrateur peut l'exiger pour toute sa société, et la réinitialiser pour un utilisateur qui a perdu son téléphone (fiche de l'utilisateur). Code rejoué refusé, 5 essais par connexion, toutes les autres sessions fermées à l'activation. Les clés d'API des intégrations ne sont pas concernées.
- **Adoption clients** (console super admin) : pour chaque société cliente, dernière activité, utilisateurs actifs, saisies des 30 derniers jours, modules réellement utilisés, modules payés mais jamais ouverts, fin d'essai proche, score de 0 à 100 et action conseillée (« Aucune activité depuis 20 jours : appeler, risque de départ »). Filtres À traiter, À relancer, En danger, Jamais utilisé ; boutons Appeler, WhatsApp et E-mail ; taux d'utilisation de chaque module.
- **Reprise depuis Sage et Excel** (Import / export) : fichiers de tiers et d'articles exportés de Sage 100 reconnus d'office (CT_Num, CT_Intitule, CT_Type, AR_Ref, AR_Design, FA_CodeFamille, AR_PrixVen…). Nouveau : **balance d'ouverture** (une écriture d'à-nouveaux au début de l'exercice, comptes Sage à 8 chiffres rapprochés du plan, refus si déséquilibrée ou déjà importée) et **écritures comptables** (journal, date, pièce, compte, débit / crédit ou montant + sens 0 / 1, compte tiers rattaché au client ; une écriture par pièce, pièce déséquilibrée refusée, pièce déjà reprise ignorée). Vérification à blanc avant l'import, comme pour les autres fichiers.
- Sécurité : relier une installation au compte en ligne demande aussi le code de double authentification (le mot de passe seul ne suffit plus) ; l'obligation d'activer la double authentification ne peut pas être contournée par le nom d'appareil ; commande serveur de dernier recours `php artisan erp:2fa-reset adresse@email` (téléphone et codes de secours perdus).
- Correctif : un client (ou un article) présent deux fois dans le même fichier d'import n'est plus créé en double.
- Robustesse : les écrans acceptent une liste vide envoyée à la place d'un objet (cause de l'écran gris des Archives sur une société sans archive) ; graphique du CRM et grilles sûrs sur téléphone.

## 0.99.44 — modèles de documents, acomptes, Archives, WhatsApp Web, fiche contact, caractères spéciaux

- **Facture d'acompte** (numérotée ACP) : « Convertir › Facture d'acompte » depuis un devis, une proforma ou une commande (20, 30, 40, 50 % ou autre, une ligne par taux de TVA). Sur la facture finale du client, les acomptes validés sont proposés en un clic (« Déduire ») ; un acompte ne peut pas être déduit deux fois. Comptabilité : acompte et déduction au compte 4191 « Clients, avances et acomptes reçus ».
- **Modèles de documents** (Paramètres › Modèles de documents) : bibliothèque avec aperçus — Classique, Minimal, Moderne (gratuits) ; Prestige, Horizon, Offre commerciale (10 000 F), Ivoire (15 000 F), Executive (20 000 F) en achat définitif dans la boutique. Modèle par défaut par type (factures, proformas, devis et offres, commandes, acomptes, avoirs, bons de commande) et choix du modèle sur chaque document. Titres personnalisables (« OFFRE COMMERCIALE »), bloc « Bon pour accord » sur les devis et proformas, validité de l'offre.
- **Studio de modèles** (module, 15 000 F) : composition par glisser-déposer — blocs (en-tête, client, lignes, totaux, montant en lettres, règlement, FNE, décharge, bon pour accord, signature, texte libre, séparateur, espace…) à glisser dans la page ou réordonner, couleurs, police, en-tête (cartouche, épuré, bandeau, centré, deux tons), tableau (foncé, accent, clair, filets, zébré), liseré, aperçu en direct sur chaque type de document.
- **Archives** (nouveau module, palier Standard 30 000 F / mois car il occupe du stockage) pour l'archiviste : plan de classement prêt à l'emploi (OHADA : pièces comptables 10 ans), archivage automatique et scellé des factures validées, versement de scans (cote, emplacement physique, service versant, bordereau), empreinte SHA-256 et contrôle d'intégrité du fonds, confidentialité (public → secret), gel juridique, demandes de consultation et de prêt, éliminations en deux temps (visa puis exécution) avec procès-verbal PDF, journal complet exportable. « Verser aux archives » depuis la GED. Les archives comptent dans l'espace de stockage partagé.
- **WhatsApp Web** dans le menu Collaboration : intégré aux applications 1.0.9 (session conservée), fenêtre accolée sur la version web ; bouton WhatsApp sur les clients.
- **Boîte mail** : expéditeur et destinataires surlignés au survol ; au clic, fiche contact comme Outlook (appeler, écrire, WhatsApp, copier, ajouter aux contacts, tous ses e-mails, factures et reste dû, derniers échanges).
- **Caractères spéciaux** : les accents des e-mails reçus d'Outlook et d'autres logiciels ne sont plus abîmés (« reÃ§u » → « reçu ») ; les messages déjà reçus sont réparés à la mise à jour. Même correction pour les pages et e-mails conçus dans l'ERP et l'analyse de sites ; exports CSV de la comptabilité et des participants Yomeet lisibles dans Excel.
- **Facture récurrente depuis l'éditeur** : « ⋯ › Rendre récurrente » sur une facture, un devis ou une commande crée l'abonnement (chaque semaine, mois, trimestre, semestre ou année ; nombre de factures ; validation et envoi automatiques).
- **Parcours par métier** dans le guide de démarrage : commerce, restaurant, prestataire de services, grossiste, BTP, hôtel, santé et beauté, PME. Les écrans utiles sont épinglés en tête du menu et les quatre premiers pas sont proposés, chacun ouvrant le bon écran.
- **Applications 1.0.9** (Android, iPhone, Mac, Windows, Linux) : une seule source pour tous les stores, avec Office, WhatsApp Web et Yomeet intégrés et l'ouverture des liens directement dans l'application. Nouvelle commande Mac GENERER-TOUTES-LES-APPS : APK, App Bundle, IPA, PKG, DMG, MSIX, installateur Windows, .tar.gz, .deb et AppImage, avec la liste de ce qu'il faut envoyer à chaque store.
- Audit : déduction d'acompte contrôlée par le serveur (acompte validé du même client, jamais déduit deux fois) ; Archives : lever un gel juridique, avancer un terme ou décider une élimination demande le droit d'éliminer (séparation des rôles) ; taille des modèles du studio limitée ; bibliothèque « uuid » de l'éditeur Office mise à jour (avis de sécurité).
- Correctif : un module vendu uniquement en achat définitif n'est plus enregistré comme gratuit.

## 0.99.43 — factures : TTC, TVA selon le pays, décharge et montant en lettres

- Factures et devis : nouvelle colonne « Prix unit. TTC » calculée automatiquement ; saisir le TTC recalcule le HT (65 000 TTC à 18 % → 55 084,75 HT, total exact 65 000).
- TVA : le taux normal suit le pays de la société (Côte d'Ivoire 18 %, Sénégal 18 %, Cameroun 19,25 %, France 20 %…). Sur chaque ligne de vente, choix entre le taux normal et 0 %.
- Société non assujettie à la TVA (Paramètres › Informations de la société) : lignes à 0 % imposées (aussi par le serveur), facture sans colonne TVA, « Total » et mention « TVA non applicable ». Pays, régime d'imposition (RNI, RSI, Entreprenant…), case décharge et montant en lettres se règlent au même endroit.
- « Ajouter un produit » : bouton « Nouveau produit » et « Créer « … » » quand la recherche ne trouve rien ; le produit créé s'ajoute aussitôt à la facture.
- Inspiré du parcours Dolibarr : TVA à 0 % par défaut pour un client établi dans un autre pays (exportation) ; « Compte bancaire affiché » choisi sur chaque facture ; mode de règlement par défaut de la société ; forme juridique et capital social (« SARL au capital de 1 000 000 F CFA ») en en-tête et en pied de page ; prix de vente du produit saisi au choix en HT ou en TTC.
- Facture PDF : logo de la société, code client, TVA détaillée par taux (« TVA 18 % »), filigrane « BROUILLON » sur les brouillons.
- Facture PDF : mode de règlement (choisi sur la facture ou d'après les paiements reçus), « Arrêtée la présente facture à la somme de … francs CFA », case « Pour décharge » (signature du client et de la société), pied de page complet (adresse, téléphone, e-mail, RCCM, NCC, régime, banque), colonne Désignation alignée à gauche.

## 0.99.42 — correctifs de sécurité

- Signatures d'e-mail : les adresses d'image, de logo, de photo et de site saisies dans le formulaire sont vérifiées (http/https ou image intégrée) et échappées ; une adresse piégée ne peut plus injecter de code dans la signature.
- Yomara Office : une session d'édition cesse immédiatement de fonctionner si le compte est désactivé ou supprimé ; la page de l'éditeur interdit les scripts extérieurs, ne transmet jamais son adresse (jeton) aux sites tiers et ne peut pas être intégrée dans un autre site ; le contenu des fichiers n'est plus interprétable par le navigateur.
- Application web : l'éditeur intégré n'accepte que les messages venant de sa propre page.
- Super admin › Stockage des documents : les adresses internes (métadonnées du serveur, 169.254.x.x, réseau local) sont refusées, sauf le réseau local pour un serveur MinIO ; contrôle refait à chaque requête et sans suivi des redirections.

## 0.99.41 — Word et Excel en ligne, PDF dans l'application, stockage partagé et stockages externes

- Documents : **Yomara Office**. Les fichiers Word (.docx) et Excel (.xlsx, .xls, .ods, .csv) s'ouvrent et se modifient dans l'application, avec une interface moderne en français : barre d'outils complète, mise en page réelle des documents Word, formules, filtres, mise en forme conditionnelle et onglets pour Excel. Enregistrement automatique (3 s après la dernière frappe, Ctrl+S) en nouvelle version. Une seule personne modifie à la fois : les autres voient le fichier en lecture et savent qui le modifie. Bouton « Nouveau » : document Word ou classeur Excel vierge. Les .xls et .ods sont enregistrés en .xlsx. Les présentations PowerPoint se téléchargent pour l'instant.
- PDF : lecture dans l'application partout (documents, factures, bulletins, rapports…), à la place de la fenêtre d'impression. Depuis la visionneuse : modifier / annoter / signer, protéger par mot de passe (AES-256), retirer la protection, autres outils PDF, imprimer, télécharger. Un PDF des Documents modifié devient une nouvelle version.
- Stockage des Documents (plateforme en ligne) : 2 Go partagés par toute la société (réglage ERP_DOCUMENTS_QUOTA_MB), sans limite par personne. Packs dans la boutique : +10 Go 25 000, +20 Go 50 000, +50 Go 125 000, +100 Go 250 000 F CFA / mois (sans essai, jamais inclus d'office dans une formule). Bouton « Espace de stockage » : jauge, qui utilise l'espace, packs.
- Tarifs : palier Standard (SMS, WhatsApp, e-mailing, sites, boutique, boîtes mail, signature, avis Google, réseaux, veille, acquisition, Documents) à 30 000 F CFA / mois, appliqué automatiquement aux modules encore à l'ancien prix.
- Super admin › Stockage des documents : plusieurs stockages (serveur, Cloudflare R2, Backblaze B2, Wasabi, IDrive e2, Amazon S3, Scaleway, OVHcloud, Contabo, MinIO, tout service compatible S3) avec capacité et ordre de priorité ; bascule automatique vers le suivant quand l'un est plein ou ne répond pas ; chaque fichier reste lisible là où il a été déposé ; test de connexion ; clés chiffrées et jamais réaffichées.
## 0.99.40 — tarifs alignés sur la formule gratuite

- Super admin › Modules & tarifs › « Aligner les tarifs » : les modules inclus dans une formule gratuite passent en Gratuit, les modules vendus sans prix reçoivent le prix de leur palier (Essentiel 5 000, Standard 30 000, Premium 25 000 F CFA / mois ; annuel = 10 mois, achat définitif = 24 mois, essai 14 jours) ; les modules Standard (SMS, WhatsApp, e-mailing, site vitrine, boutique, boîte mail…) et Premium sont ramenés au prix de leur palier. Aperçu avant d'appliquer ; les autres modules déjà tarifés ne changent pas.
- Ma configuration : un module de la formule gratuite n'est plus compté comme « sans prix ».
- Réunions : la salle prévient l'application quand on la quitte (applications 1.0.9).

## 0.99.39 — signatures en image, recherche façon Outlook, e-mail de bienvenue, campagnes repensées, contrats de travail dans Mon espace
- Signatures : nouveau mode « Image » (bannière ou carte de visite à la place du texte, largeur réglable de 200 à 800 px, lien au clic facultatif) ; chaque ligne du générateur peut être retirée (×) ; les images insérées en HTML sont à taille réelle (600 px, adaptées à l'écran) au lieu de 160 px.
- Recherche dans la messagerie : dans toutes les boîtes par défaut (ou seulement le dossier ouvert), « Meilleurs résultats » puis « Tous les résultats », dossier de chaque message (Boîte de réception, Éléments envoyés…), étiquette « Brouillon » et mots recherchés surlignés.
- E-mail de bienvenue : logo Yomara et toutes les applications (Microsoft Store, Mac App Store, Google Play, App Store, plus les fichiers à télécharger quand ils sont sur le serveur).
- Juridique › Contrat de travail : le juriste choisit le salarié ; dès que le contrat passe « À signer » ou « En vigueur », le salarié est prévenu et le retrouve dans Mon espace › Mes contrats de travail (texte, impression / PDF, contrat signé ; les brouillons et les remarques internes restent privés).
- Réunions : fin de l'écran qui devenait noir toutes les une à deux secondes (les vidéos étaient décrochées et replacées à chaque mise à jour de la présence ; elles restent désormais en place).
- Lien d'une réunion ouvert sur un téléphone ou un ordinateur : la page propose l'application Yomara avec le bon store selon l'appareil (Google Play, App Store, Microsoft Store, Mac App Store), ou de continuer dans le navigateur. Le bouton « Ouvrir dans l'application » s'activera avec les applications 1.0.9 (réglage ERP_APP_DEEPLINKS).
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
