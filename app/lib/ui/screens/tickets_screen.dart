import 'dart:async';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../files/save_file.dart';
import '../../pdf/report_pdf.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const ticketStatuses = {
  'submitted': 'Soumis',
  'open': 'Ouvert',
  'accepted': 'Accepté',
  'in_progress': 'En cours',
  'on_hold': 'En attente',
  'done': 'Terminé',
  'closed': 'Clôturé',
  'cancelled': 'Annulé',
  'rejected': 'Refusé',
};
const ticketUrgencies = {'low': 'Faible', 'normal': 'Normale', 'high': 'Haute', 'critical': 'Critique'};
const memberRoles = {'head': 'Chef de service', 'deputy': 'Adjoint / second', 'member': 'Collaborateur', 'intern': 'Stagiaire'};

Color ticketStatusColor(String? s) => switch (s) {
      'submitted' => YColors.muted,
      'open' => YColors.blue,
      'accepted' => const Color(0xFF0E7490),
      'in_progress' => const Color(0xFF7C3AED),
      'on_hold' => YColors.warning,
      'done' || 'closed' => YColors.success,
      _ => YColors.danger,
    };

Color urgencyColor(String? u) => switch (u) {
      'critical' => YColors.danger,
      'high' => const Color(0xFFEA580C),
      'normal' => YColors.blue,
      _ => YColors.muted,
    };

String durationFr(num? seconds) {
  if (seconds == null) return '—';
  final s = seconds.toInt();
  if (s < 3600) return '${(s / 60).round().clamp(1, 59)} min';
  if (s < 86400) return '${s ~/ 3600} h ${((s % 3600) ~/ 60).toString().padLeft(2, '0')}';
  final d = s / 86400;
  return '${d.toStringAsFixed(d < 10 ? 1 : 0).replaceAll('.', ',')} j';
}

String _ago(dynamic iso) {
  final d = DateTime.tryParse('$iso')?.toLocal();
  if (d == null) return '';
  return durationFr(DateTime.now().difference(d).inSeconds);
}

/// Demandes internes entre services (nécessite la connexion).
class TicketsScreen extends StatefulWidget {
  const TicketsScreen({super.key, this.openTicketId});

  final int? openTicketId;

  @override
  State<TicketsScreen> createState() => _TicketsScreenState();
}

class _TicketsScreenState extends State<TicketsScreen> with TickerProviderStateMixin {
  Map<String, dynamic>? _setup;
  Object? _error;
  TabController? _tabs;
  int _version = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.get('tickets/setup'));
      if (!mounted) return;
      final me = Map<String, dynamic>.from(r['me']);
      final count = 3 + (me['is_manager'] == true ? 2 : 0) + (me['can_configure'] == true ? 1 : 0);
      setState(() {
        _setup = r;
        _error = null;
        if (_tabs?.length != count) {
          _tabs?.dispose();
          _tabs = TabController(length: count, vsync: this);
        }
      });
      if (widget.openTicketId != null && _version == 0) _open(widget.openTicketId!);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _refresh() => setState(() => _version++);

  Future<void> _open(int id) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TicketDetailScreen(ticketId: id)));
    _refresh();
  }

  @override
  void dispose() {
    _tabs?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _setup == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_setup == null || _tabs == null) return const Center(child: CircularProgressIndicator());
    final me = Map<String, dynamic>.from(_setup!['me']);
    final manager = me['is_manager'] == true;
    final configure = me['can_configure'] == true;

    return Column(children: [
      PageHeader(
        title: tr('Demandes internes'),
        subtitle: tr('Ouvrez un ticket vers un service, suivez son traitement et ses délais'),
        actions: [
          FilledButton.icon(
            onPressed: () async {
              final id = await newTicket(context, _setup!);
              if (id != null) {
                _refresh();
                _open(id);
              }
            },
            icon: const Icon(Icons.add),
            label: Text(tr('Nouvelle demande')),
          ),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Mes demandes')),
        Tab(text: tr('À traiter')),
        if (manager) Tab(text: tr('Mon périmètre')),
        Tab(text: tr('Statistiques')),
        if (manager) Tab(text: tr('Rapports')),
        if (configure) Tab(text: tr('Organisation')),
      ]),
      Expanded(
        child: TabBarView(controller: _tabs, children: [
          _TicketList(key: ValueKey('mine$_version'), box: 'mine', setup: _setup!, onOpen: _open),
          _TicketList(key: ValueKey('todo$_version'), box: 'todo', setup: _setup!, onOpen: _open),
          if (manager) _TicketList(key: ValueKey('dep$_version'), box: 'department', setup: _setup!, onOpen: _open, filters: true),
          _StatsTab(setup: _setup!),
          if (manager) const _ReportsTab(),
          if (configure) _OrgTab(setup: _setup!, onChanged: _load),
        ]),
      ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Listes
// ---------------------------------------------------------------------------

class _TicketList extends StatefulWidget {
  const _TicketList({super.key, required this.box, required this.setup, required this.onOpen, this.filters = false});

  final String box;
  final Map<String, dynamic> setup;
  final void Function(int) onOpen;
  final bool filters;

  @override
  State<_TicketList> createState() => _TicketListState();
}

class _TicketListState extends State<_TicketList> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _status = 'active';
  int? _department;
  String _q = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  Timer? _debounce;
  int _gen = 0;

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
      final r = await context.read<Session>().api.get('tickets/tickets', query: {
        'box': widget.box,
        if (_status.isNotEmpty && _status != 'all') 'status': _status,
        'department_id': ?_department?.toString(),
        if (_q.isNotEmpty) 'q': _q,
      });
      if (mounted) setState(() => (_rows = List<Map<String, dynamic>>.from((r['data'] as List).map((e) => Map<String, dynamic>.from(e))), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _rows == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_rows == null) return const Center(child: CircularProgressIndicator());
    final managed = List<int>.from((widget.setup['me']['managed'] as List?) ?? const []);
    final deps = List<Map<String, dynamic>>.from((widget.setup['departments'] as List).map((e) => Map<String, dynamic>.from(e)))
        .where((d) => widget.setup['me']['all'] == true || managed.contains(d['id']))
        .toList();

    final all = _rows!;
    String name(dynamic m) => m is Map ? '${m['name'] ?? ''}' : '';
    final rows = all
        .where((t) => _f['urgency'] == null || '${t['urgency']}' == _f['urgency'])
        .where((t) => _f['category'] == null || '${t['category']}' == _f['category'])
        .where((t) => _f['assignee'] == null || (_f['assignee'] == '-' ? t['assignee'] == null : name(t['assignee']) == _f['assignee']))
        .where((t) => _f['target'] == null || name(t['department']) == _f['target'])
        .where((t) => _f['requester'] == null || name(t['requester']) == _f['requester'])
        .where((t) => _f['late'] == null || (asMap(t['durations'])['late'] == true) == (_f['late'] == 'yes'))
        .where((t) => _period == null || _period!.contains(t['created_at']))
        .toList();
    Map<String, String> names(String key) {
      final m = <String, String>{for (final t in all) if (name(t[key]).isNotEmpty) name(t[key]): name(t[key])};
      return Map.fromEntries(m.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase())));
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
        FilterBar(
          key: ValueKey(_gen),
          padding: const EdgeInsets.only(bottom: 14),
          onSearch: (v) {
            _q = v.trim();
            _debounce?.cancel();
            _debounce = Timer(const Duration(milliseconds: 400), () {
              if (mounted) _load();
            });
          },
          search: _q,
          searchHint: 'Objet, numéro…',
          statuses: const {'': 'Tous', 'active': 'En cours', 'on_hold': 'En attente', 'done': 'Terminés'},
          status: _status,
          onStatus: (v) => setState(() => (_status = v, _load())),
          filters: [
            if (widget.filters && deps.length > 1) FilterSpec('department', 'Service', {for (final d in deps) '${d['id']}': '${d['name']}'}, icon: Icons.apartment_outlined),
            if (!widget.filters || deps.length <= 1) FilterSpec('target', 'Service', names('department'), icon: Icons.apartment_outlined),
            FilterSpec('urgency', 'Urgence', {for (final e in ticketUrgencies.entries) if (all.any((t) => t['urgency'] == e.key)) e.key: e.value}, icon: Icons.priority_high_rounded),
            FilterSpec('category', 'Catégorie', filterOptions(all, 'category'), icon: Icons.category_outlined),
            if (widget.box != 'mine') FilterSpec('requester', 'Demandeur', names('requester'), icon: Icons.person_outline),
            FilterSpec('assignee', 'Pris en charge par', {...names('assignee'), if (all.any((t) => t['assignee'] == null)) '-': 'Non attribué'}, icon: Icons.engineering_outlined),
            const FilterSpec('late', 'Délai', {'yes': 'En retard', 'no': 'Dans les délais'}, icon: Icons.alarm),
          ],
          values: {..._f, 'department': _department?.toString()},
          onFilter: (k, v) {
            if (k == 'department') {
              setState(() => (_department = v == null ? null : int.tryParse(v), _load()));
            } else {
              setState(() => _f[k] = v);
            }
          },
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows.length,
          countLabel: 'demande',
          onReset: () => setState(() {
            _status = 'active';
            _department = null;
            _q = '';
            _f.clear();
            _period = null;
            _gen++;
            _load();
          }),
        ),
        if (all.isNotEmpty && rows.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucune demande ne correspond à ces filtres.')),
        if (all.isEmpty)
          EmptyState(
            icon: Icons.confirmation_number_outlined,
            title: widget.box == 'mine' ? 'Aucune demande' : 'Rien à traiter',
            message: widget.box == 'mine' ? 'Une panne, un besoin ? Ouvrez une demande vers le service concerné.' : 'Les tickets de votre service apparaîtront ici.',
          ),
        for (final t in rows) _TicketTile(ticket: t, onTap: () => widget.onOpen(t['id'] as int)),
      ]),
    );
  }
}

