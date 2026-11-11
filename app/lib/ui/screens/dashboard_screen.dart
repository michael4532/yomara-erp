import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../navigation.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'document_editor.dart';
import 'documents_screen.dart';
import 'insights_view.dart';
import '../widgets/my_day.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Indicateurs mis en avant par défaut (les 4 premiers disponibles), l'utilisateur peut choisir les siens.
const _headline = [
  'ventes:revenue_month',
  'tresorerie:tr_cash',
  'compta:treasury',
  'ventes:unpaid',
  'pos:pos_today',
  'achats:purchases_month',
  'compta:result_ytd',
  'abonnements:ab_mrr',
  'restaurant:rs_covers',
  'immobilier:rent_roll',
  'stock:stock_value',
  'crm:crm_weighted',
];

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

final _todoWords = RegExp(
  r"a valider|a approuver|a traiter|en retard|en attente|a confirmer|a planifier|a facturer|a rapprocher|non lus|a decider|a fournir|a rembourser|a remettre|perimes|depass|a attribuer|surcharge|presque|echeance|renouvellements|en alerte|a ecouler|refusees|nouvelles candidatures|demandes de conge|non justifies|plus de 60 jours|a payer",
);

class _Kpi {
  _Kpi(this.raw) : id = '${raw['module']}:${raw['key']}';

  final Map<String, dynamic> raw;
  final String id;

  String get module => '${raw['module']}';
  String get label => '${raw['label'] ?? ''}';
  double get value => (raw['value'] as num?)?.toDouble() ?? 0;
  String get format => '${raw['format'] ?? 'number'}';
  bool get alert => raw['alert'] == true;
  String get navId => navIdForModule(module);
  NavGroup get group => groupOf(navId);
  bool get isTodo => format != 'percent' && (alert || _todoWords.hasMatch(_fold(label)));

