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
      builder: (c) => StatefulBuilder(builder: (c, set) {
        final list = _s.directory.where((d) => q.isEmpty || '${d['name']} ${d['email']} ${d['label'] ?? ''}'.toLowerCase().contains(q.toLowerCase())).toList();
        return YAlertDialog(
          icon: const Icon(Icons.contacts_outlined),
          title: Text(tr('Carnet d\'adresses de l\'entreprise')),
          content: SizedBox(
            width: 560,
            height: 480,
            child: Column(children: [
              Text(tr('Collaborateurs, direction, stagiaires et boîtes partagées : chaque nouvelle boîte connectée y apparaît automatiquement.'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12.5)),
              const SizedBox(height: 10),
              TextField(autofocus: true, decoration: InputDecoration(prefixIcon: const Icon(Icons.search, size: 20), hintText: tr('Nom, adresse, fonction…')), onChanged: (v) => set(() => q = v)),
              const SizedBox(height: 8),
              Expanded(
                child: list.isEmpty
                    ? EmptyState(icon: Icons.person_search_outlined, title: tr('Aucun collaborateur trouvé'))
                    : ListView(children: [
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
                                openComposer(context, _s, to: [{'name': '${d['name'] ?? ''}', 'email': '${d['email']}'}]);
                              },
                            ),
                          ),
                      ]),
              ),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
        );
      }),
    );
  }

  @override
  void initState() {
    super.initState();
    _s.addListener(_changed);
    _s.arrivals.addListener(_arrived);
    _life = AppLifecycleListener(onStateChange: (st) => _s.foreground = st == AppLifecycleState.resumed);
    _s.init();
  }

  // Nouveaux messages arrivés pendant que la boîte est ouverte : alerte discrète avec accès direct
  void _arrived() {
    final l = _s.arrivals.value;
    if (!mounted || l.isEmpty) return;
    final m = l.first;
    final who = '${m['from_name'] ?? m['from_email'] ?? ''}'.trim();
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      behavior: SnackBarBehavior.floating,
      width: 420,
      duration: const Duration(seconds: 6),
      content: Row(children: [
        const Icon(Icons.mark_email_unread_outlined, color: Colors.white),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            l.length > 1 ? tr('${l.length} nouveaux messages · dernier de $who') : tr('Nouveau message de $who\n${m['subject'] ?? ''}'),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ]),
      action: SnackBarAction(label: tr('Ouvrir'), onPressed: () => _openMessage(m)),
    ));
  }

  void _changed() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
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
      final rows = await _s.older({
        if (_view.folderId != null) 'folder_id': _view.folderId!,
        if (_view.role != null) 'role': _view.role!,
        if (_view.unread || _filter == 'unread') 'unread': 1,
        if (_view.flagged || _filter == 'flagged') 'flagged': 1,
        if (_filter == 'attachments') 'attachments': 1,
        if (_q.isNotEmpty) 'q': _q,
      }, _olderCursor ?? (last == null ? null : '${last['date']}|${last['id']}'));
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

  @override
  Widget build(BuildContext context) {
    final base = Theme.of(context);
    return Theme(
      data: base.copyWith(colorScheme: base.colorScheme.copyWith(primary: _theme.accent), progressIndicatorTheme: ProgressIndicatorThemeData(color: _theme.accent)),
      child: LayoutBuilder(builder: (context, c) {
        if (_s.loading) return const Center(child: CircularProgressIndicator());
        if (_s.accounts.isEmpty) return _welcome();
        final medium = c.maxWidth >= 720;
        if (!medium) return _mobile();
        final showFolders = _foldersOpen && c.maxWidth >= 980;
        final fw = _wFolders.clamp(170.0, 380.0);
        final maxList = (c.maxWidth - (showFolders ? fw : 0) - 360).clamp(260.0, 760.0);
        final lw = _wList.clamp(260.0, maxList);
        return Column(children: [
          _commandBar(),
          Expanded(
            child: Row(children: [
              if (showFolders) ...[
                SizedBox(width: fw, child: Container(color: _theme.accent.withValues(alpha: .035), child: _folders())),
                _Splitter(color: _theme.accent, onDrag: (dx) => setState(() => _wFolders = (fw + dx).clamp(170.0, 380.0)), onEnd: _saveLayout, onReset: () {
                  setState(() => _wFolders = 250);
                  _saveLayout();
                }),
              ],
              SizedBox(width: lw, child: _messageList()),
              _Splitter(color: _theme.accent, onDrag: (dx) => setState(() => _wList = (lw + dx).clamp(260.0, maxList)), onEnd: _saveLayout, onReset: () {
                setState(() => _wList = 400);
                _saveLayout();
              }),
              Expanded(child: _openId == null ? _emptyReader(showFolders: !showFolders) : _reader()),
            ]),
          ),
        ]);
      }),
    );
  }

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
            setState(() => _open = _s.local(id) ?? _open);
          case 'unread':
            await _s.setFlags(id, seen: m?['seen'] != true ? true : false);
            if (m?['seen'] == true) setState(() => (_openId = null, _open = null));
        }
      });
      return;
    }
    try {
      final r = await _s.api.post('mailbox/messages/bulk', {'ids': ids, 'action': action == 'flag' ? 'flag' : action == 'unread' ? 'unread' : action, 'folder_id': ?folderId});
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

  /// Barre de commandes façon Outlook, aux couleurs du thème.
  Widget _commandBar() {
    final on = _theme.onBand;
    final has = _targets.isNotEmpty;
    final open = _open;
    final folders = open == null ? const <Map<String, dynamic>>[] : _s.foldersOf(open['account_id'] as int).where((f) => f['id'] != open['folder_id']).toList();
    // Fenêtre étroite : icônes seules (avec info-bulle), pour que toute la barre reste visible
    final compact = MediaQuery.sizeOf(context).width < 1500;
    Widget cmd(IconData icon, String label, VoidCallback? onTap) => compact
        ? IconButton(tooltip: tr(label), onPressed: onTap, color: on, disabledColor: on.withValues(alpha: .45), icon: Icon(icon, size: 20))
        : Padding(
          padding: const EdgeInsets.symmetric(horizontal: 1),
          child: TextButton.icon(
            onPressed: onTap,
            style: TextButton.styleFrom(foregroundColor: on, disabledForegroundColor: on.withValues(alpha: .45), padding: const EdgeInsets.symmetric(horizontal: 10)),
            icon: Icon(icon, size: 19),
            label: Text(tr(label)),
          ),
        );
    return ThemeBand(
      theme: _theme,
      child: SizedBox(
        height: 54,
        child: Row(children: [
          const SizedBox(width: 6),
          IconButton(tooltip: trn(_foldersOpen ? 'Masquer les dossiers' : 'Afficher les dossiers'), color: on, icon: const Icon(Icons.menu), onPressed: () {
            setState(() => _foldersOpen = !_foldersOpen);
            _saveLayout();
          }),
          const SizedBox(width: 4),
          FilledButton.icon(
            onPressed: () => openComposer(context, _s),
            style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: const Color(0xFF13203A), elevation: 1, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22))),
            icon: Icon(Icons.edit_outlined, size: 18, color: _theme.accent),
            label: Text(tr('Nouveau courrier'), style: TextStyle(fontWeight: FontWeight.w700)),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(children: [
                cmd(Icons.delete_outline, 'Supprimer', has ? () => _bulk('delete') : null),
                cmd(Icons.archive_outlined, 'Archiver', has ? () => _bulk('archive') : null),
                PopupMenuButton<int>(
                  enabled: has && open != null && _selected.isEmpty,
                  tooltip: tr('Déplacer'),
                  onSelected: (fid) => _bulk('move', folderId: fid),
                  itemBuilder: (_) => [for (final f in folders) PopupMenuItem(value: f['id'] as int, child: Text(tr('${f['name']}')))],
                  child: IgnorePointer(child: cmd(Icons.drive_file_move_outline, 'Déplacer', has && open != null && _selected.isEmpty ? () {} : null)),
                ),
                cmd(open?['flagged'] == true && _selected.isEmpty ? Icons.flag : Icons.outlined_flag, 'Indicateur', has ? () => _bulk('flag') : null),
                cmd(Icons.mark_email_unread_outlined, open?['seen'] == false && _selected.isEmpty ? 'Marquer comme lu' : 'Marquer comme non lu', has ? () => _bulk('unread') : null),
                cmd(Icons.sync, 'Synchroniser', _s.syncing ? null : () => _s.refresh(pull: true)),
                _LiveBadge(store: _s, color: on),
              ]),
            ),
          ),
          if (_s.offline) Tooltip(message: tr('Hors ligne : copie locale'), child: Icon(Icons.cloud_off, color: on)),
          IconButton(tooltip: tr('Application mobile'), color: on, icon: const Icon(Icons.qr_code_2), onPressed: _s.mobileUrl.isEmpty ? null : () => showMobileAppQr(context, _s.mobileUrl, ready: false)),
          IconButton(
            tooltip: tr('Thème'),
            color: on,
            icon: const Icon(Icons.palette_outlined),
            onPressed: () async {
              final t = await pickMailTheme(context, _theme);
              if (t != null) {
                await MailTheme.save(t);
                setState(() => _theme = t);
              }
            },
          ),
          const SizedBox(width: 6),
        ]),
      ),
    );
  }

  /// Téléphone : une vue à la fois, gestes de balayage, tirer pour actualiser.
  Widget _mobile() {
    if (_openId != null) return _reader(back: true);
    final on = _theme.onBand;
    return Scaffold(
      drawer: Drawer(child: SafeArea(child: _folders())),
      appBar: PreferredSize(
        preferredSize: const Size.fromHeight(56),
        child: ThemeBand(
          theme: _theme,
          child: SafeArea(
            bottom: false,
            child: Row(children: [
              Builder(builder: (c) => IconButton(color: on, icon: const Icon(Icons.menu), onPressed: () => Scaffold.of(c).openDrawer())),
              Expanded(child: Text(tr(_view.title), overflow: TextOverflow.ellipsis, style: TextStyle(color: on, fontWeight: FontWeight.w800, fontSize: 18))),
              IconButton(color: on, icon: const Icon(Icons.palette_outlined), onPressed: () async {
                final t = await pickMailTheme(context, _theme);
                if (t != null) {
                  await MailTheme.save(t);
                  setState(() => _theme = t);
                }
              }),
            ]),
          ),
        ),
      ),
      body: _messageList(compact: true),
      floatingActionButton: _composeFab(),
    );
  }

  Widget _composeFab() => FloatingActionButton.extended(backgroundColor: _theme.accent, foregroundColor: Colors.white, onPressed: () => openComposer(context, _s), icon: const Icon(Icons.edit_outlined), label: Text(tr('Nouveau')));

  Widget _welcome() => Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Container(
                width: 96,
                height: 96,
                decoration: BoxDecoration(gradient: const LinearGradient(colors: [Color(0xFF2E6BE6), Color(0xFF5B5FC7)]), borderRadius: BorderRadius.circular(28)),
                child: const Icon(Icons.mail_outline, color: Colors.white, size: 48),
              ),
              const SizedBox(height: 20),
              Text(tr('Votre boîte mail dans Yomara'), textAlign: TextAlign.center, style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
              const SizedBox(height: 10),
              Text(
                tr('Microsoft 365, Gmail ou la boîte de votre hébergement : lisez, répondez, signez et classez vos e-mails sans quitter le logiciel, même hors ligne. '
                'Les mails de vos clients apparaissent sur leur fiche, et votre calendrier Outlook ou Google se synchronise avec l\'Agenda.'),
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).hintColor, height: 1.5),
              ),
              const SizedBox(height: 22),
              FilledButton.icon(onPressed: () => addAccount(context, _s), icon: const Icon(Icons.add), label: Text(tr('Ajouter un compte'))),
              if (_s.canManage) TextButton.icon(onPressed: () => mailboxSettings(context), icon: const Icon(Icons.settings_outlined), label: Text(tr('Paramétrer Microsoft 365 / Google'))),
            ]),
          ),
        ),
      );

  // ------------------------------------------------------------------ dossiers

  Widget _folders() {
    final hint = Theme.of(context).hintColor;
    Widget item(IconData icon, String label, _View v, {int count = 0, Color? color, Map<String, dynamic>? folder}) {
      final sel = _view.key == v.key;
      final depth = (folder?['depth'] as num?)?.toInt() ?? 0;
      if (folder != null && depth > 0) label = label.split(' › ').last;
      Widget tile(bool hover) => Padding(
        padding: EdgeInsets.only(left: 8.0 + depth * 14, right: 8, top: 1, bottom: 1),
        child: Material(
          color: sel ? YColors.blue.withValues(alpha: .1) : Colors.transparent,
          borderRadius: BorderRadius.circular(8),
          child: InkWell(
            borderRadius: BorderRadius.circular(8),
            onTap: () {
              _select(v);
              if (Scaffold.maybeOf(context)?.isDrawerOpen == true) Navigator.pop(context);
            },
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              child: Row(children: [
                Icon(icon, size: 19, color: sel ? YColors.blue : (color ?? hint)),
                const SizedBox(width: 10),
                Expanded(child: Text(tr(label), overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: sel || count > 0 ? FontWeight.w700 : FontWeight.w500, color: sel ? YColors.blue : null))),
                if (count > 0) Text(tr('$count'), style: const TextStyle(color: YColors.blue, fontWeight: FontWeight.w700, fontSize: 12.5)),
                if (folder != null && !_s.offline)
                  SizedBox(
                    width: 26,
                    height: 22,
                    child: PopupMenuButton<String>(
                      padding: EdgeInsets.zero,
                      iconSize: 17,
                      tooltip: tr('Gérer le dossier'),
                      icon: Icon(Icons.more_vert, color: hint.withValues(alpha: .7)),
                      onSelected: (a) => _folderAction(a, folder),
                      itemBuilder: (_) => [
                        PopupMenuItem(value: 'sub', child: Text(tr('Nouveau sous-dossier'))),
                        if (folder['custom'] == true || folder['role'] == 'other') ...[
                          PopupMenuItem(value: 'rename', child: Text(tr('Renommer'))),
                          PopupMenuItem(value: 'delete', child: Text(tr('Supprimer le dossier'), style: const TextStyle(color: YColors.danger))),
                        ],
                      ],
                    ),
                  ),
              ]),
            ),
          ),
        ),
      );
      if (folder == null) return tile(false);
      // Glisser-déposer : on range une discussion en la lâchant sur un dossier du même compte
      return DragTarget<int>(
        onWillAcceptWithDetails: (d) => _s.local(d.data)?['account_id'] == folder['account_id'] && _s.local(d.data)?['folder_id'] != folder['id'],
        onAcceptWithDetails: (d) => _moveTo(d.data, folder['id'] as int),
        builder: (_, cand, _) => cand.isEmpty
            ? tile(false)
            : DecoratedBox(
                decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .14), borderRadius: BorderRadius.circular(8), border: Border.all(color: YColors.blue, width: 1.4)),
                child: tile(true)),
      );
    }

    IconData roleIcon(String r) => switch (r) {
          'inbox' => Icons.inbox_outlined,
          'sent' => Icons.send_outlined,
          'drafts' => Icons.drafts_outlined,
          'trash' => Icons.delete_outline,
          'junk' => Icons.report_gmailerrorred_outlined,
          'archive' => Icons.archive_outlined,
          _ => Icons.folder_outlined,
        };

    return Column(children: [
      const SizedBox(height: 10),
      Expanded(
        child: ListView(children: [
          item(Icons.all_inbox_outlined, 'Toutes les réceptions', _View(role: 'inbox', title: tr('Boîte de réception')), count: _s.unreadIn('inbox')),
          item(Icons.mark_email_unread_outlined, 'Non lus', _View(unread: true, title: tr('Non lus'))),
          item(Icons.flag_outlined, 'Suivis', _View(flagged: true, title: tr('Suivis')), color: YColors.danger),
          item(Icons.send_outlined, 'Tous les envoyés', _View(role: 'sent', title: tr('Éléments envoyés'))),
          if (_s.pending.isNotEmpty || _s.outbox.any((o) => o['status'] != 'sent'))
            ListTile(
              dense: true,
              leading: const Icon(Icons.outbox_outlined, color: YColors.warning),
              title: Text(tr('Boîte d\'envoi (${_s.pending.length + _s.outbox.where((o) => o['status'] != 'sent').length})')),
              onTap: _outboxDialog,
            ),
          for (final a in _s.accounts) ...[
            const SizedBox(height: 10),
            Padding(
              padding: const EdgeInsets.fromLTRB(18, 4, 8, 4),
              child: Row(children: [
                AccountAvatar(account: a, radius: 11),
                const SizedBox(width: 8),
                Expanded(
                  child: Tooltip(
                    message: '${a['provider_label']}${a['last_sync_at'] != null ? ' · synchro ${dateTimeFr(a['last_sync_at'])}' : ''}',
                    child: Text(tr('${a['display_name'] ?? a['email']}'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5)),
                  ),
                ),
                if (a['status'] == 'error') Tooltip(message: '${a['error']}', child: const Icon(Icons.error_outline, size: 16, color: YColors.danger)),
                if (a['shared'] == true) Tooltip(message: tr('Boîte partagée'), child: Icon(Icons.group_outlined, size: 15)),
                if (!_s.offline) IconButton(visualDensity: VisualDensity.compact, iconSize: 17, tooltip: tr('Nouveau dossier'), icon: const Icon(Icons.create_new_folder_outlined), onPressed: () => _newFolder(a['id'] as int)),
                IconButton(visualDensity: VisualDensity.compact, iconSize: 17, tooltip: tr('Réglages du compte'), icon: const Icon(Icons.more_horiz), onPressed: () => editAccount(context, _s, a)),
              ]),
            ),
            for (final f in _s.foldersOf(a['id'] as int))
              item(roleIcon('${f['role']}'), '${f['name']}', _View(folderId: f['id'] as int, title: '${f['name']}'),
                  count: f['role'] == 'sent' || f['role'] == 'trash' || f['role'] == 'drafts' ? 0 : ((f['unread'] as num?)?.toInt() ?? 0), folder: f),
          ],
        ]),
      ),
      const Divider(height: 1),
      Padding(
        padding: const EdgeInsets.all(6),
        child: Row(children: [
          IconButton(tooltip: tr('Ajouter un compte'), onPressed: () => addAccount(context, _s), icon: const Icon(Icons.person_add_alt)),
          IconButton(tooltip: tr('Carnet d\'adresses de l\'entreprise'), onPressed: _directory, icon: const Icon(Icons.contacts_outlined)),
          IconButton(tooltip: tr('Signatures et modèles'), onPressed: () => manageSignatures(context, _s), icon: const Icon(Icons.draw_outlined)),
          if (_s.canManage) IconButton(tooltip: tr('Paramètres Microsoft 365 / Google'), onPressed: () => mailboxSettings(context), icon: const Icon(Icons.settings_outlined)),
          const Spacer(),
          if (_s.offline) Tooltip(message: tr('Hors ligne : copie locale'), child: Icon(Icons.cloud_off, color: YColors.warning, size: 20)),
        ]),
      ),
    ]);
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
            child: ListView(shrinkWrap: true, children: [
              for (final p in _s.pending)
                ListTile(
                  leading: const Icon(Icons.cloud_off, color: YColors.warning),
                  title: Text(tr('${p['subject'] ?? '(sans objet)'}')),
                  subtitle: Text(tr('En attente de connexion')),
                  trailing: TextButton(onPressed: () async {
                    await _s.cancelPending('${p['id']}');
                    set(() {});
                  }, child: Text(tr('Annuler'))),
                ),
              for (final o in _s.outbox.where((o) => o['status'] != 'sent'))
                ListTile(
                  leading: Icon(o['status'] == 'failed' ? Icons.error_outline : Icons.schedule_send, color: o['status'] == 'failed' ? YColors.danger : YColors.blue),
                  title: Text(tr('${o['subject'] ?? '(sans objet)'}')),
                  subtitle: Text(tr(o['status'] == 'failed' ? 'Échec : ${o['error'] ?? ''}' : (o['send_at'] != null ? 'Programmé le ${dateTimeFr(o['send_at'])}' : 'Envoi en cours…'))),
                  trailing: Wrap(children: [
                    if (o['status'] == 'failed')
                      IconButton(tooltip: tr('Réessayer'), icon: const Icon(Icons.replay), onPressed: () => _do(() async {
                            await _s.api.post('mailbox/outbox/${o['id']}/retry');
                            await _s.reloadIndex();
                            set(() {});
                          })),
                    IconButton(tooltip: tr('Annuler l\'envoi'), icon: const Icon(Icons.cancel_outlined), onPressed: () => _do(() async {
                          await _s.api.delete('mailbox/outbox/${o['id']}');
                          await _s.reloadIndex();
                          set(() {});
                        })),
                  ]),
                ),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer')))],
        ),
      ),
    );
  }

  // ------------------------------------------------------------------ liste

  Widget _messageList({bool compact = false}) {
    final list = _list;
    final hint = Theme.of(context).hintColor;
    final groups = <String, List<Map<String, dynamic>>>{};
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    for (final m in list) {
      final d = DateTime.tryParse('${m['date']}') ?? now;
      final day = DateTime(d.year, d.month, d.day);
      final diff = today.difference(day).inDays;
      final g = diff <= 0 ? 'Aujourd\'hui' : diff == 1 ? 'Hier' : diff < 7 ? 'Cette semaine' : (d.year == now.year && d.month == now.month ? 'Plus tôt ce mois-ci' : DateFormat('MMMM y', 'fr_FR').format(d));
      groups.putIfAbsent(g, () => []).add(m);
    }
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(12, 12, 8, 4),
        child: Row(children: [
          Expanded(child: Text(tr(compact ? '${_list.length} message(s)' : _view.title), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800), overflow: TextOverflow.ellipsis)),
          IconButton(
            tooltip: tr('Actualiser'),
            onPressed: _s.syncing ? null : () => _s.refresh(pull: true),
            icon: _s.syncing ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.refresh),
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
            icon: Icon(_selecting ? Icons.close : Icons.checklist),
          ),
        ]),
      ),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: TextField(
          controller: _search,
          decoration: InputDecoration(
            isDense: true,
            prefixIcon: const Icon(Icons.search, size: 20),
            hintText: tr('Rechercher (expéditeur, objet, contenu)'),
            suffixIcon: _q.isEmpty ? null : IconButton(icon: const Icon(Icons.clear, size: 18), onPressed: () => setState(() {
                      _search.clear();
                      _q = '';
                      _older.clear();
                    })),
          ),
          onChanged: (v) => setState(() {
            _q = v.trim();
            _older.clear();
            _olderCursor = null;
            _olderDone = false;
          }),
        ),
      ),
      SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 4),
        child: Row(children: [
          for (final f in const [('all', 'Tous'), ('focused', 'Prioritaires'), ('other', 'Autres'), ('unread', 'Non lus'), ('flagged', 'Suivis'), ('attachments', 'Avec pièces jointes')])
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: ChoiceChip(label: Text(tr(f.$2)), selected: _filter == f.$1, visualDensity: VisualDensity.compact, onSelected: (_) => setState(() {
                _filter = f.$1;
                _older.clear();
              })),
            ),
        ]),
      ),
      if (_selecting) _bulkBar(list),
      if (_s.error != null) Padding(padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4), child: Text(tr(_s.error!), style: const TextStyle(color: YColors.danger, fontSize: 12))),
      Expanded(
        child: list.isEmpty
            ? EmptyState(icon: Icons.mark_email_read_outlined, title: _q.isNotEmpty ? 'Aucun résultat' : 'Aucun message', message: _q.isNotEmpty ? 'Essayez d\'autres mots, ou cherchez dans les messages plus anciens.' : null,
                action: _q.isNotEmpty ? OutlinedButton(onPressed: _loadOlder, child: Text(tr('Chercher sur le serveur'))) : null)
            : RefreshIndicator(
                onRefresh: () => _s.refresh(pull: true),
                child: ListView(physics: const AlwaysScrollableScrollPhysics(), children: [
                for (final g in groups.entries) ...[
                  Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 4), child: Text(tr(g.key), style: TextStyle(color: hint, fontWeight: FontWeight.w700, fontSize: 12))),
                  for (final m in g.value) compact ? _swipeRow(m) : _row(m),
                ],
                if (!_olderDone)
                  Padding(padding: const EdgeInsets.all(12), child: Center(child: TextButton.icon(onPressed: _loadOlder, icon: const Icon(Icons.history), label: Text(tr('Messages plus anciens'))))),
                const SizedBox(height: 80),
              ]),
              ),
      ),
    ]);
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
      decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .08), borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        Checkbox(
          value: _selected.length == list.length && list.isNotEmpty,
          tristate: false,
          onChanged: (v) => setState(() => v == true ? _selected.addAll(list.map((m) => m['id'] as int)) : _selected.clear()),
        ),
        Text(tr('${_selected.length} sélectionné(s)'), style: const TextStyle(fontWeight: FontWeight.w600)),
        const Spacer(),
        IconButton(tooltip: tr('Marquer comme lu'), onPressed: _selected.isEmpty ? null : () => run('read'), icon: const Icon(Icons.mark_email_read_outlined, size: 20)),
        IconButton(tooltip: tr('Marquer comme non lu'), onPressed: _selected.isEmpty ? null : () => run('unread'), icon: const Icon(Icons.mark_email_unread_outlined, size: 20)),
        IconButton(tooltip: tr('Archiver'), onPressed: _selected.isEmpty ? null : () => run('archive'), icon: const Icon(Icons.archive_outlined, size: 20)),
        IconButton(tooltip: tr('Supprimer'), onPressed: _selected.isEmpty ? null : () => run('delete'), icon: const Icon(Icons.delete_outline, size: 20)),
      ]),
    );
  }

  /// Balayer vers la droite : archiver ; vers la gauche : supprimer (téléphone).
  Widget _swipeRow(Map<String, dynamic> m) => Dismissible(
        key: ValueKey('mail-${m['id']}'),
        background: Container(color: const Color(0xFF12A150), alignment: Alignment.centerLeft, padding: const EdgeInsets.only(left: 22), child: Row(children: [Icon(Icons.archive_outlined, color: Colors.white), SizedBox(width: 8), Text(tr('Archiver'), style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700))])),
        secondaryBackground: Container(color: YColors.danger, alignment: Alignment.centerRight, padding: const EdgeInsets.only(right: 22), child: Row(mainAxisAlignment: MainAxisAlignment.end, children: [Text(tr('Supprimer'), style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700)), SizedBox(width: 8), Icon(Icons.delete_outline, color: Colors.white)])),
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

  Widget _row(Map<String, dynamic> m) {
    final sel = _openId == m['id'];
    final unread = m['seen'] != true;
    final hint = Theme.of(context).hintColor;
    final sent = _s.folder(m['folder_id'])?['role'] == 'sent';
    final who = sent
        ? 'À : ${(m['to'] as List? ?? const []).map((t) => (t['name'] ?? '').toString().isNotEmpty ? t['name'] : t['email']).join(', ')}'
        : '${(m['from_name'] ?? '').toString().isNotEmpty ? m['from_name'] : m['from_email'] ?? ''}';
    final acc = _s.account(m['account_id']);
    final checked = _selected.contains(m['id']);
    final tileW = Material(
      color: sel ? YColors.blue.withValues(alpha: .09) : Colors.transparent,
      child: InkWell(
        onTap: () {
          if (_selecting) {
            setState(() => checked ? _selected.remove(m['id']) : _selected.add(m['id'] as int));
          } else {
            _openMessage(m);
          }
        },
        onLongPress: () => setState(() => _selected.add(m['id'] as int)),
        child: Container(
          decoration: BoxDecoration(border: Border(left: BorderSide(color: unread ? YColors.blue : Colors.transparent, width: 3))),
          padding: const EdgeInsets.fromLTRB(10, 9, 12, 9),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            _selecting
                ? Checkbox(visualDensity: VisualDensity.compact, value: checked, onChanged: (v) => setState(() => v == true ? _selected.add(m['id'] as int) : _selected.remove(m['id'])))
                : _Avatar(name: sent ? '${(m['to'] as List? ?? const []).firstOrNull?['email'] ?? '?'}' : who, color: _color(acc?['color'])),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(tr(who), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: unread ? FontWeight.w800 : FontWeight.w500))),
                  if (m['has_attachments'] == true) Icon(Icons.attach_file, size: 14, color: hint),
                  if (m['answered'] == true) Icon(Icons.reply, size: 14, color: hint),
                  if (m['flagged'] == true) const Icon(Icons.flag, size: 14, color: YColors.danger),
                  const SizedBox(width: 4),
                  Text(tr(_shortDate('${m['date']}')), style: TextStyle(fontSize: 11.5, color: unread ? YColors.blue : hint, fontWeight: unread ? FontWeight.w700 : FontWeight.w400)),
                ]),
                const SizedBox(height: 2),
                Text(tr('${m['subject'] ?? '(sans objet)'}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: unread ? FontWeight.w700 : FontWeight.w500, color: unread ? YColors.blue : null, fontSize: 13)),
                const SizedBox(height: 2),
                Text(tr('${m['snippet'] ?? ''}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12.5)),
              ]),
            ),
          ]),
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
        color: YColors.blue,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.drive_file_move_outline, color: Colors.white, size: 18),
            const SizedBox(width: 8),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 260),
              child: Text(tr('${m['subject'] ?? '(sans objet)'}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 13)),
            ),
          ]),
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

  Widget _emptyReader({bool showFolders = false}) => Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.mail_outline, size: 64, color: Theme.of(context).hintColor.withValues(alpha: .4)),
          const SizedBox(height: 10),
          Text(tr('Sélectionnez un message pour le lire'), style: TextStyle(color: Theme.of(context).hintColor)),
          if (showFolders) ...[
            const SizedBox(height: 16),
            Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center, children: [
              for (final a in _s.accounts)
                for (final f in _s.foldersOf(a['id'] as int).where((f) => f['role'] != 'other'))
                  ActionChip(label: Text(tr('${f['name']}${_s.accounts.length > 1 ? ' · ${a['email']}' : ''}')), onPressed: () => _select(_View(folderId: f['id'] as int, title: '${f['name']}'))),
            ]),
          ],
        ]),
      );

  Widget _reader({bool back = false}) {
    final m = _open;
    if (m == null) return const SizedBox();
    final hint = Theme.of(context).hintColor;
    final atts = [for (final a in (m['attachments'] as List? ?? const [])) if (a['inline'] != true) asMap(a)];
    final thread = _s.thread(m).where((x) => x['id'] != m['id']).toList();
    final folder = _s.folder(m['folder_id']);
    final accountFolders = _s.foldersOf(m['account_id'] as int).where((f) => f['id'] != m['folder_id']).toList();
    final partner = m['partner'] as Map?;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final html = (m['html'] as String?) ?? '<div style="white-space:pre-wrap">${const _Esc().convert('${m['text'] ?? m['snippet'] ?? ''}')}</div>';
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Container(
        padding: const EdgeInsets.fromLTRB(8, 6, 8, 6),
        decoration: BoxDecoration(border: Border(bottom: BorderSide(color: Theme.of(context).dividerColor))),
        child: Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 2, children: [
          if (back) IconButton(icon: const Icon(Icons.arrow_back), onPressed: () => setState(() => (_openId = null, _open = null))),
          TextButton.icon(onPressed: () => openComposer(context, _s, mode: 'reply', original: m), icon: const Icon(Icons.reply, size: 18), label: Text(tr('Répondre'))),
          TextButton.icon(onPressed: () => openComposer(context, _s, mode: 'reply_all', original: m), icon: const Icon(Icons.reply_all, size: 18), label: Text(tr('Répondre à tous'))),
          TextButton.icon(onPressed: () => openComposer(context, _s, mode: 'forward', original: m), icon: const Icon(Icons.forward, size: 18), label: Text(tr('Transférer'))),
          if (back) IconButton(tooltip: tr('Archiver'), icon: const Icon(Icons.archive_outlined), onPressed: () => _do(() async {
                await _s.archive(m['id'] as int);
                _afterRemove(m['id'] as int);
              }, 'Archivé.')),
          if (back) IconButton(tooltip: tr('Supprimer'), icon: const Icon(Icons.delete_outline), onPressed: () => _do(() async {
                await _s.remove(m['id'] as int);
                _afterRemove(m['id'] as int);
              })),
          if (back) PopupMenuButton<int>(
            tooltip: tr('Déplacer'),
            icon: const Icon(Icons.drive_file_move_outline),
            onSelected: (fid) => _do(() async {
              await _s.moveConversation(m['id'] as int, fid);
              _afterRemove(m['id'] as int);
            }, 'Discussion rangée.'),
            itemBuilder: (_) => [for (final f in accountFolders) PopupMenuItem(value: f['id'] as int, child: Text(tr('${f['name']}')))],
          ),
          if (back) IconButton(
            tooltip: trn(m['flagged'] == true ? 'Retirer le suivi' : 'Suivre'),
            icon: Icon(m['flagged'] == true ? Icons.flag : Icons.outlined_flag, color: m['flagged'] == true ? YColors.danger : null),
            onPressed: () => _do(() async {
              await _s.setFlags(m['id'] as int, flagged: m['flagged'] != true);
              setState(() => _open = _s.local(m['id'] as int) ?? m);
            }),
          ),
          if (back) IconButton(
            tooltip: tr('Marquer comme non lu'),
            icon: const Icon(Icons.mark_email_unread_outlined),
            onPressed: () => _do(() async {
              await _s.setFlags(m['id'] as int, seen: false);
              setState(() => (_openId = null, _open = null));
            }),
          ),
          PopupMenuButton<String>(
            tooltip: tr('Créer à partir de ce message'),
            icon: const Icon(Icons.auto_awesome_outlined),
            onSelected: (v) => _mailAction(m, v),
            itemBuilder: (_) => [
              for (final a in MailActionsBar.allActions(m))
                PopupMenuItem(value: a, child: ListTile(dense: true, leading: Icon(MailActionsBar.icons[a]), title: Text(tr(MailActionsBar.labels[a]!)))),
            ],
          ),
        ]),
      ),
      if (_openLoading) const LinearProgressIndicator(minHeight: 2),
      Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SelectableText(tr('${m['subject'] ?? '(sans objet)'}'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            _Avatar(name: '${(m['from_name'] ?? '').toString().isNotEmpty ? m['from_name'] : m['from_email']}', color: _color(_s.account(m['account_id'])?['color']), size: 40),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SelectableText.rich(TextSpan(children: [
                  TextSpan(text: '${m['from_name'] ?? ''} ', style: const TextStyle(fontWeight: FontWeight.w700)),
                  TextSpan(text: '<${m['from_email'] ?? ''}>', style: TextStyle(color: hint)),
                ])),
                Text(tr('À : ${(m['to'] as List? ?? const []).map((t) => (t['name'] ?? '').toString().isNotEmpty ? t['name'] : t['email']).join(', ')}'
                    '${(m['cc'] as List? ?? const []).isNotEmpty ? '  ·  Cc : ${(m['cc'] as List).map((t) => (t['name'] ?? '').toString().isNotEmpty ? t['name'] : t['email']).join(', ')}' : ''}'),
                    style: TextStyle(color: hint, fontSize: 12.5), maxLines: 2, overflow: TextOverflow.ellipsis),
              ]),
            ),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(tr(dateTimeFr(m['date'])), style: TextStyle(color: hint, fontSize: 12.5)),
              if (folder != null) Text(tr('${folder['name']}'), style: TextStyle(color: hint, fontSize: 11.5)),
            ]),
          ]),
          if (partner != null)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Chip(avatar: const Icon(Icons.business, size: 16, color: YColors.blue), label: Text(tr('Fiche : ${partner['name']}')), visualDensity: VisualDensity.compact),
            ),
          if (m['warning'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('${m['warning']}'), style: const TextStyle(color: YColors.warning))),
          if (atts.isNotEmpty) ...[
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 8, children: [
              for (final a in atts)
                ActionChip(
                  avatar: Icon(_attIcon('${a['mime']}'), size: 18, color: YColors.blue),
                  label: Text(tr('${a['name']} · ${fileSize(a['size'])}')),
                  onPressed: a['url'] == null ? null : () => safeLaunchUrl(Uri.parse('${a['url']}'), mode: LaunchMode.externalApplication, webOnlyWindowName: '_blank'),
                ),
            ]),
          ],
        ]),
      ),
      MailActionsBar(message: m, onAction: (a, [start]) => _mailAction(m, a, start), onAcceptInvite: () => _do(() async {
            final r = await _s.api.post('mailbox/messages/${m['id']}/accept-invite');
            if (mounted) toast(context, '${r['message']}');
          })),
      MailAssistBar(store: _s, message: m, onReply: (body) => openComposer(context, _s, mode: 'reply', original: m, bodyText: '$body\n\n')),
      const Divider(height: 1),
      Expanded(
        child: Container(
          color: dark ? const Color(0xFF151B26) : Colors.white,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          child: MailHtml(key: ValueKey('mail-${m['id']}-${(m['html'] ?? '').hashCode}'), html: html, dark: dark),
        ),
      ),
      if (thread.isNotEmpty)
        Container(
          constraints: const BoxConstraints(maxHeight: 170),
          decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(context).dividerColor))),
          child: ListView(shrinkWrap: true, padding: const EdgeInsets.symmetric(vertical: 4), children: [
            Padding(padding: const EdgeInsets.fromLTRB(16, 4, 16, 2), child: Text(tr('Conversation (${thread.length + 1} messages)'), style: TextStyle(color: hint, fontWeight: FontWeight.w700, fontSize: 12))),
            for (final t in thread)
              ListTile(
                dense: true,
                leading: Icon(_s.folder(t['folder_id'])?['role'] == 'sent' ? Icons.call_made : Icons.call_received, size: 16),
                title: Text(tr('${(t['from_name'] ?? '').toString().isNotEmpty ? t['from_name'] : t['from_email']} — ${t['snippet'] ?? ''}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                trailing: Text(tr(_shortDate('${t['date']}')), style: TextStyle(color: hint, fontSize: 11.5)),
                onTap: () => _openMessage(t),
              ),
          ]),
        ),
    ]);
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
        if (doc != null) Navigator.of(context).push(MaterialPageRoute(builder: (_) => DocumentEditor(config: DocConfig.sale, document: doc, initialType: 'quote')));
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
    final bg = HSLColor.fromAHSL(1, hue.toDouble(), .55, .9).toColor();
    final fg = HSLColor.fromAHSL(1, hue.toDouble(), .55, .35).toColor();
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: bg, shape: BoxShape.circle, border: Border.all(color: color.withValues(alpha: .35), width: 1.5)),
      child: Text(tr(ini), style: TextStyle(color: fg, fontWeight: FontWeight.w800, fontSize: size * .36)),
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
            width: 8,
            child: Center(
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 120),
                width: _hover || _drag ? 3 : 1,
                color: _hover || _drag ? widget.color : Theme.of(context).dividerColor,
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
      child: Text(tr(name.trim().isEmpty ? '?' : name.trim()[0].toUpperCase()), style: TextStyle(color: Colors.white, fontSize: radius * .9, fontWeight: FontWeight.w800)),
    );
  }
}


/// « En direct » : la boîte se met à jour toute seule ; indique la dernière actualisation.
class _LiveBadge extends StatefulWidget {
  const _LiveBadge({required this.store, required this.color});

  final MailStore store;
  final Color color;

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
    final label = off ? 'Hors ligne' : (widget.store.syncing ? 'Actualisation…' : (s == null ? 'En direct' : (s < 15 ? 'En direct · à l\'instant' : 'En direct · il y a ${s < 60 ? '$s s' : '${s ~/ 60} min'}')));
    return Tooltip(
      message: tr('Nouveaux messages affichés automatiquement (relève toutes les 30 secondes quand la boîte est ouverte).'),
      child: Container(
        margin: const EdgeInsets.only(left: 6),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: widget.color.withValues(alpha: .10), borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Container(width: 8, height: 8, decoration: BoxDecoration(shape: BoxShape.circle, color: off ? Colors.grey : const Color(0xFF22C55E))),
          const SizedBox(width: 6),
          Text(tr(label), style: TextStyle(color: widget.color, fontSize: 12, fontWeight: FontWeight.w600)),
        ]),
      ),
    );
  }
}
