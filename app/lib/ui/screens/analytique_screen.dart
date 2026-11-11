import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

// ---------------------------------------------------------------------------
// Ventilation analytique : outils partagés (documents, notes de frais)
// ---------------------------------------------------------------------------

bool analyticEnabled(Session s) => s.hasModule('analytique') && LocalDb.hasEntity('an_sections') && LocalDb.hasEntity('an_axes');

List<Map<String, dynamic>> anAxes({bool activeOnly = true}) {
  if (!LocalDb.hasEntity('an_axes')) return [];
  return LocalDb.all('an_axes').where((a) => !activeOnly || a['active'] != false).toList()
    ..sort((a, b) {
      final c = toDouble(a['sort']).compareTo(toDouble(b['sort']));
      return c != 0 ? c : '${a['name']}'.compareTo('${b['name']}');
    });
}

List<Map<String, dynamic>> anSections(String axisId, {bool activeOnly = true}) {
  if (!LocalDb.hasEntity('an_sections')) return [];
  return LocalDb.all('an_sections').where((s) => s['axis_id'] == axisId && (!activeOnly || s['active'] != false)).toList()
    ..sort((a, b) => '${a['code'] ?? 'zzz'} ${a['name']}'.compareTo('${b['code'] ?? 'zzz'} ${b['name']}'));
}

String sectionLabel(Map<String, dynamic>? s) => s == null ? '—' : (s['code'] != null && '${s['code']}'.isNotEmpty ? '${s['code']} · ${s['name']}' : '${s['name']}');

Map<String, double> anDist(dynamic raw) {
  if (raw is! Map) return {};
  return {for (final e in raw.entries) '${e.key}': toDouble(e.value)};
}

/// « Négoce 100 % · Abidjan 50 % · Bouaké 50 % »
String analyticSummary(dynamic raw) {
  final d = anDist(raw);
  if (d.isEmpty) return 'Non ventilé';
  return [
    for (final e in d.entries)
      '${LocalDb.get('an_sections', e.key)?['name'] ?? 'Section supprimée'}${e.value == 100 ? '' : ' ${number(e.value, decimals: e.value == e.value.roundToDouble() ? 0 : 1)} %'}',
  ].join(' · ');
}

/// Boîte de dialogue de ventilation : null = annulé, {} = effacée.
Future<Map<String, double>?> editDistribution(BuildContext context, dynamic current, {String title = 'Ventilation analytique', String? hint}) async {
  final rows = <String, List<(String?, TextEditingController)>>{};
  final d = anDist(current);
  final axes = anAxes();
  for (final a in axes) {
    rows['${a['id']}'] = [
      for (final e in d.entries)
        if (LocalDb.get('an_sections', e.key)?['axis_id'] == a['id']) (e.key, TextEditingController(text: number(e.value, decimals: e.value == e.value.roundToDouble() ? 0 : 2))),
    ];
  }
  return showDialog<Map<String, double>>(
    context: context,
    builder: (dc) => StatefulBuilder(builder: (dc, set) {
      double sum(String axis) => rows[axis]!.fold(0.0, (s, r) => s + toDouble(r.$2.text.replaceAll(',', '.')));
      return YAlertDialog(
        title: Text(tr(title)),
        content: SizedBox(
          width: 520,
          child: axes.isEmpty
              ? Text(tr('Aucun axe analytique : créez-en dans Analytique & budgets › Paramètres.'))
              : SingleChildScrollView(
                  child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    if (hint != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(tr(hint), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 13))),
                    for (final a in axes) ...[
                      Padding(
                        padding: const EdgeInsets.only(top: 10, bottom: 4),
                        child: Row(children: [
                          Text(tr('${a['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                          if (a['required'] == true) Padding(padding: EdgeInsets.only(left: 6), child: StatusChip(tr('obligatoire'), color: YColors.warning)),
                          const Spacer(),
                          Text(tr('${number(sum('${a['id']}'), decimals: 0)} %'), style: TextStyle(color: sum('${a['id']}') > 100.001 ? YColors.danger : Theme.of(dc).hintColor, fontWeight: FontWeight.w600)),
                        ]),
                      ),
                      for (var i = 0; i < rows['${a['id']}']!.length; i++)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 6),
                          child: Row(children: [
                            Expanded(
                              child: DropdownButtonFormField<String>(
                                initialValue: rows['${a['id']}']![i].$1,
                                isExpanded: true,
                                decoration: InputDecoration(isDense: true, hintText: tr('Section')),
                                items: [for (final s in anSections('${a['id']}')) DropdownMenuItem(value: '${s['id']}', child: Text(tr(sectionLabel(s)), overflow: TextOverflow.ellipsis))],
                                onChanged: (v) => set(() => rows['${a['id']}']![i] = (v, rows['${a['id']}']![i].$2)),
                              ),
                            ),
                            const SizedBox(width: 8),
                            SizedBox(
                              width: 80,
                              child: TextField(
                                controller: rows['${a['id']}']![i].$2,
                                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                                textAlign: TextAlign.right,
                                decoration: const InputDecoration(isDense: true, suffixText: '%'),
                                onChanged: (_) => set(() {}),
                              ),
                            ),
                            IconButton(icon: const Icon(Icons.close, size: 18), onPressed: () => set(() => rows['${a['id']}']!.removeAt(i))),
                          ]),
                        ),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton.icon(
                          onPressed: anSections('${a['id']}').isEmpty
                              ? null
                              : () => set(() {
                                    final left = (100 - sum('${a['id']}')).clamp(0, 100).toDouble();
                                    rows['${a['id']}']!.add((null, TextEditingController(text: number(left, decimals: 0))));
                                  }),
                          icon: const Icon(Icons.add, size: 18),
                          label: Text(tr(anSections('${a['id']}').isEmpty ? 'Aucune section sur cet axe' : 'Ajouter une section')),
                        ),
                      ),
                    ],
                  ]),
                ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(dc, <String, double>{}), child: Text(tr('Effacer'))),
          TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))),
          FilledButton(
            onPressed: () {
              final out = <String, double>{};
              for (final a in axes) {
                if (sum('${a['id']}') > 100.001) {
                  toast(dc, tr('La ventilation dépasse 100 % sur l\'axe « ${a['name']} ».'), error: true);
                  return;
                }
                for (final r in rows['${a['id']}']!) {
                  final p = toDouble(r.$2.text.replaceAll(',', '.'));
                  if (r.$1 != null && p > 0) out[r.$1!] = (out[r.$1!] ?? 0) + p;
                }
              }
              Navigator.pop(dc, out);
            },
            child: Text(tr('OK')),
          ),
        ],
      );
    }),
  );
}

/// Champ « Analytique » : résumé cliquable + budget restant (achats, notes de frais).
class AnalyticField extends StatefulWidget {
  const AnalyticField({super.key, required this.value, required this.onChanged, this.enabled = true, this.showBudget = false, this.hint});

  final dynamic value;
  final ValueChanged<Map<String, double>> onChanged;
  final bool enabled;
  final bool showBudget;
  final String? hint;

  @override
  State<AnalyticField> createState() => _AnalyticFieldState();
}

class _AnalyticFieldState extends State<AnalyticField> {
  List<Map<String, dynamic>> _budget = [];
  String _loadedFor = '';

  @override
  void initState() {
    super.initState();
    _loadBudget();
  }

  @override
  void didUpdateWidget(covariant AnalyticField old) {
    super.didUpdateWidget(old);
    _loadBudget();
  }

