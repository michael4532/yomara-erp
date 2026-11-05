import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../widgets/custom_fields_editor.dart';
import 'rh_documents_screen.dart';
import 'rh_positions_screen.dart';
import 'rh_requests_screen.dart';
import 'team_screen.dart' show showTeamMember;
import 'settlement_screen.dart' show showSettlement;
import 'ged_screen.dart' show LinkedDocuments;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

const contractTypes = {'CDI': 'CDI', 'CDD': 'CDD', 'stage': 'Stage', 'journalier': 'Journalier', 'consultant': 'Consultant'};
const maritalStatuses = {'single': 'Célibataire', 'married': 'Marié(e)', 'divorced': 'Divorcé(e)', 'widowed': 'Veuf / veuve'};
const salaryPaymentMethods = {'bank_transfer': 'Virement bancaire', 'mobile_money': 'Mobile Money', 'cash': 'Espèces', 'cheque': 'Chèque'};
const leaveTypes = {'annual': 'Congé annuel', 'sick': 'Maladie', 'maternity': 'Maternité', 'unpaid': 'Absence non payée', 'exceptional': 'Permission exceptionnelle', 'other': 'Autre'};
const staffTypes = {'employee': 'Salarié', 'intern': 'Stagiaire', 'apprentice': 'Apprenti', 'contractor': 'Prestataire', 'daily': 'Journalier'};
const rhContractTypes = {'CDI': 'CDI', 'CDD': 'CDD', 'stage': 'Stage', 'apprentissage': 'Apprentissage', 'prestation': 'Prestation / consultant', 'journalier': 'Journalier'};
const leaveStatuses = {'requested': 'Demandé', 'approved': 'Approuvé', 'rejected': 'Refusé', 'cancelled': 'Annulé'};

String empName(Map e) => '${e['last_name'] ?? ''} ${e['first_name'] ?? ''}'.trim();

double empParts(Map e) {
  final o = toDouble(e['parts_override']);
  if (o > 0) return o;
  final p = (e['marital_status'] == 'married' ? 2.0 : 1.0) + 0.5 * toDouble(e['children_count']);
  return p > 5 ? 5 : p;
}

List<Map<String, dynamic>> rhEmployees({bool all = false}) =>
    LocalDb.all('rh_employees').where((e) => all || e['active'] != false).toList()..sort((a, b) => empName(a).toLowerCase().compareTo(empName(b).toLowerCase()));

/// Fiche du salarié connecté (reçue par la synchronisation, disponible hors ligne).
Map<String, dynamic>? myEmployee(Session s) => LocalDb.hasEntity('rh_employees') ? LocalDb.all('rh_employees').where((e) => e['user_id'] != null && e['user_id'] == s.user['id']).firstOrNull : null;

/// Solde de congés payés (même calcul que le serveur) : report + mois complets × jours/mois − congés annuels approuvés de l'année.
double leaveBalance(Map e, {DateTime? at}) {
  final now = at ?? DateTime.now();
  final yearStart = DateTime(now.year, 1, 1);
  final hire = DateTime.tryParse('${e['hire_date'] ?? ''}');
  final from = hire != null && hire.isAfter(yearStart) ? hire : yearStart;
  var months = (now.year - from.year) * 12 + now.month - from.month - (now.day < from.day ? 1 : 0);
  if (months < 0) months = 0;
  final taken = LocalDb.all('rh_leaves')
      .where((l) => l['employee_id'] == e['id'] && l['status'] == 'approved' && l['type'] == 'annual' && '${l['start_date']}'.compareTo(isoDate(yearStart)) >= 0)
      .fold<double>(0, (t, l) => t + toDouble(l['days']));
  return ((toDouble(e['leave_opening_balance']) + months * toDouble(e['leave_days_per_month']) - taken) * 10).round() / 10;
}

double workingDaysBetween(DateTime a, DateTime b) {
  var n = 0;
  for (var d = DateTime(a.year, a.month, a.day); !d.isAfter(b); d = d.add(const Duration(days: 1))) {
    if (d.weekday != DateTime.sunday) n++;
  }
  return n.toDouble();
}

Color leaveStatusColor(String? s) => switch (s) {
  'approved' => YColors.success,
  'requested' => YColors.warning,
  'rejected' => YColors.danger,
  _ => YColors.muted,
};

/// Ressources humaines : salariés et congés (fonctionne hors ligne).
class RhScreen extends StatefulWidget {
  const RhScreen({super.key});

  @override
  State<RhScreen> createState() => _RhScreenState();
}

class _RhScreenState extends State<RhScreen> with SingleTickerProviderStateMixin {
  late final Session _s = context.read<Session>();
  late final bool _manage = _s.can('rh.manage');
  late final bool _contracts = _s.can('rh.contracts');
  late final TabController _tabs = TabController(length: _contracts ? 7 : 6, vsync: this);
  final _employeesKey = GlobalKey<_EmployeesTabState>();
  final _leavesKey = GlobalKey<_LeavesTabState>();

