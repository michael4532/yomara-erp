import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/custom_fields_editor.dart';
import '../widgets/filter_bar.dart';
import 'document_editor.dart';
import 'documents_screen.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const taskStatuses = {'todo': 'À faire', 'doing': 'En cours', 'review': 'À valider', 'done': 'Terminé'};
const taskStatusColors = {'todo': YColors.muted, 'doing': YColors.blue, 'review': YColors.warning, 'done': YColors.success};
const projectStatuses = {'planned': 'Planifié', 'active': 'En cours', 'on_hold': 'En pause', 'done': 'Terminé', 'cancelled': 'Annulé'};
const projectStatusColors = {'planned': YColors.muted, 'active': YColors.blue, 'on_hold': YColors.warning, 'done': YColors.success, 'cancelled': YColors.danger};
const billingModes = {'time': 'Au temps passé', 'fixed': 'Forfait (jalons)', 'none': 'Non facturé (interne)'};
const projectColors = ['#1F6FEB', '#12A150', '#E8A317', '#D93025', '#7B3FE4', '#E4407B', '#0E7C86', '#0B1B3A'];

Color _hex(String? s, [Color f = YColors.blue]) {
  final h = (s ?? '').replaceAll('#', '');
  final v = int.tryParse(h, radix: 16);
  return h.length == 6 && v != null ? Color(0xFF000000 | v) : f;
}

DateTime? _date(dynamic v) => v == null || '$v'.isEmpty ? null : DateTime.tryParse('$v');
String _day(DateTime d) => DateFormat('yyyy-MM-dd').format(d);
/// Ajout de jours calendaires (insensible aux changements d'heure).
DateTime addDays(DateTime d, int n) => DateTime(d.year, d.month, d.day + n);
int daysBetween(DateTime a, DateTime b) => DateTime.utc(b.year, b.month, b.day).difference(DateTime.utc(a.year, a.month, a.day)).inDays;

DateTime _today() {
  final n = DateTime.now();
  return DateTime(n.year, n.month, n.day);
}

String hoursFr(num h) {
  final total = (h * 60).round();
  final hh = total ~/ 60;
  final mm = total % 60;
  return mm == 0 ? '$hh h' : '$hh h ${mm.toString().padLeft(2, '0')}';
}

/// Utilisateurs (cache local pour le hors ligne).
Future<List<Map<String, dynamic>>> projetUsers(Session s) async {
  List<Map<String, dynamic>> cached() => [for (final u in (LocalDb.state().get('prj_users') as List? ?? const [])) asMap(u)];
  try {
    final r = await s.api.get('projets/users');
    final users = List<Map<String, dynamic>>.from(r['users']);
    await LocalDb.state().put('prj_users', users);
    return users;
  } catch (_) {
    final c = cached();
    return c.isEmpty
        ? [
            {'id': s.user['id'], 'name': s.user['name']},
          ]
        : c;
  }
}

List<Map<String, dynamic>> _cachedUsers() => [for (final u in (LocalDb.state().get('prj_users') as List? ?? const [])) asMap(u)];

String _userName(dynamic id) => _cachedUsers().where((u) => u['id'] == id).map((u) => '${u['name']}').firstOrNull ?? '';

String _initials(String name) {
  final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
  if (parts.isEmpty) return '?';
  return (parts.first[0] + (parts.length > 1 ? parts.last[0] : '')).toUpperCase();
}

/// Indicateurs d'un projet calculés sur l'appareil (fonctionne hors ligne).
class ProjectMetrics {
  ProjectMetrics(this.project, List<Map<String, dynamic>> allTasks, List<Map<String, dynamic>> allTimes, List<Map<String, dynamic>> allMilestones)
      : tasks = allTasks.where((t) => t['project_id'] == project['id']).toList(),
        times = allTimes.where((t) => t['project_id'] == project['id']).toList(),
        milestones = allMilestones.where((m) => m['project_id'] == project['id']).toList();

  final Map<String, dynamic> project;
  final List<Map<String, dynamic>> tasks;
  final List<Map<String, dynamic>> times;
  final List<Map<String, dynamic>> milestones;

  int get done => tasks.where((t) => t['status'] == 'done').length;
  int get progress => tasks.isEmpty ? 0 : (done * 100 / tasks.length).round();
  int get late => tasks.where((t) => t['status'] != 'done' && (_date(t['due_date'])?.isBefore(_today()) ?? false)).length;
  double get hours => times.fold(0.0, (s, t) => s + toDouble(t['hours']));
  double get estimate => tasks.fold(0.0, (s, t) => s + toDouble(t['estimate_hours']));
  double get toInvoiceHours => times.where((t) => t['billable'] != false && t['invoice_id'] == null && t['pack_id'] == null && project['pack_id'] == null).fold(0.0, (s, t) => s + toDouble(t['hours']));
  double get rate => toDouble(project['hourly_rate']);
  double get budgetHours => toDouble(project['budget_hours']);
  double get budgetAmount => toDouble(project['budget_amount']);
  double get toInvoiceAmount => project['billing_mode'] == 'time'
      ? toInvoiceHours * rate
      : milestones.where((m) => m['status'] == 'reached' && m['invoice_id'] == null).fold(0.0, (s, m) => s + toDouble(m['amount']));

  /// Part du budget consommée (heures, ou montant au taux horaire).
  double? get budgetUsed => budgetHours > 0 ? hours / budgetHours : (budgetAmount > 0 && rate > 0 ? hours * rate / budgetAmount : null);
}

// ======================================================================
// Écran principal
// ======================================================================

class ProjetsScreen extends StatefulWidget {
  const ProjetsScreen({super.key});

  @override
  State<ProjetsScreen> createState() => _ProjetsScreenState();
}

class _ProjetsScreenState extends State<ProjetsScreen> with SingleTickerProviderStateMixin {
  late final TabController _tabs = TabController(length: 3, vsync: this);
  String _filter = 'active';
  String _q = '';
  final Map<String, String?> _f = {};
  FilterPeriod? _period;
  int _gen = 0;

  static bool _overdue(Map<String, dynamic> p) {
    final end = _date(p['end_date']);
    return end != null && end.isBefore(_today()) && !['done', 'cancelled'].contains(p['status']);
  }

  static bool _statusMatch(Map<String, dynamic> p, String f) => switch (f) {
        'active' => ['planned', 'active', 'on_hold'].contains(p['status']),
        'done' => ['done', 'cancelled'].contains(p['status']),
        'late' => _overdue(p),
        '' => true,
        _ => p['status'] == f,
      };

  @override
  void initState() {
    super.initState();
    projetUsers(context.read<Session>()).then((_) {
      if (mounted) setState(() {});
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('projets.manage');
    return Column(children: [
      PageHeader(
        title: tr('Projets'),
        subtitle: tr('Tâches, planning, temps passé et facturation · fonctionne hors ligne'),
        actions: [
          const TimerChip(),
          if (manage) FilledButton.icon(onPressed: () => editProject(context), icon: const Icon(Icons.add), label: Text(tr('Projet'))),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Projets')), Tab(text: tr('Mes tâches')), Tab(text: tr('Mes temps'))]),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('prj_projects'),
          builder: (context, _, _) => ValueListenableBuilder(
            valueListenable: LocalDb.listen('prj_tasks'),
            builder: (context, _, _) => ValueListenableBuilder(
              valueListenable: LocalDb.listen('prj_timesheets'),
              builder: (context, _, _) {
                final projects = LocalDb.all('prj_projects');
                final tasks = LocalDb.all('prj_tasks');
                final times = LocalDb.all('prj_timesheets');
                final milestones = LocalDb.all('prj_milestones');
                return TabBarView(controller: _tabs, children: [
                  _projectsTab(context, projects, tasks, times, milestones, manage),
                  MyTasks(projects: projects, tasks: tasks),
                  WeekTimesheet(projects: projects, tasks: tasks, times: times),
                ]);
              },
            ),
          ),
        ),
      ),
    ]);
  }

  Widget _projectsTab(BuildContext context, List<Map<String, dynamic>> projects, List<Map<String, dynamic>> tasks, List<Map<String, dynamic>> times, List<Map<String, dynamic>> ms, bool manage) {
    final cur = context.read<Session>().currency;
    String partnerName(Map<String, dynamic> p) => '${LocalDb.get('partners', p['partner_id'] as String?)?['name'] ?? ''}';
    List<dynamic> team(Map<String, dynamic> p) => [p['manager_id'], ...(p['member_ids'] as List? ?? const [])].where((x) => x != null).toList();
    final base = projects
        .where((p) => _q.isEmpty || '${p['name']} ${p['code'] ?? ''} ${partnerName(p)}'.toLowerCase().contains(_q))
        .where((p) => _f['partner'] == null || (_f['partner'] == '-' ? p['partner_id'] == null : '${p['partner_id']}' == _f['partner']))
        .where((p) => _f['manager'] == null || '${p['manager_id']}' == _f['manager'])
        .where((p) => _f['member'] == null || team(p).any((u) => '$u' == _f['member']))
        .where((p) => _f['billing'] == null || '${p['billing_mode']}' == _f['billing'])
        .where((p) => _period == null || _period!.contains(p['end_date'] ?? p['start_date']))
        .toList();
    const statuses = {'': 'Tous', 'active': 'En cours', 'late': 'En retard', 'planned': 'Planifiés', 'on_hold': 'En pause', 'done': 'Terminés'};
    final userOpts = <String, String>{
      for (final p in projects)
        for (final u in team(p))
          if (_userName(u).isNotEmpty) '$u': _userName(u),
    };
    final userSorted = Map.fromEntries(userOpts.entries.toList()..sort((a, b) => a.value.toLowerCase().compareTo(b.value.toLowerCase())));
    final rows = base.where((p) => _statusMatch(p, _filter)).toList()
      ..sort((a, b) => '${a['end_date'] ?? '9999'}'.compareTo('${b['end_date'] ?? '9999'}'));
    final all = [for (final p in projects.where((p) => p['status'] == 'active')) ProjectMetrics(p, tasks, times, ms)];
    final me = context.read<Session>().user['id'];
    final weekStart = addDays(_today(), -(_today().weekday - 1));
    final myWeek = times.where((t) => t['user_id'] == me && !(_date(t['date'])?.isBefore(weekStart) ?? true)).fold(0.0, (s, t) => s + toDouble(t['hours']));

    Widget kpi(String label, String value, IconData icon, {Color color = YColors.blue}) => KpiBox(
          width: 220,
          child: StatTile(label: label, value: value, icon: icon, color: color),
        );

    return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
      KpiWrap(children: [
        kpi('Projets en cours', '${all.length}', Icons.task_alt),
        kpi('Tâches en retard', '${all.fold(0, (s, m) => s + m.late)}', Icons.alarm, color: YColors.danger),
        kpi('Mes heures cette semaine', hoursFr(myWeek), Icons.schedule),
        if (manage) kpi('À facturer', money(all.fold<double>(0, (s, m) => s + m.toInvoiceAmount), cur), Icons.request_quote_outlined, color: YColors.success),
      ]),
      const SizedBox(height: 12),
      FilterBar(
        key: ValueKey(_gen),
        padding: const EdgeInsets.only(bottom: 12),
        onSearch: (v) => setState(() => _q = v.toLowerCase()),
        search: _q,
        searchHint: 'Projet, client, code…',
        statuses: statuses,
        status: _filter,
        onStatus: (v) => setState(() => _filter = v),
        statusCounts: {for (final k in statuses.keys) k: base.where((p) => _statusMatch(p, k)).length},
        filters: [
          FilterSpec('partner', 'Client', {...filterOptions(projects, 'partner_id', label: partnerName)..removeWhere((k, v) => v.isEmpty), if (projects.any((p) => p['partner_id'] == null)) '-': 'Projet interne'}, icon: Icons.business_outlined),
          FilterSpec('manager', 'Chef de projet', {for (final p in projects) if (p['manager_id'] != null && _userName(p['manager_id']).isNotEmpty) '${p['manager_id']}': _userName(p['manager_id'])}, icon: Icons.manage_accounts_outlined),
          FilterSpec('member', 'Membre', userSorted, icon: Icons.group_outlined),
          FilterSpec('billing', 'Facturation', {for (final p in projects) if (p['billing_mode'] != null) '${p['billing_mode']}': billingModes[p['billing_mode']] ?? '${p['billing_mode']}'}, icon: Icons.request_quote_outlined),
        ],
        values: _f,
        onFilter: (k, v) => setState(() => _f[k] = v),
        showPeriod: true,
        period: _period,
        onPeriod: (p) => setState(() => _period = p),
        count: rows.length,
        countLabel: 'projet',
        onReset: () => setState(() {
          _filter = 'active';
          _q = '';
          _f.clear();
          _period = null;
          _gen++;
        }),
      ),
      if (projects.isNotEmpty && rows.isEmpty)
        EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucun projet ne correspond à ces filtres.'))
      else if (rows.isEmpty)
        EmptyState(
          icon: Icons.task_alt,
          title: tr('Aucun projet'),
          message: tr('Créez un projet pour organiser les tâches de l\'équipe et suivre le temps passé.'),
          action: manage ? FilledButton.icon(onPressed: () => editProject(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau projet'))) : null,
        )
      else
        Wrap(spacing: 12, runSpacing: 12, children: [
          for (final p in rows) _ProjectCard(m: ProjectMetrics(p, tasks, times, ms), manage: manage),
        ]),
    ]);
  }
}

class _ProjectCard extends StatelessWidget {
  const _ProjectCard({required this.m, required this.manage});

  final ProjectMetrics m;
  final bool manage;

  @override
  Widget build(BuildContext context) {
    final p = m.project;
    final color = _hex(p['color'] as String?);
    final partner = LocalDb.get('partners', p['partner_id'] as String?);
    final hint = Theme.of(context).hintColor;
    final used = m.budgetUsed;
    final end = _date(p['end_date']);
    final overdue = end != null && end.isBefore(_today()) && !['done', 'cancelled'].contains(p['status']);
    return SizedBox(
      width: 340,
      child: Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ProjectScreen(id: p['id'] as String))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Container(height: 5, color: color),
            Padding(
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(tr('${p['name']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16), maxLines: 1, overflow: TextOverflow.ellipsis)),
                  StatusChip(projectStatuses[p['status']] ?? '${p['status']}', color: projectStatusColors[p['status']] ?? YColors.muted),
                ]),
                const SizedBox(height: 2),
                Text(tr([if ((p['code'] ?? '').toString().isNotEmpty) p['code'], partner?['name'] ?? 'Projet interne'].join(' · ')), style: TextStyle(color: hint, fontSize: 13)),
                const SizedBox(height: 12),
                Row(children: [
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(6),
                      child: LinearProgressIndicator(value: m.progress / 100, minHeight: 8, color: color, backgroundColor: color.withValues(alpha: .12)),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Text(tr('${m.progress} %'), style: const TextStyle(fontWeight: FontWeight.w700)),
                ]),
                const SizedBox(height: 10),
                Wrap(spacing: 12, runSpacing: 4, children: [
                  _Fact(Icons.checklist, '${m.done}/${m.tasks.length} tâches'),
                  if (m.late > 0) _Fact(Icons.alarm, '${m.late} en retard', color: YColors.danger),
                  _Fact(Icons.schedule, '${hoursFr(m.hours)}${m.budgetHours > 0 ? ' / ${hoursFr(m.budgetHours)}' : ''}', color: (used ?? 0) > 1 ? YColors.danger : null),
                  if (end != null) _Fact(Icons.event, dateFr(p['end_date']), color: overdue ? YColors.danger : null),
                ]),
                if (used != null) ...[
                  const SizedBox(height: 8),
                  Text(tr('Budget consommé : ${(used * 100).round()} %'), style: TextStyle(fontSize: 12, color: used > 1 ? YColors.danger : (used > .8 ? YColors.warning : hint), fontWeight: FontWeight.w600)),
                ],
                const SizedBox(height: 8),
                Row(children: [
                  for (final uid in [p['manager_id'], ...(p['member_ids'] as List? ?? const [])].where((x) => x != null).toSet().take(6))
                    Padding(
                      padding: const EdgeInsets.only(right: 4),
                      child: Tooltip(message: _userName(uid), child: CircleAvatar(radius: 13, backgroundColor: color.withValues(alpha: .15), child: Text(tr(_initials(_userName(uid))), style: TextStyle(fontSize: 10, color: color, fontWeight: FontWeight.w700)))),
                    ),
                ]),
              ]),
            ),
          ]),
        ),
      ),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact(this.icon, this.text, {this.color});

  final IconData icon;
  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ?? Theme.of(context).hintColor;
    return Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 15, color: c), const SizedBox(width: 4), Text(tr(text), style: TextStyle(fontSize: 12, color: c))]);
  }
}

