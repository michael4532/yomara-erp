import 'dart:async';
import 'dart:convert';

import 'local_db.dart';

/// Préférences d'interface (favoris, menu…) gardées aussi sur le serveur, par utilisateur :
/// elles survivent à la déconnexion et suivent la personne d'un appareil à l'autre.
class PrefsSync {
  /// Envoi au serveur (branché par la session une fois connecté).
  static Future<void> Function(Map<String, Object?> prefs)? sender;

  static final Map<String, Object?> _pending = {};
  /// En cours d'envoi : une copie serveur plus ancienne ne doit pas les écraser entre-temps.
  static final Set<String> _sending = {};
  static Timer? _timer;

  /// Une préférence a changé localement : envoi groupé au serveur un instant plus tard.
  static void queue(String key, Object? value) {
    _pending[key] = value;
    _timer?.cancel();
    _timer = Timer(const Duration(milliseconds: 1200), flush);
  }

  /// Déconnexion : rien de la session précédente ne doit partir dans le compte suivant.
  static void reset() {
    _timer?.cancel();
    _pending.clear();
    _sending.clear();
    sender = null;
  }

  static Future<void> flush() async {
    final s = sender;
    if (s == null || _pending.isEmpty) return;
    final batch = Map<String, Object?>.from(_pending);
    _pending.clear();
    _sending.addAll(batch.keys);
    try {
      await s(batch);
    } catch (_) {
      // hors ligne : on réessaiera à la prochaine modification ou au prochain démarrage
      for (final e in batch.entries) {
        _pending.putIfAbsent(e.key, () => e.value);
      }
    } finally {
      _sending.removeAll(batch.keys);
    }
  }

  /// Valeurs du serveur (démarrage, connexion) : elles remplacent la copie locale, sauf un changement pas encore envoyé.
  static Future<void> restore(Map<String, dynamic> server, {Set<String> keys = const {}}) async {
    if (!LocalDb.isOpen) return;
    final box = LocalDb.state();
    // Préférences d'avant cette version, connues seulement de cet appareil : on les envoie une première fois
    for (final k in keys) {
      final local = box.get('ui_$k');
      if (!server.containsKey(k) && local != null) queue(k, local is String && local.startsWith('[') ? jsonDecode(local) : local);
    }
    for (final e in server.entries) {
      if (_pending.containsKey(e.key) || _sending.contains(e.key)) continue;
      final v = e.value;
      await box.put('ui_${e.key}', v is List ? jsonEncode(v) : v);
    }
    if (_pending.isNotEmpty) unawaited(flush());
  }
}
