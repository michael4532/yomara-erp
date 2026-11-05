import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/pdf/rh_document_pdf.dart';

void main() {
  test('document RH PDF (contrat, attestation)', () async {
    TestWidgetsFlutterBinding.ensureInitialized();
    final docs = File('/tmp/docs.json').existsSync() ? List<Map<String, dynamic>>.from(jsonDecode(File('/tmp/docs.json').readAsStringSync())) : <Map<String, dynamic>>[];
    if (docs.isEmpty) {
      docs.add({'type': 'attestation', 'title': 'Attestation de travail', 'number': 'ATT-2026-00001', 'date': '2026-10-24', 'status': 'issued', 'body': 'Je soussigné…\n\nLa présente attestation…'});
    }
    for (final d in docs) {
      final bytes = await rhDocumentPdf(company: {'name': 'Démo SARL', 'city': 'Abidjan', 'address': 'Rue des Jardins, Cocody', 'phone': '+225 01 00 00 00 00', 'trade_register': 'CI-ABJ-2020-B-12345', 'tax_id': '2012345A'}, doc: d, employeeName: 'Traoré Mariam', signatory: 'Awa Koné', signatoryTitle: 'La Gérante');
      expect(String.fromCharCodes(bytes.take(5)), '%PDF-');
      File('${Directory.systemTemp.path}/rhdoc_${d['type']}.pdf').writeAsBytesSync(bytes);
    }
    expect(longDateFr('2026-03-01'), '1er mars 2026');
  });
}