  Future<void> _loadBudget() async {
    final ids = anDist(widget.value).keys.toList()..sort();
    final key = ids.join(',');
    if (!widget.showBudget || key == _loadedFor) return;
    _loadedFor = key;
    if (ids.isEmpty) {
      if (mounted) setState(() => _budget = []);
      return;
    }
    try {
      final r = await context.read<Session>().api.get('analytique/budget-status', query: {'sections[]': ids});
      if (mounted) setState(() => _budget = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (_) {
      // hors ligne : pas d'information budgétaire
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      InkWell(
        onTap: widget.enabled
            ? () async {
                final r = await editDistribution(context, widget.value, hint: widget.hint);
                if (r != null) widget.onChanged(r);
              }
            : null,
        child: InputDecorator(
          decoration: InputDecoration(suffixIcon: widget.enabled ? const Icon(Icons.pie_chart_outline, size: 20) : null),
          child: Text(tr(analyticSummary(widget.value)), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: anDist(widget.value).isEmpty ? Theme.of(context).hintColor : null)),
        ),
      ),
      for (final b in _budget)
        Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            tr(toDouble(b['remaining']) < 0
                ? '${b['label'] ?? LocalDb.get('an_sections', b['section_id'])?['name'] ?? ''} : budget dépassé de ${money(-toDouble(b['remaining']), cur)} (${money(toDouble(b['total']), cur)} prévus, ${b['budget']})'
                : '${b['label'] ?? LocalDb.get('an_sections', b['section_id'])?['name'] ?? ''} : ${money(toDouble(b['remaining']), cur)} restants sur ${money(toDouble(b['total']), cur)} (${b['budget']})'),
            style: TextStyle(fontSize: 12, color: toDouble(b['remaining']) < 0 ? YColors.danger : (toDouble(b['percent']) >= 80 ? YColors.warning : Theme.of(context).hintColor)),
          ),
        ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Écran « Analytique & budgets »
// ---------------------------------------------------------------------------

class AnalytiqueScreen extends StatelessWidget {
  const AnalytiqueScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('analytique.manage');
    final tabs = <(String, Widget)>[
      ('Rapport analytique', const _ReportTab()),
      ('Budgets', const _BudgetsTab()),
      if (manage) ('Axes & sections', const _AxesTab()),
      if (manage) ('Règles de ventilation', const _RulesTab()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Analytique & budgets'), subtitle: tr('Marge par activité, site ou projet — budgets comparés au réalisé')),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

// ---------- Période ----------

class _Period {
  _Period(this.from, this.to, this.label);
  final DateTime from;
  final DateTime to;
  final String label;

  static List<_Period> presets() {
    final n = DateTime.now();
    final q = ((n.month - 1) ~/ 3) * 3 + 1;
    return [
      _Period(DateTime(n.year, n.month, 1), DateTime(n.year, n.month + 1, 0), 'Ce mois'),
      _Period(DateTime(n.year, q, 1), DateTime(n.year, q + 3, 0), 'Ce trimestre'),
      _Period(DateTime(n.year, 1, 1), DateTime(n.year, 12, 31), 'Cette année'),
      _Period(DateTime(n.year - 1, 1, 1), DateTime(n.year - 1, 12, 31), 'Année ${n.year - 1}'),
    ];
  }
}

const _monthsFr = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

String monthLabel(String ym) {
  final p = ym.split('-');
  if (p.length < 2) return ym;
  return '${_monthsFr[(int.tryParse(p[1]) ?? 1) - 1]} ${p[0].substring(2)}';
}

/// Barres mensuelles (produits / charges ou prévu / réalisé).
class MonthBars extends StatelessWidget {
  const MonthBars({super.key, required this.months, required this.a, required this.b, required this.labelA, required this.labelB, this.colorA = YColors.success, this.colorB = YColors.danger});

  final List<String> months;
  final List<double> a;
  final List<double> b;
  final String labelA;
  final String labelB;
  final Color colorA;
  final Color colorB;

  @override
  Widget build(BuildContext context) {
    final max = [...a, ...b, 1.0].reduce((x, y) => x > y ? x : y);
    final hint = Theme.of(context).hintColor;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        _legend(colorA, labelA),
        const SizedBox(width: 16),
        _legend(colorB, labelB),
      ]),
      const SizedBox(height: 10),
      SizedBox(
        height: 150,
        child: LayoutBuilder(builder: (context, c) {
          final w = (c.maxWidth / (months.isEmpty ? 1 : months.length)).clamp(18.0, 80.0);
          return SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
              for (var i = 0; i < months.length; i++)
                SizedBox(
                  width: w,
                  child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [
                    Row(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                      Tooltip(message: '$labelA : ${number(a[i])}', child: Container(width: w * 0.3, height: 120 * a[i] / max, decoration: BoxDecoration(color: colorA, borderRadius: const BorderRadius.vertical(top: Radius.circular(3))))),
                      const SizedBox(width: 2),
                      Tooltip(message: '$labelB : ${number(b[i])}', child: Container(width: w * 0.3, height: 120 * b[i] / max, decoration: BoxDecoration(color: colorB, borderRadius: const BorderRadius.vertical(top: Radius.circular(3))))),
                    ]),
                    const SizedBox(height: 4),
                    Text(tr(monthLabel(months[i])), style: TextStyle(fontSize: 10, color: hint), overflow: TextOverflow.clip, maxLines: 1),
                  ]),
                ),
            ]),
          );
        }),
      ),
    ]);
  }

  Widget _legend(Color c, String l) => Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 10, height: 10, decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(2))),
        const SizedBox(width: 6),
        Text(tr(l), style: const TextStyle(fontSize: 12)),
      ]);
}

class _Kpi extends StatelessWidget {
  const _Kpi(this.label, this.value, {this.color, this.sub});

  final String label;
  final String value;
  final Color? color;
  final String? sub;

  @override
  Widget build(BuildContext context) => KpiBox(
        width: 240,
        child: StatTile(label: label, value: value, detail: sub, color: color ?? YColors.blue, valueColor: color),
      );
}

// ---------- Rapport ----------

class _ReportTab extends StatefulWidget {
  const _ReportTab();

  @override
  State<_ReportTab> createState() => _ReportTabState();
}