// ======================================================================
// Formulaire projet
// ======================================================================

Future<void> editProject(BuildContext context, [Map<String, dynamic>? project]) async {
  final session = context.read<Session>();
  final sync = context.read<SyncEngine>();
  final users = await projetUsers(session);
  if (!context.mounted) return;
  final p = {...?project};
  final c = {for (final k in ['name', 'code', 'description', 'tags']) k: TextEditingController(text: '${p[k] ?? ''}')};
  final rate = TextEditingController(text: p['hourly_rate'] == null ? '' : number(toDouble(p['hourly_rate'])).replaceAll(RegExp(r'\s'), ''));
  final budgetH = TextEditingController(text: toDouble(p['budget_hours']) == 0 ? '' : '${toDouble(p['budget_hours'])}');
  final budgetA = TextEditingController(text: toDouble(p['budget_amount']) == 0 ? '' : '${toDouble(p['budget_amount']).round()}');
  var partner = p['partner_id'] as String?;
  var pack = p['pack_id'] as String?;
  final withPacks = session.hasModule('prestations') && LocalDb.hasEntity('pr_packs');
  var status = '${p['status'] ?? 'active'}';
  var billing = '${p['billing_mode'] ?? 'time'}';
  var color = '${p['color'] ?? projectColors[Random().nextInt(projectColors.length)]}';
  var manager = (p['manager_id'] ?? session.user['id']) as int?;
  final members = <int>{for (final m in (p['member_ids'] as List? ?? const [])) (m as num).toInt()};
  final custom = asMap(p['custom']);
  final champs = session.hasModule('champs');
  DateTime? start = _date(p['start_date']) ?? (project == null ? _today() : null);
  DateTime? end = _date(p['end_date']);
  final partners = LocalDb.all('partners').where((x) => x['type'] != 'supplier').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));

  await showFormDialog(
    context,
    title: project == null ? 'Nouveau projet' : 'Projet',
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(label: tr('Nom du projet'), child: TextField(controller: c['name'], autofocus: project == null)),
        Row(children: [
          Expanded(child: Field(label: tr('Code (facultatif)'), child: TextField(controller: c['code']))),
          const SizedBox(width: 12),
          Expanded(
            child: Field(
              label: tr('Statut'),
              child: DropdownButtonFormField<String>(
                initialValue: status,
                items: [for (final s in projectStatuses.entries) DropdownMenuItem(value: s.key, child: Text(tr(s.value)))],
                onChanged: (v) => set(() => status = v ?? status),
              ),
            ),
          ),
        ]),
        Field(
          label: tr('Client'),
          child: DropdownButtonFormField<String?>(
            initialValue: partners.any((x) => x['id'] == partner) ? partner : null,
            isExpanded: true,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr('Projet interne (sans client)'))),
              for (final x in partners) DropdownMenuItem<String?>(value: x['id'] as String, child: Text(tr('${x['name']}'), overflow: TextOverflow.ellipsis)),
            ],
            onChanged: (v) => set(() {
              partner = v;
              if (pack != null && LocalDb.get('pr_packs', pack!)?['partner_id'] != v) pack = null;
            }),
          ),
        ),
        if (withPacks && partner != null)
          Builder(builder: (_) {
            final packs = LocalDb.all('pr_packs').where((x) => x['partner_id'] == partner && (x['id'] == pack || x['status'] == 'active' || x['status'] == 'exhausted')).toList();
            if (packs.isEmpty) return const SizedBox.shrink();
            return Field(
              label: tr('Forfait d\'heures du client'),
              child: DropdownButtonFormField<String?>(
                initialValue: packs.any((x) => x['id'] == pack) ? pack : null,
                isExpanded: true,
                items: [
                  DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun : le temps est facturé par le projet'))),
                  for (final x in packs)
                    DropdownMenuItem<String?>(
                      value: x['id'] as String,
                      child: Text(tr('${x['name']} · reste ${hoursFr(toDouble(x['remaining']))}'), overflow: TextOverflow.ellipsis),
                    ),
                ],
                onChanged: (v) => set(() => pack = v),
              ),
            );
          }),
        if (withPacks && pack != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(tr('Le temps facturable saisi sur ce projet est décompté du forfait et n\'est pas refacturé par le projet.'), style: TextStyle(fontSize: 12, color: Theme.of(ctx).hintColor)),
          ),
        Row(children: [
          Expanded(
            child: Field(
              label: tr('Début'),
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await pickDate(ctx, initialDate: start ?? _today(), firstDate: DateTime(2020), lastDate: DateTime(2100));
                  if (d != null) set(() => start = d);
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr(start == null ? '—' : DateFormat('dd/MM/yyyy').format(start!))),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Field(
              label: tr('Fin prévue'),
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await pickDate(ctx, initialDate: end ?? (start ?? _today()).add(const Duration(days: 30)), firstDate: DateTime(2020), lastDate: DateTime(2100));
                  if (d != null) set(() => end = d);
                },
                icon: const Icon(Icons.flag_outlined, size: 18),
                label: Text(tr(end == null ? '—' : DateFormat('dd/MM/yyyy').format(end!))),
              ),
            ),
          ),
        ]),
        Field(
          label: tr('Facturation'),
          child: SegmentedButton<String>(
            segments: [for (final b in billingModes.entries) ButtonSegment(value: b.key, label: Text(tr(b.value), style: const TextStyle(fontSize: 12)))],
            selected: {billing},
            onSelectionChanged: (s) => set(() => billing = s.first),
          ),
        ),
        Row(children: [
          if (billing == 'time')
            Expanded(child: Field(label: tr('Taux horaire (${session.currency})'), child: TextField(controller: rate, keyboardType: TextInputType.number))),
          if (billing == 'time') const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Budget en heures'), child: TextField(controller: budgetH, keyboardType: TextInputType.number))),
          const SizedBox(width: 12),
          Expanded(child: Field(label: tr('Budget de coûts (${session.currency})'), child: TextField(controller: budgetA, keyboardType: TextInputType.number))),
        ]),
        Field(
          label: tr('Chef de projet'),
          child: DropdownButtonFormField<int>(
            initialValue: users.any((u) => u['id'] == manager) ? manager : null,
            isExpanded: true,
            items: [for (final u in users) DropdownMenuItem(value: u['id'] as int, child: Text(tr('${u['name']}')))],
            onChanged: (v) => set(() => manager = v),
          ),
        ),
        Field(
          label: tr('Équipe'),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final u in users)
              FilterChip(label: Text(tr('${u['name']}')), selected: members.contains(u['id']), onSelected: (v) => set(() => v ? members.add(u['id'] as int) : members.remove(u['id']))),
          ]),
        ),
        Field(
          label: tr('Couleur'),
          child: Wrap(spacing: 8, children: [
            for (final col in projectColors)
              InkWell(
                onTap: () => set(() => color = col),
                child: Container(width: 26, height: 26, decoration: BoxDecoration(color: _hex(col), shape: BoxShape.circle, border: Border.all(color: color == col ? YColors.night : Colors.transparent, width: 3))),
              ),
          ]),
        ),
        Field(label: tr('Description'), child: TextField(controller: c['description'], maxLines: 4)),
        if (champs) CustomFieldsEditor(entity: 'prj_projects', values: custom, creating: project == null),
        const SizedBox(height: 8),
        Row(children: [
          if (project != null && session.can('projets.manage'))
            TextButton(
              onPressed: () async {
                if (!await confirm(ctx, tr('Supprimer le projet ?'), tr('Ses tâches, jalons et temps seront supprimés.'), danger: true, ok: tr('Supprimer'))) return;
                await sync.remove('prj_projects', project['id'] as String);
                if (ctx.mounted) Navigator.pop(ctx);
                if (context.mounted) Navigator.of(context).popUntil((r) => r.isFirst);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              if (c['name']!.text.trim().isEmpty) {
                toast(ctx, tr('Donnez un nom au projet.'), error: true);
                return;
              }
              final err = champs ? customRequiredError('prj_projects', custom) : null;
              if (err != null) {
                toast(ctx, err, error: true);
                return;
              }
              await sync.save('prj_projects', {
                'id': project?['id'] ?? newId(),
                if (champs) 'custom': custom,
                'name': c['name']!.text.trim(),
                'code': c['code']!.text.trim().isEmpty ? null : c['code']!.text.trim(),
                'partner_id': partner,
                if (withPacks) 'pack_id': partner == null ? null : pack,
                'status': status,
                'billing_mode': billing,
                'hourly_rate': double.tryParse(rate.text.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.')) ?? 0,
                'budget_hours': double.tryParse(budgetH.text.replaceAll(',', '.')) ?? 0,
                'budget_amount': double.tryParse(budgetA.text.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.')) ?? 0,
                'start_date': start == null ? null : _day(start!),
                'end_date': end == null ? null : _day(end!),
                'manager_id': manager,
                'member_ids': members.toList(),
                'color': color,
                'description': c['description']!.text.trim().isEmpty ? null : c['description']!.text.trim(),
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
// Fiche projet
// ======================================================================

class ProjectScreen extends StatefulWidget {
  const ProjectScreen({super.key, required this.id});

  final String id;

  @override
  State<ProjectScreen> createState() => _ProjectScreenState();
}

class _ProjectScreenState extends State<ProjectScreen> {
  String? _assignee; // filtre : 'me' ou id
  String _q = '';
  final Map<String, String?> _f = {};
  int _gen = 0;
  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('projets.manage');
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('prj_projects'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('prj_tasks'),
        builder: (context, _, _) => ValueListenableBuilder(
          valueListenable: LocalDb.listen('prj_timesheets'),
          builder: (context, _, _) => ValueListenableBuilder(
            valueListenable: LocalDb.listen('prj_milestones'),
            builder: (context, _, _) {
              final p = LocalDb.get('prj_projects', widget.id);
              if (p == null) return Scaffold(appBar: AppBar(), body: EmptyState(icon: Icons.task_alt, title: tr('Projet introuvable'), message: tr('Il a peut-être été supprimé.')));
              final m = ProjectMetrics(p, LocalDb.all('prj_tasks'), LocalDb.all('prj_timesheets'), LocalDb.all('prj_milestones'));
              final color = _hex(p['color'] as String?);
              final partner = LocalDb.get('partners', p['partner_id'] as String?);
              final me = s.user['id'];
              final today = _today();
              final tasks = m.tasks
                  .where((t) => _assignee == null || (_assignee == 'me' ? t['assignee_id'] == me : (_assignee == '-' ? t['assignee_id'] == null : '${t['assignee_id']}' == _assignee)))
                  .where((t) => _q.isEmpty || '${t['title']} ${t['description'] ?? ''} ${t['tags'] ?? ''}'.toLowerCase().contains(_q))
                  .where((t) => _f['priority'] == null || '${((t['priority'] as num?) ?? 0).toInt()}' == _f['priority'])
                  .where((t) => _f['milestone'] == null || '${t['milestone_id']}' == _f['milestone'])
                  .where((t) => _f['due'] == null || switch (_f['due']) {
                        'late' => t['status'] != 'done' && (_date(t['due_date'])?.isBefore(today) ?? false),
                        'week' => _date(t['due_date']) != null && !_date(t['due_date'])!.isBefore(today) && daysBetween(today, _date(t['due_date'])!) < 7,
                        'none' => t['due_date'] == null,
                        _ => true,
                      })
                  .toList();
              return DefaultTabController(
                length: manage ? 6 : 5,
                child: Scaffold(
                  appBar: AppBar(
                    title: Row(children: [
                      Container(width: 12, height: 12, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
                      const SizedBox(width: 10),
                      Flexible(child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis)),
                    ]),
                    actions: [
                      Padding(padding: const EdgeInsets.only(right: 8), child: Center(child: StatusChip(projectStatuses[p['status']] ?? '', color: projectStatusColors[p['status']] ?? YColors.muted))),
                      if (manage) IconButton(tooltip: tr('Modifier le projet'), onPressed: () => editProject(context, p), icon: const Icon(Icons.edit_outlined)),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 8),
                        child: FilledButton.icon(onPressed: () => editTask(context, project: p), icon: const Icon(Icons.add), label: Text(tr('Tâche'))),
                      ),
                    ],
                    bottom: TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
                      Tab(icon: Icon(Icons.view_kanban_outlined), text: tr('Tableau')),
                      Tab(icon: Icon(Icons.list_alt), text: tr('Liste')),
                      Tab(icon: Icon(Icons.view_timeline_outlined), text: tr('Planning')),
                      Tab(icon: Icon(Icons.schedule), text: tr('Temps')),
                      Tab(icon: Icon(Icons.request_quote_outlined), text: tr('Jalons & facturation')),
                      if (manage) Tab(icon: Icon(Icons.savings_outlined), text: tr('Rentabilité')),
                    ]),
                  ),
                  body: Column(children: [
                    _Summary(m: m, partner: partner, color: color, manage: manage),
                    FilterBar(
                      key: ValueKey(_gen),
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                      onSearch: (v) => setState(() => _q = v.toLowerCase()),
                      search: _q,
                      searchHint: 'Rechercher une tâche…',
                      filters: [
                        FilterSpec('assignee', 'Attribuée à', {
                          'me': 'Mes tâches',
                          for (final uid in {...m.tasks.map((t) => t['assignee_id']).whereType<int>()}.where((u) => u != me)) '$uid': _userName(uid),
                          if (m.tasks.any((t) => t['assignee_id'] == null)) '-': 'Non attribuées',
                        }, icon: Icons.person_outline),
                        FilterSpec('priority', 'Priorité', {for (final t in m.tasks) '${((t['priority'] as num?) ?? 0).toInt()}': ((t['priority'] as num?) ?? 0).toInt() == 0 ? 'Sans priorité' : '★' * ((t['priority'] as num).toInt())}, icon: Icons.star_outline),
                        FilterSpec('milestone', 'Jalon', {for (final ms in m.milestones) '${ms['id']}': '${ms['name'] ?? ''}'}, icon: Icons.flag_outlined),
                        const FilterSpec('due', 'Échéance', {'late': 'En retard', 'week': 'Dans les 7 jours', 'none': 'Sans échéance'}, icon: Icons.event_outlined),
                      ],
                      values: {..._f, 'assignee': _assignee},
                      onFilter: (k, v) => setState(() => k == 'assignee' ? _assignee = v : _f[k] = v),
                      count: tasks.length,
                      countLabel: 'tâche',
                      onReset: () => setState(() {
                        _assignee = null;
                        _q = '';
                        _f.clear();
                        _gen++;
                      }),
                    ),
                    Expanded(
                      child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [
                        TaskBoard(project: p, tasks: tasks, times: m.times),
                        _TaskList(project: p, tasks: tasks, times: m.times),
                        GanttView(project: p, tasks: tasks, milestones: m.milestones),
                        _TimeTab(project: p, m: m),
                        _BillingTab(project: p, m: m, manage: manage),
                        if (manage) ProjectCostsTab(project: p),
                      ]),
                    ),
                  ]),
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

class _Summary extends StatelessWidget {
  const _Summary({required this.m, required this.partner, required this.color, required this.manage});

  final ProjectMetrics m;
  final Map<String, dynamic>? partner;
  final Color color;
  final bool manage;

  @override
  Widget build(BuildContext context) {
    final p = m.project;
    final cur = context.read<Session>().currency;
    final used = m.budgetUsed;
    final hint = Theme.of(context).hintColor;
    Widget item(String label, String value, {Color? c, Widget? extra}) => Padding(
          padding: const EdgeInsets.only(right: 28),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Text(tr(value), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: c)),
            Text(tr(label), style: TextStyle(fontSize: 12, color: hint)),
            ?extra,
          ]),
        );
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: Wrap(runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
        item('Client', '${partner?['name'] ?? 'Interne'}'),
        item('Avancement', '${m.progress} %',
            extra: SizedBox(width: 110, child: Padding(padding: const EdgeInsets.only(top: 4), child: LinearProgressIndicator(value: m.progress / 100, color: color, backgroundColor: color.withValues(alpha: .15))))),
        item('Tâches', '${m.done} / ${m.tasks.length}'),
        if (m.late > 0) item('En retard', '${m.late}', c: YColors.danger),
        item('Temps passé', '${hoursFr(m.hours)}${m.budgetHours > 0 ? ' / ${hoursFr(m.budgetHours)}' : ''}', c: (used ?? 0) > 1 ? YColors.danger : null),
        if (m.estimate > 0) item('Estimé', hoursFr(m.estimate)),
        if (manage && p['billing_mode'] != 'none') item('À facturer', money(m.toInvoiceAmount, cur), c: YColors.success),
        if (p['end_date'] != null) item('Échéance', dateFr(p['end_date'])),
      ]),
    );
  }
}

// ======================================================================
// Rentabilité
// ======================================================================

class ProjectCostsTab extends StatefulWidget {
  const ProjectCostsTab({super.key, required this.project});

  final Map<String, dynamic> project;

  @override
  State<ProjectCostsTab> createState() => _ProjectCostsTabState();
}

class _ProjectCostsTabState extends State<ProjectCostsTab> {
  Map<String, dynamic>? _c;
  Object? _error;
  String _sig = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant ProjectCostsTab old) {
    super.didUpdateWidget(old);
    final sig = '${widget.project['version']}-${widget.project['budget_amount']}';
    if (sig != _sig) _load();
  }

  Future<void> _load() async {
    _sig = '${widget.project['version']}-${widget.project['budget_amount']}';
    try {
      final r = await context.read<Session>().api.get('projets/projects/${widget.project['id']}/costs');
      if (mounted) setState(() => (_c = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_error != null && _c == null) return OnlineOnlyNotice(error: _error!, onRetry: _load);
    final c = _c;
    if (c == null) return const Center(child: CircularProgressIndicator());
    final cur = '${c['currency'] ?? context.read<Session>().currency}';
    final hint = Theme.of(context).hintColor;
    final budget = c['budget'] == null ? null : toDouble(c['budget']);
    final used = c['budget_used'] == null ? null : toDouble(c['budget_used']);
    final usedColor = used == null ? YColors.blue : (used >= 100 ? YColors.danger : (used >= 80 ? YColors.warning : YColors.success));
    final margin = toDouble(c['margin']);
    Widget tile(String label, String value, {Color? color, String? sub}) => KpiBox(
          width: 210,
          child: StatTile(label: label, value: value, detail: sub, color: color ?? YColors.blue, valueColor: color),
        );
    // Barre empilée : réalisé (MO + achats) puis engagé, sur le budget
    Widget bar() {
      final total = max(budget ?? 0, toDouble(c['forecast_cost'])).toDouble();
      if (total <= 0) return const SizedBox.shrink();
      Widget seg(double v, Color col) => v <= 0 ? const SizedBox.shrink() : Expanded(flex: max(1, (v / total * 1000).round()), child: Container(height: 18, color: col));
      final rest = total - toDouble(c['labour']) - toDouble(c['purchases']) - toDouble(c['expenses']) - toDouble(c['commitments']);
      return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: Row(children: [
            seg(toDouble(c['labour']), YColors.blue),
            seg(toDouble(c['purchases']), const Color(0xFF7B3FE4)),
            seg(toDouble(c['expenses']), const Color(0xFF0E7C86)),
            seg(toDouble(c['commitments']), YColors.warning),
            seg(rest, Theme.of(context).dividerColor.withValues(alpha: .4)),
          ]),
        ),
        const SizedBox(height: 6),
        Wrap(spacing: 14, children: [
          _Fact(Icons.square, 'Main-d\'œuvre ${money(toDouble(c['labour']), cur)}', color: YColors.blue),
          _Fact(Icons.square, 'Achats facturés ${money(toDouble(c['purchases']), cur)}', color: const Color(0xFF7B3FE4)),
          if (toDouble(c['expenses']) > 0) _Fact(Icons.square, 'Notes de frais ${money(toDouble(c['expenses']), cur)}', color: const Color(0xFF0E7C86)),
          _Fact(Icons.square, 'Commandes en cours ${money(toDouble(c['commitments']), cur)}', color: YColors.warning),
          if (budget != null) _Fact(Icons.flag_outlined, 'Budget ${money(budget, cur)}'),
        ]),
      ]);
    }

    final users = (c['by_user'] as List? ?? const []).cast<Map>();
    final orders = (c['orders'] as List? ?? const []).cast<Map>();
    final invoices = (c['invoices'] as List? ?? const []).cast<Map>();
    final expenses = (c['expense_list'] as List? ?? const []).cast<Map>();
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
        if (c['labour_configured'] != true)
          Card(
            color: YColors.warning.withValues(alpha: .08),
            child: ListTile(
              leading: const Icon(Icons.info_outline, color: YColors.warning),
              title: Text(tr('Coût horaire de la main-d\'œuvre non renseigné')),
              subtitle: Text(tr('Les heures passées ne sont pas valorisées : indiquez un coût horaire (général ou par personne).')),
              trailing: TextButton(onPressed: () => editLabourCosts(context).then((_) => _load()), child: Text(tr('Renseigner'))),
            ),
          ),
        KpiWrap(children: [
          tile('Budget consommé', used == null ? '—' : '${number(used, decimals: 1)} %', color: usedColor, sub: budget == null ? 'aucun budget de coûts' : 'reste ${money(toDouble(c['budget_left']), cur)}'),
          tile('Coûts réalisés', money(toDouble(c['actual_cost']), cur), sub: 'engagés : ${money(toDouble(c['committed_cost']), cur)}'),
          tile('Chiffre d\'affaires facturé', money(toDouble(c['revenue']), cur), sub: 'HT, avoirs déduits'),
          tile('Marge', money(margin, cur), color: margin < 0 ? YColors.danger : YColors.success, sub: c['margin_rate'] == null ? null : '${number(toDouble(c['margin_rate']), decimals: 1)} % du CA'),
          tile('Coût à terminaison', money(toDouble(c['forecast_cost']), cur), sub: c['forecast_margin'] == null ? null : 'marge prévue ${money(toDouble(c['forecast_margin']), cur)}'),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Coûts du projet'),
          trailing: TextButton.icon(onPressed: () => editLabourCosts(context).then((_) => _load()), icon: const Icon(Icons.badge_outlined, size: 18), label: Text(tr('Coûts horaires'))),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            bar(),
            if (users.isNotEmpty) ...[
              const SizedBox(height: 14),
              Text(tr('Main-d\'œuvre (${hoursFr(toDouble(c['hours']))})'), style: Theme.of(context).textTheme.titleSmall),
              for (final u in users)
                ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${u['name']}')), subtitle: Text(tr('${hoursFr(toDouble(u['hours']))} × ${money(toDouble(u['hourly_cost']), cur)}')), trailing: Text(tr(money(toDouble(u['cost']), cur)), style: const TextStyle(fontWeight: FontWeight.w700))),
            ],
          ]),
        ),
        if (orders.isNotEmpty || invoices.isNotEmpty || expenses.isNotEmpty)
          SectionCard(
            title: tr('Achats et frais rattachés'),
            child: Column(children: [
              for (final o in orders)
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.shopping_cart_outlined),
                  title: Text(tr('${o['number']} · ${o['partner_name']}')),
                  subtitle: Text(tr('Commande ${money(toDouble(o['amount']), cur)} HT · facturé ${money(toDouble(o['invoiced']), cur)}')),
                  trailing: Text(tr(toDouble(o['open']) > 0 ? 'reste ${money(toDouble(o['open']), cur)}' : 'soldée'), style: TextStyle(color: toDouble(o['open']) > 0 ? YColors.warning : YColors.success, fontWeight: FontWeight.w600)),
                ),
              for (final i in invoices)
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.receipt_long_outlined),
                  title: Text(tr('${i['number']} · ${i['partner_name']}')),
                  subtitle: Text(tr('Facture du ${dateFr(i['date'])}')),
                  trailing: Text(tr(money(toDouble(i['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
              for (final e in expenses)
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.receipt_outlined),
                  title: Text(tr('${e['description']}')),
                  subtitle: Text(tr('Note de frais du ${dateFr(e['date'])}${e['employee'] != null ? ' · ${e['employee']}' : ''}${e['status'] == 'paid' ? ' · remboursée' : ''}')),
                  trailing: Text(tr(money(toDouble(e['amount']), cur)), style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
            ]),
          )
        else
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(tr('Rattachez vos bons de commande, factures fournisseurs et notes de frais à ce projet (champ « Projet » du document, ou depuis un appel d\'offres) pour suivre les achats.'), style: TextStyle(color: hint)),
          ),
      ]),
    );
  }
}

