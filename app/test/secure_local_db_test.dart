import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/core/secure_store.dart';

void main() {
  test('données locales chiffrées : saisies en attente gardées, effacement de la société seule', () async {
    final dir = Directory.systemTemp.createTempSync('ydb');
    LocalDb.sharedStore = false;
    await LocalDb.initAt(dir.path);

    // Avant : stockage en clair avec une saisie en attente
    SecureStore.debugSetKey(null);
    await LocalDb.openTenant('t_srv_1', ['products']);
    await LocalDb.outbox().put('m1', '{"secret":"Mot-Repere-Clair"}');
    await LocalDb.put('products', {'id': 'p1', 'name': 'Produit-Repere'});
    await LocalDb.closeTenant();
    // Espace d'une autre personne du même poste : ne doit pas être touché
    await LocalDb.openTenant('t_srv_1_u7', ['products']);
    await LocalDb.outbox().put('m9', '{"x":1}');
    await LocalDb.closeTenant();

    // Coffre disponible : passage au chiffrement
    SecureStore.debugSetKey(List<int>.generate(32, (i) => i));
    await LocalDb.openTenant('t_srv_1', ['products']);
    expect(LocalDb.outbox().get('m1'), contains('Mot-Repere-Clair')); // saisie conservée
    expect(LocalDb.get('products', 'p1'), isNull); // rechargé depuis le serveur ensuite
    await LocalDb.put('products', {'id': 'p2', 'name': 'Produit-Repere'});
    await LocalDb.closeTenant();
    final raw = File('${dir.path}/t_srv_1_outbox.hive').readAsStringSync(encoding: const Latin1Codec());
    expect(raw.contains('Mot-Repere-Clair'), isFalse); // illisible sur le disque
    expect(File('${dir.path}/t_srv_1_products.hive').readAsStringSync(encoding: const Latin1Codec()).contains('Produit-Repere'), isFalse);

    await LocalDb.openTenant('t_srv_1', ['products']);
    expect(LocalDb.get('products', 'p2')?['name'], 'Produit-Repere'); // relu avec la clé

    // Déconnexion avec effacement : plus rien de cette société, l'autre espace reste
    await LocalDb.eraseTenant('t_srv_1');
    final names = dir.listSync().map((f) => f.uri.pathSegments.last).toList();
    expect(names.where((n) => n.startsWith('t_srv_1_') && !n.startsWith('t_srv_1_u7_')), isEmpty);
    expect(names.any((n) => n.startsWith('t_srv_1_u7_outbox')), isTrue);
    SecureStore.debugSetKey(null);
  });
}
