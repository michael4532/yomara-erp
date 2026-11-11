import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/local_db.dart';
import '../core/prefs_sync.dart';
import 'theme.dart';
import '../i18n/i18n.dart';

/// Espaces de travail : les modules sont rangés par métier, dans l'ordre où on s'en sert.
class NavGroup {
  const NavGroup(this.key, this.label, this.icon, this.ids, {this.hint});

  final String key;
  final String label;
  final IconData icon;
  final List<String> ids;
  final String? hint;
}

final navGroups = <NavGroup>[
  NavGroup('pilotage', tr('Pilotage'), Icons.insights_outlined, ['dashboard', 'pilotage', 'rapports', 'tableaux_excel', 'ia', 'agences', 'approbations'], hint: tr('tableau de bord, prévisions, rapports, Excel, assistant')),
  NavGroup('ventes', tr('Ventes & clients'), Icons.receipt_long_outlined,
      ['ventes', 'contacts', 'crm', 'commercial', 'relances', 'pay_links', 'abonnements', 'fne', 'fidelite', 'rdv'], hint: tr('devis, factures, clients, recouvrement')),
  NavGroup('caisse', tr('Caisse & restaurant'), Icons.point_of_sale, ['pos', 'restaurant', 'kitchen'], hint: tr('ventes au comptoir, tables, cuisine')),
  NavGroup('achats', tr('Achats & stocks'), Icons.inventory_2_outlined,
      ['achats', 'purchase_requests', 'appro', 'portail_fournisseurs', 'products', 'tarifs', 'stock', 'lots', 'labels'], hint: tr('fournisseurs, articles, inventaire')),
  NavGroup('operations', tr('Opérations'), Icons.local_shipping_outlined,
      ['logistique', 'flotte', 'materiel', 'maintenance', 'qualite', 'dev', 'production', 'interventions', 'helpdesk', 'projets', 'prestations', 'planning', 'feuilles_temps'], hint: tr('livraisons, parc auto, développement, fabrication, projets, SAV')),
  NavGroup('finance', tr('Finance'), Icons.account_balance_outlined,
      ['tresorerie', 'compta', 'analytique', 'immobilisations', 'devises', 'notes_frais', 'fonds'], hint: tr('banque, comptabilité, budgets')),
  NavGroup('rh', tr('Ressources humaines'), Icons.badge_outlined, ['rh', 'mon_equipe', 'talents', 'paie', 'pointage', 'kiosk', 'recrutement', 'mon_espace'], hint: tr('salariés, paie, présence')),
  NavGroup('digital', tr('Marketing & communication'), Icons.campaign_outlined, ['contenus', 'acquisition', 'veille', 'social', 'liens', 'marketing', 'questionnaires', 'avis_google', 'sites', 'ecommerce', 'woocommerce', 'shopify'], hint: tr('stratégie, calendrier éditorial, veille concurrentielle, campagnes, avis Google, site, boutique en ligne')),
  NavGroup('juridique', tr('Juridique'), Icons.gavel_outlined, ['juridique'], hint: tr('contrats, contentieux, échéances')),
  NavGroup('metiers', tr('Métiers'), Icons.domain_outlined, ['immobilier', 'btp', 'location', 'marches_publics', 'evenements', 'billetterie', 'hebergement'], hint: tr('immobilier, BTP, location, appels d\'offres, événements')),
  NavGroup('collab', tr('Collaboration'), Icons.groups_2_outlined, ['activite', 'mailbox', 'whatsapp', 'agenda', 'visio', 'tickets', 'ged', 'signature', 'pdf'], hint: tr('e-mails, WhatsApp, agenda, réunions vidéo, documents, signatures, outils PDF')),
  NavGroup('admin', tr('Administration'), Icons.settings_outlined, ['team', 'settings', 'payments', 'backups', 'integrations', 'store', 'referral', 'licenses', 'license', 'import_export', 'migration', 'champs'], hint: tr('équipe et accès, paramètres, modules, imports, migration')),
  NavGroup('superadmin', tr('Plateforme'), Icons.admin_panel_settings_outlined,
      ['admin_console', 'admin_setup', 'admin_site', 'admin_livechat', 'admin_comms', 'admin_promo', 'admin_licenses', 'admin_support', 'admin', 'admin_online', 'admin_companies', 'admin_plans', 'admin_modules', 'admin_storage', 'admin_store', 'admin_updates', 'admin_env', 'admin_backups', 'admin_sites', 'admin_events']),
];