  Widget? _action(int i) {
    if (!_manage) return null;
    return switch (i) {
      0 => FilledButton.icon(onPressed: () => editEmployee(context), icon: const Icon(Icons.person_add_alt), label: Text(tr('Nouveau salarié'))),
      2 => FilledButton.icon(onPressed: () => editPosition(context), icon: const Icon(Icons.work_outline), label: Text(tr('Nouvelle fiche de poste'))),
      4 => FilledButton.icon(onPressed: () => editLeave(context), icon: const Icon(Icons.event_available), label: Text(tr('Nouveau congé'))),
      5 => FilledButton.icon(onPressed: () => editRhDocument(context), icon: const Icon(Icons.note_add_outlined), label: Text(tr('Nouveau document'))),
      _ => null,
    };
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        PageHeader(
          title: tr('Ressources humaines'),
          subtitle: tr('Salariés, effectifs par service, fiches de poste, demandes, congés et documents'),
          actions: [
            AnimatedBuilder(animation: _tabs, builder: (_, _) => _action(_tabs.index) ?? const SizedBox.shrink()),
          ],
        ),
        TabBar(
          controller: _tabs,
          isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8),
          tabAlignment: TabAlignment.start,
          tabs: [
            Tab(text: tr('Salariés')),
            Tab(text: tr('Effectifs')),
            Tab(text: tr('Postes & catégories')),
            Tab(text: tr('Demandes')),
            Tab(text: tr('Congés & absences')),
            Tab(text: tr('Documents')),
            if (_contracts) Tab(text: tr('Contrats')),
          ],
        ),
        Expanded(
          child: TabBarView(
            controller: _tabs,
            children: [
              _EmployeesTab(key: _employeesKey, manage: _manage),
              const RhEffectifsTab(),
              RhPositionsTab(manage: _manage),
              RhRequestsTab(manage: _manage),
              _LeavesTab(key: _leavesKey, manage: _manage),
              RhDocumentsTab(manage: _manage),
              if (_contracts) const RhContractsTab(),
            ],
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Salariés
// ---------------------------------------------------------------------------

class _EmployeesTab extends StatefulWidget {
  const _EmployeesTab({super.key, required this.manage});

  final bool manage;

  @override
  State<_EmployeesTab> createState() => _EmployeesTabState();
}

class _EmployeesTabState extends State<_EmployeesTab> {
  String _q = '';
  String _status = 'active';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  void load() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_employees'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('rh_leaves'),
        builder: (context, _, _) {
          final q = _q.toLowerCase();
          final all = rhEmployees(all: true);
          bool base(Map<String, dynamic> e) =>
              (q.isEmpty || '${empName(e)} ${e['matricule'] ?? ''} ${e['job_title'] ?? ''} ${e['department'] ?? ''}'.toLowerCase().contains(q)) &&
              (_f['department'] == null || '${e['department'] ?? ''}' == _f['department']) &&
              (_f['job_title'] == null || '${e['job_title'] ?? ''}' == _f['job_title']) &&
              (_f['contract_type'] == null || '${e['contract_type'] ?? ''}' == _f['contract_type']) &&
              (_f['staff_type'] == null || '${e['staff_type'] ?? 'employee'}' == _f['staff_type']) &&
              (_f['category'] == null || '${e['category'] ?? ''}' == _f['category']) &&
              (_f['cnps'] == null || (_f['cnps'] == 'yes') == (e['cnps_declared'] != false)) &&
              (_f['space'] == null || (_f['space'] == 'yes') == (e['user_id'] != null)) &&
              (_period == null || _period!.contains(e['hire_date']));
          bool st(Map<String, dynamic> e, String k) => k.isEmpty || (k == 'active') == (e['active'] != false);
          final rows = all.where((e) => base(e) && st(e, _status)).toList();
          final mass = rows.where((e) => e['active'] != false).fold<double>(0, (s, e) => s + toDouble(e['base_salary']));
          return Column(
            children: [
              const SizedBox(height: 16),
              FilterBar(
                onSearch: (v) => setState(() => _q = v),
                search: _q,
                searchHint: 'Nom, matricule, fonction…',
                statuses: const {'active': 'Actifs', 'former': 'Anciens salariés', '': 'Tous'},
                status: _status,
                onStatus: (v) => setState(() => _status = v),
                statusCounts: {for (final k in ['active', 'former', '']) k: all.where((e) => base(e) && st(e, k)).length},
                filters: [
                  FilterSpec('department', 'Service', filterOptions(all, 'department'), icon: Icons.apartment_outlined),
                  FilterSpec('job_title', 'Fonction', filterOptions(all, 'job_title'), icon: Icons.work_outline),
                  FilterSpec('contract_type', 'Contrat', {...contractTypes, ...filterOptions(all, 'contract_type', label: (e) => contractTypes[e['contract_type']] ?? '${e['contract_type']}')}, icon: Icons.description_outlined),
                  FilterSpec('staff_type', 'Statut', staffTypes, icon: Icons.badge_outlined),
                  FilterSpec('category', 'Catégorie', filterOptions(all, 'category'), icon: Icons.layers_outlined),
                  const FilterSpec('cnps', 'CNPS', {'yes': 'Déclaré', 'no': 'Non déclaré'}, icon: Icons.verified_outlined),
                  const FilterSpec('space', 'Espace salarié', {'yes': 'Avec accès', 'no': 'Sans accès'}, icon: Icons.phone_iphone),
                ],
                values: _f,
                onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
                showPeriod: true,
                period: _period,
                onPeriod: (p) => setState(() => _period = p),
                count: rows.length,
                countLabel: 'salarié',
                onReset: () => setState(() => (_q = '', _status = 'active', _f = {}, _period = null)),
                trailing: [Text(tr('Salaires de base ${money(mass, cur)}'), style: TextStyle(color: Theme.of(context).hintColor, fontWeight: FontWeight.w600))],
              ),
              Expanded(
                child: rows.isEmpty
                    ? EmptyState(icon: Icons.badge_outlined, title: tr('Aucun salarié'), message: tr('Créez les fiches de vos salariés pour gérer leurs congés et leur paie.'))
                    : ResponsiveList<Map<String, dynamic>>(
                        items: rows,
                        columns: const ['Matricule', 'Nom', 'Fonction', 'Contrat', 'Salaire de base', 'Parts', 'Solde congés', ''],
                        cells: (e) => [
                          Text(tr('${e['matricule'] ?? '—'}')),
                          Row(children: [
                            InitialsAvatar(empName(e)),
                            const SizedBox(width: 10),
                            Flexible(child: Text(tr(empName(e)), style: const TextStyle(fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis)),
                            if (e['user_id'] != null) Padding(padding: EdgeInsets.only(left: 6), child: Tooltip(message: tr('A accès à son espace salarié'), child: Icon(Icons.phone_iphone, size: 15, color: YColors.blue))),
                          ]),
                          Text(tr([e['job_title'], e['department'], if ('${e['category'] ?? ''}'.isNotEmpty) 'cat. ${e['category']}'].where((x) => x != null && '$x'.isNotEmpty).join(' · '))),
                          Wrap(spacing: 4, runSpacing: 4, children: [
                            StatusChip(contractTypes[e['contract_type']] ?? '${e['contract_type'] ?? ''}', color: YColors.blue),
                            if (e['staff_type'] != null && e['staff_type'] != 'employee') StatusChip(staffTypes['${e['staff_type']}'] ?? '', color: const Color(0xFF7C3AED)),
                            if (e['cnps_declared'] == false) const StatusChip('Non déclaré', color: YColors.warning),
                          ]),
                          Text(tr(money(e['base_salary'], cur))),
                          Text(tr(number(empParts(e), decimals: 1))),
                          Text(tr('${number(leaveBalance(e), decimals: 1)} j')),
                          Row(mainAxisSize: MainAxisSize.min, children: [
                            if (e['active'] == false) StatusChip(tr('Sorti')),
                            if (e['settlement'] != null) Tooltip(message: tr('Solde de tout compte validé'), child: const Icon(Icons.logout, size: 16, color: YColors.warning)),
                            IconButton(tooltip: tr('Dossier complet'), icon: const Icon(Icons.folder_shared_outlined, size: 20), onPressed: () => showTeamMember(context, '${e['id']}', empName(e))),
                            if (widget.manage)
                              IconButton(tooltip: tr('Solde de tout compte'), icon: const Icon(Icons.exit_to_app, size: 20), onPressed: () => showSettlement(context, e)),
                          ]),
                        ],
                        mobileTile: (e) => Row(
                          children: [
                            InitialsAvatar(empName(e), size: 40),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(tr(empName(e)), style: const TextStyle(fontWeight: FontWeight.w600)),
                                  Text(tr('${e['matricule'] ?? ''} · ${e['job_title'] ?? ''} · ${number(leaveBalance(e), decimals: 1)} j de congés'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                                ],
                              ),
                            ),
                            Text(tr(money(e['base_salary'], cur))),
                            IconButton(tooltip: tr('Dossier complet'), icon: const Icon(Icons.folder_shared_outlined, size: 20), onPressed: () => showTeamMember(context, '${e['id']}', empName(e))),
                          ],
                        ),
                        onTap: widget.manage ? (e) => editEmployee(context, e) : null,
                      ),
              ),
            ],
          );
        },
      ),
    );
  }
}

Future<Map<String, dynamic>?> editEmployee(BuildContext context, [Map<String, dynamic>? employee]) {
  return showFormDialog<Map<String, dynamic>>(
    context,
    title: employee == null ? 'Nouveau salarié' : empName(employee),
    builder: (c) => _EmployeeForm(employee: employee),
  );
}

class _EmployeeForm extends StatefulWidget {
  const _EmployeeForm({this.employee});

  final Map<String, dynamic>? employee;

  @override
  State<_EmployeeForm> createState() => _EmployeeFormState();
}

