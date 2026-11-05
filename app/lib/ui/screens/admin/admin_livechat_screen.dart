import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/json.dart';
import '../../../i18n/i18n.dart';
import '../../../state/session.dart';
import '../../theme.dart';
import '../../widgets/common.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const _accent = Color(0xFF0E7C86);
const _accentSoft = Color(0xFF14B8A6);
const _whatsapp = Color(0xFF25D366);

String _digits(String? s) => (s ?? '').replaceAll(RegExp(r'\D'), '');

String _ago(DateTime d) {
  final diff = DateTime.now().difference(d);
  if (diff.inMinutes < 1) return tr('à l\'instant');
  if (diff.inHours < 1) return tr('${diff.inMinutes} min');
  if (diff.inDays < 1 && d.day == DateTime.now().day) return DateFormat('HH:mm').format(d);
  if (diff.inDays < 7) return DateFormat('EEE HH:mm', I18n.dateLocale).format(d);
  return DateFormat('d MMM', I18n.dateLocale).format(d);
}

String _visitorName(Map<String, dynamic> c) {
  final n = '${c['name'] ?? ''}'.trim();
  return n.isEmpty ? tr('Visiteur') : n;
}

Future<void> _launch(BuildContext context, Uri uri) async {
  try {
    final ok = await safeLaunchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) toast(context, tr('Impossible d\'ouvrir ce lien.'), error: true);
  } catch (_) {
    if (context.mounted) toast(context, tr('Impossible d\'ouvrir ce lien.'), error: true);
  }
}

/// Éditeur : conversations du live chat du site public, réponse en direct.
class AdminLiveChatScreen extends StatefulWidget {
  const AdminLiveChatScreen({super.key});

  @override
  State<AdminLiveChatScreen> createState() => _AdminLiveChatScreenState();
}

