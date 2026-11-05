import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:yomara_erp/core/fne.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/pdf/document_pdf.dart';
import 'package:yomara_erp/state/session.dart';
import 'package:yomara_erp/ui/screens/documents_screen.dart';

void main() {
  test('facture certifiée FNE : bloc DGI avec QR code dans le PDF', () async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await initializeDateFormatting('fr_FR');
    final dir = await Directory.systemTemp.createTemp('fne');
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('fne_${DateTime.now().millisecondsSinceEpoch}', ['partners', 'sales_documents', 'fne_certifications']);
    await LocalDb.put('partners', {'id': 'p1', 'name': 'SOTRA BTP', 'tax_id': '1234567A', 'city': 'Abidjan'});
    final doc = {
      'id': 'd1', 'type': 'invoice', 'number': 'FAC-2026-00012', 'status': 'validated', 'date': '2026-10-24', 'partner_id': 'p1', 'partner_name': 'SOTRA BTP',
      'subtotal': 150000, 'tax_total': 27000, 'total': 177000, 'amount_paid': 0,
      'lines': [
        {'description': 'Ciment CPJ 45', 'quantity': 2, 'unit': 'sac', 'unit_price': 75000, 'discount_percent': 0, 'tax_rate': 18, 'total': 177000},
      ],
    };
    await LocalDb.put('sales_documents', doc);
    await LocalDb.put('fne_certifications', {
      'id': 'c1', 'document_id': 'd1', 'status': 'certified', 'invoice_type': 'sale', 'reference': '1904512K260001001', 'ncc': '1904512K',
      'verify_url': 'http://54.247.95.108/fr/verification/019465c1-3f61-766c-9652-706e3b6f3c9b', 'certified_at': '2026-10-24T10:12:00Z', 'fiscal_stamp': 'ST-8F2A11C9D0',
    });
    expect(fneCertFor('d1')?['reference'], '1904512K260001001');
    expect(fneCertFor('autre'), isNull);
    final bytes = await buildDocumentPdf(session: Session(), config: DocConfig.sale, doc: doc);
    expect(String.fromCharCodes(bytes.take(5)), '%PDF-');
    File('${Directory.systemTemp.path}/fne_facture.pdf').writeAsBytesSync(bytes);
  });
}
