import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/json.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../navigation.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const billingLabels = {'monthly': 'par mois', 'yearly': 'par an', 'one_time': 'achat définitif'};
const billingTitles = {'monthly': 'Abonnement mensuel', 'yearly': 'Abonnement annuel', 'one_time': 'Achat définitif (paiement unique)'};
const sourceLabels = {'purchase': 'Acheté', 'trial': 'Essai gratuit', 'plan': 'Inclus dans votre formule', 'free': 'Gratuit', 'included': 'Inclus', 'admin': 'Activé par l\'éditeur'};

Color orderStatusColor(String? s) => switch (s) {
  'paid' => YColors.success,
  'awaiting_validation' => YColors.blue,
  'pending' => YColors.warning,
  _ => YColors.danger,
};

/// Boutique des modules : découvrir, essayer, acheter (nécessite la connexion).
class StoreScreen extends StatefulWidget {
  const StoreScreen({super.key, this.licenseId});

  /// Licence (installation auto-hébergée) pour laquelle acheter ; null = l'espace en ligne.
  final int? licenseId;

  @override
  State<StoreScreen> createState() => _StoreScreenState();
}

class _StoreScreenState extends State<StoreScreen> with TickerProviderStateMixin {
  late TabController _tabs = TabController(length: 3, vsync: this);
  Map<String, dynamic>? _data;
  Object? _error;
  String _category = 'Tous';
  String _q = '';
  late int? _licenseId = widget.licenseId;
  bool _switching = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(StoreScreen old) {
    super.didUpdateWidget(old);
    if (old.licenseId != widget.licenseId && widget.licenseId != _licenseId) _switchTo(widget.licenseId);
  }

  Future<void> _load() async {
    try {
      final id = _licenseId;
      final r = Map<String, dynamic>.from(await context.read<Session>().api.get('store', query: id == null ? null : {'license': id}));
      if (mounted && id == _licenseId) setState(() => (_data = r, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  /// Change la cible des achats (espace en ligne ou une licence) et recharge le catalogue.
  Future<void> _switchTo(int? id) async {
    if (id == _licenseId && !_switching) return;
    final previous = _licenseId;
    setState(() => (_licenseId = id, _switching = true));
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.get('store', query: id == null ? null : {'license': id}));
      if (mounted && id == _licenseId) setState(() => (_data = r, _error = null, _category = 'Tous'));
    } catch (e) {
      if (mounted) {
        setState(() => _licenseId = previous);
        toast(context, errorText(e), error: true);
      }
    } finally {
      if (mounted) setState(() => _switching = false);
    }
  }

  Future<void> _changed() async {
    await _load();
    if (mounted) await context.read<Session>().refreshBootstrap();
  }

  @override
  void dispose() {
    _tabs.dispose();
    super.dispose();
  }

  /// L'onglet « Mon abonnement » (2e position) apparaît ou disparaît selon la cible : on garde l'onglet affiché.
  void _syncTabs(int length) {
    if (_tabs.length == length) return;
    final old = _tabs;
    final i = old.index;
    final index = length > old.length ? (i >= 1 ? i + 1 : 0) : (i == 1 ? 0 : (i > 1 ? i - 1 : 0));
    _tabs = TabController(length: length, vsync: this, initialIndex: index.clamp(0, length - 1));
    WidgetsBinding.instance.addPostFrameCallback((_) => old.dispose());
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _data == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_data == null) return const Center(child: CircularProgressIndicator());
    if (_data!['self_host'] is Map) return _SelfHostStore(info: asMap(_data!['self_host']), store: _data!, onRefresh: _changed);
    final modules = List<Map<String, dynamic>>.from((_data!['modules'] as List).map((e) => Map<String, dynamic>.from(e)));
    final present = {for (final m in modules) '${m['category'] ?? 'Autres'}'};
    final cats = ['Tous', 'Actifs', ..._catOrder.where(present.contains), ...present.where((c) => !_catOrder.contains(c))];
    final q = _q.toLowerCase();
    final shown =
        modules.where((m) {
          if (_category == 'Actifs' && m['active'] != true) return false;
          if (_category != 'Tous' && _category != 'Actifs' && m['category'] != _category) return false;
          return q.isEmpty || '${m['name']} ${m['description']} ${m['tagline'] ?? ''}'.toLowerCase().contains(q);
        }).toList()..sort((a, b) {
          int rank(Map m) => m['available'] == true ? (m['active'] == true ? 1 : 0) : 2;
          return rank(a).compareTo(rank(b));
        });
    final orders = List<Map<String, dynamic>>.from((_data!['orders'] as List).map((e) => Map<String, dynamic>.from(e)));
    final pendingOrders = orders.where((o) => o['status'] == 'pending' || o['status'] == 'awaiting_validation').length;
    final subs = modules.where((m) => m['active'] == true && ['purchase', 'trial'].contains(m['source'])).toList()..sort((a, b) => '${a['expires_at'] ?? '9'}'.compareTo('${b['expires_at'] ?? '9'}'));
    final plans = [for (final p in (_data!['plans'] as List? ?? const [])) asMap(p)];
    final subscription = asMap(_data!['subscription']);
    final showPlans = _data!['license'] == null && plans.isNotEmpty;
    final planAlert = showPlans && _subscriptionNeedsAction(subscription);
    _syncTabs(showPlans ? 4 : 3);

    return Column(
      children: [
        PageHeader(
          title: tr('Boutique de modules'),
          subtitle: tr('Ajoutez des fonctions à votre ERP : essai gratuit, abonnement ou achat définitif'),
          actions: [
            OutlinedButton.icon(onPressed: () => _contactSheet(context, Map<String, dynamic>.from(_data!['contact'])), icon: const Icon(Icons.support_agent), label: Text(tr('Nous contacter'))),
          ],
        ),
        if ((_data!['licenses'] as List? ?? const []).isNotEmpty)
          _LicenseTargetBar(
            licenses: List<Map<String, dynamic>>.from((_data!['licenses'] as List).map((e) => Map<String, dynamic>.from(e as Map))),
            selected: _licenseId,
            current: _data!['license'] is Map ? Map<String, dynamic>.from(_data!['license'] as Map) : null,
            busy: _switching,
            onChanged: _switchTo,
          ),
        TabBar(
          controller: _tabs,
          isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
          tabAlignment: TabAlignment.start,
          tabs: [
            Tab(text: tr('Modules')),
            if (showPlans)
              Tab(
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(tr('Mon abonnement')),
                    if (planAlert) ...[
                      const SizedBox(width: 6),
                      Container(width: 7, height: 7, decoration: const BoxDecoration(color: YColors.warning, shape: BoxShape.circle)),
                    ],
                  ],
                ),
              ),
            Tab(
              text: subs.any((m) => _daysLeft(m) != null && _daysLeft(m)! <= 14)
                  ? 'Mes abonnements (${subs.where((m) => _daysLeft(m) != null && _daysLeft(m)! <= 14).length} à renouveler)'
                  : 'Mes abonnements',
            ),
            Tab(text: pendingOrders > 0 ? 'Mes commandes ($pendingOrders)' : 'Mes commandes'),
          ],
        ),
        Expanded(
          child: TabBarView(
            controller: _tabs,
            children: [
              RefreshIndicator(
                onRefresh: _load,
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
                  children: [
                    SearchField(onChanged: (v) => setState(() => _q = v), hint: tr('Rechercher un module…')),
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [for (final c in cats) ChoiceChip(label: Text(tr(c)), selected: _category == c, onSelected: (_) => setState(() => _category = c))],
                    ),
                    const SizedBox(height: 16),
                    if (_category == 'Tous' && q.isEmpty && (_data!['packs'] as List? ?? const []).isNotEmpty) _Packs(packs: List.from(_data!['packs']), store: _data!, onChanged: _changed),
                    if (_category == 'Tous' && q.isEmpty && _data!['visio_quota'] is Map && (_data!['minute_packs'] as List? ?? const []).isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 18),
                        child: YomeetQuotaBar(
                          quota: Map<String, dynamic>.from(_data!['visio_quota'] as Map),
                          onBuy: () => showYomeetMinutesShop(context, onChanged: _changed),
                        ),
                      ),
                    LayoutBuilder(
                      builder: (context, box) {
                        final cols = box.maxWidth > 1100 ? 3 : (box.maxWidth > 700 ? 2 : 1);
                        final w = (box.maxWidth - (cols - 1) * 14) / cols;
                        Widget grid(List<Map<String, dynamic>> list) => Wrap(
                          spacing: 14,
                          runSpacing: 14,
                          children: [
                            for (final (i, m) in list.indexed)
                              SizedBox(
                                width: w,
                                child: AnimatedFadeIn(
                                  index: i,
                                  child: HoverLift(
                                    child: _ModuleCard(module: m, store: _data!, onChanged: _changed),
                                  ),
                                ),
                              ),
                          ],
                        );
                        // « Tous » sans recherche : modules rangés par espace de travail
                        if (_category == 'Tous' && q.isEmpty) {
                          return Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              for (final c in cats.skip(2))
                                if (shown.any((m) => '${m['category'] ?? 'Autres'}' == c)) ...[
                                  Padding(
                                    padding: const EdgeInsets.only(top: 8, bottom: 10),
                                    child: Row(
                                      children: [
                                        Text(tr(c), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                                        const SizedBox(width: 8),
                                        Text(tr('${shown.where((m) => '${m['category'] ?? 'Autres'}' == c).length} module(s)'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
                                      ],
                                    ),
                                  ),
                                  grid(shown.where((m) => '${m['category'] ?? 'Autres'}' == c).toList()),
                                  const SizedBox(height: 14),
                                ],
                            ],
                          );
                        }
                        return grid(shown);
                      },
                    ),
                    const SizedBox(height: 20),
                    _ContactCard(contact: Map<String, dynamic>.from(_data!['contact'])),
                  ],
                ),
              ),
              if (showPlans) _PlanTab(plans: plans, subscription: subscription, store: _data!, onChanged: _changed),
              _Subscriptions(subs: subs, store: _data!, onChanged: _changed),
              _Orders(orders: orders, onChanged: _changed),
            ],
          ),
        ),
      ],
    );
  }
}

const _catOrder = [
  'Pilotage',
  'Ventes & clients',
  'Caisse & restaurant',
  'Achats & stocks',
  'Opérations',
  'Finance',
  'Ressources humaines',
  'Marketing & communication',
  'Juridique',
  'Métiers',
  'Collaboration',
  'Administration',
  'Socle',
];

int? _daysLeft(Map m) => _daysUntil(m['expires_at']);

int? _daysUntil(Object? iso) {
  final e = DateTime.tryParse('${iso ?? ''}')?.toLocal();
  if (e == null) return null;
  final n = DateTime.now();
  return DateTime.utc(e.year, e.month, e.day).difference(DateTime.utc(n.year, n.month, n.day)).inDays;
}

/// Mes abonnements : échéance de chaque module acheté ou à l'essai, renouvellement en un clic.
class _Subscriptions extends StatelessWidget {
  const _Subscriptions({required this.subs, required this.store, required this.onChanged});

