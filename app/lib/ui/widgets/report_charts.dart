import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../theme.dart';
import 'common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Palette catégorielle validée (daltonisme, contraste) — ordre fixe, jamais recyclé.
const _seriesLight = [
  Color(0xFF2A78D6), Color(0xFFEB6834), Color(0xFF1BAF7A), Color(0xFFEDA100),
  Color(0xFFE87BA4), Color(0xFF008300), Color(0xFF6250D6), Color(0xFFE34948),
];
const _seriesDark = [
  Color(0xFF3987E5), Color(0xFFD95926), Color(0xFF199E70), Color(0xFFC98500),
  Color(0xFFD55181), Color(0xFF008300), Color(0xFF9085E9), Color(0xFFE66767),
];
const _othersColor = Color(0xFF8A94A6);

Color seriesColor(BuildContext context, int i, {bool others = false}) {
  if (others) return _othersColor;
  final dark = Theme.of(context).brightness == Brightness.dark;
  return (dark ? _seriesDark : _seriesLight)[i % 8];
}

/// Valeur mise en forme selon le format de la mesure.
String formatValue(dynamic v, String format, String currency, {bool unit = true}) {
  if (v == null) return '—';
  final d = toDouble(v);
  switch (format) {
    case 'money':
      return unit ? money(d, currency) : number(d, decimals: decimalsFor(currency));
    case 'percent':
      return '${number(d, decimals: 1)} %';
    case 'decimal':
      return number(d, decimals: 1);
    default:
      return number(d, decimals: (d - d.roundToDouble()).abs() < 0.005 ? 0 : 2);
  }
}

/// Graduation compacte (1,2 M · 350 k).
String compactValue(double v) {
  final a = v.abs();
  String f(double x) => number(x, decimals: x.abs() >= 10 || x == x.roundToDouble() ? 0 : 1);
  if (a >= 1e9) return '${f(v / 1e9)} Md';
  if (a >= 1e6) return '${f(v / 1e6)} M';
  if (a >= 1e3) return '${f(v / 1e3)} k';
  return f(v);
}

List<Map<String, dynamic>> _list(dynamic v) => [for (final x in (v as List? ?? const [])) asMap(x)];

/// Lecture d'un résultat de rapport renvoyé par le serveur.
class ReportData {
  ReportData(this.raw)
      : config = asMap(raw['config']),
        columns = _list(raw['columns']),
        rows = _list(raw['rows']);

  final Map<String, dynamic> raw;
  final Map<String, dynamic> config;
  final List<Map<String, dynamic>> columns;
  final List<Map<String, dynamic>> rows;

  List<Map<String, dynamic>> get dims => columns.where((c) => c['kind'] == 'dimension').toList();
  List<Map<String, dynamic>> get measures => columns.where((c) => c['kind'] == 'measure').toList();
  String get chart => '${config['chart'] ?? 'table'}';
  String get currency => '${raw['currency'] ?? 'XOF'}';
  Map<String, dynamic> get totals => asMap(raw['totals']);
  Map<String, dynamic>? get others => raw['others'] == null ? null : asMap(raw['others']);
  Map<String, dynamic>? get previous => raw['previous'] == null ? null : asMap(raw['previous']);
  String get periodLabel => '${asMap(raw['period'])['label'] ?? ''}';
  String? get filtersLabel => raw['filters_label'] as String?;
  bool get cumulative => config['cumulative'] == true;

  String label(Map<String, dynamic> row, String dim) => '${asMap(row['labels'])[dim] ?? ''}';
  String? key(Map<String, dynamic> row, String dim) => asMap(row['keys'])[dim]?.toString();
  double? value(Map<String, dynamic> row, String m) {
    final v = asMap(row['values'])[m];
    return v == null ? null : toDouble(v);
  }

  double? delta(String m) {
    final p = previous?['totals']?[m];
    final v = totals[m];
    if (p == null || v == null || toDouble(p).abs() < 0.0001) return null;
    return (toDouble(v) - toDouble(p)) / toDouble(p).abs() * 100;
  }
}

class ChartSeries {
  ChartSeries(this.name, this.values, {this.others = false});
  final String name;
  final List<double?> values;
  final bool others;
}

