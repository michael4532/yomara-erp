import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/local_db.dart';
import '../../../core/sync_engine.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';
import '../../widgets/mail_html.dart';
import '../../widgets/mail_alerts.dart';
import 'compose.dart';
import 'mail_assist.dart';
import 'mail_store.dart';
import 'mail_theme.dart';
import 'mailbox_settings.dart';
import 'mail_actions.dart';
import '../document_editor.dart';
import '../documents_screen.dart';
import '../../../i18n/i18n.dart';
import '../../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';

/// Boîte mail intégrée (façon Outlook) : dossiers, liste, lecture, rédaction — copie locale pour le hors ligne.
class MailboxScreen extends StatefulWidget {
  const MailboxScreen({super.key});

  @override
  State<MailboxScreen> createState() => _MailboxScreenState();
}

class _View {
  const _View({this.folderId, this.role, this.unread = false, this.flagged = false, required this.title});

  final int? folderId;
  final String? role;
  final bool unread, flagged;
  final String title;

  String get key => '$folderId|$role|$unread|$flagged';
}

class _MailboxScreenState extends State<MailboxScreen> {
  late final MailStore _s = MailStore(context.read<Session>());
  _View _view = _View(role: 'inbox', title: tr('Boîte de réception'));
  final _search = TextEditingController();
  String _q = '';
  String _filter = 'all';
  int? _openId;
  Map<String, dynamic>? _open;
  bool _openLoading = false;
  final List<Map<String, dynamic>> _older = [];
  String? _olderCursor;
  bool _olderDone = false;
  final Set<int> _selected = {};
  MailTheme _theme = MailTheme.current;
  double _wFolders = (LocalDb.meta.get('mb_w_folders') as num?)?.toDouble() ?? 250;
  double _wList = (LocalDb.meta.get('mb_w_list') as num?)?.toDouble() ?? 400;
  bool _foldersOpen = LocalDb.meta.get('mb_folders_open') as bool? ?? true;
  int? _accId; // compte affiché dans « Dossiers »
  bool _showRcpt = false; // détail des destinataires du message ouvert

  void _saveLayout() {
    LocalDb.meta.put('mb_w_folders', _wFolders);
    LocalDb.meta.put('mb_w_list', _wList);
    LocalDb.meta.put('mb_folders_open', _foldersOpen);
  }

  AppLifecycleListener? _life;

  /// Carnet d'adresses interne : tous les collaborateurs, la direction et les boîtes partagées, mis à jour automatiquement.
  Future<void> _directory() async {
    var q = '';
    await showDialog(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) {
          final list = _s.directory.where((d) => q.isEmpty || '${d['name']} ${d['email']} ${d['label'] ?? ''}'.toLowerCase().contains(q.toLowerCase())).toList();
          return YAlertDialog(
            icon: const Icon(Icons.contacts_outlined),
            title: Text(tr('Carnet d\'adresses de l\'entreprise')),
            content: SizedBox(
              width: 560,
              height: 480,
              child: Column(
                children: [
                  Text(tr('Collaborateurs, direction, stagiaires et boîtes partagées : chaque nouvelle boîte connectée y apparaît automatiquement.'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12.5)),
                  const SizedBox(height: 10),
                  TextField(
                    autofocus: true,
                    decoration: InputDecoration(prefixIcon: const Icon(Icons.search, size: 20), hintText: tr('Nom, adresse, fonction…')),
                    onChanged: (v) => set(() => q = v),
                  ),
                  const SizedBox(height: 8),
                  Expanded(
                    child: list.isEmpty
                        ? EmptyState(icon: Icons.person_search_outlined, title: tr('Aucun collaborateur trouvé'))
                        : ListView(
                            children: [
                              for (final d in list)
                                ListTile(
                                  leading: InitialsAvatar('${(d['name'] ?? '').toString().isNotEmpty ? d['name'] : d['email']}', size: 34),
                                  title: Text(tr('${(d['name'] ?? '').toString().isNotEmpty ? d['name'] : d['email']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                                  subtitle: Text(tr('${d['email']}${d['label'] != null ? ' · ${d['label']}' : ''}')),
                                  trailing: IconButton(
                                    tooltip: tr('Écrire'),
                                    icon: const Icon(Icons.edit_outlined),
                                    onPressed: () {
                                      Navigator.pop(c);
                                      openComposer(
                                        context,
                                        _s,
                                        to: [
                                          {'name': '${d['name'] ?? ''}', 'email': '${d['email']}'},
                                        ],
                                      );
                                    },
                                  ),
                                ),
                            ],
                          ),
                  ),
                ],
              ),
            ),
            actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
          );
        },
      ),
    );
  }

  @override
  void initState() {
    super.initState();
    _s.addListener(_changed);
    _s.arrivals.addListener(_arrived);
    _life = AppLifecycleListener(onStateChange: (st) => _s.foreground = st == AppLifecycleState.resumed);
    _s.init();
    MailAlerts.opener = _alertOpener;
  }

  // Ouverture depuis la cloche (comparaison par égalité de la méthode détachée, stable pour cette instance)
  void _alertOpener(Map<String, dynamic> m) {
    MailAlerts.remove(m);
    _openMessage(m);
  }

  // Nouveaux messages arrivés pendant que la boîte est ouverte : signalés dans la cloche de notifications
  void _arrived() {
    final l = _s.arrivals.value;
    if (!mounted || l.isEmpty) return;
    MailAlerts.push(List<Map<String, dynamic>>.from(l));
  }

  void _changed() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    if (MailAlerts.opener == _alertOpener) MailAlerts.opener = null;
    _life?.dispose();
    _s.arrivals.removeListener(_arrived);
    _s.removeListener(_changed);
    _s.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> get _list {
    final l = _s.query(folderId: _view.folderId, role: _view.role, unread: _view.unread || _filter == 'unread', flagged: _view.flagged || _filter == 'flagged', attachments: _filter == 'attachments', q: _q, focused: _filter == 'focused' ? true : (_filter == 'other' ? false : null));
    final ids = {for (final m in l) m['id']};
    return [...l, ..._older.where((m) => !ids.contains(m['id']))];
  }

  void _select(_View v) {
    setState(() {
      _view = v;
      _openId = null;
      _open = null;
      _older.clear();
      _olderCursor = null;
      _olderDone = false;
      _selected.clear();
    });
  }