  final List<Map<String, dynamic>> subs;
  final Map<String, dynamic> store;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    if (subs.isEmpty) {
      return EmptyState(
        icon: Icons.workspace_premium_outlined,
        title: tr('Aucun abonnement'),
        message: tr('Les modules achetés ou à l\'essai apparaissent ici avec leur échéance et le bouton de renouvellement.'),
      );
    }
    final soon = subs.where((m) => (_daysLeft(m) ?? 999) <= 14).length;
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      children: [
        if (soon > 0)
          AnimatedFadeIn(
            child: Container(
              padding: const EdgeInsets.all(16),
              margin: const EdgeInsets.only(bottom: 14),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(16),
                gradient: const LinearGradient(colors: [Color(0xFFFFF4D6), Color(0xFFFFE7B3)]),
              ),
              child: Row(
                children: [
                  const Icon(Icons.notifications_active, color: Color(0xFFB7791F)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      tr('$soon module(s) arrivent à échéance dans moins de 14 jours. Renouvelez maintenant : la nouvelle période démarre à la fin de l\'actuelle, vous ne perdez aucun jour.'),
                      style: const TextStyle(color: Color(0xFF6B4A0E), fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
            ),
          ),
        for (final (i, m) in subs.indexed)
          AnimatedFadeIn(
            index: i,
            child: Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: HoverLift(
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Builder(
                      builder: (context) {
                        final d = _daysLeft(m);
                        final trial = m['source'] == 'trial';
                        final total = trial ? toDouble(m['trial_days'] == 0 ? 14 : m['trial_days']) : (m['billing'] == 'yearly' ? 365.0 : (m['billing'] == 'one_time' ? 0.0 : 30.0));
                        final ratio = d == null || total == 0 ? 1.0 : (d / total).clamp(0.0, 1.0);
                        final color = d == null ? YColors.success : (d <= 3 ? YColors.danger : (d <= 14 ? YColors.warning : YColors.success));
                        return Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Container(
                                  padding: const EdgeInsets.all(10),
                                  decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
                                  child: Icon(iconFor(m['icon']), color: color),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                                      Text(
                                        tr(
                                          d == null
                                              ? 'Licence définitive'
                                              : '${trial ? 'Essai' : (m['billing'] == 'yearly' ? 'Annuel' : 'Mensuel')} · ${d < 0 ? 'expiré' : (d == 0 ? 'expire aujourd\'hui' : 'expire dans $d jour${d > 1 ? 's' : ''}')} (${dateFr(m['expires_at'])})',
                                        ),
                                        style: TextStyle(color: d != null && d <= 14 ? color : hint, fontWeight: d != null && d <= 14 ? FontWeight.w600 : null),
                                      ),
                                    ],
                                  ),
                                ),
                                if (d != null)
                                  FilledButton.icon(
                                    onPressed: () => showModuleDetail(context, m, store, onChanged),
                                    icon: const Icon(Icons.autorenew, size: 18),
                                    label: Text(tr(trial ? 'S\'abonner' : 'Renouveler')),
                                  ),
                              ],
                            ),
                            if (d != null) ...[
                              const SizedBox(height: 12),
                              TweenAnimationBuilder<double>(
                                tween: Tween(begin: 0, end: ratio),
                                duration: const Duration(milliseconds: 900),
                                curve: Curves.easeOutCubic,
                                builder: (context, v, _) => ClipRRect(
                                  borderRadius: BorderRadius.circular(6),
                                  child: LinearProgressIndicator(value: v, minHeight: 7, color: color, backgroundColor: color.withValues(alpha: .12)),
                                ),
                              ),
                            ],
                          ],
                        );
                      },
                    ),
                  ),
                ),
              ),
            ),
          ),
        const SizedBox(height: 6),
        Text(
          tr(
            'Paiement en ligne (Mobile Money, carte) avec activation immédiate, ou hors ligne (dépôt Mobile Money, virement…) validé par notre équipe. Vous recevez un rappel 7, 3 et 1 jour(s) avant chaque échéance.',
          ),
          style: TextStyle(color: hint, fontSize: 12.5),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Mon abonnement : formule de la société (essai, abonnement, formule gratuite)
// ---------------------------------------------------------------------------

String _pct(Object? v) {
  final d = toDouble(v);
  return number(d, decimals: d == d.roundToDouble() ? 0 : 1);
}

/// Date de fin pertinente de la formule : fin d'essai ou fin d'abonnement.
Object? _subscriptionEnd(Map<String, dynamic> sub) => sub['status'] == 'trial' ? sub['trial_ends_at'] : sub['subscription_ends_at'];

/// Essai en cours, abonnement suspendu ou échéance à moins de 14 jours : il faut agir.
bool _subscriptionNeedsAction(Map<String, dynamic> sub) {
  if (sub.isEmpty || sub['is_free'] == true) return false;
  if (sub['status'] == 'trial' || sub['status'] == 'suspended') return true;
  final d = _daysUntil(_subscriptionEnd(sub));
  return d != null && d <= 14;
}

/// « Crédit parrainage déduit : 5 000 F CFA · Remise : 2 000 F CFA. » (null si rien n'a été déduit).
String? orderSavings(Map<String, dynamic> order) {
  final cur = '${order['currency'] ?? 'XOF'}';
  final credit = toDouble(order['credit_used']);
  final discount = toDouble(order['discount']);
  final parts = [
    if (credit > 0) 'Crédit parrainage déduit : ${money(credit, cur)}',
    if (discount > 0) 'Remise : ${money(discount, cur)}',
  ];
  return parts.isEmpty ? null : '${parts.join(' · ')}.';
}

/// Parrainage dans la boutique : crédit disponible et remise de bienvenue (null pour une licence).
({double available, double welcome}) _referralOf(Map<String, dynamic> store) {
  final r = asMap(store['referral']);
  return (available: toDouble(r['available']), welcome: toDouble(r['welcome_discount']));
}

/// Estimation affichée (le serveur fait le calcul réel) : remise de bienvenue si aucun code promo, puis crédit.
({double total, double welcome, double credit}) _referralEstimate(Map<String, dynamic> store, double amount, {required bool promo, required bool useCredit}) {
  final ref = _referralOf(store);
  var total = amount;
  final welcome = !promo && ref.welcome > 0 && total > 0 ? (total * ref.welcome / 100).roundToDouble() : 0.0;
  total = (total - welcome).clamp(0, double.infinity).toDouble();
  final credit = useCredit && ref.available > 0 ? (ref.available < total ? ref.available : total) : 0.0;
  return (total: total - credit, welcome: welcome, credit: credit);
}

/// Bloc « parrainage » des écrans de paiement : remise de bienvenue et crédit à utiliser.
class _ReferralCheckout extends StatelessWidget {
  const _ReferralCheckout({required this.store, required this.amount, required this.promo, required this.useCredit, required this.onUseCredit, required this.currency, this.showTotal = false});

  final Map<String, dynamic> store;
  final double amount;
  final bool promo;
  final bool useCredit;
  final ValueChanged<bool> onUseCredit;
  final String currency;
  final bool showTotal;

  @override
  Widget build(BuildContext context) {
    final ref = _referralOf(store);
    if (ref.available <= 0 && ref.welcome <= 0) return const SizedBox.shrink();
    final est = _referralEstimate(store, amount, promo: promo, useCredit: useCredit);
    final hint = Theme.of(context).hintColor;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final maxCredit = _referralEstimate(store, amount, promo: promo, useCredit: true).credit;
    return AnimatedFadeIn(
      child: Padding(
        padding: const EdgeInsets.only(top: 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (ref.welcome > 0)
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(Icons.card_giftcard_rounded, size: 16, color: YColors.success),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      tr(promo
                          ? '−${_pct(ref.welcome)} % de bienvenue sur votre premier achat (invitation) · non cumulable avec un code promo'
                          : '−${_pct(ref.welcome)} % de bienvenue sur votre premier achat (invitation)'),
                      style: TextStyle(color: promo ? hint : YColors.success, fontSize: 12.5, fontWeight: FontWeight.w700),
                    ),
                  ),
                ],
              ),
            if (ref.available > 0) ...[
              if (ref.welcome > 0) const SizedBox(height: 10),
              AnimatedContainer(
                duration: const Duration(milliseconds: 220),
                decoration: BoxDecoration(
                  color: useCredit ? YColors.success.withValues(alpha: .08) : (dark ? Colors.white.withValues(alpha: .03) : const Color(0xFFF8FAFD)),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: useCredit ? YColors.success.withValues(alpha: .35) : (dark ? Colors.white12 : const Color(0xFFE2E8F0))),
                ),
                child: SwitchListTile(
                  value: useCredit,
                  onChanged: onUseCredit,
                  dense: true,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  secondary: const Icon(Icons.volunteer_activism_outlined, color: YColors.success),
                  title: Text(tr('Utiliser mon crédit parrainage (${money(ref.available, currency)} disponibles)'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(
                    tr(useCredit
                        ? (maxCredit > 0 ? 'jusqu\'à ${money(maxCredit, currency)} déduits' : 'Rien à déduire sur ce montant')
                        : 'Votre crédit reste disponible pour un prochain achat'),
                    style: TextStyle(color: useCredit && maxCredit > 0 ? YColors.success : hint, fontSize: 12),
                  ),
                ),
              ),
            ],
            if (showTotal && (est.welcome > 0 || est.credit > 0))
              Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Row(
                  children: [
                    Expanded(child: Text(tr('Total estimé à payer'), style: const TextStyle(fontWeight: FontWeight.w700))),
                    TweenAnimationBuilder<double>(
                      tween: Tween(end: est.total),
                      duration: const Duration(milliseconds: 450),
                      curve: Curves.easeOutCubic,
                      builder: (context, v, _) => Text(money(v, currency), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w900)),
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

/// Suite d'une commande : rien à payer (activation immédiate), paiement en ligne ou instructions hors ligne.
Future<void> _afterOrder(NavigatorState nav, Map<String, dynamic> r, Map<String, dynamic> store, Future<void> Function() onChanged) async {
  await onChanged();
  if (!nav.mounted) return;
  nav.pop();
  // Le dialogue est fermé : on continue sur le contexte du navigateur
  final host = nav.context;
  final order = asMap(r['order']);
  final savings = orderSavings(order);
  if (r['activated'] == true) {
    if (host.mounted) toast(host, tr(savings == null ? 'Activé, rien à payer.' : 'Activé, rien à payer. $savings'));
    return;
  }
  if (order.isEmpty || !host.mounted) return;
  if (r['payment_url'] != null) {
    if (savings != null) toast(host, tr(savings));
    await safeLaunchUrl(Uri.parse('${r['payment_url']}'), mode: LaunchMode.externalApplication);
    if (host.mounted) await showPendingPayment(host, order, onChanged);
  } else {
    await showOfflineInstructions(host, order, '${r['instructions'] ?? ''}', List.from(store['bank_accounts'] ?? []), onChanged);
  }
}

class _PlanTab extends StatefulWidget {
  const _PlanTab({required this.plans, required this.subscription, required this.store, required this.onChanged});

  final List<Map<String, dynamic>> plans;
  final Map<String, dynamic> subscription;
  final Map<String, dynamic> store;
  final Future<void> Function() onChanged;

  @override
  State<_PlanTab> createState() => _PlanTabState();
}

class _PlanTabState extends State<_PlanTab> {
  String _billing = 'monthly';
  String? _busy;

  bool get _canBuy => widget.store['can_buy'] == true;

  Future<void> _choose(Map<String, dynamic> p) async {
    if (!_canBuy) return toast(context, tr('Seul un administrateur de votre société peut changer de formule.'), error: true);
    if (p['is_free'] == true) return _switchFree(p);
    await _showPlanCheckout(context, p, widget.store, _billing, widget.onChanged);
  }

  Future<void> _switchFree(Map<String, dynamic> p) async {
    final sub = widget.subscription;
    final paidDays = sub['status'] == 'active' && sub['is_free'] != true ? (_daysUntil(sub['subscription_ends_at']) ?? 0) : 0;
    final ok = await confirm(
      context,
      'Passer à la formule gratuite ?',
      '${paidDays > 0 ? 'Votre abonnement est payé encore $paidDays jour(s) : il repassera tout seul sur la formule gratuite à son échéance, sans rien faire. Si vous changez maintenant, ces jours sont perdus. ' : ''}Votre société passe immédiatement sur la formule « ${p['name']} ». Les modules compris dans votre formule actuelle ne seront plus inclus ; les modules achetés séparément restent actifs jusqu\'à leur échéance.',
      ok: 'Passer à la formule gratuite',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = '${p['code']}');
    try {
      final r = asMap(await context.read<Session>().api.post('store/orders', {'module': 'plan:${p['code']}', 'billing': 'monthly'}));
      await widget.onChanged();
      if (mounted) toast(context, tr(r['activated'] == true ? 'Formule « ${p['name']} » activée, rien à payer.' : 'Demande enregistrée.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final plans = widget.plans;
    final current = plans.where((p) => p['current'] == true).firstOrNull;
    final bestYearly = plans.where((p) => p['is_free'] != true).fold<double>(0, (a, p) => toDouble(p['yearly_discount']) > a ? toDouble(p['yearly_discount']) : a);

    final toggle = SegmentedButton<String>(
      showSelectedIcon: false,
      segments: [
        ButtonSegment(value: 'monthly', icon: const Icon(Icons.calendar_view_month_rounded, size: 18), label: Text(tr('Mensuel'))),
        ButtonSegment(
          value: 'yearly',
          icon: const Icon(Icons.event_available_rounded, size: 18),
          label: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(tr('Annuel')),
              if (bestYearly > 0) ...[
                const SizedBox(width: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                  decoration: BoxDecoration(color: YColors.success, borderRadius: BorderRadius.circular(6)),
                  child: Text('−${_pct(bestYearly)} %', style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w800)),
                ),
              ],
            ],
          ),
        ),
      ],
      selected: {_billing},
      onSelectionChanged: (s) => setState(() => _billing = s.first),
    );

    return RefreshIndicator(
      onRefresh: widget.onChanged,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        children: [
          AnimatedFadeIn(
            child: _CurrentPlanCard(
              subscription: widget.subscription,
              plan: current,
              onRenew: current == null || current['is_free'] == true || !_canBuy ? null : () => _choose(current),
            ),
          ),
          const SizedBox(height: 24),
          Wrap(
            spacing: 16,
            runSpacing: 12,
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(tr('Nos formules'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                  Text(tr('Changez de formule à tout moment, sans engagement'), style: TextStyle(color: hint, fontSize: 12.5)),
                ],
              ),
              toggle,
            ],
          ),
          const SizedBox(height: 16),
          LayoutBuilder(
            builder: (context, box) {
              final maxCols = box.maxWidth > 1150 ? 4 : (box.maxWidth > 850 ? 3 : (box.maxWidth > 560 ? 2 : 1));
              final cols = maxCols.clamp(1, plans.length);
              final rows = <List<Map<String, dynamic>>>[for (var i = 0; i < plans.length; i += cols) plans.sublist(i, (i + cols).clamp(0, plans.length))];
              return Column(
                children: [
                  for (final (r, row) in rows.indexed)
                    Padding(
                      padding: EdgeInsets.only(bottom: r == rows.length - 1 ? 0 : 14),
                      child: IntrinsicHeight(
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            for (var c = 0; c < cols; c++) ...[
                              if (c > 0) const SizedBox(width: 14),
                              Expanded(
                                child: c < row.length
                                    ? AnimatedFadeIn(
                                        index: r * cols + c,
                                        child: HoverLift(
                                          child: _PlanCard(
                                            plan: row[c],
                                            billing: _billing,
                                            busy: _busy == '${row[c]['code']}',
                                            enabled: _canBuy && _busy == null,
                                            onChoose: () => _choose(row[c]),
                                          ),
                                        ),
                                      )
                                    : const SizedBox.shrink(),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                ],
              );
            },
          ),
          const SizedBox(height: 16),
          Text(
            tr(
              'Paiement en ligne (Mobile Money, carte) avec activation immédiate, ou hors ligne (dépôt Mobile Money, virement…) validé par notre équipe. Un renouvellement démarre à la fin de la période en cours : vous ne perdez aucun jour.',
            ),
            style: TextStyle(color: hint, fontSize: 12.5),
          ),
          if (!_canBuy) ...[
            const SizedBox(height: 8),
            Text(tr('Seul un administrateur de votre société peut changer de formule.'), style: TextStyle(color: hint, fontSize: 12.5)),
          ],
        ],
      ),
    );
  }
}

/// Carte « Votre formule » : formule actuelle, statut, échéance et appel à l'action.
class _CurrentPlanCard extends StatelessWidget {
  const _CurrentPlanCard({required this.subscription, required this.plan, this.onRenew});

  final Map<String, dynamic> subscription;
  final Map<String, dynamic>? plan;
  final VoidCallback? onRenew;

  @override
  Widget build(BuildContext context) {
    final sub = subscription;
    final status = '${sub['status'] ?? ''}';
    final free = sub['is_free'] == true;
    final trial = status == 'trial';
    final name = '${asMap(sub['plan'])['name'] ?? plan?['name'] ?? '—'}';
    final end = _subscriptionEnd(sub);
    final d = free ? null : _daysUntil(end);
    final (label, color, icon) = switch (status) {
      _ when free => ('Gratuit', YColors.success, Icons.volunteer_activism_outlined),
      'trial' => ('Essai', YColors.yellow, Icons.timer_outlined),
      'suspended' => ('Suspendu', YColors.danger, Icons.pause_circle_outline),
      _ => ('Actif', const Color(0xFF34D399), Icons.verified_rounded),
    };
    final urgent = _subscriptionNeedsAction(sub);
    final total = trial ? 14.0 : ((d ?? 0) > 31 ? 365.0 : 30.0);
    final ratio = d == null ? 1.0 : (d / total).clamp(0.0, 1.0);
    final barColor = d == null ? const Color(0xFF34D399) : (d <= 3 ? const Color(0xFFFF7A6E) : (d <= 14 ? YColors.yellow : const Color(0xFF34D399)));

    final String when;
    if (free) {
      when = 'Formule gratuite, sans échéance';
    } else if (d == null) {
      when = trial ? 'Période d\'essai en cours' : 'Abonnement actif';
    } else {
      final left = d < 0 ? 'échu depuis ${-d} jour${d < -1 ? 's' : ''}' : (d == 0 ? 'se termine aujourd\'hui' : '$d jour${d > 1 ? 's' : ''} restant${d > 1 ? 's' : ''}');
      when = '${trial ? 'Essai gratuit' : 'Abonnement'} jusqu\'au ${dateFr(end)} · $left';
    }

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF1F4FB8)], begin: Alignment.topLeft, end: Alignment.bottomRight),
        boxShadow: [BoxShadow(color: const Color(0xFF0B1B3A).withValues(alpha: .18), blurRadius: 24, offset: const Offset(0, 10))],
      ),
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                width: 54,
                height: 54,
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(16)),
                child: const Icon(Icons.workspace_premium_rounded, color: YColors.yellow, size: 30),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(tr('Votre formule'), style: const TextStyle(color: Color(0xFFC9D6F5), fontSize: 12.5, fontWeight: FontWeight.w600)),
                    const SizedBox(height: 2),
                    Wrap(
                      spacing: 10,
                      runSpacing: 6,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(tr(name), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800, letterSpacing: -.4)),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                          decoration: BoxDecoration(color: color.withValues(alpha: .18), borderRadius: BorderRadius.circular(20), border: Border.all(color: color.withValues(alpha: .45))),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(icon, size: 14, color: color),
                              const SizedBox(width: 5),
                              Text(tr(label), style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700)),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              if (d != null && d >= 0)
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    TweenAnimationBuilder<double>(
                      tween: Tween(begin: 0, end: d.toDouble()),
                      duration: const Duration(milliseconds: 900),
                      curve: Curves.easeOutCubic,
                      builder: (context, v, _) => Text('${v.round()}', style: TextStyle(color: barColor, fontSize: 30, fontWeight: FontWeight.w900, height: 1)),
                    ),
                    Text(tr(d > 1 ? 'jours restants' : 'jour restant'), style: const TextStyle(color: Color(0xFFC9D6F5), fontSize: 12)),
                  ],
                ),
            ],
          ),
          const SizedBox(height: 14),
          Text(tr(when), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
          if (d != null) ...[
            const SizedBox(height: 10),
            TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: ratio),
              duration: const Duration(milliseconds: 900),
              curve: Curves.easeOutCubic,
              builder: (context, v, _) => ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(value: v, minHeight: 7, color: barColor, backgroundColor: Colors.white.withValues(alpha: .14)),
              ),
            ),
          ],
          AnimatedSize(
            duration: const Duration(milliseconds: 250),
            curve: Curves.easeOutCubic,
            alignment: Alignment.topCenter,
            child: !urgent
                ? const SizedBox(width: double.infinity)
                : Container(
                    margin: const EdgeInsets.only(top: 14),
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: YColors.yellow.withValues(alpha: .14),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: YColors.yellow.withValues(alpha: .4)),
                    ),
                    child: Wrap(
                      spacing: 14,
                      runSpacing: 10,
                      alignment: WrapAlignment.spaceBetween,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 560),
                          child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(Icons.notifications_active_rounded, color: YColors.yellow, size: 20),
                              const SizedBox(width: 10),
                              Flexible(
                                child: Text(
                                  tr(
                                    trial
                                        ? 'Votre essai se termine bientôt. Souscrivez dès maintenant pour garder tous les modules de votre formule sans interruption.'
                                        : (status == 'suspended'
                                              ? 'Votre abonnement est suspendu. Renouvelez-le pour retrouver tous les modules de votre formule.'
                                              : 'Votre abonnement arrive à échéance. Renouvelez maintenant : la nouvelle période démarre à la fin de l\'actuelle.'),
                                  ),
                                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, height: 1.35),
                                ),
                              ),
                            ],
                          ),
                        ),
                        if (onRenew != null)
                          FilledButton.icon(
                            style: FilledButton.styleFrom(backgroundColor: YColors.yellow, foregroundColor: YColors.night),
                            onPressed: onRenew,
                            icon: Icon(trial ? Icons.rocket_launch_rounded : Icons.autorenew_rounded, size: 18),
                            label: Text(tr(trial ? 'Souscrire maintenant' : 'Renouveler maintenant')),
                          ),
                      ],
                    ),
                  ),
          ),
          if (!free) ...[
            const SizedBox(height: 14),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Icon(Icons.info_outline_rounded, size: 16, color: Color(0xFFC9D6F5)),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    tr(
                      'À la fin de l\'essai ou de l\'abonnement, votre société repasse automatiquement sur la formule gratuite. Les modules achetés séparément restent actifs jusqu\'à leur propre échéance.',
                    ),
                    style: const TextStyle(color: Color(0xFFC9D6F5), fontSize: 12.5, height: 1.4),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.plan, required this.billing, required this.busy, required this.enabled, required this.onChoose});

  final Map<String, dynamic> plan;
  final String billing;
  final bool busy, enabled;
  final VoidCallback onChoose;

  Widget _price(BuildContext context) {
    final p = plan;
    final hint = Theme.of(context).hintColor;
    final cur = '${p['currency'] ?? 'XOF'}';
    Widget big(num v) => Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Flexible(
          child: FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(money(v, cur), style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w900, letterSpacing: -.8)),
          ),
        ),
        const SizedBox(width: 4),
        Padding(padding: const EdgeInsets.only(bottom: 4), child: Text(tr('/ mois'), style: TextStyle(color: hint, fontWeight: FontWeight.w600))),
      ],
    );
    if (p['is_free'] == true) {
      return Column(
        key: const ValueKey('free'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(tr('Gratuit'), style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w900, color: YColors.success, letterSpacing: -.8)),
          Text(tr('Sans engagement, sans limite de durée'), style: TextStyle(color: hint, fontSize: 12.5)),
        ],
      );
    }
    if (billing == 'yearly') {
      final saving = toDouble(p['yearly_saving']);
      return Column(
        key: const ValueKey('yearly'),
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          big(toDouble(p['yearly']) / 12),
          Text(tr('soit ${money(p['yearly'] as num?, cur)} par an'), style: TextStyle(color: hint, fontSize: 12.5)),
          if (saving > 0)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                tr('${money(saving, cur)} d\'économie${toDouble(p['yearly_discount']) > 0 ? ' (−${_pct(p['yearly_discount'])} %)' : ''}'),
                style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w700, fontSize: 12.5),
              ),
            ),
        ],
      );
    }
    final intro = toDouble(p['intro_left']) > 0 && toDouble(p['monthly_now']) < toDouble(p['monthly']);
    final left = toDouble(p['intro_left']).round();
    return Column(
      key: const ValueKey('monthly'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        big(toDouble(p['monthly_now'])),
        if (intro)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Wrap(
              spacing: 8,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text(money(p['monthly'] as num?, cur), style: TextStyle(color: hint, fontSize: 12.5, decoration: TextDecoration.lineThrough)),
                StatusChip(
                  '−${_pct(p['intro_discount'])} % ${left > 1 ? 'les $left premiers mois' : 'le premier mois'}',
                  color: YColors.success,
                  icon: Icons.local_offer_outlined,
                ),
              ],
            ),
          )
        else
          Text(tr('Sans engagement, résiliable'), style: TextStyle(color: hint, fontSize: 12.5)),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final p = plan;
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final hint = theme.hintColor;
    final primary = theme.colorScheme.primary;
    final current = p['current'] == true;
    final free = p['is_free'] == true;
    final badge = '${p['badge'] ?? ''}'.trim();
    final highlight = current ? YColors.success : (badge.isNotEmpty ? primary : null);
    final users = (p['max_users'] as num?)?.toInt() ?? 0;
    final modules = (p['modules'] as List? ?? const []).length;

    final Widget button;
    if (free) {
      button = current
          ? OutlinedButton.icon(onPressed: null, icon: const Icon(Icons.check_rounded, size: 18), label: Text(tr('Formule actuelle')))
          : OutlinedButton.icon(
              onPressed: enabled ? onChoose : null,
              icon: busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.south_rounded, size: 18),
              label: Text(tr('Passer à la formule gratuite')),
            );
    } else {
      button = FilledButton.icon(
        style: current ? FilledButton.styleFrom(backgroundColor: YColors.success) : null,
        onPressed: enabled ? onChoose : null,
        icon: Icon(current ? Icons.autorenew_rounded : Icons.arrow_forward_rounded, size: 18),
        label: Text(tr(current ? 'Renouveler' : 'Choisir')),
      );
    }

    return AnimatedContainer(
      duration: const Duration(milliseconds: 250),
      decoration: BoxDecoration(
        color: theme.cardColor,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: highlight ?? (dark ? Colors.white12 : YColors.line), width: highlight != null ? 1.6 : 1),
        boxShadow: highlight == null ? null : [BoxShadow(color: highlight.withValues(alpha: .12), blurRadius: 18, offset: const Offset(0, 8))],
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (highlight != null) Container(height: 4, color: highlight),
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 16, 18, 18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    if (current) const StatusChip('Formule actuelle', color: YColors.success, icon: Icons.check_circle_rounded),
                    if (badge.isNotEmpty) StatusChip(badge, color: YColors.warning, icon: Icons.star_rounded),
                    if (!current && badge.isEmpty) const SizedBox(height: 22),
                  ],
                ),
                const SizedBox(height: 10),
                Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18, letterSpacing: -.3)),
                const SizedBox(height: 10),
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 260),
                  switchInCurve: Curves.easeOutCubic,
                  transitionBuilder: (child, a) => FadeTransition(
                    opacity: a,
                    child: SlideTransition(position: Tween(begin: const Offset(0, .12), end: Offset.zero).animate(a), child: child),
                  ),
                  layoutBuilder: (cur, prev) => Stack(alignment: Alignment.topLeft, children: [...prev, ?cur]),
                  child: _price(context),
                ),
                const SizedBox(height: 14),
                Divider(height: 1, color: dark ? Colors.white10 : YColors.line),
                const SizedBox(height: 12),
                _feature(Icons.group_outlined, users > 0 ? 'Jusqu\'à $users utilisateur${users > 1 ? 's' : ''}' : 'Utilisateurs illimités', primary),
                if (modules > 0) _feature(Icons.extension_outlined, '$modules module${modules > 1 ? 's' : ''} inclus', primary),
                if ('${p['description'] ?? ''}'.trim().isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(tr('${p['description']}'), style: TextStyle(color: hint, fontSize: 13, height: 1.4)),
                ],
              ],
            ),
          ),
          const Spacer(),
          Padding(padding: const EdgeInsets.fromLTRB(18, 0, 18, 18), child: button),
        ],
      ),
    );
  }

  Widget _feature(IconData icon, String text, Color color) => Padding(
    padding: const EdgeInsets.only(bottom: 6),
    child: Row(
      children: [
        Icon(icon, size: 17, color: color),
        const SizedBox(width: 8),
        Expanded(child: Text(tr(text), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5))),
      ],
    ),
  );
}