class _TicketTile extends StatelessWidget {
  const _TicketTile({required this.ticket, required this.onTap});

  final Map<String, dynamic> ticket;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final t = ticket;
    final d = asMap(t['durations']);
    final hint = Theme.of(context).hintColor;
    final active = !['done', 'closed', 'cancelled', 'rejected'].contains(t['status']);
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(width: 4, height: 48, decoration: BoxDecoration(color: urgencyColor(t['urgency']), borderRadius: BorderRadius.circular(4))),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Text(tr('${t['number']}'), style: TextStyle(color: hint, fontSize: 12, fontWeight: FontWeight.w600)),
                  const SizedBox(width: 8),
                  if (t['purchase_requested'] == true && t['status'] == 'on_hold') const Icon(Icons.shopping_cart_outlined, size: 14, color: YColors.warning),
                  if (d['late'] == true) const Padding(padding: EdgeInsets.only(left: 4), child: Icon(Icons.alarm, size: 14, color: YColors.danger)),
                ]),
                Text(tr('${t['subject']}'), style: const TextStyle(fontWeight: FontWeight.w600), maxLines: 1, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 2),
                Text(
                  tr([
                    '${t['requester']?['name'] ?? ''}${t['requester_department'] != null ? ' (${t['requester_department']['name']})' : ''} › ${t['department']?['name'] ?? ''}',
                    if (t['assignee'] != null) 'pris par ${t['assignee']['name']}',
                    if (t['category'] != null) '${t['category']}',
                  ].join(' · ')),
                  style: TextStyle(color: hint, fontSize: 13),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ]),
            ),
            const SizedBox(width: 10),
            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              StatusChip('${t['status_label']}', color: ticketStatusColor(t['status'])),
              const SizedBox(height: 4),
              Text(
                tr(active ? 'depuis ${durationFr(d['elapsed'] as num?)}' : 'en ${durationFr((d['resolution'] ?? d['elapsed']) as num?)}'),
                style: TextStyle(fontSize: 12, color: d['late'] == true && active ? YColors.danger : hint),
              ),
            ]),
          ]),
        ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Nouvelle demande
// ---------------------------------------------------------------------------

Future<int?> newTicket(BuildContext context, Map<String, dynamic> setup) {
  return showFormDialog<int>(context, title: tr('Nouvelle demande'), builder: (c) => _NewTicket(setup: setup));
}

class _NewTicket extends StatefulWidget {
  const _NewTicket({required this.setup});

  final Map<String, dynamic> setup;

  @override
  State<_NewTicket> createState() => _NewTicketState();
}

class _NewTicketState extends State<_NewTicket> {
  int? _dep;
  String? _category;
  String _urgency = 'normal';
  int? _assignee;
  final _subject = TextEditingController();
  final _desc = TextEditingController();
  final List<PlatformFile> _files = [];
  bool _busy = false;

  List<Map<String, dynamic>> get _deps => List<Map<String, dynamic>>.from((widget.setup['departments'] as List).map((e) => Map<String, dynamic>.from(e)))
      .where((d) => d['accepts_tickets'] == true && d['active'] != false)
      .toList();

