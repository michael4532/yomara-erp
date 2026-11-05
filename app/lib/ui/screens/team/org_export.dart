import 'dart:convert';
import 'dart:typed_data';

import 'package:archive/archive.dart';
import 'package:flutter/services.dart' show rootBundle;
import 'package:intl/intl.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

/// Un nœud d'organigramme : une personne (rattachée à son responsable) ou une fonction (avec ses titulaires).
class OrgNode {
  OrgNode({required this.title, this.subtitle, this.lines = const [], this.color = 0xFF1F6FEB, List<OrgNode>? children}) : children = children ?? [];
  final String title;
  final String? subtitle;
  final List<String> lines;
  final int color;
  final List<OrgNode> children;

  int get size => 1 + children.fold(0, (s, c) => s + c.size);
}

String _date() => DateFormat('dd/MM/yyyy').format(DateTime.now());

/// PDF de l'organigramme : chaque branche dans un cadre, les niveaux reliés par un trait, sur autant de pages qu'il faut.
Future<Uint8List> orgChartPdf({required String company, required String title, required List<OrgNode> roots, List<OrgNode> loose = const []}) async {
  final regular = pw.Font.ttf(await rootBundle.load('assets/fonts/Inter-Regular.ttf'));
  final bold = pw.Font.ttf(await rootBundle.load('assets/fonts/Inter-Bold.ttf'));
  final doc = pw.Document(title: '$title — $company', author: company, theme: pw.ThemeData.withFont(base: regular, bold: bold));
  const ink = PdfColor.fromInt(0xFF0B1530), muted = PdfColor.fromInt(0xFF64748B), line = PdfColor.fromInt(0xFFCBD5E1);

  pw.Widget box(OrgNode n) => pw.Container(
        width: 240,
        decoration: pw.BoxDecoration(
          color: PdfColors.white,
          border: pw.Border.all(color: line, width: .8),
          borderRadius: const pw.BorderRadius.all(pw.Radius.circular(6)),
        ),
        child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
          pw.Container(height: 3, color: PdfColor.fromInt(n.color)),
          pw.Padding(
            padding: const pw.EdgeInsets.fromLTRB(8, 5, 8, 6),
            child: pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
              pw.Text(n.title, style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 10.5, color: PdfColor.fromInt(n.color))),
              if ((n.subtitle ?? '').isNotEmpty) pw.Text(n.subtitle!, style: const pw.TextStyle(fontSize: 8.5, color: muted)),
              for (final l in n.lines.take(8)) pw.Padding(padding: const pw.EdgeInsets.only(top: 1.5), child: pw.Text('• $l', style: const pw.TextStyle(fontSize: 8.5, color: ink))),
              if (n.lines.length > 8) pw.Text('+ ${n.lines.length - 8} autre(s)', style: const pw.TextStyle(fontSize: 8, color: muted)),
            ]),
          ),
        ]),
      );

  // Liste à plat avec niveau : chaque ligne est un cadre, décalé et relié à son supérieur
  final rows = <pw.Widget>[];
  void walk(OrgNode n, int depth, bool last) {
    rows.add(pw.Padding(
      padding: pw.EdgeInsets.only(left: depth * 26.0, bottom: 5),
      child: pw.Row(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
        if (depth > 0) pw.Container(width: 14, height: 12, margin: const pw.EdgeInsets.only(right: 4), decoration: const pw.BoxDecoration(border: pw.Border(left: pw.BorderSide(color: line), bottom: pw.BorderSide(color: line)))),
        box(n),
        if (n.children.isNotEmpty) pw.Padding(padding: const pw.EdgeInsets.only(left: 8, top: 4), child: pw.Text('${n.children.length} directement rattaché(s)', style: const pw.TextStyle(fontSize: 8, color: muted))),
      ]),
    ));
    for (final (i, c) in n.children.indexed) {
      walk(c, depth + 1, i == n.children.length - 1);
    }
  }

  for (final r in roots) {
    walk(r, 0, true);
    rows.add(pw.SizedBox(height: 10));
  }

  doc.addPage(pw.MultiPage(
    pageFormat: PdfPageFormat.a4,
    margin: const pw.EdgeInsets.fromLTRB(32, 30, 32, 30),
    header: (c) => pw.Container(
      margin: const pw.EdgeInsets.only(bottom: 12),
      padding: const pw.EdgeInsets.only(bottom: 6),
      decoration: const pw.BoxDecoration(border: pw.Border(bottom: pw.BorderSide(color: line))),
      child: pw.Row(mainAxisAlignment: pw.MainAxisAlignment.spaceBetween, children: [
        pw.Text('$company — $title', style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 13, color: ink)),
        pw.Text('Au ${_date()}', style: const pw.TextStyle(fontSize: 9, color: muted)),
      ]),
    ),
    footer: (c) => pw.Align(alignment: pw.Alignment.centerRight, child: pw.Text('Page ${c.pageNumber} / ${c.pagesCount}', style: const pw.TextStyle(fontSize: 8, color: muted))),
    build: (c) => [
      ...rows,
      if (loose.isNotEmpty) ...[
        pw.SizedBox(height: 8),
        pw.Text(roots.isEmpty ? 'Toutes les fonctions' : 'Non rattachés', style: pw.TextStyle(fontWeight: pw.FontWeight.bold, fontSize: 11.5, color: ink)),
        pw.SizedBox(height: 6),
        pw.Wrap(spacing: 8, runSpacing: 8, children: [for (final n in loose) box(n)]),
      ],
    ],
  ));
  return doc.save();
}

