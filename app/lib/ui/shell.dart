import 'dart:async';

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../core/sync_engine.dart';
import '../files/web_link.dart';
import '../state/session.dart';
import 'screens/admin/admin_companies_screen.dart';
import 'screens/event_extras.dart' show AdminEventsScreen;
import 'screens/admin/admin_modules_screen.dart';
import 'screens/admin/admin_online_screen.dart';
import 'screens/admin/admin_overview_screen.dart';
import 'screens/admin/admin_console_screen.dart';
import 'screens/admin/admin_licenses_screen.dart';
import 'screens/admin/admin_site_screen.dart';
import 'screens/admin/admin_livechat_screen.dart';
import 'screens/admin/admin_promo_screen.dart';
import 'screens/admin/admin_comms_screen.dart';
import 'screens/admin/admin_setup_screen.dart';
import 'screens/admin/admin_common.dart' show ServerUpdateBanner;
import 'widgets/about.dart';
import '../core/config.dart';
import '../core/firebase_setup.dart';
import 'widgets/delete_account.dart';
import 'screens/licenses_screen.dart';
import 'screens/license_local_screen.dart';
import 'screens/referral_screen.dart';
import 'screens/support_chat.dart';
import 'screens/onboarding_guide.dart';
import 'screens/admin/admin_plans_screen.dart';
import 'screens/admin/admin_store_screen.dart';
import 'screens/admin/admin_updates_screen.dart';
import 'screens/compta_screen.dart';
import 'screens/fonds_screen.dart';
import 'screens/tresorerie_screen.dart';
import 'screens/tickets_screen.dart';
import 'widgets/notification_bell.dart';
import 'widgets/app_update.dart';
import 'widgets/my_day.dart';
import 'widgets/branch_selector.dart';
import 'screens/agences_screen.dart';
import 'screens/ecommerce_screen.dart';
import 'screens/immobilier_screen.dart';
import 'screens/btp_screen.dart';
import 'screens/assistant_screen.dart';
import 'screens/store_screen.dart';
import 'screens/crm_screen.dart';
import 'screens/marketing_screen.dart';
import 'screens/projets_screen.dart';
import 'screens/logistique_screen.dart';
import 'screens/interventions_screen.dart';
import 'screens/achats_screen.dart';
import 'screens/appro_screen.dart';
import 'screens/rdv_screen.dart';
import 'screens/fidelite_screen.dart';
import 'screens/restaurant_screen.dart';
import 'screens/production_screen.dart';
import 'screens/labels_screen.dart';
import 'screens/import_export_screen.dart';
import 'screens/paie_screen.dart';
import 'screens/rh_screen.dart';
import 'screens/mon_espace_screen.dart';
import 'screens/team_screen.dart';
import 'screens/pointage_screen.dart';
import 'screens/notes_frais_screen.dart';
import 'screens/recrutement_screen.dart';
import 'screens/analytique_screen.dart';
import 'screens/abonnements_screen.dart';
import 'screens/devises_screen.dart';
import 'screens/fne_screen.dart';
import 'screens/approbations_screen.dart';
import 'screens/lots_screen.dart';
import 'screens/evenements_screen.dart';
import 'screens/hebergement_screen.dart';
import 'screens/billetterie_screen.dart';
import 'screens/sites_screen.dart';
import 'screens/admin/admin_sites_screen.dart';
import 'screens/feuilles_screen.dart';
import 'screens/fournisseurs_screen.dart';
import 'screens/helpdesk_screen.dart';
import 'screens/planning_screen.dart';
import 'screens/prestations_screen.dart';
import 'screens/tarifs_screen.dart';
import 'screens/champs_screen.dart';
import 'screens/rapports_screen.dart';
import 'screens/tableaux_excel_screen.dart';
import 'screens/migration_screen.dart';
import 'screens/visio_screen.dart';
import 'screens/integrations_screen.dart';
import 'screens/mailbox/mailbox_screen.dart';
import 'screens/whatsapp_screen.dart';
import 'screens/avis_google_screen.dart';
import 'screens/woocommerce_screen.dart';
import 'screens/payment_links_screen.dart';
import 'screens/backups_screen.dart';
import 'screens/contenus_screen.dart';
import 'screens/social_screen.dart';
import 'screens/liens_screen.dart';
import 'screens/pdf_tools_screen.dart';
import '../i18n/language_picker.dart';
import 'screens/activite_screen.dart';
import 'screens/dev_screen.dart';
import 'screens/commercial_screen.dart';
import 'screens/juridique_screen.dart';
import 'screens/marches_screen.dart';
import 'screens/veille_screen.dart';
import 'screens/acquisition_screen.dart';
import 'screens/company_payments_screen.dart';
import 'screens/location_screen.dart';
import 'screens/maintenance_screen.dart';
import 'screens/pilotage_screen.dart';
import 'screens/admin/admin_env_screen.dart';
import 'screens/qualite_screen.dart';
import 'screens/questionnaires_screen.dart';
import 'screens/flotte_screen.dart';
import 'screens/materiel_screen.dart';
import 'screens/talents_screen.dart';
import 'screens/relances_screen.dart';
import 'screens/immobilisations_screen.dart';
import 'screens/signature_screen.dart';
import 'screens/ged_screen.dart';
import 'screens/agenda_screen.dart';
import 'screens/dashboard_screen.dart';
import 'screens/documents_screen.dart';
import 'screens/partners_screen.dart';
import 'screens/pos_screen.dart';
import 'screens/products_screen.dart';
import 'screens/settings_screen.dart';
import 'screens/stock_screen.dart';
import 'navigation.dart';
import 'screens/document_editor.dart';
import 'theme.dart';
import '../core/local_db.dart';
import 'widgets/common.dart';
import '../i18n/i18n.dart';
import '../core/json.dart';

class NavItem {
  const NavItem(this.id, this.label, this.icon, this.builder, {this.section});

  final String id;
  final String label;
  final IconData icon;
  final WidgetBuilder builder;
  final String? section;
}

class AppShell extends StatefulWidget {
  const AppShell({super.key});

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  // « Plus tard » sur le rappel d'échéance : masqué jusqu'au lendemain
  late bool _renewDismissed = UiPrefs.list('renew_later').contains(DateTime.now().toIso8601String().substring(0, 10));
  bool _subDismissed = false;
  late final Session _session = context.read<Session>();
  late final List<String> _knownModules = List.of(_session.enabledModules);

  @override
  void initState() {
    super.initState();
    _session.modulesChanged.addListener(_onModulesChanged);
    _unlisten = listenScreenLinks((id) {
      if (mounted) _go(id);
    });
    // Téléphone : notification push reçue (appli ouverte) ou touchée (ouvre l'écran concerné)
    FirebaseSetup.listen(
      onForeground: (m) {
        final t = m.notification?.title;
        if (mounted && t != null) toast(context, m.notification?.body != null ? '$t — ${m.notification!.body}' : t);
      },
      onOpen: (data) {
        final id = '${data['module'] ?? ''}';
        if (!mounted || id.isEmpty) return;
        if (_items(_session).any((i) => i.id == id)) _go(id);
      },
    );
  }

  void Function() _unlisten = () {};

  @override
  void dispose() {
    _unlisten();
    _session.modulesChanged.removeListener(_onModulesChanged);
    super.dispose();
  }

  /// Module activé ou retiré côté serveur : le menu se met à jour seul, on prévient l'utilisateur.
  void _onModulesChanged() {
    if (!mounted) return;
    final now = _session.enabledModules;
    final added = now.where((k) => !_knownModules.contains(k)).toList();
    final names = {for (final m in _session.modules) '${m['key']}': '${m['name']}'};
    _knownModules
      ..clear()
      ..addAll(now);
    setState(() {});
    toast(context, added.isEmpty ? tr('Votre espace a été mis à jour.') : tr('Nouveau : ${added.map((k) => names[k] ?? k).join(', ')} ${added.length > 1 ? 'sont activés' : 'est activé'}.'));
  }
  // Un livreur ou un technicien arrive directement sur son travail du jour
  late String _current = () {
    final s = context.read<Session>();
    // Version Web : lien direct vers un écran (…/app/?ecran=maintenance)
    final asked = kIsWeb ? Uri.base.queryParameters['ecran'] : null;
    if (asked != null && RegExp(r'^[a-z_]{2,40}$').hasMatch(asked)) return asked;
    // Éditeur de la plateforme : arrive sur sa console (supervision des clients), pas sur le tableau de bord d'une société
    if (s.isSuperAdmin && s.mode == 'saas') return 'admin_console';
    if (s.can('ventes.view')) return 'dashboard';
    if (s.hasModule('logistique') && s.can('logistique.deliver') && !s.can('logistique.view')) return 'logistique';
    if (s.hasModule('interventions') && s.can('interventions.do') && !s.can('interventions.view')) return 'interventions';
    if (s.hasModule('restaurant') && s.can('restaurant.kitchen') && !s.can('restaurant.serve') && !s.can('ventes.view')) return 'kitchen';
    if (s.hasModule('restaurant') && s.can('restaurant.serve') && !s.can('ventes.view')) return 'restaurant';
    if (s.hasModule('production') && s.can('production.operate') && !s.can('ventes.view')) return 'production';
    if (s.hasModule('rendez_vous') && (s.can('rendez_vous.own') || s.can('rendez_vous.manage')) && !s.can('ventes.view')) return 'rdv';
    if (s.hasModule('pointage') && s.can('pointage.kiosk') && !s.can('pointage.view') && !s.can('pointage.manage')) return 'kiosk';
    // Salarié sans autre rôle : son espace
    if (s.hasModule('rh') && s.can('rh.self') && !s.can('rh.view') && !s.can('pos.use') && !s.can('stock.view')) return 'mon_espace';
    return 'dashboard';
  }();

  List<NavItem> _items(Session s) {
    ptClock = s.companyNow;
    return _navItems(s);
  }

  /// Copie installée chez un client et reliée au système de comptes Yomara (licence gérée par l'éditeur).
  bool _selfHosted(Session s) {
    final st = '${asMap(asMap(s.bootstrap?['app'])['license'])['status'] ?? ''}';
    return s.mode != 'saas' && st.isNotEmpty && st != 'not_required';
  }

  List<NavItem> _navItems(Session s) => _buildNav(s, _selfHosted(s));

