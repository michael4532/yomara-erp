import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'logistique_screen.dart' show SignaturePad, SignaturePadState;
import 'portal_screen.dart' show openSalesDocument;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const itStatuses = {
  'requested': 'Demandée',
  'planned': 'Planifiée',
  'en_route': 'En route',
  'in_progress': 'En cours',
  'done': 'Terminée',
  'cancelled': 'Annulée',
};
const itColors = {
  'requested': YColors.warning,
  'planned': YColors.blue,
  'en_route': YColors.warning,
  'in_progress': YColors.warning,
  'done': YColors.success,
  'cancelled': YColors.muted,
};
const itTypes = {'corrective': 'Dépannage', 'preventive': 'Maintenance préventive', 'installation': 'Installation', 'other': 'Autre'};
const itPriorities = ['Basse', 'Normale', 'Haute', 'Urgente'];
const itPriorityColors = [YColors.muted, YColors.blue, YColors.warning, YColors.danger];
const contractFrequencies = {'none': 'Sans visite programmée', 'monthly': 'Mensuelle', 'quarterly': 'Trimestrielle', 'semiannual': 'Semestrielle', 'annual': 'Annuelle'};
const contractBillings = {'monthly': 'Mensuelle', 'quarterly': 'Trimestrielle', 'annual': 'Annuelle', 'none': 'Pas de facturation'};
const equipmentStatuses = {'active': 'En service', 'broken': 'En panne', 'retired': 'Retiré'};

DateTime? _dt(dynamic v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();
DateTime _day(DateTime d) => DateTime(d.year, d.month, d.day);
DateTime _addDays(DateTime d, int n) => DateTime(d.year, d.month, d.day + n);
String _hm(int? minutes) => minutes == null ? '—' : '${minutes ~/ 60} h ${(minutes % 60).toString().padLeft(2, '0')}';
String _phoneWa(String p) => p.replaceAll(RegExp(r'\D'), '').replaceFirst(RegExp(r'^0'), '2250');

/// Techniciens (cache local pour le hors ligne).
Future<List<Map<String, dynamic>>> interventionTechnicians(Session s) async {
  try {
    final r = await s.api.get('interventions/technicians');
    final users = List<Map<String, dynamic>>.from((r['technicians'] as List).map((e) => Map<String, dynamic>.from(e)));
    await LocalDb.state().put('it_technicians', users);
    return users;
  } catch (_) {
    return _cachedTechs();
  }
}

List<Map<String, dynamic>> _cachedTechs() => [for (final u in (LocalDb.state().get('it_technicians') as List? ?? const [])) asMap(u)];
String techName(dynamic id) => _cachedTechs().where((u) => u['id'] == id).map((u) => '${u['name']}').firstOrNull ?? '';
String partnerName(dynamic id) => '${LocalDb.get('partners', id as String?)?['name'] ?? ''}';

void openIntervention(BuildContext context, String id) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => InterventionScreen(id: id)));

// ======================================================================
// Écran principal
// ======================================================================

class InterventionsScreen extends StatefulWidget {
  const InterventionsScreen({super.key});

  @override
  State<InterventionsScreen> createState() => _InterventionsScreenState();
}

class _InterventionsScreenState extends State<InterventionsScreen> with SingleTickerProviderStateMixin {
  late final bool _office = context.read<Session>().can('interventions.view') || context.read<Session>().can('interventions.manage');
  late final TabController _tabs = TabController(length: 5, vsync: this);

  @override
  void initState() {
    super.initState();
    interventionTechnicians(context.read<Session>()).then((_) {
      if (mounted) setState(() {});
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    if (!_office) return const TechnicianHome();
    final canEdit = s.can('interventions.manage');
    return Column(children: [
      PageHeader(
        title: tr('Interventions'),
        subtitle: tr('Dépannages, maintenance préventive, techniciens et contrats'),
        actions: [
          if (s.can('interventions.do'))
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const Scaffold(body: TechnicianHome(standalone: true)))),
              icon: const Icon(Icons.engineering_outlined),
              label: Text(tr('Vue technicien')),
            ),
          if (canEdit) FilledButton.icon(onPressed: () => editIntervention(context), icon: const Icon(Icons.add), label: Text(tr('Intervention'))),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Planning')),
        Tab(text: tr('Interventions')),
        Tab(text: tr('Équipements')),
        Tab(text: tr('Contrats')),
        Tab(text: tr('Statistiques')),
      ]),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('it_interventions'),
          builder: (context, _, _) => TabBarView(controller: _tabs, physics: const NeverScrollableScrollPhysics(), children: [
            WeekPlanner(canEdit: canEdit),
            // ignore: prefer_const_constructors
            _AllInterventions(), // reconstruit à chaque synchronisation
            _EquipmentsTab(canEdit: canEdit),
            _ContractsTab(canEdit: canEdit),
            const _ItStatsTab(),
          ]),
        ),
      ),
    ]);
  }
}

// ======================================================================
// Planning de la semaine : techniciens × jours, glisser-déposer
// ======================================================================

class WeekPlanner extends StatefulWidget {
  const WeekPlanner({super.key, required this.canEdit});

  final bool canEdit;

  @override
  State<WeekPlanner> createState() => _WeekPlannerState();
}

class _WeekPlannerState extends State<WeekPlanner> {
  bool _weekend = false;
  double colW = 150;
  late DateTime _monday = () {
    final t = _day(DateTime.now());
    return _addDays(t, -(t.weekday - 1));
  }();

  Future<void> _drop(Map<String, dynamic> i, int? tech, DateTime day) async {
    final old = _dt(i['scheduled_at']);
    final at = DateTime(day.year, day.month, day.day, old?.hour ?? 8, old?.minute ?? 30);
    try {
      await context.read<SyncEngine>().save('it_interventions', {
        'id': i['id'],
        'technician_id': tech,
        'scheduled_at': at.toUtc().toIso8601String(),
        if (i['status'] == 'requested' && tech != null) 'status': 'planned',
      });
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final techs = _cachedTechs();
    final all = LocalDb.all('it_interventions').where((i) => i['status'] != 'cancelled').toList();
    final end = _addDays(_monday, 7);
    final week = all.where((i) {
      final d = _dt(i['scheduled_at']);
      return d != null && !d.isBefore(_monday) && d.isBefore(end);
    }).toList()
      ..sort((a, b) => '${a['scheduled_at']}'.compareTo('${b['scheduled_at']}'));
    // Week-end affiché s'il est demandé ou s'il y a des interventions ce week-end
    final weekend = _weekend || week.any((i) => _dt(i['scheduled_at'])!.weekday >= 6);
    final days = [for (var k = 0; k < (weekend ? 7 : 5); k++) _addDays(_monday, k)];
    final toPlan = all.where((i) => i['status'] == 'requested' || (i['scheduled_at'] == null && i['status'] != 'done')).toList()
      ..sort((a, b) => ((b['priority'] as num?) ?? 1).compareTo((a['priority'] as num?) ?? 1));
    // Lignes : les techniciens, plus toute personne ayant une intervention cette semaine
    final rows = <int?>[
      for (final t in techs)
        if (t['technician'] == true || week.any((i) => i['technician_id'] == t['id'])) t['id'] as int,
      null,
    ];
    final today = _day(DateTime.now());
    final hint = Theme.of(context).hintColor;
    const headW = 140.0;

    Widget cell(int? tech, DateTime day) {
      final items = week.where((i) => i['technician_id'] == tech && _day(_dt(i['scheduled_at'])!) == day).toList();
      return DragTarget<Map<String, dynamic>>(
        onWillAcceptWithDetails: (_) => widget.canEdit,
        onAcceptWithDetails: (d) => _drop(d.data, tech, day),
        builder: (context, cand, _) => Container(
          width: colW,
          constraints: const BoxConstraints(minHeight: 86),
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            color: cand.isNotEmpty ? YColors.blue.withValues(alpha: .1) : (day == today ? YColors.blue.withValues(alpha: .03) : null),
            border: Border(left: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: .4)), bottom: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: .4))),
          ),
          child: Column(children: [for (final i in items) _draggable(i, _MiniCard(i: i))]),
        ),
      );
    }

    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
      // Demandes à planifier
      Container(
        width: 240,
        margin: const EdgeInsets.fromLTRB(16, 12, 0, 16),
        decoration: BoxDecoration(color: Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .45), borderRadius: BorderRadius.circular(14)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
            child: Row(children: [
              const Icon(Icons.support_agent, size: 18),
              const SizedBox(width: 8),
              Expanded(child: Text(tr('À planifier'), style: TextStyle(fontWeight: FontWeight.w700))),
              StatusChip('${toPlan.length}', color: toPlan.isEmpty ? YColors.muted : YColors.warning),
            ]),
          ),
          Expanded(
            child: toPlan.isEmpty
                ? Center(child: Padding(padding: const EdgeInsets.all(16), child: Text(tr('Aucune demande en attente.'), style: TextStyle(color: hint), textAlign: TextAlign.center)))
                : ListView(padding: const EdgeInsets.fromLTRB(8, 0, 8, 8), children: [for (final i in toPlan) _draggable(i, InterventionCard(i: i, compact: true))]),
          ),
        ]),
      ),
      Expanded(
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 16, 8),
            child: Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
              IconButton(onPressed: () => setState(() => _monday = _addDays(_monday, -7)), icon: const Icon(Icons.chevron_left)),
              Text(tr('Semaine du ${DateFormat('d MMMM', I18n.dateLocale).format(_monday)}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
              IconButton(onPressed: () => setState(() => _monday = _addDays(_monday, 7)), icon: const Icon(Icons.chevron_right)),
              TextButton(onPressed: () => setState(() => _monday = _addDays(today, -(today.weekday - 1))), child: Text(tr('Cette semaine'))),
              FilterChip(label: Text(tr('Week-end')), selected: weekend, onSelected: (v) => setState(() => _weekend = v)),
              StatusChip(tr('${week.length} intervention(s)'), color: YColors.blue),
              StatusChip(tr('${week.where((i) => i['status'] == 'done').length} terminée(s)'), color: YColors.success),
              if (widget.canEdit)
                TextButton.icon(
                  onPressed: () async {
                    try {
                      final r = await context.read<Session>().api.post('interventions/generate', {'days': 14});
                      if (!context.mounted) return;
                      await context.read<SyncEngine>().sync();
                      if (context.mounted) toast(context, tr('${r['created']} visite(s) préventive(s) créée(s) pour les 14 prochains jours.'));
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                  icon: const Icon(Icons.event_repeat, size: 18),
                  label: Text(tr('Générer les visites des contrats')),
                ),
            ]),
          ),
          Expanded(
            child: LayoutBuilder(builder: (context, box) {
              colW = ((box.maxWidth - 28 - headW) / days.length).clamp(118.0, 220.0);
              return Scrollbar(
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.fromLTRB(12, 0, 16, 16),
                child: SizedBox(
                  width: headW + colW * days.length,
                  child: Card(
                    clipBehavior: Clip.antiAlias,
                    margin: EdgeInsets.zero,
                    child: ListView(children: [
                      Row(children: [
                        const SizedBox(width: headW),
                        for (final d in days)
                          Container(
                            width: colW,
                            padding: const EdgeInsets.symmetric(vertical: 10),
                            color: d == today ? YColors.blue.withValues(alpha: .08) : null,
                            child: Text(tr(DateFormat('EEE d', I18n.dateLocale).format(d)), textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.w700, color: d == today ? YColors.blue : null)),
                          ),
                      ]),
                      for (final t in rows)
                        IntrinsicHeight(
                          child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                            Container(
                              width: headW,
                              padding: const EdgeInsets.all(10),
                              decoration: BoxDecoration(border: Border(bottom: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: .4)))),
                              child: Row(children: [
                                CircleAvatar(radius: 14, backgroundColor: (t == null ? YColors.muted : YColors.blue).withValues(alpha: .15), child: Icon(t == null ? Icons.help_outline : Icons.engineering, size: 16, color: t == null ? YColors.muted : YColors.blue)),
                                const SizedBox(width: 8),
                                Expanded(child: Text(tr(t == null ? 'Non attribuées' : techName(t)), style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13))),
                              ]),
                            ),
                            for (final d in days) cell(t, d),
                          ]),
                        ),
                    ]),
                  ),
                ),
              ),
            );
            }),
          ),
        ]),
      ),
    ]);
  }

  Widget _draggable(Map<String, dynamic> i, Widget child) {
    if (!widget.canEdit || ['done', 'cancelled'].contains(i['status'])) return child;
    return Draggable<Map<String, dynamic>>(
      data: i,
      feedback: Material(color: Colors.transparent, child: SizedBox(width: 220, child: Opacity(opacity: .9, child: InterventionCard(i: i, compact: true)))),
      childWhenDragging: Opacity(opacity: .3, child: child),
      child: child,
    );
  }
}