class _EmployeeFormState extends State<_EmployeeForm> {
  final _key = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _c;
  late String _contract, _marital, _payment;
  String? _gender;
  late bool _spouse, _expat, _active;
  late List<Map<String, dynamic>> _fixed;
  bool _busy = false;
  int? _userId;
  String? _position;
  String? _manager;
  String? _deptId;
  late String _staffType;
  late bool _declared, _cmu, _insurance;
  late Map<String, Map<String, dynamic>> _schedule;
  List<Map<String, dynamic>>? _users;

  static const _text = [
    'matricule',
    'first_name',
    'last_name',
    'phone',
    'email',
    'address',
    'emergency_contact',
    'id_number',
    'job_title',
    'department',
    'category',
    'hire_date',
    'end_date',
    'birth_date',
    'base_salary',
    'transport_allowance',
    'children_count',
    'parts_override',
    'cnps_number',
    'payment_account',
    'leave_days_per_month',
    'leave_opening_balance',
    'notes',
    'insurance_name',
    'insurance_employee',
    'insurance_employer',
    'weekly_hours',
  ];

  late final Map<String, dynamic> _custom = asMap(widget.employee?['custom']);

  bool get _champs {
    try {
      return context.read<Session>().hasModule('champs');
    } catch (_) {
      return false; // hors de l'application (tests)
    }
  }

  @override
  void initState() {
    super.initState();
    final e = widget.employee ?? {};
    String v(String f) {
      final x = e[f];
      if (x == null) return '';
      if (x is num) return x == x.roundToDouble() ? x.toInt().toString() : x.toString();
      return '$x';
    }

    _c = {for (final f in _text) f: TextEditingController(text: v(f))};
    if (widget.employee == null) {
      _c['leave_days_per_month']!.text = '2.2';
      _c['children_count']!.text = '0';
      _c['hire_date']!.text = isoDate(DateTime.now());
    }
    _contract = (e['contract_type'] as String?) ?? 'CDI';
    _marital = (e['marital_status'] as String?) ?? 'single';
    _payment = (e['payment_method'] as String?) ?? 'bank_transfer';
    _gender = e['gender'] as String?;
    _spouse = e['spouse_dependent'] == true;
    _expat = e['is_expatriate'] == true;
    _active = e['active'] != false;
    _fixed = [for (final i in (e['fixed_items'] as List? ?? const [])) Map<String, dynamic>.from(i)];
    _userId = (e['user_id'] as num?)?.toInt();
    _position = e['position_id'] as String?;
    _manager = e['manager_id'] as String?;
    _deptId = e['department_id'] as String?;
    _staffType = '${e['staff_type'] ?? 'employee'}';
    _declared = e['cnps_declared'] != false;
    _cmu = e['cmu_enrolled'] != false;
    _insurance = e['has_insurance'] == true;
    _schedule = {for (final en in asMap(e['work_schedule']).entries) en.key: asMap(en.value)};
    if (widget.employee == null) {
      _c['weekly_hours']!.text = '40';
      _schedule = {for (var d = 1; d <= 5; d++) '$d': {'start': '08:00', 'end': '17:00', 'break': 60}};
    }
    try {
      context.read<Session>().api.get('rh/users').then((r) {
        if (mounted) setState(() => _users = [for (final u in (r['data'] as List)) asMap(u)]);
      }).catchError((_) {});
    } catch (_) {
      // Hors de l'application (tests) : pas de liste de comptes
    }
  }

  double get _parts {
    final o = double.tryParse(_c['parts_override']!.text.replaceAll(',', '.'));
    if (o != null) return o;
    final p = (_marital == 'married' ? 2.0 : 1.0) + 0.5 * (int.tryParse(_c['children_count']!.text) ?? 0);
    return p > 5 ? 5 : p;
  }

