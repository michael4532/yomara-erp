import 'package:flutter/services.dart' show rootBundle;
import 'package:intl/intl.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

import '../state/session.dart';
import '../ui/widgets/common.dart';
import '../i18n/i18n.dart';
import '../ui/widgets/pdf_viewer.dart';

/// Feuille de route d'une tournée (arrêts dans l'ordre, montants à encaisser, cases de signature).
Future<void> printRoadSheet({
  required Session session,
  required Map<String, dynamic> tour,
  required List<Map<String, dynamic>> deliveries,
  String? driver,
  String? vehicle,
}) async {
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf'));
  final bold = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf'));
  final pdf = pw.Document(theme: pw.ThemeData.withFont(base: regular, bold: bold));
  final night = PdfColor.fromHex('#0B1B3A');
  final grey = PdfColor.fromHex('#5B6B85');
  final cur = session.currency;
  final date = DateTime.tryParse('${tour['date']}');
  final cod = deliveries.fold<double>(0, (s, d) => s + toDouble(d['cod_amount']));

  pdf.addPage(pw.MultiPage(
    pageFormat: PdfPageFormat.a4.landscape,
    margin: const pw.EdgeInsets.all(28),
    header: (ctx) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
      pw.Row(children: [
        pw.Expanded(
          child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
            pw.Text(tr('FEUILLE DE ROUTE'), style: pw.TextStyle(fontSize: 18, fontWeight: pw.FontWeight.bold, color: night)),
            pw.Text(tr('${session.company['name'] ?? ''}${tour['name'] != null ? ' · ${tour['name']}' : ''}'), style: pw.TextStyle(color: grey)),
          ]),
        ),
        pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.end, children: [
          pw.Text(tr(date == null ? '' : DateFormat('EEEE d MMMM yyyy', I18n.dateLocale).format(date)), style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
          pw.Text(tr('Livreur : ${driver ?? '—'}${vehicle != null ? ' · $vehicle' : ''}')),
          pw.Text(tr('${deliveries.length} arrêt(s) · à encaisser : ${money(cod, cur)}')),
        ]),
      ]),
      pw.SizedBox(height: 10),
    ]),
    footer: (ctx) => pw.Row(children: [
      pw.Text(tr('Départ : ____h____   Retour : ____h____   Km : ________   Montant remis : ______________   Signature responsable : ______________'), style: const pw.TextStyle(fontSize: 9)),
      pw.Spacer(),
      pw.Text(tr('${ctx.pageNumber}/${ctx.pagesCount}'), style: const pw.TextStyle(fontSize: 9)),
    ]),
    build: (ctx) => [
      pw.TableHelper.fromTextArray(
        headerStyle: pw.TextStyle(fontWeight: pw.FontWeight.bold, color: PdfColors.white, fontSize: 9),
        headerDecoration: pw.BoxDecoration(color: night),
        cellStyle: const pw.TextStyle(fontSize: 9),
        cellHeight: 34,
        columnWidths: {
          0: const pw.FixedColumnWidth(22),
          1: const pw.FixedColumnWidth(78),
          2: const pw.FlexColumnWidth(2.2),
          3: const pw.FlexColumnWidth(3),
          4: const pw.FlexColumnWidth(2.6),
          5: const pw.FixedColumnWidth(70),
          6: const pw.FixedColumnWidth(110),
        },
        cellAlignments: {0: pw.Alignment.center, 5: pw.Alignment.centerRight},
        headers: ['#', 'N°', 'Destinataire', 'Adresse', 'Contenu', 'À encaisser', 'Reçu par / signature'],
        data: [
          for (var i = 0; i < deliveries.length; i++)
            [
              '${i + 1}',
              '${deliveries[i]['number'] ?? '(provisoire)'}',
              '${deliveries[i]['recipient_name'] ?? ''}\n${deliveries[i]['recipient_phone'] ?? ''}${deliveries[i]['time_slot'] != null ? '\n${deliveries[i]['time_slot']}' : ''}',
              '${deliveries[i]['address'] ?? ''}${deliveries[i]['city'] != null ? ', ${deliveries[i]['city']}' : ''}${(deliveries[i]['notes'] ?? '').toString().isNotEmpty ? '\nNB : ${deliveries[i]['notes']}' : ''}',
              [
                for (final it in (deliveries[i]['items'] as List? ?? const []))
                  '${number(toDouble((it as Map)['quantity'] ?? 1), decimals: 0)} × ${it['description']}',
                if ((deliveries[i]['packages'] ?? 1) != 1) '${deliveries[i]['packages']} colis',
              ].join('\n'),
              toDouble(deliveries[i]['cod_amount']) > 0 ? money(toDouble(deliveries[i]['cod_amount']), cur) : '—',
              '',
            ],
        ],
      ),
    ],
  ));
  await previewPdf(name: 'Feuille de route ${tour['date']}.pdf', onLayout: (_) async => pdf.save());
}
