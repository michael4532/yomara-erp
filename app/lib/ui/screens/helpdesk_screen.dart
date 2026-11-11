import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
/// Service client : tickets avec délais garantis, réponses, notes internes, temps passé, centre d'aide.

const hdStatuses = {'new': 'Nouveau', 'open': 'En cours', 'pending': 'En attente du client', 'resolved': 'Résolu', 'closed': 'Fermé'};
const hdPriorities = {'urgent': 'Urgente', 'high': 'Haute', 'normal': 'Normale', 'low': 'Basse'};
const hdChannels = {'agent': 'Saisi par l\'équipe', 'phone': 'Téléphone', 'email': 'E-mail', 'whatsapp': 'WhatsApp', 'web': 'Formulaire en ligne', 'portal': 'Espace client'};
const hdChannelIcons = {'agent': Icons.edit_note, 'phone': Icons.call, 'email': Icons.mail_outline, 'whatsapp': Icons.chat, 'web': Icons.language, 'portal': Icons.account_circle_outlined};

bool hdAgent(Session s) => s.can('helpdesk.agent') || s.can('helpdesk.manage');
bool hdManage(Session s) => s.can('helpdesk.manage');

Color hdPriorityColor(String p) => switch (p) { 'urgent' => YColors.danger, 'high' => YColors.warning, 'low' => YColors.muted, _ => YColors.blue };

Color hdStatusColor(String s) => switch (s) { 'new' => YColors.blue, 'open' => YColors.blue, 'pending' => YColors.warning, 'resolved' => YColors.success, _ => YColors.muted };

String hdDuration(Duration d) {
  final m = d.inMinutes.abs();
  if (m < 60) return '$m min';
  if (m < 24 * 60) return '${m ~/ 60} h${m % 60 > 0 ? ' ${(m % 60).toString().padLeft(2, '0')}' : ''}';
  return '${(m / 1440).round()} j';
}

/// Délai du ticket à afficher : (libellé, couleur) ou null.
(String, Color)? hdSla(Map<String, dynamic> t, {DateTime? now}) {
  final n = now ?? DateTime.now();
  final st = '${t['status']}';
  final breached = t['sla_first_breached'] == true || t['sla_resolve_breached'] == true;
  if (st == 'resolved' || st == 'closed') return breached ? ('Délai dépassé', YColors.danger) : null;
  if (st == 'pending') return ('Délai suspendu', YColors.muted);
  final first = DateTime.tryParse('${t['first_response_due'] ?? ''}')?.toLocal();
  final resolve = DateTime.tryParse('${t['resolution_due'] ?? ''}')?.toLocal();
  if (t['first_response_at'] == null && first != null) {
    final d = first.difference(n);
    return d.isNegative ? ('Réponse en retard de ${hdDuration(d)}', YColors.danger) : ('Répondre sous ${hdDuration(d)}', d.inMinutes < 60 ? YColors.warning : YColors.blue);
  }
  if (resolve != null) {
    final d = resolve.difference(n);
    return d.isNegative ? ('Résolution en retard de ${hdDuration(d)}', YColors.danger) : ('À résoudre sous ${hdDuration(d)}', d.inMinutes < 120 ? YColors.warning : YColors.muted);
  }
  return null;
}

/// Personnes qui traitent les tickets (gardées pour le hors ligne).
List<Map<String, dynamic>> hdAgents() => [for (final u in (LocalDb.state().get('hd_agents') as List? ?? const [])) asMap(u)];

String hdAgentName(dynamic id) => id == null ? 'Non attribué' : '${hdAgents().firstWhere((u) => '${u['id']}' == '$id', orElse: () => {'name': '#$id'})['name']}';

Future<void> hdLoadAgents(BuildContext context) async {
  try {
    final r = await context.read<Session>().api.get('helpdesk/agents');
    await LocalDb.state().put('hd_agents', r['data']);
  } catch (_) {}
}

String hdSize(dynamic b) {
  final n = toDouble(b);
  return n >= 1048576 ? '${(n / 1048576).toStringAsFixed(1).replaceAll('.', ',')} Mo' : '${(n / 1024).ceil()} Ko';
}

IconData hdFileIcon(String? mime, String name) {
  final m = mime ?? '', n = name.toLowerCase();
  if (m.startsWith('image/') || n.endsWith('.jpg') || n.endsWith('.png') || n.endsWith('.jpeg')) return Icons.image_outlined;
  if (m.contains('pdf') || n.endsWith('.pdf')) return Icons.picture_as_pdf_outlined;
  if (m.startsWith('audio/')) return Icons.mic_none;
  if (m.startsWith('video/')) return Icons.movie_outlined;
  return Icons.attach_file;
}

Future<void> hdDownload(BuildContext context, Map<String, dynamic> a) async {
  try {
    final bytes = await context.read<Session>().api.download('helpdesk/files/${a['id']}');
    final where = await saveFile('${a['name']}', bytes, mime: '${a['mime'] ?? 'application/octet-stream'}');
    if (context.mounted) toast(context, tr('Fichier enregistré${where.isEmpty ? '' : ' : $where'}'));
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
  }
}

Widget hdFileChip(BuildContext context, Map<String, dynamic> a) => Padding(
      padding: const EdgeInsets.only(top: 6),
      child: ActionChip(
        avatar: Icon(hdFileIcon(a['mime'] as String?, '${a['name']}'), size: 18),
        label: Text(tr('${a['name']} · ${hdSize(a['size'])}${a['public'] == false ? ' · interne' : ''}'), overflow: TextOverflow.ellipsis),
        onPressed: () => hdDownload(context, a),
      ),
    );

List<Map<String, dynamic>> _all(String e) => LocalDb.hasEntity(e) ? LocalDb.all(e) : <Map<String, dynamic>>[];

class HelpdeskScreen extends StatefulWidget {
  const HelpdeskScreen({super.key});

  @override
  State<HelpdeskScreen> createState() => _HelpdeskScreenState();
}

class _HelpdeskScreenState extends State<HelpdeskScreen> {
  Map<String, dynamic>? _stats;

  @override
  void initState() {
    super.initState();
    hdLoadAgents(context).then((_) => mounted ? setState(() {}) : null);
    _loadStats();
  }

  Future<void> _loadStats() async {
    try {
      final r = await context.read<Session>().api.get('helpdesk/stats');
      if (mounted) setState(() => _stats = asMap(r['data']));
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final narrow = MediaQuery.of(context).size.width < 700;
    final tabs = [Tab(text: tr('Tickets')), Tab(text: tr('Centre d\'aide')), if (hdManage(session)) Tab(text: tr('Réglages'))];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(
          title: tr('Service client'),
          subtitle: tr('Demandes des clients, délais garantis, réponses et base de connaissances'),
          actions: [if (hdAgent(session)) FilledButton.icon(onPressed: () => newTicket(context), icon: const Icon(Icons.add), label: Text(tr('Ticket')))],
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
          child: Align(alignment: Alignment.centerLeft, child: TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: tabs)),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: TabBarView(children: [
            _TicketsTab(stats: _stats, onRefresh: _loadStats),
            const _ArticlesTab(),
            if (hdManage(session)) const _SettingsTab(),
          ]),
        ),
      ]),
    );
  }
}

// ======================================================================
// Liste des tickets

class _TicketsTab extends StatefulWidget {
  const _TicketsTab({required this.stats, required this.onRefresh});

  final Map<String, dynamic>? stats;
  final Future<void> Function() onRefresh;

  @override
  State<_TicketsTab> createState() => _TicketsTabState();
}

