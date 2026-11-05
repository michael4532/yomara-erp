import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../pdf/payslip_pdf.dart' show periodLabel;
import 'employee_dossier.dart' show showEmployeeDossier;
import 'rh_requests_screen.dart' show showRequest, requestTypes, requestStatuses;
import 'rh_screen.dart' show contractTypes, myEmployee;

// ===========================================================================
// Mon équipe : le chef de département voit ses collaborateurs et ses stagiaires,
// avec ce que la RH lui a ouvert. La même fiche 360° sert à la RH.
// ===========================================================================

const _todayStatus = <String, (String, Color, IconData)>{
  'present': ('Présent', YColors.success, Icons.check_circle_outline),
  'late': ('En retard', YColors.warning, Icons.schedule),
  'absent': ('Absent', YColors.danger, Icons.cancel_outlined),
  'leave': ('En congé', Color(0xFF0EA5E9), Icons.beach_access_outlined),
  'rest': ('Repos', YColors.muted, Icons.weekend_outlined),
  'holiday': ('Férié', YColors.muted, Icons.celebration_outlined),
  'upcoming': ('Pas encore arrivé', YColors.muted, Icons.hourglass_empty),
};

const _staffColors = {'employee': YColors.blue, 'intern': Color(0xFF7C3AED), 'apprentice': Color(0xFF0D9488), 'contractor': Color(0xFFF59E0B), 'daily': Color(0xFF64748B)};

String _months(dynamic m) {
  final n = (m as num?)?.toInt();
  if (n == null) return '—';
  final y = n ~/ 12, r = n % 12;
  return [if (y > 0) '$y an${y > 1 ? 's' : ''}', if (r > 0 || y == 0) '$r mois'].join(' ');
}

/// Le salarié connecté dirige-t-il un département ou a-t-il des collaborateurs directs ?
bool leadsATeam(Session s) {
  final me = myEmployee(s);
  if (me == null || !LocalDb.hasEntity('rh_departments')) return false;
  return LocalDb.all('rh_departments').any((d) => d['active'] != false && (d['head_ids'] as List? ?? const []).contains(me['id']));
}

class MonEquipeScreen extends StatefulWidget {
  const MonEquipeScreen({super.key});

  @override
  State<MonEquipeScreen> createState() => _MonEquipeScreenState();
}

class _MonEquipeScreenState extends State<MonEquipeScreen> {
  late Future<Map<String, dynamic>> _future = _load();
  String? _dept;
  String _q = '';
  String _filter = '';
  Map<String, String?> _f = {};
  int _rk = 0;

  static const _views = {'': 'Tous', 'employees': 'Salariés', 'interns': 'Stagiaires', 'absent': 'Absents / en congé', 'requests': 'Avec demandes'};

  static bool _inView(Map<String, dynamic> m, String v) => switch (v) {
        'interns' => m['staff_type'] == 'intern' || m['staff_type'] == 'apprentice',
        'employees' => m['staff_type'] == 'employee',
        'absent' => m['today'] == 'absent' || m['today'] == 'leave',
        'requests' => (m['pending_requests'] ?? 0) > 0,
        _ => true,
      };

  Future<Map<String, dynamic>> _load() async => asMap(await context.read<Session>().api.get('rh/team', query: {if (_dept != null) 'department': _dept}));

