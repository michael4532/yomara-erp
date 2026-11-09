import 'package:yomara_erp/ui/widgets/mail_alerts.dart';
import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/firebase_setup.dart';
import '../core/config.dart';
import '../core/local_db.dart';
import '../core/prefs_sync.dart';
import '../core/secure_store.dart';
import '../i18n/i18n.dart';
import '../core/sync_engine.dart';
import '../ui/widgets/common.dart' show currencyDecimals, decimalsFor;
import '../ui/widgets/records.dart' show RecordSchema;
import '../ui/screens/rapports_screen.dart' show RapportsMeta;
import '../core/json.dart';

/// État global : serveur, utilisateur connecté, société, modules activés,
/// permissions. Le dernier "bootstrap" est mis en cache pour démarrer hors ligne.
class Session extends ChangeNotifier {
  Session() {
    // Jeton : dans le coffre du système quand il existe (sinon, base locale comme avant)
    final stored = LocalDb.meta.get('token') as String?;
    api = ApiClient(baseUrl: (LocalDb.meta.get('server_url') as String?) ?? '', token: SecureStore.token ?? stored);
    if (stored != null && SecureStore.available) unawaited(_saveToken());
    api.onUnauthorized = () {
      if (api.token != null) logout(remote: false);
    };
    sync = SyncEngine(api);
    final cached = LocalDb.meta.get('bootstrap') as String?;
    if (cached != null) bootstrap = Map<String, dynamic>.from(jsonDecode(cached));
    currencyDecimals = decimalsFor(currency);
    api.branchId = currentBranchId;
  }

  late final ApiClient api;
  late final SyncEngine sync;

  Map<String, dynamic>? bootstrap;
  bool restoring = true;

  /// Écran « Nous configurons votre interface » : affiché jusqu'à la fin du premier chargement des données.
  bool preparing = false;
  final bootStep = ValueNotifier<String>('Ouverture de votre espace');

  /// Attend la fin de la première synchronisation (borne : hors ligne, l'application s'ouvre avec les données locales).
  Future<void> _prepare({required bool firstTime}) async {
    preparing = true;
    notifyListeners();
    final t0 = DateTime.now();
    final limit = Duration(seconds: firstTime ? 90 : 5);
    bootStep.value = firstTime ? 'Installation de vos modules' : 'Synchronisation de vos données';
    // Laisse la synchronisation démarrée par _start() aboutir
    await Future<void>.delayed(const Duration(milliseconds: 250));
    while (DateTime.now().difference(t0) < limit) {
      if (!sync.syncing && (sync.lastSyncAt != null || sync.lastError != null || !sync.online)) break;
      final p = sync.pullProgress.value;
      if (p != null) bootStep.value = p < .5 ? 'Synchronisation de vos données' : 'Préparation de vos tableaux de bord';
      await Future<void>.delayed(const Duration(milliseconds: 150));
    }
    bootStep.value = 'Presque prêt';
    final shown = DateTime.now().difference(t0);
    const minimum = Duration(milliseconds: 1100);
    if (shown < minimum) await Future<void>.delayed(minimum - shown);
    preparing = false;
    notifyListeners();
  }
  Timer? _heartbeat;

  /// Incrémenté quand les modules ou les droits ont changé côté serveur (pour prévenir l'utilisateur).
  final modulesChanged = ValueNotifier<int>(0);

  bool get isLoggedIn => api.token != null && bootstrap != null;
  String get serverUrl => api.baseUrl;

  Map<String, dynamic> get user => asMap(bootstrap?['user']);
  Map<String, dynamic> get company => asMap(bootstrap?['company']);

  /// Heure locale de la société (fuseau du serveur), même si l'appareil est réglé sur un autre fuseau.
  DateTime companyNow() {
    final off = (bootstrap?['utc_offset'] as num?)?.toInt();
    if (off == null) return DateTime.now();
    final u = DateTime.now().toUtc().add(Duration(minutes: off));
    return DateTime(u.year, u.month, u.day, u.hour, u.minute, u.second);
  }
  Map<String, dynamic> get companySettings => asMap(company['settings']);
  String get currency => (company['currency'] as String?) ?? 'XOF';
  bool get isSuperAdmin => user['is_super_admin'] == true;
  String get mode => (bootstrap?['app']?['mode'] as String?) ?? 'single';

  List<String> get enabledModules => List<String>.from(bootstrap?['enabled_modules'] ?? []);
  List<Map<String, dynamic>> get modules =>
      List<Map<String, dynamic>>.from((bootstrap?['modules'] as List? ?? []).map((m) => Map<String, dynamic>.from(m)));