class _MiniCard extends StatelessWidget {
  const _MiniCard({required this.i});

  final Map<String, dynamic> i;

  @override
  Widget build(BuildContext context) {
    final status = '${i['status']}';
    final color = itColors[status] ?? YColors.blue;
    final at = _dt(i['scheduled_at']);
    final prio = ((i['priority'] as num?) ?? 1).toInt();
    return InkWell(
      onTap: () => openIntervention(context, '${i['id']}'),
      child: Container(
        margin: const EdgeInsets.only(bottom: 4),
        padding: const EdgeInsets.fromLTRB(8, 6, 6, 6),
        decoration: BoxDecoration(
          color: color.withValues(alpha: .1),
          borderRadius: BorderRadius.circular(8),
          border: Border(left: BorderSide(color: prio >= 3 ? YColors.danger : color, width: 3)),
        ),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text(tr(at == null ? '' : DateFormat('HH:mm').format(at)), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
            const Spacer(),
            if (i['type'] == 'preventive') const Icon(Icons.event_repeat, size: 12, color: YColors.muted),
            if (status == 'done') const Icon(Icons.check_circle, size: 12, color: YColors.success),
          ]),
          Text(tr(partnerName(i['partner_id']).isEmpty ? '${i['contact_name'] ?? ''}' : partnerName(i['partner_id'])), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600), maxLines: 1, overflow: TextOverflow.ellipsis),
          Text(tr('${i['title']}'), style: const TextStyle(fontSize: 11), maxLines: 2, overflow: TextOverflow.ellipsis),
        ]),
      ),
    );
  }
}

class InterventionCard extends StatelessWidget {
  const InterventionCard({super.key, required this.i, this.compact = false});

  final Map<String, dynamic> i;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final status = '${i['status']}';
    final prio = ((i['priority'] as num?) ?? 1).toInt();
    final at = _dt(i['scheduled_at']);
    final hint = Theme.of(context).hintColor;
    final late = at != null && at.isBefore(DateTime.now().subtract(const Duration(hours: 2))) && ['planned', 'requested'].contains(status);
    final client = partnerName(i['partner_id']);
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => openIntervention(context, '${i['id']}'),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr(client.isEmpty ? '${i['contact_name'] ?? 'Client'}' : client), style: const TextStyle(fontWeight: FontWeight.w700), overflow: TextOverflow.ellipsis)),
              StatusChip(itStatuses[status] ?? status, color: itColors[status] ?? YColors.muted),
            ]),
            const SizedBox(height: 2),
            Text(tr('${i['title']}'), maxLines: compact ? 2 : 3, overflow: TextOverflow.ellipsis),
            Text(tr('${i['number'] ?? 'provisoire'} · ${itTypes[i['type']] ?? ''}'), style: TextStyle(fontSize: 12, color: hint)),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 6, children: [
              if (prio != 1) StatusChip(itPriorities[prio], icon: prio >= 2 ? Icons.priority_high : null, color: itPriorityColors[prio]),
              if (at != null) StatusChip(DateFormat('dd/MM HH:mm').format(at), icon: Icons.event, color: late ? YColors.danger : YColors.muted),
              if (!compact && i['technician_id'] != null) StatusChip(techName(i['technician_id']), icon: Icons.engineering, color: YColors.blue),
              if (i['source'] == 'portal') StatusChip(tr('Portail client'), icon: Icons.web, color: YColors.blue),
              if (status == 'done' && i['invoice_id'] == null && i['billable'] != false) StatusChip(tr('À facturer'), icon: Icons.request_quote_outlined, color: YColors.warning),
            ]),
          ]),
        ),
      ),
    );
  }
}

// ======================================================================
// Liste
// ======================================================================

class _AllInterventions extends StatefulWidget {
  const _AllInterventions();

  @override
  State<_AllInterventions> createState() => _AllInterventionsState();
}

class _AllInterventionsState extends State<_AllInterventions> {
  String _q = '';
  String _filter = 'open';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  static bool _statusMatch(Map<String, dynamic> i, String f) {
    final st = '${i['status']}';
    return switch (f) {
      'open' => ['requested', 'planned', 'en_route', 'in_progress'].contains(st),
      'to_invoice' => st == 'done' && i['invoice_id'] == null && i['billable'] != false,
      '' => true,
      _ => st == f,
    };
  }

  @override
  Widget build(BuildContext context) {
    final all = LocalDb.all('it_interventions');
    final base = all
        .where((i) => _q.isEmpty || '${i['number']} ${i['title']} ${partnerName(i['partner_id'])} ${i['address']} ${i['city']}'.toLowerCase().contains(_q))
        .where((i) => _f['technician'] == null || '${i['technician_id'] ?? '-'}' == _f['technician'])
        .where((i) => _f['partner'] == null || '${i['partner_id']}' == _f['partner'])
        .where((i) => _f['type'] == null || '${i['type']}' == _f['type'])
        .where((i) => _f['priority'] == null || '${((i['priority'] as num?) ?? 1).toInt()}' == _f['priority'])
        .where((i) => _f['city'] == null || '${i['city']}' == _f['city'])
        .where((i) => _f['source'] == null || (i['source'] == 'portal' ? 'portal' : 'office') == _f['source'])
        .where((i) => _period == null || _period!.contains(i['scheduled_at'] ?? i['created_at']))
        .toList();
    final statuses = {'': 'Toutes', 'open': 'En cours', 'to_invoice': 'À facturer', ...itStatuses};
    final rows = base.where((i) => _statusMatch(i, _filter)).toList()
      ..sort((a, b) => '${b['scheduled_at'] ?? b['created_at'] ?? ''}'.compareTo('${a['scheduled_at'] ?? a['created_at'] ?? ''}'));
    return Column(children: [
      const SizedBox(height: 12),
      FilterBar(
        key: ValueKey(_gen),
        onSearch: (v) => setState(() => _q = v.toLowerCase()),
        search: _q,
        searchHint: 'N°, client, objet, adresse…',
        statuses: statuses,
        status: _filter,
        onStatus: (v) => setState(() => _filter = v),
        statusCounts: {for (final k in statuses.keys) k: base.where((i) => _statusMatch(i, k)).length},
        filters: [
          FilterSpec('technician', 'Technicien', {
            ...filterOptions(all, 'technician_id', label: (i) => techName(i['technician_id']).isEmpty ? '#${i['technician_id']}' : techName(i['technician_id'])),
            if (all.any((i) => i['technician_id'] == null)) '-': 'Non attribuée',
          }, icon: Icons.engineering_outlined),
          FilterSpec('partner', 'Client', filterOptions(all, 'partner_id', label: (i) => partnerName(i['partner_id']))..removeWhere((k, v) => v.isEmpty), icon: Icons.business_outlined),
          FilterSpec('type', 'Type', {for (final e in itTypes.entries) if (all.any((i) => i['type'] == e.key)) e.key: e.value}, icon: Icons.category_outlined),
          FilterSpec('priority', 'Priorité', {for (var p = 0; p < itPriorities.length; p++) '$p': itPriorities[p]}, icon: Icons.priority_high_rounded),
          FilterSpec('city', 'Ville', filterOptions(all, 'city'), icon: Icons.place_outlined),
          if (all.any((i) => i['source'] == 'portal')) const FilterSpec('source', 'Origine', {'portal': 'Portail client', 'office': 'Saisie interne'}, icon: Icons.input_outlined),
        ],
        values: _f,
        onFilter: (k, v) => setState(() => _f[k] = v),
        showPeriod: true,
        period: _period,
        onPeriod: (p) => setState(() => _period = p),
        count: rows.length,
        countLabel: 'intervention',
        onReset: () => setState(() {
          _filter = 'open';
          _q = '';
          _f.clear();
          _period = null;
          _gen++;
        }),
      ),
      Expanded(
        child: rows.isEmpty
            ? EmptyState(icon: Icons.engineering_outlined, title: tr('Aucune intervention'), message: tr('Créez une intervention, ou laissez vos clients la demander depuis leur espace.'))
            : ResponsiveList<Map<String, dynamic>>(
                items: rows,
                columns: const ['N°', 'Date', 'Client', 'Objet', 'Technicien', 'Statut'],
                cells: (i) => [
                  Text(tr('${i['number'] ?? 'provisoire'}')),
                  Text(tr(i['scheduled_at'] == null ? '—' : dateTimeFr(i['scheduled_at']))),
                  Text(tr(partnerName(i['partner_id']))),
                  Text(tr('${i['title']}'), overflow: TextOverflow.ellipsis),
                  Text(tr(techName(i['technician_id']))),
                  StatusChip(itStatuses[i['status']] ?? '', color: itColors[i['status']] ?? YColors.muted),
                ],
                mobileTile: (i) => InterventionCard(i: i),
                onTap: (i) => openIntervention(context, '${i['id']}'),
              ),
      ),
    ]);
  }
}