  Future<void> _save() async {
    if (!_key.currentState!.validate()) return;
    final err = _champs ? customRequiredError('rh_employees', _custom) : null;
    if (err != null) return toast(context, err, error: true);
    setState(() => _busy = true);
    final sync = context.read<SyncEngine>();
    num? n(String f) => num.tryParse(_c[f]!.text.replaceAll(' ', '').replaceAll(',', '.'));
    String? t(String f) => _c[f]!.text.trim().isEmpty ? null : _c[f]!.text.trim();
    final data = <String, dynamic>{
      for (final f in [
        'matricule',
        'first_name',
        'last_name',
        'phone',
        'email',
        'address',
        'emergency_contact',
        'id_number',
        'job_title',
        'department',
        'category',
        'hire_date',
        'end_date',
        'birth_date',
        'cnps_number',
        'payment_account',
        'notes',
      ])
        f: t(f),
      'base_salary': n('base_salary') ?? 0,
      'transport_allowance': n('transport_allowance') ?? 0,
      'children_count': n('children_count') ?? 0,
      'parts_override': n('parts_override'),
      'leave_days_per_month': n('leave_days_per_month') ?? 2.2,
      'leave_opening_balance': n('leave_opening_balance') ?? 0,
      'contract_type': _contract,
      'marital_status': _marital,
      'payment_method': _payment,
      'gender': _gender,
      'spouse_dependent': _spouse,
      'is_expatriate': _expat,
      'active': _active,
      'fixed_items': _fixed.where((i) => '${i['label'] ?? ''}'.trim().isNotEmpty).toList(),
      'user_id': _userId,
      if (LocalDb.hasEntity('rh_positions')) 'position_id': _position,
      if (LocalDb.hasEntity('rh_requests')) 'manager_id': _manager,
      if (LocalDb.hasEntity('rh_departments')) ...{
        'department_id': _deptId,
        'staff_type': _staffType,
        'cnps_declared': _declared,
        'cmu_enrolled': _cmu,
        'has_insurance': _insurance,
        'insurance_name': t('insurance_name'),
        'insurance_employee': n('insurance_employee') ?? 0,
        'insurance_employer': n('insurance_employer') ?? 0,
        'weekly_hours': n('weekly_hours') ?? 40,
        'work_schedule': _schedule,
      },
      if (_champs) 'custom': _custom,
    };
    try {
      final id = widget.employee?['id'] ?? newId();
      await sync.save('rh_employees', {...data, 'id': id});
      if (mounted) Navigator.pop(context, {...data, 'id': id});
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  Widget _row(List<Widget> children) => Row(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      for (var i = 0; i < children.length; i++) ...[if (i > 0) const SizedBox(width: 12), Expanded(child: children[i])],
    ],
  );

  Widget _tf(String f, String label, {bool required = false, bool numeric = false, String? hint}) => Field(
    label: label,
    child: TextFormField(
      controller: _c[f],
      keyboardType: numeric ? const TextInputType.numberWithOptions(decimal: true) : null,
      inputFormatters: numeric ? [FilteringTextInputFormatter.allow(RegExp(r'[0-9.,\s]'))] : null,
      decoration: InputDecoration(hintText: trn(hint)),
      onChanged: (_) => setState(() {}),
      validator: required ? (v) => (v ?? '').trim().isEmpty ? 'Obligatoire' : null : null,
    ),
  );

  Widget _date(String f, String label) => Field(
    label: label,
    child: InkWell(
      borderRadius: BorderRadius.circular(10),
      onTap: () async {
        final d = await showDatePicker(context: context, firstDate: DateTime(1940), lastDate: DateTime(2100), initialDate: DateTime.tryParse(_c[f]!.text) ?? DateTime.now());
        if (d != null) setState(() => _c[f]!.text = isoDate(d));
      },
      child: InputDecorator(
        decoration: InputDecoration(
          suffixIcon: _c[f]!.text.isEmpty ? const Icon(Icons.event, size: 18) : IconButton(icon: const Icon(Icons.clear, size: 18), onPressed: () => setState(() => _c[f]!.clear())),
        ),
        child: Text(tr(_c[f]!.text.isEmpty ? 'jj/mm/aaaa' : dateFr(_c[f]!.text)), style: TextStyle(color: _c[f]!.text.isEmpty ? Theme.of(context).hintColor : null)),
      ),
    ),
  );

  Widget _section(String t) => Padding(
    padding: const EdgeInsets.only(top: 8, bottom: 10),
    child: Text(
      tr(t),
      style: TextStyle(fontWeight: FontWeight.w700, color: Theme.of(context).colorScheme.primary),
    ),
  );

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _key,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
        shrinkWrap: true,
        children: [
          _section('Identité'),
          _row([_tf('last_name', 'Nom', required: true), _tf('first_name', 'Prénoms', required: true)]),
          _row([
            _tf('matricule', 'Matricule', hint: tr('Automatique')),
            Field(
              label: tr('Sexe'),
              child: DropdownButtonFormField<String?>(
                isExpanded: true,
                initialValue: _gender,
                items: [
                  DropdownMenuItem(value: null, child: Text('—')),
                  DropdownMenuItem(value: 'F', child: Text(tr('Femme'))),
                  DropdownMenuItem(value: 'M', child: Text(tr('Homme'))),
                ],
                onChanged: (v) => setState(() => _gender = v),
              ),
            ),
            _date('birth_date', 'Naissance'),
          ]),
          _row([_tf('phone', 'Téléphone'), _tf('email', 'E-mail'), _tf('id_number', 'N° CNI / passeport')]),
          _row([_tf('address', 'Adresse'), _tf('emergency_contact', 'Personne à prévenir (urgence)')]),
          _section('Contrat'),
          if (rhPositions().isNotEmpty)
            Field(
              label: tr('Poste (fiche de poste)'),
              child: DropdownButtonFormField<String?>(
                isExpanded: true,
                initialValue: rhPositions(all: true).any((p) => p['id'] == _position) ? _position : null,
                items: [
                  DropdownMenuItem<String?>(value: null, child: Text(tr('— Aucune fiche de poste'))),
                  for (final p in rhPositions(all: _position != null))
                    DropdownMenuItem<String?>(value: '${p['id']}', child: Text(tr([p['title'], p['department']].where((x) => x != null && '$x'.isNotEmpty).join(' · ')), overflow: TextOverflow.ellipsis)),
                ],
                onChanged: (v) => setState(() {
                  _position = v;
                  final p = v == null ? null : LocalDb.get('rh_positions', v);
                  if (p == null) return;
                  // Fonction, service et catégorie repris de la fiche de poste
                  _c['job_title']!.text = '${p['title'] ?? ''}';
                  if ('${p['department'] ?? ''}'.isNotEmpty) _c['department']!.text = '${p['department']}';
                  final cat = p['category_id'] == null ? null : LocalDb.get('rh_categories', '${p['category_id']}');
                  if (cat != null) _c['category']!.text = '${cat['code']}';
                  if (p['contract_type'] != null && widget.employee == null) _contract = '${p['contract_type']}';
                }),
              ),
            ),
          _row([
            _tf('job_title', 'Fonction'),
            rhDepartments().isEmpty
                ? _tf('department', 'Service')
                : Field(
                    label: tr('Département'),
                    child: DropdownButtonFormField<String?>(
                      isExpanded: true,
                      initialValue: rhDepartments(all: true).any((d) => d['id'] == _deptId) ? _deptId : null,
                      items: [
                        DropdownMenuItem<String?>(value: null, child: Text(tr('—'))),
                        for (final d in rhDepartments()) DropdownMenuItem<String?>(value: '${d['id']}', child: Text(tr('${d['name']}'), overflow: TextOverflow.ellipsis)),
                      ],
                      onChanged: (v) => setState(() {
                        _deptId = v;
                        _c['department']!.text = v == null ? '' : '${LocalDb.get('rh_departments', v)?['name'] ?? ''}';
                      }),
                    ),
                  ),
            rhCategories().isEmpty
                ? _tf('category', 'Catégorie')
                : Field(
                    label: tr('Catégorie'),
                    child: DropdownButtonFormField<String>(
                      isExpanded: true,
                      initialValue: _c['category']!.text,
                      items: [
                        DropdownMenuItem(value: '', child: Text('—')),
                        if (_c['category']!.text.isNotEmpty && rhCategoryByCode(_c['category']!.text) == null)
                          DropdownMenuItem(value: _c['category']!.text, child: Text(tr('${_c['category']!.text} (hors grille)'))),
                        for (final c in rhCategories()) DropdownMenuItem(value: '${c['code']}', child: Text(tr('${c['code']}${c['label'] != null ? ' · ${c['label']}' : ''}'), overflow: TextOverflow.ellipsis)),
                      ],
                      onChanged: (v) => setState(() => _c['category']!.text = v ?? ''),
                    ),
                  ),
          ]),
          if (LocalDb.hasEntity('rh_requests'))
            Field(
              label: tr('Responsable direct'),
              child: DropdownButtonFormField<String?>(
                isExpanded: true,
                initialValue: rhEmployees().any((x) => x['id'] == _manager) ? _manager : null,
                items: [
                  DropdownMenuItem<String?>(value: null, child: Text(tr('— Titulaire du poste supérieur (fiche de poste)'))),
                  for (final x in rhEmployees().where((x) => x['id'] != widget.employee?['id']))
                    DropdownMenuItem<String?>(value: '${x['id']}', child: Text(tr([empName(x), x['job_title']].where((v) => v != null && '$v'.isNotEmpty).join(' · ')), overflow: TextOverflow.ellipsis)),
                ],
                onChanged: (v) => setState(() => _manager = v),
              ),
            ),
          _row([
            Field(
              label: tr('Type de contrat'),
              child: DropdownButtonFormField<String>(
                isExpanded: true,
                initialValue: _contract,
                items: [for (final e in contractTypes.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => setState(() => _contract = v!),
              ),
            ),
            _date('hire_date', 'Date d\'embauche'),
            _date('end_date', 'Date de sortie'),
          ]),
          if (LocalDb.hasEntity('rh_departments')) ...[
            Field(
              label: tr('Statut'),
              child: Wrap(spacing: 6, runSpacing: 6, children: [
                for (final st in staffTypes.entries)
                  ChoiceChip(
                    label: Text(tr(st.value)),
                    selected: _staffType == st.key,
                    onSelected: (_) => setState(() {
                      _staffType = st.key;
                      if (widget.employee == null && (st.key == 'intern' || st.key == 'contractor')) {
                        _declared = false;
                        _cmu = false;
                      }
                    }),
                  ),
              ]),
            ),
            _section('Déclarations et assurance'),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: _declared,
              onChanged: (v) => setState(() => _declared = v),
              title: Text(tr('Déclaré à la CNPS')),
              subtitle: Text(tr(_declared ? 'Bulletin avec cotisations CNPS, ITS et charges patronales.' : 'Non déclaré (stagiaire, prestataire…) : payé au montant convenu, sans retenues.')),
            ),
            SwitchListTile(contentPadding: EdgeInsets.zero, value: _cmu, onChanged: (v) => setState(() => _cmu = v), title: Text(tr('Adhérent CMU (Couverture maladie universelle)'))),
            SwitchListTile(contentPadding: EdgeInsets.zero, value: _insurance, onChanged: (v) => setState(() => _insurance = v), title: Text(tr('Assurance santé / mutuelle'))),
            if (_insurance) _row([_tf('insurance_name', 'Assureur', hint: 'ex. Sunu, Allianz'), _tf('insurance_employee', 'Part salarié / mois', numeric: true), _tf('insurance_employer', 'Part employeur / mois', numeric: true)]),
            _section('Horaires de travail'),
            _row([
              _tf('weekly_hours', 'Heures par semaine', numeric: true),
              Field(
                label: tr('Taux horaire'),
                child: Builder(builder: (context) {
                  final h = (num.tryParse(_c['weekly_hours']!.text.replaceAll(',', '.')) ?? 40) * 52 / 12;
                  final base = num.tryParse(_c['base_salary']!.text.replaceAll(' ', '').replaceAll(',', '.')) ?? 0;
                  return InputDecorator(
                    decoration: const InputDecoration(),
                    child: Text(tr(h > 0 && base > 0 ? '${money(base / h, context.read<Session>().currency)} / h (${number(h, decimals: 0)} h / mois)' : '—')),
                  );
                }),
              ),
            ]),
            _ScheduleEditor(value: _schedule, onChanged: (v) => setState(() => _schedule = v)),
            if (widget.employee != null && LocalDb.hasEntity('rh_contracts')) _ContractsSection(employee: widget.employee!),
          ],
          _section('Rémunération'),
          _row([_tf('base_salary', 'Salaire de base mensuel', numeric: true, required: true), _tf('transport_allowance', 'Indemnité de transport', numeric: true)]),
          Builder(builder: (context) {
            final under = rhUnderMinimum({'category': _c['category']!.text, 'base_salary': num.tryParse(_c['base_salary']!.text.replaceAll(' ', '').replaceAll(',', '.')) ?? 0});
            if (under == null) return const SizedBox.shrink();
            final min = rhCategoryByCode(_c['category']!.text)!['min_salary'];
            return Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Row(children: [
                const Icon(Icons.warning_amber_rounded, size: 18, color: YColors.danger),
                const SizedBox(width: 6),
                Expanded(child: Text(tr('Inférieur au minimum de la catégorie ${_c['category']!.text} (${number(toDouble(min))} ) : il manque ${number(under)}.'), style: const TextStyle(color: YColors.danger, fontSize: 12.5))),
              ]),
            );
          }),
          Text(tr('Primes fixes mensuelles'), style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
          const SizedBox(height: 6),
          for (var i = 0; i < _fixed.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: [
                  Expanded(
                    flex: 3,
                    child: TextFormField(
                      initialValue: '${_fixed[i]['label'] ?? ''}',
                      decoration: InputDecoration(hintText: tr('Libellé (ex. prime de logement)')),
                      onChanged: (v) => _fixed[i]['label'] = v,
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    flex: 2,
                    child: TextFormField(
                      initialValue: _fixed[i]['amount'] == null ? '' : '${(_fixed[i]['amount'] as num).round()}',
                      keyboardType: TextInputType.number,
                      decoration: InputDecoration(hintText: tr('Montant')),
                      onChanged: (v) => _fixed[i]['amount'] = num.tryParse(v.replaceAll(' ', '')) ?? 0,
                    ),
                  ),
                  Tooltip(
                    message: tr('Imposable (ITS) et soumise à CNPS'),
                    child: Checkbox(value: _fixed[i]['taxable'] != false, onChanged: (v) => setState(() => _fixed[i] = {..._fixed[i], 'taxable': v, 'social': v})),
                  ),
                  IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => setState(() => _fixed.removeAt(i))),
                ],
              ),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => setState(() => _fixed.add({'label': '', 'amount': 0, 'taxable': true, 'social': true})),
              icon: const Icon(Icons.add),
              label: Text(tr('Ajouter une prime')),
            ),
          ),
          const SizedBox(height: 8),
          _row([
            Field(
              label: tr('Paiement du salaire'),
              child: DropdownButtonFormField<String>(
                isExpanded: true,
                initialValue: _payment,
                items: [for (final e in salaryPaymentMethods.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => setState(() => _payment = v!),
              ),
            ),
            _tf('payment_account', 'RIB / numéro Mobile Money'),
          ]),
          _section('Situation fiscale et sociale'),
          _row([
            Field(
              label: tr('Situation familiale'),
              child: DropdownButtonFormField<String>(
                isExpanded: true,
                initialValue: _marital,
                items: [for (final e in maritalStatuses.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => setState(() => _marital = v!),
              ),
            ),
            _tf('children_count', 'Enfants à charge', numeric: true),
            _tf('parts_override', 'Parts (forcer)', numeric: true, hint: number(_parts, decimals: 1)),
          ]),
          Text(
            tr('Parts fiscales retenues : ${number(_parts, decimals: 1)}. Célibataire 1, marié 2, + 0,5 par enfant (max 5). '
            'Forcez la valeur pour les cas particuliers (veuf, divorcé avec enfants…).'),
            style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
          ),
          const SizedBox(height: 10),
          _row([_tf('cnps_number', 'N° CNPS'), const SizedBox()]),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _spouse,
            onChanged: (v) => setState(() => _spouse = v),
            title: Text(tr('Conjoint sans emploi')),
            subtitle: Text(tr('Pris en charge pour la CMU')),
          ),
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: _expat,
            onChanged: (v) => setState(() => _expat = v),
            title: Text(tr('Personnel expatrié')),
            subtitle: Text(tr('Contribution employeur au taux expatrié')),
          ),
          _section('Congés'),
          _row([_tf('leave_days_per_month', 'Jours acquis par mois', numeric: true), _tf('leave_opening_balance', 'Report au 1er janvier (jours)', numeric: true)]),
          _tf('notes', 'Notes'),
          if (_champs) CustomFieldsEditor(entity: 'rh_employees', values: _custom, creating: widget.employee == null),
          _section('Accès à l\'application'),
          Field(
            label: tr('Compte de connexion (espace salarié)'),
            child: _users == null
                ? Text(tr(_userId == null ? 'Liste des comptes disponible en ligne' : 'Compte relié'), style: TextStyle(color: Theme.of(context).hintColor))
                : DropdownButtonFormField<int?>(
                    isExpanded: true,
                    initialValue: _users!.any((u) => u['id'] == _userId) ? _userId : null,
                    items: [
                      DropdownMenuItem<int?>(value: null, child: Text(tr('Aucun — pas d\'accès à l\'application'))),
                      for (final u in _users!) DropdownMenuItem<int?>(value: (u['id'] as num).toInt(), child: Text(tr('${u['name']} (${u['email']})'), overflow: TextOverflow.ellipsis)),
                    ],
                    onChanged: (v) => setState(() => _userId = v),
                  ),
          ),

          if (widget.employee != null) SwitchListTile(contentPadding: EdgeInsets.zero, value: _active, onChanged: (v) => setState(() => _active = v), title: Text(tr('Dans les effectifs'))),
          if (widget.employee != null) LinkedDocuments(field: 'employee_id', id: '${widget.employee!['id']}'),
          const SizedBox(height: 12),
          FilledButton(onPressed: _busy ? null : _save, child: Text(tr(_busy ? 'Enregistrement…' : 'Enregistrer'))),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Congés
// ---------------------------------------------------------------------------

class _LeavesTab extends StatefulWidget {
  const _LeavesTab({super.key, required this.manage});

  final bool manage;

  @override
  State<_LeavesTab> createState() => _LeavesTabState();
}

class _LeavesTabState extends State<_LeavesTab> {
  String _status = 'requested';
  String _q = '';
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  void load() => setState(() {});

  Future<void> _decide(Map<String, dynamic> l, String status) async {
    String? note;
    if (status == 'rejected' || status == 'cancelled') {
      final c = TextEditingController();
      final ok = await showDialog<bool>(
        context: context,
        builder: (d) => YAlertDialog(
          title: Text(tr(status == 'rejected' ? 'Refuser la demande' : 'Annuler le congé')),
          content: TextField(controller: c, autofocus: true, decoration: InputDecoration(labelText: tr('Motif (envoyé au salarié)'))),
          actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Retour'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Confirmer')))],
        ),
      );
      if (ok != true) return;
      note = c.text.trim().isEmpty ? null : c.text.trim();
    }
    if (!mounted) return;
    try {
      await context.read<SyncEngine>().save('rh_leaves', {'id': l['id'], 'status': status, 'decision_note': ?note});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    Widget actions(Map<String, dynamic> l) {
      if (!widget.manage) return const SizedBox();
      return Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (l['status'] == 'requested') ...[
            IconButton(tooltip: tr('Approuver'), icon: const Icon(Icons.check_circle_outline, color: YColors.success), onPressed: () => _decide(l, 'approved')),
            IconButton(tooltip: tr('Refuser'), icon: const Icon(Icons.highlight_off, color: YColors.danger), onPressed: () => _decide(l, 'rejected')),
          ],
          if (l['status'] == 'approved') IconButton(tooltip: tr('Annuler'), icon: const Icon(Icons.undo), onPressed: () => _decide(l, 'cancelled')),
        ],
      );
    }

    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_leaves'),
      builder: (context, _, _) {
        final emps = {for (final e in LocalDb.all('rh_employees')) e['id']: e};
        String who(Map l) => emps[l['employee_id']] == null ? '—' : empName(emps[l['employee_id']]!);
        final all = LocalDb.all('rh_leaves');
        bool overlaps(Map l) {
          if (_period == null) return true;
          final a = DateTime.tryParse('${l['start_date']}');
          final b = DateTime.tryParse('${l['end_date'] ?? l['start_date']}');
          if (a == null || b == null) return false;
          return !a.isAfter(_period!.end) && !b.isBefore(_period!.start);
        }

        bool base(Map<String, dynamic> l) =>
            (_q.isEmpty || '${who(l)} ${l['reason'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
            (_f['employee'] == null || '${l['employee_id']}' == _f['employee']) &&
            (_f['type'] == null || '${l['type']}' == _f['type']) &&
            (_f['department'] == null || '${emps[l['employee_id']]?['department'] ?? ''}' == _f['department']) &&
            overlaps(l);
        bool st(Map<String, dynamic> l, String k) => k.isEmpty || l['status'] == k;
        final rows = all.where((l) => base(l) && st(l, _status)).toList()..sort((a, b) => '${b['start_date']}'.compareTo('${a['start_date']}'));
        const stKeys = ['requested', 'approved', 'rejected', 'cancelled', ''];
        return Column(
          children: [
            const SizedBox(height: 16),
            FilterBar(
              onSearch: (v) => setState(() => _q = v),
              search: _q,
              searchHint: 'Salarié, motif…',
              statuses: {for (final k in stKeys) k: k.isEmpty ? 'Tous' : (k == 'requested' ? 'À traiter' : leaveStatuses[k]!)},
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in stKeys) k: all.where((l) => base(l) && st(l, k)).length},
              filters: [
                FilterSpec('employee', 'Salarié', filterOptions(all, 'employee_id', label: who), icon: Icons.person_outline),
                FilterSpec('type', 'Type', leaveTypes, icon: Icons.beach_access_outlined),
                FilterSpec('department', 'Service', filterOptions(emps.values, 'department'), icon: Icons.apartment_outlined),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              showPeriod: true,
              period: _period,
              onPeriod: (p) => setState(() => _period = p),
              count: rows.length,
              countLabel: 'congé',
              onReset: () => setState(() => (_q = '', _status = 'requested', _f = {}, _period = null)),
            ),
            Expanded(
              child: rows.isEmpty
                  ? EmptyState(icon: Icons.beach_access_outlined, title: _status == 'requested' ? 'Aucune demande en attente' : 'Aucun congé', message: tr('Les demandes des salariés (depuis leur espace) et les absences saisies apparaissent ici.'))
                  : ResponsiveList<Map<String, dynamic>>(
                      items: rows,
                      columns: const ['Salarié', 'Type', 'Du', 'Au', 'Jours', 'Motif', 'Statut', ''],
                      cells: (l) => [
                        Row(children: [
                          InitialsAvatar(who(l)),
                          const SizedBox(width: 10),
                          Flexible(child: Text(tr(who(l)), style: const TextStyle(fontWeight: FontWeight.w600), overflow: TextOverflow.ellipsis)),
                        ]),
                        Text(tr(leaveTypes[l['type']] ?? '${l['type']}')),
                        Text(tr(dateFr(l['start_date']))),
                        Text(tr(dateFr(l['end_date']))),
                        Text(tr(number(l['days'], decimals: 1))),
                        Text(tr('${l['reason'] ?? ''}${l['decision_note'] != null ? ' — ${l['decision_note']}' : ''}'), maxLines: 2, overflow: TextOverflow.ellipsis),
                        StatusChip(leaveStatuses[l['status']] ?? '${l['status']}', color: leaveStatusColor(l['status'])),
                        actions(l),
                      ],
                      mobileTile: (l) => Row(
                        children: [
                          InitialsAvatar(who(l), size: 40),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(tr(who(l)), style: const TextStyle(fontWeight: FontWeight.w600)),
                                Text(
                                  tr('${leaveTypes[l['type']]} · ${dateFr(l['start_date'])} au ${dateFr(l['end_date'])} · ${number(l['days'], decimals: 1)} j'),
                                  style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13),
                                ),
                                if (l['reason'] != null) Text(tr('${l['reason']}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 13)),
                                const SizedBox(height: 4),
                                StatusChip(leaveStatuses[l['status']] ?? '${l['status']}', color: leaveStatusColor(l['status'])),
                              ],
                            ),
                          ),
                          actions(l),
                        ],
                      ),
                    ),
            ),
          ],
        );
      },
    );
  }
}