  List<NavItem> _buildNav(Session s, bool selfHosted) => [
    NavItem('dashboard', tr('Tableau de bord'), Icons.space_dashboard_outlined, (_) => DashboardScreen(key: ValueKey('dash${s.currentBranchId}'))),
    if (s.hasModule('immobilier') && (s.can('immobilier.view') || s.can('immobilier.manage') || s.can('immobilier.owners')))
      NavItem('immobilier', tr('Gestion locative'), Icons.apartment, (_) => const ImmobilierScreen()),
    if (s.hasModule('btp') && (s.can('btp.view') || s.can('btp.manage') || s.can('btp.log')))
      NavItem('btp', tr('BTP & chantiers'), Icons.construction, (_) => const BtpScreen()),
    if (s.hasModule('location') && (s.can('location.view') || s.can('location.manage')))
      NavItem('location', tr('Location de matériel'), Icons.key_outlined, (_) => const LocationScreen()),
    if (s.hasModule('marches_publics') && (s.can('marches.view') || s.can('marches.manage')))
      NavItem('marches_publics', tr('Appels d\'offres & marchés'), Icons.gavel_outlined, (_) => const MarchesScreen()),
    if (s.hasModule('agences') && (s.can('agences.view') || s.can('agences.manage')))
      NavItem('agences', tr('Agences'), Icons.store_mall_directory_outlined, (_) => const AgencesScreen()),
    if (s.hasModule('ia') && (s.can('ia.use') || s.can('ia.manage')))
      NavItem('ia', tr('Assistant IA'), Icons.auto_awesome_outlined, (_) => const AssistantScreen()),
    if (s.hasModule('rapports') && (s.can('rapports.view') || s.can('rapports.create') || s.can('rapports.manage')))
      NavItem('rapports', tr('Rapports & analyses'), Icons.insights_outlined, (_) => const RapportsScreen()),
    if (s.hasModule('pilotage') && (s.can('pilotage.view') || s.can('pilotage.manage')))
      NavItem('pilotage', tr('Pilotage & prévisions'), Icons.query_stats, (_) => const PilotageScreen()),
    if (s.hasModule('tableaux_excel') && (s.can('tableaux_excel.view') || s.can('tableaux_excel.manage')))
      NavItem('tableaux_excel', tr('Tableaux de bord Excel'), Icons.table_chart_outlined, (_) => const TableauxExcelScreen()),
    if (s.hasModule('pos') && s.can('pos.use')) NavItem('pos', tr('Caisse'), Icons.point_of_sale, (_) => const PosScreen(), section: 'Commerce'),
    if (s.hasModule('restaurant') && (s.can('restaurant.serve') || s.can('restaurant.manage')))
      NavItem('restaurant', tr('Restaurant'), Icons.restaurant_outlined, (_) => const RestaurantScreen(), section: 'Commerce'),
    if (s.hasModule('restaurant') && s.can('restaurant.kitchen') && !s.can('restaurant.serve') && !s.can('restaurant.manage'))
      NavItem('kitchen', tr('Cuisine & bar'), Icons.soup_kitchen_outlined, (_) => const KitchenScreen(), section: 'Commerce'),
    if (s.hasModule('crm') && (s.can('crm.view') || s.can('crm.manage')))
      NavItem('crm', tr('CRM'), Icons.handshake_outlined, (_) => const CrmScreen(), section: 'Commerce'),
    if (s.hasModule('commercial') && ['commercial.field', 'commercial.view', 'commercial.manage'].any(s.can))
      NavItem('commercial', tr('Force de vente'), Icons.storefront_outlined, (_) => const CommercialScreen(), section: 'Commerce'),
    if (s.hasModule('contenus') && ['contenus.view', 'contenus.manage', 'contenus.validate', 'contenus.watch'].any(s.can))
      NavItem('contenus', tr('Stratégie & contenus'), Icons.calendar_month_outlined, (_) => const ContenusScreen(), section: 'Commerce'),
    if (s.hasModule('acquisition') && ['acquisition.view', 'acquisition.manage'].any(s.can))
      NavItem('acquisition', tr('Publicité & acquisition'), Icons.ads_click, (_) => const AcquisitionScreen(), section: 'Commerce'),
    if (s.hasModule('veille') && ['veille.view', 'veille.manage', 'veille.report'].any(s.can))
      NavItem('veille', tr('Veille concurrentielle'), Icons.radar, (_) => const VeilleScreen(), section: 'Commerce'),
    if (s.hasModule('social') && ['social.view', 'social.manage', 'social.publish', 'social.accounts'].any(s.can))
      NavItem('social', tr('Réseaux sociaux'), Icons.share_outlined, (_) => const SocialScreen(), section: 'Commerce'),
    if (s.hasModule('liens') && ['liens.view', 'liens.manage'].any(s.can))
      NavItem('liens', tr('Liens & QR codes'), Icons.link, (_) => const LiensScreen(), section: 'Commerce'),
    if (s.hasModule('pdf') && s.can('pdf.use')) NavItem('pdf', tr('Outils PDF'), Icons.picture_as_pdf_outlined, (_) => const PdfToolsScreen(), section: 'Commerce'),
    if (s.hasModule('marketing') && (s.can('marketing.view') || s.can('marketing.manage')))
      NavItem('marketing', tr('Marketing'), Icons.campaign_outlined, (_) => const MarketingScreen(), section: 'Commerce'),
    if (s.hasModule('sites') && (s.can('sites.view') || s.can('sites.manage')))
      NavItem('sites', tr('Site web'), Icons.language, (_) => const SitesScreen(), section: 'Commerce'),
    if (s.hasModule('avis_google') && (s.can('avis.view') || s.can('avis.reply') || s.can('avis.manage')))
      NavItem('avis_google', tr('Avis Google'), Icons.star_rate_outlined, (_) => const AvisGoogleScreen(), section: 'Commerce'),
    if (s.hasModule('questionnaires') && ['questionnaires.view', 'questionnaires.answer', 'questionnaires.manage'].any(s.can))
      NavItem('questionnaires', tr('Sondages & formulaires'), Icons.quiz_outlined, (_) => const QuestionnairesScreen(), section: 'Commerce'),
    if (s.hasModule('woocommerce') && (s.can('woo.view') || s.can('woo.manage')))
      NavItem('woocommerce', tr('WooCommerce'), Icons.storefront_outlined, (_) => const WoocommerceScreen(key: ValueKey('woocommerce')), section: 'Commerce'),
    if (s.hasModule('shopify') && (s.can('woo.view') || s.can('woo.manage')))
      NavItem(
        'shopify',
        tr('Shopify'),
        Icons.shopping_bag_outlined,
        (_) => const WoocommerceScreen(key: ValueKey('shopify'), platform: 'shopify'),
        section: 'Commerce',
      ),
    if (s.hasModule('ecommerce') && (s.can('ecommerce.view') || s.can('ecommerce.manage') || s.can('ecommerce.settings')))
      NavItem('ecommerce', tr('Boutique en ligne'), Icons.shopping_cart_checkout, (_) => const EcommerceScreen(), section: 'Commerce'),
    if (s.hasModule('fidelite') && (s.can('fidelite.view') || s.can('fidelite.manage')))
      NavItem('fidelite', tr('Fidélité'), Icons.loyalty_outlined, (_) => const FideliteScreen(), section: 'Commerce'),
    if (s.hasModule('rendez_vous') && (s.can('rendez_vous.view') || s.can('rendez_vous.own') || s.can('rendez_vous.manage')))
      NavItem('rdv', tr('Rendez-vous'), Icons.event_available_outlined, (_) => const RdvScreen(), section: 'Commerce'),
    if (s.hasModule('ventes') && s.can('ventes.view'))
      NavItem('ventes', tr('Ventes'), Icons.receipt_long_outlined, (_) => const DocumentsScreen(direction: DocDirection.sale), section: 'Commerce'),
    if (s.hasModule('fne') && (s.can('fne.view') || s.can('fne.manage')))
      NavItem('fne', tr('Facture normalisée (FNE)'), Icons.verified_outlined, (_) => const FneScreen(), section: 'Commerce'),
    if (s.hasModule('relances') && (s.can('relances.view') || s.can('relances.manage')))
      NavItem('relances', tr('Relances & recouvrement'), Icons.notifications_active_outlined, (_) => const RelancesScreen(), section: 'Commerce'),
    if (s.hasModule('ventes') && (s.can('ventes.manage') || s.can('ventes.payments.manage')))
      NavItem('pay_links', tr('Liens de paiement'), Icons.add_link, (_) => const PaymentLinksScreen(), section: 'Commerce'),
    if (s.hasModule('abonnements') && (s.can('abonnements.view') || s.can('abonnements.manage')))
      NavItem('abonnements', tr('Contrats & abonnements'), Icons.autorenew, (_) => const AbonnementsScreen(), section: 'Commerce'),
    if (s.hasModule('evenements') && (s.can('evenements.view') || s.can('evenements.sell') || s.can('evenements.manage')))
      NavItem('evenements', tr('Événements & salons'), Icons.storefront_outlined, (_) => const EvenementsScreen(), section: 'Commerce'),
    if (s.hasModule('billetterie') && (s.can('billetterie.scan') || s.can('billetterie.sell') || s.can('billetterie.manage')))
      NavItem('billetterie', tr('Billetterie'), Icons.confirmation_number_outlined, (_) => const BilletterieHome(), section: 'Commerce'),
    if (s.hasModule('hebergement') && (s.can('hebergement.view') || s.can('hebergement.manage')))
      NavItem('hebergement', tr('Hébergement web'), Icons.dns_outlined, (_) => const HebergementScreen(), section: 'Commerce'),
    if (s.hasModule('achats') && s.can('achats.view'))
      NavItem('achats', tr('Achats'), Icons.shopping_cart_outlined, (_) => const DocumentsScreen(direction: DocDirection.purchase), section: 'Commerce'),
    if (s.hasModule('appro') && ['appro.view', 'appro.manage'].any(s.can))
      NavItem('appro', tr('Importations & locations'), Icons.directions_boat_outlined, (_) => const ApproScreen(), section: 'Commerce'),
    if (s.hasModule('portail_fournisseurs') && (s.can('portail_fournisseurs.view') || s.can('portail_fournisseurs.manage')))
      NavItem('portail_fournisseurs', tr('Portail fournisseurs'), Icons.storefront_outlined, (_) => const FournisseursScreen(), section: 'Commerce'),
    if (s.can('core.contacts.view'))
      NavItem('contacts', tr('Clients & fournisseurs'), Icons.people_outline, (_) => const PartnersScreen(), section: 'Commerce'),
    if (s.can('core.products.view')) NavItem('products', tr('Produits & services'), Icons.sell_outlined, (_) => const ProductsScreen(), section: 'Commerce'),
    if (s.hasModule('tarifs') && (s.can('tarifs.manage') || s.can('ventes.manage')))
      NavItem('tarifs', tr('Listes de prix'), Icons.price_change_outlined, (_) => const TarifsScreen(), section: 'Commerce'),
    if (s.hasModule('etiquettes') && (s.can('etiquettes.print') || s.can('etiquettes.manage')))
      NavItem(
        'labels',
        tr('Étiquettes'),
        Icons.qr_code_2,
        (_) => Column(
          children: [
            PageHeader(title: tr('Étiquettes & codes-barres'), subtitle: tr('Prix, rayons, pesée — planche A4 ou imprimante d\'étiquettes')),
            const Expanded(child: LabelsScreen()),
          ],
        ),
        section: 'Commerce',
      ),
    if (s.hasModule('stock') && s.can('stock.view')) NavItem('stock', tr('Stock'), Icons.inventory_2_outlined, (_) => const StockScreen(), section: 'Commerce'),
    if (s.hasModule('lots') && (s.can('stock.view') || s.can('stock.manage')))
      NavItem('lots', tr('Lots & séries'), Icons.qr_code_scanner, (_) => const LotsScreen(), section: 'Commerce'),
    if (s.hasModule('logistique') && (s.can('logistique.view') || s.can('logistique.deliver') || s.can('logistique.manage')))
      NavItem('logistique', tr('Livraisons'), Icons.local_shipping_outlined, (_) => const LogistiqueScreen(), section: 'Opérations'),
    if (s.hasModule('flotte') && ['flotte.request', 'flotte.approve', 'flotte.view', 'flotte.manage'].any(s.can))
      NavItem('flotte', tr('Parc automobile'), Icons.directions_car_outlined, (_) => const FlotteScreen(), section: 'Opérations'),
    if (s.hasModule('materiel') && ['materiel.use', 'materiel.view', 'materiel.manage'].any(s.can))
      NavItem('materiel', tr('Sorties de matériel'), Icons.videocam_outlined, (_) => const MaterielScreen(), section: 'Opérations'),
    if (s.hasModule('maintenance') && ['maintenance.request', 'maintenance.view', 'maintenance.work', 'maintenance.manage'].any(s.can))
      NavItem('maintenance', tr('Maintenance'), Icons.build_outlined, (_) => const MaintenanceScreen(), section: 'Opérations'),
    if (s.hasModule('qualite') && ['qualite.report', 'qualite.inspect', 'qualite.view', 'qualite.manage'].any(s.can))
      NavItem('qualite', tr('Qualité'), Icons.verified_outlined, (_) => const QualiteScreen(), section: 'Opérations'),
    if (s.hasModule('dev') && ['dev.view', 'dev.contribute', 'dev.manage'].any(s.can))
      NavItem('dev', tr('Développement'), Icons.code, (_) => const DevScreen(), section: 'Opérations'),
    if (s.hasModule('prestations') && (s.can('prestations.view') || s.can('prestations.log') || s.can('prestations.manage')))
      NavItem('prestations', tr('Forfaits & livrables'), Icons.work_history_outlined, (_) => const PrestationsScreen(), section: 'Opérations'),
    if (s.hasModule('helpdesk') && (s.can('helpdesk.view') || s.can('helpdesk.agent') || s.can('helpdesk.manage')))
      NavItem('helpdesk', tr('Service client'), Icons.support_agent, (_) => const HelpdeskScreen(), section: 'Opérations'),
    if (s.hasModule('interventions') && (s.can('interventions.view') || s.can('interventions.do') || s.can('interventions.manage')))
      NavItem('interventions', tr('Interventions'), Icons.engineering_outlined, (_) => const InterventionsScreen(), section: 'Opérations'),
    if (s.hasModule('production') && (s.can('production.view') || s.can('production.operate') || s.can('production.manage')))
      NavItem('production', tr('Fabrication'), Icons.precision_manufacturing_outlined, (_) => const ProductionScreen(), section: 'Opérations'),
    if (s.hasModule('tresorerie') && (s.can('tresorerie.view') || s.can('tresorerie.operate') || s.can('tresorerie.manage')))
      NavItem('tresorerie', tr('Banque & trésorerie'), Icons.savings_outlined, (_) => const TresorerieScreen(), section: 'Finance'),
    if (s.hasModule('fonds') && ['fonds.request', 'fonds.approve', 'fonds.provide', 'fonds.view', 'fonds.manage'].any(s.can))
      NavItem('fonds', tr('Appels de fonds'), Icons.request_page_outlined, (_) => const FondsScreen(), section: 'Finance'),
    if (s.hasModule('notes_frais') && (s.can('notes_frais.approve') || s.can('notes_frais.manage')))
      NavItem('notes_frais', tr('Notes de frais'), Icons.request_quote_outlined, (_) => const NotesFraisScreen(), section: 'Finance'),
    if (s.hasModule('analytique') && (s.can('analytique.view') || s.can('analytique.manage')))
      NavItem('analytique', tr('Analytique & budgets'), Icons.pie_chart_outline, (_) => const AnalytiqueScreen(), section: 'Finance'),
    if (s.hasModule('devises') && (s.can('devises.manage') || s.can('compta.view')))
      NavItem('devises', tr('Devises & cours'), Icons.currency_exchange, (_) => const DevisesScreen(), section: 'Finance'),
    if (s.hasModule('immobilisations') && (s.can('immobilisations.view') || s.can('immobilisations.manage')))
      NavItem('immobilisations', tr('Immobilisations'), Icons.domain_outlined, (_) => const ImmobilisationsScreen(), section: 'Finance'),
    if (s.hasModule('compta') && (s.can('compta.view') || s.can('compta.manage')))
      NavItem('compta', tr('Comptabilité'), Icons.account_balance_outlined, (_) => const ComptaScreen(), section: 'Finance'),
    if (s.hasModule('approbations'))
      NavItem('approbations', tr('Approbations'), Icons.approval_outlined, (_) => const ApprobationsScreen(), section: 'Collaboration'),
    if (s.hasModule('achats') && s.can('achats.request') && !s.can('achats.view'))
      NavItem(
        'purchase_requests',
        tr('Demandes d\'achat'),
        Icons.assignment_outlined,
        (_) => const PurchaseRequestsScreen(embedded: true),
        section: 'Collaboration',
      ),
    if (s.hasModule('projets') && (s.can('projets.view') || s.can('projets.contribute') || s.can('projets.manage')))
      NavItem('projets', tr('Projets'), Icons.task_alt, (_) => const ProjetsScreen(), section: 'Collaboration'),
    if (s.hasModule('planning') && (s.can('planning.view') || s.can('planning.manage')))
      NavItem('planning', tr('Planning des ressources'), Icons.view_timeline_outlined, (_) => const PlanningScreen(), section: 'Collaboration'),
    if (s.hasModule('feuilles_temps') && (s.can('feuilles_temps.submit') || s.can('feuilles_temps.approve')))
      NavItem('feuilles_temps', tr('Feuilles de temps'), Icons.fact_check_outlined, (_) => const FeuillesScreen(), section: 'Collaboration'),
    if (s.hasModule('tickets'))
      NavItem('tickets', tr('Demandes internes'), Icons.confirmation_number_outlined, (_) => const TicketsScreen(), section: 'Collaboration'),
    if (s.hasModule('agenda') && (s.can('agenda.use') || s.can('agenda.manage')))
      NavItem('agenda', tr('Agenda'), Icons.calendar_month_outlined, (_) => const AgendaScreen(), section: 'Collaboration'),
    if (s.hasModule('mailbox') && (s.can('mailbox.use') || s.can('mailbox.manage')))
      NavItem('mailbox', tr('Boîte mail'), Icons.mail_outline, (_) => const MailboxScreen(), section: 'Collaboration'),
    if (s.hasModule('whatsapp') && (s.can('whatsapp.use') || s.can('whatsapp.manage')))
      NavItem('whatsapp', tr('WhatsApp'), Icons.chat_outlined, (_) => const WhatsappScreen(), section: 'Collaboration'),
    if (s.hasModule('visio') && (s.can('visio.use') || s.can('visio.webinar') || s.can('visio.manage')))
      NavItem('visio', tr('Yomeet : réunions & webinaires'), Icons.video_call_outlined, (_) => const VisioScreen(), section: 'Collaboration'),
    if (s.hasModule('documents') && (s.can('documents.view') || s.can('documents.manage') || s.can('documents.admin')))
      NavItem('ged', tr('Documents'), Icons.folder_outlined, (_) => const GedScreen(), section: 'Collaboration'),
    if (s.hasModule('activite') && (s.can('activite.use') || s.can('activite.view_all')))
      NavItem('activite', tr('Rapports d\'activité'), Icons.summarize_outlined, (_) => const ActiviteScreen(), section: 'Collaboration'),
    if (s.hasModule('juridique') && (s.can('juridique.view') || s.can('juridique.manage')))
      NavItem('juridique', tr('Juridique & contrats'), Icons.gavel_outlined, (_) => const JuridiqueScreen(), section: 'Collaboration'),
    if (s.hasModule('signature'))
      NavItem('signature', tr('Signature électronique'), Icons.draw_outlined, (_) => const SignatureScreen(), section: 'Collaboration'),
    if (s.hasModule('rh') && s.can('rh.self') && (myEmployee(s) != null || !(s.can('rh.view') || s.can('rh.manage') || s.can('pointage.kiosk'))))
      NavItem('mon_espace', tr('Mon espace'), Icons.person_outline, (_) => const MonEspaceScreen(), section: 'RH'),
    if (s.hasModule('rh') && (s.can('rh.view') || s.can('rh.manage') || leadsATeam(s)))
      NavItem('mon_equipe', tr('Mon équipe'), Icons.groups_2_outlined, (_) => const MonEquipeScreen(), section: 'RH'),
    if (s.hasModule('rh') && (s.can('rh.view') || s.can('rh.manage')))
      NavItem('rh', tr('Salariés & congés'), Icons.badge_outlined, (_) => const RhScreen(), section: 'RH'),
    if (s.hasModule('talents') && (s.can('talents.view') || s.can('talents.manage') || s.can('rh.self')))
      NavItem('talents', tr('Talents'), Icons.emoji_events_outlined, (_) => const TalentsScreen(), section: 'RH'),
    if (s.hasModule('recrutement') && (s.can('recrutement.view') || s.can('recrutement.manage') || s.can('recrutement.interview')))
      NavItem('recrutement', tr('Recrutement'), Icons.person_search_outlined, (_) => const RecrutementScreen(), section: 'RH'),
    if (s.hasModule('pointage') && (s.can('pointage.view') || s.can('pointage.manage')))
      NavItem('pointage', tr('Présence'), Icons.fingerprint, (_) => const PointageScreen(), section: 'RH'),
    if (s.hasModule('pointage') && s.can('pointage.kiosk') && !(s.can('pointage.view') || s.can('pointage.manage')))
      NavItem('kiosk', tr('Borne de pointage'), Icons.tablet_android, (_) => const KioskScreen(embedded: true), section: 'RH'),
    if (s.hasModule('paie') && (s.can('paie.view') || s.can('paie.manage')))
      NavItem('paie', tr('Paie'), Icons.payments_outlined, (_) => const PaieScreen(), section: 'RH'),
    if (s.hasModule('import_export') && (s.can('import_export.import') || s.can('import_export.export')))
      NavItem('import_export', tr('Import / export'), Icons.import_export, (_) => const ImportExportScreen(), section: 'Société'),
    if (s.hasModule('migration') && s.can('migration.run'))
      NavItem('migration', tr('Migration depuis un autre logiciel'), Icons.move_down, (_) => const MigrationScreen(), section: 'Société'),
    if (!AppConfig.purchasesHidden) NavItem('store', tr('Boutique de modules'), Icons.storefront_outlined, (_) => const StoreScreen(), section: 'Société'),
    if (s.mode == 'saas' && s.can('core.settings.manage'))
      NavItem('referral', tr('Parrainage'), Icons.card_giftcard_rounded, (_) => const ReferralScreen(), section: 'Société'),
    if (s.mode == 'saas' && s.can('core.settings.manage') && !AppConfig.purchasesHidden)
      NavItem('licenses', tr('Mes installations'), Icons.dns_outlined, (c) => LicensesScreen(onBuy: (id) => Navigator.of(c).push(MaterialPageRoute(builder: (_) => Scaffold(appBar: AppBar(title: Text(tr('Boutique · installation'))), body: StoreScreen(licenseId: id))))), section: 'Société'),
    if (s.mode != 'saas' && (s.isSuperAdmin || s.can('core.settings.manage')))
      NavItem('license', tr('Compte Yomara & modules'), Icons.link_rounded, (_) => const LicenseLocalScreen(), section: 'Société'),
    if (s.hasModule('champs') && s.can('champs.manage'))
      NavItem('champs', tr('Champs personnalisés'), Icons.dashboard_customize_outlined, (_) => const ChampsScreen(), section: 'Société'),
    if (s.can('core.settings.manage'))
      NavItem('integrations', tr('Intégrations & API'), Icons.hub_outlined, (_) => const IntegrationsScreen(), section: 'Société'),
    if (s.can('core.users.manage')) NavItem('team', tr('Équipe & accès'), Icons.groups_2_outlined, (_) => const TeamScreen(), section: 'Société'),
    NavItem('settings', tr('Paramètres'), Icons.settings_outlined, (_) => const SettingsScreen(), section: 'Société'),
    if (s.can('core.settings.manage'))
      NavItem('payments', tr('Moyens de paiement'), Icons.payments_outlined, (_) => const CompanyPaymentsScreen(embedded: true), section: 'Société'),
    if (s.mode != 'saas' && (s.isSuperAdmin || s.can('core.settings.manage')))
      NavItem('backups', tr('Sauvegardes'), Icons.backup_outlined, (_) => const BackupsScreen(), section: 'Société'),
    if (s.isSuperAdmin) ...[
      // Installation reliée à un compte Yomara : ventes, tarifs et formules sont gérés par l'éditeur, pas ici
      NavItem('admin_console', tr('Console éditeur'), Icons.space_dashboard_outlined, (c) => AdminConsoleScreen(onGo: ShellNav.of(c)?.go), section: 'Super admin'),
      NavItem('admin_setup', tr('Ma configuration'), Icons.fact_check_outlined, (c) => AdminSetupScreen(onGo: ShellNav.of(c)?.go), section: 'Super admin'),
      if (s.mode == 'saas') NavItem('admin_site', tr('Site & prospects'), Icons.travel_explore_rounded, (c) => AdminSiteScreen(onGo: ShellNav.of(c)?.go), section: 'Super admin'),
      if (s.mode == 'saas') NavItem('admin_livechat', tr('Live chat du site'), Icons.forum_rounded, (_) => const AdminLiveChatScreen(), section: 'Super admin'),
      if (s.mode == 'saas') NavItem('admin_comms', tr('Marketing : e-mails clients'), Icons.campaign_outlined, (_) => const AdminCommsScreen(), section: 'Super admin'),
      if (s.mode == 'saas') NavItem('admin_promo', tr('Marketing : promotions'), Icons.local_offer_outlined, (_) => const AdminPromoScreen(), section: 'Super admin'),
      if (s.mode == 'saas') NavItem('admin_licenses', tr('Installations'), Icons.vpn_key_rounded, (_) => const AdminLicensesScreen(), section: 'Super admin'),
      NavItem('admin_support', tr('Support clients'), Icons.support_agent_rounded, (_) => const AdminSupportScreen(), section: 'Super admin'),
      NavItem('admin', tr('Système & serveur'), Icons.insights, (_) => const AdminOverviewScreen(), section: 'Super admin'),
      NavItem('admin_online', tr('Connectés'), Icons.wifi_tethering, (_) => const AdminOnlineScreen(), section: 'Super admin'),
      NavItem('admin_sites', tr('Sites vitrines'), Icons.language, (_) => const AdminSitesScreen(), section: 'Super admin'),
      NavItem('admin_events', tr('Événements (plateforme)'), Icons.event_available_outlined, (_) => const AdminEventsScreen(), section: 'Super admin'),
      if (!selfHosted) NavItem('admin_store', tr('Ventes de modules'), Icons.shopping_bag_outlined, (_) => const AdminStoreScreen(), section: 'Super admin'),
      if (!selfHosted) NavItem('admin_modules', tr('Modules & tarifs'), Icons.extension_outlined, (_) => const AdminModulesScreen(), section: 'Super admin'),
      NavItem('admin_companies', tr('Sociétés'), Icons.business_outlined, (_) => const AdminCompaniesScreen(), section: 'Super admin'),
      if (!selfHosted) NavItem('admin_plans', tr('Formules'), Icons.workspace_premium_outlined, (_) => const AdminPlansScreen(), section: 'Super admin'),
      NavItem('admin_updates', tr('Mises à jour'), Icons.system_update_alt, (_) => const AdminUpdatesScreen(), section: 'Super admin'),
      NavItem('admin_env', tr('Configuration du serveur'), Icons.dns_outlined, (_) => const AdminEnvScreen(), section: 'Super admin'),
      if (s.mode == 'saas') NavItem('admin_backups', tr('Sauvegardes'), Icons.backup_outlined, (_) => const BackupsScreen(), section: 'Super admin'),
    ],
  ];

