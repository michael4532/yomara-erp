import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import 'admin_common.dart';
import '../../../i18n/i18n.dart';

/// Arrondi à la centaine, comme le serveur (Plan::yearlyPrice / introPrice).
double _round100(double v) => (v / 100).round() * 100.0;

/// Calcul des prix d'une offre, identique à celui du serveur.
({double monthly, double monthlyNow, double intro, double yearly, double saving, double introSaving, bool free}) planOffer({
  required double monthly,
  double yearlyInput = 0,
  double yearlyDiscount = 0,
  double introDiscount = 0,
  int introMonths = 0,
  bool isFree = false,
}) {
  final free = isFree || monthly <= 0;
  if (free) return (monthly: 0, monthlyNow: 0, intro: 0, yearly: 0, saving: 0, introSaving: 0, free: true);
  final yearly = yearlyDiscount > 0 ? _round100(monthly * 12 * (100 - yearlyDiscount) / 100) : (yearlyInput > 0 ? yearlyInput : monthly * 12);
  final hasIntro = introDiscount > 0 && introMonths > 0;
  final intro = hasIntro ? _round100(monthly * (100 - introDiscount) / 100) : monthly;
  final saving = monthly * 12 - yearly;
  return (
    monthly: monthly,
    monthlyNow: intro,
    intro: intro,
    yearly: yearly,
    saving: saving > 0 ? saving : 0,
    introSaving: hasIntro ? (monthly - intro) * introMonths : 0,
    free: false,
  );
}

String _pct(num v) => v == v.roundToDouble() ? '${v.round()}' : v.toStringAsFixed(1).replaceAll('.', ',');

/// Résumé de l'offre affiché sur la carte d'une formule : « −25 % à l'année », « −15 % les 3 premiers mois », « Gratuit ».
List<(String, IconData, Color)> planOfferTags(Map<String, dynamic> p) {
  final offer = p['offer'] is Map ? Map<String, dynamic>.from(p['offer'] as Map) : const <String, dynamic>{};
  final free = p['is_free'] == true || offer['is_free'] == true || toDouble(p['price_monthly']) <= 0;
  if (free) return [('Gratuit', Icons.card_giftcard_rounded, YColors.success)];
  final yd = toDouble(p['yearly_discount'] ?? offer['yearly_discount']);
  final id = toDouble(p['intro_discount'] ?? offer['intro_discount']);
  final im = toDouble(p['intro_months'] ?? offer['intro_months']).round();
  return [
    if (yd > 0) ('−${_pct(yd)} % à l\'année', Icons.event_repeat_rounded, const Color(0xFF7C3AED)),
    if (id > 0 && im > 0) (im == 1 ? '−${_pct(id)} % le premier mois' : '−${_pct(id)} % les $im premiers mois', Icons.rocket_launch_outlined, const Color(0xFFEA580C)),
  ];
}