  Future<void> _send() async {
    if (_dep == null || _subject.text.trim().isEmpty) return toast(context, tr('Choisissez le service et indiquez l\'objet.'), error: true);
    setState(() => _busy = true);
    final api = context.read<Session>().api;
    try {
      final r = await api.post('tickets/tickets', {
        'department_id': _dep,
        'category': _category,
        'urgency': _urgency,
        'subject': _subject.text.trim(),
        'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
        'assignee_id': _assignee,
      });
      final id = r['ticket']['id'] as int;
      for (final f in _files) {
        await api.upload('tickets/tickets/$id/files', 'file', await f.readAsBytes(), f.name);
      }
      if (mounted) Navigator.pop(context, id);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final dep = _deps.where((d) => d['id'] == _dep).firstOrNull;
    final cats = List<String>.from(dep?['categories'] ?? const []);
    final members = List<Map<String, dynamic>>.from((dep?['members'] as List? ?? const []).map((e) => Map<String, dynamic>.from(e)));
    final hint = Theme.of(context).hintColor;
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Field(
        label: tr('Service destinataire'),
        child: DropdownButtonFormField<int>(
          isExpanded: true,
          initialValue: _dep,
          items: [for (final d in _deps) DropdownMenuItem(value: d['id'] as int, child: Text(tr('${d['name']}')))],
          onChanged: (v) => setState(() => (_dep = v, _category = null, _assignee = null)),
        ),
      ),
      if (cats.isNotEmpty)
        Field(
          label: tr('Type de demande'),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final c in cats) ChoiceChip(label: Text(tr(c)), selected: _category == c, onSelected: (s) => setState(() => _category = s ? c : null)),
          ]),
        ),
      Field(
        label: tr('Urgence'),
        child: SegmentedButton<String>(
          showSelectedIcon: false,
          segments: [for (final e in ticketUrgencies.entries) ButtonSegment(value: e.key, label: Text(tr(e.value)))],
          selected: {_urgency},
          onSelectionChanged: (s) => setState(() => _urgency = s.first),
        ),
      ),
      if (dep != null)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Text(tr('Délai cible : ${dep['sla']?[_urgency] ?? '—'} h'), style: TextStyle(color: hint, fontSize: 12)),
        ),
      Field(label: tr('Objet'), child: TextField(controller: _subject, decoration: InputDecoration(hintText: tr('Ex. Mon imprimante ne démarre plus')))),
      Field(label: tr('Message'), child: TextField(controller: _desc, maxLines: 5, decoration: InputDecoration(hintText: tr('Décrivez le besoin : où, depuis quand, ce que vous attendez…')))),
      if (members.isNotEmpty)
        Field(
          label: tr('Adresser à (facultatif)'),
          child: DropdownButtonFormField<int?>(
            isExpanded: true,
            initialValue: _assignee,
            items: [
              DropdownMenuItem(value: null, child: Text(tr('Le service (le chef attribuera)'))),
              for (final m in members) DropdownMenuItem(value: m['user_id'] as int, child: Text(tr('${m['name']} — ${memberRoles[m['role']] ?? m['role']}'))),
            ],
            onChanged: (v) => setState(() => _assignee = v),
          ),
        ),
      Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
        OutlinedButton.icon(
          onPressed: () async {
            final r = await FilePicker.pickFiles();
            if (r.isNotEmpty) setState(() => _files.addAll(r));
          },
          icon: const Icon(Icons.attach_file),
          label: Text(tr('Joindre un fichier')),
        ),
        for (final f in _files) InputChip(label: Text(tr(f.name)), onDeleted: () => setState(() => _files.remove(f))),
      ]),
      const SizedBox(height: 20),
      FilledButton.icon(onPressed: _busy ? null : _send, icon: const Icon(Icons.send), label: Text(tr(_busy ? 'Envoi…' : 'Envoyer la demande'))),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Détail d'un ticket
// ---------------------------------------------------------------------------

class TicketDetailScreen extends StatefulWidget {
  const TicketDetailScreen({super.key, required this.ticketId});

  final int ticketId;

  @override
  State<TicketDetailScreen> createState() => _TicketDetailScreenState();
}