Future<void> _showPlanCheckout(BuildContext context, Map<String, dynamic> plan, Map<String, dynamic> store, String billing, Future<void> Function() onChanged) {
  return showFormDialog(
    context,
    title: 'Formule ${plan['name']}',
    subtitle: plan['current'] == true ? 'Renouvellement de votre abonnement' : 'Abonnement à une formule',
    icon: Icons.workspace_premium_outlined,
    maxWidth: 620,
    builder: (_) => _PlanCheckout(plan: plan, store: store, billing: billing, onChanged: onChanged),
  );
}

/// Paiement d'une formule : période, moyen de paiement, code promo.
class _PlanCheckout extends StatefulWidget {
  const _PlanCheckout({required this.plan, required this.store, required this.billing, required this.onChanged});

  final Map<String, dynamic> plan;
  final Map<String, dynamic> store;
  final String billing;
  final Future<void> Function() onChanged;

  @override
  State<_PlanCheckout> createState() => _PlanCheckoutState();
}

class _PlanCheckoutState extends State<_PlanCheckout> {
  late String _billing = widget.billing;
  int? _gateway;
  Map<String, dynamic>? _promo;
  bool _busy = false;
  bool _useCredit = true;

  Map<String, dynamic> get p => widget.plan;

  @override
  void initState() {
    super.initState();
    final methods = List.from(widget.store['payment_methods'] ?? []);
    _gateway = methods.isEmpty ? null : asMap(methods.first)['id'] as int?;
  }

