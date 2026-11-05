// Test d'intégration du moteur hors ligne contre un vrai serveur Yomara ERP.
// Lancer un serveur de démo puis : YOMARA_TEST_SERVER=http://127.0.0.1:8098 flutter test
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/api_client.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/core/stock_calc.dart';
import 'package:yomara_erp/core/sync_engine.dart';

void main() {
  final server = Platform.environment['YOMARA_TEST_SERVER'];

  test('saisie hors ligne puis synchronisation au retour du réseau', () async {
    final dir = await Directory.systemTemp.createTemp('yomara_test');
    await LocalDb.initAt(dir.path);
    LocalDb.sharedStore = Platform.environment['YOMARA_SHARED'] == '1'; // stockage « navigateur »

    final api = ApiClient(baseUrl: server!);
    final login = await api.post('auth/login', {'email': 'admin@demo.ci', 'password': 'password'});
    api.token = login['token'];
    final boot = await api.get('bootstrap');

    final engine = SyncEngine(api)..entities = List<String>.from(boot['sync_entities']);
    await LocalDb.openTenant('test_${DateTime.now().millisecondsSinceEpoch}', engine.entities);
    await engine.sync();
    expect(engine.online, isTrue);

    // ---- Coupure réseau ----
    api.baseUrl = 'http://127.0.0.1:1'; // port fermé
    final product = await engine.save('products', {'name': 'Ciment 50kg', 'sale_price': 6000, 'purchase_price': 4800, 'tax_rate': 18});
    final wh = await engine.save('warehouses', {'name': 'Dépôt Abobo', 'is_default': true});
    await engine.save('stock_moves', {'product_id': product['id'], 'warehouse_id': wh['id'], 'quantity': 200, 'type': 'initial', 'date': '2026-10-22'});
    final customer = await engine.save('partners', {'name': 'BTP Bamba', 'type': 'customer'});
    final invoice = await engine.save('sales_documents', {
      'type': 'invoice',
      'partner_id': customer['id'],
      'partner_name': 'BTP Bamba',
      'date': '2026-10-22',
      'status': 'validated',
      'warehouse_id': wh['id'],
      'lines': [
        {'id': newId(), 'product_id': product['id'], 'description': 'Ciment 50kg', 'quantity': 20, 'unit_price': 6000, 'tax_rate': 18, 'discount_percent': 0},
      ],
    });
    await engine.save('sales_payments', {'document_id': invoice['id'], 'date': '2026-10-22', 'amount': 50000, 'method': 'mobile_money'});
    // modification hors ligne du même produit : fusionnée dans la même mutation
    await engine.save('products', {'id': product['id'], 'sale_price': 6500});

    await engine.sync();
    expect(engine.online, isFalse);
    expect(engine.pendingCount, 6, reason: '6 mutations (produit fusionné)');
    // Stock calculé localement, facture non encore numérotée : 200 − 20
    expect(StockCalc.totals()[product['id']], 180);

    // ---- Retour du réseau ----
    api.baseUrl = server;
    await engine.sync();
    expect(engine.online, isTrue);
    expect(engine.pendingCount, 0);
    expect(engine.issues, isEmpty, reason: engine.issues.map((i) => i.message).join(', '));

    final inv = LocalDb.get('sales_documents', invoice['id'])!;
    expect(inv['number'], startsWith('FAC-'));
    expect(inv['status'], 'validated');
    expect(inv['total'], 141600.0); // 20 × 6000 × 1,18
    expect(inv['payment_status'], 'partial');
    expect(LocalDb.get('products', product['id'])!['sale_price'], 6500.0);
    // Mouvement de vente généré par le serveur et récupéré par le pull
    expect(StockCalc.totals()[product['id']], 180);
    expect(LocalDb.all('stock_moves').where((m) => m['type'] == 'sale' && m['product_id'] == product['id']).length, 1);

    // ---- Conflit : un autre appareil modifie le produit entre-temps ----
    await api.put('core/products/${product['id']}', {'sale_price': 7000, 'version': LocalDb.get('products', product['id'])!['version']});
    await engine.save('products', {'id': product['id'], 'sale_price': 6800});
    await engine.sync();
    expect(engine.issues.single.status, 'conflict');
    expect(LocalDb.get('products', product['id'])!['sale_price'], 7000.0, reason: 'la version serveur est conservée');

    // ---- Suppression propagée depuis le serveur ----
    final draft = await engine.save('sales_documents', {'type': 'quote', 'date': '2026-10-22', 'lines': []});
    await engine.sync();
    await api.delete('ventes/documents/${draft['id']}');
    await engine.sync();
    expect(LocalDb.get('sales_documents', draft['id']), isNull);
  }, skip: server == null ? 'YOMARA_TEST_SERVER non défini' : null, timeout: const Timeout(Duration(minutes: 2)));
}