  Future<void> _openMessage(Map<String, dynamic> m) async {
    if (MailAlerts.items.value.any((x) => x['id'] == m['id'])) MailAlerts.remove(m);
    setState(() {
      _openId = m['id'] as int;
      _open = m;
      _openLoading = true;
    });
    try {
      final full = await _s.open(m['id'] as int);
      if (mounted && _openId == m['id']) setState(() => _open = full);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _openLoading = false);
    }
  }

  Future<void> _loadOlder() async {
    final list = _list;
    final last = list.isEmpty ? null : list.last;
    try {
      final rows = await _s.older({if (_view.folderId != null) 'folder_id': _view.folderId!, if (_view.role != null) 'role': _view.role!, if (_view.unread || _filter == 'unread') 'unread': 1, if (_view.flagged || _filter == 'flagged') 'flagged': 1, if (_filter == 'attachments') 'attachments': 1, if (_q.isNotEmpty) 'q': _q}, _olderCursor ?? (last == null ? null : '${last['date']}|${last['id']}'));
      if (!mounted) return;
      setState(() {
        _older.addAll(rows);
        _olderDone = rows.length < 50;
        if (rows.isNotEmpty) _olderCursor = '${rows.last['date']}|${rows.last['id']}';
      });
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _do(Future<void> Function() f, [String? ok]) async {
    try {
      await f();
      if (ok != null && mounted) toast(context, ok);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  void _afterRemove(int id) {
    if (_openId == id) {
      final l = _list;
      final i = l.indexWhere((m) => m['id'] == id);
      setState(() {
        _openId = null;
        _open = null;
      });
      if (i >= 0 && i + 1 < l.length) _openMessage(l[i + 1]);
    }
  }

  MailPalette get _p => MailPalette.of(context, _theme.accent);

  @override
  Widget build(BuildContext context) {
    final base = Theme.of(context);
    return Theme(
      data: base.copyWith(
        colorScheme: base.colorScheme.copyWith(primary: _theme.accent),
        progressIndicatorTheme: ProgressIndicatorThemeData(color: _theme.accent),
      ),
      child: Builder(
        builder: (context) {
          final p = MailPalette.of(context, _theme.accent);
          return ColoredBox(
            color: p.page,
            child: LayoutBuilder(
              builder: (context, c) {
                if (_s.loading) return const Center(child: CircularProgressIndicator());
                if (_s.accounts.isEmpty) return _welcome();
                final medium = c.maxWidth >= 720;
                if (!medium) return _mobile();
                final showFolders = _foldersOpen && c.maxWidth >= 980;
                final fw = _wFolders.clamp(200.0, 380.0);
                final maxList = (c.maxWidth - (showFolders ? fw : 0) - 380).clamp(280.0, 760.0);
                final lw = _wList.clamp(280.0, maxList);
                return Column(
                  children: [
                    _header(showFolders: showFolders),
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            if (showFolders) ...[
                              SizedBox(width: fw, child: _folders()),
                              _Splitter(
                                color: _theme.accent,
                                onDrag: (dx) => setState(() => _wFolders = (fw + dx).clamp(200.0, 380.0)),
                                onEnd: _saveLayout,
                                onReset: () {
                                  setState(() => _wFolders = 250);
                                  _saveLayout();
                                },
                              ),
                            ],
                            SizedBox(width: lw, child: _card(_messageList())),
                            _Splitter(
                              color: _theme.accent,
                              onDrag: (dx) => setState(() => _wList = (lw + dx).clamp(280.0, maxList)),
                              onEnd: _saveLayout,
                              onReset: () {
                                setState(() => _wList = 400);
                                _saveLayout();
                              },
                            ),
                            Expanded(child: _card(_openId == null ? _emptyReader(showFolders: !showFolders) : _reader())),
                          ],
                        ),
                      ),
                    ),
                  ],
                );
              },
            ),
          );
        },
      ),
    );
  }

  /// Volet : carte arrondie à ombre douce.
  Widget _card(Widget child) => Container(
    decoration: _p.cardBox,
    clipBehavior: Clip.antiAlias,
    child: Material(type: MaterialType.transparency, child: child),
  );

  /// Cibles des commandes : sélection multiple, sinon message ouvert.
  List<int> get _targets => _selected.isNotEmpty ? _selected.toList() : [?_openId];

  Future<void> _bulk(String action, {int? folderId}) async {
    final ids = _targets;
    if (ids.isEmpty) return;
    if (ids.length == 1 && _selected.isEmpty) {
      final id = ids.first;
      final m = _s.local(id) ?? _open;
      await _do(() async {
        switch (action) {
          case 'delete':
            await _s.remove(id);
            _afterRemove(id);
          case 'archive':
            await _s.archive(id);
            _afterRemove(id);
          case 'move':
            await _s.moveConversation(id, folderId!);
            _afterRemove(id);
          case 'flag':
            await _s.setFlags(id, flagged: m?['flagged'] != true);
            if (mounted) setState(() => _open = _s.local(id) ?? _open);
          case 'unread':
            await _s.setFlags(id, seen: m?['seen'] != true ? true : false);
            if (m?['seen'] == true) setState(() => (_openId = null, _open = null));
        }
      });
      return;
    }
    try {
      final r = await _s.api.post('mailbox/messages/bulk', {
        'ids': ids,
        'action': action == 'flag'
            ? 'flag'
            : action == 'unread'
            ? 'unread'
            : action,
        'folder_id': ?folderId,
      });
      if ((r['errors'] as List?)?.isNotEmpty == true && mounted) toast(context, '${(r['errors'] as List).first}', error: true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    setState(() {
      _selected.clear();
      _selectMode = false;
    });
    await _s.refresh();
  }

  /// En-tête épuré : afficher/masquer les dossiers, recherche large, état de la synchronisation, application mobile et thème.
  Widget _header({required bool showFolders}) {
    final p = _p;
    return SizedBox(
      height: 64,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(10, 10, 12, 10),
        child: Row(
          children: [
            IconButton(
              tooltip: trn(_foldersOpen ? 'Masquer les dossiers' : 'Afficher les dossiers'),
              icon: const Icon(Icons.menu_rounded),
              onPressed: () {
                setState(() => _foldersOpen = !_foldersOpen);
                _saveLayout();
              },
            ),
            const SizedBox(width: 6),
            if (!showFolders) ...[ComposeButton(theme: _theme, compact: true, onPressed: () => openComposer(context, _s)), const SizedBox(width: 12)],
            Expanded(
              child: Align(
                alignment: Alignment.centerLeft,
                child: ConstrainedBox(constraints: const BoxConstraints(maxWidth: 640), child: _searchField(card: true)),
              ),
            ),
            const SizedBox(width: 12),
            if (_s.offline) ...[
              MailStatusChip(
                leading: const Icon(Icons.cloud_off, size: 15, color: YColors.warning),
                label: 'Hors ligne',
                color: YColors.warning,
                tooltip: tr('Hors ligne : copie locale'),
              ),
              const SizedBox(width: 8),
            ] else ...[
              _syncChip(p),
              const SizedBox(width: 8),
            ],
            _LiveBadge(store: _s),
            const SizedBox(width: 4),
            IconButton(tooltip: tr('Application mobile'), icon: const Icon(Icons.qr_code_2), onPressed: _s.mobileUrl.isEmpty ? null : () => showMobileAppQr(context, _s.mobileUrl, ready: false)),
            IconButton(tooltip: tr('Thème'), icon: const Icon(Icons.palette_outlined), onPressed: _pickTheme),
          ],
        ),
      ),
    );
  }

  Future<void> _pickTheme() async {
    final t = await pickMailTheme(context, _theme);
    if (t != null) {
      await MailTheme.save(t);
      if (mounted) setState(() => _theme = t);
    }
  }

  /// « Synchronisé » (cliquer : synchroniser maintenant), « Synchronisation… » ou erreur.
  Widget _syncChip(MailPalette p) {
    if (_s.syncing) {
      return MailStatusChip(
        leading: SizedBox(width: 13, height: 13, child: CircularProgressIndicator(strokeWidth: 2, color: p.accent)),
        label: 'Synchronisation…',
        color: p.accent,
      );
    }
    final err = _s.accounts.any((a) => a['status'] == 'error');
    return MailStatusChip(
      leading: Icon(err ? Icons.error_outline : Icons.check_circle, size: 15, color: err ? YColors.danger : YColors.success),
      label: err ? 'Erreur de synchro' : 'Synchronisé',
      color: err ? YColors.danger : null,
      tooltip: tr('Synchroniser'),
      onTap: () => _s.refresh(pull: true),
    );
  }

  /// Champ de recherche arrondi (en-tête sur ordinateur, liste sur téléphone).
  Widget _searchField({bool card = false}) {
    final p = _p;
    final border = OutlineInputBorder(
      borderRadius: BorderRadius.circular(24),
      borderSide: BorderSide(color: card ? p.line : Colors.transparent),
    );
    return TextField(
      controller: _search,
      decoration: InputDecoration(
        isDense: true,
        filled: true,
        fillColor: card ? p.card : p.soft,
        contentPadding: const EdgeInsets.symmetric(vertical: 12),
        border: border,
        enabledBorder: border,
        focusedBorder: border.copyWith(borderSide: BorderSide(color: p.accent, width: 1.4)),
        prefixIcon: Icon(Icons.search_rounded, size: 21, color: p.muted),
        hintText: tr('Rechercher dans les messages'),
        suffixIcon: _q.isEmpty
            ? null
            : IconButton(
                icon: const Icon(Icons.clear, size: 18),
                onPressed: () => setState(() {
                  _search.clear();
                  _q = '';
                  _older.clear();
                }),
              ),
      ),
      onChanged: (v) => setState(() {
        _q = v.trim();
        _older.clear();
        _olderCursor = null;
        _olderDone = false;
      }),
    );
  }

  /// Téléphone : une vue à la fois, gestes de balayage, tirer pour actualiser.
  Widget _mobile() {
    final p = _p;
    if (_openId != null) {
      return ColoredBox(
        color: p.page,
        child: SafeArea(
          child: Padding(padding: const EdgeInsets.all(8), child: _card(_reader(back: true))),
        ),
      );
    }
    return Scaffold(
      backgroundColor: p.page,
      drawer: Drawer(
        backgroundColor: p.page,
        child: SafeArea(child: _folders(drawer: true)),
      ),
      appBar: AppBar(
        backgroundColor: p.page,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        leading: Builder(
          builder: (c) => IconButton(icon: const Icon(Icons.menu_rounded), onPressed: () => Scaffold.of(c).openDrawer()),
        ),
        titleSpacing: 0,
        title: Text(
          tr(_view.title),
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
        ),
        actions: [
          if (_s.offline)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: Tooltip(
                message: tr('Hors ligne : copie locale'),
                child: const Icon(Icons.cloud_off, color: YColors.warning),
              ),
            ),
          IconButton(tooltip: tr('Thème'), icon: const Icon(Icons.palette_outlined), onPressed: _pickTheme),
        ],
      ),
      body: Padding(padding: const EdgeInsets.fromLTRB(8, 0, 8, 8), child: _card(_messageList(compact: true))),
      floatingActionButton: _composeFab(),
    );
  }

  Widget _composeFab() => FloatingActionButton.extended(backgroundColor: _theme.accent, foregroundColor: Colors.white, onPressed: () => openComposer(context, _s), icon: const Icon(Icons.edit_outlined), label: Text(tr('Nouveau')));

  Widget _welcome() => Center(
    child: ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 560),
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(
                gradient: const LinearGradient(colors: [Color(0xFF2E6BE6), Color(0xFF5B5FC7)]),
                borderRadius: BorderRadius.circular(28),
              ),
              child: const Icon(Icons.mail_outline, color: Colors.white, size: 48),
            ),
            const SizedBox(height: 20),
            Text(
              tr('Votre boîte mail dans Yomara'),
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 10),
            Text(
              tr(
                'Microsoft 365, Gmail ou la boîte de votre hébergement : lisez, répondez, signez et classez vos e-mails sans quitter le logiciel, même hors ligne. '
                'Les mails de vos clients apparaissent sur leur fiche, et votre calendrier Outlook ou Google se synchronise avec l\'Agenda.',
              ),
              textAlign: TextAlign.center,
              style: TextStyle(color: Theme.of(context).hintColor, height: 1.5),
            ),
            const SizedBox(height: 22),
            FilledButton.icon(onPressed: () => addAccount(context, _s), icon: const Icon(Icons.add), label: Text(tr('Ajouter un compte'))),
            if (_s.canManage) TextButton.icon(onPressed: () => mailboxSettings(context), icon: const Icon(Icons.settings_outlined), label: Text(tr('Paramétrer Microsoft 365 / Google'))),
          ],
        ),
      ),
    ),
  );

  // ------------------------------------------------------------------ dossiers

  Map<String, dynamic> get _account => _s.accounts.firstWhere((a) => a['id'] == _accId, orElse: () => _s.accounts.first);

  Widget _folders({bool drawer = false}) {
    final p = _p;
    void go(_View v) {
      _select(v);
      if (drawer && Navigator.of(context).canPop()) Navigator.pop(context);
    }

    Widget pill(int n, bool sel) => Container(
      constraints: const BoxConstraints(minWidth: 24),
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(color: sel ? p.accent : p.soft, borderRadius: BorderRadius.circular(20)),
      child: Text(
        '$n',
        textAlign: TextAlign.center,
        style: TextStyle(color: sel ? Colors.white : p.muted, fontWeight: FontWeight.w700, fontSize: 11.5),
      ),
    );

    Widget item(Widget icon, String label, _View v, {int count = 0, Map<String, dynamic>? folder}) {
      final sel = _view.key == v.key;
      final depth = (folder?['depth'] as num?)?.toInt() ?? 0;
      if (folder != null && depth > 0) label = label.split(' › ').last;
      Widget tile() => Padding(
        padding: EdgeInsets.only(left: depth * 14.0, top: 1, bottom: 1),
        child: Material(
          color: sel ? p.selected : Colors.transparent,
          borderRadius: BorderRadius.circular(12),
          child: InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: () => go(v),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 9, 8, 9),
              child: Row(
                children: [
                  IconTheme.merge(
                    data: IconThemeData(size: 20, color: sel ? p.accent : p.muted),
                    child: icon,
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      tr(label),
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(fontSize: 14, fontWeight: sel ? FontWeight.w700 : FontWeight.w500, color: sel ? p.accent : p.ink),
                    ),
                  ),
                  if (count > 0) pill(count, sel),
                  if (folder != null && !_s.offline)
                    SizedBox(
                      width: 26,
                      height: 22,
                      child: PopupMenuButton<String>(
                        padding: EdgeInsets.zero,
                        iconSize: 17,
                        tooltip: tr('Gérer le dossier'),
                        icon: Icon(Icons.more_vert, color: p.muted.withValues(alpha: .6)),
                        onSelected: (a) => _folderAction(a, folder),
                        itemBuilder: (_) => [
                          PopupMenuItem(value: 'sub', child: Text(tr('Nouveau sous-dossier'))),
                          if (folder['custom'] == true || folder['role'] == 'other') ...[
                            PopupMenuItem(value: 'rename', child: Text(tr('Renommer'))),
                            PopupMenuItem(
                              value: 'delete',
                              child: Text(tr('Supprimer le dossier'), style: const TextStyle(color: YColors.danger)),
                            ),
                          ],
                        ],
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      );
      if (folder == null) return tile();
      // Glisser-déposer : on range une discussion en la lâchant sur un dossier du même compte
      return DragTarget<int>(
        onWillAcceptWithDetails: (d) => _s.local(d.data)?['account_id'] == folder['account_id'] && _s.local(d.data)?['folder_id'] != folder['id'],
        onAcceptWithDetails: (d) => _moveTo(d.data, folder['id'] as int),
        builder: (_, cand, _) => cand.isEmpty
            ? tile()
            : DecoratedBox(
                decoration: BoxDecoration(
                  color: p.accent.withValues(alpha: .14),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: p.accent, width: 1.4),
                ),
                child: tile(),
              ),
      );
    }

    Widget section(String title, {VoidCallback? onAdd, String? addTip}) => Padding(
      padding: const EdgeInsets.fromLTRB(12, 14, 2, 4),
      child: Row(
        children: [
          Expanded(
            child: Text(
              tr(title),
              style: TextStyle(color: p.muted, fontWeight: FontWeight.w600, fontSize: 13),
            ),
          ),
          if (onAdd != null)
            IconButton(
              visualDensity: VisualDensity.compact,
              iconSize: 20,
              tooltip: tr(addTip ?? 'Ajouter'),
              icon: Icon(Icons.add_rounded, color: p.muted),
              onPressed: onAdd,
            ),
        ],
      ),
    );

    IconData roleIcon(String r) => switch (r) {
      'inbox' => Icons.inbox_outlined,
      'sent' => Icons.send_outlined,
      'drafts' => Icons.description_outlined,
      'trash' => Icons.delete_outline,
      'junk' => Icons.report_gmailerrorred_outlined,
      'archive' => Icons.archive_outlined,
      _ => Icons.folder_rounded,
    };

    final acc = _account;
    final multi = _s.accounts.length > 1;
    final hasRole = {for (final f in _s.folders) '${f['role']}'};
    // Dossiers du compte : ceux que les vues unifiées ne couvrent pas déjà (tous si plusieurs comptes)
    final accFolders = _s.foldersOf(acc['id'] as int).where((f) => multi || !const ['inbox', 'sent', 'drafts', 'junk', 'trash'].contains(f['role'])).toList();
    final unreadAll = _s.folders.where((f) => !const ['sent', 'drafts', 'trash'].contains(f['role'])).fold<int>(0, (n, f) => n + ((f['unread'] as num?)?.toInt() ?? 0));
    final outboxN = _s.pending.length + _s.outbox.where((o) => o['status'] != 'sent').length;

    final body = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 14, 12, 10),
          child: ComposeButton(
            theme: _theme,
            onPressed: () {
              if (drawer && Navigator.of(context).canPop()) Navigator.pop(context);
              openComposer(context, _s);
            },
          ),
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
            children: [
              item(
                const Icon(Icons.mail_outline_rounded),
                'Toutes les réceptions',
                _View(role: 'inbox', title: tr('Boîte de réception')),
                count: _s.unreadIn('inbox'),
              ),
              item(const Icon(Icons.mark_email_unread_outlined), 'Non lus', _View(unread: true, title: tr('Non lus')), count: unreadAll),
              item(const Icon(Icons.star_border_rounded), 'Suivis', _View(flagged: true, title: tr('Suivis'))),
              item(const Icon(Icons.send_outlined), 'Tous les envoyés', _View(role: 'sent', title: tr('Éléments envoyés'))),
              if (hasRole.contains('drafts')) item(Icon(roleIcon('drafts')), 'Brouillons', _View(role: 'drafts', title: tr('Brouillons'))),
              if (hasRole.contains('junk'))
                item(
                  Icon(roleIcon('junk')),
                  'Spam',
                  _View(role: 'junk', title: tr('Spam')),
                  count: _s.unreadIn('junk'),
                ),
              if (hasRole.contains('trash')) item(Icon(roleIcon('trash')), 'Corbeille', _View(role: 'trash', title: tr('Corbeille'))),
              if (outboxN > 0)
                Material(
                  color: Colors.transparent,
                  borderRadius: BorderRadius.circular(12),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: _outboxDialog,
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(12, 9, 8, 9),
                      child: Row(
                        children: [
                          const Icon(Icons.outbox_outlined, size: 20, color: YColors.warning),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Text(
                              tr('Boîte d\'envoi'),
                              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w500, color: p.ink),
                            ),
                          ),
                          pill(outboxN, false),
                        ],
                      ),
                    ),
                  ),
                ),
              Padding(
                padding: const EdgeInsets.fromLTRB(8, 10, 8, 0),
                child: Divider(height: 1, color: p.line),
              ),
              // Compte affiché (sélecteur compact)
              Padding(
                padding: const EdgeInsets.fromLTRB(4, 10, 0, 0),
                child: Row(
                  children: [
                    Expanded(
                      child: PopupMenuButton<int>(
                        enabled: multi,
                        tooltip: tr(multi ? 'Changer de compte' : '${acc['provider_label'] ?? ''}'),
                        onSelected: (id) => setState(() => _accId = id),
                        itemBuilder: (_) => [
                          for (final a in _s.accounts)
                            PopupMenuItem(
                              value: a['id'] as int,
                              child: Row(
                                children: [
                                  AccountAvatar(account: a, radius: 11),
                                  const SizedBox(width: 10),
                                  Flexible(child: Text(tr('${a['display_name'] ?? a['email']}'), overflow: TextOverflow.ellipsis)),
                                ],
                              ),
                            ),
                        ],
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                          decoration: BoxDecoration(color: p.soft, borderRadius: BorderRadius.circular(20)),
                          child: Row(
                            children: [
                              AccountAvatar(account: acc, radius: 11),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Tooltip(
                                  message: '${acc['provider_label']}${acc['last_sync_at'] != null ? ' · synchro ${dateTimeFr(acc['last_sync_at'])}' : ''}',
                                  child: Text(
                                    tr('${acc['display_name'] ?? acc['email']}'),
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(fontWeight: FontWeight.w600, fontSize: 12.5, color: p.ink),
                                  ),
                                ),
                              ),
                              if (acc['status'] == 'error')
                                Tooltip(
                                  message: '${acc['error']}',
                                  child: const Icon(Icons.error_outline, size: 16, color: YColors.danger),
                                ),
                              if (acc['shared'] == true)
                                Tooltip(
                                  message: tr('Boîte partagée'),
                                  child: Icon(Icons.group_outlined, size: 15, color: p.muted),
                                ),
                              if (multi) Icon(Icons.expand_more_rounded, size: 18, color: p.muted),
                            ],
                          ),
                        ),
                      ),
                    ),
                    IconButton(
                      visualDensity: VisualDensity.compact,
                      iconSize: 18,
                      tooltip: tr('Réglages du compte'),
                      icon: Icon(Icons.more_horiz, color: p.muted),
                      onPressed: () => editAccount(context, _s, acc),
                    ),
                  ],
                ),
              ),
              section('Dossiers', addTip: 'Nouveau dossier', onAdd: _s.offline ? null : () => _newFolder(acc['id'] as int)),
              for (final f in accFolders)
                item(
                  f['role'] == 'other' || f['custom'] == true ? Icon(Icons.folder_rounded, color: MailPalette.folderColor('${f['name']}')) : Icon(roleIcon('${f['role']}')),
                  '${f['name']}',
                  _View(folderId: f['id'] as int, title: '${f['name']}'),
                  count: f['role'] == 'sent' || f['role'] == 'trash' || f['role'] == 'drafts' ? 0 : ((f['unread'] as num?)?.toInt() ?? 0),
                  folder: f,
                ),
              if (accFolders.isEmpty)
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 0, 12, 6),
                  child: Text(tr('Créez un dossier avec « + », puis glissez-y vos discussions.'), style: TextStyle(color: p.muted, fontSize: 12)),
                ),
            ],
          ),
        ),
        Divider(height: 1, color: p.line),
        Padding(
          padding: const EdgeInsets.fromLTRB(8, 6, 8, 6),
          child: Row(
            children: [
              IconButton(
                tooltip: tr('Ajouter un compte'),
                onPressed: () => addAccount(context, _s),
                icon: Icon(Icons.person_add_alt, color: p.muted),
              ),
              IconButton(
                tooltip: tr('Carnet d\'adresses de l\'entreprise'),
                onPressed: _directory,
                icon: Icon(Icons.people_alt_outlined, color: p.muted),
              ),
              IconButton(
                tooltip: tr('Signatures et modèles'),
                onPressed: () => manageSignatures(context, _s),
                icon: Icon(Icons.draw_outlined, color: p.muted),
              ),
              if (_s.canManage)
                IconButton(
                  tooltip: tr('Paramètres Microsoft 365 / Google'),
                  onPressed: () => mailboxSettings(context),
                  icon: Icon(Icons.settings_outlined, color: p.muted),
                ),
              const Spacer(),
              if (_s.offline)
                Tooltip(
                  message: tr('Hors ligne : copie locale'),
                  child: const Icon(Icons.cloud_off, color: YColors.warning, size: 20),
                ),
            ],
          ),
        ),
      ],
    );
    return drawer ? body : _card(body);
  }

  // ------------------------------------------------------------------ rangement

  /// Range toute la discussion (messages du même fil dans le même dossier).
  Future<void> _moveTo(int id, int folderId) async {
    final f = _s.folder(folderId);
    await _do(() async {
      final n = await _s.moveConversation(id, folderId);
      if (_openId != null && _s.local(_openId!)?['folder_id'] == folderId) _afterRemove(_openId!);
      if (mounted) toast(context, tr(n > 1 ? 'Discussion ($n messages) rangée dans « ${f?['name']} ».' : 'Rangé dans « ${f?['name']} ».'));
    });
  }

  Future<String?> _askName(String title, {String initial = '', String ok = 'Créer'}) {
    final c = TextEditingController(text: initial);
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr(title)),
        content: SizedBox(
          width: 360,
          child: TextField(
            controller: c,
            autofocus: true,
            maxLength: 80,
            decoration: InputDecoration(labelText: tr('Nom du dossier'), hintText: tr('Ex. Clients, Factures, Projets 2026')),
            onSubmitted: (v) => Navigator.pop(ctx, v.trim()),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr(ok))),
        ],
      ),
    );
  }

  Future<void> _newFolder(int accountId, {Map<String, dynamic>? parent}) async {
    final name = await _askName(parent == null ? 'Nouveau dossier' : 'Nouveau sous-dossier de « ${parent['name']} »');
    if (name == null || name.isEmpty) return;
    await _do(() async {
      final f = await _s.createFolder(accountId, name, parentId: parent?['id'] as int?);
      _select(_View(folderId: f['id'] as int, title: '${f['name']}'));
    }, 'Dossier créé. Glissez-y vos discussions.');
  }

  Future<void> _folderAction(String a, Map<String, dynamic> f) async {
    switch (a) {
      case 'sub':
        await _newFolder(f['account_id'] as int, parent: f);
      case 'rename':
        final name = await _askName('Renommer le dossier', initial: '${f['name']}'.split(' › ').last, ok: 'Renommer');
        if (name == null || name.isEmpty) return;
        await _do(() => _s.renameFolder(f['id'] as int, name), 'Dossier renommé.');
      case 'delete':
        if (!await confirm(context, tr('Supprimer « ${f['name']} » ?'), tr('Le dossier et les messages qu\'il contient seront supprimés de la boîte mail (aussi sur le serveur).'), ok: 'Supprimer', danger: true)) return;
        await _do(() async {
          await _s.deleteFolder(f['id'] as int);
          if (_view.folderId == f['id']) _select(_View(role: 'inbox', title: tr('Boîte de réception')));
        }, 'Dossier supprimé.');
    }
  }

  static Color _color(dynamic hex) {
    final h = '${hex ?? '#2E6BE6'}'.replaceAll('#', '');
    return Color(int.tryParse('FF${h.padLeft(6, '0')}', radix: 16) ?? 0xFF2E6BE6);
  }

  Future<void> _outboxDialog() async {
    await showDialog(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Boîte d\'envoi')),
          content: SizedBox(
            width: 560,
            child: ListView(
              shrinkWrap: true,
              children: [
                for (final p in _s.pending)
                  ListTile(
                    leading: const Icon(Icons.cloud_off, color: YColors.warning),
                    title: Text(tr('${p['subject'] ?? '(sans objet)'}')),
                    subtitle: Text(tr('En attente de connexion')),
                    trailing: TextButton(
                      onPressed: () async {
                        await _s.cancelPending('${p['id']}');
                        set(() {});
                      },
                      child: Text(tr('Annuler')),
                    ),
                  ),
                for (final o in _s.outbox.where((o) => o['status'] != 'sent'))
                  ListTile(
                    leading: Icon(o['status'] == 'failed' ? Icons.error_outline : Icons.schedule_send, color: o['status'] == 'failed' ? YColors.danger : YColors.blue),
                    title: Text(tr('${o['subject'] ?? '(sans objet)'}')),
                    subtitle: Text(tr(o['status'] == 'failed' ? 'Échec : ${o['error'] ?? ''}' : (o['send_at'] != null ? 'Programmé le ${dateTimeFr(o['send_at'])}' : 'Envoi en cours…'))),
                    trailing: Wrap(
                      children: [
                        if (o['status'] == 'failed')
                          IconButton(
                            tooltip: tr('Réessayer'),
                            icon: const Icon(Icons.replay),
                            onPressed: () => _do(() async {
                              await _s.api.post('mailbox/outbox/${o['id']}/retry');
                              await _s.reloadIndex();
                              set(() {});
                            }),
                          ),
                        IconButton(
                          tooltip: tr('Annuler l\'envoi'),
                          icon: const Icon(Icons.cancel_outlined),
                          onPressed: () => _do(() async {
                            await _s.api.delete('mailbox/outbox/${o['id']}');
                            await _s.reloadIndex();
                            set(() {});
                          }),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ liste

  Widget _messageList({bool compact = false}) {
    final p = _p;
    final list = _list;
    final groups = <String, List<Map<String, dynamic>>>{};
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    for (final m in list) {
      final d = DateTime.tryParse('${m['date']}') ?? now;
      final day = DateTime(d.year, d.month, d.day);
      final diff = today.difference(day).inDays;
      final g = diff <= 0
          ? 'Aujourd\'hui'
          : diff == 1
          ? 'Hier'
          : diff < 7
          ? 'Cette semaine'
          : (d.year == now.year && d.month == now.month ? 'Plus tôt ce mois-ci' : DateFormat('MMMM y', 'fr_FR').format(d));
      groups.putIfAbsent(g, () => []).add(m);
    }
    final unreadN = list.where((m) => m['seen'] != true).length;
    const extra = {'unread': ('Non lus', Icons.mark_email_unread_outlined), 'flagged': ('Suivis', Icons.star_border_rounded), 'attachments': ('Avec pièces jointes', Icons.attach_file)};

    Widget seg(String key, String label, {Widget? icon}) {
      final sel = _filter == key;
      return Expanded(
        child: Padding(
          padding: const EdgeInsets.only(right: 6),
          child: Material(
            color: sel ? p.selected : Colors.transparent,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
              side: BorderSide(color: sel ? Colors.transparent : p.line),
            ),
            child: InkWell(
              borderRadius: BorderRadius.circular(10),
              onTap: () => setState(() {
                _filter = key;
                _older.clear();
              }),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    if (icon != null) ...[icon, const SizedBox(width: 5)],
                    Flexible(
                      child: Text(
                        tr(label),
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(fontSize: 13, fontWeight: sel ? FontWeight.w700 : FontWeight.w500, color: sel ? p.accent : p.ink),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(18, 14, 8, 6),
          child: Row(
            children: [
              Expanded(
                child: Row(
                  children: [
                    Flexible(
                      child: Text(
                        tr(compact ? '${list.length} message(s)' : _view.title),
                        style: TextStyle(fontSize: compact ? 16 : 20, fontWeight: FontWeight.w800, color: p.ink),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    if (!compact && unreadN > 0) ...[
                      const SizedBox(width: 8),
                      Tooltip(
                        message: tr('$unreadN non lu(s)'),
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 2),
                          decoration: BoxDecoration(color: p.selected, borderRadius: BorderRadius.circular(20)),
                          child: Text(
                            '$unreadN',
                            style: TextStyle(color: p.accent, fontWeight: FontWeight.w700, fontSize: 13),
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              IconButton(
                tooltip: tr('Actualiser'),
                onPressed: _s.syncing ? null : () => _s.refresh(pull: true),
                icon: _s.syncing ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Icon(Icons.refresh_rounded, color: p.muted),
              ),
              IconButton(
                tooltip: trn(_selecting ? 'Annuler la sélection' : 'Sélectionner'),
                onPressed: () => setState(() {
                  if (_selecting) {
                    _selected.clear();
                    _selectMode = false;
                  } else {
                    _selectMode = true;
                  }
                }),
                icon: Icon(_selecting ? Icons.close : Icons.checklist_rounded, color: _selecting ? p.accent : p.muted),
              ),
            ],
          ),
        ),
        if (compact) Padding(padding: const EdgeInsets.fromLTRB(12, 0, 12, 6), child: _searchField()),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 2, 4, 6),
          child: Row(
            children: [
              seg('all', 'Tous'),
              seg('focused', 'Prioritaires', icon: const Icon(Icons.auto_awesome, size: 15, color: Color(0xFFF59E0B))),
              seg('other', 'Autres'),
              PopupMenuButton<String>(
                tooltip: tr('Filtrer'),
                icon: Icon(Icons.filter_list_rounded, color: extra.containsKey(_filter) ? p.accent : p.muted),
                onSelected: (k) => setState(() {
                  _filter = _filter == k ? 'all' : k;
                  _older.clear();
                }),
                itemBuilder: (_) => [
                  for (final e in extra.entries)
                    CheckedPopupMenuItem(
                      value: e.key,
                      checked: _filter == e.key,
                      child: Row(children: [Icon(e.value.$2, size: 18), const SizedBox(width: 10), Text(tr(e.value.$1))]),
                    ),
                ],
              ),
            ],
          ),
        ),
        if (extra.containsKey(_filter))
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 4),
            child: Align(
              alignment: Alignment.centerLeft,
              child: InputChip(
                visualDensity: VisualDensity.compact,
                avatar: Icon(extra[_filter]!.$2, size: 16, color: p.accent),
                label: Text(tr(extra[_filter]!.$1)),
                onDeleted: () => setState(() {
                  _filter = 'all';
                  _older.clear();
                }),
              ),
            ),
          ),
        if (_selecting) _bulkBar(list),
        if (_s.error != null)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: Text(tr(_s.error!), style: const TextStyle(color: YColors.danger, fontSize: 12)),
          ),
        Expanded(
          child: list.isEmpty
              ? EmptyState(
                  icon: Icons.mark_email_read_outlined,
                  title: _q.isNotEmpty ? 'Aucun résultat' : 'Aucun message',
                  message: _q.isNotEmpty ? 'Essayez d\'autres mots, ou cherchez dans les messages plus anciens.' : null,
                  action: _q.isNotEmpty ? OutlinedButton(onPressed: _loadOlder, child: Text(tr('Chercher sur le serveur'))) : null,
                )
              : RefreshIndicator(
                  onRefresh: () => _s.refresh(pull: true),
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    children: [
                      for (final g in groups.entries) ...[
                        Padding(
                          padding: const EdgeInsets.fromLTRB(14, 12, 14, 6),
                          child: Text(
                            tr(g.key),
                            style: TextStyle(color: p.ink.withValues(alpha: .75), fontWeight: FontWeight.w600, fontSize: 13.5),
                          ),
                        ),
                        for (final (i, m) in g.value.indexed) ...[if (i > 0) Divider(height: 1, indent: 64, endIndent: 10, color: p.line), compact ? _swipeRow(m) : _row(m)],
                      ],
                      if (!_olderDone)
                        Padding(
                          padding: const EdgeInsets.all(12),
                          child: Center(
                            child: TextButton.icon(onPressed: _loadOlder, icon: const Icon(Icons.history), label: Text(tr('Messages plus anciens'))),
                          ),
                        ),
                      const SizedBox(height: 80),
                    ],
                  ),
                ),
        ),
      ],
    );
  }

  bool _selectMode = false;

  bool get _selecting => _selectMode || _selected.isNotEmpty;

  Widget _bulkBar(List<Map<String, dynamic>> list) {
    Future<void> run(String action) async {
      final ids = _selected.toList();
      try {
        final r = await _s.api.post('mailbox/messages/bulk', {'ids': ids, 'action': action});
        if ((r['errors'] as List?)?.isNotEmpty == true && mounted) toast(context, '${(r['errors'] as List).first}', error: true);
      } catch (e) {
        if (mounted) toast(context, errorText(e), error: true);
      }
      setState(() {
        _selected.clear();
        _selectMode = false;
      });
      await _s.refresh();
    }

    return Container(
      margin: const EdgeInsets.fromLTRB(12, 4, 12, 4),
      padding: const EdgeInsets.symmetric(horizontal: 6),
      decoration: BoxDecoration(color: _p.selected, borderRadius: BorderRadius.circular(12)),
      child: Row(
        children: [
          Checkbox(value: _selected.length == list.length && list.isNotEmpty, tristate: false, onChanged: (v) => setState(() => v == true ? _selected.addAll(list.map((m) => m['id'] as int)) : _selected.clear())),
          Text(tr('${_selected.length} sélectionné(s)'), style: const TextStyle(fontWeight: FontWeight.w600)),
          const Spacer(),
          IconButton(tooltip: tr('Marquer comme lu'), onPressed: _selected.isEmpty ? null : () => run('read'), icon: const Icon(Icons.mark_email_read_outlined, size: 20)),
          IconButton(tooltip: tr('Marquer comme non lu'), onPressed: _selected.isEmpty ? null : () => run('unread'), icon: const Icon(Icons.mark_email_unread_outlined, size: 20)),
          IconButton(tooltip: tr('Archiver'), onPressed: _selected.isEmpty ? null : () => run('archive'), icon: const Icon(Icons.archive_outlined, size: 20)),
          IconButton(tooltip: tr('Supprimer'), onPressed: _selected.isEmpty ? null : () => run('delete'), icon: const Icon(Icons.delete_outline, size: 20)),
        ],
      ),
    );
  }

  /// Balayer vers la droite : archiver ; vers la gauche : supprimer (téléphone).
  Widget _swipeRow(Map<String, dynamic> m) => Dismissible(
    key: ValueKey('mail-${m['id']}'),
    background: Container(
      color: const Color(0xFF12A150),
      alignment: Alignment.centerLeft,
      padding: const EdgeInsets.only(left: 22),
      child: Row(
        children: [
          Icon(Icons.archive_outlined, color: Colors.white),
          SizedBox(width: 8),
          Text(
            tr('Archiver'),
            style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    ),
    secondaryBackground: Container(
      color: YColors.danger,
      alignment: Alignment.centerRight,
      padding: const EdgeInsets.only(right: 22),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.end,
        children: [
          Text(
            tr('Supprimer'),
            style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
          ),
          SizedBox(width: 8),
          Icon(Icons.delete_outline, color: Colors.white),
        ],
      ),
    ),
    confirmDismiss: (dir) async {
      try {
        dir == DismissDirection.startToEnd ? await _s.archive(m['id'] as int) : await _s.remove(m['id'] as int);
        return true;
      } catch (e) {
        if (mounted) toast(context, errorText(e), error: true);
        return false;
      }
    },
    child: _row(m),
  );

  Future<void> _toggleStar(Map<String, dynamic> m) => _do(() async {
    final id = m['id'] as int;
    await _s.setFlags(id, flagged: m['flagged'] != true);
    if (mounted && _openId == id) setState(() => _open = {...?_open, ...?_s.local(id)});
  });

  Widget _row(Map<String, dynamic> m) {
    final p = _p;
    final sel = _openId == m['id'];
    final unread = m['seen'] != true;
    final flagged = m['flagged'] == true;
    final sent = _s.folder(m['folder_id'])?['role'] == 'sent';
    final who = sent ? 'À : ${(m['to'] as List? ?? const []).map((t) => (t['name'] ?? '').toString().isNotEmpty ? t['name'] : t['email']).join(', ')}' : '${(m['from_name'] ?? '').toString().isNotEmpty ? m['from_name'] : m['from_email'] ?? ''}';
    final acc = _s.account(m['account_id']);
    final checked = _selected.contains(m['id']);
    final tileW = Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Material(
        color: sel || checked ? p.selected : Colors.transparent,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () {
            if (_selecting) {
              setState(() => checked ? _selected.remove(m['id']) : _selected.add(m['id'] as int));
            } else {
              _openMessage(m);
            }
          },
          onLongPress: () => setState(() => _selected.add(m['id'] as int)),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(4, 10, 4, 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 16,
                  height: 38,
                  child: Center(
                    child: unread
                        ? Container(
                            width: 8,
                            height: 8,
                            decoration: BoxDecoration(color: p.accent, shape: BoxShape.circle),
                          )
                        : null,
                  ),
                ),
                _selecting
                    ? SizedBox(
                        width: 38,
                        height: 38,
                        child: Checkbox(visualDensity: VisualDensity.compact, value: checked, onChanged: (v) => setState(() => v == true ? _selected.add(m['id'] as int) : _selected.remove(m['id']))),
                      )
                    : _Avatar(name: sent ? '${(m['to'] as List? ?? const []).firstOrNull?['email'] ?? '?'}' : who, color: _color(acc?['color']), size: 38),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              tr(who),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(fontSize: 14, fontWeight: unread ? FontWeight.w700 : FontWeight.w600, color: p.ink),
                            ),
                          ),
                          if (m['has_attachments'] == true) Icon(Icons.attach_file, size: 14, color: p.muted),
                          if (m['answered'] == true) Icon(Icons.reply, size: 14, color: p.muted),
                          const SizedBox(width: 4),
                          Text(
                            tr(_shortDate('${m['date']}')),
                            style: TextStyle(fontSize: 12, color: unread ? p.accent : p.muted, fontWeight: unread ? FontWeight.w600 : FontWeight.w400),
                          ),
                        ],
                      ),
                      const SizedBox(height: 2),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  tr('${m['subject'] ?? '(sans objet)'}'),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(fontWeight: unread ? FontWeight.w700 : FontWeight.w500, color: unread ? (p.dark ? const Color(0xFF8AB4FF) : const Color(0xFF1D4ED8)) : p.ink, fontSize: 13),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  tr('${m['snippet'] ?? ''}'),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(color: p.muted, fontSize: 12.5),
                                ),
                              ],
                            ),
                          ),
                          SizedBox(
                            width: 30,
                            height: 26,
                            child: IconButton(
                              padding: EdgeInsets.zero,
                              iconSize: 20,
                              tooltip: trn(flagged ? 'Retirer le suivi' : 'Suivre'),
                              onPressed: () => _toggleStar(m),
                              icon: Icon(flagged ? Icons.star_rounded : Icons.star_border_rounded, color: flagged ? const Color(0xFFF5B301) : p.muted.withValues(alpha: .7)),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    if (_selecting || MediaQuery.sizeOf(context).width < 700) return tileW;
    // glisser la discussion vers un dossier de la colonne de gauche
    return Draggable<int>(
      data: m['id'] as int,
      dragAnchorStrategy: pointerDragAnchorStrategy,
      feedback: Material(
        elevation: 6,
        borderRadius: BorderRadius.circular(10),
        color: p.accent,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.drive_file_move_outline, color: Colors.white, size: 18),
              const SizedBox(width: 8),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 260),
                child: Text(
                  tr('${m['subject'] ?? '(sans objet)'}'),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 13),
                ),
              ),
            ],
          ),
        ),
      ),
      childWhenDragging: Opacity(opacity: .4, child: tileW),
      child: tileW,
    );
  }

  static String _shortDate(String s) {
    final d = DateTime.tryParse(s);
    if (d == null) return '';
    final now = DateTime.now();
    if (d.year == now.year && d.month == now.month && d.day == now.day) return DateFormat('HH:mm').format(d);
    if (now.difference(d).inDays < 7) return DateFormat('EEE HH:mm', 'fr_FR').format(d);
    return DateFormat(d.year == now.year ? 'd MMM' : 'dd/MM/yy', 'fr_FR').format(d);
  }

  // ------------------------------------------------------------------ lecture

  Widget _emptyReader({bool showFolders = false}) {
    final p = _p;
    return Container(
      color: p.dark ? Colors.transparent : const Color(0xFFF7FAFD),
      alignment: Alignment.center,
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 96,
            height: 96,
            decoration: BoxDecoration(shape: BoxShape.circle, color: p.selected),
            child: Icon(Icons.mark_email_unread_outlined, size: 44, color: p.accent),
          ),
          const SizedBox(height: 16),
          Text(
            tr('Sélectionnez un message pour le lire'),
            textAlign: TextAlign.center,
            style: TextStyle(color: p.ink, fontSize: 16, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 6),
          Text(tr('Rien n\'est sélectionné'), style: TextStyle(color: p.muted, fontSize: 13)),
          if (showFolders) ...[
            const SizedBox(height: 18),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              alignment: WrapAlignment.center,
              children: [
                for (final a in _s.accounts)
                  for (final f in _s.foldersOf(a['id'] as int).where((f) => f['role'] != 'other'))
                    ActionChip(
                      label: Text(tr('${f['name']}${_s.accounts.length > 1 ? ' · ${a['email']}' : ''}')),
                      onPressed: () => _select(_View(folderId: f['id'] as int, title: '${f['name']}')),
                    ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  /// Menu « ⋯ » du message : suivi, réponses, et création d'éléments (réunion, devis, ticket, opportunité CRM…).
  List<PopupMenuEntry<String>> _moreItems(Map<String, dynamic> m) => [
    PopupMenuItem(
      value: 'flag',
      child: ListTile(dense: true, leading: Icon(m['flagged'] == true ? Icons.star_rounded : Icons.star_border_rounded), title: Text(tr(m['flagged'] == true ? 'Retirer le suivi' : 'Suivre'))),
    ),
    PopupMenuItem(
      value: 'reply_all',
      child: ListTile(dense: true, leading: const Icon(Icons.reply_all), title: Text(tr('Répondre à tous'))),
    ),
    PopupMenuItem(
      value: 'forward',
      child: ListTile(dense: true, leading: const Icon(Icons.forward), title: Text(tr('Transférer'))),
    ),
    const PopupMenuDivider(),
    PopupMenuItem(
      enabled: false,
      height: 28,
      child: Text(
        tr('Créer à partir de ce message'),
        style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor, fontWeight: FontWeight.w700),
      ),
    ),
    for (final a in MailActionsBar.allActions(m))
      PopupMenuItem(
        value: 'act:$a',
        child: ListTile(dense: true, leading: Icon(MailActionsBar.icons[a]), title: Text(tr(MailActionsBar.labels[a]!))),
      ),
  ];

  void _more(Map<String, dynamic> m, String v) {
    if (v == 'flag') {
      _toggleStar(m);
    } else if (v == 'reply_all' || v == 'forward') {
      openComposer(context, _s, mode: v, original: m);
    } else if (v.startsWith('act:')) {
      _mailAction(m, v.substring(4));
    }
  }

  Widget _reader({bool back = false}) {
    final m = _open;
    if (m == null) return const SizedBox();
    final p = _p;
    final atts = [
      for (final a in (m['attachments'] as List? ?? const []))
        if (a['inline'] != true) asMap(a),
    ];
    final thread = _s.thread(m).where((x) => x['id'] != m['id']).toList();
    final folder = _s.folder(m['folder_id']);
    final accountFolders = _s.foldersOf(m['account_id'] as int).where((f) => f['id'] != m['folder_id']).toList();
    final partner = m['partner'] as Map?;
    final dark = p.dark;
    final html = (m['html'] as String?) ?? '<div style="white-space:pre-wrap">${const _Esc().convert('${m['text'] ?? m['snippet'] ?? ''}')}</div>';
    final list = _list;
    final idx = list.indexWhere((x) => x['id'] == m['id']);
    final multi = _selected.isNotEmpty;
    final flagged = m['flagged'] == true;
    String names(List? l) => (l ?? const []).map((t) => (t['name'] ?? '').toString().isNotEmpty ? t['name'] : t['email']).join(', ');
    final acc = _s.account(m['account_id']);
    final to = (m['to'] as List? ?? const []);
    final toMe = to.length == 1 && '${to.first['email']}'.toLowerCase() == '${acc?['email'] ?? ''}'.toLowerCase();

    Widget tb(IconData icon, String tip, VoidCallback? onTap) => IconButton(
      tooltip: tr(tip),
      onPressed: onTap,
      icon: Icon(icon, size: 21, color: p.ink.withValues(alpha: .8)),
    );
    Widget chip(String label, Color color, {IconData? icon}) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: dark ? .2 : .1),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[Icon(icon, size: 13, color: color), const SizedBox(width: 4)],
          Text(
            tr(label),
            style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
    Widget pillBtn(IconData icon, String label, VoidCallback onTap) => OutlinedButton.icon(
      onPressed: onTap,
      style: OutlinedButton.styleFrom(
        foregroundColor: p.ink,
        side: BorderSide(color: p.line, width: 1.2),
        shape: const StadiumBorder(),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        textStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
      ),
      icon: Icon(icon, size: 19),
      label: Text(tr(label)),
    );

    return LayoutBuilder(
      builder: (_, box) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Barre d'outils : agit sur le message ouvert, ou sur la sélection multiple de la liste
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 8, 10, 4),
            child: Row(
              children: [
                tb(Icons.arrow_back_rounded, 'Fermer', () => setState(() => (_openId = null, _open = null))),
                const SizedBox(width: 4),
                tb(Icons.archive_outlined, 'Archiver', () => _bulk('archive')),
                tb(Icons.delete_outline_rounded, 'Supprimer', () => _bulk('delete')),
                tb(Icons.mark_email_unread_outlined, multi ? 'Marquer comme non lu' : (m['seen'] == false ? 'Marquer comme lu' : 'Marquer comme non lu'), () => _bulk('unread')),
                PopupMenuButton<int>(
                  enabled: !multi && accountFolders.isNotEmpty,
                  tooltip: tr('Déplacer'),
                  icon: Icon(Icons.label_outline_rounded, size: 21, color: p.ink.withValues(alpha: multi ? .35 : .8)),
                  onSelected: (fid) => _bulk('move', folderId: fid),
                  itemBuilder: (_) => [for (final f in accountFolders) PopupMenuItem(value: f['id'] as int, child: Text(tr('${f['name']}')))],
                ),
                if (multi)
                  tb(Icons.star_border_rounded, 'Suivre', () => _bulk('flag'))
                else
                  PopupMenuButton<String>(
                    tooltip: tr('Plus d\'actions'),
                    icon: Icon(Icons.more_horiz_rounded, color: p.ink.withValues(alpha: .8)),
                    onSelected: (v) => _more(m, v),
                    itemBuilder: (_) => _moreItems(m),
                  ),
                if (multi) Padding(padding: const EdgeInsets.only(left: 6), child: chip('${_selected.length} sélectionné(s)', p.accent)),
                const Spacer(),
                Container(
                  decoration: BoxDecoration(
                    border: Border.all(color: p.line),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Message précédent'), onPressed: idx > 0 ? () => _openMessage(list[idx - 1]) : null, icon: const Icon(Icons.chevron_left_rounded)),
                      IconButton(visualDensity: VisualDensity.compact, tooltip: tr('Message suivant'), onPressed: idx >= 0 && idx + 1 < list.length ? () => _openMessage(list[idx + 1]) : null, icon: const Icon(Icons.chevron_right_rounded)),
                    ],
                  ),
                ),
              ],
            ),
          ),
          if (_openLoading) const LinearProgressIndicator(minHeight: 2) else const SizedBox(height: 2),
          ConstrainedBox(
            constraints: BoxConstraints(maxHeight: box.maxHeight * (box.maxWidth < 560 ? .42 : .5)),
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(22, 10, 22, 6),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Wrap(
                          spacing: 8,
                          runSpacing: 6,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            SelectableText(
                              tr('${m['subject'] ?? '(sans objet)'}'),
                              style: TextStyle(fontSize: 21, fontWeight: FontWeight.w800, color: p.ink, height: 1.25),
                            ),
                            if (folder?['role'] == 'inbox' && _s.isFocused(m)) chip('Prioritaire', const Color(0xFFE11D48), icon: Icons.bolt_rounded),
                            if (folder != null) chip('${folder['name']}', p.accent, icon: Icons.folder_outlined),
                            if (m['has_attachments'] == true) chip('Pièces jointes', p.muted, icon: Icons.attach_file),
                          ],
                        ),
                        const SizedBox(height: 14),
                        Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            _Avatar(name: '${(m['from_name'] ?? '').toString().isNotEmpty ? m['from_name'] : m['from_email']}', color: _color(acc?['color']), size: 44),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Tooltip(
                                    message: '${m['from_email'] ?? ''}',
                                    child: Text.rich(
                                      TextSpan(
                                        children: [
                                          TextSpan(
                                            text: '${m['from_name'] ?? ''} ',
                                            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15, color: p.ink),
                                          ),
                                          TextSpan(
                                            text: '<${m['from_email'] ?? ''}>',
                                            style: TextStyle(color: p.muted, fontSize: 13),
                                          ),
                                        ],
                                      ),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                    ),
                                  ),
                                  InkWell(
                                    borderRadius: BorderRadius.circular(6),
                                    onTap: () => setState(() => _showRcpt = !_showRcpt),
                                    child: Padding(
                                      padding: const EdgeInsets.symmetric(vertical: 2),
                                      child: Row(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Flexible(
                                            child: Text(
                                              tr(_showRcpt ? 'À : ${names(to)}${(m['cc'] as List? ?? const []).isNotEmpty ? '  ·  Cc : ${names(m['cc'] as List)}' : ''}' : (toMe ? 'à moi' : 'à ${names(to)}')),
                                              maxLines: _showRcpt ? 4 : 1,
                                              overflow: TextOverflow.ellipsis,
                                              style: TextStyle(color: p.muted, fontSize: 12.5),
                                            ),
                                          ),
                                          Icon(_showRcpt ? Icons.expand_less_rounded : Icons.expand_more_rounded, size: 18, color: p.muted),
                                        ],
                                      ),
                                    ),
                                  ),
                                  if (box.maxWidth < 560) Text(tr(dateTimeFr(m['date'])), style: TextStyle(color: p.muted, fontSize: 12)),
                                ],
                              ),
                            ),
                            if (box.maxWidth >= 560) ...[
                              Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: Text(tr(dateTimeFr(m['date'])), style: TextStyle(color: p.muted, fontSize: 12.5)),
                              ),
                              const SizedBox(width: 4),
                            ],
                            IconButton(
                              visualDensity: VisualDensity.compact,
                              tooltip: trn(flagged ? 'Retirer le suivi' : 'Suivre'),
                              onPressed: () => _toggleStar(m),
                              icon: Icon(flagged ? Icons.star_rounded : Icons.star_border_rounded, color: flagged ? const Color(0xFFF5B301) : p.muted),
                            ),
                            if (box.maxWidth >= 560)
                              IconButton(
                                visualDensity: VisualDensity.compact,
                                tooltip: tr('Répondre'),
                                onPressed: () => openComposer(context, _s, mode: 'reply', original: m),
                                icon: Icon(Icons.reply_rounded, color: p.muted),
                              ),
                            PopupMenuButton<String>(
                              tooltip: tr('Plus d\'actions'),
                              icon: Icon(Icons.more_vert_rounded, color: p.muted),
                              onSelected: (v) => _more(m, v),
                              itemBuilder: (_) => _moreItems(m),
                            ),
                          ],
                        ),
                        if (partner != null || m['warning'] != null || atts.isNotEmpty) const SizedBox(height: 10),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            if (partner != null)
                              Chip(
                                avatar: Icon(Icons.business, size: 16, color: p.accent),
                                label: Text(tr('Fiche : ${partner['name']}')),
                                visualDensity: VisualDensity.compact,
                                shape: const StadiumBorder(),
                              ),
                            for (final a in atts)
                              ActionChip(
                                shape: const StadiumBorder(),
                                visualDensity: VisualDensity.compact,
                                avatar: Icon(_attIcon('${a['mime']}'), size: 18, color: p.accent),
                                label: Text(tr('${a['name']} · ${fileSize(a['size'])}')),
                                onPressed: a['url'] == null ? null : () => safeLaunchUrl(Uri.parse('${a['url']}'), mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank'),
                              ),
                          ],
                        ),
                        if (m['warning'] != null)
                          Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: Text(tr('${m['warning']}'), style: const TextStyle(color: YColors.warning)),
                          ),
                      ],
                    ),
                  ),
                  MailActionsBar(
                    message: m,
                    onAction: (a, [start]) => _mailAction(m, a, start),
                    onAcceptInvite: () => _do(() async {
                      final r = await _s.api.post('mailbox/messages/${m['id']}/accept-invite');
                      if (mounted) toast(context, '${r['message']}');
                    }),
                  ),
                  MailAssistBar(
                    store: _s,
                    message: m,
                    onReply: (body) => openComposer(context, _s, mode: 'reply', original: m, bodyText: '$body\n\n'),
                  ),
                ],
              ),
            ),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
              child: Container(
                clipBehavior: Clip.antiAlias,
                decoration: BoxDecoration(
                  color: dark ? const Color(0xFF151B26) : Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: p.line),
                ),
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                child: MailHtml(key: ValueKey('mail-${m['id']}-${(m['html'] ?? '').hashCode}'), html: html, dark: dark),
              ),
            ),
          ),
          if (thread.isNotEmpty)
            Container(
              constraints: BoxConstraints(maxHeight: box.maxWidth < 560 ? 96 : 150),
              margin: const EdgeInsets.symmetric(horizontal: 20),
              decoration: BoxDecoration(color: p.soft, borderRadius: BorderRadius.circular(12)),
              child: ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.symmetric(vertical: 4),
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(14, 4, 14, 2),
                    child: Text(
                      tr('Conversation (${thread.length + 1} messages)'),
                      style: TextStyle(color: p.muted, fontWeight: FontWeight.w700, fontSize: 12),
                    ),
                  ),
                  for (final t in thread)
                    ListTile(
                      dense: true,
                      leading: Icon(_s.folder(t['folder_id'])?['role'] == 'sent' ? Icons.call_made : Icons.call_received, size: 16),
                      title: Text(tr('${(t['from_name'] ?? '').toString().isNotEmpty ? t['from_name'] : t['from_email']} — ${t['snippet'] ?? ''}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                      trailing: Text(tr(_shortDate('${t['date']}')), style: TextStyle(color: p.muted, fontSize: 11.5)),
                      onTap: () => _openMessage(t),
                    ),
                ],
              ),
            ),
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 10, 20, 16),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                if (box.maxWidth < 560) ...[
                  // Téléphone : boutons ronds (libellé en info-bulle) pour garder la place au message
                  for (final (icon, label, mode) in const [(Icons.reply_rounded, 'Répondre', 'reply'), (Icons.reply_all_rounded, 'Répondre à tous', 'reply_all'), (Icons.forward_rounded, 'Transférer', 'forward')])
                    IconButton.outlined(
                      tooltip: tr(label),
                      style: IconButton.styleFrom(
                        side: BorderSide(color: p.line, width: 1.2),
                        fixedSize: const Size(44, 44),
                      ),
                      onPressed: () => openComposer(context, _s, mode: mode, original: m),
                      icon: Icon(icon, color: p.ink),
                    ),
                ] else ...[
                  pillBtn(Icons.reply_rounded, 'Répondre', () => openComposer(context, _s, mode: 'reply', original: m)),
                  pillBtn(Icons.reply_all_rounded, 'Répondre à tous', () => openComposer(context, _s, mode: 'reply_all', original: m)),
                  pillBtn(Icons.forward_rounded, 'Transférer', () => openComposer(context, _s, mode: 'forward', original: m)),
                ],
                PopupMenuButton<String>(
                  tooltip: tr('Plus d\'actions'),
                  onSelected: (v) => _more(m, v),
                  itemBuilder: (_) => _moreItems(m),
                  child: Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(color: p.line, width: 1.2),
                    ),
                    child: Icon(Icons.more_horiz_rounded, color: p.ink),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Créer un élément à partir du message : réunion (dialogue avec date, type, invités), devis, ticket, appel de fonds…
  Future<void> _mailAction(Map<String, dynamic> m, String action, [String? start]) async {
    Map<String, dynamic> body = {'type': action};
    if (['meeting', 'visio', 'webinar'].contains(action)) {
      final r = await showMeetingFromMailDialog(context, m, initialType: action, start: start);
      if (r == null) return;
      body = r;
    } else if (action == 'funds' || action == 'quote') {
      body['amount'] = (m['insights'] as Map?)?['amount'];
      body.removeWhere((k, v) => v == null);
    }
    await _do(() async {
      final r = await _s.api.post('mailbox/messages/${m['id']}/convert', body);
      if (!mounted) return;
      final type = '${r['type']}';
      if (type == 'quote' && r['id'] != null) {
        await context.read<SyncEngine>().sync();
        if (!mounted) return;
        final doc = LocalDb.get('sales_documents', '${r['id']}');
        toast(context, '${r['message']}');
        if (doc != null) {
          Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => DocumentEditor(config: DocConfig.sale, document: doc, initialType: 'quote'),
            ),
          );
        }
        return;
      }
      toast(context, '${r['message']}');
      if (r['join_url'] != null) safeLaunchUrl(Uri.parse('${r['join_url']}'), webOnlyWindowName: '_blank');
    });
  }

  static IconData _attIcon(String mime) => mime.startsWith('image/')
      ? Icons.image_outlined
      : mime.contains('pdf')
      ? Icons.picture_as_pdf_outlined
      : mime.contains('sheet') || mime.contains('excel') || mime.contains('csv')
      ? Icons.table_chart_outlined
      : mime.contains('word') || mime.contains('document')
      ? Icons.description_outlined
      : Icons.insert_drive_file_outlined;
}

