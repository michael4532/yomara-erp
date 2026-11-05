import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/approvals.dart';
import 'package:yomara_erp/core/custom_fields.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/ui/widgets/custom_fields_editor.dart';

void main() {
  setUp(() async {
    final dir = await Directory.systemTemp.createTemp('yomara_cf');
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('t${DateTime.now().microsecondsSinceEpoch}', ['cf_fields', 'wf_rules', 'wf_requests']);
    await LocalDb.putMany('cf_fields', [
      {'id': 'f1', 'entity': 'partners', 'key': 'secteur', 'label': 'Secteur', 'type': 'select', 'options': ['Hôtellerie', 'Commerce'], 'required': true, 'default_value': 'Commerce', 'sort': 1},
      {'id': 'f2', 'entity': 'partners', 'key': 'verifie', 'label': 'Vérifié', 'type': 'checkbox', 'sort': 2},
      {'id': 'f3', 'entity': 'partners', 'key': 'ancien', 'label': 'Ancien', 'type': 'text', 'active': false},
      {'id': 'f4', 'entity': 'partners', 'key': null, 'label': 'Pas encore synchronisé', 'type': 'text'},
      {'id': 'f5', 'entity': 'products', 'key': 'garantie', 'label': 'Garantie', 'type': 'number', 'required': true},
      {'id': 'f6', 'entity': 'partners', 'key': 'ca', 'label': 'CA annoncé', 'type': 'money', 'sort': 3},
    ]);
    await LocalDb.putMany('wf_rules', [
      {'id': 'r1', 'name': 'Remises', 'active': true, 'sort': 0, 'targets': ['sale.quote'], 'discount_over': 15, 'steps': [{'name': 'Direction', 'mode': 'any', 'roles': [8], 'users': []}]},
      {'id': 'r2', 'name': 'Gros achats', 'active': true, 'sort': 0, 'targets': ['purchase.purchase_order'], 'min_amount': 1000000, 'steps': [{'name': 'Compta', 'roles': [5], 'users': []}, {'name': 'DG', 'mode': 'all', 'roles': [8], 'users': [3]}]},
      {'id': 'r3', 'name': 'Très gros achats', 'active': true, 'sort': 0, 'targets': ['purchase.purchase_order'], 'min_amount': 5000000, 'steps': [{'name': 'DG', 'roles': [8], 'users': []}]},
      {'id': 'r4', 'name': 'Inactif', 'active': false, 'sort': 0, 'targets': ['expense'], 'steps': []},
    ]);
  });

  tearDown(() => LocalDb.closeTenant());

  test('champs actifs, ordre, valeurs affichées et obligatoires', () {
    expect(customFieldsFor('partners').map((f) => f['key']), ['secteur', 'verifie', 'ca']);
    final f = customFieldsFor('partners');
    expect(customDisplay(f[1], true), 'Oui');
    expect(customDisplay(f[2], 1500000.5), '1 500 000,50');
    expect(customDisplay({'type': 'date'}, '2026-03-15'), '15/03/2026');
    expect(customDisplay({'type': 'multiselect'}, ['A', 'B']), 'A, B');
    expect(customMissing('products', {}), ['Garantie']);
    expect(customMissing('products', {'garantie': 0}), isEmpty);
    expect(customMissing('partners', {}), isEmpty); // valeur par défaut appliquée par le serveur
    expect(customMissing('partners', {'secteur': null}), ['Secteur']); // vidé volontairement
  });

  test('circuit applicable : cible, remise, montant, priorité au seuil le plus élevé', () {
    expect(approvalRuleFor(target: 'sale.quote', amount: 100, discount: 10), isNull);
    expect(approvalRuleFor(target: 'sale.quote', amount: 100, discount: 20)?['id'], 'r1');
    expect(approvalRuleFor(target: 'purchase.purchase_order', amount: 900000), isNull);
    expect(approvalRuleFor(target: 'purchase.purchase_order', amount: 1500000)?['id'], 'r2');
    expect(approvalRuleFor(target: 'purchase.purchase_order', amount: 6000000)?['id'], 'r3');
    expect(approvalRuleFor(target: 'expense', amount: 999999), isNull);
    final r1 = LocalDb.get('wf_rules', 'r1')!;
    final r2 = LocalDb.get('wf_rules', 'r2')!;
    expect(approvalImplicitFor(r1, {'id': 3, 'role_id': 8}), isTrue);
    expect(approvalImplicitFor(r1, {'id': 3, 'role_id': 5}), isFalse);
    expect(approvalImplicitFor(r2, {'id': 3, 'role_id': 8}), isFalse); // « tous » à plusieurs
    expect(approvalMyTurn({'status': 'pending', 'current_approver_ids': [3, 7]}, 7), isTrue);
    expect(approvalMyTurn({'status': 'approved', 'current_approver_ids': [7]}, 7), isFalse);
  });

  testWidgets('saisie : valeur par défaut, liste, case à cocher, montant', (tester) async {
    final values = <String, dynamic>{};
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: SingleChildScrollView(child: CustomFieldsEditor(entity: 'partners', values: values, creating: true)))));
    expect(values['secteur'], 'Commerce');
    expect(find.text('Secteur *'), findsOneWidget);
    expect(find.text('Ancien'), findsNothing);
    await tester.tap(find.byType(Switch));
    await tester.pump();
    expect(values['verifie'], isTrue);
    await tester.enterText(find.byType(TextField).last, '1 250 000,5');
    expect(values['ca'], 1250000.5);
    expect(customRequiredError('products', {}), 'Champ obligatoire : Garantie');
  });
}
