import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:yomara_erp/core/local_db.dart';
import 'package:yomara_erp/display/customer_display.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  // Le binding de test remplace HttpClient par un faux : on veut de vraies requêtes.
  HttpOverrides.global = null;

  test('écran client servi en local : page, état initial, mises à jour en direct', () async {
    final dir = await Directory.systemTemp.createTemp('yomara_display');
    await LocalDb.initAt(dir.path);
    final d = CustomerDisplay.instance..setBranding(company: 'Supérette Démo', footer: 'À bientôt !');

    // Port libre choisi au hasard pour le test
    final probe = await ServerSocket.bind(InternetAddress.loopbackIPv4, 0);
    final port = probe.port;
    await probe.close();
    await d.saveConfig(CustomerDisplayConfig(enabled: true, port: port, welcome: 'Akwaba'));
    expect(d.running, isTrue, reason: d.lastError);
    expect((await d.urls()).last, 'http://localhost:$port');

    final client = HttpClient();
    // Page HTML en mode SSE
    final page = await (await (await client.getUrl(Uri.parse('http://127.0.0.1:$port/'))).close()).transform(utf8.decoder).join();
    expect(page, contains("window.YD_MODE='sse'"));
    expect(page, contains('Total à payer'));

    // Flux temps réel
    final res = await (await client.getUrl(Uri.parse('http://127.0.0.1:$port/events'))).close();
    expect(res.headers.contentType?.mimeType, 'text/event-stream');
    final events = StreamController<Map<String, dynamic>>();
    final sub = res.transform(utf8.decoder).transform(const LineSplitter()).where((l) => l.startsWith('data: ')).listen((l) => events.add(jsonDecode(l.substring(6))));
    final queue = StreamIterator(events.stream);

    expect(await queue.moveNext(), isTrue);
    expect(queue.current['status'], 'idle');
    expect(queue.current['welcome'], 'Akwaba');
    expect(queue.current['company'], 'Supérette Démo');

    d.showCart(lines: [{'key': 'p1', 'name': 'Coca-Cola 33cl', 'detail': '4 × 500 F CFA', 'total': '2 000 F CFA'}], total: '2 000 F CFA', count: '4 articles');
    await queue.moveNext();
    expect(queue.current['status'], 'cart');
    expect(queue.current['total'], '2 000 F CFA');
    expect((queue.current['lines'] as List).single['name'], 'Coca-Cola 33cl');

    d.showPaid(total: '6 900 F CFA', change: '3 100 F CFA', hold: const Duration(milliseconds: 300));
    await queue.moveNext();
    expect(queue.current['status'], 'paid');
    expect(queue.current['change'], '3 100 F CFA');

    // Panier vidé juste après la vente : le remerciement reste affiché…
    d.showCart(lines: [], total: '0 F CFA', count: '');
    // …puis retour automatique à l'accueil
    await queue.moveNext();
    expect(queue.current['status'], 'idle');

    // Un second écran qui se connecte reçoit l'état courant
    final state = await (await (await client.getUrl(Uri.parse('http://127.0.0.1:$port/state'))).close()).transform(utf8.decoder).join();
    expect(jsonDecode(state)['status'], 'idle');

    await sub.cancel();
    client.close(force: true);
    await d.saveConfig(CustomerDisplayConfig(enabled: false, port: port));
    expect(d.running, isFalse);
  });
}
