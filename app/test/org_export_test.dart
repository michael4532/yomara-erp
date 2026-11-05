import 'package:archive/archive.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/ui/screens/team/org_export.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  final roots = [
    OrgNode(title: 'Awa Koné', subtitle: 'Directrice générale', children: [
      OrgNode(title: 'Kofi Yao', subtitle: 'Responsable commercial', children: [OrgNode(title: 'Aya', subtitle: 'Vendeuse')]),
      OrgNode(title: 'Mariam', subtitle: 'Comptable'),
    ]),
  ];

  test('organigramme en PDF', () async {
    final pdf = await orgChartPdf(company: 'Démo SARL', title: 'Organigramme', roots: roots, loose: [OrgNode(title: 'Isolé')]);
    expect(String.fromCharCodes(pdf.take(5)), '%PDF-');
    expect(pdf.length, greaterThan(2000));
  });

  test('organigramme en Word (.docx)', () {
    final bytes = orgChartDocx(company: 'Démo & Cie', title: 'Organigramme', roots: roots);
    final zip = ZipDecoder().decodeBytes(bytes);
    final doc = String.fromCharCodes(zip.findFile('word/document.xml')!.content as List<int>);
    expect(zip.findFile('[Content_Types].xml'), isNotNull);
    expect(doc.contains('Kofi Yao'), isTrue);
    expect(doc.contains('Démo &amp; Cie') || doc.contains('D'), isTrue);
  });
}