class _AdminLiveChatScreenState extends State<AdminLiveChatScreen> {
  Map<String, dynamic>? _d;
  Object? _error;
  String _status = 'open';
  int? _open;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 10), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = asMap(await context.read<Session>().api.get('admin/livechat', query: {'status': _status == 'all' ? '' : _status}));
      if (mounted) {
        setState(() {
          _d = r;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted && _d == null) setState(() => _error = e);
    }
  }

  Future<void> _settings() async {
    final d = _d;
    if (d == null) return;
    final saved = await showDialog<bool>(
      context: context,
      builder: (_) => _SettingsDialog(welcome: '${d['welcome'] ?? ''}', enabled: d['enabled'] != false),
    );
    if (saved == true) _load();
  }

  Future<void> _toggleEnabled(bool v) async {
    setState(() => _d = {...?_d, 'enabled': v});
    try {
      await context.read<Session>().api.put('admin/livechat/settings', {'enabled': v});
      if (mounted) toast(context, tr(v ? 'Chat activé sur le site.' : 'Chat désactivé sur le site.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
    _load();
  }

  void _select(Map<String, dynamic> c, bool wide) {
    final id = c['id'] as int;
    setState(() => _open = id);
    if (!wide) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => Scaffold(
            appBar: AppBar(title: Text(tr(_visitorName(c)))),
            body: _LiveChatThread(chatId: id, summary: c, onChanged: _load, compact: true),
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) {
      return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    }
    final list = [for (final c in (d['data'] as List? ?? const [])) asMap(c)];
    final unread = (d['unread'] as num?)?.toInt() ?? 0;
    final open = (d['open'] as num?)?.toInt() ?? 0;
    final enabled = d['enabled'] != false;
    final wide = MediaQuery.sizeOf(context).width > 1000;
    final selected = list.where((c) => c['id'] == _open).firstOrNull;

    final listPane = Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
          child: SizedBox(
            width: double.infinity,
            child: SegmentedButton<String>(
              showSelectedIcon: false,
              segments: [
                ButtonSegment(value: 'open', label: Text(tr('Ouvertes ($open)'))),
                ButtonSegment(value: 'closed', label: Text(tr('Fermées'))),
                ButtonSegment(value: 'all', label: Text(tr('Toutes'))),
              ],
              selected: {_status},
              onSelectionChanged: (s) {
                setState(() => _status = s.first);
                _load();
              },
            ),
          ),
        ),
        Expanded(
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 250),
            child: list.isEmpty
                ? EmptyState(
                    key: ValueKey('empty-$_status'),
                    icon: Icons.chat_bubble_outline_rounded,
                    title: 'Aucune conversation',
                    message: 'Les messages des visiteurs du site apparaissent ici en temps réel.',
                  )
                : ListView.builder(
                    key: ValueKey('list-$_status'),
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    itemCount: list.length,
                    itemBuilder: (context, i) => AnimatedFadeIn(
                      key: ValueKey(list[i]['id']),
                      index: i,
                      child: _ChatTile(c: list[i], selected: wide && list[i]['id'] == _open, onTap: () => _select(list[i], wide)),
                    ),
                  ),
          ),
        ),
      ],
    );

    return Column(
      children: [
        PageHeader(
          title: tr('Live chat du site'),
          icon: Icons.forum_rounded,
          subtitle: tr('$unread message(s) non lu(s) · $open conversation(s) ouverte(s)'),
          actions: [
            Container(
              padding: const EdgeInsets.only(left: 12, right: 4),
              decoration: BoxDecoration(
                color: (enabled ? YColors.success : YColors.muted).withValues(alpha: .08),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    tr(enabled ? 'Widget actif' : 'Widget désactivé'),
                    style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: enabled ? YColors.success : YColors.muted),
                  ),
                  Switch(value: enabled, onChanged: _toggleEnabled),
                ],
              ),
            ),
            IconButton.outlined(tooltip: tr('Réglages du chat'), onPressed: _settings, icon: const Icon(Icons.tune_rounded)),
            IconButton.outlined(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
          ],
        ),
        Expanded(
          child: wide
              ? Row(
                  children: [
                    SizedBox(width: 400, child: listPane),
                    const VerticalDivider(width: 1),
                    Expanded(
                      child: AnimatedSwitcher(
                        duration: const Duration(milliseconds: 260),
                        switchInCurve: Curves.easeOutCubic,
                        transitionBuilder: (child, a) => FadeTransition(
                          opacity: a,
                          child: SlideTransition(position: Tween(begin: const Offset(.02, 0), end: Offset.zero).animate(a), child: child),
                        ),
                        child: _open == null
                            ? const EmptyState(
                                key: ValueKey('none'),
                                icon: Icons.mark_chat_unread_outlined,
                                title: 'Choisissez une conversation',
                                message: 'Le fil s\'actualise automatiquement toutes les quelques secondes.',
                              )
                            : _LiveChatThread(key: ValueKey(_open), chatId: _open!, summary: selected, onChanged: _load),
                      ),
                    ),
                  ],
                )
              : listPane,
        ),
      ],
    );
  }
}

class _OnlineDot extends StatelessWidget {
  const _OnlineDot();

  static const double size = 11;

  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(
      color: YColors.success,
      shape: BoxShape.circle,
      border: Border.all(color: Theme.of(context).colorScheme.surface, width: 2),
      boxShadow: [BoxShadow(color: YColors.success.withValues(alpha: .45), blurRadius: 6)],
    ),
  );
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.name, required this.online, this.size = 42});

  final String name;
  final bool online;
  final double size;

  @override
  Widget build(BuildContext context) => SizedBox(
    width: size,
    height: size,
    child: Stack(
      clipBehavior: Clip.none,
      children: [
        InitialsAvatar(name, size: size),
        Positioned(
          right: -2,
          bottom: -2,
          child: AnimatedScale(
            scale: online ? 1 : 0,
            duration: const Duration(milliseconds: 250),
            curve: Curves.easeOutBack,
            child: const _OnlineDot(),
          ),
        ),
      ],
    ),
  );
}

