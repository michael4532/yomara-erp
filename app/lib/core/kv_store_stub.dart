import 'package:hive_ce/hive.dart';

/// Magasin clé → texte unique (hors navigateur : une box Hive ; utilisé par les tests du mode « navigateur »).
class KvStore {
  KvStore._(this._box);
  final Box<String> _box;

  static Future<KvStore> open(String name) async => KvStore._(await Hive.openBox<String>(name));

  /// Tout le contenu (chargé une fois à l'ouverture).
  Future<Map<String, String>> readAll() async => {for (final k in _box.keys) k as String: _box.get(k)!};

  Future<void> write(Map<String, String> put, Iterable<String> delete) async {
    if (put.isNotEmpty) await _box.putAll(put);
    final d = delete.toList();
    if (d.isNotEmpty) await _box.deleteAll(d);
  }

  Future<void> clear() => _box.clear();

  Future<void> close() => _box.close();

  /// Bases du navigateur dont le nom commence par [prefix] (sans effet hors navigateur).
  static Future<void> deleteByPrefix(String prefix, {RegExp? except}) async {}
}