/// Coût horaire de la main-d'œuvre : général et par personne (sert à valoriser les temps des projets).
Future<void> editLabourCosts(BuildContext context) async {
  final session = context.read<Session>();
  final users = await projetUsers(session);
  Map<String, dynamic> s;
  try {
    s = asMap((await session.api.get('projets/cost-settings'))['data']);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return;
  }
  if (!context.mounted) return;
  String fmt(dynamic v) => v == null || toDouble(v) == 0 ? '' : '${toDouble(v).round()}';
  final general = TextEditingController(text: fmt(s['hourly_cost']));
  final per = {for (final u in users) '${u['id']}': TextEditingController(text: fmt((s['user_costs'] as Map?)?['${u['id']}']))};
  await showFormDialog(
    context,
    title: tr('Coûts horaires de la main-d\'œuvre'),
    maxWidth: 520,
    builder: (ctx) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Text(tr('Coût chargé d\'une heure de travail (salaire + charges). Il valorise les temps saisis sur les projets ; il n\'est visible que des chefs de projet.'), style: TextStyle(color: Theme.of(ctx).hintColor)),
      const SizedBox(height: 10),
      Field(label: tr('Coût horaire général (${session.currency})'), child: TextField(controller: general, keyboardType: TextInputType.number)),
      if (users.isNotEmpty) Text(tr('Par personne (laisser vide = coût général)'), style: Theme.of(ctx).textTheme.titleSmall),
      for (final u in users)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(children: [
            Expanded(child: Text(tr('${u['name']}'))),
            SizedBox(width: 150, child: TextField(controller: per['${u['id']}'], keyboardType: TextInputType.number, decoration: InputDecoration(isDense: true, hintText: tr('général')))),
          ]),
        ),
      const SizedBox(height: 14),
      Align(
        alignment: Alignment.centerRight,
        child: SaveButton(
          onPressed: () async {
            double? n(String t) => double.tryParse(t.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.'));
            try {
              await session.api.put('projets/cost-settings', {
                'hourly_cost': n(general.text) ?? 0,
                'user_costs': {for (final e in per.entries) if (n(e.value.text) != null) e.key: n(e.value.text)},
              });
              if (ctx.mounted) {
                Navigator.pop(ctx);
                toast(ctx, tr('Coûts horaires enregistrés.'));
              }
            } catch (e) {
              if (ctx.mounted) toast(ctx, errorText(e), error: true);
            }
          },
          child: Text(tr('Enregistrer')),
        ),
      ),
    ]),
  );
}

