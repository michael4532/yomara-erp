import 'package:flutter/services.dart' show rootBundle;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import '../i18n/i18n.dart';

/// Section d'état comptable : titre facultatif, lignes, total facultatif.
class ReportSection {
  const ReportSection({this.title, required this.rows, this.total});

  final String? title;
  final List<List<String>> rows;
  final List<String>? total;
}

/// Imprime / exporte en PDF un état comptable (balance, bilan, résultat, TVA, grand livre).
Future<void> printReport({
  required String company,
  required String title,
  required String period,
  required List<String> headers,
  required List<ReportSection> sections,
  Map<int, pw.Alignment>? align,
  bool landscape = false,
  String? footnote,
}) async {
  final doc = pw.Document(
    theme: pw.ThemeData.withFont(
      base: pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf')),
      bold: pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf')),
    ),
  );
  final night = PdfColor.fromHex('#0B1B3A');
  final alignments = align ?? {for (var i = 1; i < headers.length; i++) i: pw.Alignment.centerRight};

  doc.addPage(pw.MultiPage(
    pageFormat: landscape ? PdfPageFormat.a4.landscape : PdfPageFormat.a4,
    margin: const pw.EdgeInsets.all(32),
    header: (ctx) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
      pw.Row(children: [
        pw.Expanded(child: pw.Text(tr(company), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 11))),
        pw.Text(tr('Page ${ctx.pageNumber}/${ctx.pagesCount}'), style: const pw.TextStyle(fontSize: 8, color: PdfColors.grey700)),
      ]),
      pw.SizedBox(height: 4),
      pw.Text(tr(title), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 16, color: night)),
      pw.Text(tr(period), style: const pw.TextStyle(fontSize: 9, color: PdfColors.grey700)),
      pw.SizedBox(height: 12),
    ]),
    footer: (_) => footnote == null ? pw.SizedBox() : pw.Text(tr(footnote), style: const pw.TextStyle(fontSize: 7, color: PdfColors.grey600)),
    build: (_) => [
      for (final s in sections) ...[
        if (s.title != null)
          pw.Padding(
            padding: const pw.EdgeInsets.only(top: 10, bottom: 4),
            child: pw.Text(tr(s.title!), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 11, color: night)),
          ),
        pw.TableHelper.fromTextArray(
          headers: headers,
          data: [...s.rows, if (s.total != null) s.total!],
          headerStyle: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 8, color: PdfColors.white),
          headerDecoration: pw.BoxDecoration(color: night),
          cellStyle: const pw.TextStyle(fontSize: 8),
          cellAlignments: alignments,
          rowDecoration: const pw.BoxDecoration(border: pw.Border(bottom: pw.BorderSide(color: PdfColors.grey300, width: .5))),
          cellPadding: const pw.EdgeInsets.symmetric(horizontal: 4, vertical: 3),
        ),
        if (s.total != null) pw.SizedBox(height: 2),
      ],
    ],
  ));

  await Printing.layoutPdf(name: '$title.pdf', onLayout: (_) async => doc.save());
}
