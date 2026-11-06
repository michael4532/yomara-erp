import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'rh_screen.dart' show empName, myEmployee, rhEmployees;

// ===========================================================================
// Talents : entretiens d'évaluation, plan de formation, parcours d'arrivée et de départ.
// Hors ligne. Le salarié remplit son auto-évaluation, le responsable son évaluation, la RH organise.
// ===========================================================================

const reviewTypes = {'annual': 'Entretien annuel', 'trial': 'Fin de période d\'essai', 'mid': 'Point à mi-parcours', 'exit': 'Entretien de départ'};
const reviewStatuses = <String, (String, Color)>{
  'planned': ('Planifié', YColors.blue),
  'self': ('Auto-évaluation', YColors.warning),
  'manager': ('Chez le responsable', Color(0xFF7C3AED)),
  'done': ('Terminé', YColors.success),
  'cancelled': ('Annulé', YColors.muted),
};
const reviewOutcomes = {
  'confirm': 'Confirmation dans le poste',
  'extend': 'Renouvellement de la période d\'essai',
  'end': 'Fin de la période d\'essai',
  'promotion': 'Promotion proposée',
  'raise': 'Augmentation proposée',
  'training': 'Formation à prévoir',
  'none': 'Pas de changement',
};
const trainingStatuses = <String, (String, Color)>{
  'planned': ('Prévue', YColors.blue),
  'ongoing': ('En cours', YColors.warning),
  'done': ('Réalisée', YColors.success),
  'cancelled': ('Annulée', YColors.muted),
};
const checklistKinds = {'onboarding': 'Arrivée', 'offboarding': 'Départ'};
const checklistOwners = {'rh': 'RH', 'manager': 'Responsable', 'it': 'Informatique', 'employee': 'Salarié'};

List<Map<String, dynamic>> _all(String e) => LocalDb.hasEntity(e) ? LocalDb.all(e) : [];

String _emp(dynamic id) {
  if (id == null || !LocalDb.hasEntity('rh_employees')) return '—';
  final e = LocalDb.get('rh_employees', '$id');
  return e == null ? '—' : empName(e);
}

/// Salariés de l'équipe du salarié connecté (départements dirigés, sous-départements compris, et collaborateurs directs).
Set<String> myTeamIds(Session s) {
  final me = myEmployee(s);
  if (me == null) return {};
  final depts = _all('rh_departments');
  final led = <String>{for (final d in depts) if ((d['head_ids'] as List? ?? const []).contains(me['id'])) '${d['id']}'};
  var grew = true;
  while (grew) {
    grew = false;
    for (final d in depts) {
      if (d['parent_id'] != null && led.contains('${d['parent_id']}') && led.add('${d['id']}')) grew = true;
    }
  }
  return {
    for (final e in _all('rh_employees'))
      if (e['id'] != me['id'] && (led.contains('${e['department_id']}') || e['manager_id'] == me['id'])) '${e['id']}',
  };
}

double? _num(dynamic v) => v is num ? v.toDouble() : double.tryParse('${v ?? ''}'.replaceAll(',', '.'));

class TalentsScreen extends StatefulWidget {
  const TalentsScreen({super.key});

  @override
  State<TalentsScreen> createState() => _TalentsScreenState();
}

class _TalentsScreenState extends State<TalentsScreen> with SingleTickerProviderStateMixin {
  late final Session _s = context.read<Session>();
  late final bool _hr = _s.can('talents.manage');
  late final TabController _tabs = TabController(length: 3, vsync: this);

  @override
  Widget build(BuildContext context) {
    if (!LocalDb.hasEntity('tal_reviews')) {
      return Column(children: [
        PageHeader(title: tr('Talents')),
        Expanded(child: EmptyState(icon: Icons.sync, title: tr('Synchronisation nécessaire'), message: tr('Synchronisez pour recevoir les entretiens, formations et parcours.'))),
      ]);
    }
    final canPlan = _hr || myTeamIds(_s).isNotEmpty;
    return Column(children: [
      PageHeader(
        title: tr('Talents'),
        subtitle: tr('Entretiens d\'évaluation, plan de formation, parcours d\'arrivée et de départ'),
        actions: [
          AnimatedBuilder(
            animation: _tabs,
            builder: (_, _) => switch (_tabs.index) {
              0 when canPlan => FilledButton.icon(onPressed: () => newReview(context), icon: const Icon(Icons.add), label: Text(tr('Planifier un entretien'))),
              1 when _hr => FilledButton.icon(onPressed: () => editTraining(context), icon: const Icon(Icons.add), label: Text(tr('Nouvelle formation'))),
              2 when _hr => FilledButton.icon(onPressed: () => newChecklist(context), icon: const Icon(Icons.add), label: Text(tr('Nouveau parcours'))),
              _ => const SizedBox.shrink(),
            },
          ),
        ],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Entretiens')), Tab(text: tr('Formations')), Tab(text: tr('Arrivées & départs'))]),
      Expanded(child: TabBarView(controller: _tabs, children: const [_ReviewsTab(), _TrainingsTab(), _ChecklistsTab()])),
    ]);
  }
}

double _pad(BuildContext c) => MediaQuery.of(c).size.width < 700 ? 16.0 : 24.0;

EdgeInsets _barPad(BuildContext context) => EdgeInsets.fromLTRB(_pad(context), 8, _pad(context), 8);

String _dept(dynamic employeeId) => '${employeeId == null ? '' : (LocalDb.get('rh_employees', '$employeeId')?['department'] ?? '')}';

Map<String, String> _deptOptions() => filterOptions(_all('rh_employees'), 'department');

// ---------------------------------------------------------------------------
// Entretiens
// ---------------------------------------------------------------------------

class _ReviewsTab extends StatefulWidget {
  const _ReviewsTab();