class ChartData {
  ChartData(this.labels, this.series, this.format, {this.othersLabel = false});
  final List<String> labels;
  final List<ChartSeries> series;
  final String format;
  final bool othersLabel; // dernière catégorie = « Autres »
}

/// Séries à tracer : une dimension → une série par mesure de même format (un seul axe) ;
/// deux dimensions → une série par valeur de la 2e (7 + « Autres »).
ChartData chartData(ReportData d, {bool withOthers = true}) {
  final dims = d.dims;
  final ms = d.measures;
  if (dims.isEmpty || ms.isEmpty) return ChartData([], [], 'number');
  final fmt = '${ms.first['format']}';
  final d0 = '${dims.first['key']}';
  if (dims.length == 1) {
    final same = ms.where((m) => m['format'] == fmt).toList();
    final labels = [for (final r in d.rows) d.label(r, d0)];
    final series = [for (final m in same) ChartSeries('${m['label']}', [for (final r in d.rows) d.value(r, '${m['key']}')])];
    final o = d.others;
    final isDate = dims.first['date'] != null;
    if (withOthers && o != null && !isDate) {
      labels.add('Autres');
      for (var i = 0; i < same.length; i++) {
        series[i].values.add(o['${same[i]['key']}'] == null ? null : toDouble(o['${same[i]['key']}']));
      }
      return ChartData(labels, series, fmt, othersLabel: true);
    }
    return ChartData(labels, series, fmt);
  }
  final d1 = '${dims[1]['key']}';
  final m = '${ms.first['key']}';
  final labels = <String>[];
  final idx = <String, int>{};
  final s1 = <String, String>{};
  final totals = <String, double>{};
  for (final r in d.rows) {
    final k0 = d.key(r, d0) ?? '';
    if (!idx.containsKey(k0)) {
      idx[k0] = labels.length;
      labels.add(d.label(r, d0));
    }
    final k1 = d.key(r, d1) ?? '';
    s1[k1] = d.label(r, d1);
    totals[k1] = (totals[k1] ?? 0) + (d.value(r, m) ?? 0).abs();
  }
  final isDate1 = dims[1]['date'] != null;
  final keys = s1.keys.toList()..sort((a, b) => isDate1 ? a.compareTo(b) : totals[b]!.compareTo(totals[a]!));
  final top = keys.length > 8 ? keys.take(7).toList() : keys;
  final rest = keys.length > 8 ? keys.skip(7).toSet() : <String>{};
  final values = {for (final k in top) k: List<double?>.filled(labels.length, null)};
  final other = List<double?>.filled(labels.length, null);
  for (final r in d.rows) {
    final i = idx[d.key(r, d0) ?? '']!;
    final k1 = d.key(r, d1) ?? '';
    final v = d.value(r, m);
    if (rest.contains(k1)) {
      other[i] = (other[i] ?? 0) + (v ?? 0);
    } else {
      values[k1]![i] = v;
    }
  }
  return ChartData(labels, [
    for (final k in top) ChartSeries(s1[k]!, values[k]!),
    if (rest.isNotEmpty) ChartSeries('Autres', other, others: true),
  ], fmt);
}

/// Affiche un résultat selon sa présentation (indicateur, tableau, graphiques, tableau croisé).
class ReportView extends StatelessWidget {
  const ReportView({super.key, required this.data, this.chart, this.compact = false});

  final ReportData data;
  final String? chart;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final c = chart ?? data.chart;
    if (c == 'kpi' || data.dims.isEmpty) return KpiView(data: data, compact: compact);
    if (data.rows.isEmpty) {
      return Center(child: Padding(padding: const EdgeInsets.all(16), child: Text(tr('Aucune donnée sur la période.'), style: TextStyle(color: Theme.of(context).hintColor))));
    }
    switch (c) {
      case 'bar':
        return CartesianChart(data: chartData(data, withOthers: false), currency: data.currency, line: false);
      case 'line':
        return CartesianChart(data: chartData(data, withOthers: false), currency: data.currency, line: true);
      case 'hbar':
        return HBarChart(data: chartData(data), currency: data.currency, compact: compact);
      case 'pie':
        return DonutChart(data: chartData(data), currency: data.currency);
      case 'pivot':
        return data.dims.length == 2 ? PivotTable(data: data) : ReportTable(data: data);
      default:
        return ReportTable(data: data, compact: compact);
    }
  }
}

