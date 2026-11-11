import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:hive_ce/hive.dart';
import 'package:uuid/uuid.dart';

import '../../../core/api_client.dart';
import '../../../core/local_db.dart';
import '../../../state/session.dart';
import '../../../core/json.dart';

/// Copie locale de la boîte mail sur ce poste : comptes, dossiers, messages récents (corps compris),
/// envois et actions faits hors ligne rejoués au retour du réseau.
class MailStore extends ChangeNotifier {
  MailStore(this.session);

  final Session session;
  Box<String>? _box;
  Timer? _timer;

  List<Map<String, dynamic>> accounts = [];
  List<Map<String, dynamic>> folders = [];
  List<Map<String, dynamic>> signatures = [];
  /// Signatures attribuées par boîte : account_id → {new_id, reply_id}
  Map<int, Map<String, dynamic>> signatureDefaults = {};
  /// Carnet d'adresses interne : collaborateurs, direction, stagiaires, boîtes partagées
  List<Map<String, dynamic>> directory = [];

  /// Lance une recherche dans la boîte (posé par l'écran : « Tous les e-mails de ce contact »).
  void Function(String q)? searchFor;
  List<Map<String, dynamic>> templates = [];
  List<Map<String, dynamic>> outbox = [];
  List<Map<String, dynamic>> users = [];
  Map<String, dynamic> oauth = {};
  /// Connexions Gmail / Outlook coupées par le super admin de la plateforme.
  Map<String, dynamic> oauthDisabled = {};
  Map<String, dynamic> hosted = {};
  String mobileUrl = '';
  bool canManage = false;
  bool loading = true;
  bool syncing = false;
  bool offline = false;
  String? error;
  final Map<int, Map<String, dynamic>> _messages = {};
  int revision = 0;

  /// Dernière actualisation réussie et nouveaux messages reçus (pour l'indicateur « en direct » et l'alerte).
  DateTime? lastRefreshAt;
  final arrivals = ValueNotifier<List<Map<String, dynamic>>>(const []);
  bool _foreground = true;
  int _tick = 0;

  set foreground(bool v) {
    final was = _foreground;
    _foreground = v;
    if (v && !was) unawaited(live());
  }

  /// Boîte affichée : le serveur relève les comptes (au plus toutes les 30 s), puis on récupère les nouveautés.
  Future<void> live() async {
    if (syncing) return;
    try {
      await api.post('mailbox/live');
    } catch (_) {}
    await refresh();
  }

  ApiClient get api => session.api;

  dynamic _state(String k) => LocalDb.isOpen ? LocalDb.state().get(k) : null;
  Future<void> _put(String k, dynamic v) async {
    if (LocalDb.isOpen) await LocalDb.state().put(k, v);
  }

