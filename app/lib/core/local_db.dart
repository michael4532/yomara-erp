import 'dart:async';
import 'dart:convert';
import 'dart:io' show Directory, File, Platform;

import 'package:flutter/foundation.dart';
import 'package:hive_ce_flutter/hive_ce_flutter.dart';
import 'package:path_provider/path_provider.dart';

import 'kv_store.dart';
import 'secure_store.dart';

/// Base locale (Hive) : fonctionne sur Windows, macOS, Linux, Android, iOS
/// et dans le navigateur (IndexedDB). Toutes les données de la société sont
/// copiées ici pour travailler sans connexion.
///
/// Organisation :
///  - box "meta"               : serveur, jeton, dernier bootstrap (global)
///  - ordinateur / téléphone : box `<tenant>_<entité>` par entité (enregistrements JSON indexés par id)
///  - navigateur : une seule base IndexedDB `<tenant>_data` pour toutes les entités (clé « entité␟id »),
///    écrite par lots ; une base Hive par entité rendait la première connexion très lente
///  - box `<tenant>_outbox`    : modifications faites hors ligne, à envoyer
///  - box `<tenant>_state`     : curseur de synchronisation, caches divers
class LocalDb {
  LocalDb._();

  static late Box meta;

  static String? _tenant;
  static final Map<String, LocalBox> _boxes = {};
  static KvStore? _shared;

  /// Une seule box pour toutes les entités (navigateur). Modifiable pour les tests.
  @visibleForTesting
  static bool sharedStore = kIsWeb;

  /// Version du stockage des entités : un changement impose une resynchronisation complète.
  static const _storeVersion = 2;
  static Box<String>? _outbox;
  static Box? _state;

  /// Dossier des fichiers Hive (ordinateur / téléphone) : utile pour effacer l'espace d'une société.
  static String? _dir;

  /// Chiffrement des données de la société (clé dans le coffre du système ; pas dans le navigateur).
  static HiveAesCipher? get _cipher => sharedStore ? null : SecureStore.cipher;

  static Future<void> init() async {
    // Windows et Linux : données hors du dossier « Documents » (souvent synchronisé avec OneDrive ou partagé),
    // dans le dossier privé de l'application. Les données déjà présentes sont déplacées une fois.
    if (!kIsWeb && (Platform.isWindows || Platform.isLinux)) {
      try {
        final base = await getApplicationSupportDirectory();
        final target = Directory('${base.path}${Platform.pathSeparator}yomara_erp');
        if (!target.existsSync()) {
          try {
            final docs = await getApplicationDocumentsDirectory();
            final old = Directory('${docs.path}${Platform.pathSeparator}yomara_erp');
            if (old.existsSync()) {
              base.createSync(recursive: true);
              try {
                old.renameSync(target.path);
              } catch (_) {
                // autre disque : copie puis suppression
                target.createSync(recursive: true);
                for (final f in old.listSync().whereType<File>()) {
                  f.copySync('${target.path}${Platform.pathSeparator}${f.uri.pathSegments.last}');
                }
                old.deleteSync(recursive: true);
              }
            }
          } catch (_) {}
        }
        target.createSync(recursive: true);
        Hive.init(target.path);
        _dir = target.path;
        meta = await Hive.openBox('meta');
        return;
      } catch (_) {
        // dossier privé indisponible : ancien emplacement
      }
    }
    try {
      await Hive.initFlutter('yomara_erp');
      if (!kIsWeb) {
        try {
          _dir = '${(await getApplicationDocumentsDirectory()).path}${Platform.pathSeparator}yomara_erp';
        } catch (_) {}
      }
    } catch (_) {
      // Linux sans dossier « Documents » (installation minimale, poste partagé) : dossier de données de l'application
      if (kIsWeb) rethrow;
      final base = await getApplicationSupportDirectory();
      Hive.init('${base.path}/yomara_erp');
      _dir = '${base.path}/yomara_erp';
    }
    meta = await Hive.openBox('meta');
  }

