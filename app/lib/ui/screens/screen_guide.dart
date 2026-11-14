import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../navigation.dart';
import '../theme.dart';

/// Guide de prise en main d'un écran : à quoi il sert, par où commencer, bon à savoir, écrans liés.
class ScreenGuide {
  const ScreenGuide(this.purpose, this.steps, {this.tips = const [], this.related = const []});

  final String purpose;
  final List<String> steps;
  final List<String> tips;
  final List<String> related;
}

const _search = 'La recherche en haut (Ctrl K) ouvre n\'importe quel écran ou fiche en tapant quelques lettres.';

/// Guides écrits à la main pour les écrans les plus utilisés ; les autres reçoivent un guide construit
/// à partir de la description du module.
const screenGuides = <String, ScreenGuide>{
  'dashboard': ScreenGuide(
    'Votre point de départ : les chiffres clés de la société et ce qui demande votre attention aujourd\'hui.',
    ['Regardez les indicateurs en haut : ventes, encaissements, trésorerie, tâches en retard.', 'Touchez un indicateur pour ouvrir l\'écran détaillé.', 'Personnalisez : choisissez les indicateurs à afficher et leur ordre.'],
    tips: [_search, 'Le menu de gauche regroupe les écrans par métier : ouvrez un espace pour voir ce qu\'il contient.'],
    related: ['rapports', 'pilotage'],
  ),
  'ventes': ScreenGuide(
    'Tout le cycle de vente : devis, commandes, factures, avoirs et encaissements.',
    ['Touchez « Nouveau » et choisissez devis ou facture.', 'Choisissez le client, ajoutez les articles : les totaux et taxes se calculent seuls.', 'Validez, puis envoyez par e-mail ou WhatsApp en un clic : le client reçoit un PDF à votre logo.', 'Enregistrez le paiement quand il arrive : la facture passe en « Payée ».'],
    tips: ['Un devis accepté se transforme en commande ou en facture sans tout ressaisir.', 'Le texte de l\'e-mail envoyé se règle dans Paramètres › Modèles d\'e-mails.'],
    related: ['contacts', 'products', 'relances', 'pay_links'],
  ),
  'contacts': ScreenGuide(
    'Le carnet de vos clients et fournisseurs, avec tout leur historique au même endroit.',
    ['Ajoutez un contact avec « Nouveau » ou importez votre liste depuis Excel.', 'Ouvrez une fiche : devis, factures, paiements, échanges et documents du contact.', 'Depuis la fiche, créez directement un devis, une facture ou une relance.'],
    tips: ['Le solde dû de chaque client est visible sur sa fiche.'],
    related: ['ventes', 'crm', 'import_export'],
  ),
  'crm': ScreenGuide(
    'Le suivi des prospects : chaque affaire avance d\'étape en étape jusqu\'à la signature.',
    ['Créez une opportunité : client, montant espéré, étape.', 'Faites glisser la carte d\'une étape à l\'autre dans le pipeline.', 'Planifiez les appels et rendez-vous dans « Activités » : vous êtes prévenu à l\'heure.', 'Gagnée ? Créez le devis directement depuis l\'opportunité.'],
    tips: ['L\'onglet « Relances » liste les prospects à rappeler aujourd\'hui.', 'L\'onglet « Statistiques » montre le taux de transformation et les prévisions.'],
    related: ['contacts', 'ventes', 'agenda'],
  ),
  'pos': ScreenGuide(
    'La caisse tactile pour vendre au comptoir, même sans internet.',
    ['Ouvrez la session de caisse avec le fond de caisse.', 'Touchez les articles (ou scannez le code-barres), puis « Encaisser ».', 'Choisissez espèces, carte ou Mobile Money : le ticket s\'imprime ou part par WhatsApp.', 'En fin de journée, clôturez la session : l\'écart de caisse est calculé.'],
    tips: ['Hors ligne, les ventes sont gardées sur l\'appareil puis envoyées dès le retour du réseau.'],
    related: ['products', 'stock', 'tresorerie'],
  ),
  'achats': ScreenGuide(
    'Vos achats fournisseurs, de la demande interne au paiement.',
    ['Créez une demande d\'achat ou directement un bon de commande.', 'Envoyez le bon au fournisseur ; à la livraison, enregistrez la réception (même partielle).', 'Saisissez la facture fournisseur et son paiement.'],
    tips: ['Au-delà d\'un montant, le bon de commande demande une approbation.', 'La réception met le stock à jour toute seule.'],
    related: ['contacts', 'stock', 'approbations'],
  ),
  'products': ScreenGuide(
    'Votre catalogue : articles vendus ou achetés, et services.',
    ['Ajoutez un produit : nom, prix de vente, prix d\'achat, taxe.', 'Ajoutez une photo et un code-barres pour la caisse.', 'Importez un catalogue entier depuis Excel si vous en avez déjà un.'],
    related: ['stock', 'tarifs', 'labels'],
  ),
  'stock': ScreenGuide(
    'Les quantités en stock, entrepôt par entrepôt, et tous les mouvements.',
    ['Consultez les quantités et les articles en alerte de rupture.', 'Faites un transfert entre entrepôts ou un ajustement.', 'Lancez un inventaire : comptez, validez, les écarts sont corrigés.'],
    tips: ['Ventes, achats et caisse mettent le stock à jour automatiquement : pas de double saisie.'],
    related: ['products', 'achats', 'lots'],
  ),
  'tresorerie': ScreenGuide(
    'Vos comptes bancaires, caisses et Mobile Money, avec les soldes du jour.',
    ['Créez vos comptes (banque, caisse, Mobile Money).', 'Enregistrez les dépenses et recettes diverses ou importez le relevé bancaire.', 'Rapprochez le relevé : les paiements correspondants sont proposés automatiquement.'],
    tips: ['La prévision montre le solde attendu selon les factures à encaisser et à payer.'],
    related: ['ventes', 'achats', 'compta'],
  ),
  'compta': ScreenGuide(
    'La comptabilité SYSCOHADA tenue toute seule à partir de vos ventes, achats, paie et trésorerie.',
    ['Vérifiez les écritures générées automatiquement.', 'Saisissez les écritures manuelles si besoin (OD).', 'Éditez le grand livre, la balance, le bilan et la déclaration de TVA.'],
    tips: ['Votre comptable peut recevoir un accès limité à cet écran.'],
    related: ['tresorerie', 'immobilisations', 'analytique'],
  ),
  'rh': ScreenGuide(
    'Les fiches de vos salariés, leurs contrats, congés et documents RH.',
    ['Ajoutez un salarié : identité, poste, contrat, salaire.', 'Validez les demandes de congés envoyées depuis « Mon espace ».', 'Générez contrats, attestations et certificats en un clic.'],
    related: ['paie', 'pointage', 'team'],
  ),
  'paie': ScreenGuide(
    'Les bulletins de paie et les déclarations sociales, calculés pour vous.',
    ['Vérifiez le profil de paie de la société (pays, cotisations).', 'Lancez la paie du mois : tous les bulletins sont calculés.', 'Contrôlez, validez, puis envoyez les bulletins aux salariés.'],
    tips: ['Les heures supplémentaires du pointage et les avances arrivent directement dans la paie.'],
    related: ['rh', 'pointage', 'compta'],
  ),
  'projets': ScreenGuide(
    'Vos projets, découpés en tâches suivies sur un tableau Kanban.',
    ['Créez un projet et ajoutez les membres de l\'équipe.', 'Ajoutez des tâches, assignez-les et fixez une échéance : la personne est prévenue.', 'Faites glisser les tâches de « À faire » à « Terminé ».'],
    tips: ['Le temps passé saisi sur les tâches peut être facturé au client.'],
    related: ['planning', 'feuilles_temps', 'tickets'],
  ),
  'tickets': ScreenGuide(
    'Les demandes entre services : confier une tâche à quelqu\'un sans se déplacer ni l\'appeler.',
    ['Touchez « Confier une tâche », décrivez le besoin et choisissez le service ou la personne.', 'Indiquez l\'urgence : un délai de réponse s\'applique.', 'La personne reçoit une notification, répond et fait avancer le ticket ; vous suivez tout en temps réel.', 'Une fois traité, le ticket est clos : l\'historique reste consultable.'],
    tips: ['Plus besoin de passer au bureau de quelqu\'un : le ticket garde la trace de qui fait quoi et depuis quand.', 'Les retards remontent automatiquement au responsable.'],
    related: ['activite', 'projets', 'approbations'],
  ),
  'activite': ScreenGuide(
    'Le rapport d\'activité de chaque collaborateur, rédigé automatiquement à partir de ce qu\'il a fait dans le logiciel.',
    ['Choisissez la personne et la période (jour, semaine, mois).', 'Lisez le résumé : ventes, visites, tâches, tickets, réunions… sans que personne n\'ait eu à l\'écrire.', 'Ajoutez un commentaire ou envoyez le rapport par e-mail.'],
    tips: ['Chacun peut compléter son rapport avec ce qui ne passe pas par le logiciel (visites, appels).', 'Plus besoin de réunion pour savoir où en est l\'équipe : tout est là.'],
    related: ['tickets', 'projets', 'mon_equipe'],
  ),
  'agenda': ScreenGuide(
    'L\'agenda partagé de l\'équipe : réunions, rendez-vous, congés et échéances.',
    ['Touchez un créneau pour créer un événement et invitez les participants.', 'Réservez une salle ou un véhicule : les doubles réservations sont impossibles.', 'Passez en vue d\'équipe pour voir les disponibilités de chacun.'],
    related: ['visio', 'rdv', 'planning'],
  ),
  'mailbox': ScreenGuide(
    'YoMail : toutes vos boîtes e-mail (votre hébergeur, Outlook, Gmail) réunies dans le logiciel.',
    ['Ajoutez votre compte e-mail dans les réglages de YoMail.', 'Lisez, répondez et transférez avec la barre d\'actions.', 'Classez dans des dossiers ou partagez une boîte avec l\'équipe.'],
    tips: ['Une signature à votre logo s\'ajoute à chaque envoi.', 'Les pièces jointes images et PDF s\'ouvrent directement dans l\'application.'],
    related: ['contacts', 'agenda'],
  ),
  'whatsapp_web': ScreenGuide(
    'WhatsApp Web ouvert dans le logiciel, sans onglet en plus.',
    ['Scannez le QR code avec WhatsApp sur votre téléphone (Appareils connectés).', 'Discutez avec vos clients comme d\'habitude.', 'Envoyez factures et devis depuis les écrans de vente : ils arrivent ici.'],
    related: ['whatsapp', 'contacts'],
  ),
  'visio': ScreenGuide(
    'Yomeet : réunions et webinaires vidéo intégrés, avec un lien à partager.',
    ['Créez une réunion instantanée ou planifiée.', 'Partagez le lien ou le code : les invités externes n\'ont rien à installer.', 'Pendant la réunion : partage d\'écran, salle d\'attente, enregistrement.'],
    related: ['agenda'],
  ),
  'ged': ScreenGuide(
    'Les documents de l\'entreprise rangés dans des dossiers, avec droits d\'accès et versions.',
    ['Créez vos dossiers et déposez vos fichiers (PDF, Word, Excel, photos).', 'Ouvrez un Word ou un Excel pour le modifier en ligne : il s\'enregistre tout seul.', 'Partagez un fichier par lien ou réglez qui peut le voir.'],
    tips: ['Si l\'application se ferme pendant une modification, vos changements sont proposés à la réouverture.', 'La recherche trouve aussi le texte à l\'intérieur des PDF et des Word.'],
    related: ['pdf', 'signature', 'archives'],
  ),
  'pdf': ScreenGuide(
    'Tous les outils PDF du quotidien : fusionner, découper, signer, protéger, modifier.',
    ['Choisissez un outil et ajoutez votre fichier.', 'Réglez les options, puis lancez : le résultat se télécharge.', 'Retrouvez vos fichiers produits dans « Mes fichiers récents », en bas de l\'écran.'],
    tips: ['L\'historique est personnel : vous seul voyez vos fichiers, gardés 30 jours.'],
    related: ['ged', 'signature'],
  ),
  'relances': ScreenGuide(
    'Les relances automatiques des factures impayées, pour être payé sans y penser.',
    ['Réglez les paliers : rappel, relance, dernier avis, et les délais.', 'Choisissez le canal : e-mail, WhatsApp ou SMS, avec lien de paiement.', 'Suivez la balance âgée et les promesses de paiement.'],
    related: ['ventes', 'pay_links', 'contacts'],
  ),
  'helpdesk': ScreenGuide(
    'Le service client : chaque demande de client devient un ticket suivi jusqu\'à sa résolution.',
    ['Les demandes arrivent par e-mail, WhatsApp, formulaire ou espace client.', 'Attribuez le ticket, répondez avec une réponse type, ajoutez des notes internes.', 'Surveillez les délais garantis : les retards sont signalés.'],
    related: ['contacts', 'interventions'],
  ),
  'notes_frais': ScreenGuide(
    'Les dépenses avancées par les salariés, avec photo du justificatif.',
    ['Créez une note : montant, catégorie, photo du reçu.', 'Envoyez-la : votre responsable la valide.', 'Le remboursement passe par la trésorerie ou sur la paie.'],
    related: ['tresorerie', 'approbations'],
  ),
  'approbations': ScreenGuide(
    'Les validations en attente : devis, commandes, factures, notes de frais.',
    ['Ouvrez une demande et vérifiez le détail.', 'Approuvez ou refusez avec un commentaire : le demandeur est prévenu.', 'Réglez les circuits (montants, approbateurs) dans les règles.'],
    related: ['achats', 'notes_frais'],
  ),
  'rapports': ScreenGuide(
    'Les rapports et analyses sur toutes vos données.',
    ['Choisissez un rapport prêt à l\'emploi ou créez le vôtre.', 'Réglez la période, les filtres et les regroupements.', 'Exportez en Excel ou PDF, ou programmez un envoi automatique.'],
    related: ['dashboard', 'tableaux_excel'],
  ),
  'signature': ScreenGuide(
    'Faire signer un document en ligne, sans imprimer ni scanner.',
    ['Déposez le PDF et placez les zones de signature.', 'Ajoutez les signataires : ils reçoivent un lien par e-mail, WhatsApp ou SMS.', 'Suivez l\'avancement : le document signé est scellé et classé.'],
    related: ['ged', 'juridique'],
  ),
  'team': ScreenGuide(
    'Les comptes de votre équipe et ce que chacun peut voir ou faire.',
    ['Ajoutez un employé : il reçoit un e-mail de bienvenue pour choisir son mot de passe.', 'Donnez-lui un rôle (vendeur, comptable…) qui règle ses accès.', 'Désactivez un compte quand quelqu\'un quitte la société.'],
    related: ['rh', 'settings'],
  ),
  'settings': ScreenGuide(
    'Les réglages de la société : identité, logo, documents, taxes, e-mails.',
    ['Complétez l\'identité de la société et ajoutez votre logo.', 'Réglez la numérotation et l\'apparence des documents.', 'Personnalisez les e-mails envoyés aux clients dans « Modèles d\'e-mails ».'],
    related: ['team', 'payments', 'backups'],
  ),
  'mon_espace': ScreenGuide(
    'Votre espace personnel : congés, bulletins, notes de frais, demandes et tâches.',
    ['Demandez un congé : votre responsable est prévenu.', 'Retrouvez vos bulletins de paie et documents RH.', 'Consultez vos tâches et demandes en cours.'],
    related: ['tickets', 'notes_frais', 'agenda'],
  ),
  'store': ScreenGuide(
    'La boutique de modules : ajoutez des fonctions quand vous en avez besoin.',
    ['Parcourez les modules par catégorie.', 'Essayez un module gratuitement quand l\'essai est proposé.', 'Activez-le : il apparaît aussitôt dans le menu.'],
  ),
  'fne': ScreenGuide(
    'La certification de vos factures auprès de la DGI (facture normalisée).',
    ['Renseignez vos identifiants FNE dans les réglages.', 'Validez une facture : elle est certifiée automatiquement.', 'Le QR code de vérification s\'imprime sur la facture.'],
    tips: ['Hors ligne, les factures attendent dans une file et partent au retour du réseau.'],
    related: ['ventes', 'pos'],
  ),
  'pointage': ScreenGuide(
    'La présence de l\'équipe : arrivées, départs, retards et absences.',
    ['Les salariés pointent sur la borne (code PIN) ou depuis leur téléphone.', 'Consultez les présents, retards et absents du jour.', 'Les heures supplémentaires partent dans la paie.'],
    related: ['kiosk', 'rh', 'paie'],
  ),
  'planning': ScreenGuide(
    'Qui travaille sur quoi, semaine par semaine.',
    ['Affectez les membres de l\'équipe aux projets ou clients.', 'Repérez les surcharges et les disponibilités.', 'Comparez le prévu au temps réellement passé.'],
    related: ['projets', 'feuilles_temps'],
  ),
  'interventions': ScreenGuide(
    'Les interventions des techniciens chez vos clients.',
    ['Créez une demande d\'intervention et planifiez un technicien.', 'Le technicien remplit sa fiche sur le téléphone, même hors ligne.', 'Le client signe : le rapport et la facture suivent.'],
    related: ['helpdesk', 'maintenance'],
  ),
  'logistique': ScreenGuide(
    'Les livraisons : bons, tournées, livreurs et preuve de livraison.',
    ['Créez un bon de livraison depuis une commande.', 'Regroupez les livraisons en tournée et assignez un livreur.', 'Le livreur fait signer ou photographie la remise.'],
    related: ['ventes', 'flotte'],
  ),
  'ecommerce': ScreenGuide(
    'Les commandes en ligne (site, WhatsApp, Facebook) reliées au stock et à la facturation.',
    ['Recevez les commandes dans le tableau.', 'Préparez, puis expédiez : la facture et la sortie de stock se font seules.', 'Suivez le paiement et la livraison.'],
    related: ['stock', 'logistique'],
  ),
  'fidelite': ScreenGuide(
    'Le programme de fidélité : points, récompenses et cartes cadeaux.',
    ['Réglez combien de points rapporte chaque achat.', 'Créez des récompenses et des niveaux.', 'À la caisse, le client paie avec ses points.'],
    related: ['pos', 'contacts'],
  ),
  'rdv': ScreenGuide(
    'Les rendez-vous pris par vos clients en ligne.',
    ['Réglez vos services, durées et horaires.', 'Partagez le lien de réservation.', 'Les rendez-vous arrivent dans l\'agenda, avec rappels automatiques.'],
    related: ['agenda', 'contacts'],
  ),
};