  String text(String currency) => format == 'money' ? money(value, currency) : (format == 'percent' ? '${number(value, decimals: 1)} %' : number(value.round()));
}

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  List<Map<String, dynamic>> _widgets = [];
  String? _cachedAt;
  bool _loading = true;
  bool _offline = false;
  List<String> _pinned = UiPrefs.list('dash_pinned');
  bool _showAllTodo = false;
  Map<String, dynamic>? _insights;

  @override
  void initState() {
    super.initState();
    final cached = LocalDb.isOpen ? LocalDb.state().get('dashboard') as String? : null;
    if (cached != null) {
      final d = jsonDecode(cached);
      _widgets = List<Map<String, dynamic>>.from((d['data'] as List).map((e) => Map<String, dynamic>.from(e)));
      _cachedAt = d['at'];
    }
    final ci = LocalDb.isOpen ? LocalDb.state().get('dashboard_insights') as String? : null;
    if (ci != null) _insights = Map<String, dynamic>.from(jsonDecode(ci));
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final session = context.read<Session>();
      final res = Map<String, dynamic>.from(await session.api.get('dashboard', query: {'branch_id': ?session.currentBranchId}));
      _widgets = List<Map<String, dynamic>>.from((res['data'] as List).map((e) => Map<String, dynamic>.from(e)));
      _cachedAt = DateTime.now().toIso8601String();
      _offline = false;
      if (LocalDb.isOpen) await LocalDb.state().put('dashboard', jsonEncode({'data': _widgets, 'at': _cachedAt}));
      // Tableau de bord analytique : seulement pour qui voit les ventes ou les rapports
      if (!session.hasModule('ventes') || !(session.can('ventes.view') || session.can('rapports.view'))) {
        _insights = null;
      } else {
        try {
          _insights = Map<String, dynamic>.from(await session.api.get('dashboard/insights', query: {'branch_id': ?session.currentBranchId}));
          if (LocalDb.isOpen) await LocalDb.state().put('dashboard_insights', jsonEncode(_insights));
        } on ApiException catch (e) {
          // Pas de droit sur les ventes : tableau de bord simple
          if (!e.isNetwork) _insights = null;
        }
      }
    } on ApiException catch (e) {
      _offline = e.isNetwork;
    } catch (_) {
      // base locale encore en ouverture : les chiffres seront mis en cache au prochain chargement
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  List<_Kpi> get _kpis => [for (final w in _widgets) _Kpi(w)];

  List<_Kpi> _essentials(List<_Kpi> all) {
    final byId = {for (final k in all) k.id: k};
    final chosen = _pinned.where(byId.containsKey).toList();
    var ids = chosen.isNotEmpty ? chosen : _headline.where(byId.containsKey).toList();
    // Une seule trésorerie par défaut (module Trésorerie, sinon celle de la comptabilité)
    if (chosen.isEmpty && ids.contains('tresorerie:tr_cash')) ids = ids.where((i) => i != 'compta:treasury').toList();
    final out = [for (final id in ids) byId[id]!];
    // Pas encore 4 indicateurs : on complète avec les premiers chiffres non « à traiter »
    if (chosen.isEmpty) {
      for (final k in all) {
        if (out.length >= 4) break;
        if (!k.isTodo && !out.contains(k) && k.format != 'percent') out.add(k);
      }
    }
    return out.take(chosen.isEmpty ? 4 : 8).toList();
  }

  Future<void> _customize(List<_Kpi> all) async {
    final res = await showDialog<List<String>>(
      context: context,
      builder: (_) => _CustomizeDialog(all: all, initial: [..._essentials(all).map((k) => k.id)], currency: context.read<Session>().currency),
    );
    if (res == null) return;
    setState(() => _pinned = res);
    UiPrefs.save('dash_pinned', res);
  }

  /// Faits marquants qui défilent dans l'accueil (inspirés des « headlines » des tableaux de bord de Dynamics 365).
  List<String> _headlines(String currency) {
    final i = _insights;
    if (i == null) return const [];
    final out = <String>[];
    final k = asMap(i['kpis']);
    final rev = asMap(k['revenue']);
    if (rev['value'] != null) {
      final ly = rev['vs_last_year'] as num?;
      out.add('Chiffre d\'affaires du mois : ${compactMoney((rev['value'] as num).toDouble(), currency)}${ly != null ? ', ${ly >= 0 ? '+' : ''}${number(ly, decimals: 1)} % sur un an' : ''}.');
    }
    final h = asMap(i['health']);
    if (h['score'] != null) out.add('Score de santé de l\'entreprise : ${h['score']}/100. ${h['summary'] ?? ''}');
    for (final a in (i['alerts'] as List? ?? const []).take(4)) {
      out.add('${(a as Map)['title']} : ${a['text']}');
    }
    final mix = asMap(i['mix']);
    if (mix['fastest'] != null) out.add('Plus forte progression ce mois-ci : ${mix['fastest']}.');
    return out;
  }

  void _go(String navId) {
    final nav = ShellNav.of(context);
    if (nav != null && nav.available.contains(navId)) nav.go(navId);
  }

  List<Widget> _domains(List<_Kpi> rest, double pad, String currency) => [
    if (rest.isNotEmpty)
      Padding(
        padding: EdgeInsets.fromLTRB(pad, 22, pad, 8),
        child: Text(tr('Indicateurs par espace'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
      ),
    if (rest.isNotEmpty)
      Padding(
        padding: EdgeInsets.symmetric(horizontal: pad),
        child: LayoutBuilder(
          builder: (context, c) {
            final groups = <NavGroup, List<_Kpi>>{};
            for (final k in rest) {
              groups.putIfAbsent(k.group, () => []).add(k);
            }
            final ordered = navGroups.where(groups.containsKey).toList();
            final cols = c.maxWidth > 1100
                ? 3
                : c.maxWidth > 640
                ? 2
                : 1;
            final w = (c.maxWidth - (cols - 1) * 14) / cols;
            return Wrap(
              spacing: 14,
              runSpacing: 14,
              children: [
                for (final (i, g) in ordered.indexed)
                  SizedBox(
                    width: w,
                    child: AnimatedFadeIn(
                      index: i,
                      child: _DomainCard(group: g, items: groups[g]!, currency: currency, onTap: (k) => _go(k.navId)),
                    ),
                  ),
              ],
            );
          },
        ),
      ),
  ];

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final sync = context.watch<SyncEngine>();
    final width = MediaQuery.of(context).size.width;
    final pad = width < 700 ? 16.0 : 24.0;
    final all = _kpis;
    final essentials = _essentials(all);
    final todo = all.where((k) => k.isTodo && k.value > 0 && !essentials.contains(k)).toList()..sort((a, b) => (b.alert ? 1 : 0).compareTo(a.alert ? 1 : 0));
    final rest = all.where((k) => !essentials.contains(k) && !todo.contains(k) && !(k.isTodo && k.value == 0)).toList();
    final revenue = all.where((k) => k.raw['series'] != null).firstOrNull;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.only(bottom: 32),
        children: [
          MyDayHero(
            headlines: _headlines(session.currency),
            subtitle: _offline && _cachedAt != null ? 'Hors ligne, chiffres du ${dateTimeFr(_cachedAt)}' : (session.company['name'] as String?),
            actions: [
              if (ShellNav.of(context)?.available.contains('tableaux_excel') == true)
                OutlinedButton.icon(onPressed: () => _go('tableaux_excel'), icon: const Icon(Icons.table_chart_outlined, size: 18), label: Text(tr('Excel'))),
              IconButton.outlined(onPressed: all.isEmpty ? null : () => _customize(all), icon: const Icon(Icons.tune), tooltip: tr('Personnaliser')),
              IconButton.outlined(onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh), tooltip: tr('Actualiser')),
            ],
          ),
          const SizedBox(height: 10),
          Padding(
            padding: EdgeInsets.symmetric(horizontal: pad),
            child: _QuickActions(session: session, go: _go),
          ),
          if (sync.issues.isNotEmpty)
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 12, pad, 0),
              child: _IssuesCard(sync: sync),
            ),
          if (_loading && _widgets.isEmpty)
            const Padding(
              padding: EdgeInsets.all(48),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_insights != null) ...[
            const SizedBox(height: 18),
            InsightsView(data: _insights!, currency: session.currency, onGo: _go, padding: pad),
            if (_pinned.isNotEmpty && essentials.isNotEmpty) ...[
              Padding(
                padding: EdgeInsets.fromLTRB(pad, 22, pad, 10),
                child: Text(tr('Mes indicateurs'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
              ),
              Padding(
                padding: EdgeInsets.symmetric(horizontal: pad),
                child: LayoutBuilder(
                  builder: (context, c) {
                    final cols = c.maxWidth > 900
                        ? 4
                        : c.maxWidth > 300
                        ? 2
                        : 1;
                    final gap = c.maxWidth < 600 ? 10.0 : 14.0;
                    final w = (c.maxWidth - (cols - 1) * gap) / cols;
                    return Wrap(
                      spacing: gap,
                      runSpacing: gap,
                      children: [
                        for (final (i, k) in essentials.indexed)
                          SizedBox(
                            width: w,
                            child: AnimatedFadeIn(
                              index: i,
                              child: HoverLift(
                                child: _HeadlineCard(kpi: k, currency: session.currency, onTap: () => _go(k.navId)),
                              ),
                            ),
                          ),
                      ],
                    );
                  },
                ),
              ),
            ],
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 16, pad, 0),
              child: _TodoCard(items: todo, currency: session.currency, expanded: _showAllTodo, onExpand: () => setState(() => _showAllTodo = !_showAllTodo), onTap: (k) => _go(k.navId)),
            ),
            ..._domains(all.where((k) => !todo.contains(k) && !(k.isTodo && k.value == 0) && !(_pinned.isNotEmpty && essentials.contains(k))).toList(), pad, session.currency),
          ] else ...[
            // L'essentiel
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 16, pad, 0),
              child: LayoutBuilder(
                builder: (context, c) {
                  final cols = c.maxWidth > 900
                      ? 4
                      : c.maxWidth > 300
                      ? 2
                      : 1;
                  final gap = c.maxWidth < 600 ? 10.0 : 14.0;
                  final w = (c.maxWidth - (cols - 1) * gap) / cols;
                  return Wrap(
                    spacing: gap,
                    runSpacing: gap,
                    children: [
                      for (final (i, k) in essentials.indexed)
                        SizedBox(
                          width: w,
                          child: AnimatedFadeIn(
                            index: i,
                            child: HoverLift(
                              child: _HeadlineCard(kpi: k, currency: session.currency, onTap: () => _go(k.navId)),
                            ),
                          ),
                        ),
                    ],
                  );
                },
              ),
            ),
            // Graphique + à traiter
            Padding(
              padding: EdgeInsets.fromLTRB(pad, 16, pad, 0),
              child: LayoutBuilder(
                builder: (context, c) {
                  final chart = revenue == null
                      ? null
                      : SectionCard(
                          title: tr('Chiffre d\'affaires — 6 derniers mois'),
                          child: SizedBox(
                            height: 230,
                            child: _BarChart(series: List<Map<String, dynamic>>.from((revenue.raw['series'] as List).map((e) => Map<String, dynamic>.from(e))), currency: session.currency),
                          ),
                        );
                  final tasks = _TodoCard(items: todo, currency: session.currency, expanded: _showAllTodo, onExpand: () => setState(() => _showAllTodo = !_showAllTodo), onTap: (k) => _go(k.navId));
                  if (c.maxWidth > 980 && chart != null) {
                    return Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(flex: 3, child: chart),
                        const SizedBox(width: 16),
                        Expanded(flex: 2, child: tasks),
                      ],
                    );
                  }
                  return Column(children: [?chart, if (chart != null) const SizedBox(height: 16), tasks]);
                },
              ),
            ),
            ..._domains(rest, pad, session.currency),
          ],
        ],
      ),
    );
  }
}