// ======================================================================
// Fiche intervention
// ======================================================================

class InterventionScreen extends StatefulWidget {
  const InterventionScreen({super.key, required this.id});

  final String id;

  @override
  State<InterventionScreen> createState() => _InterventionScreenState();
}

class _InterventionScreenState extends State<InterventionScreen> {
  bool _busy = false;

  Future<void> _save(Map<String, dynamic> data) async {
    try {
      await context.read<SyncEngine>().save('it_interventions', {'id': widget.id, ...data});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _invoice() async {
    setState(() => _busy = true);
    try {
      final r = await context.read<Session>().api.post('interventions/interventions/${widget.id}/invoice');
      if (!mounted) return;
      await context.read<SyncEngine>().sync();
      if (mounted) openSalesDocument(context, '${r['invoice']['id']}');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _photo() async {
    final r = await FilePicker.pickFiles(type: FileType.image);
    if (r.isEmpty || !mounted) return;
    setState(() => _busy = true);
    try {
      final sync = context.read<SyncEngine>();
      await sync.sync();
      if (!mounted) return;
      await context.read<Session>().api.upload('interventions/interventions/${widget.id}/photos', 'photo', await r.first.readAsBytes(), r.first.name);
      await sync.sync();
    } catch (e) {
      if (mounted) toast(context, tr('Photo non envoyée (connexion nécessaire) : ${errorText(e)}'), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('it_interventions'),
      builder: (context, _, _) {
        final i = LocalDb.get('it_interventions', widget.id);
        if (i == null) return Scaffold(appBar: AppBar(), body: EmptyState(icon: Icons.engineering_outlined, title: tr('Intervention introuvable'), message: ''));
        final status = '${i['status']}';
        final manage = s.can('interventions.manage');
        final mine = i['technician_id'] == s.user['id'];
        final canWork = manage || (mine && s.can('interventions.do'));
        final locked = i['invoice_id'] != null || (!manage && status == 'done' && i['signed_at'] != null);
        final partner = LocalDb.get('partners', i['partner_id'] as String?);
        final equipment = LocalDb.get('it_equipments', i['equipment_id'] as String?);
        final contract = LocalDb.get('it_contracts', i['contract_id'] as String?);
        final phone = '${i['contact_phone'] ?? partner?['phone'] ?? ''}';
        final address = [i['address'] ?? partner?['address'], i['city'] ?? partner?['city']].where((e) => e != null && '$e'.isNotEmpty).join(', ');
        final hint = Theme.of(context).hintColor;
        final cur = s.currency;
        final checklist = [for (final c in (i['checklist'] as List? ?? const [])) asMap(c)];
        final parts = [for (final p in (i['parts'] as List? ?? const [])) asMap(p)];
        final photos = [for (final p in (i['photos'] as List? ?? const [])) asMap(p)];
        final reportUrl = i['report_token'] == null ? null : '${s.serverUrl}/r/${i['report_token']}';
        Widget row(String k, String? v) => v == null || v.isEmpty
            ? const SizedBox.shrink()
            : Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [SizedBox(width: 140, child: Text(tr(k), style: TextStyle(color: hint))), Expanded(child: Text(tr(v)))]));

        return Scaffold(
          appBar: AppBar(
            title: Text(tr('${i['number'] ?? 'Intervention'}')),
            actions: [
              Padding(padding: const EdgeInsets.only(right: 8), child: Center(child: StatusChip(itStatuses[status] ?? status, color: itColors[status] ?? YColors.muted))),
              if (manage && !locked) IconButton(tooltip: tr('Modifier'), onPressed: () => editIntervention(context, intervention: i), icon: const Icon(Icons.edit_outlined)),
            ],
          ),
          body: ListView(padding: const EdgeInsets.all(16), children: [
            Text(tr('${i['title']}'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, children: [
              StatusChip(itTypes[i['type']] ?? '', color: YColors.blue),
              StatusChip(tr('Priorité ${itPriorities[((i['priority'] as num?) ?? 1).toInt()].toLowerCase()}'), color: itPriorityColors[((i['priority'] as num?) ?? 1).toInt()]),
              if (contract != null) StatusChip(tr('Contrat ${contract['number'] ?? ''}'), icon: Icons.verified_outlined, color: YColors.success),
              if (i['source'] == 'portal') StatusChip(tr('Demandée par le client'), icon: Icons.web, color: YColors.blue),
            ]),
            const SizedBox(height: 12),
            if (canWork && !locked) _actions(context, i, manage),
            if (manage && status == 'done')
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Wrap(spacing: 8, runSpacing: 8, children: [
                  if (i['invoice_id'] == null && s.hasModule('ventes'))
                    FilledButton.icon(onPressed: _busy ? null : _invoice, icon: const Icon(Icons.request_quote_outlined), label: Text(tr('Facturer'))),
                  if (i['invoice_id'] != null)
                    OutlinedButton.icon(onPressed: () => openSalesDocument(context, '${i['invoice_id']}'), icon: const Icon(Icons.receipt_long_outlined), label: Text(tr('Voir la facture'))),
                  if (i['invoice_id'] == null) OutlinedButton.icon(onPressed: () => _save({'status': 'in_progress'}), icon: const Icon(Icons.lock_open), label: Text(tr('Rouvrir'))),
                ]),
              ),
            const SizedBox(height: 12),
            SectionCard(
              title: partner?['name'] ?? i['contact_name'] ?? 'Client',
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                row('Adresse', address),
                row('Contact', [i['contact_name'], phone].where((e) => e != null && '$e'.isNotEmpty).join(' · ')),
                row('Prévue le', i['scheduled_at'] == null ? 'À planifier' : '${dateTimeFr(i['scheduled_at'])} · ${_hm((i['duration_minutes'] as num?)?.toInt())}'),
                row('Technicien', techName(i['technician_id'])),
                if (i['started_at'] != null) row('Arrivée', dateTimeFr(i['started_at'])),
                if (i['finished_at'] != null) row('Fin', dateTimeFr(i['finished_at'])),
                if (i['worked_minutes'] != null) row('Temps passé', _hm((i['worked_minutes'] as num).toInt())),
                if (manage && i['billable'] != false && toDouble(i['labor_rate']) > 0) row('Taux horaire', money(toDouble(i['labor_rate']), cur)),
                if (manage && i['billable'] != false && toDouble(i['travel_fee']) > 0) row('Déplacement', money(toDouble(i['travel_fee']), cur)),
                if (i['billable'] == false) row('Facturation', 'Main-d\'œuvre non facturée (contrat)'),
                const SizedBox(height: 8),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  if (phone.isNotEmpty) OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('tel:$phone')), icon: const Icon(Icons.call), label: Text(tr('Appeler'))),
                  if (phone.isNotEmpty)
                    OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/${_phoneWa(phone)}'), mode: LaunchMode.externalApplication), icon: const Icon(Icons.chat_outlined), label: Text(tr('WhatsApp'))),
                  if (address.isNotEmpty)
                    OutlinedButton.icon(
                      onPressed: () => safeLaunchUrl(Uri.parse('https://www.google.com/maps/dir/?api=1&destination=${Uri.encodeComponent('$address, Côte d\'Ivoire')}'), mode: LaunchMode.externalApplication),
                      icon: const Icon(Icons.navigation_outlined),
                      label: Text(tr('Itinéraire')),
                    ),
                  if (reportUrl != null)
                    OutlinedButton.icon(
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: reportUrl));
                        toast(context, tr('Lien du rapport copié.'));
                      },
                      icon: const Icon(Icons.link),
                      label: Text(tr(status == 'done' ? 'Lien du rapport' : 'Lien de suivi')),
                    ),
                  if (reportUrl != null && status == 'done')
                    OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse(reportUrl), mode: LaunchMode.externalApplication), icon: const Icon(Icons.print_outlined), label: Text(tr('Rapport (PDF)'))),
                ]),
              ]),
            ),
            if ('${i['description'] ?? ''}'.isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(title: tr('Demande'), child: Text(tr('${i['description']}'))),
            ],
            if (equipment != null) ...[
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Équipement'),
                trailing: TextButton(onPressed: () => openEquipment(context, '${equipment['id']}'), child: Text(tr('Historique'))),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('${equipment['name']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  Text(tr([equipment['brand'], equipment['model'], if (equipment['serial_number'] != null) 'N° ${equipment['serial_number']}', equipment['location']].where((e) => e != null && '$e'.isNotEmpty).join(' · ')),
                      style: TextStyle(color: hint)),
                  if (equipment['warranty_until'] != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: StatusChip(
                        DateTime.tryParse('${equipment['warranty_until']}')?.isAfter(DateTime.now()) == true ? 'Sous garantie jusqu\'au ${dateFr(equipment['warranty_until'])}' : 'Garantie expirée',
                        color: DateTime.tryParse('${equipment['warranty_until']}')?.isAfter(DateTime.now()) == true ? YColors.success : YColors.muted,
                      ),
                    ),
                ]),
              ),
            ],
            if (checklist.isNotEmpty) ...[
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Points de contrôle (${checklist.where((c) => c['done'] == true).length}/${checklist.length})'),
                child: Column(children: [
                  for (var k = 0; k < checklist.length; k++)
                    CheckboxListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      value: checklist[k]['done'] == true,
                      title: Text(tr('${checklist[k]['text']}')),
                      onChanged: !canWork || locked
                          ? null
                          : (v) {
                              final next = [...checklist];
                              next[k] = {...next[k], 'done': v == true};
                              _save({'checklist': next});
                            },
                    ),
                ]),
              ),
            ],
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Pièces et fournitures'),
              trailing: canWork && !locked ? TextButton.icon(onPressed: () => _addPart(context, parts), icon: const Icon(Icons.add, size: 18), label: Text(tr('Pièce'))) : null,
              child: parts.isEmpty
                  ? Text(tr('Aucune pièce posée.'), style: TextStyle(color: hint))
                  : Column(children: [
                      for (var k = 0; k < parts.length; k++)
                        ListTile(
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          leading: CircleAvatar(radius: 14, child: Text(tr(qty(toDouble(parts[k]['quantity']))), style: const TextStyle(fontSize: 11))),
                          title: Text(tr('${parts[k]['description']}')),
                          subtitle: manage && parts[k]['unit_price'] != null ? Text(tr(money(toDouble(parts[k]['unit_price']) * toDouble(parts[k]['quantity']), cur))) : null,
                          trailing: canWork && !locked
                              ? IconButton(
                                  icon: const Icon(Icons.close, size: 18),
                                  onPressed: () => _save({'parts': [...parts]..removeAt(k)}),
                                )
                              : null,
                        ),
                    ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Photos'),
              trailing: canWork ? TextButton.icon(onPressed: _busy ? null : _photo, icon: const Icon(Icons.photo_camera_outlined, size: 18), label: Text(tr('Ajouter'))) : null,
              child: photos.isEmpty
                  ? Text(tr('Avant / après, plaque signalétique… (connexion nécessaire)'), style: TextStyle(color: hint))
                  : Wrap(spacing: 8, runSpacing: 8, children: [
                      for (final p in photos)
                        InkWell(
                          onTap: () => safeLaunchUrl(Uri.parse('${p['url']}'), mode: LaunchMode.externalApplication),
                          child: ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.network('${p['url']}', width: 120, height: 90, fit: BoxFit.cover, errorBuilder: (_, _, _) => const SizedBox(width: 120, height: 90, child: Icon(Icons.broken_image)))),
                        ),
                    ]),
            ),
            if ('${i['report'] ?? ''}'.isNotEmpty || status == 'done') ...[
              const SizedBox(height: 12),
              SectionCard(
                title: tr('Rapport'),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('${i['report'] ?? ''}')),
                  if ('${i['recommendations'] ?? ''}'.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Text(tr('Recommandations'), style: TextStyle(color: hint, fontSize: 12)),
                    Text(tr('${i['recommendations']}')),
                  ],
                  if (i['signed_by'] != null || '${i['signature'] ?? ''}'.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Text(tr('Validé par ${i['signed_by'] ?? 'le client'}${i['signed_at'] != null ? ' le ${dateTimeFr(i['signed_at'])}' : ''}'), style: TextStyle(color: hint)),
                    if ('${i['signature'] ?? ''}'.startsWith('data:image'))
                      Container(
                        margin: const EdgeInsets.only(top: 6),
                        height: 100,
                        width: 260,
                        decoration: BoxDecoration(border: Border.all(color: YColors.line), borderRadius: BorderRadius.circular(8), color: Colors.white),
                        child: Image.memory(UriData.parse('${i['signature']}').contentAsBytes(), fit: BoxFit.contain),
                      ),
                  ],
                  if (i['satisfaction'] != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: Row(children: [for (var k = 1; k <= 5; k++) Icon(k <= (i['satisfaction'] as num) ? Icons.star : Icons.star_border, color: YColors.warning, size: 20)]),
                    ),
                ]),
              ),
            ],
          ]),
        );
      },
    );
  }

  Widget _actions(BuildContext context, Map<String, dynamic> i, bool manage) {
    final status = '${i['status']}';
    return Wrap(spacing: 8, runSpacing: 8, children: [
      if (['planned', 'requested'].contains(status) && i['technician_id'] != null)
        FilledButton.icon(onPressed: () => _save({'status': 'en_route'}), icon: const Icon(Icons.directions_car_outlined), label: Text(tr('Je pars'))),
      if (['planned', 'en_route', 'requested'].contains(status) && i['technician_id'] != null)
        FilledButton.icon(onPressed: () => _save({'status': 'in_progress', 'started_at': DateTime.now().toUtc().toIso8601String()}), icon: const Icon(Icons.play_arrow), label: Text(tr('Démarrer sur place'))),
      if (['in_progress', 'en_route'].contains(status))
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: YColors.success),
          onPressed: () => showFinishSheet(context, i),
          icon: const Icon(Icons.task_alt),
          label: Text(tr('Terminer et faire signer')),
        ),
      if (manage && !['done', 'cancelled'].contains(status))
        TextButton(
          onPressed: () async {
            if (await confirm(context, tr('Annuler l\'intervention ?'), '${i['number'] ?? ''} — ${i['title']}', danger: true, ok: tr('Annuler l\'intervention'))) _save({'status': 'cancelled'});
          },
          child: Text(tr('Annuler'), style: TextStyle(color: YColors.danger)),
        ),
      if (manage && status == 'cancelled') OutlinedButton(onPressed: () => _save({'status': 'planned'}), child: Text(tr('Réactiver'))),
    ]);
  }

  Future<void> _addPart(BuildContext context, List<Map<String, dynamic>> parts) async {
    final products = LocalDb.all('products').where((p) => p['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    String? productId;
    final desc = TextEditingController();
    final q = TextEditingController(text: '1');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, set) => YAlertDialog(
          title: Text(tr('Pièce posée')),
          content: SizedBox(
            width: 420,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Autocomplete<Map<String, dynamic>>(
                optionsBuilder: (v) => v.text.isEmpty ? const [] : products.where((p) => '${p['name']} ${p['sku'] ?? ''} ${p['barcode'] ?? ''}'.toLowerCase().contains(v.text.toLowerCase())).take(20),
                displayStringForOption: (p) => '${p['name']}',
                onSelected: (p) => set(() {
                  productId = '${p['id']}';
                  desc.text = '${p['name']}';
                }),
                fieldViewBuilder: (c, ctl, focus, submit) => TextField(controller: ctl, focusNode: focus, autofocus: true, decoration: InputDecoration(labelText: tr('Article du catalogue (recherche)'))),
              ),
              TextField(controller: desc, decoration: InputDecoration(labelText: tr('Désignation')), onChanged: (_) => productId = productId),
              TextField(controller: q, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: InputDecoration(labelText: tr('Quantité'))),
            ]),
          ),
          actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Ajouter')))],
        ),
      ),
    );
    if (ok != true || desc.text.trim().isEmpty) return;
    final quantity = double.tryParse(q.text.replaceAll(',', '.')) ?? 1;
    final price = productId == null ? null : toDouble(LocalDb.get('products', productId)?['sale_price']);
    if (!context.mounted) return;
    final manage = context.read<Session>().can('interventions.manage');
    await _save({
      'parts': [
        ...parts,
        {'product_id': productId, 'description': desc.text.trim(), 'quantity': quantity, if (price != null && manage) 'unit_price': price},
      ],
    });
  }
}

