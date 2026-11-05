import 'dart:typed_data';

import 'package:flutter/services.dart' show rootBundle;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

import '../core/fne.dart';
import '../state/session.dart';
import '../printing/receipt_escpos.dart' show paymentMethodLabels, kitchenCourseLabel, qtyLabel;
import '../ui/widgets/common.dart';
import '../i18n/i18n.dart';
import '../core/json.dart';

const _methodLabels = paymentMethodLabels;

Future<pw.Document> _doc() async => pw.Document(
      theme: pw.ThemeData.withFont(
        base: pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf')),
        bold: pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf')),
      ),
    );

pw.Widget _row(String a, String b, {bool bold = false, double size = 8}) => pw.Row(children: [
      pw.Expanded(child: pw.Text(tr(a), style: pw.TextStyle(fontSize: size, fontWeight: bold ? pw.FontWeight.bold : null))),
      pw.Text(tr(b), style: pw.TextStyle(fontSize: size, fontWeight: bold ? pw.FontWeight.bold : null)),
    ]);

pw.Widget _header(Session s) {
  final c = s.company;
  return pw.Column(children: [
    pw.Text(tr('${c['name'] ?? ''}'), style: pw.TextStyle(fontSize: 12, fontWeight: pw.FontWeight.bold), textAlign: pw.TextAlign.center),
    for (final l in [c['address'], c['city'], c['phone'] != null ? 'Tél. ${c['phone']}' : null, c['tax_id'] != null ? 'NCC ${c['tax_id']}' : null])
      if (l != null) pw.Text(tr('$l'), style: const pw.TextStyle(fontSize: 8), textAlign: pw.TextAlign.center),
  ]);
}

/// Ticket de caisse au format rouleau (80 ou 58 mm), généré hors ligne.
Future<Uint8List> receiptPdf({
  required Session session,
  required Map<String, dynamic> doc,
  required List<Map<String, dynamic>> payments,
  double change = 0,
  String? registerName,
  PdfPageFormat format = PdfPageFormat.roll80,
}) async {
  final cur = session.currency;
  final pdf = await _doc();
  final lines = List<Map<String, dynamic>>.from((doc['lines'] as List? ?? []).map((e) => Map<String, dynamic>.from(e)));

  pdf.addPage(pw.Page(
    pageFormat: format,
    margin: const pw.EdgeInsets.all(8),
    build: (_) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.stretch, children: [
      _header(session),
      pw.SizedBox(height: 6),
      pw.Text(tr('Ticket ${doc['number'] ?? doc['reference'] ?? ''}'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 9), textAlign: pw.TextAlign.center),
      pw.Text(tr('${dateTimeFr(doc['created_at'] ?? DateTime.now().toIso8601String())}${registerName != null ? ' · $registerName' : ''}'),
          style: const pw.TextStyle(fontSize: 7), textAlign: pw.TextAlign.center),
      pw.Text(tr('Caissier : ${session.user['name'] ?? ''}'), style: const pw.TextStyle(fontSize: 7), textAlign: pw.TextAlign.center),
      if (doc['partner_name'] != null) pw.Text(tr('Client : ${doc['partner_name']}'), style: const pw.TextStyle(fontSize: 7), textAlign: pw.TextAlign.center),
      pw.Divider(borderStyle: pw.BorderStyle.dashed),
      for (final l in lines) ...[
        pw.Text(tr('${l['description']}'), style: const pw.TextStyle(fontSize: 8)),
        _row('  ${qty(l['quantity'] as num?)} × ${money(toDouble(l['unit_price']) * (1 + toDouble(l['tax_rate']) / 100), cur)}', money(toDouble(l['total']), cur)),
      ],
      pw.Divider(borderStyle: pw.BorderStyle.dashed),
      _row('Total HT', money(toDouble(doc['subtotal']), cur)),
      _row('TVA', money(toDouble(doc['tax_total']), cur)),
      _row('TOTAL TTC', money(toDouble(doc['total']), cur), bold: true, size: 11),
      pw.SizedBox(height: 4),
      for (final p in payments) _row(_methodLabels[p['method']] ?? '${p['method']}', money(toDouble(p['amount']) + (p['method'] == 'cash' ? change : 0), cur)),
      if (change > 0) _row('Monnaie rendue', money(change, cur), bold: true),
      pw.SizedBox(height: 8),
      ...() {
        final fne = fneCertFor(doc['id'] as String?);
        if (fne == null) return <pw.Widget>[];
        if (fne['status'] != 'certified') return <pw.Widget>[pw.Text(tr('Certification FNE en cours'), style: const pw.TextStyle(fontSize: 7), textAlign: pw.TextAlign.center)];
        return <pw.Widget>[
          pw.Text(tr('Facture normalisée électronique DGI'), style: pw.TextStyle(fontSize: 7, fontWeight: pw.FontWeight.bold), textAlign: pw.TextAlign.center),
          if (fne['reference'] != null) pw.Text(tr('N° FNE ${fne['reference']}'), style: const pw.TextStyle(fontSize: 7), textAlign: pw.TextAlign.center),
          if (fne['verify_url'] != null) pw.Center(child: pw.Padding(padding: const pw.EdgeInsets.symmetric(vertical: 4), child: pw.BarcodeWidget(barcode: pw.Barcode.qrCode(), data: '${fne['verify_url']}', width: 70, height: 70, drawText: false))),
        ];
      }(),
      pw.Text(tr('${session.companySettings['receipt_footer'] ?? 'Merci de votre visite !'}'), style: const pw.TextStyle(fontSize: 8), textAlign: pw.TextAlign.center),
      if (doc['number'] == null) pw.Text(tr('N° définitif attribué à la synchronisation'), style: const pw.TextStyle(fontSize: 6), textAlign: pw.TextAlign.center),
    ]),
  ));

  return pdf.save();
}