// ======================================================================
// Tableau Kanban
// ======================================================================

class TaskBoard extends StatelessWidget {
  const TaskBoard({super.key, required this.project, required this.tasks, required this.times});

  final Map<String, dynamic> project;
  final List<Map<String, dynamic>> tasks;
  final List<Map<String, dynamic>> times;

  Future<void> _move(BuildContext context, Map<String, dynamic> t, String status) async {
    try {
      await context.read<SyncEngine>().save('prj_tasks', {'id': t['id'], 'status': status});
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final touch = MediaQuery.of(context).size.width < 900;
    return ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.fromLTRB(16, 4, 16, 16), children: [
      for (final st in taskStatuses.entries)
        Builder(builder: (context) {
          final rows = tasks.where((t) => (t['status'] ?? 'todo') == st.key).toList()
            ..sort((a, b) {
              final pr = ((b['priority'] as num?) ?? 0).compareTo((a['priority'] as num?) ?? 0);
              return pr != 0 ? pr : '${a['due_date'] ?? '9'}'.compareTo('${b['due_date'] ?? '9'}');
            });
          final color = taskStatusColors[st.key]!;
          return DragTarget<Map<String, dynamic>>(
            onWillAcceptWithDetails: (d) => d.data['status'] != st.key,
            onAcceptWithDetails: (d) => _move(context, d.data, st.key),
            builder: (context, cand, _) => Container(
              width: 290,
              margin: const EdgeInsets.only(right: 12),
              decoration: BoxDecoration(
                color: cand.isNotEmpty ? color.withValues(alpha: .1) : Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .45),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: cand.isNotEmpty ? color : Colors.transparent, width: 2),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(14, 12, 6, 6),
                  child: Row(children: [
                    Container(width: 10, height: 10, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
                    const SizedBox(width: 8),
                    Expanded(child: Text(tr(st.value), style: const TextStyle(fontWeight: FontWeight.w700))),
                    StatusChip('${rows.length}', color: color),
                    IconButton(tooltip: tr('Ajouter'), visualDensity: VisualDensity.compact, onPressed: () => editTask(context, project: project, status: st.key), icon: const Icon(Icons.add, size: 18)),
                  ]),
                ),
                Expanded(
                  child: rows.isEmpty
                      ? Center(child: Text(tr('Glissez une tâche ici'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)))
                      : ListView.builder(
                          padding: const EdgeInsets.fromLTRB(10, 0, 10, 10),
                          itemCount: rows.length,
                          itemBuilder: (_, i) {
                            final card = TaskCard(task: rows[i], hours: times.where((x) => x['task_id'] == rows[i]['id']).fold(0.0, (s, x) => s + toDouble(x['hours'])));
                            final feedback = Material(color: Colors.transparent, child: SizedBox(width: 270, child: Opacity(opacity: .9, child: card)));
                            return touch
                                ? LongPressDraggable<Map<String, dynamic>>(data: rows[i], feedback: feedback, childWhenDragging: Opacity(opacity: .3, child: card), child: card)
                                : Draggable<Map<String, dynamic>>(data: rows[i], feedback: feedback, childWhenDragging: Opacity(opacity: .3, child: card), child: card);
                          },
                        ),
                ),
              ]),
            ),
          );
        }),
    ]);
  }
}

class TaskCard extends StatelessWidget {
  const TaskCard({super.key, required this.task, required this.hours, this.showProject = false});

  final Map<String, dynamic> task;
  final double hours;
  final bool showProject;

