import 'package:flutter/widgets.dart';

/// Nombre de fenêtres ouvertes par-dessus l'écran (panneaux latéraux, boîtes de dialogue, menus, feuilles).
///
/// Dans le navigateur, un e-mail HTML ou une réunion vidéo est affiché dans un cadre (iframe) qui reçoit
/// les clics même quand un panneau Flutter le recouvre : un clic sur le panneau ouvrait alors le lien de
/// l'e-mail situé dessous. Ces cadres écoutent ce compteur et ignorent les clics tant qu'il est positif.
final ValueNotifier<int> popupDepth = ValueNotifier<int>(0);

class PopupGuardObserver extends NavigatorObserver {
  void _add(Route<dynamic>? r, int d) {
    if (r is PopupRoute) {
      popupDepth.value = (popupDepth.value + d).clamp(0, 1 << 20);
    }
  }

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      _add(route, 1);

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      _add(route, -1);

  @override
  void didRemove(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      _add(route, -1);

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) {
    _add(oldRoute, -1);
    _add(newRoute, 1);
  }
}