Future<Map<String, dynamic>?> editLeave(BuildContext context, {String? employeeId, bool self = false}) {
  return showFormDialog<Map<String, dynamic>>(context, title: self ? 'Demande de congé' : 'Nouveau congé ou absence', builder: (c) => _LeaveForm(employeeId: employeeId, self: self));
}

class _LeaveForm extends StatefulWidget {
  const _LeaveForm({this.employeeId, this.self = false});

  final String? employeeId;
  final bool self;

  @override
  State<_LeaveForm> createState() => _LeaveFormState();
}

class _LeaveFormState extends State<_LeaveForm> {
  String? _employee;
  String _type = 'annual';
  DateTimeRange? _range;
  final _days = TextEditingController();
  final _reason = TextEditingController();
  bool _approve = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _employee = widget.employeeId;
    _approve = !widget.self;
  }

  Future<void> _save() async {
    if (_employee == null || _range == null) return toast(context, tr('Choisissez ${widget.self ? '' : 'le salarié et '}les dates.'), error: true);
    setState(() => _busy = true);
    final data = {
      'id': newId(),
      'employee_id': _employee,
      'type': _type,
      'start_date': isoDate(_range!.start),
      'end_date': isoDate(_range!.end),
      'days': num.tryParse(_days.text.replaceAll(',', '.')) ?? workingDaysBetween(_range!.start, _range!.end),
      'reason': _reason.text.trim().isEmpty ? null : _reason.text.trim(),
      'status': _approve ? 'approved' : 'requested',
    };
    try {
      await context.read<SyncEngine>().save('rh_leaves', data);
      if (mounted) Navigator.pop(context, data);
    } catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, errorText(e), error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final employees = rhEmployees();
    final emp = LocalDb.get('rh_employees', _employee);
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
      shrinkWrap: true,
      children: [
        if (!widget.self)
          Field(
            label: tr('Salarié'),
            child: DropdownButtonFormField<String>(
              isExpanded: true,
              initialValue: _employee,
              items: [for (final e in employees) DropdownMenuItem(value: '${e['id']}', child: Text(tr('${empName(e)} (${e['matricule'] ?? '—'})')))],
              onChanged: (v) => setState(() => _employee = v),
            ),
          ),
        if (emp != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(tr('Solde de congés payés : ${number(leaveBalance(emp), decimals: 1)} jours'), style: TextStyle(color: Theme.of(context).hintColor)),
          ),
        Field(
          label: tr('Type'),
          child: DropdownButtonFormField<String>(
            isExpanded: true,
            initialValue: _type,
            items: [for (final e in leaveTypes.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
            onChanged: (v) => setState(() => _type = v!),
          ),
        ),
        if (_type == 'unpaid')
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: Text(tr('Une absence non payée approuvée est déduite automatiquement des jours payés dans la paie du mois.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          ),
        Field(
          label: tr('Période'),
          child: OutlinedButton.icon(
            onPressed: () async {
              final r = await showDateRangePicker(context: context, firstDate: DateTime(2015), lastDate: DateTime(2100), initialDateRange: _range);
              if (r != null) setState(() => (_range = r, _days.text = number(workingDaysBetween(r.start, r.end))));
            },
            icon: const Icon(Icons.date_range),
            label: Text(tr(_range == null ? 'Choisir les dates' : 'Du ${dateFr(isoDate(_range!.start))} au ${dateFr(isoDate(_range!.end))}')),
          ),
        ),
        Field(
          label: tr('Nombre de jours (ouvrables, du lundi au samedi)'),
          child: TextField(controller: _days, keyboardType: TextInputType.number),
        ),
        Field(
          label: tr('Motif'),
          child: TextField(controller: _reason),
        ),
        if (!widget.self) SwitchListTile(contentPadding: EdgeInsets.zero, value: _approve, onChanged: (v) => setState(() => _approve = v), title: Text(tr('Approuver directement'))),
        if (widget.self)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(tr('La demande part au service RH (même hors ligne, dès le retour du réseau). Vous serez prévenu de sa décision.'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
          ),
        const SizedBox(height: 12),
        FilledButton(onPressed: _busy ? null : _save, child: Text(tr(widget.self ? 'Envoyer la demande' : 'Enregistrer'))),
      ],
    );
  }
}


// ---------------------------------------------------------------------------
// Horaires de la semaine (entrée, sortie, pause)
// ---------------------------------------------------------------------------

class _ScheduleEditor extends StatelessWidget {
  const _ScheduleEditor({required this.value, required this.onChanged});

  final Map<String, Map<String, dynamic>> value;
  final ValueChanged<Map<String, Map<String, dynamic>>> onChanged;

  static const _days = ['', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

  int _minutes(String? hm) {
    final p = (hm ?? '').split(':');
    return p.length == 2 ? (int.tryParse(p[0]) ?? 0) * 60 + (int.tryParse(p[1]) ?? 0) : 0;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    var total = 0;
    for (final d in value.values) {
      total += (_minutes('${d['end']}') - _minutes('${d['start']}') - ((d['break'] as num?)?.toInt() ?? 0)).clamp(0, 24 * 60);
    }
    Future<void> pick(String day, String key) async {
      final cur = '${value[day]?[key] ?? (key == 'start' ? '08:00' : '17:00')}'.split(':');
      final t = await showTimePicker(context: context, initialTime: TimeOfDay(hour: int.tryParse(cur[0]) ?? 8, minute: int.tryParse(cur.length > 1 ? cur[1] : '0') ?? 0));
      if (t == null) return;
      final v = {...value, day: {...?value[day], key: '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}'}};
      onChanged(v);
    }

    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        for (var d = 1; d <= 7; d++)
          Padding(
            padding: const EdgeInsets.only(bottom: 4),
            child: Row(children: [
              SizedBox(
                width: 78,
                child: FilterChip(
                  label: Text(tr(_days[d])),
                  selected: value['$d'] != null,
                  onSelected: (on) {
                    final v = {...value};
                    on ? v['$d'] = {'start': '08:00', 'end': d == 6 ? '13:00' : '17:00', 'break': d == 6 ? 0 : 60} : v.remove('$d');
                    onChanged(v);
                  },
                ),
              ),
              const SizedBox(width: 8),
              if (value['$d'] != null) ...[
                OutlinedButton(onPressed: () => pick('$d', 'start'), child: Text('${value['$d']!['start']}')),
                const Padding(padding: EdgeInsets.symmetric(horizontal: 6), child: Text('→')),
                OutlinedButton(onPressed: () => pick('$d', 'end'), child: Text('${value['$d']!['end']}')),
                const SizedBox(width: 8),
                Flexible(
                  child: DropdownButton<int>(
                    value: [0, 30, 60, 90, 120].contains((value['$d']!['break'] as num?)?.toInt()) ? (value['$d']!['break'] as num).toInt() : 60,
                    underline: const SizedBox.shrink(),
                    items: [for (final b in const [0, 30, 60, 90, 120]) DropdownMenuItem(value: b, child: Text(tr(b == 0 ? 'sans pause' : 'pause $b min')))],
                    onChanged: (b) => onChanged({...value, '$d': {...value['$d']!, 'break': b}}),
                  ),
                ),
              ] else
                Text(tr('Repos'), style: TextStyle(color: theme.hintColor)),
            ]),
          ),
        Text(tr('Total planifié : ${number(total / 60, decimals: 1)} h par semaine'), style: TextStyle(color: theme.hintColor, fontSize: 12.5)),
      ]),
    );
  }
}

// ---------------------------------------------------------------------------
// Contrats enregistrés par la RH (fiche salarié)
// ---------------------------------------------------------------------------

class _ContractsSection extends StatelessWidget {
  const _ContractsSection({required this.employee});

  final Map<String, dynamic> employee;

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rh_contracts'),
      builder: (context, _, _) {
        final list = LocalDb.all('rh_contracts').where((c) => c['employee_id'] == employee['id']).toList()..sort((a, b) => '${b['start_date']}'.compareTo('${a['start_date']}'));
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.only(top: 8, bottom: 6),
            child: Row(children: [
              Text(tr('Contrats'), style: TextStyle(fontWeight: FontWeight.w700, color: Theme.of(context).colorScheme.primary)),
              const Spacer(),
              TextButton.icon(onPressed: () => editRhContract(context, employee), icon: const Icon(Icons.add, size: 18), label: Text(tr('Nouveau contrat'))),
            ]),
          ),
          if (list.isEmpty) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(tr('Aucun contrat enregistré.'), style: TextStyle(color: Theme.of(context).hintColor))),
          for (final c in list)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                leading: const Icon(Icons.description_outlined, color: YColors.blue),
                title: Text(tr('${rhContractTypes['${c['type']}'] ?? c['type']} · ${c['number'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text(tr([
                  'du ${dateFr(c['start_date'])}',
                  if (c['end_date'] != null) 'au ${dateFr(c['end_date'])}',
                  if (c['trial_end_date'] != null) 'essai jusqu\'au ${dateFr(c['trial_end_date'])}',
                  if (toDouble(c['salary']) > 0) money(c['salary'], cur),
                  if (c['weekly_hours'] != null) '${number(c['weekly_hours'], decimals: 0)} h / sem.',
                ].join(' · '))),
                trailing: StatusChip(const {'active': 'En vigueur', 'ended': 'Terminé', 'terminated': 'Rompu', 'draft': 'Brouillon'}['${c['status']}'] ?? '${c['status']}',
                    color: c['status'] == 'active' ? YColors.success : YColors.muted),
                onTap: () => editRhContract(context, employee, c),
              ),
            ),
        ]);
      },
    );
  }
}