  double get _base => _billing == 'yearly' ? toDouble(p['yearly']) : toDouble(p['monthly_now']);

  Future<void> _pay() async {
    if (_gateway == null) return;
    setState(() => _busy = true);
    try {
      final api = context.read<Session>().api;
      final nav = Navigator.of(context);
      final String? promo = _promo == null ? null : '${_promo!['code']}';
      final r = asMap(
        await api.post('store/orders', {'module': 'plan:${p['code']}', 'billing': _billing, 'gateway_id': _gateway, 'promo_code': ?promo, 'use_credit': _useCredit}),
      );
      await _afterOrder(nav, r, widget.store, widget.onChanged);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final hint = theme.hintColor;
    final cur = '${p['currency'] ?? 'XOF'}';
    final methods = [for (final g in (widget.store['payment_methods'] as List? ?? const [])) asMap(g)];
    final canBuy = widget.store['can_buy'] == true;
    final current = p['current'] == true;
    final subtotal = _promo == null ? _base : toDouble(_promo!['total']);
    final est = _referralEstimate(widget.store, subtotal, promo: _promo != null, useCredit: _useCredit);
    final total = est.total;
    final intro = toDouble(p['intro_left']) > 0 && toDouble(p['monthly_now']) < toDouble(p['monthly']);
    final left = toDouble(p['intro_left']).round();

    Widget period(String key, String title, String sub, Color? subColor, num price, String unit) {
      final sel = _billing == key;
      return Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Material(
          color: sel ? theme.colorScheme.primary.withValues(alpha: .08) : theme.cardColor,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: BorderSide(color: sel ? theme.colorScheme.primary : (dark ? Colors.white12 : const Color(0xFFE2E8F0)), width: sel ? 1.6 : 1),
          ),
          child: InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: () => setState(() {
              if (_billing != key) _promo = null;
              _billing = key;
            }),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              child: Row(
                children: [
                  Icon(sel ? Icons.radio_button_checked : Icons.radio_button_off, size: 20, color: sel ? theme.colorScheme.primary : hint),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text(tr(sub), style: TextStyle(color: subColor ?? hint, fontSize: 12)),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(money(price, cur), style: const TextStyle(fontWeight: FontWeight.w800)),
                      Text(tr(unit), style: TextStyle(color: hint, fontSize: 11.5)),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(22, 14, 22, 22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AnimatedFadeIn(
            child: Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(16),
                gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF1F4FB8)], begin: Alignment.topLeft, end: Alignment.bottomRight),
              ),
              child: Row(
                children: [
                  const Icon(Icons.workspace_premium_rounded, color: YColors.yellow, size: 30),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(tr('${p['name']}'), style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800)),
                        if ('${p['description'] ?? ''}'.trim().isNotEmpty)
                          Text(tr('${p['description']}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Color(0xFFC9D6F5), fontSize: 13)),
                      ],
                    ),
                  ),
                  if (current) ...[
                    const SizedBox(width: 8),
                    const StatusChip('Formule actuelle', color: Color(0xFF34D399), icon: Icons.check_circle_rounded),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 18),
          Text(tr('Période'), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          period(
            'monthly',
            'Abonnement mensuel',
            intro ? '−${_pct(p['intro_discount'])} % ${left > 1 ? 'les $left premiers mois' : 'le premier mois'}' : 'Sans engagement, résiliable',
            intro ? YColors.success : null,
            toDouble(p['monthly_now']),
            'par mois',
          ),
          period(
            'yearly',
            'Abonnement annuel',
            toDouble(p['yearly_saving']) > 0
                ? '${money(toDouble(p['yearly_saving']), cur)} d\'économie${toDouble(p['yearly_discount']) > 0 ? ' (−${_pct(p['yearly_discount'])} %)' : ''}'
                : '12 mois en une fois',
            toDouble(p['yearly_saving']) > 0 ? YColors.success : null,
            toDouble(p['yearly']),
            'par an',
          ),
          const SizedBox(height: 8),
          if (methods.isEmpty)
            Text(tr('Aucun moyen de paiement n\'est encore configuré : contactez-nous pour souscrire.'), style: TextStyle(color: hint))
          else ...[
            Text(tr('Paiement'), style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final g in methods)
                  ChoiceChip(
                    avatar: Icon(g['online'] == true ? Icons.bolt : Icons.account_balance_outlined, size: 16),
                    label: Text(tr('${g['label']}')),
                    selected: _gateway == g['id'],
                    onSelected: (_) => setState(() => _gateway = g['id'] as int?),
                  ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(
                children: [
                  Icon(Icons.lock_outline_rounded, size: 14, color: hint),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      tr(
                        methods.firstWhere((g) => g['id'] == _gateway, orElse: () => const {})['online'] == true
                            ? 'Paiement sécurisé · activation immédiate après confirmation'
                            : 'Activation dès réception et vérification de votre paiement',
                      ),
                      style: TextStyle(color: hint, fontSize: 12),
                    ),
                  ),
                ],
              ),
            ),
            if (canBuy) ...[
              const SizedBox(height: 14),
              _PromoField(module: 'plan:${p['code']}', billing: _billing, currency: cur, onChanged: (r) => setState(() => _promo = r)),
              _ReferralCheckout(
                store: widget.store,
                amount: subtotal,
                promo: _promo != null,
                useCredit: _useCredit,
                onUseCredit: (v) => setState(() => _useCredit = v),
                currency: cur,
              ),
            ],
          ],
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              color: dark ? Colors.white.withValues(alpha: .04) : const Color(0xFFF8FAFD),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: dark ? Colors.white12 : const Color(0xFFE6EBF4)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(child: Text(tr('Total à payer'), style: const TextStyle(fontWeight: FontWeight.w700))),
                    if (est.welcome > 0 || est.credit > 0) ...[
                      Text(money(subtotal, cur), style: TextStyle(color: hint, fontSize: 13, decoration: TextDecoration.lineThrough)),
                      const SizedBox(width: 8),
                    ],
                    TweenAnimationBuilder<double>(
                      tween: Tween(end: total),
                      duration: const Duration(milliseconds: 450),
                      curve: Curves.easeOutCubic,
                      builder: (context, v, _) => Text(money(v, cur), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900, letterSpacing: -.5)),
                    ),
                  ],
                ),
                if (est.welcome > 0 || est.credit > 0)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      tr([
                        if (est.welcome > 0) 'Bienvenue −${money(est.welcome, cur)}',
                        if (est.credit > 0) 'crédit parrainage : jusqu\'à ${money(est.credit, cur)} déduits',
                      ].join(' · ')),
                      textAlign: TextAlign.end,
                      style: const TextStyle(color: YColors.success, fontSize: 12, fontWeight: FontWeight.w600),
                    ),
                  ),
              ],
            ),
          ),
          if (current)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(tr('La nouvelle période démarre à la fin de la période en cours : vous ne perdez aucun jour.'), style: TextStyle(color: hint, fontSize: 12)),
            ),
          const SizedBox(height: 16),
          if (!canBuy)
            Text(tr('Seul un administrateur de votre société peut changer de formule.'), style: TextStyle(color: hint))
          else
            FilledButton.icon(
              style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 16)),
              onPressed: _busy || _gateway == null ? null : _pay,
              icon: _busy
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : Icon(total <= 0 ? Icons.check_circle_outline : Icons.shopping_cart_checkout),
              label: Text(tr(total <= 0 ? 'Activer sans frais' : (current ? 'Renouveler · ${money(total, cur)}' : 'Souscrire · ${money(total, cur)}'))),
            ),
        ],
      ),
    );
  }
}

/// Code promo : aperçu de la remise (store/promo) avant de commander.
class _PromoField extends StatefulWidget {
  const _PromoField({required this.module, required this.billing, required this.currency, required this.onChanged, this.licenseId});

  final String module;
  final String billing;
  final String currency;
  final int? licenseId;
  final ValueChanged<Map<String, dynamic>?> onChanged;

  @override
  State<_PromoField> createState() => _PromoFieldState();
}

class _PromoFieldState extends State<_PromoField> {
  final _ctrl = TextEditingController();
  bool _open = false;
  bool _busy = false;
  Map<String, dynamic>? _result;
  String? _error;

  @override
  void didUpdateWidget(_PromoField old) {
    super.didUpdateWidget(old);
    // Autre période ou autre article : le montant change, on recalcule la remise
    if ((old.billing != widget.billing || old.module != widget.module) && (_result != null || _error != null) && _ctrl.text.trim().isNotEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _apply();
      });
    }
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  Future<void> _apply() async {
    final code = _ctrl.text.trim();
    if (code.isEmpty) {
      setState(() => _error = tr('Saisissez un code promo.'));
      return;
    }
    setState(() => (_busy = true, _error = null));
    try {
      final r = asMap(
        await context.read<Session>().api.post('store/promo', {'code': code, 'module': widget.module, 'billing': widget.billing, 'license_id': ?widget.licenseId}),
      );
      if (!mounted) return;
      setState(() => _result = r);
      widget.onChanged(r);
    } catch (e) {
      if (!mounted) return;
      setState(() => (_result = null, _error = errorText(e)));
      widget.onChanged(null);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _clear() {
    _ctrl.clear();
    setState(() => (_result = null, _error = null));
    widget.onChanged(null);
  }

  @override
  Widget build(BuildContext context) {
    final r = _result;
    final cur = widget.currency;
    Widget line(String label, String value, {bool strong = false}) => Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Row(
        children: [
          Expanded(child: Text(tr(label), style: TextStyle(color: YColors.success, fontWeight: strong ? FontWeight.w800 : FontWeight.w600))),
          Text(value, style: TextStyle(color: YColors.success, fontWeight: strong ? FontWeight.w900 : FontWeight.w700, fontSize: strong ? 15.5 : null)),
        ],
      ),
    );

    return AnimatedSize(
      duration: const Duration(milliseconds: 220),
      curve: Curves.easeOutCubic,
      alignment: Alignment.topCenter,
      child: !_open && r == null
          ? Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: () => setState(() => _open = true),
                icon: const Icon(Icons.local_offer_outlined, size: 18),
                label: Text(tr('Vous avez un code promo ?')),
              ),
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(tr('Code promo'), style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: _ctrl,
                        autofocus: r == null,
                        textCapitalization: TextCapitalization.characters,
                        decoration: InputDecoration(
                          isDense: true,
                          hintText: tr('Ex. BIENVENUE'),
                          prefixIcon: const Icon(Icons.local_offer_outlined, size: 18),
                          suffixIcon: r != null || _ctrl.text.isNotEmpty ? IconButton(tooltip: tr('Retirer le code'), onPressed: _clear, icon: const Icon(Icons.close_rounded, size: 18)) : null,
                        ),
                        onSubmitted: (_) => _apply(),
                        onChanged: (_) {
                          if (_result != null || _error != null) {
                            setState(() => (_result = null, _error = null));
                            widget.onChanged(null);
                          } else {
                            setState(() {});
                          }
                        },
                      ),
                    ),
                    const SizedBox(width: 8),
                    FilledButton.tonal(
                      onPressed: _busy ? null : _apply,
                      child: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : Text(tr('Appliquer')),
                    ),
                  ],
                ),
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Icon(Icons.error_outline_rounded, size: 16, color: YColors.danger),
                        const SizedBox(width: 6),
                        Expanded(child: Text(_error!, style: const TextStyle(color: YColors.danger, fontSize: 12.5))),
                      ],
                    ),
                  ),
                if (r != null)
                  AnimatedFadeIn(
                    child: Container(
                      margin: const EdgeInsets.only(top: 10),
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: YColors.success.withValues(alpha: .08),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: YColors.success.withValues(alpha: .3)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              const Icon(Icons.check_circle_rounded, size: 18, color: YColors.success),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  tr('Code ${r['code']} appliqué${'${r['label'] ?? ''}'.isNotEmpty ? ' · ${r['label']}' : ''}'),
                                  style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w800),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          line('Montant', money(toDouble(r['amount']), cur)),
                          line('Remise', '− ${money(toDouble(r['discount']), cur)}'),
                          Divider(height: 14, color: YColors.success.withValues(alpha: .25)),
                          line('Total à payer', money(toDouble(r['total']), cur), strong: true),
                        ],
                      ),
                    ),
                  ),
              ],
            ),
    );
  }
}

