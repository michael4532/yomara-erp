import 'dart:async';
import 'dart:math' as math;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/api_client.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'mailbox/mail_theme.dart' show QrBox;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const _waGreen = Color(0xFF008069);
const _waDark = Color(0xFF075E54);
const _waOut = Color(0xFFD9FDD3);
const _waBg = Color(0xFFEFEAE2);
const _waBlue = Color(0xFF53BDEB);

/// Messagerie WhatsApp partagée par l'équipe (façon WhatsApp Web).
class WhatsappScreen extends StatefulWidget {
  const WhatsappScreen({super.key});

  @override
  State<WhatsappScreen> createState() => _WhatsappScreenState();
}

class _WhatsappScreenState extends State<WhatsappScreen> {
  Map<String, dynamic>? _info;
  List<Map<String, dynamic>> _convs = [];
  // '' = mes conversations (vue par défaut côté serveur : 'mine')
  String _filter = '';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _rk = 0;
  int? _openId;
  Map<String, dynamic>? _conv;
  Map<String, dynamic>? _partner;
  Map<String, dynamic>? _stats;
  List<Map<String, dynamic>> _msgs = [];
  Timer? _listTimer, _chatTimer, _searchTimer;
  bool _showInfo = true, _sending = false;
  Object? _error;
  final _text = TextEditingController();
  final _scroll = ScrollController();
  final _focus = FocusNode();
  double _wList = 360;

  ApiClient get api => context.read<Session>().api;

  @override
  void initState() {
    super.initState();
    _loadInfo();
    _listTimer = Timer.periodic(const Duration(seconds: 10), (_) => _loadList(silent: true));
    _chatTimer = Timer.periodic(const Duration(seconds: 4), (_) => _poll());
    _text.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _listTimer?.cancel();
    _chatTimer?.cancel();
    _searchTimer?.cancel();
    _text.dispose();
    _scroll.dispose();
    _focus.dispose();
    super.dispose();
  }

