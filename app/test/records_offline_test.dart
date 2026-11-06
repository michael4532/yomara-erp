import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/api_client.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/core/records_offline.dart';

/// Serveur simulé : hors ligne tant que [online] est faux.
class FakeApi extends ApiClient {
  FakeApi() : super(baseUrl: 'http://test');

  bool online = true;
  final List<String> calls = [];
  final Map<String, dynamic> lists = {'commercial/records/visits': [{'id': 1, 'title': 'Visite A'}, {'id': 2, 'title': 'Visite B'}]};

  void _net() {
    if (!online) throw ApiException('Serveur injoignable');
  }

  @override
  Future<dynamic> get(String path, {Map<String, dynamic>? query}) async {
    _net();
    return {'data': lists[path] ?? []};
  }

  @override
  Future<dynamic> post(String path, [Object? body, Map<String, String>? headers]) async {
    _net();
    final b = body as Map<String, dynamic>?;
    calls.add('POST $path ${b?['title'] ?? b?['km'] ?? ''}');
    if (b?['title'] == 'refusée') throw ApiException('Indiquez le client.', status: 422);
    return {'data': {...?b, 'id': 99}};
  }

  @override
  Future<dynamic> put(String path, [Object? body, Map<String, String>? headers]) async {
    _net();
    calls.add('PUT $path ${(body as Map?)?['status'] ?? ''}');
    return {'data': body};
  }

  @override
  Future<dynamic> delete(String path, [Object? body, Map<String, String>? headers]) async {
    _net();
    calls.add('DELETE $path');
    return null;
  }
}

void main() {
  late FakeApi api;

  setUp(() async {
    final dir = await Directory.systemTemp.createTemp('yomara_records');
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('t${DateTime.now().microsecondsSinceEpoch}', const []);
    api = FakeApi();
  });

  tearDown(() => LocalDb.closeTenant());

  test('les listes consultées restent disponibles sans réseau', () async {
    final first = await RecordsOffline.get(api, 'commercial/records/visits', query: {'status': 'done'});
    api.online = false;
    final again = await RecordsOffline.get(api, 'commercial/records/visits', query: {'status': 'done'});
    expect(again, first);
    expect(() => RecordsOffline.get(api, 'flotte/records/trips'), throwsA(isA<ApiException>()));
  });

  test('les saisies hors ligne sont rejouées dans l\'ordre au retour du réseau', () async {
    api.online = false;
    final created = await RecordsOffline.send(api, 'POST', 'commercial/records/visits', {'title': 'Visite terrain'}, 'Visite');
    expect(created['offline'], true);
    final localId = created['data']['id'] as String;
    expect(localId, startsWith('local-'));
    // modifier une fiche pas encore envoyée : la demande en attente est complétée
    await RecordsOffline.send(api, 'PUT', 'commercial/records/visits/$localId', {'report': 'Client intéressé'});
    await RecordsOffline.send(api, 'POST', 'flotte/trips/5/checkout', {'km': 1200});
    await RecordsOffline.send(api, 'PUT', 'commercial/records/visits/2', {'status': 'done'});
    await RecordsOffline.send(api, 'POST', 'commercial/records/visits', {'title': 'refusée'});
    expect(RecordsOffline.queued, 4);
    final pend = await RecordsOffline.pending('commercial/records/visits');
    expect(pend.first['body'], {'title': 'Visite terrain', 'report': 'Client intéressé'});
    expect(pend.length, 3);

    expect(await RecordsOffline.flush(api), 0); // toujours hors ligne : rien ne part, rien n'est perdu
    expect(RecordsOffline.queued, 4);

    api.online = true;
    expect(await RecordsOffline.flush(api), 3);
    expect(api.calls, ['POST commercial/records/visits Visite terrain', 'POST flotte/trips/5/checkout 1200', 'PUT commercial/records/visits/2 done', 'POST commercial/records/visits refusée']);
    expect(RecordsOffline.queued, 0);
    final failed = await RecordsOffline.failures();
    expect(failed.single['error'], 'Indiquez le client.');
    await RecordsOffline.clearFailures();
    expect(await RecordsOffline.failures(), isEmpty);
  });

  test('supprimer une fiche créée hors ligne l\'enlève de la file', () async {
    api.online = false;
    final c = await RecordsOffline.send(api, 'POST', 'activite/entries', {'title': 'Appel client'});
    await RecordsOffline.send(api, 'DELETE', 'activite/entries/${c['data']['id']}');
    expect(RecordsOffline.queued, 0);
    expect(() => RecordsOffline.send(api, 'POST', 'flotte/trips/${c['data']['id']}/checkout', {}), throwsA(isA<ApiException>()));
  });
}