  void _reload() => setState(() => _future = _load());

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final hr = s.can('rh.view') || s.can('rh.manage');
    final depts = LocalDb.hasEntity('rh_departments') ? (LocalDb.all('rh_departments').where((d) => d['active'] != false).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'))) : <Map<String, dynamic>>[];
    return Column(children: [
      PageHeader(title: tr('Mon équipe'), subtitle: tr('Vos collaborateurs et stagiaires : présence, congés, demandes, travaux')),
      Expanded(
        child: FutureBuilder<Map<String, dynamic>>(
          future: _future,
          builder: (context, snap) {
            if (snap.hasError) return OnlineOnlyNotice(error: snap.error!, onRetry: _reload);
            if (!snap.hasData) return const Center(child: CircularProgressIndicator());
            final d = snap.data!;
            final members = [for (final m in (d['members'] as List? ?? const [])) asMap(m)];
            final sum = asMap(d['summary']);
            final myDepts = [for (final x in (d['departments'] as List? ?? const [])) asMap(x)];
            if (members.isEmpty && myDepts.isEmpty && !hr) {
              return EmptyState(
                icon: Icons.groups_2_outlined,
                title: tr('Aucune équipe'),
                message: tr('Vous n\'êtes chef d\'aucun département. La RH désigne les chefs de département dans Ressources humaines → Postes & catégories → Départements.'),
              );
            }
            final q = _q.toLowerCase();
            final serverDept = hr && depts.isNotEmpty;
            final base = members
                .where((m) => q.isEmpty || '${m['name']} ${m['job_title'] ?? ''} ${m['department'] ?? ''}'.toLowerCase().contains(q))
                .where((m) => _f['department'] == null || '${m['department'] ?? ''}' == _f['department'])
                .where((m) => _f['today'] == null || '${m['today'] ?? ''}' == _f['today'])
                .where((m) => _f['contract'] == null || '${m['contract_type'] ?? ''}' == _f['contract'])
                .where((m) => _f['job'] == null || '${m['job_title'] ?? ''}' == _f['job'])
                .toList();
            final shown = base.where((m) => _inView(m, _filter)).toList();
            final pad = MediaQuery.of(context).size.width < 700 ? 16.0 : 24.0;
            return RefreshIndicator(
              onRefresh: () async => _reload(),
              child: ListView(padding: const EdgeInsets.only(bottom: 32), children: [
                if (myDepts.isNotEmpty)
                  Padding(
                    padding: EdgeInsets.fromLTRB(pad, 4, pad, 12),
                    child: Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      for (final x in myDepts) StatusChip('${x['name']}', color: YColors.blue, icon: Icons.apartment_outlined),
                    ]),
                  ),
                StatStrip(tiles: [
                  StatTile(label: 'Équipe', value: '${sum['total'] ?? 0}', detail: '${sum['employees'] ?? 0} salarié(s) · ${sum['interns'] ?? 0} stagiaire(s)', icon: Icons.groups_2_outlined),
                  if (d['presence_tracked'] == true)
                    StatTile(label: 'Présents aujourd\'hui', value: '${(sum['present'] ?? 0) + (sum['late'] ?? 0)}', detail: '${sum['late'] ?? 0} en retard', icon: Icons.how_to_reg_outlined, color: YColors.success),
                  StatTile(label: 'Absents ou en congé', value: '${(sum['absent'] ?? 0) + (sum['leave'] ?? 0)}', detail: '${sum['leave'] ?? 0} en congé', icon: Icons.event_busy_outlined, color: YColors.warning),
                  StatTile(label: 'Demandes en attente', value: '${sum['pending_requests'] ?? 0}', detail: 'congés, absences, autres', icon: Icons.mark_email_unread_outlined, color: const Color(0xFF7C3AED)),
                ]),
                FilterBar(
                  key: ValueKey(_rk),
                  padding: EdgeInsets.fromLTRB(pad, 0, pad, 12),
                  onSearch: (v) => setState(() => _q = v),
                  search: _q,
                  searchHint: 'Nom, fonction…',
                  statuses: _views,
                  status: _filter,
                  onStatus: (v) => setState(() => _filter = v),
                  statusCounts: {for (final k in _views.keys) k: base.where((m) => _inView(m, k)).length},
                  filters: [
                    if (serverDept)
                      FilterSpec('dept', 'Département', {for (final x in depts) '${x['id']}': '${x['name']}'}, icon: Icons.apartment_outlined)
                    else
                      FilterSpec('department', 'Département', filterOptions(members, 'department'), icon: Icons.apartment_outlined),
                    FilterSpec('today', 'Aujourd\'hui', {for (final e in _todayStatus.entries) if (members.any((m) => m['today'] == e.key)) e.key: e.value.$1}, icon: Icons.today_outlined),
                    FilterSpec('contract', 'Contrat', filterOptions(members, 'contract_type', label: (m) => contractTypes['${m['contract_type']}'] ?? '${m['contract_type']}'), icon: Icons.description_outlined),
                    FilterSpec('job', 'Fonction', filterOptions(members, 'job_title'), icon: Icons.work_outline),
                  ],
                  values: {..._f, 'dept': _dept},
                  onFilter: (k, v) {
                    if (k == 'dept') {
                      _dept = v;
                      _reload();
                    } else {
                      setState(() => _f = {..._f, k: v});
                    }
                  },
                  count: shown.length,
                  countLabel: 'personne',
                  onReset: () {
                    final reloadDept = _dept != null;
                    setState(() => (_q = '', _filter = '', _f = {}, _rk++, _dept = null));
                    if (reloadDept) _reload();
                  },
                ),
                Padding(
                  padding: EdgeInsets.symmetric(horizontal: pad),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    if (shown.isEmpty)
                      Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(tr('Personne ne correspond.'), style: TextStyle(color: Theme.of(context).hintColor))))
                    else
                      LayoutBuilder(builder: (context, c) {
                        final cols = (c.maxWidth / 320).floor().clamp(1, 4);
                        final w = (c.maxWidth - 12 * (cols - 1)) / cols;
                        return Wrap(spacing: 12, runSpacing: 12, children: [for (final m in shown) SizedBox(width: w, child: _MemberCard(m, cur: s.currency))]);
                      }),
                  ]),
                ),
              ]),
            );
          },
        ),
      ),
    ]);
  }
}