// ======================================================================
// Fin d'intervention : rapport, temps, signature, satisfaction
// ======================================================================

Future<void> showFinishSheet(BuildContext context, Map<String, dynamic> i) async {
  await showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (c) => Padding(padding: EdgeInsets.only(bottom: MediaQuery.of(c).viewInsets.bottom), child: _FinishForm(i: i)),
  );
}

class _FinishForm extends StatefulWidget {
  const _FinishForm({required this.i});

  final Map<String, dynamic> i;

  @override
  State<_FinishForm> createState() => _FinishFormState();
}

class _FinishFormState extends State<_FinishForm> {
  final _pad = GlobalKey<SignaturePadState>();
  late final _report = TextEditingController(text: '${widget.i['report'] ?? ''}');
  late final _reco = TextEditingController(text: '${widget.i['recommendations'] ?? ''}');
  late final _signer = TextEditingController(text: '${widget.i['contact_name'] ?? partnerName(widget.i['partner_id'])}');
  late final _minutes = TextEditingController(text: () {
    final start = _dt(widget.i['started_at']);
    final m = (widget.i['worked_minutes'] as num?)?.toInt() ?? (start == null ? 60 : DateTime.now().difference(start).inMinutes.clamp(1, 24 * 60));
    return '$m';
  }());
  int? _stars;
  bool _busy = false;

  Future<void> _save() async {
    if (_report.text.trim().isEmpty) {
      toast(context, tr('Décrivez les travaux réalisés.'), error: true);
      return;
    }
    setState(() => _busy = true);
    final signature = await _pad.currentState?.exportPng();
    if (!mounted) return;
    try {
      await context.read<SyncEngine>().save('it_interventions', {
        'id': widget.i['id'],
        'status': 'done',
        'report': _report.text.trim(),
        'recommendations': _reco.text.trim().isEmpty ? null : _reco.text.trim(),
        'worked_minutes': int.tryParse(_minutes.text) ?? 60,
        'finished_at': DateTime.now().toUtc().toIso8601String(),
        'signature': ?signature,
        if (signature != null) 'signed_at': DateTime.now().toUtc().toIso8601String(),
        'signed_by': _signer.text.trim().isEmpty ? null : _signer.text.trim(),
        'satisfaction': _stars,
      });
      if (mounted) {
        Navigator.pop(context);
        toast(context, tr('Intervention terminée. Le rapport part au client dès la synchronisation.'));
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(tr('Terminer ${widget.i['number'] ?? ''}'), style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        const SizedBox(height: 12),
        TextField(controller: _report, minLines: 3, maxLines: 6, decoration: InputDecoration(labelText: tr('Travaux réalisés *'), hintText: tr('Diagnostic, réparation, réglages…'))),
        const SizedBox(height: 8),
        TextField(controller: _reco, minLines: 1, maxLines: 3, decoration: InputDecoration(labelText: tr('Recommandations au client'))),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: TextField(controller: _minutes, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Temps passé (minutes)')))),
          const SizedBox(width: 12),
          Expanded(child: TextField(controller: _signer, decoration: InputDecoration(labelText: tr('Nom du client signataire')))),
        ]),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: Text(tr('Signature du client'), style: TextStyle(fontWeight: FontWeight.w600))),
          TextButton(onPressed: () => _pad.currentState?.clear(), child: Text(tr('Effacer'))),
        ]),
        SignaturePad(key: _pad, height: 160),
        const SizedBox(height: 10),
        Row(children: [
          Text(tr('Satisfaction du client')),
          const SizedBox(width: 8),
          for (var k = 1; k <= 5; k++)
            IconButton(
              visualDensity: VisualDensity.compact,
              onPressed: () => setState(() => _stars = k),
              icon: Icon((_stars ?? 0) >= k ? Icons.star : Icons.star_border, color: YColors.warning),
            ),
        ]),
        const SizedBox(height: 12),
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: YColors.success, minimumSize: const Size.fromHeight(48)),
          onPressed: _busy ? null : _save,
          icon: const Icon(Icons.check),
          label: Text(tr(_busy ? 'Enregistrement…' : 'Valider le rapport')),
        ),
      ]),
    );
  }
}