class _TicketDetailScreenState extends State<TicketDetailScreen> {
  Map<String, dynamic>? _d;
  Object? _error;
  bool _busy = false;
  bool _internal = false;
  final _comment = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.get('tickets/tickets/${widget.ticketId}'));
      if (mounted) setState(() => (_d = r, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _post(String path, [Map<String, dynamic>? body]) async {
    setState(() => _busy = true);
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.post('tickets/tickets/${widget.ticketId}/$path', body ?? {}));
      if (mounted) setState(() => _d = r);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<String?> _ask(String title, String label, {bool required = true, bool purchase = false, ValueChanged<bool>? onPurchase}) {
    final c = TextEditingController();
    var p = false;
    return showDialog<String>(
      context: context,
      builder: (dc) => StatefulBuilder(
        builder: (dc, set) => YAlertDialog(
          title: Text(tr(title)),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: c, autofocus: true, maxLines: 3, decoration: InputDecoration(labelText: trn(label))),
            if (purchase)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: p,
                onChanged: (v) => set(() {
                  p = v == true;
                  onPurchase?.call(p);
                }),
                title: Text(tr('Une demande d\'achat de matériel a été faite')),
              ),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(dc), child: Text(tr('Annuler'))),
            FilledButton(
              onPressed: () {
                if (required && c.text.trim().isEmpty) return;
                Navigator.pop(dc, c.text.trim());
              },
              child: Text(tr('Valider')),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _assign(List<Map<String, dynamic>> members) async {
    final id = await showModalBottomSheet<int>(
      context: context,
      builder: (c) => SafeArea(
        child: ListView(shrinkWrap: true, children: [
          ListTile(title: Text(tr('Attribuer à'), style: TextStyle(fontWeight: FontWeight.w700))),
          for (final m in members)
            ListTile(leading: InitialsAvatar('${m['name']}', size: 34), title: Text(tr('${m['name']}')), subtitle: Text(tr('${m['role_label']}')), onTap: () => Navigator.pop(c, m['user_id'] as int)),
        ]),
      ),
    );
    if (id != null) await _post('assign', {'assignee_id': id});
  }

  Future<void> _close() async {
    var rating = 5;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Confirmer la résolution')),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(tr('Votre demande a-t-elle été bien traitée ?')),
            const SizedBox(height: 12),
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              for (var i = 1; i <= 5; i++) IconButton(onPressed: () => set(() => rating = i), icon: Icon(i <= rating ? Icons.star_rounded : Icons.star_outline_rounded, color: YColors.yellow, size: 32)),
            ]),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Clôturer'))),
          ],
        ),
      ),
    );
    if (ok == true) await _post('close', {'rating': rating});
  }

  @override
  Widget build(BuildContext context) {
    final api = context.read<Session>().api;
    Widget body;
    if (_error != null && _d == null) {
      body = OnlineOnlyNotice(error: _error!, onRetry: _load);
    } else if (_d == null) {
      body = const Center(child: CircularProgressIndicator());
    } else {
      final t = Map<String, dynamic>.from(_d!['ticket']);
      final can = Map<String, dynamic>.from(_d!['can']);
      final dur = asMap(t['durations']);
      final events = List<Map<String, dynamic>>.from((_d!['events'] as List).map((e) => Map<String, dynamic>.from(e)));
      final files = List<Map<String, dynamic>>.from((_d!['files'] as List).map((e) => Map<String, dynamic>.from(e)));
      final members = List<Map<String, dynamic>>.from((_d!['members'] as List).map((e) => Map<String, dynamic>.from(e)));
      final s = '${t['status']}';
      final handle = can['handle'] == true, manage = can['manage'] == true, req = can['requester'] == true;
      final hint = Theme.of(context).hintColor;
      final active = ['submitted', 'open', 'accepted', 'in_progress', 'on_hold'].contains(s);
      var purchase = false;

      final actions = <Widget>[
        if (manage && active) OutlinedButton.icon(onPressed: _busy ? null : () => _assign(members), icon: const Icon(Icons.person_add_alt), label: Text(tr(t['assignee'] == null ? 'Attribuer' : 'Réattribuer'))),
        if (handle && ['submitted', 'open'].contains(s)) FilledButton.icon(onPressed: _busy ? null : () => _post('accept'), icon: const Icon(Icons.check), label: Text(tr('Accepter'))),
        if (handle && ['submitted', 'open', 'accepted'].contains(s)) FilledButton.tonalIcon(onPressed: _busy ? null : () => _post('start'), icon: const Icon(Icons.play_arrow), label: Text(tr('Démarrer'))),
        if (handle && ['accepted', 'in_progress'].contains(s))
          OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final r = await _ask('Mettre en attente', 'Raison (ex. soumission d\'achat de matériel : réglé dès réception)', purchase: true, onPurchase: (v) => purchase = v);
                    if (r != null) await _post('hold', {'reason': r, 'purchase': purchase});
                  },
            icon: const Icon(Icons.pause),
            label: Text(tr('En attente')),
          ),
        if (handle && s == 'on_hold') FilledButton.tonalIcon(onPressed: _busy ? null : () => _post('resume'), icon: const Icon(Icons.play_arrow), label: Text(tr('Reprendre'))),
        if (handle && active)
          FilledButton.icon(
            style: FilledButton.styleFrom(backgroundColor: YColors.success),
            onPressed: _busy
                ? null
                : () async {
                    final r = await _ask('Terminer le ticket', 'Ce qui a été fait (visible par le demandeur)', required: false);
                    if (r != null) await _post('done', {'resolution': r.isEmpty ? null : r});
                  },
            icon: const Icon(Icons.task_alt),
            label: Text(tr('Terminer')),
          ),
        if (manage && ['submitted', 'open'].contains(s))
          TextButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final r = await _ask('Refuser la demande', 'Motif');
                    if (r != null) await _post('reject', {'reason': r});
                  },
            icon: const Icon(Icons.block, color: YColors.danger),
            label: Text(tr('Refuser'), style: TextStyle(color: YColors.danger)),
          ),
        if ((req || manage) && s == 'done') FilledButton.icon(onPressed: _busy ? null : _close, icon: const Icon(Icons.verified), label: Text(tr('Confirmer et clôturer'))),
        if (req && ['done', 'closed'].contains(s))
          OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final r = await _ask('Rouvrir la demande', 'Pourquoi ?');
                    if (r != null) await _post('reopen', {'comment': r});
                  },
            icon: const Icon(Icons.replay),
            label: Text(tr('Rouvrir')),
          ),
        if (req && active)
          TextButton(
            onPressed: _busy
                ? null
                : () async {
                    if (await confirm(context, tr('Annuler la demande ?'), tr('Le service sera prévenu.'), ok: tr('Annuler la demande'), danger: true)) await _post('cancel');
                  },
            child: Text(tr('Annuler ma demande')),
          ),
      ];

      Widget step(String label, dynamic at, {String? extra}) {
        final d = DateTime.tryParse('${at ?? ''}')?.toLocal();
        return Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            Icon(d != null ? Icons.check_circle : Icons.radio_button_unchecked, size: 18, color: d != null ? YColors.success : hint),
            const SizedBox(width: 8),
            Expanded(child: Text(tr(label))),
            Text(tr(d == null ? '—' : DateFormat('dd/MM HH:mm').format(d)), style: TextStyle(color: hint)),
            if (extra != null) SizedBox(width: 90, child: Text(tr(extra), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
          ]),
        );
      }

      final info = SectionCard(
        title: tr('Délais'),
        child: Column(children: [
          step('Soumis', t['submitted_at']),
          step('Ouvert / attribué', t['opened_at']),
          step('Accepté (prise en charge)', t['accepted_at'], extra: durationFr(dur['response'] as num?)),
          step('Démarré', t['started_at']),
          if ((dur['hold'] as num? ?? 0) > 0 || t['hold_since'] != null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(children: [
                const Icon(Icons.pause_circle, size: 18, color: YColors.warning),
                const SizedBox(width: 8),
                Expanded(child: Text(tr(t['hold_since'] != null ? 'En attente actuellement' : 'Temps d\'attente'))),
                Text(tr(durationFr(dur['hold'] as num?)), style: const TextStyle(fontWeight: FontWeight.w600)),
              ]),
            ),
          step('Terminé', t['done_at'], extra: dur['resolution'] == null ? null : durationFr(dur['resolution'] as num?)),
          step('Clôturé par le demandeur', t['closed_at']),
          const Divider(),
          Row(children: [
            Expanded(child: Text(tr('Délai cible : ${dateTimeFr(t['due_at'])}'), style: TextStyle(color: dur['late'] == true ? YColors.danger : hint))),
            if (dur['work'] != null) Text(tr('Traitement effectif : ${durationFr(dur['work'] as num?)}'), style: const TextStyle(fontWeight: FontWeight.w600)),
          ]),
          if (t['rating'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(children: [for (var i = 1; i <= 5; i++) Icon(i <= (t['rating'] as int) ? Icons.star_rounded : Icons.star_outline_rounded, color: YColors.yellow)]),
            ),
        ]),
      );

      final history = SectionCard(
        title: tr('Échanges et historique'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          for (final e in events) _EventTile(event: e),
          const SizedBox(height: 8),
          TextField(controller: _comment, maxLines: 3, minLines: 1, decoration: InputDecoration(hintText: tr('Écrire un message…'))),
          const SizedBox(height: 8),
          Row(children: [
            if (handle)
              Expanded(
                child: CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  controlAffinity: ListTileControlAffinity.leading,
                  dense: true,
                  value: _internal,
                  onChanged: (v) => setState(() => _internal = v == true),
                  title: Text(tr('Note interne (invisible pour le demandeur)')),
                ),
              )
            else
              const Spacer(),
            FilledButton.icon(
              onPressed: _busy
                  ? null
                  : () async {
                      if (_comment.text.trim().isEmpty) return;
                      await _post('comments', {'body': _comment.text.trim(), 'internal': _internal});
                      _comment.clear();
                    },
              icon: const Icon(Icons.send),
              label: Text(tr('Envoyer')),
            ),
          ]),
        ]),
      );

      final filesCard = SectionCard(
        title: tr('Pièces jointes'),
        trailing: TextButton.icon(
          onPressed: () async {
            final r = await FilePicker.pickFiles();
            if (r.isEmpty) return;
            try {
              await api.upload('tickets/tickets/${widget.ticketId}/files', 'file', await r.first.readAsBytes(), r.first.name);
              _load();
            } catch (e) {
              if (context.mounted) toast(context, errorText(e), error: true);
            }
          },
          icon: const Icon(Icons.attach_file),
          label: Text(tr('Ajouter')),
        ),
        child: files.isEmpty
            ? Text(tr('Aucune pièce jointe.'), style: TextStyle(color: hint))
            : Column(children: [
                for (final f in files)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.insert_drive_file_outlined),
                    title: Text(tr('${f['name']}')),
                    subtitle: Text(tr('${((f['size'] as num) / 1024).round()} Ko · ${dateTimeFr(f['created_at'])}')),
                    trailing: const Icon(Icons.download),
                    onTap: () async {
                      final bytes = await api.download('tickets/tickets/${widget.ticketId}/files/${f['id']}');
                      final where = await saveFile('${f['name']}', bytes);
                      if (context.mounted) toast(context, tr('Enregistré : $where'));
                    },
                  ),
              ]),
      );

      body = RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: const EdgeInsets.all(24), children: [
          Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            StatusChip('${t['status_label']}', color: ticketStatusColor(s)),
            StatusChip('Urgence ${t['urgency_label']}'.toLowerCase().replaceFirst('u', 'U'), color: urgencyColor(t['urgency'])),
            if (t['category'] != null) StatusChip('${t['category']}'),
            if (t['purchase_requested'] == true && s == 'on_hold') StatusChip(tr('Achat de matériel demandé'), color: YColors.warning),
            if (dur['late'] == true) StatusChip(tr('Hors délai'), color: YColors.danger),
          ]),
          const SizedBox(height: 10),
          Text(tr('${t['subject']}'), style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          Text(
            tr('${t['requester']?['name']}${t['requester_department'] != null ? ' (${t['requester_department']['name']})' : ''} › ${t['department']?['name']}'
            '${t['assignee'] != null ? ' · traité par ${t['assignee']['name']}' : ' · non attribué'} · ouvert il y a ${_ago(t['submitted_at'])}'),
            style: TextStyle(color: hint),
          ),
          if (t['description'] != null) ...[const SizedBox(height: 14), Text(tr('${t['description']}'), style: const TextStyle(height: 1.4))],
          if (t['hold_reason'] != null && s == 'on_hold')
            Card(color: noticeFill(context), shape: noticeShape(context), margin: const EdgeInsets.only(top: 12), child: Padding(padding: const EdgeInsets.all(12), child: Text(tr('En attente : ${t['hold_reason']}')))),
          if (t['resolution'] != null)
            Card(color: YColors.success.withValues(alpha: .1), margin: const EdgeInsets.only(top: 12), child: Padding(padding: const EdgeInsets.all(12), child: Text(tr('Résolution : ${t['resolution']}')))),
          const SizedBox(height: 16),
          if (actions.isNotEmpty) Wrap(spacing: 8, runSpacing: 8, children: actions),
          const SizedBox(height: 16),
          LayoutBuilder(
            builder: (context, c) => c.maxWidth > 980
                ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Expanded(flex: 3, child: history),
                    const SizedBox(width: 16),
                    Expanded(flex: 2, child: Column(children: [info, const SizedBox(height: 16), filesCard])),
                  ])
                : Column(children: [info, const SizedBox(height: 16), history, const SizedBox(height: 16), filesCard]),
          ),
        ]),
      );
    }
    return Scaffold(appBar: AppBar(title: Text(tr(_d == null ? 'Ticket' : '${_d!['ticket']['number']}'))), body: body);
  }
}