class _ReportTabState extends State<_ReportTab> {
  late _Period _period = _Period.presets()[2];
  FilterPeriod? _fp;
  String? _axis;
  String _q = '';
  Map<String, dynamic>? _data;
  Object? _error;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final r = await context.read<Session>().api.get('analytique/report', query: {'from': isoDate(_period.from), 'to': isoDate(_period.to), 'axis_id': ?_axis});
      if (mounted) {
        setState(() {
          _data = asMap(r['data']);
          _axis = _data!['axis']?['id'] as String?;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _setPeriod(FilterPeriod? p) {
    setState(() {
      _fp = p;
      _period = p == null ? _Period.presets()[2] : _Period(p.start, p.end, p.label == 'custom' ? '${dateFr(isoDate(p.start))} – ${dateFr(isoDate(p.end))}' : p.label);
    });
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final cur = session.currency;
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    if (_data == null) {
      if (_error != null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
      return const Center(child: CircularProgressIndicator());
    }
    final d = _data!;
    final axes = [for (final a in (d['axes'] as List)) asMap(a)];
    if (axes.isEmpty) {
      return EmptyState(
        icon: Icons.pie_chart_outline,
        title: tr('Aucun axe analytique'),
        message: tr('Créez vos axes (Activité, Site, Département…) et leurs sections, puis ventilez vos ventes, achats et frais.'),
        action: session.can('analytique.manage') ? FilledButton.icon(onPressed: () => DefaultTabController.of(context).animateTo(2), icon: const Icon(Icons.add), label: Text(tr('Créer un axe'))) : null,
      );
    }
    final t = asMap(d['totals']);
    final sections = [for (final s in (d['sections'] as List)) asMap(s)];
    final months = [for (final m in (d['months'] as List)) asMap(m)];
    // Arborescence : parents puis enfants indentés
    final ordered = <(Map<String, dynamic>, int)>[];
    void walk(String? parent, int depth) {
      for (final s in sections.where((x) => x['parent_id'] == parent || (parent == null && !sections.any((p) => p['id'] == x['parent_id']) && x['parent_id'] != null && depth == 0))) {
        if (ordered.any((o) => o.$1['id'] == s['id'])) continue;
        ordered.add((s, depth));
        walk('${s['id']}', depth + 1);
      }
    }

    walk(null, 0);
    final shownSections = ordered.where((o) => _q.isEmpty || '${o.$1['code'] ?? ''} ${o.$1['name']}'.toLowerCase().contains(_q)).toList();

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: EdgeInsets.fromLTRB(pad, 12, pad, 24), children: [
        FilterBar(
          padding: EdgeInsets.zero,
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Code ou nom de section…',
          filters: [FilterSpec('axis', 'Axe', {for (final a in axes) '${a['id']}': '${a['name']}'}, icon: Icons.account_tree_outlined)],
          values: {'axis': _axis},
          onFilter: (k, v) {
            if (v == null || v == _axis) return;
            _axis = v;
            _load();
          },
          showPeriod: true,
          period: _fp,
          onPeriod: _setPeriod,
          count: shownSections.length,
          countLabel: 'section',
          onReset: () {
            setState(() => _q = '');
            if (_fp != null) _setPeriod(null);
          },
          trailing: [
            if (_fp == null) Text(tr(_period.label), style: TextStyle(color: Theme.of(context).hintColor, fontWeight: FontWeight.w600)),
            if (_loading) const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
          ],
        ),
        const SizedBox(height: 12),
        KpiWrap(children: [
          _Kpi('Produits ventilés', money(toDouble(t['revenue']), cur), color: YColors.success),
          _Kpi('Charges ventilées', money(toDouble(t['cost']), cur), color: YColors.danger),
          _Kpi('Marge', money(toDouble(t['margin']), cur), color: toDouble(t['margin']) >= 0 ? YColors.night : YColors.danger,
              sub: toDouble(t['revenue']) > 0 ? 'Taux ${number(toDouble(t['margin']) / toDouble(t['revenue']) * 100, decimals: 1)} %' : null),
          _Kpi('Non ventilé', '${money(toDouble(t['unallocated_revenue']), cur)} / ${money(toDouble(t['unallocated_cost']), cur)}',
              color: (toDouble(t['unallocated_revenue']) + toDouble(t['unallocated_cost'])) > 0 ? YColors.warning : YColors.muted, sub: 'produits / charges sur cet axe'),
        ]),
        const SizedBox(height: 12),
        if (months.isNotEmpty)
          SectionCard(
            title: tr('Évolution mensuelle — ${d['axis']['name']}'),
            child: MonthBars(
              months: [for (final m in months) '${m['month']}'],
              a: [for (final m in months) toDouble(m['revenue'])],
              b: [for (final m in months) toDouble(m['cost'])],
              labelA: 'Produits',
              labelB: 'Charges',
            ),
          ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Sections'),
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: sections.isEmpty
              ? Padding(padding: const EdgeInsets.all(16), child: Text(tr('Aucun montant ventilé sur cet axe pour la période.'), style: TextStyle(color: Theme.of(context).hintColor)))
              : Column(children: [
                  if (MediaQuery.of(context).size.width >= 760)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(8, 0, 8, 6),
                      child: DefaultTextStyle.merge(
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Theme.of(context).hintColor),
                        child: Row(children: [
                          Expanded(child: Text(tr('Section'))),
                          SizedBox(width: 150, child: Text(tr('Produits'), textAlign: TextAlign.right)),
                          SizedBox(width: 150, child: Text(tr('Charges'), textAlign: TextAlign.right)),
                          SizedBox(width: 150, child: Text(tr('Marge'), textAlign: TextAlign.right)),
                          SizedBox(width: 80, child: Text(tr('Taux'), textAlign: TextAlign.right)),
                          SizedBox(width: 18),
                        ]),
                      ),
                    ),
                  for (final (s, depth) in shownSections) _sectionRow(s, depth, cur),
                ]),
        ),
      ]),
    );
  }

  Widget _sectionRow(Map<String, dynamic> s, int depth, String cur) {
    final hasTotal = s['total_revenue'] != null;
    final rev = toDouble(hasTotal ? s['total_revenue'] : s['revenue']);
    final cost = toDouble(hasTotal ? s['total_cost'] : s['cost']);
    final margin = rev - cost;
    final narrow = MediaQuery.of(context).size.width < 760;
    return InkWell(
      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => _SectionDetail(id: '${s['id']}', from: _period.from, to: _period.to, title: '${s['name']}'))),
      child: Container(
        padding: EdgeInsets.fromLTRB(8.0 + depth * 20, 10, 8, 10),
        decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: YColors.line))),
        child: narrow
            ? Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${s['code'] != null ? '${s['code']} · ' : ''}${s['name']}'), style: TextStyle(fontWeight: depth == 0 ? FontWeight.w700 : FontWeight.w500)),
                    Text(tr('${money(rev, cur)} − ${money(cost, cur)}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                  ]),
                ),
                Text(tr(money(margin, cur)), style: TextStyle(fontWeight: FontWeight.w700, color: margin >= 0 ? YColors.success : YColors.danger)),
              ])
            : Row(children: [
                Expanded(child: Text(tr('${s['code'] != null ? '${s['code']} · ' : ''}${s['name']}${hasTotal ? '  (cumul)' : ''}'), style: TextStyle(fontWeight: depth == 0 ? FontWeight.w700 : FontWeight.w500))),
                SizedBox(width: 150, child: Text(tr(money(rev, cur)), textAlign: TextAlign.right)),
                SizedBox(width: 150, child: Text(tr(money(cost, cur)), textAlign: TextAlign.right)),
                SizedBox(width: 150, child: Text(tr(money(margin, cur)), textAlign: TextAlign.right, style: TextStyle(fontWeight: FontWeight.w700, color: margin >= 0 ? YColors.success : YColors.danger))),
                SizedBox(width: 80, child: Text(tr(rev > 0 ? '${number(margin / rev * 100, decimals: 1)} %' : '—'), textAlign: TextAlign.right, style: TextStyle(color: Theme.of(context).hintColor))),
                const Icon(Icons.chevron_right, size: 18),
              ]),
      ),
    );
  }
}

class _SectionDetail extends StatefulWidget {
  const _SectionDetail({required this.id, required this.from, required this.to, required this.title});
  final String id;
  final DateTime from;
  final DateTime to;
  final String title;

  @override
  State<_SectionDetail> createState() => _SectionDetailState();
}