// ---------------------------------------------------------------- indicateur

class KpiView extends StatelessWidget {
  const KpiView({super.key, required this.data, this.compact = false});

  final ReportData data;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final ms = data.measures;
    return Wrap(spacing: 28, runSpacing: 12, children: [
      for (final m in ms)
        Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (ms.length > 1 || !compact) Text(tr('${m['label']}'), style: TextStyle(color: hint, fontSize: 12)),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(tr(formatValue(data.totals['${m['key']}'], '${m['format']}', data.currency)),
                style: TextStyle(fontSize: compact ? 26 : 32, fontWeight: FontWeight.w800, letterSpacing: -0.5)),
          ),
          Builder(builder: (_) {
            final dlt = data.delta('${m['key']}');
            if (dlt == null) return const SizedBox.shrink();
            final up = dlt >= 0;
            final color = up ? YColors.success : YColors.danger;
            return Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(up ? Icons.arrow_upward : Icons.arrow_downward, size: 14, color: color),
                Text(tr('${up ? '+' : ''}${number(dlt, decimals: 1)} %'), style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12)),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(tr('vs ${formatValue(data.previous?['totals']?['${m['key']}'], '${m['format']}', data.currency)}'),
                      style: TextStyle(color: hint, fontSize: 12), overflow: TextOverflow.ellipsis),
                ),
              ]),
            );
          }),
        ]),
    ]);
  }
}

// ---------------------------------------------------------------- tableaux

class ReportTable extends StatelessWidget {
  const ReportTable({super.key, required this.data, this.compact = false, this.maxRows});

  final ReportData data;
  final bool compact;
  final int? maxRows;

  @override
  Widget build(BuildContext context) {
    final dims = data.dims;
    final ms = data.measures;
    final hint = Theme.of(context).hintColor;
    final line = Theme.of(context).dividerColor.withValues(alpha: 0.4);
    final rows = maxRows != null && data.rows.length > maxRows! ? data.rows.take(maxRows!).toList() : data.rows;
    TableRow row(List<String> cells, {bool bold = false, bool head = false, Color? bg}) => TableRow(
          decoration: BoxDecoration(color: bg, border: Border(bottom: BorderSide(color: line))),
          children: [
            for (var i = 0; i < cells.length; i++)
              Padding(
                padding: EdgeInsets.symmetric(horizontal: 10, vertical: compact ? 6 : 8),
                child: Text(tr(cells[i]),
                    textAlign: i < dims.length ? TextAlign.left : TextAlign.right,
                    style: TextStyle(fontSize: head ? 12 : 13, color: head ? hint : null, fontWeight: bold || head ? FontWeight.w700 : FontWeight.w400,
                        fontFeatures: const [FontFeature.tabularFigures()])),
              ),
          ],
        );
    final shaded = Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: 0.35);
    final table = Table(
      defaultColumnWidth: const IntrinsicColumnWidth(),
      defaultVerticalAlignment: TableCellVerticalAlignment.middle,
      children: [
        row([for (final c in data.columns) '${c['label']}'], head: true),
        for (final r in rows) row([for (final d in dims) data.label(r, '${d['key']}'), for (final m in ms) formatValue(data.value(r, '${m['key']}'), '${m['format']}', data.currency, unit: false)]),
        if (data.others != null && (maxRows == null || data.rows.length <= maxRows!))
          row(['Autres', for (var i = 1; i < dims.length; i++) '', for (final m in ms) formatValue(data.others!['${m['key']}'], '${m['format']}', data.currency, unit: false)]),
        if (dims.isNotEmpty)
          row([
            data.cumulative ? 'Total (cumul)' : 'Total',
            for (var i = 1; i < dims.length; i++) '',
            for (final m in ms)
              formatValue(data.cumulative && m['additive'] == true && data.rows.isNotEmpty ? data.value(data.rows.last, '${m['key']}') : data.totals['${m['key']}'], '${m['format']}', data.currency, unit: false),
          ], bold: true, bg: shaded),
      ],
    );
    return Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
      SingleChildScrollView(scrollDirection: Axis.horizontal, child: table),
      if (rows.length < data.rows.length)
        Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('… ${data.rows.length - rows.length} ligne(s) de plus'), style: TextStyle(color: hint, fontSize: 12))),
      if (data.raw['truncated'] == true)
        Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Résultat limité à 2 000 lignes : affinez les filtres.'), style: TextStyle(color: YColors.warning, fontSize: 12))),
    ]);
  }
}

