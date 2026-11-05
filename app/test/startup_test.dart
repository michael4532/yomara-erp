import 'dart:io';

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:provider/provider.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/main.dart';
import 'package:yomara_erp/state/session.dart';
import 'package:yomara_erp/ui/widgets/common.dart';

void main() {
  testWidgets('démarrage : écran de connexion', (tester) async {
    late Session session;
    await tester.runAsync(() async {
      await initializeDateFormatting('fr');
      final dir = await Directory.systemTemp.createTemp('yomara_ui');
      await LocalDb.initAt(dir.path);
      session = Session();
      await session.restore();
    });
    expect(money(164300), contains('164'));
    expect(dateFr('2026-10-22'), '22/10/2026');

    await tester.pumpWidget(MultiProvider(providers: [
      ChangeNotifierProvider.value(value: session),
      ChangeNotifierProvider.value(value: session.sync),
    ], child: const YomaraErpApp()));
    await tester.pump(const Duration(milliseconds: 100));
    // Premier lancement de l'application installée : présentation avant la connexion
    expect(find.text('Passer'), findsOneWidget);

    await tester.runAsync(() => LocalDb.meta.put('intro_seen', true));
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(MultiProvider(providers: [
      ChangeNotifierProvider.value(value: session),
      ChangeNotifierProvider.value(value: session.sync),
    ], child: const YomaraErpApp()));
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.text('Connectez-vous à votre entreprise'), findsOneWidget);
  });
}
