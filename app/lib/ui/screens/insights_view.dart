import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Tableau de bord analytique : chiffres clés et évolutions, courbe 12 mois vs année précédente,
/// indicateurs de pilotage, score de santé et alertes, performance par agence / client, répartition du CA.
class InsightsView extends StatelessWidget {
  const InsightsView({super.key, required this.data, required this.currency, required this.onGo, this.padding = 24});

  final Map<String, dynamic> data;
  final String currency;
  final void Function(String navId) onGo;
  final double padding;

  Map<String, dynamic> _m(String k) => asMap(data[k]);

  @override
  Widget build(BuildContext context) {
    final kpis = _m('kpis');
    Map<String, dynamic> k(String n) => asMap(kpis[n]);
    final rev = k('revenue'), mg = k('margin'), exp = k('expenses'), cash = k('cash');
    final series = [for (final s in (data['series'] as List? ?? const [])) asMap(s)];
    final gauges = [for (final s in (data['gauges'] as List? ?? const [])) asMap(s)];
    final alerts = [for (final s in (data['alerts'] as List? ?? const [])) asMap(s)];
    final table = _m('table');
    final mix = _m('mix');
    final health = _m('health');
    final period = '${_m('period')['label'] ?? ''}';

    final cards = <Widget>[
      _KpiCard(
        primary: true,
        icon: Icons.payments_outlined,
        title: tr('Chiffre d\'affaires · $period'),
        value: compactMoney(_d(rev['value']), currency),
        full: money(_d(rev['value']), currency),
        chips: [
          if (rev['vs_last_year'] != null) _Chip(_pct(rev['vs_last_year'], 'sur 1 an'), _d(rev['vs_last_year']) >= 0),
          if (rev['vs_last_month'] != null) _Chip(_pct(rev['vs_last_month'], 'vs mois dernier'), _d(rev['vs_last_month']) >= 0),
        ],
        onTap: () => onGo('ventes'),
      ),
      if (kpis['margin'] != null) _KpiCard(
        icon: Icons.trending_up,
        title: tr('Marge brute'),
        value: compactMoney(_d(mg['value']), currency),
        full: money(_d(mg['value']), currency),
        chips: [
          if (mg['rate'] != null) _Chip('Taux ${number(_d(mg['rate']), decimals: 1)} %', _d(mg['rate']) >= 15, neutral: true),
          if (mg['rate_change'] != null) _Chip('${_d(mg['rate_change']) >= 0 ? '+' : ''}${number(_d(mg['rate_change']), decimals: 1)} pt', _d(mg['rate_change']) >= 0),
        ],
        onTap: () => onGo('rapports'),
      ),
      if (kpis['expenses'] != null) _KpiCard(
        icon: Icons.receipt_long_outlined,
        title: tr('Dépenses du mois'),
        value: compactMoney(_d(exp['value']), currency),
        full: money(_d(exp['value']), currency),
        chips: [if (exp['vs_average'] != null) _Chip(_pct(exp['vs_average'], 'vs moyenne 3 mois'), _d(exp['vs_average']) <= 0)],
        onTap: () => onGo('achats'),
      ),
      if (kpis['cash'] != null) cash['value'] != null
          ? _KpiCard(
              icon: Icons.account_balance_wallet_outlined,
              title: tr('Trésorerie disponible'),
              value: compactMoney(_d(cash['value']), currency),
              full: money(_d(cash['value']), currency),
              chips: [
                _Chip('Flux 30 j ${_d(cash['flow_30']) >= 0 ? '+' : ''}${compactMoney(_d(cash['flow_30']), currency)}', _d(cash['flow_30']) >= 0),
                if (cash['flow_change'] != null) _Chip(_pct(cash['flow_change'], ''), _d(cash['flow_change']) >= 0),
              ],
              onTap: () => onGo('tresorerie'),
            )
          : _KpiCard(
              icon: Icons.swap_vert,
              title: tr('Flux net · 30 jours'),
              value: compactMoney(_d(cash['flow_30']), currency),
              full: money(_d(cash['flow_30']), currency),
              chips: [if (cash['flow_change'] != null) _Chip(_pct(cash['flow_change'], 'vs 30 j précédents'), _d(cash['flow_change']) >= 0)],
              onTap: () => onGo('ventes'),
            ),
    ];

    return LayoutBuilder(builder: (context, c) {
      final w = c.maxWidth - 2 * padding;
      final wide = w > 1000;
      final cols = clampCount(w > 1000 ? 4 : (w > 300 ? 2 : 1), 1, cards.length);
      final gap = w < 560 ? 10.0 : 16.0;
      final cw = (w - (cols - 1) * gap) / cols;
      Widget pair(Widget a, Widget b, {int fa = 3, int fb = 2}) => wide
          ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(flex: fa, child: a), const SizedBox(width: 16), Expanded(flex: fb, child: b)])
          : Column(children: [a, const SizedBox(height: 16), b]);

      final chart = _Panel(
        title: tr('Évolution du chiffre d\'affaires'),
        subtitle: tr('12 derniers mois, comparés à l\'année précédente'),
        trailing: const Wrap(spacing: 14, children: [_Legend('Cette année', YColors.blue), _Legend('Année précédente', Color(0xFF9AA8BF), dashed: true)]),
        child: w > 760
            ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(child: SizedBox(height: 280, child: _TrendChart(series: series, currency: currency))),
                if (gauges.isNotEmpty) ...[
                  const SizedBox(width: 20),
                  SizedBox(width: 230, child: _Gauges(gauges: gauges)),
                ],
              ])
            : Column(children: [
                SizedBox(height: 240, child: _TrendChart(series: series, currency: currency)),
                if (gauges.isNotEmpty) ...[const SizedBox(height: 16), _Gauges(gauges: gauges)],
              ]),
      );

      final alertPanel = _Panel(
        title: tr('Alertes intelligentes'),
        subtitle: tr('Analyse automatique de vos chiffres'),
        icon: Icons.auto_awesome,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          _HealthCard(score: (health['score'] as num?)?.toInt() ?? 0, summary: '${health['summary'] ?? ''}'),
          const SizedBox(height: 12),
          if (alerts.isEmpty)
            Padding(padding: const EdgeInsets.all(12), child: Text(tr('Aucune alerte : tout est sous contrôle.'), style: TextStyle(color: Theme.of(context).hintColor)))
          else
            LayoutBuilder(builder: (context, c2) {
              final two = c2.maxWidth > 520;
              final aw = two ? (c2.maxWidth - 10) / 2 : c2.maxWidth;
              return Wrap(spacing: 10, runSpacing: 10, children: [
                for (final a in alerts) SizedBox(width: aw, child: _AlertTile(a: a, onTap: () => onGo('${a['target'] ?? 'dashboard'}'))),
              ]);
            }),
        ]),
      );

      final rows = [for (final r in (table['rows'] as List? ?? const [])) asMap(r)];
      final branches = table['kind'] == 'branches';
      final tablePanel = _Panel(
        title: branches ? 'Performance par agence' : 'Meilleurs clients de l\'année',
        subtitle: branches ? 'Depuis janvier, comparé à la même période l\'an dernier' : 'Chiffre d\'affaires HT depuis janvier',
        child: rows.isEmpty
            ? Padding(padding: const EdgeInsets.all(16), child: Text(tr('Pas encore de ventes cette année.'), style: TextStyle(color: Theme.of(context).hintColor)))
            : _EntityTable(rows: rows, branches: branches, currency: currency, compact: w < 640),
      );

      final parts = [for (final p in (mix['parts'] as List? ?? const [])) asMap(p)];
      final mixPanel = _Panel(
        title: tr('Répartition du chiffre d\'affaires'),
        subtitle: tr('Par catégorie d\'articles, depuis janvier'),
        child: parts.isEmpty
            ? Padding(padding: const EdgeInsets.all(16), child: Text(tr('Pas encore de ventes cette année.'), style: TextStyle(color: Theme.of(context).hintColor)))
            : _Mix(parts: parts, currency: currency, largest: mix['largest'] as String?, fastest: mix['fastest'] as String?, fastestGrowth: mix['fastest_growth']),
      );

      return Padding(
        padding: EdgeInsets.symmetric(horizontal: padding),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(spacing: gap, runSpacing: gap, children: [
            // Téléphone : cartes de même hauteur, alignées deux par deux
            for (final (i, card) in cards.indexed) SizedBox(width: cw, height: cols == 2 && w < 560 ? 146 : null, child: AnimatedFadeIn(index: i, child: HoverLift(child: card))),
          ]),
          const SizedBox(height: 16),
          chart,
          const SizedBox(height: 16),
          pair(alertPanel, mixPanel, fa: 3, fb: 2),
          const SizedBox(height: 16),
          tablePanel,
        ]),
      );
    });
  }
}

