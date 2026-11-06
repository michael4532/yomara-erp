import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:hive_ce/hive.dart' show Box;
import 'package:uuid/uuid.dart';

import 'api_client.dart';
import 'config.dart';
import 'local_db.dart';
import 'records_offline.dart';
import 'json.dart';

const _uuid = Uuid();

String newId() => _uuid.v4();

/// Problème remonté par la synchronisation (conflit ou refus du serveur).
class SyncIssue {
  SyncIssue({required this.entity, required this.recordId, required this.status, required this.message, required this.at});

  final String entity;
  final String recordId;
  final String status; // conflict | rejected
  final String message;
  final DateTime at;

  Map<String, dynamic> toJson() => {'entity': entity, 'record_id': recordId, 'status': status, 'message': message, 'at': at.toIso8601String()};

  static SyncIssue fromJson(Map m) => SyncIssue(
    entity: '${m['entity']}',
    recordId: '${m['record_id']}',
    status: '${m['status']}',
    message: '${m['message']}',
    at: DateTime.tryParse('${m['at']}') ?? DateTime.now(),
  );
}

/// Moteur de synchronisation hors ligne.
///
/// Écriture : toute modification est d'abord enregistrée localement puis placée
/// dans la file "outbox". Dès que le serveur est joignable, la file est envoyée
/// (POST /sync/push) puis les changements des autres appareils sont récupérés
/// (GET /sync/pull) à partir du dernier curseur.
class SyncEngine extends ChangeNotifier {
  SyncEngine(this.api);

  final ApiClient api;

  bool online = true;
  bool syncing = false;
  DateTime? lastSyncAt;

  /// Progression du téléchargement en cours (0 à 1), utile pour l'écran de premier démarrage.
  final pullProgress = ValueNotifier<double?>(null);

  /// Aucune synchronisation complète n'a encore abouti sur cet appareil pour cette société.
  bool get neverSynced => !LocalDb.isOpen || LocalDb.state().get('last_sync_at') == null;
  String? lastError;
  final List<SyncIssue> issues = [];

  /// Entités synchronisées (fournies par /bootstrap selon les modules activés).
  List<String> entities = [];

  Timer? _timer;
  Timer? _debounce;
  StreamSubscription? _connSub;
  bool _again = false;

  /// Arrêtée (déconnexion) : plus aucune synchronisation ne démarre, même relancée par une précédente.
  bool _stopped = false;
  Completer<void>? _idle;

  /// Se termine quand aucune synchronisation n'est en cours.
  Future<void> idle() => !syncing ? Future.value() : (_idle?.future ?? Future<void>.delayed(const Duration(milliseconds: 50)));

  /// Synchronisation complète maintenant : attend celle en cours (qui a pu commencer avant la dernière saisie)
  /// puis en lance une nouvelle et attend sa fin.
  Future<void> syncNow() async {
    while (syncing) {
      await idle();
    }
    await sync();
  }

  int get pendingCount => (LocalDb.isOpen ? LocalDb.outbox().length : 0) + RecordsOffline.queued;

  void start() {
    _stopped = false;
    _loadIssues();
    _timer?.cancel();
    _timer = Timer.periodic(AppConfig.syncInterval, (_) => sync());
    _connSub?.cancel();
    _connSub = Connectivity().onConnectivityChanged.listen((results) {
      final hasNet = results.any((r) => r != ConnectivityResult.none);
      if (hasNet) sync();
    });
    sync();
  }

  void stop() {
    _stopped = true;
    _again = false;
    _timer?.cancel();
    _debounce?.cancel();
    _connSub?.cancel();
    _timer = null;
  }

  @override
  void dispose() {
    stop();
    super.dispose();
  }

