import 'dart:js_interop';

import 'package:web/web.dart' as web;

/// Recharge l'application web (nouvelle interface après une mise à jour du serveur).
bool reloadApp() {
  web.window.location.reload();
  return true;
}

/// Recharge en vidant tout ce que le navigateur garde de l'ancienne interface : caches du service worker
/// et service workers eux-mêmes. Les données (IndexedDB, connexion, saisies hors ligne) ne sont pas touchées.
Future<bool> hardReloadApp(String version) async {
  try {
    final keys = (await web.window.caches.keys().toDart).toDart;
    for (final k in keys) {
      await web.window.caches.delete(k.toDart).toDart;
    }
  } catch (_) {}
  try {
    final regs = (await web.window.navigator.serviceWorker.getRegistrations().toDart).toDart;
    for (final r in regs) {
      await r.unregister().toDart;
    }
  } catch (_) {}
  // Adresse différente : le navigateur ne peut pas resservir l'ancienne page depuis son cache HTTP
  final l = web.window.location;
  l.replace('${l.pathname}?v=${Uri.encodeQueryComponent(version)}${l.hash}');
  return true;
}


/// Adresse de vérification de version : le serveur qui sert cette interface (…/app/ → …/api/ping).
Uri pingUri() => Uri.base.resolve('../api/ping');

bool get pageHidden => web.document.visibilityState == 'hidden';

/// Appelé quand l'onglet est masqué ou réaffiché.
void onVisibilityChange(void Function() f) {
  web.document.addEventListener('visibilitychange', ((web.Event _) => f()).toJS);
}

Future<bool> restartApp() async => false;