double _d(Object? v) => (v as num?)?.toDouble() ?? 0;

String _pct(Object? v, String suffix) {
  final x = _d(v);
  final s = x.abs() >= 1000 ? '${x >= 0 ? '+' : '-'}999 %+' : '${x >= 0 ? '+' : ''}${number(x, decimals: 1)} %';
  return suffix.isEmpty ? s : '$s ${tr(suffix)}';
}

/// Montant abrégé : 32,3 M F, 850 k F.
String compactMoney(double v, String currency) {
  final sym = currency == 'XOF' || currency == 'XAF' ? 'F' : currency;
  final a = v.abs();
  final sign = v < 0 ? '-' : '';
  if (a >= 1e9) return '$sign${number(a / 1e9, decimals: 2)} Md $sym';
  if (a >= 1e6) return '$sign${number(a / 1e6, decimals: 1)} M $sym';
  if (a >= 1e4) return '$sign${number(a / 1e3)} k $sym';
  return '$sign${number(a)} $sym';
}

String _axis(double v) {
  if (v >= 1e9) return '${number(v / 1e9, decimals: 1)} Md';
  if (v >= 1e6) return '${number(v / 1e6, decimals: v >= 1e7 ? 0 : 1)} M';
  if (v >= 1e3) return '${number(v / 1e3)} k';
  return number(v);
}

