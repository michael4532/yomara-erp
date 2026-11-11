import 'dart:async';
import 'dart:convert';

import 'package:crypto/crypto.dart';
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
import 'rh_screen.dart' show leaveTypes;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

// ======================================================================
// Calcul de la présence (même règles que le serveur, pour l'affichage hors ligne)
// ======================================================================

const ptStatuses = {
  'in': 'Sur place',
  'present': 'Présent',
  'late': 'En retard',
  'absent': 'Absent',
  'leave': 'En congé',
  'holiday': 'Férié',
  'rest': 'Repos',
  'incomplete': 'Pointage incomplet',
  'upcoming': 'Pas encore arrivé',
  'out': 'Parti',
  'off': '—',
};

Color ptStatusColor(String? s) => switch (s) {
  'in' || 'present' || 'out' => YColors.success,
  'late' || 'incomplete' => YColors.warning,
  'absent' => YColors.danger,
  'leave' || 'holiday' => YColors.blue,
  _ => YColors.muted,
};

const ptSources = {'kiosk': 'Borne', 'self': 'Téléphone', 'manual': 'Saisie du responsable'};
const ptDayNames = {'1': 'Lundi', '2': 'Mardi', '3': 'Mercredi', '4': 'Jeudi', '5': 'Vendredi', '6': 'Samedi', '7': 'Dimanche'};

/// Horloge de la société (réglée par l'application au démarrage : fuseau du serveur, pas celui de l'appareil).
DateTime Function() ptClock = DateTime.now;

String ptNow() {
  final n = ptClock();
  String p(int v) => v.toString().padLeft(2, '0');
  return '${n.year}-${p(n.month)}-${p(n.day)} ${p(n.hour)}:${p(n.minute)}:${p(n.second)}';
}

String hm(int minutes) => minutes <= 0 ? '0 h' : '${minutes ~/ 60} h ${(minutes % 60).toString().padLeft(2, '0')}';

String ptPinHash(String employeeId, String pin) => sha256.convert(utf8.encode('$employeeId:$pin')).toString();

Map<String, dynamic>? ptSchedule(Map badge) {
  final s = LocalDb.get('pt_schedules', badge['schedule_id'] as String?);
  return s ?? LocalDb.all('pt_schedules').where((x) => x['is_default'] == true).firstOrNull;
}

Map<String, dynamic>? ptPlan(Map? schedule, DateTime day) {
  final d = asMap(schedule?['days'])['${day.weekday}'];
  return d == null ? null : asMap(d);
}

int _min(String hhmm) {
  final p = hhmm.split(':');
  return int.parse(p[0]) * 60 + int.parse(p[1]);
}

List<Map<String, dynamic>> ptPunchesOf(String employeeId, String date) =>
    LocalDb.all('pt_punches').where((p) => p['employee_id'] == employeeId && '${p['at']}'.startsWith(date)).toList()..sort((a, b) => '${a['at']}'.compareTo('${b['at']}'));

/// Journée d'un salarié : arrivée, départ, minutes travaillées, retard, statut.
Map<String, dynamic> ptDay(Map badge, DateTime day) {
  final date = isoDate(day);
  final schedule = ptSchedule(badge);
  final plan = ptPlan(schedule, day);
  final tolerance = (schedule?['tolerance'] as num?)?.toInt() ?? 10;
  final punches = ptPunchesOf('${badge['id']}', date);
  final today = isoDate(ptClock()) == date;
  var worked = 0;
  int? open;
  var missing = false;
  for (final p in punches) {
    final t = _min('${p['at']}'.substring(11, 16));
    if (p['kind'] == 'in') {
      if (open != null) missing = true;
      open = t;
    } else if (open != null) {
      worked += t - open;
      open = null;
    } else {
      missing = true;
    }
  }
  final first = punches.where((p) => p['kind'] == 'in').firstOrNull;
  final last = punches.where((p) => p['kind'] == 'out').lastOrNull;
  final pairs = punches.where((p) => p['kind'] == 'out').length;
  final brk = (plan?['break'] as num?)?.toInt() ?? 0;
  if (plan != null && brk > 0 && pairs == 1 && worked > brk + 240) worked -= brk;
  var late = 0;
  if (plan != null && first != null) {
    final d = _min('${first['at']}'.substring(11, 16)) - _min('${plan['start']}');
    if (d > tolerance) late = d;
  }
  if (today && open != null) worked += _min(ptNow().substring(11, 16)) - open; // en cours
  String status;
  if (punches.isEmpty) {
    final leave = LocalDb.hasEntity('rh_leaves')
        ? LocalDb.all('rh_leaves').where((l) => l['employee_id'] == badge['id'] && l['status'] == 'approved' && '${l['start_date']}'.compareTo(date) <= 0 && '${l['end_date']}'.compareTo(date) >= 0).firstOrNull
        : null;
    final holidays = asMap(LocalDb.meta.get('pt_holidays'));
    status = leave != null
        ? 'leave'
        : holidays.containsKey(date)
            ? 'holiday'
            : plan == null
                ? 'rest'
                : (today && _min(ptNow().substring(11, 16)) < _min('${plan['start']}')) || day.isAfter(ptClock())
                    ? 'upcoming'
                    : 'absent';
  } else if (missing || (open != null && !today)) {
    status = 'incomplete';
  } else if (open != null) {
    status = late > 0 ? 'late' : 'in';
  } else {
    status = today ? 'out' : (late > 0 ? 'late' : 'present');
  }
  return {
    'status': status,
    'first_in': first == null ? null : '${first['at']}'.substring(11, 16),
    'last_out': last == null ? null : '${last['at']}'.substring(11, 16),
    'worked': worked < 0 ? 0 : worked,
    'late': late,
    'open': open != null,
    'plan': plan,
    'punches': punches,
  };
}

/// Prochain sens de pointage : arrivée si rien d'ouvert aujourd'hui, sinon départ.
String ptNextKind(String employeeId) {
  final p = ptPunchesOf(employeeId, isoDate(ptClock()));
  return p.isNotEmpty && p.last['kind'] == 'in' ? 'out' : 'in';
}

List<Map<String, dynamic>> ptBadges() => LocalDb.all('pt_badges').where((b) => b['active'] != false).toList()..sort((a, b) => '${a['name']}'.toLowerCase().compareTo('${b['name']}'.toLowerCase()));

Future<void> ptRefreshHolidays(BuildContext context) async {
  try {
    final r = await context.read<Session>().api.get('pointage/settings');
    await LocalDb.meta.put('pt_holidays', asMap(r['data']['year_holidays']));
  } catch (_) {}
}

// ======================================================================
// Écran du responsable
// ======================================================================


/// Total des heures supplémentaires (minutes), toutes majorations.
int _ot(Map t) => ['ot15', 'ot50', 'ot_night', 'ot75', 'ot100'].fold(0, (a, k) => a + ((t[k] as num?) ?? 0).toInt());

class PointageScreen extends StatefulWidget {
  const PointageScreen({super.key});

  @override
  State<PointageScreen> createState() => _PointageScreenState();
}

