import 'package:flutter/services.dart' show rootBundle, Uint8List;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../ui/widgets/common.dart';
import '../i18n/i18n.dart';

const _months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/// Décimales utiles d'un taux : 2 › « 2 % », 6.3 › « 6,3 % », 0.75 › « 0,75 % ».
int rateDecimals(num v) {
  final c = (v * 100).round();
  return c % 100 == 0 ? 0 : (c % 10 == 0 ? 1 : 2);
}

String periodLabel(String period) {
  final p = period.split('-');
  return '${_months[int.parse(p[1]) - 1]} ${p[0]}';
}

/// Bulletins de paie (un par page) : un salarié ou tout le lot.
Future<void> printPayslips({required Map<String, dynamic> company, required String currency, required String period, required List<Map<String, dynamic>> payslips, String? title}) async {
  final bytes = await buildPayslipsPdf(company: company, currency: currency, period: period, payslips: payslips);
  final name = title ?? (payslips.length == 1 ? 'Bulletin ${payslips.first['employee']['full_name']} ${periodLabel(period)}' : 'Bulletins ${periodLabel(period)}');
  await Printing.layoutPdf(name: '$name.pdf', onLayout: (_) async => bytes);
}

Future<Uint8List> buildPayslipsPdf({required Map<String, dynamic> company, required String currency, required String period, required List<Map<String, dynamic>> payslips}) async {
  final pdf = pw.Document(
    theme: pw.ThemeData.withFont(base: pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf')), bold: pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf'))),
  );
  for (final p in payslips) {
    pdf.addPage(_page(company, currency, period, p));
  }
  return pdf.save();
}

