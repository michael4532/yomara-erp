import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/pdf/labels_pdf.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('codes EAN-13 et étiquettes de pesée', () {
    expect(isEan13('5449000000996'), isTrue);
    expect(isEan13('5449000000997'), isFalse);
    expect(weightBarcode('00001', 1.25), '2100001012502');
  });

  test('PDF des étiquettes : planches et rouleaux', () async {
    const d = LabelData(name: 'Riz parfumé 5kg', price: '4 500 F CFA', unitPrice: '900 F CFA / kg', barcode: '2000000000039', sku: 'RIZ-5', company: 'Démo SARL', date: 'Prix au 23/10/2026');
    final out = Platform.environment['LABELS_OUT'];
    for (final f in labelPresets) {
      final bytes = await labelsPdf(format: f.copyWith(fields: f.fields.toSet().union({'sku', 'date'}).toList()), labels: List.filled(f.kind == 'roll' ? 2 : 30, d), skip: f.kind == 'sheet' ? 2 : 0);
      expect(bytes.length, greaterThan(1000));
      if (out != null) File('$out/${f.id}.pdf').writeAsBytesSync(bytes);
    }
  });
}
