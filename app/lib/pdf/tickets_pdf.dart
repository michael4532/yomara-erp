import 'package:barcode/barcode.dart';
import 'package:flutter/services.dart' show rootBundle;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import '../i18n/i18n.dart';

PdfColor _hex(dynamic hex, PdfColor fallback) {
  final h = '${hex ?? ''}'.replaceAll('#', '');
  final v = h.length == 6 ? int.tryParse(h, radix: 16) : null;
  return v == null ? fallback : PdfColor.fromInt(0xFF000000 | v);
}

/// Billets et badges : 8 par page A4 (95 × 68 mm), découpables, avec QR code, ou un billet par page (ticket 80 mm).
Future<List<int>> ticketsPdf({
  required Map<String, dynamic> event,
  required List<Map<String, dynamic>> tickets,
  required Map<String, Map<String, dynamic>> types,
  required String company,
  bool roll = false,
}) async {
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf'));
  final bold = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf'));
  final doc = pw.Document(theme: pw.ThemeData.withFont(base: regular, bold: bold));
  const kinds = {'visitor': 'VISITEUR', 'vip': 'VIP', 'exhibitor': 'EXPOSANT', 'staff': 'ORGANISATION', 'press': 'PRESSE'};
  String fr(dynamic iso) {
    final s = '${iso ?? ''}';
    return s.length >= 10 ? '${s.substring(8, 10)}/${s.substring(5, 7)}/${s.substring(0, 4)}' : '';
  }

  pw.Widget card(Map<String, dynamic> t, {double w = 95, double h = 68}) {
    final type = types['${t['type_id']}'];
    final color = _hex(type?['color'], t['kind'] == 'exhibitor' ? PdfColor.fromInt(0xFF12A150) : (t['kind'] == 'vip' ? PdfColor.fromInt(0xFF8E44AD) : PdfColor.fromInt(0xFF1F6FEB)));
    final validity = t['valid_from'] == null ? '' : (t['valid_from'] == t['valid_to'] ? 'Le ${fr(t['valid_from'])}' : 'Du ${fr(t['valid_from'])} au ${fr(t['valid_to'])}');
    return pw.Container(
      width: w * PdfPageFormat.mm,
      height: h * PdfPageFormat.mm,
      decoration: pw.BoxDecoration(border: pw.Border.all(color: PdfColors.grey400, width: .5)),
      child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.stretch, children: [
        pw.Container(
          color: color,
          padding: const pw.EdgeInsets.symmetric(horizontal: 8, vertical: 5),
          child: pw.Row(children: [
            pw.Expanded(child: pw.Text(tr('${event['name']}'), maxLines: 1, style: pw.TextStyle(color: PdfColors.white, fontWeight: pw.FontWeight.bold, fontSize: 9))),
            pw.Text(tr(kinds[t['kind']] ?? 'BILLET'), style: pw.TextStyle(color: PdfColors.white, fontWeight: pw.FontWeight.bold, fontSize: 9)),
          ]),
        ),
        pw.Expanded(
          child: pw.Padding(
            padding: const pw.EdgeInsets.all(7),
            child: pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.center, children: [
              pw.Expanded(
                child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, mainAxisAlignment: pw.MainAxisAlignment.center, children: [
                  pw.Text(tr('${t['holder_name'] ?? ''}'), maxLines: 2, style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 14)),
                  if (t['company_name'] != null) pw.Text(tr('${t['company_name']}'), maxLines: 1, style: const pw.TextStyle(fontSize: 9.5)),
                  pw.SizedBox(height: 4),
                  pw.Text(tr('${type?['name'] ?? ''}'), maxLines: 1, style: pw.TextStyle(fontSize: 9, color: color, fontWeight: pw.FontWeight.bold)),
                  pw.Text(tr(validity), style: const pw.TextStyle(fontSize: 8, color: PdfColors.grey700)),
                  if (event['venue'] != null) pw.Text(tr('${event['venue']}'), maxLines: 1, style: const pw.TextStyle(fontSize: 8, color: PdfColors.grey700)),
                  pw.Spacer(),
                  pw.Text(tr('${t['code']}'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 11, letterSpacing: 1.5)),
                ]),
              ),
              pw.SizedBox(width: 6),
              pw.BarcodeWidget(barcode: Barcode.qrCode(), data: '${t['token']}', width: 30 * PdfPageFormat.mm, height: 30 * PdfPageFormat.mm),
            ]),
          ),
        ),
        pw.Container(
          padding: const pw.EdgeInsets.symmetric(horizontal: 8, vertical: 2),
          child: pw.Text(tr(company), style: const pw.TextStyle(fontSize: 6.5, color: PdfColors.grey600)),
        ),
      ]),
    );
  }

  if (roll) {
    for (final t in tickets) {
      doc.addPage(pw.Page(pageFormat: const PdfPageFormat(80 * PdfPageFormat.mm, 60 * PdfPageFormat.mm), margin: pw.EdgeInsets.zero, build: (_) => card(t, w: 80, h: 60)));
    }
  } else {
    for (var i = 0; i < tickets.length; i += 8) {
      final chunk = tickets.sublist(i, i + 8 > tickets.length ? tickets.length : i + 8);
      doc.addPage(pw.Page(
        pageFormat: PdfPageFormat.a4,
        margin: const pw.EdgeInsets.symmetric(horizontal: 10 * PdfPageFormat.mm, vertical: 12.5 * PdfPageFormat.mm),
        build: (_) => pw.Wrap(children: [for (final t in chunk) card(t)]),
      ));
    }
  }
  return doc.save();
}