  Future<void> _loadInfo() async {
    try {
      final r = await api.get('whatsapp/inbox');
      if (!mounted) return;
      setState(() {
        _info = asMap(r['data']);
        _error = null;
      });
      if (_convs.isEmpty && (_info!['counts']?['mine'] ?? 0) == 0) _filter = 'all';
      await _loadList();
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _loadList({bool silent = false}) async {
    try {
      final r = await api.get('whatsapp/conversations', query: {'filter': _filter.isEmpty ? 'mine' : _filter, if (_q.isNotEmpty) 'q': _q});
      if (!mounted) return;
      setState(() => _convs = [for (final c in (r['data'] as List)) asMap(c)]);
    } catch (e) {
      if (!silent && mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _open(Map<String, dynamic> c) async {
    setState(() {
      _openId = c['id'] as int;
      _conv = c;
      _msgs = [];
      _partner = null;
      _stats = null;
    });
    try {
      final r = await api.get('whatsapp/conversations/${c['id']}');
      if (!mounted || _openId != c['id']) return;
      setState(() {
        _conv = asMap(r['data']);
        _msgs = [for (final m in (r['messages'] as List)) asMap(m)];
        _partner = r['partner'] == null ? null : asMap(r['partner']);
        _stats = r['stats'] == null ? null : asMap(r['stats']);
        final i = _convs.indexWhere((x) => x['id'] == c['id']);
        if (i >= 0) _convs[i] = {..._convs[i], 'unread': 0};
      });
      _toBottom();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _poll() async {
    final id = _openId;
    if (id == null || !mounted) return;
    try {
      final last = _msgs.isEmpty ? 0 : _msgs.last['id'];
      final r = await api.get('whatsapp/conversations/$id', query: {'after': '$last'});
      if (!mounted || _openId != id) return;
      final add = [for (final m in (r['messages'] as List)) asMap(m)];
      final conv = asMap(r['data']);
      // statuts (distribué / lu) des derniers messages envoyés
      final recent = await api.get('whatsapp/conversations/$id', query: {'limit': '20', 'peek': '1'});
      final upd = {for (final m in (recent['messages'] as List)) (m as Map)['id']: m};
      if (!mounted) return;
      setState(() {
        _conv = conv;
        _msgs = [for (final m in _msgs) upd.containsKey(m['id']) ? asMap(upd[m['id']]) : m, ...add.where((a) => !_msgs.any((m) => m['id'] == a['id']))];
      });
      if (add.isNotEmpty) _toBottom();
    } catch (_) {}
  }

  void _toBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) _scroll.animateTo(_scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
    });
  }

  Future<void> _send({String? template, List<String>? params, (List<int>, String)? file}) async {
    final c = _conv;
    if (c == null) return;
    final body = _text.text.trim();
    if (body.isEmpty && template == null && file == null) return;
    setState(() => _sending = true);
    try {
      final dynamic r = file != null
          ? await api.uploadForm('whatsapp/conversations/${c['id']}/messages', {if (body.isNotEmpty) 'body': body}, 'file', file.$1, file.$2)
          : await api.post('whatsapp/conversations/${c['id']}/messages', {if (template == null && body.isNotEmpty) 'body': body, 'template': ?template, 'params': ?params});
      _text.clear();
      if (!mounted) return;
      setState(() {
        _msgs.add(asMap(r['data']));
        _conv = asMap(r['conversation']);
      });
      _toBottom();
      unawaited(_loadList(silent: true));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _update(Map<String, dynamic> data, [String? ok]) async {
    try {
      final r = await api.put('whatsapp/conversations/${_conv!['id']}', data);
      if (mounted) setState(() => _conv = asMap(r['data']));
      if (ok != null && mounted) toast(context, ok);
      unawaited(_loadList(silent: true));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  // ------------------------------------------------------------------ vues

  @override
  Widget build(BuildContext context) {
    if (_info == null) return _error != null ? Center(child: Text(tr(errorText(_error!)))) : const Center(child: CircularProgressIndicator());
    return LayoutBuilder(builder: (context, c) {
      final phone = c.maxWidth < 760;
      if (phone) return _openId == null ? _listPane(phone: true) : _chatPane(back: true);
      final lw = _wList.clamp(300.0, math.max(300.0, math.min(520.0, c.maxWidth - 420))).toDouble();
      return Row(children: [
        SizedBox(width: lw, child: _listPane()),
        MouseRegion(
          cursor: SystemMouseCursors.resizeColumn,
          child: GestureDetector(
            behavior: HitTestBehavior.opaque,
            onHorizontalDragUpdate: (d) => setState(() => _wList = (lw + d.delta.dx).clamp(300.0, 520.0)),
            child: Container(width: 6, color: Colors.transparent, child: const VerticalDivider(width: 1)),
          ),
        ),
        Expanded(child: _openId == null ? _emptyChat() : _chatPane()),
        if (_openId != null && _showInfo && c.maxWidth >= 1250) ...[const VerticalDivider(width: 1), SizedBox(width: 300, child: _infoPane())],
      ]);
    });
  }

  Widget _emptyChat() => Container(
        color: const Color(0xFFF7F5F3),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Container(width: 96, height: 96, decoration: BoxDecoration(color: _waGreen.withValues(alpha: .1), shape: BoxShape.circle), child: const Icon(Icons.chat, color: _waGreen, size: 48)),
              const SizedBox(height: 18),
              Text(tr('WhatsApp de l\'entreprise'), style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Text(
                tr(_info!['provider'] == null
                    ? 'Connectez votre numéro WhatsApp Business (API Cloud de Meta, UltraMsg ou Green API) pour recevoir et répondre à vos clients ici, à plusieurs.'
                    : 'Choisissez une conversation, ou écrivez à un client. Les messages arrivent en direct et toute l\'équipe peut répondre.'),
                textAlign: TextAlign.center,
                style: TextStyle(color: Theme.of(context).hintColor, height: 1.5),
              ),
              const SizedBox(height: 18),
              Wrap(spacing: 10, runSpacing: 10, alignment: WrapAlignment.center, children: [
                FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: _waGreen), onPressed: _newConversation, icon: const Icon(Icons.add_comment_outlined), label: Text(tr('Nouvelle conversation'))),
                if (_info!['can_manage'] == true) OutlinedButton.icon(onPressed: _settings, icon: const Icon(Icons.settings_outlined), label: Text(tr('Paramètres'))),
              ]),
            ]),
          ),
        ),
      );

  Widget _listPane({bool phone = false}) {
    final counts = asMap(_info!['counts']);
    final shown = _convs
        .where((c) => _f['label'] == null || (c['labels'] as List? ?? const []).map((e) => '$e').contains(_f['label']))
        .where((c) => _f['assignee'] == null || '${c['assignee'] ?? ''}' == _f['assignee'])
        .where((c) => _f['state'] == null || '${c['status'] ?? 'open'}' == _f['state'])
        .where((c) => _period == null || _period!.contains(DateTime.tryParse('${c['last_message_at'] ?? ''}')?.toLocal()))
        .toList();
    return Scaffold(
      backgroundColor: Theme.of(context).colorScheme.surface,
      floatingActionButton: phone ? FloatingActionButton(backgroundColor: _waGreen, foregroundColor: Colors.white, onPressed: _newConversation, child: const Icon(Icons.add_comment_outlined)) : null,
      body: Column(children: [
        Container(
          color: _waDark,
          padding: const EdgeInsets.fromLTRB(16, 10, 6, 10),
          child: SafeArea(
            bottom: false,
            child: Row(children: [
              const Icon(Icons.chat, color: Colors.white),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('WhatsApp'), style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 17)),
                  Text(tr(_info!['provider'] == null ? 'Non connecté' : '${_info!['provider_label']}${_info!['open_now'] == true ? ' · ouvert' : ' · hors horaires'}'),
                      style: const TextStyle(color: Colors.white70, fontSize: 11.5), overflow: TextOverflow.ellipsis),
                ]),
              ),
              IconButton(tooltip: tr('Nouvelle conversation'), color: Colors.white, icon: const Icon(Icons.add_comment_outlined), onPressed: _newConversation),
              if (_info!['can_manage'] == true) IconButton(tooltip: tr('Paramètres'), color: Colors.white, icon: const Icon(Icons.settings_outlined), onPressed: _settings),
            ]),
          ),
        ),
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: FilterBar(
            key: ValueKey(_rk),
            padding: const EdgeInsets.fromLTRB(10, 0, 10, 8),
            onSearch: (v) {
              _q = v.trim();
              _searchTimer?.cancel();
              _searchTimer = Timer(const Duration(milliseconds: 400), () => _loadList(silent: true));
            },
            search: _q,
            searchHint: 'Rechercher un nom, un numéro, un message',
            statuses: const {'': 'Mes conversations', 'unassigned': 'Non attribuées', 'unread': 'Non lues', 'all': 'Toutes', 'closed': 'Clôturées'},
            status: _filter,
            onStatus: (v) {
              setState(() => _filter = v);
              _loadList();
            },
            statusCounts: {
              if ((counts['mine'] as num? ?? 0) > 0) '': (counts['mine'] as num).toInt(),
              if ((counts['unassigned'] as num? ?? 0) > 0) 'unassigned': (counts['unassigned'] as num).toInt(),
              if ((counts['unread'] as num? ?? 0) > 0) 'unread': (counts['unread'] as num).toInt(),
            },
            filters: [
              FilterSpec('label', 'Étiquette', {for (final c in _convs) for (final l in (c['labels'] as List? ?? const [])) '$l': '$l'}, icon: Icons.label_outline),
              FilterSpec('assignee', 'Attribuée à', filterOptions(_convs, 'assignee'), icon: Icons.support_agent_outlined),
              const FilterSpec('state', 'État', {'open': 'Ouverte', 'pending': 'En attente', 'closed': 'Clôturée'}, icon: Icons.flag_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: shown.length,
            countLabel: 'conversation',
            onReset: () {
              setState(() => (_q = '', _filter = '', _f = {}, _period = null, _rk++));
              _loadList();
            },
          ),
        ),
        const Divider(height: 1),
        Expanded(
          child: shown.isEmpty
              ? EmptyState(
                  icon: Icons.forum_outlined,
                  title: tr(_convs.isNotEmpty ? 'Aucune conversation ne correspond aux filtres' : 'Aucune conversation'),
                  message: _convs.isEmpty && _filter.isEmpty ? 'Rien d\'attribué pour l\'instant : regardez « Non attribuées » ou « Toutes ».' : null)
              : RefreshIndicator(
                  onRefresh: () async {
                    await _loadInfo();
                  },
                  child: ListView.builder(itemCount: shown.length, itemBuilder: (_, i) => _convRow(shown[i])),
                ),
        ),
      ]),
    );
  }

  Widget _convRow(Map<String, dynamic> c) {
    final sel = _openId == c['id'];
    final unread = (c['unread'] as num? ?? 0) > 0;
    final hint = Theme.of(context).hintColor;
    return Material(
      color: sel ? _waGreen.withValues(alpha: .08) : Colors.transparent,
      child: InkWell(
        onTap: () => _open(c),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Row(children: [
            _avatar('${c['name']}', 22),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(tr('${c['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: unread ? FontWeight.w800 : FontWeight.w600))),
                  Text(tr(_when(c['last_message_at'])), style: TextStyle(fontSize: 11.5, color: unread ? _waGreen : hint, fontWeight: unread ? FontWeight.w700 : FontWeight.w400)),
                ]),
                const SizedBox(height: 3),
                Row(children: [
                  if (c['last_direction'] == 'out') Padding(padding: const EdgeInsets.only(right: 3), child: Icon(Icons.done_all, size: 15, color: hint)),
                  Expanded(child: Text(tr('${c['last_preview'] ?? ''}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 13))),
                  if (unread)
                    Container(
                      margin: const EdgeInsets.only(left: 6),
                      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                      decoration: BoxDecoration(color: const Color(0xFF25D366), borderRadius: BorderRadius.circular(12)),
                      child: Text(tr('${c['unread']}'), style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w800)),
                    ),
                ]),
                if ((c['labels'] as List? ?? const []).isNotEmpty || c['assignee'] != null || c['status'] != 'open')
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Wrap(spacing: 4, runSpacing: 2, children: [
                      for (final l in (c['labels'] as List? ?? const [])) _tag('$l', YColors.blue),
                      if (c['status'] == 'pending') _tag('En attente', YColors.warning),
                      if (c['status'] == 'closed') _tag('Clôturée', YColors.muted),
                      if (c['assignee'] != null) _tag('${c['assignee']}', _waGreen),
                    ]),
                  ),
              ]),
            ),
          ]),
        ),
      ),
    );
  }

  Widget _tag(String t, Color c) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
        decoration: BoxDecoration(color: c.withValues(alpha: .1), borderRadius: BorderRadius.circular(8)),
        child: Text(tr(t), style: TextStyle(color: c, fontSize: 10.5, fontWeight: FontWeight.w600)),
      );

  static Widget _avatar(String name, double r) {
    final parts = name.replaceAll('+', '').split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    final ini = parts.isEmpty ? '?' : (parts.length == 1 ? parts[0].substring(0, math.min(2, parts[0].length)) : parts[0][0] + parts[1][0]).toUpperCase();
    final hue = (name.codeUnits.fold<int>(0, (a, b) => a + b) * 41) % 360;
    return CircleAvatar(
      radius: r,
      backgroundColor: HSLColor.fromAHSL(1, hue.toDouble(), .45, .88).toColor(),
      child: Text(tr(ini), style: TextStyle(color: HSLColor.fromAHSL(1, hue.toDouble(), .5, .3).toColor(), fontWeight: FontWeight.w800, fontSize: r * .7)),
    );
  }

  static String _when(dynamic iso) {
    final d = DateTime.tryParse('${iso ?? ''}')?.toLocal();
    if (d == null) return '';
    final now = DateTime.now();
    if (d.year == now.year && d.month == now.month && d.day == now.day) return DateFormat('HH:mm').format(d);
    if (now.difference(d).inDays < 1) return 'Hier';
    if (now.difference(d).inDays < 7) return DateFormat('EEE', 'fr_FR').format(d);
    return DateFormat('dd/MM/yy').format(d);
  }

  // ------------------------------------------------------------------ conversation

  Widget _chatPane({bool back = false}) {
    final c = _conv!;
    final provider = _info!['provider'];
    final windowClosed = provider == 'meta' && c['window_open'] != true;
    final agents = [for (final a in (_info!['agents'] as List? ?? const [])) asMap(a)];
    final remaining = DateTime.tryParse('${c['window_closes_at'] ?? ''}')?.toLocal().difference(DateTime.now());
    return Column(children: [
      Container(
        color: const Color(0xFFF0F2F5),
        padding: const EdgeInsets.fromLTRB(8, 8, 8, 8),
        child: Row(children: [
          if (back) IconButton(icon: const Icon(Icons.arrow_back), onPressed: () => setState(() => (_openId = null, _conv = null))),
          _avatar('${c['name']}', 20),
          const SizedBox(width: 10),
          Expanded(
            child: InkWell(
              onTap: () => setState(() => _showInfo = !_showInfo),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(tr('${c['name']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15.5), overflow: TextOverflow.ellipsis),
                Text(
                  tr('+${c['phone']}${provider == 'meta' ? (windowClosed ? ' · fenêtre de 24 h fermée' : (remaining != null ? ' · fenêtre ouverte encore ${remaining.inHours} h ${remaining.inMinutes % 60} min' : '')) : ''}'),
                  style: TextStyle(color: windowClosed ? YColors.warning : Theme.of(context).hintColor, fontSize: 12),
                  overflow: TextOverflow.ellipsis,
                ),
              ]),
            ),
          ),
          PopupMenuButton<int?>(
            tooltip: tr('Attribuer'),
            icon: Icon(Icons.person_add_alt, color: c['assigned_to'] != null ? _waGreen : null),
            onSelected: (v) => _update({'assigned_to': v == -1 ? null : v}, v == -1 ? 'Conversation libérée.' : 'Conversation attribuée.'),
            itemBuilder: (_) => [
              PopupMenuItem(value: _info!['me'] as int, child: Text(tr('Me l\'attribuer'))),
              if (_info!['can_manage'] == true)
                for (final a in agents.where((a) => a['id'] != _info!['me'])) PopupMenuItem(value: a['id'] as int, child: Text(tr('${a['name']}'))),
              PopupMenuItem(value: -1, child: Text(tr('Personne (non attribuée)'))),
            ],
          ),
          IconButton(
            tooltip: tr('Étiquettes'),
            icon: const Icon(Icons.label_outline),
            onPressed: () async {
              final l = await _labels(List<String>.from(c['labels'] as List? ?? const []));
              if (l != null) await _update({'labels': l});
            },
          ),
          c['status'] == 'closed'
              ? TextButton.icon(onPressed: () => _update({'status': 'open'}, 'Conversation rouverte.'), icon: const Icon(Icons.refresh, size: 18), label: Text(tr('Rouvrir')))
              : TextButton.icon(onPressed: () => _update({'status': 'closed'}, 'Conversation clôturée.'), icon: const Icon(Icons.check_circle_outline, size: 18), label: Text(tr('Clôturer'))),
          PopupMenuButton<String>(
            onSelected: (v) async {
              if (v == 'pending') await _update({'status': 'pending'}, 'Mise en attente.');
              if (v == 'unread') await _update({'unread': true});
              if (v == 'info') setState(() => _showInfo = !_showInfo);
              if (v == 'partner') await _createPartner();
              if (v == 'call') await safeLaunchUrl(Uri.parse('tel:+${c['phone']}'));
            },
            itemBuilder: (_) => [
              PopupMenuItem(value: 'info', child: Text(tr('Fiche du contact'))),
              if (c['partner_id'] == null) PopupMenuItem(value: 'partner', child: Text(tr('Créer la fiche client'))),
              PopupMenuItem(value: 'pending', child: Text(tr('Mettre en attente'))),
              PopupMenuItem(value: 'unread', child: Text(tr('Marquer comme non lue'))),
              PopupMenuItem(value: 'call', child: Text(tr('Appeler'))),
            ],
          ),
        ]),
      ),
      Expanded(
        child: CustomPaint(
          painter: _DoodlePainter(),
          child: ListView.builder(
            controller: _scroll,
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
            itemCount: _msgs.length,
            itemBuilder: (_, i) {
              final m = _msgs[i];
              final d = DateTime.tryParse('${m['at']}')?.toLocal();
              final prev = i > 0 ? DateTime.tryParse('${_msgs[i - 1]['at']}')?.toLocal() : null;
              final newDay = d != null && (prev == null || prev.day != d.day || prev.month != d.month);
              return Column(children: [
                if (newDay)
                  Container(
                    margin: const EdgeInsets.symmetric(vertical: 10),
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8), boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 2)]),
                    child: Text(tr(_dayLabel(d)), style: const TextStyle(fontSize: 12, color: Color(0xFF54656F))),
                  ),
                _bubble(m),
              ]);
            },
          ),
        ),
      ),
      if (windowClosed)
        Container(
          color: const Color(0xFFFFF4D6),
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          child: Row(children: [
            const Icon(Icons.schedule, color: YColors.warning),
            const SizedBox(width: 10),
            Expanded(child: Text(tr('Le client n\'a pas écrit depuis plus de 24 h : WhatsApp impose un modèle approuvé pour reprendre contact.'))),
            FilledButton(style: FilledButton.styleFrom(backgroundColor: _waGreen), onPressed: _pickTemplate, child: Text(tr('Choisir un modèle'))),
          ]),
        )
      else
        _composer(),
    ]);
  }

  static String _dayLabel(DateTime d) {
    final now = DateTime.now();
    final diff = DateTime(now.year, now.month, now.day).difference(DateTime(d.year, d.month, d.day)).inDays;
    if (diff == 0) return 'AUJOURD\'HUI';
    if (diff == 1) return 'HIER';
    return DateFormat('EEEE d MMMM y', 'fr_FR').format(d).toUpperCase();
  }

  Widget _bubble(Map<String, dynamic> m) {
    final out = m['direction'] == 'out';
    final d = DateTime.tryParse('${m['at']}')?.toLocal();
    final st = '${m['status']}';
    final url = m['media_url'] as String?;
    final type = '${m['type']}';
    final w = MediaQuery.sizeOf(context).width;
    return Align(
      alignment: out ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(maxWidth: math.min(520, w * .62)),
        margin: const EdgeInsets.symmetric(vertical: 2),
        padding: const EdgeInsets.fromLTRB(9, 6, 9, 5),
        decoration: BoxDecoration(
          color: out ? _waOut : Colors.white,
          borderRadius: BorderRadius.only(topLeft: const Radius.circular(10), topRight: const Radius.circular(10), bottomLeft: Radius.circular(out ? 10 : 2), bottomRight: Radius.circular(out ? 2 : 10)),
          boxShadow: const [BoxShadow(color: Color(0x14000000), blurRadius: 1.5, offset: Offset(0, 1))],
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          if (m['auto'] == true) Text(tr('Message automatique'), style: TextStyle(fontSize: 11, color: _waGreen, fontWeight: FontWeight.w700)),
          if (m['template'] != null) Text(tr('Modèle « ${m['template']} »'), style: const TextStyle(fontSize: 11, color: _waGreen, fontWeight: FontWeight.w700)),
          if (type == 'image' && url != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: InkWell(
                onTap: () => safeLaunchUrl(Uri.parse(url), webOnlyWindowName: '_blank'),
                child: ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.network(url, width: 260, fit: BoxFit.cover, errorBuilder: (_, _, _) => const Icon(Icons.broken_image, size: 48))),
              ),
            ),
          if ((type == 'document' || type == 'audio' || type == 'video' || (type == 'image' && url == null)) )
            InkWell(
              onTap: url == null ? null : () => safeLaunchUrl(Uri.parse(url), webOnlyWindowName: '_blank'),
              child: Container(
                margin: const EdgeInsets.only(bottom: 4),
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Colors.black.withValues(alpha: .05), borderRadius: BorderRadius.circular(8)),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(switch (type) { 'audio' => Icons.mic, 'video' => Icons.play_circle_outline, 'image' => Icons.image_outlined, _ => Icons.description_outlined }, color: _waGreen),
                  const SizedBox(width: 8),
                  Flexible(child: Text(tr('${m['file_name'] ?? (type == 'audio' ? 'Note vocale' : 'Fichier')}'), style: const TextStyle(fontWeight: FontWeight.w600, color: Color(0xFF111B21)), overflow: TextOverflow.ellipsis)),
                  if (url != null) const Padding(padding: EdgeInsets.only(left: 6), child: Icon(Icons.download, size: 18, color: Color(0xFF54656F))),
                ]),
              ),
            ),
          if ((m['body'] ?? '').toString().isNotEmpty) SelectableText(tr('${m['body']}'), style: const TextStyle(fontSize: 14.2, color: Color(0xFF111B21), height: 1.35)),
          const SizedBox(height: 2),
          Row(mainAxisSize: MainAxisSize.min, children: [
            if (out && m['user'] != null) Text(tr('${m['user']} · '), style: const TextStyle(fontSize: 10.5, color: Color(0xFF667781))),
            Text(tr(d == null ? '' : DateFormat('HH:mm').format(d)), style: const TextStyle(fontSize: 10.5, color: Color(0xFF667781))),
            if (out) ...[
              const SizedBox(width: 3),
              if (st == 'failed')
                Tooltip(message: '${m['error'] ?? 'Échec'}', child: const Icon(Icons.error_outline, size: 15, color: YColors.danger))
              else if (st == 'queued')
                const Icon(Icons.schedule, size: 14, color: Color(0xFF667781))
              else
                Icon(st == 'sent' ? Icons.done : Icons.done_all, size: 15, color: st == 'read' ? _waBlue : const Color(0xFF667781)),
            ],
          ]),
        ]),
      ),
    );
  }

  Widget _composer() {
    final quick = [for (final q in (_info!['quick_replies'] as List? ?? const [])) asMap(q)];
    final t = _text.text;
    final slash = t.startsWith('/') ? t.substring(1).toLowerCase() : null;
    final matches = slash == null ? const <Map<String, dynamic>>[] : quick.where((q) => '${q['shortcut']}'.toLowerCase().contains(slash) || '${q['body']}'.toLowerCase().contains(slash)).take(6).toList();
    return Container(
      color: const Color(0xFFF0F2F5),
      padding: const EdgeInsets.fromLTRB(8, 6, 8, 8),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        if (matches.isNotEmpty)
          Container(
            margin: const EdgeInsets.only(bottom: 6),
            decoration: BoxDecoration(color: Theme.of(context).colorScheme.surface, borderRadius: BorderRadius.circular(10), boxShadow: const [BoxShadow(color: Color(0x22000000), blurRadius: 8)]),
            child: Column(children: [
              for (final q in matches)
                ListTile(
                  dense: true,
                  leading: const Icon(Icons.bolt, color: _waGreen),
                  title: Text(tr('/${q['shortcut']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(tr('${q['body']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
                  onTap: () {
                    _text.text = '${q['body']}';
                    _text.selection = TextSelection.collapsed(offset: _text.text.length);
                    _focus.requestFocus();
                  },
                ),
            ]),
          ),
        Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          PopupMenuButton<String>(
            tooltip: tr('Joindre'),
            icon: const Icon(Icons.add, color: Color(0xFF54656F)),
            onSelected: (v) async {
              if (v == 'template') return _pickTemplate();
              if (v == 'quick') {
                _text.text = '/';
                _text.selection = const TextSelection.collapsed(offset: 1);
                _focus.requestFocus();
                return;
              }
              final files = await FilePicker.pickFiles(type: v == 'image' ? FileType.image : FileType.any);
              if (files.isEmpty) return;
              final f = files.first;
              final bytes = await f.readAsBytes();
              if (bytes.length > 16 * 1024 * 1024) {
                if (mounted) toast(context, tr('Fichier trop lourd (16 Mo maximum).'), error: true);
                return;
              }
              await _send(file: (bytes, f.name));
            },
            itemBuilder: (_) => [
              PopupMenuItem(value: 'image', child: ListTile(dense: true, leading: Icon(Icons.image_outlined, color: Color(0xFF7F66FF)), title: Text(tr('Photo')))),
              PopupMenuItem(value: 'file', child: ListTile(dense: true, leading: Icon(Icons.description_outlined, color: Color(0xFF5157AE)), title: Text(tr('Document')))),
              PopupMenuItem(value: 'quick', child: ListTile(dense: true, leading: Icon(Icons.bolt, color: _waGreen), title: Text(tr('Réponse rapide')))),
              PopupMenuItem(value: 'template', child: ListTile(dense: true, leading: Icon(Icons.verified_outlined, color: _waGreen), title: Text(tr('Modèle approuvé')))),
            ],
          ),
          Expanded(
            child: CallbackShortcuts(
              bindings: {const SingleActivator(LogicalKeyboardKey.enter): () => _sending ? null : _send()},
              child: TextField(
                controller: _text,
                focusNode: _focus,
                minLines: 1,
                maxLines: 6,
                textInputAction: TextInputAction.newline,
                decoration: InputDecoration(
                  hintText: tr('Écrivez un message (« / » pour les réponses rapides)'),
                  filled: true,
                  fillColor: Colors.white,
                  isDense: true,
                  contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(22), borderSide: BorderSide.none),
                ),
              ),
            ),
          ),
          const SizedBox(width: 6),
          FloatingActionButton.small(
            heroTag: null,
            elevation: 0,
            backgroundColor: _waGreen,
            foregroundColor: Colors.white,
            onPressed: _sending || _text.text.trim().isEmpty ? null : () => _send(),
            child: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.send),
          ),
        ]),
      ]),
    );
  }

  Widget _infoPane() {
    final c = _conv!;
    final p = _partner;
    final s = _stats;
    final cur = context.read<Session>().currency;
    return ListView(padding: const EdgeInsets.all(18), children: [
      Center(child: _avatar('${c['name']}', 40)),
      const SizedBox(height: 10),
      Center(child: Text(tr('${c['name']}'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17), textAlign: TextAlign.center)),
      Center(child: SelectableText(tr('+${c['phone']}'), style: TextStyle(color: Theme.of(context).hintColor))),
      const SizedBox(height: 14),
      if (p != null) ...[
        Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [Icon(Icons.business, size: 18, color: YColors.blue), SizedBox(width: 6), Text(tr('Fiche client'), style: TextStyle(fontWeight: FontWeight.w700))]),
              const SizedBox(height: 6),
              Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
              if (p['email'] != null) Text(tr('${p['email']}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
              if (s != null) ...[
                const Divider(),
                Text(tr('Reste à payer : ${money((s['invoices_due'] as num?)?.toDouble() ?? 0, cur)}'), style: TextStyle(fontWeight: FontWeight.w700, color: ((s['invoices_due'] as num?) ?? 0) > 0 ? YColors.danger : YColors.success)),
                if (s['last_invoice'] != null) Text(tr('Dernière facture : ${s['last_invoice']}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
              ],
            ]),
          ),
        ),
      ] else
        OutlinedButton.icon(onPressed: _createPartner, icon: const Icon(Icons.person_add_alt_1), label: Text(tr('Créer la fiche client'))),
      const SizedBox(height: 12),
      Text(tr('Attribuée à : ${c['assignee'] ?? 'personne'}'), style: TextStyle(color: Theme.of(context).hintColor)),
      const SizedBox(height: 6),
      Wrap(spacing: 4, runSpacing: 4, children: [for (final l in (c['labels'] as List? ?? const [])) Chip(label: Text(tr('$l')), visualDensity: VisualDensity.compact)]),
      const SizedBox(height: 14),
      OutlinedButton.icon(
        onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/${c['phone']}'), webOnlyWindowName: '_blank'),
        icon: const Icon(Icons.open_in_new, size: 18),
        label: Text(tr('Ouvrir dans WhatsApp')),
      ),
    ]);
  }

  Future<void> _createPartner() async {
    try {
      final r = await api.post('whatsapp/conversations/${_conv!['id']}/partner', {'name': _conv!['name']});
      if (mounted) setState(() => _conv = asMap(r['data']));
      await _open(_conv!);
      if (mounted) toast(context, tr('Fiche client créée.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<List<String>?> _labels(List<String> current) {
    final sel = {...current};
    final add = TextEditingController();
    const presets = ['Commande', 'Devis', 'Paiement', 'Livraison', 'Réclamation', 'SAV', 'Prospect', 'VIP'];
    return showDialog<List<String>>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Étiquettes')),
          content: SizedBox(
            width: 380,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final l in {...presets, ...sel}) FilterChip(label: Text(tr(l)), selected: sel.contains(l), onSelected: (v) => set(() => v ? sel.add(l) : sel.remove(l))),
              ]),
              TextField(controller: add, decoration: InputDecoration(labelText: tr('Autre étiquette')), onSubmitted: (v) => set(() => v.trim().isEmpty ? null : sel.add(v.trim()))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, sel.toList()), child: Text(tr('Enregistrer')))],
        ),
      ),
    );
  }

  Future<void> _pickTemplate() async {
    final r = await showTemplateDialog(context, _info!);
    if (r != null) await _send(template: r.$1, params: r.$2);
  }

  Future<void> _newConversation() async {
    final phone = TextEditingController(), name = TextEditingController(), body = TextEditingController();
    (String, List<String>)? tpl;
    final meta = _info!['provider'] == 'meta';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Nouvelle conversation')),
          content: SizedBox(
            width: 440,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: phone, keyboardType: TextInputType.phone, decoration: InputDecoration(labelText: tr('Numéro WhatsApp'), hintText: tr('07 07 00 00 00 ou +33 6…'))),
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom (facultatif)'))),
              const SizedBox(height: 10),
              if (meta) ...[
                Text(tr('Pour écrire en premier à un client, WhatsApp impose un modèle approuvé.'), style: TextStyle(color: Theme.of(c).hintColor, fontSize: 12.5)),
                const SizedBox(height: 6),
                OutlinedButton.icon(
                  onPressed: () async {
                    final r = await showTemplateDialog(c, _info!);
                    if (r != null) set(() => tpl = r);
                  },
                  icon: const Icon(Icons.verified_outlined),
                  label: Text(tr(tpl == null ? 'Choisir un modèle' : 'Modèle « ${tpl!.$1} »')),
                ),
              ] else
                TextField(controller: body, minLines: 2, maxLines: 5, decoration: InputDecoration(labelText: tr('Message'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Envoyer')))],
        ),
      ),
    );
    if (ok != true) return;
    try {
      final r = await api.post('whatsapp/conversations', {
        'phone': phone.text.trim(), if (name.text.trim().isNotEmpty) 'name': name.text.trim(),
        if (tpl != null) ...{'template': tpl!.$1, 'params': tpl!.$2} else 'body': body.text.trim(),
      });
      await _loadList(silent: true);
      await _open(asMap(r['data']));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _settings() async {
    await showFormDialog(context, title: 'Paramètres WhatsApp', maxWidth: 728, builder: (_) => _WaSettings(info: _info!));
    await _loadInfo();
  }
}

/// Choix d'un modèle approuvé et de ses variables, avec aperçu. Renvoie (nom, paramètres).
Future<(String, List<String>)?> showTemplateDialog(BuildContext context, Map<String, dynamic> info) {
  final templates = [for (final t in (info['templates'] as List? ?? const [])) asMap(t)].where((t) => t['status'] == 'APPROVED').toList();
  Map<String, dynamic>? sel = templates.isEmpty ? null : templates.first;
  final ctrls = <TextEditingController>[];
  void prepare() {
    ctrls
      ..clear()
      ..addAll(List.generate((sel?['params'] as num? ?? 0).toInt(), (_) => TextEditingController()));
  }

  prepare();
  return showDialog<(String, List<String>)>(
    context: context,
    builder: (c) => StatefulBuilder(builder: (c, set) {
      var preview = '${sel?['body'] ?? ''}';
      for (var i = 0; i < ctrls.length; i++) {
        preview = preview.replaceAll('{{${i + 1}}}', ctrls[i].text.isEmpty ? '[${i + 1}]' : ctrls[i].text);
      }
      return YAlertDialog(
        title: Text(tr('Modèle approuvé')),
        content: SizedBox(
          width: 520,
          child: templates.isEmpty
              ? Text(tr('Aucun modèle approuvé. Un responsable peut synchroniser ou proposer des modèles dans Paramètres › Modèles.'))
              : SingleChildScrollView(
                  child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    DropdownButtonFormField<int>(
                      initialValue: sel?['id'] as int?,
                      decoration: InputDecoration(labelText: tr('Modèle')),
                      items: [for (final t in templates) DropdownMenuItem(value: t['id'] as int, child: Text(tr('${t['name']} · ${t['category'] == 'MARKETING' ? 'marketing' : 'service'}')))],
                      onChanged: (v) => set(() {
                        sel = templates.firstWhere((t) => t['id'] == v);
                        prepare();
                      }),
                    ),
                    for (var i = 0; i < ctrls.length; i++) TextField(controller: ctrls[i], decoration: InputDecoration(labelText: tr('Variable {{${i + 1}}}')), onChanged: (_) => set(() {})),
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(color: _waBg, borderRadius: BorderRadius.circular(10)),
                      child: Align(
                        alignment: Alignment.centerRight,
                        child: Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(color: _waOut, borderRadius: BorderRadius.circular(10)),
                          child: Text(tr(preview), style: const TextStyle(color: Color(0xFF111B21))),
                        ),
                      ),
                    ),
                  ]),
                ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          if (sel != null)
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: _waGreen),
              onPressed: ctrls.any((x) => x.text.trim().isEmpty) ? null : () => Navigator.pop(c, ('${sel!['name']}', [for (final x in ctrls) x.text.trim()])),
              child: Text(tr('Envoyer')),
            ),
        ],
      );
    }),
  );
}