/// Formules d'abonnement (SaaS) : prix, nombre d'utilisateurs, modules inclus, offres et remises.
class AdminPlansScreen extends StatelessWidget {
  const AdminPlansScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    return AdminLoader(
      path: 'admin/plans',
      builder: (context, d, reload) {
        final plans = List<Map<String, dynamic>>.from((d['data'] as List).map((e) => Map<String, dynamic>.from(e)));
        final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
        return ListView(padding: const EdgeInsets.only(bottom: 32), children: [
          PageHeader(
            title: tr('Formules'),
            subtitle: session.mode == 'saas' ? 'Proposées à l\'inscription et attribuées aux sociétés.' : 'Utilisées en mode SaaS.',
            actions: [FilledButton.icon(onPressed: () => _edit(context, null, reload), icon: const Icon(Icons.add), label: Text(tr('Nouvelle formule')))],
          ),
          if (session.mode == 'saas') Padding(padding: EdgeInsets.fromLTRB(pad, 0, pad, 16), child: const _TrialOfferCard()),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: pad),
            // Cartes en colonnes égales ; une seule colonne pleine largeur sur téléphone
            child: LayoutBuilder(builder: (context, c) {
              final cols = (c.maxWidth / 320).floor().clamp(1, 4);
              final w = (c.maxWidth - (cols - 1) * 12) / cols;
              return Wrap(spacing: 12, runSpacing: 12, children: [
                for (var i = 0; i < plans.length; i++)
                  SizedBox(
                    width: w,
                    child: AnimatedFadeIn(index: i, child: HoverLift(child: _PlanCard(plan: plans[i], onTap: () => _edit(context, plans[i], reload)))),
                  ),
              ]);
            }),
          ),
        ]);
      },
    );
  }

  Future<void> _edit(BuildContext context, Map<String, dynamic>? plan, VoidCallback reload) async {
    final session = context.read<Session>();
    List<Map<String, dynamic>> modules;
    try {
      modules = List<Map<String, dynamic>>.from(((await session.api.get('admin/modules'))['data'] as List).map((e) => Map<String, dynamic>.from(e)))
          .where((m) => m['is_core'] != true)
          .toList();
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!context.mounted) return;

    String n(dynamic v) => v == null ? '' : '${(v as num).round()}';
    String dec(dynamic v) => v == null || toDouble(v) == 0 ? '' : _pct(toDouble(v));
    final currency = '${plan?['currency'] ?? session.currency}';
    final name = TextEditingController(text: '${plan?['name'] ?? ''}');
    final code = TextEditingController(text: '${plan?['code'] ?? ''}');
    final monthly = TextEditingController(text: n(plan?['price_monthly'] ?? 0));
    final yearly = TextEditingController(text: n(plan?['price_yearly'] ?? 0));
    final maxUsers = TextEditingController(text: n(plan?['max_users']));
    final desc = TextEditingController(text: '${plan?['description'] ?? ''}');
    final yearlyDisc = TextEditingController(text: dec(plan?['yearly_discount']));
    final introDisc = TextEditingController(text: dec(plan?['intro_discount']));
    final introMonths = TextEditingController(text: n(plan?['intro_months'] == 0 ? null : plan?['intro_months']));
    final badge = TextEditingController(text: '${plan?['badge'] ?? ''}');
    final selected = Set<String>.from(plan?['modules'] ?? []);
    var active = (plan?['is_active'] as bool?) ?? true;
    var free = (plan?['is_free'] as bool?) ?? false;
    final fmt = [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,]'))];
    final intFmt = [FilteringTextInputFormatter.digitsOnly];

    double pct(TextEditingController c) => toDouble(c.text).clamp(0, 90).toDouble();

    await showFormDialog(context, maxWidth: 680, title: plan == null ? 'Nouvelle formule' : 'Formule ${plan['name']}', builder: (dc) {
      return StatefulBuilder(builder: (dc, setS) {
        void refresh(String _) => setS(() {});
        final sumModules = modules.where((m) => selected.contains(m['key'])).fold<double>(0, (s, m) => s + toDouble(m['price_monthly']));
        final offer = planOffer(
          monthly: toDouble(monthly.text),
          yearlyInput: toDouble(yearly.text),
          yearlyDiscount: pct(yearlyDisc),
          introDiscount: pct(introDisc),
          introMonths: toDouble(introMonths.text).round().clamp(0, 24),
          isFree: free,
        );
        final autoYearly = !free && pct(yearlyDisc) > 0;
        final narrow = MediaQuery.of(dc).size.width < 560;
        Widget pair(List<Widget> fields) => narrow
            ? Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: fields)
            : Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                for (var i = 0; i < fields.length; i++) ...[if (i > 0) const SizedBox(width: 12), Expanded(child: fields[i])],
              ]);

        return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
          pair([
            Field(label: tr('Nom'), child: TextField(controller: name)),
            Field(label: tr('Code'), child: TextField(controller: code, enabled: plan == null)),
          ]),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 220),
            child: free
                ? const SizedBox(key: ValueKey('free'), width: double.infinity)
                : KeyedSubtree(key: const ValueKey('paid'), child: pair([
                    Field(label: tr('Prix / mois'), child: TextField(controller: monthly, inputFormatters: fmt, onChanged: refresh)),
                    Field(
                      label: tr(autoYearly ? 'Prix / an (calculé)' : 'Prix / an'),
                      child: autoYearly
                          ? InputDecorator(
                              decoration: InputDecoration(enabled: false, suffixIcon: Tooltip(message: tr('Calculé d\'après la remise à l\'année'), child: const Icon(Icons.auto_awesome, size: 18))),
                              child: Text(tr(number(offer.yearly))),
                            )
                          : TextField(
                              controller: yearly,
                              inputFormatters: fmt,
                              onChanged: refresh,
                              decoration: InputDecoration(hintText: tr('12 × prix mensuel')),
                            ),
                    ),
                  ])),
          ),
          Field(label: tr('Utilisateurs max'), child: TextField(controller: maxUsers, inputFormatters: intFmt, decoration: InputDecoration(hintText: tr('illimité')))),
          Field(label: tr('Description'), child: TextField(controller: desc)),

          // Offres et remises
          _OfferSection(
            children: [
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                secondary: DialogIconBadge(icon: const Icon(Icons.card_giftcard_rounded), color: YColors.success, size: 38),
                title: Text(tr('Formule gratuite')),
                subtitle: Text(tr('Aucun paiement demandé : prix à 0 et remises ignorées.')),
                value: free,
                onChanged: (v) => setS(() => free = v),
              ),
              AnimatedSize(
                duration: const Duration(milliseconds: 240),
                curve: Curves.easeOutCubic,
                alignment: Alignment.topCenter,
                child: free
                    ? const SizedBox(width: double.infinity)
                    : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                        const SizedBox(height: 8),
                        pair([
                          Field(
                            label: tr('Remise à l\'année (%)'),
                            child: TextField(
                              controller: yearlyDisc,
                              inputFormatters: fmt,
                              onChanged: refresh,
                              decoration: InputDecoration(hintText: tr('ex. 20'), suffixText: '%', helperText: tr('Sur 12 mois, max 90 %')),
                            ),
                          ),
                          Field(
                            label: tr('Badge'),
                            child: TextField(
                              controller: badge,
                              maxLength: 40,
                              onChanged: refresh,
                              decoration: InputDecoration(hintText: tr('ex. Le plus choisi'), counterText: '', helperText: tr('Court texte mis en avant')),
                            ),
                          ),
                        ]),
                        pair([
                          Field(
                            label: tr('Remise de bienvenue (%)'),
                            child: TextField(
                              controller: introDisc,
                              inputFormatters: fmt,
                              onChanged: refresh,
                              decoration: InputDecoration(hintText: tr('ex. 15'), suffixText: '%', helperText: tr('Sur le prix mensuel')),
                            ),
                          ),
                          Field(
                            label: tr('Pendant (mois payés)'),
                            child: TextField(
                              controller: introMonths,
                              inputFormatters: intFmt,
                              onChanged: refresh,
                              decoration: InputDecoration(hintText: tr('ex. 3'), suffixText: tr('mois'), helperText: tr('Premiers mois, max 24')),
                            ),
                          ),
                        ]),
                      ]),
              ),
              const SizedBox(height: 4),
              _OfferPreview(offer: offer, currency: currency, badge: badge.text.trim(), introMonths: toDouble(introMonths.text).round(), introDiscount: pct(introDisc), yearlyDiscount: pct(yearlyDisc)),
            ],
          ),
          const SizedBox(height: 16),
          Text(tr('Modules inclus · valeur à la carte : ${money(sumModules, session.currency)} / mois'), style: const TextStyle(fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 6, children: [
            for (final m in modules)
              FilterChip(
                label: Text(tr('${m['name']}')),
                selected: selected.contains(m['key']),
                onSelected: (v) => setS(() => v ? selected.add(m['key']) : selected.remove(m['key'])),
              ),
          ]),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Proposée à l\'inscription')), value: active, onChanged: (v) => setS(() => active = v)),
          Row(children: [
            if (plan != null)
              TextButton(
                onPressed: () async {
                  try {
                    await session.api.delete('admin/plans/${plan['id']}');
                    reload();
                    if (dc.mounted) Navigator.pop(dc);
                  } catch (e) {
                    if (dc.mounted) toast(dc, errorText(e), error: true);
                  }
                },
                child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
              ),
            const Spacer(),
            FilledButton(
              onPressed: () async {
                final body = {
                  'name': name.text.trim(),
                  if (plan == null) 'code': code.text.trim(),
                  'price_monthly': free ? 0 : toDouble(monthly.text),
                  'price_yearly': free ? 0 : offer.yearly,
                  'max_users': maxUsers.text.trim().isEmpty ? null : toDouble(maxUsers.text).round(),
                  'description': desc.text.trim().isEmpty ? null : desc.text.trim(),
                  'modules': selected.toList(),
                  'is_active': active,
                  'is_free': free,
                  'yearly_discount': free ? 0 : pct(yearlyDisc),
                  'intro_discount': free ? 0 : pct(introDisc),
                  'intro_months': free ? 0 : toDouble(introMonths.text).round().clamp(0, 24),
                  'badge': badge.text.trim().isEmpty ? null : badge.text.trim(),
                };
                try {
                  plan == null ? await session.api.post('admin/plans', body) : await session.api.put('admin/plans/${plan['id']}', body);
                  reload();
                  if (dc.mounted) Navigator.pop(dc);
                } catch (e) {
                  if (dc.mounted) toast(dc, errorText(e), error: true);
                }
              },
              child: Text(tr('Enregistrer')),
            ),
          ]),
        ]);
      });
    });
  }
}