/// Formats de ticket des bornes d'accueil.
const kioskPaperFormats = {'roll80': 'Rouleau 80 mm (imprimante ticket)', 'roll58': 'Rouleau 58 mm', 'a6': 'A6 (carte 10 × 15 cm)', 'a4': 'A4'};

/// Référence courte d'un billet de borne : « 010042 » affiché « 01-0042 ».
String kioskRef(String code) => RegExp(r'^\d{6,}$').hasMatch(code) ? '${code.substring(0, 2)}-${code.substring(2)}' : code;

/// Ticket imprimé par la borne : événement, visiteur, numéro à retaper les jours suivants, QR code, date et heure d'entrée.
Future<List<int>> kioskTicketPdf({
  required Map<String, dynamic> event,
  required Map<String, dynamic> ticket,
  required String company,
  required String kiosk,
  required DateTime at,
  String? city,
  String format = 'roll80',
  bool returning = false,
}) async {
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf'));
  final bold = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf'));
  final doc = pw.Document(theme: pw.ThemeData.withFont(base: regular, bold: bold));
  String fr(dynamic iso) {
    final s = '${iso ?? ''}';
    return s.length >= 10 ? '${s.substring(8, 10)}/${s.substring(5, 7)}/${s.substring(0, 4)}' : '';
  }

  const days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
  String two(int n) => n.toString().padLeft(2, '0');
  final when = '${days[at.weekday - 1]} ${two(at.day)}/${two(at.month)}/${at.year} à ${two(at.hour)}:${two(at.minute)}';
  final narrow = format == 'roll58';
  final page = switch (format) {
    'roll58' => const PdfPageFormat(58 * PdfPageFormat.mm, double.infinity, marginAll: 3 * PdfPageFormat.mm),
    'a6' => PdfPageFormat.a6.copyWith(marginLeft: 8 * PdfPageFormat.mm, marginRight: 8 * PdfPageFormat.mm, marginTop: 8 * PdfPageFormat.mm, marginBottom: 8 * PdfPageFormat.mm),
    'a4' => PdfPageFormat.a4.copyWith(marginLeft: 30 * PdfPageFormat.mm, marginRight: 30 * PdfPageFormat.mm, marginTop: 25 * PdfPageFormat.mm, marginBottom: 25 * PdfPageFormat.mm),
    _ => const PdfPageFormat(80 * PdfPageFormat.mm, double.infinity, marginAll: 4 * PdfPageFormat.mm),
  };
  final s = narrow ? .8 : (format == 'a4' ? 1.5 : 1.0);
  final validity = event['start_date'] == null
      ? ''
      : (event['end_date'] == null || event['end_date'] == event['start_date'] ? 'Le ${fr(event['start_date'])}' : 'Du ${fr(event['start_date'])} au ${fr(event['end_date'])}');
  final ref = kioskRef('${ticket['code']}');
  pw.Widget rule() => pw.Padding(padding: pw.EdgeInsets.symmetric(vertical: 5 * s), child: pw.Container(height: .8, color: PdfColors.black));
  doc.addPage(pw.Page(
    pageFormat: page,
    build: (_) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.center, mainAxisSize: pw.MainAxisSize.min, children: [
      pw.Text(tr(company), textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 8 * s)),
      pw.SizedBox(height: 2 * s),
      pw.Text(tr('${event['name'] ?? ''}'), textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 13 * s, fontWeight: pw.FontWeight.bold)),
      pw.Text(tr([validity, if (event['venue'] != null) '${event['venue']}', if (event['city'] != null) '${event['city']}'].where((x) => x.isNotEmpty).join(' · ')),
          textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 8 * s)),
      rule(),
      pw.Text(tr(returning ? 'ENTRÉE DU JOUR — VISITEUR' : 'TICKET D\'ENTRÉE — VISITEUR'), style: pw.TextStyle(fontSize: 9 * s, fontWeight: pw.FontWeight.bold, letterSpacing: 1)),
      pw.SizedBox(height: 4 * s),
      pw.Text(tr('${ticket['holder_name'] ?? ''}'), textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 16 * s, fontWeight: pw.FontWeight.bold)),
      if (ticket['company_name'] != null) pw.Text(tr('${ticket['company_name']}'), textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 9 * s)),
      if (city != null && city.isNotEmpty) pw.Text(tr(city), textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 9 * s)),
      pw.SizedBox(height: 6 * s),
      pw.Text(tr('Votre numéro'), style: pw.TextStyle(fontSize: 8 * s)),
      pw.Container(
        margin: pw.EdgeInsets.only(top: 2 * s),
        padding: pw.EdgeInsets.symmetric(horizontal: 10 * s, vertical: 4 * s),
        decoration: pw.BoxDecoration(border: pw.Border.all(width: 1.4), borderRadius: pw.BorderRadius.circular(4)),
        child: pw.Text(tr(ref), style: pw.TextStyle(fontSize: 24 * s, fontWeight: pw.FontWeight.bold, letterSpacing: 2)),
      ),
      pw.SizedBox(height: 6 * s),
      pw.BarcodeWidget(barcode: Barcode.qrCode(), data: '${ticket['code']}', width: (narrow ? 30 : 36) * s * PdfPageFormat.mm, height: (narrow ? 30 : 36) * s * PdfPageFormat.mm),
      pw.SizedBox(height: 5 * s),
      pw.Text(tr('Entrée : $when'), textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 8.5 * s, fontWeight: pw.FontWeight.bold)),
      pw.Text(tr(kiosk), style: pw.TextStyle(fontSize: 7.5 * s)),
      rule(),
      pw.Text(tr('Les jours suivants : tapez votre numéro sur la borne (ou présentez ce QR code) pour entrer sans vous réinscrire.'),
          textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 7.5 * s)),
    ]),
  ));
  return doc.save();
}