Color toneColor(String? t) => switch (t) {
      'critical' => YColors.danger,
      'warning' => YColors.warning,
      'pattern' => const Color(0xFF7C4DFF),
      'primary' => YColors.blue,
      _ => YColors.success,
    };

class _Chip {
  const _Chip(this.text, this.good, {this.neutral = false});
  final String text;
  final bool good;
  final bool neutral;
}

class _KpiCard extends StatelessWidget {
  const _KpiCard({required this.icon, required this.title, required this.value, required this.full, required this.chips, required this.onTap, this.primary = false});

  final IconData icon;
  final String title, value, full;
  final List<_Chip> chips;
  final VoidCallback onTap;
  final bool primary;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    // Toutes les cartes en blanc (style des maquettes) ; la première garde seulement une icône bleue pleine
    final hero = primary; // première carte : icône bleue pleine
    final fg = Theme.of(context).colorScheme.onSurface;
    final sub = Theme.of(context).hintColor;
    return Material(
      color: Theme.of(context).cardColor,
      borderRadius: BorderRadius.circular(18),
      child: Ink(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: dark ? Colors.white12 : YColors.line),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: onTap,
          child: LayoutBuilder(builder: (context, box) {
            final compact = box.maxWidth < 230; // téléphone : deux cartes par ligne
            return Padding(
            padding: EdgeInsets.all(compact ? 14 : 18),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Container(
                  width: compact ? 34 : 42,
                  height: compact ? 34 : 42,
                  decoration: BoxDecoration(color: hero ? YColors.blue : YColors.blue.withValues(alpha: .09), borderRadius: BorderRadius.circular(12)),
                  child: Icon(icon, size: compact ? 17 : 21, color: hero ? Colors.white : YColors.blue),
                ),
                SizedBox(width: compact ? 8 : 12),
                Expanded(child: Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(tr(title), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: sub, fontSize: compact ? 12 : 14, fontWeight: FontWeight.w600, height: 1.25)),
                )),
              ]),
              SizedBox(height: compact ? 10 : 16),
              Tooltip(
                message: full,
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(tr(value), style: TextStyle(fontSize: compact ? 22 : 28, fontWeight: FontWeight.w800, letterSpacing: -.5, color: fg)),
                ),
              ),
              SizedBox(height: compact ? 8 : 12),
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final ch in compact ? chips.take(1) : chips)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: (ch.neutral ? YColors.blue : (ch.good ? YColors.success : YColors.danger)).withValues(alpha: .1),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      if (!ch.neutral) ...[
                        Icon(ch.good ? Icons.arrow_upward : Icons.arrow_downward, size: 12,
                            color: (ch.good ? YColors.success : YColors.danger)),
                        const SizedBox(width: 3),
                      ],
                      Flexible(
                        child: Text(tr(ch.text),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              fontSize: 11.5,
                              fontWeight: FontWeight.w700,
                              color: (ch.neutral ? YColors.blue : (ch.good ? YColors.success : YColors.danger)))),
                      ),
                    ]),
                  ),
              ]),
            ]),
          );
          }),
        ),
      ),
    );
  }
}