/// Mots-clés de recherche (synonymes) par écran pour la palette de commandes.
const navKeywords = <String, String>{
  'dashboard': 'accueil indicateurs',
  'activite': 'rapport activite hebdomadaire semaine compte rendu bilan superieur manager envoyer pdf word',
  'dev': 'developpement developpeur code bug backlog sprint kanban scrum agile version release git pull request recette deploiement',
  'commercial': 'force de vente commerciaux objectifs tournee terrain visite compte rendu classement prospection quota',
  'liens': 'liens courts raccourcir lien bitly smartlink onelink qr code qrcode wifi carte de visite statistiques clics masquer',
  'pdf': 'pdf fusionner decouper filigrane mot de passe chiffrer proteger signature numeroter pivoter acrobat images en pdf',
  'social': 'reseaux sociaux hootsuite planifier publier facebook instagram threads linkedin x twitter tiktok pinterest youtube google business telegram mastodon bluesky sensya hashtags calendrier publication',
  'acquisition': 'publicite pub ads facebook ads meta ads google ads tiktok ads linkedin prospects lead formulaire page de capture landing roi retour sur investissement cout par prospect pixel conversions search console campagne depense',
  'veille': 'veille concurrentielle concurrents concurrence benchmark comparatif positionnement prix releve publicite ads meta google tiktok linkedin pixel surveillance site seo mentions presse revue de presse swot fiche de combat battle card',
  'contenus': 'calendrier editorial community management reseaux sociaux publication facebook instagram tiktok linkedin strategie communication veille concurrents concurrence presse communique',
  'admin_env': 'configuration serveur env smtp e-mail adresse publique app url cle api ia openai anthropic zego agora gocardless microsoft google dropbox fuseau horaire debogage',
  'payments': 'moyens de paiement encaissement paiement en ligne cinetpay wave orange money mtn momo paydunya fedapay paystack flutterwave stripe paypal lien marchand virement',
  'pilotage': 'pilotage prevision previsions sig soldes intermediaires gestion marge ebe resultat seuil rentabilite point mort simulateur objectif atterrissage rentabilite client article',
  'qualite': 'qualite controle non conformite nc reclamation plainte action corrective preventive capa audit hygiene haccp iso',
  'questionnaires': 'questionnaire sondage enquete satisfaction nps avis formulaire evaluation qr code vote',
  'maintenance': 'maintenance gmao panne machine equipement entretien preventif curatif ordre de travail ot technicien vidange revision compteur heures arret reparation',
  'location': 'location louer loueur materiel engin groupe electrogene sono chapiteau chaise bache reservation caution retour sortie disponibilite',
  'marches_publics': 'appel d offres ao dao soumission soumissionner marche public tender caution mainlevee decompte situation retenue de garantie avance demarrage reception provisoire definitive attestation regularite fiscale cnps',
  'juridique': 'juriste contrats contrat registre bail nda confidentialite contentieux litige proces audience avocat huissier tribunal echeance preavis modele',
  'talents': 'talents entretien annuel evaluation periode essai objectifs competences formation plan de formation fdfp integration onboarding arrivee depart offboarding',
  'mon_equipe': 'mon equipe collaborateurs stagiaires chef de departement responsable service presence absences conges demandes travaux',
  'materiel': 'materiel sortie retour camera appareil photo ordinateur outillage outil pret emprunt intervention bon de sortie signature inventaire',
  'flotte': 'parc automobile vehicule voiture sortie carburant essence gasoil chauffeur kilometrage assurance visite technique vidange entretien',
  'ventes': 'factures devis commandes clients avoirs proforma',
  'contacts': 'clients fournisseurs tiers carnet',
  'achats': 'factures fournisseurs bons de commande depenses',
  'pos': 'caisse ticket comptoir',
  'compta': 'comptabilite grand livre bilan journal ecritures',
  'tresorerie': 'banque caisse mobile money rapprochement',
  'stock': 'inventaire entrepot depot mouvements',
  'products': 'articles catalogue prix',
  'rh': 'salaries employes conges personnel',
  'paie': 'salaires bulletins cnps',
  'pointage': 'presence badge heures',
  'relances': 'impayes recouvrement',
  'rapports': 'statistiques analyses tableaux',
  'admin_updates': 'mise a jour diffuser version deploiement publier',
  'integrations': 'integration api webhook zapier make n8n cle token google sheets power bi excel flux donnees connecteur automatisation',
  'migration': 'migrer reprise donnees dolibarr odoo erpnext sage quickbooks zoho xero ebp ancien logiciel',
  'mailbox': 'mail email e-mail courriel outlook office 365 microsoft gmail google imap pop3 smtp boite reception message signature envoyer repondre',
  'backups': 'sauvegarde backup restauration restaurer google drive dropbox archive chiffrement securite donnees',
  'admin_backups': 'sauvegarde backup restauration google drive dropbox plateforme',
  'pay_links': 'lien de paiement payer en ligne mobile money wave orange money cinetpay acompte qr code encaisser don cotisation',
  'shopify': 'shopify boutique en ligne commande produit stock prix synchronisation connecteur webhook sku variante',
  'woocommerce': 'woocommerce wordpress woo boutique site commande en ligne produit stock prix synchronisation connecteur webhook sku',
  'avis_google': 'avis google note etoile review fiche etablissement google business maps reponse e-reputation reputation qr code demande avis publication',
  'whatsapp': 'whatsapp wa discussion chat conversation message client numero business meta ultramsg green api modele reponse rapide',
  'visio': 'reunion visioconference video zoom teams meet webinaire appel conference transcription enregistrement compte rendu',
  'tableaux_excel': 'excel xlsx classeur tableur tableau croise dynamique graphiques export',
  'ia': 'intelligence artificielle chatgpt claude question',
  'logistique': 'livraisons livreurs tournees',
  'crm': 'prospects opportunites pipeline',
  'immobilier': 'loyers locataires baux quittances',
  'btp': 'chantiers situations travaux',
  'team': 'equipe employes utilisateurs acces comptes collaborateurs ajouter employe roles droits mot de passe inviter',
  'settings': 'parametres utilisateurs roles societe',
  'store': 'modules abonnements mon abonnement formule offre code promo renouveler acheter packs',
  'licenses': 'installations serveur auto-hebergement installe telecharger',
  'license': 'compte yomara relier modules installation acheter',
  'referral': 'parrainage inviter partager gagner credit reduction filleul',
  'admin_site': 'site vitrine visites trafic audience prospects demandes inscriptions conversion',
  'admin_licenses': 'licences installations serveurs clients appareils',
  'admin_livechat': 'live chat discussion visiteurs site messages direct',
  'admin_promo': 'marketing promotion promo code reduction remise coupon offre soldes',
  'admin_setup': 'configuration reglages parametrage checklist installation demarrage paiement smtp email cron',
  'admin_comms': 'marketing campagne email e-mail notification annonce relance newsletter clients promotion',
};

