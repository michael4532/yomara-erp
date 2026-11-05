import 'dart:io';

const bool rawNetworkSupported = true;

/// Envoie des octets bruts à une imprimante réseau (port 9100 « RAW / JetDirect »).
Future<void> sendRaw(String host, int port, List<int> bytes, {Duration timeout = const Duration(seconds: 5)}) async {
  final socket = await Socket.connect(host, port, timeout: timeout);
  try {
    socket.add(bytes);
    await socket.flush().timeout(timeout);
  } finally {
    await socket.close();
    socket.destroy();
  }
}