class _PointageScreenState extends State<PointageScreen> {
  @override
  void initState() {
    super.initState();
    ptRefreshHolidays(context);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('pointage.manage');
    final tabs = [
      ('Aujourd\'hui', const _TodayTab()),
      ('Feuille de présence', const _SheetTab()),
      ('Pointages', const _PunchesTab()),
      if (manage) ('Horaires & codes', const _SettingsTab()),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(
          title: tr('Pointage & présence'),
          subtitle: tr('Arrivées, départs, retards et absences — la borne et les téléphones fonctionnent hors ligne'),
          actions: [
            if (manage) OutlinedButton.icon(onPressed: () => editPunch(context), icon: const Icon(Icons.edit_calendar_outlined), label: Text(tr('Saisir un pointage'))),
            if (s.can('pointage.kiosk') || manage)
              FilledButton.icon(
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(fullscreenDialog: true, builder: (_) => const KioskScreen())),
                icon: const Icon(Icons.tablet_android),
                label: Text(tr('Ouvrir la borne')),
              ),
          ],
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }
}

class _TodayTab extends StatefulWidget {
  const _TodayTab();

  @override
  State<_TodayTab> createState() => _TodayTabState();
}

class _TodayTabState extends State<_TodayTab> {
  String _filter = '';
  String _q = '';
  Map<String, String?> _f = {};
  Timer? _tick;

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(minutes: 1), (_) => mounted ? setState(() {}) : null);
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pt_punches'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('pt_badges'),
        builder: (context, _, _) {
          final now = ptClock();
          final allRows = [for (final b in ptBadges()) (b, ptDay(b, now))];
          final q = _q.toLowerCase();
          final rows = allRows
              .where((r) =>
                  (q.isEmpty || '${r.$1['name']} ${r.$1['matricule'] ?? ''} ${r.$1['department'] ?? ''}'.toLowerCase().contains(q)) &&
                  (_f['department'] == null || '${r.$1['department'] ?? ''}' == _f['department']))
              .toList();
          int count(Set<String> st) => rows.where((r) => st.contains(r.$2['status'])).length;
          final groups = {
            'present': ('Sur place', {'in', 'late'}, YColors.success),
            'out': ('Partis', {'out'}, YColors.muted),
            'late': ('En retard', {'late'}, YColors.warning),
            'absent': ('Absents', {'absent'}, YColors.danger),
            'upcoming': ('Pas encore arrivés', {'upcoming'}, YColors.muted),
            'leave': ('En congé / repos', {'leave', 'holiday', 'rest'}, YColors.blue),
            'incomplete': ('À vérifier', {'incomplete'}, YColors.warning),
          };
          final shown = _filter.isEmpty || !groups.containsKey(_filter) ? rows : rows.where((r) => groups[_filter]!.$2.contains(r.$2['status'])).toList();
          final visibleGroups = {for (final g in groups.entries) if (count(g.value.$2) > 0 || g.key == 'absent' || g.key == 'present' || g.key == _filter) g.key: g.value};
          return ListView(padding: const EdgeInsets.all(16), children: [
            FilterBar(
              padding: EdgeInsets.zero,
              onSearch: (v) => setState(() => _q = v),
              search: _q,
              searchHint: 'Nom, matricule, service…',
              statuses: {'': 'Tous', for (final g in visibleGroups.entries) g.key: g.value.$1},
              status: _filter,
              onStatus: (v) => setState(() => _filter = v),
              statusCounts: {'': rows.length, for (final g in visibleGroups.entries) g.key: count(g.value.$2)},
              filters: [FilterSpec('department', 'Service', filterOptions(allRows.map((r) => r.$1), 'department'), icon: Icons.apartment_outlined)],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: shown.length,
              countLabel: 'salarié',
              onReset: () => setState(() => (_q = '', _filter = '', _f = {})),
            ),
            const SizedBox(height: 12),
            if (allRows.isEmpty) EmptyState(icon: Icons.fingerprint, title: tr('Aucun badge'), message: tr('Les badges apparaissent avec les fiches salariés (module RH).')),
            for (final (b, d) in shown)
              Card(
                child: ListTile(
                  leading: Stack(clipBehavior: Clip.none, children: [
                    InitialsAvatar('${b['name']}', size: 38),
                    Positioned(
                      right: -2,
                      bottom: -2,
                      child: Container(
                        width: 12,
                        height: 12,
                        decoration: BoxDecoration(color: ptStatusColor(d['status']), shape: BoxShape.circle, border: Border.all(color: Theme.of(context).colorScheme.surface, width: 2)),
                      ),
                    ),
                  ]),
                  title: Text(tr('${b['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                  subtitle: Text(tr([
                    if (b['department'] != null) '${b['department']}',
                    if (d['plan'] != null) 'Prévu ${d['plan']['start']} – ${d['plan']['end']}',
                    if (d['first_in'] != null) 'Arrivé à ${d['first_in']}',
                    if (d['last_out'] != null && d['open'] != true) 'parti à ${d['last_out']}',
                    if ((d['late'] as int) > 0) 'retard ${d['late']} min',
                    if ((d['worked'] as int) > 0) hm(d['worked'] as int),
                  ].join(' · '))),
                  trailing: StatusChip(ptStatuses[d['status']] ?? '${d['status']}', color: ptStatusColor(d['status'])),
                  onTap: () => showPunchDay(context, b, now),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

/// Pointages d'un salarié pour un jour, avec correction par le responsable.
Future<void> showPunchDay(BuildContext context, Map badge, DateTime day) async {
  final manage = context.read<Session>().can('pointage.manage');
  await showDialog(
    context: context,
    builder: (c) => ValueListenableBuilder(
      valueListenable: LocalDb.listen('pt_punches'),
      builder: (c, _, _) {
        final d = ptDay(badge, day);
        return YAlertDialog(
          title: Text(tr('${badge['name']} — ${dateFr(isoDate(day))}')),
          content: SizedBox(
            width: 460,
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Wrap(spacing: 8, children: [
                StatusChip(ptStatuses[d['status']] ?? '', color: ptStatusColor(d['status'])),
                if (d['plan'] != null) Text(tr('Horaire ${d['plan']['start']} – ${d['plan']['end']}')),
                Text(tr('Travaillé ${hm(d['worked'] as int)}')),
              ]),
              const SizedBox(height: 8),
              if ((d['punches'] as List).isEmpty) Text(tr('Aucun pointage.'), style: TextStyle(color: Theme.of(c).hintColor)),
              for (final p in (d['punches'] as List))
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(p['kind'] == 'in' ? Icons.login : Icons.logout, color: p['kind'] == 'in' ? YColors.success : YColors.blue),
                  title: Text(tr('${p['kind'] == 'in' ? 'Arrivée' : 'Départ'} à ${'${p['at']}'.substring(11, 16)}')),
                  subtitle: Text(tr([
                    ptSources[p['source']] ?? '${p['source'] ?? 'Envoi en attente'}',
                    if (p['late_sync'] == true) 'reçu en différé (appareil hors ligne)',
                    if (p['note'] != null) '${p['note']}',
                  ].join(' · '))),
                  trailing: manage
                      ? IconButton(
                          icon: const Icon(Icons.delete_outline),
                          onPressed: () async {
                            if (await confirm(c, tr('Supprimer ce pointage ?'), tr('Le calcul de la journée sera mis à jour.'), danger: true)) {
                              if (c.mounted) await c.read<SyncEngine>().remove('pt_punches', '${p['id']}');
                            }
                          },
                        )
                      : null,
                ),
            ]),
          ),
          actions: [
            if (manage) TextButton.icon(onPressed: () => editPunch(c, employeeId: '${badge['id']}', day: day), icon: const Icon(Icons.add), label: Text(tr('Ajouter'))),
            TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Fermer'))),
          ],
        );
      },
    ),
  );
}

/// Saisie manuelle par le responsable (oubli de badge, panne de la borne…).
Future<void> editPunch(BuildContext context, {String? employeeId, DateTime? day}) async {
  final sync = context.read<SyncEngine>();
  final badges = ptBadges();
  if (badges.isEmpty) return toast(context, tr('Aucun badge actif.'), error: true);
  var emp = employeeId ?? '${badges.first['id']}';
  var date = day ?? ptClock();
  var time = TimeOfDay.now();
  var kind = ptNextKind(emp);
  final note = TextEditingController();
  final ok = await showFormDialog<bool>(context, title: 'Saisir un pointage', builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            DropdownButtonFormField<String>(
              initialValue: emp,
              isExpanded: true,
              decoration: InputDecoration(labelText: tr('Salarié')),
              items: [for (final b in badges) DropdownMenuItem(value: '${b['id']}', child: Text(tr('${b['name']}')))],
              onChanged: (v) => set(() => emp = v!),
            ),
            const SizedBox(height: 8),
            SegmentedButton<String>(segments: [ButtonSegment(value: 'in', label: Text(tr('Arrivée')), icon: Icon(Icons.login)), ButtonSegment(value: 'out', label: Text(tr('Départ')), icon: Icon(Icons.logout))], selected: {kind}, onSelectionChanged: (v) => set(() => kind = v.first)),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final d = await pickDate(c, initialDate: date, firstDate: DateTime(2020), lastDate: ptClock());
                    if (d != null) set(() => date = d);
                  },
                  icon: const Icon(Icons.event),
                  label: Text(tr(dateFr(isoDate(date)))),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: () async {
                    final t = await showTimePicker(context: c, initialTime: time);
                    if (t != null) set(() => time = t);
                  },
                  icon: const Icon(Icons.schedule),
                  label: Text(tr('${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}')),
                ),
              ),
            ]),
            TextField(controller: note, decoration: InputDecoration(labelText: tr('Motif *'), hintText: tr('Badge oublié, panne de la borne, mission extérieure…'))),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true) return;
  if (note.text.trim().isEmpty) {
    if (context.mounted) toast(context, tr('Indiquez le motif de la saisie.'), error: true);
    return;
  }
  final at = '${isoDate(date)} ${time.hour.toString().padLeft(2, '0')}:${time.minute.toString().padLeft(2, '0')}:00';
  await sync.save('pt_punches', {'id': newId(), 'employee_id': emp, 'at': at, 'kind': kind, 'source': 'manual', 'note': note.text.trim()});
}