class _EventTile extends StatelessWidget {
  const _EventTile({required this.event});

  final Map<String, dynamic> event;

  @override
  Widget build(BuildContext context) {
    final e = event;
    final hint = Theme.of(context).hintColor;
    final who = '${e['user']?['name'] ?? 'Système'}';
    final when = dateTimeFr(e['created_at']);
    final type = '${e['type']}';
    if (type == 'comment' || type == 'note') {
      final note = type == 'note';
      return Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: note ? YColors.yellow.withValues(alpha: .15) : Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .5), borderRadius: BorderRadius.circular(10)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr('$who · $when${note ? ' · note interne' : ''}'), style: TextStyle(fontSize: 12, color: hint, fontWeight: FontWeight.w600)),
          const SizedBox(height: 4),
          Text(tr('${e['body']}')),
        ]),
      );
    }
    final label = switch (type) {
      'created' => 'a ouvert la demande',
      'assign' => 'a attribué le ticket',
      'hold' => 'a mis en attente${e['meta']?['purchase'] == true ? ' (achat de matériel)' : ''}',
      _ => '› ${ticketStatuses[e['to_status']] ?? e['to_status']}',
    };
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(type == 'hold' ? Icons.pause_circle_outline : Icons.circle, size: type == 'hold' ? 16 : 8, color: ticketStatusColor(e['to_status'] as String?)),
        const SizedBox(width: 10),
        Expanded(
          child: Text.rich(TextSpan(children: [
            TextSpan(text: '$who ', style: const TextStyle(fontWeight: FontWeight.w600)),
            TextSpan(text: label),
            if (e['body'] != null && type != 'created') TextSpan(text: ' — ${e['body']}', style: TextStyle(color: hint)),
          ])),
        ),
        Text(tr(when), style: TextStyle(fontSize: 12, color: hint)),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Statistiques
// ---------------------------------------------------------------------------

class _StatsTab extends StatefulWidget {
  const _StatsTab({required this.setup});

  final Map<String, dynamic> setup;

  @override
  State<_StatsTab> createState() => _StatsTabState();
}

class _StatsTabState extends State<_StatsTab> {
  String _period = 'month';
  int? _department;
  Map<String, dynamic>? _d;
  Object? _error;