  final _scaffold = GlobalKey<ScaffoldState>();
  late final List<String> _favorites = UiPrefs.list('nav_favorites');
  late List<String> _recent = UiPrefs.list('nav_recent');
  late final Set<String> _open = {...UiPrefs.list('nav_open'), if (_current != 'dashboard') groupOf(_current).key};
  late bool _rail = UiPrefs.flag('nav_rail');

  void _go(String id) {
    rememberScreen(id);
    setState(() {
      _current = id;
      if (id != 'dashboard') _open.add(groupOf(id).key);
      _recent = [id, ..._recent.where((r) => r != id)].take(8).toList();
    });
    UiPrefs.save('nav_recent', _recent);
    if (_scaffold.currentState?.isDrawerOpen ?? false) _scaffold.currentState!.closeDrawer();
  }

  /// Barre du bas (téléphone) : accueil, puis vos favoris ou les écrans clés de votre poste, et « Plus » (menu complet).
  List<NavItem> _mobileTabs(List<NavItem> items, Session s) {
    final byId = {for (final i in items) i.id: i};
    final defaults = s.isSuperAdmin
        ? ['admin_console', 'admin_livechat', 'admin_support', 'admin_companies', 'admin_comms']
        : ['mailbox', 'mon_espace', 'pos', 'agenda', 'tickets', 'ventes', 'crm', 'interventions', 'helpdesk', 'whatsapp', 'stock', 'contacts'];
    final picks = <String>[];
    for (final id in [..._favorites, ...defaults]) {
      if (picks.length == 3) break;
      if (id != 'dashboard' && byId.containsKey(id) && !picks.contains(id)) picks.add(id);
    }
    if (s.isSuperAdmin && byId.containsKey('admin_console')) {
      // Super admin : la console remplace l'accueil ; « Ma société » reste accessible dans le menu
      final rest = <String>[];
      for (final id in [...picks.where((i) => i != 'admin_console'), 'admin_comms', 'admin_companies']) {
        if (byId.containsKey(id) && !rest.contains(id) && rest.length < 3) rest.add(id);
      }
      return [byId['admin_console']!, for (final id in rest) byId[id]!];
    }
    return [if (byId.containsKey('dashboard')) byId['dashboard']!, for (final id in picks) byId[id]!];
  }