/// Packs par département : modules d'un métier achetés ensemble avec une remise.
class _Packs extends StatelessWidget {
  const _Packs({required this.packs, required this.store, required this.onChanged});

  final List packs;
  final Map<String, dynamic> store;
  final Future<void> Function() onChanged;

  void _open(BuildContext context, Map<String, dynamic> p) {
    final mods = List<Map>.from(p['modules'] as List);
    final cur = '${p['currency'] ?? 'XOF'}';
    // Présenté comme un module : même écran d'achat (formule, moyen de paiement)
    showModuleDetail(
      context,
      {
        'key': p['key'],
        'name': p['name'],
        'icon': p['icon'],
        'currency': cur,
        'available': true,
        'active': p['active'],
        'source': p['active'] == true ? 'plan' : null,
        'offers': p['offers'],
        'missing_dependencies': const [],
        'tagline': '−${p['discount']} % par rapport aux modules pris séparément',
        'long_description':
            '${p['description']}\n\nValeur des modules séparés : ${money(p['full_monthly'], cur)} par mois. Tous s\'activent dès le paiement reçu ; les modules déjà compris dans votre formule ne sont pas facturés.',
        'features': [for (final m in mods) '${m['name']}${(p['to_buy'] as List).contains(m['key']) ? '' : ' — déjà compris'}'],
      },
      store,
      onChanged,
    );
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return Padding(
      padding: const EdgeInsets.only(bottom: 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(tr('Packs par département'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(width: 8),
              Text(tr('jusqu\'à −25 %'), style: TextStyle(color: hint, fontSize: 12.5)),
            ],
          ),
          const SizedBox(height: 10),
          SizedBox(
            height: 178,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: packs.length,
              separatorBuilder: (_, _) => const SizedBox(width: 12),
              itemBuilder: (context, i) {
                final p = Map<String, dynamic>.from(packs[i] as Map);
                final cur = '${p['currency'] ?? 'XOF'}';
                final offers = Map<String, dynamic>.from(p['offers'] is Map ? p['offers'] as Map : const {});
                return SizedBox(
                  width: 290,
                  child: Card(
                    clipBehavior: Clip.antiAlias,
                    child: InkWell(
                      onTap: () => _open(context, p),
                      child: Container(
                        decoration: BoxDecoration(
                          gradient: LinearGradient(colors: [YColors.blue.withValues(alpha: .10), Colors.transparent], begin: Alignment.topLeft, end: Alignment.bottomRight),
                        ),
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Icon(iconFor(p['icon']), color: YColors.blue),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    tr('${p['name']}'),
                                    style: const TextStyle(fontWeight: FontWeight.w800),
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                                if (p['active'] == true) const StatusChip('Actif', color: YColors.success) else StatusChip('−${p['discount']} %', color: YColors.warning),
                              ],
                            ),
                            const SizedBox(height: 6),
                            Expanded(
                              child: Text(
                                tr('${p['description']}'),
                                maxLines: 3,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(color: hint, fontSize: 12.5),
                              ),
                            ),
                            Text(tr('${(p['modules'] as List).length} modules'), style: TextStyle(color: hint, fontSize: 12)),
                            const SizedBox(height: 4),
                            if (p['active'] == true)
                              Text(
                                tr(offers['monthly'] != null ? 'Tous actifs · renouvellement groupé ${money(offers['monthly'], cur)} / mois' : 'Compris dans votre formule'),
                                style: const TextStyle(fontWeight: FontWeight.w700),
                                overflow: TextOverflow.ellipsis,
                              )
                            else if (offers['monthly'] != null)
                              Row(
                                children: [
                                  Text(tr('${money(offers['monthly'], cur)} / mois'), style: const TextStyle(fontWeight: FontWeight.w800)),
                                  const SizedBox(width: 8),
                                  if (toDouble(p['full_monthly']) > toDouble(offers['monthly']))
                                    Text(
                                      money(p['full_monthly'], cur),
                                      style: TextStyle(color: hint, fontSize: 12, decoration: TextDecoration.lineThrough),
                                    ),
                                ],
                              )
                            else
                              Text(tr('Sur devis'), style: const TextStyle(fontWeight: FontWeight.w700)),
                          ],
                        ),
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _ModuleCard extends StatelessWidget {
  const _ModuleCard({required this.module, required this.store, required this.onChanged});

  final Map<String, dynamic> module;
  final Map<String, dynamic> store;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final m = module;
    final cur = '${m['currency'] ?? 'XOF'}';
    final offers = Map<String, dynamic>.from(m['offers'] is Map ? m['offers'] as Map : const {});
    final shots = List<String>.from(m['screenshots'] ?? []);
    final hint = Theme.of(context).hintColor;
    final from = offers.containsKey('monthly') ? '${money(offers['monthly'], cur)} / mois' : (offers.containsKey('one_time') ? money(offers['one_time'], cur) : null);

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => showModuleDetail(context, m, store, onChanged),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AspectRatio(
              aspectRatio: 16 / 8,
              child: shots.isNotEmpty ? Image.network(shots.first, fit: BoxFit.cover, alignment: Alignment.topLeft, errorBuilder: (_, _, _) => _Placeholder(m)) : _Placeholder(m),
            ),
            Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(iconFor(m['icon']), color: YColors.blue, size: 20),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                      ),
                      if (m['active'] == true)
                        StatusChip(sourceLabels[m['source']] ?? 'Actif', color: YColors.success)
                      else if (m['available'] != true)
                        StatusChip(tr('Bientôt'), color: YColors.muted),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    tr('${m['tagline'] ?? m['description'] ?? ''}'),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(color: hint),
                  ),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      Expanded(
                        child: m['billing_mode'] == 'free'
                            ? Text(tr('Gratuit'), style: const TextStyle(fontWeight: FontWeight.w800, color: YColors.success))
                            : offers.containsKey('monthly')
                            ? Text.rich(TextSpan(children: [
                                TextSpan(text: money(offers['monthly'] as num, cur), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17)),
                                TextSpan(text: tr(' / mois'), style: TextStyle(color: hint, fontWeight: FontWeight.w600)),
                              ]))
                            : Text(tr(from ?? 'Sur devis'), style: const TextStyle(fontWeight: FontWeight.w800)),
                      ),
                      if (m['tier_label'] != null && m['billing_mode'] != 'free')
                        Padding(
                          padding: const EdgeInsets.only(right: 6),
                          child: StatusChip(
                            tr('${m['tier_label']}'),
                            color: switch (m['tier']) {
                              'essentiel' => YColors.success,
                              'premium' => YColors.warning,
                              _ => YColors.blue,
                            },
                          ),
                        ),
                      if (m['can_trial'] == true) StatusChip(tr('${m['trial_days']} j d\'essai'), color: YColors.blue),
                    ],
                  ),
                  if (m['active'] == true && m['expires_at'] != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(tr('Jusqu\'au ${dateFr(m['expires_at'])}'), style: TextStyle(fontSize: 12, color: hint)),
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

class _Placeholder extends StatelessWidget {
  const _Placeholder(this.m);

  final Map<String, dynamic> m;

  @override
  Widget build(BuildContext context) => Container(
    decoration: const BoxDecoration(
      gradient: LinearGradient(colors: [YColors.night, YColors.nightSoft], begin: Alignment.topLeft, end: Alignment.bottomRight),
    ),
    child: Center(child: Icon(iconFor(m['icon']), size: 56, color: YColors.yellow)),
  );
}

// ---------------------------------------------------------------------------
// Fiche détaillée + achat
// ---------------------------------------------------------------------------

/// Licence pour laquelle la boutique est ouverte (null = l'espace en ligne).
int? storeLicenseId(Map<String, dynamic> store) {
  final l = store['license'];
  return l is Map && l['id'] is int ? l['id'] as int : null;
}

IconData _licenseIcon(String? t) => switch (t) {
  'server' => Icons.dns_outlined,
  'windows' => Icons.desktop_windows_outlined,
  'mac' => Icons.laptop_mac,
  'linux' => Icons.computer,
  _ => Icons.vpn_key_outlined,
};

/// « Acheter pour : » — l'espace en ligne ou l'une des licences (installations auto-hébergées).
class _LicenseTargetBar extends StatelessWidget {
  const _LicenseTargetBar({required this.licenses, required this.selected, required this.current, required this.busy, required this.onChanged});

  final List<Map<String, dynamic>> licenses;
  final int? selected;
  final Map<String, dynamic>? current;
  final bool busy;
  final ValueChanged<int?> onChanged;

  String _label(Map<String, dynamic> l) => 'Licence · ${l['company_name']} (${l['masked_key']})';

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final dark = theme.brightness == Brightness.dark;
    final hint = theme.hintColor;
    final primary = theme.colorScheme.primary;
    final narrow = MediaQuery.sizeOf(context).width < 700;
    final sel = selected == null ? null : (current ?? licenses.where((l) => l['id'] == selected).firstOrNull);
    final label = sel == null ? 'Mon espace en ligne' : _label(sel);

    final picker = PopupMenuButton<int>(
      tooltip: tr('Choisir pour quelle installation acheter'),
      enabled: !busy,
      position: PopupMenuPosition.under,
      initialValue: selected ?? 0,
      onSelected: (v) => onChanged(v == 0 ? null : v),
      itemBuilder: (c) => [
        PopupMenuItem(
          value: 0,
          child: ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.cloud_outlined),
            title: Text(tr('Mon espace en ligne')),
            subtitle: Text(tr('Modules de votre compte sur la plateforme')),
          ),
        ),
        const PopupMenuDivider(),
        for (final l in licenses)
          PopupMenuItem(
            value: l['id'] as int,
            child: ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Icon(_licenseIcon('${l['target']}')),
              title: Text('${l['company_name']}', maxLines: 1, overflow: TextOverflow.ellipsis),
              subtitle: Text(
                tr('${l['masked_key']}${l['status'] == 'unused' ? ' · à installer' : (l['status'] == 'suspended' ? ' · suspendue' : '')}'),
                style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
              ),
            ),
          ),
      ],
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
        decoration: BoxDecoration(
          color: sel == null ? theme.colorScheme.onSurface.withValues(alpha: .04) : primary.withValues(alpha: dark ? .2 : .08),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: sel == null ? (dark ? Colors.white24 : YColors.line) : primary.withValues(alpha: .4)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(sel == null ? Icons.cloud_outlined : _licenseIcon('${sel['target']}'), size: 17, color: sel == null ? hint : primary),
            const SizedBox(width: 8),
            Flexible(
              child: Text(
                tr(label),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(fontWeight: FontWeight.w700, color: sel == null ? null : primary),
              ),
            ),
            const SizedBox(width: 4),
            busy
                ? const Padding(padding: EdgeInsets.all(4), child: SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)))
                : Icon(Icons.arrow_drop_down_rounded, color: sel == null ? hint : primary),
          ],
        ),
      ),
    );

    return Padding(
      padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.shopping_bag_outlined, size: 18, color: hint),
              const SizedBox(width: 8),
              Text(tr('Acheter pour :'), style: TextStyle(color: hint, fontWeight: FontWeight.w600)),
              const SizedBox(width: 10),
              Flexible(child: picker),
            ],
          ),
          AnimatedSize(
            duration: const Duration(milliseconds: 220),
            curve: Curves.easeOutCubic,
            alignment: Alignment.topCenter,
            child: sel == null
                ? const SizedBox(width: double.infinity)
                : Container(
                    margin: const EdgeInsets.only(top: 10),
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    decoration: BoxDecoration(
                      color: primary.withValues(alpha: dark ? .12 : .05),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: primary.withValues(alpha: .14)),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(Icons.sync_rounded, size: 18, color: primary),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            tr(
                              'Les modules achetés ou essayés ici seront activés sur l\'installation « ${sel['company_name']} » lors de sa prochaine vérification (dans les 6 h), et non sur votre espace en ligne.',
                            ),
                            style: TextStyle(fontSize: 12.8, color: dark ? Colors.white70 : YColors.inkSoft, height: 1.4),
                          ),
                        ),
                      ],
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}