  /// Déclenche une synchronisation peu après une écriture locale.
  void schedule() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 800), sync);
  }

  // ------------------------------------------------------------------
  // Écritures locales
  // ------------------------------------------------------------------

  /// Enregistre localement et met en file d'envoi. Renvoie l'enregistrement local.
  Future<Map<String, dynamic>> save(String entity, Map<String, dynamic> data) async {
    final id = (data['id'] as String?) ?? newId();
    final existing = LocalDb.get(entity, id);
    final record = <String, dynamic>{...?existing, ...data, 'id': id, '_local': true};
    await LocalDb.put(entity, record);

    final payload = Map<String, dynamic>.from(data)..remove('id');
    _enqueue(entity: entity, op: 'upsert', recordId: id, data: payload);
    notifyListeners();
    schedule();
    return record;
  }

  Future<void> remove(String entity, String id) async {
    await LocalDb.remove(entity, id);
    _enqueue(entity: entity, op: 'delete', recordId: id);
    notifyListeners();
    schedule();
  }

  /// Fusionne avec une mutation en attente pour le même enregistrement (non encore envoyée),
  /// afin qu'un seul envoi parte avec la bonne version de base.
  void _enqueue({required String entity, required String op, required String recordId, Map<String, dynamic>? data}) {
    final box = LocalDb.outbox();
    for (final key in box.keys) {
      final raw = box.get(key);
      // Lecture rapide : on ne décode que les envois qui mentionnent cet enregistrement
      if (raw == null || !raw.contains(recordId)) continue;
      final m = Map<String, dynamic>.from(jsonDecode(raw));
      if (m['record_id'] != recordId || m['entity'] != entity || m['in_flight'] == true) continue;

      if (op == 'delete') {
        if (m['op'] == 'upsert' && m['created_offline'] == true) {
          box.delete(key); // créé puis supprimé hors ligne : rien à envoyer
          return;
        }
        m['op'] = 'delete';
        m.remove('data');
      } else if (m['op'] == 'upsert') {
        m['data'] = {...asMap(m['data']), ...?data};
        // La version fusionnée part après les enregistrements créés depuis (ex. devis lié à
        // l'opportunité), sauf si l'un d'eux dépend de celui-ci : l'ordre reste alors inchangé.
        final keys = box.keys.toList();
        final later = keys.sublist(keys.indexOf(key) + 1);
        final referenced = later.any((k) {
          final other = box.get(k);
          return other != null && (jsonDecode(other) as Map)['record_id'] != recordId && other.contains(recordId);
        });
        if (later.isNotEmpty && !referenced) {
          box.delete(key);
          box.add(jsonEncode(m));
          return;
        }
      } else {
        continue;
      }
      box.put(key, jsonEncode(m));
      return;
    }

    final local = LocalDb.get(entity, recordId);
    box.add(jsonEncode({
      'id': newId(),
      'entity': entity,
      'op': op,
      'record_id': recordId,
      'data': data,
      'created_offline': local == null || local['version'] == null,
      'at': DateTime.now().toIso8601String(),
    }));
  }

  /// Données locales en attente pour un enregistrement (pour ne pas les écraser au pull).
  /// Clés « entité/id » des enregistrements modifiés localement et pas encore envoyés.
  Set<String> _pendingIndex() {
    final out = <String>{};
    for (final v in LocalDb.outbox().values) {
      final m = Map<String, dynamic>.from(jsonDecode(v));
      out.add('${m['entity']}/${m['record_id']}');
    }
    return out;
  }

  Map<String, dynamic>? _pendingFor(String entity, String recordId) {
    final box = LocalDb.outbox();
    Map<String, dynamic>? merged;
    for (final v in box.values) {
      final m = Map<String, dynamic>.from(jsonDecode(v));
      if (m['entity'] == entity && m['record_id'] == recordId) {
        if (m['op'] == 'delete') return {'_deleted': true};
        merged = {...?merged, ...asMap(m['data'])};
      }
    }
    return merged;
  }

  // ------------------------------------------------------------------
  // Synchronisation
  // ------------------------------------------------------------------

  Future<void> sync() async {
    // Arrêtée (déconnexion en cours) : une saisie ou un minuteur ne relance plus d'envoi sur des données en cours de fermeture
    if (_stopped || !LocalDb.isOpen || api.token == null) return;
    if (syncing) {
      if (!_stopped) _again = true;
      return;
    }
    syncing = true;
    _idle = Completer<void>();
    unawaited(RecordsOffline.refreshCount());
    notifyListeners();

    try {
      await _push();
      await _pull();
      await RecordsOffline.flush(api);
      online = true;
      lastError = null;
      lastSyncAt = DateTime.now();
      LocalDb.state().put('last_sync_at', lastSyncAt!.toIso8601String());
    } on ApiException catch (e) {
      online = !e.isNetwork;
      lastError = e.isNetwork ? null : e.detail;
    } catch (e) {
      lastError = e.toString();
      if (kDebugMode) debugPrint('sync error: $e');
    } finally {
      syncing = false;
      _idle?.complete();
      _idle = null;
      notifyListeners();
    }

    if (_again && !_stopped) {
      _again = false;
      unawaited(sync());
    }
  }

  Future<void> _push() async {
    final box = LocalDb.outbox();
    while (box.isNotEmpty) {
      final keys = box.keys.take(100).toList();
      final mutations = <Map<String, dynamic>>[];

      final sent = <dynamic>[];
      for (final key in keys) {
        final raw = box.get(key);
        if (raw == null) continue; // retirée entre-temps (créée puis supprimée hors ligne)
        final Map<String, dynamic> m;
        try {
          m = Map<String, dynamic>.from(jsonDecode(raw));
        } catch (_) {
          // Ligne illisible : elle bloquerait toute la file pour toujours ; mise de côté pour le support
          await LocalDb.state().put('outbox_corrupt_$key', raw);
          await box.delete(key);
          continue;
        }
        sent.add(key);
        m['in_flight'] = true;
        await box.put(key, jsonEncode(m));
        final local = LocalDb.get(m['entity'], m['record_id']);
        mutations.add({
          'id': m['id'],
          'entity': m['entity'],
          'op': m['op'],
          'record_id': m['record_id'],
          if (m['data'] != null) 'data': m['data'],
          // Version serveur connue localement : le serveur détecte les conflits.
          if (local?['version'] != null) 'base_version': local!['version'],
        });
      }

      if (mutations.isEmpty) continue;
      Map<String, dynamic> res;
      try {
        res = Map<String, dynamic>.from(await api.post('sync/push', {'mutations': mutations}));
      } catch (e) {
        // Échec réseau : les mutations restent dans la file pour le prochain essai.
        await _release(box, sent);
        rethrow;
      }

      for (final r in (res['results'] as List? ?? const [])) {
        // Résultat incomplet ou d'un autre type : ignoré (la mutation reste en file) au lieu de bloquer toute la synchronisation
        if (r is! Map) continue;
        final result = Map<String, dynamic>.from(r);
        final entity = result['entity'];
        final recordId = result['record_id'];
        final status = '${result['status'] ?? ''}';
        if (entity is! String || recordId is! String || status.isEmpty) continue;
        final record = result['record'] is Map ? Map<String, dynamic>.from(result['record']) : null;

        // Retire la mutation de la file
        final key = sent.firstWhere(
          (k) => box.get(k) != null && jsonDecode(box.get(k)!)['id'] == result['mutation_id'],
          orElse: () => null,
        );
        if (key != null) await box.delete(key);

        if (!LocalDb.hasEntity(entity)) continue;

        if (record != null && result['deleted'] != true) {
          final pending = _pendingFor(entity, recordId);
          if (pending?['_deleted'] == true) continue;
          await LocalDb.put(entity, {...record, ...?pending, if (pending != null) '_local': true});
        } else if (status == 'applied' || result['deleted'] == true) {
          await LocalDb.remove(entity, recordId);
        } else if (status == 'rejected') {
          // Refusé par le serveur (droits, module, validation) : on garde la saisie sur l'appareil,
          // marquée, au lieu de l'effacer (ex. vente de caisse faite hors ligne).
          final local = LocalDb.get(entity, recordId);
          if (local != null) {
            await LocalDb.put(entity, {...local, '_local': true, '_rejected': '${result['message'] ?? 'Refusé par le serveur'}'});
          }
        }

        if (status != 'applied') {
          issues.insert(
            0,
            SyncIssue(
              entity: entity,
              recordId: recordId,
              status: status,
              message: (result['errors'] is Map && (result['errors'] as Map).isNotEmpty)
                  ? (() {
                      final v = (result['errors'] as Map).values.first;
                      return v is List && v.isNotEmpty ? v.first.toString() : v.toString();
                    })()
                  : (result['message'] ?? 'Refusé par le serveur').toString(),
              at: DateTime.now(),
            ),
          );
          if (issues.length > 50) issues.removeLast();
        }
      }
      await _saveIssues();
      notifyListeners();
      // Réponse sans résultat pour certaines mutations : on s'arrête là (elles repartiront à la prochaine
      // synchronisation) au lieu de renvoyer le même lot en boucle au serveur.
      final left = sent.where((k) => box.get(k) != null).toList();
      if (left.isNotEmpty) {
        await _release(box, left);
        break;
      }
    }
  }

  Future<void> _release(Box<String> box, List<dynamic> keys) async {
    for (final key in keys) {
      final raw = box.get(key);
      if (raw == null) continue;
      try {
        final m = Map<String, dynamic>.from(jsonDecode(raw))..remove('in_flight');
        await box.put(key, jsonEncode(m));
      } catch (_) {}
    }
  }

  Future<void> _pull() async {
    final state = LocalDb.state();

    // Si la liste des entités a changé (module activé / désactivé) › resynchronisation complète.
    final known = (state.get('entities') as String?) ?? '';
    final current = (List<String>.from(entities)..sort()).join(',');
    if (known != current) {
      await state.put('cursor', 0);
      await state.put('entities', current);
    }

    var cursor = (state.get('cursor') as int?) ?? 0;
    final start = cursor;
    var hasMore = true;
    while (hasMore) {
      final res = Map<String, dynamic>.from(await api.get('sync/pull', query: {'cursor': cursor, 'limit': 500}));
      final changes = asMap(res['changes']);

      // Lignes en attente d'envoi, lues une seule fois par page (et non une fois par enregistrement reçu)
      final pendingIndex = _pendingIndex();
      final allUpserts = <String, List<Map<String, dynamic>>>{};
      final allDeletes = <String, List<String>>{};
      for (final entry in changes.entries) {
        final entity = entry.key;
        if (!LocalDb.hasEntity(entity) || entry.value is! Map) continue;
        final c = Map<String, dynamic>.from(entry.value);

        final upserts = <Map<String, dynamic>>[];
        for (final r in (c['upserts'] is List ? c['upserts'] as List : const [])) {
          // Ligne sans identifiant texte : elle ferait échouer toute la page, donc toute la synchronisation
          if (r is! Map || r['id'] is! String) continue;
          final rec = Map<String, dynamic>.from(r);
          final pending = pendingIndex.contains('$entity/${rec['id']}') ? _pendingFor(entity, rec['id']) : null;
          if (pending?['_deleted'] == true) continue;
          upserts.add({...rec, ...?pending, if (pending != null) '_local': true});
        }
        final deletes = [for (final d in (c['deletes'] is List ? c['deletes'] as List : const [])) if (d != null) '$d'];
        allUpserts[entity] = upserts;
        allDeletes[entity] = deletes.where((id) => !pendingIndex.contains('$entity/$id')).toList();
      }
      // Toute la page en une fois (une seule écriture sur le navigateur)
      await LocalDb.applyBatch(allUpserts, allDeletes);

      final next = (res['cursor'] as num?)?.toInt() ?? cursor;
      // Curseur qui n'avance pas : on s'arrête au lieu de redemander la même page sans fin
      hasMore = res['has_more'] == true && next > cursor;
      cursor = next;
      await state.put('cursor', cursor);
      final latest = (res['latest'] as num?)?.toInt() ?? cursor;
      pullProgress.value = !hasMore || latest <= start ? 1 : ((cursor - start) / (latest - start)).clamp(0, 1).toDouble();
    }
  }

  /// Oublie les données locales et recharge tout depuis le serveur.
  Future<void> resync() async {
    // Une synchronisation en cours réécrirait son curseur après l'effacement (données manquantes ensuite)
    while (syncing) {
      await idle();
    }
    if (!LocalDb.isOpen) return;
    syncing = true;
    _idle = Completer<void>();
    try {
      await LocalDb.wipeTenant();
    } finally {
      syncing = false;
      _idle?.complete();
      _idle = null;
    }
    _again = false;
    await sync();
  }

  void clearIssues() {
    issues.clear();
    _saveIssues();
    notifyListeners();
  }

  // Les alertes de synchro survivent au redémarrage de l'application (conservées sur l'appareil).
  void _loadIssues() {
    if (!LocalDb.isOpen) return;
    try {
      final raw = LocalDb.state().get('sync_issues');
      issues
        ..clear()
        ..addAll([for (final m in (raw is String ? jsonDecode(raw) as List : const [])) SyncIssue.fromJson(m as Map)]);
    } catch (_) {}
  }

  Future<void> _saveIssues() async {
    if (!LocalDb.isOpen) return;
    try {
      await LocalDb.state().put('sync_issues', jsonEncode([for (final i in issues) i.toJson()]));
    } catch (_) {}
  }
}
