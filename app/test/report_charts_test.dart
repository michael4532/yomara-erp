import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/widgets/report_charts.dart';

Map<String, dynamic> _row(Map<String, String> keys, Map<String, String> labels, Map<String, num?> values) => {'keys': keys, 'labels': labels, 'values': values};

Map<String, dynamic> _result({required List<String> dims, required List<Map<String, dynamic>> rows, String chart = 'bar', Map<String, num>? others}) => {
      'config': {'dimensions': dims, 'measures': ['subtotal', 'count'], 'chart': chart, 'compare': 'previous_period'},
      'columns': [
        for (final d in dims) {'key': d, 'label': d == 'month' ? 'Mois' : 'Client', 'kind': 'dimension', 'date': d == 'month' ? 'month' : null},
        {'key': 'subtotal', 'label': 'CA HT', 'kind': 'measure', 'format': 'money', 'additive': true},
        {'key': 'count', 'label': 'Nombre', 'kind': 'measure', 'format': 'number', 'additive': true},
      ],
      'rows': rows,
      'totals': {'subtotal': 600000, 'count': 6},
      'others': others,
      'previous': {'label': 'Période précédente', 'totals': {'subtotal': 400000, 'count': 4}},
      'period': {'label': 'Cette année'},
      'currency': 'XOF',
    };

void main() {
  test('une dimension : une série par mesure de même format, ligne « Autres » hors dates', () {
    final d = ReportData(_result(dims: ['partner'], rows: [
      _row({'partner': 'a'}, {'partner': 'Hôtel Ivoire'}, {'subtotal': 500000, 'count': 2}),
    ], others: {'subtotal': 100000, 'count': 4}));
    final c = chartData(d);
    expect(c.labels, ['Hôtel Ivoire', 'Autres']);
    expect(c.series.length, 1); // « Nombre » n'a pas le même format : jamais deux axes
    expect(c.series.first.values, [500000, 100000]);
    expect(c.othersLabel, isTrue);
    expect(d.delta('subtotal'), closeTo(50, 0.001));
  });

  test('deux dimensions : séries par valeur de la 2e, au-delà de 8 regroupées dans « Autres »', () {
    final rows = <Map<String, dynamic>>[];
    for (var c = 0; c < 10; c++) {
      for (final m in ['2026-01', '2026-02']) {
        rows.add(_row({'month': m, 'partner': 'p$c'}, {'month': m, 'partner': 'Client $c'}, {'subtotal': (c + 1) * 1000, 'count': 1}));
      }
    }
    final c = chartData(ReportData(_result(dims: ['month', 'partner'], rows: rows)));
    expect(c.labels, ['2026-01', '2026-02']);
    expect(c.series.length, 8);
    expect(c.series.first.name, 'Client 9'); // les plus gros d'abord
    expect(c.series.last.name, 'Autres');
    expect(c.series.last.others, isTrue);
    expect(c.series.last.values.first, 1000 + 2000 + 3000);
  });

  test('mise en forme des valeurs', () {
    expect(compactValue(1250000), '1,3 M');
    expect(compactValue(350000), '350 k');
    expect(formatValue(12.345, 'percent', 'XOF'), '12,3 %');
    expect(formatValue(null, 'money', 'XOF'), '—');
  });

  testWidgets('chaque présentation s\'affiche', (tester) async {
    final rows = [
      _row({'month': '2026-01', 'partner': 'a'}, {'month': 'janv. 2026', 'partner': 'Hôtel'}, {'subtotal': 200000, 'count': 2}),
      _row({'month': '2026-02', 'partner': 'a'}, {'month': 'févr. 2026', 'partner': 'Hôtel'}, {'subtotal': 300000, 'count': 3}),
      _row({'month': '2026-02', 'partner': 'b'}, {'month': 'févr. 2026', 'partner': 'Maquis'}, {'subtotal': 100000, 'count': 1}),
    ];
    for (final chart in ['table', 'bar', 'hbar', 'line', 'pie', 'kpi', 'pivot']) {
      final data = ReportData(_result(dims: chart == 'pivot' || chart == 'bar' ? ['month', 'partner'] : ['month'], rows: rows, chart: chart));
      await tester.pumpWidget(MaterialApp(home: Scaffold(body: SizedBox(width: 800, height: 420, child: SingleChildScrollView(child: SizedBox(height: 400, child: ReportView(data: data)))))));
      await tester.pump();
      expect(tester.takeException(), isNull, reason: chart);
    }
    expect(find.text('Total'), findsWidgets);
  });
}