/// Petite étiquette « licence » sur une commande.
class _LicenseTag extends StatelessWidget {
  const _LicenseTag({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) {
    final c = Theme.of(context).colorScheme.primary;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(color: c.withValues(alpha: .10), borderRadius: BorderRadius.circular(6)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.vpn_key_outlined, size: 12, color: c),
          const SizedBox(width: 4),
          Text(name, style: TextStyle(color: c, fontSize: 11.5, fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }
}

Future<void> showModuleDetail(BuildContext context, Map<String, dynamic> m, Map<String, dynamic> store, Future<void> Function() onChanged) {
  return showFormDialog(
    context,
    title: '${m['name']}',
    subtitle: 'Boutique de modules',
    icon: Icons.storefront_outlined,
    maxWidth: 1000,
    builder: (c) => _ModuleDetail(module: m, store: store, onChanged: onChanged),
  );
}

class _ModuleDetail extends StatefulWidget {
  const _ModuleDetail({required this.module, required this.store, required this.onChanged});

  final Map<String, dynamic> module;
  final Map<String, dynamic> store;
  final Future<void> Function() onChanged;

  @override
  State<_ModuleDetail> createState() => _ModuleDetailState();
}

class _ModuleDetailState extends State<_ModuleDetail> {
  final _page = PageController();
  int _shot = 0;
  String? _billing;
  int? _gateway;
  bool _busy = false;
  bool _useCredit = true;
  Map<String, dynamic>? _promo;

  Map<String, dynamic> get m => widget.module;

  @override
  void initState() {
    super.initState();
    final offers = Map<String, dynamic>.from(m['offers'] is Map ? m['offers'] as Map : const {});
    _billing = offers.keys.firstOrNull;
    final methods = List.from(widget.store['payment_methods'] ?? []);
    _gateway = methods.isEmpty ? null : methods.first['id'] as int;
  }

  Future<void> _trial() async {
    setState(() => _busy = true);
    try {
      final license = storeLicenseId(widget.store);
      final r = await context.read<Session>().api.post('store/trial/${m['key']}', license == null ? null : {'license_id': license});
      await widget.onChanged();
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Essai activé jusqu\'au ${dateFr(r['expires_at'])}. Le module apparaît dans le menu.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _buy() async {
    if (_billing == null || _gateway == null) return;
    setState(() => _busy = true);
    try {
      final api = context.read<Session>().api;
      final nav = Navigator.of(context);
      final license = storeLicenseId(widget.store);
      final String? promo = _promo == null ? null : '${_promo!['code']}';
      final r = asMap(
        await api.post('store/orders', {
          'module': m['key'],
          'billing': _billing,
          'gateway_id': _gateway,
          'license_id': ?license,
          'promo_code': ?promo,
          if (license == null) 'use_credit': _useCredit,
        }),
      );
      await _afterOrder(nav, r, widget.store, widget.onChanged);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = '${m['currency'] ?? 'XOF'}';
    final shots = List<String>.from(m['screenshots'] ?? []);
    final features = List<String>.from(m['features'] ?? []);
    final offers = Map<String, dynamic>.from(m['offers'] is Map ? m['offers'] as Map : const {});
    final methods = List<Map<String, dynamic>>.from((widget.store['payment_methods'] as List? ?? []).map((e) => Map<String, dynamic>.from(e)));
    final missing = List.from(m['missing_dependencies'] ?? []);
    final canBuy = widget.store['can_buy'] == true;
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final dark = theme.brightness == Brightness.dark;
    final yearlySaving = offers.containsKey('monthly') && offers.containsKey('yearly') ? toDouble(offers['monthly']) * 12 - toDouble(offers['yearly']) : 0.0;
    final wide = MediaQuery.sizeOf(context).width > 900;
    final buyable = m['available'] == true && (m['active'] != true || m['source'] == 'trial' || (m['source'] == 'purchase' && m['billing'] != 'one_time')) && offers.isNotEmpty;
    final price = _billing == null ? null : offers[_billing];
    final monthlyEq = _billing == 'yearly' && price != null ? toDouble(price) / 12 : null;

    // Colonne gauche : présentation du module
    final about = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(18),
          gradient: const LinearGradient(colors: [Color(0xFF0B1B3A), Color(0xFF1F4FB8)], begin: Alignment.topLeft, end: Alignment.bottomRight),
        ),
        child: Row(children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(16)),
            child: Icon(iconFor(m['icon']), color: Colors.white, size: 30),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr('${m['name']}'), style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
              if (m['tagline'] != null) Text(tr('${m['tagline']}'), style: const TextStyle(color: Color(0xFFC9D6F5), fontSize: 13.5)),
              const SizedBox(height: 8),
              Wrap(spacing: 6, runSpacing: 6, children: [
                if (m['active'] == true) _pill(Icons.verified_rounded, sourceLabels[m['source']] ?? 'Actif', const Color(0xFF34D399)),
                if (m['tier_label'] != null && m['billing_mode'] != 'free') _pill(Icons.workspace_premium_outlined, '${m['tier_label']}', const Color(0xFFFFC531)),
                if (m['can_trial'] == true) _pill(Icons.timer_outlined, '${m['trial_days']} jours d\'essai gratuit', Colors.white),
                if (features.isNotEmpty) _pill(Icons.checklist_rounded, '${features.length} fonctionnalités', Colors.white),
              ]),
            ]),
          ),
        ]),
      ),
      const SizedBox(height: 16),
      if (shots.isNotEmpty) ...[
        ClipRRect(
          borderRadius: BorderRadius.circular(14),
          child: AspectRatio(
            aspectRatio: 16 / 10,
            child: Stack(children: [
              PageView(
                controller: _page,
                onPageChanged: (i) => setState(() => _shot = i),
                children: [
                  for (final s in shots)
                    InkWell(
                      onTap: () => showDialog(context: context, builder: (_) => Dialog(child: InteractiveViewer(child: Image.network(s)))),
                      child: Image.network(s, fit: BoxFit.cover, alignment: Alignment.topLeft, errorBuilder: (_, _, _) => _Placeholder(m)),
                    ),
                ],
              ),
              if (shots.length > 1) ...[
                Positioned(left: 6, top: 0, bottom: 0, child: Center(child: IconButton.filledTonal(onPressed: () => _page.previousPage(duration: const Duration(milliseconds: 250), curve: Curves.easeOut), icon: const Icon(Icons.chevron_left)))),
                Positioned(right: 6, top: 0, bottom: 0, child: Center(child: IconButton.filledTonal(onPressed: () => _page.nextPage(duration: const Duration(milliseconds: 250), curve: Curves.easeOut), icon: const Icon(Icons.chevron_right)))),
                Positioned(
                  bottom: 8,
                  left: 0,
                  right: 0,
                  child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                    for (var i = 0; i < shots.length; i++)
                      AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        margin: const EdgeInsets.all(3),
                        width: i == _shot ? 18 : 7,
                        height: 7,
                        decoration: BoxDecoration(borderRadius: BorderRadius.circular(4), color: i == _shot ? Colors.white : Colors.white54),
                      ),
                  ]),
                ),
              ],
            ]),
          ),
        ),
        const SizedBox(height: 16),
      ],
      Text(tr('${m['long_description'] ?? m['description'] ?? ''}'), style: const TextStyle(height: 1.5, fontSize: 14.5)),
      if (features.isNotEmpty) ...[
        const SizedBox(height: 18),
        Text(tr('Ce que vous obtenez'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
        const SizedBox(height: 10),
        LayoutBuilder(builder: (context, c) {
          final cols = c.maxWidth > 520 ? 2 : 1;
          final w = (c.maxWidth - (cols - 1) * 10) / cols;
          return Wrap(spacing: 10, runSpacing: 8, children: [
            for (final f in features)
              SizedBox(
                width: w,
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Container(
                    margin: const EdgeInsets.only(top: 1),
                    padding: const EdgeInsets.all(2),
                    decoration: BoxDecoration(color: YColors.success.withValues(alpha: .14), shape: BoxShape.circle),
                    child: const Icon(Icons.check_rounded, size: 14, color: YColors.success),
                  ),
                  const SizedBox(width: 8),
                  Expanded(child: Text(tr(f), style: const TextStyle(height: 1.35))),
                ]),
              ),
          ]);
        }),
      ],
      if (m['demo_url'] != null && '${m['demo_url']}'.isNotEmpty) ...[
        const SizedBox(height: 12),
        Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('${m['demo_url']}')), icon: const Icon(Icons.play_circle_outline), label: Text(tr('Voir la démonstration')))),
      ],
    ]);

    // Colonne droite : tarif et abonnement
    final panel = Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: dark ? Colors.white.withValues(alpha: .03) : const Color(0xFFF8FAFD),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: dark ? Colors.white12 : const Color(0xFFE6EBF4)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (m['available'] != true)
          Text(tr('Ce module est en préparation. Contactez-nous pour être prévenu de sa sortie ou pour un développement sur mesure.'))
        else if (m['billing_mode'] == 'free')
          Text(tr('Module gratuit, inclus pour toutes les sociétés.'), style: const TextStyle(fontWeight: FontWeight.w700))
        else ...[
          if (m['active'] == true)
            Container(
              margin: const EdgeInsets.only(bottom: 14),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: YColors.success.withValues(alpha: .10), borderRadius: BorderRadius.circular(12)),
              child: Row(children: [
                const Icon(Icons.verified_rounded, color: YColors.success),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    tr('${sourceLabels[m['source']] ?? 'Actif'}${m['expires_at'] != null ? ' · jusqu\'au ${dateFr(m['expires_at'])}' : (m['source'] == 'purchase' && m['billing'] == 'one_time' ? ' · licence définitive' : '')}'),
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
              ]),
            ),
          if (price != null) ...[
            Text(tr(_billing == 'one_time' ? 'Prix' : 'Abonnement'), style: TextStyle(color: hint, fontSize: 12.5, fontWeight: FontWeight.w600)),
            const SizedBox(height: 2),
            Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Flexible(child: FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(money(price as num, cur), style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w900, letterSpacing: -.8)))),
              const SizedBox(width: 6),
              Padding(padding: const EdgeInsets.only(bottom: 6), child: Text(tr(billingLabels[_billing] ?? ''), style: TextStyle(color: hint, fontWeight: FontWeight.w600))),
            ]),
            if (monthlyEq != null) Text(tr('soit ${money(monthlyEq, cur)} par mois'), style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w700, fontSize: 12.5)),
            const SizedBox(height: 14),
          ],
          if (buyable) ...[
            if (missing.isNotEmpty)
              Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(tr('Nécessite d\'abord : ${missing.map((d) => d['name']).join(', ')}.'), style: const TextStyle(color: YColors.danger))),
            Text(tr('Formule'), style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            for (final e in offers.entries)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Material(
                  color: _billing == e.key ? theme.colorScheme.primary.withValues(alpha: .08) : theme.cardColor,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: _billing == e.key ? theme.colorScheme.primary : (dark ? Colors.white12 : const Color(0xFFE2E8F0)), width: _billing == e.key ? 1.6 : 1)),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: () => setState(() {
                      if (_billing != e.key) _promo = null;
                      _billing = e.key;
                    }),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                      child: Row(children: [
                        Icon(_billing == e.key ? Icons.radio_button_checked : Icons.radio_button_off, size: 20, color: _billing == e.key ? theme.colorScheme.primary : hint),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(tr(billingTitles[e.key] ?? e.key), style: const TextStyle(fontWeight: FontWeight.w700)),
                            Text(
                              tr(e.key == 'yearly' && yearlySaving > 0 ? '${money(yearlySaving, cur)} d\'économie' : (e.key == 'one_time' ? 'Payé une fois, le module reste à vous' : 'Sans engagement, résiliable')),
                              style: TextStyle(color: e.key == 'yearly' && yearlySaving > 0 ? YColors.success : hint, fontSize: 12),
                            ),
                          ]),
                        ),
                        Text(money(e.value as num, cur), style: const TextStyle(fontWeight: FontWeight.w800)),
                      ]),
                    ),
                  ),
                ),
              ),
            const SizedBox(height: 6),
            if (methods.isEmpty)
              Text(tr('Aucun moyen de paiement n\'est encore configuré : contactez-nous pour activer ce module.'), style: TextStyle(color: hint))
            else ...[
              Text(tr('Paiement'), style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final g in methods)
                  ChoiceChip(
                    avatar: Icon(g['online'] == true ? Icons.bolt : Icons.account_balance_outlined, size: 16),
                    label: Text(tr('${g['label']}')),
                    selected: _gateway == g['id'],
                    onSelected: (_) => setState(() => _gateway = g['id'] as int),
                  ),
              ]),
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Row(children: [
                  Icon(Icons.lock_outline_rounded, size: 14, color: hint),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      tr(methods.firstWhere((g) => g['id'] == _gateway, orElse: () => {})['online'] == true ? 'Paiement sécurisé · activation immédiate après confirmation' : 'Activation dès réception et vérification de votre paiement'),
                      style: TextStyle(color: hint, fontSize: 12),
                    ),
                  ),
                ]),
              ),
            ],
            if (canBuy && methods.isNotEmpty && _billing != null) ...[
              const SizedBox(height: 14),
              _PromoField(
                module: '${m['key']}',
                billing: _billing!,
                currency: cur,
                licenseId: storeLicenseId(widget.store),
                onChanged: (r) => setState(() => _promo = r),
              ),
              if (price != null)
                _ReferralCheckout(
                  store: widget.store,
                  amount: _promo == null ? toDouble(price) : toDouble(_promo!['total']),
                  promo: _promo != null,
                  useCredit: _useCredit,
                  onUseCredit: (v) => setState(() => _useCredit = v),
                  currency: cur,
                  showTotal: true,
                ),
            ],
            const SizedBox(height: 16),
            if (!canBuy)
              Text(tr('Seul un administrateur de votre société peut commander un module.'), style: TextStyle(color: hint))
            else ...[
              FilledButton.icon(
                style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 16)),
                onPressed: _busy || missing.isNotEmpty || _gateway == null || _billing == null ? null : _buy,
                icon: const Icon(Icons.shopping_cart_checkout),
                label: Text(tr(m['active'] == true ? 'Renouveler' : (_billing == 'one_time' ? 'Acheter' : 'S\'abonner'))),
              ),
              if (m['can_trial'] == true) ...[
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
                  onPressed: _busy || missing.isNotEmpty ? null : _trial,
                  icon: const Icon(Icons.timer_outlined),
                  label: Text(tr('Essayer ${m['trial_days']} jours gratuitement')),
                ),
              ],
            ],
          ],
        ],
      ]),
    );

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(22, 6, 22, 22),
      child: wide
          ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(flex: 3, child: about),
              const SizedBox(width: 20),
              SizedBox(width: 340, child: panel),
            ])
          : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [about, const SizedBox(height: 16), panel]),
    );
  }

  Widget _pill(IconData icon, String text, Color color) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
    decoration: BoxDecoration(color: Colors.white.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
    child: Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 14, color: color),
      const SizedBox(width: 5),
      Text(tr(text), style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
    ]),
  );
}