pw.Page _page(Map<String, dynamic> company, String currency, String period, Map<String, dynamic> slip) {
  final night = PdfColor.fromHex('#0B1B3A');
  final yellow = PdfColor.fromHex('#FFC531');
  final grey = PdfColor.fromHex('#5B6B85');
  final e = Map<String, dynamic>.from(slip['employee']);
  final r = Map<String, dynamic>.from(slip['result']);
  final t = Map<String, dynamic>.from(r['totals']);
  String m(num? v) => v == null ? '' : number(v);
  String rate(num? v) => v == null ? '' : '${number(v, decimals: rateDecimals(v))} %';

  const small = pw.TextStyle(fontSize: 8);
  final head = pw.TextStyle(fontSize: 8, fontWeight: pw.FontWeight.bold, color: PdfColors.white);

  pw.Widget cell(String s, {bool right = false, bool bold = false}) => pw.Padding(
    padding: const pw.EdgeInsets.symmetric(horizontal: 4, vertical: 3),
    child: pw.Text(
      tr(s),
      textAlign: right ? pw.TextAlign.right : pw.TextAlign.left,
      style: pw.TextStyle(fontSize: 8, fontWeight: bold ? pw.FontWeight.bold : null),
    ),
  );

  pw.TableRow row(String label, {String base = '', String rateTxt = '', String gain = '', String ret = '', bool bold = false, PdfColor? bg}) => pw.TableRow(
    decoration: pw.BoxDecoration(
      color: bg,
      border: const pw.Border(bottom: pw.BorderSide(color: PdfColors.grey300, width: .5)),
    ),
    children: [
      cell(label, bold: bold),
      cell(base, right: true),
      cell(rateTxt, right: true),
      cell(gain, right: true, bold: bold),
      cell(ret, right: true, bold: bold),
    ],
  );

  pw.Widget kv(String k, String v) => pw.Padding(
    padding: const pw.EdgeInsets.symmetric(vertical: 1.5),
    child: pw.Row(
      crossAxisAlignment: pw.CrossAxisAlignment.start,
      children: [
        pw.SizedBox(
          width: 90,
          child: pw.Text(tr(k), style: pw.TextStyle(fontSize: 8, color: grey)),
        ),
        pw.Expanded(child: pw.Text(tr(v), style: small)),
      ],
    ),
  );

  return pw.Page(
    pageFormat: PdfPageFormat.a4,
    margin: const pw.EdgeInsets.all(32),
    build: (_) => pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.stretch,
      children: [
        pw.Row(
          crossAxisAlignment: pw.CrossAxisAlignment.start,
          children: [
            pw.Expanded(
              child: pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.start,
                children: [
                  pw.Text(
                    tr('${company['name'] ?? ''}'),
                    style: pw.TextStyle(fontSize: 14, fontWeight: pw.FontWeight.bold, color: night),
                  ),
                  for (final k in ['address', 'city', 'phone'])
                    if (company[k] != null && '${company[k]}'.isNotEmpty) pw.Text(tr('${company[k]}'), style: small),
                  if (company['tax_id'] != null) pw.Text(tr('NCC ${company['tax_id']}'), style: small),
                  if (company['trade_register'] != null) pw.Text(tr('RCCM ${company['trade_register']}'), style: small),
                ],
              ),
            ),
            pw.Container(
              padding: const pw.EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: pw.BoxDecoration(color: night, borderRadius: pw.BorderRadius.circular(4)),
              child: pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.end,
                children: [
                  pw.Text(
                    tr('BULLETIN DE PAIE'),
                    style: pw.TextStyle(color: yellow, fontWeight: pw.FontWeight.bold, fontSize: 12),
                  ),
                  pw.Text(tr('Période : ${periodLabel(period)}'), style: const pw.TextStyle(color: PdfColors.white, fontSize: 9)),
                  pw.Text(tr('${slip['number'] ?? ''}'), style: const pw.TextStyle(color: PdfColors.white, fontSize: 8)),
                ],
              ),
            ),
          ],
        ),
        pw.SizedBox(height: 14),
        pw.Container(
          padding: const pw.EdgeInsets.all(8),
          decoration: pw.BoxDecoration(
            border: pw.Border.all(color: PdfColors.grey400, width: .5),
            borderRadius: pw.BorderRadius.circular(4),
          ),
          child: pw.Row(
            crossAxisAlignment: pw.CrossAxisAlignment.start,
            children: [
              pw.Expanded(
                child: pw.Column(
                  crossAxisAlignment: pw.CrossAxisAlignment.start,
                  children: [
                    pw.Text(tr('${e['full_name']}'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 11)),
                    pw.SizedBox(height: 4),
                    kv('Matricule', '${e['matricule']}'),
                    kv('Fonction', [e['job_title'], e['department']].where((x) => x != null).join(' · ')),
                    kv('Catégorie', '${e['category'] ?? ''}'),
                    kv('Contrat', '${e['contract_type'] ?? ''}'),
                  ],
                ),
              ),
              pw.Expanded(
                child: pw.Column(
                  crossAxisAlignment: pw.CrossAxisAlignment.start,
                  children: [
                    pw.SizedBox(height: 15),
                    kv('N° sécurité sociale', '${e['cnps_number'] ?? ''}'),
                    kv('Embauche', e['hire_date'] == null ? '' : dateFr(e['hire_date'])),
                    kv('Ancienneté', '${e['seniority_years']} an(s)'),
                    kv('Parts fiscales', number(t['parts'], decimals: 1)),
                    kv('Jours payés', number(slip['days_worked'], decimals: 1)),
                  ],
                ),
              ),
            ],
          ),
        ),
        pw.SizedBox(height: 12),
        pw.Table(
          columnWidths: const {0: pw.FlexColumnWidth(4), 1: pw.FlexColumnWidth(2), 2: pw.FlexColumnWidth(1.2), 3: pw.FlexColumnWidth(2), 4: pw.FlexColumnWidth(2)},
          children: [
            pw.TableRow(
              decoration: pw.BoxDecoration(color: night),
              children: [
                for (final h in ['Rubrique', 'Base', 'Taux', 'Gains', 'Retenues'])
                  pw.Padding(
                    padding: const pw.EdgeInsets.all(4),
                    child: pw.Text(tr(h), style: head, textAlign: h == 'Rubrique' ? pw.TextAlign.left : pw.TextAlign.right),
                  ),
              ],
            ),
            for (final g in r['gains'] as List) row('${g['label']}', base: g['base'] is String ? g['base'] : '', rateTxt: rate(g['rate'] as num?), gain: m(g['amount'] as num)),
            row('SALAIRE BRUT', gain: m(t['gross']), bold: true, bg: PdfColors.grey100),
            for (final w in r['withholdings'] as List)
              row(
                w['detail'] is Map && ((w['detail']['ricf'] ?? w['detail']['reduction'] ?? 0) as num) > 0
                    ? '${w['label']} (brut ${m(w['detail']['brut'])} − réduction famille ${m(w['detail']['ricf'] ?? w['detail']['reduction'])})'
                    : '${w['label']}',
                base: m(w['base'] as num?),
                rateTxt: rate(w['rate'] as num?),
                ret: m(w['amount'] as num),
              ),
            for (final d in r['deductions'] as List) row('${d['label']}', ret: m(d['amount'] as num)),
            row('TOTAUX', gain: m(t['gross']), ret: m((t['withholdings'] as num) + (t['deductions'] as num)), bold: true, bg: PdfColors.grey100),
            for (final f in (r['reimbursements'] as List? ?? const [])) row('${f['label']} (hors salaire)', gain: m(f['amount'] as num)),
          ],
        ),
        pw.SizedBox(height: 10),
        pw.Row(
          crossAxisAlignment: pw.CrossAxisAlignment.start,
          children: [
            pw.Expanded(
              child: pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.start,
                children: [
                  kv('Brut imposable', m(t['taxable'])),
                  kv('Base cotisable', m(t['social_base'])),
                  kv('Paiement', '${{'bank_transfer': 'Virement', 'mobile_money': 'Mobile Money', 'cash': 'Espèces', 'cheque': 'Chèque'}[e['payment_method']] ?? ''} ${e['payment_account'] ?? ''}'),
                ],
              ),
            ),
            pw.Container(
              width: 200,
              padding: const pw.EdgeInsets.all(10),
              decoration: pw.BoxDecoration(color: yellow, borderRadius: pw.BorderRadius.circular(4)),
              child: pw.Row(
                children: [
                  pw.Expanded(
                    child: pw.Text(
                      tr('NET À PAYER'),
                      style: pw.TextStyle(fontWeight: pw.FontWeight.bold, color: night),
                    ),
                  ),
                  pw.Text(
                    tr(money(t['net'] as num, currency)),
                    style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 13, color: night),
                  ),
                ],
              ),
            ),
          ],
        ),
        pw.SizedBox(height: 16),
        pw.Text(
          tr('Charges patronales (information)'),
          style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 9, color: night),
        ),
        pw.SizedBox(height: 4),
        pw.Table(
          columnWidths: const {0: pw.FlexColumnWidth(4), 1: pw.FlexColumnWidth(2), 2: pw.FlexColumnWidth(1.2), 3: pw.FlexColumnWidth(2)},
          children: [
            for (final c in r['employer'] as List)
              if ((c['amount'] as num) > 0)
                pw.TableRow(
                  decoration: const pw.BoxDecoration(
                    border: pw.Border(bottom: pw.BorderSide(color: PdfColors.grey300, width: .5)),
                  ),
                  children: [cell('${c['label']}'), cell(m(c['base'] as num?), right: true), cell(rate(c['rate'] as num?), right: true), cell(m(c['amount'] as num), right: true)],
                ),
            pw.TableRow(children: [cell('Total charges patronales', bold: true), cell(''), cell(''), cell(m(t['employer_total']), right: true, bold: true)]),
            pw.TableRow(children: [cell('Coût total employeur', bold: true), cell(''), cell(''), cell(m(t['cost']), right: true, bold: true)]),
          ],
        ),
        pw.Spacer(),
        pw.Divider(color: PdfColors.grey300),
        pw.Text(
          tr('Pour vous aider à faire valoir vos droits, conservez ce bulletin de paie sans limitation de durée.'),
          style: pw.TextStyle(fontSize: 7, color: grey),
          textAlign: pw.TextAlign.center,
        ),
      ],
    ),
  );
}