/// Actions rapides selon les droits et les modules.
class _QuickActions extends StatelessWidget {
  const _QuickActions({required this.session, required this.go});

  final Session session;
  final void Function(String) go;

  @override
  Widget build(BuildContext context) {
    final avail = ShellNav.of(context)?.available ?? const <String>{};
    void editor(DocConfig cfg, String type) => Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => DocumentEditor(config: cfg, initialType: type),
      ),
    );
    final actions = <(IconData, String, VoidCallback)>[
      if (avail.contains('ventes') && session.can(DocConfig.sale.managePerm)) ...[
        (Icons.add_card, 'Nouvelle facture', () => editor(DocConfig.sale, 'invoice')),
        (Icons.request_quote_outlined, 'Nouveau devis', () => editor(DocConfig.sale, 'quote')),
      ],
      if (avail.contains('pos')) (Icons.point_of_sale, 'Caisse', () => go('pos')),
      if (avail.contains('achats') && session.can(DocConfig.purchase.managePerm)) (Icons.post_add, 'Facture fournisseur', () => editor(DocConfig.purchase, 'supplier_invoice')),
      if (avail.contains('contacts')) (Icons.person_add_alt, 'Clients', () => go('contacts')),
      if (avail.contains('tresorerie')) (Icons.savings_outlined, 'Trésorerie', () => go('tresorerie')),
      if (avail.contains('rapports')) (Icons.insights_outlined, 'Rapports', () => go('rapports')),
      if (avail.contains('ia')) (Icons.auto_awesome_outlined, 'Demander à l\'assistant', () => go('ia')),
    ];
    if (actions.isEmpty) return const SizedBox.shrink();
    if (MediaQuery.sizeOf(context).width < 600) {
      const tints = [YColors.blue, Color(0xFF7C3AED), Color(0xFF0E9F6E), Color(0xFFE8890C), Color(0xFF0891B2), Color(0xFFDB2777), Color(0xFF4F46E5), Color(0xFF65A30D)];
      return LayoutBuilder(builder: (context, c) {
        final w = (c.maxWidth - 3 * 8) / 4;
        return Wrap(spacing: 8, runSpacing: 10, children: [
          for (final (i, a) in actions.take(8).indexed)
            SizedBox(
              width: w,
              child: InkWell(
                borderRadius: BorderRadius.circular(14),
                onTap: a.$3,
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Column(children: [
                    Container(
                      width: 50,
                      height: 50,
                      decoration: BoxDecoration(color: tints[i % tints.length].withValues(alpha: .1), borderRadius: BorderRadius.circular(16)),
                      child: Icon(a.$1, color: tints[i % tints.length], size: 24),
                    ),
                    const SizedBox(height: 6),
                    Text(tr(a.$2), textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, height: 1.2)),
                  ]),
                ),
              ),
            ),
        ]);
      });
    }
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          for (final (i, a) in actions.indexed)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: i == 0
                  ? FilledButton.icon(onPressed: a.$3, icon: Icon(a.$1, size: 18), label: Text(tr(a.$2)))
                  : OutlinedButton.icon(onPressed: a.$3, icon: Icon(a.$1, size: 18), label: Text(tr(a.$2))),
            ),
        ],
      ),
    );
  }
}