ScreenGuide guideFor(BuildContext context, String id, String label) {
  final g = screenGuides[id];
  if (g != null) return g;
  // Guide construit : description du module (même clé que l'écran) et espace de travail
  final session = context.read<Session>();
  final m = session.modules.where((m) => m['key'] == id).firstOrNull;
  final desc = '${m?['description'] ?? ''}'.trim();
  final group = groupOf(id);
  return ScreenGuide(
    desc.isNotEmpty ? desc : 'L\'écran « $label » de l\'espace ${group.label}.',
    [
      'Le bouton « Nouveau » (ou +) crée une fiche.',
      'Touchez une ligne pour l\'ouvrir, la modifier ou voir son historique.',
      'Utilisez la recherche et les filtres en haut de la liste pour retrouver une fiche.',
    ],
    tips: const [_search],
    related: [for (final r in group.ids) if (r != id) r].take(3).toList(),
  );
}

/// Ouvre le guide de l'écran [id].
Future<void> showScreenGuide(BuildContext context, {required String id, required String label, required IconData icon}) async {
  final g = guideFor(context, id, label);
  final nav = ShellNav.of(context);
  final labels = {for (final i in nav?.available ?? <String>{}) i: i};
  await showDialog(
    context: context,
    builder: (ctx) => _GuideDialog(guide: g, label: label, icon: icon, labels: labels, go: nav?.go),
  );
  await markGuideSeen(id);
}