  static const _shortLabels = {
    'dashboard': 'Accueil', 'mailbox': 'Mail', 'mon_espace': 'Mon espace', 'pos': 'Caisse', 'agenda': 'Agenda', 'tickets': 'Demandes', 'ventes': 'Ventes',
    'crm': 'CRM', 'interventions': 'Interventions', 'helpdesk': 'Service client', 'whatsapp': 'WhatsApp', 'stock': 'Stock', 'contacts': 'Clients',
    'admin_console': 'Console', 'admin_livechat': 'Live chat', 'admin_support': 'Support', 'admin_companies': 'Sociétés', 'admin_comms': 'Marketing',
    'admin_store': 'Ventes', 'admin_modules': 'Modules', 'admin_plans': 'Formules', 'admin_setup': 'Réglages', 'admin_site': 'Site', 'admin_promo': 'Promotions',
    'admin_licenses': 'Installations', 'admin_sites': 'Sites', 'admin_events': 'Événements', 'admin_updates': 'Mises à jour', 'admin_env': 'Serveur', 'admin_backups': 'Sauvegardes',
  };

  /// Libellé court (barre du bas, barre de titre du téléphone) : jamais sur deux lignes.
  static String shortLabel(NavItem t) {
    final s = _shortLabels[t.id];
    if (s != null) return tr(s);
    final l = t.label;
    if (l.length <= 12) return l;
    final first = l.split(RegExp(r'[\s:&,]+')).first;
    return first.length >= 3 ? first : l;
  }

  // Guide de démarrage : une fois par utilisateur, après l'écran de préparation
  bool _guideQueued = false;

  Future<void> _maybeShowGuide() async {
    await Future<void>.delayed(const Duration(milliseconds: 900));
    if (!mounted) return;
    final s = context.read<Session>();
    if (s.preparing) {
      _guideQueued = false; // reprogrammé à la fin de la préparation
      return;
    }
    if (s.user['onboarded'] != false) return;
    await showOnboardingGuide(context, available: _items(s).map((i) => i.id).toSet(), go: _go);
  }

  void _toggleFavorite(String id) {
    setState(() => _favorites.contains(id) ? _favorites.remove(id) : _favorites.add(id));
    UiPrefs.save('nav_favorites', _favorites);
  }

  void _toggleGroup(String key) {
    setState(() => _open.contains(key) ? _open.remove(key) : _open.add(key));
    UiPrefs.save('nav_open', _open.toList());
  }

  void _toggleRail() {
    setState(() => _rail = !_rail);
    UiPrefs.setFlag('nav_rail', _rail);
  }