class _TicketsTabState extends State<_TicketsTab> {
  String _filter = 'active';
  String _q = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final me = session.user['id'];
    final narrow = MediaQuery.of(context).size.width < 700;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('hd_tickets'),
      builder: (context, _, _) {
        final all = _all('hd_tickets');
        final active = all.where((t) => const {'new', 'open', 'pending'}.contains(t['status'])).toList();
        final overdue = active.where((t) => hdSla(t)?.$2 == YColors.danger).length;
        bool viewMatch(Map<String, dynamic> t, String f) => switch (f) {
              'mine' => t['assignee_id'] == me && const {'new', 'open', 'pending'}.contains(t['status']),
              'unassigned' => t['assignee_id'] == null && const {'new', 'open', 'pending'}.contains(t['status']),
              'late' => const {'new', 'open', 'pending'}.contains(t['status']) && hdSla(t)?.$2 == YColors.danger,
              'pending' => t['status'] == 'pending',
              'done' => t['status'] == 'resolved' || t['status'] == 'closed',
              'all' || '' => true,
              _ => const {'new', 'open', 'pending'}.contains(t['status']),
            };
        final base = all
            .where((t) => _q.isEmpty || '${t['number']} ${t['subject']} ${t['partner_name'] ?? ''} ${t['contact_name'] ?? ''} ${t['contact_email'] ?? ''}'.toLowerCase().contains(_q))
            .where((t) => _f['priority'] == null || '${t['priority']}' == _f['priority'])
            .where((t) => _f['channel'] == null || '${t['channel']}' == _f['channel'])
            .where((t) => _f['assignee'] == null || '${t['assignee_id'] ?? '-'}' == _f['assignee'])
            .where((t) => _f['partner'] == null || '${t['partner_name'] ?? t['contact_name'] ?? ''}' == _f['partner'])
            .where((t) => _f['category'] == null || '${t['category_id']}' == _f['category'])
            .where((t) => _period == null || _period!.contains(t['created_at']))
            .toList();
        final rows = base.where((t) => viewMatch(t, _filter)).toList()
          ..sort((a, b) {
            const p = {'urgent': 0, 'high': 1, 'normal': 2, 'low': 3};
            if (_filter == 'done') return '${b['resolved_at'] ?? b['updated_at']}'.compareTo('${a['resolved_at'] ?? a['updated_at']}');
            final c = (p[a['priority']] ?? 2).compareTo(p[b['priority']] ?? 2);
            return c != 0 ? c : '${a['created_at'] ?? '9'}'.compareTo('${b['created_at'] ?? '9'}');
          });
        Widget kpi(String label, String value, IconData icon, Color color) => KpiBox(
              width: 180,
              child: StatTile(label: label, value: value, icon: icon, color: color),
            );
        final s = widget.stats;
        const filters = {
          '': 'Tous',
          'active': 'Ouverts',
          'mine': 'Mes tickets',
          'unassigned': 'Non attribués',
          'late': 'En retard',
          'pending': 'En attente du client',
          'done': 'Résolus et fermés',
        };
        final cats = {for (final c in _all('hd_categories')) '${c['id']}': '${c['name']}'};
        final partners = <String, String>{
          for (final t in all)
            if ('${t['partner_name'] ?? t['contact_name'] ?? ''}'.isNotEmpty) '${t['partner_name'] ?? t['contact_name']}': '${t['partner_name'] ?? t['contact_name']}',
        };
        return RefreshIndicator(
          onRefresh: widget.onRefresh,
          child: ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24), children: [
            KpiWrap(children: [
              kpi('Tickets ouverts', '${active.length}', Icons.support_agent, YColors.blue),
              kpi('En retard', '$overdue', Icons.timer_off_outlined, overdue > 0 ? YColors.danger : YColors.success),
              kpi('Délais tenus (30 j)', s?['sla_compliance'] == null ? '—' : '${s!['sla_compliance']} %', Icons.verified_outlined, YColors.success),
              kpi('1re réponse (moy.)', s?['avg_first_response'] == null ? '—' : hdDuration(Duration(minutes: (s!['avg_first_response'] as num).toInt())), Icons.reply, YColors.muted),
              kpi('Satisfaction', s?['csat'] == null ? '—' : '${'${s!['csat']}'.replaceAll('.', ',')} / 5', Icons.star_outline, YColors.warning),
            ]),
            const SizedBox(height: 12),
            FilterBar(
              key: ValueKey(_gen),
              padding: const EdgeInsets.only(bottom: 10),
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              search: _q,
              searchHint: 'N°, objet, client…',
              statuses: filters,
              status: _filter,
              onStatus: (v) => setState(() => _filter = v),
              statusCounts: {for (final k in filters.keys) k: base.where((t) => viewMatch(t, k)).length},
              filters: [
                FilterSpec('priority', 'Priorité', hdPriorities, icon: Icons.priority_high_rounded),
                FilterSpec('assignee', 'Attribué à', {for (final u in hdAgents()) '${u['id']}': '${u['name']}', '-': 'Non attribué'}, icon: Icons.support_agent),
                FilterSpec('partner', 'Client', Map.fromEntries(partners.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase()))), icon: Icons.business_outlined),
                FilterSpec('category', 'Catégorie', {for (final t in all) if (t['category_id'] != null && cats.containsKey('${t['category_id']}')) '${t['category_id']}': cats['${t['category_id']}']!}, icon: Icons.category_outlined),
                FilterSpec('channel', 'Canal', {for (final e in hdChannels.entries) if (all.any((t) => t['channel'] == e.key)) e.key: e.value}, icon: Icons.forum_outlined),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f[k] = v),
              showPeriod: true,
              period: _period,
              onPeriod: (p) => setState(() => _period = p),
              count: rows.length,
              countLabel: 'ticket',
              onReset: () => setState(() {
                _filter = 'active';
                _q = '';
                _f.clear();
                _period = null;
                _gen++;
              }),
            ),
            if (rows.isEmpty)
              EmptyState(icon: Icons.support_agent, title: tr('Aucun ticket'), message: tr('Les demandes des clients (formulaire en ligne, espace client, appels saisis) arrivent ici.'))
            else
              Card(
                clipBehavior: Clip.antiAlias,
                child: Column(children: [
                  for (final t in rows) ...[_TicketRow(t: t, narrow: narrow), if (t != rows.last) const Divider(height: 1)],
                ]),
              ),
          ]),
        );
      },
    );
  }
}

class _TicketRow extends StatelessWidget {
  const _TicketRow({required this.t, required this.narrow});

  final Map<String, dynamic> t;
  final bool narrow;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final sla = hdSla(t);
    final st = '${t['status']}';
    return InkWell(
      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => TicketScreen(id: '${t['id']}'))),
      child: IntrinsicHeight(
        child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Container(width: 5, color: hdPriorityColor('${t['priority']}')),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
              child: Row(children: [
                Icon(hdChannelIcons[t['channel']] ?? Icons.confirmation_number_outlined, size: 20, color: hint),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr('${t['subject']}'), style: TextStyle(fontWeight: st == 'new' ? FontWeight.w800 : FontWeight.w600), maxLines: 1, overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 2),
                    Text(tr([
                      '${t['number'] ?? '…'}',
                      '${t['partner_name'] ?? t['contact_name'] ?? ''}',
                      hdAgentName(t['assignee_id']),
                      if (t['created_at'] != null) dateTimeFr(t['created_at']),
                    ].where((x) => x.isNotEmpty).join(' · ')), style: TextStyle(fontSize: 12.5, color: hint), maxLines: 1, overflow: TextOverflow.ellipsis),
                  ]),
                ),
                if (!narrow && sla != null) ...[const SizedBox(width: 8), StatusChip(sla.$1, color: sla.$2)],
                const SizedBox(width: 8),
                StatusChip(hdStatuses[st] ?? st, color: hdStatusColor(st)),
              ]),
            ),
          ),
        ]),
      ),
    );
  }
}

// ======================================================================
// Ticket : conversation, réponse, propriétés, temps passé

class TicketScreen extends StatefulWidget {
  const TicketScreen({super.key, required this.id});

  final String id;

  @override
  State<TicketScreen> createState() => _TicketScreenState();
}

class _TicketScreenState extends State<TicketScreen> {
  final _body = TextEditingController();
  final _minutes = TextEditingController();
  bool _internal = false;
  String? _afterStatus;

  Future<void> _set(Map<String, dynamic> data) async {
    await context.read<SyncEngine>().save('hd_tickets', {'id': widget.id, ...data});
  }

  Future<void> _send(Map<String, dynamic> t) async {
    final text = _body.text.trim();
    final min = int.tryParse(_minutes.text.trim()) ?? 0;
    if (text.isEmpty && min <= 0) return;
    final sync = context.read<SyncEngine>();
    final session = context.read<Session>();
    if (text.isNotEmpty) {
      await sync.save('hd_messages', {
        'id': newId(),
        'ticket_id': widget.id,
        'body': text,
        'internal': _internal,
        'author_type': 'agent',
        'author_name': session.user['name'],
        'user_id': session.user['id'],
        'created_at': DateTime.now().toUtc().toIso8601String(),
      });
    }
    if (min > 0) {
      await sync.save('hd_times', {'id': newId(), 'ticket_id': widget.id, 'date': isoDate(DateTime.now()), 'minutes': min, 'note': text.isEmpty ? null : (text.length > 120 ? '${text.substring(0, 120)}…' : text), 'billable': true, 'user_id': session.user['id'], 'user_name': session.user['name']});
    }
    if (_afterStatus != null && _afterStatus != t['status']) {
      await _set({'status': _afterStatus});
    } else if (!_internal && text.isNotEmpty && t['status'] == 'new') {
      await _set({'status': 'open'});
    }
    _body.clear();
    _minutes.clear();
    setState(() {
      _afterStatus = null;
      _internal = false;
    });
    if (mounted) toast(context, text.isEmpty ? 'Temps enregistré.' : (_internal ? 'Note ajoutée.' : 'Réponse envoyée au client.'));
  }

