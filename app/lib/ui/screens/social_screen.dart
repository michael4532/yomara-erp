import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'dart:async';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api_client.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'social_inbox.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ---------------------------------------------------------------------------
// Réseaux : pictogramme, couleur, comptage des caractères (mêmes règles que le serveur)
// ---------------------------------------------------------------------------

const _icons = <String, IconData>{
  'facebook': Icons.facebook,
  'instagram': Icons.camera_alt_outlined,
  'threads': Icons.alternate_email,
  'linkedin': Icons.work_outline,
  'x': Icons.close,
  'tiktok': Icons.tiktok,
  'pinterest': Icons.push_pin_outlined,
  'youtube': Icons.smart_display_outlined,
  'gbp': Icons.storefront_outlined,
  'telegram': Icons.telegram,
  'mastodon': Icons.forum_outlined,
  'bluesky': Icons.cloud_outlined,
  'sensya': Icons.auto_awesome,
};

void openUrl(String u) => safeLaunchUrl(Uri.parse(u), mode: LaunchMode.externalApplication);

Color _hex(dynamic v, [Color fallback = YColors.blue]) {
  final s = '${v ?? ''}'.replaceAll('#', '');
  final n = int.tryParse(s, radix: 16);
  return n == null ? fallback : Color(0xFF000000 | n);
}

final _url = RegExp(r'https?://\S+');
final _wide = RegExp(r'[ᄀ-ᇿ⺀-鿿가-힯豈-﫿]');

/// Longueur du texte telle que le réseau la compte.
int countFor(String network, String text) {
  var t = text;
  if (network == 'x' || network == 'mastodon') t = t.replaceAll(_url, 'x' * 23);
  var n = t.runes.length;
  if (network == 'x') {
    n += _wide.allMatches(t).length + t.runes.where((r) => r >= 0x1F000 && r <= 0x1FAFF).length;
  }
  return n;
}

class _NetBadge extends StatelessWidget {
  const _NetBadge(this.network, {this.color, this.size = 26});
  final String network;
  final Color? color;
  final double size;

  @override
  Widget build(BuildContext context) {
    final c = color ?? YColors.blue;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: c, shape: BoxShape.circle),
      alignment: Alignment.center,
      child: network == 'x'
          ? Text(
              'X',
              style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: size * .5),
            )
          : Icon(_icons[network] ?? Icons.share, color: Colors.white, size: size * .56),
    );
  }
}

DateTime? _dt(dynamic v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();

const _statusColors = <String, Color>{
  'draft': YColors.muted,
  'pending': Color(0xFF9333EA),
  'scheduled': YColors.blue,
  'publishing': YColors.warning,
  'published': YColors.success,
  'partial': YColors.warning,
  'failed': YColors.danger,
  'cancelled': YColors.muted,
};

// ---------------------------------------------------------------------------
// Écran principal
// ---------------------------------------------------------------------------

class SocialScreen extends StatefulWidget {
  const SocialScreen({super.key});

  @override
  State<SocialScreen> createState() => _SocialScreenState();
}

class _SocialScreenState extends State<SocialScreen> {
  Map<String, dynamic>? _ov;
  Object? _error;
  int _rev = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await context.read<Session>().api.get('social/overview');
      if (mounted) {
        setState(() {
          _ov = Map<String, dynamic>.from(d as Map);
          _error = null;
          _rev++;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  List<Map<String, dynamic>> get _networks => [for (final n in (_ov?['networks'] as List? ?? const [])) Map<String, dynamic>.from(n as Map)];
  List<Map<String, dynamic>> get _accounts => [for (final a in (_ov?['accounts'] as List? ?? const [])) Map<String, dynamic>.from(a as Map)];
  Map<String, dynamic> get _can => Map<String, dynamic>.from(_ov?['can'] as Map? ?? const {});

  Future<void> _compose([Map<String, dynamic>? post, DateTime? at]) async {
    if (_ov == null) return;
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => SocialComposer(networks: _networks, accounts: _accounts, can: _can, ai: _ov?['ai'] == true, post: post, initialDate: at),
      ),
    );
    if (saved == true) _load();
  }

  Future<void> _open(Map<String, dynamic> p) async {
    final changed = await showAdaptiveSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      constraints: const BoxConstraints(maxWidth: 720),
      builder: (_) => _PostSheet(postId: p['id'] as int, networks: _networks, can: _can, onEdit: (full) => _compose(full)),
    );
    if (changed == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _ov == null) {
      return EmptyState(
        icon: Icons.cloud_off,
        title: tr('Réseaux sociaux indisponibles'),
        message: errorText(_error!),
        action: FilledButton(onPressed: _load, child: Text(tr('Réessayer'))),
      );
    }
    if (_ov == null) return const Center(child: CircularProgressIndicator());
    final canWrite = _can['manage'] == true || _can['publish'] == true;
    final rawCounts = _ov!['counts'];
    final counts = rawCounts is Map ? Map<String, dynamic>.from(rawCounts) : <String, dynamic>{};
    final tabs = <(String, Widget)>[
      ('Planificateur', _Planner(key: ValueKey('planner$_rev'), networks: _networks, onOpen: _open, onCreate: canWrite ? (d) => _compose(null, d) : null)),
      ('Publications', _PostList(key: ValueKey('list$_rev'), networks: _networks, onOpen: _open, pending: (counts['pending'] as num?)?.toInt() ?? 0)),
      if (_can['inbox'] == true) ('Boîte de réception', SocialInboxTab(canSetup: _can['accounts'] == true || _can['publish'] == true)),
      ('Studio visuel', SocialStudioTab(canWrite: canWrite, onCreated: canWrite && _accounts.isNotEmpty ? (m) => _compose({'media': [m], 'body': ''}) : null)),
      ('Comptes', _AccountsTab(networks: _networks, accounts: _accounts, can: _can, onChanged: _load)),
      ('Statistiques', _StatsTab(key: ValueKey('stats$_rev'), networks: _networks, canSync: _can['publish'] == true)),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Scaffold(
        backgroundColor: Colors.transparent,
        floatingActionButton: canWrite && _accounts.isNotEmpty
            ? Builder(builder: (c) {
                final ctl = DefaultTabController.of(c);
                return AnimatedBuilder(
                  animation: ctl,
                  builder: (_, _) => const ['Boîte de réception', 'Studio visuel'].contains(tabs[ctl.index].$1)
                      ? const SizedBox.shrink()
                      : FloatingActionButton.extended(onPressed: () => _compose(), icon: const Icon(Icons.edit_outlined), label: Text(tr('Nouvelle publication'))),
                );
              })
            : null,
        body: Column(
          children: [
            PageHeader(
              title: tr('Réseaux sociaux'),
              subtitle: tr('Planifiez une fois, publiez partout en même temps'),
              icon: Icons.share_outlined,
              actions: [IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh))],
            ),
            if (_accounts.isEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: Card(
                  color: noticeFill(context), shape: noticeShape(context),
                  child: ListTile(
                    leading: const Icon(Icons.link),
                    title: Text(tr('Connectez votre premier compte')),
                    subtitle: Text(tr('Onglet « Comptes » : Facebook, Instagram, LinkedIn, X, TikTok, Sensya…')),
                  ),
                ),
              ),
            TabBar(
              isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
              tabAlignment: TabAlignment.start,
              tabs: [
                for (final t in tabs)
                  Tab(
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(tr(t.$1)),
                        if (t.$1 == 'Publications' && ((counts['pending'] as num?) ?? 0) > 0) ...[const SizedBox(width: 6), Badge(label: Text('${counts['pending']}'))],
                        if (t.$1 == 'Boîte de réception' && ((_ov!['inbox_unread'] as num?) ?? 0) > 0) ...[const SizedBox(width: 6), Badge(label: Text('${_ov!['inbox_unread']}'))],
                      ],
                    ),
                  ),
              ],
            ),
            Expanded(
              child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2]),
            ),
          ],
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Carte d'une publication (liste, calendrier)
// ---------------------------------------------------------------------------