// ---------- Feuille de présence (en ligne : calcul du serveur) ----------

class _SheetTab extends StatefulWidget {
  const _SheetTab();

  @override
  State<_SheetTab> createState() => _SheetTabState();
}

class _SheetTabState extends State<_SheetTab> {
  String _range = 'week';
  String _status = '';
  String _q = '';
  Map<String, String?> _f = {};
  Map<String, dynamic>? _data;
  Object? _error;
  bool _busy = false;

  (DateTime, DateTime) get _period {
    final n = ptClock();
    final today = DateTime(n.year, n.month, n.day);
    final monday = today.subtract(Duration(days: today.weekday - 1));
    return switch (_range) {
      'last_week' => (monday.subtract(const Duration(days: 7)), monday.subtract(const Duration(days: 1))),
      'month' => (DateTime(n.year, n.month, 1), today),
      'last_month' => (DateTime(n.year, n.month - 1, 1), DateTime(n.year, n.month, 0)),
      _ => (monday, today),
    };
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final (a, b) = _period;
    try {
      final r = await context.read<Session>().api.get('pointage/report', query: {'from': isoDate(a), 'to': isoDate(b)});
      if (mounted) setState(() => (_data = asMap(r['data']), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _export() async {
    final (a, b) = _period;
    try {
      final bytes = await context.read<Session>().api.download('pointage/report/export', query: {'from': isoDate(a), 'to': isoDate(b)});
      final where = await saveFile('presence_${isoDate(a)}_${isoDate(b)}.xlsx', bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      if (mounted) toast(context, tr('Feuille enregistrée${where.isEmpty ? '' : ' : $where'}'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _payroll() async {
    final api = context.read<Session>().api;
    List runs;
    try {
      final r = await api.get('paie/runs');
      runs = ((r is Map ? (r['runs'] ?? r['data']) : r) as List).where((x) => x['status'] == 'draft').toList();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!mounted) return;
    if (runs.isEmpty) return toast(context, tr('Aucune paie en brouillon : créez d\'abord la paie du mois dans « Paie ».'), error: true);
    final run = await showDialog<Map>(
      context: context,
      builder: (c) => SimpleDialog(title: Text(tr('Envoyer les heures supplémentaires dans la paie de…')), children: [
        for (final r in runs) SimpleDialogOption(onPressed: () => Navigator.pop(c, r as Map), child: Text(tr('${r['label'] ?? r['period']}'))),
      ]),
    );
    if (run == null) return;
    setState(() => _busy = true);
    try {
      final r = await api.post('pointage/payroll', {'run_id': run['id']});
      final done = (r['data'] as List).where((x) => _ot(asMap(x)) > 0).length;
      if (mounted) toast(context, done == 0 ? 'Aucune heure supplémentaire sur ce mois.' : 'Heures supplémentaires ajoutées aux bulletins de $done salarié(s). Les absences restent à traiter dans la paie.');
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final allRows = [for (final e in (_data?['employees'] as List? ?? [])) asMap(e)];
    int tot(Map<String, dynamic> e, String k) => ((asMap(e['totals'])[k] as num?) ?? 0).toInt();
    bool base(Map<String, dynamic> e) =>
        (_q.isEmpty || '${e['name']} ${e['matricule'] ?? ''} ${e['department'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
        (_f['department'] == null || '${e['department'] ?? ''}' == _f['department']);
    bool st(Map<String, dynamic> e, String k) => switch (k) {
          'absent' => tot(e, 'absences') > 0,
          'late' => tot(e, 'late_count') > 0,
          'incomplete' => tot(e, 'incomplete') > 0,
          'ot' => _ot(asMap(e['totals'])) > 0,
          _ => true,
        };
    final rows = allRows.where((e) => base(e) && st(e, _status)).toList();
    const stKeys = ['', 'absent', 'late', 'incomplete', 'ot'];
    return Column(children: [
      const SizedBox(height: 12),
      FilterBar(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
        onSearch: (v) => setState(() => _q = v),
        search: _q,
        searchHint: 'Nom du salarié…',
        statuses: const {'': 'Tous', 'absent': 'Avec absences', 'late': 'Avec retards', 'incomplete': 'À vérifier', 'ot': 'Heures sup.'},
        status: _status,
        onStatus: (v) => setState(() => _status = v),
        statusCounts: _data == null ? null : {for (final k in stKeys) k: allRows.where((e) => base(e) && st(e, k)).length},
        filters: [
          const FilterSpec('range', 'Période', {'week': 'Cette semaine', 'last_week': 'Semaine dernière', 'month': 'Ce mois', 'last_month': 'Mois dernier'}, icon: Icons.date_range_rounded),
          FilterSpec('department', 'Service', filterOptions(allRows, 'department'), icon: Icons.apartment_outlined),
        ],
        values: {..._f, 'range': _range},
        onFilter: (k, v) {
          if (k == 'range') {
            if (v == null || v == _range) return;
            setState(() => (_range = v, _data = null));
            _load();
            return;
          }
          setState(() => _f = {..._f, k: v});
        },
        count: _data == null ? null : rows.length,
        countLabel: 'salarié',
        onReset: () => setState(() => (_q = '', _status = '', _f = {})),
        trailing: [
          OutlinedButton.icon(onPressed: _export, icon: const Icon(Icons.download_outlined), label: Text(tr('Excel'))),
          if (s.hasModule('paie') && s.can('pointage.manage') && s.can('paie.manage'))
            FilledButton.tonalIcon(onPressed: _busy ? null : _payroll, icon: const Icon(Icons.payments_outlined), label: Text(tr('Envoyer les heures sup. en paie'))),
        ],
      ),
      Expanded(
        child: _data == null
            ? (_error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
            : rows.isEmpty
                ? EmptyState(icon: Icons.fingerprint, title: tr('Aucun salarié'), message: tr('Aucun badge actif sur la période.'))
                : ResponsiveList<Map<String, dynamic>>(
                    items: rows,
                    columns: const ['Salarié', 'Présent', 'Absences', 'Retards', 'Travaillé', 'HS 15 %', 'HS 50 %', 'HS nuit', 'Dim. / férié', 'À vérifier'],
                    cells: (e) {
                      final t = asMap(e['totals']);
                      int i(String k) => (t[k] as num).toInt();
                      return [
                        Text(tr('${e['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text(tr('${i('present_days')} j')),
                        Text(tr('${i('absences')}'), style: TextStyle(color: i('absences') > 0 ? YColors.danger : null, fontWeight: i('absences') > 0 ? FontWeight.w700 : null)),
                        Text(tr(i('late_count') == 0 ? '0' : '${i('late_count')} (${i('late_minutes')} min)'), style: TextStyle(color: i('late_count') > 0 ? YColors.warning : null)),
                        Text(tr(hm(i('worked')))),
                        Text(tr(i('ot15') == 0 ? '—' : hm(i('ot15')))),
                        Text(tr(i('ot50') == 0 ? '—' : hm(i('ot50')))),
                        Text(tr((t['ot_night'] ?? 0) == 0 ? '—' : hm(i('ot_night')))),
                        Text(tr(i('ot75') + ((t['ot100'] as num?) ?? 0).toInt() == 0 ? '—' : hm(i('ot75') + ((t['ot100'] as num?) ?? 0).toInt()))),
                        i('incomplete') == 0 ? const Text('') : StatusChip(tr('${i('incomplete')} jour(s)'), color: YColors.warning),
                      ];
                    },
                    mobileTile: (e) {
                      final t = asMap(e['totals']);
                      return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(tr('${e['name']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
                        Text(
                          tr('${t['present_days']} j présent · ${t['absences']} absence(s) · ${t['late_count']} retard(s) · ${hm((t['worked'] as num).toInt())}'
                          '${_ot(t) > 0 ? ' · HS ${hm(_ot(t))}' : ''}'),
                          style: TextStyle(fontSize: 13, color: Theme.of(context).hintColor),
                        ),
                      ]);
                    },
                    onTap: (e) => showDialog(context: context, builder: (_) => _EmployeeSheetDialog(employee: e)),
                  ),
      ),
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        child: Text(
          tr('Heures supplémentaires par semaine au-delà de ${_data?['weekly_hours'] ?? 40} h : 6 premières heures de jour à +15 %, au-delà +50 %, de nuit (21 h – 5 h) +75 % ; dimanche et jours fériés +75 % le jour, +100 % la nuit. Elles sont reprises automatiquement dans la paie du mois.'),
          style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
        ),
      ),
    ]);
  }
}

class _EmployeeSheetDialog extends StatelessWidget {
  const _EmployeeSheetDialog({required this.employee});

  final Map<String, dynamic> employee;

  @override
  Widget build(BuildContext context) {
    final days = [for (final d in (employee['days'] as List)) asMap(d)].where((d) => d['status'] != 'upcoming' && d['status'] != 'off').toList();
    return YAlertDialog(
      title: Text(tr('${employee['name']}')),
      content: SizedBox(
        width: 560,
        height: 480,
        child: ListView(children: [
          for (final d in days)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text(tr('${dateFr(d['date'])}${d['holiday'] != null ? ' · ${d['holiday']}' : ''}${d['leave'] != null ? ' · ${leaveTypes[d['leave']] ?? d['leave']}' : ''}')),
              subtitle: Text(tr([
                if (d['first_in'] != null) '${d['first_in']} › ${d['last_out'] ?? '?'}',
                if ((d['late'] as num) > 0) 'retard ${d['late']} min',
                if ((d['early'] as num) > 0) 'départ anticipé ${d['early']} min',
                if ((d['punches'] as List).any((p) => p['late_sync'] == true)) 'pointage reçu en différé',
              ].join(' · '))),
              trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                if ((d['worked'] as num) > 0) Padding(padding: const EdgeInsets.only(right: 8), child: Text(tr(hm((d['worked'] as num).toInt())))),
                StatusChip(ptStatuses[d['status']] ?? '${d['status']}', color: ptStatusColor(d['status'])),
              ]),
            ),
        ]),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Fermer')))],
    );
  }
}

// ---------- Pointages d'un jour ----------

class _PunchesTab extends StatefulWidget {
  const _PunchesTab();

  @override
  State<_PunchesTab> createState() => _PunchesTabState();
}

class _PunchesTabState extends State<_PunchesTab> {
  DateTime _day = ptClock();
  String _kind = '';
  String _q = '';
  Map<String, String?> _f = {};

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pt_punches'),
      builder: (context, _, _) {
        final date = isoDate(_day);
        final names = {for (final b in LocalDb.all('pt_badges')) b['id']: b};
        final dayRows = LocalDb.all('pt_punches').where((p) => '${p['at']}'.startsWith(date)).toList()..sort((a, b) => '${b['at']}'.compareTo('${a['at']}'));
        bool base(Map<String, dynamic> p) {
          final b = names[p['employee_id']];
          return (_q.isEmpty || '${b?['name'] ?? ''} ${p['note'] ?? ''}'.toLowerCase().contains(_q.toLowerCase())) &&
              (_f['employee'] == null || '${p['employee_id']}' == _f['employee']) &&
              (_f['department'] == null || '${b?['department'] ?? ''}' == _f['department']) &&
              (_f['source'] == null || '${p['source'] ?? 'pending'}' == _f['source']) &&
              (_f['late_sync'] == null || p['late_sync'] == true);
        }

        bool st(Map<String, dynamic> p, String k) => k.isEmpty || p['kind'] == k;
        final rows = dayRows.where((p) => base(p) && st(p, _kind)).toList();
        return Column(children: [
          const SizedBox(height: 12),
          FilterBar(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            onSearch: (v) => setState(() => _q = v),
            search: _q,
            searchHint: 'Salarié, note…',
            statuses: const {'': 'Tous', 'in': 'Arrivées', 'out': 'Départs'},
            status: _kind,
            onStatus: (v) => setState(() => _kind = v),
            statusCounts: {for (final k in ['', 'in', 'out']) k: dayRows.where((p) => base(p) && st(p, k)).length},
            filters: [
              FilterSpec('employee', 'Salarié', filterOptions(dayRows, 'employee_id', label: (p) => '${names[p['employee_id']]?['name'] ?? '?'}'), icon: Icons.person_outline),
              FilterSpec('department', 'Service', filterOptions(names.values, 'department'), icon: Icons.apartment_outlined),
              const FilterSpec('source', 'Source', {...ptSources, 'pending': 'Envoi en attente'}, icon: Icons.devices_other_outlined),
              const FilterSpec('late_sync', 'Réception', {'yes': 'Reçus en différé'}, icon: Icons.history_toggle_off),
            ],
            values: _f,
            onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
            count: rows.length,
            countLabel: 'pointage',
            onReset: () => setState(() => (_q = '', _kind = '', _f = {})),
            trailing: [
              Row(mainAxisSize: MainAxisSize.min, children: [
                IconButton(onPressed: () => setState(() => _day = _day.subtract(const Duration(days: 1))), icon: const Icon(Icons.chevron_left)),
                TextButton(
                  onPressed: () async {
                    final d = await pickDate(context, initialDate: _day, firstDate: DateTime(2020), lastDate: ptClock());
                    if (d != null) setState(() => _day = d);
                  },
                  child: Text(tr(dateFr(date)), style: const TextStyle(fontWeight: FontWeight.w700)),
                ),
                IconButton(onPressed: isoDate(_day) == isoDate(ptClock()) ? null : () => setState(() => _day = _day.add(const Duration(days: 1))), icon: const Icon(Icons.chevron_right)),
              ]),
            ],
          ),
          Expanded(
            child: rows.isEmpty
                ? EmptyState(icon: Icons.fingerprint, title: tr('Aucun pointage'), message: tr('Les pointages de la borne et des téléphones apparaissent ici, même hors ligne.'))
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                    itemCount: rows.length,
                    separatorBuilder: (_, _) => const Divider(height: 1),
                    itemBuilder: (_, i) {
                      final p = rows[i];
                      final b = names[p['employee_id']];
                      return ListTile(
                        leading: Icon(p['kind'] == 'in' ? Icons.login : Icons.logout, color: p['kind'] == 'in' ? YColors.success : YColors.blue),
                        title: Text(tr('${'${p['at']}'.substring(11, 16)} · ${b?['name'] ?? '?'}')),
                        subtitle: Text(tr([
                          p['kind'] == 'in' ? 'Arrivée' : 'Départ',
                          ptSources[p['source']] ?? 'Envoi en attente',
                          if (p['note'] != null) '${p['note']}',
                        ].join(' · '))),
                        trailing: p['late_sync'] == true ? Tooltip(message: tr('Reçu en différé : appareil hors ligne ou heure à vérifier'), child: Icon(Icons.history_toggle_off, color: YColors.warning)) : null,
                        onTap: b == null ? null : () => showPunchDay(context, b, _day),
                      );
                    },
                  ),
          ),
        ]);
      },
    );
  }
}

// ---------- Horaires, badges et réglages ----------

class _SettingsTab extends StatefulWidget {
  const _SettingsTab();

  @override
  State<_SettingsTab> createState() => _SettingsTabState();
}

class _SettingsTabState extends State<_SettingsTab> {
  Map<String, dynamic>? _settings;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('pointage/settings');
      if (mounted) setState(() => _settings = asMap(r['data']));
    } catch (_) {}
  }

  Future<void> _editSettings() async {
    final api = context.read<Session>().api;
    final weekly = TextEditingController(text: '${_settings?['weekly_hours'] ?? 40}');
    final holidays = [for (final h in (_settings?['holidays'] as List? ?? [])) asMap(h)];
    final labels = asMap(_settings?['overtime_labels']);
    final rates = {for (final e in asMap(_settings?['overtime_rates']).entries) e.key: TextEditingController(text: number(e.value as num, decimals: 0))};
    var auto = _settings?['auto_overtime'] != false;
    final ok = await showFormDialog<bool>(context, title: 'Réglages du pointage', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              TextField(controller: weekly, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Durée hebdomadaire (heures)'), helperText: tr('40 h selon le Code du travail ; au-delà : heures supplémentaires'))),
              const SizedBox(height: 14),
              Text(tr('Majorations des heures supplémentaires (%)'), style: const TextStyle(fontWeight: FontWeight.w600)),
              const SizedBox(height: 6),
              Wrap(spacing: 10, runSpacing: 10, children: [
                for (final r in rates.entries)
                  SizedBox(
                    width: 140,
                    child: TextField(controller: r.value, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('${labels[r.key] ?? r.key}'), isDense: true, suffixText: '%')),
                  ),
              ]),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: auto,
                onChanged: (v) => set(() => auto = v),
                title: Text(tr('Reprendre automatiquement dans la paie')),
                subtitle: Text(tr('Les heures supplémentaires du mois s\'ajoutent aux bulletins à chaque calcul de la paie.')),
              ),
              const SizedBox(height: 8),
              Text(tr('Jours fériés variables de l\'année'), style: TextStyle(fontWeight: FontWeight.w600)),
              Text(tr('Les fêtes fixes et le lundi de Pâques sont déjà comptés. Ajoutez Tabaski, Ramadan, Maouloud, Ascension, Pentecôte… dès leur annonce.'), style: TextStyle(fontSize: 12, color: Theme.of(c).hintColor)),
              for (var i = 0; i < holidays.length; i++)
                ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text(tr('${holidays[i]['label'] ?? 'Jour férié'}')),
                  subtitle: Text(tr(dateFr(holidays[i]['date']))),
                  trailing: IconButton(icon: const Icon(Icons.close), onPressed: () => set(() => holidays.removeAt(i))),
                ),
              TextButton.icon(
                onPressed: () async {
                  final d = await pickDate(c, initialDate: ptClock(), firstDate: DateTime(2024), lastDate: DateTime(2100));
                  if (d == null || !c.mounted) return;
                  final l = TextEditingController();
                  final name = await showDialog<String>(
                    context: c,
                    builder: (c2) => YAlertDialog(
                      title: Text(tr('Férié du ${dateFr(isoDate(d))}')),
                      content: TextField(controller: l, autofocus: true, decoration: InputDecoration(labelText: tr('Nom'), hintText: tr('Tabaski, Korité…'))),
                      actions: [FilledButton(onPressed: () => Navigator.pop(c2, l.text.trim()), child: Text(tr('Ajouter')))],
                    ),
                  );
                  set(() => holidays.add({'date': isoDate(d), 'label': (name ?? '').isEmpty ? 'Jour férié' : name}));
                },
                icon: const Icon(Icons.add),
                label: Text(tr('Ajouter un jour férié')),
              ),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Enregistrer'))),
              ]),
            ),
          ]),
        ),
      ));
    if (ok != true) return;
    try {
      await api.put('pointage/settings', {
        'weekly_hours': toDouble(weekly.text),
        'holidays': holidays,
        'auto_overtime': auto,
        'overtime_rates': {for (final r in rates.entries) r.key: toDouble(r.value.text.replaceAll(',', '.'))},
      });
      await _load();
      if (mounted) await ptRefreshHolidays(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  Future<void> _pin(Map b) async {
    final api = context.read<Session>().api;
    final c = TextEditingController();
    final r = await showDialog<String>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr('Code PIN de ${b['name']}')),
        content: TextField(controller: c, autofocus: true, keyboardType: TextInputType.number, maxLength: 6, inputFormatters: [FilteringTextInputFormatter.digitsOnly], decoration: InputDecoration(labelText: tr('4 à 6 chiffres'), helperText: tr('Communiquez-le au salarié ; il peut le changer dans son espace.'))),
        actions: [
          if (b['pin_hash'] != null) TextButton(onPressed: () => Navigator.pop(d, 'clear'), child: Text(tr('Retirer le code'))),
          TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
          FilledButton(onPressed: () => Navigator.pop(d, 'set'), child: Text(tr('Enregistrer'))),
        ],
      ),
    );
    if (r == null) return;
    try {
      await api.put('pointage/badges/${b['id']}/pin', {'pin': r == 'clear' ? null : c.text});
      if (mounted) {
        await context.read<SyncEngine>().sync();
        if (mounted) toast(context, r == 'clear' ? 'Code retiré.' : 'Code PIN enregistré.');
      }
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pt_schedules'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('pt_badges'),
        builder: (context, _, _) {
          final schedules = LocalDb.all('pt_schedules')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
          final badges = ptBadges();
          return ListView(padding: const EdgeInsets.all(16), children: [
            SectionCard(
              title: tr('Horaires de travail'),
              trailing: TextButton.icon(onPressed: () => editSchedule(context), icon: const Icon(Icons.add, size: 18), label: Text(tr('Horaire'))),
              child: Column(children: [
                for (final s in schedules)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(s['is_default'] == true ? Icons.star : Icons.schedule, color: s['is_default'] == true ? YColors.yellow : null),
                    title: Text(tr('${s['name']}')),
                    subtitle: Text(tr(scheduleSummary(s))),
                    trailing: Text(tr('${badges.where((b) => (b['schedule_id'] ?? (s['is_default'] == true ? s['id'] : null)) == s['id']).length} salarié(s)')),
                    onTap: () => editSchedule(context, s),
                  ),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Salariés : horaire, code PIN, pointage mobile'),
              child: Column(children: [
                for (final b in badges)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: InitialsAvatar('${b['name']}', size: 36),
                    title: Text(tr('${b['name']}')),
                    subtitle: Text(tr([b['department'], b['user_id'] == null ? 'pas de compte : borne seulement' : null].where((x) => x != null).join(' · '))),
                    trailing: Wrap(spacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                      SizedBox(
                        width: 180,
                        child: DropdownButton<String?>(
                          isExpanded: true,
                          value: schedules.any((s) => s['id'] == b['schedule_id']) ? b['schedule_id'] as String? : null,
                          hint: Text(tr('Horaire par défaut')),
                          items: [
                            DropdownMenuItem<String?>(value: null, child: Text(tr('Horaire par défaut'))),
                            for (final s in schedules) DropdownMenuItem<String?>(value: '${s['id']}', child: Text(tr('${s['name']}'), overflow: TextOverflow.ellipsis)),
                          ],
                          onChanged: (v) => context.read<SyncEngine>().save('pt_badges', {'id': b['id'], 'schedule_id': v}),
                        ),
                      ),
                      Tooltip(
                        message: b['self_allowed'] != false ? 'Peut pointer depuis son téléphone' : 'Borne uniquement',
                        child: IconButton(
                          icon: Icon(b['self_allowed'] != false ? Icons.phone_iphone : Icons.phonelink_erase, color: b['self_allowed'] != false ? YColors.blue : YColors.muted),
                          onPressed: () => context.read<SyncEngine>().save('pt_badges', {'id': b['id'], 'self_allowed': b['self_allowed'] == false}),
                        ),
                      ),
                      OutlinedButton.icon(onPressed: () => _pin(b), icon: Icon(b['pin_hash'] == null ? Icons.pin_outlined : Icons.check, size: 16), label: Text(tr(b['pin_hash'] == null ? 'Code PIN' : 'PIN défini'))),
                    ]),
                  ),
              ]),
            ),
            const SizedBox(height: 12),
            SectionCard(
              title: tr('Réglages'),
              trailing: TextButton.icon(onPressed: _settings == null ? null : _editSettings, icon: const Icon(Icons.edit_outlined, size: 18), label: Text(tr('Modifier'))),
              child: _settings == null
                  ? Text(tr('Disponible en ligne.'), style: TextStyle(color: Theme.of(context).hintColor))
                  : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(tr('Durée hebdomadaire : ${_settings!['weekly_hours']} h')),
                      const SizedBox(height: 6),
                      Text(tr('Jours fériés ${ptClock().year} : ${(_settings!['year_holidays'] as Map).entries.map((e) => '${dateFr(e.key)} ${e.value}').join(' · ')}'), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
                    ]),
            ),
            const SizedBox(height: 12),
            Text(
              tr('Borne : installez une tablette à l\'entrée, connectez-la avec un compte au rôle « Borne de pointage » ; elle fonctionne sans Internet et envoie les pointages au retour du réseau.'),
              style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
            ),
          ]);
        },
      ),
    );
  }
}