// ======================================================================
// Vue technicien : mes interventions (hors ligne)
// ======================================================================

class TechnicianHome extends StatelessWidget {
  const TechnicianHome({super.key, this.standalone = false});

  final bool standalone;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final me = s.user['id'];
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('it_interventions'),
      builder: (context, _, _) {
        final today = _day(DateTime.now());
        final mine = LocalDb.all('it_interventions').where((i) => i['technician_id'] == me && i['status'] != 'cancelled').toList()
          ..sort((a, b) => '${a['scheduled_at'] ?? ''}'.compareTo('${b['scheduled_at'] ?? ''}'));
        final active = mine.where((i) => ['en_route', 'in_progress'].contains(i['status'])).toList();
        final todo = mine.where((i) {
          final d = _dt(i['scheduled_at']);
          return ['planned', 'requested'].contains(i['status']) && (d == null || !_day(d).isAfter(today));
        }).toList();
        final later = mine.where((i) {
          final d = _dt(i['scheduled_at']);
          return ['planned', 'requested'].contains(i['status']) && d != null && _day(d).isAfter(today);
        }).toList();
        final doneToday = mine.where((i) => i['status'] == 'done' && _dt(i['finished_at']) != null && _day(_dt(i['finished_at'])!) == today).toList();
        final wide = MediaQuery.of(context).size.width >= 1000;
        return Scaffold(
          appBar: standalone ? AppBar(title: Text(tr('Mes interventions'))) : null,
          body: ListView(padding: const EdgeInsets.all(16), children: [
            if (!standalone && wide) PageHeader(title: tr('Mes interventions')),
            Wrap(spacing: 8, runSpacing: 8, children: [
              StatusChip(tr('${todo.length + active.length} à faire aujourd\'hui'), icon: Icons.engineering, color: YColors.blue),
              StatusChip(tr('${doneToday.length} terminée(s)'), icon: Icons.task_alt, color: YColors.success),
              if (later.isNotEmpty) StatusChip(tr('${later.length} à venir'), icon: Icons.event, color: YColors.muted),
            ]),
            const SizedBox(height: 12),
            if (active.isNotEmpty) ...[
              Text(tr('En cours'), style: TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              for (final i in active) InterventionCard(i: i),
            ],
            if (todo.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(tr('Aujourd\'hui'), style: TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              for (final i in todo) InterventionCard(i: i),
            ],
            if (todo.isEmpty && active.isEmpty)
              EmptyState(icon: Icons.task_alt, title: tr('Rien de prévu aujourd\'hui'), message: tr('Vos interventions apparaissent ici dès qu\'elles vous sont attribuées, même sans connexion.')),
            if (later.isNotEmpty) ...[
              const SizedBox(height: 16),
              Text(tr('Prochains jours'), style: TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              for (final i in later.take(30)) InterventionCard(i: i, compact: true),
            ],
          ]),
        );
      },
    );
  }
}

// ======================================================================
// Formulaire intervention
// ======================================================================

Future<void> editIntervention(BuildContext context, {Map<String, dynamic>? intervention, String? partnerId, String? equipmentId}) async {
  final techs = await interventionTechnicians(context.read<Session>());
  if (!context.mounted) return;
  await showFormDialog(context,
      title: intervention == null ? 'Nouvelle intervention' : 'Intervention ${intervention['number'] ?? ''}',
      builder: (c) => _InterventionForm(i: intervention, techs: techs, partnerId: partnerId, equipmentId: equipmentId));
}

class _InterventionForm extends StatefulWidget {
  const _InterventionForm({this.i, required this.techs, this.partnerId, this.equipmentId});

  final Map<String, dynamic>? i;
  final List<Map<String, dynamic>> techs;
  final String? partnerId;
  final String? equipmentId;

  @override
  State<_InterventionForm> createState() => _InterventionFormState();
}

class _InterventionFormState extends State<_InterventionForm> {
  late final Map<String, dynamic> _d = {...?widget.i};
  final Map<String, TextEditingController> _c = {};
  late String? _partner = (_d['partner_id'] as String?) ?? widget.partnerId ?? (widget.equipmentId == null ? null : LocalDb.get('it_equipments', widget.equipmentId)?['partner_id'] as String?);
  late String? _equipment = (_d['equipment_id'] as String?) ?? widget.equipmentId;
  late String? _contract = _d['contract_id'] as String?;
  late String _type = '${_d['type'] ?? 'corrective'}';
  late int _priority = ((_d['priority'] as num?) ?? 1).toInt();
  late int? _tech = (_d['technician_id'] as num?)?.toInt();
  late DateTime? _at = _dt(_d['scheduled_at']);
  late bool _billable = _d['billable'] != false;
  late bool _notify = _d['notify'] != false;
  late final List<Map<String, dynamic>> _checklist = [for (final c in (_d['checklist'] as List? ?? const [])) asMap(c)];

  TextEditingController c(String k, [String? init]) => _c.putIfAbsent(k, () => TextEditingController(text: init ?? (_d[k] == null ? '' : '${_d[k]}')));

  @override
  void initState() {
    super.initState();
    for (final k in ['labor_rate', 'travel_fee']) {
      final v = toDouble(_d[k]);
      c(k, v == 0 ? '' : '${v.round()}');
    }
    c('duration_minutes', '${_d['duration_minutes'] ?? 60}');
    if (widget.i == null && _partner != null) _applyPartner(_partner!);
  }

  void _applyPartner(String id) {
    final p = LocalDb.get('partners', id);
    if (p == null) return;
    if (c('address').text.isEmpty) c('address').text = '${p['address'] ?? ''}';
    if (c('city').text.isEmpty) c('city').text = '${p['city'] ?? ''}';
    if (c('contact_name').text.isEmpty) c('contact_name').text = '${p['name'] ?? ''}';
    if (c('contact_phone').text.isEmpty) c('contact_phone').text = '${p['phone'] ?? ''}';
    final contracts = LocalDb.all('it_contracts').where((k) => k['partner_id'] == id && k['status'] == 'active').toList();
    if (_contract == null && contracts.length == 1) _setContract(contracts.first);
  }

  void _setContract(Map<String, dynamic>? k) {
    _contract = k?['id'] as String?;
    if (k != null) {
      _billable = k['labor_included'] != true;
      _tech ??= (k['technician_id'] as num?)?.toInt();
    }
  }

