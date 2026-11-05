/// Navigateur : pas de connexion TCP directe possible.
const bool rawNetworkSupported = false;

Future<void> sendRaw(String host, int port, List<int> bytes, {Duration timeout = const Duration(seconds: 5)}) =>
    throw UnsupportedError('L\'impression réseau directe n\'est pas disponible dans le navigateur.');