class _PostTile extends StatelessWidget {
  const _PostTile({required this.p, required this.networks, required this.onTap, this.dense = false});
  final Map<String, dynamic> p;
  final List<Map<String, dynamic>> networks;
  final VoidCallback onTap;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final st = '${p['status']}';
    final when = _dt(p['published_at']) ?? _dt(p['scheduled_at']);
    final media = (p['media'] as List? ?? const []);
    final nets = {for (final t in (p['targets'] as List? ?? const [])) '${(t as Map)['network']}'};
    final color = _statusColors[st] ?? YColors.muted;
    final thumb = media.isNotEmpty && (media.first as Map)['kind'] == 'image' ? '${(media.first as Map)['url']}' : null;
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (thumb != null)
                ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: Image.network(thumb, width: 56, height: 56, fit: BoxFit.cover, errorBuilder: (_, _, _) => const SizedBox(width: 56)),
                )
              else if (media.isNotEmpty)
                Container(
                  width: 56,
                  height: 56,
                  decoration: BoxDecoration(color: YColors.night, borderRadius: BorderRadius.circular(8)),
                  child: const Icon(Icons.play_arrow, color: Colors.white),
                ),
              if (media.isNotEmpty) const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        StatusChip(tr('${p['status_label']}'), color: color),
                        const SizedBox(width: 8),
                        if (when != null)
                          Flexible(
                            child: Text(
                              DateFormat('EEE d MMM · HH:mm', 'fr').format(when),
                              style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(verbatim('${p['title'] ?? ''}'.isNotEmpty ? '${p['title']}' : '${p['body'] ?? ''}'), maxLines: dense ? 2 : 3, overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 4,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        for (final n in nets) _NetBadge(n, color: _hex(networks.firstWhere((x) => x['key'] == n, orElse: () => const {})['color']), size: 20),
                        if (p['campaign'] != null && '${p['campaign']}'.isNotEmpty) ...[
                          const SizedBox(width: 6),
                          Text(verbatim('# ${p['campaign']}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                        ],
                        if ((p['stats']?['likes'] ?? 0) + (p['stats']?['comments'] ?? 0) > 0) ...[
                          const SizedBox(width: 8),
                          Icon(Icons.favorite_border, size: 14, color: Theme.of(context).hintColor),
                          Text(' ${p['stats']['likes']}  ', style: const TextStyle(fontSize: 12)),
                          Icon(Icons.mode_comment_outlined, size: 14, color: Theme.of(context).hintColor),
                          Text(' ${p['stats']['comments']}', style: const TextStyle(fontSize: 12)),
                        ],
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Planificateur : calendrier du mois et programme du jour
// ---------------------------------------------------------------------------

class _Planner extends StatefulWidget {
  const _Planner({super.key, required this.networks, required this.onOpen, this.onCreate});
  final List<Map<String, dynamic>> networks;
  final void Function(Map<String, dynamic>) onOpen;
  final void Function(DateTime)? onCreate;

  @override
  State<_Planner> createState() => _PlannerState();
}

class _PlannerState extends State<_Planner> {
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  DateTime _day = DateUtils.dateOnly(DateTime.now());
  List<Map<String, dynamic>> _posts = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final from = _month.subtract(const Duration(days: 7));
    final to = DateTime(_month.year, _month.month + 1, 7);
    try {
      final r = await context.read<Session>().api.get('social/posts', query: {'from': from.toIso8601String(), 'to': to.toIso8601String(), 'order': 'asc', 'limit': '500'});
      if (mounted) setState(() => _posts = [for (final p in (r['data'] as List)) Map<String, dynamic>.from(p as Map)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  DateTime? _when(Map<String, dynamic> p) => _dt(p['published_at']) ?? _dt(p['scheduled_at']);

  List<Map<String, dynamic>> _on(DateTime d) => _posts.where((p) {
    final w = _when(p);
    return w != null && DateUtils.isSameDay(w, d) && p['status'] != 'cancelled';
  }).toList();

  void _shift(int m) {
    setState(() => _month = DateTime(_month.year, _month.month + m));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= 900;
    final cal = _calendar(context, wide);
    final day = _dayList(context);
    if (wide) {
      return Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 3,
            child: SingleChildScrollView(padding: const EdgeInsets.all(16), child: cal),
          ),
          SizedBox(width: 380, child: day),
        ],
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(12, 12, 12, 90), children: [cal, const SizedBox(height: 12), day]),
    );
  }

  Widget _calendar(BuildContext context, bool wide) {
    final first = DateTime(_month.year, _month.month);
    final start = first.subtract(Duration(days: first.weekday - 1));
    final today = DateUtils.dateOnly(DateTime.now());
    final theme = Theme.of(context);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          children: [
            Row(
              children: [
                IconButton(onPressed: () => _shift(-1), icon: const Icon(Icons.chevron_left)),
                Expanded(
                  child: Text(
                    toBeginningOfSentenceCase(DateFormat('MMMM yyyy', 'fr').format(_month)),
                    textAlign: TextAlign.center,
                    style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                  ),
                ),
                if (_loading) const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
                IconButton(onPressed: () => _shift(1), icon: const Icon(Icons.chevron_right)),
              ],
            ),
            Row(
              children: [
                for (final d in ['L', 'M', 'M', 'J', 'V', 'S', 'D'])
                  Expanded(
                    child: Center(
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 6),
                        child: Text(
                          d,
                          style: TextStyle(color: theme.hintColor, fontWeight: FontWeight.w600),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
            for (var w = 0; w < 6; w++)
              Row(
                children: [
                  for (var i = 0; i < 7; i++)
                    Builder(
                      builder: (context) {
                        final d = start.add(Duration(days: w * 7 + i));
                        final items = _on(d);
                        final sel = DateUtils.isSameDay(d, _day);
                        final inMonth = d.month == _month.month;
                        return Expanded(
                          child: InkWell(
                            borderRadius: BorderRadius.circular(10),
                            onTap: () => setState(() => _day = d),
                            onDoubleTap: widget.onCreate == null ? null : () => widget.onCreate!(DateTime(d.year, d.month, d.day, 10)),
                            child: Container(
                              height: wide ? 92 : 54,
                              margin: const EdgeInsets.all(2),
                              padding: const EdgeInsets.all(4),
                              decoration: BoxDecoration(
                                color: sel ? YColors.blue.withValues(alpha: .10) : null,
                                border: Border.all(color: sel ? YColors.blue : theme.dividerColor.withValues(alpha: .4)),
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    '${d.day}',
                                    style: TextStyle(
                                      fontWeight: DateUtils.isSameDay(d, today) ? FontWeight.w900 : FontWeight.w500,
                                      color: !inMonth ? theme.hintColor.withValues(alpha: .5) : (DateUtils.isSameDay(d, today) ? YColors.blue : null),
                                      fontSize: 12.5,
                                    ),
                                  ),
                                  const Spacer(),
                                  if (wide)
                                    for (final p in items.take(2))
                                      Container(
                                        margin: const EdgeInsets.only(top: 2),
                                        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                                        decoration: BoxDecoration(color: (_statusColors['${p['status']}'] ?? YColors.muted).withValues(alpha: .15), borderRadius: BorderRadius.circular(4)),
                                        child: Text(
                                          verbatim('${DateFormat('HH:mm').format(_when(p)!)} ${p['title'] ?? p['body'] ?? ''}'),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                          style: const TextStyle(fontSize: 10.5),
                                        ),
                                      )
                                  else if (items.isNotEmpty)
                                    Row(
                                      children: [
                                        for (final p in items.take(3))
                                          Container(
                                            width: 7,
                                            height: 7,
                                            margin: const EdgeInsets.only(right: 2),
                                            decoration: BoxDecoration(color: _statusColors['${p['status']}'] ?? YColors.muted, shape: BoxShape.circle),
                                          ),
                                      ],
                                    ),
                                  if (wide && items.length > 2) Text('+${items.length - 2}', style: TextStyle(fontSize: 10.5, color: theme.hintColor)),
                                ],
                              ),
                            ),
                          ),
                        );
                      },
                    ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  Widget _dayList(BuildContext context) {
    final items = _on(_day)..sort((a, b) => _when(a)!.compareTo(_when(b)!));
    final list = Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(toBeginningOfSentenceCase(DateFormat('EEEE d MMMM', 'fr').format(_day)), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
            ),
            if (widget.onCreate != null)
              TextButton.icon(
                onPressed: () {
                  final now = DateTime.now();
                  final base = DateUtils.isSameDay(_day, now) ? now.add(const Duration(hours: 1)) : DateTime(_day.year, _day.month, _day.day, 10);
                  widget.onCreate!(DateTime(base.year, base.month, base.day, base.hour));
                },
                icon: const Icon(Icons.add),
                label: Text(tr('Planifier')),
              ),
          ],
        ),
        const SizedBox(height: 6),
        if (items.isEmpty)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 24),
            child: Text(
              tr('Rien de prévu ce jour-là.'),
              textAlign: TextAlign.center,
              style: TextStyle(color: Theme.of(context).hintColor),
            ),
          )
        else
          for (final p in items) _PostTile(p: p, networks: widget.networks, onTap: () => widget.onOpen(p), dense: true),
      ],
    );
    return MediaQuery.sizeOf(context).width >= 900 ? SingleChildScrollView(padding: const EdgeInsets.fromLTRB(0, 16, 16, 90), child: list) : list;
  }
}

// ---------------------------------------------------------------------------
// Liste des publications
// ---------------------------------------------------------------------------

class _PostList extends StatefulWidget {
  const _PostList({super.key, required this.networks, required this.onOpen, required this.pending});
  final List<Map<String, dynamic>> networks;
  final void Function(Map<String, dynamic>) onOpen;
  final int pending;

  @override
  State<_PostList> createState() => _PostListState();
}

/// Miniature d'une vidéo YouTube publiée, tirée du lien de la publication.
String? _youtubeThumb(Map<String, dynamic> p) {
  for (final t in (p['targets'] as List? ?? const [])) {
    final u = '${(t as Map)['url'] ?? ''}';
    final m = RegExp(r'(?:youtu\.be/|v=|shorts/|embed/)([\w-]{11})').firstMatch(u);
    if (m != null) return 'https://i.ytimg.com/vi/${m.group(1)}/hqdefault.jpg';
  }
  return null;
}

/// Image à montrer pour une publication : première image, sinon miniature YouTube.
String? _cover(Map<String, dynamic> p) {
  final media = (p['media'] as List? ?? const []);
  for (final m in media) {
    if ((m as Map)['kind'] == 'image') return '${m['url']}';
  }
  return _youtubeThumb(p);
}

bool _hasVideo(Map<String, dynamic> p) => (p['media'] as List? ?? const []).any((m) => (m as Map)['kind'] == 'video');

String _compact(num n) {
  if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(n >= 10000000 ? 0 : 1).replaceAll('.', ',')} M';
  if (n >= 1000) return '${(n / 1000).toStringAsFixed(n >= 10000 ? 0 : 1).replaceAll('.', ',')} k';
  return '$n';
}

class _PostListState extends State<_PostList> {
  String _filter = 'all';
  String _q = '';
  String? _net;
  bool _grid = false;
  int? _selected;
  List<Map<String, dynamic>>? _rows;
  Timer? _debounce;

  // « Toutes » d'abord, puis les catégories
  static const _filters = {
    'all': ('Toutes', <String>[], Icons.all_inbox_outlined),
    'upcoming': ('À venir', ['scheduled', 'pending', 'publishing'], Icons.schedule),
    'pending': ('À valider', ['pending'], Icons.fact_check_outlined),
    'draft': ('Brouillons', ['draft'], Icons.edit_note),
    'published': ('Publiées', ['published', 'partial'], Icons.check_circle_outline),
    'failed': ('Échecs', ['failed', 'partial'], Icons.error_outline),
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('social/posts', query: {if (_q.isNotEmpty) 'q': _q, 'limit': '500'});
      if (!mounted) return;
      setState(() => _rows = [for (final p in (r['data'] as List)) Map<String, dynamic>.from(p as Map)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  bool _inFilter(Map<String, dynamic> p, String f) {
    final st = _filters[f]!.$2;
    return st.isEmpty || st.contains(p['status']);
  }

  bool _inNet(Map<String, dynamic> p) => _net == null || (p['targets'] as List? ?? const []).any((t) => (t as Map)['network'] == _net);

  List<Map<String, dynamic>> get _visible {
    final l = (_rows ?? const <Map<String, dynamic>>[]).where((p) => _inFilter(p, _filter) && _inNet(p)).toList();
    if (_filter == 'upcoming') l.sort((a, b) => '${a['scheduled_at'] ?? ''}'.compareTo('${b['scheduled_at'] ?? ''}'));
    return l;
  }

  Color _netColor(String n) => _hex(widget.networks.firstWhere((x) => x['key'] == n, orElse: () => const {})['color']);

  static String _dayLabel(DateTime? d) {
    if (d == null) return 'Sans date';
    final now = DateTime.now();
    final day = DateTime(d.year, d.month, d.day);
    final diff = day.difference(DateTime(now.year, now.month, now.day)).inDays;
    if (diff == 0) return 'Aujourd\'hui';
    if (diff == -1) return 'Hier';
    if (diff == 1) return 'Demain';
    final s = DateFormat(d.year == now.year ? 'EEEE d MMMM' : 'EEEE d MMMM yyyy', 'fr').format(d);
    return s[0].toUpperCase() + s.substring(1);
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    final t = Theme.of(context);
    return LayoutBuilder(builder: (context, box) {
      final wide = box.maxWidth >= 1050;
      final list = _visible;
      Map<String, dynamic>? sel;
      if (wide && list.isNotEmpty) sel = list.firstWhere((p) => p['id'] == _selected, orElse: () => list.first);
      final nets = <String>{for (final p in rows ?? const <Map<String, dynamic>>[]) for (final x in (p['targets'] as List? ?? const [])) '${(x as Map)['network']}'}.toList()..sort();
      final views = list.fold<num>(0, (a, p) => a + ((p['stats']?['views'] as num?) ?? 0));
      final likes = list.fold<num>(0, (a, p) => a + ((p['stats']?['likes'] as num?) ?? 0));
      final comments = list.fold<num>(0, (a, p) => a + ((p['stats']?['comments'] as num?) ?? 0));

      final toolbar = Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 6),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Wrap(
            runSpacing: 8,
            children: [
              for (final f in _filters.entries) _FilterPill(
                icon: f.value.$3,
                label: tr(f.value.$1),
                count: rows?.where((p) => _inFilter(p, f.key) && _inNet(p)).length,
                alert: f.key == 'pending' || f.key == 'failed',
                selected: _filter == f.key,
                onTap: () => setState(() => _filter = f.key),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(
              child: SearchField(
                hint: tr('Rechercher un texte, une campagne…'),
                onChanged: (v) {
                  _q = v;
                  _debounce?.cancel();
                  _debounce = Timer(const Duration(milliseconds: 400), _load);
                },
              ),
            ),
            if (nets.length > 1) ...[
              const SizedBox(width: 8),
              PopupMenuButton<String>(
                tooltip: tr('Filtrer par réseau'),
                onSelected: (v) => setState(() => _net = v == '' ? null : v),
                itemBuilder: (_) => [
                  PopupMenuItem(value: '', child: Text(tr('Tous les réseaux'))),
                  for (final n in nets)
                    PopupMenuItem(
                      value: n,
                      child: Row(children: [_NetBadge(n, color: _netColor(n), size: 22), const SizedBox(width: 10), Text(verbatim('${widget.networks.firstWhere((x) => x['key'] == n, orElse: () => {'label': n})['label']}'))]),
                    ),
                ],
                child: Container(
                  height: 44,
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  decoration: BoxDecoration(border: Border.all(color: t.dividerColor), borderRadius: BorderRadius.circular(12), color: t.cardColor),
                  child: Row(children: [
                    _net == null ? Icon(Icons.filter_list, size: 18, color: t.hintColor) : _NetBadge(_net!, color: _netColor(_net!), size: 20),
                    const SizedBox(width: 6),
                    Text(tr(_net == null ? 'Réseaux' : 'Filtré'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    const Icon(Icons.arrow_drop_down),
                  ]),
                ),
              ),
            ],
            const SizedBox(width: 8),
            SegmentedButton<bool>(
              showSelectedIcon: false,
              style: const ButtonStyle(visualDensity: VisualDensity.compact),
              segments: [
                ButtonSegment(value: false, icon: const Icon(Icons.view_agenda_outlined, size: 18), tooltip: tr('Liste')),
                ButtonSegment(value: true, icon: const Icon(Icons.grid_view_rounded, size: 18), tooltip: tr('Grille')),
              ],
              selected: {_grid},
              onSelectionChanged: (v) => setState(() => _grid = v.first),
            ),
          ]),
          if (list.isNotEmpty && (views + likes + comments) > 0) ...[
            const SizedBox(height: 10),
            Wrap(spacing: 18, runSpacing: 6, children: [
              _Kpi(icon: Icons.article_outlined, value: '${list.length}', label: tr(list.length > 1 ? 'publications' : 'publication')),
              if (views > 0) _Kpi(icon: Icons.visibility_outlined, value: _compact(views), label: tr('vues')),
              _Kpi(icon: Icons.favorite_border, value: _compact(likes), label: tr('j\'aime')),
              _Kpi(icon: Icons.mode_comment_outlined, value: _compact(comments), label: tr('commentaires')),
            ]),
          ],
        ]),
      );

      Widget body;
      if (rows == null) {
        body = const Center(child: CircularProgressIndicator());
      } else if (list.isEmpty) {
        body = EmptyState(icon: Icons.inbox_outlined, title: tr('Aucune publication'), message: tr(_q.isNotEmpty || _net != null ? 'Aucun résultat pour cette recherche.' : 'Les publications de cette catégorie apparaîtront ici.'));
      } else if (_grid) {
        body = RefreshIndicator(
          onRefresh: _load,
          child: GridView.builder(
            padding: const EdgeInsets.fromLTRB(16, 6, 16, 96),
            gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(maxCrossAxisExtent: 300, mainAxisExtent: 300, crossAxisSpacing: 12, mainAxisSpacing: 12),
            itemCount: list.length,
            itemBuilder: (_, i) => _PostCard(p: list[i], networks: widget.networks, grid: true, selected: wide && sel?['id'] == list[i]['id'], onTap: () => wide ? setState(() => _selected = list[i]['id'] as int) : widget.onOpen(list[i]), onOpen: () => widget.onOpen(list[i])),
          ),
        );
      } else {
        final children = <Widget>[];
        String? last;
        for (final p in list) {
          final label = _dayLabel(_dt(p['published_at']) ?? _dt(p['scheduled_at']) ?? _dt(p['created_at']));
          if (label != last) {
            children.add(Padding(padding: const EdgeInsets.fromLTRB(4, 12, 4, 6), child: Text(tr(label), style: TextStyle(color: t.hintColor, fontWeight: FontWeight.w700, fontSize: 12.5))));
            last = label;
          }
          children.add(_PostCard(p: p, networks: widget.networks, selected: wide && sel?['id'] == p['id'], onTap: () => wide ? setState(() => _selected = p['id'] as int) : widget.onOpen(p), onOpen: () => widget.onOpen(p)));
        }
        body = RefreshIndicator(onRefresh: _load, child: ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 96), children: children));
      }

      final main = Column(children: [toolbar, Expanded(child: body)]);
      if (!wide) return main;
      return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(child: main),
        SizedBox(
          width: box.maxWidth >= 1300 ? 420 : 380,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(0, 14, 16, 88),
            child: sel == null
                ? const SizedBox.shrink()
                : _PostPreview(key: ValueKey(sel['id']), p: sel, networks: widget.networks, onOpen: () => widget.onOpen(sel!)),
          ),
        ),
      ]);
    });
  }
}

class _FilterPill extends StatelessWidget {
  const _FilterPill({required this.icon, required this.label, required this.count, required this.selected, required this.onTap, this.alert = false});
  final IconData icon;
  final String label;
  final int? count;
  final bool selected;
  final bool alert;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final hot = alert && (count ?? 0) > 0;
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: Material(
        color: selected ? YColors.blue : t.cardColor,
        borderRadius: BorderRadius.circular(24),
        child: InkWell(
          borderRadius: BorderRadius.circular(24),
          onTap: onTap,
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 160),
            padding: const EdgeInsets.fromLTRB(12, 7, 8, 7),
            decoration: BoxDecoration(borderRadius: BorderRadius.circular(24), border: Border.all(color: selected ? YColors.blue : t.dividerColor)),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              if (MediaQuery.sizeOf(context).width >= 1600 || MediaQuery.sizeOf(context).width < 1050) ...[Icon(icon, size: 16, color: selected ? Colors.white : t.hintColor), const SizedBox(width: 6)],
              Text(label, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: selected ? Colors.white : null)),
              if (count != null) ...[
                const SizedBox(width: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                  decoration: BoxDecoration(
                    color: selected ? Colors.white.withValues(alpha: .22) : (hot ? YColors.danger.withValues(alpha: .12) : t.hintColor.withValues(alpha: .1)),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text('$count', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: selected ? Colors.white : (hot ? YColors.danger : t.hintColor))),
                ),
              ],
            ]),
          ),
        ),
      ),
    );
  }
}

