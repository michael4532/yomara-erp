import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/billetterie.dart';
import 'package:yomara_erp/core/local_db.dart';

void main() {
  String day(int o) => DateTime.now().add(Duration(days: o)).toIso8601String().substring(0, 10);

  setUp(() async {
    final dir = await Directory.systemTemp.createTemp('yomara_tk');
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('t${DateTime.now().microsecondsSinceEpoch}', ['ev_tickets', 'ev_checkins', 'ev_ticket_types']);
    await LocalDb.putMany('ev_ticket_types', [{'id': 'day', 'quantity': 3}, {'id': 'vip'}]);
    await LocalDb.putMany('ev_tickets', [
      {'id': 't1', 'event_id': 'e1', 'type_id': 'day', 'code': 'AB12-CD34', 'token': 'tokenvisiteur00000000000000000001', 'status': 'valid', 'valid_from': day(0), 'valid_to': day(0), 'reentry': false, 'holder_name': 'Awa'},
      {'id': 't2', 'event_id': 'e1', 'type_id': 'vip', 'code': 'VIPP-0001', 'token': 'tokenvip000000000000000000000002', 'status': 'valid', 'valid_from': day(-1), 'valid_to': day(2), 'reentry': true},
      {'id': 't3', 'event_id': 'e1', 'type_id': 'day', 'code': 'XXXX-0003', 'token': 'tokenannule000000000000000000003', 'status': 'cancelled', 'valid_from': day(0), 'valid_to': day(0)},
      {'id': 't4', 'event_id': 'e2', 'code': 'OTHR-0004', 'token': 'tokenautre0000000000000000000004', 'status': 'valid'},
      {'id': 't5', 'event_id': 'e1', 'type_id': 'day', 'code': 'LATE-0005', 'token': 'tokendemain00000000000000000005', 'status': 'valid', 'valid_from': day(1), 'valid_to': day(1)},
    ]);
  });

  tearDown(() => LocalDb.closeTenant());

  test('codes : format, normalisation, recherche par jeton, code ou URL', () {
    final c = tkNewCode();
    expect(RegExp(r'^[A-Z2-9]{4}-[A-Z2-9]{4}$').hasMatch(c), isTrue);
    expect(c.contains(RegExp('[01IO]')), isFalse);
    expect(tkNewToken().length, 32);
    expect(tkNormalize(' ab12cd34 '), 'AB12-CD34');
    expect(tkFind('ab12 cd34')?['id'], 't1');
    expect(tkFind('tokenvip000000000000000000000002')?['id'], 't2');
    expect(tkFind('https://erp.exemple.ci/billet/tokenvip000000000000000000000002')?['id'], 't2');
    expect(tkFind('NOPE-0000'), isNull);
    expect(tkLeft(LocalDb.get('ev_ticket_types', 'day')!), 1); // annulé non compté
    expect(tkLeft(LocalDb.get('ev_ticket_types', 'vip')!), isNull);
  });

  test('contrôle : valide, déjà entré, réentrée, annulé, autre événement, hors dates', () async {
    expect(tkEvaluate('e1', 'AB12-CD34').result, 'ok');
    await LocalDb.put('ev_checkins', {'id': 'c1', 'ticket_id': 't1', 'result': 'ok', 'scanned_at': DateTime.now().toUtc().toIso8601String()});
    final dup = tkEvaluate('e1', 'AB12-CD34');
    expect(dup.result, 'duplicate');
    expect(dup.reason, startsWith('Déjà entré aujourd\'hui à'));
    await LocalDb.put('ev_checkins', {'id': 'c2', 'ticket_id': 't2', 'result': 'ok', 'scanned_at': DateTime.now().toUtc().toIso8601String()});
    expect(tkEvaluate('e1', 'VIPP-0001').ok, isTrue); // réentrée libre
    expect(tkEvaluate('e1', 'XXXX-0003').reason, 'Billet annulé');
    expect(tkEvaluate('e1', 'OTHR-0004').reason, 'Billet d\'un autre événement');
    expect(tkEvaluate('e1', 'LATE-0005').reason, startsWith('Valable du'));
    expect(tkEvaluate('e1', 'ZZZZ-9999').reason, 'Billet inconnu');
    expect(tkEvaluate('e1', 'AB12-CD34', at: DateTime.now().add(const Duration(days: 1))).result, 'refused');
  });
}