  (DateTime, DateTime) get _range {
    final n = DateTime.now();
    final today = DateTime(n.year, n.month, n.day);
    return switch (_period) {
      'day' => (today, today),
      'week' => (today.subtract(Duration(days: today.weekday - 1)), today),
      'last_month' => (DateTime(n.year, n.month - 1), DateTime(n.year, n.month, 0)),
      'year' => (DateTime(n.year), today),
      _ => (DateTime(n.year, n.month), today),
    };
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final (f, t) = _range;
    try {
      final r = await context.read<Session>().api.get('tickets/stats', query: {'from': isoDate(f), 'to': isoDate(t), 'department_id': ?_department?.toString()});
      if (mounted) setState(() => (_d = Map<String, dynamic>.from(r), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _d == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_d == null) return const Center(child: CircularProgressIndicator());
    final d = _d!;
    final k = Map<String, dynamic>.from(d['kpi']);
    final deps = List<Map<String, dynamic>>.from((d['departments'] as List? ?? const []).map((e) => Map<String, dynamic>.from(e)));
    final series = List<Map<String, dynamic>>.from((d['series'] as List).map((e) => Map<String, dynamic>.from(e)));
    final ranking = List<Map<String, dynamic>>.from((d['ranking'] as List).map((e) => Map<String, dynamic>.from(e)));
    final hint = Theme.of(context).hintColor;
    final maxDay = series.fold<int>(1, (m, s) => [m, s['received'] as int, s['done'] as int].reduce((a, b) => a > b ? a : b));

    Widget kpi(String label, String value, IconData icon, {Color color = YColors.blue}) => KpiBox(
          width: 220,
          child: StatTile(label: label, value: value, icon: icon, color: color),
        );

    Widget counts(String title, List rows) => SectionCard(
          title: title,
          child: rows.isEmpty
              ? Text('—', style: TextStyle(color: hint))
              : Column(children: [
                  for (final r in rows.cast<Map>())
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 3),
                      child: Row(children: [Expanded(child: Text(tr('${r['label'] ?? r['name']}'))), Text(tr('${r['count'] ?? r['received']}'), style: const TextStyle(fontWeight: FontWeight.w600))]),
                    ),
                ]),
        );

    Future<void> print() => printReport(
          company: '${context.read<Session>().company['name'] ?? ''}',
          title: tr('Statistiques des demandes internes'),
          period: 'Du ${dateFr(d['from'])} au ${dateFr(d['to'])}',
          headers: const ['Indicateur', 'Valeur'],
          sections: [
            ReportSection(rows: [
              ['Demandes reçues', '${k['received']}'],
              ['Demandes traitées', '${k['done']}'],
              ['En cours', '${k['backlog']}'],
              ['En retard', '${k['late']}'],
              ['Prise en charge moyenne', durationFr(k['avg_response'] as num?)],
              ['Résolution moyenne', durationFr(k['avg_resolution'] as num?)],
              ['Délais respectés', k['sla'] == null ? '—' : '${k['sla']} %'],
              ['Satisfaction', k['rating'] == null ? '—' : '${k['rating']} / 5'],
            ]),
            ReportSection(title: tr('Classement des collaborateurs'), rows: [
              for (final (i, r) in ranking.indexed) ['${i + 1}. ${r['name']} — ${r['done']} ticket(s)', '${durationFr(r['avg_work'] as num?)} · ${r['sla']} % · score ${r['score']}'],
            ]),
          ],
        );

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
        Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          for (final e in const {'day': 'Aujourd\'hui', 'week': 'Cette semaine', 'month': 'Ce mois', 'last_month': 'Mois dernier', 'year': 'Cette année'}.entries)
            ChoiceChip(label: Text(tr(e.value)), selected: _period == e.key, onSelected: (_) => setState(() => (_period = e.key, _load()))),
          if (deps.length > 1)
            SizedBox(
              width: 240,
              child: DropdownButtonFormField<int?>(
                isExpanded: true,
                isDense: true,
                initialValue: _department,
                decoration: const InputDecoration(contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8)),
                items: [DropdownMenuItem(value: null, child: Text(tr('Tous les services'))), for (final x in deps) DropdownMenuItem(value: x['id'] as int, child: Text(tr('${x['name']}')))],
                onChanged: (v) => setState(() => (_department = v, _load())),
              ),
            ),
          IconButton(tooltip: tr('Imprimer / PDF'), onPressed: print, icon: const Icon(Icons.print_outlined)),
        ]),
        const SizedBox(height: 12),
        KpiWrap(children: [
          kpi('Reçues', '${k['received']}', Icons.inbox_outlined),
          kpi('Traitées', '${k['done']}', Icons.task_alt, color: YColors.success),
          kpi('En cours', '${k['backlog']}', Icons.pending_actions_outlined, color: const Color(0xFF7C3AED)),
          kpi('En retard', '${k['late']}', Icons.alarm, color: (k['late'] as num) > 0 ? YColors.danger : YColors.success),
          kpi('Prise en charge', durationFr(k['avg_response'] as num?), Icons.timer_outlined),
          kpi('Résolution', durationFr(k['avg_resolution'] as num?), Icons.timelapse),
          kpi('Délais respectés', k['sla'] == null ? '—' : '${k['sla']} %', Icons.verified_outlined, color: YColors.success),
          kpi('Satisfaction', k['rating'] == null ? '—' : '${k['rating']} / 5', Icons.star_outline, color: YColors.warning),
          if ((k['purchase_waiting'] as num) > 0) kpi('Attente achat', '${k['purchase_waiting']}', Icons.shopping_cart_outlined, color: YColors.warning),
        ]),
        const SizedBox(height: 16),
        if (series.length > 1)
          SectionCard(
            title: tr('Reçues / traitées par jour'),
            child: SizedBox(
              height: 140,
              child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                for (final s in series)
                  Expanded(
                    child: Tooltip(
                      message: tr('${dateFr(s['date'])} : ${s['received']} reçue(s), ${s['done']} traitée(s)'),
                      child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                        Expanded(child: Container(height: 4 + 120 * (s['received'] as int) / maxDay, margin: const EdgeInsets.symmetric(horizontal: .5), color: YColors.blue)),
                        Expanded(child: Container(height: 4 + 120 * (s['done'] as int) / maxDay, margin: const EdgeInsets.symmetric(horizontal: .5), color: YColors.success)),
                      ]),
                    ),
                  ),
              ]),
            ),
          ),
        const SizedBox(height: 16),
        SectionCard(
          title: tr('Classement des collaborateurs'),
          trailing: d['fastest'] != null ? StatusChip(tr('Le plus rapide : ${d['fastest']['name']}'), color: YColors.success) : null,
          child: ranking.isEmpty
              ? Text(tr('Aucun ticket terminé sur la période.'), style: TextStyle(color: hint))
              : SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: DataTable(columns: [
                    DataColumn(label: Text('#')),
                    DataColumn(label: Text(tr('Collaborateur'))),
                    DataColumn(label: Text(tr('Traités')), numeric: true),
                    DataColumn(label: Text(tr('Prise en charge')), numeric: true),
                    DataColumn(label: Text(tr('Traitement')), numeric: true),
                    DataColumn(label: Text(tr('Délais respectés')), numeric: true),
                    DataColumn(label: Text(tr('Satisfaction')), numeric: true),
                    DataColumn(label: Text(tr('Score')), numeric: true),
                  ], rows: [
                    for (final (i, r) in ranking.indexed)
                      DataRow(cells: [
                        DataCell(i < 3 ? Icon(Icons.emoji_events, color: [YColors.yellow, Colors.grey, const Color(0xFFCD7F32)][i]) : Text(tr('${i + 1}'))),
                        DataCell(Text(tr('${r['name']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
                        DataCell(Text(tr('${r['done']}'))),
                        DataCell(Text(tr(durationFr(r['avg_response'] as num?)))),
                        DataCell(Text(tr(durationFr(r['avg_work'] as num?)))),
                        DataCell(Text(tr('${r['sla']} %'))),
                        DataCell(Text(tr(r['rating'] == null ? '—' : '${r['rating']} / 5'))),
                        DataCell(Text(tr('${r['score']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                      ]),
                  ]),
                ),
        ),
        const SizedBox(height: 16),
        LayoutBuilder(builder: (context, c) {
          final cards = [
            counts('Par type de demande', d['by_category'] as List),
            counts('Par urgence', d['by_urgency'] as List),
            counts('Par service', d['by_department'] as List),
          ];
          if (c.maxWidth < 900) return Column(children: [for (final w in cards) Padding(padding: const EdgeInsets.only(bottom: 16), child: w)]);
          return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [for (var i = 0; i < cards.length; i++) ...[if (i > 0) const SizedBox(width: 16), Expanded(child: cards[i])]]);
        }),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Rapports automatiques
// ---------------------------------------------------------------------------

class _ReportsTab extends StatefulWidget {
  const _ReportsTab();

  @override
  State<_ReportsTab> createState() => _ReportsTabState();
}

class _ReportsTabState extends State<_ReportsTab> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;
  String _kind = '';
  String _q = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('tickets/reports');
      if (mounted) setState(() => (_rows = List<Map<String, dynamic>>.from((r['data'] as List).map((e) => Map<String, dynamic>.from(e))), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _rows == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    if (_rows == null) return const Center(child: CircularProgressIndicator());
    final s = context.read<Session>();
    final shown = _rows!
        .where((r) => _kind.isEmpty || (_kind == 'month') == (r['period'] == 'month'))
        .where((r) => _q.isEmpty || '${r['department']} ${r['summary'] ?? ''}'.toLowerCase().contains(_q))
        .where((r) => _f['department'] == null || '${r['department']}' == _f['department'])
        .where((r) => _period == null || _period!.contains(r['from']) || _period!.contains(r['to']))
        .toList();
    return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
      Row(children: [
        Expanded(child: Text(tr('Un rapport est produit automatiquement chaque lundi (semaine écoulée) et le 1er du mois, pour chaque service et pour toute l\'entreprise.'), style: TextStyle(color: Theme.of(context).hintColor))),
        if (s.can('tickets.manage') || s.can('tickets.view_all'))
          OutlinedButton.icon(
            onPressed: () async {
              try {
                await s.api.post('tickets/reports/generate', {'period': 'week'});
                _load();
              } catch (e) {
                if (context.mounted) toast(context, errorText(e), error: true);
              }
            },
            icon: const Icon(Icons.auto_awesome),
            label: Text(tr('Générer maintenant')),
          ),
      ]),
      const SizedBox(height: 12),
      if (_rows!.isNotEmpty)
        FilterBar(
          key: ValueKey(_gen),
          padding: const EdgeInsets.only(bottom: 12),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Service, contenu…',
          statuses: const {'': 'Tous', 'week': 'Hebdomadaires', 'month': 'Mensuels'},
          status: _kind,
          onStatus: (v) => setState(() => _kind = v),
          statusCounts: {for (final k in const ['', 'week', 'month']) k: _rows!.where((r) => k.isEmpty || (k == 'month') == (r['period'] == 'month')).length},
          filters: [FilterSpec('department', 'Service', filterOptions(_rows!, 'department'), icon: Icons.apartment_outlined)],
          values: _f,
          onFilter: (k, v) => setState(() => _f[k] = v),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: shown.length,
          countLabel: 'rapport',
          onReset: () => setState(() {
            _kind = '';
            _q = '';
            _f.clear();
            _period = null;
            _gen++;
          }),
        ),
      if (_rows!.isEmpty) EmptyState(icon: Icons.summarize_outlined, title: tr('Pas encore de rapport')),
      for (final r in shown)
        Card(
          child: ListTile(
            leading: const CircleAvatar(child: Icon(Icons.summarize_outlined)),
            title: Text(tr('${r['department']} · ${r['period'] == 'month' ? 'mois' : 'semaine'} du ${dateFr(r['from'])} au ${dateFr(r['to'])}'), style: const TextStyle(fontWeight: FontWeight.w600)),
            subtitle: Text(tr('${r['summary'] ?? ''}'.split('\n').skip(1).take(2).join(' ')), maxLines: 2, overflow: TextOverflow.ellipsis),
            onTap: () async {
              final full = Map<String, dynamic>.from(await s.api.get('tickets/reports/${r['id']}'));
              if (!context.mounted) return;
              final k = Map<String, dynamic>.from(full['data']['kpi']);
              await showFormDialog(context, title: tr('Rapport ${full['department']}'), builder: (c) => ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), shrinkWrap: true, children: [
                    SelectableText(tr('${full['summary']}'), style: const TextStyle(height: 1.5)),
                    const SizedBox(height: 16),
                    FilledButton.icon(
                      onPressed: () => printReport(
                        company: '${s.company['name'] ?? ''}',
                        title: tr('Rapport des demandes — ${full['department']}'),
                        period: 'Du ${dateFr(full['from'])} au ${dateFr(full['to'])}',
                        headers: const ['Indicateur', 'Valeur'],
                        sections: [
                          ReportSection(rows: [
                            ['Reçues', '${k['received']}'],
                            ['Traitées', '${k['done']}'],
                            ['En cours / en retard', '${k['backlog']} / ${k['late']}'],
                            ['Prise en charge moyenne', durationFr(k['avg_response'] as num?)],
                            ['Résolution moyenne', durationFr(k['avg_resolution'] as num?)],
                            ['Délais respectés', k['sla'] == null ? '—' : '${k['sla']} %'],
                          ]),
                          ReportSection(title: tr('Classement'), rows: [
                            for (final x in (full['data']['ranking'] as List)) ['${x['name']} — ${x['done']} ticket(s)', '${x['sla']} % · score ${x['score']}'],
                          ]),
                        ],
                        footnote: '${full['summary']}'.replaceAll('\n', ' '),
                      ),
                      icon: const Icon(Icons.print),
                      label: Text(tr('Imprimer / PDF')),
                    ),
                  ]));
            },
          ),
        ),
    ]);
  }
}

// ---------------------------------------------------------------------------
// Organisation : services, chefs, collaborateurs, hiérarchie
// ---------------------------------------------------------------------------

class _OrgTab extends StatelessWidget {
  const _OrgTab({required this.setup, required this.onChanged});

  final Map<String, dynamic> setup;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final deps = List<Map<String, dynamic>>.from((setup['departments'] as List).map((e) => Map<String, dynamic>.from(e)));
    final byParent = <int?, List<Map<String, dynamic>>>{};
    for (final d in deps) {
      byParent.putIfAbsent(d['parent_id'] as int?, () => []).add(d);
    }
    final ids = deps.map((d) => d['id']).toSet();
    final roots = deps.where((d) => d['parent_id'] == null || !ids.contains(d['parent_id'])).toList();
    final hint = Theme.of(context).hintColor;

    List<Widget> tree(Map<String, dynamic> d, int depth) => [
          Padding(
            padding: EdgeInsets.only(left: depth * 28.0, bottom: 8),
            child: Card(
              child: ListTile(
                leading: CircleAvatar(backgroundColor: _color(d['color']).withValues(alpha: .15), child: Text(tr('${d['code'] ?? '${d['name']}'.substring(0, 1)}'), style: TextStyle(color: _color(d['color']), fontWeight: FontWeight.w700, fontSize: 12))),
                title: Row(children: [
                  Flexible(child: Text(tr('${d['name']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                  if (d['active'] == false) Padding(padding: EdgeInsets.only(left: 8), child: StatusChip(tr('Archivé'))),
                  if (d['accepts_tickets'] != true) Padding(padding: EdgeInsets.only(left: 8), child: StatusChip(tr('Supervision'))),
                ]),
                subtitle: Text(
                  tr([
                    'Chef : ${d['head_name'] ?? '—'}',
                    '${(d['members'] as List).length} membre(s)',
                    if ((d['supervisor_ids'] as List? ?? const []).isNotEmpty) 'suivi aussi par ${(d['supervisor_ids'] as List).length} responsable(s)',
                  ].join(' · ')),
                  style: TextStyle(color: hint),
                ),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  TextButton.icon(onPressed: () => _members(context, d), icon: const Icon(Icons.group_outlined, size: 18), label: Text(tr('Équipe'))),
                  IconButton(onPressed: () => _edit(context, d, deps), icon: const Icon(Icons.edit_outlined)),
                ]),
              ),
            ),
          ),
          for (final c in byParent[d['id']] ?? const <Map<String, dynamic>>[]) ...tree(c, depth + 1),
        ];

    return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
      Row(children: [
        Expanded(
          child: Text(tr('Chaque chef voit les demandes reçues et envoyées par son service ; un service parent (DGA, direction) voit tous les services placés sous lui. Les « responsables qui suivent » (ex. RH) voient aussi le service.'),
              style: TextStyle(color: hint)),
        ),
        const SizedBox(width: 12),
        FilledButton.icon(onPressed: () => _edit(context, null, deps), icon: const Icon(Icons.add), label: Text(tr('Service'))),
      ]),
      const SizedBox(height: 16),
      for (final r in roots) ...tree(r, 0),
    ]);
  }

  static Color _color(dynamic c) {
    final s = '${c ?? ''}';
    return s.length == 7 && s.startsWith('#') ? Color(int.parse('FF${s.substring(1)}', radix: 16)) : YColors.blue;
  }

  Future<void> _edit(BuildContext context, Map<String, dynamic>? d, List<Map<String, dynamic>> deps) async {
    final changed = await showFormDialog<bool>(context, title: d == null ? 'Nouveau service' : '${d['name']}', builder: (c) => _DepartmentForm(dep: d, deps: deps, users: List<Map<String, dynamic>>.from((setup['users'] as List).map((e) => Map<String, dynamic>.from(e)))));
    if (changed == true) await onChanged();
  }

  Future<void> _members(BuildContext context, Map<String, dynamic> d) async {
    final changed = await showFormDialog<bool>(context, title: tr('Équipe · ${d['name']}'), builder: (c) => _MembersForm(dep: d, users: List<Map<String, dynamic>>.from((setup['users'] as List).map((e) => Map<String, dynamic>.from(e)))));
    if (changed == true) await onChanged();
  }
}