class _HeadlineCard extends StatelessWidget {
  const _HeadlineCard({required this.kpi, required this.currency, required this.onTap});

  final _Kpi kpi;
  final String currency;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final color = kpi.alert ? YColors.danger : YColors.blue;
    final series = kpi.raw['series'] is List ? [for (final s in kpi.raw['series'] as List) ((s as Map)['value'] as num).toDouble()] : <double>[];
    double? delta;
    if (series.length >= 2 && series[series.length - 2] > 0) delta = (series.last - series[series.length - 2]) * 100 / series[series.length - 2];
    final hint = Theme.of(context).hintColor;
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(10)),
                    child: Icon(iconFor(kpi.raw['icon'] as String?), size: 19, color: color),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      tr(kpi.label),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(color: hint, fontSize: 13, fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: CountUp(
                  value: kpi.value,
                  format: (v) => _Kpi({...kpi.raw, 'value': v}).text(currency),
                  style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, letterSpacing: -.3),
                ),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  if (delta != null) ...[
                    Icon(delta >= 0 ? Icons.trending_up : Icons.trending_down, size: 16, color: delta >= 0 ? YColors.success : YColors.danger),
                    const SizedBox(width: 4),
                    Text(
                      tr('${delta >= 0 ? '+' : ''}${number(delta, decimals: 1)} % vs mois dernier'),
                      style: TextStyle(fontSize: 12, color: delta >= 0 ? YColors.success : YColors.danger, fontWeight: FontWeight.w600),
                    ),
                  ] else
                    Text(tr(kpi.group.label), style: TextStyle(fontSize: 12, color: hint)),
                  const Spacer(),
                  if (series.length > 2) SizedBox(width: 70, height: 24, child: CustomPaint(painter: _Spark(series, color))),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Spark extends CustomPainter {
  _Spark(this.v, this.color);

  final List<double> v;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final mx = v.reduce((a, b) => a > b ? a : b);
    final mn = v.reduce((a, b) => a < b ? a : b);
    final span = (mx - mn) == 0 ? 1 : mx - mn;
    final path = Path();
    for (var i = 0; i < v.length; i++) {
      final x = size.width * i / (v.length - 1);
      final y = size.height - (v[i] - mn) / span * size.height;
      i == 0 ? path.moveTo(x, y) : path.lineTo(x, y);
    }
    canvas.drawPath(
      path,
      Paint()
        ..color = color
        ..strokeWidth = 2
        ..style = PaintingStyle.stroke
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(covariant _Spark old) => old.v != v;
}

class _TodoCard extends StatelessWidget {
  const _TodoCard({required this.items, required this.currency, required this.expanded, required this.onExpand, required this.onTap});

  final List<_Kpi> items;
  final String currency;
  final bool expanded;
  final VoidCallback onExpand;
  final void Function(_Kpi) onTap;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final shown = expanded ? items : items.take(7).toList();
    return SectionCard(
      title: tr('À traiter'),
      trailing: items.isEmpty ? null : StatusChip('${items.length}', color: items.any((k) => k.alert) ? YColors.danger : YColors.warning),
      child: items.isEmpty
          ? Padding(
              padding: const EdgeInsets.symmetric(vertical: 18),
              child: Row(
                children: [
                  const Icon(Icons.check_circle_outline, color: YColors.success),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(tr('Rien d\'urgent : tout est à jour.'), style: TextStyle(color: hint)),
                  ),
                ],
              ),
            )
          : Column(
              children: [
                for (final k in shown)
                  InkWell(
                    borderRadius: BorderRadius.circular(10),
                    onTap: () => onTap(k),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
                      child: Row(
                        children: [
                          Container(
                            width: 8,
                            height: 8,
                            decoration: BoxDecoration(color: k.alert ? YColors.danger : YColors.warning, shape: BoxShape.circle),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(tr(k.label), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5)),
                                Text(tr(k.group.label), style: TextStyle(color: hint, fontSize: 11.5)),
                              ],
                            ),
                          ),
                          Text(
                            tr(k.text(currency)),
                            style: TextStyle(fontWeight: FontWeight.w800, color: k.alert ? YColors.danger : null),
                          ),
                          const SizedBox(width: 4),
                          Icon(Icons.chevron_right, size: 18, color: hint),
                        ],
                      ),
                    ),
                  ),
                if (items.length > 7)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton(onPressed: onExpand, child: Text(tr(expanded ? 'Réduire' : 'Tout voir (${items.length})'))),
                  ),
              ],
            ),
    );
  }
}