class _Panel extends StatelessWidget {
  const _Panel({required this.title, required this.child, this.subtitle, this.trailing, this.icon});

  final String title;
  final String? subtitle;
  final Widget child;
  final Widget? trailing;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return AnimatedFadeIn(
      child: Container(
        decoration: BoxDecoration(
          color: Theme.of(context).cardColor,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: dark ? Colors.white12 : YColors.line),
        ),
        padding: const EdgeInsets.all(20),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Wrap(alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, runSpacing: 8, spacing: 12, children: [
            Row(mainAxisSize: MainAxisSize.min, children: [
              if (icon != null) ...[Icon(icon, size: 18, color: const Color(0xFF7C4DFF)), const SizedBox(width: 8)],
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                if (subtitle != null) Text(tr(subtitle!), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
              ]),
            ]),
            ?trailing,
          ]),
          const SizedBox(height: 16),
          child,
        ]),
      ),
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend(this.label, this.color, {this.dashed = false});
  final String label;
  final Color color;
  final bool dashed;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 16, height: 3, decoration: BoxDecoration(color: dashed ? color.withValues(alpha: .7) : color, borderRadius: BorderRadius.circular(2))),
        const SizedBox(width: 6),
        Text(tr(label), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor, fontWeight: FontWeight.w600)),
      ]);
}

/// Courbe année en cours (pleine, avec aire) vs année précédente (pointillés), survol interactif.
class _TrendChart extends StatefulWidget {
  const _TrendChart({required this.series, required this.currency});
  final List<Map<String, dynamic>> series;
  final String currency;

  @override
  State<_TrendChart> createState() => _TrendChartState();
}

class _TrendChartState extends State<_TrendChart> {
  int? _hover;

  @override
  Widget build(BuildContext context) {
    final s = widget.series;
    if (s.isEmpty) return const SizedBox.shrink();
    final cur = [for (final p in s) _d(p['current'])];
    final prev = [for (final p in s) _d(p['previous'])];
    final labels = [for (final p in s) '${p['label']}'];
    return LayoutBuilder(builder: (context, c) {
      const left = 52.0, bottom = 26.0;
      final plotW = c.maxWidth - left;
      int? idx(Offset o) => o.dx < left ? null : ((o.dx - left) / plotW * (s.length - 1)).round().clamp(0, s.length - 1);
      return MouseRegion(
        onHover: (e) => setState(() => _hover = idx(e.localPosition)),
        onExit: (_) => setState(() => _hover = null),
        child: GestureDetector(
          onTapDown: (e) => setState(() => _hover = idx(e.localPosition)),
          child: Stack(children: [
            Positioned.fill(
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: 1),
                duration: const Duration(milliseconds: 900),
                curve: Curves.easeOutCubic,
                builder: (context, t, _) => CustomPaint(
                  painter: _TrendPainter(cur, prev, labels, t, _hover, Theme.of(context).hintColor,
                      Theme.of(context).brightness == Brightness.dark ? Colors.white10 : YColors.line, left, bottom),
                ),
              ),
            ),
            if (_hover != null)
              Positioned(
                left: (left + plotW * _hover! / math.max(1, s.length - 1) + (_hover! > s.length / 2 ? -190 : 12)).clamp(0, c.maxWidth - 180),
                top: 4,
                child: IgnorePointer(
                  child: Container(
                    width: 178,
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: YColors.night,
                      borderRadius: BorderRadius.circular(10),
                      boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 12)],
                    ),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${s[_hover!]['month']}'.split('-').reversed.join('/')),
                          style: const TextStyle(color: Colors.white70, fontSize: 11, fontWeight: FontWeight.w600)),
                      const SizedBox(height: 4),
                      Text(tr('Cette année : ${compactMoney(cur[_hover!], widget.currency)}'), style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)),
                      Text(tr('An dernier : ${compactMoney(prev[_hover!], widget.currency)}'), style: const TextStyle(color: Colors.white70, fontSize: 12)),
                      if (prev[_hover!] > 0)
                        Text(tr(_pct((cur[_hover!] - prev[_hover!]) * 100 / prev[_hover!], '')),
                            style: TextStyle(color: cur[_hover!] >= prev[_hover!] ? const Color(0xFF6EE7A0) : const Color(0xFFFF8A80), fontSize: 12, fontWeight: FontWeight.w700)),
                    ]),
                  ),
                ),
              ),
          ]),
        ),
      );
    });
  }
}

