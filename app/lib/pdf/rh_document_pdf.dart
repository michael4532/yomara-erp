import 'package:flutter/services.dart' show rootBundle, Uint8List;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import '../i18n/i18n.dart';
import '../ui/widgets/pdf_viewer.dart';

const _months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

String longDateFr(String? iso) {
  final d = DateTime.tryParse(iso ?? '');
  if (d == null) return '[à compléter]';
  return '${d.day == 1 ? '1er' : d.day} ${_months[d.month - 1]} ${d.year}';
}

String _money(num v) {
  final s = v.round().abs().toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(' ');
    b.write(s[i]);
  }
  return '${v < 0 ? '-' : ''}$b F CFA';
}

/// Document RH sur papier à en-tête : attestation, certificat, contrat, avertissement, solde de tout compte.
Future<Uint8List> rhDocumentPdf({required Map<String, dynamic> company, required Map<String, dynamic> doc, required String employeeName, String? signatory, String? signatoryTitle, String partyLabel = 'Le Salarié', String companyLabel = 'L\'Employeur'}) async {
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf'));
  final bold = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf'));
  final night = PdfColor.fromHex('#0B1B3A');
  final yellow = PdfColor.fromHex('#FFC531');
  final pdf = pw.Document(version: PdfVersion.pdf_1_4, theme: pw.ThemeData.withFont(base: regular, bold: bold));
  final type = '${doc['type']}';
  final city = '${company['city'] ?? 'Abidjan'}';
  final cancelled = doc['status'] == 'cancelled';

  List<pw.Widget> paragraphs(String text) {
    final out = <pw.Widget>[];
    for (final para in text.split(RegExp(r'\n\s*\n'))) {
      final p = para.trim();
      if (p.isEmpty) continue;
      final lines = p.split('\n');
      for (final l in lines) {
        final t = l.trim();
        final m = RegExp(r'^\*\*(.+)\*\*$').firstMatch(t);
        if (m != null) {
          out.add(pw.Padding(padding: const pw.EdgeInsets.only(top: 6, bottom: 2), child: pw.Text(tr(m.group(1)!), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 11))));
        } else if (t.isNotEmpty) {
          // gras en ligne : **texte**
          final spans = <pw.TextSpan>[];
          var rest = t;
          final re = RegExp(r'\*\*(.+?)\*\*');
          while (true) {
            final mm = re.firstMatch(rest);
            if (mm == null) {
              spans.add(pw.TextSpan(text: rest));
              break;
            }
            spans.add(pw.TextSpan(text: rest.substring(0, mm.start)));
            spans.add(pw.TextSpan(text: mm.group(1), style: pw.TextStyle(fontWeight: pw.FontWeight.bold)));
            rest = rest.substring(mm.end);
          }
          out.add(pw.Padding(padding: const pw.EdgeInsets.only(bottom: 2), child: pw.RichText(textAlign: pw.TextAlign.justify, text: pw.TextSpan(children: spans, style: const pw.TextStyle(fontSize: 10.5, lineSpacing: 3)))));
        }
      }
      out.add(pw.SizedBox(height: 8));
    }
    return out;
  }

  pw.Widget signature() {
    final who = pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.center, children: [
      pw.Text(tr(signatoryTitle ?? 'Pour la société'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
      pw.SizedBox(height: 46),
      pw.Text(tr(signatory ?? '')),
    ]);
    if (type == 'contract' || type == 'stc') {
      return pw.Row(mainAxisAlignment: pw.MainAxisAlignment.spaceBetween, crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
        pw.Column(children: [pw.Text(tr(partyLabel), style: pw.TextStyle(fontWeight: pw.FontWeight.bold)), pw.Text(tr('(lu et approuvé${type == 'stc' ? ', bon pour solde de tout compte' : ''})'), style: const pw.TextStyle(fontSize: 8)), pw.SizedBox(height: 40), pw.Text(tr(employeeName))]),
        pw.Column(children: [pw.Text(tr(companyLabel), style: pw.TextStyle(fontWeight: pw.FontWeight.bold)), pw.Text(tr(signatoryTitle ?? ''), style: const pw.TextStyle(fontSize: 8)), pw.SizedBox(height: 40), pw.Text(tr(signatory ?? ''))]),
      ]);
    }
    if (type == 'warning') {
      return pw.Row(mainAxisAlignment: pw.MainAxisAlignment.spaceBetween, crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
        pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [pw.Text(tr('Reçu en main propre le ____________'), style: const pw.TextStyle(fontSize: 9)), pw.SizedBox(height: 30), pw.Text(tr('Signature du salarié'), style: const pw.TextStyle(fontSize: 9))]),
        who,
      ]);
    }
    return pw.Align(alignment: pw.Alignment.centerRight, child: who);
  }

  pdf.addPage(pw.MultiPage(
    pageFormat: PdfPageFormat.a4,
    margin: const pw.EdgeInsets.fromLTRB(48, 40, 48, 40),
    header: (ctx) => ctx.pageNumber > 1
        ? pw.SizedBox()
        : pw.Column(children: [
            pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
              pw.Container(width: 6, height: 54, color: yellow),
              pw.SizedBox(width: 10),
              pw.Expanded(
                child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
                  pw.Text(tr('${company['name'] ?? ''}'), style: pw.TextStyle(fontSize: 16, fontWeight: pw.FontWeight.bold, color: night)),
                  pw.Text(tr([company['address'], company['city']].where((e) => e != null && '$e'.isNotEmpty).join(' — ')), style: const pw.TextStyle(fontSize: 9)),
                  pw.Text(tr([if (company['phone'] != null) 'Tél. ${company['phone']}', if (company['email'] != null) '${company['email']}'].join(' · ')), style: const pw.TextStyle(fontSize: 9)),
                  pw.Text(tr([if (company['trade_register'] != null) 'RCCM ${company['trade_register']}', if (company['tax_id'] != null) 'NCC ${company['tax_id']}'].join(' · ')), style: const pw.TextStyle(fontSize: 9)),
                ]),
              ),
              if (doc['number'] != null) pw.Text(tr('Réf. ${doc['number']}'), style: const pw.TextStyle(fontSize: 9)),
            ]),
            pw.SizedBox(height: 26),
          ]),
    footer: (ctx) => pw.Row(mainAxisAlignment: pw.MainAxisAlignment.spaceBetween, children: [
      pw.Text(tr('${company['name'] ?? ''}${doc['number'] != null ? ' — ${doc['number']}' : ''}'), style: const pw.TextStyle(fontSize: 7, color: PdfColors.grey600)),
      pw.Text(tr('Page ${ctx.pageNumber} / ${ctx.pagesCount}'), style: const pw.TextStyle(fontSize: 7, color: PdfColors.grey600)),
    ]),
    build: (ctx) => [
      if (cancelled) pw.Container(padding: const pw.EdgeInsets.all(6), color: PdfColors.red50, child: pw.Text(tr('DOCUMENT ANNULÉ${doc['note'] != null ? ' — ${doc['note']}' : ''}'), style: pw.TextStyle(color: PdfColors.red800, fontWeight: pw.FontWeight.bold))),
      if (type == 'warning') pw.Align(alignment: pw.Alignment.centerRight, child: pw.Padding(padding: const pw.EdgeInsets.only(bottom: 16), child: pw.Text(tr('$employeeName\n(remis en main propre)'), textAlign: pw.TextAlign.right))),
      pw.Center(child: pw.Text(tr('${doc['title'] ?? ''}'.toUpperCase()), textAlign: pw.TextAlign.center, style: pw.TextStyle(fontSize: 15, fontWeight: pw.FontWeight.bold, color: night, letterSpacing: 1))),
      pw.SizedBox(height: 22),
      ...paragraphs('${doc['body'] ?? ''}'),
      if (type == 'stc' && doc['data'] is Map) ...[
        pw.TableHelper.fromTextArray(
          headers: ['Désignation', 'Montant'],
          data: [
            for (final l in ((doc['data']['lines'] as List?) ?? [])) ['${l['label']}', _money(l['amount'] as num)],
            ['NET À PAYER', _money((doc['data']['total'] as num?) ?? 0)],
          ],
          headerStyle: pw.TextStyle(fontWeight: pw.FontWeight.bold, color: PdfColors.white, fontSize: 9.5),
          headerDecoration: pw.BoxDecoration(color: night),
          cellStyle: const pw.TextStyle(fontSize: 9.5),
          cellAlignments: {1: pw.Alignment.centerRight},
          columnWidths: {0: const pw.FlexColumnWidth(3), 1: const pw.FlexColumnWidth(1.2)},
        ),
        pw.SizedBox(height: 14),
      ],
      if (type != 'contract') pw.Padding(padding: const pw.EdgeInsets.only(top: 8, bottom: 18), child: pw.Text(tr('Fait à $city, le ${longDateFr('${doc['date']}')}.'))),
      if (type == 'contract') pw.SizedBox(height: 10),
      signature(),
    ],
  ));
  return pdf.save();
}

Future<void> printRhDocument({required Map<String, dynamic> company, required Map<String, dynamic> doc, required String employeeName, String? signatory, String? signatoryTitle}) async {
  final bytes = await rhDocumentPdf(company: company, doc: doc, employeeName: employeeName, signatory: signatory, signatoryTitle: signatoryTitle);
  await previewPdf(name: '${doc['title']} ${doc['number'] ?? ''} $employeeName.pdf', onLayout: (_) async => bytes);
}
