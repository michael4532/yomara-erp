import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/api_client.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/core/records_offline.dart';
import 'package:yomara_erp/core/secure_store.dart';
import 'package:yomara_erp/core/sync_engine.dart';
import 'package:yomara_erp/files/safe_name.dart';
import 'package:yomara_erp/ui/widgets/common.dart' show toDouble;

/// Serveur qui répond sans résultat pour les mutations envoyées.
class _SilentApi extends ApiClient {
  _SilentApi() : super(baseUrl: 'http://test', token: 'x');
  int pushes = 0;

  @override
  Future<dynamic> post(String path, [Object? body, Map<String, String>? headers]) async {
    if (path == 'sync/push') pushes++;
    return {'results': []};
  }

  @override
  Future<dynamic> get(String path, {Map<String, dynamic>? query}) async => {'changes': {}, 'cursor': 0, 'has_more': false};
}

/// Serveur aux réponses incomplètes : résultats mal formés, curseur qui n'avance pas.
class _OddApi extends ApiClient {
  _OddApi() : super(baseUrl: 'http://test', token: 'x');
  int pulls = 0;

  @override
  Future<dynamic> post(String path, [Object? body, Map<String, String>? headers]) async => {
        'results': [null, 'x', {'entity': null, 'status': 'applied'}],
      };

  @override
  Future<dynamic> get(String path, {Map<String, dynamic>? query}) async {
    pulls++;
    return {
      'changes': {
        'products': {
          'upserts': [null, {'id': 5}, {'id': 'p-ok', 'name': 'B'}],
          'deletes': null,
        },
        'other': null,
      },
      'cursor': 3,
      'has_more': true,
    };
  }
}

