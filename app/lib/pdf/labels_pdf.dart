import 'package:barcode/barcode.dart';
import 'package:flutter/services.dart' show rootBundle;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import '../i18n/i18n.dart';
import '../core/json.dart';

/// Format d'étiquettes (dimensions en millimètres).
class LabelFormat {
  const LabelFormat({
    required this.id,
    required this.name,
    this.kind = 'sheet',
    this.pageW = 210,
    this.pageH = 297,
    this.cols = 1,
    this.rows = 1,
    required this.labelW,
    required this.labelH,
    this.marginTop = 0,
    this.marginLeft = 0,
    this.gapX = 0,
    this.gapY = 0,
    this.style = 'product',
    this.fields = const ['name', 'price', 'barcode'],
    this.custom = false,
  });

  final String id;
  final String name;
  final String kind; // sheet | roll
  final double pageW, pageH, labelW, labelH, marginTop, marginLeft, gapX, gapY;
  final int cols, rows;
  final String style; // product | shelf | barcode
  final List<String> fields;
  final bool custom;

  int get perPage => cols * rows;

  factory LabelFormat.fromMap(Map<String, dynamic> m) {
    double d(String k, double def) => (m[k] as num?)?.toDouble() ?? def;
    return LabelFormat(
      id: '${m['id']}',
      name: '${m['name']}',
      kind: '${m['kind'] ?? 'sheet'}',
      pageW: d('page_w', 210),
      pageH: d('page_h', 297),
      cols: (m['cols'] as num?)?.toInt() ?? 1,
      rows: (m['rows'] as num?)?.toInt() ?? 1,
      labelW: d('label_w', 70),
      labelH: d('label_h', 37),
      marginTop: d('margin_top', 0),
      marginLeft: d('margin_left', 0),
      gapX: d('gap_x', 0),
      gapY: d('gap_y', 0),
      style: '${m['style'] ?? 'product'}',
      fields: [for (final f in (m['fields'] as List? ?? const ['name', 'price', 'barcode'])) '$f'],
      custom: true,
    );
  }

  LabelFormat copyWith({String? style, List<String>? fields}) => LabelFormat(
        id: id,
        name: name,
        kind: kind,
        pageW: pageW,
        pageH: pageH,
        cols: cols,
        rows: rows,
        labelW: labelW,
        labelH: labelH,
        marginTop: marginTop,
        marginLeft: marginLeft,
        gapX: gapX,
        gapY: gapY,
        style: style ?? this.style,
        fields: fields ?? this.fields,
        custom: custom,
      );
}

/// Formats courants (planches Avery / Herma et rouleaux d'imprimantes thermiques).
const labelPresets = [
  LabelFormat(id: 'a4_24', name: 'A4 — 24 étiquettes 70 × 37 mm', cols: 3, rows: 8, labelW: 70, labelH: 37, marginTop: 0.5),
  LabelFormat(id: 'a4_21', name: 'A4 — 21 étiquettes 63,5 × 38,1 mm (L7160)', cols: 3, rows: 7, labelW: 63.5, labelH: 38.1, marginTop: 15.15, marginLeft: 7.21, gapX: 2.54),
  LabelFormat(id: 'a4_40', name: 'A4 — 40 étiquettes 52,5 × 29,7 mm', cols: 4, rows: 10, labelW: 52.5, labelH: 29.7),
  LabelFormat(id: 'a4_65', name: 'A4 — 65 étiquettes 38,1 × 21,2 mm (L7651)', cols: 5, rows: 13, labelW: 38.1, labelH: 21.2, marginTop: 10.7, marginLeft: 4.75, gapX: 2.5, fields: ['price', 'barcode']),
  LabelFormat(id: 'a4_shelf', name: 'A4 — étiquettes de rayon 70 × 37 mm', cols: 3, rows: 8, labelW: 70, labelH: 37, marginTop: 0.5, style: 'shelf', fields: ['name', 'price', 'unit_price', 'barcode', 'date']),
  LabelFormat(id: 'roll_50x30', name: 'Rouleau 50 × 30 mm', kind: 'roll', pageW: 50, pageH: 30, labelW: 50, labelH: 30),
  LabelFormat(id: 'roll_40x25', name: 'Rouleau 40 × 25 mm', kind: 'roll', pageW: 40, pageH: 25, labelW: 40, labelH: 25, fields: ['name', 'price', 'barcode']),
  LabelFormat(id: 'roll_58x40', name: 'Rouleau 58 × 40 mm (pesée)', kind: 'roll', pageW: 58, pageH: 40, labelW: 58, labelH: 40, fields: ['company', 'name', 'price', 'unit_price', 'barcode', 'date']),
  LabelFormat(id: 'roll_30x20', name: 'Rouleau 30 × 20 mm (code seul)', kind: 'roll', pageW: 30, pageH: 20, labelW: 30, labelH: 20, style: 'barcode', fields: ['barcode', 'sku']),
];

const labelFields = {
  'name': 'Désignation',
  'price': 'Prix TTC',
  'unit_price': 'Prix au kg / unité',
  'barcode': 'Code-barres',
  'sku': 'Référence',
  'category': 'Catégorie',
  'company': 'Nom de la boutique',
  'date': 'Date',
};

/// Contenu d'une étiquette.
class LabelData {
  const LabelData({required this.name, this.price, this.unitPrice, this.barcode, this.sku, this.category, this.company, this.date, this.weight});

  final String name;
  final String? price, unitPrice, barcode, sku, category, company, date, weight;
}

bool isEan13(String? s) {
  if (s == null || !RegExp(r'^\d{13}$').hasMatch(s)) return false;
  var sum = 0;
  for (var i = 0; i < 12; i++) {
    sum += int.parse(s[i]) * (i.isOdd ? 3 : 1);
  }
  return (10 - sum % 10) % 10 == int.parse(s[12]);
}