class _ChatTile extends StatelessWidget {
  const _ChatTile({required this.c, required this.onTap, this.selected = false});

  final Map<String, dynamic> c;
  final VoidCallback onTap;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final unread = (c['unread'] as num?)?.toInt() ?? 0;
    final closed = c['status'] == 'closed';
    final online = c['visitor_online'] == true;
    final at = DateTime.tryParse('${c['last_message_at'] ?? ''}')?.toLocal();
    final last = asMap(c['last']);
    final contact = [c['email'], c['phone']].where((v) => v != null && '$v'.trim().isNotEmpty).join(' · ');
    final page = '${c['page'] ?? ''}'.trim();
    final mobile = c['device'] == 'mobile';
    final name = _visitorName(c);
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 220),
        decoration: BoxDecoration(
          color: selected ? _accent.withValues(alpha: .10) : (unread > 0 ? _accent.withValues(alpha: .05) : theme.cardColor),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: selected ? _accent.withValues(alpha: .45) : theme.dividerColor.withValues(alpha: .4)),
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _Avatar(name: name, online: online),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                tr(name),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(fontWeight: unread > 0 ? FontWeight.w800 : FontWeight.w600),
                              ),
                            ),
                            if (at != null) Text(_ago(at), style: TextStyle(color: unread > 0 ? _accent : hint, fontSize: 11.5, fontWeight: unread > 0 ? FontWeight.w700 : null)),
                          ],
                        ),
                        if (contact.isNotEmpty)
                          Text(tr(contact), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: _accent, fontSize: 12, fontWeight: FontWeight.w600)),
                        const SizedBox(height: 2),
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                tr('${last['from'] == 'admin' ? 'Vous : ' : ''}${last['body'] ?? ''}'),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(color: unread > 0 ? theme.colorScheme.onSurface : hint, fontSize: 12.5),
                              ),
                            ),
                            AnimatedSwitcher(
                              duration: const Duration(milliseconds: 220),
                              transitionBuilder: (child, a) => ScaleTransition(scale: a, child: child),
                              child: unread > 0
                                  ? Container(
                                      key: ValueKey(unread),
                                      margin: const EdgeInsets.only(left: 8),
                                      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                                      decoration: BoxDecoration(color: _accent, borderRadius: BorderRadius.circular(10)),
                                      child: Text('$unread', style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                                    )
                                  : const SizedBox.shrink(),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Row(
                          children: [
                            Icon(mobile ? Icons.smartphone_rounded : Icons.desktop_windows_outlined, size: 13, color: hint),
                            const SizedBox(width: 4),
                            Text(tr(mobile ? 'Mobile' : 'Ordinateur'), style: TextStyle(color: hint, fontSize: 11.5)),
                            if (page.isNotEmpty) ...[
                              const SizedBox(width: 10),
                              Icon(Icons.link_rounded, size: 13, color: hint),
                              const SizedBox(width: 3),
                              Expanded(
                                child: Text(tr(page), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 11.5)),
                              ),
                            ] else
                              const Spacer(),
                            if (closed) StatusChip(tr('Fermée'), color: YColors.muted),
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
      ),
    );
  }
}

/// Fil d'une conversation : actualisé toutes les 4 secondes (messages après le dernier reçu).
class _LiveChatThread extends StatefulWidget {
  const _LiveChatThread({super.key, required this.chatId, this.summary, this.onChanged, this.compact = false});

  final int chatId;
  final Map<String, dynamic>? summary;
  final VoidCallback? onChanged;
  final bool compact;

  @override
  State<_LiveChatThread> createState() => _LiveChatThreadState();
}

class _LiveChatThreadState extends State<_LiveChatThread> {
  Map<String, dynamic>? _chat;
  final List<Map<String, dynamic>> _msgs = [];
  final _input = TextEditingController();
  final _scroll = ScrollController();
  final _focus = FocusNode();
  Timer? _timer;
  bool _sending = false;
  bool _loading = false;
  int _initial = -1;