  /// Actions rapides proposées dans la palette (selon les droits).
  List<PaletteEntry> _actions(Session s, Set<String> ids) {
    void editor(DocConfig cfg, String type) => Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => DocumentEditor(config: cfg, initialType: type),
      ),
    );
    return [
      if (ids.contains('ventes') && s.can(DocConfig.sale.managePerm)) ...[
        PaletteEntry(
          id: 'action:invoice',
          label: tr('Nouvelle facture'),
          icon: Icons.add_card,
          subtitle: tr('Ventes'),
          keywords: 'facturer client',
          run: () => editor(DocConfig.sale, 'invoice'),
        ),
        PaletteEntry(
          id: 'action:quote',
          label: tr('Nouveau devis'),
          icon: Icons.request_quote_outlined,
          subtitle: tr('Ventes'),
          keywords: 'proforma offre',
          run: () => editor(DocConfig.sale, 'quote'),
        ),
      ],
      if (ids.contains('achats') && s.can(DocConfig.purchase.managePerm))
        PaletteEntry(
          id: 'action:supplier_invoice',
          label: tr('Saisir une facture fournisseur'),
          icon: Icons.post_add,
          subtitle: tr('Achats'),
          keywords: 'depense achat',
          run: () => editor(DocConfig.purchase, 'supplier_invoice'),
        ),
      if (ids.contains('pos'))
        PaletteEntry(
          id: 'action:pos',
          label: tr('Ouvrir la caisse'),
          icon: Icons.point_of_sale,
          subtitle: tr('Caisse'),
          keywords: 'ticket vente comptoir',
          run: () => _go('pos'),
        ),
      if (ids.contains('contacts'))
        PaletteEntry(
          id: 'action:contacts',
          label: tr('Ajouter un client ou un fournisseur'),
          icon: Icons.person_add_alt,
          subtitle: tr('Contacts'),
          keywords: 'tiers',
          run: () => _go('contacts'),
        ),
      PaletteEntry(
        id: 'action:guide',
        label: tr('Guide de démarrage'),
        icon: Icons.school_outlined,
        subtitle: tr('Aide'),
        keywords: 'aide tutoriel visite decouvrir prise en main onboarding',
        run: () => showOnboardingGuide(context, markSeen: false, available: ids, go: _go),
      ),
    ];
  }

  Future<void> _palette() async {
    final s = context.read<Session>();
    final items = _items(s);
    final ids = items.map((i) => i.id).toSet();
    final entries = [
      for (final it in items)
        PaletteEntry(id: it.id, label: it.label, icon: it.icon, subtitle: groupOf(it.id).label, keywords: navKeywords[it.id] ?? '', run: () => _go(it.id)),
      ..._actions(s, ids),
    ];
    await showCommandPalette(context, entries, _recent);
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final items = _items(session);
    final item = items.firstWhere((i) => i.id == _current, orElse: () => items.first);
    // Le guide de démarrage s'adresse aux sociétés clientes, pas à l'éditeur de la plateforme
    if (!_guideQueued && !session.preparing && session.user['onboarded'] == false && !(session.isSuperAdmin && session.mode == 'saas')) {
      _guideQueued = true;
      WidgetsBinding.instance.addPostFrameCallback((_) => _maybeShowGuide());
    }
    final wide = MediaQuery.of(context).size.width >= 1000;
    SbColors.light = Theme.of(context).brightness == Brightness.light && UiPrefs.flag('sidebar_light');

    final suspended = session.bootstrap?['suspended'] as String?;
    final expiring = [for (final m in (session.bootstrap?['expiring_modules'] as List? ?? const [])) asMap(m)];
    final sub = asMap(session.bootstrap?['subscription']);
    final canRenew = session.can('core.settings.manage') && items.any((i) => i.id == 'store');
    final lic = asMap(asMap(session.bootstrap?['app'])['license']);
    final licStatus = '${lic['status'] ?? ''}';
    final licProblem = licStatus.isNotEmpty && !const ['valid', 'not_required', 'free'].contains(licStatus) && items.any((i) => i.id == 'license') && item.id != 'license';
    final body = Column(
      children: [
        if (licProblem)
          AnimatedFadeIn(
            child: Container(
              margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                color: const Color(0xFFFDECEC),
                border: Border.all(color: const Color(0xFFF3B4B4)),
              ),
              child: Row(children: [
                const Icon(Icons.vpn_key_off_outlined, color: Color(0xFFB42318), size: 20),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    tr(switch (licStatus) {
                      'expired' => 'Modules non vérifiés depuis plus de 30 jours : reconnectez cet appareil à internet.',
                      'refused' => 'Liaison au compte Yomara refusée : ${lic['error'] ?? ''}',
                      'suspended' => 'Modules suspendus par l\'éditeur : contactez-nous.',
                      'revoked' => 'Cette installation a été déliée de votre compte : reliez-la à nouveau.',
                      _ => 'Liaison au compte Yomara à vérifier.',
                    }),
                    style: const TextStyle(color: Color(0xFF7A271A), fontWeight: FontWeight.w600, fontSize: 13),
                  ),
                ),
                TextButton(onPressed: () => _go('license'), child: Text(tr('Ouvrir'))),
              ]),
            ),
          ),
        if (sub.isNotEmpty && sub['quiet'] != true && session.can('core.settings.manage') && item.id != 'store' && !_subDismissed && !AppConfig.purchasesHidden)
          AnimatedFadeIn(child: _SubscriptionBanner(sub: sub, onOpen: () => _go('store'), onClose: () => setState(() => _subDismissed = true))),
        // Super admin : nouveaux fichiers déposés, base de données pas encore mise à jour → proposé sur tous les écrans
        if (session.isSuperAdmin && ((session.bootstrap?['app']?['pending_migrations'] as num?) ?? 0) > 0 && item.id != 'admin_console' && item.id != 'admin_updates')
          const ServerUpdateBanner(padding: EdgeInsets.fromLTRB(16, 12, 16, 0)),
        if (expiring.isNotEmpty && canRenew && item.id != 'store' && !_renewDismissed)
          AnimatedFadeIn(
            child: Container(
              margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                color: Theme.of(context).brightness == Brightness.dark ? const Color(0xFF3A2F12) : const Color(0xFFFFF4D6),
                border: Border.all(color: Theme.of(context).brightness == Brightness.dark ? const Color(0xFF6B5420) : const Color(0xFFF5D88A)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.workspace_premium, color: Color(0xFFB7791F), size: 20),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      tr(
                        MediaQuery.sizeOf(context).width < 700
                            ? (expiring.length == 1 ? '« ${expiring.first['name']} » expire dans ${toDouble(expiring.first['days_left']).round()} j' : '${expiring.length} modules expirent bientôt')
                            : expiring.length == 1
                            ? '« ${expiring.first['name']} » ${toDouble(expiring.first['days_left']) == 0 ? 'expire aujourd\'hui' : 'expire dans ${toDouble(expiring.first['days_left']).round()} jour(s)'}${expiring.first['source'] == 'trial' ? ' (essai)' : ''}.'
                            : '${expiring.length} modules arrivent à échéance (le premier dans ${toDouble(expiring.first['days_left']).round()} jour(s)).',
                      ),
                      style: TextStyle(color: Theme.of(context).brightness == Brightness.dark ? const Color(0xFFF6DFA0) : const Color(0xFF6B4A0E), fontWeight: FontWeight.w600, fontSize: 13),
                    ),
                  ),
                  TextButton(
                    onPressed: () => _go('store'),
                    style: TextButton.styleFrom(foregroundColor: Theme.of(context).brightness == Brightness.dark ? YColors.yellow : const Color(0xFF8A5A0B)),
                    child: Text(tr('Renouveler')),
                  ),
                  IconButton(
                    tooltip: tr('Plus tard'),
                    visualDensity: VisualDensity.compact,
                    onPressed: () {
                      setState(() => _renewDismissed = true);
                      UiPrefs.save('renew_later', [DateTime.now().toIso8601String().substring(0, 10)]);
                    },
                    icon: const Icon(Icons.close, size: 18, color: Color(0xFF6B4A0E)),
                  ),
                ],
              ),
            ),
          ),
        if (suspended != null)
          MaterialBanner(
            backgroundColor: YColors.danger.withValues(alpha: .1),
            content: Text(tr(suspended)),
            actions: [TextButton(onPressed: () => session.refreshBootstrap(), child: Text(tr('Vérifier')))],
          ),
        Expanded(
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 260),
            switchInCurve: Curves.easeOutCubic,
            transitionBuilder: pageSwitchTransition,
            layoutBuilder: (current, previous) => Stack(children: [...previous, ?current]),
            child: KeyedSubtree(
              key: ValueKey(item.id),
              child: NavScope(
                icon: item.icon,
                group: item.id == 'dashboard' ? '' : groupOf(item.id).label,
                groupKey: groupOf(item.id).key,
                child: Builder(builder: item.builder),
              ),
            ),
          ),
        ),
      ],
    );

    final sidebar = _Sidebar(
      items: items,
      current: item.id,
      onSelect: _go,
      favorites: _favorites,
      open: _open,
      onToggleGroup: _toggleGroup,
      onToggleFavorite: _toggleFavorite,
      onSearch: _palette,
      rail: wide && _rail,
      onToggleRail: wide ? _toggleRail : null,
    );
    final shell = ShellNav(
      go: _go,
      palette: _palette,
      available: items.map((i) => i.id).toSet(),
      child: wide
          ? Scaffold(
              body: Row(
                children: [
                  AnimatedContainer(duration: const Duration(milliseconds: 220), curve: Curves.easeOutCubic, width: _rail ? 76 : 264, child: sidebar),
                  Expanded(
                    child: Column(
                      children: [
                        _TopBar(
                          item: item,
                          rail: _rail,
                          onToggleRail: _toggleRail,
                          onSearch: _palette,
                          onGo: _go,
                          canSettings: items.any((i) => i.id == 'settings'),
                          canMe: items.any((i) => i.id == 'mon_espace'),
                          onToggleSidebarTheme: () async {
                            await UiPrefs.setFlag('sidebar_light', !SbColors.light);
                            setState(() {});
                          },
                        ),
                        Expanded(child: body),
                      ],
                    ),
                  ),
                ],
              ),
            )
          : Scaffold(
              key: _scaffold,
              appBar: AppBar(
                titleSpacing: 0,
                // Téléphone : titre lisible en entier ; la boutique reste dans le menu
                // Écran étroit : libellé court (le titre complet est repris dans l'en-tête de la page)
                title: Text(tr(item.id == 'dashboard' ? '${session.company['name'] ?? item.label}' : (MediaQuery.sizeOf(context).width < 430 ? shortLabel(item) : item.label)),
                    maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, letterSpacing: -.2)),
                actions: [
                  IconButton(tooltip: tr('Rechercher'), onPressed: _palette, icon: const Icon(Icons.search_rounded)),
                  const RefreshSpaceButton(),
                  const UpdateButton(compact: true),
                  if (context.watch<Session>().hasBranches) const Padding(padding: EdgeInsets.only(right: 4), child: BranchSelector(dark: false)),
                  const NotificationBell(light: false),
                  const Padding(padding: EdgeInsets.only(right: 10), child: SyncBadge(compact: true)),
                ],
              ),
              drawer: Drawer(width: 300, child: sidebar),
              body: body,
              bottomNavigationBar: Builder(builder: (context) {
                final tabs = _mobileTabs(items, session);
                if (tabs.length < 2) return const SizedBox.shrink();
                final idx = tabs.indexWhere((t) => t.id == item.id);
                final theme = Theme.of(context);
                return DecoratedBox(
                  decoration: BoxDecoration(
                    color: theme.cardColor,
                    border: Border(top: BorderSide(color: theme.dividerColor.withValues(alpha: .35))),
                    boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .04), blurRadius: 12, offset: const Offset(0, -2))],
                  ),
                  child: NavigationBarTheme(
                  data: NavigationBarThemeData(
                    backgroundColor: Colors.transparent,
                    surfaceTintColor: Colors.transparent,
                    elevation: 0,
                    indicatorColor: YColors.blue.withValues(alpha: .12),
                    indicatorShape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(size: 23, color: s.contains(WidgetState.selected) ? YColors.blue : theme.hintColor)),
                    labelTextStyle: WidgetStateProperty.resolveWith((s) => TextStyle(fontSize: 11.5, fontWeight: s.contains(WidgetState.selected) ? FontWeight.w800 : FontWeight.w600,
                        color: s.contains(WidgetState.selected) ? YColors.blue : theme.hintColor)),
                  ),
                  child: NavigationBar(
                  height: 66,
                  labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
                  selectedIndex: idx < 0 ? tabs.length : idx,
                  onDestinationSelected: (i) => i == tabs.length ? _scaffold.currentState?.openDrawer() : _go(tabs[i].id),
                  destinations: [
                    for (final t in tabs) NavigationDestination(icon: Icon(t.icon), label: shortLabel(t)),
                    NavigationDestination(icon: const Icon(Icons.apps_rounded), label: tr('Plus')),
                  ],
                  ),
                  ),
                );
              }),
            ),
    );
    return CallbackShortcuts(
      bindings: {const SingleActivator(LogicalKeyboardKey.keyK, control: true): _palette, const SingleActivator(LogicalKeyboardKey.keyK, meta: true): _palette},
      child: ChangeNotifierProvider(
        create: (_) => MyDay(session),
        child: Focus(
          autofocus: true,
          child: Stack(children: [
            Positioned.fill(child: shell),
            // Support client : chat en direct avec l'éditeur (personnes désignées par la société)
            // au-dessus de l'emplacement des boutons « Nouveau… » des écrans, pour ne jamais les recouvrir
            if (!session.isSuperAdmin) Positioned(right: wide ? 24 : 16, bottom: wide ? 96 : 160, child: const SupportButton()),
          ]),
        ),
      ),
    );
  }
}

/// Couleurs du menu latéral : clair (par défaut) ou sombre (thème sombre, ou préférence « menu sombre »).
class SbColors {
  static bool light = true;
  static Color get bg => light ? Colors.white : const Color(0xFF17213A);
  static Color get text => light ? const Color(0xFF15233F) : Colors.white;
  static Color get sub => light ? const Color(0xFF6B7A94) : const Color(0xFF94A3C4);
  static Color get icon => light ? const Color(0xFF5B6B85) : const Color(0xFFC3CDE3);
  static Color get label => light ? const Color(0xFF33415C) : const Color(0xFFE6ECF7);
  static Color get muted => light ? const Color(0xFF8A97AD) : const Color(0xFF8391AF);
  static Color get fill => light ? const Color(0xFFF2F5FA) : Colors.white.withValues(alpha: .07);
  static Color get sel => light ? YColors.blue.withValues(alpha: .09) : const Color(0xFF26324F);
  static Color get accent => light ? YColors.blue : YColors.yellow;
  static Color get border => light ? YColors.line : Colors.white.withValues(alpha: .10);
}

/// Menu latéral : tableau de bord, favoris, puis les espaces de travail repliables ; mode réduit (icônes).
class _Sidebar extends StatelessWidget {
  const _Sidebar({
    required this.items,
    required this.current,
    required this.onSelect,
    required this.favorites,
    required this.open,
    required this.onToggleGroup,
    required this.onToggleFavorite,
    required this.onSearch,
    required this.rail,
    this.onToggleRail,
  });

  final List<NavItem> items;
  final String current;
  final ValueChanged<String> onSelect;
  final List<String> favorites;
  final Set<String> open;
  final ValueChanged<String> onToggleGroup;
  final ValueChanged<String> onToggleFavorite;
  final VoidCallback onSearch;
  final bool rail;
  final VoidCallback? onToggleRail;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final byId = {for (final it in items) it.id: it};
    // En bas du menu : modules & tarifs, paramètres (comme sur les maquettes)
    final pinned = [
      for (final id in [byId.containsKey('store') ? 'store' : 'license', 'settings'])
        if (byId[id] != null) byId[id]!,
    ];
    final pinnedIds = {for (final p in pinned) p.id};
    final placed = <String>{...pinnedIds};
    final groups = <(NavGroup, List<NavItem>)>[];
    for (final g in navGroups) {
      final its = [
        for (final id in g.ids)
          if (byId[id] != null && id != 'dashboard' && (rail || !pinnedIds.contains(id))) byId[id]!,
      ];
      placed.addAll(g.ids);
      if (its.isNotEmpty) groups.add((g, its));
    }
    final orphans = items.where((i) => !placed.contains(i.id)).toList();
    if (orphans.isNotEmpty) {
      final i = groups.indexWhere((x) => x.$1.key == 'admin');
      if (i >= 0) {
        groups[i] = (groups[i].$1, [...groups[i].$2, ...orphans]);
      } else {
        groups.add((navGroups[10], orphans));
      }
    }
    final favs = [
      for (final f in favorites)
        if (byId[f] != null) byId[f]!,
    ];
    final dash = byId['dashboard'];