/// Fond des conversations : petits motifs discrets, comme WhatsApp.
class _DoodlePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size s) {
    canvas.drawRect(Offset.zero & s, Paint()..color = _waBg);
    final p = Paint()
      ..color = const Color(0xFF000000).withValues(alpha: .035)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.4;
    final r = math.Random(3);
    for (var y = 20.0; y < s.height; y += 70) {
      for (var x = 20.0; x < s.width; x += 70) {
        final k = r.nextInt(4);
        final o = Offset(x + r.nextDouble() * 20, y + r.nextDouble() * 20);
        switch (k) {
          case 0:
            canvas.drawCircle(o, 7, p);
          case 1:
            canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromCenter(center: o, width: 16, height: 11), const Radius.circular(3)), p);
          case 2:
            canvas.drawPath(Path()..moveTo(o.dx - 8, o.dy + 6)..lineTo(o.dx, o.dy - 7)..lineTo(o.dx + 8, o.dy + 6)..close(), p);
          default:
            canvas.drawLine(o.translate(-7, 0), o.translate(7, 0), p);
            canvas.drawLine(o.translate(0, -7), o.translate(0, 7), p);
        }
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter old) => false;
}

// ---------------------------------------------------------------------------- paramètres

class _WaSettings extends StatefulWidget {
  const _WaSettings({required this.info});