  bool hasModule(String key) => enabledModules.contains(key);

  // ------------------------------------------------------------------ multi-agences

  /// Agences visibles par l'utilisateur (module Multi-agences).
  List<Map<String, dynamic>> get branches => hasModule('agences')
      ? List<Map<String, dynamic>>.from((bootstrap?['branches'] as List? ?? []).map((b) => Map<String, dynamic>.from(b)))
      : const [];

  bool get hasBranches => branches.isNotEmpty;

  /// Agences auxquelles l'utilisateur est limité (null = toutes).
  List<String>? get allowedBranchIds => user['branch_ids'] is List ? List<String>.from(user['branch_ids'] as List) : null;

  bool get branchRestricted => allowedBranchIds != null;

  String get _branchKey => 'branch_${company['id']}';

  /// Agence de travail : choisie dans le menu (null = toutes les agences).
  String? get currentBranchId {
    if (!hasBranches) return null;
    final ids = branches.map((b) => '${b['id']}').toSet();
    final saved = LocalDb.meta.get(_branchKey) as String?;
    if (saved != null && ids.contains(saved)) return saved;
    final allowed = allowedBranchIds;
    if (allowed != null && allowed.length == 1 && ids.contains(allowed.first)) return allowed.first;
    return null;
  }

  Map<String, dynamic>? get currentBranch => branches.where((b) => b['id'] == currentBranchId).firstOrNull;

  String branchName(String? id) => id == null ? 'Sans agence' : '${branches.where((b) => b['id'] == id).firstOrNull?['name'] ?? 'Autre agence'}';

  Future<void> setBranch(String? id) async {
    if (id == null) {
      await LocalDb.meta.delete(_branchKey);
    } else {
      await LocalDb.meta.put(_branchKey, id);
    }
    api.branchId = currentBranchId;
    notifyListeners();
  }

  /// Filtre d'affichage : vrai si l'enregistrement appartient à l'agence de travail (ou si aucune n'est choisie).
  bool inCurrentBranch(Map record) {
    final b = currentBranchId;
    return b == null || record['branch_id'] == b;
  }

  /// Même logique que le serveur : "*", "ventes.*", "*.view".
  /// Même liste que Role::EXPLICIT côté serveur.
  static const explicitPermissions = {'rh.contracts'};

  bool can(String permission) {
    // Droits retirés à cet utilisateur (ou modules masqués) : priment sur le rôle
    for (final d in List<String>.from(user['denied_permissions'] ?? const [])) {
      if (d == permission || RegExp('^${RegExp.escape(d).replaceAll(r'\*', '.*')}\$').hasMatch(permission)) return false;
    }
    final perms = List<String>.from(user['permissions'] ?? []);
    for (final p in perms) {
      if (p == '*' || p == permission) return true;
      // Droits sensibles : accordés nommément seulement, un joker de module (« rh.* ») ne les donne pas
      if (explicitPermissions.contains(permission)) continue;
      final re = RegExp('^${RegExp.escape(p).replaceAll(r'\*', '.*')}\$');
      if (re.hasMatch(permission)) return true;
    }
    return false;
  }

  /// Au démarrage : rouvre la session en cache (fonctionne hors ligne).
  Future<void> restore() async {
    if (api.token != null && bootstrap != null) {
      await _openLocal();
      unawaited(refreshBootstrap());
      _start();
      restoring = false;
      unawaited(_prepare(firstTime: sync.neverSynced));
      return;
    }
    restoring = false;
    notifyListeners();
  }

  /// Vérifie qu'une adresse correspond bien à un serveur Yomara ERP.
  Future<Map<String, dynamic>> probe(String url) async {
    final client = ApiClient(baseUrl: ApiClient.normalizeUrl(url));
    final res = Map<String, dynamic>.from(await client.get('ping'));
    if (res['product'] != 'yomara-erp') throw ApiException('Ce serveur n\'est pas un serveur Yomara ERP.');
    return res;
  }

  Future<void> login(String url, String email, String password) async {
    api.baseUrl = ApiClient.normalizeUrl(url);
    final insecure = kIsWeb ? null : ApiClient.insecureReason(api.baseUrl);
    if (insecure != null) throw ApiException(insecure, status: 400, code: 'insecure_url');
    api.token = null;
    final res = Map<String, dynamic>.from(await api.post('auth/login', {
      'email': email.trim(),
      'password': password,
      'device_name': '${AppConfig.appName} ${clientPlatform()}',
    }));
    api.token = res['token'] as String;
    await LocalDb.meta.put('server_url', api.baseUrl);
    await _saveToken();
    await LocalDb.meta.put('last_email', email.trim());
    await refreshBootstrap(throwOnError: true);
    await _openLocal();
    _start();
    unawaited(_prepare(firstTime: sync.neverSynced));
  }