    Widget header() => Padding(
      padding: EdgeInsets.fromLTRB(rail ? 0 : 18, 18, rail ? 0 : 12, 8),
      child: rail
          ? const Center(child: _Logo())
          : Row(
              children: [
                const _Logo(),
                const SizedBox(width: 10),
                Expanded(
                  child: InkWell(
                    borderRadius: BorderRadius.circular(8),
                    onTap: () => _accountSheet(context),
                    child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        tr((session.company['name'] as String?) ?? 'Yomara ERP'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(color: SbColors.text, fontWeight: FontWeight.w700),
                      ),
                      Text(
                        tr('Yomara ERP'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(color: SbColors.sub, fontSize: 12),
                      ),
                    ],
                  ),
                  ),
                ),
                if (onToggleRail == null) NotificationBell(light: !SbColors.light),
              ],
            ),
    );

    Widget search() => Padding(
      padding: EdgeInsets.fromLTRB(rail ? 14 : 14, 4, rail ? 14 : 14, 8),
      child: Material(
        color: SbColors.fill,
        borderRadius: BorderRadius.circular(10),
        child: InkWell(
          borderRadius: BorderRadius.circular(10),
          onTap: onSearch,
          child: SizedBox(
            height: 40,
            child: rail
                ? Tooltip(
                    message: tr('Rechercher (Ctrl+K)'),
                    child: Icon(Icons.search, color: SbColors.icon, size: 20),
                  )
                : Row(
                    children: [
                      SizedBox(width: 12),
                      Icon(Icons.search, color: SbColors.icon, size: 19),
                      SizedBox(width: 10),
                      Expanded(
                        child: Text(tr('Rechercher…'), style: TextStyle(color: SbColors.sub, fontSize: 13.5)),
                      ),
                      _Kbd('Ctrl K'),
                      SizedBox(width: 8),
                    ],
                  ),
          ),
        ),
      ),
    );

    final children = <Widget>[];
    if (rail) {
      if (dash != null) children.add(_RailButton(icon: dash.icon, label: dash.label, selected: current == 'dashboard', onTap: () => onSelect('dashboard')));
      if (favs.isNotEmpty) {
        children.add(_RailMenu(icon: Icons.star_rounded, label: tr('Favoris'), items: favs, current: current, onSelect: onSelect, accent: true));
      }
      for (final (g, its) in groups) {
        children.add(_RailMenu(icon: g.icon, label: g.label, items: its, current: current, onSelect: onSelect));
      }
    } else {
      if (dash != null) {
        children.add(const _SectionTitle('Vue d\'ensemble'));
        children.add(_NavTile(item: dash, selected: current == 'dashboard', onTap: () => onSelect('dashboard')));
      }
      if (favs.isNotEmpty) {
        children.add(const _SectionTitle('Favoris'));
        for (final it in favs) {
          children.add(_NavTile(item: it, selected: it.id == current, onTap: () => onSelect(it.id), favorite: true, onFavorite: () => onToggleFavorite(it.id)));
        }
      }
      children.add(const _SectionTitle('Espaces de travail'));
      for (final (g, its) in groups) {
        final isOpen = open.contains(g.key);
        final active = its.any((i) => i.id == current);
        children.add(_GroupHeader(group: g, count: its.length, open: isOpen, active: active, onTap: () => onToggleGroup(g.key)));
        children.add(
          AnimatedSize(
            duration: const Duration(milliseconds: 220),
            curve: Curves.easeOutCubic,
            alignment: Alignment.topCenter,
            child: isOpen
                ? Column(
                    children: [
                      for (final it in its)
                        _NavTile(
                          item: it,
                          selected: it.id == current,
                          onTap: () => onSelect(it.id),
                          indent: true,
                          favorite: favorites.contains(it.id),
                          onFavorite: () => onToggleFavorite(it.id),
                        ),
                      const SizedBox(height: 4),
                    ],
                  )
                : const SizedBox(width: double.infinity),
          ),
        );
      }
    }

    return Container(
      decoration: SbColors.light
          ? BoxDecoration(
              color: SbColors.bg,
              border: const Border(right: BorderSide(color: YColors.line)),
            )
          : BoxDecoration(color: SbColors.bg),
      child: SafeArea(
        child: Column(
          children: [
            header(),
            if (session.hasBranches && !rail)
              Padding(
                padding: EdgeInsets.fromLTRB(14, 0, 14, 6),
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: BranchSelector(dark: !SbColors.light),
                ),
              ),
            search(),
            Expanded(
              child: ListView(padding: const EdgeInsets.only(bottom: 12), children: children),
            ),
            if (!rail) ...[
              Divider(height: 1, indent: 18, endIndent: 18, color: SbColors.border),
              const SizedBox(height: 6),
              for (final it in pinned)
                _NavTile(
                  item: it.id == 'store' || it.id == 'license' ? NavItem(it.id, tr('Modules & tarifs'), Icons.view_in_ar_outlined, it.builder) : it,
                  selected: it.id == current,
                  onTap: () => onSelect(it.id),
                ),
              const _UserCard(),
            ] else
              const Padding(padding: EdgeInsets.all(6), child: SyncBadge(compact: true)),
            if (onToggleRail != null && rail)
              Align(
                alignment: rail ? Alignment.center : Alignment.centerRight,
                child: IconButton(
                  tooltip: trn(rail ? 'Déplier le menu' : 'Réduire le menu'),
                  onPressed: onToggleRail,
                  icon: Icon(rail ? Icons.keyboard_double_arrow_right : Icons.keyboard_double_arrow_left, color: SbColors.muted, size: 20),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Carte de l'utilisateur en bas du menu : initiales, nom, rôle ; ouvre le compte.
class _UserCard extends StatelessWidget {
  const _UserCard();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final name = '${s.user['name'] ?? ''}'.trim();
    final initials = name.split(RegExp(r'\s+')).where((w) => w.isNotEmpty).take(2).map((w) => w[0].toUpperCase()).join();
    final role = s.isSuperAdmin ? tr('Super administrateur') : tr('${s.user['role'] ?? s.user['email'] ?? ''}');
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
      child: Material(
        color: SbColors.light ? const Color(0xFFF4F6FA) : const Color(0xFF212C47),
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () => _accountSheet(context),
          child: Container(
            padding: const EdgeInsets.fromLTRB(10, 10, 6, 10),
            decoration: BoxDecoration(borderRadius: BorderRadius.circular(14), border: Border.all(color: SbColors.border)),
            child: Row(children: [
              Container(
                width: 40,
                height: 40,
                alignment: Alignment.center,
                decoration: const BoxDecoration(shape: BoxShape.circle, color: YColors.blue),
                child: Text(initials.isEmpty ? '?' : initials, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 14)),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                  Text(name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: SbColors.text, fontWeight: FontWeight.w700, fontSize: 14)),
                  if (role.isNotEmpty) Text(role, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: SbColors.sub, fontSize: 12)),
                ]),
              ),
              Icon(Icons.chevron_right_rounded, color: SbColors.muted, size: 20),
            ]),
          ),
        ),
      ),
    );
  }
}

class _Logo extends StatelessWidget {
  const _Logo();

  @override
  Widget build(BuildContext context) => const YomaraMark(size: 34);
}

class _Kbd extends StatelessWidget {
  const _Kbd(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
    decoration: BoxDecoration(
      border: Border.all(color: SbColors.border),
      borderRadius: BorderRadius.circular(5),
    ),
    child: Text(
      tr(text),
      style: TextStyle(color: SbColors.sub, fontSize: 10.5, fontWeight: FontWeight.w600),
    ),
  );
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(22, 16, 16, 6),
    child: Text(
      tr(text.toUpperCase()),
      style: TextStyle(color: SbColors.muted, fontSize: 10.5, fontWeight: FontWeight.w700, letterSpacing: .9),
    ),
  );
}

class _GroupHeader extends StatelessWidget {
  const _GroupHeader({required this.group, required this.count, required this.open, required this.active, required this.onTap});

  final NavGroup group;
  final int count;
  final bool open;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 1),
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          borderRadius: BorderRadius.circular(10),
          onTap: onTap,
          child: SizedBox(
            height: 44,
            child: Row(
              children: [
                const SizedBox(width: 12),
                Icon(group.icon, size: 20, color: active ? SbColors.accent : SbColors.icon),
                const SizedBox(width: 14),
                Expanded(
                  child: Text(
                    tr(group.label),
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(color: active ? SbColors.text : SbColors.label, fontWeight: active ? FontWeight.w700 : FontWeight.w500, fontSize: 14.5),
                  ),
                ),
                AnimatedRotation(
                  turns: open ? .5 : 0,
                  duration: const Duration(milliseconds: 200),
                  child: Icon(Icons.keyboard_arrow_down_rounded, size: 20, color: SbColors.muted),
                ),
                const SizedBox(width: 8),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Mode réduit : une icône par espace, ses modules dans un menu.
class _RailMenu extends StatelessWidget {
  const _RailMenu({required this.icon, required this.label, required this.items, required this.current, required this.onSelect, this.accent = false});

  final IconData icon;
  final String label;
  final List<NavItem> items;
  final String current;
  final ValueChanged<String> onSelect;
  final bool accent;

  @override
  Widget build(BuildContext context) {
    final active = items.any((i) => i.id == current);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Center(
        child: PopupMenuButton<String>(
          tooltip: trn(label),
          offset: const Offset(64, 0),
          onSelected: onSelect,
          itemBuilder: (_) => [
            PopupMenuItem(
              enabled: false,
              height: 32,
              child: Text(tr(label.toUpperCase()), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: .6)),
            ),
            for (final it in items)
              PopupMenuItem(
                value: it.id,
                child: Row(
                  children: [
                    Icon(it.icon, size: 19, color: it.id == current ? YColors.blue : null),
                    const SizedBox(width: 12),
                    Text(tr(it.label), style: TextStyle(fontWeight: it.id == current ? FontWeight.w700 : null)),
                  ],
                ),
              ),
          ],
          child: Container(
            width: 48,
            height: 44,
            decoration: BoxDecoration(color: active ? SbColors.sel : null, borderRadius: BorderRadius.circular(12)),
            child: Icon(icon, size: 21, color: active || accent ? SbColors.accent : SbColors.icon),
          ),
        ),
      ),
    );
  }
}

class _RailButton extends StatelessWidget {
  const _RailButton({required this.icon, required this.label, required this.selected, required this.onTap});

  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 2),
    child: Center(
      child: Tooltip(
        message: label,
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Container(
            width: 48,
            height: 44,
            decoration: BoxDecoration(color: selected ? SbColors.sel : null, borderRadius: BorderRadius.circular(12)),
            child: Icon(icon, size: 21, color: selected ? SbColors.accent : SbColors.icon),
          ),
        ),
      ),
    ),
  );
}

/// Entrée du menu : survol animé, barre jaune de l'élément actif, étoile des favoris au survol.
class _NavTile extends StatefulWidget {
  const _NavTile({required this.item, required this.selected, required this.onTap, this.indent = false, this.favorite = false, this.onFavorite});

  final NavItem item;
  final bool selected;
  final VoidCallback onTap;
  final bool indent;
  final bool favorite;
  final VoidCallback? onFavorite;

  @override
  State<_NavTile> createState() => _NavTileState();
}