  @override
  State<_ReviewsTab> createState() => _ReviewsTabState();
}

class _ReviewsTabState extends State<_ReviewsTab> {
  String _filter = 'open';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Listenable.merge([LocalDb.listen('tal_reviews'), LocalDb.listen('rh_employees')]),
      builder: (context, _) {
        final all = _all('tal_reviews')..sort((a, b) => '${b['scheduled_on'] ?? b['created_at'] ?? ''}'.compareTo('${a['scheduled_on'] ?? a['created_at'] ?? ''}'));
        final open = all.where((r) => !['done', 'cancelled'].contains(r['status'])).toList();
        final done = all.where((r) => r['status'] == 'done').toList();
        final scores = done.map((r) => _num(r['score'])).whereType<double>().toList();
        final late = open.where((r) => DateTime.tryParse('${r['scheduled_on'] ?? ''}')?.isBefore(DateTime.now().subtract(const Duration(days: 1))) ?? false).length;
        bool base(Map<String, dynamic> r) =>
            (_q.isEmpty || '${_emp(r['employee_id'])} ${_emp(r['reviewer_id'])} ${r['period'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
            (_f['type'] == null || '${r['type']}' == _f['type']) &&
            (_f['employee'] == null || '${r['employee_id']}' == _f['employee']) &&
            (_f['reviewer'] == null || '${r['reviewer_id']}' == _f['reviewer']) &&
            (_f['department'] == null || _dept(r['employee_id']) == _f['department']) &&
            (_f['outcome'] == null || '${r['outcome']}' == _f['outcome']) &&
            (_period == null || _period!.contains(r['scheduled_on'] ?? r['created_at']));
        bool st(Map<String, dynamic> r, String k) => switch (k) {
              'open' => !['done', 'cancelled'].contains(r['status']),
              '' => true,
              _ => r['status'] == k,
            };
        final shown = all.where((r) => base(r) && st(r, _filter)).toList();
        return ListView(padding: const EdgeInsets.only(top: 16, bottom: 32), children: [
          StatStrip(tiles: [
            StatTile(label: 'À mener', value: '${open.length}', detail: late > 0 ? '$late en retard' : 'entretiens en cours', icon: Icons.event_note_outlined, color: late > 0 ? YColors.warning : YColors.blue),
            StatTile(label: 'Terminés', value: '${done.length}', detail: 'cette année et avant', icon: Icons.task_alt, color: YColors.success),
            StatTile(
              label: 'Note moyenne',
              value: scores.isEmpty ? '—' : '${number(scores.reduce((a, b) => a + b) / scores.length, decimals: 1)} / 5',
              detail: 'entretiens terminés',
              icon: Icons.star_outline,
              color: const Color(0xFF7C3AED),
            ),
          ]),
          FilterBar(
            padding: _barPad(context),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Salarié, évaluateur, période…',
            statuses: const {'open': 'En cours', 'done': 'Terminés', 'cancelled': 'Annulés', '': 'Tous'},
            status: _filter,
            onStatus: (v) => setState(() => _filter = v),
            statusCounts: {for (final k in ['open', 'done', 'cancelled', '']) k: all.where((r) => base(r) && st(r, k)).length},
            filters: [
              const FilterSpec('type', 'Type', reviewTypes, icon: Icons.record_voice_over_outlined),
              FilterSpec('employee', 'Salarié', filterOptions(all, 'employee_id', label: (r) => _emp(r['employee_id'])), icon: Icons.person_outline),
              FilterSpec('reviewer', 'Évaluateur', filterOptions(all, 'reviewer_id', label: (r) => _emp(r['reviewer_id'])), icon: Icons.supervisor_account_outlined),
              FilterSpec('department', 'Service', _deptOptions(), icon: Icons.apartment_outlined),
              const FilterSpec('outcome', 'Décision', reviewOutcomes, icon: Icons.flag_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: shown.length,
            countLabel: 'entretien',
            onReset: () => setState(() => (_q = '', _filter = 'open', _f = {}, _period = null)),
          ),
          if (shown.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 40),
              child: EmptyState(icon: Icons.record_voice_over_outlined, title: tr('Aucun entretien'), message: tr('Planifiez les entretiens annuels et de fin de période d\'essai : le salarié prépare son auto-évaluation, son responsable l\'évalue.')),
            ),
          for (final r in shown)
            Padding(
              padding: EdgeInsets.symmetric(horizontal: _pad(context), vertical: 4),
              child: Card(
                child: ListTile(
                  leading: InitialsAvatar(_emp(r['employee_id'])),
                  title: Text(tr(_emp(r['employee_id'])), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text(tr([
                    reviewTypes[r['type']] ?? 'Entretien',
                    if (r['period'] != null) '${r['period']}',
                    if (r['scheduled_on'] != null) dateFr('${r['scheduled_on']}'),
                    if (r['reviewer_id'] != null) 'avec ${_emp(r['reviewer_id'])}',
                  ].join(' · '))),
                  trailing: Wrap(spacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
                    if (_num(r['score']) != null) Text('${number(_num(r['score'])!, decimals: 1)}/5', style: const TextStyle(fontWeight: FontWeight.w700)),
                    StatusChip(reviewStatuses[r['status']]?.$1 ?? '${r['status']}', color: reviewStatuses[r['status']]?.$2 ?? YColors.muted),
                  ]),
                  onTap: () => showReview(context, '${r['id']}'),
                ),
              ),
            ),
        ]);
      },
    );
  }
}

Future<void> newReview(BuildContext context, {String? employeeId}) => showFormDialog(context, title: 'Planifier un entretien', builder: (_) => _NewReviewForm(employeeId: employeeId));

class _NewReviewForm extends StatefulWidget {
  const _NewReviewForm({this.employeeId});

  final String? employeeId;

  @override
  State<_NewReviewForm> createState() => _NewReviewFormState();
}

class _NewReviewFormState extends State<_NewReviewForm> {
  late final Session _s = context.read<Session>();
  late String? _employee = widget.employeeId;
  String _type = 'annual';
  DateTime? _date = DateTime.now().add(const Duration(days: 7));
  final _period = TextEditingController(text: '${DateTime.now().year}');
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    final team = myTeamIds(_s);
    final staff = rhEmployees().where((e) => _s.can('talents.manage') || team.contains(e['id'])).toList();
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Field(
          label: 'Salarié',
          child: DropdownButtonFormField<String>(
            initialValue: _employee,
            isExpanded: true,
            items: [for (final e in staff) DropdownMenuItem(value: '${e['id']}', child: Text(tr(empName(e)), overflow: TextOverflow.ellipsis))],
            onChanged: (v) => setState(() => _employee = v),
          ),
        ),
        Field(
          label: 'Type',
          child: Wrap(spacing: 8, runSpacing: 8, children: [
            for (final t in reviewTypes.entries) ChoiceChip(label: Text(tr(t.value)), selected: _type == t.key, onSelected: (_) => setState(() => _type = t.key)),
          ]),
        ),
        Row(children: [
          Expanded(child: Field(label: 'Période', child: TextField(controller: _period))),
          const SizedBox(width: 12),
          Expanded(
            child: Field(
              label: 'Date prévue',
              child: OutlinedButton.icon(
                onPressed: () async {
                  final d = await pickDate(context, firstDate: DateTime(2020), lastDate: DateTime(2100), initialDate: _date ?? DateTime.now());
                  if (d != null) setState(() => _date = d);
                },
                icon: const Icon(Icons.event),
                label: Text(_date == null ? tr('Choisir') : dateFr(_date!.toIso8601String())),
              ),
            ),
          ),
        ]),
        const SizedBox(height: 8),
        Text(tr('Le salarié reçoit une notification pour préparer son auto-évaluation ; son responsable mène l\'entretien.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _busy || _employee == null
              ? null
              : () async {
                  setState(() => _busy = true);
                  try {
                    await context.read<SyncEngine>().save('tal_reviews', {
                      'id': newId(),
                      'employee_id': _employee,
                      'type': _type,
                      'period': _period.text.trim().isEmpty ? null : _period.text.trim(),
                      'scheduled_on': _date?.toIso8601String().substring(0, 10),
                      'status': 'self',
                    });
                    if (context.mounted) Navigator.pop(context);
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                    setState(() => _busy = false);
                  }
                },
          child: Text(tr('Planifier')),
        ),
      ]),
    );
  }
}