  /// « Se connecter avec Google » : le serveur vérifie le jeton et ouvre le compte qui a la même adresse.
  Future<void> loginWithGoogle(String url, String idToken) async {
    api.baseUrl = ApiClient.normalizeUrl(url);
    final insecure = kIsWeb ? null : ApiClient.insecureReason(api.baseUrl);
    if (insecure != null) throw ApiException(insecure, status: 400, code: 'insecure_url');
    api.token = null;
    final res = Map<String, dynamic>.from(await api.post('auth/google', {
      'id_token': idToken,
      'device_name': '${AppConfig.appName} ${clientPlatform()}',
    }));
    api.token = res['token'] as String;
    await LocalDb.meta.put('server_url', api.baseUrl);
    await _saveToken();
    final email = '${res['user']?['email'] ?? ''}';
    if (email.isNotEmpty) await LocalDb.meta.put('last_email', email);
    await refreshBootstrap(throwOnError: true);
    await _openLocal();
    _start();
    unawaited(_prepare(firstTime: sync.neverSynced));
  }

  Future<void> register(String url, Map<String, dynamic> data) async {
    api.baseUrl = ApiClient.normalizeUrl(url);
    final insecure = kIsWeb ? null : ApiClient.insecureReason(api.baseUrl);
    if (insecure != null) throw ApiException(insecure, status: 400, code: 'insecure_url');
    final res = Map<String, dynamic>.from(await api.post('auth/register', data));
    api.token = res['token'] as String;
    await LocalDb.meta.put('server_url', api.baseUrl);
    await _saveToken();
    await refreshBootstrap(throwOnError: true);
    await _openLocal();
    _start();
    unawaited(_prepare(firstTime: sync.neverSynced));
  }

  Future<void> refreshBootstrap({bool throwOnError = false}) async {
    try {
      final res = Map<String, dynamic>.from(await api.get('bootstrap'));
      bootstrap = res;
      // Langue : celle choisie par l'utilisateur, sinon celle de la société
      final loc = (res['user']?['locale'] as String?) ?? (res['company']?['settings']?['locale'] as String?);
      if (loc != null && loc != I18n.lang && I18n.languages.containsKey(loc)) {
        await I18n.use(loc);
        await LocalDb.meta.put('lang', loc);
      }
      currencyDecimals = decimalsFor(currency);
      api.branchId = currentBranchId;
      await LocalDb.meta.put('bootstrap', jsonEncode(res));
      sync.entities = List<String>.from(res['sync_entities'] ?? []);
      await LocalDb.openTenant(await _tenantKey(), sync.entities);
      // Favoris et menu enregistrés sur le serveur : retrouvés après une déconnexion ou sur un autre appareil
      PrefsSync.sender = (m) => api.put('me/ui-prefs', {'prefs': m});
      final prefs = res['user']?['ui_prefs'];
      await PrefsSync.restore(prefs is Map ? Map<String, dynamic>.from(prefs) : {}, keys: const {'nav_favorites', 'nav_open', 'nav_rail', 'dash_pinned', 'sidebar_dark', 'mb_theme', 'rp_board'});
      // Agences accessibles modifiées : les données déjà masquées ne reviennent qu'avec une resynchronisation complète
      final scopeKey = 'branch_scope_${company['id']}';
      final scope = jsonEncode(user['branch_ids']);
      final previous = LocalDb.meta.get(scopeKey);
      if (previous != null && previous != scope) {
        unawaited(sync.resync());
      }
      await LocalDb.meta.put(scopeKey, scope);
      notifyListeners();
    } on ApiException catch (e) {
      if (throwOnError) rethrow;
      if (e.code == 'company_suspended') {
        bootstrap = {...?bootstrap, 'suspended': e.message};
        notifyListeners();
      }
    }
  }

  /// Espace local d'un utilisateur dans une société : sur un poste partagé (caisse, accueil), chaque personne a ses
  /// propres données, saisies en attente, e-mails et tableaux de bord. L'espace créé avant cette séparation reste
  /// à la première personne qui se connecte (pas de perte des saisies hors ligne à la mise à jour).
  Future<String> _tenantKey() async {
    final host = Uri.tryParse(api.baseUrl)?.host.replaceAll(RegExp(r'[^a-zA-Z0-9]'), '_') ?? 'srv';
    final base = 't_${host}_${company['id']}';
    final uid = '${user['id'] ?? ''}';
    if (uid.isEmpty) return base;
    final ownerKey = 'owner_$base';
    final owner = LocalDb.meta.get(ownerKey);
    if (owner == null) {
      await LocalDb.meta.put(ownerKey, uid);
      return base;
    }
    return '$owner' == uid ? base : '${base}_u$uid';
  }