class _MemberCard extends StatelessWidget {
  const _MemberCard(this.m, {required this.cur});

  final Map<String, dynamic> m;
  final String cur;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final today = _todayStatus['${m['today'] ?? ''}'];
    final staffColor = _staffColors['${m['staff_type']}'] ?? YColors.blue;
    final leaves = [for (final l in (m['leaves'] as List? ?? const [])) asMap(l)];
    final work = m['work'] == null ? null : asMap(m['work']);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => showTeamMember(context, '${m['id']}', '${m['name']}'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              InitialsAvatar('${m['name']}', size: 44),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(tr('${m['name']}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15), maxLines: 1, overflow: TextOverflow.ellipsis),
                  Text(tr('${m['job_title'] ?? m['department'] ?? ''}'), style: TextStyle(color: theme.hintColor, fontSize: 12.5), maxLines: 1, overflow: TextOverflow.ellipsis),
                ]),
              ),
              if (today != null) Tooltip(message: tr(today.$1), child: Icon(today.$3, color: today.$2, size: 22)),
            ]),
            const SizedBox(height: 10),
            Wrap(spacing: 6, runSpacing: 6, children: [
              StatusChip('${m['staff_label'] ?? 'Salarié'}', color: staffColor, icon: m['staff_type'] == 'intern' ? Icons.school_outlined : Icons.badge_outlined),
              if (today != null) StatusChip(today.$1, color: today.$2),
              if (m['contract_type'] != null) StatusChip(contractTypes['${m['contract_type']}'] ?? '${m['contract_type']}', color: YColors.muted, icon: Icons.description_outlined),
              if (m['seniority_months'] != null) StatusChip(_months(m['seniority_months']), color: YColors.muted, icon: Icons.timelapse),
              if ((m['pending_requests'] ?? 0) > 0) StatusChip('${m['pending_requests']} demande(s)', color: const Color(0xFF7C3AED), icon: Icons.mail_outline),
            ]),
            if (leaves.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                tr('${leaves.first['type']} du ${dateFr(leaves.first['start_date'])} au ${dateFr(leaves.first['end_date'])}${leaves.first['status'] == 'requested' ? ' (demandé)' : ''}'),
                style: TextStyle(fontSize: 12.5, color: theme.hintColor),
              ),
            ],
            if (work != null || m['base_salary'] != null || m['month'] != null) ...[
              const Divider(height: 20),
              Wrap(spacing: 14, runSpacing: 6, children: [
                if (m['month'] != null) _mini(context, Icons.access_time, '${asMap(m['month'])['worked_hours']} h ce mois'),
                if (m['month'] != null && (asMap(m['month'])['late_count'] ?? 0) > 0) _mini(context, Icons.schedule, '${asMap(m['month'])['late_count']} retard(s)', color: YColors.warning),
                if (work != null) _mini(context, Icons.task_alt, '${work['open_tasks']} tâche(s) en cours'),
                if (work != null && (work['late_tasks'] ?? 0) > 0) _mini(context, Icons.warning_amber_rounded, '${work['late_tasks']} en retard', color: YColors.danger),
                if (work != null && (work['done_this_month'] ?? 0) > 0) _mini(context, Icons.done_all, '${work['done_this_month']} faite(s) ce mois', color: YColors.success),
                if (m['base_salary'] != null) _mini(context, Icons.payments_outlined, money(m['base_salary'], cur)),
              ]),
            ],
          ]),
        ),
      ),
    );
  }

  Widget _mini(BuildContext context, IconData i, String t, {Color? color}) => Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(i, size: 15, color: color ?? Theme.of(context).hintColor),
        const SizedBox(width: 4),
        Text(tr(t), style: TextStyle(fontSize: 12.5, color: color ?? Theme.of(context).hintColor, fontWeight: FontWeight.w600)),
      ]);
}