String scheduleSummary(Map s) {
  final days = asMap(s['days']);
  final groups = <String, List<String>>{};
  for (final k in ['1', '2', '3', '4', '5', '6', '7']) {
    final d = days[k];
    if (d == null) continue;
    groups.putIfAbsent('${d['start']}–${d['end']}${(d['break'] ?? 0) > 0 ? ' (pause ${d['break']} min)' : ''}', () => []).add(ptDayNames[k]!.substring(0, 3));
  }
  return [for (final e in groups.entries) '${e.value.join(', ')} ${e.key}', 'tolérance ${s['tolerance'] ?? 10} min'].join(' · ');
}

Future<void> editSchedule(BuildContext context, [Map<String, dynamic>? s]) async {
  final sync = context.read<SyncEngine>();
  final name = TextEditingController(text: '${s?['name'] ?? ''}');
  final tol = TextEditingController(text: '${s?['tolerance'] ?? 10}');
  final days = <String, Map<String, dynamic>?>{
    for (final k in ['1', '2', '3', '4', '5', '6', '7'])
      k: s == null ? (int.parse(k) <= 5 ? {'start': '08:00', 'end': '17:00', 'break': 60} : null) : (asMap(s['days'])[k] == null ? null : asMap(asMap(s['days'])[k])),
  };
  var isDefault = s?['is_default'] == true;
  Future<String?> pick(BuildContext c, String v) async {
    final p = v.split(':');
    final t = await showTimePicker(context: c, initialTime: TimeOfDay(hour: int.parse(p[0]), minute: int.parse(p[1])));
    return t == null ? null : '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
  }

  final r = await showFormDialog<String>(context, title: s == null ? 'Nouvel horaire' : 'Horaire', builder: (c) => StatefulBuilder(
      builder: (c, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom'), hintText: tr('Boutique 7 h 30 – 19 h, équipe de nuit…'))),
            const SizedBox(height: 8),
            for (final k in days.keys)
              Row(children: [
                SizedBox(
                  width: 120,
                  child: CheckboxListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    controlAffinity: ListTileControlAffinity.leading,
                    title: Text(tr(ptDayNames[k]!)),
                    value: days[k] != null,
                    onChanged: (v) => set(() => days[k] = v == true ? {'start': '08:00', 'end': '17:00', 'break': 60} : null),
                  ),
                ),
                if (days[k] != null) ...[
                  TextButton(onPressed: () async {
                    final t = await pick(c, '${days[k]!['start']}');
                    if (t != null) set(() => days[k]!['start'] = t);
                  }, child: Text(tr('${days[k]!['start']}'))),
                  const Text('›'),
                  TextButton(onPressed: () async {
                    final t = await pick(c, '${days[k]!['end']}');
                    if (t != null) set(() => days[k]!['end'] = t);
                  }, child: Text(tr('${days[k]!['end']}'))),
                  const SizedBox(width: 8),
                  SizedBox(
                    width: 110,
                    child: DropdownButton<int>(
                      isExpanded: true,
                      value: [0, 30, 45, 60, 90, 120].contains((days[k]!['break'] as num?)?.toInt()) ? (days[k]!['break'] as num?)?.toInt() : 60,
                      items: [for (final m in [0, 30, 45, 60, 90, 120]) DropdownMenuItem(value: m, child: Text(tr(m == 0 ? 'sans pause' : 'pause $m min')))],
                      onChanged: (v) => set(() => days[k]!['break'] = v),
                    ),
                  ),
                ] else
                  Text(tr('Repos'), style: TextStyle(color: Theme.of(c).hintColor)),
              ]),
            TextField(controller: tol, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Retard toléré (minutes)'))),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Horaire par défaut')), subtitle: Text(tr('Pour les salariés sans horaire choisi')), value: isDefault, onChanged: (v) => set(() => isDefault = v)),
          ]),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              if (s != null && s['is_default'] != true) TextButton(onPressed: () => Navigator.pop(c, 'delete'), child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger))),
              TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
              FilledButton(onPressed: () => Navigator.pop(c, 'save'), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  if (r == 'delete') await sync.remove('pt_schedules', '${s!['id']}');
  if (r != 'save' || name.text.trim().isEmpty) return;
  await sync.save('pt_schedules', {'id': s?['id'] ?? newId(), 'name': name.text.trim(), 'days': days, 'tolerance': int.tryParse(tol.text) ?? 10, 'is_default': isDefault});
}