Future<void> markGuideSeen(String id) async {
  final seen = UiPrefs.list('guides_seen');
  if (!seen.contains(id)) await UiPrefs.save('guides_seen', [...seen, id]);
}

bool guideSeen(String id) => UiPrefs.list('guides_seen').contains(id);

class _GuideDialog extends StatelessWidget {
  const _GuideDialog({required this.guide, required this.label, required this.icon, required this.labels, this.go});

  final ScreenGuide guide;
  final String label;
  final IconData icon;
  final Map<String, String> labels;
  final void Function(String id)? go;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final related = [for (final r in guide.related) if (labels.containsKey(r)) r];
    final names = navLabels(context);
    return Dialog(
      insetPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 560, maxHeight: 680),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(22, 20, 12, 18),
            decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFF0A1633), Color(0xFF1D3E86)], begin: Alignment.topLeft, end: Alignment.bottomRight)),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
                child: Icon(icon, color: const Color(0xFFFFC531)),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('Guide de l\'écran'), style: TextStyle(color: Colors.white.withValues(alpha: .7), fontSize: 12.5)),
                  Text(tr(label), style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
                ]),
              ),
              IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close, color: Colors.white), tooltip: tr('Fermer')),
            ]),
          ),
          Flexible(
            child: ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(22, 18, 22, 8), children: [
              Text(tr(guide.purpose), style: const TextStyle(fontSize: 15, height: 1.45, fontWeight: FontWeight.w500)),
              const SizedBox(height: 18),
              Text(tr('Pour commencer'), style: const TextStyle(fontWeight: FontWeight.w800)),
              const SizedBox(height: 10),
              for (final (i, s) in guide.steps.indexed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Container(
                      width: 24,
                      height: 24,
                      alignment: Alignment.center,
                      decoration: const BoxDecoration(color: YColors.blue, shape: BoxShape.circle),
                      child: Text('${i + 1}', style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w800)),
                    ),
                    const SizedBox(width: 12),
                    Expanded(child: Padding(padding: const EdgeInsets.only(top: 2), child: Text(tr(s), style: const TextStyle(height: 1.4)))),
                  ]),
                ),
              if (guide.tips.isNotEmpty) ...[
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: const Color(0xFFFFC531).withValues(alpha: .14), borderRadius: BorderRadius.circular(12)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      const Icon(Icons.lightbulb_outline, size: 18, color: Color(0xFF8A6100)),
                      const SizedBox(width: 6),
                      Text(tr('Bon à savoir'), style: const TextStyle(fontWeight: FontWeight.w800, color: Color(0xFF8A6100))),
                    ]),
                    const SizedBox(height: 6),
                    for (final t in guide.tips) Padding(padding: const EdgeInsets.only(top: 4), child: Text('• ${tr(t)}', style: const TextStyle(height: 1.4))),
                  ]),
                ),
              ],
              if (related.isNotEmpty && go != null) ...[
                const SizedBox(height: 16),
                Text(tr('Écrans liés'), style: TextStyle(fontWeight: FontWeight.w700, color: hint, fontSize: 13)),
                const SizedBox(height: 8),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final r in related)
                    ActionChip(
                      avatar: const Icon(Icons.arrow_outward, size: 16),
                      label: Text(tr(names[r] ?? r)),
                      onPressed: () {
                        Navigator.pop(context);
                        go!(r);
                      },
                    ),
                ]),
              ],
              const SizedBox(height: 12),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 4, 22, 16),
            child: Row(children: [
              Expanded(child: Text(tr('Revoyez ce guide à tout moment avec le bouton ? en haut.'), style: TextStyle(color: hint, fontSize: 12))),
              FilledButton(onPressed: () => Navigator.pop(context), child: Text(tr('C\'est compris'))),
            ]),
          ),
        ]),
      ),
    );
  }
}

