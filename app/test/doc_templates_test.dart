import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/pdf/doc_templates.dart';
import 'package:yomara_erp/pdf/document_pdf.dart';
import 'package:yomara_erp/state/session.dart';
import 'package:yomara_erp/ui/screens/doc_templates_screen.dart' show sampleDocument;
import 'package:yomara_erp/ui/screens/documents_screen.dart';

void main() {
  test('chaque modèle intégré se génère pour chaque type de document', () async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await initializeDateFormatting('fr_FR');
    final dir = await Directory.systemTemp.createTemp('tpl');
    await LocalDb.initAt(dir.path);
    await LocalDb.openTenant('tpl_${DateTime.now().millisecondsSinceEpoch}', ['partners', 'sales_documents', 'purchase_documents', 'fne_certifications', 'payment_terms']);
    for (final t in builtinTemplates) {
      for (final type in ['invoice', 'proforma', 'quote', 'order', 'deposit', 'credit_note']) {
        final bytes = await buildDocumentPdf(session: Session(), config: DocConfig.sale, doc: sampleDocument(type), template: t.toSpec());
        expect(String.fromCharCodes(bytes.take(5)), '%PDF-', reason: '${t.id} / $type');
      }
      final po = await buildDocumentPdf(session: Session(), config: DocConfig.purchase, doc: sampleDocument('purchase_order'), template: t.toSpec());
      expect(po.length, greaterThan(1000));
    }
    // Modèle du studio : tous les blocs, options et styles extrêmes
    final custom = {
      'id': 'tpl_x',
      'name': 'Studio',
      'style': {'primary': '#FFFFFF', 'accent': 'zz', 'font': 'inter', 'header': 'split', 'table': 'striped', 'radius': 14, 'sidebar': true},
      'blocks': [
        for (final k in docBlockTypes.keys) {'type': k, 'text': 'Texte', 'align': 'center', 'height': 300, 'size': 99, 'bold': true},
        {'type': 'inconnu'},
        {'type': 'lines', 'hidden': true},
      ],
      'titles': {'quote': 'OFFRE'},
    };
    final bytes = await buildDocumentPdf(session: Session(), config: DocConfig.sale, doc: sampleDocument('quote'), template: custom);
    expect(String.fromCharCodes(bytes.take(5)), '%PDF-');
    // Chaque décor, en-tête, total et police, combinés (sur plusieurs pages)
    final long = sampleDocument('invoice');
    long['lines'] = [for (var i = 0; i < 60; i++) ...(long['lines'] as List)];
    for (final d in decorChoices.keys) {
      for (final h in headerStyles.keys) {
        final spec = {
          'id': 'tpl_d',
          'name': 'D',
          'style': {'primary': '#123456', 'accent': '#ABCDEF', 'decor': d, 'header': h, 'totals': totalsStyles.keys.elementAt(h.length % 3), 'font': fontChoices.keys.elementAt(d.length % 4), 'title_font': titleFontChoices.keys.elementAt(h.length % 5), 'watermark': true, 'footer_band': true},
        };
        final b = await buildDocumentPdf(session: Session(), config: DocConfig.sale, doc: d == 'wave' ? long : sampleDocument('invoice'), template: spec);
        expect(String.fromCharCodes(b.take(5)), '%PDF-', reason: '$d / $h');
      }
    }
  });
}