  Future<void> _attach() async {
    final r = await FilePicker.pickFiles();
    if (r.isEmpty || !mounted) return;
    final f = r.first;
    final api = context.read<Session>().api;
    try {
      final res = await api.upload('helpdesk/tickets/${widget.id}/files', 'file', await f.readAsBytes(), f.name);
      await LocalDb.put('hd_attachments', asMap(res['data']));
      if (mounted) toast(context, tr('Fichier joint : il partira avec votre prochaine réponse au client.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  void _macro(Map<String, dynamic> m, Map<String, dynamic> t) {
    final session = context.read<Session>();
    final body = '${m['body']}'.replaceAll('{client}', '${t['contact_name'] ?? t['partner_name'] ?? ''}'.trim()).replaceAll('{agent}', '${session.user['name'] ?? ''}');
    setState(() {
      _body.text = _body.text.trim().isEmpty ? body : '${_body.text}\n\n$body';
      _afterStatus = m['set_status'] as String?;
      _internal = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final wide = MediaQuery.of(context).size.width >= 1000;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('hd_tickets'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('hd_messages'),
        builder: (context, _, _) => AnimatedBuilder(
          animation: Listenable.merge([LocalDb.listen('hd_times'), LocalDb.listen('hd_attachments')]),
          builder: (context, _) {
            final t = LocalDb.get('hd_tickets', widget.id);
            if (t == null) return Scaffold(appBar: AppBar(), body: Center(child: Text(tr('Ticket introuvable.'))));
            final conversation = _conversation(context, t, session);
            final side = _side(context, t, session);
            return Scaffold(
              appBar: AppBar(
                title: Text(tr('${t['number'] ?? 'Nouveau ticket'} · ${t['subject']}'), overflow: TextOverflow.ellipsis),
                actions: [
                  if (t['public_url'] != null) ...[
                    IconButton(
                      tooltip: tr('Copier le lien du client'),
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: '${t['public_url']}'));
                        toast(context, tr('Lien de suivi copié.'));
                      },
                      icon: const Icon(Icons.link),
                    ),
                    IconButton(tooltip: tr('Voir comme le client'), onPressed: () => safeLaunchUrl(Uri.parse('${t['public_url']}')), icon: const Icon(Icons.open_in_new)),
                  ],
                  const SizedBox(width: 8),
                ],
              ),
              body: wide
                  ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Expanded(child: conversation),
                      const VerticalDivider(width: 1),
                      SizedBox(width: 340, child: SingleChildScrollView(padding: const EdgeInsets.all(16), child: side)),
                    ])
                  : ListView(padding: EdgeInsets.zero, children: [Padding(padding: const EdgeInsets.all(16), child: side), const Divider(height: 1), SizedBox(height: 640, child: conversation)]),
            );
          },
        ),
      ),
    );
  }

  Widget _conversation(BuildContext context, Map<String, dynamic> t, Session session) {
    final hint = Theme.of(context).hintColor;
    final messages = _all('hd_messages').where((m) => m['ticket_id'] == widget.id).toList()..sort((a, b) => '${a['created_at'] ?? '9'}'.compareTo('${b['created_at'] ?? '9'}'));
    final macros = _all('hd_macros')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final closed = t['status'] == 'closed';
    final files = _all('hd_attachments').where((a) => a['ticket_id'] == widget.id).toList()..sort((a, b) => '${a['created_at'] ?? '9'}'.compareTo('${b['created_at'] ?? '9'}'));
    Widget bubble({required String who, required String when, required String body, required String kind, List<Map<String, dynamic>> attached = const []}) {
      final (Color bg, Alignment al, Color? border) = switch (kind) {
        'customer' => (const Color(0xFFE8F0FE), Alignment.centerLeft, null),
        'internal' => (const Color(0xFFFFF6DB), Alignment.centerRight, const Color(0xFFF0D98C)),
        _ => (Colors.white, Alignment.centerRight, YColors.line),
      };
      return Align(
        alignment: al,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 620),
          child: Container(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(14), border: border == null ? null : Border.all(color: border)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(mainAxisSize: MainAxisSize.min, children: [
                if (kind == 'internal') const Padding(padding: EdgeInsets.only(right: 4), child: Icon(Icons.lock_outline, size: 13, color: Color(0xFF9A6B00))),
                Flexible(child: Text(tr('$who · $when'), style: TextStyle(fontSize: 12, color: hint, fontWeight: FontWeight.w600))),
              ]),
              const SizedBox(height: 4),
              SelectableText(tr(body), style: const TextStyle(height: 1.4)),
              for (final a in attached) hdFileChip(context, a),
            ]),
          ),
        ),
      );
    }

    return Column(children: [
      Expanded(
        child: ListView(padding: const EdgeInsets.all(16), children: [
          bubble(who: '${t['contact_name'] ?? t['partner_name'] ?? 'Client'}', when: t['created_at'] == null ? 'maintenant' : dateTimeFr(t['created_at']), body: '${t['description'] ?? t['subject']}', kind: 'customer', attached: files.where((a) => a['message_id'] == null && a['uploaded_by'] == null).toList()),
          for (final m in messages)
            if (m['author_type'] == 'system')
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Center(child: Text(tr('${m['body']}${m['author_name'] != null ? ' — ${m['author_name']}' : ''} · ${m['created_at'] == null ? '' : dateTimeFr(m['created_at'])}'), style: TextStyle(fontSize: 12, color: hint), textAlign: TextAlign.center)),
              )
            else
              bubble(
                who: m['author_type'] == 'customer' ? '${m['author_name'] ?? 'Client'}' : '${m['author_name'] ?? 'Équipe'}${m['internal'] == true ? ' (note interne)' : (m['sent_via'] != null ? ' · via ${m['sent_via']}' : '')}',
                when: m['created_at'] == null ? 'maintenant' : dateTimeFr(m['created_at']),
                body: '${m['body']}',
                kind: m['author_type'] == 'customer' ? 'customer' : (m['internal'] == true ? 'internal' : 'agent'),
                attached: files.where((a) => a['message_id'] == m['id']).toList(),
              ),
          if (t['rating'] != null)
            Center(
              child: Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Chip(avatar: const Icon(Icons.star, color: Color(0xFFFFB400), size: 18), label: Text(tr('Avis du client : ${t['rating']}/5${t['rating_comment'] != null ? ' — « ${t['rating_comment']} »' : ''}'))),
              ),
            ),
        ]),
      ),
      if (hdAgent(session))
        Container(
          decoration: BoxDecoration(color: Theme.of(context).cardColor, border: const Border(top: BorderSide(color: YColors.line))),
          padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
          child: Column(children: [
            Row(children: [
              SegmentedButton<bool>(
                segments: [ButtonSegment(value: false, label: Text(tr('Répondre au client')), icon: Icon(Icons.reply)), ButtonSegment(value: true, label: Text(tr('Note interne')), icon: Icon(Icons.lock_outline))],
                selected: {_internal},
                showSelectedIcon: false,
                onSelectionChanged: closed ? null : (v) => setState(() => _internal = v.first),
              ),
              const Spacer(),
              if (macros.isNotEmpty)
                PopupMenuButton<Map<String, dynamic>>(
                  tooltip: tr('Réponses types'),
                  onSelected: (m) => _macro(m, t),
                  itemBuilder: (_) => [for (final m in macros) PopupMenuItem(value: m, child: Text(tr('${m['name']}')))],
                  child: Padding(padding: EdgeInsets.all(8), child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(Icons.bolt, size: 18), SizedBox(width: 4), Text(tr('Réponses types'))])),
                ),
            ]),
            if (files.any((a) => a['message_id'] == null && a['uploaded_by'] == session.user['name']))
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Wrap(spacing: 6, children: [
                  for (final a in files.where((a) => a['message_id'] == null && a['uploaded_by'] == session.user['name']))
                    Chip(avatar: const Icon(Icons.attach_file, size: 16), label: Text(tr('${a['name']} — partira avec la réponse'), style: const TextStyle(fontSize: 12))),
                ]),
              ),
            const SizedBox(height: 8),
            TextField(
              controller: _body,
              minLines: 3,
              maxLines: 8,
              decoration: InputDecoration(
                hintText: trn(closed ? 'Ticket fermé : rouvrez-le pour répondre au client (les notes internes restent possibles).' : (_internal ? 'Note visible par l\'équipe seulement' : 'Votre réponse (envoyée par e-mail et WhatsApp)')),
                fillColor: _internal ? const Color(0xFFFFFBEA) : null,
                filled: _internal,
              ),
            ),
            const SizedBox(height: 8),
            Row(children: [
              IconButton(tooltip: tr('Joindre un fichier (envoyé avec la réponse)'), onPressed: closed ? null : () => _attach(), icon: const Icon(Icons.attach_file)),
              SizedBox(width: 170, child: TextField(controller: _minutes, keyboardType: TextInputType.number, decoration: InputDecoration(isDense: true, labelText: tr('Temps (min)'), prefixIcon: Icon(Icons.timer_outlined, size: 18)))),
              const SizedBox(width: 10),
              if (_afterStatus != null) Flexible(child: Text(tr('Puis : ${hdStatuses[_afterStatus]}'), style: TextStyle(color: hint, fontSize: 12))),
              const Spacer(),
              FilledButton.icon(
                onPressed: closed && !_internal ? null : () => _send(t),
                icon: Icon(_internal ? Icons.lock_outline : Icons.send),
                label: Text(tr(_internal ? 'Ajouter la note' : 'Envoyer')),
              ),
            ]),
          ]),
        ),
    ]);
  }

  Widget _side(BuildContext context, Map<String, dynamic> t, Session session) {
    final hint = Theme.of(context).hintColor;
    final agent = hdAgent(session);
    final st = '${t['status']}';
    final sla = hdSla(t);
    final cats = _all('hd_categories')..sort((a, b) => toDouble(a['sort']).compareTo(toDouble(b['sort'])));
    final packs = _all('pr_packs').where((p) => p['partner_id'] == t['partner_id'] && (p['id'] == t['pack_id'] || p['status'] == 'active' || p['status'] == 'exhausted')).toList();
    final times = _all('hd_times').where((x) => x['ticket_id'] == widget.id).toList()..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
    final minutes = times.fold<int>(0, (s, x) => s + toDouble(x['minutes']).toInt());
    final agents = hdAgents();
    DateTime? due(String k) => DateTime.tryParse('${t[k] ?? ''}')?.toLocal();
    Widget line(String l, String v) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 3),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [SizedBox(width: 120, child: Text(tr(l), style: TextStyle(color: hint, fontSize: 13))), Expanded(child: Text(tr(v), style: const TextStyle(fontSize: 13)))]),
        );
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Wrap(spacing: 8, runSpacing: 8, children: [
        StatusChip(hdStatuses[st] ?? st, color: hdStatusColor(st)),
        StatusChip(tr('Priorité ${hdPriorities[t['priority']]?.toLowerCase() ?? ''}'), color: hdPriorityColor('${t['priority']}')),
        if (sla != null) StatusChip(sla.$1, color: sla.$2),
      ]),
      const SizedBox(height: 12),
      if (agent)
        Wrap(spacing: 8, runSpacing: 8, children: [
          if (st == 'new' || st == 'pending') OutlinedButton(onPressed: () => _set({'status': 'open'}), child: Text(tr('En cours'))),
          if (st == 'open' || st == 'new') OutlinedButton(onPressed: () => _set({'status': 'pending'}), child: Text(tr('En attente du client'))),
          if (st != 'resolved' && st != 'closed') FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: YColors.success), onPressed: () => _set({'status': 'resolved'}), icon: const Icon(Icons.check), label: Text(tr('Résolu'))),
          if (st == 'resolved') OutlinedButton(onPressed: () => _set({'status': 'closed'}), child: Text(tr('Fermer'))),
          if (st == 'resolved' || st == 'closed') OutlinedButton.icon(onPressed: () => _set({'status': 'open'}), icon: const Icon(Icons.undo), label: Text(tr('Rouvrir'))),
        ]),
      const SizedBox(height: 14),
      Field(
        label: tr('Attribué à'),
        child: DropdownButtonFormField<int?>(
          initialValue: agents.any((u) => u['id'] == t['assignee_id']) ? t['assignee_id'] as int? : null,
          isExpanded: true,
          items: [DropdownMenuItem<int?>(value: null, child: Text(tr('Non attribué'))), for (final u in agents) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}')))],
          onChanged: agent ? (v) => _set({'assignee_id': v}) : null,
        ),
      ),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Priorité'),
            child: DropdownButtonFormField<String>(
              initialValue: '${t['priority']}',
              items: [for (final p in hdPriorities.entries) DropdownMenuItem(value: p.key, child: Text(tr(p.value)))],
              onChanged: agent ? (v) => _set({'priority': v}) : null,
            ),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Field(
            label: tr('Catégorie'),
            child: DropdownButtonFormField<String?>(
              initialValue: cats.any((c) => c['id'] == t['category_id']) ? t['category_id'] as String? : null,
              isExpanded: true,
              items: [const DropdownMenuItem<String?>(value: null, child: Text('—')), for (final c in cats) DropdownMenuItem<String?>(value: c['id'] as String, child: Text(tr('${c['name']}'), overflow: TextOverflow.ellipsis))],
              onChanged: agent ? (v) => _set({'category_id': v}) : null,
            ),
          ),
        ),
      ]),
      if (packs.isNotEmpty)
        Field(
          label: tr('Forfait d\'heures (temps décompté)'),
          child: DropdownButtonFormField<String?>(
            initialValue: packs.any((p) => p['id'] == t['pack_id']) ? t['pack_id'] as String? : null,
            isExpanded: true,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun'))),
              for (final p in packs) DropdownMenuItem<String?>(value: p['id'] as String, child: Text(tr('${p['name']} · reste ${toDouble(p['remaining']).toStringAsFixed(1).replaceAll('.', ',')} h'), overflow: TextOverflow.ellipsis)),
            ],
            onChanged: agent ? (v) => _set({'pack_id': v}) : null,
          ),
        ),
      const SizedBox(height: 6),
      Text(tr('Client'), style: Theme.of(context).textTheme.titleSmall),
      const SizedBox(height: 4),
      line('Société', '${t['partner_name'] ?? '—'}'),
      line('Contact', '${t['contact_name'] ?? '—'}'),
      if (t['contact_email'] != null) line('E-mail', '${t['contact_email']}'),
      if (t['contact_phone'] != null) line('Téléphone', '${t['contact_phone']}'),
      line('Canal', hdChannels[t['channel']] ?? '${t['channel']}'),
      const SizedBox(height: 10),
      Text(tr('Délais'), style: Theme.of(context).textTheme.titleSmall),
      const SizedBox(height: 4),
      line('1re réponse', t['first_response_at'] != null ? 'le ${dateTimeFr(t['first_response_at'])}${t['sla_first_breached'] == true ? ' (en retard)' : ''}' : (due('first_response_due') == null ? '—' : 'avant le ${DateFormat('dd/MM HH:mm').format(due('first_response_due')!)}')),
      line('Résolution', t['resolved_at'] != null ? 'le ${dateTimeFr(t['resolved_at'])}${t['sla_resolve_breached'] == true ? ' (en retard)' : ''}' : (due('resolution_due') == null ? '—' : 'avant le ${DateFormat('dd/MM HH:mm').format(due('resolution_due')!)}')),
      if (toDouble(t['reopened']) > 0) line('Rouvert', '${toDouble(t['reopened']).toInt()} fois'),
      const SizedBox(height: 10),
      Row(children: [
        Text(tr('Temps passé · ${hdDuration(Duration(minutes: minutes))}'), style: Theme.of(context).textTheme.titleSmall),
        const Spacer(),
        if (agent) IconButton(tooltip: tr('Ajouter du temps'), onPressed: () => hdLogTime(context, ticketId: widget.id), icon: const Icon(Icons.more_time, size: 20)),
      ]),
      for (final x in times)
        ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          title: Text(tr('${hdDuration(Duration(minutes: toDouble(x['minutes']).toInt()))} · ${x['user_name'] ?? ''}${x['billable'] == false ? ' · non facturable' : ''}')),
          subtitle: Text(tr([dateFr(x['date']), if (x['note'] != null) '${x['note']}'].join(' · ')), maxLines: 2, overflow: TextOverflow.ellipsis),
          trailing: agent && (x['user_id'] == session.user['id'] || hdManage(session))
              ? IconButton(icon: const Icon(Icons.delete_outline, size: 18), onPressed: () => context.read<SyncEngine>().remove('hd_times', '${x['id']}'))
              : null,
        ),
      if (t['pack_id'] != null && times.isNotEmpty) Text(tr('Le temps facturable est décompté du forfait du client.'), style: TextStyle(fontSize: 12, color: hint)),
    ]);
  }
}

