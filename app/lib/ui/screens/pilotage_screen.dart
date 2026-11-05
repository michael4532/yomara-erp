import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import '../widgets/report_charts.dart';
import 'insights_view.dart' show compactMoney;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Pilotage & prévisions : soldes intermédiaires de gestion, prévision des ventes, seuil de rentabilité, rentabilité, objectifs.
class PilotageScreen extends StatelessWidget {
  const PilotageScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final tabs = <(String, Widget)>[
      ('Soldes de gestion', const _Overview()),
      ('Prévisions', const _Forecast()),
      ('Seuil de rentabilité', const _Breakeven()),
      ('Rentabilité', const _Profitability()),
      ('Objectifs', RecordsView(module: 'pilotage', resource: 'targets', emptyIcon: Icons.flag_outlined, newLabel: tr('Nouvel objectif'),
          emptyMessage: tr('Fixez un objectif de chiffre d\'affaires pour l\'année (réparti selon la saisonnalité) ou mois par mois.'))),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Pilotage & prévisions'), subtitle: tr('Marge, EBE, prévisions, point mort et rentabilité, calculés à partir de votre activité')),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

/// Chargement d'un indicateur du serveur.
mixin _Loader<T extends StatefulWidget> on State<T> {
  Map<String, dynamic>? data;
  Object? error;

  String get path;
  Map<String, dynamic> get query => const {};

  Future<void> load() async {
    setState(() => error = null);
    try {
      final r = await context.read<Session>().api.get(path, query: query);
      if (mounted) setState(() => data = asMap((r as Map)['data']));
    } catch (e) {
      if (mounted) setState(() => error = e);
    }
  }

  Widget body(Widget Function(Map<String, dynamic> d) build) {
    if (error != null) return OnlineOnlyNotice(error: error!, onRetry: load);
    if (data == null) return const Center(child: CircularProgressIndicator());
    return RefreshIndicator(onRefresh: load, child: build(data!));
  }
}

(String, String) _period(String p) {
  final n = DateTime.now();
  return switch (p) {
    'month' => (isoDate(DateTime(n.year, n.month, 1)), isoDate(n)),
    '12m' => (isoDate(DateTime(n.year, n.month - 11, 1)), isoDate(n)),
    'last' => (isoDate(DateTime(n.year - 1, 1, 1)), isoDate(DateTime(n.year - 1, 12, 31))),
    _ => (isoDate(DateTime(n.year, 1, 1)), isoDate(n)),
  };
}

Widget _periodChips(String value, ValueChanged<String> onChanged) => Wrap(spacing: 8, runSpacing: 6, children: [
      for (final p in const [('year', 'Année en cours'), ('month', 'Mois en cours'), ('12m', '12 derniers mois'), ('last', 'Année précédente')])
        ChoiceChip(label: Text(tr(p.$2)), selected: value == p.$1, onSelected: (_) => onChanged(p.$1)),
    ]);

String _pct(Object? v) => v == null ? '—' : '${number(toDouble(v), decimals: 1)} %';

class _Overview extends StatefulWidget {
  const _Overview();

  @override
  State<_Overview> createState() => _OverviewState();
}

class _OverviewState extends State<_Overview> with _Loader {
  String _p = 'year';

  @override
  String get path => 'pilotage/overview';

  @override
  Map<String, dynamic> get query {
    final (f, t) = _period(_p);
    return {'from': f, 'to': t};
  }

  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return body((d) {
      final lines = [for (final l in d['lines'] as List) asMap(l)];
      final by = {for (final l in lines) l['key']: l};
      final months = [for (final m in d['months'] as List) asMap(m)];
      Widget delta(Object? v) {
        if (v == null) return const Text('—');
        final x = toDouble(v);
        return Text('${x >= 0 ? '+' : ''}${number(x, decimals: 1)} %', style: TextStyle(color: x >= 0 ? YColors.success : YColors.danger, fontWeight: FontWeight.w600));
      }

      return ListView(padding: const EdgeInsets.all(16), children: [
        _periodChips(_p, (v) {
          setState(() => _p = v);
          load();
        }),
        const SizedBox(height: 12),
        StatStrip(tiles: [
          StatTile(label: tr('Chiffre d\'affaires HT'), value: compactMoney(toDouble(by['revenue']?['value']), cur), detail: by['revenue']?['change'] == null ? null : tr('${_pct(by['revenue']?['change'])} vs l\'an dernier'), icon: Icons.trending_up),
          StatTile(label: tr('Marge brute'), value: compactMoney(toDouble(by['gross_margin']?['value']), cur), detail: tr('taux de marge ${_pct(d['margin_rate'])}'), icon: Icons.percent, color: const Color(0xFF0891B2)),
          StatTile(label: tr('EBE'), value: compactMoney(toDouble(by['ebitda']?['value']), cur), detail: tr('${_pct(d['ebitda_rate'])} du chiffre d\'affaires'), icon: Icons.account_balance_wallet_outlined,
              color: toDouble(by['ebitda']?['value']) >= 0 ? YColors.success : YColors.danger),
          StatTile(label: tr('Résultat avant amortissements'), value: compactMoney(toDouble(by['result']?['value']), cur), icon: Icons.flag_outlined,
              color: toDouble(by['result']?['value']) >= 0 ? YColors.success : YColors.danger),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Soldes intermédiaires de gestion'),
          child: Column(children: [
            Row(children: [
              const Expanded(flex: 5, child: SizedBox()),
              for (final h in ['Montant', '% du CA', 'Année précédente', 'Évolution'])
                Expanded(flex: 2, child: Text(tr(h), textAlign: TextAlign.right, style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12))),
            ]),
            const Divider(),
            for (final l in lines)
              Container(
                padding: const EdgeInsets.symmetric(vertical: 7, horizontal: 6),
                decoration: l['kind'] == 'total' ? BoxDecoration(color: YColors.blue.withValues(alpha: .06), borderRadius: BorderRadius.circular(8)) : null,
                child: Row(children: [
                  Expanded(
                      flex: 5,
                      child: Text('${l['kind'] == 'minus' ? '− ' : (l['kind'] == 'total' ? '= ' : '')}${tr('${l['label']}')}',
                          style: TextStyle(fontWeight: l['kind'] == 'total' ? FontWeight.w700 : FontWeight.w400))),
                  Expanded(flex: 2, child: Text(money(toDouble(l['value']), cur), textAlign: TextAlign.right, style: TextStyle(fontWeight: l['kind'] == 'total' ? FontWeight.w700 : FontWeight.w400))),
                  Expanded(flex: 2, child: Text(_pct(l['rate']), textAlign: TextAlign.right)),
                  Expanded(flex: 2, child: Text(money(toDouble(l['previous']), cur), textAlign: TextAlign.right, style: TextStyle(color: Theme.of(context).hintColor))),
                  Expanded(flex: 2, child: Align(alignment: Alignment.centerRight, child: delta(l['change']))),
                ]),
              ),
            const SizedBox(height: 8),
            Text(
                tr('Estimation à partir des ventes, des factures de services, des dépenses de trésorerie et de la paie. Coût d\'achat connu pour ${d['cost_coverage'] ?? '—'} % du chiffre d\'affaires (articles avec prix d\'achat). Le compte de résultat comptable est dans Comptabilité.'),
                style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
          ]),
        ),
        const SizedBox(height: 12),
        if (months.length > 1)
          SectionCard(
            title: tr('Mois par mois'),
            child: SizedBox(
              height: 260,
              child: CartesianChart(
                currency: cur,
                line: false,
                data: ChartData([for (final m in months) '${'${m['month']}'.substring(5, 7)}/${'${m['month']}'.substring(2, 4)}'], [
                  ChartSeries(tr('Chiffre d\'affaires'), [for (final m in months) toDouble(m['revenue'])]),
                  ChartSeries(tr('Marge brute'), [for (final m in months) toDouble(m['gross_margin'])]),
                  ChartSeries(tr('EBE'), [for (final m in months) toDouble(m['ebitda'])]),
                ], 'money'),
              ),
            ),
          ),
      ]);
    });
  }
}

class _Forecast extends StatefulWidget {
  const _Forecast();

  @override
  State<_Forecast> createState() => _ForecastState();
}

class _ForecastState extends State<_Forecast> with _Loader {
  @override
  String get path => 'pilotage/forecast';

  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return body((d) {
      final hist = [for (final h in d['history'] as List) asMap(h)];
      final fc = [for (final f in d['forecast'] as List) asMap(f)];
      final last = hist.length > 12 ? hist.sublist(hist.length - 12) : hist;
      final targets = [for (final t in d['targets'] as List) t == null ? null : toDouble(t)];
      final year = d['year'] as int;
      double? target(String ym) => ym.startsWith('$year-') ? targets[int.parse(ym.substring(5, 7)) - 1] : null;
      final labels = [for (final h in last) '${h['month']}', for (final f in fc) '${f['month']}'];
      final gap = d['target_gap'] == null ? null : toDouble(d['target_gap']);
      return ListView(padding: const EdgeInsets.all(16), children: [
        StatStrip(tiles: [
          StatTile(label: tr('Réalisé depuis janvier'), value: compactMoney(toDouble(d['ytd']), cur), detail: d['ytd_target'] == null ? null : tr('objectif à date ${compactMoney(toDouble(d['ytd_target']), cur)}'), icon: Icons.check_circle_outline),
          StatTile(label: tr('Atterrissage probable $year'), value: compactMoney(toDouble(d['landing']), cur), detail: tr('l\'an dernier : ${compactMoney(toDouble(d['previous_year']), cur)}'), icon: Icons.flight_land, color: const Color(0xFF0891B2)),
          StatTile(
              label: tr('Objectif de l\'année'),
              value: d['annual_target'] == null ? '—' : compactMoney(toDouble(d['annual_target']), cur),
              detail: gap == null ? tr('fixez-le dans l\'onglet Objectifs') : (gap >= 0 ? tr('dépassé de ${compactMoney(gap, cur)}') : tr('il manque ${compactMoney(-gap, cur)}')),
              icon: Icons.flag_outlined,
              color: gap == null || gap >= 0 ? YColors.success : YColors.danger),
          StatTile(label: tr('Tendance'), value: d['trend_pct'] == null ? '—' : '${toDouble(d['trend_pct']) >= 0 ? '+' : ''}${_pct(d['trend_pct'])}', detail: tr('par mois, hors saisonnalité · ${d['months_of_history']} mois d\'historique'), icon: Icons.show_chart, color: const Color(0xFF9333EA)),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Ventes : 12 derniers mois et 12 prochains'),
          child: SizedBox(
            height: 280,
            child: CartesianChart(
              currency: cur,
              line: true,
              data: ChartData([for (final l in labels) '${l.substring(5, 7)}/${l.substring(2, 4)}'], [
                ChartSeries(tr('Réalisé'), [for (final h in last) toDouble(h['value']), for (final f in fc) f['actual'] == null ? null : toDouble(f['actual'])]),
                ChartSeries(tr('Prévision'), [for (final _ in last) null, for (final f in fc) toDouble(f['value'])]),
                ChartSeries(tr('Objectif'), [for (final l in labels) target(l)]),
              ], 'money'),
            ),
          ),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Prévision mois par mois'),
          child: Column(children: [
            for (final f in fc)
              ListTile(
                dense: true,
                title: Text(tr('${f['label']}')),
                subtitle: Text(tr('fourchette ${compactMoney(toDouble(f['low']), cur)} – ${compactMoney(toDouble(f['high']), cur)}${target('${f['month']}') == null ? '' : ' · objectif ${compactMoney(target('${f['month']}')!, cur)}'}')),
                trailing: Text(money(toDouble(f['value']), cur), style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
            Text(tr('Tendance des 12 derniers mois et saisonnalité des 24 derniers ; fourchette à 80 %. La prévision s\'affine avec l\'historique.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
          ]),
        ),
      ]);
    });
  }
}

class _Breakeven extends StatefulWidget {
  const _Breakeven();

  @override
  State<_Breakeven> createState() => _BreakevenState();
}

class _BreakevenState extends State<_Breakeven> with _Loader {
  int _window = 3;
  double _price = 0, _volume = 0, _cost = 0, _fixed = 0;

  @override
  String get path => 'pilotage/breakeven';

  @override
  Map<String, dynamic> get query => {'window': '$_window'};

  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return body((d) {
      final a = asMap(d['average']);
      final rev = toDouble(a['revenue']), cogs = toDouble(a['cogs']), fixed = toDouble(a['fixed']);
      // simulateur : prix et volumes agissent sur le CA, volumes et prix d'achat sur le coût des ventes
      final sRev = rev * (1 + _price / 100) * (1 + _volume / 100);
      final sCogs = cogs * (1 + _volume / 100) * (1 + _cost / 100);
      final sFixed = fixed * (1 + _fixed / 100);
      final sRes = sRev - sCogs - sFixed;
      final baseRes = rev - cogs - fixed;
      final sRate = sRev > 0 ? (sRev - sCogs) / sRev : 0.0;
      final sBe = sRate > 0 ? sFixed / sRate : null;
      Widget slider(String label, double v, ValueChanged<double> on) => Row(children: [
            SizedBox(width: 210, child: Text(tr(label))),
            Expanded(child: Slider(value: v, min: -30, max: 30, divisions: 60, label: '${v >= 0 ? '+' : ''}${v.round()} %', onChanged: (x) => setState(() => on(x)))),
            SizedBox(width: 60, child: Text('${v >= 0 ? '+' : ''}${v.round()} %', textAlign: TextAlign.right)),
          ]);
      return ListView(padding: const EdgeInsets.all(16), children: [
        Wrap(spacing: 8, children: [
          for (final w in const [1, 3, 6, 12])
            ChoiceChip(
                label: Text(tr(w == 1 ? 'Dernier mois' : 'Moyenne $w mois')),
                selected: _window == w,
                onSelected: (_) {
                  setState(() => _window = w);
                  load();
                }),
        ]),
        const SizedBox(height: 12),
        StatStrip(tiles: [
          StatTile(label: tr('Seuil de rentabilité mensuel'), value: d['breakeven'] == null ? '—' : compactMoney(toDouble(d['breakeven']), cur), detail: tr('CA moyen ${compactMoney(rev, cur)} / mois'), icon: Icons.balance),
          StatTile(label: tr('Marge de sécurité'), value: _pct(d['safety_margin']), detail: tr('taux de marge ${_pct(d['margin_rate'])}'), icon: Icons.shield_outlined,
              color: toDouble(d['safety_margin']) >= 0 ? YColors.success : YColors.danger),
          StatTile(label: tr('Point mort du mois'), value: d['breakeven_day_label'] == null ? '—' : tr('${d['breakeven_day_label']}'), detail: tr('CA du mois ${compactMoney(toDouble(d['month_to_date']), cur)}'), icon: Icons.event_available, color: const Color(0xFFD97706)),
          StatTile(label: tr('Charges fixes mensuelles'), value: compactMoney(fixed, cur), detail: tr('services, loyers, salaires, taxes, frais financiers'), icon: Icons.receipt_long_outlined, color: const Color(0xFF9333EA)),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Simulateur'),
          trailing: TextButton(onPressed: () => setState(() => _price = _volume = _cost = _fixed = 0), child: Text(tr('Réinitialiser'))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            slider('Prix de vente', _price, (x) => _price = x),
            slider('Volumes vendus', _volume, (x) => _volume = x),
            slider('Prix d\'achat', _cost, (x) => _cost = x),
            slider('Charges fixes', _fixed, (x) => _fixed = x),
            const Divider(),
            Wrap(spacing: 24, runSpacing: 8, children: [
              _kv(context, 'Chiffre d\'affaires / mois', compactMoney(sRev, cur)),
              _kv(context, 'Résultat / mois', compactMoney(sRes, cur), color: sRes >= 0 ? YColors.success : YColors.danger),
              _kv(context, 'Écart avec aujourd\'hui', '${sRes - baseRes >= 0 ? '+' : ''}${compactMoney(sRes - baseRes, cur)}', color: sRes - baseRes >= 0 ? YColors.success : YColors.danger),
              _kv(context, 'Nouveau seuil', sBe == null ? '—' : compactMoney(sBe, cur)),
            ]),
            const SizedBox(height: 8),
            Text(tr('Base : moyenne des ${d['window']} derniers mois complets (${dateFr(d['from'])} – ${dateFr(d['to'])}).'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
          ]),
        ),
        const SizedBox(height: 12),
        if (d['annual_breakeven'] != null)
          SectionCard(
            title: tr('Sur l\'année'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              LinearProgressIndicator(value: math.min(1, toDouble(d['ytd_revenue']) / math.max(1, toDouble(d['annual_breakeven']))), minHeight: 10, borderRadius: BorderRadius.circular(6)),
              const SizedBox(height: 8),
              Text(tr('${compactMoney(toDouble(d['ytd_revenue']), cur)} réalisés sur ${compactMoney(toDouble(d['annual_breakeven']), cur)} nécessaires pour couvrir les charges de l\'année.')),
              if (d['year_breakeven_date'] != null)
                Text(d['year_breakeven_date'] == 'atteint' ? tr('Seuil annuel atteint : chaque vente fait désormais du bénéfice.') : tr('Au rythme actuel, seuil atteint vers le ${dateFr(d['year_breakeven_date'])}.'),
                    style: const TextStyle(fontWeight: FontWeight.w600)),
            ]),
          ),
      ]);
    });
  }
}

Widget _kv(BuildContext context, String k, String v, {Color? color}) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(tr(k), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
      Text(v, style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18, color: color)),
    ]);

class _Profitability extends StatefulWidget {
  const _Profitability();

  @override
  State<_Profitability> createState() => _ProfitabilityState();
}

class _ProfitabilityState extends State<_Profitability> with _Loader {
  String _by = 'customer';
  String _p = 'year';

  @override
  String get path => 'pilotage/profitability';

  @override
  Map<String, dynamic> get query {
    final (f, t) = _period(_p);
    return {'by': _by, 'from': f, 'to': t};
  }

  @override
  void initState() {
    super.initState();
    load();
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return body((d) {
      final rows = [for (final r in d['rows'] as List) asMap(r)];
      final t = asMap(d['totals']);
      return ListView(padding: const EdgeInsets.all(16), children: [
        Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          SegmentedButton<String>(
            segments: [
              ButtonSegment(value: 'customer', label: Text(tr('Clients'))),
              ButtonSegment(value: 'product', label: Text(tr('Articles'))),
              ButtonSegment(value: 'category', label: Text(tr('Catégories'))),
            ],
            selected: {_by},
            onSelectionChanged: (v) {
              setState(() => _by = v.first);
              load();
            },
          ),
          _periodChips(_p, (v) {
            setState(() => _p = v);
            load();
          }),
        ]),
        const SizedBox(height: 12),
        StatStrip(tiles: [
          StatTile(label: tr('Chiffre d\'affaires HT'), value: compactMoney(toDouble(t['revenue']), cur), detail: tr('${d['count']} ligne(s)'), icon: Icons.trending_up),
          StatTile(label: tr('Marge'), value: compactMoney(toDouble(t['margin']), cur), detail: tr('taux ${_pct(t['margin_rate'])}'), icon: Icons.percent, color: YColors.success),
          StatTile(label: tr('80 % de la marge'), value: d['pareto'] == null ? '—' : '${d['pareto']}', detail: tr('élément(s) sur ${d['count']}'), icon: Icons.stars_outlined, color: const Color(0xFFD97706)),
          StatTile(label: tr('Vendus à perte'), value: '${d['losses']}', detail: tr('marge négative, coût connu'), icon: Icons.trending_down, color: YColors.danger),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          child: Column(children: [
            for (final r in rows)
              ListTile(
                dense: true,
                title: Text(verbatim('${r['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text(tr('CA ${money(toDouble(r['revenue']), cur)} · coût ${money(toDouble(r['cost']), cur)}${toDouble(r['cost_known']) < 90 ? ' · coût connu ${r['cost_known'] ?? 0} %' : ''}')),
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text(money(toDouble(r['margin']), cur), style: TextStyle(fontWeight: FontWeight.w700, color: toDouble(r['margin']) < 0 ? YColors.danger : null)),
                  Text(_pct(r['margin_rate']), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                ]),
              ),
            if (rows.isEmpty) EmptyState(icon: Icons.insights_outlined, title: tr('Aucune vente sur la période')),
          ]),
        ),
      ]);
    });
  }
}
