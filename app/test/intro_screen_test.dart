import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/screens/intro_screen.dart';

void main() {
  for (final size in const [Size(360, 640), Size(390, 844), Size(820, 1180)]) {
    testWidgets('présentation sans débordement en ${size.width.toInt()}×${size.height.toInt()}', (tester) async {
      tester.view.physicalSize = size;
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MaterialApp(home: IntroScreen(onDone: (_) {})));
      await tester.pump(const Duration(milliseconds: 500));
      expect(find.text('Suivant'), findsOneWidget);
      for (var i = 0; i < 3; i++) {
        await tester.tap(find.text('Suivant'));
        for (var f = 0; f < 12; f++) {
          await tester.pump(const Duration(milliseconds: 60));
        }
      }
      expect(find.text('Se connecter'), findsOneWidget);
      expect(find.text('Pas encore de compte ? Créer mon entreprise'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