/// Saisie de temps sur un ticket (choisi si non fourni).
Future<void> hdLogTime(BuildContext context, {String? ticketId}) async {
  final sync = context.read<SyncEngine>();
  final session = context.read<Session>();
  final tickets = _all('hd_tickets').where((t) => t['id'] == ticketId || const {'new', 'open', 'pending', 'resolved'}.contains(t['status'])).toList()
    ..sort((a, b) => '${b['created_at'] ?? '9'}'.compareTo('${a['created_at'] ?? '9'}'));
  var tid = ticketId ?? (tickets.isEmpty ? null : tickets.first['id'] as String);
  var date = DateTime.now();
  var billable = true;
  final minutes = TextEditingController(text: '30');
  final note = TextEditingController();
  await showFormDialog(
    context,
    title: tr('Temps passé sur un ticket'),
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        if (ticketId == null)
          Field(
            label: tr('Ticket'),
            child: DropdownButtonFormField<String>(
              initialValue: tid,
              isExpanded: true,
              items: [for (final t in tickets) DropdownMenuItem(value: t['id'] as String, child: Text(tr('${t['number'] ?? ''} · ${t['subject']}'), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => set(() => tid = v),
            ),
          ),
        Row(children: [
          Expanded(child: Field(label: tr('Durée (minutes)'), child: TextField(controller: minutes, keyboardType: TextInputType.number, autofocus: true))),
          const SizedBox(width: 12),
          Expanded(
            child: Field(
              label: tr('Date'),
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await pickDate(ctx, initialDate: date, firstDate: DateTime(2020), lastDate: DateTime.now());
                  if (d != null) set(() => date = d);
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr(DateFormat('dd/MM/yyyy').format(date))),
              ),
            ),
          ),
        ]),
        Field(label: tr('Travail réalisé'), child: TextField(controller: note)),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Facturable (décompté du forfait)')), value: billable, onChanged: (v) => set(() => billable = v)),
        Align(
          alignment: Alignment.centerRight,
          child: SaveButton(
            onPressed: () async {
              final m = int.tryParse(minutes.text.trim()) ?? 0;
              if (tid == null || m <= 0 || m > 1440) return toast(ctx, tr('Indiquez le ticket et une durée.'), error: true);
              await sync.save('hd_times', {'id': newId(), 'ticket_id': tid, 'date': isoDate(date), 'minutes': m, 'note': note.text.trim().isEmpty ? null : note.text.trim(), 'billable': billable, 'user_id': session.user['id'], 'user_name': session.user['name']});
              if (ctx.mounted) Navigator.pop(ctx);
            },
            child: Text(tr('Enregistrer')),
          ),
        ),
      ]),
    ),
  );
}

