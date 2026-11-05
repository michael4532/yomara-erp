import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/theme.dart';

/// Les puces de filtre gardent un texte visible et coloré, sélectionnées ou non (logiciels de bureau).
void main() {
  testWidgets('libellé des puces résolu dans les deux états', (t) async {
    final theme = buildTheme(Brightness.light);
    await t.pumpWidget(MaterialApp(
      theme: theme,
      home: Scaffold(body: Row(children: [
        ChoiceChip(label: const Text('Toutes'), selected: true, onSelected: (_) {}),
        ChoiceChip(label: const Text('À vérifier'), selected: false, onSelected: (_) {}),
      ])),
    ));
    Color? colorOf(String s) {
      final rt = t.widget<RichText>(find.descendant(of: find.widgetWithText(ChoiceChip, s), matching: find.byType(RichText)).first);
      return rt.text.style?.color ?? DefaultTextStyle.of(t.element(find.text(s))).style.color;
    }

    expect(colorOf('À vérifier'), YColors.inkSoft);
    expect(colorOf('Toutes'), theme.colorScheme.primary);
    final rt = t.widget<RichText>(find.descendant(of: find.widgetWithText(ChoiceChip, 'À vérifier'), matching: find.byType(RichText)).first);
    expect(rt.text.style?.color?.a, greaterThan(.5));
  });
}