class PivotTable extends StatelessWidget {
  const PivotTable({super.key, required this.data});

  final ReportData data;

  @override
  Widget build(BuildContext context) {
    final d0 = '${data.dims[0]['key']}';
    final d1 = '${data.dims[1]['key']}';
    final m = data.measures.first;
    final mk = '${m['key']}';
    final additive = m['additive'] == true;
    final cols = <String, String>{};
    final lines = <String, String>{};
    final cell = <String, double?>{};
    for (final r in data.rows) {
      final a = data.key(r, d0) ?? '';
      final b = data.key(r, d1) ?? '';
      lines.putIfAbsent(a, () => data.label(r, d0));
      cols[b] = data.label(r, d1);
      cell['$a|$b'] = data.value(r, mk);
    }
    final colKeys = cols.keys.toList()..sort();
    final hint = Theme.of(context).hintColor;
    final line = Theme.of(context).dividerColor.withValues(alpha: 0.4);
    final shaded = Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: 0.35);
    String f(double? v) => formatValue(v, '${m['format']}', data.currency, unit: false);
    // Intensité : une seule teinte, du clair au foncé (lecture des ordres de grandeur).
    final maxV = cell.values.whereType<double>().fold<double>(0, (a, b) => math.max(a, b.abs()));
    final base = seriesColor(context, 0);
    Widget c(String t, {bool bold = false, bool head = false, TextAlign align = TextAlign.right, Color? bg}) => Container(
          color: bg,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
          child: Text(tr(t), textAlign: align, style: TextStyle(fontSize: head ? 12 : 13, color: head ? hint : null, fontWeight: bold || head ? FontWeight.w700 : FontWeight.w400, fontFeatures: const [FontFeature.tabularFigures()])),
        );
    final colTotals = {for (final k in colKeys) k: 0.0};
    final rows = <TableRow>[
      TableRow(decoration: BoxDecoration(border: Border(bottom: BorderSide(color: line))), children: [
        c('${data.dims[0]['label']} \\ ${data.dims[1]['label']}', head: true, align: TextAlign.left),
        for (final k in colKeys) c(cols[k]!, head: true),
        if (additive) c('Total', head: true),
      ]),
    ];
    for (final e in lines.entries) {
      var sum = 0.0;
      final cells = <Widget>[c(e.value, align: TextAlign.left)];
      for (final k in colKeys) {
        final v = cell['${e.key}|$k'];
        sum += v ?? 0;
        colTotals[k] = colTotals[k]! + (v ?? 0);
        cells.add(c(f(v), bg: v == null || maxV == 0 ? null : base.withValues(alpha: 0.06 + 0.30 * (v.abs() / maxV))));
      }
      if (additive) cells.add(c(f(sum), bold: true));
      rows.add(TableRow(decoration: BoxDecoration(border: Border(bottom: BorderSide(color: line))), children: cells));
    }
    if (additive) {
      rows.add(TableRow(decoration: BoxDecoration(color: shaded), children: [
        c('Total', bold: true, align: TextAlign.left),
        for (final k in colKeys) c(f(colTotals[k]), bold: true),
        c(f(toDouble(data.totals[mk])), bold: true),
      ]));
    }
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Table(defaultColumnWidth: const IntrinsicColumnWidth(), defaultVerticalAlignment: TableCellVerticalAlignment.middle, children: rows),
    );
  }
}

// ---------------------------------------------------------------- légende

class ChartLegend extends StatelessWidget {
  const ChartLegend({super.key, required this.series});

  final List<ChartSeries> series;