// ======================================================================
// Borne de pointage (tablette à l'entrée) — hors ligne
// ======================================================================

class KioskScreen extends StatefulWidget {
  const KioskScreen({super.key, this.embedded = false});

  /// Ouverte depuis le menu (compte « borne ») : pas de bouton de fermeture.
  final bool embedded;

  @override
  State<KioskScreen> createState() => _KioskScreenState();
}

class _KioskScreenState extends State<KioskScreen> {
  String _q = '';
  late Timer _clock;
  DateTime _now = ptClock();

  @override
  void initState() {
    super.initState();
    _clock = Timer.periodic(const Duration(seconds: 15), (_) => setState(() => _now = ptClock()));
  }

  @override
  void dispose() {
    _clock.cancel();
    super.dispose();
  }

  Future<void> _punch(Map<String, dynamic> b) async {
    if (b['pin_hash'] == null) {
      await showDialog(context: context, builder: (c) => YAlertDialog(title: Text(tr('${b['name']}')), content: Text(tr('Aucun code PIN : demandez-le au service RH.')), actions: [FilledButton(onPressed: () => Navigator.pop(c), child: Text(tr('OK')))]));
      return;
    }
    final kind = ptNextKind('${b['id']}');
    final ok = await showDialog<bool>(context: context, barrierDismissible: false, builder: (_) => _PinPad(badge: b, kind: kind));
    if (ok != true || !mounted) return;
    final at = ptNow();
    try {
      await context.read<SyncEngine>().save('pt_punches', {'id': newId(), 'employee_id': b['id'], 'at': at, 'kind': kind, 'source': 'kiosk', 'device': 'Borne'});
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
      return;
    }
    if (!mounted) return;
    final d = ptDay(b, ptClock());
    final first = '${b['name']}'.split(' ').length > 1 ? '${b['name']}'.split(' ').sublist(1).join(' ') : '${b['name']}';
    await showDialog(
      context: context,
      builder: (c) {
        Timer(const Duration(seconds: 3), () => Navigator.of(c).maybePop());
        return YAlertDialog(
          icon: Icon(kind == 'in' ? Icons.waving_hand_outlined : Icons.check_circle_outline, size: 56, color: YColors.success),
          title: Text(tr(kind == 'in' ? 'Bonjour $first' : 'Au revoir $first'), textAlign: TextAlign.center),
          content: Text(
            tr([
              '${kind == 'in' ? 'Arrivée' : 'Départ'} enregistrée à ${at.substring(11, 16)}',
              if (kind == 'in' && (d['late'] as int) > 0) 'Retard de ${d['late']} min',
              if (kind == 'out') 'Temps de présence aujourd\'hui : ${hm(d['worked'] as int)}',
            ].join('\n')),
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 18),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final sync = context.watch<SyncEngine>();
    final time = '${_now.hour.toString().padLeft(2, '0')}:${_now.minute.toString().padLeft(2, '0')}';
    final body = ValueListenableBuilder(
      valueListenable: LocalDb.listen('pt_badges'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('pt_punches'),
        builder: (context, _, _) {
          final badges = ptBadges().where((b) => _q.isEmpty || '${b['name']} ${b['matricule'] ?? ''}'.toLowerCase().contains(_q)).toList();
          final w = MediaQuery.sizeOf(context).width;
          final cols = w > 1100 ? 5 : (w > 800 ? 4 : (w > 520 ? 3 : 2));
          return Column(children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.fromLTRB(24, 20, 24, 20),
              color: YColors.night,
              child: Column(children: [
                Text(tr(time), style: const TextStyle(color: Colors.white, fontSize: 54, fontWeight: FontWeight.w800, letterSpacing: 2)),
                Text(tr(DateFormat('EEEE d MMMM y', I18n.dateLocale).format(_now)), style: const TextStyle(color: Colors.white70, fontSize: 16)),
                const SizedBox(height: 4),
                Text(tr('Touchez votre nom puis saisissez votre code'), style: TextStyle(color: YColors.yellow, fontSize: 15)),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: SearchField(hint: tr('Rechercher mon nom…'), onChanged: (v) => setState(() => _q = v.toLowerCase())),
            ),
            Expanded(
              child: badges.isEmpty
                  ? EmptyState(icon: Icons.fingerprint, title: tr('Aucun salarié'), message: tr('Les badges arrivent à la première synchronisation.'))
                  : GridView.count(
                      padding: const EdgeInsets.all(16),
                      crossAxisCount: cols,
                      mainAxisSpacing: 12,
                      crossAxisSpacing: 12,
                      childAspectRatio: cols <= 2 ? 1.15 : 1.5,
                      children: [
                        for (final b in badges)
                          Builder(builder: (context) {
                            final inside = ptNextKind('${b['id']}') == 'out';
                            return Material(
                              color: inside ? YColors.success.withValues(alpha: 0.1) : Theme.of(context).cardColor,
                              borderRadius: BorderRadius.circular(16),
                              child: InkWell(
                                borderRadius: BorderRadius.circular(16),
                                onTap: () => _punch(b),
                                child: Padding(
                                  padding: const EdgeInsets.all(12),
                                  child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                                    CircleAvatar(
                                      radius: 22,
                                      backgroundColor: inside ? YColors.success : YColors.blue.withValues(alpha: 0.15),
                                      child: Text(tr('${b['name']}'.trim().split(' ').map((p) => p.isEmpty ? '' : p[0]).take(2).join()), style: TextStyle(color: inside ? Colors.white : YColors.blue, fontWeight: FontWeight.w700)),
                                    ),
                                    const SizedBox(height: 8),
                                    Text(tr('${b['name']}'), textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700)),
                                    Text(tr(inside ? 'Sur place · toucher pour partir' : 'Toucher pour arriver'), textAlign: TextAlign.center, maxLines: 2, style: TextStyle(fontSize: 12, color: inside ? YColors.success : Theme.of(context).hintColor)),
                                  ]),
                                ),
                              ),
                            );
                          }),
                      ],
                    ),
            ),
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(
                tr(sync.pendingCount > 0 ? '${sync.pendingCount} pointage(s) en attente d\'envoi — ils partiront au retour du réseau' : 'Tous les pointages sont envoyés'),
                style: TextStyle(fontSize: 12, color: sync.pendingCount > 0 ? YColors.warning : Theme.of(context).hintColor),
              ),
            ),
          ]);
        },
      ),
    );
    if (widget.embedded) return body;
    return Scaffold(
      appBar: AppBar(title: Text(tr('Borne de pointage')), backgroundColor: YColors.night, foregroundColor: Colors.white),
      body: body,
    );
  }
}

