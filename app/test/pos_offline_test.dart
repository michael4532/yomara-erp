// Caisse hors ligne contre un vrai serveur de démo (DemoSeeder).
// YOMARA_TEST_SERVER=http://127.0.0.1:8000 flutter test test/pos_offline_test.dart
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/api_client.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/core/stock_calc.dart';
import 'package:yomara_erp/core/sync_engine.dart';
import 'package:yomara_erp/ui/screens/document_editor.dart' show computeLine;

void main() {
  final server = Platform.environment['YOMARA_TEST_SERVER'];

  test('journée de caisse sans connexion puis synchronisation', () async {
    final dir = await Directory.systemTemp.createTemp('yomara_pos');
    await LocalDb.initAt(dir.path);
    LocalDb.sharedStore = Platform.environment['YOMARA_SHARED'] == '1'; // stockage « navigateur »
    final api = ApiClient(baseUrl: server!);
    api.token = (await api.post('auth/login', {'email': 'admin@demo.ci', 'password': 'password'}))['token'];
    final boot = await api.get('bootstrap');
    expect(List<String>.from(boot['enabled_modules']), contains('pos'));

    final engine = SyncEngine(api)..entities = List<String>.from(boot['sync_entities']);
    await LocalDb.openTenant('pos_${DateTime.now().millisecondsSinceEpoch}', engine.entities);
    await engine.sync();

    final register = LocalDb.all('pos_registers').first;
    final coca = LocalDb.all('products').firstWhere((p) => p['name'] == 'Coca-Cola 33cl');
    final riz = LocalDb.all('products').firstWhere((p) => p['name'] == 'Riz parfumé 5kg');
    final cocaStart = StockCalc.totals()[coca['id']] ?? 0;
    final rizStart = StockCalc.totals()[riz['id']] ?? 0;

    // ---- Coupure ----
    api.baseUrl = 'http://127.0.0.1:1';
    final session = await engine.save('pos_sessions', {'register_id': register['id'], 'status': 'open', 'opening_cash': 20000});

    Future<Map<String, dynamic>> ticket(List<(Map<String, dynamic>, int)> items, List<(String, double)> pays, String ref) async {
      final lines = [
        for (final (p, q) in items)
          {
            'id': newId(), 'product_id': p['id'], 'description': p['name'], 'quantity': q,
            'unit_price': p['sale_price'], 'tax_rate': p['tax_rate'], 'discount_percent': 0,
          },
      ];
      final total = lines.fold<double>(0, (s, l) => s + computeLine(l)['total']!);
      final doc = await engine.save('sales_documents', {
        'type': 'receipt', 'status': 'validated', 'date': '2026-10-22', 'reference': ref,
        'pos_session_id': session['id'], 'warehouse_id': register['warehouse_id'], 'lines': lines, 'total': total,
      });
      for (final (m, a) in pays) {
        await engine.save('sales_payments', {'document_id': doc['id'], 'date': '2026-10-22', 'amount': a, 'method': m});
      }
      return doc;
    }

    final t1 = await ticket([(coca, 4), (riz, 1)], [('cash', 6500)], 'C1-000001'); // 2000 + 4500
    await ticket([(coca, 2)], [('mobile_money', 600), ('cash', 400)], 'C1-000002');
    expect(StockCalc.totals()[coca['id']], cocaStart - 6, reason: 'stock décrémenté hors ligne');

    await engine.save('pos_sessions', {'id': session['id'], 'status': 'closed', 'counted_cash': 26900});
    await engine.sync();
    expect(engine.online, isFalse);

    // ---- Retour du réseau ----
    api.baseUrl = server;
    await engine.sync();
    expect(engine.pendingCount, 0);
    expect(engine.issues, isEmpty, reason: engine.issues.map((i) => i.message).join(' | '));

    final s = LocalDb.get('pos_sessions', session['id'])!;
    expect(s['status'], 'closed');
    expect(s['total_sales'], 7500.0);
    expect(s['tickets_count'], 2);
    expect(s['expected_cash'], 26900.0);
    expect(s['difference'], 0.0);

    final doc = LocalDb.get('sales_documents', t1['id'])!;
    expect(doc['number'], startsWith('TK-'));
    expect(doc['reference'], 'C1-000001');
    expect(doc['payment_status'], 'paid');
    expect(StockCalc.totals()[coca['id']], cocaStart - 6, reason: 'mouvements serveur reçus, plus de double comptage');
    expect(StockCalc.totals()[riz['id']], rizStart - 1);
  }, skip: server == null ? 'YOMARA_TEST_SERVER non défini' : null, timeout: const Timeout(Duration(minutes: 2)));
}