class _NavTileState extends State<_NavTile> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final sel = widget.selected;
    return Padding(
      padding: EdgeInsets.fromLTRB(widget.indent ? 22 : 10, 1, 10, 1),
      child: MouseRegion(
        onEnter: (_) => setState(() => _hover = true),
        onExit: (_) => setState(() => _hover = false),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          curve: Curves.easeOut,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(10),
            color: sel ? SbColors.sel : (_hover ? SbColors.fill : Colors.transparent),
          ),
          child: Material(
            type: MaterialType.transparency,
            child: InkWell(
              borderRadius: BorderRadius.circular(10),
              onTap: widget.onTap,
              onLongPress: widget.onFavorite,
              child: SizedBox(
                height: widget.indent ? 38 : 44,
                child: Row(
                  children: [
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 220),
                      width: 3,
                      height: sel ? 20 : 0,
                      margin: EdgeInsets.only(right: widget.indent ? 10 : 13),
                      decoration: BoxDecoration(color: SbColors.accent, borderRadius: BorderRadius.circular(3)),
                    ),
                    Icon(widget.item.icon, size: widget.indent ? 18 : 20, color: sel ? (SbColors.light ? SbColors.accent : Colors.white) : SbColors.icon),
                    SizedBox(width: widget.indent ? 12 : 16),
                    Expanded(
                      child: Text(
                        tr(widget.item.label),
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: widget.indent ? 13.5 : 14,
                          color: sel ? (SbColors.light ? YColors.blue : Colors.white) : (_hover ? SbColors.text : SbColors.label),
                          fontWeight: sel ? FontWeight.w700 : FontWeight.w500,
                        ),
                      ),
                    ),
                    if (widget.onFavorite != null && (_hover || widget.favorite))
                      IconButton(
                        tooltip: widget.favorite ? 'Retirer des favoris' : 'Ajouter aux favoris',
                        visualDensity: VisualDensity.compact,
                        iconSize: 17,
                        onPressed: widget.onFavorite,
                        icon: Icon(widget.favorite ? Icons.star_rounded : Icons.star_outline_rounded, color: widget.favorite ? YColors.yellow : SbColors.muted),
                      )
                    else
                      const SizedBox(width: 8),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Indicateur de connexion / synchronisation.
class SyncBadge extends StatelessWidget {
  const SyncBadge({super.key, this.compact = false});

  final bool compact;

  @override
  Widget build(BuildContext context) {
    final sync = context.watch<SyncEngine>();
    final pending = sync.pendingCount;

    final (color, icon, label) = !sync.online
        ? (YColors.warning, Icons.cloud_off, 'Hors ligne')
        : sync.syncing
        ? (YColors.blue, Icons.sync, 'Synchronisation…')
        : pending > 0
        ? (YColors.warning, Icons.cloud_upload_outlined, '$pending en attente')
        : (YColors.success, Icons.cloud_done_outlined, 'Synchronisé');

    final detail = !sync.online && pending > 0
        ? '$pending modification(s) à envoyer'
        : (sync.lastSyncAt != null ? 'Dernière synchro ${dateTimeFr(sync.lastSyncAt!.toIso8601String())}' : null);

    if (compact) {
      return Tooltip(
        message: [label, ?detail].join('\n'),
        child: IconButton(
          onPressed: () => sync.sync(),
          icon: Badge(
            isLabelVisible: pending > 0,
            label: Text(tr('$pending')),
            child: Icon(icon, color: color),
          ),
        ),
      );
    }

    return InkWell(
      borderRadius: BorderRadius.circular(10),
      onTap: () => sync.sync(),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: SbColors.fill, borderRadius: BorderRadius.circular(10)),
        child: Row(
          children: [
            sync.syncing
                ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: YColors.yellow))
                : Icon(icon, color: color, size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    tr(label),
                    style: TextStyle(color: SbColors.text, fontWeight: FontWeight.w600, fontSize: 13),
                  ),
                  if (detail != null) Text(tr(detail), style: TextStyle(color: SbColors.sub, fontSize: 11)),
                  if (sync.issues.isNotEmpty)
                    Text(
                      tr('${sync.issues.length} alerte(s) de synchro'),
                      style: TextStyle(color: SbColors.light ? YColors.warning : YColors.yellow, fontSize: 11),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Barre supérieure (grand écran) : repli du menu, fil d'Ariane, recherche globale, notifications, synchro, profil.
class _TopBar extends StatelessWidget {
  const _TopBar({
    required this.item,
    required this.rail,
    required this.onToggleRail,
    required this.onSearch,
    required this.onGo,
    required this.canSettings,
    required this.canMe,
    required this.onToggleSidebarTheme,
  });

  final NavItem item;
  final bool rail, canSettings, canMe;
  final VoidCallback onToggleRail, onSearch, onToggleSidebarTheme;
  final ValueChanged<String> onGo;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final dark = Theme.of(context).brightness == Brightness.dark;
    final hint = Theme.of(context).hintColor;
    final name = (session.user['name'] as String?) ?? '';
    final initials = name.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).take(2).map((w) => w[0].toUpperCase()).join();
    return Container(
      height: 60,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        border: Border(bottom: BorderSide(color: dark ? Colors.white10 : YColors.line)),
      ),
      child: Row(
        children: [
          IconButton(
            tooltip: trn(rail ? 'Déplier le menu' : 'Réduire le menu'),
            onPressed: onToggleRail,
            icon: Icon(rail ? Icons.menu : Icons.menu_open, color: hint),
          ),
          const SizedBox(width: 6),
          // Fil d'Ariane et titre : dans l'en-tête de chaque page
          const Spacer(flex: 2),
          Expanded(
            flex: 3,
            child: Align(
              alignment: Alignment.centerRight,
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Material(
                  color: dark ? Colors.white.withValues(alpha: .06) : YColors.bg,
                  borderRadius: BorderRadius.circular(12),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: onSearch,
                    child: SizedBox(
                      height: 40,
                      child: Row(
                        children: [
                          const SizedBox(width: 12),
                          Icon(Icons.search, size: 19, color: hint),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              tr('Rechercher un écran, une action…'),
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(color: hint, fontSize: 13.5),
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              border: Border.all(color: dark ? Colors.white24 : YColors.line),
                              borderRadius: BorderRadius.circular(5),
                            ),
                            child: Text(
                              tr('Ctrl K'),
                              style: TextStyle(color: hint, fontSize: 10.5, fontWeight: FontWeight.w600),
                            ),
                          ),
                          const SizedBox(width: 10),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(width: 10),
          const UpdateButton(),
          if (canSettings && item.id != 'store' && !AppConfig.purchasesHidden)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: Tooltip(
                message: tr('Ajouter ou renouveler des modules'),
                child: Material(
                  color: const Color(0xFFF59E0B),
                  elevation: 2,
                  shadowColor: const Color(0x66F59E0B),
                  shape: const StadiumBorder(),
                  child: InkWell(
                    customBorder: const StadiumBorder(),
                    onTap: () => onGo('store'),
                    child: Padding(
                      padding: EdgeInsets.symmetric(horizontal: MediaQuery.of(context).size.width >= 1200 ? 14 : 9, vertical: 8),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.storefront_outlined, size: 19, color: Colors.white),
                          if (MediaQuery.of(context).size.width >= 1200) ...[
                            const SizedBox(width: 8),
                            Text(
                              tr('Boutique de modules'),
                              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          const MyDayButtons(),
          const RefreshSpaceButton(),
          const NotificationBell(light: false),
          const SizedBox(width: 10),
          const SyncBadge(compact: true),
          const SizedBox(width: 4),
          _HoverAccountMenu(
            onSelected: (v) async {
              if (v == 'theme') onToggleSidebarTheme();
              if (v == 'settings') onGo('settings');
              if (v == 'me') onGo('mon_espace');
              if (v == 'store') onGo('store');
              if (v == 'shortcuts') onSearch();
              if (v == 'guide') return showOnboardingGuide(context, markSeen: false);
              if (v == 'lang') return chooseLanguage(context);
              if (v == 'appearance') return chooseAppearance(context);
              if (v == 'about') return showYomaraAbout(context);
              if (v == 'delete_account') return showDeleteAccountDialog(context);
              if (v == 'logout') return confirmLogout(context);
            },
            header: _AccountHeader(
              name: name,
              email: '${session.user['email'] ?? ''}',
              initials: initials,
              role: session.isSuperAdmin ? 'Super administrateur' : '${session.user['role'] ?? ''}',
              company: '${session.company['name'] ?? ''}',
            ),
            items: [
              if (canMe) _accountItem('me', Icons.badge_outlined, 'Mon espace', 'Congés, fiches de paie, notes de frais', YColors.blue),
              if (canSettings) _accountItem('settings', Icons.settings_outlined, 'Paramètres', 'Société, utilisateurs, documents', const Color(0xFF6A4BD8)),
              if (canSettings && !AppConfig.purchasesHidden) _accountItem('store', Icons.storefront_outlined, 'Boutique de modules', 'Ajouter ou renouveler des modules', YColors.warning),
              _accountItem('shortcuts', Icons.keyboard_command_key, 'Recherche rapide', 'Ctrl K pour aller partout', const Color(0xFF00897B)),
              _accountItem('guide', Icons.school_outlined, 'Guide de démarrage', 'Revoir la présentation des espaces', const Color(0xFFD97706)),
              _accountItem('lang', Icons.translate, 'Langue', I18n.languages[I18n.lang], const Color(0xFF0891B2)),
              _accountItem('appearance', dark ? Icons.dark_mode_outlined : Icons.light_mode_outlined, 'Apparence',
                  switch (ThemePrefs.name) { 'dark' => 'Sombre', 'system' => 'Selon l\'appareil', _ => 'Clair' }, const Color(0xFF4F46E5)),
              if (!dark)
                _accountItem(
                  'theme',
                  SbColors.light ? Icons.dark_mode_outlined : Icons.light_mode_outlined,
                  SbColors.light ? 'Menu latéral sombre' : 'Menu latéral clair',
                  'Changer l\'apparence du menu',
                  YColors.night,
                ),
              _accountItem('about', Icons.info_outline, 'À propos', 'Version, licence et code source', YColors.muted),
              const _AccItem.divider(),
              _accountItem('logout', Icons.logout, 'Se déconnecter', null, YColors.danger, danger: true),
              if (!session.isSuperAdmin) _accountItem('delete_account', Icons.person_remove_outlined, 'Supprimer mon compte', 'Désactiver et effacer mes données', YColors.danger, danger: true),
            ],
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 6),
              child: Container(
                padding: const EdgeInsets.all(2),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: YColors.blue.withValues(alpha: .3), width: 2),
                ),
                child: CircleAvatar(
                  radius: 16,
                  backgroundColor: YColors.blue,
                  child: Text(
                    tr(initials.isEmpty ? '?' : initials),
                    style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w800),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

_AccItem _accountItem(String value, IconData icon, String title, String? subtitle, Color color, {bool danger = false}) =>
    _AccItem(value, icon, title, subtitle, color, danger: danger);

/// Entrée du menu du compte (ou séparateur).
class _AccItem {
  const _AccItem(this.value, this.icon, this.title, this.subtitle, this.color, {this.danger = false});
  const _AccItem.divider() : value = null, icon = null, title = '', subtitle = null, color = Colors.transparent, danger = false;
  final String? value;
  final IconData? icon;
  final String title;
  final String? subtitle;
  final Color color;
  final bool danger;
}

/// Menu du compte : s'ouvre au survol de l'avatar (et au clic / toucher), se referme quand la souris s'en éloigne.
class _HoverAccountMenu extends StatefulWidget {
  const _HoverAccountMenu({required this.header, required this.items, required this.onSelected, required this.child});
  final Widget header;
  final List<_AccItem> items;
  final ValueChanged<String> onSelected;
  final Widget child;

  @override
  State<_HoverAccountMenu> createState() => _HoverAccountMenuState();
}

class _HoverAccountMenuState extends State<_HoverAccountMenu> {
  final _portal = OverlayPortalController();
  final _link = LayerLink();
  final _group = Object();
  Timer? _closeTimer;

  void _open() {
    _closeTimer?.cancel();
    if (!_portal.isShowing) setState(_portal.show);
  }

  void _scheduleClose() {
    _closeTimer?.cancel();
    _closeTimer = Timer(const Duration(milliseconds: 250), _close);
  }

  void _close() {
    _closeTimer?.cancel();
    if (mounted && _portal.isShowing) setState(_portal.hide);
  }

  @override
  void dispose() {
    _closeTimer?.cancel();
    super.dispose();
  }

  Widget _item(_AccItem it) {
    if (it.value == null) return const Padding(padding: EdgeInsets.symmetric(vertical: 4), child: Divider(height: 1));
    return InkWell(
      onTap: () {
        _close();
        widget.onSelected(it.value!);
      },
      child: Container(
        height: it.subtitle == null ? 48 : 58,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Row(
          children: [
            Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(color: it.color.withValues(alpha: .1), borderRadius: BorderRadius.circular(10)),
              child: Icon(it.icon, size: 18, color: it.color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    tr(it.title),
                    style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5, color: it.danger ? YColors.danger : null),
                  ),
                  if (it.subtitle != null)
                    Text(
                      tr(it.subtitle!),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 11.5, color: YColors.muted),
                    ),
                ],
              ),
            ),
            if (!it.danger) const Icon(Icons.chevron_right, size: 18, color: YColors.muted),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return OverlayPortal(
      controller: _portal,
      overlayChildBuilder: (ctx) => Positioned(
        width: 300,
        child: CompositedTransformFollower(
          link: _link,
          targetAnchor: Alignment.bottomRight,
          followerAnchor: Alignment.topRight,
          offset: const Offset(0, 6),
          child: TapRegion(
            groupId: _group,
            onTapOutside: (_) => _close(),
            child: MouseRegion(
              onEnter: (_) => _open(),
              onExit: (_) => _scheduleClose(),
              child: Material(
                elevation: 8,
                color: Theme.of(ctx).cardColor,
                borderRadius: BorderRadius.circular(16),
                clipBehavior: Clip.antiAlias,
                child: ConstrainedBox(
                  constraints: BoxConstraints(maxHeight: MediaQuery.of(ctx).size.height - 90),
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [widget.header, for (final it in widget.items) _item(it)]),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
      child: CompositedTransformTarget(
        link: _link,
        child: TapRegion(
          groupId: _group,
          child: MouseRegion(
            cursor: SystemMouseCursors.click,
            onEnter: (_) => _open(),
            onExit: (_) => _scheduleClose(),
            child: Tooltip(
              message: _portal.isShowing ? '' : tr('Mon compte'),
              child: GestureDetector(onTap: () => _portal.isShowing ? _close() : _open(), child: widget.child),
            ),
          ),
        ),
      ),
    );
  }
}

/// En-tête du menu du compte : avatar, nom, e-mail, rôle et société (non cliquable, couleurs pleines).
class _AccountHeader extends StatelessWidget {
  const _AccountHeader({required this.name, required this.email, required this.initials, required this.role, required this.company});
  final String name, email, initials, role, company;

  @override
  Widget build(BuildContext context) {
    final w = this;
    return Container(
      margin: const EdgeInsets.fromLTRB(8, 8, 8, 6),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        gradient: const LinearGradient(colors: [Color(0xFF1F6FEB), Color(0xFF1453C4)], begin: Alignment.topLeft, end: Alignment.bottomRight),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 22,
            backgroundColor: Colors.white,
            child: Text(
              tr(w.initials.isEmpty ? '?' : w.initials),
              style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w900),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  tr(w.name),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15),
                ),
                Text(
                  tr(w.email),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: Colors.white.withValues(alpha: .8), fontSize: 12),
                ),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  runSpacing: 4,
                  children: [
                    for (final t in [w.role, w.company])
                      if (t.isNotEmpty && t != 'null')
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                          decoration: BoxDecoration(color: Colors.white.withValues(alpha: .18), borderRadius: BorderRadius.circular(20)),
                          child: Text(
                            tr(t),
                            style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600),
                          ),
                        ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}


/// Bouton « Actualiser » : recharge modules, droits et données sans fermer l'application.
class RefreshSpaceButton extends StatefulWidget {
  const RefreshSpaceButton({super.key});

  @override
  State<RefreshSpaceButton> createState() => _RefreshSpaceButtonState();
}

class _RefreshSpaceButtonState extends State<RefreshSpaceButton> with SingleTickerProviderStateMixin {
  late final _spin = AnimationController(vsync: this, duration: const Duration(milliseconds: 800));
  bool _busy = false;

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  Future<void> _go() async {
    if (_busy) return;
    setState(() => _busy = true);
    _spin.repeat();
    final s = context.read<Session>();
    try {
      await s.refreshBootstrap(throwOnError: true);
      unawaited(s.sync.sync());
      if (mounted) toast(context, tr('Espace actualisé.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      _spin.stop();
      _spin.reset();
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => IconButton(
        tooltip: tr('Actualiser'),
        onPressed: _go,
        icon: RotationTransition(turns: _spin, child: const Icon(Icons.refresh_rounded)),
      );
}


/// Bandeau d'abonnement : essai qui se termine, abonnement qui expire, ou modules désactivés après l'échéance.
class _SubscriptionBanner extends StatelessWidget {
  const _SubscriptionBanner({required this.sub, required this.onOpen, required this.onClose});
  final Map<String, dynamic> sub;
  final VoidCallback onOpen;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final kind = '${sub['kind']}';
    final days = (sub['days_left'] as num?)?.toInt() ?? 0;
    final when = days <= 0 ? 'aujourd\'hui' : (days == 1 ? 'demain' : 'dans $days jours');
    final expired = kind == 'expired';
    final mods = [for (final m in (sub['modules'] as List? ?? const [])) '$m'];
    final (bg, border, fg, icon) = expired
        ? (const Color(0xFFFDECEC), const Color(0xFFF3B4B4), const Color(0xFF7A271A), Icons.lock_clock_outlined)
        : kind == 'trial'
            ? (const Color(0xFFE8F0FF), const Color(0xFFB7CBFA), const Color(0xFF1E3A8A), Icons.hourglass_bottom_rounded)
            : (const Color(0xFFFFF4D6), const Color(0xFFF5D88A), const Color(0xFF6B4A0E), Icons.event_busy_outlined);
    final title = switch (kind) {
      'expired' => sub['reason'] == 'trial' ? 'Votre essai gratuit est terminé' : 'Votre abonnement a expiré',
      'trial' => 'Essai gratuit${sub['plan'] != null ? ' ${sub['plan']}' : ''} : se termine $when',
      _ => 'Votre formule${sub['plan'] != null ? ' ${sub['plan']}' : ''} expire $when',
    };
    final text = expired
        ? (mods.isEmpty ? 'Votre espace est passé sur la formule gratuite. Vos données sont conservées.' : '${mods.length} module(s) désactivé(s) : ${mods.take(6).join(', ')}${mods.length > 6 ? '…' : ''}. Vos données sont conservées.')
        : kind == 'trial'
            ? 'Choisissez une formule pour garder tous vos modules.'
            : 'Renouvelez maintenant : la nouvelle période démarre à la fin de l\'actuelle.';
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
      decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), color: bg, border: Border.all(color: border)),
      child: Row(children: [
        Icon(icon, color: fg, size: 22),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr(title), style: TextStyle(color: fg, fontWeight: FontWeight.w800, fontSize: 13.5)),
            const SizedBox(height: 2),
            Text(tr(text), style: TextStyle(color: fg, fontSize: 12.5)),
          ]),
        ),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: fg, foregroundColor: Colors.white, visualDensity: VisualDensity.compact),
          onPressed: onOpen,
          child: Text(tr(expired ? 'Réactiver' : (kind == 'trial' ? 'Choisir ma formule' : 'Renouveler'))),
        ),
        IconButton(tooltip: tr('Plus tard'), visualDensity: VisualDensity.compact, onPressed: onClose, icon: Icon(Icons.close, size: 18, color: fg)),
      ]),
    );
  }
}


/// Menu du compte sur mobile (toucher le nom en haut du menu) : déconnexion et suppression du compte.
Future<void> _accountSheet(BuildContext context) => showModalBottomSheet<void>(
  context: context,
  showDragHandle: true,
  builder: (c) {
    final s = c.read<Session>();
    return SafeArea(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        ListTile(
          leading: CircleAvatar(backgroundColor: YColors.blue, child: Text(((s.user['name'] as String?) ?? '?').trim().characters.firstOrNull?.toUpperCase() ?? '?', style: const TextStyle(color: Colors.white))),
          title: Text('${s.user['name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text('${s.user['email'] ?? ''}'),
        ),
        const Divider(height: 1),
        ListTile(
          leading: const Icon(Icons.contrast_rounded),
          title: Text(tr('Apparence')),
          subtitle: Text(tr(switch (ThemePrefs.name) { 'dark' => 'Sombre', 'system' => 'Selon l\'appareil', _ => 'Clair' })),
          onTap: () {
            Navigator.pop(c);
            chooseAppearance(context);
          },
        ),
        ListTile(
          leading: const Icon(Icons.info_outline),
          title: Text(tr('À propos')),
          onTap: () {
            Navigator.pop(c);
            showYomaraAbout(context);
          },
        ),
        ListTile(
          leading: const Icon(Icons.logout, color: YColors.danger),
          title: Text(tr('Se déconnecter'), style: const TextStyle(color: YColors.danger)),
          onTap: () async {
            Navigator.pop(c);
            await confirmLogout(context);
          },
        ),
        if (!s.isSuperAdmin)
          ListTile(
            leading: const Icon(Icons.person_remove_outlined, color: YColors.danger),
            title: Text(tr('Supprimer mon compte'), style: const TextStyle(color: YColors.danger)),
            subtitle: Text(tr('Désactiver et effacer mes données')),
            onTap: () {
              Navigator.pop(c);
              showDeleteAccountDialog(context);
            },
          ),
        const SizedBox(height: 8),
      ]),
    );
  },
);

/// Déconnexion, avec le choix d'effacer les données de la société de cet appareil (poste partagé, navigateur).
Future<void> confirmLogout(BuildContext context) async {
  final session = context.read<Session>();
  final pending = context.read<SyncEngine>().pendingCount;
  var wipe = session.wipeOnLogoutDefault;
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        icon: const Icon(Icons.logout_rounded),
        title: Text(tr('Se déconnecter ?')),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(pending > 0 && !wipe
              ? '$pending saisie(s) ne sont pas encore envoyées au serveur : elles restent sur cet appareil et partiront à votre prochaine connexion.'
              : 'Vous pourrez vous reconnecter à tout moment.')),
          const SizedBox(height: 12),
          CheckboxListTile(
            value: wipe,
            onChanged: (v) => set(() => wipe = v ?? false),
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            title: Text(tr('Effacer les données de la société sur cet appareil')),
            subtitle: Text(tr('Conseillé sur un ordinateur partagé : elles seront rechargées à la prochaine connexion.')),
          ),
          if (wipe && pending > 0)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(tr('Attention : $pending saisie(s) non envoyée(s) seront perdues.'), style: const TextStyle(color: YColors.danger, fontWeight: FontWeight.w600)),
            ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
          FilledButton(
            style: wipe && pending > 0 ? FilledButton.styleFrom(backgroundColor: YColors.danger) : null,
            onPressed: () => Navigator.pop(c, true),
            child: Text(tr('Déconnexion')),
          ),
        ],
      ),
    ),
  );
  if (ok == true) await session.logout(wipe: wipe);
}

/// Apparence de l'application sur cet appareil : clair, sombre ou selon le réglage du système.
Future<void> chooseAppearance(BuildContext context) async {
  final v = await showDialog<String>(
    context: context,
    builder: (c) => SimpleDialog(
      title: Text(tr('Apparence')),
      children: [
        for (final (k, label, icon) in const [
          ('light', 'Clair', Icons.light_mode_outlined),
          ('dark', 'Sombre', Icons.dark_mode_outlined),
          ('system', 'Selon l\'appareil', Icons.brightness_auto_outlined),
        ])
          ListTile(
            leading: Icon(icon),
            title: Text(tr(label)),
            trailing: ThemePrefs.name == k ? const Icon(Icons.check_rounded, color: YColors.blue) : null,
            onTap: () => Navigator.pop(c, k),
          ),
      ],
    ),
  );
  if (v == null) return;
  await LocalDb.meta.put('theme_mode', v);
  ThemePrefs.load(v);
}
