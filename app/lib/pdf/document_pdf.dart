import 'package:flutter/services.dart' show rootBundle, Uint8List;
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart' show networkImage;

import '../core/amount_words.dart';
import '../core/custom_fields.dart';
import '../core/vat.dart';
import '../core/fne.dart';
import '../core/local_db.dart';
import '../state/session.dart';
import '../ui/screens/documents_screen.dart';
import '../ui/screens/document_editor.dart' show paymentMethods;
import '../ui/widgets/common.dart';
import '../i18n/i18n.dart';
import '../ui/widgets/pdf_viewer.dart';
import 'doc_templates.dart';
import '../core/json.dart';

/// Génère le PDF d'un document (facture, devis…) entièrement en local :
/// impression et export fonctionnent sans connexion.
Future<void> printDocument({
  required Session session,
  required DocConfig config,
  required Map<String, dynamic> doc,
  List<Map<String, dynamic>> payments = const [],
}) async {
  final bytes = await buildDocumentPdf(session: session, config: config, doc: doc, payments: payments);
  final number = doc['number'] ?? ((doc['status'] ?? 'draft') == 'draft' ? 'BROUILLON' : 'EN ATTENTE DE NUMÉRO');
  await previewPdf(name: '${config.types[doc['type']]} $number.pdf', onLayout: (_) async => bytes);
}

/// Logo mis en cache (une seule requête par session ; ignoré hors connexion).
final _logoCache = <String, pw.ImageProvider?>{};

Future<pw.ImageProvider?> _logo(String url) async {
  if (!url.startsWith('http')) return null;
  if (_logoCache.containsKey(url)) return _logoCache[url];
  try {
    return _logoCache[url] = await networkImage(url).timeout(const Duration(seconds: 5));
  } catch (_) {
    return _logoCache[url] = null;
  }
}

PdfColor _hex(dynamic v, String fallback) {
  final s = '${v ?? ''}'.trim();
  try {
    return PdfColor.fromHex(RegExp(r'^#?[0-9a-fA-F]{6}$').hasMatch(s) ? (s.startsWith('#') ? s : '#$s') : fallback);
  } catch (_) {
    return PdfColor.fromHex(fallback);
  }
}

/// Couleur mélangée avec du blanc (teinte claire pour fonds).
PdfColor _tint(PdfColor c, double t) => PdfColor(c.red + (1 - c.red) * t, c.green + (1 - c.green) * t, c.blue + (1 - c.blue) * t);

/// Texte lisible sur une couleur de fond.
PdfColor _on(PdfColor c) => (0.299 * c.red + 0.587 * c.green + 0.114 * c.blue) > 0.62 ? PdfColor.fromHex('#111827') : PdfColors.white;