/// Nouveau ticket saisi par l'équipe (appel, e-mail, WhatsApp, visite).
Future<void> newTicket(BuildContext context) async {
  final sync = context.read<SyncEngine>();
  final partners = LocalDb.all('partners').where((x) => x['type'] != 'supplier').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
  final cats = _all('hd_categories')..sort((a, b) => toDouble(a['sort']).compareTo(toDouble(b['sort'])));
  final agents = hdAgents();
  String? partner;
  String? category;
  int? assignee;
  var channel = 'phone';
  var priority = 'normal';
  final c = {for (final k in ['subject', 'description', 'contact_name', 'contact_email', 'contact_phone']) k: TextEditingController()};
  await showFormDialog(
    context,
    title: tr('Nouveau ticket'),
    maxWidth: 620,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(
          label: tr('Client'),
          child: DropdownButtonFormField<String?>(
            initialValue: partner,
            isExpanded: true,
            items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Nouveau contact (saisir ci-dessous)'))), for (final p in partners) DropdownMenuItem<String?>(value: p['id'] as String, child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
            onChanged: (v) => set(() => partner = v),
          ),
        ),
        Row(children: [
          Expanded(child: Field(label: tr('Contact'), child: TextField(controller: c['contact_name']))),
          const SizedBox(width: 10),
          Expanded(child: Field(label: tr('Téléphone'), child: TextField(controller: c['contact_phone']))),
        ]),
        Field(label: tr('E-mail'), child: TextField(controller: c['contact_email'], keyboardType: TextInputType.emailAddress)),
        Field(label: tr('Objet'), child: TextField(controller: c['subject'])),
        Field(label: tr('Description'), child: TextField(controller: c['description'], minLines: 3, maxLines: 6)),
        Row(children: [
          Expanded(
            child: Field(
              label: tr('Canal'),
              child: DropdownButtonFormField<String>(
                initialValue: channel,
                items: [for (final k in ['phone', 'email', 'whatsapp', 'agent']) DropdownMenuItem(value: k, child: Text(tr(hdChannels[k]!)))],
                onChanged: (v) => set(() => channel = v ?? channel),
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Field(
              label: tr('Priorité'),
              child: DropdownButtonFormField<String>(
                initialValue: priority,
                items: [for (final p in hdPriorities.entries) DropdownMenuItem(value: p.key, child: Text(tr(p.value)))],
                onChanged: (v) => set(() => priority = v ?? priority),
              ),
            ),
          ),
        ]),
        Row(children: [
          Expanded(
            child: Field(
              label: tr('Catégorie'),
              child: DropdownButtonFormField<String?>(
                initialValue: category,
                isExpanded: true,
                items: [const DropdownMenuItem<String?>(value: null, child: Text('—')), for (final x in cats) DropdownMenuItem<String?>(value: x['id'] as String, child: Text(tr('${x['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => set(() => category = v),
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Field(
              label: tr('Attribuer à'),
              child: DropdownButtonFormField<int?>(
                initialValue: assignee,
                isExpanded: true,
                items: [DropdownMenuItem<int?>(value: null, child: Text(tr('Automatique'))), for (final u in agents) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}')))],
                onChanged: (v) => set(() => assignee = v),
              ),
            ),
          ),
        ]),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerRight,
          child: SaveButton(
            onPressed: () async {
              String? v(String k) => c[k]!.text.trim().isEmpty ? null : c[k]!.text.trim();
              if (v('subject') == null) return toast(ctx, tr('Indiquez l\'objet.'), error: true);
              if (partner == null && v('contact_email') == null && v('contact_phone') == null) return toast(ctx, tr('Choisissez le client ou indiquez un e-mail ou un téléphone.'), error: true);
              final p = partner == null ? null : LocalDb.get('partners', partner!);
              final id = newId();
              await sync.save('hd_tickets', {
                'id': id,
                'partner_id': partner,
                'partner_name': p?['name'],
                'contact_name': v('contact_name') ?? p?['name'],
                'contact_email': v('contact_email') ?? p?['email'],
                'contact_phone': v('contact_phone') ?? p?['phone'],
                'subject': v('subject'),
                'description': v('description'),
                'channel': channel,
                'priority': priority,
                'category_id': category,
                'assignee_id': assignee,
                'status': 'new',
                'created_at': DateTime.now().toUtc().toIso8601String(),
              });
              if (ctx.mounted) {
                Navigator.pop(ctx);
                Navigator.of(ctx).push(MaterialPageRoute(builder: (_) => TicketScreen(id: id)));
              }
            },
            child: Text(tr('Créer le ticket')),
          ),
        ),
      ]),
    ),
  );
}

// ======================================================================
// Base de connaissances

class _ArticlesTab extends StatefulWidget {
  const _ArticlesTab();

  @override
  State<_ArticlesTab> createState() => _ArticlesTabState();
}

class _ArticlesTabState extends State<_ArticlesTab> {
  String? _helpUrl;
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  @override
  void initState() {
    super.initState();
    context.read<Session>().api.get('helpdesk/settings').then((r) {
      if (mounted) setState(() => _helpUrl = '${r['data']['help_url']}');
    }).catchError((_) {});
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final hint = Theme.of(context).hintColor;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('hd_articles'),
      builder: (context, _, _) {
        final all = _all('hd_articles');
        final base = all
            .where((a) => _q.isEmpty || '${a['title']} ${a['section'] ?? ''} ${a['tags'] ?? ''}'.toLowerCase().contains(_q))
            .where((a) => _f['section'] == null || '${a['section']}' == _f['section'])
            .toList();
        bool pubMatch(Map<String, dynamic> a, String k) => k.isEmpty || (k == 'published') == (a['published'] == true);
        const statuses = {'': 'Tous', 'published': 'Publiés', 'draft': 'Brouillons'};
        final rows = base.where((a) => pubMatch(a, _status)).toList()..sort((a, b) => '${a['section'] ?? ''}${a['title']}'.compareTo('${b['section'] ?? ''}${b['title']}'));
        return ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Wrap(spacing: 12, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
                const Icon(Icons.travel_explore, color: YColors.blue, size: 28),
                ConstrainedBox(constraints: const BoxConstraints(maxWidth: 520), child: Text(tr('Les articles publiés forment votre centre d\'aide public : vos clients y trouvent les réponses, puis peuvent ouvrir une demande. Le formulaire propose les articles proches de leur question.'), style: TextStyle(color: hint))),
                if (_helpUrl != null) ...[
                  OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse(_helpUrl!)), icon: const Icon(Icons.open_in_new), label: Text(tr('Ouvrir le centre d\'aide'))),
                  IconButton(
                    tooltip: tr('Copier le lien'),
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: _helpUrl!));
                      toast(context, tr('Lien copié.'));
                    },
                    icon: const Icon(Icons.link),
                  ),
                ],
                if (hdManage(session)) FilledButton.icon(onPressed: () => editArticle(context), icon: const Icon(Icons.add), label: Text(tr('Article'))),
              ]),
            ),
          ),
          const SizedBox(height: 12),
          if (all.isNotEmpty)
            FilterBar(
              key: ValueKey(_gen),
              padding: const EdgeInsets.only(bottom: 8),
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              search: _q,
              searchHint: 'Titre, rubrique, mot-clé…',
              statuses: statuses,
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in statuses.keys) k: base.where((a) => pubMatch(a, k)).length},
              filters: [FilterSpec('section', 'Rubrique', filterOptions(all, 'section'), icon: Icons.folder_outlined)],
              values: _f,
              onFilter: (k, v) => setState(() => _f[k] = v),
              count: rows.length,
              countLabel: 'article',
              onReset: () => setState(() {
                _q = '';
                _status = '';
                _f.clear();
                _gen++;
              }),
            ),
          if (rows.isEmpty) EmptyState(icon: Icons.menu_book_outlined, title: tr('Aucun article'), message: tr('Rédigez les réponses aux questions fréquentes : moins de tickets, des clients autonomes.')),
          for (final a in rows)
            Card(
              child: ListTile(
                onTap: hdManage(session) ? () => editArticle(context, a) : null,
                leading: Icon(Icons.article_outlined, color: a['published'] == true ? YColors.blue : YColors.muted),
                title: Text(tr('${a['title']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(tr([if (a['section'] != null) '${a['section']}', '${toDouble(a['views']).toInt()} lecture(s)', if (toDouble(a['helpful_yes']) + toDouble(a['helpful_no']) > 0) '${(100 * toDouble(a['helpful_yes']) / (toDouble(a['helpful_yes']) + toDouble(a['helpful_no']))).round()} % utile'].join(' · '))),
                trailing: StatusChip(a['published'] == true ? 'Publié' : 'Brouillon', color: a['published'] == true ? YColors.success : YColors.muted),
              ),
            ),
        ]);
      },
    );
  }
}

