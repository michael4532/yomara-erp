// Stockage local « navigateur » : une seule box pour toutes les entités.
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/local_db.dart';

void main() {
  test('box commune : lecture, écriture, écoute, réouverture et migration de l\'ancien stockage', () async {
    final dir = await Directory.systemTemp.createTemp('yomara_shared');
    await LocalDb.initAt(dir.path);
    LocalDb.sharedStore = true;
    addTearDown(() => LocalDb.sharedStore = false);

    // Ancien stockage : curseur avancé, sans version → resynchronisation complète
    await LocalDb.openTenant('t1', ['partners', 'products']);
    await LocalDb.closeTenant();
    await LocalDb.openTenant('t1', ['partners', 'products']);
    expect(LocalDb.state().get('cursor'), 0);
    expect(LocalDb.state().get('store_version'), 2);

    var pings = 0;
    LocalDb.listen('partners').addListener(() => pings++);
    await LocalDb.putMany('partners', [
      {'id': 'a', 'name': 'Alpha'},
      {'id': 'b', 'name': 'Beta'},
    ]);
    await LocalDb.put('products', {'id': 'a', 'name': 'Riz'}); // même id, autre entité
    expect(pings, 1);
    expect(LocalDb.all('partners').map((p) => p['name']), unorderedEquals(['Alpha', 'Beta']));
    expect(LocalDb.get('products', 'a')!['name'], 'Riz');
    expect(LocalDb.box('partners').length, 2);
    expect(LocalDb.listen('partners').value.length, 2);

    await LocalDb.removeMany('partners', ['b', 'inconnu']);
    expect(pings, 2);

    // Page de synchronisation appliquée d'un bloc
    await LocalDb.applyBatch({
      'partners': [{'id': 'c', 'name': 'Gamma'}],
      'products': [{'id': 'p2', 'name': 'Huile'}],
    }, {
      'partners': ['c2'],
      'products': [],
    });
    expect(pings, 3);
    expect(LocalDb.get('partners', 'c')!['name'], 'Gamma');
    await LocalDb.applyBatch({}, {'partners': ['c']});
    expect(LocalDb.get('partners', 'c'), isNull);
    await LocalDb.state().put('cursor', 42);

    // Réouverture : les données et le curseur sont conservés (pas de nouvelle resynchronisation)
    await LocalDb.closeTenant();
    await LocalDb.openTenant('t1', ['partners', 'products', 'warehouses']);
    expect(LocalDb.state().get('cursor'), 42);
    expect(LocalDb.all('partners').single['name'], 'Alpha');
    expect(LocalDb.get('products', 'a')!['name'], 'Riz');
    expect(LocalDb.all('warehouses'), isEmpty);

    // Effacement complet de la société
    await LocalDb.wipeTenant();
    expect(LocalDb.all('partners'), isEmpty);
    await LocalDb.closeTenant();
    await LocalDb.openTenant('t1', ['partners', 'products']);
    expect(LocalDb.all('products'), isEmpty);
    expect(LocalDb.state().get('store_version'), 2);
    await LocalDb.closeTenant();
  });
}