Future<void> showReview(BuildContext context, String id) => showFormDialog(context, title: 'Entretien', maxWidth: 820, builder: (_) => _ReviewView(id: id));

class _ReviewView extends StatefulWidget {
  const _ReviewView({required this.id});

  final String id;

  @override
  State<_ReviewView> createState() => _ReviewViewState();
}

class _ReviewViewState extends State<_ReviewView> {
  late final Session _s = context.read<Session>();
  late final Map<String, dynamic> _r = Map<String, dynamic>.from(LocalDb.get('tal_reviews', widget.id) ?? {});
  late final List<Map<String, dynamic>> _ratings = [for (final x in (_r['ratings'] as List? ?? const [])) Map<String, dynamic>.from(x as Map)];
  late final List<Map<String, dynamic>> _objectives = [for (final x in (_r['objectives'] as List? ?? const [])) Map<String, dynamic>.from(x as Map)];
  late final List<Map<String, dynamic>> _next = [for (final x in (_r['next_objectives'] as List? ?? const [])) Map<String, dynamic>.from(x as Map)];
  late final _c = {
    for (final k in ['employee_comment', 'manager_comment', 'strengths', 'improvements', 'training_needs']) k: TextEditingController(text: '${_r[k] ?? ''}'),
  };
  late String? _outcome = _r['outcome'] as String?;
  bool _busy = false;

  String get _me => '${myEmployee(_s)?['id'] ?? ''}';
  bool get _hr => _s.can('talents.manage');
  bool get _isEmployee => _r['employee_id'] == _me && !_hr;
  bool get _isReviewer => !_hr && (_r['reviewer_id'] == _me || (_r['reviewer_id'] == null && myTeamIds(_s).contains(_r['employee_id'])));
  bool get _done => _r['status'] == 'done' || _r['status'] == 'cancelled';
  bool get _canSelf => !_done && (_hr || (_isEmployee && ['planned', 'self'].contains(_r['status'])));
  bool get _canManager => !_done && (_hr || _isReviewer);

  Future<void> _save({String? status}) async {
    setState(() => _busy = true);
    final data = <String, dynamic>{
      'id': widget.id,
      'ratings': _ratings,
      if (_canSelf) ...{'employee_comment': _c['employee_comment']!.text.trim(), 'training_needs': _c['training_needs']!.text.trim()},
      if (_canManager) ...{
        'objectives': _objectives.where((o) => '${o['title'] ?? ''}'.trim().isNotEmpty).toList(),
        'next_objectives': _next.where((o) => '${o['title'] ?? ''}'.trim().isNotEmpty).toList(),
        'manager_comment': _c['manager_comment']!.text.trim(),
        'strengths': _c['strengths']!.text.trim(),
        'improvements': _c['improvements']!.text.trim(),
        'training_needs': _c['training_needs']!.text.trim(),
        'outcome': _outcome,
      },
      'status': ?status,
    };
    try {
      await context.read<SyncEngine>().save('tal_reviews', data);
      if (!mounted) return;
      toast(context, tr(status == 'manager' ? 'Auto-évaluation transmise à votre responsable.' : (status == 'done' ? 'Entretien clôturé.' : 'Entretien enregistré.')));
      Navigator.pop(context);
    } catch (e) {
      if (mounted) {
        toast(context, errorText(e), error: true);
        setState(() => _busy = false);
      }
    }
  }

  Widget _stars(dynamic value, ValueChanged<double>? onChanged) => Row(mainAxisSize: MainAxisSize.min, children: [
    for (var i = 1; i <= 5; i++)
      InkWell(
        onTap: onChanged == null ? null : () => onChanged(i.toDouble()),
        child: Padding(
          padding: const EdgeInsets.all(2),
          child: Icon((_num(value) ?? 0) >= i ? Icons.star_rounded : Icons.star_outline_rounded, size: 22, color: onChanged == null ? Colors.amber.withValues(alpha: .6) : Colors.amber),
        ),
      ),
  ]);

