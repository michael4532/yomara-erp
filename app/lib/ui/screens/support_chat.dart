import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/config.dart';
import '../../core/json.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

const _violet = Color(0xFF5B3FD8);

/// Bouton « Support » flottant : chat en direct avec l'éditeur. Visible pour les personnes désignées par la société.
class SupportButton extends StatefulWidget {
  const SupportButton({super.key});

  @override
  State<SupportButton> createState() => _SupportButtonState();
}

class _SupportButtonState extends State<SupportButton> {
  Map<String, dynamic>? _status;
  Timer? _timer;
  bool _open = false;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 30), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('support/status'));
      if (mounted) setState(() => _status = r);
    } catch (_) {}
  }

  Future<void> _toggle() async {
    if (_open) return;
    setState(() => _open = true);
    await showGeneralDialog(
      context: context,
      barrierDismissible: true,
      barrierLabel: 'Support',
      barrierColor: Colors.black.withValues(alpha: .15),
      transitionDuration: const Duration(milliseconds: 220),
      pageBuilder: (c, _, _) => _SupportPanel(status: _status!, onChanged: _load),
      transitionBuilder: (c, a, _, child) => FadeTransition(
        opacity: a,
        child: SlideTransition(
          position: Tween(begin: const Offset(0, .04), end: Offset.zero).animate(CurvedAnimation(parent: a, curve: Curves.easeOutCubic)),
          child: child,
        ),
      ),
    );
    if (mounted) setState(() => _open = false);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final s = _status;
    if (s == null || s['allowed'] != true || _open) return const SizedBox.shrink();
    final unread = (s['unread'] as num?)?.toInt() ?? 0;
    return Tooltip(
      message: tr('Contacter le support Yomara'),
      child: Material(
        elevation: 6,
        shadowColor: _violet.withValues(alpha: .4),
        shape: const CircleBorder(),
        color: _violet,
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: _toggle,
          // pastille ronde et discrète : elle ne recouvre plus les boutons des écrans
          child: SizedBox(
            width: 48,
            height: 48,
            child: Center(
              child: Badge(
                isLabelVisible: unread > 0,
                label: Text('$unread'),
                child: const Icon(Icons.support_agent_rounded, color: Colors.white, size: 24),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SupportPanel extends StatefulWidget {
  const _SupportPanel({required this.status, required this.onChanged});

  final Map<String, dynamic> status;
  final Future<void> Function() onChanged;

  @override
  State<_SupportPanel> createState() => _SupportPanelState();
}

class _SupportPanelState extends State<_SupportPanel> {
  List<Map<String, dynamic>>? _list;
  int? _openId;
  bool _new = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('support/conversations'));
      if (!mounted) return;
      setState(() {
        _list = [for (final c in (r['data'] as List)) asMap(c)];
        if (_list!.isEmpty) _new = true;
      });
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.sizeOf(context);
    final narrow = size.width < 600;
    final cats = Map<String, dynamic>.from(widget.status['categories'] as Map? ?? const {});
    Widget body;
    if (_new) {
      body = _NewConversation(
        categories: cats,
        onCreated: (c) {
          setState(() {
            _new = false;
            _openId = c['id'] as int;
          });
          _load();
        },
      );
    } else if (_openId != null) {
      body = SupportThread(key: ValueKey(_openId), path: 'support/conversations/$_openId', platform: false, onChanged: _load);
    } else if (_list == null) {
      body = const Center(child: CircularProgressIndicator());
    } else {
      body = ListView(
        padding: const EdgeInsets.all(12),
        children: [for (final c in _list!) _ConversationTile(c: c, onTap: () => setState(() => _openId = c['id'] as int))],
      );
    }
    return SafeArea(
      child: Align(
        alignment: narrow ? Alignment.center : Alignment.bottomRight,
        child: Padding(
          padding: EdgeInsets.all(narrow ? 8 : 20),
          child: Material(
            elevation: 18,
            borderRadius: BorderRadius.circular(22),
            clipBehavior: Clip.antiAlias,
            child: SizedBox(
              width: narrow ? size.width : 420,
              height: narrow ? size.height - 16 : (size.height - 40).clamp(400, 640),
              child: Column(
                children: [
                  Container(
                    padding: const EdgeInsets.fromLTRB(8, 12, 8, 14),
                    decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFF0B1B3A), _violet])),
                    child: Row(
                      children: [
                        if (_openId != null || (_new && (_list?.isNotEmpty ?? false)))
                          IconButton(
                            color: Colors.white,
                            onPressed: () => setState(() {
                              _openId = null;
                              _new = false;
                            }),
                            icon: const Icon(Icons.arrow_back_rounded),
                          )
                        else
                          const SizedBox(width: 12),
                        const YomaraMark(size: 36),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                tr('Support Yomara'),
                                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16),
                              ),
                              Text(
                                tr('${widget.status['hours'] ?? ''}'),
                                style: const TextStyle(color: Color(0xFFC9D4F2), fontSize: 12),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ],
                          ),
                        ),
                        if (widget.status['can_manage'] == true)
                          IconButton(
                            color: Colors.white,
                            tooltip: tr('Personnes autorisées à contacter le support'),
                            onPressed: () => showSupportContacts(context),
                            icon: const Icon(Icons.manage_accounts_outlined),
                          ),
                        IconButton(color: Colors.white, onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close_rounded)),
                      ],
                    ),
                  ),
                  Expanded(child: body),
                  if (!_new && _openId == null)
                    Padding(
                      padding: const EdgeInsets.all(12),
                      child: SizedBox(
                        width: double.infinity,
                        child: FilledButton.icon(
                          style: FilledButton.styleFrom(backgroundColor: _violet, padding: const EdgeInsets.symmetric(vertical: 14)),
                          onPressed: () => setState(() => _new = true),
                          icon: const Icon(Icons.add_comment_outlined),
                          label: Text(tr('Nouvelle demande')),
                        ),
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
}

class _ConversationTile extends StatelessWidget {
  const _ConversationTile({required this.c, required this.onTap, this.company});

  final Map<String, dynamic> c;
  final VoidCallback onTap;
  final String? company;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final unread = (c['unread'] as num?)?.toInt() ?? 0;
    final closed = c['status'] == 'closed';
    final at = DateTime.tryParse('${c['last_message_at'] ?? ''}')?.toLocal();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: unread > 0 ? _violet.withValues(alpha: .07) : Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Colors.black.withValues(alpha: .06)),
            ),
            child: Row(
              children: [
                DialogIconBadge(icon: Icon(closed ? Icons.check_circle_outline : Icons.forum_outlined), color: closed ? YColors.success : _violet, size: 38),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(
                              tr('${c['subject']}'),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(fontWeight: unread > 0 ? FontWeight.w800 : FontWeight.w600),
                            ),
                          ),
                          if (at != null) Text(_ago(at), style: TextStyle(color: hint, fontSize: 11.5)),
                        ],
                      ),
                      if (company != null)
                        Text(
                          tr(company!),
                          style: const TextStyle(color: _violet, fontSize: 12, fontWeight: FontWeight.w600),
                        ),
                      Text(
                        tr('${c['last_from_platform'] == true ? 'Support : ' : ''}${c['last_message'] ?? ''}'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(color: hint, fontSize: 12.5),
                      ),
                    ],
                  ),
                ),
                if (unread > 0) ...[
                  const SizedBox(width: 8),
                  CircleAvatar(
                    radius: 10,
                    backgroundColor: _violet,
                    child: Text('$unread', style: const TextStyle(color: Colors.white, fontSize: 11)),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}

String _ago(DateTime d) {
  final diff = DateTime.now().difference(d);
  if (diff.inMinutes < 1) return 'à l\'instant';
  if (diff.inHours < 1) return '${diff.inMinutes} min';
  if (diff.inDays < 1) return DateFormat('HH:mm').format(d);
  if (diff.inDays < 7) return DateFormat('EEE', 'fr').format(d);
  return DateFormat('d MMM', 'fr').format(d);
}

class _NewConversation extends StatefulWidget {
  const _NewConversation({required this.categories, required this.onCreated});

  final Map<String, dynamic> categories;
  final void Function(Map<String, dynamic>) onCreated;

  @override
  State<_NewConversation> createState() => _NewConversationState();
}

class _NewConversationState extends State<_NewConversation> {
  final _subject = TextEditingController();
  final _body = TextEditingController();
  String _cat = 'question';
  bool _busy = false;

  Future<void> _send() async {
    if (_subject.text.trim().isEmpty || _body.text.trim().isEmpty) {
      toast(context, tr('Indiquez un sujet et votre message.'), error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final r = asMap(
        await context.read<Session>().api.post('support/conversations', {
          'subject': _subject.text.trim(),
          'body': _body.text.trim(),
          'category': _cat,
          'context': {'version': context.read<Session>().bootstrap?['app']?['version'], 'platform': clientPlatform()},
        }),
      );
      widget.onCreated(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => ListView(
    padding: const EdgeInsets.all(16),
    children: [
      Text(tr('Comment pouvons-nous vous aider ?'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
      const SizedBox(height: 4),
      Text(tr('Notre équipe vous répond ici, en direct.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
      const SizedBox(height: 14),
      Wrap(
        spacing: 6,
        runSpacing: 6,
        children: [for (final e in widget.categories.entries) ChoiceChip(label: Text(tr('${e.value}')), selected: _cat == e.key, onSelected: (_) => setState(() => _cat = e.key))],
      ),
      const SizedBox(height: 12),
      TextField(
        controller: _subject,
        decoration: InputDecoration(labelText: tr('Sujet')),
      ),
      const SizedBox(height: 10),
      TextField(
        controller: _body,
        minLines: 5,
        maxLines: 10,
        decoration: InputDecoration(labelText: tr('Décrivez votre demande'), alignLabelWithHint: true),
      ),
      const SizedBox(height: 14),
      FilledButton.icon(
        style: FilledButton.styleFrom(backgroundColor: _violet, padding: const EdgeInsets.symmetric(vertical: 14)),
        onPressed: _busy ? null : _send,
        icon: const Icon(Icons.send_rounded),
        label: Text(tr('Envoyer au support')),
      ),
    ],
  );
}

/// Fil de discussion en direct (société ou éditeur selon [platform]), actualisé toutes les 4 secondes.
class SupportThread extends StatefulWidget {
  const SupportThread({super.key, required this.path, required this.platform, this.onChanged, this.header});

  final String path;
  final bool platform;
  final VoidCallback? onChanged;
  final Widget Function(Map<String, dynamic> conv)? header;

  @override
  State<SupportThread> createState() => _SupportThreadState();
}

class _SupportThreadState extends State<SupportThread> {
  Map<String, dynamic>? _conv;
  final List<Map<String, dynamic>> _msgs = [];
  final _input = TextEditingController();
  final _scroll = ScrollController();
  Timer? _timer;
  bool _sending = false;

  int get _last => _msgs.isEmpty ? 0 : _msgs.last['id'] as int;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 4), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _merge(Map<String, dynamic> r) {
    final had = _msgs.length;
    final ids = {for (final m in _msgs) m['id']};
    for (final m in (r['messages'] as List? ?? const [])) {
      final mm = asMap(m);
      if (!ids.contains(mm['id'])) _msgs.add(mm);
    }
    _conv = asMap(r['data']);
    if (_msgs.length != had) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) _scroll.animateTo(_scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      });
      widget.onChanged?.call();
    }
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get(widget.path, query: {'after': '$_last'}));
      if (mounted) setState(() => _merge(r));
    } catch (_) {}
  }

  Future<void> _send({String? status}) async {
    final t = _input.text.trim();
    if (t.isEmpty) return;
    setState(() => _sending = true);
    try {
      final r = asMap(await context.read<Session>().api.post('${widget.path}/messages', {'body': t, 'after': _last, 'status': ?status}));
      _input.clear();
      if (mounted) setState(() => _merge(r));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _close() async {
    final rating = await showDialog<int>(
      context: context,
      builder: (c) => YAlertDialog(
        icon: const Icon(Icons.star_rounded),
        title: Text(tr('Clore la demande')),
        content: Text(tr('Comment s\'est passé l\'échange avec le support ?')),
        actions: [
          for (var i = 1; i <= 5; i++)
            IconButton(
              onPressed: () => Navigator.pop(c, i),
              icon: const Icon(Icons.star_rounded, color: Color(0xFFFFC531)),
            ),
        ],
      ),
    );
    if (rating == null || !mounted) return;
    await context.read<Session>().api.post('${widget.path}/close', {'rating': rating});
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final c = _conv;
    if (c == null) return const Center(child: CircularProgressIndicator());
    final hint = Theme.of(context).hintColor;
    final closed = c['status'] == 'closed';
    return Column(
      children: [
        if (widget.header != null) widget.header!(c),
        Container(
          padding: const EdgeInsets.fromLTRB(14, 10, 8, 10),
          decoration: BoxDecoration(
            border: Border(bottom: BorderSide(color: Colors.black.withValues(alpha: .06))),
          ),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      tr('${c['subject']}'),
                      style: const TextStyle(fontWeight: FontWeight.w800),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    Text(tr('${c['category_label']} · ${closed ? 'close' : (c['status'] == 'waiting' ? 'en attente de votre réponse' : 'ouverte')}'), style: TextStyle(color: hint, fontSize: 12)),
                  ],
                ),
              ),
              if (!closed && !widget.platform) TextButton(onPressed: _close, child: Text(tr('Clore'))),
              if (widget.platform)
                PopupMenuButton<String>(
                  tooltip: tr('Statut'),
                  onSelected: (s) async {
                    await context.read<Session>().api.put(widget.path, {'status': s});
                    _load();
                  },
                  itemBuilder: (_) => [
                    PopupMenuItem(value: 'open', child: Text(tr('Ouverte'))),
                    PopupMenuItem(value: 'waiting', child: Text(tr('En attente du client'))),
                    PopupMenuItem(value: 'closed', child: Text(tr('Close'))),
                  ],
                  child: Padding(
                    padding: const EdgeInsets.all(8),
                    child: StatusChip(tr(closed ? 'Close' : (c['status'] == 'waiting' ? 'En attente' : 'Ouverte')), color: closed ? YColors.success : _violet),
                  ),
                ),
            ],
          ),
        ),
        Expanded(
          child: ListView.builder(
            controller: _scroll,
            padding: const EdgeInsets.all(14),
            itemCount: _msgs.length,
            itemBuilder: (context, i) {
              final m = _msgs[i];
              final mine = (m['from_platform'] == true) == widget.platform;
              final at = DateTime.tryParse('${m['created_at']}')?.toLocal();
              return Align(
                alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 320),
                  child: Container(
                    margin: const EdgeInsets.only(bottom: 8),
                    padding: const EdgeInsets.fromLTRB(12, 9, 12, 7),
                    decoration: BoxDecoration(
                      color: mine ? _violet : Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .6),
                      borderRadius: BorderRadius.only(
                        topLeft: const Radius.circular(16),
                        topRight: const Radius.circular(16),
                        bottomLeft: Radius.circular(mine ? 16 : 4),
                        bottomRight: Radius.circular(mine ? 4 : 16),
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (!mine)
                          Text(
                            tr('${m['author']}'),
                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12, color: _violet),
                          ),
                        SelectableText('${m['body']}', style: TextStyle(color: mine ? Colors.white : null, height: 1.35)),
                        const SizedBox(height: 2),
                        Text(at == null ? '' : DateFormat('d MMM HH:mm', 'fr').format(at), style: TextStyle(fontSize: 10.5, color: mine ? Colors.white70 : hint)),
                      ],
                    ),
                  ),
                ),
              );
            },
          ),
        ),
        Container(
          padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: Colors.black.withValues(alpha: .06))),
          ),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _input,
                  minLines: 1,
                  maxLines: 5,
                  textInputAction: TextInputAction.send,
                  onSubmitted: (_) => _send(),
                  decoration: InputDecoration(
                    hintText: tr(closed ? 'Écrire pour rouvrir la demande…' : 'Votre message…'),
                    isDense: true,
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(22)),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filled(
                style: IconButton.styleFrom(backgroundColor: _violet),
                onPressed: _sending ? null : _send,
                icon: const Icon(Icons.send_rounded),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// La société choisit qui peut contacter le support (les administrateurs le peuvent toujours).
Future<void> showSupportContacts(BuildContext context) => showFormDialog(
  context,
  title: 'Contacts autorisés pour le support',
  subtitle: 'Les administrateurs peuvent toujours contacter le support',
  icon: Icons.support_agent_rounded,
  builder: (_) => const _ContactsForm(),
);

class _ContactsForm extends StatefulWidget {
  const _ContactsForm();

  @override
  State<_ContactsForm> createState() => _ContactsFormState();
}

class _ContactsFormState extends State<_ContactsForm> {
  List<Map<String, dynamic>>? _users;
  Set<int> _sel = {};

  @override
  void initState() {
    super.initState();
    () async {
      final api = context.read<Session>().api;
      final u = await api.get('users');
      final s = asMap(await api.get('support/status'));
      if (!mounted) return;
      setState(() {
        _users = [for (final x in (u['data'] as List)) asMap(x)];
        _sel = {for (final i in (s['contacts'] as List? ?? const [])) (i as num).toInt()};
      });
    }();
  }

  @override
  Widget build(BuildContext context) {
    final u = _users;
    if (u == null) {
      return const Padding(
        padding: EdgeInsets.all(30),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(
          child: ListView(
            shrinkWrap: true,
            padding: const EdgeInsets.symmetric(vertical: 8),
            children: [
              for (final x in u)
                CheckboxListTile(
                  value: _sel.contains(x['id']),
                  secondary: InitialsAvatar('${x['name']}', size: 32),
                  title: Text(tr('${x['name']}')),
                  subtitle: Text(tr('${x['email'] ?? ''}')),
                  onChanged: (v) => setState(() => v == true ? _sel.add(x['id'] as int) : _sel.remove(x['id'])),
                ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.end,
            children: [
              TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
              const SizedBox(width: 8),
              FilledButton(
                onPressed: () async {
                  try {
                    await context.read<Session>().api.put('support/contacts', {'user_ids': _sel.toList()});
                    if (context.mounted) {
                      toast(context, tr('Contacts du support enregistrés.'));
                      Navigator.pop(context);
                    }
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

/// Écran éditeur : toutes les demandes des sociétés, réponse en direct.
class AdminSupportScreen extends StatefulWidget {
  const AdminSupportScreen({super.key});

  @override
  State<AdminSupportScreen> createState() => _AdminSupportScreenState();
}

class _AdminSupportScreenState extends State<AdminSupportScreen> {
  Map<String, dynamic>? _d;
  String _status = 'active';
  String _q = '';
  int? _open;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 8), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('admin/support/conversations', query: {'status': _status == 'all' ? '' : _status, 'q': _q}));
      if (mounted) setState(() => _d = r);
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) return const Center(child: CircularProgressIndicator());
    final counts = asMap(d['counts']);
    final list = [for (final c in (d['data'] as List)) asMap(c)];
    final wide = MediaQuery.sizeOf(context).width > 1000;
    final listPane = Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
          child: Column(
            children: [
              SegmentedButton<String>(
                showSelectedIcon: false,
                segments: [
                  ButtonSegment(value: 'active', label: Text(tr('En cours (${counts['active'] ?? 0})'))),
                  ButtonSegment(value: 'closed', label: Text(tr('Closes'))),
                  ButtonSegment(value: 'all', label: Text(tr('Toutes'))),
                ],
                selected: {_status},
                onSelectionChanged: (s) {
                  setState(() => _status = s.first);
                  _load();
                },
              ),
              const SizedBox(height: 10),
              SearchField(
                onChanged: (v) {
                  _q = v.trim();
                  _load();
                },
                hint: tr('Rechercher un sujet…'),
              ),
            ],
          ),
        ),
        Expanded(
          child: list.isEmpty
              ? EmptyState(icon: Icons.support_agent_rounded, title: 'Aucune demande', message: 'Les demandes de vos clients apparaissent ici en temps réel.')
              : ListView(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  children: [
                    for (final c in list)
                      _ConversationTile(
                        c: c,
                        company: '${c['company']}${c['user'] != null ? ' · ${c['user']}' : ''}',
                        onTap: () {
                          setState(() => _open = c['id'] as int);
                          if (!wide) {
                            Navigator.of(context).push(
                              MaterialPageRoute(
                                builder: (_) => Scaffold(
                                  appBar: AppBar(title: Text(tr('${c['company']}'))),
                                  body: SupportThread(path: 'admin/support/conversations/${c['id']}', platform: true, onChanged: _load),
                                ),
                              ),
                            );
                          }
                        },
                      ),
                  ],
                ),
        ),
      ],
    );
    return Column(
      children: [
        PageHeader(
          title: tr('Support clients'),
          subtitle: tr('${counts['unread'] ?? 0} message(s) non lu(s) · réponses en direct dans l\'ERP de vos clients'),
          actions: [IconButton.outlined(onPressed: _load, icon: const Icon(Icons.refresh))],
        ),
        Expanded(
          child: wide
              ? Row(
                  children: [
                    SizedBox(width: 420, child: listPane),
                    const VerticalDivider(width: 1),
                    Expanded(
                      child: _open == null
                          ? EmptyState(icon: Icons.forum_outlined, title: 'Choisissez une demande', message: 'Le fil s\'actualise automatiquement toutes les quelques secondes.')
                          : SupportThread(key: ValueKey(_open), path: 'admin/support/conversations/$_open', platform: true, onChanged: _load),
                    ),
                  ],
                )
              : listPane,
        ),
      ],
    );
  }
}