/// Libellés des écrans (fournis par le shell).
final screenLabels = <String, String>{};

Map<String, String> navLabels(BuildContext context) => screenLabels;

/// Invitation discrète à la première visite d'un écran : « Première fois ici ? Voir le guide ».
class ScreenGuideNudge extends StatefulWidget {
  const ScreenGuideNudge({super.key, required this.id, required this.label, required this.icon});

  final String id;
  final String label;
  final IconData icon;

  @override
  State<ScreenGuideNudge> createState() => _ScreenGuideNudgeState();
}

class _ScreenGuideNudgeState extends State<ScreenGuideNudge> {
  bool _show = false;
  Timer? _in, _out;

  @override
  void initState() {
    super.initState();
    _arm();
  }

  @override
  void didUpdateWidget(ScreenGuideNudge old) {
    super.didUpdateWidget(old);
    if (old.id != widget.id) _arm();
  }

  void _arm() {
    _in?.cancel();
    _out?.cancel();
    _show = false;
    if (guideSeen(widget.id) || UiPrefs.flag('guides_off')) return;
    _in = Timer(const Duration(milliseconds: 1400), () {
      if (!mounted) return;
      setState(() => _show = true);
      _out = Timer(const Duration(seconds: 14), () {
        if (mounted) setState(() => _show = false);
      });
    });
  }

