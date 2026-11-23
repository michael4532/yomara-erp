import 'dart:math';

/// Référence automatique d'un document : initiales de l'entreprise + code aléatoire (ex. « YT-7K3Q9M »).
/// Créée sur l'appareil, donc disponible hors ligne et imprimée tant que le numéro officiel n'est pas attribué.
String companyInitials(String name) {
  const accents = {'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e', 'à': 'a', 'â': 'a', 'ä': 'a', 'î': 'i', 'ï': 'i', 'ô': 'o', 'ö': 'o', 'ù': 'u', 'û': 'u', 'ü': 'u', 'ç': 'c'};
  final clean = name.toLowerCase().split('').map((c) => accents[c] ?? c).join();
  const skip = {'sarl', 'sa', 'sas', 'sasu', 'suarl', 'ets', 'et', 'de', 'des', 'du', 'la', 'le', 'les', 'and', 'the', 'ci'};
  final words = clean.split(RegExp(r'[^a-z0-9]+')).where((w) => w.isNotEmpty && !skip.contains(w)).toList();
  if (words.isEmpty) return 'DOC';
  final out = words.length == 1 ? words.first.substring(0, min(3, words.first.length)) : words.take(4).map((w) => w[0]).join();
  return out.toUpperCase();
}

String autoReference(String companyName) {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans 0/O ni 1/I
  final r = Random.secure();
  return '${companyInitials(companyName)}-${List.generate(6, (_) => alphabet[r.nextInt(alphabet.length)]).join()}';
}