int ean13Check(String twelve) {
  var sum = 0;
  for (var i = 0; i < 12; i++) {
    sum += int.parse(twelve[i]) * (i.isOdd ? 3 : 1);
  }
  return (10 - sum % 10) % 10;
}

/// Étiquette de pesée : 21 + code balance (5) + poids en grammes (5) + clé.
String weightBarcode(String plu, double kg) {
  final twelve = '21${plu.padLeft(5, '0')}${(kg * 1000).round().toString().padLeft(5, '0')}';
  return '$twelve${ean13Check(twelve)}';
}

Barcode barcodeFor(String data) => isEan13(data) ? Barcode.ean13() : Barcode.code128();

const _mm = PdfPageFormat.mm;

Future<List<int>> labelsPdf({required LabelFormat format, required List<LabelData> labels, int skip = 0}) async {
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf'));
  final doc = pw.Document(theme: pw.ThemeData.withFont(base: regular, bold: pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf'))));
  final page = PdfPageFormat(format.pageW * _mm, format.pageH * _mm);
  final cells = <LabelData?>[...List<LabelData?>.filled(format.kind == 'sheet' ? skip : 0, null), ...labels];
  for (var start = 0; start < cells.length; start += format.perPage) {
    final chunk = cells.sublist(start, (start + format.perPage).clamp(0, cells.length));
    doc.addPage(pw.Page(
      pageFormat: page,
      margin: pw.EdgeInsets.zero,
      build: (_) => pw.Stack(children: [
        for (var i = 0; i < chunk.length; i++)
          if (chunk[i] != null)
            pw.Positioned(
              left: (format.marginLeft + (i % format.cols) * (format.labelW + format.gapX)) * _mm,
              top: (format.marginTop + (i ~/ format.cols) * (format.labelH + format.gapY)) * _mm,
              child: pw.SizedBox(width: format.labelW * _mm, height: format.labelH * _mm, child: _pdfLabel(format, chunk[i]!, regular)),
            ),
      ]),
    ));
  }
  return doc.save();
}

/// Tailles en millimètres, relatives à la hauteur de l'étiquette (mêmes valeurs pour l'aperçu à l'écran).
({double pad, double name, double price, double small, double barcode}) labelMetrics(LabelFormat f) {
  final h = f.labelH;
  final w = f.labelW;
  return (
    pad: (h * 0.06).clamp(1.0, 3.0).toDouble(),
    name: (h * 0.095).clamp(1.8, 6.0).toDouble(),
    price: clampSize(f.style == 'shelf' ? h * 0.27 : h * 0.16, 2.2, w * 0.16),
    small: (h * 0.065).clamp(1.5, 3.2).toDouble(),
    barcode: (h * (f.style == 'barcode' ? 0.55 : (f.style == 'shelf' ? 0.2 : 0.3))).clamp(4.0, 30.0).toDouble(),
  );
}

const _pt = 2.835; // points par millimètre

pw.Widget _pdfLabel(LabelFormat f, LabelData d, pw.Font font) {
  final m = labelMetrics(f);
  bool has(String k) => f.fields.contains(k);
  final shelf = f.style == 'shelf';
  final children = <pw.Widget>[
    if (has('company') && d.company != null) pw.Text(tr(d.company!), style: pw.TextStyle(fontSize: m.small * _pt), maxLines: 1),
    if (has('name')) pw.Text(tr(d.name), style: pw.TextStyle(fontSize: m.name * _pt, fontWeight: pw.FontWeight.bold), maxLines: 2, textAlign: shelf ? pw.TextAlign.left : pw.TextAlign.center),
    if (has('category') && d.category != null) pw.Text(tr(d.category!), style: pw.TextStyle(fontSize: m.small * _pt, color: PdfColors.grey700), maxLines: 1),
    if (d.weight != null) pw.Text(tr(d.weight!), style: pw.TextStyle(fontSize: m.small * _pt)),
    if (has('price') && d.price != null) pw.Text(tr(d.price!), style: pw.TextStyle(fontSize: m.price * _pt, fontWeight: pw.FontWeight.bold), maxLines: 1),
    if (has('unit_price') && d.unitPrice != null) pw.Text(tr(d.unitPrice!), style: pw.TextStyle(fontSize: m.small * _pt), maxLines: 1),
    pw.Spacer(),
    if (has('barcode') && d.barcode != null && d.barcode!.isNotEmpty)
      pw.BarcodeWidget(
        barcode: barcodeFor(d.barcode!),
        data: d.barcode!,
        height: m.barcode * _mm,
        width: (f.labelW - 2 * m.pad) * _mm * (f.style == 'barcode' ? 1 : (shelf ? 0.55 : 0.85)),
        textStyle: pw.TextStyle(font: font, fontSize: m.small * _pt),
      ),
    if ((has('sku') && d.sku != null) || (has('date') && d.date != null))
      pw.Row(mainAxisAlignment: pw.MainAxisAlignment.spaceBetween, children: [
        pw.Text(tr(has('sku') ? (d.sku ?? '') : ''), style: pw.TextStyle(fontSize: m.small * 0.9 * _pt)),
        pw.Text(tr(has('date') ? (d.date ?? '') : ''), style: pw.TextStyle(fontSize: m.small * 0.9 * _pt)),
      ]),
  ];
  return pw.Padding(
    padding: pw.EdgeInsets.all(m.pad * _mm),
    child: pw.Column(crossAxisAlignment: shelf ? pw.CrossAxisAlignment.start : pw.CrossAxisAlignment.center, children: children),
  );
}
