import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:path_provider/path_provider.dart';

/// Windows : un seul environnement WebView2 pour tout le logiciel (Office, visio…).
/// En créer plusieurs sur le même dossier de profil fait échouer WebView2, ce qui peut fermer l'application entière.
Future<WebViewEnvironment?>? _pending;

Future<WebViewEnvironment?> windowsWebViewEnvironment() => _pending ??= _create();

Future<WebViewEnvironment?> _create() async {
  try {
    if (await WebViewEnvironment.getAvailableVersion() == null) return null; // WebView2 absent : navigateur
    final dir = await getApplicationSupportDirectory();
    return await WebViewEnvironment.create(settings: WebViewEnvironmentSettings(userDataFolder: '${dir.path}\\WebView2'));
  } catch (_) {
    _pending = null;
    return null;
  }
}
