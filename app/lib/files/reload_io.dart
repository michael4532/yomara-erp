import 'dart:io';

/// Applications natives : pas de rechargement de page (le serveur mis à jour est utilisé directement).
bool reloadApp() => false;

Future<bool> hardReloadApp(String version) async => false;


Uri pingUri(String server) => Uri();

String? reloadMarker() => null;

void setReloadMarker(String v) {}

bool get pageHidden => false;

void Function() onVisibilityChange(void Function() f) => () {};

/// Ordinateur : relance l'application (pour appliquer une mise à jour téléchargée). Téléphone : impossible, renvoie faux.
/// La base locale est fermée d'abord, et la nouvelle instance démarre après un court délai, une fois celle-ci quittée
/// (sinon elle trouverait la base encore verrouillée et afficherait « déjà ouverte »).
Future<bool> restartApp({Future<void> Function()? beforeExit}) async {
  if (!(Platform.isWindows || Platform.isMacOS || Platform.isLinux)) return false;
  try {
    final exe = Platform.environment['APPIMAGE'] ?? Platform.resolvedExecutable; // AppImage : le fichier lui-même, pas son point de montage temporaire
    if (Platform.isWindows) {
      await Process.start('cmd', ['/c', 'timeout /t 2 /nobreak >nul & start "" "$exe"'], mode: ProcessStartMode.detached);
    } else if (Platform.isMacOS && exe.contains('.app/')) {
      final app = exe.substring(0, exe.indexOf('.app/') + 4);
      await Process.start('/bin/sh', ['-c', 'sleep 2; open -n "\$0"', app], mode: ProcessStartMode.detached);
    } else {
      await Process.start('/bin/sh', ['-c', 'sleep 2; exec "\$0"', exe], mode: ProcessStartMode.detached);
    }
    if (beforeExit != null) await beforeExit();
    exit(0);
  } catch (_) {
    return false;
  }
}