class _DomainCard extends StatefulWidget {
  const _DomainCard({required this.group, required this.items, required this.currency, required this.onTap});

  final NavGroup group;
  final List<_Kpi> items;
  final String currency;
  final void Function(_Kpi) onTap;

  @override
  State<_DomainCard> createState() => _DomainCardState();
}

class _DomainCardState extends State<_DomainCard> {
  bool _open = false;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final shown = _open ? widget.items : widget.items.take(3).toList();
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(widget.group.icon, size: 19, color: YColors.blue),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(tr(widget.group.label), style: const TextStyle(fontWeight: FontWeight.w800)),
                ),
              ],
            ),
            const SizedBox(height: 6),
            for (final k in shown)
              InkWell(
                onTap: () => widget.onTap(k),
                borderRadius: BorderRadius.circular(8),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          tr(k.label),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(color: hint, fontSize: 13),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Text(tr(k.text(widget.currency)), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                    ],
                  ),
                ),
              ),
            if (widget.items.length > 3)
              TextButton(
                style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact),
                onPressed: () => setState(() => _open = !_open),
                child: Text(tr(_open ? 'Moins' : '+ ${widget.items.length - 3} autre(s)')),
              ),
          ],
        ),
      ),
    );
  }
}

class _BarChart extends StatelessWidget {
  const _BarChart({required this.series, required this.currency});