NavGroup groupOf(String id) => navGroups.firstWhere((g) => g.ids.contains(id), orElse: () => navGroups[10]);

/// Module (clé serveur) → écran du menu.
String navIdForModule(String module) => switch (module) {
      'core' => 'contacts',
      'rendez_vous' => 'rdv',
      'documents' => 'ged',
      'portail' => 'ventes',
      'etiquettes' => 'labels',
      _ => module,
    };

/// Préférences d'interface de l'utilisateur (favoris, groupes ouverts, récents, tableau de bord).
class UiPrefs {
  static List<String> list(String key) {
    if (!LocalDb.isOpen) return [];
    final v = LocalDb.state().get('ui_$key');
    if (v is String) return List<String>.from(jsonDecode(v) as List);
    return [];
  }

  static Future<void> save(String key, List<String> v) async {
    if (LocalDb.isOpen) await LocalDb.state().put('ui_$key', jsonEncode(v));
    if (synced.contains(key)) PrefsSync.queue(key, v);
  }

  /// Gardées sur le serveur (le reste, comme les écrans récents, reste propre à l'appareil)
  static const synced = {'nav_favorites', 'nav_bottom', 'nav_open', 'nav_rail', 'dash_pinned', 'sidebar_dark', 'mb_theme', 'rp_board'};