class _Kpi extends StatelessWidget {
  const _Kpi({required this.icon, required this.value, required this.label});
  final IconData icon;
  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 16, color: hint),
      const SizedBox(width: 5),
      Text(value, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
      const SizedBox(width: 4),
      Text(label, style: TextStyle(color: hint, fontSize: 13)),
    ]);
  }
}

/// Visuel d'une publication : image, miniature YouTube ou vidéo, avec pictogramme de lecture.
class _Cover extends StatelessWidget {
  const _Cover({required this.p, this.radius = 12, this.playSize = 22});
  final Map<String, dynamic> p;
  final double radius;
  final double playSize;

  @override
  Widget build(BuildContext context) {
    final url = _cover(p);
    final video = _hasVideo(p);
    final hasMedia = (p['media'] as List? ?? const []).isNotEmpty || url != null;
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: Stack(fit: StackFit.expand, children: [
        if (url != null)
          Image.network(url, fit: BoxFit.cover, errorBuilder: (_, _, _) => const _CoverFallback())
        else if (hasMedia)
          const _CoverFallback()
        else
          Container(
            decoration: BoxDecoration(gradient: LinearGradient(colors: [YColors.blue.withValues(alpha: .14), YColors.blue.withValues(alpha: .04)], begin: Alignment.topLeft, end: Alignment.bottomRight)),
            child: Icon(Icons.notes_rounded, color: YColors.blue.withValues(alpha: .7), size: playSize * 1.3),
          ),
        if (video)
          Center(
            child: Container(
              padding: EdgeInsets.all(playSize * .25),
              decoration: BoxDecoration(color: Colors.black.withValues(alpha: .55), shape: BoxShape.circle),
              child: Icon(Icons.play_arrow_rounded, color: Colors.white, size: playSize),
            ),
          ),
      ]),
    );
  }
}

class _CoverFallback extends StatelessWidget {
  const _CoverFallback();
  @override
  Widget build(BuildContext context) => Container(
        decoration: const BoxDecoration(gradient: LinearGradient(colors: [Color(0xFF0E1E45), Color(0xFF1D4FBF)], begin: Alignment.topLeft, end: Alignment.bottomRight)),
      );
}

/// Carte d'une publication dans la liste (ligne) ou la grille.
class _PostCard extends StatelessWidget {
  const _PostCard({required this.p, required this.networks, required this.onTap, required this.onOpen, this.selected = false, this.grid = false});
  final Map<String, dynamic> p;
  final List<Map<String, dynamic>> networks;
  final VoidCallback onTap;
  final VoidCallback onOpen;
  final bool selected;
  final bool grid;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final st = '${p['status']}';
    final color = _statusColors[st] ?? YColors.muted;
    final when = _dt(p['published_at']) ?? _dt(p['scheduled_at']);
    final title = '${p['title'] ?? ''}'.trim();
    final body = '${p['body'] ?? ''}'.trim();
    final head = title.isNotEmpty ? title : body.split('\n').first;
    final rest = title.isNotEmpty ? body : body.split('\n').skip(1).join(' ');
    final targets = [for (final x in (p['targets'] as List? ?? const [])) Map<String, dynamic>.from(x as Map)];
    final accounts = {for (final x in targets) '${x['account'] ?? ''}'}.where((e) => e.isNotEmpty).toList();
    final stats = Map<String, dynamic>.from(p['stats'] as Map? ?? const {});
    final hint = t.hintColor;

    final status = Row(mainAxisSize: MainAxisSize.min, children: [
      Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
      const SizedBox(width: 6),
      Text(tr('${p['status_label']}'), style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12.5)),
      if (when != null) ...[
        Text('  ·  ', style: TextStyle(color: hint, fontSize: 12.5)),
        Flexible(child: Text(DateFormat(grid ? 'd MMM · HH:mm' : 'EEE d MMM · HH:mm', 'fr').format(when), overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12.5))),
      ],
    ]);

    final footer = Row(children: [
      for (final n in {for (final x in targets) '${x['network']}'}.take(5))
        Padding(padding: const EdgeInsets.only(right: 4), child: _NetBadge(n, color: _hex(networks.firstWhere((x) => x['key'] == n, orElse: () => const {})['color']), size: 20)),
      if (accounts.isNotEmpty) ...[
        const SizedBox(width: 4),
        Flexible(child: Text(verbatim(accounts.join(', ')), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 12))),
      ] else
        const Spacer(),
      if (accounts.isNotEmpty) const SizedBox(width: 8),
      for (final (k, ic) in [('views', Icons.visibility_outlined), ('likes', Icons.favorite_border), ('comments', Icons.mode_comment_outlined)])
        if (((stats[k] as num?) ?? 0) > 0) ...[
          const SizedBox(width: 10),
          Icon(ic, size: 14, color: hint),
          const SizedBox(width: 3),
          Text(_compact(stats[k] as num), style: TextStyle(fontSize: 12, color: hint, fontWeight: FontWeight.w600)),
        ],
    ]);

    final menu = PopupMenuButton<String>(
      tooltip: tr('Actions'),
      icon: Icon(Icons.more_horiz, color: hint),
      onSelected: (v) {
        if (v == 'open') onOpen();
        if (v.startsWith('http')) openUrl(v);
      },
      itemBuilder: (_) => [
        PopupMenuItem(value: 'open', child: Row(children: [const Icon(Icons.open_in_full, size: 18), const SizedBox(width: 10), Text(tr('Voir le détail'))])),
        for (final x in targets.where((x) => '${x['url'] ?? ''}'.startsWith('http')))
          PopupMenuItem(value: '${x['url']}', child: Row(children: [const Icon(Icons.open_in_new, size: 18), const SizedBox(width: 10), Text(tr('Voir sur ${x['network_label'] ?? x['network']}'))])),
      ],
    );

    final decoration = BoxDecoration(
      color: t.cardColor,
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: selected ? YColors.blue : t.dividerColor.withValues(alpha: .6), width: selected ? 1.6 : 1),
      boxShadow: selected ? [BoxShadow(color: YColors.blue.withValues(alpha: .12), blurRadius: 18, offset: const Offset(0, 6))] : null,
    );

    if (grid) {
      return Material(
        color: Colors.transparent,
        child: Ink(
          decoration: decoration,
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(10),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SizedBox(height: 140, width: double.infinity, child: _Cover(p: p, playSize: 28)),
                const SizedBox(height: 10),
                status,
                const SizedBox(height: 6),
                Text(verbatim(head), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, height: 1.3)),
                const Spacer(),
                footer,
              ]),
            ),
          ),
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: Colors.transparent,
        child: Ink(
          decoration: decoration,
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: onTap,
            onDoubleTap: onOpen,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 4, 12),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                SizedBox(width: 96, height: 96, child: _Cover(p: p)),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    status,
                    const SizedBox(height: 6),
                    Text(verbatim(head), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
                    if (rest.trim().isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(verbatim(rest.replaceAll('\n', ' ')), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: hint, fontSize: 13, height: 1.35)),
                    ],
                    const SizedBox(height: 10),
                    footer,
                  ]),
                ),
                menu,
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// Aperçu à droite : la publication telle qu'elle apparaît sur le réseau choisi, avec ses résultats.
class _PostPreview extends StatefulWidget {
  const _PostPreview({super.key, required this.p, required this.networks, required this.onOpen});
  final Map<String, dynamic> p;
  final List<Map<String, dynamic>> networks;
  final VoidCallback onOpen;

  @override
  State<_PostPreview> createState() => _PostPreviewState();
}

class _PostPreviewState extends State<_PostPreview> {
  int _i = 0;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context);
    final p = widget.p;
    final hint = t.hintColor;
    final targets = [for (final x in (p['targets'] as List? ?? const [])) Map<String, dynamic>.from(x as Map)];
    final tg = targets.isEmpty ? null : targets[_i.clamp(0, targets.length - 1)];
    final net = '${tg?['network'] ?? ''}';
    final accent = _hex(widget.networks.firstWhere((x) => x['key'] == net, orElse: () => const {})['color']);
    final variants = p['variants'] is Map ? Map<String, dynamic>.from(p['variants'] as Map) : const <String, dynamic>{};
    final text = '${variants[net] ?? p['body'] ?? ''}';
    final title = '${p['title'] ?? ''}'.trim();
    final when = _dt(tg?['published_at']) ?? _dt(p['published_at']) ?? _dt(p['scheduled_at']);
    final name = '${tg?['account'] ?? 'Votre compte'}';
    final avatar = '${tg?['avatar_url'] ?? ''}';
    final stats = Map<String, dynamic>.from((tg?['stats'] as Map?) ?? (p['stats'] as Map?) ?? const {});
    final url = '${tg?['url'] ?? ''}';
    final media = [for (final m in (p['media'] as List? ?? const [])) Map<String, dynamic>.from(m as Map)];
    final yt = _youtubeThumb(p);
    final tStatus = '${tg?['status'] ?? p['status']}';
    final tColor = _statusColors[tStatus] ?? YColors.muted;

    return Container(
      decoration: BoxDecoration(color: t.cardColor, borderRadius: BorderRadius.circular(18), border: Border.all(color: t.dividerColor.withValues(alpha: .6))),
      clipBehavior: Clip.antiAlias,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 8, 8),
          child: Row(children: [
            Text(tr('Aperçu'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            const Spacer(),
            TextButton.icon(onPressed: widget.onOpen, icon: const Icon(Icons.open_in_full, size: 16), label: Text(tr('Détail et actions'))),
          ]),
        ),
        if (targets.length > 1)
          SizedBox(
            height: 40,
            child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12), children: [
              for (var i = 0; i < targets.length; i++)
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(
                    avatar: _NetBadge('${targets[i]['network']}', color: _hex(widget.networks.firstWhere((x) => x['key'] == targets[i]['network'], orElse: () => const {})['color']), size: 18),
                    label: Text(verbatim('${targets[i]['network_label'] ?? targets[i]['network']}')),
                    selected: _i == i,
                    onSelected: (_) => setState(() => _i = i),
                  ),
                ),
            ]),
          ),
        Expanded(
          child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 16), children: [
            // Maquette de la publication
            Container(
              decoration: BoxDecoration(borderRadius: BorderRadius.circular(14), border: Border.all(color: t.dividerColor.withValues(alpha: .7)), color: t.brightness == Brightness.dark ? const Color(0xFF0F1729) : Colors.white),
              clipBehavior: Clip.antiAlias,
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
                  child: Row(children: [
                    Stack(clipBehavior: Clip.none, children: [
                      CircleAvatar(
                        radius: 19,
                        backgroundColor: accent.withValues(alpha: .15),
                        backgroundImage: avatar.startsWith('http') ? NetworkImage(avatar) : null,
                        child: avatar.startsWith('http') ? null : Text(name.characters.first.toUpperCase(), style: TextStyle(color: accent, fontWeight: FontWeight.w800)),
                      ),
                      if (net.isNotEmpty) Positioned(right: -3, bottom: -3, child: _NetBadge(net, color: accent, size: 17)),
                    ]),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(verbatim(name), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
                        Text(
                          [if ('${tg?['username'] ?? ''}'.isNotEmpty) '@${tg!['username']}', if (when != null) DateFormat('d MMM yyyy · HH:mm', 'fr').format(when)].join('  ·  '),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(color: hint, fontSize: 12),
                        ),
                      ]),
                    ),
                  ]),
                ),
                if (net == 'youtube' && title.isNotEmpty)
                  Padding(padding: const EdgeInsets.fromLTRB(12, 0, 12, 8), child: Text(verbatim(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5))),
                if (media.isNotEmpty || yt != null)
                  media.length > 1
                      ? _MediaGrid(media: media)
                      : AspectRatio(
                          aspectRatio: net == 'youtube' || _hasVideo(p) ? 16 / 9 : 1.2,
                          child: InkWell(onTap: url.startsWith('http') ? () => openUrl(url) : null, child: _Cover(p: p, radius: 0, playSize: 36)),
                        ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
                  child: _ExpandableText(text: text, accent: accent),
                ),
                Divider(height: 1, color: t.dividerColor.withValues(alpha: .6)),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  child: Row(children: [
                    for (final (k, ic) in [('views', Icons.visibility_outlined), ('likes', Icons.thumb_up_alt_outlined), ('comments', Icons.mode_comment_outlined), ('shares', Icons.reply_outlined)]) ...[
                      Icon(ic, size: 16, color: hint),
                      const SizedBox(width: 4),
                      Text(_compact((stats[k] as num?) ?? 0), style: TextStyle(color: hint, fontWeight: FontWeight.w600, fontSize: 12.5)),
                      const SizedBox(width: 16),
                    ],
                  ]),
                ),
              ]),
            ),
            const SizedBox(height: 14),
            // Résultat par compte
            for (final x in targets)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(children: [
                  _NetBadge('${x['network']}', color: _hex(widget.networks.firstWhere((n) => n['key'] == x['network'], orElse: () => const {})['color']), size: 22),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(verbatim('${x['account'] ?? x['network_label']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                      if ('${x['error'] ?? ''}'.isNotEmpty) Text(verbatim('${x['error']}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: YColors.danger, fontSize: 12)),
                    ]),
                  ),
                  StatusChip(tr(switch ('${x['status']}') { 'published' => 'Publiée', 'failed' => 'Échec', 'pending' || 'queued' => 'En attente', 'publishing' => 'En cours', _ => '${x['status']}' }), color: _statusColors['${x['status']}'] ?? tColor),
                  if ('${x['url'] ?? ''}'.startsWith('http')) IconButton(tooltip: tr('Voir sur le réseau'), visualDensity: VisualDensity.compact, onPressed: () => openUrl('${x['url']}'), icon: const Icon(Icons.open_in_new, size: 18)),
                ]),
              ),
            if (p['user_name'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('Rédigée par ${p['user_name']}'), style: TextStyle(color: hint, fontSize: 12))),
          ]),
        ),
        if (url.startsWith('http'))
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
            child: FilledButton.icon(
              onPressed: () => openUrl(url),
              icon: const Icon(Icons.open_in_new, size: 18),
              label: Text(tr('Voir sur ${tg?['network_label'] ?? 'le réseau'}')),
            ),
          ),
      ]),
    );
  }
}

