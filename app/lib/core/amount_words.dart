// Montant en toutes lettres, en français (orthographe traditionnelle, usage des factures en Afrique francophone).
// « Arrêtée la présente facture à la somme de soixante-quatre mille neuf cents francs CFA ».

const _units = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize'];
const _tens = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante'];

String _below100(int n) {
  if (n <= 16) return _units[n];
  if (n < 20) return 'dix-${_units[n - 10]}';
  if (n < 70) {
    final t = n ~/ 10, u = n % 10;
    if (u == 0) return _tens[t];
    return u == 1 ? '${_tens[t]} et un' : '${_tens[t]}-${_units[u]}';
  }
  if (n < 80) return n == 71 ? 'soixante et onze' : 'soixante-${_below100(n - 60)}';
  if (n == 80) return 'quatre-vingts';
  return 'quatre-vingt-${_below100(n - 80)}';
}

String _below1000(int n) {
  final h = n ~/ 100, r = n % 100;
  final parts = <String>[];
  if (h > 0) parts.add(h == 1 ? 'cent' : (r == 0 ? '${_units[h]} cents' : '${_units[h]} cent'));
  if (r > 0 || h == 0) parts.add(_below100(r));
  return parts.join(' ');
}

/// Nombre entier en lettres.
String numberToFrench(int n) {
  if (n == 0) return 'zéro';
  if (n < 0) return 'moins ${numberToFrench(-n)}';
  const scales = [(1000000000, 'milliard'), (1000000, 'million')];
  final parts = <String>[];
  var rest = n;
  for (final (value, name) in scales) {
    final q = rest ~/ value;
    if (q > 0) {
      parts.add('${numberToFrench(q)} $name${q > 1 ? 's' : ''}');
      rest %= value;
    }
  }
  final th = rest ~/ 1000;
  if (th > 0) {
    // « mille » est invariable ; « quatre-vingt » et « cent » perdent leur s devant mille
    var w = th == 1 ? 'mille' : '${_below1000(th)} mille';
    w = w.replaceAll('vingts mille', 'vingt mille').replaceAll('cents mille', 'cent mille');
    parts.add(w);
    rest %= 1000;
  }
  if (rest > 0) parts.add(_below1000(rest));
  return parts.join(' ');
}

/// Nom de la monnaie pour le montant en lettres.
(String, String, String) _currencyWords(String c) => switch (c.toUpperCase()) {
      'XOF' || 'XAF' => ('franc CFA', 'francs CFA', 'centime'),
      'GNF' => ('franc guinéen', 'francs guinéens', 'centime'),
      'CDF' => ('franc congolais', 'francs congolais', 'centime'),
      'EUR' => ('euro', 'euros', 'centime'),
      'USD' => ('dollar', 'dollars', 'cent'),
      'MAD' => ('dirham', 'dirhams', 'centime'),
      'GHS' => ('cedi', 'cedis', 'pesewa'),
      'NGN' => ('naira', 'nairas', 'kobo'),
      'CHF' => ('franc suisse', 'francs suisses', 'centime'),
      'CAD' => ('dollar canadien', 'dollars canadiens', 'cent'),
      _ => (c, c, 'centime'),
    };

/// Montant en lettres avec la monnaie : « soixante-quatre mille neuf cents francs CFA ».
String amountInWords(double amount, String currency) {
  final cents = (amount.abs() * 100).round();
  final whole = cents ~/ 100, frac = cents % 100;
  final (one, many, sub) = _currencyWords(currency);
  // Avant un nom de monnaie, « million(s) » et « milliard(s) » prennent « de »
  var w = numberToFrench(whole);
  final de = whole > 0 && whole % 1000000 == 0 ? ' de' : '';
  w = '$w$de ${whole > 1 ? many : one}';
  if (frac > 0) w += ' et ${numberToFrench(frac)} $sub${frac > 1 ? 's' : ''}';
  return w;
}