class _TrendPainter extends CustomPainter {
  _TrendPainter(this.cur, this.prev, this.labels, this.t, this.hover, this.hint, this.grid, this.left, this.bottom);

  final List<double> cur, prev;
  final List<String> labels;
  final double t;
  final int? hover;
  final Color hint, grid;
  final double left, bottom;

  double _nice(double v) {
    if (v <= 0) return 1;
    final p = math.pow(10, (math.log(v) / math.ln10).floor()).toDouble();
    for (final m in [1, 2, 2.5, 5, 10]) {
      if (m * p >= v) return m * p;
    }
    return 10 * p;
  }

  @override
  void paint(Canvas canvas, Size size) {
    final n = cur.length;
    final top = 8.0;
    final h = size.height - bottom - top;
    final w = size.width - left;
    final maxV = _nice([...cur, ...prev].fold<double>(0, math.max) * 1.05 / 4) * 4;
    final tp = TextPainter(textDirection: TextDirection.ltr);
    // Grille et axe Y
    for (var i = 0; i <= 4; i++) {
      final y = top + h - h * i / 4;
      canvas.drawLine(Offset(left, y), Offset(size.width, y), Paint()..color = grid..strokeWidth = 1);
      tp.text = TextSpan(text: _axis(maxV * i / 4), style: TextStyle(color: hint, fontSize: 10.5));
      tp.layout();
      tp.paint(canvas, Offset(left - tp.width - 8, y - tp.height / 2));
    }
    Offset pt(int i, double v) => Offset(left + (n == 1 ? w / 2 : w * i / (n - 1)), top + h - (v / maxV) * h * t);
    for (var i = 0; i < n; i++) {
      if (n > 8 && i.isOdd && size.width < 520) continue;
      tp.text = TextSpan(text: labels[i], style: TextStyle(color: i == hover ? YColors.blue : hint, fontSize: 10.5, fontWeight: i == hover ? FontWeight.w800 : FontWeight.w500));
      tp.layout();
      tp.paint(canvas, Offset(pt(i, 0).dx - tp.width / 2, top + h + 8));
    }
    Path smooth(List<double> v) {
      final p = Path()..moveTo(pt(0, v[0]).dx, pt(0, v[0]).dy);
      for (var i = 1; i < n; i++) {
        final a = pt(i - 1, v[i - 1]), b = pt(i, v[i]);
        final mx = (a.dx + b.dx) / 2;
        p.cubicTo(mx, a.dy, mx, b.dy, b.dx, b.dy);
      }
      return p;
    }

    if (n < 2) return;
    // Année précédente en pointillés
    final pp = smooth(prev);
    final dash = Paint()
      ..color = const Color(0xFF9AA8BF)
      ..strokeWidth = 2
      ..style = PaintingStyle.stroke;
    for (final m in pp.computeMetrics()) {
      for (var d = 0.0; d < m.length; d += 10) {
        canvas.drawPath(m.extractPath(d, math.min(d + 5, m.length)), dash);
      }
    }
    // Année en cours : aire dégradée + courbe
    final cp = smooth(cur);
    final area = Path.from(cp)
      ..lineTo(pt(n - 1, 0).dx, top + h)
      ..lineTo(pt(0, 0).dx, top + h)
      ..close();
    canvas.drawPath(
        area,
        Paint()
          ..shader = LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [YColors.blue.withValues(alpha: .22), YColors.blue.withValues(alpha: 0)])
              .createShader(Rect.fromLTWH(0, top, size.width, h)));
    canvas.drawPath(
        cp,
        Paint()
          ..color = YColors.blue
          ..strokeWidth = 3
          ..style = PaintingStyle.stroke
          ..strokeCap = StrokeCap.round);
    final last = pt(n - 1, cur.last);
    canvas.drawCircle(last, 5, Paint()..color = YColors.blue);
    canvas.drawCircle(last, 2.5, Paint()..color = Colors.white);
    if (hover != null) {
      final x = pt(hover!, 0).dx;
      canvas.drawLine(Offset(x, top), Offset(x, top + h), Paint()..color = YColors.blue.withValues(alpha: .35)..strokeWidth = 1.2);
      canvas.drawCircle(pt(hover!, prev[hover!]), 4, Paint()..color = const Color(0xFF9AA8BF));
      canvas.drawCircle(pt(hover!, cur[hover!]), 6, Paint()..color = YColors.blue);
      canvas.drawCircle(pt(hover!, cur[hover!]), 3, Paint()..color = Colors.white);
    }
  }

  @override
  bool shouldRepaint(covariant _TrendPainter o) => o.t != t || o.hover != hover || o.cur != cur;
}