  @override
  Widget build(BuildContext context) {
    final t = task;
    final due = _date(t['due_date']);
    final late = due != null && t['status'] != 'done' && due.isBefore(_today());
    final soon = due != null && !late && t['status'] != 'done' && daysBetween(_today(), due) <= 2;
    final checklist = (t['checklist'] as List? ?? const []).whereType<Map>().toList();
    final checked = checklist.where((c) => c['done'] == true).length;
    final est = toDouble(t['estimate_hours']);
    final assignee = _userName(t['assignee_id']);
    final milestone = LocalDb.get('prj_milestones', t['milestone_id'] as String?);
    final project = showProject ? LocalDb.get('prj_projects', t['project_id'] as String?) : null;
    final hint = Theme.of(context).hintColor;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => editTask(context, task: t),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (project != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(children: [
                  Container(width: 8, height: 8, decoration: BoxDecoration(color: _hex(project['color'] as String?), shape: BoxShape.circle)),
                  const SizedBox(width: 6),
                  Expanded(child: Text(tr('${project['name']}'), style: TextStyle(fontSize: 11, color: hint), maxLines: 1, overflow: TextOverflow.ellipsis)),
                ]),
              ),
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(child: Text(tr('${t['title']}'), style: TextStyle(fontWeight: FontWeight.w600, decoration: t['status'] == 'done' ? TextDecoration.lineThrough : null))),
              if (((t['priority'] as num?) ?? 0) > 0)
                Row(mainAxisSize: MainAxisSize.min, children: [for (var i = 0; i < ((t['priority'] as num).toInt()); i++) const Icon(Icons.star, size: 14, color: YColors.yellow)]),
            ]),
            if ((t['blocked_reason'] ?? '').toString().isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Row(children: [
                  const Icon(Icons.block, size: 14, color: YColors.danger),
                  const SizedBox(width: 4),
                  Expanded(child: Text(tr('${t['blocked_reason']}'), style: const TextStyle(fontSize: 12, color: YColors.danger), maxLines: 2, overflow: TextOverflow.ellipsis)),
                ]),
              ),
            const SizedBox(height: 8),
            Wrap(spacing: 6, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
              if (due != null) StatusChip(DateFormat('dd/MM').format(due), icon: Icons.event, color: late ? YColors.danger : (soon ? YColors.warning : YColors.muted)),
              if (checklist.isNotEmpty) StatusChip('$checked/${checklist.length}', icon: Icons.checklist, color: checked == checklist.length ? YColors.success : YColors.muted),
              if (hours > 0) StatusChip('${hoursFr(hours)}${est > 0 ? ' / ${hoursFr(est)}' : ''}', icon: Icons.schedule, color: est > 0 && hours > est ? YColors.danger : YColors.blue),
              if (hours == 0 && est > 0) StatusChip(tr('${hoursFr(est)} prévues'), icon: Icons.schedule, color: YColors.muted),
              if (milestone != null) StatusChip('${milestone['name']}', icon: Icons.diamond_outlined, color: const Color(0xFF7B3FE4)),
            ]),
            if (assignee.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Row(children: [
                  CircleAvatar(radius: 11, backgroundColor: YColors.blue.withValues(alpha: .12), child: Text(tr(_initials(assignee)), style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w700, color: YColors.blue))),
                  const SizedBox(width: 6),
                  Expanded(child: Text(tr(assignee), style: TextStyle(fontSize: 12, color: hint), overflow: TextOverflow.ellipsis)),
                ]),
              ),
          ]),
        ),
      ),
    );
  }
}

// ======================================================================
// Liste
// ======================================================================

class _TaskList extends StatelessWidget {
  const _TaskList({required this.project, required this.tasks, required this.times});

  final Map<String, dynamic> project;
  final List<Map<String, dynamic>> tasks;
  final List<Map<String, dynamic>> times;

  @override
  Widget build(BuildContext context) {
    final rows = [...tasks]..sort((a, b) {
        final s = taskStatuses.keys.toList().indexOf('${a['status']}').compareTo(taskStatuses.keys.toList().indexOf('${b['status']}'));
        return s != 0 ? s : '${a['due_date'] ?? '9'}'.compareTo('${b['due_date'] ?? '9'}');
      });
    if (rows.isEmpty) return EmptyState(icon: Icons.checklist, title: tr('Aucune tâche'), message: tr('Ajoutez les tâches du projet avec le bouton « Tâche ».'));
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
      itemCount: rows.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (_, i) {
        final t = rows[i];
        final due = _date(t['due_date']);
        final late = due != null && t['status'] != 'done' && due.isBefore(_today());
        final h = times.where((x) => x['task_id'] == t['id']).fold(0.0, (s, x) => s + toDouble(x['hours']));
        return ListTile(
          leading: Checkbox(
            value: t['status'] == 'done',
            onChanged: (v) async {
              try {
                await context.read<SyncEngine>().save('prj_tasks', {'id': t['id'], 'status': v == true ? 'done' : 'todo'});
              } catch (e) {
                if (context.mounted) toast(context, errorText(e), error: true);
              }
            },
          ),
          title: Text(tr('${t['title']}'), style: TextStyle(decoration: t['status'] == 'done' ? TextDecoration.lineThrough : null)),
          subtitle: Text(tr([
            _userName(t['assignee_id']).isEmpty ? 'Non attribuée' : _userName(t['assignee_id']),
            if (due != null) 'échéance ${DateFormat('dd/MM/yyyy').format(due)}',
            if (h > 0) hoursFr(h),
          ].join(' · ')), style: TextStyle(color: late ? YColors.danger : null)),
          trailing: StatusChip(taskStatuses[t['status']] ?? '', color: taskStatusColors[t['status']] ?? YColors.muted),
          onTap: () => editTask(context, task: t),
        );
      },
    );
  }
}

// ======================================================================
// Planning (diagramme de Gantt)
// ======================================================================

class GanttView extends StatelessWidget {
  const GanttView({super.key, required this.project, required this.tasks, required this.milestones});

  final Map<String, dynamic> project;
  final List<Map<String, dynamic>> tasks;
  final List<Map<String, dynamic>> milestones;

  static const dayW = 28.0;
  static const rowH = 38.0;
  static const labelW = 240.0;

  @override
  Widget build(BuildContext context) {
    final rows = tasks.where((t) => t['due_date'] != null || t['start_date'] != null).toList()
      ..sort((a, b) => '${a['start_date'] ?? a['due_date']}'.compareTo('${b['start_date'] ?? b['due_date']}'));
    final undated = tasks.length - rows.length;
    final dates = <DateTime>[
      for (final t in rows) ...[?_date(t['start_date']), ?_date(t['due_date'])],
      for (final m in milestones) ?_date(m['due_date']),
      ?_date(project['start_date']),
      ?_date(project['end_date']),
      _today(),
    ];
    if (rows.isEmpty && milestones.where((m) => m['due_date'] != null).isEmpty) {
      return EmptyState(icon: Icons.view_timeline_outlined, title: tr('Planning vide'), message: tr('Donnez une date de début ou d\'échéance aux tâches pour les voir sur le planning.'));
    }
    var start = addDays(dates.reduce((a, b) => a.isBefore(b) ? a : b), -2);
    start = addDays(start, -(start.weekday - 1));
    final end = addDays(dates.reduce((a, b) => a.isAfter(b) ? a : b), 7);
    final days = daysBetween(start, end) + 1;
    final width = days * dayW;
    final hint = Theme.of(context).hintColor;
    final color = _hex(project['color'] as String?);
    final ms = milestones.where((m) => m['due_date'] != null).toList();
    double x(DateTime d) => daysBetween(start, d) * dayW;

    Widget header() => SizedBox(
          height: 44,
          child: Stack(children: [
            for (var i = 0; i < days; i++)
              Positioned(
                left: i * dayW,
                top: 0,
                width: dayW,
                height: 44,
                child: Builder(builder: (context) {
                  final d = addDays(start, i);
                  final weekend = d.weekday >= 6;
                  return Container(
                    decoration: BoxDecoration(color: weekend ? Colors.black.withValues(alpha: .03) : null, border: Border(left: BorderSide(color: d.weekday == 1 ? YColors.line : Colors.transparent))),
                    child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [
                      if (d.weekday == 1 || i == 0) Text(tr(DateFormat('d MMM', I18n.dateLocale).format(d)), style: TextStyle(fontSize: 10, color: hint, fontWeight: FontWeight.w600), overflow: TextOverflow.visible, softWrap: false),
                      Text(tr(DateFormat('E', I18n.dateLocale).format(d).substring(0, 1).toUpperCase()), style: TextStyle(fontSize: 10, color: hint)),
                      const SizedBox(height: 4),
                    ]),
                  );
                }),
              ),
          ]),
        );

    final today = x(_today()) + dayW / 2;
    final body = SizedBox(
      width: width,
      height: (rows.length + (ms.isEmpty ? 0 : 1)) * rowH + 8,
      child: Stack(children: [
        for (var i = 0; i < days; i++)
          if (addDays(start, i).weekday >= 6) Positioned(left: i * dayW, top: 0, bottom: 0, width: dayW, child: Container(color: Colors.black.withValues(alpha: .03))),
        Positioned(left: today, top: 0, bottom: 0, width: 2, child: Container(color: YColors.danger.withValues(alpha: .6))),
        for (var r = 0; r < rows.length; r++)
          Builder(builder: (context) {
            final t = rows[r];
            final due = _date(t['due_date']);
            final st = _date(t['start_date']) ?? addDays(due!, -max(0, (toDouble(t['estimate_hours']) / 8).ceil() - 1));
            final en = due ?? st;
            final c = taskStatusColors[t['status']] ?? color;
            final late = t['status'] != 'done' && en.isBefore(_today());
            return Positioned(
              left: x(st) + 2,
              top: r * rowH + 7,
              width: max(dayW - 4, x(en) - x(st) + dayW - 4),
              height: rowH - 14,
              child: Tooltip(
                message: '${t['title']} · ${DateFormat('dd/MM').format(st)} › ${DateFormat('dd/MM').format(en)}',
                child: InkWell(
                  onTap: () => editTask(context, task: t),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6),
                    alignment: Alignment.centerLeft,
                    decoration: BoxDecoration(
                      color: c.withValues(alpha: t['status'] == 'done' ? .35 : .85),
                      borderRadius: BorderRadius.circular(6),
                      border: late ? Border.all(color: YColors.danger, width: 2) : null,
                    ),
                    child: Text(tr('${t['title']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600)),
                  ),
                ),
              ),
            );
          }),
        for (final m in ms)
          Positioned(
            left: x(_date(m['due_date'])!) + dayW / 2 - 9,
            top: rows.length * rowH + 9,
            child: Tooltip(
              message: tr('Jalon : ${m['name']} · ${dateFr(m['due_date'])}'),
              child: Transform.rotate(
                angle: pi / 4,
                child: Container(width: 16, height: 16, decoration: BoxDecoration(color: m['status'] == 'open' ? const Color(0xFF7B3FE4) : YColors.success, borderRadius: BorderRadius.circular(3))),
              ),
            ),
          ),
      ]),
    );

    final labels = Column(children: [
      const SizedBox(height: 44),
      for (final t in rows)
        SizedBox(
          height: rowH,
          child: Row(children: [
            Container(width: 8, height: 8, margin: const EdgeInsets.only(right: 8), decoration: BoxDecoration(color: taskStatusColors[t['status']], shape: BoxShape.circle)),
            Expanded(child: Text(tr('${t['title']}'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13))),
          ]),
        ),
      if (ms.isNotEmpty) SizedBox(height: rowH, child: Row(children: [const Icon(Icons.diamond_outlined, size: 14, color: Color(0xFF7B3FE4)), const SizedBox(width: 6), Text(tr('Jalons'), style: TextStyle(fontSize: 13, color: hint))])),
    ]);

    return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
      Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SizedBox(width: MediaQuery.of(context).size.width < 700 ? 130 : labelW, child: labels),
            Expanded(child: SingleChildScrollView(scrollDirection: Axis.horizontal, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [SizedBox(width: width, child: header()), body]))),
          ]),
        ),
      ),
      if (undated > 0) Padding(padding: const EdgeInsets.only(top: 8), child: Text(tr('$undated tâche(s) sans date ne figurent pas sur le planning.'), style: TextStyle(fontSize: 12, color: hint))),
    ]);
  }
}

// ======================================================================
// Temps du projet
// ======================================================================

class _TimeTab extends StatelessWidget {
  const _TimeTab({required this.project, required this.m});

  final Map<String, dynamic> project;
  final ProjectMetrics m;