  /// Initialisation sur un dossier donné (tests automatisés).
  @visibleForTesting
  static Future<void> initAt(String path) async {
    Hive.init(path);
    _dir = path;
    meta = await Hive.openBox('meta');
  }

  /// Ouvre l'espace local d'une société sur un serveur donné.
  static Future<void> openTenant(String tenant, Iterable<String> entities) async {
    if (_tenant != tenant) {
      await closeTenant();
      _tenant = tenant;
      try {
        await _encryptOnce(tenant);
      } catch (_) {
        // Rien d'ouvert : un nouvel appel (redémarrage, reconnexion) reprend le passage au lieu de croire la société ouverte
        _tenant = null;
        rethrow;
      }
      _outbox = await Hive.openBox<String>('${tenant}_outbox', encryptionCipher: _cipher);
      _state = await Hive.openBox('${tenant}_state', encryptionCipher: _cipher);
      if (sharedStore) {
        _shared = await KvStore.open('${tenant}_data');
        const version = _storeVersion;
        if (_state!.get('store_version') != version) {
          // Ancien stockage (une base par entité) : on recharge tout depuis le serveur, les saisies hors ligne
          // en attente (outbox) sont conservées.
          await _state!.put('cursor', 0);
          await _state!.put('store_version', version);
          final old = entities.toList();
          unawaited(Future(() async {
            for (final e in old) {
              try {
                await Hive.deleteBoxFromDisk('${tenant}_$e');
              } catch (_) {}
            }
          }));
        }
      }
    }
    final missing = entities.where((e) => !_boxes.containsKey(e)).toSet().toList();
    if (sharedStore) {
      // Répartition du magasin commun entre les entités (une seule lecture)
      final byEntity = <String, Map<String, String>>{for (final e in missing) e: <String, String>{}};
      (await _shared!.readAll()).forEach((key, value) {
        final i = key.indexOf(_SharedBox.sep);
        if (i < 0) return;
        final m = byEntity[key.substring(0, i)];
        if (m != null) m[key.substring(i + 1)] = value;
      });
      for (final e in missing) {
        _boxes[e] = _SharedBox(e, _shared!, byEntity[e]!);
      }
    } else {
      // Ouverture en parallèle des boîtes (une par entité)
      final opened = await Future.wait(missing.map((e) => Hive.openBox<String>('${tenant}_$e', encryptionCipher: _cipher)));
      for (var i = 0; i < missing.length; i++) {
        _boxes[missing[i]] = _HiveBox(opened[i]);
      }
    }
  }

  static Future<void> closeTenant() async {
    for (final b in _boxes.values) {
      await b.close();
    }
    _boxes.clear();
    await _shared?.close();
    _shared = null;
    for (final b in _extra.values) {
      if (b.isOpen) await b.close();
    }
    _extra.clear();
    await _outbox?.close();
    await _state?.close();
    _outbox = null;
    _state = null;
    _tenant = null;
  }

  /// Société ouverte (préfixe des boîtes locales).
  static String? get tenant => _tenant;

  static final Map<String, Box<String>> _extra = {};

  /// Boîte locale annexe d'un module (ex. copie locale des e-mails), propre à la société ouverte.
  static Future<Box<String>> extraBox(String name) async {
    // Aucune société ouverte (déconnexion en cours) : surtout pas de boîte « null_… » partagée entre comptes
    if (_tenant == null) throw StateError('Aucune société ouverte');
    final key = '${_tenant}_$name';
    final b = _extra[key];
    if (b != null && b.isOpen) return b;
    return _extra[key] = await Hive.openBox<String>(key, encryptionCipher: _cipher);
  }