/// Carte d'une formule dans la liste : prix, badge et résumé de l'offre.
class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.plan, required this.onTap});

  final Map<String, dynamic> plan;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final p = plan;
    final cur = '${p['currency'] ?? 'XOF'}';
    final hint = Theme.of(context).hintColor;
    final offer = p['offer'] is Map ? Map<String, dynamic>.from(p['offer'] as Map) : const <String, dynamic>{};
    final tags = planOfferTags(p);
    final free = tags.isNotEmpty && tags.first.$1 == 'Gratuit';
    final monthly = toDouble(p['price_monthly']);
    final monthlyNow = offer.containsKey('monthly_now') ? toDouble(offer['monthly_now']) : monthly;
    final yearly = offer.containsKey('yearly') ? toDouble(offer['yearly']) : toDouble(p['price_yearly']);
    final saving = toDouble(offer['yearly_saving']);
    final badge = '${p['badge'] ?? ''}'.trim();
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr('${p['name']}'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
              if (badge.isNotEmpty) ...[
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(colors: [YColors.yellow, Color(0xFFFFA41B)]),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(tr(badge), style: const TextStyle(color: YColors.night, fontSize: 11.5, fontWeight: FontWeight.w800)),
                ),
                const SizedBox(width: 6),
              ],
              if (p['is_active'] != true) StatusChip(tr('Inactive')),
            ]),
            const SizedBox(height: 8),
            if (free)
              Text(tr('Gratuit'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: YColors.success))
            else ...[
              Wrap(crossAxisAlignment: WrapCrossAlignment.end, spacing: 8, children: [
                Text(tr('${money(monthlyNow, cur)} / mois'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: YColors.blue)),
                if (monthlyNow < monthly)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 2),
                    child: Text(tr(money(monthly, cur)), style: TextStyle(color: hint, decoration: TextDecoration.lineThrough)),
                  ),
              ]),
              Text(tr(saving > 0 ? '${money(yearly, cur)} / an · ${money(saving, cur)} économisés' : '${money(yearly, cur)} / an'), style: TextStyle(color: hint)),
            ],
            if (tags.isNotEmpty) ...[
              const SizedBox(height: 10),
              Wrap(spacing: 6, runSpacing: 6, children: [for (final t in tags) StatusChip(t.$1, color: t.$3, icon: t.$2)]),
            ],
            const SizedBox(height: 10),
            Text(tr(p['max_users'] == null ? 'Utilisateurs illimités' : 'Jusqu\'à ${p['max_users']} utilisateurs')),
            Text(tr('${(p['modules'] as List? ?? []).length} module(s) · ${p['companies']} société(s)')),
          ]),
        ),
      ),
    );
  }
}