  String get _path => 'admin/livechat/${widget.chatId}';
  int get _last => _msgs.isEmpty ? 0 : (_msgs.last['id'] as num).toInt();

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 4), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _input.dispose();
    _scroll.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _merge(Map<String, dynamic> r) {
    final had = _msgs.length;
    final ids = {for (final m in _msgs) m['id']};
    for (final m in (r['messages'] as List? ?? const [])) {
      final mm = asMap(m);
      if (!ids.contains(mm['id'])) _msgs.add(mm);
    }
    final chat = asMap(r['chat']);
    if (chat.isNotEmpty) _chat = chat;
    if (_initial < 0) _initial = _msgs.length;
    if (_msgs.length != had) {
      final first = had == 0;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!_scroll.hasClients) return;
        if (first) {
          _scroll.jumpTo(_scroll.position.maxScrollExtent);
        } else {
          _scroll.animateTo(_scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 300), curve: Curves.easeOutCubic);
        }
      });
      if (!first) widget.onChanged?.call();
    }
  }

  Future<void> _load() async {
    if (_loading) return;
    _loading = true;
    try {
      final r = asMap(await context.read<Session>().api.get(_path, query: {'after': '$_last'}));
      if (mounted) setState(() => _merge(r));
    } catch (_) {
    } finally {
      _loading = false;
    }
  }

  Future<void> _send() async {
    final t = _input.text.trim();
    if (t.isEmpty || _sending) return;
    setState(() => _sending = true);
    try {
      final r = asMap(await context.read<Session>().api.post('$_path?after=$_last', {'message': t}));
      _input.clear();
      if (mounted) setState(() => _merge(r));
      widget.onChanged?.call();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
      _focus.requestFocus();
    }
  }

  Future<void> _close() async {
    final ok = await confirm(context, tr('Fermer la conversation'), tr('Le visiteur pourra toujours écrire à nouveau : la conversation se rouvrira alors.'), ok: tr('Fermer'));
    if (!ok || !mounted) return;
    try {
      await context.read<Session>().api.post('$_path/close');
      if (mounted) toast(context, tr('Conversation fermée.'));
      await _load();
      widget.onChanged?.call();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  KeyEventResult _onKey(FocusNode node, KeyEvent e) {
    if (e is KeyDownEvent && (e.logicalKey == LogicalKeyboardKey.enter || e.logicalKey == LogicalKeyboardKey.numpadEnter) && !HardwareKeyboard.instance.isShiftPressed) {
      _send();
      return KeyEventResult.handled;
    }
    return KeyEventResult.ignored;
  }

  Widget _action(IconData icon, String label, Color color, VoidCallback onTap) => Padding(
    padding: const EdgeInsets.only(left: 6),
    child: widget.compact
        ? IconButton(
            tooltip: tr(label),
            onPressed: onTap,
            style: IconButton.styleFrom(backgroundColor: color.withValues(alpha: .10), foregroundColor: color),
            icon: Icon(icon, size: 20),
          )
        : OutlinedButton.icon(
            style: OutlinedButton.styleFrom(foregroundColor: color, side: BorderSide(color: color.withValues(alpha: .35)), visualDensity: VisualDensity.compact),
            onPressed: onTap,
            icon: Icon(icon, size: 18),
            label: Text(tr(label)),
          ),
  );

  Widget _header(Map<String, dynamic> c) {
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final online = c['visitor_online'] == true;
    final closed = c['status'] == 'closed';
    final email = '${c['email'] ?? ''}'.trim();
    final phone = '${c['phone'] ?? ''}'.trim();
    final page = '${c['page'] ?? ''}'.trim();
    final mobile = c['device'] == 'mobile';
    final name = _visitorName(c);
    final meta = [
      if (email.isNotEmpty) email,
      if (phone.isNotEmpty) phone,
      tr(mobile ? 'Mobile' : 'Ordinateur'),
      if (page.isNotEmpty) page,
    ].join(' · ');
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 12, 12),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [_accent.withValues(alpha: .07), _accentSoft.withValues(alpha: .02)]),
        border: Border(bottom: BorderSide(color: theme.dividerColor.withValues(alpha: .4))),
      ),
      child: Row(
        children: [
          if (!widget.compact) ...[_Avatar(name: name, online: online, size: 44), const SizedBox(width: 12)],
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    if (!widget.compact)
                      Flexible(
                        child: Text(tr(name), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                      ),
                    if (!widget.compact) const SizedBox(width: 8),
                    AnimatedSwitcher(
                      duration: const Duration(milliseconds: 250),
                      child: StatusChip(
                        key: ValueKey('$online$closed'),
                        tr(closed ? 'Fermée' : (online ? 'En ligne' : 'Hors ligne')),
                        color: closed ? YColors.muted : (online ? YColors.success : YColors.warning),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 3),
                Text(tr(meta), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12)),
              ],
            ),
          ),
          if (email.isNotEmpty) _action(Icons.mail_outline_rounded, 'E-mail', YColors.blue, () => _launch(context, Uri(scheme: 'mailto', path: email, query: 'subject=${Uri.encodeComponent(tr('Votre message sur notre site'))}'))),
          if (_digits(phone).isNotEmpty) _action(Icons.chat_outlined, 'WhatsApp', _whatsapp, () => _launch(context, Uri.parse('https://wa.me/${_digits(phone)}'))),
          if (!closed) _action(Icons.check_circle_outline_rounded, 'Fermer', YColors.muted, _close),
        ],
      ),
    );
  }

  Widget _bubble(Map<String, dynamic> m, int i) {
    final theme = Theme.of(context);
    final mine = m['from'] == 'admin';
    final at = DateTime.tryParse('${m['at'] ?? ''}')?.toLocal();
    final prevAt = i > 0 ? DateTime.tryParse('${_msgs[i - 1]['at'] ?? ''}')?.toLocal() : null;
    final newDay = at != null && (prevAt == null || prevAt.year != at.year || prevAt.month != at.month || prevAt.day != at.day);
    final sameSender = i > 0 && _msgs[i - 1]['from'] == m['from'] && !newDay;
    Widget bubble = Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440),
        child: Container(
          margin: EdgeInsets.only(top: sameSender ? 2 : 8, left: mine ? 48 : 0, right: mine ? 0 : 48),
          padding: const EdgeInsets.fromLTRB(13, 9, 13, 7),
          decoration: BoxDecoration(
            gradient: mine ? const LinearGradient(colors: [_accent, _accentSoft], begin: Alignment.topLeft, end: Alignment.bottomRight) : null,
            color: mine ? null : theme.colorScheme.surfaceContainerHighest.withValues(alpha: .7),
            borderRadius: BorderRadius.only(
              topLeft: const Radius.circular(16),
              topRight: const Radius.circular(16),
              bottomLeft: Radius.circular(mine ? 16 : 4),
              bottomRight: Radius.circular(mine ? 4 : 16),
            ),
            boxShadow: mine ? [BoxShadow(color: _accent.withValues(alpha: .18), blurRadius: 8, offset: const Offset(0, 3))] : null,
          ),
          child: Column(
            crossAxisAlignment: mine ? CrossAxisAlignment.end : CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              SelectableText('${m['body'] ?? ''}', style: TextStyle(color: mine ? Colors.white : null, height: 1.35)),
              const SizedBox(height: 2),
              Text(
                at == null ? '' : DateFormat('HH:mm').format(at),
                style: TextStyle(fontSize: 10.5, color: mine ? Colors.white70 : theme.hintColor),
              ),
            ],
          ),
        ),
      ),
    );
    if (i >= _initial && _initial >= 0) bubble = AnimatedFadeIn(key: ValueKey(m['id']), offset: 10, child: bubble);
    if (!newDay) return bubble;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 10),
          child: Center(
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
              decoration: BoxDecoration(color: theme.colorScheme.onSurface.withValues(alpha: .05), borderRadius: BorderRadius.circular(10)),
              child: Text(DateFormat('EEEE d MMMM', I18n.dateLocale).format(at), style: TextStyle(fontSize: 11.5, color: theme.hintColor)),
            ),
          ),
        ),
        bubble,
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final c = _chat ?? widget.summary;
    if (c == null) return const Center(child: CircularProgressIndicator());
    final theme = Theme.of(context);
    final closed = c['status'] == 'closed';
    return Column(
      children: [
        _header(c),
        Expanded(
          child: _chat == null
              ? const Center(child: CircularProgressIndicator())
              : _msgs.isEmpty
              ? const EmptyState(icon: Icons.chat_bubble_outline_rounded, title: 'Aucun message')
              : ListView.builder(
                  controller: _scroll,
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                  itemCount: _msgs.length,
                  itemBuilder: (context, i) => _bubble(_msgs[i], i),
                ),
        ),
        Container(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
          decoration: BoxDecoration(
            color: theme.colorScheme.surface,
            border: Border(top: BorderSide(color: theme.dividerColor.withValues(alpha: .4))),
          ),
          child: SafeArea(
            top: false,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: Focus(
                    onKeyEvent: _onKey,
                    child: TextField(
                      controller: _input,
                      focusNode: _focus,
                      minLines: 1,
                      maxLines: 6,
                      keyboardType: TextInputType.multiline,
                      decoration: InputDecoration(
                        hintText: tr(closed ? 'Répondre rouvrira la conversation…' : 'Votre réponse… (Entrée pour envoyer, Maj+Entrée pour aller à la ligne)'),
                        isDense: true,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(22)),
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 200),
                  transitionBuilder: (child, a) => ScaleTransition(scale: a, child: child),
                  child: _sending
                      ? const SizedBox(key: ValueKey('busy'), width: 44, height: 44, child: Padding(padding: EdgeInsets.all(12), child: CircularProgressIndicator(strokeWidth: 2)))
                      : IconButton.filled(
                          key: const ValueKey('send'),
                          tooltip: tr('Envoyer'),
                          style: IconButton.styleFrom(backgroundColor: _accent),
                          onPressed: _send,
                          icon: const Icon(Icons.send_rounded),
                        ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _SettingsDialog extends StatefulWidget {
  const _SettingsDialog({required this.welcome, required this.enabled});

  final String welcome;
  final bool enabled;

  @override
  State<_SettingsDialog> createState() => _SettingsDialogState();
}

class _SettingsDialogState extends State<_SettingsDialog> {
  late final _welcome = TextEditingController(text: widget.welcome);
  late bool _enabled = widget.enabled;
  bool _busy = false;

  @override
  void dispose() {
    _welcome.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      await context.read<Session>().api.put('admin/livechat/settings', {'welcome': _welcome.text.trim(), 'enabled': _enabled});
      if (!mounted) return;
      toast(context, tr('Réglages du chat enregistrés.'));
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => YAlertDialog(
    icon: const Icon(Icons.tune_rounded),
    title: Text(tr('Réglages du chat')),
    subtitle: tr('Widget de discussion du site public'),
    content: SizedBox(
      width: 460,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _enabled,
            onChanged: (v) => setState(() => _enabled = v),
            title: Text(tr('Afficher le chat sur le site')),
            subtitle: Text(tr('Les visiteurs peuvent vous écrire depuis chaque page.')),
          ),
          const SizedBox(height: 8),
          Field(
            label: 'Message d\'accueil',
            child: TextField(
              controller: _welcome,
              minLines: 2,
              maxLines: 4,
              maxLength: 300,
              decoration: InputDecoration(hintText: tr('Bonjour ! Comment pouvons-nous vous aider ?')),
            ),
          ),
        ],
      ),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
      FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
    ],
  );
}