  Widget _area(String key, String label, bool enabled) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextField(controller: _c[key], enabled: enabled, minLines: 2, maxLines: 6, decoration: InputDecoration(labelText: tr(label))),
  );

  @override
  Widget build(BuildContext context) {
    if (_r.isEmpty) return EmptyState(icon: Icons.search_off, title: tr('Entretien introuvable'));
    final st = reviewStatuses[_r['status']];
    final hint = Theme.of(context).hintColor;
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          InitialsAvatar(_emp(_r['employee_id']), size: 44),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(_emp(_r['employee_id'])), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
              Text(
                tr([reviewTypes[_r['type']] ?? '', if (_r['period'] != null) '${_r['period']}', if (_r['scheduled_on'] != null) dateFr('${_r['scheduled_on']}'), 'évaluateur : ${_emp(_r['reviewer_id'])}'].join(' · ')),
                style: TextStyle(color: hint),
              ),
            ]),
          ),
          if (_num(_r['score']) != null)
            Padding(padding: const EdgeInsets.only(right: 8), child: Text('${number(_num(_r['score'])!, decimals: 1)}/5', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
          StatusChip(st?.$1 ?? '', color: st?.$2 ?? YColors.muted),
        ]),
        const SizedBox(height: 16),
        SectionCard(
          title: tr('Compétences'),
          child: Column(children: [
            Row(children: [
              const Expanded(child: SizedBox()),
              SizedBox(width: 130, child: Text(tr('Salarié'), textAlign: TextAlign.center, style: TextStyle(color: hint, fontSize: 12))),
              SizedBox(width: 130, child: Text(tr('Responsable'), textAlign: TextAlign.center, style: TextStyle(color: hint, fontSize: 12))),
            ]),
            for (final r in _ratings)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(children: [
                  Expanded(child: Text(tr('${r['criterion']}'))),
                  SizedBox(width: 130, child: Center(child: _stars(r['self'], _canSelf ? (v) => setState(() => r['self'] = v) : null))),
                  SizedBox(width: 130, child: Center(child: _stars(r['score'], _canManager ? (v) => setState(() => r['score'] = v) : null))),
                ]),
              ),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Objectifs de la période'),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (_objectives.isEmpty) Text(tr('Aucun objectif fixé.'), style: TextStyle(color: hint)),
            for (final o in _objectives)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Wrap(spacing: 10, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  SizedBox(
                    width: 300,
                    child: TextFormField(initialValue: '${o['title'] ?? ''}', enabled: _canManager, decoration: InputDecoration(labelText: tr('Objectif'), isDense: true), onChanged: (v) => o['title'] = v),
                  ),
                  SizedBox(
                    width: 90,
                    child: TextFormField(
                      initialValue: o['weight'] == null ? '' : '${o['weight']}',
                      enabled: _canManager,
                      keyboardType: TextInputType.number,
                      decoration: InputDecoration(labelText: tr('Poids'), isDense: true),
                      onChanged: (v) => o['weight'] = _num(v),
                    ),
                  ),
                  _stars(o['score'], _canManager ? (v) => setState(() => o['score'] = v) : null),
                  if (_canManager) IconButton(onPressed: () => setState(() => _objectives.remove(o)), icon: const Icon(Icons.delete_outline)),
                ]),
              ),
            if (_canManager)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(onPressed: () => setState(() => _objectives.add({'title': '', 'weight': 1})), icon: const Icon(Icons.add), label: Text(tr('Ajouter un objectif'))),
              ),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Échanges'),
          child: Column(children: [
            _area('employee_comment', 'Commentaire du salarié', _canSelf),
            if (!_isEmployee || _done) ...[
              _area('strengths', 'Points forts', _canManager),
              _area('improvements', 'Axes de progrès', _canManager),
              _area('manager_comment', 'Avis du responsable', _canManager),
            ],
            _area('training_needs', 'Besoins de formation', _canSelf || _canManager),
            if (!_isEmployee || _done)
              DropdownButtonFormField<String>(
                initialValue: _outcome,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Suite donnée')),
                items: [
                  for (final o in reviewOutcomes.entries)
                    if (_r['type'] == 'trial' ? ['confirm', 'extend', 'end'].contains(o.key) : !['confirm', 'extend', 'end'].contains(o.key))
                      DropdownMenuItem(value: o.key, child: Text(tr(o.value))),
                ],
                onChanged: _canManager ? (v) => setState(() => _outcome = v) : null,
              ),
          ]),
        ),
        if (_canManager) ...[
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Objectifs de la prochaine période'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              for (final o in _next)
                Row(children: [
                  Expanded(child: TextFormField(initialValue: '${o['title'] ?? ''}', decoration: InputDecoration(labelText: tr('Objectif'), isDense: true), onChanged: (v) => o['title'] = v)),
                  IconButton(onPressed: () => setState(() => _next.remove(o)), icon: const Icon(Icons.delete_outline)),
                ]),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(onPressed: () => setState(() => _next.add({'title': ''})), icon: const Icon(Icons.add), label: Text(tr('Ajouter'))),
              ),
            ]),
          ),
        ],
        const SizedBox(height: 16),
        if (!_done)
          Wrap(spacing: 10, runSpacing: 8, children: [
            if (_canSelf || _canManager) OutlinedButton.icon(onPressed: _busy ? null : () => _save(), icon: const Icon(Icons.save_outlined), label: Text(tr('Enregistrer'))),
            if (_isEmployee && _canSelf)
              FilledButton.icon(onPressed: _busy ? null : () => _save(status: 'manager'), icon: const Icon(Icons.send), label: Text(tr('Transmettre à mon responsable'))),
            if (_canManager && !_isEmployee)
              FilledButton.icon(onPressed: _busy ? null : () => _save(status: 'done'), icon: const Icon(Icons.task_alt), label: Text(tr('Clôturer l\'entretien'))),
            if (_hr)
              TextButton(onPressed: _busy ? null : () => _save(status: 'cancelled'), child: Text(tr('Annuler l\'entretien'))),
          ]),
      ]),
    );
  }
}

/// Carte « Mes entretiens » de l'espace salarié.
class MyReviewsCard extends StatelessWidget {
  const MyReviewsCard({super.key, required this.employeeId});

  final String employeeId;