  Future<void> init() async {
    if (LocalDb.isOpen) {
      _box = await LocalDb.extraBox('mailbox');
      for (final v in _box!.values) {
        final m = Map<String, dynamic>.from(jsonDecode(v));
        _messages[m['id'] as int] = m;
      }
      final idx = _state('mb_index');
      if (idx is String) _applyIndex(Map<String, dynamic>.from(jsonDecode(idx)));
    }
    loading = accounts.isEmpty && _messages.isEmpty;
    notifyListeners();
    await refresh();
    unawaited(live());
    // En direct : nouveautés toutes les 20 s quand la boîte est à l'écran (relève serveur au plus toutes les 30 s), sinon chaque minute
    _timer = Timer.periodic(const Duration(seconds: 20), (_) {
      _tick++;
      if (_foreground) {
        unawaited(live());
      } else if (_tick % 3 == 0) {
        unawaited(refresh());
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _applyIndex(Map<String, dynamic> d) {
    List<Map<String, dynamic>> l(String k) => [for (final e in (d[k] as List? ?? const [])) asMap(e)];
    accounts = l('accounts');
    folders = l('folders');
    signatures = l('signatures');
    directory = l('directory');
    signatureDefaults = {for (final x in l('signature_defaults')) (x['account_id'] as num).toInt(): x};
    templates = l('templates');
    outbox = l('outbox');
    users = l('users');
    oauth = asMap(d['oauth']);
    oauthDisabled = asMap(d['oauth_disabled']);
    hosted = asMap(d['hosted']);
    mobileUrl = '${d['mobile_url'] ?? ''}';
    canManage = d['can_manage'] == true;
  }

  List<Map<String, dynamic>> get pending => [for (final p in (jsonDecode((_state('mb_pending') as String?) ?? '[]') as List)) asMap(p)];

  Future<void> _savePending(List<Map<String, dynamic>> p) => _put('mb_pending', jsonEncode(p));

  List<Map<String, dynamic>> get _ops => [for (final p in (jsonDecode((_state('mb_ops') as String?) ?? '[]') as List)) asMap(p)];

  Future<void> _saveOps(List<Map<String, dynamic>> p) => _put('mb_ops', jsonEncode(p));

  /// Synchronisation : envois et actions en attente, puis index et messages modifiés.
  Future<void> refresh({bool pull = false}) async {
    if (syncing) return;
    syncing = true;
    notifyListeners();
    try {
      await _flush();
      if (pull) {
        for (final a in accounts.where((a) => a['status'] != 'paused')) {
          try {
            await api.postLong('mailbox/accounts/${a['id']}/sync');
          } catch (_) {}
        }
      }
      final idx = await api.get('mailbox');
      final d = asMap(idx['data']);
      _applyIndex(d);
      await _put('mb_index', jsonEncode(d));
      var cursor = (_state('mb_cursor') as String?) ?? '';
      final firstLoad = _messages.isEmpty;
      final inboxIds = {for (final f in folders.where((f) => f['role'] == 'inbox')) f['id']};
      final fresh = <Map<String, dynamic>>[];
      for (var i = 0; i < 20; i++) {
        final r = await api.get('mailbox/changes', query: {'since': cursor, 'limit': '200'});
        final rows = [for (final m in (r['data'] as List)) asMap(m)];
        final put = <String, String>{};
        for (final m in rows) {
          if (!firstLoad && !_messages.containsKey(m['id']) && m['seen'] != true && inboxIds.contains(m['folder_id'])) fresh.add(m);
          _messages[m['id'] as int] = m;
          put['${m['id']}'] = jsonEncode(m);
        }
        final del = [for (final x in (r['deleted'] as List? ?? const [])) x as int];
        for (final id in del) {
          _messages.remove(id);
        }
        await _box?.putAll(put);
        await _box?.deleteAll(del.map((e) => '$e'));
        cursor = '${r['cursor'] ?? cursor}';
        await _put('mb_cursor', cursor);
        if (r['more'] != true) break;
      }
      // messages de dossiers ou comptes disparus
      final fids = {for (final f in folders) f['id']};
      final gone = _messages.values.where((m) => !fids.contains(m['folder_id'])).map((m) => m['id'] as int).toList();
      for (final id in gone) {
        _messages.remove(id);
      }
      await _box?.deleteAll(gone.map((e) => '$e'));
      offline = false;
      error = null;
      lastRefreshAt = DateTime.now();
      if (fresh.isNotEmpty) arrivals.value = fresh;
    } on ApiException catch (e) {
      if (e.isNetwork) {
        offline = true;
      } else {
        error = e.detail;
      }
    } catch (e) {
      offline = true;
    } finally {
      loading = false;
      syncing = false;
      revision++;
      notifyListeners();
    }
  }

  Future<void> _flush() async {
    final p = pending;
    if (p.isNotEmpty) {
      final left = <Map<String, dynamic>>[];
      for (final m in p) {
        try {
          await api.post('mailbox/send', m);
        } on ApiException catch (e) {
          if (e.isNetwork) {
            left.add(m);
          } // refus du serveur : visible dans la boîte d'envoi (statut « échec »)
        }
      }
      await _savePending(left);
    }
    final ops = _ops;
    if (ops.isNotEmpty) {
      final left = <Map<String, dynamic>>[];
      for (final o in ops) {
        try {
          await _remote(o);
        } on ApiException catch (e) {
          if (e.isNetwork) left.add(o);
        }
      }
      await _saveOps(left);
    }
  }

  Future<void> _remote(Map<String, dynamic> o) async {
    switch (o['op']) {
      case 'flags':
        await api.post('mailbox/messages/${o['id']}/flags', o['data']);
      case 'move':
        await api.post('mailbox/messages/${o['id']}/move', {'folder_id': o['folder_id']});
      case 'delete':
        await api.delete('mailbox/messages/${o['id']}');
    }
  }

  /// Action immédiate sur la copie locale, envoyée au serveur (ou mise en attente sans réseau).
  Future<void> _act(Map<String, dynamic> op) async {
    try {
      await _remote(op);
    } on ApiException catch (e) {
      if (e.isNetwork) {
        await _saveOps([..._ops, op]);
        offline = true;
      } else {
        error = e.detail;
        rethrow;
      }
    }
  }

  // ------------------------------------------------------------------ lecture

  Map<String, dynamic>? folder(dynamic id) => folders.where((f) => f['id'] == id).firstOrNull;
  Map<String, dynamic>? account(dynamic id) => accounts.where((a) => a['id'] == id).firstOrNull;
  Map<String, dynamic>? local(int id) => _messages[id];

  static const roleOrder = ['inbox', 'drafts', 'sent', 'archive', 'other', 'junk', 'trash'];

  List<Map<String, dynamic>> foldersOf(int accountId) {
    final l = folders.where((f) => f['account_id'] == accountId).toList();
    l.sort((a, b) {
      final r = roleOrder.indexOf('${a['role']}').compareTo(roleOrder.indexOf('${b['role']}'));
      return r != 0 ? r : '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase());
    });
    return l;
  }

  Map<String, dynamic>? roleFolder(int accountId, String role) => folders.where((f) => f['account_id'] == accountId && f['role'] == role).firstOrNull;

  int unreadIn(String role) => folders.where((f) => f['role'] == role).fold(0, (s, f) => s + ((f['unread'] as num?)?.toInt() ?? 0));

  /// Messages de la copie locale pour une vue (dossier, rôle unifié, non lus, suivis), filtrés et triés.
  /// Boîte « Prioritaires » (comme Outlook) : contacts connus et échanges réels ; lettres d'information et envois automatiques à part.
  static final _bulk = RegExp(r'(no-?reply|newsletter|news@|mailer|notification|marketing|bounce|donotreply|info@|promo)', caseSensitive: false);
  bool isFocused(Map<String, dynamic> m) {
    if (m['partner'] != null || m['partner_id'] != null || m['flagged'] == true || m['answered'] == true) return true;
    final from = '${m['from_email'] ?? ''}';
    if (_bulk.hasMatch(from)) return false;
    final s = '${m['snippet'] ?? ''}'.toLowerCase();
    return !(s.contains('désabonner') || s.contains('desinscri') || s.contains('unsubscribe'));
  }

  List<Map<String, dynamic>> query({int? folderId, String? role, bool unread = false, bool flagged = false, bool attachments = false, String q = '', String? partnerId, bool? focused}) {
    final fids = role != null ? {for (final f in folders.where((f) => f['role'] == role)) f['id']} : null;
    final needle = _norm(q);
    final out = _messages.values.where((m) {
      if (folderId != null && m['folder_id'] != folderId) return false;
      if (fids != null && !fids.contains(m['folder_id'])) return false;
      if (unread && m['seen'] == true) return false;
      if (flagged && m['flagged'] != true) return false;
      if (attachments && m['has_attachments'] != true) return false;
      if (partnerId != null && m['partner_id'] != partnerId) return false;
      if (focused != null && isFocused(m) != focused) return false;
      if (needle.isNotEmpty) {
        final hay = _norm('${m['subject']} ${m['from_name']} ${m['from_email']} ${m['snippet']} ${(m['to'] as List? ?? []).map((t) => '${t['email']} ${t['name']}').join(' ')} ${m['text'] ?? ''}');
        if (!needle.split(' ').every(hay.contains)) return false;
      }
      return true;
    }).toList();
    out.sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
    return out;
  }

  static String _norm(String s) {
    const from = 'àâäáãåçéèêëíìîïñóòôöõúùûüýÿœæ';
    const to = 'aaaaaaceeeeiiiinooooouuuuyyoa';
    final b = StringBuffer();
    for (final c in s.toLowerCase().runes) {
      final ch = String.fromCharCode(c);
      final i = from.indexOf(ch);
      b.write(i >= 0 ? to[i] : ch);
    }
    return b.toString().replaceAll(RegExp(r'\s+'), ' ').trim();
  }

  List<Map<String, dynamic>> thread(Map<String, dynamic> m) {
    final k = m['thread_key'];
    if (k == null) return [m];
    final l = _messages.values.where((x) => x['thread_key'] == k).toList()..sort((a, b) => '${a['date']}'.compareTo('${b['date']}'));
    return l.isEmpty ? [m] : l;
  }

  /// Message complet : copie locale si le corps y est, sinon serveur (puis mis en cache).
  Future<Map<String, dynamic>> open(int id, {bool markRead = true}) async {
    final m = _messages[id];
    if (m != null && markRead && m['seen'] != true) {
      unawaited(setFlags(id, seen: true));
    }
    final needServer = m == null || m['html'] == null && m['text'] == null || m['body_loaded'] == false || (m['has_attachments'] == true && (m['attachments'] as List? ?? const []).isEmpty) || !m.containsKey('actions');
    if (!needServer) return m;
    try {
      final r = await api.get('mailbox/messages/$id');
      final full = asMap(r['data']);
      if (r['partner'] != null) full['partner'] = r['partner'];
      if (r['warning'] != null) full['warning'] = r['warning'];
      full['insights'] = r['insights']; // dates citées, invitation, nature de la demande
      full['actions'] = r['actions'] ?? const [];
      _messages[id] = full;
      await _box?.put('$id', jsonEncode(full));
      notifyListeners();
      return full;
    } catch (_) {
      if (m != null) return m;
      rethrow;
    }
  }

  Future<List<Map<String, dynamic>>> older(Map<String, dynamic> query, String? before) async {
    final r = await api.get('mailbox/messages', query: {...query.map((k, v) => MapEntry(k, '$v')), 'before': ?before, 'limit': '50'});
    return [for (final m in (r['data'] as List)) asMap(m)];
  }

  // ------------------------------------------------------------------ actions

  Future<void> _localUpdate(int id, Map<String, dynamic> patch) async {
    final m = _messages[id];
    if (m == null) return;
    final n = {...m, ...patch};
    _messages[id] = n;
    await _box?.put('$id', jsonEncode(n));
  }

  void _adjustUnread(dynamic folderId, int delta) {
    final f = folder(folderId);
    if (f != null) f['unread'] = ((f['unread'] as num? ?? 0) + delta).clamp(0, 1 << 30);
  }

  Future<void> setFlags(int id, {bool? seen, bool? flagged}) async {
    final m = _messages[id];
    if (m != null && seen != null && m['seen'] != seen) _adjustUnread(m['folder_id'], seen ? -1 : 1);
    await _localUpdate(id, {'seen': ?seen, 'flagged': ?flagged});
    revision++;
    notifyListeners();
    await _act({'op': 'flags', 'id': id, 'data': {'seen': ?seen, 'flagged': ?flagged}});
  }

  Future<void> move(int id, int folderId) async {
    final m = _messages[id];
    if (m != null && m['seen'] != true) {
      _adjustUnread(m['folder_id'], -1);
      _adjustUnread(folderId, 1);
    }
    await _localUpdate(id, {'folder_id': folderId});
    revision++;
    notifyListeners();
    await _act({'op': 'move', 'id': id, 'folder_id': folderId});
  }

  /// Messages de la même conversation rangés dans le même dossier (on range la discussion entière, comme Gmail).
  List<int> conversationIds(Map<String, dynamic> m) =>
      [for (final x in thread(m)) if (x['folder_id'] == m['folder_id'] && x['account_id'] == m['account_id']) x['id'] as int];

  Future<int> moveConversation(int id, int folderId) async {
    final m = _messages[id];
    final ids = m == null ? [id] : conversationIds(m);
    for (final i in ids) {
      await move(i, folderId);
    }
    return ids.length;
  }

  // ------------------------------------------------------------------ dossiers de rangement

  Future<Map<String, dynamic>> createFolder(int accountId, String name, {int? parentId}) async {
    final r = await api.post('mailbox/folders', {'account_id': accountId, 'name': name, 'parent_id': ?parentId});
    await reloadIndex();
    return asMap(r['data']);
  }

  Future<void> renameFolder(int id, String name) async {
    await api.put('mailbox/folders/$id', {'name': name});
    await reloadIndex();
  }

  Future<void> deleteFolder(int id) async {
    await api.delete('mailbox/folders/$id');
    final gone = [for (final m in _messages.values) if (m['folder_id'] == id) m['id'] as int];
    for (final g in gone) {
      _messages.remove(g);
      await _box?.delete('$g');
    }
    await reloadIndex();
  }

  Future<void> remove(int id) async {
    final m = _messages[id];
    if (m == null) return;
    final trash = roleFolder(m['account_id'] as int, 'trash');
    if (trash != null && m['folder_id'] != trash['id']) {
      await move(id, trash['id'] as int);
      return;
    }
    _messages.remove(id);
    await _box?.delete('$id');
    revision++;
    notifyListeners();
    await _act({'op': 'delete', 'id': id});
  }

  Future<void> archive(int id) async {
    final m = _messages[id];
    if (m == null) return;
    final a = roleFolder(m['account_id'] as int, 'archive');
    if (a == null) throw StateError('Ce compte n\'a pas de dossier Archives.');
    await move(id, a['id'] as int);
  }

  /// Envoi : part tout de suite si le serveur répond, sinon attend le retour du réseau (sans doublon : identifiant unique).
  Future<Map<String, dynamic>> send(Map<String, dynamic> mail) async {
    final m = {...mail, 'id': mail['id'] ?? const Uuid().v4()};
    try {
      final r = await api.post('mailbox/send', m);
      unawaited(refresh());
      return asMap(r['data']);
    } on ApiException catch (e) {
      if (!e.isNetwork) rethrow;
      await _savePending([...pending, m]);
      offline = true;
      notifyListeners();
      return {'id': m['id'], 'status': 'offline'};
    }
  }

  Future<void> cancelPending(String id) async {
    await _savePending(pending.where((p) => p['id'] != id).toList());
    notifyListeners();
  }

  Future<void> reloadIndex() async {
    try {
      final idx = await api.get('mailbox');
      final d = asMap(idx['data']);
      _applyIndex(d);
      await _put('mb_index', jsonEncode(d));
      revision++;
      notifyListeners();
    } catch (_) {}
  }

  /// Adresses connues pour l'autocomplétion : clients / fournisseurs + correspondants récents.
  List<Map<String, String>> contacts() {
    final seen = <String>{};
    final out = <Map<String, String>>[];
    void add(String? name, String? email, [String? label]) {
      final e = (email ?? '').trim().toLowerCase();
      if (e.isEmpty || !e.contains('@') || !seen.add(e)) return;
      out.add({'name': (name ?? '').trim(), 'email': e, 'label': ?label});
    }

    // Collègues et direction d'abord (carnet d'adresses de la société)
    for (final d in directory) {
      add('${d['name'] ?? ''}', '${d['email']}', d['label'] == null ? 'Collègue' : '${d['label']}');
    }

    for (final p in LocalDb.all('partners')) {
      add('${p['name'] ?? ''}', p['email'] as String?);
    }
    final recent = _messages.values.toList()..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
    for (final m in recent.take(800)) {
      add(m['from_name'] as String?, m['from_email'] as String?);
      for (final t in (m['to'] as List? ?? const [])) {
        add(t['name'] as String?, t['email'] as String?);
      }
    }
    for (final u in users) {
      add('${u['name']}', '${u['email']}');
    }
    return out;
  }
}