  Future<void> _save() async {
    if (c('title').text.trim().isEmpty) {
      toast(context, tr('Indiquez l\'objet de l\'intervention.'), error: true);
      return;
    }
    String? t(String k) => c(k).text.trim().isEmpty ? null : c(k).text.trim();
    try {
      await context.read<SyncEngine>().save('it_interventions', {
        'id': _d['id'] ?? newId(),
        'partner_id': _partner,
        'contract_id': _contract,
        'equipment_id': _equipment,
        'type': _type,
        'priority': _priority,
        'title': c('title').text.trim(),
        'description': t('description'),
        'address': t('address'),
        'city': t('city'),
        'contact_name': t('contact_name'),
        'contact_phone': t('contact_phone'),
        'scheduled_at': _at?.toUtc().toIso8601String(),
        'duration_minutes': int.tryParse(c('duration_minutes').text) ?? 60,
        'technician_id': _tech,
        'labor_rate': double.tryParse(c('labor_rate').text.replaceAll(RegExp(r'[\s ]'), '')) ?? 0,
        'travel_fee': double.tryParse(c('travel_fee').text.replaceAll(RegExp(r'[\s ]'), '')) ?? 0,
        'billable': _billable,
        'notify': _notify,
        'checklist': _checklist.where((x) => '${x['text']}'.trim().isNotEmpty).toList(),
        if (widget.i == null) 'status': _tech != null && _at != null ? 'planned' : 'requested',
        if (widget.i != null && _d['status'] == 'requested' && _tech != null && _at != null) 'status': 'planned',
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final partners = LocalDb.all('partners').where((p) => p['type'] != 'supplier').toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
    final equipments = LocalDb.all('it_equipments').where((e) => _partner == null || e['partner_id'] == _partner).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final contracts = LocalDb.all('it_contracts').where((k) => k['partner_id'] == _partner && k['status'] == 'active').toList();
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Field(
        label: tr('Client'),
        child: DropdownButtonFormField<String?>(
          initialValue: partners.any((p) => p['id'] == _partner) ? _partner : null,
          isExpanded: true,
          items: [const DropdownMenuItem<String?>(value: null, child: Text('—')), for (final p in partners) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
          onChanged: (v) => setState(() {
            _partner = v;
            _equipment = null;
            _contract = null;
            if (v != null) _applyPartner(v);
          }),
        ),
      ),
      Field(label: tr('Objet'), child: TextField(controller: c('title'), decoration: InputDecoration(hintText: tr('Ex. Climatiseur bureau DG ne refroidit plus')))),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Type'),
            child: DropdownButtonFormField<String>(
              initialValue: _type,
              items: [for (final t in itTypes.entries) DropdownMenuItem(value: t.key, child: Text(tr(t.value)))],
              onChanged: (v) => setState(() => _type = v ?? _type),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Priorité'),
            child: DropdownButtonFormField<int>(
              initialValue: _priority,
              items: [for (var k = 0; k < 4; k++) DropdownMenuItem(value: k, child: Text(tr(itPriorities[k])))],
              onChanged: (v) => setState(() => _priority = v ?? 1),
            ),
          ),
        ),
      ]),
      if (_partner != null && (equipments.isNotEmpty || contracts.isNotEmpty))
        Row(children: [
          Expanded(
            child: Field(
              label: tr('Équipement'),
              child: DropdownButtonFormField<String?>(
                initialValue: equipments.any((e) => e['id'] == _equipment) ? _equipment : null,
                isExpanded: true,
                items: [const DropdownMenuItem<String?>(value: null, child: Text('—')), for (final e in equipments) DropdownMenuItem<String?>(value: '${e['id']}', child: Text(tr('${e['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => setState(() {
                  _equipment = v;
                  final e = v == null ? null : LocalDb.get('it_equipments', v);
                  if (e?['contract_id'] != null) _setContract(LocalDb.get('it_contracts', '${e!['contract_id']}'));
                }),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Field(
              label: tr('Contrat'),
              child: DropdownButtonFormField<String?>(
                key: ValueKey('ctr-$_contract'),
                initialValue: contracts.any((k) => k['id'] == _contract) ? _contract : null,
                isExpanded: true,
                items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Hors contrat'))), for (final k in contracts) DropdownMenuItem<String?>(value: '${k['id']}', child: Text(tr('${k['number'] ?? ''} ${k['name']}'), overflow: TextOverflow.ellipsis))],
                onChanged: (v) => setState(() => _setContract(v == null ? null : LocalDb.get('it_contracts', v))),
              ),
            ),
          ),
        ]),
      Field(label: tr('Description de la demande'), child: TextField(controller: c('description'), maxLines: 3)),
      Row(children: [
        Expanded(flex: 2, child: Field(label: tr('Adresse'), child: TextField(controller: c('address')))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Commune'), child: TextField(controller: c('city')))),
      ]),
      Row(children: [
        Expanded(child: Field(label: tr('Contact sur place'), child: TextField(controller: c('contact_name')))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Téléphone'), child: TextField(controller: c('contact_phone'), keyboardType: TextInputType.phone))),
      ]),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Date et heure'),
            child: OutlinedButton.icon(
              onPressed: () async {
                final d = await pickDate(context, initialDate: _at ?? DateTime.now(), firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (d == null || !context.mounted) return;
                final t = await showTimePicker(context: context, initialTime: TimeOfDay(hour: _at?.hour ?? 8, minute: _at?.minute ?? 30));
                setState(() => _at = DateTime(d.year, d.month, d.day, t?.hour ?? 8, t?.minute ?? 30));
              },
              icon: const Icon(Icons.event, size: 18),
              label: Text(tr(_at == null ? 'À planifier' : DateFormat('EEE dd/MM HH:mm', I18n.dateLocale).format(_at!))),
            ),
          ),
        ),
        const SizedBox(width: 12),
        SizedBox(width: 110, child: Field(label: tr('Durée (min)'), child: TextField(controller: c('duration_minutes'), keyboardType: TextInputType.number))),
      ]),
      Field(
        label: tr('Technicien'),
        child: DropdownButtonFormField<int?>(
          initialValue: widget.techs.any((u) => u['id'] == _tech) ? _tech : null,
          isExpanded: true,
          items: [DropdownMenuItem<int?>(value: null, child: Text(tr('À attribuer'))), for (final u in widget.techs) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}')))],
          onChanged: (v) => setState(() => _tech = v),
        ),
      ),
      Row(children: [
        Expanded(child: Field(label: tr('Taux horaire'), child: TextField(controller: c('labor_rate'), keyboardType: TextInputType.number, decoration: InputDecoration(hintText: tr('Paramètre par défaut'))))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('Déplacement'), child: TextField(controller: c('travel_fee'), keyboardType: TextInputType.number))),
      ]),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Main-d\'œuvre facturable')), subtitle: Text(tr('Désactivé si couverte par un contrat')), value: _billable, onChanged: (v) => setState(() => _billable = v)),
      Field(
        label: tr('Points de contrôle'),
        child: Column(children: [
          for (var k = 0; k < _checklist.length; k++)
            Row(children: [
              Expanded(child: TextFormField(initialValue: '${_checklist[k]['text']}', decoration: const InputDecoration(isDense: true), onChanged: (v) => _checklist[k]['text'] = v)),
              IconButton(onPressed: () => setState(() => _checklist.removeAt(k)), icon: const Icon(Icons.close, size: 18)),
            ]),
          Align(alignment: Alignment.centerLeft, child: TextButton.icon(onPressed: () => setState(() => _checklist.add({'text': '', 'done': false})), icon: const Icon(Icons.add), label: Text(tr('Point')))),
        ]),
      ),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Prévenir le client')), subtitle: Text(tr('Technicien en route, puis rapport en fin d\'intervention')), value: _notify, onChanged: (v) => setState(() => _notify = v)),
      const SizedBox(height: 8),
      Row(children: [
        if (widget.i != null && widget.i!['invoice_id'] == null)
          TextButton(
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer l\'intervention ?'), '${_d['number'] ?? ''}', danger: true, ok: tr('Supprimer'))) return;
              if (!context.mounted) return;
              await context.read<SyncEngine>().remove('it_interventions', '${_d['id']}');
              if (context.mounted) Navigator.of(context).popUntil((r) => r.isFirst);
            },
            child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
          ),
        const Spacer(),
        SaveButton(onPressed: _save, child: Text(tr('Enregistrer'))),
      ]),
    ]);
  }
}

// ======================================================================
// Équipements
// ======================================================================

void openEquipment(BuildContext context, String id) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => EquipmentScreen(id: id)));

class _EquipmentsTab extends StatefulWidget {
  const _EquipmentsTab({required this.canEdit});

  final bool canEdit;

  @override
  State<_EquipmentsTab> createState() => _EquipmentsTabState();
}