  @override
  Widget build(BuildContext context) {
    if (!LocalDb.hasEntity('tal_reviews')) return const SizedBox.shrink();
    return ListenableBuilder(
      listenable: LocalDb.listen('tal_reviews'),
      builder: (context, _) {
        final mine = _all('tal_reviews').where((r) => r['employee_id'] == employeeId && r['status'] != 'cancelled').toList()
          ..sort((a, b) => '${b['scheduled_on'] ?? ''}'.compareTo('${a['scheduled_on'] ?? ''}'));
        if (mine.isEmpty) return const SizedBox.shrink();
        return SectionCard(
          title: tr('Mes entretiens'),
          child: Column(children: [
            for (final r in mine.take(4))
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.record_voice_over_outlined),
                title: Text(tr('${reviewTypes[r['type']] ?? 'Entretien'}${r['period'] != null ? ' ${r['period']}' : ''}')),
                subtitle: Text(tr(['planned', 'self'].contains(r['status']) ? 'À préparer : votre auto-évaluation' : (reviewStatuses[r['status']]?.$1 ?? ''))),
                trailing: StatusChip(reviewStatuses[r['status']]?.$1 ?? '', color: reviewStatuses[r['status']]?.$2 ?? YColors.muted),
                onTap: () => showReview(context, '${r['id']}'),
              ),
          ]),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Formations
// ---------------------------------------------------------------------------

class _TrainingsTab extends StatefulWidget {
  const _TrainingsTab();

  @override
  State<_TrainingsTab> createState() => _TrainingsTabState();
}

class _TrainingsTabState extends State<_TrainingsTab> {
  String _filter = 'upcoming';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final cur = s.currency;
    final hr = s.can('talents.manage');
    final me = '${myEmployee(s)?['id'] ?? ''}';
    return ListenableBuilder(
      listenable: Listenable.merge([LocalDb.listen('tal_trainings'), LocalDb.listen('rh_employees')]),
      builder: (context, _) {
        final all = _all('tal_trainings')..sort((a, b) => '${b['start_date'] ?? ''}'.compareTo('${a['start_date'] ?? ''}'));
        final year = '${DateTime.now().year}';
        final thisYear = all.where((t) => '${t['start_date'] ?? ''}'.startsWith(year) && t['status'] != 'cancelled').toList();
        double sum(Iterable<Map> l, String k) => l.fold(0.0, (a, t) => a + (_num(t[k]) ?? 0));
        final trained = <String>{for (final t in thisYear) for (final p in (t['participants'] as List? ?? const [])) '${(p as Map)['employee_id']}'};
        bool st(Map<String, dynamic> t, String k) => switch (k) {
              'upcoming' => ['planned', 'ongoing'].contains(t['status']),
              'mine' => (t['participants'] as List? ?? const []).any((p) => (p as Map)['employee_id'] == me),
              'done' => t['status'] == 'done',
              'cancelled' => t['status'] == 'cancelled',
              _ => true,
            };
        List<String> parts(Map t) => [for (final p in (t['participants'] as List? ?? const [])) '${(p as Map)['employee_id']}'];
        bool base(Map<String, dynamic> t) =>
            (_q.isEmpty || '${t['title'] ?? ''} ${t['provider'] ?? ''} ${t['location'] ?? ''} ${t['category'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
            (_f['category'] == null || '${t['category'] ?? ''}' == _f['category']) &&
            (_f['provider'] == null || '${t['provider'] ?? ''}' == _f['provider']) &&
            (_f['participant'] == null || parts(t).contains(_f['participant'])) &&
            (_f['department'] == null || parts(t).any((id) => _dept(id) == _f['department'])) &&
            (_f['funded'] == null || (_f['funded'] == 'yes') == (t['funded'] == true)) &&
            (_period == null || _period!.contains(t['start_date']));
        final shown = all.where((t) => base(t) && st(t, _filter)).toList();
        final stOpts = {'upcoming': 'À venir', if (me.isNotEmpty) 'mine': 'Mes formations', 'done': 'Réalisées', 'cancelled': 'Annulées', '': 'Toutes'};
        final participants = <String, String>{for (final t in all) for (final id in parts(t)) id: _emp(id)};
        return ListView(padding: const EdgeInsets.only(top: 16, bottom: 32), children: [
          StatStrip(tiles: [
            StatTile(label: 'Formations $year', value: '${thisYear.length}', detail: '${number(sum(thisYear, 'hours'), decimals: 0)} h prévues ou réalisées', icon: Icons.school_outlined),
            StatTile(label: 'Salariés formés', value: '${trained.length}', detail: 'sur ${rhEmployees().length} salarié(s)', icon: Icons.groups_outlined, color: YColors.success),
            if (hr)
              StatTile(
                label: 'Budget formation',
                value: money(sum(thisYear, 'cost'), cur),
                detail: 'dont ${money(sum(thisYear, 'funded_amount'), cur)} pris en charge',
                icon: Icons.account_balance_wallet_outlined,
                color: YColors.warning,
              ),
          ]),
          FilterBar(
            padding: _barPad(context),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Intitulé, organisme, lieu…',
            statuses: stOpts,
            status: _filter,
            onStatus: (v) => setState(() => _filter = v),
            statusCounts: {for (final k in stOpts.keys) k: all.where((t) => base(t) && st(t, k)).length},
            filters: [
              FilterSpec('category', 'Domaine', filterOptions(all, 'category'), icon: Icons.category_outlined),
              FilterSpec('provider', 'Organisme', filterOptions(all, 'provider'), icon: Icons.business_outlined),
              FilterSpec('participant', 'Participant', Map.fromEntries(participants.entries.toList()..sort((a, b) => a.value.compareTo(b.value))), icon: Icons.person_outline),
              FilterSpec('department', 'Service', _deptOptions(), icon: Icons.apartment_outlined),
              const FilterSpec('funded', 'Prise en charge', {'yes': 'Financée', 'no': 'Non financée'}, icon: Icons.account_balance_wallet_outlined),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: shown.length,
            countLabel: 'formation',
            onReset: () => setState(() => (_q = '', _filter = 'upcoming', _f = {}, _period = null)),
          ),
          if (shown.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 40),
              child: EmptyState(icon: Icons.school_outlined, title: tr('Aucune formation'), message: tr('Construisez le plan de formation : sessions, participants, heures, coût et prise en charge.')),
            ),
          for (final t in shown)
            Padding(
              padding: EdgeInsets.symmetric(horizontal: _pad(context), vertical: 4),
              child: Card(
                child: ListTile(
                  leading: CircleAvatar(backgroundColor: YColors.blue.withValues(alpha: .12), child: const Icon(Icons.school_outlined, color: YColors.blue)),
                  title: Text(tr('${t['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text(tr([
                    if (t['start_date'] != null) dateFr('${t['start_date']}'),
                    if (t['hours'] != null) '${number(_num(t['hours'])!, decimals: 0)} h',
                    if (t['provider'] != null) '${t['provider']}',
                    '${(t['participants'] as List? ?? const []).length} participant(s)',
                  ].join(' · '))),
                  trailing: StatusChip(trainingStatuses[t['status']]?.$1 ?? '', color: trainingStatuses[t['status']]?.$2 ?? YColors.muted),
                  onTap: hr ? () => editTraining(context, t) : null,
                ),
              ),
            ),
        ]);
      },
    );
  }
}

Future<void> editTraining(BuildContext context, [Map<String, dynamic>? t]) => showFormDialog(context, title: t == null ? 'Nouvelle formation' : 'Formation', maxWidth: 720, builder: (_) => _TrainingForm(t: t));

class _TrainingForm extends StatefulWidget {
  const _TrainingForm({this.t});

  final Map<String, dynamic>? t;

  @override
  State<_TrainingForm> createState() => _TrainingFormState();
}

class _TrainingFormState extends State<_TrainingForm> {
  late final Map<String, dynamic> _t = Map<String, dynamic>.from(widget.t ?? {'status': 'planned'});
  late final _c = {for (final k in ['title', 'category', 'provider', 'location', 'hours', 'cost', 'funded_amount', 'description']) k: TextEditingController(text: _t[k] == null ? '' : '${_t[k]}')};
  late final List<Map<String, dynamic>> _people = [for (final p in (_t['participants'] as List? ?? const [])) Map<String, dynamic>.from(p as Map)];
  String _q = '';
  bool _busy = false;

  Future<void> _date(String key) async {
    final d = await pickDate(context, firstDate: DateTime(2020), lastDate: DateTime(2100), initialDate: DateTime.tryParse('${_t[key] ?? ''}') ?? DateTime.now());
    if (d != null) setState(() => _t[key] = d.toIso8601String().substring(0, 10));
  }

  @override
  Widget build(BuildContext context) {
    final staff = rhEmployees().where((e) => _q.isEmpty || empName(e).toLowerCase().contains(_q.toLowerCase())).toList();
    final ids = _people.map((p) => p['employee_id']).toSet();
    final done = _t['status'] == 'done';
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Field(label: 'Intitulé', child: TextField(controller: _c['title'])),
        Wrap(spacing: 12, runSpacing: 4, children: [
          SizedBox(width: 200, child: Field(label: 'Domaine', child: TextField(controller: _c['category']))),
          SizedBox(width: 220, child: Field(label: 'Organisme', child: TextField(controller: _c['provider']))),
          SizedBox(width: 200, child: Field(label: 'Lieu', child: TextField(controller: _c['location']))),
        ]),
        Wrap(spacing: 12, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.end, children: [
          OutlinedButton.icon(onPressed: () => _date('start_date'), icon: const Icon(Icons.event), label: Text(_t['start_date'] == null ? tr('Début') : '${tr('Du')} ${dateFr('${_t['start_date']}')}')),
          OutlinedButton.icon(onPressed: () => _date('end_date'), icon: const Icon(Icons.event), label: Text(_t['end_date'] == null ? tr('Fin') : '${tr('Au')} ${dateFr('${_t['end_date']}')}')),
          SizedBox(width: 100, child: TextField(controller: _c['hours'], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Heures')))),
          SizedBox(width: 140, child: TextField(controller: _c['cost'], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Coût')))),
        ]),
        const SizedBox(height: 8),
        Wrap(spacing: 12, crossAxisAlignment: WrapCrossAlignment.center, children: [
          SizedBox(
            width: 290,
            child: SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: _t['funded'] == true,
              title: Text(tr('Prise en charge (FDFP, OPCO…)')),
              onChanged: (v) => setState(() => _t['funded'] = v),
            ),
          ),
          if (_t['funded'] == true)
            SizedBox(width: 160, child: TextField(controller: _c['funded_amount'], keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Montant pris en charge')))),
        ]),
        Field(
          label: 'Statut',
          child: Wrap(spacing: 8, runSpacing: 8, children: [
            for (final s in trainingStatuses.entries) ChoiceChip(label: Text(tr(s.value.$1)), selected: _t['status'] == s.key, onSelected: (_) => setState(() => _t['status'] = s.key)),
          ]),
        ),
        Field(
          label: 'Participants (${_people.length})',
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            TextField(decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: tr('Rechercher un salarié'), isDense: true), onChanged: (v) => setState(() => _q = v)),
            const SizedBox(height: 8),
            Wrap(spacing: 6, runSpacing: 6, children: [
              for (final e in staff.take(40))
                FilterChip(
                  label: Text(tr(empName(e))),
                  selected: ids.contains(e['id']),
                  onSelected: (v) => setState(() => v ? _people.add({'employee_id': e['id'], 'attended': null}) : _people.removeWhere((p) => p['employee_id'] == e['id'])),
                ),
            ]),
            if (done && _people.isNotEmpty) ...[
              const SizedBox(height: 10),
              Text(tr('Présence'), style: const TextStyle(fontWeight: FontWeight.w600)),
              for (final p in _people)
                CheckboxListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  value: p['attended'] == true,
                  title: Text(tr(_emp(p['employee_id']))),
                  onChanged: (v) => setState(() => p['attended'] = v),
                ),
            ],
          ]),
        ),
        Field(label: 'Programme / objectifs', child: TextField(controller: _c['description'], minLines: 2, maxLines: 5)),
        const SizedBox(height: 12),
        Row(children: [
          if (widget.t != null)
            TextButton.icon(
              onPressed: () async {
                if (!await confirm(context, 'Supprimer la formation', 'Cette session sera supprimée du plan de formation.', danger: true)) return;
                if (!context.mounted) return;
                await context.read<SyncEngine>().remove('tal_trainings', '${widget.t!['id']}');
                if (context.mounted) Navigator.pop(context);
              },
              icon: const Icon(Icons.delete_outline),
              label: Text(tr('Supprimer')),
            ),
          const Spacer(),
          FilledButton(
            onPressed: _busy
                ? null
                : () async {
                    if (_c['title']!.text.trim().isEmpty) return toast(context, tr('Indiquez l\'intitulé de la formation.'), error: true);
                    setState(() => _busy = true);
                    String? txt(String k) => _c[k]!.text.trim().isEmpty ? null : _c[k]!.text.trim();
                    try {
                      await context.read<SyncEngine>().save('tal_trainings', {
                        'id': _t['id'] ?? newId(),
                        'title': txt('title'),
                        'category': txt('category'),
                        'provider': txt('provider'),
                        'location': txt('location'),
                        'start_date': _t['start_date'],
                        'end_date': _t['end_date'],
                        'hours': _num(txt('hours')),
                        'cost': _num(txt('cost')),
                        'funded': _t['funded'] == true,
                        'funded_amount': _t['funded'] == true ? _num(txt('funded_amount')) : null,
                        'status': _t['status'],
                        'participants': _people,
                        'description': txt('description'),
                      });
                      if (context.mounted) Navigator.pop(context);
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                      setState(() => _busy = false);
                    }
                  },
            child: Text(tr('Enregistrer')),
          ),
        ]),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Parcours d'arrivée et de départ
// ---------------------------------------------------------------------------

int _progress(Map c) {
  final items = (c['items'] as List? ?? const []);
  if (items.isEmpty) return 0;
  return (100 * items.where((i) => (i as Map)['done'] == true).length / items.length).round();
}

class _ChecklistsTab extends StatefulWidget {
  const _ChecklistsTab();

  @override
  State<_ChecklistsTab> createState() => _ChecklistsTabState();
}

class _ChecklistsTabState extends State<_ChecklistsTab> {
  String _filter = 'open';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Listenable.merge([LocalDb.listen('tal_checklists'), LocalDb.listen('rh_employees')]),
      builder: (context, _) {
        final all = _all('tal_checklists')..sort((a, b) => '${b['start_date'] ?? ''}'.compareTo('${a['start_date'] ?? ''}'));
        bool st(Map<String, dynamic> c, String k) => switch (k) {
              'open' => c['status'] != 'done',
              'onboarding' || 'offboarding' => c['kind'] == k,
              'done' => c['status'] == 'done',
              _ => true,
            };
        bool base(Map<String, dynamic> c) =>
            (_q.isEmpty || '${c['title'] ?? ''} ${_emp(c['employee_id'])}'.toLowerCase().contains(_q.toLowerCase())) &&
            (_f['employee'] == null || '${c['employee_id']}' == _f['employee']) &&
            (_f['department'] == null || _dept(c['employee_id']) == _f['department']) &&
            (_f['state'] == null || (_f['state'] == 'done') == (c['status'] == 'done')) &&
            (_period == null || _period!.contains(c['start_date']));
        final shown = all.where((c) => base(c) && st(c, _filter)).toList();
        const stKeys = ['open', 'onboarding', 'offboarding', 'done', ''];
        return ListView(padding: const EdgeInsets.only(top: 16, bottom: 32), children: [
          StatStrip(tiles: [
            StatTile(label: 'Arrivées en cours', value: '${all.where((c) => c['kind'] == 'onboarding' && c['status'] != 'done').length}', icon: Icons.login, color: YColors.success),
            StatTile(label: 'Départs en cours', value: '${all.where((c) => c['kind'] == 'offboarding' && c['status'] != 'done').length}', icon: Icons.logout, color: YColors.warning),
            StatTile(label: 'Parcours terminés', value: '${all.where((c) => c['status'] == 'done').length}', icon: Icons.task_alt),
          ]),
          FilterBar(
            padding: _barPad(context),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Parcours, salarié…',
            statuses: const {'open': 'En cours', 'onboarding': 'Arrivées', 'offboarding': 'Départs', 'done': 'Terminés', '': 'Tous'},
            status: _filter,
            onStatus: (v) => setState(() => _filter = v),
            statusCounts: {for (final k in stKeys) k: all.where((c) => base(c) && st(c, k)).length},
            filters: [
              FilterSpec('employee', 'Salarié', filterOptions(all, 'employee_id', label: (c) => _emp(c['employee_id'])), icon: Icons.person_outline),
              FilterSpec('department', 'Service', _deptOptions(), icon: Icons.apartment_outlined),
              const FilterSpec('state', 'Avancement', {'open': 'En cours', 'done': 'Terminé'}, icon: Icons.checklist_rtl),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            showPeriod: true,
            period: _period,
            onPeriod: (p) => setState(() => _period = p),
            count: shown.length,
            countLabel: 'résultat',
            onReset: () => setState(() => (_q = '', _filter = 'open', _f = {}, _period = null)),
          ),
          if (shown.isEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 40),
              child: EmptyState(icon: Icons.checklist_rtl, title: tr('Aucun parcours'), message: tr('Préparez l\'arrivée d\'un nouveau salarié (contrat, matériel, accès, présentation) ou organisez un départ (restitution, solde de tout compte, certificat).')),
            ),
          for (final c in shown)
            Padding(
              padding: EdgeInsets.symmetric(horizontal: _pad(context), vertical: 4),
              child: Card(
                child: ListTile(
                  leading: InitialsAvatar(_emp(c['employee_id'])),
                  title: Text(tr('${c['title'] ?? _emp(c['employee_id'])}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Row(children: [
                      Expanded(child: ClipRRect(borderRadius: BorderRadius.circular(4), child: LinearProgressIndicator(value: _progress(c) / 100, minHeight: 6))),
                      const SizedBox(width: 10),
                      Text('${_progress(c)} %'),
                    ]),
                  ),
                  trailing: StatusChip(checklistKinds[c['kind']] ?? '', color: c['kind'] == 'offboarding' ? YColors.warning : YColors.success),
                  onTap: () => showChecklist(context, '${c['id']}'),
                ),
              ),
            ),
        ]);
      },
    );
  }
}

Future<void> newChecklist(BuildContext context, {String? employeeId, String kind = 'onboarding'}) => showFormDialog(
  context,
  title: 'Nouveau parcours',
  builder: (c) => _NewChecklistForm(employeeId: employeeId, kind: kind),
);

class _NewChecklistForm extends StatefulWidget {
  const _NewChecklistForm({this.employeeId, required this.kind});

  final String? employeeId;
  final String kind;

  @override
  State<_NewChecklistForm> createState() => _NewChecklistFormState();
}

class _NewChecklistFormState extends State<_NewChecklistForm> {
  late String? _employee = widget.employeeId;
  late String _kind = widget.kind;
  DateTime? _due;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Field(
          label: 'Type',
          child: SegmentedButton<String>(
            segments: [for (final k in checklistKinds.entries) ButtonSegment(value: k.key, label: Text(tr(k.value)))],
            selected: {_kind},
            onSelectionChanged: (v) => setState(() => _kind = v.first),
          ),
        ),
        Field(
          label: 'Salarié',
          child: DropdownButtonFormField<String>(
            initialValue: _employee,
            isExpanded: true,
            items: [for (final e in rhEmployees()) DropdownMenuItem(value: '${e['id']}', child: Text(tr(empName(e)), overflow: TextOverflow.ellipsis))],
            onChanged: (v) => setState(() => _employee = v),
          ),
        ),
        Field(
          label: 'À terminer pour le',
          child: OutlinedButton.icon(
            onPressed: () async {
              final d = await pickDate(context, firstDate: DateTime(2020), lastDate: DateTime(2100), initialDate: DateTime.now().add(const Duration(days: 14)));
              if (d != null) setState(() => _due = d);
            },
            icon: const Icon(Icons.event),
            label: Text(_due == null ? tr('Choisir une date') : dateFr(_due!.toIso8601String())),
          ),
        ),
        Text(tr('La liste type est créée (contrat, matériel, accès, présentation… ou restitution, solde de tout compte, certificat) ; vous pourrez l\'adapter.'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _employee == null
              ? null
              : () async {
                  try {
                    final id = newId();
                    await context.read<SyncEngine>().save('tal_checklists', {'id': id, 'employee_id': _employee, 'kind': _kind, 'due_date': _due?.toIso8601String().substring(0, 10)});
                    if (context.mounted) Navigator.pop(context);
                  } catch (e) {
                    if (context.mounted) toast(context, errorText(e), error: true);
                  }
                },
          child: Text(tr('Créer le parcours')),
        ),
      ]),
    );
  }
}

Future<void> showChecklist(BuildContext context, String id) => showFormDialog(context, title: 'Parcours', maxWidth: 680, builder: (_) => _ChecklistView(id: id));

class _ChecklistView extends StatefulWidget {
  const _ChecklistView({required this.id});

  final String id;

  @override
  State<_ChecklistView> createState() => _ChecklistViewState();
}

class _ChecklistViewState extends State<_ChecklistView> {
  late final Session _s = context.read<Session>();
  final _newItem = TextEditingController();
  String _newOwner = 'rh';

  Future<void> _save(Map<String, dynamic> c, List<Map<String, dynamic>> items) async {
    try {
      await context.read<SyncEngine>().save('tal_checklists', {'id': c['id'], 'items': items});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: LocalDb.listen('tal_checklists'),
      builder: (context, _) {
        final c = LocalDb.get('tal_checklists', widget.id);
        if (c == null) return EmptyState(icon: Icons.search_off, title: tr('Parcours introuvable'));
        final items = [for (final i in (c['items'] as List? ?? const [])) Map<String, dynamic>.from(i as Map)];
        final hr = _s.can('talents.manage');
        final manager = myTeamIds(_s).contains(c['employee_id']);
        final mine = myEmployee(_s)?['id'] == c['employee_id'];
        bool canTick(Map i) => hr || (manager && ['manager', 'employee'].contains(i['owner'])) || (mine && i['owner'] == 'employee');
        return SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              InitialsAvatar(_emp(c['employee_id']), size: 40),
              const SizedBox(width: 12),
              Expanded(child: Text(tr('${c['title'] ?? ''}'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700))),
              Text('${_progress(c)} %', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
            ]),
            const SizedBox(height: 10),
            ClipRRect(borderRadius: BorderRadius.circular(4), child: LinearProgressIndicator(value: _progress(c) / 100, minHeight: 8)),
            const SizedBox(height: 12),
            for (final i in items)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                value: i['done'] == true,
                onChanged: canTick(i)
                    ? (v) {
                        i['done'] = v == true;
                        _save(c, items);
                      }
                    : null,
                title: Text(tr('${i['label']}'), style: TextStyle(decoration: i['done'] == true ? TextDecoration.lineThrough : null)),
                subtitle: Text(tr([checklistOwners[i['owner']] ?? 'RH', if (i['done_by'] != null) 'fait par ${i['done_by']}', if (i['done_at'] != null) dateFr('${i['done_at']}')].join(' · '))),
                secondary: hr
                    ? IconButton(
                        icon: const Icon(Icons.close, size: 18),
                        onPressed: () => _save(c, items..remove(i)),
                      )
                    : null,
              ),
            if (hr) ...[
              const Divider(),
              Row(children: [
                Expanded(child: TextField(controller: _newItem, decoration: InputDecoration(hintText: tr('Ajouter une tâche'), isDense: true))),
                const SizedBox(width: 8),
                DropdownButton<String>(
                  value: _newOwner,
                  items: [for (final o in checklistOwners.entries) DropdownMenuItem(value: o.key, child: Text(tr(o.value)))],
                  onChanged: (v) => setState(() => _newOwner = v ?? 'rh'),
                ),
                IconButton(
                  icon: const Icon(Icons.add_circle_outline),
                  onPressed: () {
                    if (_newItem.text.trim().isEmpty) return;
                    _save(c, items..add({'label': _newItem.text.trim(), 'owner': _newOwner, 'done': false}));
                    _newItem.clear();
                  },
                ),
              ]),
            ],
          ]),
        );
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Échéances RH (tableau de bord RH, en ligne)
// ---------------------------------------------------------------------------

const _deadlineIcons = {
  'contract_end': Icons.assignment_late_outlined,
  'trial_end': Icons.hourglass_bottom,
  'work_anniversary': Icons.workspace_premium_outlined,
  'birthday': Icons.cake_outlined,
  'review': Icons.record_voice_over_outlined,
  'checklist': Icons.checklist_rtl,
};

class RhDeadlinesCard extends StatefulWidget {
  const RhDeadlinesCard({super.key});