void main() {
  test('nom de fichier venu du serveur : jamais de chemin', () {
    expect(safeFileName('../../.bashrc'), 'bashrc');
    expect(safeFileName(r'..\..\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup\x.bat'), 'x.bat');
    expect(safeFileName('/etc/passwd'), 'passwd');
    expect(safeFileName('C:evil.txt'), 'C_evil.txt');
    expect(safeFileName('..'), 'fichier');
    expect(safeFileName('CON.txt'), '_CON.txt');
    expect(safeFileName('Facture FAC-001.pdf'), 'Facture FAC-001.pdf');
  });

  test('identifiants de la file hors ligne : uniques même dans la même milliseconde', () {
    final ids = {for (var i = 0; i < 1000; i++) RecordsOffline.newLocalId()};
    expect(ids.length, 1000);
    expect(ids.every((id) => RegExp(r'^local-\d+$').hasMatch(id)), isTrue);
  });

  test('chiffrement : arrêt en plein passage puis coffre momentanément indisponible, sans perte', () async {
    final dir = Directory.systemTemp.createTempSync('ydb_audit');
    LocalDb.sharedStore = false;
    await LocalDb.initAt(dir.path);
    final key = List<int>.generate(32, (i) => 255 - i);

    // Arrêt après l'effacement des boîtes en clair, avant l'écriture chiffrée : la copie de secours fait foi
    SecureStore.debugSetKey(null);
    await LocalDb.meta.put('encrypt_backup_t_crash_1', jsonEncode({'outbox': [[0, '{"id":"m-crash"}']]}));
    SecureStore.debugSetKey(key);
    await LocalDb.openTenant('t_crash_1', const []);
    expect(LocalDb.outbox().values.single, contains('m-crash'));
    await LocalDb.outbox().add('{"id":"m-2"}');
    await LocalDb.closeTenant();
    expect(LocalDb.meta.containsKey('encrypt_backup_t_crash_1'), isFalse);

    // Coffre illisible au démarrage (trousseau verrouillé, délai dépassé) : rien n'est effacé
    SecureStore.debugSetKey(null);
    await LocalDb.openTenant('t_crash_1', const []);
    expect(LocalDb.outbox().isEmpty, isTrue);
    await LocalDb.outbox().add('{"id":"m-3"}'); // saisie faite pendant ce temps
    await LocalDb.closeTenant();

    // La clé revient : saisies d'avant et d'entre-temps, dans l'ordre
    SecureStore.debugSetKey(key);
    await LocalDb.openTenant('t_crash_1', const []);
    expect(LocalDb.outbox().values.map((v) => jsonDecode(v)['id']).toList(), ['m-crash', 'm-2', 'm-3']);
    await LocalDb.closeTenant();
    SecureStore.debugSetKey(null);
  });

  test('réponse du serveur sans résultat : pas de renvoi en boucle', () async {
    final dir = Directory.systemTemp.createTempSync('ydb_push');
    LocalDb.sharedStore = false;
    SecureStore.debugSetKey(null);
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('t_push_1', ['products']);
    final api = _SilentApi();
    final engine = SyncEngine(api)..entities = ['products'];
    await engine.save('products', {'name': 'A'});
    await engine.sync().timeout(const Duration(seconds: 5));
    expect(api.pushes, lessThanOrEqualTo(2));
    expect(engine.pendingCount, 1); // toujours en attente
    expect(jsonDecode(LocalDb.outbox().values.single)['in_flight'], isNull);
    engine.stop();
    await LocalDb.closeTenant();
  });

  test('montants saisis : virgule ou point, séparateurs de milliers', () {
    expect(toDouble('12,50'), 12.5);
    expect(toDouble('12.50'), 12.5);
    expect(toDouble('1 234,50'), 1234.5);
    expect(toDouble('1 234,50'), 1234.5);
    expect(toDouble('1.234,50'), 1234.5);
    expect(toDouble('1,234.50'), 1234.5);
    expect(toDouble('2500'), 2500);
    expect(toDouble(null), 0);
  });

  test('chiffrement : copie de secours abîmée, le démarrage n\'est pas bloqué', () async {
    final dir = Directory.systemTemp.createTempSync('ydb_bad');
    LocalDb.sharedStore = false;
    await LocalDb.initAt(dir.path);
    await LocalDb.meta.put('encrypt_backup_t_bad_1', jsonEncode({
      'outbox': [[null, '{"id":"a"}'], 'x', [1], [2, 5], ['k' * 300, '{"id":"b"}'], [3, '{"id":"ok"}']],
      'records_outbox': {'x': 1},
    }));
    SecureStore.debugSetKey(List<int>.generate(32, (i) => i));
    await LocalDb.openTenant('t_bad_1', const []);
    expect(LocalDb.outbox().values.single, contains('"ok"'));
    await LocalDb.closeTenant();
    SecureStore.debugSetKey(null);
  });

  test('chiffrement : arrêt pendant le retour des fichiers mis de côté, sans perte ni doublon', () async {
    final dir = Directory.systemTemp.createTempSync('ydb_aside');
    LocalDb.sharedStore = false;
    await LocalDb.initAt(dir.path);
    final key = List<int>.generate(32, (i) => (i * 7) % 256);
    SecureStore.debugSetKey(key);
    await LocalDb.openTenant('t_aside_1', const []);
    await LocalDb.outbox().add('{"id":"m-1"}');
    await LocalDb.closeTenant();
    // Coffre indisponible : fichiers chiffrés mis de côté, une saisie en clair entre-temps
    SecureStore.debugSetKey(null);
    await LocalDb.openTenant('t_aside_1', const []);
    await LocalDb.outbox().add('{"id":"m-2"}');
    await LocalDb.closeTenant();
    // Arrêt simulé juste après le retour des fichiers (copie de secours écrite, boîtes en clair effacées)
    await LocalDb.meta.put('encrypt_backup_t_aside_1', jsonEncode({'outbox': [[0, '{"id":"m-2"}']]}));
    for (final f in dir.listSync().whereType<File>()) {
      if (f.uri.pathSegments.last.startsWith('t_aside_1_')) f.deleteSync();
    }
    await LocalDb.meta.put('encrypt_restoring', ['t_aside_1']);
    for (final f in Directory('${dir.path}/coffre_indisponible').listSync().whereType<File>()) {
      f.renameSync('${dir.path}/${f.uri.pathSegments.last}');
    }
    await LocalDb.meta.put('encrypted_aside', <String>[]);
    // Redémarrage avec la clé : rien d'effacé, rien en double
    SecureStore.debugSetKey(key);
    await LocalDb.openTenant('t_aside_1', const []);
    expect(LocalDb.outbox().values.map((v) => jsonDecode(v)['id']).toList(), ['m-1', 'm-2']);
    await LocalDb.closeTenant();
    expect((LocalDb.meta.get('encrypt_restoring') as List), isEmpty);
    // Démarrage suivant : toujours là
    await LocalDb.openTenant('t_aside_1', const []);
    expect(LocalDb.outbox().length, 2);
    await LocalDb.closeTenant();
    SecureStore.debugSetKey(null);
  });

  test('réponses mal formées du serveur : la synchronisation se termine et garde la saisie', () async {
    final dir = Directory.systemTemp.createTempSync('ydb_odd');
    LocalDb.sharedStore = false;
    SecureStore.debugSetKey(null);
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('t_odd_1', ['products']);
    final api = _OddApi();
    final engine = SyncEngine(api)..entities = ['products'];
    await engine.save('products', {'name': 'A'});
    await engine.sync().timeout(const Duration(seconds: 5));
    expect(engine.pendingCount, 1);
    expect(LocalDb.get('products', 'p-ok')?['name'], 'B');
    await engine.sync().timeout(const Duration(seconds: 5));
    expect(api.pulls, lessThanOrEqualTo(3)); // curseur immobile : pas de boucle
    engine.stop();
    await engine.sync(); // arrêtée : ne relance rien
    expect(api.pulls, lessThanOrEqualTo(3));
    await LocalDb.closeTenant();
  });
}
