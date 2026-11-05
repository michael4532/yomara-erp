/// Canal entre la caisse et l'écran client.
abstract class DisplayTransport {
  /// Vrai si l'écran est servi sur le réseau local (application de bureau).
  bool get isServer;
  bool get running;

  Future<void> start({required int port, required Future<String> Function() html, required String Function() current});
  Future<void> stop();
  void send(String json);

  /// Adresses à ouvrir sur la tablette / le second écran.
  Future<List<String>> urls();

  /// Nombre d'écrans actuellement connectés (si connu).
  int get clients;
}

class NoopDisplayTransport implements DisplayTransport {
  @override
  bool get isServer => false;
  @override
  bool get running => false;
  @override
  int get clients => 0;
  @override
  Future<void> start({required int port, required Future<String> Function() html, required String Function() current}) async {}
  @override
  Future<void> stop() async {}
  @override
  void send(String json) {}
  @override
  Future<List<String>> urls() async => [];
}
