import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/api_client.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/core/sync_engine.dart';

List<String> order() => [for (final v in LocalDb.outbox().values) '${(jsonDecode(v) as Map)['entity']}'];

void main() {
  late SyncEngine engine;

  setUp(() async {
    final dir = await Directory.systemTemp.createTemp('yomara_outbox');
    await LocalDb.initAt(dir.path);
    engine = SyncEngine(ApiClient(baseUrl: 'http://127.0.0.1:1'))..entities = ['crm_leads', 'crm_activities', 'sales_documents', 'partners'];
    await LocalDb.openTenant('t${DateTime.now().microsecondsSinceEpoch}', engine.entities);
  });

  tearDown(() => LocalDb.closeTenant());

  test('une modification fusionnée part après le devis qu\'elle référence', () async {
    final lead = await engine.save('crm_leads', {'title': 'Site web'});
    final doc = await engine.save('sales_documents', {'type': 'quote', 'status': 'draft'});
    await engine.save('crm_leads', {'id': lead['id'], 'quote_id': doc['id']});
    expect(order(), ['sales_documents', 'crm_leads']);
    final m = jsonDecode(LocalDb.outbox().values.last) as Map;
    expect(m['data'], {'title': 'Site web', 'quote_id': doc['id']});
    expect(engine.pendingCount, 2);
  });

  test('l\'ordre est conservé quand un enregistrement plus récent en dépend', () async {
    final lead = await engine.save('crm_leads', {'title': 'Site web'});
    await engine.save('crm_activities', {'lead_id': lead['id'], 'subject': 'Rappeler'});
    await engine.save('crm_leads', {'id': lead['id'], 'priority': 3});
    expect(order(), ['crm_leads', 'crm_activities']);
    expect((jsonDecode(LocalDb.outbox().values.first) as Map)['data']['priority'], 3);
  });
}
