import 'dart:js_interop';

import 'package:web/web.dart' as web;

import 'display_transport_base.dart';

DisplayTransport createDisplayTransport() => BroadcastDisplayTransport();

/// Caisse dans le navigateur : l'écran client est une seconde fenêtre
/// (même site) reliée par BroadcastChannel. Aucun réseau nécessaire.
class BroadcastDisplayTransport implements DisplayTransport {
  web.BroadcastChannel? _ch;
  late String Function() _current;

  @override
  bool get isServer => false;
  @override
  bool get running => _ch != null;
  @override
  int get clients => 0;

  @override
  Future<void> start({required int port, required Future<String> Function() html, required String Function() current}) async {
    if (_ch != null) return;
    _current = current;
    final ch = web.BroadcastChannel('yomara-customer-display');
    ch.onmessage = (web.MessageEvent e) {
      final d = e.data;
      if (d != null && d.isA<JSString>() && (d as JSString).toDart == 'hello') ch.postMessage(_current().toJS);
    }.toJS;
    _ch = ch;
  }

  @override
  void send(String json) => _ch?.postMessage(json.toJS);

  @override
  Future<void> stop() async {
    _ch?.close();
    _ch = null;
  }

  @override
  Future<List<String>> urls() async => [Uri.base.resolve('assets/assets/customer_display.html').toString()];
}