class _EquipmentsTabState extends State<_EquipmentsTab> {
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('it_equipments'),
      builder: (context, _, _) {
        final all = LocalDb.all('it_equipments');
        final today = isoDate(DateTime.now());
        String st(Map<String, dynamic> e) => '${e['status'] ?? 'active'}';
        final base = all
            .where((e) => _q.isEmpty || '${e['name']} ${e['brand']} ${e['model']} ${e['serial_number']} ${e['location']} ${partnerName(e['partner_id'])}'.toLowerCase().contains(_q))
            .where((e) => _f['partner'] == null || '${e['partner_id']}' == _f['partner'])
            .where((e) => _f['brand'] == null || '${e['brand']}' == _f['brand'])
            .where((e) => _f['warranty'] == null || switch (_f['warranty']) {
                  'yes' => e['warranty_until'] != null && '${e['warranty_until']}'.compareTo(today) >= 0,
                  'no' => e['warranty_until'] != null && '${e['warranty_until']}'.compareTo(today) < 0,
                  _ => e['warranty_until'] == null,
                })
            .toList();
        final statuses = {'': 'Tous', ...equipmentStatuses};
        final rows = base.where((e) => _status.isEmpty || st(e) == _status).toList()
          ..sort((a, b) => '${partnerName(a['partner_id'])}${a['name']}'.compareTo('${partnerName(b['partner_id'])}${b['name']}'));
        return Column(children: [
          const SizedBox(height: 12),
          FilterBar(
            key: ValueKey(_gen),
            onSearch: (v) => setState(() => _q = v.toLowerCase()),
            search: _q,
            searchHint: 'Équipement, marque, n° de série, client…',
            statuses: statuses,
            status: _status,
            onStatus: (v) => setState(() => _status = v),
            statusCounts: {for (final k in statuses.keys) k: base.where((e) => k.isEmpty || st(e) == k).length},
            filters: [
              FilterSpec('partner', 'Client', filterOptions(all, 'partner_id', label: (e) => partnerName(e['partner_id']))..removeWhere((k, v) => v.isEmpty), icon: Icons.business_outlined),
              FilterSpec('brand', 'Marque', filterOptions(all, 'brand'), icon: Icons.sell_outlined),
              const FilterSpec('warranty', 'Garantie', {'yes': 'Sous garantie', 'no': 'Garantie expirée', 'none': 'Non renseignée'}, icon: Icons.verified_user_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f[k] = v),
            count: rows.length,
            countLabel: 'équipement',
            onReset: () => setState(() {
              _q = '';
              _status = '';
              _f.clear();
              _gen++;
            }),
            trailing: [
              if (widget.canEdit) FilledButton.tonalIcon(onPressed: () => editEquipment(context), icon: const Icon(Icons.add), label: Text(tr('Équipement'))),
            ],
          ),
          Expanded(
            child: rows.isEmpty
                ? EmptyState(icon: Icons.precision_manufacturing_outlined, title: tr('Aucun équipement'), message: tr('Enregistrez le parc de vos clients : climatiseurs, groupes électrogènes, serveurs, ascenseurs…'))
                : ResponsiveList<Map<String, dynamic>>(
                    items: rows,
                    columns: const ['Équipement', 'Client', 'Marque / modèle', 'N° de série', 'Garantie', 'État'],
                    cells: (e) => [
                      Text(tr('${e['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(tr(partnerName(e['partner_id']))),
                      Text(tr([e['brand'], e['model']].where((x) => x != null).join(' '))),
                      Text(tr('${e['serial_number'] ?? ''}')),
                      Text(tr(e['warranty_until'] == null ? '—' : dateFr(e['warranty_until']))),
                      StatusChip(equipmentStatuses[e['status']] ?? 'En service', color: e['status'] == 'broken' ? YColors.danger : (e['status'] == 'retired' ? YColors.muted : YColors.success)),
                    ],
                    mobileTile: (e) => ListTile(
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.precision_manufacturing_outlined),
                      title: Text(tr('${e['name']}')),
                      subtitle: Text(tr([partnerName(e['partner_id']), e['brand'], e['serial_number']].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
                    ),
                    onTap: (e) => openEquipment(context, '${e['id']}'),
                  ),
          ),
        ]);
      },
    );
  }
}

class EquipmentScreen extends StatelessWidget {
  const EquipmentScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('it_equipments'),
      builder: (context, _, _) {
        final e = LocalDb.get('it_equipments', id);
        if (e == null) return Scaffold(appBar: AppBar(), body: const SizedBox());
        final history = LocalDb.all('it_interventions').where((i) => i['equipment_id'] == id).toList()..sort((a, b) => '${b['scheduled_at'] ?? ''}'.compareTo('${a['scheduled_at'] ?? ''}'));
        final contract = LocalDb.get('it_contracts', e['contract_id'] as String?);
        final hint = Theme.of(context).hintColor;
        Widget row(String k, String? v) => v == null || v.isEmpty || v == 'null'
            ? const SizedBox.shrink()
            : Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: Row(children: [SizedBox(width: 150, child: Text(tr(k), style: TextStyle(color: hint))), Expanded(child: Text(tr(v)))]));
        return Scaffold(
          appBar: AppBar(title: Text(tr('${e['name']}')), actions: [
            if (s.can('interventions.manage') || s.can('interventions.do')) IconButton(tooltip: tr('Modifier'), onPressed: () => editEquipment(context, e), icon: const Icon(Icons.edit_outlined)),
          ]),
          body: ListView(padding: const EdgeInsets.all(16), children: [
            SectionCard(
              title: partnerName(e['partner_id']).isEmpty ? 'Équipement' : partnerName(e['partner_id']),
              trailing: StatusChip(equipmentStatuses[e['status']] ?? 'En service', color: e['status'] == 'broken' ? YColors.danger : YColors.success),
              child: Column(children: [
                row('Catégorie', '${e['category'] ?? ''}'),
                row('Marque / modèle', [e['brand'], e['model']].where((x) => x != null).join(' ')),
                row('N° de série', '${e['serial_number'] ?? ''}'),
                row('Emplacement', '${e['location'] ?? ''}'),
                row('Mise en service', e['installed_at'] == null ? null : dateFr(e['installed_at'])),
                row('Garantie jusqu\'au', e['warranty_until'] == null ? null : dateFr(e['warranty_until'])),
                row('Contrat', contract == null ? null : '${contract['number'] ?? ''} · ${contract['name']}'),
                row('Notes', '${e['notes'] ?? ''}'),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Historique des interventions (${history.length})'),
              trailing: s.can('interventions.manage')
                  ? TextButton.icon(onPressed: () => editIntervention(context, equipmentId: id), icon: const Icon(Icons.add, size: 18), label: Text(tr('Intervention')))
                  : null,
              child: history.isEmpty ? Text(tr('Aucune intervention sur cet équipement.'), style: TextStyle(color: hint)) : Column(children: [for (final i in history) InterventionCard(i: i)]),
            ),
          ]),
        );
      },
    );
  }
}

Future<void> editEquipment(BuildContext context, [Map<String, dynamic>? e]) async {
  final sync = context.read<SyncEngine>();
  final f = {for (final k in ['name', 'category', 'brand', 'model', 'serial_number', 'location', 'notes']) k: TextEditingController(text: '${e?[k] ?? ''}')};
  var partner = e?['partner_id'] as String?;
  var contract = e?['contract_id'] as String?;
  var status = '${e?['status'] ?? 'active'}';
  DateTime? installed = DateTime.tryParse('${e?['installed_at'] ?? ''}');
  DateTime? warranty = DateTime.tryParse('${e?['warranty_until'] ?? ''}');
  final partners = LocalDb.all('partners').where((p) => p['type'] != 'supplier').toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
  await showFormDialog(context, title: e == null ? 'Nouvel équipement' : 'Équipement', builder: (c) => StatefulBuilder(builder: (c, set) {
      final contracts = LocalDb.all('it_contracts').where((k) => k['partner_id'] == partner).toList();
      Widget date(String label, DateTime? v, void Function(DateTime?) on) => OutlinedButton.icon(
            onPressed: () async {
              final d = await pickDate(c, initialDate: v ?? DateTime.now(), firstDate: DateTime(2000), lastDate: DateTime(2100));
              set(() => on(d));
            },
            icon: const Icon(Icons.event, size: 18),
            label: Text(tr(v == null ? label : '$label : ${DateFormat('dd/MM/yyyy').format(v)}')),
          );
      return SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String?>(
              initialValue: partners.any((p) => p['id'] == partner) ? partner : null,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Client')),
              items: [const DropdownMenuItem<String?>(value: null, child: Text('—')), for (final p in partners) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => set(() {
                partner = v;
                contract = null;
              }),
            ),
            TextField(controller: f['name'], decoration: InputDecoration(labelText: tr('Nom (ex. Split salle de réunion)'))),
            TextField(controller: f['category'], decoration: InputDecoration(labelText: tr('Catégorie (climatisation, électricité, informatique…)'))),
            Row(children: [
              Expanded(child: TextField(controller: f['brand'], decoration: InputDecoration(labelText: tr('Marque')))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: f['model'], decoration: InputDecoration(labelText: tr('Modèle')))),
            ]),
            TextField(controller: f['serial_number'], decoration: InputDecoration(labelText: tr('N° de série'))),
            TextField(controller: f['location'], decoration: InputDecoration(labelText: tr('Emplacement (site, étage, pièce)'))),
            if (contracts.isNotEmpty)
              DropdownButtonFormField<String?>(
                initialValue: contracts.any((k) => k['id'] == contract) ? contract : null,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Contrat de maintenance')),
                items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Hors contrat'))), for (final k in contracts) DropdownMenuItem<String?>(value: '${k['id']}', child: Text(tr('${k['number'] ?? ''} ${k['name']}')))],
                onChanged: (v) => set(() => contract = v),
              ),
            DropdownButtonFormField<String>(
              initialValue: status,
              decoration: InputDecoration(labelText: tr('État')),
              items: [for (final x in equipmentStatuses.entries) DropdownMenuItem(value: x.key, child: Text(tr(x.value)))],
              onChanged: (v) => set(() => status = v ?? status),
            ),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, children: [date('Mise en service', installed, (d) => installed = d ?? installed), date('Garantie', warranty, (d) => warranty = d ?? warranty)]),
            TextField(controller: f['notes'], maxLines: 2, decoration: InputDecoration(labelText: tr('Notes'))),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              if (e != null && context.read<Session>().can('interventions.manage'))
                TextButton(
                  onPressed: () async {
                    await sync.remove('it_equipments', '${e['id']}');
                    if (c.mounted) Navigator.pop(c);
                  },
                  child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                ),
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
              SaveButton(
                onPressed: () async {
                  if (f['name']!.text.trim().isEmpty) return;
                  try {
                    await sync.save('it_equipments', {
                      'id': e?['id'] ?? newId(),
                      'partner_id': partner,
                      'contract_id': contract,
                      for (final x in f.entries) x.key: x.value.text.trim().isEmpty ? null : x.value.text.trim(),
                      'status': status,
                      'installed_at': installed == null ? null : isoDate(installed!),
                      'warranty_until': warranty == null ? null : isoDate(warranty!),
                    });
                    if (c.mounted) Navigator.pop(c);
                  } catch (err) {
                    if (c.mounted) toast(c, errorText(err), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
            ]),
          ),
        ]),
      );
    }));
}

// ======================================================================
// Contrats de maintenance
// ======================================================================

class _ContractsTab extends StatefulWidget {
  const _ContractsTab({required this.canEdit});

  final bool canEdit;

  @override
  State<_ContractsTab> createState() => _ContractsTabState();
}

class _ContractsTabState extends State<_ContractsTab> {
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  @override
  Widget build(BuildContext context) {
    final canEdit = widget.canEdit;
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('it_contracts'),
      builder: (context, _, _) {
        final all = LocalDb.all('it_contracts');
        final base = all
            .where((k) => _q.isEmpty || '${k['number'] ?? ''} ${k['name']} ${partnerName(k['partner_id'])}'.toLowerCase().contains(_q))
            .where((k) => _f['partner'] == null || '${k['partner_id']}' == _f['partner'])
            .where((k) => _f['frequency'] == null || '${k['frequency']}' == _f['frequency'])
            .where((k) => _f['technician'] == null || '${k['technician_id']}' == _f['technician'])
            .toList();
        const statuses = {'': 'Tous', 'active': 'Actifs', 'suspended': 'Suspendus', 'ended': 'Terminés'};
        final rows = base.where((k) => _status.isEmpty || k['status'] == _status).toList()..sort((a, b) => '${a['status']}${partnerName(a['partner_id'])}'.compareTo('${b['status']}${partnerName(b['partner_id'])}'));
        return ListView(padding: const EdgeInsets.all(16), children: [
          Row(children: [
            Expanded(child: Text(tr('Visites préventives créées automatiquement chaque matin selon la fréquence, avec le technicien du contrat.'), style: TextStyle(color: Theme.of(context).hintColor))),
            if (canEdit) FilledButton.tonalIcon(onPressed: () => editContract(context), icon: const Icon(Icons.add), label: Text(tr('Contrat'))),
          ]),
          const SizedBox(height: 12),
          if (all.isNotEmpty)
            FilterBar(
              key: ValueKey(_gen),
              padding: const EdgeInsets.only(bottom: 12),
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              search: _q,
              searchHint: 'N°, contrat, client…',
              statuses: statuses,
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in statuses.keys) k: base.where((c) => k.isEmpty || c['status'] == k).length},
              filters: [
                FilterSpec('partner', 'Client', filterOptions(all, 'partner_id', label: (k) => partnerName(k['partner_id']))..removeWhere((k, v) => v.isEmpty), icon: Icons.business_outlined),
                FilterSpec('frequency', 'Fréquence des visites', {for (final e in contractFrequencies.entries) if (all.any((k) => k['frequency'] == e.key)) e.key: e.value}, icon: Icons.event_repeat_outlined),
                FilterSpec('technician', 'Technicien', filterOptions(all, 'technician_id', label: (k) => techName(k['technician_id']).isEmpty ? '#${k['technician_id']}' : techName(k['technician_id'])), icon: Icons.engineering_outlined),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f[k] = v),
              count: rows.length,
              countLabel: 'contrat',
              onReset: () => setState(() {
                _q = '';
                _status = '';
                _f.clear();
                _gen++;
              }),
            ),
          if (rows.isEmpty) EmptyState(icon: Icons.verified_outlined, title: tr('Aucun contrat'), message: tr('Un contrat fixe la fréquence des visites, le délai d\'intervention garanti et le forfait facturé.')),
          for (final k in rows)
            Card(
              child: ListTile(
                leading: Icon(Icons.verified_outlined, color: k['status'] == 'active' ? YColors.success : YColors.muted),
                title: Text(tr('${partnerName(k['partner_id'])} — ${k['name']}')),
                subtitle: Text(tr([
                  '${k['number'] ?? ''}',
                  'visites ${(contractFrequencies[k['frequency']] ?? '').toLowerCase()}',
                  if (k['next_visit'] != null) 'prochaine le ${dateFr(k['next_visit'])}',
                  if (k['response_hours'] != null) 'intervention sous ${k['response_hours']} h',
                  if (toDouble(k['price']) > 0) '${money(toDouble(k['price']), cur)} / ${(contractBillings[k['billing']] ?? '').toLowerCase()}',
                  if (k['invoiced_until'] != null) 'facturé jusqu\'au ${dateFr(k['invoiced_until'])}',
                ].join(' · '))),
                trailing: canEdit && k['status'] == 'active' && toDouble(k['price']) > 0 && k['billing'] != 'none'
                    ? TextButton(
                        onPressed: () async {
                          try {
                            final r = await context.read<Session>().api.post('interventions/contracts/${k['id']}/invoice');
                            if (!context.mounted) return;
                            await context.read<SyncEngine>().sync();
                            if (context.mounted) openSalesDocument(context, '${r['invoice']['id']}');
                          } catch (e) {
                            if (context.mounted) toast(context, errorText(e), error: true);
                          }
                        },
                        child: Text(tr('Facturer la période')),
                      )
                    : null,
                onTap: canEdit ? () => editContract(context, k) : null,
              ),
            ),
        ]);
      },
    );
  }
}