  @override
  Widget build(BuildContext context) {
    if (series.length < 2) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Wrap(spacing: 14, runSpacing: 4, children: [
        for (var i = 0; i < series.length; i++)
          Row(mainAxisSize: MainAxisSize.min, children: [
            Container(width: 10, height: 10, decoration: BoxDecoration(color: seriesColor(context, i, others: series[i].others), borderRadius: BorderRadius.circular(2))),
            const SizedBox(width: 5),
            Text(tr(series[i].name), style: const TextStyle(fontSize: 12)),
          ]),
      ]),
    );
  }
}

// ---------------------------------------------------------------- histogramme et courbe

/// Histogramme groupé ou courbes, un seul axe, info-bulle au survol ou au toucher.
class CartesianChart extends StatefulWidget {
  const CartesianChart({super.key, required this.data, required this.currency, required this.line, this.height});

  final ChartData data;
  final String currency;
  final bool line;
  final double? height;

  @override
  State<CartesianChart> createState() => _CartesianChartState();
}

class _CartesianChartState extends State<CartesianChart> {
  int? _hover;

  @override
  Widget build(BuildContext context) {
    final d = widget.data;
    final colors = [for (var i = 0; i < d.series.length; i++) seriesColor(context, i, others: d.series[i].others)];
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      ChartLegend(series: d.series),
      Expanded(
        child: LayoutBuilder(builder: (context, c) {
          final painter = _CartesianPainter(
            data: d, colors: colors, line: widget.line, hover: _hover, currency: widget.currency,
            text: Theme.of(context).textTheme.bodySmall?.color ?? Colors.black87,
            hint: Theme.of(context).hintColor, grid: Theme.of(context).dividerColor.withValues(alpha: 0.35),
            surface: Theme.of(context).cardColor,
          );
          int? hit(Offset p) => painter.indexAt(p, c.biggest);
          return MouseRegion(
            onHover: (e) => setState(() => _hover = hit(e.localPosition)),
            onExit: (_) => setState(() => _hover = null),
            child: GestureDetector(
              onTapDown: (e) => setState(() => _hover = hit(e.localPosition)),
              onHorizontalDragUpdate: (e) => setState(() => _hover = hit(e.localPosition)),
              child: CustomPaint(size: c.biggest, painter: painter),
            ),
          );
        }),
      ),
    ]);
  }
}

class _CartesianPainter extends CustomPainter {
  _CartesianPainter({required this.data, required this.colors, required this.line, required this.hover, required this.currency, required this.text, required this.hint, required this.grid, required this.surface});

  final ChartData data;
  final List<Color> colors;
  final bool line;
  final int? hover;
  final String currency;
  final Color text;
  final Color hint;
  final Color grid;
  final Color surface;

  static const _left = 48.0;
  static const _bottom = 22.0;
  static const _top = 8.0;

  (double, double, double) _scale() {
    var lo = 0.0;
    var hi = 0.0;
    for (final s in data.series) {
      for (final v in s.values) {
        if (v == null) continue;
        lo = math.min(lo, v);
        hi = math.max(hi, v);
      }
    }
    if (hi == lo) hi = lo + 1;
    final raw = (hi - lo) / 4;
    final mag = math.pow(10, (math.log(raw) / math.ln10).floor()).toDouble();
    final step = [1, 2, 2.5, 5, 10].map((m) => m * mag).firstWhere((s) => s >= raw, orElse: () => 10 * mag);
    return ((lo / step).floor() * step, (hi / step).ceil() * step, step);
  }

  int? indexAt(Offset p, Size size) {
    final n = data.labels.length;
    if (n == 0 || p.dx < _left || p.dx > size.width) return null;
    final w = (size.width - _left) / n;
    return ((p.dx - _left) / w).floor().clamp(0, n - 1);
  }

  void _text(Canvas canvas, String s, Offset at, {Color? color, double size = 10, TextAlign align = TextAlign.left, double maxWidth = 200, FontWeight weight = FontWeight.w400}) {
    final tp = TextPainter(text: TextSpan(text: s, style: TextStyle(color: color ?? hint, fontSize: size, fontWeight: weight)), textDirection: TextDirection.ltr, maxLines: 1, ellipsis: '…')
      ..layout(maxWidth: maxWidth);
    final dx = align == TextAlign.right ? at.dx - tp.width : (align == TextAlign.center ? at.dx - tp.width / 2 : at.dx);
    tp.paint(canvas, Offset(dx, at.dy - tp.height / 2));
  }