  final List<Map<String, dynamic>> series;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final max = series.fold<double>(0, (m, s) => (s['value'] as num).toDouble() > m ? (s['value'] as num).toDouble() : m);
    final hint = Theme.of(context).hintColor;
    return LayoutBuilder(
      builder: (context, c) {
        return Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            for (final s in series)
              Expanded(
                child: Tooltip(
                  message: money(s['value'] as num, currency),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: [
                        Text(
                          tr(_short((s['value'] as num).toDouble())),
                          style: TextStyle(fontSize: 11, color: hint, fontWeight: FontWeight.w600),
                        ),
                        const SizedBox(height: 4),
                        AnimatedContainer(
                          duration: const Duration(milliseconds: 400),
                          height: max == 0 ? 2 : clampSize((s['value'] as num).toDouble() / max * (c.maxHeight - 46), 2, c.maxHeight - 46),
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.bottomCenter,
                              end: Alignment.topCenter,
                              colors: identical(s, series.last) ? [const Color(0xFFFFA928), YColors.yellow] : [YColors.blue, YColors.blue.withValues(alpha: .7)],
                            ),
                            borderRadius: const BorderRadius.vertical(top: Radius.circular(8)),
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(tr('${s['label']}'), style: TextStyle(fontSize: 12, color: hint)),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  static String _short(double v) {
    if (v >= 1e9) return '${number(v / 1e9, decimals: 1)} Md';
    if (v >= 1e6) return '${number(v / 1e6, decimals: 1)} M';
    if (v >= 1e3) return '${number(v / 1e3)} k';
    return number(v);
  }
}

class _IssuesCard extends StatelessWidget {
  const _IssuesCard({required this.sync});

  final SyncEngine sync;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: tr('Alertes de synchronisation'),
      trailing: TextButton(onPressed: sync.clearIssues, child: Text(tr('Effacer'))),
      child: Column(
        children: [
          for (final i in sync.issues.take(5))
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: Icon(i.status == 'conflict' ? Icons.merge_type : Icons.block, color: YColors.warning),
              title: Text(tr(i.message)),
              subtitle: Text(tr('${i.status == 'conflict' ? 'Conflit : la version du serveur a été conservée' : 'Refusé par le serveur'} · ${dateTimeFr(i.at.toIso8601String())}')),
            ),
        ],
      ),
    );
  }
}

/// Choix des indicateurs mis en avant : recherche, filtre par espace, tuiles sélectionnables avec leur valeur actuelle.
class _CustomizeDialog extends StatefulWidget {
  const _CustomizeDialog({required this.all, required this.initial, required this.currency});
  final List<_Kpi> all;
  final List<String> initial;
  final String currency;

  @override
  State<_CustomizeDialog> createState() => _CustomizeDialogState();
}

class _CustomizeDialogState extends State<_CustomizeDialog> {
  static const _max = 8;
  late final List<String> _sel = [...widget.initial];
  String _q = '';
  NavGroup? _group;

  void _toggle(_Kpi k) => setState(() {
    if (_sel.contains(k.id)) {
      _sel.remove(k.id);
    } else if (_sel.length < _max) {
      _sel.add(k.id);
    }
  });

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final dark = t.brightness == Brightness.dark;
    final hint = t.hintColor;
    final primary = t.colorScheme.primary;
    final byId = {for (final k in widget.all) k.id: k};
    final groups = navGroups.where((g) => widget.all.any((k) => k.group == g)).toList();
    final q = _fold(_q.trim());
    final shown = widget.all.where((k) => (_group == null || k.group == _group) && (q.isEmpty || _fold(k.label).contains(q) || _fold(k.group.label).contains(q))).toList();
    final size = MediaQuery.of(context).size;
    return Dialog(
      clipBehavior: Clip.antiAlias,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: 760, maxHeight: size.height * .88),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // En-tête
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 22, 14, 0),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      gradient: const LinearGradient(colors: [Color(0xFF1F6FEB), Color(0xFF6A4BD8)]),
                      borderRadius: BorderRadius.circular(13),
                    ),
                    child: const Icon(Icons.dashboard_customize_outlined, color: Colors.white),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(tr('Personnaliser le tableau de bord'), style: t.dialogTheme.titleTextStyle),
                        const SizedBox(height: 2),
                        Text(tr('Choisissez jusqu\'à $_max indicateurs à mettre en avant. Les tâches restent dans « À traiter ».'), style: TextStyle(color: hint, fontSize: 13)),
                      ],
                    ),
                  ),
                  IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close), tooltip: tr('Fermer')),
                ],
              ),
            ),
            // Sélection
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 16, 24, 0),
              child: Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: primary.withValues(alpha: dark ? .12 : .05),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: primary.withValues(alpha: .15)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Text(
                          tr('Mes indicateurs'),
                          style: TextStyle(fontWeight: FontWeight.w800, color: dark ? Colors.white : YColors.night),
                        ),
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                          decoration: BoxDecoration(color: _sel.length == _max ? YColors.warning : primary, borderRadius: BorderRadius.circular(20)),
                          child: Text(
                            tr('${_sel.length}/$_max'),
                            style: const TextStyle(color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.w800),
                          ),
                        ),
                        const Spacer(),
                        if (_sel.isNotEmpty) TextButton(onPressed: () => setState(_sel.clear), child: Text(tr('Tout retirer'))),
                      ],
                    ),
                    const SizedBox(height: 6),
                    if (_sel.isEmpty)
                      Text(tr('Aucun choix : les indicateurs essentiels sont affichés automatiquement.'), style: TextStyle(color: hint, fontSize: 12.5))
                    else
                      Wrap(
                        spacing: 6,
                        runSpacing: 6,
                        children: [
                          for (final (i, id) in _sel.indexed)
                            if (byId[id] != null)
                              InputChip(
                                avatar: CircleAvatar(
                                  backgroundColor: primary,
                                  child: Text(
                                    tr('${i + 1}'),
                                    style: const TextStyle(color: Colors.white, fontSize: 10.5, fontWeight: FontWeight.w800),
                                  ),
                                ),
                                label: Text(tr(byId[id]!.label), style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600)),
                                onDeleted: () => setState(() => _sel.remove(id)),
                                deleteIcon: const Icon(Icons.close, size: 16),
                                backgroundColor: dark ? Colors.white10 : Colors.white,
                              ),
                        ],
                      ),
                  ],
                ),
              ),
            ),
            // Recherche et espaces
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 14, 24, 0),
              child: TextField(
                autofocus: false,
                onChanged: (v) => setState(() => _q = v),
                decoration: InputDecoration(
                  prefixIcon: const Icon(Icons.search, size: 20),
                  hintText: tr('Rechercher un indicateur (ventes, stock, trésorerie…)'),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide(color: t.dividerColor),
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide(color: dark ? Colors.white12 : YColors.line),
                  ),
                ),
              ),
            ),
            SizedBox(
              height: 50,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.fromLTRB(24, 10, 24, 4),
                children: [
                  for (final g in [null, ...groups])
                    Padding(
                      padding: const EdgeInsets.only(right: 6),
                      child: ChoiceChip(
                        selected: _group == g,
                        onSelected: (_) => setState(() => _group = g),
                        showCheckmark: false,
                        avatar: g == null ? null : Icon(g.icon, size: 16, color: _group == g ? Colors.white : hint),
                        label: Text(
                          tr(g?.label ?? 'Tous'),
                          style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: _group == g ? Colors.white : null),
                        ),
                        selectedColor: primary,
                      ),
                    ),
                ],
              ),
            ),
            // Tuiles
            Flexible(
              child: shown.isEmpty
                  ? Padding(
                      padding: const EdgeInsets.all(32),
                      child: Center(
                        child: Text(tr('Aucun indicateur ne correspond.'), style: TextStyle(color: hint)),
                      ),
                    )
                  : LayoutBuilder(
                      builder: (context, c) {
                        final cols = c.maxWidth > 560 ? 2 : 1;
                        return GridView.builder(
                          shrinkWrap: true,
                          padding: const EdgeInsets.fromLTRB(24, 8, 24, 16),
                          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: cols, mainAxisSpacing: 8, crossAxisSpacing: 8, mainAxisExtent: 68),
                          itemCount: shown.length,
                          itemBuilder: (context, i) {
                            final k = shown[i];
                            final on = _sel.contains(k.id);
                            final full = !on && _sel.length >= _max;
                            final color = k.alert ? YColors.danger : primary;
                            return AnimatedContainer(
                              duration: const Duration(milliseconds: 160),
                              decoration: BoxDecoration(
                                color: on ? primary.withValues(alpha: dark ? .16 : .06) : (dark ? Colors.white.withValues(alpha: .03) : Colors.white),
                                borderRadius: BorderRadius.circular(14),
                                border: Border.all(color: on ? primary : (dark ? Colors.white12 : YColors.line), width: on ? 1.6 : 1),
                              ),
                              child: Material(
                                color: Colors.transparent,
                                child: InkWell(
                                  borderRadius: BorderRadius.circular(14),
                                  onTap: full ? null : () => _toggle(k),
                                  child: Opacity(
                                    opacity: full ? .45 : 1,
                                    child: Padding(
                                      padding: const EdgeInsets.symmetric(horizontal: 12),
                                      child: Row(
                                        children: [
                                          Container(
                                            width: 38,
                                            height: 38,
                                            decoration: BoxDecoration(color: color.withValues(alpha: .1), borderRadius: BorderRadius.circular(11)),
                                            child: Icon(iconFor(k.raw['icon'] as String?), size: 19, color: color),
                                          ),
                                          const SizedBox(width: 12),
                                          Expanded(
                                            child: Column(
                                              mainAxisAlignment: MainAxisAlignment.center,
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              children: [
                                                Text(
                                                  tr(k.label),
                                                  maxLines: 1,
                                                  overflow: TextOverflow.ellipsis,
                                                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5),
                                                ),
                                                const SizedBox(height: 2),
                                                Text(
                                                  tr('${k.text(widget.currency)} · ${k.group.label}'),
                                                  maxLines: 1,
                                                  overflow: TextOverflow.ellipsis,
                                                  style: TextStyle(color: hint, fontSize: 12),
                                                ),
                                              ],
                                            ),
                                          ),
                                          AnimatedSwitcher(
                                            duration: const Duration(milliseconds: 160),
                                            child: on
                                                ? Icon(Icons.check_circle, key: const ValueKey(1), color: primary)
                                                : Icon(Icons.add_circle_outline, key: const ValueKey(0), color: hint.withValues(alpha: .6)),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            );
                          },
                        );
                      },
                    ),
            ),
            // Pied
            Container(
              padding: const EdgeInsets.fromLTRB(16, 12, 24, 16),
              decoration: BoxDecoration(
                border: Border(top: BorderSide(color: dark ? Colors.white10 : YColors.line)),
              ),
              child: Row(
                children: [
                  TextButton.icon(onPressed: () => Navigator.pop(context, <String>[]), icon: const Icon(Icons.restart_alt, size: 18), label: Text(tr('Rétablir par défaut'))),
                  const Spacer(),
                  TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
                  const SizedBox(width: 8),
                  FilledButton.icon(onPressed: () => Navigator.pop(context, _sel), icon: const Icon(Icons.check, size: 18), label: Text(tr('Enregistrer'))),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
