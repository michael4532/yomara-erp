import 'package:flutter/foundation.dart';

/// Nouveaux e-mails à signaler dans la cloche de notifications (au lieu d'une barre en bas de l'écran).
/// La messagerie les ajoute à leur arrivée et indique comment ouvrir un message ; la cloche les affiche.
class MailAlerts {
  MailAlerts._();

  static final ValueNotifier<List<Map<String, dynamic>>> items = ValueNotifier(const []);

  /// Ouvre un message (fourni par la messagerie quand elle est ouverte).
  static void Function(Map<String, dynamic> m)? opener;

  static String _key(Map<String, dynamic> m) => '${m['id'] ?? m['uid'] ?? '${m['from_email']}|${m['subject']}|${m['date']}'}';

  /// Ajoute des messages arrivés (les plus récents en premier), sans doublon, 30 au plus.
  static void push(List<Map<String, dynamic>> arrived) {
    if (arrived.isEmpty) return;
    final seen = <String>{};
    final next = <Map<String, dynamic>>[];
    for (final m in [...arrived, ...items.value]) {
      if (seen.add(_key(m))) next.add(m);
      if (next.length == 30) break;
    }
    items.value = next;
  }

  static void remove(Map<String, dynamic> m) => items.value = items.value.where((x) => _key(x) != _key(m)).toList();

  static void clear() => items.value = const [];
}