  @override
  void paint(Canvas canvas, Size size) {
    final n = data.labels.length;
    if (n == 0 || size.height < 40) return;
    final (lo, hi, step) = _scale();
    final plotH = size.height - _bottom - _top;
    final plotW = size.width - _left;
    double y(double v) => _top + plotH * (1 - (v - lo) / (hi - lo));
    final gridPaint = Paint()
      ..color = grid
      ..strokeWidth = 1;
    for (var v = lo; v <= hi + step / 2; v += step) {
      final yy = y(v);
      canvas.drawLine(Offset(_left, yy), Offset(size.width, yy), gridPaint);
      _text(canvas, compactValue(v), Offset(_left - 6, yy), align: TextAlign.right);
    }
    final w = plotW / n;
    final zero = y(0);
    // Libellés de l'axe horizontal, éclaircis s'ils se chevauchent.
    final every = math.max(1, (70 / w).ceil());
    for (var i = 0; i < n; i += every) {
      _text(canvas, data.labels[i], Offset(_left + w * i + w / 2, size.height - _bottom / 2 + 2), align: TextAlign.center, maxWidth: w * every - 4);
    }
    if (hover != null) {
      canvas.drawRect(Rect.fromLTWH(_left + w * hover!, _top, w, plotH), Paint()..color = grid.withValues(alpha: 0.12));
    }
    final ns = data.series.length;
    if (line) {
      for (var s = 0; s < ns; s++) {
        final path = Path();
        var started = false;
        final pts = <Offset>[];
        for (var i = 0; i < n; i++) {
          final v = data.series[s].values[i];
          if (v == null) {
            started = false;
            continue;
          }
          final p = Offset(_left + w * i + w / 2, y(v));
          pts.add(p);
          if (!started) {
            path.moveTo(p.dx, p.dy);
            started = true;
          } else {
            path.lineTo(p.dx, p.dy);
          }
        }
        canvas.drawPath(path, Paint()
          ..color = colors[s]
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2
          ..strokeJoin = StrokeJoin.round);
        if (n <= 31) {
          for (final p in pts) {
            canvas.drawCircle(p, 4, Paint()..color = surface);
            canvas.drawCircle(p, 2.6, Paint()..color = colors[s]);
          }
        }
      }
    } else {
      final groupW = math.min(w * 0.78, 28.0 * ns + 2 * (ns - 1));
      final barW = (groupW - 2 * (ns - 1)) / ns;
      for (var i = 0; i < n; i++) {
        final gx = _left + w * i + (w - groupW) / 2;
        for (var s = 0; s < ns; s++) {
          final v = data.series[s].values[i];
          if (v == null || v == 0) continue;
          final x = gx + s * (barW + 2);
          final top = math.min(y(v), zero);
          final bottom = math.max(y(v), zero);
          final r = math.min(4.0, barW / 2);
          final rect = Rect.fromLTRB(x, top, x + barW, math.max(bottom, top + 1));
          canvas.drawRRect(
            v >= 0 ? RRect.fromRectAndCorners(rect, topLeft: Radius.circular(r), topRight: Radius.circular(r)) : RRect.fromRectAndCorners(rect, bottomLeft: Radius.circular(r), bottomRight: Radius.circular(r)),
            Paint()..color = (hover == null || hover == i) ? colors[s] : colors[s].withValues(alpha: 0.55),
          );
        }
      }
    }
    canvas.drawLine(Offset(_left, zero), Offset(size.width, zero), Paint()
      ..color = hint.withValues(alpha: 0.6)
      ..strokeWidth = 1);

    // Info-bulle
    if (hover != null) {
      final i = hover!;
      final lines = <(String, Color?)>[(data.labels[i], null)];
      for (var s = 0; s < ns; s++) {
        lines.add(('${ns > 1 ? '${data.series[s].name} : ' : ''}${formatValue(data.series[s].values[i], data.format, currency)}', colors[s]));
      }
      const pad = 8.0;
      const lh = 16.0;
      final tw = lines.map((l) {
        final tp = TextPainter(text: TextSpan(text: l.$1, style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600)), textDirection: TextDirection.ltr)..layout(maxWidth: 260);
        return tp.width;
      }).reduce(math.max);
      final bw = tw + pad * 2 + 14;
      final bh = lines.length * lh + pad * 2 - 2;
      var bx = _left + w * i + w / 2 + 10;
      if (bx + bw > size.width) bx = _left + w * i + w / 2 - 10 - bw;
      bx = math.max(0, bx);
      final box = RRect.fromRectAndRadius(Rect.fromLTWH(bx, _top + 4, bw, bh), const Radius.circular(6));
      canvas.drawRRect(box.shift(const Offset(0, 1)), Paint()..color = Colors.black.withValues(alpha: 0.12));
      canvas.drawRRect(box, Paint()..color = surface);
      canvas.drawRRect(box, Paint()
        ..color = grid
        ..style = PaintingStyle.stroke);
      for (var k = 0; k < lines.length; k++) {
        final ly = _top + 4 + pad + k * lh + lh / 2 - 1;
        if (lines[k].$2 != null) {
          canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(bx + pad, ly - 4, 8, 8), const Radius.circular(2)), Paint()..color = lines[k].$2!);
        }
        _text(canvas, lines[k].$1, Offset(bx + pad + (lines[k].$2 != null ? 14 : 0), ly), color: text, size: 11.5, maxWidth: 260, weight: k == 0 ? FontWeight.w700 : FontWeight.w500);
      }
    }
  }

  @override
  bool shouldRepaint(covariant _CartesianPainter old) => old.data != data || old.hover != hover || old.colors != colors || old.line != line;
}