// ---------------------------------------------------------------------------
// Fiche 360° d'un collaborateur (onglets selon les droits)
// ---------------------------------------------------------------------------

Future<void> showTeamMember(BuildContext context, String id, String name) {
  return showFormDialog(context, title: name, maxWidth: 860, builder: (_) => _MemberView(id: id));
}

class _MemberView extends StatefulWidget {
  const _MemberView({required this.id});

  final String id;

  @override
  State<_MemberView> createState() => _MemberViewState();
}

class _MemberViewState extends State<_MemberView> {
  late final Future<Map<String, dynamic>> _future = context.read<Session>().api.get('rh/team/${widget.id}').then(asMap);

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    return FutureBuilder<Map<String, dynamic>>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return Padding(padding: const EdgeInsets.all(24), child: OnlineOnlyNotice(error: snap.error!, onRetry: () => setState(() {})));
        if (!snap.hasData) return const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator()));
        final m = snap.data!;
        final r = asMap(m['rights']);
        final tabs = <(String, IconData, Widget)>[
          ('Profil', Icons.person_outline, _profile(context, m, s)),
          if (r['contract'] == true) ('Contrat', Icons.description_outlined, _contract(context, m, s)),
          if (r['presence'] == true) ('Présence & absences', Icons.event_available_outlined, _presence(context, m)),
          if (r['requests'] == true) ('Demandes', Icons.mail_outline, _requests(context, m)),
          if (r['salary'] == true) ('Paie', Icons.payments_outlined, _pay(context, m, s)),
          if (r['documents'] == true) ('Documents', Icons.folder_outlined, _documents(context, m)),
          if (r['work'] == true) ('Travaux', Icons.task_alt, _work(context, m)),
        ];
        return SizedBox(
          height: MediaQuery.of(context).size.height * .72,
          child: DefaultTabController(
            length: tabs.length,
            child: Column(children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(24, 0, 24, 8),
                child: Row(children: [
                  InitialsAvatar('${m['name']}', size: 52),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr([m['job_title'], m['department']].where((x) => x != null && '$x'.isNotEmpty).join(' · ')), style: const TextStyle(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 4),
                      Wrap(spacing: 6, runSpacing: 4, children: [
                        StatusChip('${m['staff_label'] ?? ''}', color: _staffColors['${m['staff_type']}'] ?? YColors.blue),
                        if (m['matricule'] != null) StatusChip('${m['matricule']}', color: YColors.muted, icon: Icons.tag),
                        if (m['contract_type'] != null) StatusChip(contractTypes['${m['contract_type']}'] ?? '${m['contract_type']}', color: YColors.muted),
                        if (m['cnps_declared'] == false) const StatusChip('Non déclaré CNPS', color: YColors.warning),
                      ]),
                    ]),
                  ),
                  if (s.can('rh.view') || s.can('rh.manage'))
                    TextButton.icon(
                      onPressed: () => showEmployeeDossier(context, LocalDb.get('rh_employees', widget.id) ?? {'id': widget.id, 'last_name': m['name']}),
                      icon: const Icon(Icons.history, size: 18),
                      label: Text(tr('Historique détaillé')),
                    ),
                ]),
              ),
              TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(icon: Icon(t.$2, size: 18), text: tr(t.$1), iconMargin: const EdgeInsets.only(bottom: 2))]),
              Expanded(child: TabBarView(children: [for (final t in tabs) t.$3])),
            ]),
          ),
        );
      },
    );
  }

  Widget _rows(BuildContext context, List<(String, dynamic)> rows) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: theme.colorScheme.surfaceContainerLow, borderRadius: BorderRadius.circular(12)),
      child: Column(children: [
        for (final r in rows.where((r) => r.$2 != null && '${r.$2}'.isNotEmpty))
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              SizedBox(width: 190, child: Text(tr(r.$1), style: TextStyle(color: theme.hintColor))),
              Expanded(child: Text(tr('${r.$2}'), style: const TextStyle(fontWeight: FontWeight.w600))),
            ]),
          ),
      ]),
    );
  }

  Widget _pad(List<Widget> children) => ListView(padding: const EdgeInsets.fromLTRB(24, 16, 24, 24), children: children);

  Widget _empty(BuildContext context, String t) => Padding(padding: const EdgeInsets.all(16), child: Text(tr(t), style: TextStyle(color: Theme.of(context).hintColor)));

  Widget _profile(BuildContext context, Map m, Session s) => _pad([
        _rows(context, [
          ('Nom', m['name']),
          ('Fonction', m['job_title']),
          ('Département', m['department']),
          ('Statut', m['staff_label']),
          ('Téléphone', m['phone']),
          ('E-mail', m['email']),
          ('Adresse', m['address']),
          ('Date de naissance', m['birth_date'] == null ? null : dateFr(m['birth_date'])),
          ('Pièce d\'identité', m['id_number']),
          ('Personne à prévenir', m['emergency_contact']),
        ]),
        if (asMap(m['rights'])['personal'] != true) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('Coordonnées et pièces : non ouvertes par la RH pour votre département.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5))),
      ]);

  Widget _contract(BuildContext context, Map m, Session s) {
    final contracts = [for (final c in (m['contracts'] as List? ?? const [])) asMap(c)];
    final sched = asMap(m['work_schedule']);
    const days = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    return _pad([
      _rows(context, [
        ('Type de contrat', contractTypes['${m['contract_type']}'] ?? m['contract_type']),
        ('Prise de fonction', m['hire_date'] == null ? null : dateFr(m['hire_date'])),
        ('Fin de contrat', m['end_date'] == null ? null : dateFr(m['end_date'])),
        ('Ancienneté', m['seniority_months'] == null ? null : _months(m['seniority_months'])),
        ('Heures par semaine', m['weekly_hours'] == null ? null : '${number(m['weekly_hours'], decimals: 1)} h'),
        ('Déclaré à la CNPS', m['cnps_declared'] == null ? null : (m['cnps_declared'] == true ? 'Oui' : 'Non')),
        ('CMU', m['cmu_enrolled'] == null ? null : (m['cmu_enrolled'] == true ? 'Oui' : 'Non')),
        ('Assurance santé', m['has_insurance'] == true ? (m['insurance_name'] ?? 'Oui') : (m['has_insurance'] == false ? 'Non' : null)),
      ]),
      if (sched.isNotEmpty) ...[
        const SizedBox(height: 14),
        Text(tr('Horaires de travail'), style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (var d = 1; d <= 7; d++)
            if (sched['$d'] != null && asMap(sched['$d'])['start'] != null) StatusChip('${days[d]} ${asMap(sched['$d'])['start']}–${asMap(sched['$d'])['end']}', color: YColors.blue, icon: Icons.schedule),
        ]),
      ],
      const SizedBox(height: 14),
      Text(tr('Contrats'), style: const TextStyle(fontWeight: FontWeight.w700)),
      if (contracts.isEmpty) _empty(context, 'Aucun contrat enregistré.'),
      for (final c in contracts)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.description_outlined, color: YColors.blue),
          title: Text(tr('${contractTypes['${c['type']}'] ?? c['type']} · ${c['number'] ?? ''}')),
          subtitle: Text(tr([
            'du ${dateFr(c['start_date'])}',
            if (c['end_date'] != null) 'au ${dateFr(c['end_date'])}',
            if (c['trial_end_date'] != null) 'essai jusqu\'au ${dateFr(c['trial_end_date'])}',
            if (c['salary'] != null) money(c['salary'], s.currency),
          ].join(' · '))),
          trailing: StatusChip(const {'active': 'En vigueur', 'ended': 'Terminé', 'terminated': 'Rompu', 'draft': 'Brouillon'}['${c['status']}'] ?? '${c['status']}', color: c['status'] == 'active' ? YColors.success : YColors.muted),
        ),
    ]);
  }

  Widget _presence(BuildContext context, Map m) {
    final p = m['presence'] == null ? null : asMap(m['presence']);
    final month = p?['month'] == null ? null : asMap(p!['month']);
    final leaves = [for (final l in (m['leaves'] as List? ?? const [])) asMap(l)];
    return _pad([
      if (month != null)
        Wrap(spacing: 10, runSpacing: 10, children: [
          for (final k in [
            ('Heures ce mois', '${month['worked_hours']} h', Icons.access_time, YColors.blue),
            ('Jours présents', '${month['present_days']}', Icons.how_to_reg_outlined, YColors.success),
            ('Absences', '${month['absences']}', Icons.cancel_outlined, YColors.danger),
            ('Retards', '${month['late_count']} (${month['late_minutes']} min)', Icons.schedule, YColors.warning),
          ])
            KpiBox(width: 190, child: StatTile(label: k.$1, value: k.$2, icon: k.$3, color: k.$4)),
        ]),
      const SizedBox(height: 14),
      Text(tr('Congés et absences'), style: const TextStyle(fontWeight: FontWeight.w700)),
      if (leaves.isEmpty) _empty(context, 'Aucun congé ni absence.'),
      for (final l in leaves)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(l['type'] == 'sick' ? Icons.medical_services_outlined : Icons.event_note, color: l['type'] == 'sick' ? YColors.danger : YColors.blue),
          title: Text(tr('${l['type_label']} · ${number(l['days'], decimals: 1)} j')),
          subtitle: Text(tr('Du ${dateFr(l['start_date'])} au ${dateFr(l['end_date'])}${l['reason'] != null ? ' · ${l['reason']}' : ''}')),
          trailing: StatusChip(const {'approved': 'Approuvé', 'requested': 'Demandé', 'rejected': 'Refusé', 'cancelled': 'Annulé'}['${l['status']}'] ?? '${l['status']}',
              color: l['status'] == 'approved' ? YColors.success : (l['status'] == 'requested' ? YColors.warning : YColors.muted)),
        ),
    ]);
  }

  Widget _requests(BuildContext context, Map m) {
    final reqs = [for (final r in (m['requests'] as List? ?? const [])) asMap(r)];
    return _pad([
      if (reqs.isEmpty) _empty(context, 'Aucune demande.'),
      for (final r in reqs)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(requestTypes['${r['type']}']?.$2 ?? Icons.mail_outline, color: requestTypes['${r['type']}']?.$3),
          title: Text(tr('${r['type_label']} · ${r['number'] ?? ''}')),
          subtitle: Text(tr([
            '${r['subject'] ?? ''}',
            dateTimeFr('${r['created_at']}'),
            if ((r['attachments'] as List? ?? const []).isNotEmpty) '${(r['attachments'] as List).length} justificatif(s)',
          ].where((x) => x.isNotEmpty).join(' · '))),
          trailing: StatusChip(requestStatuses['${r['status']}']?.$1 ?? '${r['status']}', color: requestStatuses['${r['status']}']?.$2 ?? YColors.muted),
          onTap: LocalDb.hasEntity('rh_requests') && LocalDb.get('rh_requests', '${r['id']}') != null ? () => showRequest(context, LocalDb.get('rh_requests', '${r['id']}')!) : null,
        ),
    ]);
  }

  Widget _pay(BuildContext context, Map m, Session s) {
    final slips = [for (final p in (m['payslips'] as List? ?? const [])) asMap(p)];
    return _pad([
      _rows(context, [
        ('Salaire de base', m['base_salary'] == null ? null : money(m['base_salary'], s.currency)),
        ('Indemnité de transport', m['transport_allowance'] == null ? null : money(m['transport_allowance'], s.currency)),
        ('Taux horaire', m['hourly_rate'] == null ? null : '${money(m['hourly_rate'], s.currency)} / h'),
      ]),
      const SizedBox(height: 14),
      Text(tr('Bulletins'), style: const TextStyle(fontWeight: FontWeight.w700)),
      if (slips.isEmpty) _empty(context, 'Aucun bulletin validé.'),
      for (final p in slips)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.receipt_long_outlined, color: YColors.blue),
          title: Text(tr(periodLabel('${p['period']}'))),
          subtitle: Text(tr('Brut ${money(p['gross'], s.currency)} · net ${money(p['net'], s.currency)}')),
          trailing: StatusChip(p['status'] == 'paid' ? 'Payé' : 'Validé', color: p['status'] == 'paid' ? YColors.success : YColors.blue),
        ),
    ]);
  }

  Widget _documents(BuildContext context, Map m) {
    final docs = [for (final d in (m['documents'] as List? ?? const [])) asMap(d)];
    return _pad([
      if (docs.isEmpty) _empty(context, 'Aucun document RH.'),
      for (final d in docs)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.article_outlined, color: YColors.blue),
          title: Text(tr('${d['title']}')),
          subtitle: Text(tr('${d['number'] ?? ''} · ${dateFr(d['date'])}')),
        ),
    ]);
  }

  Widget _work(BuildContext context, Map m) {
    final w = m['work'] == null ? null : asMap(m['work']);
    final tasks = [for (final t in (m['tasks'] as List? ?? const [])) asMap(t)];
    const st = {'todo': ('À faire', YColors.muted), 'doing': ('En cours', YColors.blue), 'review': ('À valider', YColors.warning), 'done': ('Terminée', YColors.success)};
    return _pad([
      if (w != null)
        Wrap(spacing: 10, runSpacing: 10, children: [
          KpiBox(width: 190, child: StatTile(label: 'Tâches en cours', value: '${w['open_tasks']}', icon: Icons.task_alt)),
          KpiBox(width: 190, child: StatTile(label: 'En retard', value: '${w['late_tasks']}', icon: Icons.warning_amber_rounded, color: YColors.danger)),
          KpiBox(width: 190, child: StatTile(label: 'Faites ce mois', value: '${w['done_this_month']}', icon: Icons.done_all, color: YColors.success)),
          KpiBox(width: 190, child: StatTile(label: 'Temps passé ce mois', value: '${w['hours_this_month']} h', icon: Icons.timer_outlined, color: const Color(0xFF7C3AED))),
        ]),
      const SizedBox(height: 14),
      if (tasks.isEmpty) _empty(context, w == null ? 'Les travaux apparaissent avec le module Projets (tâches et temps passés).' : 'Aucune tâche récente.'),
      for (final t in tasks)
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(t['status'] == 'done' ? Icons.check_circle : Icons.radio_button_unchecked, color: t['status'] == 'done' ? YColors.success : Theme.of(context).hintColor),
          title: Text(tr('${t['title']}')),
          subtitle: Text(tr([t['project'], if (t['due_date'] != null) 'échéance ${dateFr(t['due_date'])}'].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
          trailing: StatusChip(st['${t['status']}']?.$1 ?? '${t['status']}', color: st['${t['status']}']?.$2 ?? YColors.muted),
        ),
    ]);
  }
}
