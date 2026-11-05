import 'local_db.dart';

/// Stock calculé localement (fonctionne hors ligne) :
///   mouvements synchronisés
/// + effets des documents validés sur cet appareil mais pas encore numérotés par le serveur.
class StockCalc {
  static const _effects = {
    'sales_documents': {'invoice': -1, 'receipt': -1, 'credit_note': 1},
    'purchase_documents': {'supplier_invoice': 1},
  };

  /// product_id › warehouse_id › quantité
  static Map<String, Map<String, double>> levels() {
    final out = <String, Map<String, double>>{};
    void add(String? product, String? wh, double q) {
      if (product == null) return;
      final w = wh ?? '_';
      out.putIfAbsent(product, () => {})[w] = (out[product]![w] ?? 0) + q;
    }

    for (final m in LocalDb.all('stock_moves')) {
      add(m['product_id'] as String?, m['warehouse_id'] as String?, (m['quantity'] as num?)?.toDouble() ?? 0);
    }

    for (final entry in _effects.entries) {
      for (final d in LocalDb.all(entry.key)) {
        final sign = entry.value[d['type']];
        if (sign == null || d['status'] != 'validated' || d['stock_posted'] == true || d['number'] != null) continue;
        for (final l in (d['lines'] as List? ?? [])) {
          add(l['product_id'] as String?, d['warehouse_id'] as String?, sign * ((l['quantity'] as num?)?.toDouble() ?? 0).abs());
        }
      }
    }
    return out;
  }

  static Map<String, double> totals() =>
      levels().map((p, byWh) => MapEntry(p, byWh.values.fold<double>(0, (a, b) => a + b)));
}
