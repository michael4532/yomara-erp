import 'dart:io';

/// Applications natives : pas de rechargement de page (le serveur mis à jour est utilisé directement).
bool reloadApp() => false;

Future<bool> hardReloadApp(String version) async => false;


Uri pingUri() => Uri();

bool get pageHidden => false;

void onVisibilityChange(void Function() f) {}

/// Ordinateur : relance l'application (pour appliquer une mise à jour téléchargée). Téléphone : impossible, renvoie faux.
Future<bool> restartApp() async {
  if (!(Platform.isWindows || Platform.isMacOS || Platform.isLinux)) return false;
  try {
    var exe = Platform.resolvedExecutable;
    if (Platform.isMacOS) {
      // …/Yomara ERP.app/Contents/MacOS/yomara_erp → relance du paquet .app
      final i = exe.indexOf('.app/');
      if (i > 0) {
        await Process.start('open', ['-n', exe.substring(0, i + 4)], mode: ProcessStartMode.detached);
        exit(0);
      }
    }
    await Process.start(exe, const [], mode: ProcessStartMode.detached);
    exit(0);
  } catch (_) {
    return false;
  }
}