// ---------------------------------------------------------------- barres horizontales

class HBarChart extends StatelessWidget {
  const HBarChart({super.key, required this.data, required this.currency, this.compact = false});

  final ChartData data;
  final String currency;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final ns = data.series.length;
    final maxV = data.series.expand((s) => s.values).whereType<double>().fold<double>(0, (a, b) => math.max(a, b.abs()));
    final hint = Theme.of(context).hintColor;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      ChartLegend(series: data.series),
      Expanded(
        child: ListView.builder(
          padding: EdgeInsets.zero,
          itemCount: data.labels.length,
          itemBuilder: (context, i) {
            final others = data.othersLabel && i == data.labels.length - 1;
            return Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: LayoutBuilder(builder: (context, c) {
                final labelW = math.min(compact ? 120.0 : 180.0, c.maxWidth * 0.35);
                final barMax = c.maxWidth - labelW - 96;
                return Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
                  SizedBox(width: labelW, child: Text(tr(data.labels[i]), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, color: others ? hint : null))),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      for (var s = 0; s < ns; s++)
                        Builder(builder: (_) {
                          final v = data.series[s].values[i];
                          final len = v == null || maxV == 0 ? 0.0 : math.max(2.0, barMax * v.abs() / maxV);
                          return Tooltip(
                            message: '${data.labels[i]}${ns > 1 ? ' · ${data.series[s].name}' : ''} : ${formatValue(v, data.format, currency)}',
                            child: Padding(
                              padding: EdgeInsets.only(top: s == 0 ? 0 : 2),
                              child: Row(children: [
                                Container(
                                  width: len,
                                  height: ns > 1 ? 9 : 14,
                                  decoration: BoxDecoration(
                                    color: others ? _othersColor : seriesColor(context, s, others: data.series[s].others),
                                    borderRadius: const BorderRadius.horizontal(right: Radius.circular(4)),
                                  ),
                                ),
                                const SizedBox(width: 6),
                                Flexible(child: Text(tr(formatValue(v, data.format, currency, unit: false)), style: const TextStyle(fontSize: 11.5, fontFeatures: [FontFeature.tabularFigures()]), overflow: TextOverflow.fade, softWrap: false)),
                              ]),
                            ),
                          );
                        }),
                    ]),
                  ),
                ]);
              }),
            );
          },
        ),
      ),
    ]);
  }
}

// ---------------------------------------------------------------- anneau

class DonutChart extends StatefulWidget {
  const DonutChart({super.key, required this.data, required this.currency});

  final ChartData data;
  final String currency;

  @override
  State<DonutChart> createState() => _DonutChartState();
}

class _DonutChartState extends State<DonutChart> {
  int? _sel;