class _Esc {
  const _Esc();

  String convert(String s) => s.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.name, required this.color, this.size = 34});

  final String name;
  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    final parts = name.replaceAll(RegExp(r'[<>"]'), '').split(RegExp(r'[\s@._-]+')).where((p) => p.isNotEmpty).toList();
    final ini = parts.isEmpty ? '?' : (parts.length == 1 ? parts.first.substring(0, 1) : parts[0].substring(0, 1) + parts[1].substring(0, 1)).toUpperCase();
    final hue = (name.codeUnits.fold<int>(0, (a, b) => a + b) * 37) % 360;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final bg = HSLColor.fromAHSL(1, hue.toDouble(), .55, dark ? .26 : .9).toColor();
    final fg = HSLColor.fromAHSL(1, hue.toDouble(), .55, dark ? .82 : .35).toColor();
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: bg,
        shape: BoxShape.circle,
        border: Border.all(color: color.withValues(alpha: .25), width: 1),
      ),
      child: Text(
        tr(ini),
        style: TextStyle(color: fg, fontWeight: FontWeight.w800, fontSize: size * .36),
      ),
    );
  }
}

/// Séparateur glissable entre deux volets (double-clic : largeur par défaut).
class _Splitter extends StatefulWidget {
  const _Splitter({required this.onDrag, required this.onEnd, this.onReset, required this.color});