/// Bloc « Offres et remises » de l'éditeur.
class _OfferSection extends StatelessWidget {
  const _OfferSection({required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      margin: const EdgeInsets.only(top: 4),
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        color: dark ? Colors.white.withValues(alpha: .03) : const Color(0xFFF7F9FC),
        border: Border.all(color: dark ? Colors.white10 : const Color(0xFFE4E9F2)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          const Icon(Icons.local_offer_outlined, size: 18, color: YColors.blue),
          const SizedBox(width: 8),
          Expanded(child: Text(tr('Offres et remises'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
        ]),
        const SizedBox(height: 4),
        ...children,
      ]),
    );
  }
}

/// Aperçu en direct des prix obtenus (calcul identique au serveur, arrondi à la centaine).
class _OfferPreview extends StatelessWidget {
  const _OfferPreview({
    required this.offer,
    required this.currency,
    required this.badge,
    required this.introMonths,
    required this.introDiscount,
    required this.yearlyDiscount,
  });

  final ({double monthly, double monthlyNow, double intro, double yearly, double saving, double introSaving, bool free}) offer;
  final String currency;
  final String badge;
  final int introMonths;
  final double introDiscount;
  final double yearlyDiscount;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final hasIntro = !offer.free && introDiscount > 0 && introMonths > 0;
    Widget tile(String label, double value, String detail, Color color, {double? strike}) => Container(
          constraints: const BoxConstraints(minWidth: 150),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Theme.of(context).cardColor,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: color.withValues(alpha: .25)),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Text(tr(label), style: TextStyle(color: hint, fontSize: 12)),
            const SizedBox(height: 2),
            TweenAnimationBuilder<double>(
              tween: Tween(end: value),
              duration: const Duration(milliseconds: 380),
              curve: Curves.easeOutCubic,
              builder: (_, v, _) => Text(tr(money(v, currency)), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: color)),
            ),
            if (strike != null && strike > value)
              Text(tr(money(strike, currency)), style: TextStyle(color: hint, fontSize: 12, decoration: TextDecoration.lineThrough)),
            Text(tr(detail), style: TextStyle(color: hint, fontSize: 11.5)),
          ]),
        );

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        Text(tr('APERÇU DES PRIX'), style: TextStyle(color: hint, fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: 1)),
        const Spacer(),
        if (badge.isNotEmpty)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
            decoration: BoxDecoration(gradient: const LinearGradient(colors: [YColors.yellow, Color(0xFFFFA41B)]), borderRadius: BorderRadius.circular(20)),
            child: Text(tr(badge), style: const TextStyle(color: YColors.night, fontSize: 11.5, fontWeight: FontWeight.w800)),
          ),
      ]),
      const SizedBox(height: 8),
      AnimatedSwitcher(
        duration: const Duration(milliseconds: 220),
        child: offer.free
            ? Container(
                key: const ValueKey('free'),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: YColors.success.withValues(alpha: .08), borderRadius: BorderRadius.circular(12)),
                child: Row(children: [
                  const Icon(Icons.card_giftcard_rounded, color: YColors.success, size: 20),
                  const SizedBox(width: 10),
                  Expanded(child: Text(tr('Gratuit : aucun paiement ne sera demandé.'), style: const TextStyle(fontWeight: FontWeight.w600))),
                ]),
              )
            : Wrap(key: const ValueKey('paid'), spacing: 10, runSpacing: 10, children: [
                tile(
                  hasIntro ? 'Mensuel maintenant' : 'Mensuel',
                  offer.monthlyNow,
                  hasIntro
                      ? (introMonths == 1 ? '−${_pct(introDiscount)} % le 1er mois' : '−${_pct(introDiscount)} % les $introMonths premiers mois')
                      : 'Sans remise de bienvenue',
                  YColors.blue,
                  strike: hasIntro ? offer.monthly : null,
                ),
                tile('Total à l\'année', offer.yearly, yearlyDiscount > 0 ? '−${_pct(yearlyDiscount)} % sur 12 mois' : 'Prix annuel saisi', const Color(0xFF7C3AED), strike: offer.saving > 0 ? offer.monthly * 12 : null),
                tile('Économie annuelle', offer.saving, hasIntro ? 'Bienvenue : ${money(offer.introSaving, currency)} offerts' : 'Par rapport à 12 × mensuel', YColors.success),
              ]),
      ),
    ]);
  }
}