  @override
  void dispose() {
    _in?.cancel();
    _out?.cancel();
    super.dispose();
  }

  void _hide({bool seen = true}) {
    setState(() => _show = false);
    if (seen) markGuideSeen(widget.id);
  }

  @override
  Widget build(BuildContext context) {
    final phone = MediaQuery.sizeOf(context).width < 700;
    return AnimatedPositioned(
      duration: const Duration(milliseconds: 320),
      curve: Curves.easeOutCubic,
      right: phone ? 12 : 22,
      left: phone ? 12 : null,
      bottom: _show ? (phone ? 12 : 22) : -160,
      child: IgnorePointer(
        ignoring: !_show,
        child: AnimatedOpacity(
          duration: const Duration(milliseconds: 220),
          opacity: _show ? 1 : 0,
          child: Material(
            elevation: 10,
            shadowColor: Colors.black38,
            borderRadius: BorderRadius.circular(16),
            color: const Color(0xFF0A1633),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 380),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 14, 8, 10),
                child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    const Icon(Icons.explore_outlined, color: Color(0xFFFFC531), size: 20),
                    const SizedBox(width: 8),
                    Expanded(child: Text(tr('Première fois sur « ${widget.label} » ?'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800))),
                    IconButton(visualDensity: VisualDensity.compact, onPressed: _hide, icon: const Icon(Icons.close, size: 18, color: Colors.white70), tooltip: tr('Fermer')),
                  ]),
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: Text(tr('Découvrez en 30 secondes à quoi sert cet écran et par où commencer.'), style: TextStyle(color: Colors.white.withValues(alpha: .8), fontSize: 13)),
                  ),
                  const SizedBox(height: 8),
                  Row(children: [
                    FilledButton(
                      style: FilledButton.styleFrom(backgroundColor: const Color(0xFFFFC531), foregroundColor: const Color(0xFF0A1633)),
                      onPressed: () {
                        _hide();
                        showScreenGuide(context, id: widget.id, label: widget.label, icon: widget.icon);
                      },
                      child: Text(tr('Voir le guide')),
                    ),
                    const SizedBox(width: 6),
                    TextButton(
                      onPressed: () async {
                        _hide();
                        await UiPrefs.setFlag('guides_off', true);
                      },
                      child: Text(tr('Ne plus proposer'), style: const TextStyle(color: Colors.white70)),
                    ),
                  ]),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Bouton « ? » des barres du haut.
class ScreenGuideButton extends StatelessWidget {
  const ScreenGuideButton({super.key, required this.id, required this.label, required this.icon});

  final String id;
  final String label;
  final IconData icon;

  @override
  Widget build(BuildContext context) => IconButton(
        tooltip: tr('Guide de cet écran'),
        onPressed: () => showScreenGuide(context, id: id, label: label, icon: icon),
        icon: const Icon(Icons.help_outline_rounded),
      );
}