class _ExpandableText extends StatefulWidget {
  const _ExpandableText({required this.text, required this.accent});
  final String text;
  final Color accent;

  @override
  State<_ExpandableText> createState() => _ExpandableTextState();
}

class _ExpandableTextState extends State<_ExpandableText> {
  bool _all = false;

  @override
  Widget build(BuildContext context) {
    final long = widget.text.length > 280 || '\n'.allMatches(widget.text).length > 5;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      if (!long || _all)
        _RichPreview(text: widget.text, accent: widget.accent)
      else
        ShaderMask(
          shaderCallback: (r) => const LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [Colors.black, Colors.black, Colors.transparent], stops: [0, .7, 1]).createShader(r),
          blendMode: BlendMode.dstIn,
          child: SizedBox(height: 132, child: ClipRect(child: OverflowBox(alignment: Alignment.topLeft, maxHeight: double.infinity, child: _RichPreview(text: widget.text, accent: widget.accent)))),
        ),
      if (long) TextButton(style: TextButton.styleFrom(padding: EdgeInsets.zero, visualDensity: VisualDensity.compact), onPressed: () => setState(() => _all = !_all), child: Text(tr(_all ? 'Afficher moins' : 'Afficher plus'))),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Détail d'une publication : résultat par réseau, validation, relance
// ---------------------------------------------------------------------------

class _PostSheet extends StatefulWidget {
  const _PostSheet({required this.postId, required this.networks, required this.can, required this.onEdit});
  final int postId;
  final List<Map<String, dynamic>> networks;
  final Map<String, dynamic> can;
  final void Function(Map<String, dynamic>) onEdit;

  @override
  State<_PostSheet> createState() => _PostSheetState();
}

class _PostSheetState extends State<_PostSheet> {
  Map<String, dynamic>? _p;
  bool _busy = false;
  bool _changed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('social/posts/${widget.postId}');
      if (mounted) setState(() => _p = Map<String, dynamic>.from(r as Map));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _act(String path, {Object? body, String? ok, bool close = false}) async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('social/posts/${widget.postId}/$path', body);
      _changed = true;
      if (!mounted) return;
      if (ok != null) toast(context, tr(ok));
      if (close) {
        Navigator.pop(context, true);
        return;
      }
      setState(() => _p = Map<String, dynamic>.from(r as Map));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reject() async {
    final c = TextEditingController();
    final reason = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Renvoyer pour correction')),
        content: TextField(
          controller: c,
          autofocus: true,
          maxLines: 3,
          decoration: InputDecoration(labelText: tr('Ce qu\'il faut revoir')),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(tr('Renvoyer'))),
        ],
      ),
    );
    if (reason != null && reason.isNotEmpty) await _act('reject', body: {'reason': reason}, ok: 'Publication renvoyée à son auteur.');
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    return PopScope(
      canPop: true,
      onPopInvokedWithResult: (didPop, _) {},
      child: SafeArea(
        child: p == null
            ? const SizedBox(height: 220, child: Center(child: CircularProgressIndicator()))
            : SheetAsDialog.of(context)
                ? ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: _body(context, p))
                : DraggableScrollableSheet(
                expand: false,
                initialChildSize: .8,
                maxChildSize: .95,
                builder: (context, sc) => ListView(controller: sc, padding: const EdgeInsets.fromLTRB(20, 0, 20, 24), children: _body(context, p)),
              ),
      ),
    );
  }

  List<Widget> _body(BuildContext context, Map<String, dynamic> p) {
    final st = '${p['status']}';
    final when = _dt(p['published_at']) ?? _dt(p['scheduled_at']);
    final publish = widget.can['publish'] == true;
    final write = widget.can['manage'] == true || publish;
    final media = (p['media'] as List? ?? const []);
    final targets = (p['targets'] as List? ?? const []).map((t) => Map<String, dynamic>.from(t as Map)).toList();
    final hint = Theme.of(context).hintColor;
    return [
      Row(
        children: [
          StatusChip(tr('${p['status_label']}'), color: _statusColors[st] ?? YColors.muted),
          const SizedBox(width: 10),
          if (when != null)
            Expanded(
              child: Text(DateFormat('EEEE d MMMM yyyy · HH:mm', 'fr').format(when), style: TextStyle(color: hint)),
            ),
          IconButton(onPressed: () => Navigator.pop(context, _changed), icon: const Icon(Icons.close)),
        ],
      ),
      if (p['rejection'] != null)
        Card(
          color: noticeFill(context), shape: noticeShape(context),
          child: ListTile(leading: const Icon(Icons.feedback_outlined), title: Text(tr('À revoir')), subtitle: Text(verbatim('${p['rejection']}'))),
        ),
      if ('${p['title'] ?? ''}'.isNotEmpty)
        Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Text(verbatim('${p['title']}'), style: Theme.of(context).textTheme.titleMedium),
        ),
      const SizedBox(height: 8),
      SelectableText(verbatim('${p['body'] ?? ''}')),
      if (media.isNotEmpty) ...[
        const SizedBox(height: 12),
        SizedBox(
          height: 96,
          child: ListView(
            scrollDirection: Axis.horizontal,
            children: [
              for (final m in media)
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: (m as Map)['kind'] == 'image'
                        ? Image.network('${m['url']}', width: 96, height: 96, fit: BoxFit.cover, errorBuilder: (_, _, _) => const SizedBox(width: 96))
                        : Container(
                            width: 96,
                            height: 96,
                            color: YColors.night,
                            child: const Icon(Icons.play_circle_outline, color: Colors.white, size: 36),
                          ),
                  ),
                ),
            ],
          ),
        ),
      ],
      const SizedBox(height: 16),
      Text(tr('Comptes'), style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
      const SizedBox(height: 4),
      for (final t in targets)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: _NetBadge('${t['network']}', color: _hex(widget.networks.firstWhere((n) => n['key'] == t['network'], orElse: () => const {})['color'])),
          title: Text(verbatim('${t['account']}')),
          subtitle: Text(
            t['status'] == 'failed'
                ? '${t['error'] ?? tr('Échec')}'
                : t['status'] == 'published'
                ? [
                    tr('Publiée'),
                    if (t['stats'] is Map) ...[
                      if ((t['stats'] as Map)['likes'] != null) '${t['stats']['likes']} ${tr('J\'aime')}',
                      if ((t['stats'] as Map)['comments'] != null) '${t['stats']['comments']} ${tr('commentaires')}',
                      if ((t['stats'] as Map)['views'] != null) '${t['stats']['views']} ${tr('vues')}',
                    ],
                  ].join(' · ')
                : '${t['network_label']}',
            style: TextStyle(color: t['status'] == 'failed' ? YColors.danger : null),
          ),
          trailing: t['url'] != null
              ? IconButton(
                  tooltip: tr('Voir sur le réseau'),
                  onPressed: () => safeLaunchUrl(Uri.parse('${t['url']}'), mode: LaunchMode.externalApplication),
                  icon: const Icon(Icons.open_in_new),
                )
              : Icon(t['status'] == 'failed' ? Icons.error_outline : Icons.schedule, color: t['status'] == 'failed' ? YColors.danger : hint),
        ),
      const SizedBox(height: 16),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          if (st == 'pending' && publish) ...[
            FilledButton.icon(
              onPressed: _busy ? null : () => _act('approve', ok: 'Publication validée.'),
              icon: const Icon(Icons.check),
              label: Text(tr('Valider')),
            ),
            OutlinedButton.icon(onPressed: _busy ? null : _reject, icon: const Icon(Icons.undo), label: Text(tr('Renvoyer pour correction'))),
          ],
          if (st == 'scheduled' && publish)
            FilledButton.icon(
              onPressed: _busy ? null : () => _act('publish', ok: 'Publication envoyée.'),
              icon: const Icon(Icons.send),
              label: Text(tr('Publier maintenant')),
            ),
          if ((st == 'failed' || st == 'partial') && publish)
            FilledButton.icon(
              onPressed: _busy ? null : () => _act('retry', ok: 'Nouvel essai lancé.'),
              icon: const Icon(Icons.replay),
              label: Text(tr('Relancer les échecs')),
            ),
          if (write && ['draft', 'pending', 'scheduled', 'failed'].contains(st))
            OutlinedButton.icon(
              onPressed: _busy
                  ? null
                  : () {
                      Navigator.pop(context, _changed);
                      widget.onEdit(p);
                    },
              icon: const Icon(Icons.edit_outlined),
              label: Text(tr('Modifier')),
            ),
          if (write)
            OutlinedButton.icon(
              onPressed: _busy ? null : () => _act('duplicate', ok: 'Copie créée dans les brouillons.', close: true),
              icon: const Icon(Icons.copy),
              label: Text(tr('Dupliquer')),
            ),
          if (write && ['draft', 'pending', 'scheduled', 'failed'].contains(st))
            TextButton.icon(
              onPressed: _busy ? null : () => _act('cancel', ok: 'Publication annulée.'),
              icon: const Icon(Icons.block),
              label: Text(tr('Annuler la publication')),
            ),
          if (write && st != 'publishing')
            TextButton.icon(
              style: TextButton.styleFrom(foregroundColor: YColors.danger),
              onPressed: _busy
                  ? null
                  : () async {
                      if (!await confirm(context, tr('Supprimer'), tr('Supprimer cette publication de Yomara ? (Elle reste en ligne sur les réseaux.)'), danger: true)) return;
                      if (!context.mounted) return;
                      try {
                        await context.read<Session>().api.delete('social/posts/${widget.postId}');
                        if (context.mounted) Navigator.pop(context, true);
                      } catch (e) {
                        if (context.mounted) toast(context, errorText(e), error: true);
                      }
                    },
              icon: const Icon(Icons.delete_outline),
              label: Text(tr('Supprimer')),
            ),
        ],
      ),
      if (p['user_name'] != null)
        Padding(
          padding: const EdgeInsets.only(top: 14),
          child: Text(tr('Rédigée par ${p['user_name']}'), style: TextStyle(color: hint, fontSize: 12.5)),
        ),
    ];
  }
}

// ---------------------------------------------------------------------------
// Rédaction : comptes, texte, compteurs par réseau, médias, assistant, planification
// ---------------------------------------------------------------------------

class SocialComposer extends StatefulWidget {
  const SocialComposer({super.key, required this.networks, required this.accounts, required this.can, required this.ai, this.post, this.initialDate});
  final List<Map<String, dynamic>> networks;
  final List<Map<String, dynamic>> accounts;
  final Map<String, dynamic> can;
  final bool ai;
  final Map<String, dynamic>? post;
  final DateTime? initialDate;

  @override
  State<SocialComposer> createState() => _SocialComposerState();
}

class _SocialComposerState extends State<SocialComposer> {
  late final _body = TextEditingController(text: '${widget.post?['body'] ?? ''}');
  late final _title = TextEditingController(text: '${widget.post?['title'] ?? ''}');
  late final _link = TextEditingController(text: '${widget.post?['link_url'] ?? ''}');
  late final _campaign = TextEditingController(text: '${widget.post?['campaign'] ?? ''}');
  late final _comment = TextEditingController(text: '${(widget.post?['options'] as Map?)?['first_comment'] ?? ''}');
  final Map<String, TextEditingController> _variants = {};
  late final Set<int> _selected = {for (final t in (widget.post?['targets'] as List? ?? const [])) ((t as Map)['account_id'] as num).toInt()};
  late final List<Map<String, dynamic>> _media = [for (final m in (widget.post?['media'] as List? ?? const [])) Map<String, dynamic>.from(m as Map)];
  late DateTime? _at = _dt(widget.post?['scheduled_at']) ?? widget.initialDate;
  late String _tiktok = '${(widget.post?['options'] as Map?)?['tiktok_privacy'] ?? 'PUBLIC_TO_EVERYONE'}';
  late String _youtube = '${(widget.post?['options'] as Map?)?['youtube_privacy'] ?? 'public'}';
  bool _busy = false;
  bool _uploading = false;
  String? _assist;
  String? _previewNet;
  bool _showPreview = false;
  bool _showOptions = false;
  List<String> _errors = [];

