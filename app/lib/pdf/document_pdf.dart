import 'package:flutter/services.dart' show rootBundle, Uint8List;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';

import '../core/custom_fields.dart';
import '../core/fne.dart';
import '../core/local_db.dart';
import '../state/session.dart';
import '../ui/screens/documents_screen.dart';
import '../ui/widgets/common.dart';
import '../i18n/i18n.dart';

/// Génère le PDF d'un document (facture, devis…) entièrement en local :
/// impression et export fonctionnent sans connexion.
Future<void> printDocument({
  required Session session,
  required DocConfig config,
  required Map<String, dynamic> doc,
  List<Map<String, dynamic>> payments = const [],
}) async {
  final bytes = await buildDocumentPdf(session: session, config: config, doc: doc, payments: payments);
  final number = doc['number'] ?? (doc['status'] == 'draft' ? 'BROUILLON' : 'EN ATTENTE DE NUMÉRO');
  await Printing.layoutPdf(name: '${config.types[doc['type']]} $number.pdf', onLayout: (_) async => bytes);
}

/// PDF du document (impression, envoi par e-mail ou WhatsApp).
Future<Uint8List> buildDocumentPdf({
  required Session session,
  required DocConfig config,
  required Map<String, dynamic> doc,
  List<Map<String, dynamic>> payments = const [],
}) async {
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Regular.ttf'));
  final bold = pw.Font.ttf(await rootBundle.load('assets/fonts/Roboto-Bold.ttf'));
  final company = session.company;
  final currency = '${doc['currency'] ?? session.currency}';
  final rate = toDouble(doc['exchange_rate'] ?? 1);
  final night = PdfColor.fromHex('#0B1B3A');
  final yellow = PdfColor.fromHex('#FFC531');
  final grey = PdfColor.fromHex('#5B6B85');

  final typeLabel = (config.types[doc['type']] ?? 'Document').toUpperCase();
  final docNumber = doc['number'] ?? (doc['status'] == 'draft' ? 'BROUILLON' : 'EN ATTENTE DE NUMÉRO');
  final partner = LocalDb.get('partners', doc['partner_id'] as String?);
  final lines = List<Map<String, dynamic>>.from((doc['lines'] as List? ?? []).map((e) => Map<String, dynamic>.from(e)));

  final pdf = pw.Document(version: PdfVersion.pdf_1_4, theme: pw.ThemeData.withFont(base: regular, bold: bold));

  pw.Widget kv(String k, String v, {bool strong = false}) => pw.Padding(
        padding: const pw.EdgeInsets.symmetric(vertical: 2),
        child: pw.Row(children: [
          pw.Expanded(child: pw.Text(tr(k), style: pw.TextStyle(fontWeight: strong ? pw.FontWeight.bold : null))),
          pw.Text(tr(v), style: pw.TextStyle(fontWeight: strong ? pw.FontWeight.bold : null)),
        ]),
      );

  pdf.addPage(pw.MultiPage(
    pageFormat: PdfPageFormat.a4,
    margin: const pw.EdgeInsets.fromLTRB(36, 36, 36, 48),
    footer: (ctx) => pw.Column(children: [
      pw.Divider(color: PdfColors.grey300),
      pw.Text(
        tr([
          company['name'],
          if (company['trade_register'] != null) 'RCCM ${company['trade_register']}',
          if (company['tax_id'] != null) 'NCC ${company['tax_id']}',
          company['phone'],
          company['email'],
        ].where((e) => e != null && '$e'.isNotEmpty).join(' · ')),
        style: pw.TextStyle(fontSize: 8, color: grey),
        textAlign: pw.TextAlign.center,
      ),
      pw.SizedBox(height: 2),
      pw.Text(tr('Page ${ctx.pageNumber}/${ctx.pagesCount}'), style: pw.TextStyle(fontSize: 8, color: grey)),
    ]),
    build: (ctx) => [
      pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
        pw.Expanded(
          child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
            pw.Text(tr('${company['name'] ?? ''}'), style: pw.TextStyle(fontSize: 18, fontWeight: pw.FontWeight.bold, color: night)),
            if (company['address'] != null) pw.Text(tr('${company['address']}')),
            if (company['city'] != null) pw.Text(tr('${company['city']}')),
            if (company['phone'] != null) pw.Text(tr('Tél. ${company['phone']}')),
            if (company['email'] != null) pw.Text(tr('${company['email']}')),
          ]),
        ),
        pw.Container(
          padding: const pw.EdgeInsets.all(12),
          decoration: pw.BoxDecoration(color: night, borderRadius: pw.BorderRadius.circular(6)),
          child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.end, children: [
            pw.Text(tr(typeLabel), style: pw.TextStyle(color: yellow, fontWeight: pw.FontWeight.bold, fontSize: 16)),
            pw.Text(tr('$docNumber'), style: const pw.TextStyle(color: PdfColors.white)),
            pw.SizedBox(height: 4),
            pw.Text(tr('Date : ${dateFr(doc['date'])}'), style: const pw.TextStyle(color: PdfColors.white, fontSize: 9)),
            if (doc['due_date'] != null) pw.Text(tr('Échéance : ${dateFr(doc['due_date'])}'), style: const pw.TextStyle(color: PdfColors.white, fontSize: 9)),
          ]),
        ),
      ]),
      pw.SizedBox(height: 24),
      pw.Row(children: [
        pw.Spacer(),
        pw.Container(
          width: 240,
          padding: const pw.EdgeInsets.all(10),
          decoration: pw.BoxDecoration(border: pw.Border.all(color: PdfColors.grey300), borderRadius: pw.BorderRadius.circular(4)),
          child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
            pw.Text(tr(config.partnerLabel.toUpperCase()), style: pw.TextStyle(fontSize: 8, color: grey)),
            pw.Text(tr('${doc['partner_name'] ?? '—'}'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
            if (partner?['address'] != null) pw.Text(tr('${partner!['address']}')),
            if (partner?['city'] != null) pw.Text(tr('${partner!['city']}')),
            if (partner?['phone'] != null) pw.Text(tr('${partner!['phone']}')),
            if (partner?['tax_id'] != null) pw.Text(tr('NCC ${partner!['tax_id']}')),
            for (final f in customFieldsFor('partners'))
              if (f['show_on_pdf'] == true && !customEmpty((partner?['custom'] as Map?)?['${f['key']}']))
                pw.Text(tr('${f['label']} : ${customDisplay(f, (partner!['custom'] as Map)['${f['key']}'])}')),
          ]),
        ),
      ]),
      if (doc['reference'] != null) ...[pw.SizedBox(height: 8), pw.Text(tr('Référence : ${doc['reference']}'))],
      for (final f in customFieldsFor(config.entity))
        if (f['show_on_pdf'] == true && !customEmpty((doc['custom'] as Map?)?['${f['key']}']))
          pw.Padding(padding: const pw.EdgeInsets.only(top: 3), child: pw.Text(tr('${f['label']} : ${customDisplay(f, (doc['custom'] as Map)['${f['key']}'])}'))),
      pw.SizedBox(height: 16),
      pw.TableHelper.fromTextArray(
        headers: ['Désignation', 'Qté', 'P.U. HT', 'Remise', 'TVA', 'Total TTC'],
        data: [
          for (final l in lines)
            [
              '${l['description']}',
              qty(l['quantity'] as num?) + (l['unit'] != null ? ' ${l['unit']}' : ''),
              money(toDouble(l['unit_price']), currency),
              toDouble(l['discount_percent']) > 0 ? '${number(toDouble(l['discount_percent']))} %' : '',
              '${number(toDouble(l['tax_rate']))} %',
              money(toDouble(l['total']), currency),
            ],
        ],
        headerStyle: pw.TextStyle(fontWeight: pw.FontWeight.bold, color: PdfColors.white, fontSize: 9),
        headerDecoration: pw.BoxDecoration(color: night),
        cellStyle: const pw.TextStyle(fontSize: 9),
        cellAlignments: {1: pw.Alignment.centerRight, 2: pw.Alignment.centerRight, 3: pw.Alignment.centerRight, 4: pw.Alignment.centerRight, 5: pw.Alignment.centerRight},
        columnWidths: {0: const pw.FlexColumnWidth(4)},
        border: null,
        rowDecoration: const pw.BoxDecoration(border: pw.Border(bottom: pw.BorderSide(color: PdfColors.grey200))),
      ),
      pw.SizedBox(height: 16),
      pw.Row(children: [
        pw.Expanded(
          child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
            if (doc['notes'] != null) pw.Text(tr('${doc['notes']}'), style: pw.TextStyle(color: grey, fontSize: 9)),
            // Conditions de paiement et échéancier
            if (doc['payment_term_id'] != null && LocalDb.hasEntity('payment_terms') && LocalDb.get('payment_terms', '${doc['payment_term_id']}') != null)
              pw.Padding(
                padding: const pw.EdgeInsets.only(top: 6),
                child: pw.Text(tr('Conditions de paiement : ${LocalDb.get('payment_terms', '${doc['payment_term_id']}')!['name']}'), style: pw.TextStyle(fontSize: 9, fontWeight: pw.FontWeight.bold)),
              ),
            if (((doc['schedule'] as List?)?.length ?? 0) > 1)
              for (final i in (doc['schedule'] as List)) pw.Text(tr('• ${dateFr(i['date'])} : ${money(toDouble(i['amount']), currency)}'), style: pw.TextStyle(color: grey, fontSize: 9)),
          ]),
        ),
        pw.SizedBox(width: 24),
        pw.SizedBox(
          width: 220,
          child: pw.Column(children: [
            kv('Total HT', money(toDouble(doc['subtotal']), currency)),
            if (toDouble(doc['discount_total']) > 0) kv('dont remises', money(toDouble(doc['discount_total']), currency)),
            kv('TVA', money(toDouble(doc['tax_total']), currency)),
            pw.Divider(),
            kv('Total TTC', money(toDouble(doc['total']), currency), strong: true),
            if (currency != session.currency && rate > 0) ...[
              pw.SizedBox(height: 2),
              pw.Text(tr('Contrevaleur : ${money(toDouble(doc['total']) * rate, session.currency)} (1 $currency = ${number(rate, decimals: rate == rate.roundToDouble() ? 0 : 3)})'), style: pw.TextStyle(fontSize: 8, color: grey)),
            ],
            if (config.payableTypes.contains(doc['type']) && toDouble(doc['amount_paid']) > 0) ...[
              kv('Déjà réglé', money(toDouble(doc['amount_paid']), currency)),
              kv('Reste à payer', money(toDouble(doc['total']) - toDouble(doc['amount_paid']), currency), strong: true),
            ],
          ]),
        ),
      ]),
      // Coordonnées de paiement (factures de vente)
      if (config.entity == 'sales_documents' && (doc['type'] == 'invoice' || doc['type'] == 'quote')) ...() {
        final banks = [for (final b in (session.companySettings['bank_accounts'] as List? ?? const [])) if (b is Map && b['on_invoices'] != false) b];
        final note = session.companySettings['payment_instructions'];
        if (banks.isEmpty && note == null) return <pw.Widget>[];
        return <pw.Widget>[
          pw.SizedBox(height: 20),
          pw.Container(
            padding: const pw.EdgeInsets.all(10),
            decoration: pw.BoxDecoration(border: pw.Border.all(color: PdfColors.grey300), borderRadius: pw.BorderRadius.circular(4)),
            child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
              pw.Text(tr('Règlement'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10, color: night)),
              pw.SizedBox(height: 4),
              for (final b in banks)
                pw.Text(
                  tr([
                    '${b['bank']}',
                    if (b['holder'] != null) 'Titulaire : ${b['holder']}',
                    if (b['account'] != null) 'RIB : ${b['account']}',
                    if (b['iban'] != null) 'IBAN : ${b['iban']}',
                    if (b['swift'] != null) 'SWIFT : ${b['swift']}',
                  ].join(' · ')),
                  style: const pw.TextStyle(fontSize: 9),
                ),
              if (note != null) pw.Padding(padding: const pw.EdgeInsets.only(top: 4), child: pw.Text(tr('$note'), style: pw.TextStyle(fontSize: 9, color: grey))),
              if (doc['pay_url'] != null)
                pw.Padding(
                  padding: const pw.EdgeInsets.only(top: 6),
                  child: pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.center, children: [
                    pw.BarcodeWidget(barcode: pw.Barcode.qrCode(), data: '${doc['pay_url']}', width: 54, height: 54, drawText: false),
                    pw.SizedBox(width: 8),
                    pw.Expanded(
                      child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
                        pw.Text(tr('Payez en ligne : scannez le code (Mobile Money, carte)'), style: pw.TextStyle(fontSize: 9, color: night, fontWeight: pw.FontWeight.bold)),
                        pw.Text(tr('${doc['pay_url']}'), style: pw.TextStyle(fontSize: 8, color: grey)),
                      ]),
                    ),
                  ]),
                ),
            ]),
          ),
        ];
      }(),
      // Facture normalisée électronique (DGI Côte d'Ivoire)
      if (config.entity == 'sales_documents' || config.entity == 'purchase_documents') ...() {
        final fne = fneCertFor(doc['id'] as String?);
        if (fne == null) return <pw.Widget>[];
        if (fne['status'] != 'certified') {
          return <pw.Widget>[pw.SizedBox(height: 12), pw.Text(tr('Certification FNE (DGI) en cours de transmission.'), style: pw.TextStyle(fontSize: 9, color: grey))];
        }
        return <pw.Widget>[
          pw.SizedBox(height: 16),
          pw.Container(
            padding: const pw.EdgeInsets.all(10),
            decoration: pw.BoxDecoration(border: pw.Border.all(color: night, width: 1), borderRadius: pw.BorderRadius.circular(4)),
            child: pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.center, children: [
              if (fne['verify_url'] != null) pw.BarcodeWidget(barcode: pw.Barcode.qrCode(), data: '${fne['verify_url']}', width: 76, height: 76, drawText: false),
              pw.SizedBox(width: 12),
              pw.Expanded(
                child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
                  pw.Text(tr(fne['invoice_type'] == 'refund' ? 'AVOIR NORMALISÉ ÉLECTRONIQUE — DGI' : 'FACTURE NORMALISÉE ÉLECTRONIQUE — DGI'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10, color: night)),
                  pw.SizedBox(height: 3),
                  if (fne['reference'] != null) pw.Text(tr('N° FNE : ${fne['reference']}'), style: const pw.TextStyle(fontSize: 9)),
                  if (fne['ncc'] != null) pw.Text(tr('NCC émetteur : ${fne['ncc']}'), style: const pw.TextStyle(fontSize: 9)),
                  if (fne['certified_at'] != null) pw.Text(tr('Certifiée le ${dateFr(fne['certified_at'])}'), style: const pw.TextStyle(fontSize: 9)),
                  if (fne['fiscal_stamp'] != null && '${fne['fiscal_stamp']}'.length <= 80) pw.Text(tr('Sticker : ${fne['fiscal_stamp']}'), style: const pw.TextStyle(fontSize: 8)),
                  pw.SizedBox(height: 3),
                  pw.Text(tr('Scannez le QR code pour vérifier l\'authenticité de cette facture sur le site de la DGI.'), style: pw.TextStyle(fontSize: 8, color: grey)),
                ]),
              ),
            ]),
          ),
        ];
      }(),
      if (session.companySettings['invoice_footer'] != null) ...[
        pw.SizedBox(height: 24),
        pw.Text(tr('${session.companySettings['invoice_footer']}'), style: pw.TextStyle(fontSize: 9, color: grey)),
      ],
    ],
  ));

  return pdf.save();
}