class _DepartmentForm extends StatefulWidget {
  const _DepartmentForm({required this.dep, required this.deps, required this.users});

  final Map<String, dynamic>? dep;
  final List<Map<String, dynamic>> deps;
  final List<Map<String, dynamic>> users;

  @override
  State<_DepartmentForm> createState() => _DepartmentFormState();
}

class _DepartmentFormState extends State<_DepartmentForm> {
  late final d = widget.dep ?? {};
  late final _name = TextEditingController(text: '${d['name'] ?? ''}');
  late final _code = TextEditingController(text: '${d['code'] ?? ''}');
  late final _cats = TextEditingController(text: (d['categories'] as List? ?? const []).join('\n'));
  late final Map<String, TextEditingController> _sla = {for (final u in ticketUrgencies.keys) u: TextEditingController(text: '${(d['sla'] as Map?)?[u] ?? const {'critical': 4, 'high': 24, 'normal': 72, 'low': 168}[u]}')};
  late int? _parent = d['parent_id'] as int?;
  late int? _head = d['head_user_id'] as int?;
  late final Set<int> _supervisors = {...List<int>.from(d['supervisor_ids'] ?? const [])};
  late bool _accepts = d['accepts_tickets'] != false;
  bool _busy = false;

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) return;
    setState(() => _busy = true);
    final api = context.read<Session>().api;
    final body = {
      'name': _name.text.trim(),
      'code': _code.text.trim().isEmpty ? null : _code.text.trim().toUpperCase(),
      'parent_id': _parent,
      'head_user_id': _head,
      'supervisor_ids': _supervisors.toList(),
      'accepts_tickets': _accepts,
      'categories': _cats.text.split('\n').map((e) => e.trim()).where((e) => e.isNotEmpty).toList(),
      'sla_hours': {for (final e in _sla.entries) e.key: int.tryParse(e.value.text) ?? 72},
    };
    try {
      widget.dep == null ? await api.post('tickets/departments', body) : await api.put('tickets/departments/${widget.dep!['id']}', body);
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final others = widget.deps.where((x) => x['id'] != widget.dep?['id']).toList();
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      Row(children: [
        Expanded(child: Field(label: tr('Nom du service'), child: TextField(controller: _name))),
        const SizedBox(width: 12),
        SizedBox(width: 100, child: Field(label: tr('Code'), child: TextField(controller: _code))),
      ]),
      Field(
        label: tr('Dépend de'),
        child: DropdownButtonFormField<int?>(
          isExpanded: true,
          initialValue: _parent,
          items: [DropdownMenuItem(value: null, child: Text(tr('— (sommet de l\'organisation)'))), for (final x in others) DropdownMenuItem(value: x['id'] as int, child: Text(tr('${x['name']}')))],
          onChanged: (v) => setState(() => _parent = v),
        ),
      ),
      Field(
        label: tr('Chef de service'),
        child: DropdownButtonFormField<int?>(
          isExpanded: true,
          initialValue: _head,
          items: [const DropdownMenuItem(value: null, child: Text('—')), for (final u in widget.users) DropdownMenuItem(value: u['id'] as int, child: Text(tr('${u['name']}')))],
          onChanged: (v) => setState(() => _head = v),
        ),
      ),
      Field(
        label: tr('Responsables qui suivent aussi ce service (DGA, RH…)'),
        child: Wrap(spacing: 6, runSpacing: 6, children: [
          for (final u in widget.users)
            FilterChip(label: Text(tr('${u['name']}')), selected: _supervisors.contains(u['id']), onSelected: (v) => setState(() => v ? _supervisors.add(u['id'] as int) : _supervisors.remove(u['id']))),
        ]),
      ),
      SwitchListTile(contentPadding: EdgeInsets.zero, value: _accepts, onChanged: (v) => setState(() => _accepts = v), title: Text(tr('Reçoit des demandes')), subtitle: Text(tr('Désactivez pour un service de supervision (ex. DGA)'))),
      Field(label: tr('Types de demandes (un par ligne)'), child: TextField(controller: _cats, maxLines: 5)),
      Text(tr('Délais cibles (heures)'), style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
      const SizedBox(height: 6),
      Row(children: [
        for (final e in ticketUrgencies.entries) ...[
          Expanded(child: TextField(controller: _sla[e.key], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: e.value, isDense: true))),
          const SizedBox(width: 8),
        ],
      ]),
      const SizedBox(height: 20),
      Row(children: [
        FilledButton(onPressed: _busy ? null : _save, child: Text(tr('Enregistrer'))),
        const Spacer(),
        if (widget.dep != null)
          TextButton.icon(
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer ce service ?'), tr('S\'il a déjà reçu des tickets, il sera archivé.'), ok: tr('Supprimer'), danger: true)) return;
              if (!context.mounted) return;
              await context.read<Session>().api.delete('tickets/departments/${widget.dep!['id']}');
              if (context.mounted) Navigator.pop(context, true);
            },
            icon: const Icon(Icons.delete_outline, color: YColors.danger),
            label: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
          ),
      ]),
    ]);
  }
}