  final ValueChanged<double> onDrag;
  final VoidCallback onEnd;
  final VoidCallback? onReset;
  final Color color;

  @override
  State<_Splitter> createState() => _SplitterState();
}

class _SplitterState extends State<_Splitter> {
  bool _hover = false, _drag = false;

  @override
  Widget build(BuildContext context) => MouseRegion(
    cursor: SystemMouseCursors.resizeColumn,
    onEnter: (_) => setState(() => _hover = true),
    onExit: (_) => setState(() => _hover = false),
    child: GestureDetector(
      behavior: HitTestBehavior.opaque,
      onHorizontalDragStart: (_) => setState(() => _drag = true),
      onHorizontalDragUpdate: (d) => widget.onDrag(d.delta.dx),
      onHorizontalDragEnd: (_) {
        setState(() => _drag = false);
        widget.onEnd();
      },
      onDoubleTap: widget.onReset,
      child: SizedBox(
        width: 12,
        child: Center(
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 120),
            width: 3,
            height: 48,
            decoration: BoxDecoration(color: _hover || _drag ? widget.color : Colors.transparent, borderRadius: BorderRadius.circular(2)),
          ),
        ),
      ),
    ),
  );
}

/// Photo de profil d'une adresse (ou initiales sur sa couleur).
class AccountAvatar extends StatelessWidget {
  const AccountAvatar({super.key, required this.account, this.radius = 14});