Future<void> editArticle(BuildContext context, [Map<String, dynamic>? a]) async {
  final sync = context.read<SyncEngine>();
  final c = {for (final k in ['title', 'section', 'body', 'tags']) k: TextEditingController(text: '${a?[k] ?? ''}')};
  var published = a?['published'] == true;
  await showFormDialog(
    context,
    title: a == null ? 'Nouvel article' : 'Article',
    maxWidth: 760,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(label: tr('Titre (la question du client)'), child: TextField(controller: c['title'], autofocus: a == null)),
        Row(children: [
          Expanded(child: Field(label: tr('Rubrique'), child: TextField(controller: c['section'], decoration: InputDecoration(hintText: tr('Ex. Facturation, Caisse, Compte'))))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Mots-clés'), child: TextField(controller: c['tags']))),
        ]),
        Field(
          label: tr('Contenu'),
          child: TextField(controller: c['body'], minLines: 10, maxLines: 22, decoration: InputDecoration(helperText: tr('Mise en forme : « ## Titre », « - élément de liste », **gras**, [texte](https://lien)'), helperMaxLines: 2)),
        ),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Publié dans le centre d\'aide')), value: published, onChanged: (v) => set(() => published = v)),
        Row(children: [
          if (a != null)
            TextButton(
              onPressed: () async {
                if (!await confirm(ctx, tr('Supprimer l\'article ?'), '${a['title']}', danger: true, ok: tr('Supprimer'))) return;
                await sync.remove('hd_articles', '${a['id']}');
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              if (c['title']!.text.trim().isEmpty || c['body']!.text.trim().isEmpty) return toast(ctx, tr('Titre et contenu obligatoires.'), error: true);
              await sync.save('hd_articles', {
                'id': a?['id'] ?? newId(),
                'title': c['title']!.text.trim(),
                'section': c['section']!.text.trim().isEmpty ? null : c['section']!.text.trim(),
                'body': c['body']!.text.trim(),
                'tags': c['tags']!.text.trim().isEmpty ? null : c['tags']!.text.trim(),
                'published': published,
              });
              if (ctx.mounted) Navigator.pop(ctx);
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]),
    ),
  );
}

// ======================================================================
// Réglages : horaires, délais (SLA), catégories, réponses types

class _SettingsTab extends StatefulWidget {
  const _SettingsTab();

  @override
  State<_SettingsTab> createState() => _SettingsTabState();
}

class _SettingsTabState extends State<_SettingsTab> {
  Map<String, dynamic>? _s;
  final _start = TextEditingController();
  final _end = TextEditingController();
  final _close = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('helpdesk/settings');
      final s = asMap(r['data']);
      if (!mounted) return;
      setState(() => _s = s);
      _start.text = '${s['hours']['start']}';
      _end.text = '${s['hours']['end']}';
      _close.text = '${s['auto_close_days']}';
    } catch (_) {}
  }

  Future<void> _save(Map<String, dynamic> v) async {
    try {
      final r = await context.read<Session>().api.put('helpdesk/settings', v);
      if (mounted) setState(() => _s = {..._s!, ...asMap(r['data'])});
      if (mounted) toast(context, tr('Réglages enregistrés.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    const days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    return ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
      if (s == null)
        Card(child: ListTile(leading: const Icon(Icons.cloud_off), title: Text(tr('Connexion requise pour les horaires et options du service client.')), trailing: TextButton(onPressed: _load, child: Text(tr('Réessayer')))))
      else
        SectionCard(
          title: tr('Horaires et fonctionnement'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.end, children: [
              SizedBox(width: 110, child: Field(label: tr('Ouverture'), child: TextField(controller: _start))),
              SizedBox(width: 110, child: Field(label: tr('Fermeture'), child: TextField(controller: _end))),
              SizedBox(width: 170, child: Field(label: tr('Fermer les résolus après (j)'), child: TextField(controller: _close, keyboardType: TextInputType.number))),
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: FilledButton(onPressed: () => _save({'hours': {'start': _start.text.trim(), 'end': _end.text.trim()}, 'auto_close_days': int.tryParse(_close.text) ?? 5}), child: Text(tr('Enregistrer'))),
              ),
            ]),
            Wrap(spacing: 6, children: [
              for (var i = 1; i <= 7; i++)
                FilterChip(
                  label: Text(tr(days[i - 1])),
                  selected: (s['hours']['days'] as List).contains(i),
                  onSelected: (v) {
                    final d = {...(s['hours']['days'] as List).map((e) => (e as num).toInt())};
                    v ? d.add(i) : d.remove(i);
                    if (d.isNotEmpty) _save({'hours': {'days': (d.toList()..sort())}});
                  },
                ),
            ]),
            const SizedBox(height: 8),
            for (final o in const [
              ('auto_assign', 'Attribution automatique (responsable de la catégorie, sinon l\'agent le moins chargé)'),
              ('ack', 'Accusé de réception au client avec le lien de suivi'),
              ('csat', 'Demander l\'avis du client à la résolution'),
              ('kb_public', 'Centre d\'aide public (articles publiés)'),
              ('contact_form', 'Formulaire de contact en ligne'),
            ])
              SwitchListTile(contentPadding: EdgeInsets.zero, dense: true, title: Text(tr(o.$2)), value: s[o.$1] == true, onChanged: (v) => _save({o.$1: v})),
          ]),
        ),
      const SizedBox(height: 12),
      const _InboundCard(),
      const SizedBox(height: 12),
      _EntityList(
        entity: 'hd_sla_policies',
        title: tr('Délais garantis (SLA)'),
        empty: 'Aucun délai : les tickets n\'ont pas d\'échéance.',
        subtitle: (p) => [
          for (final k in ['urgent', 'high', 'normal', 'low'])
            if (asMap(p['targets'])[k] != null) '${hdPriorities[k]} : ${hdDuration(Duration(minutes: toDouble(p['targets'][k]['first']).toInt()))} / ${hdDuration(Duration(minutes: toDouble(p['targets'][k]['resolve']).toInt()))}',
        ].join(' · ') + (p['business_hours'] == true ? ' (heures ouvrées)' : ''),
        badge: (p) => p['is_default'] == true ? 'Par défaut' : ((p['partner_ids'] as List?)?.isNotEmpty == true ? '${(p['partner_ids'] as List).length} client(s)' : null),
        onEdit: (p) => editSla(context, p),
      ),
      const SizedBox(height: 12),
      _EntityList(
        entity: 'hd_categories',
        title: tr('Catégories'),
        empty: 'Aucune catégorie.',
        subtitle: (c) => '${c['public'] == true ? 'Proposée au client' : 'Interne'} · ${c['assignee_id'] == null ? 'attribution automatique' : 'responsable : ${hdAgentName(c['assignee_id'])}'}',
        onEdit: (c) => editCategory(context, c),
      ),
      const SizedBox(height: 12),
      _EntityList(
        entity: 'hd_macros',
        title: tr('Réponses types'),
        empty: 'Aucune réponse type.',
        subtitle: (m) => '${m['body']}'.replaceAll('\n', ' '),
        badge: (m) => m['set_status'] == null ? null : 'puis ${hdStatuses[m['set_status']]?.toLowerCase()}',
        onEdit: (m) => editMacro(context, m),
      ),
    ]);
  }
}