  /// Passage au stockage chiffré (une fois par société et par appareil) : les saisies en attente d'envoi sont
  /// reprises, le reste est effacé puis rechargé depuis le serveur à la synchronisation suivante.
  ///
  /// Sans perte si l'application s'arrête en cours de route : les saisies en attente sont d'abord copiées dans
  /// « meta » (elles étaient déjà en clair sur le disque) et reprises de là au démarrage suivant.
  /// Coffre momentanément illisible (trousseau verrouillé, délai dépassé) : les fichiers chiffrés sont mis de côté,
  /// pas effacés, et reviennent quand la même clé est de nouveau disponible.
  static Future<void> _encryptOnce(String tenant) async {
    if (sharedStore) return;
    final cipher = _cipher;
    var done = List<String>.from((meta.get('encrypted_tenants') as List?) ?? const []);
    final aside = List<String>.from((meta.get('encrypted_aside') as List?) ?? const []);
    final backupKey = 'encrypt_backup_$tenant';
    if (cipher == null) {
      // Coffre indisponible : les fichiers chiffrés sont illisibles sans la clé, on les garde de côté
      final restoring = List<String>.from((meta.get('encrypt_restoring') as List?) ?? const []);
      if (done.contains(tenant) || restoring.contains(tenant)) {
        await _moveTenantFiles(tenant, toAside: true);
        if (!aside.contains(tenant)) await meta.put('encrypted_aside', [...aside, tenant]);
        await meta.put('encrypted_tenants', done..remove(tenant));
        if (restoring.remove(tenant)) await meta.put('encrypt_restoring', restoring);
      }
      return;
    }
    // Nouvelle clé (coffre réinitialisé) : les espaces chiffrés avec l'ancienne sont à refaire
    final keyId = SecureStore.keyId;
    if (meta.get('encrypted_key') != keyId) {
      done = [];
      await meta.put('encrypted_key', keyId);
      await meta.put('encrypted_tenants', done);
      await meta.delete('encrypt_restoring');
      // fichiers mis de côté avec l'ancienne clé : illisibles pour toujours
      for (final t in aside) {
        await _deleteTenantFiles(t, aside: true);
      }
      aside.clear();
      await meta.put('encrypted_aside', aside);
    }
    if (done.contains(tenant)) {
      if (meta.containsKey(backupKey)) await meta.delete(backupKey);
      return;
    }
    // Files d'envoi à conserver : synchronisation et demandes faites hors ligne
    const keep = ['outbox', 'records_outbox', 'records_failed'];
    final pending = <String, Map<dynamic, String>>{};
    // Retour des fichiers mis de côté déjà commencé (arrêt en cours de route) : ces fichiers sont chiffrés,
    // ils ne sont ni relus sans la clé ni effacés
    final restoring = List<String>.from((meta.get('encrypt_restoring') as List?) ?? const []);
    final backup = meta.get(backupKey);
    if (backup is String) {
      // Reprise après un arrêt pendant le passage : la copie fait foi (les boîtes ont pu être déjà effacées)
      try {
        (jsonDecode(backup) as Map).forEach((n, entries) {
          if (!keep.contains(n) || entries is! List) return;
          final kept = <dynamic, String>{};
          for (final e in entries) {
            // Copie abîmée : seules les lignes valides (clé Hive, saisie JSON) sont reprises, sans bloquer le démarrage
            if (e is! List || e.length < 2 || e[1] is! String) continue;
            final k = e[0];
            if ((k is int && k >= 0 && k <= 0xFFFFFFFF) || (k is String && k.length <= 255)) kept[k] = e[1] as String;
          }
          pending['$n'] = kept;
        });
      } catch (_) {}
    }
    if (!restoring.contains(tenant)) {
      // Saisies en clair sur le disque (y compris celles faites depuis un arrêt, coffre indisponible entre-temps)
      for (final n in keep) {
        try {
          final old = await Hive.openBox<String>('${tenant}_$n');
          final kept = pending[n] ?? <dynamic, String>{};
          final known = kept.values.toSet();
          var next = kept.keys.whereType<int>().fold<int>(-1, (m, k) => k > m ? k : m) + 1;
          old.toMap().forEach((k, v) {
            // Seulement des saisies lisibles (JSON) : jamais d'octets chiffrés relus sans la clé
            try {
              if (known.contains(v) || jsonDecode(v) is! Map) return;
              if (k is int) {
                kept[next++] = v; // à la suite de la copie, dans l'ordre d'envoi
              } else if (!kept.containsKey(k)) {
                kept[k] = v;
              }
            } catch (_) {}
          });
          if (kept.isNotEmpty) pending[n] = kept;
          await old.close();
        } catch (_) {}
      }
      await meta.put(backupKey, jsonEncode({for (final e in pending.entries) e.key: [for (final x in e.value.entries) [x.key, x.value]]}));
    }
    if (!restoring.contains(tenant)) await _deleteTenantFiles(tenant);
    // Fichiers chiffrés mis de côté avec la même clé : ils reviennent (saisies d'avant comprises)
    if (aside.contains(tenant) || restoring.contains(tenant)) {
      if (!restoring.contains(tenant)) await meta.put('encrypt_restoring', [...restoring, tenant]);
      await _moveTenantFiles(tenant, toAside: false);
      await meta.put('encrypted_aside', aside..remove(tenant));
    }
    for (final e in pending.entries) {
      final box = await Hive.openBox<String>('${tenant}_${e.key}', encryptionCipher: cipher);
      // Reprise après arrêt : une saisie déjà recopiée n'est pas ajoutée deux fois
      final present = box.values.toSet();
      for (final x in e.value.entries) {
        try {
          // file de synchronisation : clés numériques (ordre d'envoi) ; ajoutées à la suite de celles déjà présentes
          if (x.key is int) {
            if (!present.contains(x.value)) await box.add(x.value);
          } else {
            await box.put(x.key, x.value);
          }
        } catch (_) {}
      }
      await box.close();
    }
    await meta.put('encrypted_tenants', [...done, tenant]);
    await meta.put('encrypt_restoring', List<String>.from((meta.get('encrypt_restoring') as List?) ?? const [])..remove(tenant));
    await meta.delete(backupKey);
  }

