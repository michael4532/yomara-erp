import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/widgets/common.dart';

void main() {
  testWidgets('SaveButton ignore un second appui pendant l\'enregistrement', (tester) async {
    var calls = 0;
    final done = Completer<void>();
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: SaveButton(
          onPressed: () async {
            calls++;
            await done.future;
          },
          child: const Text('Enregistrer'),
        ),
      ),
    ));
    await tester.tap(find.text('Enregistrer'));
    await tester.pump();
    await tester.tap(find.text('Enregistrer'));
    await tester.pump();
    expect(calls, 1);
    done.complete();
    await tester.pumpAndSettle();
    await tester.tap(find.text('Enregistrer'));
    await tester.pump();
    expect(calls, 2);
  });

  testWidgets('pickDate ramène une date initiale hors plage', (tester) async {
    late BuildContext ctx;
    await tester.pumpWidget(MaterialApp(home: Builder(builder: (c) {
      ctx = c;
      return const SizedBox();
    })));
    final first = DateTime(2026, 1, 1), last = DateTime(2026, 12, 31);
    unawaited(pickDate(ctx, initialDate: DateTime(2020, 5, 1), firstDate: first, lastDate: last));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byType(DatePickerDialog), findsOneWidget);
  });
}
