import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/core/lots.dart';
import 'package:yomara_erp/core/pricing.dart';

void main() {
  String day(int offset) {
    final n = DateTime.now();
    return DateTime(n.year, n.month, n.day + offset).toIso8601String().substring(0, 10); // calendaire : insensible au changement d'heure
  }

  setUp(() async {
    final dir = await Directory.systemTemp.createTemp('yomara_art');
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('t${DateTime.now().microsecondsSinceEpoch}', ['products', 'partners', 'pl_lists', 'stock_lots', 'stock_moves']);
    await LocalDb.putMany('products', [
      {
        'id': 'eau', 'name': 'Eau 1,5 L', 'type': 'product', 'track_stock': true, 'sale_price': 300, 'purchase_price': 200, 'category_id': 'boissons', 'tracking': 'lot', 'use_expiry': true,
        'packagings': [
          {'name': 'Pack', 'factor': 6, 'barcode': '3000000000006'},
          {'name': 'Carton', 'factor': 12, 'barcode': '3000000000012', 'sale_price': 3200},
        ],
      },
      {'id': 'riz', 'name': 'Riz 25 kg', 'type': 'product', 'sale_price': 15000, 'category_id': 'epicerie'},
      {'id': 'tel', 'name': 'Téléphone', 'type': 'product', 'sale_price': 90000, 'tracking': 'serial'},
      {'id': 'svc', 'name': 'Livraison', 'type': 'service', 'tracking': 'lot'},
    ]);
    await LocalDb.putMany('partners', [
      {'id': 'gros', 'name': 'Grossiste', 'price_list_id': 'pl-gros'},
      {'id': 'cpt', 'name': 'Comptoir'},
    ]);
    await LocalDb.putMany('pl_lists', [
      {
        'id': 'pl-gros', 'name': 'Grossistes', 'active': true,
        'items': [
          {'scope': 'all', 'mode': 'discount', 'discount': 3, 'min_qty': 0},
          {'scope': 'category', 'category_id': 'boissons', 'mode': 'discount', 'discount': 5, 'min_qty': 0},
          {'scope': 'category', 'category_id': 'boissons', 'mode': 'discount', 'discount': 8, 'min_qty': 48},
          {'scope': 'product', 'product_id': 'riz', 'mode': 'price', 'price': 14000, 'min_qty': 10},
          {'scope': 'product', 'product_id': 'eau', 'packaging': 'Carton', 'mode': 'price', 'price': 3000, 'min_qty': 10},
        ],
      },
      {'id': 'promo', 'name': 'Promo', 'is_default': true, 'active': true, 'end_date': day(-1), 'items': [{'scope': 'all', 'mode': 'discount', 'discount': 50}]},
    ]);
    await LocalDb.putMany('stock_lots', [
      {'id': 'l-old', 'product_id': 'eau', 'number': 'L-A', 'expiry_date': day(-2)},
      {'id': 'l-soon', 'product_id': 'eau', 'number': 'L-B', 'expiry_date': day(5)},
      {'id': 'l-late', 'product_id': 'eau', 'number': 'L-C', 'expiry_date': day(60)},
      {'id': 'l-none', 'product_id': 'eau', 'number': 'L-D'},
    ]);
    await LocalDb.putMany('stock_moves', [
      {'id': 'm1', 'product_id': 'eau', 'warehouse_id': 'w1', 'lot_id': 'l-late', 'quantity': 24},
      {'id': 'm2', 'product_id': 'eau', 'warehouse_id': 'w1', 'lot_id': 'l-soon', 'quantity': 12},
      {'id': 'm3', 'product_id': 'eau', 'warehouse_id': 'w2', 'lot_id': 'l-old', 'quantity': 6},
      {'id': 'm4', 'product_id': 'eau', 'warehouse_id': 'w1', 'lot_id': 'l-none', 'quantity': 3},
      {'id': 'm5', 'product_id': 'eau', 'warehouse_id': 'w1', 'lot_id': 'l-soon', 'quantity': -12}, // épuisé
      {'id': 'm6', 'product_id': 'eau', 'warehouse_id': 'w1', 'quantity': 10}, // hors lot
    ]);
  });

  tearDown(() => LocalDb.closeTenant());

  test('conditionnements : prix propre ou unité × contenance, code-barres', () {
    final eau = LocalDb.get('products', 'eau')!;
    final pks = packagingsOf(eau);
    expect(basePrice(eau, null), 300);
    expect(basePrice(eau, pks[0]), 1800);
    expect(basePrice(eau, pks[1]), 3200);
    final hit = packagingByBarcode(' 3000000000012 ');
    expect(hit?.$1['id'], 'eau');
    expect(hit?.$2['name'], 'Carton');
    expect(packagingByBarcode('999'), isNull);
  });

  test('listes de prix : précision, paliers, liste expirée ignorée', () {
    final eau = LocalDb.get('products', 'eau')!;
    final riz = LocalDb.get('products', 'riz')!;
    final carton = packagingsOf(eau)[1];
    final pack = packagingsOf(eau)[0];
    // Client sans liste : la liste par défaut est expirée → prix catalogue
    expect(priceListFor('cpt'), isNull);
    expect(resolvePrice(partnerId: 'cpt', product: eau, quantity: 5).discount, 0);
    // Catégorie (5 %) plus précise que « tout » (3 %)
    var r = resolvePrice(partnerId: 'gros', product: eau, quantity: 6);
    expect(r.discount, 5);
    expect(r.unitPrice, 300);
    // Palier exprimé en unités : 8 packs = 48 bouteilles → 8 %
    r = resolvePrice(partnerId: 'gros', product: eau, quantity: 8, packaging: pack);
    expect(r.discount, 8);
    expect(r.unitPrice, 1800);
    // Prix fixe du carton dès 10 cartons
    r = resolvePrice(partnerId: 'gros', product: eau, quantity: 10, packaging: carton);
    expect(r.unitPrice, 3000);
    expect(r.discount, 0);
    expect(r.label, contains('Grossistes'));
    // 9 cartons : palier non atteint → règle catégorie (108 unités ≥ 48 → 8 %)
    r = resolvePrice(partnerId: 'gros', product: eau, quantity: 9, packaging: carton);
    expect(r.unitPrice, 3200);
    expect(r.discount, 8);
    // Riz : prix fixe dès 10, sinon « tout » à 3 %
    expect(resolvePrice(partnerId: 'gros', product: riz, quantity: 10).unitPrice, 14000);
    expect(resolvePrice(partnerId: 'gros', product: riz, quantity: 2).discount, 3);
  });

  test('lots : suivi, disponibilités FEFO, résumé', () {
    expect(isTracked(LocalDb.get('products', 'eau')), isTrue);
    expect(isTracked(LocalDb.get('products', 'svc')), isFalse);
    expect(isSerial(LocalDb.get('products', 'tel')), isTrue);
    final all = availableLots('eau');
    expect(all.map((l) => l.number), ['L-A', 'L-C', 'L-D']); // périmé d'abord, sans date à la fin, épuisé absent
    expect(all.first.expired, isTrue);
    expect(all[1].daysLeft, 60);
    final w1 = availableLots('eau', warehouseId: 'w1');
    expect(w1.map((l) => l.number), ['L-C', 'L-D']);
    expect(w1.first.quantity, 24);
    expect(lotsSummary([{'lot_id': 'l-late', 'quantity': 2}, {'number': 'SN-1', 'quantity': 1}]), 'L-C × 2, SN-1');
    expect(specQuantity([{'quantity': 2}, {'number': 'x'}]), 3);
    expect(lotsSummary(null), '');
  });
}