/// Essai gratuit : durée standard et offre spéciale limitée dans le temps (ex. 30 jours jusqu'au 31 décembre).
class _TrialOfferCard extends StatefulWidget {
  const _TrialOfferCard();

  @override
  State<_TrialOfferCard> createState() => _TrialOfferCardState();
}

class _TrialOfferCardState extends State<_TrialOfferCard> {
  final _std = TextEditingController();
  final _promo = TextEditingController();
  final _label = TextEditingController();
  DateTime? _from;
  DateTime? _until;
  Map<String, dynamic>? _d;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _std.dispose();
    _promo.dispose();
    _label.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.get('admin/trial-offer'));
      if (!mounted) return;
      setState(() {
        _d = d;
        _std.text = '${d['standard_days'] ?? 14}';
        _promo.text = (d['promo_days'] ?? 0) == 0 ? '' : '${d['promo_days']}';
        _label.text = '${d['promo_label'] ?? ''}';
        _from = DateTime.tryParse('${d['promo_from'] ?? ''}');
        _until = DateTime.tryParse('${d['promo_until'] ?? ''}');
      });
    } catch (_) {}
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final d = Map<String, dynamic>.from(await context.read<Session>().api.put('admin/trial-offer', {
        'standard_days': int.tryParse(_std.text) ?? 14,
        'promo_days': int.tryParse(_promo.text) ?? 0,
        'promo_from': _from == null ? null : isoDate(_from!),
        'promo_until': _until == null ? null : isoDate(_until!),
        'promo_label': _label.text.trim().isEmpty ? null : _label.text.trim(),
      }));
      if (mounted) {
        setState(() => _d = d);
        toast(context, tr('Essai gratuit : ${d['days']} jours pour les nouvelles inscriptions.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pick(bool from) async {
    final d = await showDatePicker(context: context, initialDate: (from ? _from : _until) ?? DateTime.now().add(Duration(days: from ? 0 : 30)), firstDate: DateTime(2024), lastDate: DateTime(2035));
    if (d != null) setState(() => from ? _from = d : _until = d);
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    return SectionCard(
      title: tr('Essai gratuit à l\'inscription'),
      trailing: d == null
          ? null
          : StatusChip(tr(d['promo_active'] == true ? 'Offre spéciale en cours : ${d['days']} jours' : '${d['days']} jours actuellement'), color: d['promo_active'] == true ? YColors.success : YColors.blue),
      // Téléphone : un champ par ligne (dates côte à côte), libellés lisibles en entier
      child: LayoutBuilder(builder: (context, c) {
        final narrow = c.maxWidth < 620;
        final half = narrow ? (c.maxWidth - 14) / 2 : 160.0;
        return Wrap(spacing: 14, runSpacing: 12, crossAxisAlignment: WrapCrossAlignment.end, children: [
        SizedBox(width: narrow ? double.infinity : 180, child: TextField(controller: _std, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Durée standard (jours)')))),
        SizedBox(width: narrow ? double.infinity : 180, child: TextField(controller: _promo, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Offre spéciale (jours)'), hintText: '30'))),
        SizedBox(
          width: half,
          child: InkWell(onTap: () => _pick(true), child: InputDecorator(decoration: InputDecoration(labelText: tr('À partir du')), child: Text(_from == null ? tr('Tout de suite') : dateFr(_from!.toIso8601String())))),
        ),
        SizedBox(
          width: half,
          child: InkWell(onTap: () => _pick(false), child: InputDecorator(decoration: InputDecoration(labelText: tr('Jusqu\'au')), child: Text(_until == null ? tr('Sans fin') : dateFr(_until!.toIso8601String())))),
        ),
        SizedBox(width: narrow ? double.infinity : 260, child: TextField(controller: _label, decoration: InputDecoration(labelText: tr('Nom de l\'offre (bandeau du site)'), hintText: tr('Offre de lancement')))),
        FilledButton.icon(onPressed: _busy ? null : _save, icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer'))),
        Text(tr('L\'offre spéciale s\'applique aux inscriptions faites entre ces dates, puis la durée standard reprend. Un bandeau l\'annonce sur le site.'),
            style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
      ]);
      }),
    );
  }
}
