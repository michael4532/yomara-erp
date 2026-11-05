import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart' show rootBundle;

import '../core/local_db.dart';
import 'display_transport.dart';
import 'display_transport_base.dart';

/// Réglages de l'écran client (propres au poste).
class CustomerDisplayConfig {
  CustomerDisplayConfig({this.enabled = false, this.port = 8765, this.welcome = 'Bienvenue'});

  bool enabled;
  int port;
  String welcome;

  Map<String, dynamic> toJson() => {'enabled': enabled, 'port': port, 'welcome': welcome};

  factory CustomerDisplayConfig.fromJson(Map<String, dynamic> j) => CustomerDisplayConfig(
        enabled: j['enabled'] == true,
        port: (j['port'] as num?)?.toInt() ?? 8765,
        welcome: '${j['welcome'] ?? 'Bienvenue'}',
      );
}

/// Diffuse l'état de la caisse vers l'écran client (tablette, second écran).
/// L'état est pré-formaté (montants en texte) : la page n'a aucune logique métier.
class CustomerDisplay extends ChangeNotifier {
  CustomerDisplay._();
  static final instance = CustomerDisplay._();

  static const _key = 'customer_display';

  final DisplayTransport _transport = createDisplayTransport();
  Map<String, dynamic> _state = {'status': 'idle'};
  String _company = '';
  String _footer = 'Merci de votre visite !';
  Timer? _backToIdle;
  String? lastError;

  bool get running => _transport.running;
  bool get isServer => _transport.isServer;
  int get clients => _transport.clients;
  Future<List<String>> urls() => _transport.urls();

  CustomerDisplayConfig get config {
    try {
      final raw = LocalDb.meta.get(_key);
      if (raw is String) return CustomerDisplayConfig.fromJson(Map<String, dynamic>.from(jsonDecode(raw)));
    } catch (_) {}
    return CustomerDisplayConfig();
  }

  Future<void> saveConfig(CustomerDisplayConfig c) async {
    await LocalDb.meta.put(_key, jsonEncode(c.toJson()));
    await _transport.stop();
    await ensureStarted();
  }

  void setBranding({required String company, String? footer}) {
    _company = company;
    if (footer != null && footer.isNotEmpty) _footer = footer;
  }

  String _json() => jsonEncode({..._state, 'company': _company, 'footer': _footer, 'welcome': config.welcome});

  /// Démarre le canal si l'écran client est activé sur ce poste.
  Future<void> ensureStarted() async {
    final cfg = config;
    if (!cfg.enabled) {
      if (_transport.running) await _transport.stop();
      notifyListeners();
      return;
    }
    if (_transport.running) return;
    try {
      await _transport.start(
        port: cfg.port,
        html: () => rootBundle.loadString('assets/customer_display.html'),
        current: _json,
      );
      lastError = null;
    } catch (e) {
      lastError = 'Impossible d\'ouvrir le port ${cfg.port} : $e';
    }
    notifyListeners();
  }

  void _publish(Map<String, dynamic> state) {
    _state = state;
    if (_transport.running) _transport.send(_json());
  }

  /// Panier en cours. [lines] : name, detail, total (texte), key (identifiant stable).
  void showCart({required List<Map<String, String>> lines, required String total, required String count, String? customer}) {
    if (_state['status'] == 'paid' && lines.isEmpty) return; // laisser le remerciement affiché
    _backToIdle?.cancel();
    _publish(lines.isEmpty
        ? {'status': 'idle'}
        : {'status': 'cart', 'lines': lines, 'total': total, 'count': count, 'customer': ?customer});
  }

  /// Vente terminée : remerciement + monnaie rendue, puis retour à l'accueil.
  void showPaid({required String total, String? change, Duration hold = const Duration(seconds: 12)}) {
    _backToIdle?.cancel();
    _publish({'status': 'paid', 'total': total, 'change': ?change});
    _backToIdle = Timer(hold, showIdle);
  }

  void showIdle() {
    _backToIdle?.cancel();
    _publish({'status': 'idle'});
  }

  @visibleForTesting
  String get currentJson => _json();
}
