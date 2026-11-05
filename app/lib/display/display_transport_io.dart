import 'dart:async';
import 'dart:io';

import 'display_transport_base.dart';

DisplayTransport createDisplayTransport() => ServerDisplayTransport();

/// Petit serveur HTTP local : page de l'écran client + flux temps réel (SSE).
/// Fonctionne sans Internet, sur le réseau local de la boutique.
class ServerDisplayTransport implements DisplayTransport {
  HttpServer? _server;
  final _clients = <HttpResponse>{};
  Timer? _heartbeat;
  late String Function() _current;

  @override
  bool get isServer => true;
  @override
  bool get running => _server != null;
  @override
  int get clients => _clients.length;
  int? get port => _server?.port;

  @override
  Future<void> start({required int port, required Future<String> Function() html, required String Function() current}) async {
    if (_server != null) return;
    _current = current;
    final server = await HttpServer.bind(InternetAddress.anyIPv4, port, shared: true);
    _server = server;
    _heartbeat = Timer.periodic(const Duration(seconds: 15), (_) => _broadcast(': ping\n\n'));
    server.listen((req) async {
      final res = req.response;
      res.headers.set('Cache-Control', 'no-store');
      try {
        switch (req.uri.path) {
          case '/':
          case '/index.html':
            final page = (await html()).replaceFirst('<!--MODE-->', "<script>window.YD_MODE='sse'</script>");
            res.headers.contentType = ContentType.html;
            res.write(page);
            await res.close();
          case '/events':
            res.bufferOutput = false;
            res.headers.contentType = ContentType('text', 'event-stream', charset: 'utf-8');
            res.headers.set('Connection', 'keep-alive');
            res.write('retry: 2000\n\n');
            res.write(_event(_current()));
            await res.flush();
            _clients.add(res);
            res.done.whenComplete(() => _clients.remove(res)).ignore();
          case '/state':
            res.headers.contentType = ContentType.json;
            res.write(_current());
            await res.close();
          default:
            res.statusCode = HttpStatus.notFound;
            await res.close();
        }
      } catch (_) {
        _clients.remove(res);
        try {
          res.statusCode = HttpStatus.internalServerError;
          await res.close();
        } catch (_) {}
      }
    });
  }

  String _event(String json) => 'data: ${json.replaceAll('\n', ' ')}\n\n';

  void _broadcast(String chunk) {
    for (final c in _clients.toList()) {
      try {
        c.write(chunk);
        c.flush().catchError((_) => _clients.remove(c));
      } catch (_) {
        _clients.remove(c);
      }
    }
  }

  @override
  void send(String json) => _broadcast(_event(json));

  @override
  Future<void> stop() async {
    _heartbeat?.cancel();
    for (final c in _clients.toList()) {
      try {
        await c.close();
      } catch (_) {}
    }
    _clients.clear();
    await _server?.close(force: true);
    _server = null;
  }

  @override
  Future<List<String>> urls() async {
    final p = _server?.port;
    if (p == null) return [];
    final out = <String>[];
    try {
      for (final ni in await NetworkInterface.list(type: InternetAddressType.IPv4)) {
        for (final a in ni.addresses) {
          if (!a.isLoopback && !a.isLinkLocal) out.add('http://${a.address}:$p');
        }
      }
    } catch (_) {}
    out.add('http://localhost:$p');
    return out;
  }
}

