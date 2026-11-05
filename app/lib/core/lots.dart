import 'local_db.dart';
import 'json.dart';

/// Lots et numéros de série (module « lots ») : disponibilités calculées hors ligne depuis les mouvements.

bool isTracked(Map<String, dynamic>? product) =>
    product != null && product['type'] != 'service' && product['track_stock'] != false && (product['tracking'] == 'lot' || product['tracking'] == 'serial');

bool isSerial(Map<String, dynamic>? product) => product?['tracking'] == 'serial';

class LotStock {
  LotStock(this.lot, this.quantity);
  final Map<String, dynamic> lot;
  final double quantity;
  String get id => '${lot['id']}';
  String get number => '${lot['number']}';
  DateTime? get expiry => lot['expiry_date'] == null ? null : DateTime.tryParse('${lot['expiry_date']}');
  bool get expired => expiry != null && expiry!.isBefore(DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day));
  int? get daysLeft => expiry?.difference(DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day)).inDays;
}

/// Lots disponibles d'un article (dans un entrepôt si précisé), du plus urgent au moins urgent (FEFO).
List<LotStock> availableLots(String productId, {String? warehouseId}) {
  if (!LocalDb.hasEntity('stock_lots') || !LocalDb.hasEntity('stock_moves')) return const [];
  final qty = <String, double>{};
  for (final m in LocalDb.all('stock_moves')) {
    if (m['product_id'] != productId || m['lot_id'] == null) continue;
    if (warehouseId != null && m['warehouse_id'] != warehouseId) continue;
    final q = m['quantity'];
    qty['${m['lot_id']}'] = (qty['${m['lot_id']}'] ?? 0) + (q is num ? q.toDouble() : double.tryParse('$q') ?? 0);
  }
  final out = <LotStock>[];
  for (final e in qty.entries) {
    if (e.value <= 0.00001) continue;
    final lot = LocalDb.get('stock_lots', e.key);
    if (lot != null) out.add(LotStock(lot, e.value));
  }
  out.sort((a, b) {
    if (a.expiry == null && b.expiry != null) return 1;
    if (b.expiry == null && a.expiry != null) return -1;
    final c = (a.expiry ?? DateTime(9999)).compareTo(b.expiry ?? DateTime(9999));
    return c != 0 ? c : a.number.compareTo(b.number);
  });
  return out;
}

/// Résumé lisible d'une allocation de lots d'une ligne.
String lotsSummary(dynamic spec) {
  final l = (spec as List?) ?? const [];
  if (l.isEmpty) return '';
  return l.map((s) {
    final m = asMap(s);
    final name = m['number'] ?? LocalDb.get('stock_lots', '${m['lot_id']}')?['number'] ?? '?';
    final q = m['quantity'];
    return (q == null || q == 1) ? '$name' : '$name × ${q is num && q == q.roundToDouble() ? q.toInt() : q}';
  }).join(', ');
}

double specQuantity(dynamic spec) =>
    ((spec as List?) ?? const []).fold(0.0, (s, e) => s + (((e as Map)['quantity'] as num?)?.toDouble() ?? 1));
