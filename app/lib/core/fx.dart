import 'local_db.dart';

/// Parités fixes (même règle que le serveur).
double? fixedRate(String base, String currency) {
  final b = base.toUpperCase(), c = currency.toUpperCase();
  if (b == c) return 1;
  if ((b == 'XOF' || b == 'XAF') && c == 'EUR') return 655.957;
  if (b == 'KMF' && c == 'EUR') return 491.96775;
  if ({'XOF', 'XAF'}.contains(b) && {'XOF', 'XAF'}.contains(c)) return 1;
  return null;
}

/// Cours connu à une date (cours synchronisés), null si aucun.
double? localRate(String base, String currency, String? date) {
  final f = fixedRate(base, currency);
  if (f != null) return f;
  if (!LocalDb.hasEntity('fx_rates')) return null;
  final d = date ?? DateTime.now().toIso8601String().substring(0, 10);
  final rows = LocalDb.all('fx_rates').where((r) => r['currency'] == currency.toUpperCase() && '${r['date']}'.compareTo(d) <= 0).toList()
    ..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
  if (rows.isEmpty) return null;
  final v = rows.first['rate'];
  return v is num ? v.toDouble() : double.tryParse('$v');
}

const currencyNames = {
  'XOF': 'Franc CFA (UEMOA)', 'XAF': 'Franc CFA (CEMAC)', 'EUR': 'Euro', 'USD': 'Dollar américain', 'GBP': 'Livre sterling', 'CNY': 'Yuan',
  'GHS': 'Cedi ghanéen', 'NGN': 'Naira', 'GNF': 'Franc guinéen', 'MAD': 'Dirham marocain', 'CHF': 'Franc suisse', 'CAD': 'Dollar canadien', 'AED': 'Dirham des Émirats',
};