/// Paiement en ligne lancé : vérifier au retour.
Future<void> showPendingPayment(BuildContext context, Map<String, dynamic> order, Future<void> Function() onChanged) {
  return showDialog(
    context: context,
    builder: (c) {
      var busy = false;
      return StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Commande ${order['number']}')),
          content: Text(tr('La page de paiement s\'est ouverte dans votre navigateur. Une fois le paiement effectué, revenez ici et cliquez sur « Vérifier le paiement ».')),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Plus tard'))),
            FilledButton(
              onPressed: busy
                  ? null
                  : () async {
                      set(() => busy = true);
                      try {
                        final r = await c.read<Session>().api.post('store/orders/${order['id']}/verify');
                        await onChanged();
                        if (!c.mounted) return;
                        if (r['order']['status'] == 'paid') {
                          Navigator.pop(c);
                          toast(context, tr('Paiement confirmé : le module est activé.'));
                        } else {
                          toast(c, tr('Paiement pas encore confirmé par le prestataire. Réessayez dans un instant.'));
                        }
                      } catch (e) {
                        if (c.mounted) toast(c, errorText(e), error: true);
                      } finally {
                        if (c.mounted) set(() => busy = false);
                      }
                    },
              child: Text(tr('Vérifier le paiement')),
            ),
          ],
        ),
      );
    },
  );
}

/// Paiement hors ligne : instructions puis déclaration de la référence.
Future<void> showOfflineInstructions(BuildContext context, Map<String, dynamic> order, String instructions, List banks, Future<void> Function() onChanged) {
  final ref = TextEditingController();
  return showFormDialog(
    context,
    title: tr('Commande ${order['number']}'),
    builder: (c) {
      return ListView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        shrinkWrap: true,
        children: [
          Text(tr('Montant à régler : ${money(order['amount'] as num, '${order['currency'] ?? 'XOF'}')}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          if (orderSavings(order) case final savings?)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(
                children: [
                  const Icon(Icons.savings_outlined, size: 16, color: YColors.success),
                  const SizedBox(width: 6),
                  Expanded(child: Text(tr(savings), style: const TextStyle(color: YColors.success, fontWeight: FontWeight.w600, fontSize: 13))),
                ],
              ),
            ),
          const SizedBox(height: 12),
          if (instructions.isNotEmpty) SelectableText(tr(instructions)),
          for (final b in banks)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: SelectableText(
                tr(
                  [
                    '${b['bank']}${b['holder'] != null ? ' — ${b['holder']}' : ''}',
                    if (b['account'] != null) 'RIB : ${b['account']}',
                    if (b['iban'] != null) 'IBAN : ${b['iban']}',
                    if (b['swift'] != null) 'SWIFT : ${b['swift']}',
                  ].join('\n'),
                ),
              ),
            ),
          const SizedBox(height: 12),
          Text(
            tr('Indiquez la référence ${order['number']} avec votre paiement, puis saisissez ci-dessous la référence de votre virement ou transaction.'),
            style: TextStyle(color: Theme.of(c).hintColor),
          ),
          const SizedBox(height: 12),
          Field(
            label: tr('Référence du paiement'),
            child: TextField(
              controller: ref,
              decoration: InputDecoration(hintText: tr('Ex. numéro de virement, ID de transaction Mobile Money')),
            ),
          ),
          Row(
            children: [
              IconButton(
                tooltip: tr('Copier le numéro de commande'),
                onPressed: () => Clipboard.setData(ClipboardData(text: '${order['number']}')),
                icon: const Icon(Icons.copy),
              ),
              const Spacer(),
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Plus tard'))),
              const SizedBox(width: 8),
              SaveButton(
                onPressed: () async {
                  if (ref.text.trim().isEmpty) return toast(c, tr('Saisissez la référence du paiement.'), error: true);
                  try {
                    await c.read<Session>().api.post('store/orders/${order['id']}/declare', {'reference': ref.text.trim()});
                    await onChanged();
                    if (c.mounted) {
                      Navigator.pop(c);
                      toast(context, tr('Merci ! Le module sera activé dès la vérification de votre paiement.'));
                    }
                  } catch (e) {
                    if (c.mounted) toast(c, errorText(e), error: true);
                  }
                },
                child: Text(tr('J\'ai payé')),
              ),
            ],
          ),
        ],
      );
    },
  );
}

// ---------------------------------------------------------------------------
// Commandes
// ---------------------------------------------------------------------------

class _Orders extends StatelessWidget {
  const _Orders({required this.orders, required this.onChanged});

  final List<Map<String, dynamic>> orders;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    if (orders.isEmpty) return EmptyState(icon: Icons.receipt_long_outlined, title: tr('Aucune commande'), message: tr('Vos achats et abonnements de modules apparaîtront ici.'));
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      children: [
        for (final o in orders)
          Card(
            child: ListTile(
              leading: CircleAvatar(
                backgroundColor: orderStatusColor(o['status']).withValues(alpha: .12),
                child: Icon(Icons.extension, color: orderStatusColor(o['status'])),
              ),
              title: Wrap(
                spacing: 8,
                runSpacing: 4,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  Text(tr('${o['module_name'] ?? o['module_key']} · ${o['billing_label']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  if ('${o['license_name'] ?? ''}'.isNotEmpty) _LicenseTag(name: '${o['license_name']}'),
                ],
              ),
              subtitle: Text(
                tr(
                  [
                    '${o['number']}',
                    dateFr(o['created_at']),
                    '${o['gateway_label'] ?? ''}',
                    if (o['period_end'] != null) 'jusqu\'au ${dateFr(o['period_end'])}',
                  ].where((e) => e.isNotEmpty).join(' · '),
                ),
              ),
              trailing: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(tr(money(o['amount'] as num, '${o['currency'] ?? 'XOF'}')), style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  StatusChip('${o['status_label']}', color: orderStatusColor(o['status'])),
                ],
              ),
              onTap: o['status'] != 'pending'
                  ? null
                  : () => o['gateway_online'] == true ? showPendingPayment(context, o, onChanged) : showOfflineInstructions(context, o, '${o['instructions'] ?? ''}', const [], onChanged),
            ),
          ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Contact
// ---------------------------------------------------------------------------

class _ContactCard extends StatelessWidget {
  const _ContactCard({required this.contact});

  final Map<String, dynamic> contact;

  @override
  Widget build(BuildContext context) {
    return Card(
      color: YColors.night,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Wrap(
          spacing: 24,
          runSpacing: 12,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    tr('${contact['name'] ?? ''}'),
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 18),
                  ),
                  const SizedBox(height: 4),
                  Text(tr('${contact['message'] ?? ''}'), style: const TextStyle(color: Colors.white70)),
                  if (contact['hours'] != null) Text(tr('${contact['hours']}'), style: const TextStyle(color: Colors.white54, fontSize: 12)),
                ],
              ),
            ),
            ..._contactButtons(contact, dark: true),
          ],
        ),
      ),
    );
  }
}

List<Widget> _contactButtons(Map<String, dynamic> c, {bool dark = false}) {
  String digits(String v) => v.replaceAll(RegExp(r'[^0-9+]'), '');
  final style = dark ? FilledButton.styleFrom(backgroundColor: YColors.yellow, foregroundColor: YColors.night) : null;
  return [
    if (c['whatsapp'] != null && '${c['whatsapp']}'.isNotEmpty)
      FilledButton.icon(
        style: style,
        onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/${digits('${c['whatsapp']}').replaceAll('+', '')}'), mode: LaunchMode.externalApplication),
        icon: const Icon(Icons.chat),
        label: Text(tr('WhatsApp ${c['whatsapp']}')),
      ),
    if (c['phone'] != null && '${c['phone']}'.isNotEmpty)
      FilledButton.icon(style: style, onPressed: () => safeLaunchUrl(Uri.parse('tel:${digits('${c['phone']}')}')), icon: const Icon(Icons.call), label: Text(tr('${c['phone']}'))),
    if (c['email'] != null && '${c['email']}'.isNotEmpty)
      FilledButton.icon(style: style, onPressed: () => safeLaunchUrl(Uri.parse('mailto:${c['email']}')), icon: const Icon(Icons.mail_outline), label: Text(tr('${c['email']}'))),
    if (c['website'] != null && '${c['website']}'.isNotEmpty)
      FilledButton.icon(
        style: style,
        onPressed: () => safeLaunchUrl(Uri.parse('${c['website']}'.startsWith('http') ? '${c['website']}' : 'https://${c['website']}'), mode: LaunchMode.externalApplication),
        icon: const Icon(Icons.language),
        label: Text(tr('${c['website']}')),
      ),
  ];
}

Future<void> _contactSheet(BuildContext context, Map<String, dynamic> contact) {
  return showAdaptiveSheet(
    context: context,
    builder: (c) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(tr('${contact['name'] ?? ''}'), style: Theme.of(c).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 4),
            Text(tr('${contact['message'] ?? ''}')),
            if (contact['address'] != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(tr('${contact['address']}'), style: TextStyle(color: Theme.of(c).hintColor)),
              ),
            if (contact['hours'] != null) Text(tr('${contact['hours']}'), style: TextStyle(color: Theme.of(c).hintColor)),
            const SizedBox(height: 16),
            Wrap(spacing: 8, runSpacing: 8, children: _contactButtons(contact)),
            if (_contactButtons(contact).isEmpty) Text(tr('Coordonnées non renseignées par l\'éditeur.'), style: TextStyle(color: Theme.of(c).hintColor)),
          ],
        ),
      ),
    ),
  );
}

/// Forfaits de minutes Yomeet (visio intégrée) : achat mensuel, crédit ajouté dès le paiement reçu.
Future<void> showYomeetMinutesShop(BuildContext context, {Future<void> Function()? onChanged}) => showFormDialog(
  context,
  title: 'Acheter des minutes Yomeet',
  subtitle: 'Visio intégrée à l\'ERP · minutes valables un mois',
  icon: Icons.videocam_rounded,
  maxWidth: 620,
  builder: (_) => _YomeetShop(onChanged: onChanged ?? () async {}),
);

