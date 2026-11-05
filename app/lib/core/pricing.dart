import 'local_db.dart';
import 'json.dart';

/// Listes de prix et conditionnements (module « tarifs ») — même logique que le serveur, hors ligne.

double _d(dynamic v) => v is num ? v.toDouble() : double.tryParse('${v ?? ''}'.replaceAll(',', '.')) ?? 0;

List<Map<String, dynamic>> packagingsOf(Map<String, dynamic>? product) =>
    [for (final p in (product?['packagings'] as List? ?? const [])) asMap(p)];

/// Conditionnement correspondant à un code-barres : (article, conditionnement).
(Map<String, dynamic>, Map<String, dynamic>)? packagingByBarcode(String code) {
  final c = code.trim().toLowerCase();
  if (c.isEmpty || !LocalDb.hasEntity('products')) return null;
  for (final p in LocalDb.all('products')) {
    for (final pk in packagingsOf(p)) {
      if ('${pk['barcode'] ?? ''}'.toLowerCase() == c) return (p, pk);
    }
  }
  return null;
}

/// Prix HT d'un conditionnement (ou de l'unité) avant liste de prix.
double basePrice(Map<String, dynamic> product, Map<String, dynamic>? packaging, {bool purchase = false}) {
  final unit = _d(product[purchase ? 'purchase_price' : 'sale_price']);
  if (packaging == null) return unit;
  final own = packaging[purchase ? 'purchase_price' : 'sale_price'];
  return own != null ? _d(own) : unit * _d(packaging['factor']);
}

Map<String, dynamic>? priceListFor(String? partnerId, {DateTime? date}) {
  if (!LocalDb.hasEntity('pl_lists')) return null;
  final day = (date ?? DateTime.now()).toIso8601String().substring(0, 10);
  bool ok(Map<String, dynamic>? l) =>
      l != null && l['active'] != false && (l['start_date'] == null || '${l['start_date']}'.substring(0, 10).compareTo(day) <= 0) && (l['end_date'] == null || '${l['end_date']}'.substring(0, 10).compareTo(day) >= 0);
  final partner = partnerId == null ? null : LocalDb.get('partners', partnerId);
  final own = partner?['price_list_id'] == null ? null : LocalDb.get('pl_lists', '${partner!['price_list_id']}');
  if (ok(own)) return own;
  final def = LocalDb.all('pl_lists').where((l) => l['is_default'] == true).firstOrNull;
  return ok(def) ? def : null;
}

class ResolvedPrice {
  const ResolvedPrice(this.unitPrice, this.discount, this.label);
  final double unitPrice;
  final double discount;
  final String? label;
}

/// Prix applicable : ligne la plus précise (article › catégorie › tout), puis palier de quantité le plus haut atteint.
ResolvedPrice resolvePrice({String? partnerId, required Map<String, dynamic> product, required double quantity, Map<String, dynamic>? packaging, DateTime? date}) {
  final factor = packaging == null ? 1.0 : _d(packaging['factor']);
  final base = basePrice(product, packaging);
  final list = priceListFor(partnerId, date: date);
  if (list == null) return ResolvedPrice(base, 0, null);
  final units = quantity * factor;
  Map<String, dynamic>? best;
  double rank(Map it) => ({'product': 3, 'category': 2, 'all': 1}[it['scope']] ?? 0) * 1e9 + _d(it['min_qty']);
  for (final raw in (list['items'] as List? ?? const [])) {
    final it = asMap(raw);
    final match = switch (it['scope']) {
      'product' => it['product_id'] == product['id'] && (it['packaging'] == null || it['packaging'] == packaging?['name']),
      'category' => product['category_id'] != null && it['category_id'] == product['category_id'],
      _ => true,
    };
    final minUnits = it['packaging'] != null ? _d(it['min_qty']) * factor : _d(it['min_qty']);
    if (match && units + 0.00001 >= minUnits && (best == null || rank(it) > rank(best))) best = it;
  }
  if (best == null) return ResolvedPrice(base, 0, null);
  String q(dynamic v) => _d(v) == _d(v).roundToDouble() ? '${_d(v).toInt()}' : _d(v).toString().replaceAll('.', ',');
  if (best['mode'] == 'price') {
    final price = best['packaging'] != null ? _d(best['price']) : _d(best['price']) * factor;
    return ResolvedPrice(price, 0, '${list['name']}${_d(best['min_qty']) > 0 ? ' (dès ${q(best['min_qty'])})' : ''}');
  }
  return ResolvedPrice(base, _d(best['discount']), '${list['name']} : −${q(best['discount'])} %${_d(best['min_qty']) > 0 ? ' dès ${q(best['min_qty'])}' : ''}');
}