class _Gauges extends StatelessWidget {
  const _Gauges({required this.gauges});
  final List<Map<String, dynamic>> gauges;

  @override
  Widget build(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final g in gauges)
        Container(
          margin: const EdgeInsets.only(bottom: 10),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(color: dark ? Colors.white.withValues(alpha: .04) : YColors.bg, borderRadius: BorderRadius.circular(12)),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr('${g['label']}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor, fontWeight: FontWeight.w600))),
              Text(tr('${number(_d(g['value']), decimals: _d(g['value']) % 1 == 0 ? 0 : 1)}${g['unit'] ?? ''}'),
                  style: TextStyle(fontWeight: FontWeight.w800, color: toneColor(g['tone'] as String?))),
            ]),
            const SizedBox(height: 8),
            TweenAnimationBuilder<double>(
              tween: Tween(begin: 0, end: _d(g['progress']).clamp(0, 1)),
              duration: const Duration(milliseconds: 900),
              curve: Curves.easeOutCubic,
              builder: (context, v, _) => ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(
                    value: v, minHeight: 7, backgroundColor: dark ? Colors.white10 : YColors.line, color: toneColor(g['tone'] as String?)),
              ),
            ),
          ]),
        ),
    ]);
  }
}

class _HealthCard extends StatelessWidget {
  const _HealthCard({required this.score, required this.summary});
  final int score;
  final String summary;

  @override
  Widget build(BuildContext context) {
    final color = score >= 80 ? YColors.success : (score >= 60 ? YColors.warning : YColors.danger);
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [color.withValues(alpha: .12), color.withValues(alpha: .03)]),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(children: [
        SizedBox(
          width: 64,
          height: 64,
          child: TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: score / 100),
            duration: const Duration(milliseconds: 1100),
            curve: Curves.easeOutCubic,
            builder: (context, v, _) => Stack(alignment: Alignment.center, children: [
              SizedBox.expand(child: CircularProgressIndicator(value: v, strokeWidth: 7, color: color, backgroundColor: color.withValues(alpha: .15), strokeCap: StrokeCap.round)),
              Text(tr('${(v * 100).round()}'), style: TextStyle(fontSize: 19, fontWeight: FontWeight.w900, color: color)),
            ]),
          ),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('Score de santé · $score/100'), style: const TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(height: 3),
            Text(tr(summary), style: TextStyle(fontSize: 12.5, color: Theme.of(context).hintColor)),
          ]),
        ),
      ]),
    );
  }
}

class _AlertTile extends StatelessWidget {
  const _AlertTile({required this.a, required this.onTap});
  final Map<String, dynamic> a;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final lvl = '${a['level']}';
    final color = toneColor(lvl);
    final (label, icon) = switch (lvl) {
      'critical' => ('Critique', Icons.error_outline),
      'warning' => ('Attention', Icons.warning_amber_rounded),
      'pattern' => ('Tendance', Icons.insights),
      _ => ('En bonne voie', Icons.check_circle_outline),
    };
    return Material(
      color: color.withValues(alpha: .06),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(borderRadius: BorderRadius.circular(12), border: Border(left: BorderSide(color: color, width: 3))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(icon, size: 16, color: color),
              const SizedBox(width: 6),
              Text(tr(label.toUpperCase()), style: TextStyle(fontSize: 10.5, letterSpacing: .6, fontWeight: FontWeight.w800, color: color)),
            ]),
            const SizedBox(height: 6),
            Text(tr('${a['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 3),
            Text(tr('${a['text']}'), style: TextStyle(fontSize: 12.5, color: Theme.of(context).hintColor)),
          ]),
        ),
      ),
    );
  }
}