  final Map<String, dynamic> info;

  @override
  State<_WaSettings> createState() => _WaSettingsState();
}

class _WaSettingsState extends State<_WaSettings> {
  late Map<String, dynamic> d = Map<String, dynamic>.from(widget.info);
  late final _welcome = TextEditingController(text: '${d['welcome_message'] ?? ''}');
  late final _away = TextEditingController(text: '${d['away_message'] ?? ''}');
  late final _phone = TextEditingController(text: '${d['display_phone'] ?? ''}');
  late bool _auto = d['auto_assign'] == true;
  late final Map<String, List<String>?> _hours = {
    for (var i = 1; i <= 7; i++) '$i': asMap(d['hours'])['$i'] == null ? null : List<String>.from(asMap(d['hours'])['$i'] as List),
  };
  static const _days = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

  ApiClient get api => context.read<Session>().api;

  Future<void> _reload() async {
    final r = await api.get('whatsapp/inbox');
    if (mounted) setState(() => d = asMap(r['data']));
  }

  Future<void> _save() async {
    try {
      await api.put('whatsapp/inbox/settings', {
        'welcome_message': _welcome.text.trim(), 'away_message': _away.text.trim(), 'display_phone': _phone.text.trim(), 'auto_assign': _auto, 'hours': _hours,
      });
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Paramètres WhatsApp enregistrés.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Widget _copy(String label, String v) => ListTile(
        contentPadding: EdgeInsets.zero,
        title: Text(tr(label), style: const TextStyle(fontSize: 12.5)),
        subtitle: SelectableText(tr(v), style: const TextStyle(fontFamily: 'monospace', fontSize: 12)),
        trailing: IconButton(icon: const Icon(Icons.copy, size: 18), onPressed: () => Clipboard.setData(ClipboardData(text: v))),
      );

  @override
  Widget build(BuildContext context) {
    final quick = [for (final q in (d['quick_replies'] as List? ?? const [])) asMap(q)];
    final templates = [for (final t in (d['templates'] as List? ?? const [])) asMap(t)];
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Expanded(
          child: DefaultTabController(
            length: 5,
            child: Column(children: [
              TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
                Tab(text: tr('Connexion')), Tab(text: tr('Accueil & horaires')), Tab(text: tr('Réponses rapides')), Tab(text: tr('Modèles')), Tab(text: tr('Lien « Discuter »')),
              ]),
              Expanded(
                child: TabBarView(children: [
                  ListView(padding: const EdgeInsets.only(top: 12), children: [
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(d['provider'] == null ? Icons.link_off : Icons.check_circle, color: d['provider'] == null ? YColors.warning : YColors.success),
                      title: Text(tr(d['provider'] == null ? 'Aucun fournisseur WhatsApp' : '${d['provider_label']}')),
                      subtitle: Text(tr('Le fournisseur et ses clés se règlent dans Paramètres › Canaux d\'envoi.')),
                    ),
                    Text(tr('Adresse de réception à déclarer chez votre fournisseur :'), style: TextStyle(fontWeight: FontWeight.w700)),
                    if (d['webhook_url'] != null) _copy('URL du webhook (rappel)', '${d['webhook_url']}') else Text(tr('Visible par les responsables WhatsApp.'), style: TextStyle(color: Theme.of(context).hintColor)),
                    if (d['verify_token'] != null) _copy('Jeton de vérification (Meta)', '${d['verify_token']}'),
                    const SizedBox(height: 6),
                    Text(
                      tr('• Meta (API Cloud) : developers.facebook.com › votre application › WhatsApp › Configuration › Webhook : collez l\'URL et le jeton, abonnez « messages ». '
                      'Renseignez aussi la clé secrète de l\'application et le WhatsApp Business Account ID dans Canaux d\'envoi.\n'
                      '• UltraMsg : tableau de bord › Instance › Webhook URL, cochez « message received » et « message ack ».\n'
                      '• Green API : console › Instance › Réglages › URL du webhook, activez les messages entrants et les statuts.'),
                      style: TextStyle(fontSize: 12.5, height: 1.5),
                    ),
                  ]),
                  ListView(padding: const EdgeInsets.only(top: 12), children: [
                    SwitchListTile(contentPadding: EdgeInsets.zero, value: _auto, onChanged: (v) => setState(() => _auto = v), title: Text(tr('Attribuer automatiquement les nouvelles conversations')),
                        subtitle: Text(tr('À tour de rôle entre les personnes qui ont accès à WhatsApp'))),
                    TextField(controller: _welcome, minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: tr('Message d\'accueil (première conversation)'), helperText: tr('Variables : {nom}, {societe}, {horaires}'))),
                    TextField(controller: _away, minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: tr('Message d\'absence (hors horaires, une fois par 12 h)'))),
                    const SizedBox(height: 12),
                    Text(tr('Horaires d\'ouverture'), style: TextStyle(fontWeight: FontWeight.w700)),
                    for (var i = 1; i <= 7; i++)
                      Row(children: [
                        SizedBox(width: 110, child: Text(tr(_days[i - 1]))),
                        Switch(value: _hours['$i'] != null, onChanged: (v) => setState(() => _hours['$i'] = v ? ['08:00', '18:00'] : null)),
                        if (_hours['$i'] != null) ...[
                          for (final k in [0, 1])
                            TextButton(
                              onPressed: () async {
                                final parts = _hours['$i']![k].split(':');
                                final t = await showTimePicker(context: context, initialTime: TimeOfDay(hour: int.parse(parts[0]), minute: int.parse(parts[1])));
                                if (t != null) setState(() => _hours['$i']![k] = '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}');
                              },
                              child: Text(tr(k == 0 ? 'de ${_hours['$i']![0]}' : 'à ${_hours['$i']![1]}')),
                            ),
                        ] else
                          Text(tr('Fermé'), style: TextStyle(color: Theme.of(context).hintColor)),
                      ]),
                  ]),
                  ListView(padding: const EdgeInsets.only(top: 12), children: [
                    Text(tr('Tapez « / » dans une conversation pour les retrouver.'), style: TextStyle(color: Theme.of(context).hintColor)),
                    for (final q in quick)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(Icons.bolt, color: _waGreen),
                        title: Text(tr('/${q['shortcut']}')),
                        subtitle: Text(tr('${q['body']}'), maxLines: 2, overflow: TextOverflow.ellipsis),
                        trailing: IconButton(icon: const Icon(Icons.delete_outline), onPressed: () async {
                          await api.delete('whatsapp/quick-replies/${q['id']}');
                          await _reload();
                        }),
                        onTap: () => _editQuick(q),
                      ),
                    Align(alignment: Alignment.centerLeft, child: FilledButton.icon(onPressed: () => _editQuick(null), icon: const Icon(Icons.add), label: Text(tr('Réponse rapide')))),
                  ]),
                  ListView(padding: const EdgeInsets.only(top: 12), children: [
                    Row(children: [
                      Expanded(child: Text(tr('Modèles validés par Meta, obligatoires pour écrire en premier ou après 24 h.'), style: TextStyle(color: Theme.of(context).hintColor))),
                      TextButton.icon(
                        onPressed: () async {
                          try {
                            final r = await api.post('whatsapp/templates/sync');
                            await _reload();
                            if (context.mounted) toast(context, tr('${r['count']} modèle(s) synchronisé(s).'));
                          } catch (e) {
                            if (context.mounted) toast(context, errorText(e), error: true);
                          }
                        },
                        icon: const Icon(Icons.sync),
                        label: Text(tr('Synchroniser')),
                      ),
                    ]),
                    for (final t in templates)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(tr('${t['name']} · ${t['language']}')),
                        subtitle: Text(tr('${t['body'] ?? ''}'), maxLines: 3, overflow: TextOverflow.ellipsis),
                        trailing: StatusChip(
                          switch ('${t['status']}') { 'APPROVED' => 'Approuvé', 'PENDING' => 'En validation', 'REJECTED' => 'Refusé', _ => '${t['status']}' },
                          color: switch ('${t['status']}') { 'APPROVED' => YColors.success, 'REJECTED' => YColors.danger, _ => YColors.warning },
                        ),
                      ),
                    Align(alignment: Alignment.centerLeft, child: FilledButton.icon(onPressed: _newTemplate, icon: const Icon(Icons.add), label: Text(tr('Proposer un modèle')))),
                  ]),
                  ListView(padding: const EdgeInsets.only(top: 12), children: [
                    TextField(controller: _phone, decoration: InputDecoration(labelText: tr('Numéro WhatsApp de l\'entreprise'), hintText: '+225 07 00 00 00 00')),
                    const SizedBox(height: 14),
                    if (d['click_to_chat'] != null) ...[
                      Center(child: QrBox(data: '${d['click_to_chat']}', size: 170)),
                      const SizedBox(height: 8),
                      _copy('Lien à mettre sur votre site, vos factures, vos réseaux', '${d['click_to_chat']}'),
                      _copy('Bouton pour votre site (HTML)',
                          '<a href="${d['click_to_chat']}" style="display:inline-block;background:#25D366;color:#fff;padding:12px 20px;border-radius:24px;text-decoration:none;font-weight:700">Discuter sur WhatsApp</a>'),
                    ] else
                      Text(tr('Enregistrez le numéro pour obtenir le lien et le QR code « Discuter sur WhatsApp ».'), style: TextStyle(color: Theme.of(context).hintColor)),
                  ]),
                ]),
              ),
            ]),
          ),
        ),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerRight,
          child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
            TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer'))), SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
          ]),
        ),
      ]),
    );
  }

  Future<void> _editQuick(Map<String, dynamic>? q) async {
    final sc = TextEditingController(text: '${q?['shortcut'] ?? ''}'), body = TextEditingController(text: '${q?['body'] ?? ''}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => YAlertDialog(
        title: Text(tr(q == null ? 'Nouvelle réponse rapide' : 'Modifier')),
        content: SizedBox(
          width: 440,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: sc, decoration: InputDecoration(labelText: tr('Raccourci'), prefixText: '/')),
            TextField(controller: body, minLines: 3, maxLines: 6, decoration: InputDecoration(labelText: tr('Texte'))),
          ]),
        ),
        actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok != true) return;
    try {
      final data = {'shortcut': sc.text.trim(), 'body': body.text.trim()};
      q == null ? await api.post('whatsapp/quick-replies', data) : await api.put('whatsapp/quick-replies/${q['id']}', data);
      await _reload();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _newTemplate() async {
    final name = TextEditingController(), body = TextEditingController();
    var cat = 'UTILITY';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Proposer un modèle à Meta')),
          content: SizedBox(
            width: 480,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom (minuscules, ex. relance_devis)'))),
              DropdownButtonFormField<String>(
                initialValue: cat,
                decoration: InputDecoration(labelText: tr('Catégorie')),
                items: [DropdownMenuItem(value: 'UTILITY', child: Text(tr('Service (facture, commande, rendez-vous)'))), DropdownMenuItem(value: 'MARKETING', child: Text(tr('Marketing (offres, promotions)')))],
                onChanged: (v) => set(() => cat = v ?? 'UTILITY'),
              ),
              TextField(controller: body, minLines: 3, maxLines: 6, decoration: InputDecoration(labelText: tr('Texte'), helperText: tr('Variables : {{1}}, {{2}}… (ex. Bonjour {{1}}, votre commande {{2}} est prête.)'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Envoyer à Meta')))],
        ),
      ),
    );
    if (ok != true) return;
    try {
      await api.post('whatsapp/templates', {'name': name.text.trim(), 'category': cat, 'body': body.text.trim()});
      await _reload();
      if (mounted) toast(context, tr('Modèle envoyé à Meta : validation en général sous 24 h.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }
}