class _EntityList extends StatelessWidget {
  const _EntityList({required this.entity, required this.title, required this.empty, required this.subtitle, required this.onEdit, this.badge});

  final String entity;
  final String title;
  final String empty;
  final String Function(Map<String, dynamic>) subtitle;
  final String? Function(Map<String, dynamic>)? badge;
  final void Function(Map<String, dynamic>?) onEdit;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen(entity),
      builder: (context, _, _) {
        final rows = _all(entity)..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
        return SectionCard(
          title: title,
          trailing: TextButton.icon(onPressed: () => onEdit(null), icon: const Icon(Icons.add), label: Text(tr('Ajouter'))),
          child: Column(children: [
            if (rows.isEmpty) Padding(padding: const EdgeInsets.all(8), child: Text(tr(empty), style: TextStyle(color: Theme.of(context).hintColor))),
            for (final r in rows)
              ListTile(
                contentPadding: EdgeInsets.zero,
                onTap: () => onEdit(r),
                leading: r['color'] != null ? CircleAvatar(radius: 8, backgroundColor: _hex(r['color'])) : null,
                title: Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text(tr(subtitle(r)), maxLines: 2, overflow: TextOverflow.ellipsis),
                trailing: badge?.call(r) == null ? const Icon(Icons.chevron_right) : StatusChip(badge!(r)!, color: YColors.blue),
              ),
          ]),
        );
      },
    );
  }
}

Color _hex(dynamic h) {
  final s = '${h ?? ''}'.replaceAll('#', '');
  final v = s.length == 6 ? int.tryParse(s, radix: 16) : null;
  return v == null ? YColors.blue : Color(0xFF000000 | v);
}

Future<void> editSla(BuildContext context, Map<String, dynamic>? p) async {
  final sync = context.read<SyncEngine>();
  final name = TextEditingController(text: '${p?['name'] ?? ''}');
  final t = Map<String, dynamic>.from(p?['targets'] as Map? ?? {'urgent': {'first': 30, 'resolve': 240}, 'high': {'first': 60, 'resolve': 480}, 'normal': {'first': 240, 'resolve': 1440}, 'low': {'first': 480, 'resolve': 2880}});
  String hours(dynamic m) => (toDouble(m) / 60).toStringAsFixed(toDouble(m) % 60 == 0 ? 0 : 1).replaceAll('.', ',');
  final ctl = {for (final k in hdPriorities.keys) k: (TextEditingController(text: hours(t[k]?['first'] ?? 240)), TextEditingController(text: hours(t[k]?['resolve'] ?? 1440)))};
  var business = p?['business_hours'] != false;
  var isDefault = p?['is_default'] == true;
  final partners = LocalDb.all('partners').where((x) => x['type'] != 'supplier').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
  final selected = <String>{for (final x in (p?['partner_ids'] as List? ?? const [])) '$x'};
  await showFormDialog(
    context,
    title: p == null ? 'Nouveau délai garanti' : 'Délai garanti',
    maxWidth: 620,
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(label: tr('Nom'), child: TextField(controller: name, decoration: InputDecoration(hintText: tr('Ex. Standard, Premium 24/7')))),
        Table(
          columnWidths: const {0: FlexColumnWidth(1.2), 1: FlexColumnWidth(), 2: FlexColumnWidth()},
          defaultVerticalAlignment: TableCellVerticalAlignment.middle,
          children: [
            TableRow(children: [Text(tr('Priorité'), style: TextStyle(fontWeight: FontWeight.w700)), Text(tr('1re réponse (h)'), style: TextStyle(fontWeight: FontWeight.w700)), Text(tr('Résolution (h)'), style: TextStyle(fontWeight: FontWeight.w700))]),
            for (final k in hdPriorities.keys)
              TableRow(children: [
                Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Text(tr(hdPriorities[k]!))),
                Padding(padding: const EdgeInsets.all(4), child: TextField(controller: ctl[k]!.$1, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(isDense: true))),
                Padding(padding: const EdgeInsets.all(4), child: TextField(controller: ctl[k]!.$2, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(isDense: true))),
              ]),
          ],
        ),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Compter en heures ouvrées')), subtitle: Text(tr('Sans les soirs, week-ends et jours fériés')), value: business, onChanged: (v) => set(() => business = v)),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Délai par défaut')), value: isDefault, onChanged: (v) => set(() => isDefault = v)),
        Field(
          label: tr('Clients avec ce délai (contrat de support)'),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final x in partners.take(60))
              FilterChip(label: Text(tr('${x['name']}')), selected: selected.contains(x['id']), onSelected: (v) => set(() => v ? selected.add('${x['id']}') : selected.remove('${x['id']}'))),
          ]),
        ),
        Row(children: [
          if (p != null)
            TextButton(
              onPressed: () async {
                await sync.remove('hd_sla_policies', '${p['id']}');
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              int m(TextEditingController c) => (toDouble(c.text) * 60).round();
              final targets = {for (final k in hdPriorities.keys) k: {'first': m(ctl[k]!.$1), 'resolve': m(ctl[k]!.$2)}};
              if (name.text.trim().isEmpty) return toast(ctx, tr('Donnez un nom.'), error: true);
              if (targets.values.any((x) => x['first']! < 5 || x['resolve']! < x['first']!)) return toast(ctx, tr('Chaque résolution doit être au moins égale à la première réponse.'), error: true);
              await sync.save('hd_sla_policies', {'id': p?['id'] ?? newId(), 'name': name.text.trim(), 'targets': targets, 'business_hours': business, 'is_default': isDefault, 'partner_ids': selected.toList()});
              if (ctx.mounted) Navigator.pop(ctx);
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]),
    ),
  );
}