class _SectionDetailState extends State<_SectionDetail> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('analytique/sections/${widget.id}', query: {'from': isoDate(widget.from), 'to': isoDate(widget.to)});
      if (mounted) setState(() => _d = asMap(r['data']));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final manage = context.read<Session>().can('analytique.manage');
    return Scaffold(
      appBar: AppBar(title: Text(tr(widget.title))),
      body: _d == null
          ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : ListView(padding: const EdgeInsets.all(16), children: [
              Text(tr('Du ${dateFr(isoDate(widget.from))} au ${dateFr(isoDate(widget.to))} — sous-sections comprises'), style: TextStyle(color: Theme.of(context).hintColor)),
              const SizedBox(height: 10),
              KpiWrap(children: [
                _Kpi('Produits', money(toDouble(_d!['revenue']), cur), color: YColors.success),
                _Kpi('Charges', money(toDouble(_d!['cost']), cur), color: YColors.danger),
                _Kpi('Marge', money(toDouble(_d!['revenue']) - toDouble(_d!['cost']), cur)),
              ]),
              const SizedBox(height: 12),
              if ((_d!['months'] as List).isNotEmpty)
                SectionCard(
                  title: tr('Par mois'),
                  child: MonthBars(
                    months: [for (final m in _d!['months'] as List) '${m['month']}'],
                    a: [for (final m in _d!['months'] as List) toDouble(m['revenue'])],
                    b: [for (final m in _d!['months'] as List) toDouble(m['cost'])],
                    labelA: 'Produits',
                    labelB: 'Charges',
                  ),
                ),
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Par compte'),
                child: Column(children: [
                  for (final a in _d!['accounts'] as List)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      title: Text(tr('${a['code'] ?? ''} ${a['name']}')),
                      trailing: Text(tr(money(toDouble(a['amount']), cur)), style: TextStyle(fontWeight: FontWeight.w600, color: toDouble(a['amount']) >= 0 ? YColors.success : YColors.danger)),
                    ),
                ]),
              ),
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Détail des montants'),
                child: Column(children: [
                  for (final l in _d!['lines'] as List)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(switch (l['source_type']) {
                        'document' => Icons.receipt_long_outlined,
                        'expense' => Icons.request_quote_outlined,
                        'payroll' => Icons.payments_outlined,
                        _ => Icons.edit_note,
                      }, size: 20),
                      title: Text(tr('${l['label'] ?? ''}'), maxLines: 2, overflow: TextOverflow.ellipsis),
                      subtitle: Text(tr('${dateFr(l['date'])}${l['account_code'] != null ? ' · ${l['account_code']}' : ''}${toDouble(l['percent']) > 0 && toDouble(l['percent']) < 100 ? ' · ${number(toDouble(l['percent']), decimals: 0)} %' : ''}')),
                      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                        Text(tr(money(toDouble(l['amount']), cur)), style: TextStyle(fontWeight: FontWeight.w600, color: toDouble(l['amount']) >= 0 ? YColors.success : YColors.danger)),
                        if (manage && l['source_type'] == 'manual')
                          IconButton(
                            tooltip: tr('Supprimer cette saisie'),
                            icon: const Icon(Icons.delete_outline, size: 18),
                            onPressed: () async {
                              if (!await confirm(context, tr('Supprimer la saisie manuelle ?'), tr('Toutes les sections de cette saisie sont retirées.'), danger: true)) return;
                              if (!context.mounted) return;
                              try {
                                await context.read<Session>().api.delete('analytique/manual/${l['id']}');
                                _load();
                              } catch (e) {
                                if (context.mounted) toast(context, errorText(e), error: true);
                              }
                            },
                          ),
                      ]),
                    ),
                ]),
              ),
            ]),
    );
  }
}

// ---------- Budgets ----------

class _BudgetsTab extends StatefulWidget {
  const _BudgetsTab();

  @override
  State<_BudgetsTab> createState() => _BudgetsTabState();
}