  static const _asideDir = 'coffre_indisponible';

  /// Met de côté (ou ramène) les fichiers chiffrés d'une société.
  static Future<void> _moveTenantFiles(String tenant, {required bool toAside}) async {
    final dir = _dir;
    if (dir == null || kIsWeb) return;
    final sep = Platform.pathSeparator;
    final aside = Directory('$dir$sep$_asideDir');
    final from = toAside ? Directory(dir) : aside;
    final to = toAside ? aside : Directory(dir);
    try {
      if (!from.existsSync()) return;
      to.createSync(recursive: true);
      for (final f in from.listSync().whereType<File>()) {
        final name = f.uri.pathSegments.last;
        if (!_isTenantFile(tenant, name)) continue;
        try {
          f.renameSync('${to.path}$sep$name');
        } catch (_) {}
      }
    } catch (_) {}
  }

  static bool _isTenantFile(String tenant, String name) {
    final prefix = '${tenant.toLowerCase()}_';
    final other = RegExp('^${RegExp.escape(prefix)}u\\d+_');
    return name.startsWith(prefix) && !other.hasMatch(name) && (name.endsWith('.hive') || name.endsWith('.lock') || name.endsWith('.hivec'));
  }

  /// Fichiers locaux d'une société (et d'elle seule : pas les espaces « _uN » des autres personnes du poste).
  static Future<void> _deleteTenantFiles(String tenant, {bool aside = false}) async {
    final dir = _dir;
    if (dir == null || kIsWeb) return;
    final d = aside ? Directory('$dir${Platform.pathSeparator}$_asideDir') : Directory(dir);
    try {
      if (!d.existsSync()) return;
      for (final f in d.listSync().whereType<File>()) {
        if (_isTenantFile(tenant, f.uri.pathSegments.last)) {
          try {
            f.deleteSync();
          } catch (_) {}
        }
      }
    } catch (_) {}
  }