Future<void> editRhContract(BuildContext context, Map<String, dynamic> employee, [Map<String, dynamic>? contract]) {
  final x = contract ?? {};
  String type = '${x['type'] ?? (employee['contract_type'] == 'stage' ? 'stage' : 'CDI')}';
  String status = '${x['status'] ?? 'active'}';
  DateTime? start = DateTime.tryParse('${x['start_date'] ?? ''}') ?? DateTime.now();
  DateTime? end = DateTime.tryParse('${x['end_date'] ?? ''}');
  DateTime? trial = DateTime.tryParse('${x['trial_end_date'] ?? ''}');
  DateTime? signed = DateTime.tryParse('${x['signed_on'] ?? ''}');
  final salary = TextEditingController(text: toDouble(x['salary'] ?? employee['base_salary']) > 0 ? '${toDouble(x['salary'] ?? employee['base_salary']).round()}' : '');
  final hours = TextEditingController(text: '${x['weekly_hours'] ?? employee['weekly_hours'] ?? 40}'.replaceAll('.0', ''));
  final reason = TextEditingController(text: '${x['end_reason'] ?? ''}');
  final notes = TextEditingController(text: '${x['notes'] ?? ''}');
  final link = TextEditingController(text: '${x['file_url'] ?? ''}');
  return showFormDialog(context, title: contract == null ? 'Nouveau contrat — ${empName(employee)}' : 'Contrat ${x['number'] ?? ''}', builder: (dc) {
    return StatefulBuilder(builder: (dc, setS) {
      Widget date(String label, DateTime? v, ValueChanged<DateTime?> set, {bool clearable = true}) => Field(
            label: label,
            child: InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: () async {
                final d = await showDatePicker(context: dc, firstDate: DateTime(1990), lastDate: DateTime(2100), initialDate: v ?? start ?? DateTime.now());
                if (d != null) setS(() => set(d));
              },
              child: InputDecorator(
                decoration: InputDecoration(suffixIcon: v != null && clearable ? IconButton(icon: const Icon(Icons.clear, size: 18), onPressed: () => setS(() => set(null))) : const Icon(Icons.event, size: 18)),
                child: Text(tr(v == null ? '—' : dateFr(isoDate(v)))),
              ),
            ),
          );
      Widget pair(Widget a, Widget b) => Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: a), const SizedBox(width: 12), Expanded(child: b)]);
      final needsEnd = ['CDD', 'stage', 'apprentissage'].contains(type);
      return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(
          label: tr('Type de contrat'),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            for (final t in rhContractTypes.entries) ChoiceChip(label: Text(tr(t.value)), selected: type == t.key, onSelected: (_) => setS(() => type = t.key)),
          ]),
        ),
        pair(date('Prise de fonction *', start, (d) => start = d, clearable: false), date(needsEnd ? 'Date de fin *' : 'Date de fin', end, (d) => end = d)),
        pair(date('Fin de la période d\'essai', trial, (d) => trial = d), date('Signé le', signed, (d) => signed = d)),
        pair(
          Field(label: tr(type == 'stage' ? 'Gratification mensuelle' : 'Salaire de base mensuel'), child: TextField(controller: salary, keyboardType: TextInputType.number, inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9\s]'))])),
          Field(label: tr('Heures par semaine'), child: TextField(controller: hours, keyboardType: const TextInputType.numberWithOptions(decimal: true))),
        ),
        Field(
          label: tr('Statut'),
          child: DropdownButtonFormField<String>(
            isExpanded: true,
            initialValue: status,
            items: [for (final st in const {'active': 'En vigueur', 'draft': 'Brouillon', 'ended': 'Terminé', 'terminated': 'Rompu'}.entries) DropdownMenuItem(value: st.key, child: Text(tr(st.value)))],
            onChanged: (v) => setS(() => status = v!),
          ),
        ),
        if (status == 'terminated' || status == 'ended') Field(label: tr('Motif de fin'), child: TextField(controller: reason, decoration: InputDecoration(hintText: tr('ex. fin de CDD, démission, licenciement')))),
        Field(label: tr('Contrat signé (lien GED ou fichier)'), child: TextField(controller: link)),
        Field(label: tr('Notes'), child: TextField(controller: notes, minLines: 1, maxLines: 4)),
        Text(tr('Un contrat « en vigueur » met à jour la fiche (type, prise de fonction, fin, salaire, heures) et termine le précédent.'), style: TextStyle(color: Theme.of(dc).hintColor, fontSize: 12.5)),
        const SizedBox(height: 12),
        Align(
          alignment: Alignment.centerRight,
          child: FilledButton(
            onPressed: () async {
              if (start == null) return toast(dc, tr('Indiquez la prise de fonction.'), error: true);
              if (needsEnd && end == null && status != 'draft') return toast(dc, tr('Un ${rhContractTypes[type]} a une date de fin.'), error: true);
              try {
                await dc.read<SyncEngine>().save('rh_contracts', {
                  'id': x['id'] ?? newId(),
                  'employee_id': employee['id'],
                  'type': type,
                  'start_date': isoDate(start!),
                  'end_date': end == null ? null : isoDate(end!),
                  'trial_end_date': trial == null ? null : isoDate(trial!),
                  'signed_on': signed == null ? null : isoDate(signed!),
                  'salary': num.tryParse(salary.text.replaceAll(' ', '')) ?? 0,
                  'weekly_hours': num.tryParse(hours.text.replaceAll(',', '.')),
                  'status': status,
                  'end_reason': reason.text.trim().isEmpty ? null : reason.text.trim(),
                  'file_url': link.text.trim().isEmpty ? null : link.text.trim(),
                  'notes': notes.text.trim().isEmpty ? null : notes.text.trim(),
                });
                if (dc.mounted) Navigator.pop(dc);
              } catch (e) {
                if (dc.mounted) toast(dc, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ),
      ]);
    });
  });
}
