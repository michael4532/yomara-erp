import 'dart:typed_data';

import '../ui/widgets/common.dart';
import 'escpos.dart';
import '../core/json.dart';

const paymentMethodLabels = {
  'cash': 'Espèces',
  'mobile_money': 'Mobile Money',
  'card': 'Carte',
  'bank_transfer': 'Virement',
  'cheque': 'Chèque',
  'other': 'Autre',
  'loyalty': 'Points fidélité',
  'gift_card': 'Carte cadeau',
};

/// Informations d'en-tête communes aux tickets (indépendantes de l'état de l'app).
class ReceiptContext {
  const ReceiptContext({
    required this.companyName,
    this.headerLines = const [],
    required this.cashier,
    required this.currency,
    this.footer = 'Merci de votre visite !',
    this.registerName,
  });

  final String companyName;
  final List<String> headerLines;
  final String cashier;
  final String currency;
  final String footer;
  final String? registerName;
}

void _header(EscPos p, ReceiptContext c) {
  p.text(c.companyName, center: true, bold: true, tall: true);
  for (final l in c.headerLines) {
    p.text(l, center: true);
  }
  p.feed();
}

Uint8List receiptEscPos({
  required ReceiptContext ctx,
  required Map<String, dynamic> doc,
  required List<Map<String, dynamic>> payments,
  double change = 0,
  int columns = 48,
  bool openDrawer = false,
  Map<String, dynamic>? fne,
}) {
  final cur = ctx.currency;
  final p = EscPos(columns: columns)..init();
  if (openDrawer) p.drawer();
  _header(p, ctx);
  p.text('Ticket ${doc['number'] ?? doc['reference'] ?? ''}', center: true, bold: true);
  p.text('${dateTimeFr(doc['created_at'] ?? DateTime.now().toIso8601String())}${ctx.registerName != null ? ' · ${ctx.registerName}' : ''}', center: true);
  p.text('Caissier : ${ctx.cashier}', center: true);
  if (doc['partner_name'] != null) p.text('Client : ${doc['partner_name']}', center: true);
  p.divider();

  for (final raw in (doc['lines'] as List? ?? [])) {
    final l = Map<String, dynamic>.from(raw);
    p.text('${l['description']}');
    final ttc = toDouble(l['unit_price']) * (1 + toDouble(l['tax_rate']) / 100);
    final disc = toDouble(l['discount_percent']);
    p.row('  ${qty(l['quantity'] as num?)} x ${money(ttc, cur)}${disc > 0 ? ' -${qty(disc)}%' : ''}', money(toDouble(l['total']), cur));
  }
  p.divider();
  p.row('Total HT', money(toDouble(doc['subtotal']), cur));
  p.row('TVA', money(toDouble(doc['tax_total']), cur));
  p.row('TOTAL TTC', money(toDouble(doc['total']), cur), bold: true, tall: true);
  p.feed();
  for (final pay in payments) {
    final amount = toDouble(pay['amount']) + (pay['method'] == 'cash' ? change : 0);
    p.row(paymentMethodLabels[pay['method']] ?? '${pay['method']}', money(amount, cur));
  }
  if (change > 0) p.row('Monnaie rendue', money(change, cur), bold: true);
  p.feed();
  if (fne != null && fne['status'] == 'certified') {
    p.text('Facture normalisée électronique DGI', center: true, bold: true);
    if (fne['reference'] != null) p.text('N° FNE ${fne['reference']}', center: true);
    if (fne['verify_url'] != null) p.qr('${fne['verify_url']}', size: columns >= 48 ? 6 : 4);
    p.feed();
  } else if (fne != null) {
    p.text('Certification FNE en cours', center: true);
  }
  p.text(ctx.footer, center: true);
  if (doc['number'] == null) p.text('N° définitif attribué à la synchronisation', center: true);
  p.feed(4);
  p.cut();
  return p.bytes();
}

Uint8List zReportEscPos({required ReceiptContext ctx, required Map<String, dynamic> report, int columns = 48}) {
  final cur = ctx.currency;
  final p = EscPos(columns: columns)..init();
  _header(p, ctx);
  p.text('RAPPORT DE CAISSE (Z)', center: true, bold: true);
  p.text('${ctx.registerName ?? ''} · ${report['user_name'] ?? ctx.cashier}', center: true);
  p.text('Du ${dateTimeFr(report['opened_at'])}', center: true);
  p.text('au ${dateTimeFr(report['closed_at'])}', center: true);
  p.divider();
  p.row('Tickets', '${report['tickets_count']}');
  p.row('Ventes TTC', money(toDouble(report['total_sales']), cur), bold: true);
  p.row('dont TVA', money(toDouble(report['tax_total']), cur));
  p.divider();
  for (final e in asMap(report['by_method']).entries) {
    p.row(paymentMethodLabels[e.key] ?? e.key, money(toDouble((e.value as Map)['total']), cur));
  }
  p.divider();
  p.row('Fond de caisse', money(toDouble(report['opening_cash']), cur));
  p.row('Espèces attendues', money(toDouble(report['expected_cash']), cur));
  p.row('Espèces comptées', money(toDouble(report['counted_cash']), cur));
  p.row('Écart', money(toDouble(report['difference']), cur), bold: true);
  p.feed(4);
  p.cut();
  return p.bytes();
}

/// Petit ticket de test (réglages imprimante).
Uint8List testPageEscPos({required String companyName, int columns = 48, bool openDrawer = false}) {
  final p = EscPos(columns: columns)..init();
  if (openDrawer) p.drawer();
  p.text(companyName, center: true, bold: true, tall: true);
  p.text('Test d\'impression', center: true);
  p.text(dateTimeFr(DateTime.now().toIso8601String()), center: true);
  p.divider();
  p.text('Accents : é è ê à ç ù ô î « »');
  p.row('Largeur', '$columns colonnes');
  p.row('Montant', money(12500, 'XOF'), bold: true);
  p.divider('=');
  p.text('Si ce ticket est lisible et coupé, l\'imprimante est prête.', center: true);
  p.feed(4);
  p.cut();
  return p.bytes();
}

/// Bon de préparation cuisine / bar : gros caractères, sans prix.
Uint8List kitchenTicketEscPos({required String station, required String title, required String subtitle, required List<Map<String, dynamic>> lines, int columns = 48}) {
  final p = EscPos(columns: columns)..init();
  p.text(station.toUpperCase(), center: true, bold: true);
  p.text(title, center: true, bold: true, tall: true, wide: true);
  p.text(subtitle, center: true);
  p.divider();
  int? course;
  for (final l in lines) {
    if (l['course'] != course) {
      course = l['course'] as int?;
      p.text('-- ${kitchenCourseLabel(course)} --', bold: true);
    }
    p.text('${qtyLabel(l['quantity'])} x ${l['description']}', bold: true, tall: true);
    if ((l['note'] ?? '').toString().isNotEmpty) p.text('   > ${l['note']}');
  }
  p.divider();
  p.text(dateTimeFr(DateTime.now().toIso8601String()), center: true);
  p.feed(4);
  p.cut();
  return p.bytes();
}

String kitchenCourseLabel(int? c) => switch (c) { 0 => 'Boissons', 1 => 'Entrées', 2 => 'Plats', 3 => 'Desserts', _ => 'Suite' };

String qtyLabel(dynamic q) {
  final d = toDouble(q);
  return d == d.roundToDouble() ? '${d.toInt()}' : d.toStringAsFixed(2);
}
