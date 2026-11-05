import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:yomara_erp/pdf/payslip_pdf.dart';
import 'package:yomara_erp/ui/screens/rh_screen.dart';

const _employee = r'''{"id": 1, "company_id": 1, "matricule": "EMP00001", "first_name": "Awa", "last_name": "Kon\u00e9", "gender": "F", "birth_date": null, "phone": null, "email": null, "address": null, "id_number": null, "job_title": "G\u00e9rante", "department": "Direction", "category": null, "hire_date": "2022-01-01", "end_date": null, "contract_type": "CDI", "base_salary": 450000, "transport_allowance": 30000, "fixed_items": [{"label": "Prime de responsabilit\u00e9", "amount": 50000, "taxable": true, "social": true}], "marital_status": "married", "children_count": 2, "spouse_dependent": false, "parts_override": null, "cnps_number": "1002345678", "is_expatriate": false, "payment_method": "bank_transfer", "payment_account": null, "leave_days_per_month": 2.2, "leave_opening_balance": 0, "notes": null, "active": true, "created_at": "2026-10-22T23:33:40.000000Z", "updated_at": "2026-10-22T23:33:40.000000Z", "deleted_at": null, "full_name": "Kon\u00e9 Awa", "tax_parts": 3, "leave_balance": 19.8}''';

Future<void> _open(WidgetTester t, Map<String, dynamic>? employee) async {
  t.view.physicalSize = const Size(1366, 860);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  await t.pumpWidget(MaterialApp(home: Scaffold(body: Builder(builder: (c) => TextButton(onPressed: () => editEmployee(c, employee), child: const Text('go'))))));
  await t.tap(find.text('go'));
  await t.pumpAndSettle();
}

void main() {
  setUpAll(() => initializeDateFormatting('fr'));

  testWidgets('fiche salarié : création', (t) async {
    await _open(t, null);
    expect(find.text('Identité'), findsOneWidget);
    expect(find.text('Automatique'), findsOneWidget); // matricule attribué par le serveur
  });

  testWidgets('fiche salarié : modification (parts fiscales calculées)', (t) async {
    await _open(t, Map<String, dynamic>.from(jsonDecode(_employee)));
    expect(find.text('Koné Awa'), findsOneWidget);
    expect(find.text('01/01/2022'), findsOneWidget); // date affichée au format français
    await t.scrollUntilVisible(find.textContaining('Parts fiscales retenues : 3,0'), 200, scrollable: find.descendant(of: find.byType(Dialog), matching: find.byType(Scrollable)).first);
    expect(find.textContaining('Parts fiscales retenues : 3,0'), findsOneWidget);
  });

  test('bulletin de paie PDF', () async {
    TestWidgetsFlutterBinding.ensureInitialized();
    final slip = Map<String, dynamic>.from(jsonDecode(File('test/fixtures/payslip.json').readAsStringSync()));
    final bytes = await buildPayslipsPdf(company: {'name': 'Démo SARL', 'city': 'Abidjan'}, currency: 'XOF', period: '2026-10', payslips: [slip, slip]);
    expect(String.fromCharCodes(bytes.take(5)), '%PDF-');
    final out = File('${Directory.systemTemp.path}/bulletin_test.pdf')..writeAsBytesSync(bytes);
    expect(out.lengthSync(), greaterThan(10000));
    expect(periodLabel('2026-10'), 'octobre 2026');
    expect([rateDecimals(2), rateDecimals(6.3), rateDecimals(0.75)], [0, 1, 2]);
  });
}