/// Document Word (.docx) de l'organigramme : un cadre par nœud, décalé selon le niveau, avec ses lignes.
Uint8List orgChartDocx({required String company, required String title, required List<OrgNode> roots, List<OrgNode> loose = const []}) {
  String esc(String s) => const HtmlEscape(HtmlEscapeMode.element).convert(s);
  String hex(int c) => (c & 0xFFFFFF).toRadixString(16).padLeft(6, '0').toUpperCase();
  String run(String t, {bool b = false, int size = 20, String color = '0B1530'}) =>
      '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>${b ? '<w:b/>' : ''}<w:color w:val="$color"/><w:sz w:val="$size"/></w:rPr><w:t xml:space="preserve">${esc(t)}</w:t></w:r>';
  String para(String runs, {int indent = 0, int after = 0}) => '<w:p><w:pPr><w:spacing w:before="0" w:after="$after"/><w:ind w:left="$indent"/></w:pPr>$runs</w:p>';
  final body = StringBuffer();
  body.write(para(run('$company — $title', b: true, size: 32), after: 60));
  body.write(para(run('Au ${_date()}', size: 18, color: '64748B'), after: 240));

  // Un cadre = tableau d'une cellule bordée, avec un liseré de couleur à gauche
  String nodeBox(OrgNode n, int depth) {
    final ind = depth * 520;
    final inner = StringBuffer()
      ..write(para(run(n.title, b: true, size: 22, color: hex(n.color))))
      ..write((n.subtitle ?? '').isNotEmpty ? para(run(n.subtitle!, size: 18, color: '64748B')) : '');
    for (final l in n.lines) {
      inner.write(para(run('• $l', size: 19)));
    }
    if (n.children.isNotEmpty) inner.write(para(run('${n.children.length} directement rattaché(s)', size: 16, color: '64748B')));
    return '<w:tbl><w:tblPr><w:tblW w:w="5200" w:type="dxa"/><w:tblInd w:w="$ind" w:type="dxa"/>'
        '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="CBD5E1"/><w:bottom w:val="single" w:sz="4" w:color="CBD5E1"/>'
        '<w:left w:val="single" w:sz="24" w:color="${hex(n.color)}"/><w:right w:val="single" w:sz="4" w:color="CBD5E1"/></w:tblBorders>'
        '<w:tblCellMar><w:top w:w="80" w:type="dxa"/><w:left w:w="140" w:type="dxa"/><w:bottom w:w="80" w:type="dxa"/><w:right w:w="140" w:type="dxa"/></w:tblCellMar></w:tblPr>'
        '<w:tblGrid><w:gridCol w:w="5200"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="5200" w:type="dxa"/></w:tcPr>$inner</w:tc></w:tr></w:tbl>'
        '${para('', after: 80)}';
  }

  void walk(OrgNode n, int depth) {
    body.write(nodeBox(n, depth));
    for (final c in n.children) {
      walk(c, depth + 1);
    }
  }

  for (final r in roots) {
    walk(r, 0);
    body.write(para('', after: 120));
  }
  if (loose.isNotEmpty) {
    body.write(para(run(roots.isEmpty ? 'Toutes les fonctions' : 'Non rattachés', b: true, size: 24), after: 120));
    for (final n in loose) {
      body.write(nodeBox(n, 0));
    }
  }

  final document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>$body'
      '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000" w:header="500" w:footer="500" w:gutter="0"/></w:sectPr></w:body></w:document>';
  const types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
      '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
  const rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
  final a = Archive();
  for (final (name, content) in [('[Content_Types].xml', types), ('_rels/.rels', rels), ('word/document.xml', document)]) {
    final bytes = utf8.encode(content);
    a.addFile(ArchiveFile(name, bytes.length, bytes));
  }
  return Uint8List.fromList(ZipEncoder().encode(a));
}