/// Rapport de clôture (rapport Z) au format 80 mm.
Future<Uint8List> zReportPdf({
  required Session session,
  required Map<String, dynamic> report,
  required String registerName,
  PdfPageFormat format = PdfPageFormat.roll80,
}) async {
  final cur = session.currency;
  final pdf = await _doc();
  final byMethod = asMap(report['by_method']);
  pdf.addPage(pw.Page(
    pageFormat: format,
    margin: const pw.EdgeInsets.all(8),
    build: (_) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.stretch, children: [
      _header(session),
      pw.SizedBox(height: 6),
      pw.Text(tr('RAPPORT DE CAISSE (Z)'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10), textAlign: pw.TextAlign.center),
      pw.Text(tr('$registerName · ${report['user_name'] ?? ''}'), style: const pw.TextStyle(fontSize: 7), textAlign: pw.TextAlign.center),
      pw.Text(tr('Du ${dateTimeFr(report['opened_at'])} au ${dateTimeFr(report['closed_at'])}'), style: const pw.TextStyle(fontSize: 7), textAlign: pw.TextAlign.center),
      pw.Divider(borderStyle: pw.BorderStyle.dashed),
      _row('Tickets', '${report['tickets_count']}'),
      _row('Ventes TTC', money(toDouble(report['total_sales']), cur), bold: true),
      _row('dont TVA', money(toDouble(report['tax_total']), cur)),
      pw.Divider(borderStyle: pw.BorderStyle.dashed),
      for (final e in byMethod.entries) _row(_methodLabels[e.key] ?? e.key, money(toDouble(e.value['total']), cur)),
      pw.Divider(borderStyle: pw.BorderStyle.dashed),
      _row('Fond de caisse', money(toDouble(report['opening_cash']), cur)),
      _row('Espèces attendues', money(toDouble(report['expected_cash']), cur)),
      _row('Espèces comptées', money(toDouble(report['counted_cash']), cur)),
      _row('Écart', money(toDouble(report['difference']), cur), bold: true),
    ]),
  ));
  return pdf.save();
}

/// Page de test pour les réglages d'imprimante.
Future<Uint8List> receiptTestPdf(String companyName, PdfPageFormat format) async {
  final pdf = await _doc();
  pdf.addPage(pw.Page(
    pageFormat: format,
    margin: const pw.EdgeInsets.all(8),
    build: (_) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.stretch, children: [
      pw.Text(tr(companyName), style: pw.TextStyle(fontSize: 12, fontWeight: pw.FontWeight.bold), textAlign: pw.TextAlign.center),
      pw.Text(tr('Test d\'impression'), textAlign: pw.TextAlign.center),
      pw.Text(tr(dateTimeFr(DateTime.now().toIso8601String())), style: const pw.TextStyle(fontSize: 8), textAlign: pw.TextAlign.center),
      pw.Divider(borderStyle: pw.BorderStyle.dashed),
      _row('Montant', money(12500, 'XOF'), bold: true),
      pw.SizedBox(height: 8),
      pw.Text(tr('Si ce ticket est lisible, l\'imprimante est prête.'), style: const pw.TextStyle(fontSize: 8), textAlign: pw.TextAlign.center),
    ]),
  ));
  return pdf.save();
}

/// Bon de préparation cuisine / bar (PDF) — même contenu que la version ESC/POS.
Future<Uint8List> kitchenTicketPdf({required String station, required String title, required String subtitle, required List<Map<String, dynamic>> lines, PdfPageFormat format = PdfPageFormat.roll80}) async {
  final pdf = await _doc();
  int? course;
  final rows = <pw.Widget>[];
  for (final l in lines) {
    if (l['course'] != course) {
      course = l['course'] as int?;
      rows.add(pw.Padding(padding: const pw.EdgeInsets.only(top: 4), child: pw.Text(tr(kitchenCourseLabel(course).toUpperCase()), style: pw.TextStyle(fontSize: 8, fontWeight: pw.FontWeight.bold))));
    }
    rows.add(pw.Text(tr('${qtyLabel(l['quantity'])} x ${l['description']}'), style: pw.TextStyle(fontSize: 12, fontWeight: pw.FontWeight.bold)));
    if ((l['note'] ?? '').toString().isNotEmpty) rows.add(pw.Text(tr('   > ${l['note']}'), style: const pw.TextStyle(fontSize: 9)));
  }
  pdf.addPage(pw.Page(
    pageFormat: format,
    margin: const pw.EdgeInsets.all(8),
    build: (_) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.stretch, children: [
      pw.Text(tr(station.toUpperCase()), style: pw.TextStyle(fontSize: 9, fontWeight: pw.FontWeight.bold), textAlign: pw.TextAlign.center),
      pw.Text(tr(title), style: pw.TextStyle(fontSize: 18, fontWeight: pw.FontWeight.bold), textAlign: pw.TextAlign.center),
      pw.Text(tr(subtitle), style: const pw.TextStyle(fontSize: 8), textAlign: pw.TextAlign.center),
      pw.Divider(borderStyle: pw.BorderStyle.dashed),
      ...rows,
      pw.Divider(borderStyle: pw.BorderStyle.dashed),
      pw.Text(tr(dateTimeFr(DateTime.now().toIso8601String())), style: const pw.TextStyle(fontSize: 7), textAlign: pw.TextAlign.center),
    ]),
  ));
  return pdf.save();
}