class _EntityTable extends StatelessWidget {
  const _EntityTable({required this.rows, required this.branches, required this.currency, required this.compact});
  final List<Map<String, dynamic>> rows;
  final bool branches, compact;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final head = TextStyle(fontSize: 11.5, color: hint, fontWeight: FontWeight.w700, letterSpacing: .3);
    final maxRev = rows.map((r) => _d(r['revenue'])).fold<double>(0, math.max);
    Widget status(Map<String, dynamic> r) {
      final c = toneColor(r['tone'] as String?);
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
        decoration: BoxDecoration(color: c.withValues(alpha: .1), borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Container(width: 6, height: 6, decoration: BoxDecoration(color: c, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Text(tr('${r['status']}'), style: TextStyle(fontSize: 11.5, color: c, fontWeight: FontWeight.w700)),
        ]),
      );
    }

    Widget growth(Object? g) => g == null
        ? Text(tr('nouveau'), style: TextStyle(color: hint, fontSize: 12.5))
        : Text(tr(_pct(g, '')), style: TextStyle(fontWeight: FontWeight.w700, color: _d(g) >= 0 ? YColors.success : YColors.danger));

    if (compact) {
      return Column(children: [
        for (final r in rows)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  Text(tr(compactMoney(_d(r['revenue']), currency)), style: TextStyle(color: hint, fontSize: 12.5)),
                ]),
              ),
              growth(r['growth']),
              const SizedBox(width: 10),
              status(r),
            ]),
          ),
      ]);
    }
    return Column(children: [
      Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(children: [
          Expanded(flex: 4, child: Text(tr(branches ? 'AGENCE' : 'CLIENT'), style: head)),
          Expanded(flex: 4, child: Text(tr('CHIFFRE D\'AFFAIRES HT'), style: head)),
          Expanded(flex: 2, child: Text(tr(branches ? 'MARGE' : 'PART DU CA'), style: head, textAlign: TextAlign.right)),
          Expanded(flex: 2, child: Text(tr('SUR 1 AN'), style: head, textAlign: TextAlign.right)),
          Expanded(flex: 3, child: Align(alignment: Alignment.centerRight, child: Text(tr('STATUT'), style: head))),
        ]),
      ),
      const Divider(height: 1),
      for (final (i, r) in rows.indexed) ...[
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 11),
          child: Row(children: [
            Expanded(
              flex: 4,
              child: Row(children: [
                CircleAvatar(
                  radius: 15,
                  backgroundColor: YColors.blue.withValues(alpha: .1),
                  child: Text(tr('${r['name']}'.isEmpty ? '?' : '${r['name']}'[0].toUpperCase()), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: YColors.blue)),
                ),
                const SizedBox(width: 10),
                Expanded(child: Text(tr('${r['name']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700))),
              ]),
            ),
            Expanded(
              flex: 4,
              child: Row(children: [
                SizedBox(width: 92, child: Text(tr(compactMoney(_d(r['revenue']), currency)), style: const TextStyle(fontWeight: FontWeight.w600))),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(right: 18),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(4),
                      child: LinearProgressIndicator(
                          value: maxRev > 0 ? _d(r['revenue']) / maxRev : 0, minHeight: 6, color: YColors.blue.withValues(alpha: .75), backgroundColor: YColors.blue.withValues(alpha: .07)),
                    ),
                  ),
                ),
              ]),
            ),
            Expanded(
              flex: 2,
              child: Text(
                tr(branches
                    ? (r['margin_rate'] == null ? '—' : '${number(_d(r['margin_rate']), decimals: 1)} %')
                    : (r['share'] == null ? '—' : '${number(_d(r['share']), decimals: 1)} %')),
                textAlign: TextAlign.right,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            Expanded(flex: 2, child: Align(alignment: Alignment.centerRight, child: growth(r['growth']))),
            Expanded(flex: 3, child: Align(alignment: Alignment.centerRight, child: status(r))),
          ]),
        ),
        if (i < rows.length - 1) Divider(height: 1, color: Theme.of(context).dividerColor.withValues(alpha: .5)),
      ],
    ]);
  }
}