class _MembersForm extends StatefulWidget {
  const _MembersForm({required this.dep, required this.users});

  final Map<String, dynamic> dep;
  final List<Map<String, dynamic>> users;

  @override
  State<_MembersForm> createState() => _MembersFormState();
}

class _MembersFormState extends State<_MembersForm> {
  late final List<Map<String, dynamic>> _m = [for (final x in (widget.dep['members'] as List)) {'user_id': x['user_id'], 'role': x['role'], 'name': x['name']}];
  int? _add;

  @override
  Widget build(BuildContext context) {
    final available = widget.users.where((u) => !_m.any((m) => m['user_id'] == u['id'])).toList();
    return ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), shrinkWrap: true, children: [
      for (final m in _m)
        Row(children: [
          Expanded(child: Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w600))),
          SizedBox(
            width: 190,
            child: DropdownButtonFormField<String>(
              isExpanded: true,
              isDense: true,
              initialValue: '${m['role']}',
              items: [for (final e in memberRoles.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
              onChanged: (v) => setState(() => m['role'] = v),
            ),
          ),
          IconButton(onPressed: () => setState(() => _m.remove(m)), icon: const Icon(Icons.close)),
        ]),
      const SizedBox(height: 12),
      Row(children: [
        Expanded(
          child: DropdownButtonFormField<int>(
            isExpanded: true,
            initialValue: _add,
            hint: Text(tr('Ajouter une personne')),
            items: [for (final u in available) DropdownMenuItem(value: u['id'] as int, child: Text(tr('${u['name']}')))],
            onChanged: (v) => setState(() => _add = v),
          ),
        ),
        const SizedBox(width: 8),
        OutlinedButton(
          onPressed: _add == null
              ? null
              : () => setState(() {
                    final u = widget.users.firstWhere((x) => x['id'] == _add);
                    _m.add({'user_id': u['id'], 'name': u['name'], 'role': 'member'});
                    _add = null;
                  }),
          child: Text(tr('Ajouter')),
        ),
      ]),
      const SizedBox(height: 20),
      FilledButton(
        onPressed: () async {
          try {
            await context.read<Session>().api.put('tickets/departments/${widget.dep['id']}/members', {
              'members': [for (final m in _m) {'user_id': m['user_id'], 'role': m['role']}],
            });
            if (context.mounted) Navigator.pop(context, true);
          } catch (e) {
            if (context.mounted) toast(context, errorText(e), error: true);
          }
        },
        child: Text(tr('Enregistrer l\'équipe')),
      ),
    ]);
  }
}