Future<void> editCategory(BuildContext context, Map<String, dynamic>? c) async {
  final sync = context.read<SyncEngine>();
  final name = TextEditingController(text: '${c?['name'] ?? ''}');
  var public = c?['public'] != false;
  int? assignee = c?['assignee_id'] as int?;
  var color = '${c?['color'] ?? '#1F6FEB'}';
  final agents = hdAgents();
  const colors = ['#1F6FEB', '#D93025', '#8E44AD', '#12A150', '#E67E22', '#16A085', '#2C3E50'];
  await showFormDialog(
    context,
    title: c == null ? 'Nouvelle catégorie' : 'Catégorie',
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(label: tr('Nom'), child: TextField(controller: name)),
        Field(
          label: tr('Responsable (attribution automatique)'),
          child: DropdownButtonFormField<int?>(
            initialValue: agents.any((u) => u['id'] == assignee) ? assignee : null,
            items: [DropdownMenuItem<int?>(value: null, child: Text(tr('L\'agent le moins chargé'))), for (final u in agents) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}')))],
            onChanged: (v) => set(() => assignee = v),
          ),
        ),
        Field(
          label: tr('Couleur'),
          child: Wrap(spacing: 8, children: [
            for (final col in colors)
              InkWell(onTap: () => set(() => color = col), child: Container(width: 26, height: 26, decoration: BoxDecoration(color: _hex(col), shape: BoxShape.circle, border: Border.all(color: color == col ? YColors.night : Colors.transparent, width: 3)))),
          ]),
        ),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Proposée dans le formulaire du client')), value: public, onChanged: (v) => set(() => public = v)),
        Row(children: [
          if (c != null)
            TextButton(
              onPressed: () async {
                await sync.remove('hd_categories', '${c['id']}');
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              if (name.text.trim().isEmpty) return toast(ctx, tr('Donnez un nom.'), error: true);
              await sync.save('hd_categories', {'id': c?['id'] ?? newId(), 'name': name.text.trim(), 'public': public, 'assignee_id': assignee, 'color': color});
              if (ctx.mounted) Navigator.pop(ctx);
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]),
    ),
  );
}

Future<void> editMacro(BuildContext context, Map<String, dynamic>? m) async {
  final sync = context.read<SyncEngine>();
  final name = TextEditingController(text: '${m?['name'] ?? ''}');
  final body = TextEditingController(text: '${m?['body'] ?? ''}');
  String? status = m?['set_status'] as String?;
  await showFormDialog(
    context,
    title: m == null ? 'Nouvelle réponse type' : 'Réponse type',
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(label: tr('Nom'), child: TextField(controller: name)),
        Field(label: tr('Texte'), child: TextField(controller: body, minLines: 5, maxLines: 10, decoration: InputDecoration(helperText: tr('{client} et {agent} sont remplacés par les noms.')))),
        Field(
          label: tr('Puis passer le ticket en'),
          child: DropdownButtonFormField<String?>(
            initialValue: status,
            items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Ne rien changer'))), DropdownMenuItem(value: 'open', child: Text(tr('En cours'))), DropdownMenuItem(value: 'pending', child: Text(tr('En attente du client'))), DropdownMenuItem(value: 'resolved', child: Text(tr('Résolu')))],
            onChanged: (v) => set(() => status = v),
          ),
        ),
        Row(children: [
          if (m != null)
            TextButton(
              onPressed: () async {
                await sync.remove('hd_macros', '${m['id']}');
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              if (name.text.trim().isEmpty || body.text.trim().isEmpty) return toast(ctx, tr('Nom et texte obligatoires.'), error: true);
              await sync.save('hd_macros', {'id': m?['id'] ?? newId(), 'name': name.text.trim(), 'body': body.text.trim(), 'set_status': status});
              if (ctx.mounted) Navigator.pop(ctx);
            },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]),
    ),
  );
}


/// Réception : boîte e-mail relevée (IMAP) ou webhooks, et WhatsApp entrant.
class _InboundCard extends StatefulWidget {
  const _InboundCard();

  @override
  State<_InboundCard> createState() => _InboundCardState();
}

class _InboundCardState extends State<_InboundCard> {
  Map<String, dynamic>? _b;
  bool _busy = false;
  final _c = {for (final k in ['address', 'imap_host', 'imap_port', 'imap_username', 'imap_password', 'imap_folder']) k: TextEditingController()};
  String _enc = 'ssl';

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _fill(Map<String, dynamic> b) {
    _b = b;
    for (final k in ['address', 'imap_host', 'imap_port', 'imap_username', 'imap_folder']) {
      _c[k]!.text = '${b[k] ?? ''}';
    }
    _c['imap_password']!.clear();
    _enc = '${b['imap_encryption'] ?? 'ssl'}';
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('helpdesk/inbound');
      if (mounted) setState(() => _fill(asMap(r['data'])));
    } catch (_) {}
  }

  Future<void> _save(Map<String, dynamic> extra) async {
    setState(() => _busy = true);
    try {
      String? v(String k) => _c[k]!.text.trim().isEmpty ? null : _c[k]!.text.trim();
      final r = await context.read<Session>().api.put('helpdesk/inbound', {
        'address': v('address'),
        'imap_host': v('imap_host'),
        'imap_port': int.tryParse(_c['imap_port']!.text) ?? 993,
        'imap_encryption': _enc,
        'imap_username': v('imap_username'),
        if (v('imap_password') != null) 'imap_password': v('imap_password'),
        'imap_folder': v('imap_folder') ?? 'INBOX',
        ...extra,
      });
      if (mounted) setState(() => _fill(asMap(r['data'])));
      if (mounted) toast(context, tr('Réception enregistrée.'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _fetch() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('helpdesk/inbound/fetch');
      final x = asMap(r['result']);
      if (mounted) setState(() => _fill(asMap(r['data'])));
      if (mounted) toast(context, tr('Boîte relevée : ${x['created']} ticket(s) créé(s), ${x['appended']} réponse(s), ${x['ignored']} ignoré(s).'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      _load();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _url(String label, String value) => ListTile(
        dense: true,
        contentPadding: EdgeInsets.zero,
        title: Text(tr(label)),
        subtitle: SelectableText(tr(value), style: const TextStyle(fontSize: 12)),
        trailing: IconButton(
          tooltip: tr('Copier'),
          icon: const Icon(Icons.copy, size: 18),
          onPressed: () {
            Clipboard.setData(ClipboardData(text: value));
            toast(context, tr('Copié.'));
          },
        ),
      );

  @override
  Widget build(BuildContext context) {
    final b = _b;
    final hint = Theme.of(context).hintColor;
    if (b == null) return const SizedBox.shrink();
    return SectionCard(
      title: tr('Réception des e-mails et de WhatsApp'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(tr('Chaque e-mail ou message WhatsApp reçu crée un ticket ; les réponses des clients (y compris les pièces jointes) sont rattachées au bon ticket.'), style: TextStyle(color: hint)),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 8, children: [
          StatusChip(tr('${toDouble(b['received']).toInt()} message(s) reçu(s)'), color: YColors.blue),
          if (b['last_check_at'] != null) StatusChip(tr('Relevée le ${dateTimeFr(b['last_check_at'])}'), color: b['last_error'] == null ? YColors.success : YColors.danger),
        ]),
        if (b['last_error'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Erreur : ${b['last_error']}'), style: const TextStyle(color: YColors.danger, fontSize: 13))),
        const SizedBox(height: 10),
        Text(tr('1. Boîte e-mail du support (IMAP, relevée toutes les 5 minutes)'), style: Theme.of(context).textTheme.titleSmall),
        SwitchListTile(contentPadding: EdgeInsets.zero, dense: true, title: Text(tr('Relever la boîte automatiquement')), value: b['imap_enabled'] == true, onChanged: _busy ? null : (v) => _save({'imap_enabled': v})),
        Wrap(spacing: 10, runSpacing: 4, children: [
          SizedBox(width: 260, child: Field(label: tr('Adresse du support'), child: TextField(controller: _c['address'], decoration: InputDecoration(hintText: tr('support@votredomaine.ci'))))),
          SizedBox(width: 240, child: Field(label: tr('Serveur IMAP'), child: TextField(controller: _c['imap_host'], decoration: InputDecoration(hintText: tr('mail.votredomaine.ci'))))),
          SizedBox(width: 90, child: Field(label: tr('Port'), child: TextField(controller: _c['imap_port'], keyboardType: TextInputType.number))),
          SizedBox(
            width: 130,
            child: Field(
              label: tr('Chiffrement'),
              child: DropdownButtonFormField<String>(
                initialValue: _enc,
                items: [DropdownMenuItem(value: 'ssl', child: Text(tr('SSL'))), DropdownMenuItem(value: 'tls', child: Text(tr('STARTTLS'))), DropdownMenuItem(value: 'none', child: Text(tr('Aucun')))],
                onChanged: (v) => setState(() => _enc = v ?? 'ssl'),
              ),
            ),
          ),
          SizedBox(width: 260, child: Field(label: tr('Identifiant'), child: TextField(controller: _c['imap_username']))),
          SizedBox(width: 220, child: Field(label: b['imap_password_set'] == true ? 'Mot de passe (enregistré)' : 'Mot de passe', child: TextField(controller: _c['imap_password'], obscureText: true, decoration: InputDecoration(hintText: trn(b['imap_password_set'] == true ? '••••••••' : null))))),
          SizedBox(width: 140, child: Field(label: tr('Dossier'), child: TextField(controller: _c['imap_folder']))),
        ]),
        Wrap(spacing: 8, children: [
          FilledButton(onPressed: _busy ? null : () => _save({}), child: Text(tr('Enregistrer'))),
          OutlinedButton.icon(onPressed: _busy ? null : _fetch, icon: const Icon(Icons.download), label: Text(tr('Relever maintenant'))),
        ]),
        const SizedBox(height: 14),
        Text(tr('2. Ou un service d\'e-mails entrants (Mailgun, SendGrid, Postmark, Brevo)'), style: Theme.of(context).textTheme.titleSmall),
        _url('Adresse du webhook e-mail', '${b['mail_webhook']}'),
        const SizedBox(height: 6),
        Text(tr('3. WhatsApp entrant (API Cloud Meta, UltraMsg, Green API)'), style: Theme.of(context).textTheme.titleSmall),
        SwitchListTile(contentPadding: EdgeInsets.zero, dense: true, title: Text(tr('Créer des tickets depuis WhatsApp')), value: b['whatsapp_enabled'] == true, onChanged: _busy ? null : (v) => _save({'whatsapp_enabled': v})),
        _url('Adresse du webhook WhatsApp', '${b['whatsapp_webhook']}'),
        _url('Jeton de vérification (Meta)', '${b['verify_token']}'),
      ]),
    );
  }
}