  /// Effacer toutes les données d'une société sur cet appareil (déconnexion sur un poste partagé).
  static Future<void> eraseTenant(String tenant) async {
    if (_tenant == tenant) await closeTenant();
    if (kIsWeb || sharedStore) {
      // Pas les bases des autres personnes du poste (« <société>_uN_… ») : leurs saisies en attente y sont
      await KvStore.deleteByPrefix('${tenant}_', except: RegExp('^${RegExp.escape(tenant.toLowerCase())}_u\\d+_'));
      for (final n in ['outbox', 'state', 'data']) {
        try {
          await Hive.deleteBoxFromDisk('${tenant}_$n').timeout(const Duration(seconds: 5));
        } catch (_) {}
      }
    } else {
      await _deleteTenantFiles(tenant);
      await _deleteTenantFiles(tenant, aside: true);
    }
    final done = List<String>.from((meta.get('encrypted_tenants') as List?) ?? const [])..remove(tenant);
    await meta.put('encrypted_tenants', done);
    final aside = List<String>.from((meta.get('encrypted_aside') as List?) ?? const []);
    if (aside.remove(tenant)) await meta.put('encrypted_aside', aside);
    await meta.delete('encrypt_backup_$tenant');
    final restoring = List<String>.from((meta.get('encrypt_restoring') as List?) ?? const []);
    if (restoring.remove(tenant)) await meta.put('encrypt_restoring', restoring);
  }

  static bool get isOpen => _tenant != null && _outbox != null && _state != null;

  static Box<String> outbox() => _outbox!;
  static Box state() => _state!;

  static bool hasEntity(String entity) => _boxes.containsKey(entity);

  static LocalBox box(String entity) {
    final b = _boxes[entity];
    if (b == null) throw StateError('Entité locale non ouverte : $entity');
    return b;
  }

  /// Écoute les changements d'une entité (pour rafraîchir l'interface).
  static ValueListenable<LocalBox> listen(String entity) => box(entity).listenable();

  static Map<String, dynamic>? get(String entity, String? id) {
    if (id == null || !hasEntity(entity)) return null;
    final raw = box(entity).get(id);
    return raw == null ? null : Map<String, dynamic>.from(jsonDecode(raw));
  }

  static List<Map<String, dynamic>> all(String entity) {
    if (!hasEntity(entity)) return [];
    return box(entity).values.map((v) => Map<String, dynamic>.from(jsonDecode(v))).toList();
  }

  static Future<void> put(String entity, Map<String, dynamic> record) =>
      box(entity).put(record['id'] as String, jsonEncode(record));

  static Future<void> putMany(String entity, List<Map<String, dynamic>> records) async {
    if (records.isEmpty) return;
    await box(entity).putAll({for (final r in records) r['id'] as String: jsonEncode(r)});
  }

  static Future<void> remove(String entity, String id) => box(entity).delete(id);

  static Future<void> removeMany(String entity, Iterable<String> ids) async {
    final list = ids.toList();
    if (list.isEmpty) return;
    await box(entity).deleteAll(list);
  }

  /// Applique d'un coup les ajouts / suppressions de plusieurs entités (une page de synchronisation).
  /// Navigateur : une seule écriture IndexedDB pour toute la page.
  static Future<void> applyBatch(Map<String, List<Map<String, dynamic>>> upserts, Map<String, List<String>> deletes) async {
    if (sharedStore && _shared != null) {
      final put = <String, String>{};
      final del = <String>[];
      final touched = <_SharedBox>{};
      upserts.forEach((entity, records) {
        final b = _boxes[entity];
        if (b is! _SharedBox || records.isEmpty) return;
        for (final r in records) {
          final id = r['id'] as String;
          final json = jsonEncode(r);
          b._data[id] = json;
          put[b._k(id)] = json;
        }
        touched.add(b);
      });
      deletes.forEach((entity, ids) {
        final b = _boxes[entity];
        if (b is! _SharedBox) return;
        for (final id in ids) {
          if (b._data.remove(id) != null) {
            del.add(b._k(id));
            touched.add(b);
          }
        }
      });
      for (final b in touched) {
        b._changes.ping();
      }
      await _shared!.write(put, del);
      return;
    }
    await Future.wait([
      for (final e in upserts.entries) putMany(e.key, e.value),
      for (final e in deletes.entries) removeMany(e.key, e.value),
    ]);
  }