  @override
  void initState() {
    super.initState();
    final v = widget.post?['variants'];
    if (v is Map) {
      for (final e in v.entries) {
        final t = (e.value as Map?)?['text'];
        if (t != null) _variants['${e.key}'] = TextEditingController(text: '$t');
      }
    }
    if (_selected.isEmpty && widget.post?['id'] == null) {
      _selected.addAll(widget.accounts.where((a) => a['status'] == 'active').map((a) => (a['id'] as num).toInt()));
    }
    _body.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    for (final c in [_body, _title, _link, _campaign, _comment, ..._variants.values]) {
      c.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> _net(String key) => widget.networks.firstWhere((n) => n['key'] == key, orElse: () => {'key': key, 'label': key, 'limit': 2000});

  List<String> get _selectedNetworks {
    final s = <String>{};
    for (final a in widget.accounts) {
      if (_selected.contains((a['id'] as num).toInt())) s.add('${a['network']}');
    }
    return s.toList();
  }

  int _limit(String n) {
    final d = _net(n);
    if (n == 'sensya') {
      final a = widget.accounts.firstWhere((a) => a['network'] == 'sensya' && _selected.contains((a['id'] as num).toInt()), orElse: () => const {});
      if (a['limit'] != null) return (a['limit'] as num).toInt();
    }
    if (_media.isNotEmpty && d['media_limit'] != null) return (d['media_limit'] as num).toInt();
    return (d['limit'] as num?)?.toInt() ?? 2000;
  }

  String _textFor(String n) => _variants[n]?.text ?? _body.text;

  bool get _needsTitle => _selectedNetworks.any((n) => ['pinterest', 'youtube', 'tiktok'].contains(n));

  Future<void> _addMedia() async {
    final res = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov']);
    if (res.isEmpty || !mounted) return;
    setState(() => _uploading = true);
    final api = context.read<Session>().api;
    for (final f in res) {
      try {
        final bytes = await f.readAsBytes();
        final r = await api.uploadForm('social/media', const {}, 'file', bytes, f.name);
        if (mounted) setState(() => _media.add(Map<String, dynamic>.from(r as Map)));
      } catch (e) {
        if (mounted) toast(context, '${f.name} : ${errorText(e)}', error: true);
      }
    }
    if (mounted) setState(() => _uploading = false);
  }

  Future<void> _adapt() async {
    final nets = _selectedNetworks;
    if (_body.text.trim().isEmpty || nets.isEmpty) {
      toast(context, tr('Écrivez le texte et choisissez les comptes d\'abord.'), error: true);
      return;
    }
    setState(() => _assist = 'adapt');
    try {
      final r = await context.read<Session>().api.post('social/assist/adapt', {
        'text': _body.text,
        'networks': nets,
        'link': _link.text.trim().isEmpty ? null : _link.text.trim(),
        'with_media': _media.isNotEmpty,
      });
      final v = Map<String, dynamic>.from(r['variants'] as Map);
      if (!mounted) return;
      setState(() {
        for (final e in v.entries) {
          final text = '${(e.value as Map)['text']}';
          (_variants[e.key] ??= TextEditingController()).text = text;
          if (_title.text.isEmpty && (e.value as Map)['title'] != null) _title.text = '${(e.value as Map)['title']}';
        }
      });
      if (mounted) toast(context, tr(r['ai'] == true ? 'Textes réécrits par l\'IA pour chaque réseau.' : 'Textes ajustés à la limite de chaque réseau.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _assist = null);
    }
  }

  Future<void> _ideas() async {
    final topic = TextEditingController();
    final t = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr('Idées de publications')),
        content: TextField(
          controller: topic,
          autofocus: true,
          maxLines: 3,
          decoration: InputDecoration(labelText: tr('Sujet'), hintText: tr('Ex. : soldes de fin d\'année, -20 % sur les pagnes jusqu\'au 31 décembre')),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
          FilledButton.icon(onPressed: () => Navigator.pop(ctx, topic.text.trim()), icon: const Icon(Icons.auto_awesome), label: Text(tr('Proposer'))),
        ],
      ),
    );
    if (t == null || t.isEmpty || !mounted) return;
    setState(() => _assist = 'ideas');
    try {
      final r = await context.read<Session>().api.post('social/assist/ideas', {'topic': t, 'count': 5});
      if (!mounted) return;
      final ideas = [for (final i in (r['ideas'] as List)) Map<String, dynamic>.from(i as Map)];
      final pick = await showDialog<String>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(tr(r['ai'] == true ? 'Idées proposées par l\'IA' : 'Modèles à compléter')),
          content: SizedBox(
            width: 560,
            child: ListView(
              shrinkWrap: true,
              children: [
                for (final i in ideas)
                  Card(
                    child: ListTile(
                      title: Text(verbatim('${i['angle']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Padding(padding: const EdgeInsets.only(top: 4), child: Text(verbatim('${i['text']}'))),
                      onTap: () => Navigator.pop(ctx, '${i['text']}'),
                    ),
                  ),
              ],
            ),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Fermer')))],
        ),
      );
      if (pick != null) {
        _body.text = pick;
        for (final c in _variants.values) {
          c.dispose();
        }
        setState(() => _variants.clear());
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _assist = null);
    }
  }

  Future<void> _hashtags() async {
    if (_body.text.trim().isEmpty) return;
    setState(() => _assist = 'tags');
    try {
      final r = await context.read<Session>().api.post('social/assist/hashtags', {'text': _body.text});
      if (!mounted) return;
      final tags = [for (final t in (r['hashtags'] as List)) '$t'];
      final chosen = <String>{...tags.take(4)};
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => StatefulBuilder(
          builder: (ctx, set) => AlertDialog(
            title: Text(tr('Hashtags suggérés')),
            content: Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [for (final t in tags) FilterChip(label: Text(verbatim(t)), selected: chosen.contains(t), onSelected: (v) => set(() => v ? chosen.add(t) : chosen.remove(t)))],
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
              FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Ajouter'))),
            ],
          ),
        ),
      );
      if (ok == true && chosen.isNotEmpty) {
        final add = chosen.where((t) => !_body.text.contains(t)).join(' ');
        if (add.isNotEmpty) _body.text = '${_body.text.trimRight()}\n\n$add';
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _assist = null);
    }
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final base = _at ?? now.add(const Duration(hours: 1));
    final d = await pickDate(context, initialDate: base.isBefore(now) ? now : base, firstDate: DateUtils.dateOnly(now), lastDate: now.add(const Duration(days: 365)));
    if (d == null || !mounted) return;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(base));
    if (t == null) return;
    setState(() => _at = DateTime(d.year, d.month, d.day, t.hour, t.minute));
  }

  Future<void> _bestTime() async {
    try {
      final r = await context.read<Session>().api.get('social/best-times', query: {'networks[]': _selectedNetworks});
      final next = _dt(r['next']);
      if (next != null && mounted) {
        setState(() => _at = next);
        toast(context, tr('Créneau conseillé : ${DateFormat('EEEE d MMMM à HH:mm', 'fr').format(next)}'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _save(String action) async {
    setState(() {
      _busy = true;
      _errors = [];
    });
    final payload = {
      'action': action,
      'body': _body.text,
      'title': _title.text.trim().isEmpty ? null : _title.text.trim(),
      'link_url': _link.text.trim().isEmpty ? null : _link.text.trim(),
      'campaign': _campaign.text.trim().isEmpty ? null : _campaign.text.trim(),
      'accounts': _selected.toList(),
      'media': [for (final m in _media) m['id']],
      'variants': {
        for (final e in _variants.entries)
          if (_selectedNetworks.contains(e.key) && e.value.text.trim().isNotEmpty) e.key: {'text': e.value.text},
      },
      'scheduled_at': action == 'schedule' ? _at?.toUtc().toIso8601String() : null,
      'options': {if (_comment.text.trim().isNotEmpty) 'first_comment': _comment.text.trim(), 'tiktok_privacy': _tiktok, 'youtube_privacy': _youtube},
    };
    try {
      final api = context.read<Session>().api;
      final r = widget.post?['id'] == null ? await api.post('social/posts', payload) : await api.put('social/posts/${widget.post!['id']}', payload);
      if (!mounted) return;
      final st = '${r['status']}';
      toast(
        context,
        tr(switch (st) {
          'draft' => 'Brouillon enregistré.',
          'pending' => 'Envoyée pour validation.',
          'scheduled' => 'Publication planifiée.',
          'published' => 'Publiée sur tous les comptes.',
          'partial' => 'Publiée en partie : voyez le détail des échecs.',
          'failed' => 'La publication a échoué : voyez le détail.',
          _ => 'Enregistré.',
        }),
        error: st == 'failed',
      );
      Navigator.pop(context, true);
    } on ApiException catch (e) {
      final list = (e.errors?['body'] as List?)?.map((x) => '$x').toList();
      if (mounted) {
        setState(() => _errors = list ?? [e.detail]);
        toast(context, e.detail, error: true);
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool get _scheduled => _at != null && _at!.isAfter(DateTime.now().add(const Duration(minutes: 1)));

  Map<String, dynamic> _accountFor(String n) => widget.accounts.firstWhere((a) => a['network'] == n && _selected.contains((a['id'] as num).toInt()), orElse: () => const {});

  @override
  Widget build(BuildContext context) {
    final wide = MediaQuery.sizeOf(context).width >= 1000;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final bg = dark ? Theme.of(context).scaffoldBackgroundColor : const Color(0xFFF3F5FA);
    final editor = _editor(context);
    final preview = _preview(context);
    return Scaffold(
      backgroundColor: bg,
      appBar: AppBar(
        backgroundColor: bg,
        surfaceTintColor: Colors.transparent,
        titleSpacing: 0,
        title: Row(
          children: [
            Text(tr(widget.post?['id'] == null ? 'Nouvelle publication' : 'Modifier la publication'), style: const TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(width: 10),
            if (_selected.isNotEmpty)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
                decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
                child: Text(
                  tr('${_selected.length} compte(s)'),
                  style: const TextStyle(color: YColors.blue, fontSize: 12.5, fontWeight: FontWeight.w700),
                ),
              ),
          ],
        ),
        actions: [
          if (!wide)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: SegmentedButton<bool>(
                showSelectedIcon: false,
                style: const ButtonStyle(visualDensity: VisualDensity.compact),
                segments: [
                  ButtonSegment(value: false, icon: const Icon(Icons.edit_outlined, size: 18), tooltip: tr('Rédiger')),
                  ButtonSegment(value: true, icon: const Icon(Icons.visibility_outlined, size: 18), tooltip: tr('Aperçu')),
                ],
                selected: {_showPreview},
                onSelectionChanged: (s) => setState(() => _showPreview = s.first),
              ),
            ),
          TextButton(onPressed: _busy ? null : () => _save('draft'), child: Text(tr('Enregistrer le brouillon'))),
          const SizedBox(width: 8),
        ],
      ),
      body: wide
          ? Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 1360),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      flex: 11,
                      child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 12, 24), children: editor),
                    ),
                    Expanded(
                      flex: 9,
                      child: ListView(padding: const EdgeInsets.fromLTRB(12, 8, 24, 24), children: preview),
                    ),
                  ],
                ),
              ),
            )
          : ListView(padding: const EdgeInsets.fromLTRB(14, 4, 14, 24), children: _showPreview ? preview : editor),
      bottomNavigationBar: _bottomBar(context),
    );
  }

  /// Carte blanche arrondie commune à toutes les sections.
  Widget _panel(BuildContext context, {required Widget child, EdgeInsets padding = const EdgeInsets.all(18), Color? border}) {
    final theme = Theme.of(context);
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: padding,
      decoration: BoxDecoration(
        color: theme.cardColor,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: border ?? theme.dividerColor.withValues(alpha: .35)),
        boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: .035), blurRadius: 18, offset: const Offset(0, 6))],
      ),
      child: child,
    );
  }

  Widget _label(BuildContext context, String text, {Widget? trailing}) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Row(
      children: [
        Expanded(
          child: Text(tr(text), style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
        ),
        ?trailing,
      ],
    ),
  );

  List<Widget> _editor(BuildContext context) {
    final theme = Theme.of(context);
    final active = widget.accounts.where((a) => a['status'] != 'disabled').toList();
    final allOn = active.isNotEmpty && active.every((a) => _selected.contains((a['id'] as num).toInt()));
    return [
      if (_errors.isNotEmpty)
        _panel(
          context,
          border: YColors.danger.withValues(alpha: .5),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.error_outline, color: YColors.danger),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [for (final e in _errors) Padding(padding: const EdgeInsets.only(bottom: 4), child: Text(e))],
                ),
              ),
            ],
          ),
        ),

      // Comptes : rangée d'avatars, anneau coloré quand le compte est choisi
      _panel(
        context,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _label(
              context,
              'Publier sur',
              trailing: TextButton(
                onPressed: () => setState(() => allOn ? _selected.clear() : _selected.addAll(active.map((a) => (a['id'] as num).toInt()))),
                child: Text(tr(allOn ? 'Aucun' : 'Tous')),
              ),
            ),
            Wrap(
              spacing: 14,
              runSpacing: 12,
              children: [
                for (final a in widget.accounts)
                  _AccountAvatar(
                    account: a,
                    color: _hex(_net('${a['network']}')['color']),
                    selected: _selected.contains((a['id'] as num).toInt()),
                    onTap: a['status'] == 'disabled'
                        ? null
                        : () => setState(() {
                            final id = (a['id'] as num).toInt();
                            _selected.contains(id) ? _selected.remove(id) : _selected.add(id);
                          }),
                  ),
              ],
            ),
          ],
        ),
      ),

      // Texte : zone sans bordure, médias et outils dans la même carte
      _panel(
        context,
        padding: const EdgeInsets.fromLTRB(18, 14, 18, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TextField(
              controller: _body,
              minLines: 7,
              maxLines: 18,
              style: const TextStyle(fontSize: 16, height: 1.5),
              decoration: InputDecoration(
                hintText: tr('Que voulez-vous partager aujourd\'hui ?'),
                hintStyle: TextStyle(color: theme.hintColor, fontSize: 16),
                border: InputBorder.none,
                enabledBorder: InputBorder.none,
                focusedBorder: InputBorder.none,
                filled: false,
                contentPadding: EdgeInsets.zero,
              ),
            ),
            if (_media.isNotEmpty || _uploading) ...[
              const SizedBox(height: 12),
              SizedBox(
                height: 92,
                child: ReorderableListView(
                  scrollDirection: Axis.horizontal,
                  buildDefaultDragHandles: false,
                  onReorderItem: (a, b) => setState(() => _media.insert(b, _media.removeAt(a))),
                  footer: _uploading
                      ? Container(
                          width: 92,
                          height: 92,
                          alignment: Alignment.center,
                          decoration: BoxDecoration(color: theme.dividerColor.withValues(alpha: .25), borderRadius: BorderRadius.circular(14)),
                          child: const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4)),
                        )
                      : null,
                  children: [
                    for (final (i, m) in _media.indexed)
                      ReorderableDelayedDragStartListener(
                        key: ValueKey(m['id']),
                        index: i,
                        child: Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: Stack(
                            children: [
                              ClipRRect(
                                borderRadius: BorderRadius.circular(14),
                                child: m['kind'] == 'image'
                                    ? Image.network(
                                        '${m['url']}',
                                        width: 92,
                                        height: 92,
                                        fit: BoxFit.cover,
                                        errorBuilder: (_, _, _) => Container(width: 92, height: 92, color: YColors.line),
                                      )
                                    : Container(
                                        width: 92,
                                        height: 92,
                                        color: YColors.night,
                                        child: const Icon(Icons.play_circle_fill, color: Colors.white, size: 34),
                                      ),
                              ),
                              Positioned(
                                right: 4,
                                top: 4,
                                child: Material(
                                  color: Colors.black.withValues(alpha: .55),
                                  shape: const CircleBorder(),
                                  child: InkWell(
                                    customBorder: const CircleBorder(),
                                    onTap: () => setState(() => _media.remove(m)),
                                    child: const Padding(
                                      padding: EdgeInsets.all(3),
                                      child: Icon(Icons.close, size: 14, color: Colors.white),
                                    ),
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 10),
            Divider(height: 1, color: theme.dividerColor.withValues(alpha: .4)),
            const SizedBox(height: 6),
            Row(
              children: [
                _ToolIcon(icon: Icons.image_outlined, tooltip: 'Photos et vidéos', onTap: _uploading ? null : _addMedia),
                _ToolIcon(icon: Icons.tag, tooltip: 'Hashtags suggérés', busy: _assist == 'tags', onTap: _assist == null ? _hashtags : null),
                _ToolIcon(icon: Icons.lightbulb_outline, tooltip: 'Idées de publications', busy: _assist == 'ideas', onTap: _assist == null ? _ideas : null),
                _ToolIcon(icon: Icons.link, tooltip: 'Ajouter un lien', onTap: () => setState(() => _showOptions = true)),
                const Spacer(),
                Text(tr('${_body.text.runes.length} caractères'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
              ],
            ),
          ],
        ),
      ),

      // Limites par réseau et adaptation par l'IA
      if (_selectedNetworks.isNotEmpty)
        _panel(
          context,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _label(context, 'Longueur par réseau'),
              _Counters(
                networks: _selectedNetworks,
                label: (n) => '${_net(n)['label']}',
                color: (n) => _hex(_net(n)['color']),
                count: (n) => countFor(n, _textFor(n)),
                limit: _limit,
                custom: (n) => _variants.containsKey(n),
                onTap: (n) => setState(() {
                  _previewNet = n;
                  _showPreview = true;
                }),
              ),
              const SizedBox(height: 14),
              _AiButton(busy: _assist == 'adapt', ai: widget.ai, onTap: _assist == null && _body.text.trim().isNotEmpty ? _adapt : null),
              const SizedBox(height: 8),
              Text(
                tr(
                  widget.ai
                      ? 'L\'IA réécrit le texte dans le style et la limite de chaque réseau. Vous pouvez ensuite retoucher chaque version dans l\'aperçu.'
                      : 'Le texte est raccourci proprement pour chaque réseau. Activez l\'Assistant IA pour une vraie réécriture.',
                ),
                style: TextStyle(color: theme.hintColor, fontSize: 12.5, height: 1.4),
              ),
            ],
          ),
        ),

      // Lien, titre et options propres à certains réseaux
      _panel(
        context,
        padding: EdgeInsets.zero,
        child: Theme(
          data: theme.copyWith(dividerColor: Colors.transparent),
          child: ExpansionTile(
            key: ValueKey('opt$_showOptions'),
            initiallyExpanded: _showOptions || _link.text.isNotEmpty,
            onExpansionChanged: (v) => _showOptions = v,
            tilePadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 2),
            childrenPadding: const EdgeInsets.fromLTRB(18, 0, 18, 18),
            leading: const Icon(Icons.tune),
            title: Text(tr('Lien, titre et options'), style: const TextStyle(fontWeight: FontWeight.w800)),
            subtitle: Text(tr('Lien, campagne, premier commentaire, visibilité'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
            children: [
              TextField(
                controller: _link,
                keyboardType: TextInputType.url,
                decoration: InputDecoration(labelText: tr('Lien (site, boutique, article)'), prefixIcon: const Icon(Icons.link)),
              ),
              const SizedBox(height: 12),
              if (_needsTitle) ...[
                TextField(
                  controller: _title,
                  maxLength: 100,
                  decoration: InputDecoration(labelText: tr('Titre (Pinterest, YouTube, TikTok photo)'), prefixIcon: const Icon(Icons.title)),
                ),
                const SizedBox(height: 4),
              ],
              if (_selectedNetworks.any((n) => ['facebook', 'instagram'].contains(n))) ...[
                TextField(
                  controller: _comment,
                  decoration: InputDecoration(labelText: tr('Premier commentaire (Facebook, Instagram)'), hintText: tr('Ex. : vos hashtags'), prefixIcon: const Icon(Icons.mode_comment_outlined)),
                ),
                const SizedBox(height: 12),
              ],
              if (_selectedNetworks.contains('tiktok')) ...[
                DropdownButtonFormField<String>(
                  initialValue: _tiktok,
                  decoration: InputDecoration(labelText: tr('Visibilité TikTok'), prefixIcon: const Icon(Icons.lock_open_outlined)),
                  items: [
                    DropdownMenuItem(value: 'PUBLIC_TO_EVERYONE', child: Text(tr('Tout le monde'))),
                    DropdownMenuItem(value: 'MUTUAL_FOLLOW_FRIENDS', child: Text(tr('Amis'))),
                    DropdownMenuItem(value: 'FOLLOWER_OF_CREATOR', child: Text(tr('Abonnés'))),
                    DropdownMenuItem(value: 'SELF_ONLY', child: Text(tr('Moi seulement'))),
                  ],
                  onChanged: (v) => setState(() => _tiktok = v ?? _tiktok),
                ),
                const SizedBox(height: 12),
              ],
              if (_selectedNetworks.contains('youtube')) ...[
                DropdownButtonFormField<String>(
                  initialValue: _youtube,
                  decoration: InputDecoration(labelText: tr('Visibilité YouTube'), prefixIcon: const Icon(Icons.public)),
                  items: [
                    DropdownMenuItem(value: 'public', child: Text(tr('Publique'))),
                    DropdownMenuItem(value: 'unlisted', child: Text(tr('Non répertoriée'))),
                    DropdownMenuItem(value: 'private', child: Text(tr('Privée'))),
                  ],
                  onChanged: (v) => setState(() => _youtube = v ?? _youtube),
                ),
                const SizedBox(height: 12),
              ],
              TextField(
                controller: _campaign,
                decoration: InputDecoration(labelText: tr('Campagne (pour regrouper les publications)'), prefixIcon: const Icon(Icons.sell_outlined)),
              ),
            ],
          ),
        ),
      ),
      if (_selectedNetworks.any((n) => _net(n)['media'] != 'optional') && _media.isEmpty)
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 6),
          child: Row(
            children: [
              Icon(Icons.info_outline, size: 16, color: theme.hintColor),
              const SizedBox(width: 6),
              Expanded(
                child: Text(tr('Instagram, TikTok et Pinterest exigent une image ou une vidéo ; YouTube une vidéo.'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
              ),
            ],
          ),
        ),
    ];
  }

  /// Aperçu fidèle d'un réseau à la fois : en-tête du compte, texte, médias et barre d'actions.
  List<Widget> _preview(BuildContext context) {
    final theme = Theme.of(context);
    final nets = _selectedNetworks;
    if (nets.isEmpty) {
      return [
        _panel(
          context,
          child: Column(
            children: [
              const SizedBox(height: 30),
              Icon(Icons.phone_iphone, size: 46, color: theme.hintColor.withValues(alpha: .5)),
              const SizedBox(height: 12),
              Text(
                tr('Choisissez des comptes pour voir l\'aperçu de la publication sur chaque réseau.'),
                textAlign: TextAlign.center,
                style: TextStyle(color: theme.hintColor),
              ),
              const SizedBox(height: 30),
            ],
          ),
        ),
      ];
    }
    final n = nets.contains(_previewNet) ? _previewNet! : nets.first;
    final d = _net(n);
    final color = _hex(d['color']);
    final acc = _accountFor(n);
    final custom = _variants[n];
    final text = _textFor(n);
    final count = countFor(n, text);
    final limit = _limit(n);
    final over = count > limit;
    final when = _scheduled ? DateFormat('d MMM · HH:mm', 'fr').format(_at!) : tr('À l\'instant');
    return [
      // Sélecteur du réseau prévisualisé
      SizedBox(
        height: 44,
        child: ListView(
          scrollDirection: Axis.horizontal,
          children: [
            for (final x in nets)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(
                  avatar: _NetBadge(x, color: _hex(_net(x)['color']), size: 20),
                  label: Text('${_net(x)['label']}'),
                  selected: x == n,
                  showCheckmark: false,
                  side: BorderSide(color: countFor(x, _textFor(x)) > _limit(x) ? YColors.danger : theme.dividerColor.withValues(alpha: .4)),
                  onSelected: (_) => setState(() => _previewNet = x),
                ),
              ),
          ],
        ),
      ),
      const SizedBox(height: 10),
      _panel(
        context,
        padding: EdgeInsets.zero,
        border: over ? YColors.danger : null,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              height: 4,
              decoration: BoxDecoration(
                color: color,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 0),
              child: Row(
                children: [
                  Stack(
                    clipBehavior: Clip.none,
                    children: [
                      acc['avatar_url'] != null
                          ? CircleAvatar(radius: 21, backgroundImage: NetworkImage('${acc['avatar_url']}'), onBackgroundImageError: (_, _) {})
                          : CircleAvatar(
                              radius: 21,
                              backgroundColor: color.withValues(alpha: .15),
                              child: Text(
                                '${acc['name'] ?? '?'}'.characters.first.toUpperCase(),
                                style: TextStyle(color: color, fontWeight: FontWeight.w800),
                              ),
                            ),
                      Positioned(right: -3, bottom: -3, child: _NetBadge(n, color: color, size: 17)),
                    ],
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(verbatim('${acc['name'] ?? d['label']}'), style: const TextStyle(fontWeight: FontWeight.w800)),
                        Text(acc['username'] != null ? '@${acc['username']} · $when' : when, style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
                      ],
                    ),
                  ),
                  Icon(Icons.more_horiz, color: theme.hintColor),
                ],
              ),
            ),
            if (_title.text.isNotEmpty && ['youtube', 'pinterest'].contains(n))
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                child: Text(verbatim(_title.text), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
              child: custom != null
                  ? TextField(
                      controller: custom,
                      minLines: 3,
                      maxLines: 14,
                      onChanged: (_) => setState(() {}),
                      style: const TextStyle(height: 1.45),
                      decoration: InputDecoration(
                        isDense: true,
                        filled: true,
                        fillColor: color.withValues(alpha: .05),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12),
                          borderSide: BorderSide(color: color.withValues(alpha: .3)),
                        ),
                        enabledBorder: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12),
                          borderSide: BorderSide(color: color.withValues(alpha: .3)),
                        ),
                      ),
                    )
                  : _RichPreview(text: text.isEmpty ? tr('Votre texte apparaîtra ici…') : text, accent: color, muted: text.isEmpty),
            ),
            if (_media.isNotEmpty) _MediaGrid(media: _media),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
              child: Row(
                children: [
                  for (final i in const [Icons.favorite_border, Icons.mode_comment_outlined, Icons.repeat, Icons.send_outlined])
                    Padding(
                      padding: const EdgeInsets.only(right: 18),
                      child: Icon(i, size: 20, color: theme.hintColor),
                    ),
                  const Spacer(),
                  Text(
                    '$count / $limit',
                    style: TextStyle(color: over ? YColors.danger : theme.hintColor, fontWeight: FontWeight.w700, fontSize: 12.5),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          if (custom == null)
            OutlinedButton.icon(
              onPressed: () => setState(() => _variants[n] = TextEditingController(text: _body.text)),
              icon: const Icon(Icons.edit_note, size: 18),
              label: Text(tr('Texte propre à ${d['label']}')),
            )
          else
            OutlinedButton.icon(onPressed: () => setState(() => _variants.remove(n)?.dispose()), icon: const Icon(Icons.undo, size: 18), label: Text(tr('Reprendre le texte commun'))),
          if (over)
            FilledButton.tonalIcon(
              onPressed: () async {
                try {
                  final r = await context.read<Session>().api.post('social/assist/fit', {
                    'text': text,
                    'network': n,
                    'with_media': _media.isNotEmpty,
                    'link': _link.text.trim().isEmpty ? null : _link.text.trim(),
                  });
                  if (mounted) setState(() => (_variants[n] ??= TextEditingController()).text = '${r['text']}');
                } catch (e) {
                  if (context.mounted) toast(context, errorText(e), error: true);
                }
              },
              icon: const Icon(Icons.content_cut, size: 18),
              label: Text(tr('Raccourcir à $limit')),
            ),
        ],
      ),
    ];
  }

  Widget _bottomBar(BuildContext context) {
    final theme = Theme.of(context);
    final canPublish = widget.can['publish'] == true;
    final label = !canPublish ? 'Soumettre à validation' : (_scheduled ? 'Planifier' : 'Publier maintenant');
    return Container(
      decoration: BoxDecoration(
        color: theme.cardColor,
        border: Border(top: BorderSide(color: theme.dividerColor.withValues(alpha: .4))),
      ),
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
          child: Center(
            heightFactor: 1,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 1312),
              child: Row(
                children: [
                  Expanded(
                    child: InkWell(
                      borderRadius: BorderRadius.circular(14),
                      onTap: _busy ? null : _pickDate,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: theme.dividerColor.withValues(alpha: .6)),
                        ),
                        child: Row(
                          children: [
                            Icon(_scheduled ? Icons.event_available : Icons.bolt, color: _scheduled ? YColors.blue : YColors.warning),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text(tr(_scheduled ? 'Planifiée' : 'Dès maintenant'), style: TextStyle(color: theme.hintColor, fontSize: 11.5)),
                                  Text(
                                    _scheduled ? DateFormat('EEE d MMM · HH:mm', 'fr').format(_at!) : tr('Choisir une date'),
                                    style: const TextStyle(fontWeight: FontWeight.w700),
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ],
                              ),
                            ),
                            if (_scheduled)
                              IconButton(
                                visualDensity: VisualDensity.compact,
                                tooltip: tr('Publier tout de suite'),
                                onPressed: () => setState(() => _at = null),
                                icon: const Icon(Icons.close, size: 18),
                              ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Tooltip(
                    message: tr('Meilleur moment pour ces réseaux'),
                    child: IconButton.filledTonal(onPressed: _busy || _selected.isEmpty ? null : _bestTime, icon: const Icon(Icons.auto_graph)),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: SizedBox(
                      height: 50,
                      child: FilledButton.icon(
                        style: FilledButton.styleFrom(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14))),
                        onPressed: _busy || _uploading ? null : () => _save(_scheduled ? 'schedule' : 'now'),
                        icon: _busy
                            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                            : Icon(!canPublish ? Icons.how_to_reg_outlined : (_scheduled ? Icons.schedule_send : Icons.send)),
                        label: Text(
                          tr(label),
                          style: const TextStyle(fontWeight: FontWeight.w800),
                          overflow: TextOverflow.ellipsis,
                        ),
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

/// Avatar d'un compte avec l'anneau du réseau quand il est choisi.
class _AccountAvatar extends StatelessWidget {
  const _AccountAvatar({required this.account, required this.color, required this.selected, this.onTap});
  final Map<String, dynamic> account;
  final Color color;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final a = account;
    final expired = a['status'] == 'expired' || a['status'] == 'error';
    return Tooltip(
      message: '${a['network_label']} · ${a['name']}${expired ? ' — ${tr('à reconnecter')}' : ''}',
      child: InkWell(
        borderRadius: BorderRadius.circular(40),
        onTap: onTap,
        child: SizedBox(
          width: 66,
          child: Column(
            children: [
              AnimatedContainer(
                duration: const Duration(milliseconds: 180),
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: selected ? color : Colors.transparent, width: 2.5),
                ),
                child: Stack(
                  clipBehavior: Clip.none,
                  children: [
                    Opacity(
                      opacity: selected ? 1 : .38,
                      child: a['avatar_url'] != null
                          ? CircleAvatar(radius: 22, backgroundImage: NetworkImage('${a['avatar_url']}'), onBackgroundImageError: (_, _) {})
                          : CircleAvatar(
                              radius: 22,
                              backgroundColor: color.withValues(alpha: .14),
                              child: Text(
                                '${a['name']}'.replaceAll('@', '').characters.first.toUpperCase(),
                                style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 17),
                              ),
                            ),
                    ),
                    Positioned(
                      right: -4,
                      bottom: -4,
                      child: Opacity(
                        opacity: selected ? 1 : .6,
                        child: _NetBadge('${a['network']}', color: color, size: 19),
                      ),
                    ),
                    if (selected)
                      Positioned(
                        right: -4,
                        top: -4,
                        child: CircleAvatar(
                          radius: 8,
                          backgroundColor: color,
                          child: const Icon(Icons.check, size: 11, color: Colors.white),
                        ),
                      ),
                    if (expired) const Positioned(left: -4, top: -4, child: Icon(Icons.warning_amber_rounded, size: 16, color: YColors.danger)),
                  ],
                ),
              ),
              const SizedBox(height: 4),
              Text(
                verbatim('${a['name']}'),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 11.5, fontWeight: selected ? FontWeight.w700 : FontWeight.w500, color: selected ? null : Theme.of(context).hintColor),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ToolIcon extends StatelessWidget {
  const _ToolIcon({required this.icon, required this.tooltip, this.onTap, this.busy = false});
  final IconData icon;
  final String tooltip;
  final VoidCallback? onTap;
  final bool busy;

  @override
  Widget build(BuildContext context) => IconButton(
    tooltip: tr(tooltip),
    onPressed: onTap,
    color: YColors.blue,
    icon: busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Icon(icon),
  );
}

/// Bouton d'adaptation : dégradé violet-bleu propre à l'assistant.
class _AiButton extends StatelessWidget {
  const _AiButton({required this.busy, required this.ai, this.onTap});
  final bool busy;
  final bool ai;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final on = onTap != null;
    return Opacity(
      opacity: on || busy ? 1 : .5,
      child: Material(
        color: Colors.transparent,
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            gradient: const LinearGradient(colors: [Color(0xFF6C3CE1), Color(0xFF1F6FEB)]),
          ),
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.auto_awesome, color: Colors.white, size: 20),
                  const SizedBox(width: 10),
                  Text(
                    tr(ai ? 'Adapter pour chaque réseau avec l\'IA' : 'Adapter pour chaque réseau'),
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800),
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

/// Texte de l'aperçu : hashtags, mentions et liens colorés comme sur les réseaux.
class _RichPreview extends StatelessWidget {
  const _RichPreview({required this.text, required this.accent, this.muted = false});
  final String text;
  final Color accent;
  final bool muted;

  static final _token = RegExp(r'(https?://\S+|#[\p{L}\p{N}_]+|@[\w.]+)', unicode: true);

  @override
  Widget build(BuildContext context) {
    final base = TextStyle(height: 1.45, fontSize: 14.5, color: muted ? Theme.of(context).hintColor : Theme.of(context).textTheme.bodyMedium?.color);
    final spans = <TextSpan>[];
    var i = 0;
    for (final m in _token.allMatches(text)) {
      if (m.start > i) spans.add(TextSpan(text: text.substring(i, m.start)));
      spans.add(
        TextSpan(
          text: m.group(0),
          style: TextStyle(color: accent == Colors.black || accent.computeLuminance() < .02 ? YColors.blue : accent, fontWeight: FontWeight.w600),
        ),
      );
      i = m.end;
    }
    if (i < text.length) spans.add(TextSpan(text: text.substring(i)));
    return Text.rich(TextSpan(style: base, children: spans));
  }
}

/// Grille de médias : 1 image pleine largeur, 2 côte à côte, 3 et plus en mosaïque.
class _MediaGrid extends StatelessWidget {
  const _MediaGrid({required this.media});
  final List<Map<String, dynamic>> media;

  Widget _tile(Map<String, dynamic> m, {int more = 0}) => Stack(
    fit: StackFit.expand,
    children: [
      m['kind'] == 'image'
          ? Image.network(
              '${m['url']}',
              fit: BoxFit.cover,
              errorBuilder: (_, _, _) => Container(color: YColors.line),
            )
          : Container(
              color: YColors.night,
              child: const Icon(Icons.play_circle_fill, color: Colors.white, size: 46),
            ),
      if (more > 0)
        Container(
          color: Colors.black54,
          alignment: Alignment.center,
          child: Text(
            '+$more',
            style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800),
          ),
        ),
    ],
  );

  @override
  Widget build(BuildContext context) {
    final n = media.length;
    return AspectRatio(
      aspectRatio: n == 1 ? 1.2 : 1.5,
      child: n == 1
          ? _tile(media[0])
          : n == 2
          ? Row(
              children: [
                Expanded(child: _tile(media[0])),
                const SizedBox(width: 2),
                Expanded(child: _tile(media[1])),
              ],
            )
          : Row(
              children: [
                Expanded(flex: 2, child: _tile(media[0])),
                const SizedBox(width: 2),
                Expanded(
                  child: Column(
                    children: [
                      Expanded(child: _tile(media[1])),
                      const SizedBox(height: 2),
                      Expanded(child: _tile(media[2], more: n - 3)),
                    ],
                  ),
                ),
              ],
            ),
    );
  }
}

class _Counters extends StatelessWidget {
  const _Counters({required this.networks, required this.label, required this.color, required this.count, required this.limit, required this.custom, this.onTap});
  final List<String> networks;
  final String Function(String) label;
  final Color Function(String) color;
  final int Function(String) count;
  final int Function(String) limit;
  final bool Function(String) custom;
  final void Function(String)? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return LayoutBuilder(
      builder: (context, c) {
        final cols = c.maxWidth > 620 ? 3 : 2;
        final w = (c.maxWidth - (cols - 1) * 10) / cols;
        return Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            for (final n in networks)
              Builder(
                builder: (context) {
                  final v = count(n), l = limit(n);
                  final over = v > l;
                  final col = over ? YColors.danger : color(n);
                  return SizedBox(
                    width: w,
                    child: InkWell(
                      borderRadius: BorderRadius.circular(14),
                      onTap: onTap == null ? null : () => onTap!(n),
                      child: Container(
                        padding: const EdgeInsets.fromLTRB(10, 9, 10, 10),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(14),
                          color: over ? YColors.danger.withValues(alpha: .07) : theme.dividerColor.withValues(alpha: .12),
                          border: Border.all(color: over ? YColors.danger.withValues(alpha: .5) : Colors.transparent),
                        ),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                _NetBadge(n, color: color(n), size: 20),
                                const SizedBox(width: 7),
                                Expanded(
                                  child: Text(
                                    label(n),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5),
                                  ),
                                ),
                                if (custom(n)) Icon(Icons.edit, size: 13, color: theme.hintColor),
                              ],
                            ),
                            const SizedBox(height: 8),
                            ClipRRect(
                              borderRadius: BorderRadius.circular(4),
                              child: LinearProgressIndicator(value: (v / l).clamp(0.0, 1.0), minHeight: 5, color: col, backgroundColor: theme.dividerColor.withValues(alpha: .35)),
                            ),
                            const SizedBox(height: 5),
                            Text(
                              over ? tr('${v - l} de trop') : tr('${l - v} restants'),
                              style: TextStyle(fontSize: 11.5, color: over ? YColors.danger : theme.hintColor, fontWeight: over ? FontWeight.w800 : FontWeight.w500),
                            ),
                          ],
                        ),
                      ),
                    ),
                  );
                },
              ),
          ],
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Comptes connectés et connexion des réseaux
// ---------------------------------------------------------------------------

class _AccountsTab extends StatelessWidget {
  const _AccountsTab({required this.networks, required this.accounts, required this.can, required this.onChanged});
  final List<Map<String, dynamic>> networks;
  final List<Map<String, dynamic>> accounts;
  final Map<String, dynamic> can;
  final VoidCallback onChanged;

  Future<void> _connect(BuildContext context, Map<String, dynamic> n) async {
    final api = context.read<Session>().api;
    if (n['connect'] == 'oauth') {
      try {
        final r = await api.post('social/connect/${n['provider']}');
        await safeLaunchUrl(Uri.parse('${r['url']}'), mode: LaunchMode.externalApplication);
        if (!context.mounted) return;
        await showDialog<void>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: Text(tr('Connexion ${n['label']}')),
            content: Text(tr('Autorisez Yomara dans la page qui vient de s\'ouvrir, puis revenez ici et touchez « Terminé ».')),
            actions: [FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Terminé')))],
          ),
        );
        onChanged();
      } catch (e) {
        if (context.mounted) toast(context, errorText(e), error: true);
      }
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => _TokenDialog(network: n),
    );
    if (ok == true) onChanged();
  }

  @override
  Widget build(BuildContext context) {
    final manage = can['accounts'] == true;
    final theme = Theme.of(context);
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 90),
      children: [
        if (can['apps'] == true)
          Card(
            color: YColors.night,
            child: ListTile(
              leading: const Icon(Icons.admin_panel_settings_outlined, color: Colors.white),
              title: Text(
                tr('Applications des réseaux (super admin)'),
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
              ),
              subtitle: Text(tr('Clés Meta, LinkedIn, X, TikTok, Pinterest, Google et Threads utilisées par tous les clients pour se connecter.'), style: const TextStyle(color: Colors.white70)),
              trailing: const Icon(Icons.chevron_right, color: Colors.white),
              onTap: () async {
                await showDialog<void>(context: context, builder: (_) => const SocialAppsDialog());
                onChanged();
              },
            ),
          ),
        if (accounts.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(tr('Comptes connectés'), style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          for (final a in accounts)
            _AccountRow(
              a: a,
              net: networks.firstWhere((n) => n['key'] == a['network'], orElse: () => const {}),
              manage: manage,
              onChanged: onChanged,
            ),
        ],
        if (manage) ...[
          const SizedBox(height: 18),
          Text(tr('Ajouter un compte'), style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          LayoutBuilder(
            builder: (context, c) {
              final cols = c.maxWidth > 900 ? 4 : (c.maxWidth > 560 ? 3 : 2);
              return GridView.count(
                crossAxisCount: cols,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                mainAxisSpacing: 8,
                crossAxisSpacing: 8,
                childAspectRatio: cols == 2 ? 2.4 : 3,
                children: [
                  for (final n in networks)
                    Opacity(
                      opacity: n['available'] == true ? 1 : .55,
                      child: Card(
                        margin: EdgeInsets.zero,
                        child: InkWell(
                          borderRadius: BorderRadius.circular(12),
                          onTap: n['available'] == true
                              ? () => _connect(context, n)
                              : () => toast(context, tr(can['apps'] == true ? '${n['label']} n\'est pas encore configuré : renseignez ses clés dans « Applications des réseaux (super admin) », en haut de cette page.' : '${n['label']} n\'est pas encore activé : le super administrateur de la plateforme doit renseigner ses clés dans Réseaux sociaux › Comptes › « Applications des réseaux ».'), error: true),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 10),
                            child: Row(
                              children: [
                                _NetBadge('${n['key']}', color: _hex(n['color']), size: 30),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: Column(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        '${n['label']}',
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(fontWeight: FontWeight.w700),
                                      ),
                                      Text(tr('${n['limit']} caractères'), style: TextStyle(color: theme.hintColor, fontSize: 11.5)),
                                    ],
                                  ),
                                ),
                                Icon(n['available'] == true ? Icons.add_circle_outline : Icons.lock_outline, size: 20, color: theme.hintColor),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              );
            },
          ),
        ] else if (accounts.isEmpty)
          EmptyState(icon: Icons.link_off, title: tr('Aucun compte connecté'), message: tr('Demandez à un responsable de connecter les comptes de l\'entreprise.')),
      ],
    );
  }
}

class _AccountRow extends StatelessWidget {
  const _AccountRow({required this.a, required this.net, required this.manage, required this.onChanged});
  final Map<String, dynamic> a;
  final Map<String, dynamic> net;
  final bool manage;
  final VoidCallback onChanged;

  Future<void> _update(BuildContext context, Map<String, dynamic> body) async {
    try {
      await context.read<Session>().api.put('social/accounts/${a['id']}', body);
      onChanged();
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final st = '${a['status']}';
    final boards = (a['boards'] as List?) ?? const [];
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Column(
          children: [
            ListTile(
              leading: Stack(
                clipBehavior: Clip.none,
                children: [
                  a['avatar_url'] != null
                      ? CircleAvatar(radius: 20, backgroundImage: NetworkImage('${a['avatar_url']}'), onBackgroundImageError: (_, _) {})
                      : CircleAvatar(radius: 20, backgroundColor: _hex(net['color']).withValues(alpha: .15), child: Text('${a['name']}'.characters.first.toUpperCase())),
                  Positioned(right: -4, bottom: -4, child: _NetBadge('${a['network']}', color: _hex(net['color']), size: 18)),
                ],
              ),
              title: Text(verbatim('${a['name']}')),
              subtitle: Text(
                st == 'active'
                    ? '${a['network_label']}${a['username'] != null ? ' · @${a['username']}' : ''}'
                    : st == 'disabled'
                    ? tr('Désactivé')
                    : '${a['last_error'] ?? tr('À reconnecter')}',
                style: TextStyle(color: st == 'active' || st == 'disabled' ? null : YColors.danger),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
              trailing: manage
                  ? PopupMenuButton<String>(
                      onSelected: (v) async {
                        if (v == 'toggle') {
                          await _update(context, {'enabled': st == 'disabled'});
                        } else if (v == 'remove') {
                          if (!await confirm(context, tr('Déconnecter'), tr('Déconnecter ${a['name']} ? Les publications prévues ne partiront plus sur ce compte.'), danger: true)) return;
                          if (!context.mounted) return;
                          try {
                            await context.read<Session>().api.delete('social/accounts/${a['id']}');
                            onChanged();
                          } catch (e) {
                            if (context.mounted) toast(context, errorText(e), error: true);
                          }
                        }
                      },
                      itemBuilder: (_) => [
                        PopupMenuItem(value: 'toggle', child: Text(tr(st == 'disabled' ? 'Réactiver' : 'Désactiver'))),
                        PopupMenuItem(value: 'remove', child: Text(tr('Déconnecter'))),
                      ],
                    )
                  : null,
            ),
            if (manage && boards.isNotEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(72, 0, 16, 8),
                child: DropdownButtonFormField<String>(
                  initialValue: a['board_id'] == null ? null : '${a['board_id']}',
                  isDense: true,
                  decoration: InputDecoration(labelText: tr('Tableau Pinterest par défaut'), isDense: true),
                  items: [for (final b in boards) DropdownMenuItem(value: '${(b as Map)['id']}', child: Text(verbatim('${b['name']}')))],
                  onChanged: (v) => v == null ? null : _update(context, {'board_id': v}),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Réseaux connectés par jeton : Telegram (bot), Mastodon (instance + jeton), Bluesky (mot de passe d'application), Sensya (jeton d'accès).
class _TokenDialog extends StatefulWidget {
  const _TokenDialog({required this.network});
  final Map<String, dynamic> network;

  @override
  State<_TokenDialog> createState() => _TokenDialogState();
}

class _TokenDialogState extends State<_TokenDialog> {
  final _a = TextEditingController();
  final _b = TextEditingController();
  late final _base = TextEditingController(text: 'https://sensya.yomaratechnologies.com');
  bool _busy = false;

  String get _key => '${widget.network['key']}';

  (String, String, String, String) get _spec => switch (_key) {
    'telegram' => (
      'Jeton du bot',
      'Canal ou groupe (@moncanal)',
      '1. Dans Telegram, écrivez à @BotFather : /newbot, puis copiez le jeton.\n2. Ajoutez ce bot comme administrateur de votre canal avec le droit de publier.',
      'token',
    ),
    'mastodon' => (
      'Adresse de l\'instance (ex. mastodon.social)',
      'Jeton d\'accès',
      'Sur votre instance : Préférences › Développement › Nouvelle application, droits write:statuses, write:media et read:statuses, puis copiez « Votre jeton d\'accès ».',
      'instance',
    ),
    'bluesky' => (
      'Identifiant (ex. maboutique.bsky.social)',
      'Mot de passe d\'application',
      'Bluesky › Paramètres › Confidentialité et sécurité › Mots de passe d\'application › Ajouter. N\'utilisez jamais votre mot de passe principal.',
      'handle',
    ),
    _ => ('Jeton d\'accès Sensya', '', 'Dans Sensya : Paramètres › Applications connectées › « Yomara ERP » › Créer un jeton, puis collez-le ici.', 'sensya'),
  };

  Future<void> _go() async {
    setState(() => _busy = true);
    final body = switch (_key) {
      'telegram' => {'token': _a.text.trim(), 'chat': _b.text.trim()},
      'mastodon' => {'instance': _a.text.trim(), 'token': _b.text.trim()},
      'bluesky' => {'handle': _a.text.trim(), 'password': _b.text.trim()},
      _ => {'token': _a.text.trim(), 'base_url': _base.text.trim()},
    };
    try {
      final r = await context.read<Session>().api.post('social/connect-token/$_key', body);
      if (!mounted) return;
      toast(context, tr('${(r['connected'] as List).length} compte(s) connecté(s).'));
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _spec;
    return AlertDialog(
      title: Row(
        children: [
          _NetBadge(_key, color: _hex(widget.network['color'])),
          const SizedBox(width: 10),
          Text(tr('Connecter ${widget.network['label']}')),
        ],
      ),
      content: SizedBox(
        width: 460,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(tr(s.$3), style: TextStyle(color: Theme.of(context).hintColor, height: 1.4)),
            const SizedBox(height: 12),
            TextField(
              controller: _a,
              autofocus: true,
              obscureText: _key == 'sensya',
              decoration: InputDecoration(labelText: tr(s.$1)),
            ),
            if (s.$2.isNotEmpty) ...[
              const SizedBox(height: 8),
              TextField(
                controller: _b,
                obscureText: _key != 'telegram',
                decoration: InputDecoration(labelText: tr(s.$2)),
              ),
            ],
            if (_key == 'sensya') ...[
              const SizedBox(height: 8),
              TextField(
                controller: _base,
                decoration: InputDecoration(labelText: tr('Adresse de Sensya'), isDense: true),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
        FilledButton(onPressed: _busy ? null : _go, child: Text(tr(_busy ? 'Vérification…' : 'Connecter'))),
      ],
    );
  }
}

/// Super admin : applications OAuth de la plateforme (une par réseau), avec l'adresse de retour à déclarer.
class SocialAppsDialog extends StatefulWidget {
  const SocialAppsDialog({super.key});

  @override
  State<SocialAppsDialog> createState() => _SocialAppsDialogState();
}

class _SocialAppsDialogState extends State<SocialAppsDialog> {
  List<Map<String, dynamic>>? _apps;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('admin/social-apps');
      if (mounted) setState(() => _apps = [for (final p in (r['providers'] as List)) Map<String, dynamic>.from(p as Map)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _edit(Map<String, dynamic> p) async {
    final id = TextEditingController(text: '${p['client_id'] ?? ''}');
    final secret = TextEditingController();
    var orgs = p['organizations'] == true;
    var ext = p['extended'] == true;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => AlertDialog(
          title: Text('${p['label']}'),
          content: SizedBox(
            width: 520,
            child: SingleChildScrollView(child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr('Adresse de retour (redirect URI) à déclarer dans l\'application :'), style: const TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 4),
                Row(
                  children: [
                    Expanded(
                      child: SelectableText('${p['redirect_uri']}', style: const TextStyle(fontFamily: 'monospace', fontSize: 12.5)),
                    ),
                    IconButton(
                      tooltip: tr('Copier'),
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: '${p['redirect_uri']}'));
                        toast(ctx, tr('Adresse copiée.'));
                      },
                      icon: const Icon(Icons.copy, size: 18),
                    ),
                  ],
                ),
                TextButton.icon(
                  onPressed: () => safeLaunchUrl(Uri.parse('${p['console']}'), mode: LaunchMode.externalApplication),
                  icon: const Icon(Icons.open_in_new, size: 16),
                  label: Text(tr('Ouvrir la console développeur')),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: id,
                  decoration: InputDecoration(labelText: tr(p['key'] == 'tiktok' ? 'Client key' : 'Client ID / App ID')),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: secret,
                  obscureText: true,
                  decoration: InputDecoration(labelText: tr('Client secret'), helperText: p['has_secret'] == true ? tr('Laisser vide pour garder le secret enregistré') : null),
                ),
                if (p['key'] == 'meta') ...[
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    value: ext,
                    onChanged: (v) => set(() => ext = v),
                    title: Text(tr('Messages, commentaires et prospects des publicités')),
                    subtitle: Text(tr('Seulement après validation par Meta (App Review) des autorisations : pages_messaging, pages_manage_metadata, pages_read_user_content, pages_manage_engagement, leads_retrieval, ads_read, instagram_manage_comments, instagram_manage_messages. Les sociétés reconnectent ensuite leur page.')),
                  ),
                  if (p['webhook_url'] != null) ...[
                    Text(tr('Webhook à déclarer dans l\'application Meta (produits Webhooks : Page → feed, messages, leadgen ; Instagram → comments, messages) :'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    const SizedBox(height: 4),
                    for (final kv in [('URL de rappel', '${p['webhook_url']}'), ('Jeton de vérification', '${p['verify_token']}')])
                      Row(children: [
                        Expanded(child: SelectableText('${tr(kv.$1)} : ${kv.$2}', style: const TextStyle(fontFamily: 'monospace', fontSize: 12.5))),
                        IconButton(tooltip: tr('Copier'), onPressed: () {
                          Clipboard.setData(ClipboardData(text: kv.$2));
                          toast(ctx, tr('Copié.'));
                        }, icon: const Icon(Icons.copy, size: 18)),
                      ]),
                  ],
                ],
                if (p['key'] == 'linkedin')
                  SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    value: orgs,
                    onChanged: (v) => set(() => orgs = v),
                    title: Text(tr('Pages entreprise LinkedIn')),
                    subtitle: Text(tr('Seulement si l\'application a le produit « Community Management API ».')),
                  ),
              ],
            )),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(tr('Enregistrer'))),
          ],
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      final r = await context.read<Session>().api.put('admin/social-apps/${p['key']}', {
        'client_id': id.text.trim(),
        if (secret.text.trim().isNotEmpty) 'client_secret': secret.text.trim(),
        if (p['key'] == 'linkedin') 'organizations': orgs,
        if (p['key'] == 'meta') 'extended': ext,
      });
      if (mounted) setState(() => _apps = [for (final x in (r['providers'] as List)) Map<String, dynamic>.from(x as Map)]);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(tr('Applications des réseaux sociaux')),
      content: SizedBox(
        width: 560,
        child: _apps == null
            ? const SizedBox(height: 120, child: Center(child: CircularProgressIndicator()))
            : ListView(
                shrinkWrap: true,
                children: [
                  Text(
                    tr(
                      'Créez une application chez chaque réseau (une seule fois pour toute la plateforme), déclarez l\'adresse de retour, puis collez ses clés ici. Vos clients n\'ont plus qu\'à cliquer sur « Connecter ».',
                    ),
                    style: TextStyle(color: Theme.of(context).hintColor),
                  ),
                  const SizedBox(height: 8),
                  for (final p in _apps!)
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: Icon(
                        p['client_id'] != null && p['has_secret'] == true ? Icons.check_circle : Icons.radio_button_unchecked,
                        color: p['client_id'] != null && p['has_secret'] == true ? YColors.success : Theme.of(context).hintColor,
                      ),
                      title: Text('${p['label']}'),
                      subtitle: Text(p['client_id'] != null ? tr('Configurée') : tr('À configurer')),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => _edit(p),
                    ),
                ],
              ),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

// ---------------------------------------------------------------------------
// Statistiques
// ---------------------------------------------------------------------------

class _StatsTab extends StatefulWidget {
  const _StatsTab({super.key, required this.networks, required this.canSync});
  final List<Map<String, dynamic>> networks;
  final bool canSync;

  @override
  State<_StatsTab> createState() => _StatsTabState();
}

class _StatsTabState extends State<_StatsTab> {
  Map<String, dynamic>? _d;
  int _days = 30;
  bool _syncing = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('social/analytics', query: {'days': '$_days'});
      if (mounted) setState(() => _d = Map<String, dynamic>.from(r as Map));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _sync() async {
    setState(() => _syncing = true);
    try {
      final r = await context.read<Session>().api.post('social/stats/sync');
      if (mounted) toast(context, tr('${r['synced']} publication(s) mises à jour.'));
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    if (d == null) return const Center(child: CircularProgressIndicator());
    final t = Map<String, dynamic>.from(d['totals'] as Map);
    final nets = [for (final n in (d['networks'] as List)) Map<String, dynamic>.from(n as Map)];
    final maxEng = nets.fold<num>(1, (m, n) => (n['engagement'] as num) > m ? n['engagement'] as num : m);
    final theme = Theme.of(context);
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 90),
        children: [
          Row(
            children: [
              SegmentedButton<int>(
                segments: const [
                  ButtonSegment(value: 7, label: Text('7 j')),
                  ButtonSegment(value: 30, label: Text('30 j')),
                  ButtonSegment(value: 90, label: Text('90 j')),
                ],
                selected: {_days},
                onSelectionChanged: (s) {
                  setState(() => _days = s.first);
                  _load();
                },
              ),
              const Spacer(),
              if (widget.canSync)
                TextButton.icon(
                  onPressed: _syncing ? null : _sync,
                  icon: _syncing ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.sync),
                  label: Text(tr('Relever')),
                ),
            ],
          ),
          const SizedBox(height: 12),
          StatStrip(
            tiles: [
              StatTile(label: tr('Publications'), value: '${t['published']}', detail: tr('${t['scheduled']} planifiée(s) · ${t['pending']} à valider'), icon: Icons.send_outlined),
              StatTile(label: tr('J\'aime'), value: number(t['likes'] as num), icon: Icons.favorite_border, color: const Color(0xFFE1306C)),
              StatTile(
                label: tr('Commentaires'),
                value: number(t['comments'] as num),
                detail: tr('${number(t['shares'] as num)} partage(s)'),
                icon: Icons.mode_comment_outlined,
                color: YColors.success,
              ),
              StatTile(
                label: tr('Vues'),
                value: number(t['views'] as num),
                detail: (t['failed'] as num) > 0 ? tr('${t['failed']} envoi(s) en échec') : null,
                icon: Icons.visibility_outlined,
                color: const Color(0xFF9333EA),
              ),
            ],
          ),
          const SizedBox(height: 16),
          SectionCard(
            title: tr('Engagement par réseau'),
            child: nets.isEmpty
                ? Text(tr('Les chiffres apparaîtront après vos premières publications.'), style: TextStyle(color: theme.hintColor))
                : Column(
                    children: [
                      for (final n in nets)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          child: Row(
                            children: [
                              _NetBadge('${n['network']}', color: _hex(widget.networks.firstWhere((x) => x['key'] == n['network'], orElse: () => const {})['color']), size: 24),
                              const SizedBox(width: 10),
                              SizedBox(width: 110, child: Text('${n['label']}', overflow: TextOverflow.ellipsis)),
                              Expanded(
                                child: ClipRRect(
                                  borderRadius: BorderRadius.circular(6),
                                  child: LinearProgressIndicator(
                                    value: (n['engagement'] as num) / maxEng,
                                    minHeight: 10,
                                    color: _hex(widget.networks.firstWhere((x) => x['key'] == n['network'], orElse: () => const {})['color']),
                                    backgroundColor: theme.dividerColor.withValues(alpha: .3),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 10),
                              SizedBox(
                                width: 92,
                                child: Text(tr('${n['posts']} post(s) · ${n['engagement']}'), textAlign: TextAlign.right, style: const TextStyle(fontSize: 12)),
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
          ),
          const SizedBox(height: 16),
          Text(tr('Meilleures publications'), style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          for (final p in (d['top'] as List))
            _PostTile(
              p: Map<String, dynamic>.from(p as Map),
              networks: widget.networks,
              onTap: () => showAdaptiveSheet<void>(
                context: context,
                isScrollControlled: true,
                showDragHandle: true,
                builder: (_) => _PostSheet(postId: p['id'] as int, networks: widget.networks, can: const {}, onEdit: (_) {}),
              ),
            ),
        ],
      ),
    );
  }
}