class _BudgetsTabState extends State<_BudgetsTab> {
  List<Map<String, dynamic>>? _list;
  Object? _error;
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('analytique/budgets');
      if (mounted) setState(() => _list = [for (final b in r['data'] as List) asMap(b)]);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _create() async {
    final n = DateTime.now();
    final name = TextEditingController(text: tr('Budget ${n.year + (n.month >= 10 ? 1 : 0)}'));
    var start = DateTime(n.year + (n.month >= 10 ? 1 : 0), 1, 1);
    var end = DateTime(start.year, 12, 31);
    var copy = false;
    final pct = TextEditingController(text: '0');
    String? axis = anAxes().isNotEmpty ? '${anAxes().first['id']}' : null;
    final ok = await showFormDialog<bool>(context, title: 'Nouveau budget', builder: (dc) => StatefulBuilder(
        builder: (dc, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'))),
              const SizedBox(height: 10),
              Row(children: [
                Expanded(child: OutlinedButton(onPressed: () async {
                  final d = await pickDate(dc, initialDate: start, firstDate: DateTime(2015), lastDate: DateTime(2100));
                  if (d != null) set(() => start = d);
                }, child: Text(tr('Du ${dateFr(isoDate(start))}')))),
                const SizedBox(width: 8),
                Expanded(child: OutlinedButton(onPressed: () async {
                  final d = await pickDate(dc, initialDate: end, firstDate: DateTime(2015), lastDate: DateTime(2100));
                  if (d != null) set(() => end = d);
                }, child: Text(tr('au ${dateFr(isoDate(end))}')))),
              ]),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(tr('Préremplir avec le réalisé de la période précédente')),
                subtitle: Text(tr('Mois par mois, par section')),
                value: copy,
                onChanged: (v) => set(() => copy = v),
              ),
              if (copy) ...[
                DropdownButtonFormField<String?>(
                  initialValue: axis,
                  decoration: InputDecoration(labelText: tr('Axe')),
                  items: [for (final a in anAxes()) DropdownMenuItem(value: '${a['id']}', child: Text(tr('${a['name']}')))],
                  onChanged: (v) => set(() => axis = v),
                ),
                TextField(controller: pct, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Évolution (%)'), helperText: tr('Ex. 10 pour +10 %, −5 pour −5 %'))),
              ],
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Créer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true || !mounted) return;
    try {
      final months = (end.year - start.year) * 12 + end.month - start.month + 1;
      final r = await context.read<Session>().api.post('analytique/budgets', {
        'name': name.text.trim(),
        'start_date': isoDate(start),
        'end_date': isoDate(end),
        if (copy) 'copy_from': isoDate(DateTime(start.year, start.month - months, 1)),
        if (copy) 'copy_percent': int.tryParse(pct.text.replaceAll('−', '-')) ?? 0,
        if (copy) 'axis_id': axis,
      });
      await _load();
      if (mounted) _open(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  void _open(Map<String, dynamic> b) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => BudgetDetail(id: b['id'] as int)));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    if (_list == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    bool base(Map<String, dynamic> b) {
      if (_q.isNotEmpty && !'${b['name']}'.toLowerCase().contains(_q.toLowerCase())) return false;
      if (_f['over'] != null && ((_f['over'] == 'yes') != ((b['over'] ?? 0) > 0))) return false;
      if (_period != null) {
        final st = DateTime.tryParse('${b['start_date']}');
        final en = DateTime.tryParse('${b['end_date']}');
        if (st == null || en == null || st.isAfter(_period!.end) || en.isBefore(_period!.start)) return false;
      }
      return true;
    }

    bool st(Map<String, dynamic> b, String k) => k.isEmpty || '${b['status'] ?? 'draft'}' == k;
    final items = _list!.where((b) => base(b) && st(b, _status)).toList();
    const stKeys = ['', 'draft', 'approved', 'closed'];
    return Column(children: [
      const SizedBox(height: 12),
      FilterBar(
        onSearch: (v) => setState(() => _q = v),
        search: _q,
        searchHint: 'Nom du budget…',
        statuses: const {'': 'Tous', 'draft': 'Brouillons', 'approved': 'Approuvés', 'closed': 'Clôturés'},
        status: _status,
        onStatus: (v) => setState(() => _status = v),
        statusCounts: {for (final k in stKeys) k: _list!.where((b) => base(b) && st(b, k)).length},
        filters: const [FilterSpec('over', 'Dépassements', {'yes': 'Avec dépassement', 'no': 'Sans dépassement'}, icon: Icons.warning_amber)],
        values: _f,
        onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
        showPeriod: true,
        period: _period,
        onPeriod: (p) => setState(() => _period = p),
        count: items.length,
        countLabel: 'budget',
        onReset: () => setState(() => (_q = '', _status = '', _f = {}, _period = null)),
        trailing: [
          if (s.can('analytique.manage')) FilledButton.icon(onPressed: _create, icon: const Icon(Icons.add), label: Text(tr('Nouveau budget'))),
        ],
      ),
      Expanded(
        child: items.isEmpty
            ? EmptyState(icon: Icons.savings_outlined, title: tr('Aucun budget'), message: tr('Fixez des montants par section ou par compte, mois par mois, et suivez le réalisé et les engagements.'))
            : ResponsiveList<Map<String, dynamic>>(
                items: items,
                columns: const ['Budget', 'Période', 'Charges prévues', 'Consommé', 'Statut'],
                onTap: _open,
                cells: (b) {
                  final e = asMap(b['totals']['expense']);
                  return [
                    Text(tr('${b['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    Text(tr('${dateFr(b['start_date'])} – ${dateFr(b['end_date'])}')),
                    Text(tr(money(toDouble(e['budget']), cur))),
                    _PercentBar(percent: toDouble(e['percent'])),
                    _budgetChip(b),
                  ];
                },
                mobileTile: (b) {
                  final e = asMap(b['totals']['expense']);
                  return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [Expanded(child: Text(tr('${b['name']}'), style: const TextStyle(fontWeight: FontWeight.w600))), _budgetChip(b)]),
                    const SizedBox(height: 4),
                    Text(tr('${dateFr(b['start_date'])} – ${dateFr(b['end_date'])} · ${money(toDouble(e['budget']), cur)}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                    const SizedBox(height: 6),
                    _PercentBar(percent: toDouble(e['percent'])),
                  ]);
                },
              ),
      ),
    ]);
  }
}

Widget _budgetChip(Map<String, dynamic> b) {
  if ((b['over'] ?? 0) > 0 && b['status'] == 'approved') return StatusChip(tr('${b['over']} dépassement(s)'), color: YColors.danger, icon: Icons.warning_amber);
  return switch (b['status']) {
    'approved' => StatusChip(tr('Approuvé'), color: YColors.success),
    'closed' => StatusChip(tr('Clôturé'), color: YColors.muted),
    _ => StatusChip(tr('Brouillon'), color: YColors.blue),
  };
}

class _PercentBar extends StatelessWidget {
  const _PercentBar({required this.percent, this.warn = 80});
  final double percent;
  final double warn;

  @override
  Widget build(BuildContext context) {
    final c = percent >= 100 ? YColors.danger : (percent >= warn ? YColors.warning : YColors.success);
    return SizedBox(
      width: 160,
      child: Row(children: [
        Expanded(
          child: ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(value: (percent / 100).clamp(0, 1).toDouble(), minHeight: 8, color: c, backgroundColor: YColors.line),
          ),
        ),
        const SizedBox(width: 8),
        SizedBox(width: 48, child: Text(tr('${number(percent, decimals: 0)} %'), textAlign: TextAlign.right, style: TextStyle(fontSize: 12, color: c, fontWeight: FontWeight.w700))),
      ]),
    );
  }
}

class BudgetDetail extends StatefulWidget {
  const BudgetDetail({super.key, required this.id});
  final int id;

  @override
  State<BudgetDetail> createState() => _BudgetDetailState();
}

class _BudgetDetailState extends State<BudgetDetail> {
  Map<String, dynamic>? _b;
  Object? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('analytique/budgets/${widget.id}');
      if (mounted) setState(() => _b = asMap(r['data']));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _act(Future<dynamic> Function() fn, String ok) async {
    setState(() => _busy = true);
    try {
      final r = await fn();
      if (r is Map && r['data'] is Map) setState(() => _b = asMap(r['data']));
      if (mounted) toast(context, ok);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<Map<String, dynamic>> get _lines => [for (final l in _b!['lines'] as List) asMap(l)];

  Future<void> _saveLines(List<Map<String, dynamic>> lines) => _act(
        () => context.read<Session>().api.put('analytique/budgets/${widget.id}', {
          'lines': [
            for (final l in lines)
              {'kind': l['kind'], 'section_id': l['section_id'], 'account_prefix': l['account_prefix'], 'label': l['label'], 'owner_id': l['owner_id'], 'amounts': l['amounts']},
          ],
        }),
        'Budget enregistré.',
      );

  Future<void> _editLine([int? index]) async {
    final months = [for (final m in _b!['months'] as List) '$m'];
    final l = index == null ? <String, dynamic>{'kind': 'expense', 'amounts': {for (final m in months) m: 0}} : Map<String, dynamic>.from(_lines[index]);
    var kind = '${l['kind']}';
    String? section = l['section_id'] as String?;
    final prefix = TextEditingController(text: '${l['account_prefix'] ?? ''}');
    final label = TextEditingController(text: '${l['label'] ?? ''}');
    final total = TextEditingController();
    final perMonth = {for (final m in months) m: TextEditingController(text: number(toDouble(asMap(l['amounts'])[m]), decimals: 0).replaceAll(RegExp(r'\s'), ''))};
    var monthly = index != null && (perMonth.values.map((c) => c.text).toSet().length > 2);
    final sections = [
      for (final a in anAxes())
        for (final s in anSections('${a['id']}')) {...s, '_axis': a['name']},
    ];
    final ok = await showFormDialog<bool>(
      context,
      title: index == null ? 'Nouvelle ligne de budget' : 'Ligne de budget',
      builder: (dc) => StatefulBuilder(
        builder: (dc, set) => Column(children: [
          Expanded(
            child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 8), children: [
              SegmentedButton<String>(
                segments: [ButtonSegment(value: 'expense', label: Text(tr('Charges'))), ButtonSegment(value: 'revenue', label: Text(tr('Produits')))],
                selected: {kind},
                onSelectionChanged: (v) => set(() => kind = v.first),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String?>(
                initialValue: section,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Section')),
                items: [
                  DropdownMenuItem(value: null, child: Text(tr('Toutes (budget par compte)'))),
                  for (final s in sections) DropdownMenuItem(value: '${s['id']}', child: Text(tr('${s['_axis']} — ${sectionLabel(s)}'), overflow: TextOverflow.ellipsis)),
                ],
                onChanged: (v) => set(() => section = v),
              ),
              const SizedBox(height: 10),
              TextField(controller: prefix, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Compte (début du numéro)'), helperText: tr('Ex. 60 achats, 62 services, 66 personnel, 70 ventes — vide : tous'))),
              const SizedBox(height: 10),
              TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé (facultatif)'))),
              const SizedBox(height: 10),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Saisir mois par mois')), value: monthly, onChanged: (v) => set(() => monthly = v)),
              if (!monthly)
                TextField(controller: total, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant total de la période'), helperText: trn(index != null ? 'Actuel : ${number(toDouble(l['total']))} — vide pour garder la répartition actuelle' : 'Réparti également sur ${months.length} mois')))
              else
                Wrap(spacing: 8, runSpacing: 8, children: [
                  for (final m in months)
                    SizedBox(width: 120, child: TextField(controller: perMonth[m], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: monthLabel(m), isDense: true))),
                ]),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 16),
            child: Row(children: [
              if (index != null) TextButton.icon(onPressed: () => Navigator.pop(dc, false), icon: const Icon(Icons.delete_outline, color: YColors.danger), label: Text(tr('Retirer'), style: TextStyle(color: YColors.danger))),
              const Spacer(),
              TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))),
              const SizedBox(width: 8),
              FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('OK'))),
            ]),
          ),
        ]),
      ),
    );
    if (ok == null) return;
    final lines = _lines;
    if (ok == false) {
      lines.removeAt(index!);
    } else {
      Map<String, dynamic> amounts;
      if (monthly) {
        amounts = {for (final m in months) m: toDouble(perMonth[m]!.text.replaceAll(RegExp(r'\s'), '').replaceAll(',', '.'))};
      } else if (total.text.trim().isEmpty && index != null) {
        amounts = asMap(l['amounts']);
      } else {
        final t = toDouble(total.text.replaceAll(RegExp(r'\s'), '').replaceAll(',', '.'));
        final each = (t / months.length).floorToDouble();
        amounts = {for (var i = 0; i < months.length; i++) months[i]: i == months.length - 1 ? t - each * (months.length - 1) : each};
      }
      final nl = {'kind': kind, 'section_id': section, 'account_prefix': prefix.text.trim().isEmpty ? null : prefix.text.trim(), 'label': label.text.trim().isEmpty ? null : label.text.trim(), 'owner_id': l['owner_id'], 'amounts': amounts};
      if (index == null) {
        lines.add(nl);
      } else {
        lines[index] = nl;
      }
    }
    await _saveLines(lines);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final cur = s.currency;
    final manage = s.can('analytique.manage');
    if (_b == null) {
      return Scaffold(appBar: AppBar(), body: _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()));
    }
    final b = _b!;
    final e = asMap(b['totals']['expense']);
    final r = asMap(b['totals']['revenue']);
    final monthly = [for (final m in b['monthly'] as List) asMap(m)];
    final editable = manage && b['status'] != 'closed';
    final api = s.api;
    return Scaffold(
      appBar: AppBar(
        title: Row(children: [Flexible(child: Text(tr('${b['name']}'), overflow: TextOverflow.ellipsis)), const SizedBox(width: 10), _budgetChip({...b, 'over': 0})]),
        actions: [
          if (manage && b['status'] == 'draft')
            FilledButton.icon(onPressed: _busy ? null : () => _act(() => api.post('analytique/budgets/${widget.id}/approve'), 'Budget approuvé : le suivi et les alertes démarrent.'), icon: const Icon(Icons.check), label: Text(tr('Approuver'))),
          if (manage)
            PopupMenuButton<String>(
              onSelected: (v) async {
                if (v == 'close') _act(() => api.post('analytique/budgets/${widget.id}/close'), 'Budget clôturé.');
                if (v == 'reopen') _act(() => api.post('analytique/budgets/${widget.id}/reopen'), 'Budget repassé en brouillon.');
                if (v == 'delete' && await confirm(context, tr('Supprimer ce budget ?'), tr('Ses lignes seront perdues.'), danger: true)) {
                  try {
                    await api.delete('analytique/budgets/${widget.id}');
                    if (context.mounted) Navigator.pop(context);
                  } catch (err) {
                    if (context.mounted) toast(context, errorText(err), error: true);
                  }
                }
              },
              itemBuilder: (_) => [
                if (b['status'] == 'approved') PopupMenuItem(value: 'close', child: Text(tr('Clôturer'))),
                if (b['status'] != 'draft') PopupMenuItem(value: 'reopen', child: Text(tr('Repasser en brouillon'))),
                if (b['status'] != 'approved') PopupMenuItem(value: 'delete', child: Text(tr('Supprimer'))),
              ],
            ),
          const SizedBox(width: 8),
        ],
      ),
      floatingActionButton: editable ? FloatingActionButton.extended(onPressed: _busy ? null : () => _editLine(), icon: const Icon(Icons.add), label: Text(tr('Ligne'))) : null,
      body: ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
        Text(tr('Du ${dateFr(b['start_date'])} au ${dateFr(b['end_date'])} · alerte à ${b['warn_percent']} %'), style: TextStyle(color: Theme.of(context).hintColor)),
        const SizedBox(height: 10),
        KpiWrap(children: [
          _Kpi('Charges prévues', money(toDouble(e['budget']), cur)),
          _Kpi('Réalisé', money(toDouble(e['realized']), cur), color: YColors.night),
          _Kpi('Engagé (commandes)', money(toDouble(e['committed']), cur), color: YColors.blue),
          _Kpi('Reste', money(toDouble(e['remaining']), cur), color: toDouble(e['remaining']) < 0 ? YColors.danger : YColors.success, sub: e['percent'] != null ? '${number(toDouble(e['percent']), decimals: 0)} % consommé' : null),
          if (toDouble(r['budget']) > 0) _Kpi('Produits réalisés / prévus', '${money(toDouble(r['realized']), cur)} / ${money(toDouble(r['budget']), cur)}', color: YColors.success),
        ]),
        const SizedBox(height: 12),
        if (monthly.isNotEmpty)
          SectionCard(
            title: tr('Charges : prévu et réalisé par mois'),
            child: MonthBars(
              months: [for (final m in monthly) '${m['month']}'],
              a: [for (final m in monthly) toDouble(m['expense'])],
              b: [for (final m in monthly) toDouble(m['expense_real'])],
              labelA: 'Prévu',
              labelB: 'Réalisé',
              colorA: YColors.line,
              colorB: YColors.blue,
            ),
          ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Lignes'),
          child: _lines.isEmpty
              ? Padding(padding: const EdgeInsets.all(16), child: Text(tr('Aucune ligne. Ajoutez des montants par section (ex. Marketing, Agence de Bouaké) ou par compte (ex. 62 services extérieurs).'), style: TextStyle(color: Theme.of(context).hintColor)))
              : Column(children: [
                  for (var i = 0; i < _lines.length; i++) _lineRow(_lines[i], i, cur, editable, toDouble(b['warn_percent'])),
                ]),
        ),
        if (b['compta'] != true && _lines.any((l) => l['section_id'] == null))
          Padding(padding: EdgeInsets.only(top: 8), child: Text(tr('Les lignes par compte seul se calculent sur la comptabilité (module non activé).'))),
      ]),
    );
  }

  Widget _lineRow(Map<String, dynamic> l, int i, String cur, bool editable, double warn) {
    final title = [
      if (l['label'] != null) '${l['label']}',
      if (l['label'] == null && l['section_name'] != null) '${l['section_name']}',
      if (l['account_prefix'] != null) 'compte ${l['account_prefix']}…',
      if (l['section_id'] == null && l['account_prefix'] == null) 'Toutes sections',
    ].join(' · ');
    final real = l['realized'];
    return InkWell(
      onTap: editable ? () => _editLine(i) : null,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: YColors.line))),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(l['kind'] == 'revenue' ? Icons.trending_up : Icons.trending_down, size: 18, color: l['kind'] == 'revenue' ? YColors.success : YColors.danger),
            const SizedBox(width: 8),
            Expanded(child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w600))),
            Text(tr(money(toDouble(l['total']), cur)), style: const TextStyle(fontWeight: FontWeight.w700)),
          ]),
          const SizedBox(height: 6),
          Wrap(spacing: 16, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            Text(tr(real == null ? 'Réalisé : —' : 'Réalisé ${money(toDouble(real), cur)}'), style: const TextStyle(fontSize: 13)),
            if (toDouble(l['committed']) > 0) Text(tr('Engagé ${money(toDouble(l['committed']), cur)}'), style: const TextStyle(fontSize: 13, color: YColors.blue)),
            if (l['remaining'] != null) Text(tr('${l['kind'] == 'revenue' ? 'Écart' : 'Reste'} ${money(toDouble(l['remaining']), cur)}'), style: TextStyle(fontSize: 13, color: toDouble(l['remaining']) < 0 && l['kind'] == 'expense' ? YColors.danger : null)),
            if (l['percent'] != null) _PercentBar(percent: toDouble(l['percent']), warn: l['kind'] == 'revenue' ? 1000 : warn),
          ]),
        ]),
      ),
    );
  }
}

