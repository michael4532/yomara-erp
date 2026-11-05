import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:hive_ce/hive.dart';

import 'api_client.dart';
import 'local_db.dart';
import 'json.dart';

/// Hors ligne des espaces de service (fiches marketing, juridique, parc auto, force de vente, développement, rapports) :
/// - les lectures sont gardées en cache et resservies sans réseau ;
/// - les écritures faites sans réseau sont mises en file et rejouées dans l'ordre au retour de la connexion
///   (par la synchronisation habituelle) ; un refus du serveur est conservé pour être montré à la personne.
class RecordsOffline {
  static final ValueNotifier<int> changes = ValueNotifier(0);

  /// Nombre de saisies en attente d'envoi (affiché avec la synchronisation).
  static int queued = 0;

  static Future<void> refreshCount() => _count();

  static Future<void> _count() async {
    queued = LocalDb.isOpen ? (await _queue()).length : 0;
    changes.value++;
  }

  static Future<Box<String>> _cache() => LocalDb.extraBox('records_cache');
  static Future<Box<String>> _queue() => LocalDb.extraBox('records_outbox');
  static Future<Box<String>> _failed() => LocalDb.extraBox('records_failed');

  static String _key(String path, Map<String, dynamic>? query) {
    final q = (query ?? {}).entries.where((e) => e.value != null && '${e.value}'.isNotEmpty).map((e) => '${e.key}=${e.value}').toList()..sort();
    return q.isEmpty ? path : '$path?${q.join('&')}';
  }

  static bool _network(Object e) => e is ApiException && e.isNetwork;

  /// Lecture : serveur si possible (et mise en cache), sinon dernière copie connue.
  static Future<dynamic> get(ApiClient api, String path, {Map<String, dynamic>? query}) async {
    final key = _key(path, query);
    try {
      final r = await api.get(path, query: query);
      if (LocalDb.isOpen) (await _cache()).put(key, jsonEncode(r));
      return r;
    } catch (e) {
      if (!_network(e) || !LocalDb.isOpen) rethrow;
      final c = (await _cache()).get(key);
      if (c == null) rethrow;
      return jsonDecode(c);
    }
  }

  /// Écriture : envoyée tout de suite, ou mise en file sans réseau. Renvoie la réponse du serveur
  /// ou une réponse locale {data: {..., id: 'local-…', _pending: true}, offline: true}.
  static Future<dynamic> send(ApiClient api, String method, String path, [Map<String, dynamic>? body, String? label]) async {
    // Fiche créée hors ligne et pas encore envoyée : on modifie la demande en attente
    final local = RegExp(r'/(local-\d+)(/|$)').firstMatch(path)?.group(1);
    if (local != null) return _editQueued(local, method, path, body);
    try {
      return switch (method) {
        'POST' => await api.post(path, body),
        'PUT' => await api.put(path, body),
        _ => await api.delete(path, body),
      };
    } catch (e) {
      if (!_network(e) || !LocalDb.isOpen) rethrow;
      final id = 'local-${DateTime.now().microsecondsSinceEpoch}';
      final q = await _queue();
      await q.put(id, jsonEncode({'id': id, 'method': method, 'path': path, 'body': body, 'label': label, 'at': DateTime.now().toIso8601String()}));
      await _count();
      return {'data': {...?body, if (method == 'POST') 'id': id, '_pending': true}, 'offline': true};
    }
  }

  static Future<dynamic> _editQueued(String localId, String method, String path, Map<String, dynamic>? body) async {
    final q = await _queue();
    final raw = q.get(localId);
    if (raw == null) throw ApiException('Fiche introuvable.', status: 404);
    if (method == 'DELETE') {
      await q.delete(localId);
      await _count();
      return null;
    }
    final e = asMap(jsonDecode(raw));
    if (path.endsWith(localId)) {
      e['body'] = {...?(e['body'] as Map?)?.cast<String, dynamic>(), ...?body};
      await q.put(localId, jsonEncode(e));
      changes.value++;
      return {'data': {...(e['body'] as Map).cast<String, dynamic>(), 'id': localId, '_pending': true}, 'offline': true};
    }
    throw ApiException('Cette fiche n\'est pas encore envoyée au serveur : reconnectez-vous pour cette action.', status: 409);
  }

  /// Créations et modifications en attente pour une liste (`{module}/records/{fiche}`).
  static Future<List<Map<String, dynamic>>> pending(String path) async {
    if (!LocalDb.isOpen) return const [];
    final q = await _queue();
    return [
      for (final raw in q.values)
        if ((jsonDecode(raw) as Map)['path'] is String) asMap(jsonDecode(raw)),
    ].where((e) => '${e['path']}' == path || '${e['path']}'.startsWith('$path/')).toList();
  }

  static Future<int> count() async => LocalDb.isOpen ? (await _queue()).length : 0;

  static Future<List<Map<String, dynamic>>> failures() async =>
      LocalDb.isOpen ? [for (final raw in (await _failed()).values) asMap(jsonDecode(raw))] : const [];

  static Future<void> clearFailures() async {
    if (LocalDb.isOpen) await (await _failed()).clear();
    changes.value++;
  }

  static bool _flushing = false;

  /// Rejoue la file dans l'ordre ; s'arrête au premier problème de réseau.
  static Future<int> flush(ApiClient api) async {
    if (!LocalDb.isOpen || _flushing) return 0;
    _flushing = true;
    var n = 0;
    try {
      final q = await _queue();
      final keys = q.keys.toList()..sort((a, b) => '$a'.compareTo('$b'));
      for (final k in keys) {
        final e = asMap(jsonDecode(q.get(k)!));
        final body = (e['body'] as Map?)?.cast<String, dynamic>();
        try {
          switch (e['method']) {
            case 'POST':
              await api.post('${e['path']}', body);
            case 'PUT':
              await api.put('${e['path']}', body);
            default:
              await api.delete('${e['path']}', body);
          }
          n++;
        } catch (err) {
          if (_network(err)) break;
          (await _failed()).put('$k', jsonEncode({...e, 'error': err is ApiException ? err.detail : '$err'}));
        }
        await q.delete(k);
      }
    } finally {
      _flushing = false;
      await _count();
    }
    return n;
  }
}
