import 'dart:js_interop';

import 'package:web/web.dart' as web;

/// Version Web : ouvre un écran sans recharger la page quand la page hôte (ou un test automatisé) envoie
/// l'événement `yomara-ecran` : window.dispatchEvent(new CustomEvent('yomara-ecran', {detail: 'stock'})).
void Function() listenScreenLinks(void Function(String id) onScreen) {
  final cb = ((web.Event e) {
    final d = (e as web.CustomEvent).detail;
    final id = d.isA<JSString>() ? (d as JSString).toDart : '';
    if (RegExp(r'^[a-z_]{2,40}$').hasMatch(id)) onScreen(id);
  }).toJS;
  web.window.addEventListener('yomara-ecran', cb);
  return () => web.window.removeEventListener('yomara-ecran', cb);
}

/// Garde l'écran ouvert dans l'adresse (…/app/?ecran=stock) : une actualisation de la page y revient.
void rememberScreen(String id) {
  try {
    final loc = web.window.location;
    final params = Uri.parse(loc.href).queryParameters;
    if (params['ecran'] == id) return;
    final q = {...params, 'ecran': id};
    final url = Uri(path: loc.pathname, queryParameters: q).toString();
    web.window.history.replaceState(null, '', url);
  } catch (_) {}
}