  static bool flag(String key, {bool fallback = false}) => LocalDb.isOpen ? (LocalDb.state().get('ui_$key') as bool? ?? fallback) : fallback;

  static Future<void> setFlag(String key, bool v) async {
    if (LocalDb.isOpen) await LocalDb.state().put('ui_$key', v);
    if (synced.contains(key)) PrefsSync.queue(key, v);
  }
}

/// Accès à la navigation depuis n'importe quel écran (tableau de bord, actions rapides…).
class ShellNav extends InheritedWidget {
  const ShellNav({super.key, required this.go, required this.palette, required this.available, required super.child});

  final void Function(String id) go;
  final VoidCallback palette;
  final Set<String> available;

  static ShellNav? of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<ShellNav>();

  @override
  bool updateShouldNotify(ShellNav old) => old.available.length != available.length;
}

class PaletteEntry {
  const PaletteEntry({required this.id, required this.label, required this.icon, this.subtitle, this.keywords = '', required this.run});

  final String id;
  final String label;
  final IconData icon;
  final String? subtitle;
  final String keywords;
  final VoidCallback run;
}

String _fold(String s) {
  const from = 'àâäáãçéèêëíìîïñóòôöõúùûüÿœ';
  const to = 'aaaaaceeeeiiiinooooouuuuyo';
  final b = StringBuffer();
  for (final ch in s.toLowerCase().split('')) {
    final i = from.indexOf(ch);
    b.write(i >= 0 ? to[i] : ch);
  }
  return b.toString();
}

/// Palette de commandes (Ctrl+K) : aller à n'importe quel module ou lancer une action en tapant quelques lettres.
Future<void> showCommandPalette(BuildContext context, List<PaletteEntry> entries, List<String> recent) => showDialog(
      context: context,
      barrierColor: Colors.black.withValues(alpha: .35),
      builder: (_) => _Palette(entries: entries, recent: recent),
    );

class _Palette extends StatefulWidget {
  const _Palette({required this.entries, required this.recent});

  final List<PaletteEntry> entries;
  final List<String> recent;

  @override
  State<_Palette> createState() => _PaletteState();
}

class _PaletteState extends State<_Palette> {
  String _q = '';
  int _sel = 0;

  List<PaletteEntry> get _rows {
    if (_q.trim().isEmpty) {
      final rec = [for (final id in widget.recent) ...widget.entries.where((e) => e.id == id)];
      final actions = widget.entries.where((e) => e.id.startsWith('action:')).toList();
      return [...rec, ...actions.where((a) => !rec.contains(a))].take(12).toList();
    }
    final words = _fold(_q).split(RegExp(r'\s+')).where((w) => w.isNotEmpty).toList();
    final scored = <(int, PaletteEntry)>[];
    for (final e in widget.entries) {
      final hay = _fold('${e.label} ${e.subtitle ?? ''} ${e.keywords}');
      if (!words.every(hay.contains)) continue;
      final label = _fold(e.label);
      scored.add((label.startsWith(words.first) ? 0 : (label.contains(words.first) ? 1 : 2), e));
    }
    scored.sort((a, b) => a.$1.compareTo(b.$1));
    return scored.map((x) => x.$2).take(14).toList();
  }