Future<void> editContract(BuildContext context, [Map<String, dynamic>? k]) async {
  final techs = await interventionTechnicians(context.read<Session>());
  if (!context.mounted) return;
  final sync = context.read<SyncEngine>();
  final name = TextEditingController(text: '${k?['name'] ?? ''}');
  final price = TextEditingController(text: k == null || toDouble(k['price']) == 0 ? '' : '${toDouble(k['price']).round()}');
  final hours = TextEditingController(text: '${k?['response_hours'] ?? ''}');
  final minutes = TextEditingController(text: '${k?['visit_minutes'] ?? 120}');
  final notes = TextEditingController(text: '${k?['notes'] ?? ''}');
  var partner = k?['partner_id'] as String?;
  var freq = '${k?['frequency'] ?? 'quarterly'}';
  var billing = '${k?['billing'] ?? 'quarterly'}';
  var status = '${k?['status'] ?? 'active'}';
  var labor = k?['labor_included'] != false;
  var parts = k?['parts_included'] == true;
  var tech = (k?['technician_id'] as num?)?.toInt();
  var start = DateTime.tryParse('${k?['start_date'] ?? ''}') ?? DateTime.now();
  DateTime? end = DateTime.tryParse('${k?['end_date'] ?? ''}');
  DateTime? next = DateTime.tryParse('${k?['next_visit'] ?? ''}');
  final partners = LocalDb.all('partners').where((p) => p['type'] != 'supplier').toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));
  await showFormDialog(context, title: k == null ? 'Nouveau contrat de maintenance' : 'Contrat ${k['number'] ?? ''}', builder: (c) => StatefulBuilder(builder: (c, set) {
      Widget date(String label, DateTime? v, void Function(DateTime?) on) => OutlinedButton.icon(
            onPressed: () async {
              final d = await pickDate(c, initialDate: v ?? DateTime.now(), firstDate: DateTime(2000), lastDate: DateTime(2100));
              if (d != null) set(() => on(d));
            },
            icon: const Icon(Icons.event, size: 18),
            label: Text(tr(v == null ? label : '$label : ${DateFormat('dd/MM/yyyy').format(v)}')),
          );
      return SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String?>(
              initialValue: partners.any((p) => p['id'] == partner) ? partner : null,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Client')),
              items: [for (final p in partners) DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => set(() => partner = v),
            ),
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Intitulé (ex. Maintenance climatisation siège)'))),
            const SizedBox(height: 8),
            Wrap(spacing: 8, runSpacing: 8, children: [
              date('Début', start, (d) => start = d ?? start),
              date('Fin', end, (d) => end = d),
              date('Prochaine visite', next, (d) => next = d),
            ]),
            Row(children: [
              Expanded(
                child: DropdownButtonFormField<String>(
                  initialValue: freq,
                  decoration: InputDecoration(labelText: tr('Visites préventives')),
                  items: [for (final x in contractFrequencies.entries) DropdownMenuItem(value: x.key, child: Text(tr(x.value)))],
                  onChanged: (v) => set(() => freq = v ?? freq),
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(width: 130, child: TextField(controller: minutes, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Durée visite (min)')))),
            ]),
            DropdownButtonFormField<int?>(
              initialValue: techs.any((u) => u['id'] == tech) ? tech : null,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Technicien attitré')),
              items: [const DropdownMenuItem<int?>(value: null, child: Text('—')), for (final u in techs) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}')))],
              onChanged: (v) => set(() => tech = v),
            ),
            TextField(controller: hours, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Délai d\'intervention garanti (heures)'))),
            Row(children: [
              Expanded(child: TextField(controller: price, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Forfait par période')))),
              const SizedBox(width: 8),
              Expanded(
                child: DropdownButtonFormField<String>(
                  initialValue: billing,
                  decoration: InputDecoration(labelText: tr('Facturation')),
                  items: [for (final x in contractBillings.entries) DropdownMenuItem(value: x.key, child: Text(tr(x.value)))],
                  onChanged: (v) => set(() => billing = v ?? billing),
                ),
              ),
            ]),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Main-d\'œuvre incluse')), value: labor, onChanged: (v) => set(() => labor = v)),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Pièces incluses')), value: parts, onChanged: (v) => set(() => parts = v)),
            DropdownButtonFormField<String>(
              initialValue: status,
              decoration: InputDecoration(labelText: tr('État')),
              items: [DropdownMenuItem(value: 'active', child: Text(tr('Actif'))), DropdownMenuItem(value: 'suspended', child: Text(tr('Suspendu'))), DropdownMenuItem(value: 'ended', child: Text(tr('Terminé')))],
              onChanged: (v) => set(() => status = v ?? status),
            ),
            TextField(controller: notes, maxLines: 2, decoration: InputDecoration(labelText: tr('Notes'))),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
              SaveButton(
                onPressed: () async {
                  if (partner == null || name.text.trim().isEmpty) {
                    toast(c, tr('Client et intitulé obligatoires.'), error: true);
                    return;
                  }
                  try {
                    await sync.save('it_contracts', {
                      'id': k?['id'] ?? newId(),
                      'partner_id': partner,
                      'name': name.text.trim(),
                      'start_date': isoDate(start),
                      'end_date': end == null ? null : isoDate(end!),
                      'next_visit': next == null ? null : isoDate(next!),
                      'frequency': freq,
                      'visit_minutes': int.tryParse(minutes.text) ?? 120,
                      'response_hours': int.tryParse(hours.text),
                      'technician_id': tech,
                      'price': double.tryParse(price.text.replaceAll(RegExp(r'[\s ]'), '')) ?? 0,
                      'billing': billing,
                      'labor_included': labor,
                      'parts_included': parts,
                      'status': status,
                      'notes': notes.text.trim().isEmpty ? null : notes.text.trim(),
                    });
                    if (c.mounted) Navigator.pop(c);
                  } catch (err) {
                    if (c.mounted) toast(c, errorText(err), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
            ]),
          ),
        ]),
      );
    }));
}

// ======================================================================
// Statistiques
// ======================================================================

class _ItStatsTab extends StatefulWidget {
  const _ItStatsTab();

  @override
  State<_ItStatsTab> createState() => _ItStatsTabState();
}

class _ItStatsTabState extends State<_ItStatsTab> {
  Map<String, dynamic>? _d;
  Object? _error;
  late DateTimeRange _range = DateTimeRange(start: DateTime(DateTime.now().year, DateTime.now().month), end: _day(DateTime.now()));

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await context.read<Session>().api.get('interventions/stats', query: {'from': isoDate(_range.start), 'to': isoDate(_range.end)});
      if (mounted) setState(() => (_d = Map<String, dynamic>.from(d), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final d = _d!;
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    Widget kpi(String label, String value, IconData icon, {Color color = YColors.blue}) => SizedBox(
          width: 210,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Row(children: [
                Icon(icon, color: color),
                const SizedBox(width: 10),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(tr(value), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)), Text(tr(label), style: TextStyle(fontSize: 12, color: hint))])),
              ]),
            ),
          ),
        );
    final techs = [for (final t in (d['technicians'] as List)) asMap(t)];
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Row(children: [
          OutlinedButton.icon(
            onPressed: () async {
              final r = await showDateRangePicker(context: context, firstDate: DateTime(2020), lastDate: DateTime(2100), initialDateRange: _range);
              if (r != null) {
                setState(() => _range = r);
                _load();
              }
            },
            icon: const Icon(Icons.date_range),
            label: Text(tr('${DateFormat('dd/MM/yyyy').format(_range.start)} – ${DateFormat('dd/MM/yyyy').format(_range.end)}')),
          ),
        ]),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          kpi('Interventions', '${d['total']}', Icons.engineering_outlined),
          kpi('Terminées', '${d['done']}', Icons.task_alt, color: YColors.success),
          kpi('En cours (toutes dates)', '${d['open']}', Icons.pending_actions, color: YColors.warning),
          kpi('Heures passées', '${d['hours']} h', Icons.timer_outlined),
          kpi('Durée moyenne', _hm((d['avg_minutes'] as num?)?.toInt()), Icons.av_timer),
          kpi('Délai de prise en charge', d['response_hours'] == null ? '—' : '${d['response_hours']} h', Icons.speed),
          kpi('Délais contractuels tenus', d['sla_rate'] == null ? '—' : '${d['sla_rate']} %', Icons.verified_outlined, color: YColors.success),
          kpi('Satisfaction', d['satisfaction'] == null ? '—' : '${d['satisfaction']} / 5', Icons.star_outline, color: YColors.warning),
          kpi('Pièces posées', money(toDouble(d['parts']), cur), Icons.build_outlined),
          kpi('À facturer', '${d['to_invoice']}', Icons.request_quote_outlined, color: YColors.warning),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Techniciens'),
          child: techs.isEmpty
              ? Text('—', style: TextStyle(color: hint))
              : Column(children: [
                  for (final t in techs)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      leading: const Icon(Icons.engineering),
                      title: Text(tr('${t['name']}')),
                      subtitle: Text(tr('${t['done']} intervention(s) · ${t['hours']} h')),
                      trailing: t['satisfaction'] == null
                          ? null
                          : Row(mainAxisSize: MainAxisSize.min, children: [
                              Text(tr('${t['satisfaction']}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                              const Icon(Icons.star, size: 16, color: YColors.warning),
                            ]),
                    ),
                ]),
        ),
      ]),
    );
  }
}
