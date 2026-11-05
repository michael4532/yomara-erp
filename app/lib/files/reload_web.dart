import 'package:web/web.dart' as web;

/// Recharge l'application web (nouvelle interface après une mise à jour du serveur).
bool reloadApp() {
  web.window.location.reload();
  return true;
}
