import 'dart:async';

import 'package:app_links/app_links.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:provider/provider.dart';

import '../i18n/i18n.dart';
import 'app_navigator.dart';
import '../state/session.dart';
import '../ui/screens/visio_screen.dart' show openVisioRoom;
import '../ui/widgets/common.dart' show confirm;


/// Liens « yomara:// » ouverts depuis le navigateur (page d'une réunion : « Ouvrir dans l'application »).
/// yomara://visio/abc-defg-hij?s=https://erp.exemple.ci&t=jeton
class DeepLinks {
  static StreamSubscription<Uri>? _sub;
  static final _code = RegExp(r'^[a-z]{3}-[a-z]{4}-[a-z]{3}$');

  static void init() {
    if (kIsWeb || _sub != null) return;
    try {
      _sub = AppLinks().uriLinkStream.listen((uri) {
        // L'écran peut ne pas être encore prêt au lancement par le lien : on laisse la première image se dessiner
        Future.delayed(const Duration(milliseconds: 600), () => handle(uri));
      }, onError: (_) {});
    } catch (_) {
      // Plateforme sans prise en charge des liens : rien à faire
    }
  }

  /// Adresse de la salle pour un lien de réunion, ou null si le lien n'est pas valable.
  static Uri? roomUrl(Uri uri) {
    if (uri.scheme != 'yomara') return null;
    final segs = [if (uri.host.isNotEmpty) uri.host, ...uri.pathSegments].where((s) => s.isNotEmpty).toList();
    if (segs.length != 2 || segs[0] != 'visio' || !_code.hasMatch(segs[1])) return null;
    final server = Uri.tryParse(uri.queryParameters['s'] ?? '');
    if (server == null || !(server.scheme == 'https' || server.scheme == 'http') || server.host.isEmpty) return null;
    final t = uri.queryParameters['t'];
    return server.replace(
      path: '${server.path.replaceAll(RegExp(r'/+$'), '')}/visio/${segs[1]}',
      queryParameters: {'app': '1', if (t != null && t.isNotEmpty) 't': t},
    ).removeFragment();
  }

  static Future<void> handle(Uri uri) async {
    final room = roomUrl(uri);
    final context = appNavigatorKey.currentContext;
    if (room == null || context == null || !context.mounted) return;
    // Serveur différent de celui de l'application : on demande avant d'ouvrir (caméra et micro seront autorisés pour ce site)
    final mine = Uri.tryParse(context.read<Session>().serverUrl);
    if (mine == null || mine.host != room.host) {
      final ok = await confirm(context, tr('Rejoindre la réunion ?'), tr('La réunion ${room.pathSegments.last} est hébergée sur ${room.host}. La caméra et le micro seront utilisés pour cette réunion.'), ok: tr('Rejoindre'));
      if (!ok || !context.mounted) return;
    }
    await openVisioRoom(context, room.toString(), tr('Réunion'));
  }
}
