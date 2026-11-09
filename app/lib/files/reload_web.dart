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
  // Adresse différente (paramètre v) : le navigateur ne peut pas resservir l'ancienne page depuis son cache.
  // Les autres paramètres de l'adresse (lien d'invitation, jeton…) sont conservés.
  final l = web.window.location;
  final u = Uri.parse(l.href);
  final next = u.replace(queryParameters: {...u.queryParameters, 'v': version});
  l.replace(next.toString());
  return true;
}


/// Adresse de vérification de version : le serveur de l'application, sinon celui qui sert cette interface (…/app/ → …/api/ping).
Uri pingUri(String server) => server.isNotEmpty ? Uri.parse('$server/api/ping') : Uri.base.resolve('../api/ping');

/// Version pour laquelle cet onglet a déjà été rechargé (propre à l'onglet : un autre onglet ne bloque pas celui-ci).
String? reloadMarker() {
  try {
    return web.window.sessionStorage.getItem('yomara_reload_for');
  } catch (_) {
    return null;
  }
}

void setReloadMarker(String v) {
  try {
    web.window.sessionStorage.setItem('yomara_reload_for', v);
  } catch (_) {}
}

bool get pageHidden => web.document.visibilityState == 'hidden';

/// Appelé quand l'onglet est masqué ou réaffiché ; renvoie la fonction qui retire l'écoute.
void Function() onVisibilityChange(void Function() f) {
  final js = ((web.Event _) => f()).toJS;
  web.document.addEventListener('visibilitychange', js);
  return () => web.document.removeEventListener('visibilitychange', js);
}

Future<bool> restartApp({Future<void> Function()? beforeExit}) async => false;