  @override
  State<RhDeadlinesCard> createState() => _RhDeadlinesCardState();
}

class _RhDeadlinesCardState extends State<RhDeadlinesCard> {
  late final Future<Map<String, dynamic>> _f = _load();

  Future<Map<String, dynamic>> _load() async => asMap(await context.read<Session>().api.get('rh/deadlines'));
  bool _all = false;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _f,
      builder: (context, snap) {
        final items = (snap.data?['items'] as List? ?? const []).map((e) => asMap(e)).toList();
        if (snap.connectionState != ConnectionState.done || items.isEmpty) return const SizedBox.shrink();
        final shown = _all ? items : items.take(6).toList();
        return Padding(
          padding: const EdgeInsets.only(top: 16),
          child: SectionCard(
            title: tr('Échéances à surveiller'),
            trailing: items.length > 6 ? TextButton(onPressed: () => setState(() => _all = !_all), child: Text(tr(_all ? 'Réduire' : 'Tout voir (${items.length})'))) : null,
            child: Column(children: [
              for (final d in shown)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  leading: Icon(
                    _deadlineIcons[d['kind']] ?? Icons.event,
                    color: switch (d['level']) {
                      'danger' => YColors.danger,
                      'warning' => YColors.warning,
                      _ => YColors.blue,
                    },
                  ),
                  title: Text(tr('${d['employee'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text(tr('${d['label']}${d['department'] != null ? ' · ${d['department']}' : ''}')),
                  trailing: d['days'] == null
                      ? null
                      : Text(
                          tr((d['days'] as num) == 0 ? 'aujourd\'hui' : ((d['days'] as num) < 0 ? 'il y a ${-(d['days'] as num)} j' : 'dans ${d['days']} j')),
                          style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12),
                        ),
                ),
            ]),
          ),
        );
      },
    );
  }
}