class _PinPad extends StatefulWidget {
  const _PinPad({required this.badge, required this.kind});

  final Map<String, dynamic> badge;
  final String kind;

  @override
  State<_PinPad> createState() => _PinPadState();
}

class _PinPadState extends State<_PinPad> {
  String _pin = '';
  String? _error;
  int _tries = 0;

  void _key(String k) {
    setState(() {
      _error = null;
      if (k == '⌫') {
        if (_pin.isNotEmpty) _pin = _pin.substring(0, _pin.length - 1);
      } else if (_pin.length < 6) {
        _pin += k;
      }
    });
    if (_pin.length >= 4 && ptPinHash('${widget.badge['id']}', _pin) == widget.badge['pin_hash']) {
      Navigator.pop(context, true);
    } else if (_pin.length == 6) {
      setState(() {
        _tries++;
        _pin = '';
        _error = _tries >= 3 ? 'Code incorrect. Adressez-vous au responsable.' : 'Code incorrect';
      });
      if (_tries >= 3) Future.delayed(const Duration(seconds: 2), () => mounted ? Navigator.pop(context, false) : null);
    }
  }

  @override
  Widget build(BuildContext context) {
    Widget btn(String k) => Padding(
          padding: const EdgeInsets.all(6),
          child: SizedBox(
            width: 72,
            height: 60,
            child: k.isEmpty
                ? const SizedBox()
                : FilledButton.tonal(onPressed: () => _key(k), child: k == '⌫' ? const Icon(Icons.backspace_outlined) : Text(tr(k), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700))),
          ),
        );
    return Focus(
      autofocus: true,
      onKeyEvent: (_, e) {
        if (e is! KeyDownEvent) return KeyEventResult.ignored;
        final c = e.character;
        if (c != null && RegExp(r'^[0-9]$').hasMatch(c)) {
          _key(c);
          return KeyEventResult.handled;
        }
        if (e.logicalKey == LogicalKeyboardKey.backspace) {
          _key('⌫');
          return KeyEventResult.handled;
        }
        return KeyEventResult.ignored;
      },
      child: _dialog(context, btn),
    );
  }

  Widget _dialog(BuildContext context, Widget Function(String) btn) {
    return YAlertDialog(
      title: Column(children: [
        Text(tr('${widget.badge['name']}')),
        Text(tr(widget.kind == 'in' ? 'Arrivée' : 'Départ'), style: TextStyle(fontSize: 14, color: widget.kind == 'in' ? YColors.success : YColors.blue)),
      ]),
      content: Column(mainAxisSize: MainAxisSize.min, children: [
        SizedBox(
          height: 40,
          child: _pin.isEmpty
              ? Text(tr('Votre code'), style: TextStyle(fontSize: 24, color: Theme.of(context).hintColor))
              : Row(mainAxisSize: MainAxisSize.min, children: [for (var i = 0; i < _pin.length; i++) const Padding(padding: EdgeInsets.all(6), child: Icon(Icons.circle, size: 16))]),
        ),
        SizedBox(height: 22, child: _error == null ? null : Text(tr(_error!), style: const TextStyle(color: YColors.danger))),
        for (final row in [['1', '2', '3'], ['4', '5', '6'], ['7', '8', '9'], ['', '0', '⌫']]) Row(mainAxisSize: MainAxisSize.min, children: [for (final k in row) btn(k)]),
      ]),
      actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: Text(tr('Annuler')))],
    );
  }
}