// ---------- Paramètres : axes et sections (hors ligne) ----------

class _AxesTab extends StatelessWidget {
  const _AxesTab();

  Future<void> _editAxis(BuildContext context, [Map<String, dynamic>? a]) async {
    final name = TextEditingController(text: '${a?['name'] ?? ''}');
    final code = TextEditingController(text: '${a?['code'] ?? ''}');
    var required = a?['required'] == true;
    var active = a?['active'] != false;
    final ok = await showFormDialog<bool>(context, title: a == null ? 'Nouvel axe' : 'Axe ${a['name']}', builder: (dc) => StatefulBuilder(
        builder: (dc, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'), hintText: tr('Activité, Site, Département…'))),
              TextField(controller: code, decoration: InputDecoration(labelText: tr('Code (facultatif)'))),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Obligatoire pour les achats et notes de frais')), value: required, onChanged: (v) => set(() => required = v)),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Actif')), value: active, onChanged: (v) => set(() => active = v)),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true || name.text.trim().isEmpty || !context.mounted) return;
    await context.read<SyncEngine>().save('an_axes', {
      'id': a?['id'] ?? newId(),
      'name': name.text.trim(),
      'code': code.text.trim().isEmpty ? null : code.text.trim(),
      'required': required,
      'active': active,
      'sort': a?['sort'] ?? anAxes(activeOnly: false).length + 1,
    });
  }

  Future<void> _editSection(BuildContext context, String axisId, [Map<String, dynamic>? s]) async {
    final name = TextEditingController(text: '${s?['name'] ?? ''}');
    final code = TextEditingController(text: '${s?['code'] ?? ''}');
    String? parent = s?['parent_id'] as String?;
    var active = s?['active'] != false;
    final ok = await showFormDialog<bool>(context, title: s == null ? 'Nouvelle section' : 'Section ${s['name']}', builder: (dc) => StatefulBuilder(
        builder: (dc, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'), hintText: tr('Boutique Plateau, Négoce, Marketing…'))),
              TextField(controller: code, decoration: InputDecoration(labelText: tr('Code (facultatif)'))),
              DropdownButtonFormField<String?>(
                initialValue: parent,
                decoration: InputDecoration(labelText: tr('Section parente')),
                items: [
                  DropdownMenuItem(value: null, child: Text(tr('Aucune'))),
                  for (final x in anSections(axisId)) if (x['id'] != s?['id']) DropdownMenuItem(value: '${x['id']}', child: Text(tr(sectionLabel(x)))),
                ],
                onChanged: (v) => set(() => parent = v),
              ),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Active')), value: active, onChanged: (v) => set(() => active = v)),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                if (s != null && s['project_id'] == null)
                  TextButton(
                    onPressed: () async {
                      Navigator.pop(dc, false);
                      await context.read<SyncEngine>().remove('an_sections', '${s['id']}');
                    },
                    child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                  ),
                TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))),
                FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true || name.text.trim().isEmpty || !context.mounted) return;
    await context.read<SyncEngine>().save('an_sections', {
      'id': s?['id'] ?? newId(),
      'axis_id': axisId,
      'name': name.text.trim(),
      'code': code.text.trim().isEmpty ? null : code.text.trim(),
      'parent_id': parent,
      'active': active,
    });
  }

  Future<void> _manual(BuildContext context) async {
    final label = TextEditingController();
    final amount = TextEditingController();
    final account = TextEditingController();
    var date = DateTime.now();
    var cost = true;
    Map<String, double> dist = {};
    final ok = await showFormDialog<bool>(context, title: 'Saisie analytique manuelle', builder: (dc) => StatefulBuilder(
        builder: (dc, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('Pour répartir un montant hors documents (loyer du siège, frais généraux…) entre plusieurs sections.'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 13)),
              const SizedBox(height: 8),
              SegmentedButton<bool>(segments: [ButtonSegment(value: true, label: Text(tr('Charge'))), ButtonSegment(value: false, label: Text(tr('Produit')))], selected: {cost}, onSelectionChanged: (v) => set(() => cost = v.first)),
              TextField(controller: label, decoration: InputDecoration(labelText: tr('Libellé'))),
              Row(children: [
                Expanded(child: TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: account, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Compte (facultatif)')))),
              ]),
              const SizedBox(height: 8),
              OutlinedButton(onPressed: () async {
                final d = await pickDate(dc, initialDate: date, firstDate: DateTime(2015), lastDate: DateTime(2100));
                if (d != null) set(() => date = d);
              }, child: Text(tr('Date : ${dateFr(isoDate(date))}'))),
              const SizedBox(height: 8),
              OutlinedButton.icon(onPressed: () async {
                final r = await editDistribution(dc, dist);
                if (r != null) set(() => dist = r);
              }, icon: const Icon(Icons.pie_chart_outline), label: Text(tr(analyticSummary(dist)))),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(dc, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true || !context.mounted) return;
    try {
      final v = toDouble(amount.text.replaceAll(RegExp(r'\s'), '').replaceAll(',', '.'));
      await context.read<Session>().api.post('analytique/manual', {
        'date': isoDate(date),
        'label': label.text.trim(),
        'amount': cost ? -v : v,
        'account_code': account.text.trim().isEmpty ? null : account.text.trim(),
        'distribution': dist,
      });
      if (context.mounted) toast(context, tr('Saisie enregistrée.'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _rebuild(BuildContext context) async {
    if (!await confirm(context, tr('Recalculer l\'analytique ?'), tr('Toutes les ventes, achats, notes de frais et paies validés sont ventilés à nouveau selon les ventilations saisies et les règles actuelles. Les saisies manuelles sont conservées.'))) return;
    if (!context.mounted) return;
    try {
      final r = (await context.read<Session>().api.post('analytique/rebuild'))['data'] as Map;
      if (context.mounted) toast(context, tr('${r['documents']} document(s), ${r['expenses']} note(s) de frais, ${r['payroll']} paie(s) recalculés.'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('an_sections'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('an_axes'),
        builder: (context, _, _) {
          final axes = anAxes(activeOnly: false);
          return ListView(padding: EdgeInsets.fromLTRB(pad, 12, pad, 24), children: [
            Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.end, children: [
              OutlinedButton.icon(onPressed: () => _manual(context), icon: const Icon(Icons.edit_note), label: Text(tr('Saisie manuelle'))),
              OutlinedButton.icon(onPressed: () => _rebuild(context), icon: const Icon(Icons.refresh), label: Text(tr('Tout recalculer'))),
              FilledButton.icon(onPressed: () => _editAxis(context), icon: const Icon(Icons.add), label: Text(tr('Nouvel axe'))),
            ]),
            const SizedBox(height: 12),
            if (axes.isEmpty)
              EmptyState(icon: Icons.account_tree_outlined, title: tr('Aucun axe'), message: tr('Un axe regroupe des sections : « Activité » (Négoce, Services), « Site » (Plateau, Yopougon), « Département »…')),
            for (final a in axes)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: SectionCard(
                  title: '${a['name']}${a['code'] != null ? '  (${a['code']})' : ''}',
                  trailing: Wrap(spacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    if (a['required'] == true) StatusChip(tr('obligatoire'), color: YColors.warning),
                    if (a['active'] == false) StatusChip(tr('inactif')),
                    if (a['system'] == 'projects') StatusChip(tr('automatique'), color: YColors.blue),
                    IconButton(tooltip: tr('Modifier l\'axe'), onPressed: () => _editAxis(context, a), icon: const Icon(Icons.edit_outlined, size: 18)),
                  ]),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    if (a['system'] == 'projects') Text(tr('Une section par projet, créée et renommée automatiquement ; les factures de projet y sont rattachées.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                    for (final s in _tree('${a['id']}'))
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.only(left: 20.0 * s.$2),
                        leading: Icon(s.$2 == 0 ? Icons.folder_outlined : Icons.subdirectory_arrow_right, size: 18),
                        title: Text(tr(sectionLabel(s.$1)), style: TextStyle(color: s.$1['active'] == false ? Theme.of(context).hintColor : null, decoration: s.$1['active'] == false ? TextDecoration.lineThrough : null)),
                        trailing: const Icon(Icons.edit_outlined, size: 16),
                        onTap: () => _editSection(context, '${a['id']}', s.$1),
                      ),
                    if (a['system'] != 'projects')
                      Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => _editSection(context, '${a['id']}'), icon: const Icon(Icons.add, size: 18), label: Text(tr('Ajouter une section')))),
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }

  List<(Map<String, dynamic>, int)> _tree(String axisId) {
    final all = anSections(axisId, activeOnly: false);
    final out = <(Map<String, dynamic>, int)>[];
    void walk(String? parent, int depth) {
      for (final s in all.where((x) => x['parent_id'] == parent)) {
        out.add((s, depth));
        if (depth < 6) walk('${s['id']}', depth + 1);
      }
    }

    walk(null, 0);
    for (final s in all) {
      if (!out.any((o) => o.$1['id'] == s['id'])) out.add((s, 0)); // parent supprimé
    }
    return out;
  }
}

// ---------- Règles ----------

const _applies = {'any': 'Tout', 'sale': 'Ventes', 'purchase': 'Achats', 'expense': 'Notes de frais', 'payroll': 'Paie'};

class _RulesTab extends StatefulWidget {
  const _RulesTab();

  @override
  State<_RulesTab> createState() => _RulesTabState();
}

class _RulesTabState extends State<_RulesTab> {
  String _q = '';
  String _status = '';
  Map<String, String?> _f = {};

  String _criteria(Map<String, dynamic> r) => [
        if (r['product_id'] != null) 'Produit : ${LocalDb.get('products', r['product_id'] as String?)?['name'] ?? '?'}',
        if (r['category_id'] != null) 'Catégorie : ${LocalDb.hasEntity('product_categories') ? (LocalDb.get('product_categories', r['category_id'] as String?)?['name'] ?? '?') : '?'}',
        if (r['partner_id'] != null) 'Tiers : ${LocalDb.get('partners', r['partner_id'] as String?)?['name'] ?? '?'}',
        if (r['expense_category_id'] != null) 'Frais : ${LocalDb.hasEntity('nf_categories') ? (LocalDb.get('nf_categories', r['expense_category_id'] as String?)?['name'] ?? '?') : '?'}',
        if (r['department'] != null) 'Service : ${r['department']}',
      ].join(' · ');

  Future<void> _edit(BuildContext context, [Map<String, dynamic>? r]) async {
    var applies = '${r?['applies_to'] ?? 'any'}';
    String? product = r?['product_id'] as String?;
    String? category = r?['category_id'] as String?;
    String? partner = r?['partner_id'] as String?;
    String? expCat = r?['expense_category_id'] as String?;
    final dept = TextEditingController(text: '${r?['department'] ?? ''}');
    Map<String, double> dist = anDist(r?['distribution']);
    final products = LocalDb.hasEntity('products') ? (LocalDb.all('products')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'))) : <Map<String, dynamic>>[];
    final cats = LocalDb.hasEntity('product_categories') ? LocalDb.all('product_categories') : <Map<String, dynamic>>[];
    final partners = LocalDb.hasEntity('partners') ? (LocalDb.all('partners')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'))) : <Map<String, dynamic>>[];
    final expCats = LocalDb.hasEntity('nf_categories') ? LocalDb.all('nf_categories') : <Map<String, dynamic>>[];
    final ok = await showFormDialog<bool>(
      context,
      title: r == null ? 'Nouvelle règle' : 'Règle de ventilation',
      builder: (dc) => StatefulBuilder(
        builder: (dc, set) => Column(children: [
          Expanded(
            child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 8), children: [
              Text(tr('Quand une ligne n\'est pas ventilée à la main, la règle la plus précise qui correspond s\'applique.'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 13)),
              const SizedBox(height: 10),
              DropdownButtonFormField<String>(initialValue: applies, decoration: InputDecoration(labelText: tr('S\'applique à')), items: [for (final e in _applies.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))], onChanged: (v) => set(() => applies = v ?? 'any')),
              const SizedBox(height: 10),
              if (['any', 'sale', 'purchase'].contains(applies)) ...[
                DropdownButtonFormField<String?>(initialValue: product, isExpanded: true, decoration: InputDecoration(labelText: tr('Produit')), items: [const DropdownMenuItem(value: null, child: Text('—')), for (final p in products) DropdownMenuItem(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))], onChanged: (v) => set(() => product = v)),
                if (cats.isNotEmpty) DropdownButtonFormField<String?>(initialValue: category, decoration: InputDecoration(labelText: tr('Catégorie de produit')), items: [const DropdownMenuItem(value: null, child: Text('—')), for (final c in cats) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}')))], onChanged: (v) => set(() => category = v)),
                DropdownButtonFormField<String?>(initialValue: partner, isExpanded: true, decoration: InputDecoration(labelText: tr('Client ou fournisseur')), items: [const DropdownMenuItem(value: null, child: Text('—')), for (final p in partners) DropdownMenuItem(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))], onChanged: (v) => set(() => partner = v)),
              ],
              if (['any', 'expense'].contains(applies) && expCats.isNotEmpty)
                DropdownButtonFormField<String?>(initialValue: expCat, decoration: InputDecoration(labelText: tr('Catégorie de frais')), items: [const DropdownMenuItem(value: null, child: Text('—')), for (final c in expCats) DropdownMenuItem(value: '${c['id']}', child: Text(tr('${c['name']}')))], onChanged: (v) => set(() => expCat = v)),
              if (['any', 'expense', 'payroll'].contains(applies)) TextField(controller: dept, decoration: InputDecoration(labelText: tr('Service du salarié'), hintText: tr('Commercial, Logistique…'))),
              const SizedBox(height: 14),
              OutlinedButton.icon(
                onPressed: () async {
                  final res = await editDistribution(dc, dist);
                  if (res != null) set(() => dist = res);
                },
                icon: const Icon(Icons.pie_chart_outline),
                label: Text(tr(analyticSummary(dist))),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 16),
            child: Row(children: [
              if (r != null)
                TextButton(onPressed: () async {
                  Navigator.pop(dc, false);
                  await dc.read<SyncEngine>().remove('an_rules', '${r['id']}');
                }, child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
              const Spacer(),
              TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))),
              const SizedBox(width: 8),
              FilledButton(onPressed: () => Navigator.pop(dc, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    );
    if (ok != true || !context.mounted) return;
    if (dist.isEmpty) {
      toast(context, tr('Choisissez au moins une section.'), error: true);
      return;
    }
    await context.read<SyncEngine>().save('an_rules', {
      'id': r?['id'] ?? newId(),
      'applies_to': applies,
      'product_id': product,
      'category_id': category,
      'partner_id': partner,
      'expense_category_id': expCat,
      'department': dept.text.trim().isEmpty ? null : dept.text.trim(),
      'distribution': dist,
      'active': true,
    });
  }

  @override
  Widget build(BuildContext context) {
    final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('an_rules'),
      builder: (context, _, _) {
        final all = LocalDb.all('an_rules')..sort((a, b) => '${a['applies_to']}'.compareTo('${b['applies_to']}'));
        bool base(Map<String, dynamic> r) =>
            (_q.isEmpty || '${_criteria(r)} ${analyticSummary(r['distribution'])}'.toLowerCase().contains(_q.toLowerCase())) &&
            (_f['partner'] == null || '${r['partner_id']}' == _f['partner']) &&
            (_f['department'] == null || '${r['department']}' == _f['department']) &&
            (_f['active'] == null || (_f['active'] == 'yes') == (r['active'] != false));
        bool st(Map<String, dynamic> r, String k) => k.isEmpty || '${r['applies_to'] ?? 'any'}' == k;
        final rules = all.where((r) => base(r) && st(r, _status)).toList();
        return ListView(padding: EdgeInsets.fromLTRB(pad, 12, pad, 24), children: [
          FilterBar(
            padding: EdgeInsets.zero,
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Produit, tiers, section…',
            statuses: {'': 'Toutes', ..._applies},
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in ['', ..._applies.keys]) k: all.where((r) => base(r) && st(r, k)).length},
            filters: [
              FilterSpec('partner', 'Tiers', filterOptions(all, 'partner_id', label: (r) => '${LocalDb.get('partners', r['partner_id'] as String?)?['name'] ?? '?'}'), icon: Icons.storefront_outlined),
              FilterSpec('department', 'Service', filterOptions(all, 'department'), icon: Icons.apartment_outlined),
              const FilterSpec('active', 'État', {'yes': 'Active', 'no': 'Inactive'}, icon: Icons.toggle_on_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            count: rules.length,
            countLabel: 'règle',
            onReset: () => setState(() => (_q = '', _status = '', _f = {})),
            trailing: [FilledButton.icon(onPressed: () => _edit(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle règle')))],
          ),
          const SizedBox(height: 12),
          if (rules.isEmpty)
            EmptyState(icon: Icons.rule, title: tr('Aucune règle'), message: tr('Exemples : « Riz 25 kg › activité Négoce », « fournisseur Total › site Abidjan », « salariés du service Commercial › Commercial ».')),
          for (final r in rules)
            Card(
              child: ListTile(
                leading: const Icon(Icons.rule),
                title: Text(tr(_criteria(r))),
                subtitle: Text(tr('${_applies[r['applies_to']] ?? r['applies_to']} › ${analyticSummary(r['distribution'])}')),
                trailing: r['active'] == false ? StatusChip(tr('inactive')) : const Icon(Icons.edit_outlined, size: 18),
                onTap: () => _edit(context, r),
              ),
            ),
        ]);
      },
    );
  }
}