  final Map<String, dynamic> account;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final url = account['avatar_url'] as String?;
    final name = '${account['display_name'] ?? account['email'] ?? '?'}';
    final h = '${account['color'] ?? '#2E6BE6'}'.replaceAll('#', '');
    final color = Color(int.tryParse('FF${h.padLeft(6, '0')}', radix: 16) ?? 0xFF2E6BE6);
    return CircleAvatar(
      radius: radius,
      backgroundColor: color,
      foregroundImage: url != null && url.isNotEmpty ? NetworkImage(url) : null,
      child: Text(
        tr(name.trim().isEmpty ? '?' : name.trim()[0].toUpperCase()),
        style: TextStyle(color: Colors.white, fontSize: radius * .9, fontWeight: FontWeight.w800),
      ),
    );
  }
}

/// « En direct » : la boîte se met à jour toute seule ; indique la dernière actualisation.
class _LiveBadge extends StatefulWidget {
  const _LiveBadge({required this.store});

  final MailStore store;

  @override
  State<_LiveBadge> createState() => _LiveBadgeState();
}

class _LiveBadgeState extends State<_LiveBadge> {
  Timer? _t;

  @override
  void initState() {
    super.initState();
    _t = Timer.periodic(const Duration(seconds: 10), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final at = widget.store.lastRefreshAt;
    final off = widget.store.offline;
    final s = at == null ? null : DateTime.now().difference(at).inSeconds;
    final when = s == null ? '' : (s < 15 ? ' · actualisé à l\'instant' : ' · actualisé il y a ${s < 60 ? '$s s' : '${s ~/ 60} min'}');
    const green = Color(0xFF16A34A);
    return MailStatusChip(
      tooltip: tr('Nouveaux messages affichés automatiquement (relève toutes les 30 secondes quand la boîte est ouverte).$when'),
      color: off ? Colors.grey : green,
      leading: Container(
        width: 8,
        height: 8,
        decoration: BoxDecoration(shape: BoxShape.circle, color: off ? Colors.grey : const Color(0xFF22C55E)),
      ),
      label: off ? 'Hors ligne' : 'En direct',
    );
  }
}