  void _run(PaletteEntry e) {
    Navigator.pop(context);
    e.run();
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    final hint = Theme.of(context).hintColor;
    if (_sel >= rows.length) _sel = rows.isEmpty ? 0 : rows.length - 1;
    return Align(
      alignment: const Alignment(0, -.55),
      child: Material(
        color: Theme.of(context).colorScheme.surface,
        elevation: 18,
        borderRadius: BorderRadius.circular(18),
        clipBehavior: Clip.antiAlias,
        child: SizedBox(
          width: 620,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Focus(
              onKeyEvent: (_, ev) {
                if (ev is! KeyDownEvent && ev is! KeyRepeatEvent) return KeyEventResult.ignored;
                if (ev.logicalKey == LogicalKeyboardKey.arrowDown) {
                  setState(() => _sel = (_sel + 1).clamp(0, rows.isEmpty ? 0 : rows.length - 1));
                  return KeyEventResult.handled;
                }
                if (ev.logicalKey == LogicalKeyboardKey.arrowUp) {
                  setState(() => _sel = (_sel - 1).clamp(0, rows.isEmpty ? 0 : rows.length - 1));
                  return KeyEventResult.handled;
                }
                return KeyEventResult.ignored;
              },
              child: TextField(
                autofocus: true,
                onChanged: (v) => setState(() => (_q = v, _sel = 0)),
                onSubmitted: (_) => rows.isEmpty ? null : _run(rows[_sel]),
                style: const TextStyle(fontSize: 17),
                decoration: InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: tr('Aller à un module ou lancer une action…'),
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  filled: false,
                  contentPadding: EdgeInsets.symmetric(vertical: 18),
                ),
              ),
            ),
            const Divider(height: 1),
            ConstrainedBox(
              constraints: const BoxConstraints(maxHeight: 440),
              child: rows.isEmpty
                  ? Padding(padding: const EdgeInsets.all(24), child: Text(tr('Aucun résultat pour « $_q ».'), style: TextStyle(color: hint)))
                  : ListView(shrinkWrap: true, padding: const EdgeInsets.symmetric(vertical: 6), children: [
                      if (_q.trim().isEmpty)
                        Padding(padding: const EdgeInsets.fromLTRB(18, 6, 18, 4), child: Text(tr('RÉCENTS ET ACTIONS RAPIDES'), style: TextStyle(color: hint, fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: .6))),
                      for (final (i, e) in rows.indexed)
                        Container(
                          margin: const EdgeInsets.symmetric(horizontal: 8),
                          decoration: BoxDecoration(color: i == _sel ? YColors.blue.withValues(alpha: .09) : null, borderRadius: BorderRadius.circular(10)),
                          child: ListTile(
                            dense: true,
                            leading: Icon(e.icon, color: e.id.startsWith('action:') ? YColors.success : YColors.blue),
                            title: Text(tr(e.label), style: const TextStyle(fontWeight: FontWeight.w600)),
                            subtitle: e.subtitle == null ? null : Text(tr(e.subtitle!), style: TextStyle(color: hint, fontSize: 12)),
                            trailing: i == _sel ? Icon(Icons.keyboard_return, size: 16, color: hint) : null,
                            onTap: () => _run(e),
                            onFocusChange: (f) => f ? setState(() => _sel = i) : null,
                          ),
                        ),
                    ]),
            ),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              color: Theme.of(context).hintColor.withValues(alpha: .06),
              child: Text(tr('Flèches haut et bas pour choisir · Entrée pour ouvrir · Échap pour fermer'), style: TextStyle(color: hint, fontSize: 11.5)),
            ),
          ]),
        ),
      ),
    );
  }
}