  @override
  Widget build(BuildContext context) {
    final d = widget.data;
    if (d.series.isEmpty) return const SizedBox.shrink();
    final vals = [for (final v in d.series.first.values) math.max(0.0, v ?? 0)];
    final total = vals.fold<double>(0, (a, b) => a + b);
    // Au-delà de 8 parts, regroupement dans « Autres » (jamais une 9e couleur).
    var labels = d.labels;
    var values = vals;
    var othersAt = d.othersLabel ? labels.length - 1 : -1;
    if (labels.length > 8) {
      final rest = values.skip(7).fold<double>(0, (a, b) => a + b);
      labels = [...labels.take(7), 'Autres'];
      values = [...values.take(7), rest];
      othersAt = 7;
    }
    final colors = [for (var i = 0; i < labels.length; i++) i == othersAt ? _othersColor : seriesColor(context, i)];
    final legend = Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (var i = 0; i < labels.length; i++)
        InkWell(
          onTap: () => setState(() => _sel = _sel == i ? null : i),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(children: [
              Container(width: 10, height: 10, decoration: BoxDecoration(color: colors[i], borderRadius: BorderRadius.circular(2))),
              const SizedBox(width: 6),
              Expanded(child: Text(tr(labels[i]), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, fontWeight: _sel == i ? FontWeight.w700 : null))),
              Text(tr('${number(total == 0 ? 0 : values[i] / total * 100, decimals: 1)} %'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
            ]),
          ),
        ),
    ]);
    return LayoutBuilder(builder: (context, c) {
      final side = c.maxWidth > 420;
      final ring = SizedBox.square(
        dimension: math.max(60, math.min(side ? c.maxHeight : c.maxHeight * 0.55, side ? c.maxWidth * 0.45 : c.maxWidth)),
        child: CustomPaint(
          painter: _DonutPainter(values, colors, _sel, Theme.of(context).cardColor),
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(18),
              child: FittedBox(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Text(tr(_sel == null ? 'Total' : labels[_sel!]), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
                  Text(tr(formatValue(_sel == null ? total : values[_sel!], d.format, widget.currency, unit: false)), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                ]),
              ),
            ),
          ),
        ),
      );
      return side
          ? Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              ring,
              const SizedBox(width: 20),
              Flexible(child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 360), child: SingleChildScrollView(child: legend))),
            ])
          : Column(children: [ring, const SizedBox(height: 8), Expanded(child: SingleChildScrollView(child: legend))]);
    });
  }
}

class _DonutPainter extends CustomPainter {
  _DonutPainter(this.values, this.colors, this.sel, this.surface);

  final List<double> values;
  final List<Color> colors;
  final int? sel;
  final Color surface;

  @override
  void paint(Canvas canvas, Size size) {
    final total = values.fold<double>(0, (a, b) => a + b);
    if (total <= 0) return;
    final r = math.min(size.width, size.height) / 2;
    final stroke = r * 0.32;
    final rect = Rect.fromCircle(center: size.center(Offset.zero), radius: r - stroke / 2 - 2);
    var a = -math.pi / 2;
    for (var i = 0; i < values.length; i++) {
      final sweep = values[i] / total * 2 * math.pi;
      if (sweep <= 0) continue;
      canvas.drawArc(rect, a, sweep, false, Paint()
        ..color = sel == null || sel == i ? colors[i] : colors[i].withValues(alpha: 0.35)
        ..style = PaintingStyle.stroke
        ..strokeWidth = sel == i ? stroke + 4 : stroke);
      // Séparation de 2 px couleur du fond entre les parts
      if (values.where((v) => v > 0).length > 1) {
        final p1 = rect.center + Offset(math.cos(a), math.sin(a)) * (rect.width / 2 - stroke / 2 - 3);
        final p2 = rect.center + Offset(math.cos(a), math.sin(a)) * (rect.width / 2 + stroke / 2 + 3);
        canvas.drawLine(p1, p2, Paint()
          ..color = surface
          ..strokeWidth = 2);
      }
      a += sweep;
    }
  }

  @override
  bool shouldRepaint(covariant _DonutPainter old) => old.values != values || old.sel != sel || old.colors != colors;
}