class YomeetQuotaBar extends StatelessWidget {
  const YomeetQuotaBar({super.key, required this.quota, this.onBuy, this.compact = false});

  final Map<String, dynamic> quota;
  final VoidCallback? onBuy;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final pct = ((quota['percent'] as num?) ?? 0).toDouble() / 100;
    final exhausted = quota['exhausted'] == true;
    final color = exhausted ? YColors.danger : (pct > .8 ? YColors.warning : const Color(0xFF7C3AED));
    String n(Object? v) => number((v as num?) ?? 0);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [const Color(0xFF7C3AED).withValues(alpha: .10), const Color(0xFF4F46E5).withValues(alpha: .04)]),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFF7C3AED).withValues(alpha: .22)),
      ),
      child: Row(
        children: [
          const DialogIconBadge(icon: Icon(Icons.videocam_rounded), color: Color(0xFF7C3AED), size: 44),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(tr('Minutes Yomeet ce mois'), style: const TextStyle(fontWeight: FontWeight.w800)),
                    ),
                    Text(
                      tr('${n(quota['used'])} / ${n(quota['allowance'])} min'),
                      style: TextStyle(fontWeight: FontWeight.w800, color: color),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                ClipRRect(
                  borderRadius: BorderRadius.circular(6),
                  child: LinearProgressIndicator(value: pct.clamp(0, 1), minHeight: 8, color: color, backgroundColor: color.withValues(alpha: .15)),
                ),
                const SizedBox(height: 6),
                Text(
                  tr(
                    exhausted
                        ? 'Forfait épuisé : les réunions passent en mode pair-à-pair (${quota['p2p_max'] ?? 6} personnes max) jusqu\'à l\'achat de minutes.'
                        : '${n(quota['remaining'])} min restantes · ${n(quota['included'])} incluses${(quota['purchased'] as num? ?? 0) > 0 ? ' + ${n(quota['purchased'])} achetées' : ''}',
                  ),
                  style: TextStyle(color: exhausted ? YColors.danger : hint, fontSize: 12.5),
                ),
              ],
            ),
          ),
          if (onBuy != null) ...[
            const SizedBox(width: 14),
            compact
                ? IconButton.filled(tooltip: tr('Acheter des minutes'), onPressed: onBuy, icon: const Icon(Icons.add_shopping_cart_rounded))
                : FilledButton.icon(onPressed: onBuy, icon: const Icon(Icons.add_shopping_cart_rounded, size: 18), label: Text(tr('Acheter des minutes'))),
          ],
        ],
      ),
    );
  }
}

class _YomeetShop extends StatefulWidget {
  const _YomeetShop({required this.onChanged});

  final Future<void> Function() onChanged;

  @override
  State<_YomeetShop> createState() => _YomeetShopState();
}

class _YomeetShopState extends State<_YomeetShop> {
  Map<String, dynamic>? _store;
  String? _pack;
  int? _gateway;
  bool _busy = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.get('store'));
      if (!mounted) return;
      final packs = List.from(r['minute_packs'] ?? []);
      final methods = List.from(r['payment_methods'] ?? []);
      setState(() {
        _store = r;
        _pack = packs.length > 1 ? '${packs[1]['code']}' : (packs.isEmpty ? null : '${packs.first['code']}');
        _gateway = methods.isEmpty ? null : methods.first['id'] as int;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _buy() async {
    if (_pack == null || _gateway == null) return;
    setState(() => _busy = true);
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.post('store/orders', {'module': 'minutes:$_pack', 'billing': 'monthly', 'gateway_id': _gateway}));
      if (!mounted) return;
      final order = Map<String, dynamic>.from(r['order']);
      final nav = Navigator.of(context);
      final banks = List.from(_store!['bank_accounts'] ?? []);
      nav.pop();
      // Le dialogue est fermé : on continue sur le contexte du navigateur
      final host = nav.context;
      if (r['payment_url'] != null) {
        await safeLaunchUrl(Uri.parse(r['payment_url'] as String), mode: LaunchMode.externalApplication);
        if (host.mounted) await showPendingPayment(host, order, widget.onChanged);
      } else if (host.mounted) {
        await showOfflineInstructions(host, order, '${r['instructions'] ?? ''}', banks, widget.onChanged);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Text(errorText(_error!), style: const TextStyle(color: YColors.danger)),
      );
    }
    final s = _store;
    if (s == null) {
      return const Padding(
        padding: EdgeInsets.all(40),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    final hint = Theme.of(context).hintColor;
    final packs = [for (final p in (s['minute_packs'] as List? ?? const [])) Map<String, dynamic>.from(p as Map)];
    final methods = [for (final g in (s['payment_methods'] as List? ?? const [])) Map<String, dynamic>.from(g as Map)];
    final quota = s['visio_quota'] is Map ? Map<String, dynamic>.from(s['visio_quota'] as Map) : null;
    final cur = '${s['currency'] ?? 'XOF'}';
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(22, 8, 22, 22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (quota != null) ...[YomeetQuotaBar(quota: quota), const SizedBox(height: 16)],
          if (packs.isEmpty) Text(tr('Aucun forfait n\'est proposé pour le moment : contactez-nous.'), style: TextStyle(color: hint)),
          LayoutBuilder(
            builder: (context, box) {
              final cols = box.maxWidth > 520 ? 2 : 1;
              final w = (box.maxWidth - (cols - 1) * 10) / cols;
              return Wrap(
                spacing: 10,
                runSpacing: 10,
                children: [
                  for (final (i, p) in packs.indexed)
                    SizedBox(
                      width: w,
                      child: _PackTile(
                        pack: p,
                        currency: cur,
                        selected: _pack == '${p['code']}',
                        popular: i == 1,
                        perMinute: toDouble(p['price']) / ((p['minutes'] as num?) ?? 1),
                        onTap: () => setState(() => _pack = '${p['code']}'),
                      ),
                    ),
                ],
              );
            },
          ),
          const SizedBox(height: 18),
          if (methods.isEmpty)
            Text(tr('Aucun moyen de paiement n\'est encore configuré : contactez-nous.'), style: TextStyle(color: hint))
          else ...[
            Text(tr('Moyen de paiement'), style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final g in methods)
                  ChoiceChip(
                    avatar: Icon(g['online'] == true ? Icons.bolt : Icons.account_balance_outlined, size: 18),
                    label: Text(tr('${g['label']}')),
                    selected: _gateway == g['id'],
                    onSelected: (_) => setState(() => _gateway = g['id'] as int),
                  ),
              ],
            ),
          ],
          const SizedBox(height: 10),
          Text(
            tr(
              'Les minutes s\'ajoutent à celles incluses dans votre abonnement et restent valables un mois après l\'activation. Les réunions en pair-à-pair (petits groupes) ne consomment pas de minutes.',
            ),
            style: TextStyle(color: hint, fontSize: 12),
          ),
          const SizedBox(height: 16),
          if (s['can_buy'] != true)
            Text(tr('Seul un administrateur de votre société peut acheter des minutes.'), style: TextStyle(color: hint))
          else
            FilledButton.icon(
              onPressed: _busy || _pack == null || _gateway == null ? null : _buy,
              icon: const Icon(Icons.shopping_cart_checkout),
              label: Text(tr('Commander ${packs.where((p) => '${p['code']}' == _pack).map((p) => money(p['price'], cur)).firstOrNull ?? ''}')),
            ),
        ],
      ),
    );
  }
}

class _PackTile extends StatelessWidget {
  const _PackTile({required this.pack, required this.currency, required this.selected, required this.popular, required this.perMinute, required this.onTap});

  final Map<String, dynamic> pack;
  final String currency;
  final bool selected, popular;
  final double perMinute;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    const violet = Color(0xFF7C3AED);
    final hint = Theme.of(context).hintColor;
    return Material(
      color: selected ? violet.withValues(alpha: .08) : Theme.of(context).cardColor,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: selected ? violet : Colors.black12, width: selected ? 2 : 1),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Icon(selected ? Icons.radio_button_checked : Icons.radio_button_off, color: selected ? violet : hint),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(tr('${number(pack['minutes'] as num)} minutes'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                        ),
                        if (popular) ...[const SizedBox(width: 6), const StatusChip('Populaire', color: violet)],
                      ],
                    ),
                    Text(tr('${money(pack['price'], currency)} · ${perMinute.toStringAsFixed(perMinute < 10 ? 1 : 0)} $currency / min'), style: TextStyle(color: hint, fontSize: 12.5)),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}


/// Installation chez le client : rien ne s'achète dans cette copie. Les modules se prennent sur le compte Yomara
/// en ligne (« Acheter pour : cette installation ») et arrivent ici à la vérification suivante.
class _SelfHostStore extends StatefulWidget {
  const _SelfHostStore({required this.info, required this.store, required this.onRefresh});

  final Map<String, dynamic> info;
  final Map<String, dynamic> store;
  final Future<void> Function() onRefresh;

  @override
  State<_SelfHostStore> createState() => _SelfHostStoreState();
}

class _SelfHostStoreState extends State<_SelfHostStore> {
  bool _busy = false;

  Future<void> _buy() async {
    final url = '${widget.info['buy_url'] ?? ''}';
    if (url.isEmpty) return;
    if (!await safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication) && mounted) {
      toast(context, 'Ouvrez $url dans votre navigateur.', error: true);
      return;
    }
    final name = '${widget.info['company_name'] ?? ''}';
    if (mounted) toast(context, name.isEmpty ? 'Dans la boutique, choisissez cette installation dans « Acheter pour », puis payez.' : 'Dans la boutique, choisissez « Acheter pour : $name », puis payez.');
  }

  Future<void> _check() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.post('license/check', {});
      await widget.onRefresh();
      if (mounted) toast(context, tr('Modules mis à jour depuis votre compte Yomara.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final linked = widget.info['linked'] == true;
    final server = '${widget.info['server'] ?? ''}'.replaceFirst(RegExp(r'^https?://'), '');
    final active = [for (final m in (widget.store['modules'] as List? ?? const [])) asMap(m)].where((m) => m['active'] == true).toList();
    final nav = ShellNav.of(context);
    final pad = MediaQuery.sizeOf(context).width < 700 ? 16.0 : 24.0;
    return ListView(
      padding: EdgeInsets.fromLTRB(pad, 0, pad, 24),
      children: [
        PageHeader(title: tr('Boutique de modules'), subtitle: tr('Les modules de cette installation s\'achètent sur votre compte Yomara en ligne')),
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(18),
            gradient: const LinearGradient(colors: [Color(0xFF0B1530), Color(0xFF1E3A8A)]),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                const Icon(Icons.storefront_rounded, color: Color(0xFFFFC531), size: 28),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    tr(linked ? 'Achetez en ligne, recevez ici' : 'Reliez d\'abord cette installation à votre compte'),
                    style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800),
                  ),
                ),
              ]),
              const SizedBox(height: 10),
              Text(
                tr(linked
                    ? 'Ouvrez votre compte sur $server, allez dans « Boutique de modules », choisissez « Acheter pour : ${widget.info['company_name'] ?? 'cette installation'} » puis payez. Le module arrive ici à la prochaine vérification (toutes les 6 h) ou tout de suite avec « Vérifier maintenant ».'
                    : 'Cette installation fonctionne gratuitement avec les fonctions de base. Pour lui ajouter des modules, reliez-la à votre compte Yomara (l\'e-mail et le mot de passe de votre compte sur $server), puis achetez-les en ligne.'),
                style: const TextStyle(color: Color(0xFFD5DEF5), height: 1.45),
              ),
              const SizedBox(height: 16),
              Wrap(spacing: 10, runSpacing: 10, children: [
                if (linked) ...[
                  FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: const Color(0xFFFFC531), foregroundColor: const Color(0xFF0B1530)),
                    onPressed: _buy,
                    icon: const Icon(Icons.open_in_new_rounded),
                    label: Text(tr('Acheter sur mon compte Yomara')),
                  ),
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white38)),
                    onPressed: _busy ? null : _check,
                    icon: _busy ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.sync_rounded),
                    label: Text(tr('Vérifier maintenant')),
                  ),
                ] else if (nav?.available.contains('license') == true)
                  FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: const Color(0xFFFFC531), foregroundColor: const Color(0xFF0B1530)),
                    onPressed: () => nav!.go('license'),
                    icon: const Icon(Icons.link_rounded),
                    label: Text(tr('Relier à mon compte Yomara')),
                  ),
              ]),
            ],
          ),
        ),
        const SizedBox(height: 22),
        Text(tr('Modules actifs sur cette installation (${active.length})'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        const SizedBox(height: 10),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final m in active)
              Chip(avatar: const Icon(Icons.check_circle_rounded, size: 18, color: YColors.success), label: Text('${m['name']}')),
          ],
        ),
        const SizedBox(height: 14),
        Text(tr('Pas encore de compte ? Créez-le gratuitement sur $server : le même compte sert à toutes vos installations.'), style: TextStyle(color: hint, fontSize: 13)),
      ],
    );
  }
}
