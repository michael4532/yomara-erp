/// Villes et communes de Côte d'Ivoire proposées sur les formulaires (bornes d'accueil, inscriptions).
/// Même liste sur le serveur : app/Support/Localites.php.
const localitesCI = <String>[
  'Abidjan',
  'Abobo',
  'Adjamé',
  'Anyama',
  'Attécoubé',
  'Bingerville',
  'Cocody',
  'Koumassi',
  'Marcory',
  'Plateau',
  'Port-Bouët',
  'Songon',
  'Treichville',
  'Yopougon',
  'Abengourou',
  'Aboisso',
  'Adiaké',
  'Adzopé',
  'Agboville',
  'Agnibilékrou',
  'Akoupé',
  'Alépé',
  'Arrah',
  'Assinie',
  'Ayamé',
  'Azaguié',
  'Bangolo',
  'Béoumi',
  'Biankouma',
  'Bocanda',
  'Bondoukou',
  'Bongouanou',
  'Bonoua',
  'Bouaflé',
  'Bouaké',
  'Bouna',
  'Boundiali',
  'Buyo',
  'Dabakala',
  'Dabou',
  'Daloa',
  'Danané',
  'Daoukro',
  'Dimbokro',
  'Divo',
  'Duékoué',
  'Ferkessédougou',
  'Fresco',
  'Gagnoa',
  'Grand-Bassam',
  'Grand-Béréby',
  'Grand-Lahou',
  'Guiglo',
  'Guitry',
  'Issia',
  'Jacqueville',
  'Katiola',
  'Kong',
  'Korhogo',
  'Lakota',
  'M\'Bahiakro',
  'Man',
  'Mankono',
  'Méagui',
  'Minignan',
  'Niakaramandougou',
  'Odienné',
  'Oumé',
  'Sakassou',
  'San-Pédro',
  'Sassandra',
  'Séguéla',
  'Sikensi',
  'Sinfra',
  'Soubré',
  'Taabo',
  'Tabou',
  'Tanda',
  'Tiassalé',
  'Tiébissou',
  'Tingréla',
  'Touba',
  'Toulépleu',
  'Toumodi',
  'Vavoua',
  'Yamoussoukro',
  'Zouan-Hounien',
  'Zuénoula',
];

/// Minuscules sans accents, pour comparer « bouake » et « Bouaké ».
String foldPlace(String v) {
  const from = 'àâäéèêëîïôöùûüç’';
  const to = "aaaeeeeiioouuuc'";
  final s = v.trim().toLowerCase();
  final b = StringBuffer();
  for (final ch in s.split('')) {
    final i = from.indexOf(ch);
    b.write(i < 0 ? ch : to[i]);
  }
  return b.toString().replaceAll(RegExp(r'[\s-]+'), '-');
}

/// Suggestions pour une saisie : d'abord celles qui commencent par le texte, puis celles dont un mot commence par lui.
List<String> suggestPlaces(String query, Iterable<String> names, {int max = 8}) {
  final q = foldPlace(query);
  if (q.isEmpty) return const [];
  final seen = <String>{};
  final starts = <String>[], words = <String>[];
  for (final n in names) {
    final f = foldPlace(n);
    if (f.isEmpty || !seen.add(f) || f == q) continue;
    if (f.startsWith(q)) {
      starts.add(n);
    } else if (f.split(RegExp("[-']")).any((w) => w.startsWith(q))) {
      words.add(n);
    }
  }
  return [...starts, ...words].take(max).toList();
}

/// Communes du district d'Abidjan : affichées avec leur ville dans les suggestions (« Yopougon · Abidjan »).
const communesAbidjan = <String>{'Abobo', 'Adjamé', 'Anyama', 'Attécoubé', 'Bingerville', 'Cocody', 'Koumassi', 'Marcory', 'Plateau', 'Port-Bouët', 'Songon', 'Treichville', 'Yopougon'};

/// Ville dont dépend une commune (null pour une ville).
String? placeCity(String name) => communesAbidjan.contains(name) ? 'Abidjan' : null;