// ======================================================================
// Pointage depuis l'espace salarié
// ======================================================================

class SelfPunchCard extends StatelessWidget {
  const SelfPunchCard({super.key, required this.employeeId});

  final String employeeId;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('pt_punches'),
      builder: (context, _, _) => ValueListenableBuilder(
        valueListenable: LocalDb.listen('pt_badges'),
        builder: (context, _, _) {
          final b = LocalDb.get('pt_badges', employeeId);
          if (b == null) return const SizedBox();
          final d = ptDay(b, ptClock());
          final kind = ptNextKind(employeeId);
          final allowed = b['self_allowed'] != false && context.read<Session>().can('pointage.self');
          return SectionCard(
            title: tr('Pointage du jour'),
            trailing: TextButton.icon(onPressed: () => _setPin(context), icon: const Icon(Icons.pin_outlined, size: 18), label: Text(tr(b['pin_hash'] == null ? 'Créer mon code borne' : 'Changer mon code borne'))),
            child: Wrap(spacing: 16, runSpacing: 10, crossAxisAlignment: WrapCrossAlignment.center, children: [
              StatusChip(ptStatuses[d['status']] ?? '', color: ptStatusColor(d['status'])),
              if (d['plan'] != null) Text(tr('Horaire ${d['plan']['start']} – ${d['plan']['end']}')),
              if (d['first_in'] != null) Text(tr('Arrivée ${d['first_in']}${(d['late'] as int) > 0 ? ' (retard ${d['late']} min)' : ''}')),
              if (d['last_out'] != null && d['open'] != true) Text(tr('Départ ${d['last_out']}')),
              if ((d['worked'] as int) > 0) Text(tr('Présence ${hm(d['worked'] as int)}')),
              if (allowed)
                FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: kind == 'in' ? YColors.success : YColors.blue),
                  onPressed: () async {
                    try {
                      await context.read<SyncEngine>().save('pt_punches', {'id': newId(), 'employee_id': employeeId, 'at': ptNow(), 'kind': kind, 'source': 'self', 'device': 'Téléphone'});
                      if (context.mounted) toast(context, tr('${kind == 'in' ? 'Arrivée' : 'Départ'} enregistrée à ${ptNow().substring(11, 16)}.'));
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                  icon: Icon(kind == 'in' ? Icons.login : Icons.logout),
                  label: Text(tr(kind == 'in' ? 'Pointer mon arrivée' : 'Pointer mon départ')),
                )
              else
                Text(tr('Pointez à la borne de l\'entrée.'), style: TextStyle(color: Theme.of(context).hintColor)),
            ]),
          );
        },
      ),
    );
  }

  Future<void> _setPin(BuildContext context) async {
    final api = context.read<Session>().api;
    final sync = context.read<SyncEngine>();
    final c = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => YAlertDialog(
        title: Text(tr('Mon code pour la borne')),
        content: TextField(controller: c, autofocus: true, obscureText: true, keyboardType: TextInputType.number, maxLength: 6, inputFormatters: [FilteringTextInputFormatter.digitsOnly], decoration: InputDecoration(labelText: tr('4 à 6 chiffres'))),
        actions: [TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer')))],
      ),
    );
    if (ok != true) return;
    try {
      await api.put('pointage/me/pin', {'pin': c.text});
      await sync.sync();
      if (context.mounted) toast(context, tr('Code enregistré : il sera actif sur la borne à sa prochaine synchronisation.'));
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }
}
