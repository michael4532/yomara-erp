import 'dart:async';
import 'dart:js_interop';

import 'package:web/web.dart' as web;

/// Magasin clé → texte unique dans le navigateur : une base IndexedDB, une table, et une seule transaction
/// par lot d'écritures (Hive écrivait les enregistrements un par un : plus de 20 s pour une première synchronisation).
class KvStore {
  KvStore._(this._db);
  final web.IDBDatabase _db;
  static const _table = 'kv';

  static Future<KvStore> open(String name) async {
    final req = web.window.indexedDB.open(name, 1);
    req.onupgradeneeded = ((web.Event _) {
      final db = req.result as web.IDBDatabase;
      if (!db.objectStoreNames.contains(_table)) db.createObjectStore(_table);
    }).toJS;
    await _done(req);
    return KvStore._(req.result as web.IDBDatabase);
  }

  static Future<void> _done(web.IDBRequest req) {
    final c = Completer<void>();
    req.onsuccess = ((web.Event _) => c.complete()).toJS;
    req.onerror = ((web.Event _) => c.completeError(StateError('IndexedDB : ${req.error?.message}'))).toJS;
    return c.future;
  }

  static Future<void> _commit(web.IDBTransaction tx) {
    final c = Completer<void>();
    tx.oncomplete = ((web.Event _) => c.complete()).toJS;
    tx.onerror = ((web.Event _) => c.isCompleted ? null : c.completeError(StateError('IndexedDB : ${tx.error?.message}'))).toJS;
    tx.onabort = ((web.Event _) => c.isCompleted ? null : c.completeError(StateError('IndexedDB : écriture annulée (${tx.error?.message})'))).toJS;
    return c.future;
  }

  Future<Map<String, String>> readAll() async {
    final store = _db.transaction(_table.toJS, 'readonly').objectStore(_table);
    final keysReq = store.getAllKeys();
    final valsReq = store.getAll();
    await _done(keysReq);
    await _done(valsReq);
    final keys = (keysReq.result as JSArray<JSAny?>).toDart;
    final vals = (valsReq.result as JSArray<JSAny?>).toDart;
    return {for (var i = 0; i < keys.length; i++) (keys[i] as JSString).toDart: (vals[i] as JSString).toDart};
  }

  Future<void> write(Map<String, String> put, Iterable<String> delete) async {
    final d = delete.toList();
    if (put.isEmpty && d.isEmpty) return;
    final tx = _db.transaction(_table.toJS, 'readwrite');
    final store = tx.objectStore(_table);
    put.forEach((k, v) => store.put(v.toJS, k.toJS));
    for (final k in d) {
      store.delete(k.toJS);
    }
    await _commit(tx);
  }

  Future<void> clear() async {
    final tx = _db.transaction(_table.toJS, 'readwrite');
    tx.objectStore(_table).clear();
    await _commit(tx);
  }

  Future<void> close() async => _db.close();
}