  @override
  Widget build(BuildContext context) {
    final rows = [...m.times]..sort((a, b) => '${b['date']}'.compareTo('${a['date']}'));
    final byUser = <int, double>{};
    for (final t in m.times) {
      byUser[(t['user_id'] as num).toInt()] = (byUser[(t['user_id'] as num).toInt()] ?? 0) + toDouble(t['hours']);
    }
    final maxH = byUser.values.fold(1.0, max);
    final me = context.read<Session>().user['id'];
    final manage = context.read<Session>().can('projets.manage');
    return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
      Row(children: [
        Expanded(child: Text(tr('${hoursFr(m.hours)} au total · ${hoursFr(m.toInvoiceHours)} facturables non facturées'), style: const TextStyle(fontWeight: FontWeight.w600))),
        FilledButton.tonalIcon(onPressed: () => logTime(context, project: project), icon: const Icon(Icons.more_time), label: Text(tr('Saisir du temps'))),
      ]),
      const SizedBox(height: 12),
      if (byUser.isNotEmpty)
        SectionCard(
          title: tr('Par personne'),
          child: Column(children: [
            for (final e in byUser.entries.toList()..sort((a, b) => b.value.compareTo(a.value)))
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(children: [
                  SizedBox(width: 160, child: Text(tr(_userName(e.key).isEmpty ? 'Utilisateur ${e.key}' : _userName(e.key)))),
                  Expanded(child: ClipRRect(borderRadius: BorderRadius.circular(6), child: LinearProgressIndicator(value: e.value / maxH, minHeight: 10))),
                  SizedBox(width: 80, child: Text(tr(hoursFr(e.value)), textAlign: TextAlign.right, style: const TextStyle(fontWeight: FontWeight.w600))),
                ]),
              ),
          ]),
        ),
      const SizedBox(height: 12),
      if (rows.isEmpty)
        EmptyState(icon: Icons.schedule, title: tr('Aucun temps saisi'), message: tr('Utilisez le chronomètre d\'une tâche ou saisissez vos heures.'))
      else
        Card(
          child: Column(children: [
            for (final t in rows) ...[
              ListTile(
                dense: true,
                leading: CircleAvatar(radius: 16, child: Text(tr(_initials(_userName(t['user_id']))), style: const TextStyle(fontSize: 11))),
                title: Text(tr('${t['description'] ?? LocalDb.get('prj_tasks', t['task_id'] as String?)?['title'] ?? 'Temps passé'}')),
                subtitle: Text(tr([
                  dateFr(t['date']),
                  _userName(t['user_id']),
                  if (t['task_id'] != null && t['description'] != null) '${LocalDb.get('prj_tasks', t['task_id'] as String?)?['title'] ?? ''}',
                  if (t['billable'] == false) 'non facturable',
                ].where((e) => e.isNotEmpty).join(' · '))),
                trailing: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  if (t['invoice_id'] != null) StatusChip(tr('Facturé'), color: YColors.success),
                  if (t['invoice_id'] == null && t['pack_id'] != null) StatusChip(tr('Sur forfait'), color: YColors.blue),
                  Text(tr(hoursFr(toDouble(t['hours']))), style: const TextStyle(fontWeight: FontWeight.w700)),
                ]),
                onTap: t['invoice_id'] == null && (manage || t['user_id'] == me) ? () => logTime(context, project: project, entry: t) : null,
              ),
              const Divider(height: 1),
            ],
          ]),
        ),
    ]);
  }
}

// ======================================================================
// Jalons et facturation
// ======================================================================

class _BillingTab extends StatelessWidget {
  const _BillingTab({required this.project, required this.m, required this.manage});

  final Map<String, dynamic> project;
  final ProjectMetrics m;
  final bool manage;

  Future<void> _invoice(BuildContext context, {String? milestoneId}) async {
    var group = 'task';
    if (milestoneId == null) {
      final g = await showDialog<String>(
        context: context,
        builder: (c) => SimpleDialog(title: Text(tr('Regrouper les lignes de la facture')), children: [
          SimpleDialogOption(onPressed: () => Navigator.pop(c, 'task'), child: ListTile(leading: Icon(Icons.checklist), title: Text(tr('Par tâche')))),
          SimpleDialogOption(onPressed: () => Navigator.pop(c, 'user'), child: ListTile(leading: Icon(Icons.person_outline), title: Text(tr('Par intervenant')))),
          SimpleDialogOption(onPressed: () => Navigator.pop(c, 'line'), child: ListTile(leading: Icon(Icons.list), title: Text(tr('Une ligne par saisie (détaillé)')))),
        ]),
      );
      if (g == null) return;
      group = g;
    }
    if (!context.mounted) return;
    final session = context.read<Session>();
    final sync = context.read<SyncEngine>();
    final nav = Navigator.of(context);
    try {
      final r = await session.api.post('projets/projects/${project['id']}/invoice', {'milestone_id': ?milestoneId, 'group_by': group});
      final inv = Map<String, dynamic>.from(r['invoice']);
      await sync.sync();
      if (!context.mounted) return;
      toast(context, tr('Facture brouillon créée : vérifiez-la puis validez-la.'));
      await nav.push(MaterialPageRoute(builder: (_) => DocumentEditor(config: DocConfig.sale, document: LocalDb.get('sales_documents', inv['id'] as String) ?? inv, initialType: 'invoice')));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    final hint = Theme.of(context).hintColor;
    final ms = [...m.milestones]..sort((a, b) => '${a['due_date'] ?? '9'}'.compareTo('${b['due_date'] ?? '9'}'));
    final billing = '${project['billing_mode']}';
    return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 24), children: [
      if (manage && billing == 'time')
        SectionCard(
          title: tr('Temps passé à facturer'),
          child: Row(children: [
            Expanded(
              child: Text(
                tr('${hoursFr(m.toInvoiceHours)} × ${money(m.rate, cur)} = ${money(m.toInvoiceAmount, cur)} HT'),
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
              ),
            ),
            FilledButton.icon(
              onPressed: m.toInvoiceHours <= 0 ? null : () => _invoice(context),
              icon: const Icon(Icons.receipt_long_outlined),
              label: Text(tr('Créer la facture')),
            ),
          ]),
        ),
      if (billing == 'none') Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(tr('Projet interne : non facturé.'), style: TextStyle(color: hint))),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Jalons'),
        trailing: manage ? TextButton.icon(onPressed: () => editMilestone(context, project), icon: const Icon(Icons.add, size: 18), label: Text(tr('Jalon'))) : null,
        child: ms.isEmpty
            ? Text(tr('Les jalons marquent les étapes clés (maquettes validées, mise en ligne…) et peuvent être facturés au forfait.'), style: TextStyle(color: hint))
            : Column(children: [
                for (final x in ms)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(Icons.diamond, color: x['status'] == 'open' ? const Color(0xFF7B3FE4) : YColors.success),
                    title: Text(tr('${x['name']}')),
                    subtitle: Text(tr([
                      if (x['due_date'] != null) 'prévu le ${dateFr(x['due_date'])}',
                      '${m.tasks.where((t) => t['milestone_id'] == x['id'] && t['status'] == 'done').length}/${m.tasks.where((t) => t['milestone_id'] == x['id']).length} tâches',
                      if (toDouble(x['amount']) > 0) money(toDouble(x['amount']), cur),
                    ].join(' · '))),
                    trailing: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      StatusChip({'open': 'À venir', 'reached': 'Atteint', 'invoiced': 'Facturé'}[x['status']] ?? '', color: x['status'] == 'invoiced' ? YColors.success : (x['status'] == 'reached' ? YColors.blue : YColors.muted)),
                      if (manage && x['status'] == 'open')
                        TextButton(onPressed: () => context.read<SyncEngine>().save('prj_milestones', {'id': x['id'], 'status': 'reached'}), child: Text(tr('Marquer atteint'))),
                      if (manage && x['status'] == 'reached' && toDouble(x['amount']) > 0 && billing != 'none')
                        FilledButton.tonal(onPressed: () => _invoice(context, milestoneId: x['id'] as String), child: Text(tr('Facturer'))),
                    ]),
                    onTap: manage && x['invoice_id'] == null ? () => editMilestone(context, project, x) : null,
                  ),
              ]),
      ),
      if (manage) ...[
        const SizedBox(height: 12),
        Text(tr('Les factures sont créées en brouillon dans les Ventes. Si vous supprimez ou annulez une facture, le temps et les jalons redeviennent facturables.'), style: TextStyle(fontSize: 12, color: hint)),
      ],
    ]);
  }
}

Future<void> editMilestone(BuildContext context, Map<String, dynamic> project, [Map<String, dynamic>? ms]) async {
  final name = TextEditingController(text: '${ms?['name'] ?? ''}');
  final amount = TextEditingController(text: toDouble(ms?['amount']) == 0 ? '' : '${toDouble(ms?['amount']).round()}');
  var due = _date(ms?['due_date']);
  final sync = context.read<SyncEngine>();
  await showDialog(
    context: context,
    builder: (c) => StatefulBuilder(
      builder: (c, set) => YAlertDialog(
        title: Text(tr(ms == null ? 'Nouveau jalon' : 'Jalon')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: name, autofocus: true, decoration: InputDecoration(labelText: tr('Nom (ex. Maquettes validées)'))),
          TextField(controller: amount, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant facturable (facultatif)'))),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: () async {
              final d = await pickDate(c, initialDate: due ?? _today().add(const Duration(days: 14)), firstDate: DateTime(2020), lastDate: DateTime(2100));
              if (d != null) set(() => due = d);
            },
            icon: const Icon(Icons.event),
            label: Text(tr(due == null ? 'Date prévue' : DateFormat('dd/MM/yyyy').format(due!))),
          ),
        ]),
        actions: [
          if (ms != null)
            TextButton(
              onPressed: () async {
                await sync.remove('prj_milestones', ms['id'] as String);
                if (c.mounted) Navigator.pop(c);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
          SaveButton(
            onPressed: () async {
              if (name.text.trim().isEmpty) return;
              await sync.save('prj_milestones', {
                'id': ms?['id'] ?? newId(),
                'project_id': project['id'],
                'name': name.text.trim(),
                'amount': double.tryParse(amount.text.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.')) ?? 0,
                'due_date': due == null ? null : _day(due!),
                if (ms == null) 'status': 'open',
              });
              if (c.mounted) Navigator.pop(c);
            },
            child: Text(tr('Enregistrer')),
          ),
        ],
      ),
    ),
  );
}

// ======================================================================
// Tâche
// ======================================================================

Future<void> editTask(BuildContext context, {Map<String, dynamic>? task, Map<String, dynamic>? project, String? status}) async {
  final session = context.read<Session>();
  final users = await projetUsers(session);
  if (!context.mounted) return;
  final p = project ?? LocalDb.get('prj_projects', task?['project_id'] as String?);
  if (p == null) return;
  await showFormDialog(context, title: task == null ? 'Nouvelle tâche' : 'Tâche', builder: (c) => _TaskForm(task: task, project: p, users: users, status: status));
}

class _TaskForm extends StatefulWidget {
  const _TaskForm({this.task, required this.project, required this.users, this.status});

  final Map<String, dynamic>? task;
  final Map<String, dynamic> project;
  final List<Map<String, dynamic>> users;
  final String? status;

  @override
  State<_TaskForm> createState() => _TaskFormState();
}

class _TaskFormState extends State<_TaskForm> {
  late final Map<String, dynamic> _t = {...?widget.task};
  late final _title = TextEditingController(text: '${_t['title'] ?? ''}');
  late final _desc = TextEditingController(text: '${_t['description'] ?? ''}');
  late final _est = TextEditingController(text: toDouble(_t['estimate_hours']) == 0 ? '' : '${toDouble(_t['estimate_hours'])}');
  late final _blocked = TextEditingController(text: '${_t['blocked_reason'] ?? ''}');
  late final _tags = TextEditingController(text: '${_t['tags'] ?? ''}');
  final _newItem = TextEditingController();
  late String _status = '${_t['status'] ?? widget.status ?? 'todo'}';
  late int _priority = ((_t['priority'] as num?) ?? 0).toInt();
  late int? _assignee = (_t['assignee_id'] as num?)?.toInt() ?? (widget.task == null && !context.read<Session>().can('projets.manage') ? context.read<Session>().user['id'] as int? : null);
  late DateTime? _start = _date(_t['start_date']);
  late DateTime? _due = _date(_t['due_date']);
  late String? _milestone = _t['milestone_id'] as String?;
  late final List<Map<String, dynamic>> _checklist = [for (final c in (_t['checklist'] as List? ?? const [])) asMap(c)];