const mixColors = [Color(0xFF1F6FEB), Color(0xFF12A150), Color(0xFFFFB020), Color(0xFF7C4DFF), Color(0xFFEF5DA8), Color(0xFF9AA8BF)];

class _Mix extends StatelessWidget {
  const _Mix({required this.parts, required this.currency, this.largest, this.fastest, this.fastestGrowth});
  final List<Map<String, dynamic>> parts;
  final String currency;
  final String? largest, fastest;
  final Object? fastestGrowth;

  @override
  Widget build(BuildContext context) {
    final total = parts.fold<double>(0, (a, p) => a + math.max(0, _d(p['value'])));
    final hint = Theme.of(context).hintColor;
    final donut = SizedBox(
      width: 170,
      height: 170,
      child: TweenAnimationBuilder<double>(
        tween: Tween(begin: 0, end: 1),
        duration: const Duration(milliseconds: 1000),
        curve: Curves.easeOutCubic,
        builder: (context, t, _) => CustomPaint(
          painter: _DonutPainter([for (final p in parts) math.max(0, _d(p['value']))], t),
          child: Center(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(tr('Total'), style: TextStyle(fontSize: 11.5, color: hint)),
              Text(tr(compactMoney(total, currency)), style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
            ]),
          ),
        ),
      ),
    );
    final legend = Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      for (final (i, p) in parts.indexed)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            Container(width: 10, height: 10, decoration: BoxDecoration(color: mixColors[i % mixColors.length], borderRadius: BorderRadius.circular(3))),
            const SizedBox(width: 8),
            Expanded(child: Text(tr('${p['label']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13))),
            Text(tr(total > 0 ? '${number(_d(p['value']) * 100 / total, decimals: 1)} %' : '—'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
          ]),
        ),
    ]);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      LayoutBuilder(builder: (context, c) => c.maxWidth > 400
          ? Row(children: [donut, const SizedBox(width: 20), Expanded(child: legend)])
          : Column(children: [donut, const SizedBox(height: 14), legend])),
      const SizedBox(height: 14),
      Row(children: [
        if (largest != null) Expanded(child: _Fact('Poste principal', largest!, Icons.emoji_events_outlined, YColors.blue)),
        if (largest != null && fastest != null) const SizedBox(width: 10),
        if (fastest != null)
          Expanded(child: _Fact('Plus forte progression', '$fastest ${fastestGrowth == null ? '' : _pct(fastestGrowth, '')}', Icons.rocket_launch_outlined, YColors.success)),
      ]),
    ]);
  }
}

class _Fact extends StatelessWidget {
  const _Fact(this.label, this.value, this.icon, this.color);
  final String label, value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: color.withValues(alpha: .07), borderRadius: BorderRadius.circular(12)),
        child: Row(children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(label), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
              Text(tr(value), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
            ]),
          ),
        ]),
      );
}

class _DonutPainter extends CustomPainter {
  _DonutPainter(this.values, this.t);
  final List<double> values;
  final double t;

  @override
  void paint(Canvas canvas, Size size) {
    final total = values.fold<double>(0, (a, b) => a + b);
    if (total <= 0) return;
    final r = math.min(size.width, size.height) / 2 - 10;
    final rect = Rect.fromCircle(center: size.center(Offset.zero), radius: r);
    var start = -math.pi / 2;
    for (var i = 0; i < values.length; i++) {
      final sweep = values[i] / total * 2 * math.pi * t;
      if (sweep <= 0) continue;
      canvas.drawArc(
          rect,
          start + .02,
          math.max(0.001, sweep - .04),
          false,
          Paint()
            ..color = mixColors[i % mixColors.length]
            ..style = PaintingStyle.stroke
            ..strokeWidth = 22
            ..strokeCap = StrokeCap.butt);
      start += sweep;
    }
  }

  @override
  bool shouldRepaint(covariant _DonutPainter o) => o.t != t || o.values != values;
}