/// PDF du document (impression, envoi par e-mail ou WhatsApp).
/// [template] : spécification imposée (aperçu du studio) ; sinon modèle du document ou par défaut.
Future<Uint8List> buildDocumentPdf({
  required Session session,
  required DocConfig config,
  required Map<String, dynamic> doc,
  List<Map<String, dynamic>> payments = const [],
  Map<String, dynamic>? template,
}) async {
  final spec = template ?? resolveTemplate(session, doc, config.entity);
  final style = asMap(spec['style']);
  final blocks = [for (final b in (spec['blocks'] as List? ?? defaultBlocks)) if (b is Map && b['hidden'] != true) Map<String, dynamic>.from(b)];
  final titles = asMap(spec['titles']);

  final font = style['font'] == 'inter' ? 'Inter' : 'Roboto';
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/$font-Regular.ttf'));
  final bold = pw.Font.ttf(await rootBundle.load('assets/fonts/$font-Bold.ttf'));
  final company = session.company;
  final currency = '${doc['currency'] ?? session.currency}';
  final rate = toDouble(doc['exchange_rate'] ?? 1);
  final primary = _hex(style['primary'], '#0B1B3A');
  final accent = _hex(style['accent'], '#FFC531');
  final grey = PdfColor.fromHex('#5B6B85');
  final radius = toDouble(style['radius'] ?? 6);
  final headerStyle = '${style['header'] ?? 'box'}';
  final tableStyle = '${style['table'] ?? 'dark'}';

  final typeLabel = '${titles[doc['type']] ?? (config.types[doc['type']] ?? 'Document').toUpperCase()}';
  final docNumber = doc['number'] ?? ((doc['status'] ?? 'draft') == 'draft' ? 'BROUILLON' : 'EN ATTENTE DE NUMÉRO');
  final partner = LocalDb.get('partners', doc['partner_id'] as String?);
  final lines = List<Map<String, dynamic>>.from((doc['lines'] as List? ?? []).map((e) => Map<String, dynamic>.from(e)));

  final sale = config.entity == 'sales_documents';
  final settings = session.companySettings;
  // TVA : colonnes masquées et mention légale quand la société n'est pas assujettie (ventes sans aucune TVA)
  final noVat = sale && !vatSubject(session) && toDouble(doc['tax_total']) == 0;
  final regime = settings['tax_regime'];
  // Mode de règlement : celui de la facture, sinon les paiements reçus, sinon le mode par défaut de la société
  final payModes = <String>{
    if (doc['payment_method'] != null) '${paymentMethods[doc['payment_method']] ?? doc['payment_method']}',
    if (doc['payment_method'] == null) for (final p in payments) '${paymentMethods[p['method']] ?? p['method']}',
  };
  if (payModes.isEmpty && sale && paymentMethods.containsKey(settings['default_payment_method'])) payModes.add(paymentMethods[settings['default_payment_method']]!);
  final receiptBox = sale && ['invoice', 'delivery', 'order'].contains(doc['type']) && ((doc['receipt_box'] as bool?) ?? settings['invoice_receipt_box'] == true);
  final words = sale && ['invoice', 'proforma', 'quote', 'credit_note', 'deposit'].contains(doc['type']) && settings['invoice_amount_words'] != false && toDouble(doc['total']) > 0;
  // Comptes bancaires imprimés : celui choisi sur la facture, sinon tous ceux « sur les factures »
  final allBanks = invoiceBanks(session);
  final chosenBanks = doc['bank_account'] == null ? allBanks : allBanks.where((b) => bankKey(b) == doc['bank_account']).toList();
  final banks = chosenBanks.isEmpty ? allBanks : chosenBanks;
  final firstBank = banks.firstOrNull;
  final capital = toDouble(settings['capital']);
  final legal = [
    if (settings['legal_form'] != null) '${settings['legal_form']}',
    if (capital > 0) 'au capital de ${money(capital, session.currency)}',
  ].join(' ');
  // Document pas encore validé (un brouillon pas encore synchronisé n'a pas de statut)
  final isDraft = (doc['status'] ?? 'draft') == 'draft' && doc['number'] == null;
  final logo = await _logo('${company['logo_url'] ?? ''}');
  // TVA ventilée par taux (comme l'exige une facture avec plusieurs taux)
  final vatByRate = <double, double>{};
  for (final l in lines) {
    final r = toDouble(l['tax_rate']);
    if (r > 0) vatByRate[r] = (vatByRate[r] ?? 0) + toDouble(l['tax_amount']);
  }
  final docWord = switch (doc['type']) {
    'quote' => 'le présent devis',
    'proforma' => 'la présente facture proforma',
    'credit_note' => 'le présent avoir',
    'deposit' => 'la présente facture d\'acompte',
    _ => 'la présente facture',
  };
  final offer = ['quote', 'proforma'].contains(doc['type']);

  final pdf = pw.Document(version: PdfVersion.pdf_1_4, theme: pw.ThemeData.withFont(base: regular, bold: bold));

  pw.Widget kv(String k, String v, {bool strong = false}) => pw.Padding(
        padding: const pw.EdgeInsets.symmetric(vertical: 2),
        child: pw.Row(children: [
          pw.Expanded(child: pw.Text(tr(k), style: pw.TextStyle(fontWeight: strong ? pw.FontWeight.bold : null, color: strong ? primary : null))),
          pw.Text(tr(v), style: pw.TextStyle(fontWeight: strong ? pw.FontWeight.bold : null, color: strong ? primary : null)),
        ]),
      );

  pw.Widget boxed(pw.Widget child, {PdfColor? border, PdfColor? fill}) => pw.Container(
        padding: const pw.EdgeInsets.all(10),
        decoration: pw.BoxDecoration(color: fill, border: pw.Border.all(color: border ?? PdfColors.grey300), borderRadius: pw.BorderRadius.circular(radius.clamp(0, 8))),
        child: child,
      );

  // ---------- Blocs ----------

  List<pw.Widget> companyLines({PdfColor? color, pw.CrossAxisAlignment align = pw.CrossAxisAlignment.start, bool showName = true}) {
    final c = color ?? PdfColors.black;
    final small = pw.TextStyle(color: color == null ? grey : _tint(color, .15), fontSize: 9);
    return [
      if (showName) pw.Text(tr('${company['name'] ?? ''}'), style: pw.TextStyle(fontSize: 18, fontWeight: pw.FontWeight.bold, color: color ?? primary)),
      if (legal.isNotEmpty) pw.Text(tr(legal), style: small),
      if (company['address'] != null) pw.Text(tr('${company['address']}'), style: pw.TextStyle(color: c)),
      if (company['city'] != null) pw.Text(tr('${company['city']}'), style: pw.TextStyle(color: c)),
      if (company['phone'] != null) pw.Text(tr('Tél. ${company['phone']}'), style: pw.TextStyle(color: c)),
      if (company['email'] != null) pw.Text(tr('${company['email']}'), style: pw.TextStyle(color: c)),
    ];
  }

  List<pw.Widget> titleLines(PdfColor titleColor, PdfColor textColor, {pw.CrossAxisAlignment align = pw.CrossAxisAlignment.end, double size = 16}) => [
        pw.Text(tr(typeLabel), style: pw.TextStyle(color: titleColor, fontWeight: pw.FontWeight.bold, fontSize: size)),
        pw.Text(tr('$docNumber'), style: pw.TextStyle(color: textColor)),
        pw.SizedBox(height: 4),
        pw.Text(tr('Date : ${dateFr(doc['date'])}'), style: pw.TextStyle(color: textColor, fontSize: 9)),
        if (doc['due_date'] != null) pw.Text(tr('${offer ? 'Valable jusqu\'au' : 'Échéance'} : ${dateFr(doc['due_date'])}'), style: pw.TextStyle(color: textColor, fontSize: 9)),
      ];

  pw.Widget? logoW(double h) => logo == null ? null : pw.Image(logo, height: h, fit: pw.BoxFit.contain);

  List<pw.Widget> header() {
    switch (headerStyle) {
      case 'plain':
        return [
          pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
            pw.Expanded(child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [if (logo != null) pw.Padding(padding: const pw.EdgeInsets.only(bottom: 6), child: logoW(48)), ...companyLines()])),
            pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.end, children: titleLines(primary, PdfColors.black, size: 22)),
          ]),
          pw.SizedBox(height: 8),
          pw.Divider(color: accent, thickness: 1.2),
        ];
      case 'band':
        final on = _on(primary);
        return [
          pw.Container(
            padding: const pw.EdgeInsets.all(16),
            decoration: pw.BoxDecoration(color: primary, borderRadius: pw.BorderRadius.circular(radius)),
            child: pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
              if (logo != null) pw.Padding(padding: const pw.EdgeInsets.only(right: 12), child: pw.Container(padding: const pw.EdgeInsets.all(4), color: PdfColors.white, child: logoW(44))),
              pw.Expanded(child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: companyLines(color: on))),
              pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.end, children: titleLines(accent == primary ? on : accent, on, size: 18)),
            ]),
          ),
        ];
      case 'centered':
        return [
          pw.Center(child: pw.Column(children: [
            if (logo != null) pw.Padding(padding: const pw.EdgeInsets.only(bottom: 6), child: logoW(56)),
            pw.Text(tr('${company['name'] ?? ''}'), style: pw.TextStyle(fontSize: 20, fontWeight: pw.FontWeight.bold, color: primary, letterSpacing: 1.5)),
            if (legal.isNotEmpty) pw.Text(tr(legal), style: pw.TextStyle(color: grey, fontSize: 9)),
            pw.Text(
              tr([company['address'], company['city'], if (company['phone'] != null) 'Tél. ${company['phone']}', company['email']].where((e) => e != null && '$e'.isNotEmpty).join(' · ')),
              style: pw.TextStyle(color: grey, fontSize: 9),
              textAlign: pw.TextAlign.center,
            ),
            pw.SizedBox(height: 12),
            pw.Row(mainAxisAlignment: pw.MainAxisAlignment.center, children: [
              pw.Container(width: 60, height: 1, color: accent),
              pw.Padding(padding: const pw.EdgeInsets.symmetric(horizontal: 10), child: pw.Text(tr(typeLabel), style: pw.TextStyle(color: accent, fontWeight: pw.FontWeight.bold, fontSize: 16, letterSpacing: 2))),
              pw.Container(width: 60, height: 1, color: accent),
            ]),
            pw.SizedBox(height: 4),
            pw.Text(tr('N° $docNumber · ${dateFr(doc['date'])}${doc['due_date'] != null ? ' · ${offer ? 'valable jusqu\'au' : 'échéance'} ${dateFr(doc['due_date'])}' : ''}'), style: pw.TextStyle(fontSize: 9, color: grey)),
          ])),
        ];
      case 'split':
        final on = _on(primary);
        return [
          pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
            pw.Expanded(
              flex: 3,
              child: pw.Container(
                height: logo != null ? 150 : 110,
                padding: const pw.EdgeInsets.all(14),
                decoration: pw.BoxDecoration(color: _tint(primary, .9), borderRadius: pw.BorderRadius.horizontal(left: pw.Radius.circular(radius))),
                child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [if (logo != null) pw.Padding(padding: const pw.EdgeInsets.only(bottom: 6), child: logoW(44)), ...companyLines()]),
              ),
            ),
            pw.Expanded(
              flex: 2,
              child: pw.Container(
                height: logo != null ? 150 : 110,
                padding: const pw.EdgeInsets.all(14),
                decoration: pw.BoxDecoration(color: primary, borderRadius: pw.BorderRadius.horizontal(right: pw.Radius.circular(radius))),
                child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.end, mainAxisAlignment: pw.MainAxisAlignment.center, children: titleLines(on, on, size: 18)),
              ),
            ),
          ]),
          pw.Container(height: 4, color: accent),
        ];
      default: // 'box'
        final on = _on(primary);
        return [
          pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
            pw.Expanded(child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [if (logo != null) pw.Padding(padding: const pw.EdgeInsets.only(bottom: 6), child: logoW(54)), ...companyLines()])),
            pw.Container(
              padding: const pw.EdgeInsets.all(12),
              decoration: pw.BoxDecoration(color: primary, borderRadius: pw.BorderRadius.circular(radius)),
              child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.end, children: titleLines(accent, on)),
            ),
          ]),
        ];
    }
  }

  List<pw.Widget> parties(Map<String, dynamic> b) {
    final box = pw.Container(
      width: b['align'] == 'full' ? null : 240,
      padding: const pw.EdgeInsets.all(10),
      decoration: pw.BoxDecoration(border: pw.Border.all(color: _tint(primary, .75)), borderRadius: pw.BorderRadius.circular(radius.clamp(0, 8))),
      child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
        pw.Text(tr(config.partnerLabel.toUpperCase()), style: pw.TextStyle(fontSize: 8, color: primary, fontWeight: pw.FontWeight.bold)),
        pw.Text(tr('${doc['partner_name'] ?? '—'}'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
        if (partner?['address'] != null) pw.Text(tr('${partner!['address']}')),
        if (partner?['city'] != null) pw.Text(tr('${partner!['city']}')),
        if (partner?['phone'] != null) pw.Text(tr('${partner!['phone']}')),
        if (partner?['tax_id'] != null) pw.Text(tr('NCC ${partner!['tax_id']}')),
        if ('${partner?['code'] ?? ''}'.isNotEmpty) pw.Text(tr('Code client : ${partner!['code']}'), style: pw.TextStyle(fontSize: 9, color: grey)),
        for (final f in customFieldsFor('partners'))
          if (f['show_on_pdf'] == true && !customEmpty(asMap(partner?['custom'])['${f['key']}']))
            pw.Text(tr('${f['label']} : ${customDisplay(f, asMap(partner!['custom'])['${f['key']}'])}')),
      ]),
    );
    return [
      pw.SizedBox(height: 20),
      if (b['align'] == 'full')
        box
      else
        pw.Row(children: [if (b['align'] != 'left') pw.Spacer(), box, if (b['align'] == 'left') pw.Spacer()]),
    ];
  }

  List<pw.Widget> info() => [
        if (doc['reference'] != null) ...[pw.SizedBox(height: 8), pw.Text(tr('Référence : ${doc['reference']}'))],
        for (final f in customFieldsFor(config.entity))
          if (f['show_on_pdf'] == true && !customEmpty(asMap(doc['custom'])['${f['key']}']))
            pw.Padding(padding: const pw.EdgeInsets.only(top: 3), child: pw.Text(tr('${f['label']} : ${customDisplay(f, asMap(doc['custom'])['${f['key']}'])}'))),
      ];

  List<pw.Widget> lineTable() {
    final headerBg = switch (tableStyle) {
      'accent' => accent,
      'light' => _tint(primary, .88),
      'lines' => null,
      _ => primary,
    };
    final headerText = headerBg == null ? primary : _on(headerBg);
    return [
      pw.SizedBox(height: 16),
      pw.TableHelper.fromTextArray(
        headers: noVat ? ['Désignation', 'Qté', 'Prix unitaire', 'Remise', 'Total'] : ['Désignation', 'Qté', 'P.U. HT', 'Remise', 'TVA', 'Total TTC'],
        data: [
          for (final l in lines)
            [
              '${l['description']}',
              qty(l['quantity'] as num?) + (l['unit'] != null ? ' ${l['unit']}' : ''),
              money(toDouble(l['unit_price']), currency),
              toDouble(l['discount_percent']) > 0 ? '${number(toDouble(l['discount_percent']))} %' : '',
              if (!noVat) '${number(toDouble(l['tax_rate']))} %',
              money(toDouble(l['total']), currency),
            ],
        ],
        headerAlignments: {0: pw.Alignment.centerLeft},
        headerStyle: pw.TextStyle(fontWeight: pw.FontWeight.bold, color: headerText, fontSize: 9),
        headerDecoration: headerBg == null
            ? pw.BoxDecoration(border: pw.Border(bottom: pw.BorderSide(color: primary, width: 1.2)))
            : pw.BoxDecoration(color: headerBg, borderRadius: pw.BorderRadius.vertical(top: pw.Radius.circular(radius.clamp(0, 6)))),
        cellStyle: const pw.TextStyle(fontSize: 9),
        cellAlignments: {0: pw.Alignment.centerLeft, 1: pw.Alignment.centerRight, 2: pw.Alignment.centerRight, 3: pw.Alignment.centerRight, 4: pw.Alignment.centerRight, 5: pw.Alignment.centerRight},
        columnWidths: {0: const pw.FlexColumnWidth(4)},
        border: null,
        rowDecoration: const pw.BoxDecoration(border: pw.Border(bottom: pw.BorderSide(color: PdfColors.grey200))),
        oddRowDecoration: tableStyle == 'striped' ? pw.BoxDecoration(color: _tint(primary, .94)) : null,
      ),
    ];
  }

  List<pw.Widget> totals() => [
        pw.SizedBox(height: 16),
        pw.Row(children: [
          pw.Spacer(),
          pw.SizedBox(
            width: 230,
            child: pw.Column(children: [
              if (!noVat) kv('Total HT', money(toDouble(doc['subtotal']), currency)),
              if (toDouble(doc['discount_total']) > 0) kv('dont remises', money(toDouble(doc['discount_total']), currency)),
              if (!noVat && vatByRate.length <= 1) kv(vatByRate.length == 1 ? 'TVA ${vatPct(vatByRate.keys.first)} %' : 'TVA', money(toDouble(doc['tax_total']), currency)),
              if (!noVat && vatByRate.length > 1)
                for (final e in (vatByRate.entries.toList()..sort((a, b) => b.key.compareTo(a.key)))) kv('TVA ${vatPct(e.key)} %', money(e.value, currency)),
              pw.Container(
                margin: const pw.EdgeInsets.only(top: 4),
                padding: const pw.EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: pw.BoxDecoration(color: _tint(primary, .9), borderRadius: pw.BorderRadius.circular(radius.clamp(0, 6))),
                child: kv(noVat ? 'Total' : 'Total TTC', money(toDouble(doc['total']), currency), strong: true),
              ),
              if (noVat) pw.Padding(padding: const pw.EdgeInsets.only(top: 2), child: pw.Text(tr('TVA non applicable'), style: pw.TextStyle(fontSize: 8, color: grey))),
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
      ];

  List<pw.Widget> wordsBlock() => !words
      ? const []
      : [
          pw.SizedBox(height: 12),
          pw.RichText(
            text: pw.TextSpan(style: const pw.TextStyle(fontSize: 9.5), children: [
              pw.TextSpan(text: tr('Arrêté${doc['type'] == 'quote' || doc['type'] == 'credit_note' ? '' : 'e'} $docWord à la somme de : ')),
              pw.TextSpan(text: tr(_cap(amountInWords(toDouble(doc['total']), currency))), style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
              pw.TextSpan(text: tr(noVat ? '.' : ' TTC.')),
            ]),
          ),
        ];

  List<pw.Widget> notes() {
    final term = doc['payment_term_id'] != null && LocalDb.hasEntity('payment_terms') ? LocalDb.get('payment_terms', '${doc['payment_term_id']}') : null;
    final schedule = ((doc['schedule'] as List?)?.length ?? 0) > 1 ? doc['schedule'] as List : const [];
    if (doc['notes'] == null && term == null && schedule.isEmpty) return const [];
    return [
      pw.SizedBox(height: 10),
      if (doc['notes'] != null) pw.Text(tr('${doc['notes']}'), style: pw.TextStyle(color: grey, fontSize: 9)),
      if (term != null) pw.Padding(padding: const pw.EdgeInsets.only(top: 6), child: pw.Text(tr('Conditions de paiement : ${term['name']}'), style: pw.TextStyle(fontSize: 9, fontWeight: pw.FontWeight.bold))),
      for (final i in schedule) pw.Text(tr('• ${dateFr(i['date'])} : ${money(toDouble(i['amount']), currency)}'), style: pw.TextStyle(color: grey, fontSize: 9)),
    ];
  }

  List<pw.Widget> payment() {
    final note = settings['payment_instructions'];
    final showBanks = sale && ['invoice', 'quote', 'proforma', 'deposit', 'order'].contains(doc['type']);
    if (payModes.isEmpty && (!showBanks || (banks.isEmpty && note == null && doc['pay_url'] == null))) return const [];
    return [
      pw.SizedBox(height: 14),
      boxed(
        pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
          pw.Text(tr('Règlement'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10, color: primary)),
          pw.SizedBox(height: 4),
          if (payModes.isNotEmpty) pw.Text(tr('Mode de règlement : ${payModes.join(', ')}'), style: pw.TextStyle(fontSize: 9, fontWeight: pw.FontWeight.bold)),
          if (showBanks)
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
          if (showBanks && note != null) pw.Padding(padding: const pw.EdgeInsets.only(top: 4), child: pw.Text(tr('$note'), style: pw.TextStyle(fontSize: 9, color: grey))),
          if (showBanks && doc['pay_url'] != null)
            pw.Padding(
              padding: const pw.EdgeInsets.only(top: 6),
              child: pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.center, children: [
                pw.BarcodeWidget(barcode: pw.Barcode.qrCode(), data: '${doc['pay_url']}', width: 54, height: 54, drawText: false),
                pw.SizedBox(width: 8),
                pw.Expanded(
                  child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
                    pw.Text(tr('Payez en ligne : scannez le code (Mobile Money, carte)'), style: pw.TextStyle(fontSize: 9, color: primary, fontWeight: pw.FontWeight.bold)),
                    pw.Text(tr('${doc['pay_url']}'), style: pw.TextStyle(fontSize: 8, color: grey)),
                  ]),
                ),
              ]),
            ),
        ]),
        border: _tint(primary, .7),
      ),
    ];
  }

  List<pw.Widget> fneBlock() {
    if (config.entity != 'sales_documents' && config.entity != 'purchase_documents') return const [];
    final fne = fneCertFor(doc['id'] as String?);
    if (fne == null) return const [];
    if (fne['status'] != 'certified') {
      return [pw.SizedBox(height: 12), pw.Text(tr('Certification FNE (DGI) en cours de transmission.'), style: pw.TextStyle(fontSize: 9, color: grey))];
    }
    return [
      pw.SizedBox(height: 16),
      pw.Container(
        padding: const pw.EdgeInsets.all(10),
        decoration: pw.BoxDecoration(border: pw.Border.all(color: primary, width: 1), borderRadius: pw.BorderRadius.circular(4)),
        child: pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.center, children: [
          if (fne['verify_url'] != null) pw.BarcodeWidget(barcode: pw.Barcode.qrCode(), data: '${fne['verify_url']}', width: 76, height: 76, drawText: false),
          pw.SizedBox(width: 12),
          pw.Expanded(
            child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
              pw.Text(tr(fne['invoice_type'] == 'refund' ? 'AVOIR NORMALISÉ ÉLECTRONIQUE — DGI' : 'FACTURE NORMALISÉE ÉLECTRONIQUE — DGI'), style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10, color: primary)),
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
  }

  pw.Widget signBox(String title, String hint, {String? bottom}) => pw.Expanded(
        child: pw.Container(
          height: 90,
          padding: const pw.EdgeInsets.all(8),
          decoration: pw.BoxDecoration(border: pw.Border.all(color: PdfColors.grey400), borderRadius: pw.BorderRadius.circular(radius.clamp(0, 6))),
          child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
            pw.Text(tr(title), style: pw.TextStyle(fontSize: 9, fontWeight: pw.FontWeight.bold, color: primary)),
            pw.Text(tr(hint), style: pw.TextStyle(fontSize: 8, color: grey)),
            pw.Spacer(),
            if (bottom != null) pw.Text(tr(bottom), style: pw.TextStyle(fontSize: 8, color: grey)),
          ]),
        ),
      );

  final companyName = '${company['name'] ?? 'la société'}';

  List<pw.Widget> render(Map<String, dynamic> b) => switch ('${b['type']}') {
        'header' => header(),
        'parties' => parties(b),
        'info' => info(),
        'lines' => lineTable(),
        'totals' => totals(),
        'words' => wordsBlock(),
        'notes' => notes(),
        'payment' => payment(),
        'fne' => fneBlock(),
        // Case « Pour décharge » : signature du client à la remise de la facture ou de la marchandise
        'receipt' => !receiptBox
            ? const []
            : [
                pw.SizedBox(height: 20),
                pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
                  signBox('Pour décharge — le client', 'Nom, date, signature et cachet', bottom: 'Reçu le : ____ / ____ / ________'),
                  pw.SizedBox(width: 16),
                  signBox('Pour $companyName', 'Signature et cachet'),
                ]),
              ],
        // « Bon pour accord » : devis, offres et proformas
        'acceptance' => !(sale && offer)
            ? const []
            : [
                pw.SizedBox(height: 20),
                pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
                  signBox('Bon pour accord — le client', 'Date, nom, signature et cachet, précédés de la mention « Bon pour accord »', bottom: 'Le : ____ / ____ / ________'),
                  pw.SizedBox(width: 16),
                  signBox('Pour $companyName', 'Signature et cachet'),
                ]),
              ],
        'signature' => [
            pw.SizedBox(height: 20),
            pw.Row(children: [
              if (b['align'] != 'left') pw.Spacer(),
              pw.SizedBox(width: 230, child: pw.Row(children: [signBox('${b['label'] ?? 'Pour $companyName'}', '${b['hint'] ?? 'Signature et cachet'}')])),
              if (b['align'] == 'left') pw.Spacer(),
            ]),
          ],
        'text' => '${b['text'] ?? ''}'.trim().isEmpty
            ? const []
            : [
                pw.SizedBox(height: 8),
                pw.Text(
                  tr('${b['text']}'),
                  textAlign: switch (b['align']) { 'center' => pw.TextAlign.center, 'right' => pw.TextAlign.right, _ => pw.TextAlign.left },
                  style: pw.TextStyle(fontSize: toDouble(b['size'] ?? 10).clamp(7, 20), fontWeight: b['bold'] == true ? pw.FontWeight.bold : null, color: b['color'] == 'primary' ? primary : null),
                ),
              ],
        'divider' => [pw.SizedBox(height: 8), pw.Divider(color: b['color'] == 'accent' ? accent : _tint(primary, .6), thickness: 1)],
        'spacer' => [pw.SizedBox(height: toDouble(b['height'] ?? 16).clamp(4, 120))],
        'footer_note' => settings['invoice_footer'] == null ? const [] : [pw.SizedBox(height: 20), pw.Text(tr('${settings['invoice_footer']}'), style: pw.TextStyle(fontSize: 9, color: grey))],
        _ => const [],
      };

  pdf.addPage(pw.MultiPage(
    pageTheme: pw.PageTheme(
      pageFormat: PdfPageFormat.a4,
      margin: pw.EdgeInsets.fromLTRB(style['sidebar'] == true ? 46 : 36, 36, 36, 48),
      theme: pw.ThemeData.withFont(base: regular, bold: bold),
      buildBackground: (ctx) => pw.FullPage(
        ignoreMargins: true,
        child: pw.Stack(children: [
          // Liseré latéral (modèles qui le prévoient)
          if (style['sidebar'] == true) pw.Positioned(left: 0, top: 0, bottom: 0, child: pw.Container(width: 12, color: primary)),
          if (style['sidebar'] == true) pw.Positioned(left: 12, top: 0, bottom: 0, child: pw.Container(width: 3, color: accent)),
          // Filigrane « BROUILLON » : un brouillon ne vaut pas facture
          if (isDraft)
            pw.Center(
              child: pw.Transform.rotate(
                angle: 0.6,
                child: pw.Text(tr('BROUILLON'), style: pw.TextStyle(fontSize: 96, fontWeight: pw.FontWeight.bold, color: PdfColor.fromHex('#ECEFF4'))),
              ),
            ),
        ]),
      ),
    ),
    footer: (ctx) => pw.Column(children: [
      pw.Divider(color: _tint(primary, .7)),
      // Pied de page : identité légale, coordonnées et banque de la société
      pw.Text(
        tr([
          [company['name'], if (legal.isNotEmpty) legal].join(' — '),
          [company['address'], company['city']].where((e) => e != null && '$e'.isNotEmpty).join(', '),
          if (company['phone'] != null) 'Tél. ${company['phone']}',
          company['email'],
        ].where((e) => e != null && '$e'.isNotEmpty).join(' · ')),
        style: pw.TextStyle(fontSize: 8, color: grey, fontWeight: pw.FontWeight.bold),
        textAlign: pw.TextAlign.center,
      ),
      pw.Text(
        tr([
          if (company['trade_register'] != null) 'RCCM ${company['trade_register']}',
          if (company['tax_id'] != null) 'NCC ${company['tax_id']}',
          if (regime != null) 'Régime : $regime',
          if (sale && !vatSubject(session)) 'Non assujetti à la TVA',
          if (firstBank != null) [firstBank['bank'], if (firstBank['account'] != null) 'RIB ${firstBank['account']}', if (firstBank['iban'] != null) 'IBAN ${firstBank['iban']}'].where((e) => e != null).join(' '),
        ].where((e) => e.isNotEmpty).join(' · ')),
        style: pw.TextStyle(fontSize: 8, color: grey),
        textAlign: pw.TextAlign.center,
      ),
      pw.SizedBox(height: 2),
      pw.Text(tr('Page ${ctx.pageNumber}/${ctx.pagesCount}'), style: pw.TextStyle(fontSize: 8, color: grey)),
    ]),
    build: (ctx) => [for (final b in blocks) ...render(b)],
  ));

  return pdf.save();
}

String _cap(String s) => s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);