  Future<void> _save() async {
    if (_title.text.trim().isEmpty) {
      toast(context, tr('Donnez un titre à la tâche.'), error: true);
      return;
    }
    try {
      await context.read<SyncEngine>().save('prj_tasks', {
        'id': _t['id'] ?? newId(),
        'project_id': widget.project['id'],
        'title': _title.text.trim(),
        'description': _desc.text.trim().isEmpty ? null : _desc.text.trim(),
        'status': _status,
        'priority': _priority,
        'assignee_id': _assignee,
        'start_date': _start == null ? null : _day(_start!),
        'due_date': _due == null ? null : _day(_due!),
        'estimate_hours': double.tryParse(_est.text.replaceAll(',', '.')) ?? 0,
        'milestone_id': _milestone,
        'checklist': _checklist,
        'blocked_reason': _blocked.text.trim().isEmpty ? null : _blocked.text.trim(),
        'tags': _tags.text.trim().isEmpty ? null : _tags.text.trim(),
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final members = <int>{if (widget.project['manager_id'] != null) (widget.project['manager_id'] as num).toInt(), for (final m in (widget.project['member_ids'] as List? ?? const [])) (m as num).toInt()};
    final users = [...widget.users]..sort((a, b) => (members.contains(b['id']) ? 1 : 0).compareTo(members.contains(a['id']) ? 1 : 0));
    final milestones = LocalDb.all('prj_milestones').where((m) => m['project_id'] == widget.project['id']).toList();
    final times = widget.task == null ? <Map<String, dynamic>>[] : LocalDb.all('prj_timesheets').where((x) => x['task_id'] == widget.task!['id']).toList();
    final spent = times.fold(0.0, (s, x) => s + toDouble(x['hours']));
    Widget dateBtn(String label, DateTime? v, void Function(DateTime?) set) => Field(
          label: label,
          child: Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await pickDate(context, initialDate: v ?? _today(), firstDate: DateTime(2020), lastDate: DateTime(2100));
                  if (d != null) setState(() => set(d));
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr(v == null ? '—' : DateFormat('dd/MM/yyyy').format(v))),
              ),
            ),
            if (v != null) IconButton(onPressed: () => setState(() => set(null)), icon: const Icon(Icons.close, size: 18)),
          ]),
        );

    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Text(tr('${widget.project['name']}'), style: TextStyle(color: _hex(widget.project['color'] as String?), fontWeight: FontWeight.w600)),
      const SizedBox(height: 8),
      Field(label: tr('Titre'), child: TextField(controller: _title, autofocus: widget.task == null)),
      Field(
        label: tr('Statut'),
        child: SegmentedButton<String>(
          showSelectedIcon: false,
          segments: [for (final s in taskStatuses.entries) ButtonSegment(value: s.key, label: Text(tr(s.value), style: const TextStyle(fontSize: 12)))],
          selected: {_status},
          onSelectionChanged: (s) => setState(() => _status = s.first),
        ),
      ),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Responsable'),
            child: DropdownButtonFormField<int?>(
              initialValue: users.any((u) => u['id'] == _assignee) ? _assignee : null,
              isExpanded: true,
              items: [
                DropdownMenuItem<int?>(value: null, child: Text(tr('Non attribuée'))),
                for (final u in users) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}${members.contains(u['id']) ? '' : ' (hors équipe)'}'), overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _assignee = v),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Field(
          label: tr('Priorité'),
          child: Row(children: [
            for (var i = 1; i <= 3; i++)
              IconButton(
                visualDensity: VisualDensity.compact,
                onPressed: () => setState(() => _priority = _priority == i ? i - 1 : i),
                icon: Icon(i <= _priority ? Icons.star : Icons.star_border, color: YColors.yellow),
              ),
          ]),
        ),
      ]),
      Row(children: [
        Expanded(child: dateBtn('Début', _start, (d) => _start = d)),
        const SizedBox(width: 12),
        Expanded(child: dateBtn('Échéance', _due, (d) => _due = d)),
      ]),
      Row(children: [
        Expanded(child: Field(label: tr('Estimation (heures)'), child: TextField(controller: _est, keyboardType: TextInputType.number))),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Jalon'),
            child: DropdownButtonFormField<String?>(
              initialValue: milestones.any((m) => m['id'] == _milestone) ? _milestone : null,
              isExpanded: true,
              items: [
                const DropdownMenuItem<String?>(value: null, child: Text('—')),
                for (final m in milestones) DropdownMenuItem<String?>(value: m['id'] as String, child: Text(tr('${m['name']}'), overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _milestone = v),
            ),
          ),
        ),
      ]),
      Field(label: tr('Description'), child: TextField(controller: _desc, maxLines: 4)),
      Field(
        label: tr('Liste de contrôle'),
        child: Column(children: [
          for (var i = 0; i < _checklist.length; i++)
            Row(children: [
              Checkbox(value: _checklist[i]['done'] == true, onChanged: (v) => setState(() => _checklist[i] = {..._checklist[i], 'done': v == true})),
              Expanded(child: Text(tr('${_checklist[i]['text']}'), style: TextStyle(decoration: _checklist[i]['done'] == true ? TextDecoration.lineThrough : null))),
              IconButton(visualDensity: VisualDensity.compact, onPressed: () => setState(() => _checklist.removeAt(i)), icon: const Icon(Icons.close, size: 16)),
            ]),
          TextField(
            controller: _newItem,
            decoration: InputDecoration(hintText: tr('Ajouter un point puis Entrée'), isDense: true, prefixIcon: Icon(Icons.add, size: 18)),
            onSubmitted: (v) {
              if (v.trim().isEmpty) return;
              setState(() {
                _checklist.add({'text': v.trim(), 'done': false});
                _newItem.clear();
              });
            },
          ),
        ]),
      ),
      Field(label: tr('Bloqué par (facultatif)'), child: TextField(controller: _blocked, decoration: InputDecoration(hintText: tr('Ex. en attente des photos du client')))),
      Field(label: tr('Étiquettes'), child: TextField(controller: _tags)),
      if (widget.task != null) ...[
        const Divider(),
        Row(children: [
          Expanded(child: Text(tr('Temps passé : ${hoursFr(spent)}${toDouble(_t['estimate_hours']) > 0 ? ' sur ${hoursFr(toDouble(_t['estimate_hours']))} estimées' : ''}'), style: const TextStyle(fontWeight: FontWeight.w600))),
          TextButton.icon(
            onPressed: () async {
              await TimerChip.start(context, widget.task!);
              if (context.mounted) Navigator.pop(context);
            },
            icon: const Icon(Icons.play_circle_outline),
            label: Text(tr('Chronomètre')),
          ),
          TextButton.icon(onPressed: () => logTime(context, project: widget.project, task: widget.task), icon: const Icon(Icons.more_time), label: Text(tr('Saisir'))),
        ]),
      ],
      const SizedBox(height: 12),
      Row(children: [
        if (widget.task != null && context.read<Session>().can('projets.manage'))
          TextButton(
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer la tâche ?'), '${_t['title']}', danger: true, ok: tr('Supprimer'))) return;
              if (!context.mounted) return;
              await context.read<SyncEngine>().remove('prj_tasks', _t['id'] as String);
              if (context.mounted) Navigator.pop(context);
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
// Saisie de temps et chronomètre
// ======================================================================

Future<void> logTime(BuildContext context, {required Map<String, dynamic> project, Map<String, dynamic>? task, Map<String, dynamic>? entry, double? hours, DateTime? date}) async {
  final sync = context.read<SyncEngine>();
  final session = context.read<Session>();
  final tasks = LocalDb.all('prj_tasks').where((t) => t['project_id'] == project['id']).toList()..sort((a, b) => '${a['title']}'.compareTo('${b['title']}'));
  var taskId = (entry?['task_id'] ?? task?['id']) as String?;
  var day = _date(entry?['date']) ?? date ?? _today();
  var billable = entry?['billable'] != false && project['billing_mode'] != 'none';
  final h = TextEditingController(text: entry != null ? '${toDouble(entry['hours'])}' : (hours == null ? '' : hours.toStringAsFixed(2)));
  final desc = TextEditingController(text: '${entry?['description'] ?? ''}');
  await showFormDialog(context, title: entry == null ? 'Saisir du temps' : 'Temps passé', builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(tr('${project['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            DropdownButtonFormField<String?>(
              initialValue: tasks.any((t) => t['id'] == taskId) ? taskId : null,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Tâche')),
              items: [
                DropdownMenuItem<String?>(value: null, child: Text(tr('— Sans tâche —'))),
                for (final t in tasks) DropdownMenuItem<String?>(value: t['id'] as String, child: Text(tr('${t['title']}'), overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => set(() => taskId = v),
            ),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final d = await pickDate(c, initialDate: day, firstDate: DateTime(2020), lastDate: _today().add(const Duration(days: 7)));
                    if (d != null) set(() => day = d);
                  },
                  icon: const Icon(Icons.event, size: 18),
                  label: Text(tr(DateFormat('EEE dd/MM', I18n.dateLocale).format(day))),
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(width: 120, child: TextField(controller: h, autofocus: entry == null && hours == null, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Heures'), hintText: '1,5'))),
            ]),
            const SizedBox(height: 8),
            Wrap(spacing: 6, children: [for (final q in const [0.25, 0.5, 1.0, 2.0, 4.0, 8.0]) ActionChip(label: Text(tr(hoursFr(q))), onPressed: () => set(() => h.text = '$q'))]),
            TextField(controller: desc, decoration: InputDecoration(labelText: tr('Ce qui a été fait'))),
            if (project['billing_mode'] != 'none')
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Facturable au client')), value: billable, onChanged: (v) => set(() => billable = v)),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              if (entry != null)
                TextButton(
                  onPressed: () async {
                    await sync.remove('prj_timesheets', entry['id'] as String);
                    if (c.mounted) Navigator.pop(c);
                  },
                  child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
                ),
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
              SaveButton(
                onPressed: () async {
                  final hours = double.tryParse(h.text.replaceAll(',', '.').trim());
                  if (hours == null || hours <= 0 || hours > 24) {
                    toast(c, tr('Indiquez un nombre d\'heures entre 0 et 24.'), error: true);
                    return;
                  }
                  try {
                    await sync.save('prj_timesheets', {
                      'id': entry?['id'] ?? newId(),
                      'project_id': project['id'],
                      'task_id': taskId,
                      'user_id': entry?['user_id'] ?? session.user['id'],
                      'date': _day(day),
                      'hours': (hours * 100).round() / 100,
                      'description': desc.text.trim().isEmpty ? null : desc.text.trim(),
                      'billable': billable,
                    });
                    if (c.mounted) Navigator.pop(c);
                  } catch (e) {
                    if (c.mounted) toast(c, errorText(e), error: true);
                  }
                },
                child: Text(tr('Enregistrer')),
              ),
            ]),
          ),
        ]),
      ),
    ));
}

/// Chronomètre global (une tâche à la fois), conservé sur l'appareil même après fermeture de l'application.
class TimerChip extends StatefulWidget {
  const TimerChip({super.key});

  static Future<void> start(BuildContext context, Map<String, dynamic> task) async {
    final current = LocalDb.state().get('prj_timer');
    if (current is Map && current['task_id'] != task['id']) {
      if (!await confirm(context, tr('Chronomètre en cours'), tr('Arrêter le chronomètre de « ${current['title']} » et démarrer celui-ci ? Le temps écoulé vous sera proposé à la saisie.'))) return;
      if (!context.mounted) return;
      await _stop(context, Map<String, dynamic>.from(current));
    }
    await LocalDb.state().put('prj_timer', {'task_id': task['id'], 'project_id': task['project_id'], 'title': task['title'], 'started_at': DateTime.now().toIso8601String()});
    _tick.value++;
    if (context.mounted) toast(context, tr('Chronomètre démarré : « ${task['title']} ».'));
  }

  static final ValueNotifier<int> _tick = ValueNotifier(0);

  static Future<void> _stop(BuildContext context, Map<String, dynamic> t) async {
    await LocalDb.state().delete('prj_timer');
    _tick.value++;
    final elapsed = DateTime.now().difference(DateTime.parse('${t['started_at']}')).inSeconds / 3600;
    final rounded = max(0.05, (elapsed * 20).ceil() / 20); // au tiers d'heure près (3 min)
    final project = LocalDb.get('prj_projects', t['project_id'] as String?);
    final task = LocalDb.get('prj_tasks', t['task_id'] as String?);
    if (project == null || !context.mounted) return;
    await logTime(context, project: project, task: task, hours: rounded);
  }