  Future<void> _openLocal() async {
    sync.entities = List<String>.from(bootstrap?['sync_entities'] ?? []);
    await LocalDb.openTenant(await _tenantKey(), sync.entities);
  }

  void _start() {
    sync.start();
    // Téléphone : jeton Firebase envoyé au serveur pour recevoir les notifications
    unawaited(FirebaseSetup.registerPush(api));
    _heartbeat?.cancel();
    _heartbeat = Timer.periodic(AppConfig.heartbeatInterval, (_) async {
      try {
        final r = await api.post('presence/ping');
        sync.online = true;
        // Modules, formule ou droits modifiés côté serveur (activation par l'éditeur, achat…) : menu rechargé sans action
        final stamp = r is Map ? r['stamp'] : null;
        if (stamp != null && bootstrap?['stamp'] != null && stamp != bootstrap?['stamp']) {
          await refreshBootstrap();
          modulesChanged.value++;
        }
      } on ApiException catch (e) {
        if (e.isNetwork) sync.online = false;
      }
    });
  }

  /// Enregistre le jeton courant : coffre du système, ou base locale si le coffre n'est pas disponible.
  Future<void> _saveToken() async {
    if (await SecureStore.saveToken(api.token)) {
      await LocalDb.meta.delete('token');
    } else if (api.token != null) {
      await LocalDb.meta.put('token', api.token);
    } else {
      await LocalDb.meta.delete('token');
    }
  }

  /// Effacer par défaut les données de la société à la déconnexion : navigateur (souvent partagé) ou réglage choisi.
  bool get wipeOnLogoutDefault => (LocalDb.meta.get('wipe_on_logout') as bool?) ?? kIsWeb;

  bool _loggingOut = false;

  Future<void> logout({bool remote = true, bool? wipe}) async {
    // Plusieurs réponses 401 à la suite (ou 401 pendant une déconnexion) : une seule déconnexion
    if (_loggingOut) return;
    _loggingOut = true;
    try {
      await _logout(remote: remote, wipe: wipe);
    } finally {
      _loggingOut = false;
    }
  }

  Future<void> _logout({required bool remote, bool? wipe}) async {
    MailAlerts.clear(); // les alertes de messagerie ne passent pas à la personne suivante
    if (remote) {
      // Les modifications non envoyées restent stockées localement
      try {
        // Attend une synchronisation déjà en cours puis envoie les dernières saisies
        await sync.syncNow().timeout(const Duration(seconds: 60));
      } catch (_) {}
      try {
        await PrefsSync.flush();
        await FirebaseSetup.unregisterPush(api);
        await api.post('auth/logout');
      } catch (_) {}
    } else {
      // session expirée ou révoquée : notifications coupées sur cet appareil quand même
      try {
        await FirebaseSetup.unregisterPush(api);
      } catch (_) {}
    }
    _heartbeat?.cancel();
    sync.stop();
    // Attendre la fin d'une synchronisation en cours avant de fermer les données locales
    try {
      await sync.idle().timeout(const Duration(seconds: 30));
    } catch (_) {}
    // Saisies encore en attente d'envoi : l'effacement « par défaut » (navigateur) ne les supprime pas ;
    // seul un choix explicite de la personne, prévenue de la perte, les efface.
    final pendingLeft = sync.pendingCount;
    // Caches en mémoire de la session précédente (utilisateurs, catalogue des rapports, préférences)
    PrefsSync.reset();
    RecordSchema.clear();
    RapportsMeta.reset();
    api.token = null;
    bootstrap = null;
    await _saveToken();
    await LocalDb.meta.delete('bootstrap');
    final tenant = LocalDb.tenant;
    await LocalDb.closeTenant();
    // Poste partagé : plus aucune donnée de la société sur l'appareil après la déconnexion
    final erase = wipe ?? (remote && wipeOnLogoutDefault && pendingLeft == 0);
    if (erase && tenant != null) {
      if (wipe != null) await LocalDb.meta.put('wipe_on_logout', wipe);
      await LocalDb.eraseTenant(tenant);
    }
    notifyListeners();
  }

  @override
  void dispose() {
    _heartbeat?.cancel();
    sync.dispose();
    super.dispose();
  }
}