  /// Efface toutes les données locales de la société courante (resynchronisation complète).
  static Future<void> wipeTenant() async {
    if (sharedStore && _shared != null) {
      for (final b in _boxes.values.whereType<_SharedBox>()) {
        b._data.clear();
        b._changes.ping();
      }
      await _shared!.clear();
    }
    for (final b in _boxes.values) {
      await b.clear();
    }
    await _state?.clear();
    if (sharedStore) await _state?.put('store_version', _storeVersion);
  }
}

/// Enregistrements locaux d'une entité (id → JSON), quelle que soit la façon dont ils sont stockés.
abstract class LocalBox {
  String? get(String id);
  Iterable<String> get values;
  Iterable<String> get keys;
  int get length;
  bool get isEmpty => length == 0;
  bool get isNotEmpty => length > 0;
  bool containsKey(String id);
  Future<void> put(String id, String value);
  Future<void> putAll(Map<String, String> entries);
  Future<void> delete(String id);
  Future<void> deleteAll(Iterable<String> ids);
  Future<void> clear();
  ValueListenable<LocalBox> listenable();
  Future<void> close();
}

/// Ordinateur / téléphone : une box Hive par entité.
class _HiveBox extends LocalBox {
  _HiveBox(this._b);
  final Box<String> _b;
  @override
  String? get(String id) => _b.get(id);
  @override
  Iterable<String> get values => _b.values;
  @override
  Iterable<String> get keys => _b.keys.cast<String>();
  @override
  int get length => _b.length;
  @override
  bool containsKey(String id) => _b.containsKey(id);
  @override
  Future<void> put(String id, String value) => _b.put(id, value);
  @override
  Future<void> putAll(Map<String, String> entries) => _b.putAll(entries);
  @override
  Future<void> delete(String id) => _b.delete(id);
  @override
  Future<void> deleteAll(Iterable<String> ids) => _b.deleteAll(ids);
  @override
  Future<void> clear() => _b.clear();
  @override
  ValueListenable<LocalBox> listenable() => _l ??= _HiveListenable(this, _b.listenable());
  _HiveListenable? _l;
  @override
  Future<void> close() => _b.close();
}

/// Navigateur : la part d'une entité dans la box commune, gardée en mémoire (lectures synchrones).
class _SharedBox extends LocalBox {
  _SharedBox(this.entity, this._store, this._data);
  static const sep = '\u001f';
  final String entity;
  final KvStore _store;
  final Map<String, String> _data;
  late final _changes = _Notifier(this);

  String _k(String id) => '$entity$sep$id';

  @override
  String? get(String id) => _data[id];
  @override
  Iterable<String> get values => _data.values;
  @override
  Iterable<String> get keys => _data.keys;
  @override
  int get length => _data.length;
  @override
  bool containsKey(String id) => _data.containsKey(id);
  @override
  Future<void> put(String id, String value) => putAll({id: value});
  @override
  Future<void> putAll(Map<String, String> entries) async {
    if (entries.isEmpty) return;
    _data.addAll(entries);
    _changes.ping();
    await _store.write({for (final e in entries.entries) _k(e.key): e.value}, const []);
  }

  @override
  Future<void> delete(String id) => deleteAll([id]);
  @override
  Future<void> deleteAll(Iterable<String> ids) async {
    final list = ids.where(_data.containsKey).toList();
    if (list.isEmpty) return;
    for (final id in list) {
      _data.remove(id);
    }
    _changes.ping();
    await _store.write(const {}, list.map(_k));
  }

  @override
  Future<void> clear() => deleteAll(_data.keys.toList());
  @override
  ValueListenable<LocalBox> listenable() => _changes;
  @override
  Future<void> close() async {}
}


class _Notifier extends ChangeNotifier implements ValueListenable<LocalBox> {
  _Notifier(this.value);
  @override
  final LocalBox value;
  void ping() => notifyListeners();
}

class _HiveListenable implements ValueListenable<LocalBox> {
  _HiveListenable(this.value, this._inner);
  @override
  final LocalBox value;
  final Listenable _inner;
  @override
  void addListener(VoidCallback listener) => _inner.addListener(listener);
  @override
  void removeListener(VoidCallback listener) => _inner.removeListener(listener);
}