  @override
  State<TimerChip> createState() => _TimerChipState();
}

class _TimerChipState extends State<TimerChip> {
  Timer? _t;

  @override
  void initState() {
    super.initState();
    _t = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && LocalDb.state().get('prj_timer') != null) setState(() {});
    });
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: TimerChip._tick,
      builder: (context, _, _) {
        final t = LocalDb.state().get('prj_timer');
        if (t is! Map) return const SizedBox.shrink();
        final d = DateTime.now().difference(DateTime.parse('${t['started_at']}'));
        final txt = '${d.inHours.toString().padLeft(2, '0')}:${(d.inMinutes % 60).toString().padLeft(2, '0')}:${(d.inSeconds % 60).toString().padLeft(2, '0')}';
        return Padding(
          padding: const EdgeInsets.only(right: 8),
          child: InputChip(
            avatar: const Icon(Icons.timer_outlined, color: YColors.danger, size: 18),
            label: Text(tr('$txt · ${t['title']}'), overflow: TextOverflow.ellipsis),
            deleteIcon: const Icon(Icons.stop_circle, color: YColors.danger),
            deleteButtonTooltipMessage: 'Arrêter et saisir le temps',
            onDeleted: () => TimerChip._stop(context, Map<String, dynamic>.from(t)),
          ),
        );
      },
    );
  }
}

// ======================================================================
// Mes tâches (tous projets)
// ======================================================================

class MyTasks extends StatefulWidget {
  const MyTasks({super.key, required this.projects, required this.tasks});

  final List<Map<String, dynamic>> projects;
  final List<Map<String, dynamic>> tasks;

  @override
  State<MyTasks> createState() => _MyTasksState();
}

class _MyTasksState extends State<MyTasks> {
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  int _gen = 0;

  @override
  Widget build(BuildContext context) {
    final projects = widget.projects;
    final me = context.read<Session>().user['id'];
    final open = widget.tasks.where((t) => t['assignee_id'] == me && t['status'] != 'done').toList();
    String projectName(Map<String, dynamic> t) => '${projects.where((p) => p['id'] == t['project_id']).map((p) => p['name']).firstOrNull ?? ''}';
    final base = open
        .where((t) => _q.isEmpty || '${t['title']} ${projectName(t)}'.toLowerCase().contains(_q))
        .where((t) => _f['project'] == null || '${t['project_id']}' == _f['project'])
        .where((t) => _f['priority'] == null || '${((t['priority'] as num?) ?? 0).toInt()}' == _f['priority'])
        .toList();
    final statuses = {'': 'Toutes', for (final e in taskStatuses.entries) if (e.key != 'done') e.key: e.value};
    final mine = base.where((t) => _status.isEmpty || t['status'] == _status).toList();
    final bar = FilterBar(
      key: ValueKey(_gen),
      padding: const EdgeInsets.only(bottom: 4),
      onSearch: (v) => setState(() => _q = v.toLowerCase()),
      search: _q,
      searchHint: 'Tâche ou projet…',
      statuses: statuses,
      status: _status,
      onStatus: (v) => setState(() => _status = v),
      statusCounts: {for (final k in statuses.keys) k: base.where((t) => k.isEmpty || t['status'] == k).length},
      filters: [
        FilterSpec('project', 'Projet', filterOptions(open, 'project_id', label: projectName), icon: Icons.task_alt),
        FilterSpec('priority', 'Priorité', {for (final t in open) '${((t['priority'] as num?) ?? 0).toInt()}': ((t['priority'] as num?) ?? 0).toInt() == 0 ? 'Sans priorité' : '★' * ((t['priority'] as num).toInt())}, icon: Icons.star_outline),
      ],
      values: _f,
      onFilter: (k, v) => setState(() => _f[k] = v),
      count: mine.length,
      countLabel: 'tâche',
      onReset: () => setState(() {
        _q = '';
        _status = '';
        _f.clear();
        _gen++;
      }),
    );
    final today = _today();
    final groups = <String, List<Map<String, dynamic>>>{'En retard': [], 'Aujourd\'hui': [], 'Cette semaine': [], 'Plus tard': [], 'Sans échéance': []};
    for (final t in mine) {
      final d = _date(t['due_date']);
      final k = d == null
          ? 'Sans échéance'
          : d.isBefore(today)
              ? 'En retard'
              : d == today
                  ? 'Aujourd\'hui'
                  : daysBetween(today, d) < 7
                      ? 'Cette semaine'
                      : 'Plus tard';
      groups[k]!.add(t);
    }
    if (open.isEmpty) return EmptyState(icon: Icons.inbox_outlined, title: tr('Rien à faire pour l\'instant'), message: tr('Les tâches qui vous sont attribuées apparaissent ici, tous projets confondus.'));
    return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
      bar,
      if (mine.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucune tâche ne correspond à ces filtres.')),
      for (final g in groups.entries.where((g) => g.value.isNotEmpty)) ...[
        Padding(
          padding: const EdgeInsets.only(top: 8, bottom: 8),
          child: Text(tr('${g.key} (${g.value.length})'), style: TextStyle(fontWeight: FontWeight.w700, color: g.key == 'En retard' ? YColors.danger : null)),
        ),
        Wrap(spacing: 10, runSpacing: 0, children: [
          for (final t in g.value..sort((a, b) => '${a['due_date'] ?? '9'}'.compareTo('${b['due_date'] ?? '9'}')))
            SizedBox(width: 320, child: TaskCard(task: t, hours: 0, showProject: true)),
        ]),
      ],
    ]);
  }
}

// ======================================================================
// Feuille de temps de la semaine
// ======================================================================

class WeekTimesheet extends StatefulWidget {
  const WeekTimesheet({super.key, required this.projects, required this.tasks, required this.times});

  final List<Map<String, dynamic>> projects;
  final List<Map<String, dynamic>> tasks;
  final List<Map<String, dynamic>> times;

  @override
  State<WeekTimesheet> createState() => _WeekTimesheetState();
}

class _WeekTimesheetState extends State<WeekTimesheet> {
  late DateTime _monday = addDays(_today(), -(_today().weekday - 1));
  final List<(String, String?)> _extra = []; // lignes ajoutées sans temps encore

  @override
  Widget build(BuildContext context) {
    final me = context.read<Session>().user['id'];
    final days = [for (var i = 0; i < 7; i++) addDays(_monday, i)];
    final mine = widget.times.where((t) => t['user_id'] == me).toList();
    final week = mine.where((t) {
      final d = _date(t['date']);
      return d != null && !d.isBefore(_monday) && d.isBefore(addDays(_monday, 7));
    }).toList();
    final keys = <(String, String?)>{for (final t in week) ('${t['project_id']}', t['task_id'] as String?), ..._extra}.toList();
    double cell((String, String?) k, DateTime d) =>
        week.where((t) => t['project_id'] == k.$1 && t['task_id'] == k.$2 && t['date'] == _day(d)).fold(0.0, (s, t) => s + toDouble(t['hours']));
    final total = week.fold(0.0, (s, t) => s + toDouble(t['hours']));
    final hint = Theme.of(context).hintColor;

    Future<void> edit((String, String?) k, DateTime d) async {
      final project = LocalDb.get('prj_projects', k.$1);
      if (project == null) return;
      final entries = week.where((t) => t['project_id'] == k.$1 && t['task_id'] == k.$2 && t['date'] == _day(d)).toList();
      if (entries.length == 1) {
        await logTime(context, project: project, entry: entries.first);
      } else {
        await logTime(context, project: project, task: LocalDb.get('prj_tasks', k.$2), date: d);
      }
    }

    Future<void> addRow() async {
      final active = widget.projects.where((p) => p['status'] == 'active').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
      String? pid;
      String? tid;
      final ok = await showDialog<bool>(
        context: context,
        builder: (c) => StatefulBuilder(
          builder: (c, set) => YAlertDialog(
            title: Text(tr('Ajouter une ligne')),
            content: SizedBox(
              width: 400,
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                DropdownButtonFormField<String>(
                  initialValue: pid,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: tr('Projet')),
                  items: [for (final p in active) DropdownMenuItem(value: p['id'] as String, child: Text(tr('${p['name']}')))],
                  onChanged: (v) => set(() => (pid = v, tid = null)),
                ),
                if (pid != null)
                  DropdownButtonFormField<String?>(
                    initialValue: tid,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: tr('Tâche')),
                    items: [
                      DropdownMenuItem<String?>(value: null, child: Text(tr('— Sans tâche —'))),
                      for (final t in widget.tasks.where((t) => t['project_id'] == pid && t['status'] != 'done')) DropdownMenuItem<String?>(value: t['id'] as String, child: Text(tr('${t['title']}'))),
                    ],
                    onChanged: (v) => set(() => tid = v),
                  ),
              ]),
            ),
            actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Ajouter')))],
          ),
        ),
      );
      if (ok == true && pid != null) setState(() => _extra.add((pid!, tid)));
    }

    return ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: [
      Row(children: [
        IconButton(
          onPressed: () => setState(() {
            _monday = addDays(_monday, -7);
            _extra.clear();
          }),
          icon: const Icon(Icons.chevron_left),
        ),
        Text(tr('Semaine du ${DateFormat('d MMMM', I18n.dateLocale).format(_monday)}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        IconButton(
          onPressed: () => setState(() {
            _monday = addDays(_monday, 7);
            _extra.clear();
          }),
          icon: const Icon(Icons.chevron_right),
        ),
        const Spacer(),
        Text(tr('Total : ${hoursFr(total)}'), style: const TextStyle(fontWeight: FontWeight.w800)),
      ]),
      const SizedBox(height: 8),
      Card(
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: DataTable(
            columnSpacing: 18,
            columns: [
              DataColumn(label: Text(tr('Projet / tâche'))),
              for (final d in days)
                DataColumn(
                  numeric: true,
                  label: Text(tr(DateFormat('EEE d', I18n.dateLocale).format(d)), style: TextStyle(color: d == _today() ? YColors.blue : null, fontWeight: d == _today() ? FontWeight.w800 : null)),
                ),
              DataColumn(numeric: true, label: Text(tr('Total'))),
            ],
            rows: [
              for (final k in keys)
                DataRow(cells: [
                  DataCell(SizedBox(
                    width: 240,
                    child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('${LocalDb.get('prj_projects', k.$1)?['name'] ?? '?'}'), style: const TextStyle(fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis),
                      Text(tr('${k.$2 == null ? 'Sans tâche' : LocalDb.get('prj_tasks', k.$2)?['title'] ?? ''}'), style: TextStyle(fontSize: 12, color: hint), overflow: TextOverflow.ellipsis),
                    ]),
                  )),
                  for (final d in days)
                    DataCell(
                      Container(
                        width: 54,
                        alignment: Alignment.centerRight,
                        child: Text(tr(cell(k, d) == 0 ? '·' : hoursFr(cell(k, d))), style: TextStyle(color: cell(k, d) == 0 ? hint : null, fontWeight: cell(k, d) == 0 ? null : FontWeight.w600)),
                      ),
                      onTap: () => edit(k, d),
                    ),
                  DataCell(Text(tr(hoursFr(days.fold(0.0, (s, d) => s + cell(k, d)))), style: const TextStyle(fontWeight: FontWeight.w700))),
                ]),
              DataRow(cells: [
                DataCell(Text(tr('Total du jour'), style: TextStyle(fontWeight: FontWeight.w700))),
                for (final d in days) DataCell(Text(tr(hoursFr(week.where((t) => t['date'] == _day(d)).fold(0.0, (s, t) => s + toDouble(t['hours'])))), style: const TextStyle(fontWeight: FontWeight.w700))),
                DataCell(Text(tr(hoursFr(total)), style: const TextStyle(fontWeight: FontWeight.w800))),
              ]),
            ],
          ),
        ),
      ),
      const SizedBox(height: 8),
      Row(children: [
        TextButton.icon(onPressed: addRow, icon: const Icon(Icons.add), label: Text(tr('Ajouter une ligne'))),
        const Spacer(),
        Text(tr('Touchez une case pour saisir ou corriger.'), style: TextStyle(fontSize: 12, color: hint)),
      ]),
    ]);
  }
}
