import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/screens/logistique_screen.dart';

void main() {
  testWidgets('la signature du client est exportée en PNG', (tester) async {
    final key = GlobalKey<SignaturePadState>();
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: SizedBox(width: 360, child: SignaturePad(key: key)))));
    expect(find.text('Signez ici'), findsOneWidget);
    expect(await key.currentState!.exportPng(), isNull);

    await tester.drag(find.byType(SignaturePad), const Offset(120, 30));
    await tester.pump();
    expect(find.text('Signez ici'), findsNothing);
    final png = await tester.runAsync(() => key.currentState!.exportPng());
    expect(png, startsWith('data:image/png;base64,'));

    key.currentState!.clear();
    await tester.pump();
    expect(key.currentState!.isEmpty, isTrue);
  });
}
