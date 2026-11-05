import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:yomara_erp/printing/escpos.dart';
import 'package:yomara_erp/printing/receipt_escpos.dart';
import 'package:yomara_erp/printing/transport.dart';

/// Rend le flux ESC/POS en texte brut (lignes imprimées), commandes retirées.
List<String> render(List<int> b) {
  const rev = {0x82: 'é', 0x8A: 'è', 0x88: 'ê', 0x85: 'à', 0x87: 'ç', 0x97: 'ù', 0x93: 'ô', 0x8C: 'î', 0xAE: '«', 0xAF: '»', 0x90: 'É', 0xFA: '·', 0xF8: '°'};
  final out = <String>[];
  var line = StringBuffer();
  for (var i = 0; i < b.length; i++) {
    final c = b[i];
    if (c == 0x1B) {
      final cmd = b[i + 1];
      i += switch (cmd) { 0x40 => 1, 0x70 => 4, _ => 2 };
    } else if (c == 0x1D) {
      i += b[i + 1] == 0x56 ? 3 : 2;
    } else if (c == 0x0A) {
      out.add(line.toString());
      line = StringBuffer();
    } else {
      line.write(c < 0x80 ? String.fromCharCode(c) : rev[c] ?? '?');
    }
  }
  return out;
}

bool hasSeq(List<int> b, List<int> seq) {
  for (var i = 0; i + seq.length <= b.length; i++) {
    var ok = true;
    for (var j = 0; j < seq.length; j++) {
      if (b[i + j] != seq[j]) {
        ok = false;
        break;
      }
    }
    if (ok) return true;
  }
  return false;
}

void main() {
  setUpAll(() => initializeDateFormatting('fr'));

  const ctx = ReceiptContext(
    companyName: 'Supérette Démo',
    headerLines: ['Cocody, Abidjan', 'Tél. 07 00 00 00 00'],
    cashier: 'Awa Koné',
    currency: 'XOF',
    registerName: 'Caisse 1',
  );
  final doc = {
    'reference': 'C1-000001',
    'created_at': '2026-10-22T10:15:00',
    'subtotal': 5847, 'tax_total': 1053, 'total': 6900,
    'lines': [
      {'description': 'Coca-Cola 33cl', 'quantity': 4, 'unit_price': 423.73, 'tax_rate': 18, 'total': 2000},
      {'description': 'Riz parfumé 5kg — sac de qualité supérieure importé', 'quantity': 1, 'unit_price': 3813.56, 'tax_rate': 18, 'total': 4500},
      {'description': 'Eau minérale 1,5L', 'quantity': 1, 'unit_price': 338.98, 'tax_rate': 18, 'total': 400},
    ],
  };

  test('ticket 80 mm : mise en page, accents, coupe, tiroir', () {
    final bytes = receiptEscPos(ctx: ctx, doc: doc, payments: [{'method': 'cash', 'amount': 6900}], change: 3100, openDrawer: true);
    final lines = render(bytes);

    expect(bytes.sublist(0, 5), [0x1B, 0x40, 0x1B, 0x74, 2], reason: 'init + PC850');
    expect(hasSeq(bytes, [0x1B, 0x70, 0, 25, 250]), isTrue, reason: 'impulsion tiroir');
    expect(hasSeq(bytes, [0x1D, 0x56, 66, 3]), isTrue, reason: 'coupe papier');
    expect(lines.every((l) => l.length <= 48), isTrue, reason: 'aucune ligne ne dépasse 48 colonnes');
    expect(lines, contains('Supérette Démo'));
    expect(lines.firstWhere((l) => l.startsWith('TOTAL TTC')), endsWith('6 900 F CFA'));
    expect(lines.firstWhere((l) => l.startsWith('TOTAL TTC')).length, 48);
    expect(lines.firstWhere((l) => l.startsWith('Espèces')), endsWith('10 000 F CFA'));
    expect(lines.firstWhere((l) => l.startsWith('Monnaie rendue')), endsWith('3 100 F CFA'));
    final coca = lines.firstWhere((l) => l.startsWith('  4 x 500 F CFA'));
    expect(coca, endsWith(' 2 000 F CFA'));
    expect(coca.length, 48, reason: 'montant aligné à droite');
    expect(lines.where((l) => l.contains('?')), isEmpty, reason: 'aucun caractère non imprimable');
  });

  test('ticket 58 mm : 32 colonnes, sans tiroir', () {
    final bytes = receiptEscPos(ctx: ctx, doc: doc, payments: [{'method': 'mobile_money', 'amount': 6900}], columns: 32);
    final lines = render(bytes);
    expect(lines.every((l) => l.length <= 32), isTrue);
    expect(hasSeq(bytes, [0x1B, 0x70]), isFalse);
    expect(lines.firstWhere((l) => l.startsWith('Mobile Money')).length, 32);
  });

  test('encodage PC850 et nettoyage', () {
    expect(EscPos.encode('é à ç'), [0x82, 0x20, 0x85, 0x20, 0x87]);
    expect(EscPos.encode('1 000'), '1 000'.codeUnits);
    expect(EscPos.encode('œuf €'), 'oeuf EUR'.codeUnits);
    expect(EscPos.wrap('un deux trois quatre', 9), ['un deux', 'trois', 'quatre']);
  });

  test('envoi réseau vers une imprimante (port RAW)', () async {
    final server = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    final received = Completer<List<int>>();
    server.listen((s) async {
      final data = <int>[];
      await for (final chunk in s) {
        data.addAll(chunk);
      }
      received.complete(data);
    });
    final bytes = receiptEscPos(ctx: ctx, doc: doc, payments: [{'method': 'cash', 'amount': 6900}]);
    await sendRaw('127.0.0.1', server.port, bytes);
    expect(await received.future.timeout(const Duration(seconds: 5)), bytes);
    await server.close();

    // Imprimante éteinte : erreur rapide, pas de blocage.
    await expectLater(sendRaw('127.0.0.1', server.port, bytes, timeout: const Duration(seconds: 2)), throwsA(anything));
  });

  test('ticket certifié FNE : référence DGI et QR code natif', () {
    const url = 'http://54.247.95.108/fr/verification/019465c1-3f61';
    final bytes = receiptEscPos(ctx: ctx, doc: doc, payments: [{'method': 'cash', 'amount': 6900}], fne: {'status': 'certified', 'reference': '9502363N2500001', 'verify_url': url});
    // Commande de stockage du QR : GS ( k pL pH 31 50 30 <données>
    final store = [0x1D, 0x28, 0x6B, (url.length + 3) & 0xFF, 0, 0x31, 0x50, 0x30, ...url.codeUnits];
    var found = false;
    for (var i = 0; i + store.length <= bytes.length && !found; i++) {
      var ok = true;
      for (var j = 0; j < store.length; j++) {
        if (bytes[i + j] != store[j]) {
          ok = false;
          break;
        }
      }
      found = ok;
    }
    expect(found, isTrue);
    final printed = receiptEscPos(ctx: ctx, doc: doc, payments: [], fne: {'status': 'certified', 'reference': '9502363N2500001'});
    final text = render(printed).join('\n');
    expect(text, contains('FNE 9502363N2500001'));
    expect(render(receiptEscPos(ctx: ctx, doc: doc, payments: [], fne: {'status': 'pending'})).join('\n'), contains('Certification FNE en cours'));
  });
}
