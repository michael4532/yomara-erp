import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Planning des ressources : qui travaille sur quoi, capacité, occupation, surcharges.
///
/// Les affectations sont synchronisées (saisie hors ligne) ; la capacité exacte (horaires, congés, fériés)
/// et le temps réalisé viennent du serveur quand l'appareil est connecté (dernière réponse gardée hors ligne).

bool plCanManage(Session s) => s.can('planning.manage');

const plColors = ['#1F6FEB', '#12A150', '#8E44AD', '#E67E22', '#C0392B', '#16A085', '#D35400', '#2C3E50', '#B8860B', '#E84393'];

Color plHex(dynamic hex, [Color fallback = YColors.blue]) {
  final h = '${hex ?? ''}'.replaceAll('#', '');
  final v = h.length == 6 ? int.tryParse(h, radix: 16) : null;
  return v == null ? fallback : Color(0xFF000000 | v);
}

/// Dates à minuit UTC : pas de décalage au changement d'heure.
DateTime _utc(DateTime d) => DateTime.utc(d.year, d.month, d.day);

DateTime _d(dynamic v) => DateTime.tryParse('${'${v ?? ''}'.length >= 10 ? '$v'.substring(0, 10) : '2000-01-01'}T00:00:00Z') ?? DateTime.utc(2000);

DateTime _monday(DateTime d) => _utc(d).subtract(Duration(days: d.weekday - 1));

String _iso(DateTime d) => DateFormat('yyyy-MM-dd').format(d);

String _h(num v) {
  final d = v.toDouble();
  return '${d == d.roundToDouble() ? d.toInt() : d.toStringAsFixed(1).replaceAll('.', ',')} h';
}

/// Charge d'une personne calculée sur l'appareil (affectations locales + capacité du serveur ou semaine type).
class PlPerson {
  PlPerson(this.id, this.name);

  final int id;
  final String name;
  final days = <String, ({double cap, double planned, double actual, String? off})>{};

  double get capacity => days.values.fold(0, (s, d) => s + d.cap);
  double get planned => days.values.fold(0, (s, d) => s + d.planned);
  double get actual => days.values.fold(0, (s, d) => s + d.actual);
  int? get occupation => capacity > 0 ? (planned * 100 / capacity).round() : null;
  int get overloadDays => days.values.where((d) => d.cap > 0 && d.planned > d.cap + .001).length;
}

class PlanningScreen extends StatefulWidget {
  const PlanningScreen({super.key});

  @override
  State<PlanningScreen> createState() => _PlanningScreenState();
}

class _PlanningScreenState extends State<PlanningScreen> {
  DateTime _from = _monday(DateTime.now());
  int _weeks = 4;
  String _q = '';
  String _status = '';
  final Map<String, String?> _f = {};
  int _gen = 0;
  Map<String, dynamic>? _load;
  bool _loading = false;

  DateTime get _to => _from.add(Duration(days: _weeks * 7 - 1));

  String get _key => 'pl_load_${_iso(_from)}_$_weeks';

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  Future<void> _fetch() async {
    final cached = LocalDb.state().get(_key);
    if (cached is Map) _load = Map<String, dynamic>.from(cached);
    setState(() => _loading = true);
    try {
      final r = await context.read<Session>().api.get('planning/load', query: {'from': _iso(_from), 'to': _iso(_to)});
      final data = asMap(r['data']);
      await LocalDb.state().put(_key, data);
      await LocalDb.state().put('pl_users', [for (final u in data['users'] as List) {'id': u['user_id'], 'name': u['name']}]);
      if (mounted) setState(() => _load = data);
    } catch (_) {
      // hors ligne : dernière réponse ou semaine type
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _move(int weeks) {
    setState(() {
      _from = _from.add(Duration(days: weeks * 7));
      _load = null;
    });
    _fetch();
  }

  List<PlPerson> _people(List<Map<String, dynamic>> assignments) {
    final server = {for (final u in (_load?['users'] as List? ?? const [])) (u['user_id'] as num).toInt(): asMap(u)};
    final users = server.isNotEmpty
        ? [for (final u in server.values) (id: (u['user_id'] as num).toInt(), name: '${u['name']}')]
        : [for (final u in (LocalDb.state().get('pl_users') as List? ?? const [])) (id: (u['id'] as num).toInt(), name: '${u['name']}')];
    // Personnes affectées absentes de la liste (ex. hors ligne au premier lancement)
    for (final a in assignments) {
      final id = (a['user_id'] as num?)?.toInt();
      if (id != null && !users.any((u) => u.id == id)) users.add((id: id, name: '${a['user_name'] ?? '#$id'}'));
    }
    final out = <PlPerson>[];
    for (final u in users) {
      final p = PlPerson(u.id, u.name);
      final sd = asMapOrNull(server[u.id]?['days']);
      for (var i = 0; i < _weeks * 7; i++) {
        final day = _from.add(Duration(days: i));
        final k = _iso(day);
        final s = asMapOrNull(sd?[k]);
        final cap = s != null ? toDouble(s['capacity']) : (day.weekday <= 5 ? 8.0 : 0.0);
        var planned = 0.0;
        if (cap > 0) {
          for (final a in assignments) {
            if ((a['user_id'] as num?)?.toInt() == u.id && '${a['start_date']}'.compareTo(k) <= 0 && '${a['end_date']}'.compareTo(k) >= 0) {
              planned += toDouble(a['hours_per_day']).clamp(0, cap).toDouble();
            }
          }
        }
        p.days[k] = (cap: cap, planned: planned, actual: s != null ? toDouble(s['actual']) : 0, off: s?['off'] as String?);
      }
      out.add(p);
    }
    return out;
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final narrow = MediaQuery.of(context).size.width < 700;
    final hint = Theme.of(context).hintColor;
    return Column(children: [
      PageHeader(
        title: tr('Planning des ressources'),
        subtitle: tr('Qui travaille sur quoi, capacité, congés et taux d\'occupation de l\'équipe'),
        actions: [
          if (plCanManage(session)) IconButton(tooltip: tr('Semaine type'), onPressed: () => planningSettings(context).then((_) => _fetch()), icon: const Icon(Icons.tune)),
          if (plCanManage(session)) FilledButton.icon(onPressed: () => editAssignment(context, start: _from), icon: const Icon(Icons.add), label: Text(tr('Affecter'))),
        ],
      ),
      Padding(
        padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
        child: Wrap(spacing: 10, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          IconButton.outlined(tooltip: tr('Précédent'), onPressed: () => _move(-1), icon: const Icon(Icons.chevron_left)),
          OutlinedButton(onPressed: () => setState(() { _from = _monday(DateTime.now()); _fetch(); }), child: Text(tr('Aujourd\'hui'))),
          IconButton.outlined(tooltip: tr('Suivant'), onPressed: () => _move(1), icon: const Icon(Icons.chevron_right)),
          Text(tr('${DateFormat('d MMM', I18n.dateLocale).format(_from)} – ${DateFormat('d MMM yyyy', I18n.dateLocale).format(_to)}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
          SegmentedButton<int>(
            segments: [ButtonSegment(value: 2, label: Text(tr('2 sem.'))), ButtonSegment(value: 4, label: Text(tr('4 sem.'))), ButtonSegment(value: 8, label: Text(tr('8 sem.')))],
            selected: {_weeks},
            showSelectedIcon: false,
            onSelectionChanged: (v) {
              setState(() {
                _weeks = v.first;
                _load = null;
              });
              _fetch();
            },
          ),
          if (_loading) const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
          if (!_loading && _load == null) Text(tr('Hors ligne : semaine type de 40 h'), style: TextStyle(fontSize: 12, color: hint)),
        ]),
      ),
      const SizedBox(height: 10),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('pl_assignments'),
          builder: (context, _, _) {
            final from = _iso(_from), to = _iso(_to);
            final all = LocalDb.hasEntity('pl_assignments') ? LocalDb.all('pl_assignments') : <Map<String, dynamic>>[];
            final inRange = all.where((a) => '${a['start_date']}'.compareTo(to) <= 0 && '${a['end_date']}'.compareTo(from) >= 0).toList();
            final allPeople = _people(inRange);
            // Filtres sur les affectations (statut, projet, client) puis sur les personnes (personne, charge, recherche)
            final aFilter = _f['a_status'] != null || _f['project'] != null || _f['partner'] != null;
            final shownA = inRange
                .where((a) => _f['a_status'] == null || (a['status'] == 'tentative' ? 'tentative' : 'confirmed') == _f['a_status'])
                .where((a) => _f['project'] == null || '${a['project_id']}' == _f['project'])
                .where((a) => _f['partner'] == null || '${a['partner_id']}' == _f['partner'])
                .toList();
            bool loadMatch(PlPerson p, String st) => switch (st) {
                  'overload' => p.overloadDays > 0,
                  'available' => p.capacity > p.planned + .001,
                  'unassigned' => p.planned <= 0,
                  _ => true,
                };
            final base = allPeople
                .where((p) => _f['user'] == null || '${p.id}' == _f['user'])
                .where((p) => !aFilter || shownA.any((a) => (a['user_id'] as num?)?.toInt() == p.id))
                .where((p) => _q.isEmpty || p.name.toLowerCase().contains(_q) || shownA.any((a) => (a['user_id'] as num?)?.toInt() == p.id && '${a['title']} ${a['partner_name'] ?? ''}'.toLowerCase().contains(_q)))
                .toList();
            final people = base.where((p) => loadMatch(p, _status)).toList();
            const loadStatuses = {'': 'Tous', 'overload': 'En surcharge', 'available': 'Disponibles', 'unassigned': 'Sans affectation'};
            String projectName(Map<String, dynamic> a) => '${LocalDb.get('prj_projects', a['project_id'] as String?)?['name'] ?? a['project_id']}';
            final filterBar = FilterBar(
              key: ValueKey(_gen),
              padding: const EdgeInsets.only(bottom: 12),
              onSearch: (v) => setState(() => _q = v.toLowerCase()),
              search: _q,
              searchHint: 'Personne, projet, client…',
              statuses: loadStatuses,
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in loadStatuses.keys) k: allPeople.where((p) => loadMatch(p, k)).length},
              filters: [
                FilterSpec('user', 'Personne', {for (final p in (allPeople.toList()..sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase())))) '${p.id}': p.name}, icon: Icons.person_outline),
                FilterSpec('project', 'Projet', filterOptions(inRange, 'project_id', label: projectName), icon: Icons.work_outline),
                FilterSpec('partner', 'Client', filterOptions(inRange, 'partner_id', label: (a) => '${a['partner_name'] ?? a['partner_id']}'), icon: Icons.business_outlined),
                const FilterSpec('a_status', 'Affectation', {'confirmed': 'Confirmée', 'tentative': 'Option (à confirmer)'}, icon: Icons.event_available_outlined),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f[k] = v),
              count: people.length,
              countLabel: 'personne',
              onReset: () => setState(() {
                _q = '';
                _status = '';
                _f.clear();
                _gen++;
              }),
            );
            final cap = people.fold(0.0, (s, p) => s + p.capacity), planned = people.fold(0.0, (s, p) => s + p.planned), actual = people.fold(0.0, (s, p) => s + p.actual);
            final occ = cap > 0 ? (planned * 100 / cap).round() : 0;
            Widget kpi(String label, String value, IconData icon, Color color) => KpiBox(
                  width: 210,
                  child: StatTile(label: label, value: value, icon: icon, color: color),
                );
            return ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24), children: [
              filterBar,
              KpiWrap(children: [
                kpi('Occupation de l\'équipe', '$occ %', Icons.speed, plLoadColor(occ)),
                kpi('Prévu / capacité', '${_h(planned)} / ${_h(cap)}', Icons.event_available, YColors.blue),
                kpi('Disponible', _h((cap - planned).clamp(0, double.infinity)), Icons.person_search_outlined, YColors.success),
                kpi('Réalisé (temps saisis)', _h(actual), Icons.timer_outlined, YColors.muted),
                kpi('En surcharge', '${people.where((p) => p.overloadDays > 0).length} pers.', Icons.warning_amber_rounded, YColors.danger),
              ]),
              const SizedBox(height: 12),
              if (people.isEmpty && allPeople.isNotEmpty)
                EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucun résultat'), message: tr('Aucune personne ne correspond à ces filtres.'))
              else if (people.isEmpty)
                EmptyState(icon: Icons.view_timeline_outlined, title: tr('Aucune personne'), message: tr('Connectez-vous une première fois pour charger l\'équipe.'))
              else
                Card(
                  clipBehavior: Clip.antiAlias,
                  child: _Timeline(from: _from, days: _weeks * 7, people: people, assignments: shownA, manage: plCanManage(session), narrow: narrow),
                ),
              const SizedBox(height: 10),
              Wrap(spacing: 16, runSpacing: 6, children: [
                _legend(YColors.line, 'Repos, férié'),
                _legend(YColors.warning.withValues(alpha: .25), 'Congé'),
                _legend(YColors.danger.withValues(alpha: .16), 'Surcharge'),
                _legend(YColors.blue.withValues(alpha: .45), 'Option (à confirmer)'),
              ]),
            ]);
          },
        ),
      ),
    ]);
  }

  Widget _legend(Color c, String l) => Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 14, height: 14, decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(3))),
        const SizedBox(width: 6),
        Text(tr(l), style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor)),
      ]);
}

Color plLoadColor(int? pct) => pct == null ? YColors.muted : (pct > 100 ? YColors.danger : (pct >= 85 ? YColors.warning : YColors.success));

class _Timeline extends StatelessWidget {
  const _Timeline({required this.from, required this.days, required this.people, required this.assignments, required this.manage, required this.narrow});

  final DateTime from;
  final int days;
  final List<PlPerson> people;
  final List<Map<String, dynamic>> assignments;
  final bool manage;
  final bool narrow;

  static const laneH = 26.0;

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    final nameW = narrow ? 112.0 : 210.0;
    final today = _iso(DateTime.now());
    return LayoutBuilder(builder: (context, c) {
      final dw = (c.maxWidth - nameW) / days;
      final header = Row(children: [
        SizedBox(width: nameW, height: 46, child: Padding(padding: const EdgeInsets.all(10), child: Text(tr('Équipe'), style: TextStyle(fontWeight: FontWeight.w700, color: hint)))),
        for (var w = 0; w < days ~/ 7; w++)
          Container(
            width: dw * 7,
            height: 46,
            decoration: const BoxDecoration(border: Border(left: BorderSide(color: YColors.line))),
            child: Column(children: [
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(tr(dw * 7 > 90 ? 'Sem. ${_week(from.add(Duration(days: w * 7)))} · ${DateFormat('d MMM', I18n.dateLocale).format(from.add(Duration(days: w * 7)))}' : 'S${_week(from.add(Duration(days: w * 7)))}'),
                    style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700), overflow: TextOverflow.clip, maxLines: 1),
              ),
              const Spacer(),
              Row(children: [
                for (var d = 0; d < 7; d++)
                  SizedBox(
                    width: dw,
                    child: Text(tr(dw >= 16 ? '${from.add(Duration(days: w * 7 + d)).day}' : ''),
                        textAlign: TextAlign.center,
                        style: TextStyle(
                            fontSize: 10.5,
                            color: _iso(from.add(Duration(days: w * 7 + d))) == today ? YColors.blue : hint,
                            fontWeight: _iso(from.add(Duration(days: w * 7 + d))) == today ? FontWeight.w900 : FontWeight.w500)),
                  ),
              ]),
              const SizedBox(height: 3),
            ]),
          ),
      ]);
      return Column(children: [
        header,
        const Divider(height: 1),
        for (final p in people) ...[_row(context, p, dw, nameW, today), const Divider(height: 1)],
      ]);
    });
  }

  int _week(DateTime d) {
    final thursday = d.add(Duration(days: 4 - d.weekday));
    return ((thursday.difference(DateTime(thursday.year)).inDays) / 7).floor() + 1;
  }

  Widget _row(BuildContext context, PlPerson p, double dw, double nameW, String today) {
    final hint = Theme.of(context).hintColor;
    final mine = assignments.where((a) => (a['user_id'] as num?)?.toInt() == p.id).toList()..sort((a, b) => '${a['start_date']}'.compareTo('${b['start_date']}'));
    // Couloirs : deux affectations qui se chevauchent sont empilées
    final lanes = <List<Map<String, dynamic>>>[];
    final laneOf = <Map<String, dynamic>, int>{};
    for (final a in mine) {
      var i = lanes.indexWhere((l) => '${l.last['end_date']}'.compareTo('${a['start_date']}') < 0);
      if (i < 0) {
        lanes.add([a]);
        i = lanes.length - 1;
      } else {
        lanes[i].add(a);
      }
      laneOf[a] = i;
    }
    final h = (lanes.length.clamp(1, 6) * laneH + 22).toDouble().clamp(56, 400).toDouble();
    final occ = p.occupation;
    return SizedBox(
      height: h,
      child: Row(children: [
        SizedBox(
          width: nameW,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
              Text(tr(p.name), style: const TextStyle(fontWeight: FontWeight.w700), maxLines: 1, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 3),
              Row(children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                  decoration: BoxDecoration(color: plLoadColor(occ).withValues(alpha: .14), borderRadius: BorderRadius.circular(6)),
                  child: Text(tr(occ == null ? '—' : '$occ %'), style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: plLoadColor(occ))),
                ),
                if (!narrow) ...[
                  const SizedBox(width: 6),
                  Flexible(child: Text(tr('${_h((p.capacity - p.planned).clamp(0, double.infinity))} libres'), style: TextStyle(fontSize: 11, color: hint), overflow: TextOverflow.ellipsis)),
                ],
              ]),
            ]),
          ),
        ),
        Expanded(
          child: Stack(children: [
            // Fond : repos, congés, surcharges ; un clic sur un jour libre crée une affectation
            Row(children: [
              for (var i = 0; i < days; i++)
                Builder(builder: (context) {
                  final day = from.add(Duration(days: i));
                  final k = _iso(day);
                  final d = p.days[k]!;
                  final color = d.off == 'Congé'
                      ? YColors.warning.withValues(alpha: .25)
                      : d.cap == 0
                          ? YColors.line.withValues(alpha: .7)
                          : d.planned > d.cap + .001
                              ? YColors.danger.withValues(alpha: .16)
                              : Colors.transparent;
                  final cell = Container(
                    width: dw,
                    decoration: BoxDecoration(
                      color: color,
                      border: Border(
                        left: BorderSide(color: day.weekday == 1 ? YColors.line : YColors.line.withValues(alpha: .35)),
                        right: k == today ? const BorderSide(color: YColors.blue, width: 2) : BorderSide.none,
                      ),
                    ),
                  );
                  final tip = [
                    DateFormat('EEEE d MMMM', I18n.dateLocale).format(day),
                    if (d.off != null) d.off!,
                    if (d.cap > 0) 'Prévu ${_h(d.planned)} / ${_h(d.cap)}',
                    if (d.actual > 0) 'Réalisé ${_h(d.actual)}',
                  ].join('\n');
                  return Tooltip(
                    message: tip,
                    waitDuration: const Duration(milliseconds: 400),
                    child: manage ? InkWell(onTap: () => editAssignment(context, userId: p.id, start: day), child: cell) : cell,
                  );
                }),
            ]),
            for (final a in mine)
              Builder(builder: (context) {
                final s = _d(a['start_date']), e = _d(a['end_date']);
                final first = s.isBefore(from) ? 0 : s.difference(from).inDays;
                final last = e.difference(from).inDays.clamp(0, days - 1);
                final lane = laneOf[a]!;
                if (lane > 5) return const SizedBox.shrink();
                final col = plHex(a['color']);
                final tentative = a['status'] == 'tentative';
                return Positioned(
                  left: first * dw + 1,
                  width: (last - first + 1) * dw - 2,
                  top: 10 + lane * laneH,
                  height: laneH - 4,
                  child: Tooltip(
                    message: tr('${a['title']}${a['partner_name'] != null && a['partner_name'] != a['title'] ? ' · ${a['partner_name']}' : ''}\ndu ${dateFr(a['start_date'])} au ${dateFr(a['end_date'])} · ${_h(toDouble(a['hours_per_day']))} / jour${tentative ? '\nOption à confirmer' : ''}'),
                    child: InkWell(
                      onTap: manage ? () => editAssignment(context, assignment: a) : null,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6),
                        alignment: Alignment.centerLeft,
                        decoration: BoxDecoration(
                          color: tentative ? col.withValues(alpha: .35) : col,
                          borderRadius: BorderRadius.circular(6),
                          border: tentative ? Border.all(color: col, width: 1.5) : null,
                        ),
                        child: Text(
                          tr('${a['title']}${dw * (last - first + 1) > 150 ? ' · ${_h(toDouble(a['hours_per_day']))}/j' : ''}'),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(color: tentative ? YColors.night : Colors.white, fontSize: 11.5, fontWeight: FontWeight.w700),
                        ),
                      ),
                    ),
                  ),
                );
              }),
          ]),
        ),
      ]),
    );
  }
}

/// Crée ou modifie une affectation (hors ligne).
Future<void> editAssignment(BuildContext context, {Map<String, dynamic>? assignment, int? userId, DateTime? start}) async {
  final sync = context.read<SyncEngine>();
  final a = assignment ?? const <String, dynamic>{};
  final users = [for (final u in (LocalDb.state().get('pl_users') as List? ?? const [])) (id: (u['id'] as num).toInt(), name: '${u['name']}')];
  if (users.isEmpty && a['user_id'] != null) users.add((id: (a['user_id'] as num).toInt(), name: '${a['user_name']}'));
  final projects = LocalDb.hasEntity('prj_projects') ? (LocalDb.all('prj_projects').where((p) => p['status'] == 'active' || p['status'] == 'planned' || p['id'] == a['project_id']).toList()..sort((x, y) => '${x['name']}'.compareTo('${y['name']}'))) : <Map<String, dynamic>>[];
  final partners = LocalDb.all('partners').where((x) => x['type'] != 'supplier').toList()..sort((x, y) => '${x['name']}'.compareTo('${y['name']}'));
  var user = (a['user_id'] as num?)?.toInt() ?? userId ?? (users.isEmpty ? null : users.first.id);
  var project = a['project_id'] as String?;
  var partner = a['partner_id'] as String?;
  var from = a['start_date'] != null ? _d(a['start_date']) : _utc(start ?? DateTime.now());
  var to = a['end_date'] != null ? _d(a['end_date']) : from.add(Duration(days: from.weekday <= 5 ? 5 - from.weekday : 0));
  var tentative = a['status'] == 'tentative';
  var color = a['color'] as String?;
  final title = TextEditingController(text: '${a['title'] ?? ''}');
  final hours = TextEditingController(text: a['hours_per_day'] == null ? '8' : '${toDouble(a['hours_per_day'])}'.replaceAll(RegExp(r'\.0$'), ''));
  final notes = TextEditingController(text: '${a['notes'] ?? ''}');
  await showFormDialog(
    context,
    title: assignment == null ? 'Nouvelle affectation' : 'Affectation',
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Field(
          label: tr('Personne'),
          child: DropdownButtonFormField<int>(
            initialValue: users.any((u) => u.id == user) ? user : null,
            isExpanded: true,
            items: [for (final u in users) DropdownMenuItem(value: u.id, child: Text(tr(u.name)))],
            onChanged: (v) => set(() => user = v),
          ),
        ),
        if (projects.isNotEmpty)
          Field(
            label: tr('Projet'),
            child: DropdownButtonFormField<String?>(
              initialValue: projects.any((p) => p['id'] == project) ? project : null,
              isExpanded: true,
              items: [
                DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun'))),
                for (final p in projects) DropdownMenuItem<String?>(value: p['id'] as String, child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => set(() {
                project = v;
                final p = v == null ? null : LocalDb.get('prj_projects', v);
                if (p != null) {
                  partner = p['partner_id'] as String? ?? partner;
                  color ??= p['color'] as String?;
                }
              }),
            ),
          ),
        Field(
          label: tr('Client'),
          child: DropdownButtonFormField<String?>(
            initialValue: partners.any((p) => p['id'] == partner) ? partner : null,
            isExpanded: true,
            items: [
              DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun (activité interne)'))),
              for (final p in partners) DropdownMenuItem<String?>(value: p['id'] as String, child: Text(tr('${p['name']}'), overflow: TextOverflow.ellipsis)),
            ],
            onChanged: (v) => set(() => partner = v),
          ),
        ),
        Field(label: tr('Intitulé (facultatif si projet ou client)'), child: TextField(controller: title, decoration: InputDecoration(hintText: tr('Ex. Charte graphique, Support, Formation…')))),
        Row(children: [
          Expanded(
            child: Field(
              label: tr('Du'),
              child: OutlinedButton.icon(
                onPressed: () async {
                  final p = await pickDate(ctx, initialDate: DateTime(from.year, from.month, from.day), firstDate: DateTime(2020), lastDate: DateTime(2100));
                  if (p != null) {
                    final d = _utc(p);
                    set(() {
                      from = d;
                      if (to.isBefore(d)) to = d;
                    });
                  }
                },
                icon: const Icon(Icons.event, size: 18),
                label: Text(tr(DateFormat('dd/MM/yyyy').format(from))),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Field(
              label: tr('Au'),
              child: OutlinedButton.icon(
                onPressed: () async {
                  final i = to.isBefore(from) ? from : to;
                  final d = await pickDate(ctx, initialDate: DateTime(i.year, i.month, i.day), firstDate: DateTime(from.year, from.month, from.day), lastDate: DateTime(2100));
                  if (d != null) set(() => to = _utc(d));
                },
                icon: const Icon(Icons.flag_outlined, size: 18),
                label: Text(tr(DateFormat('dd/MM/yyyy').format(to))),
              ),
            ),
          ),
          const SizedBox(width: 12),
          SizedBox(width: 110, child: Field(label: tr('Heures / jour'), child: TextField(controller: hours, keyboardType: const TextInputType.numberWithOptions(decimal: true)))),
        ]),
        SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Option à confirmer')), subtitle: Text(tr('Réservation provisoire (devis en attente…)')), value: tentative, onChanged: (v) => set(() => tentative = v)),
        Field(
          label: tr('Couleur'),
          child: Wrap(spacing: 8, children: [
            for (final col in plColors)
              InkWell(
                onTap: () => set(() => color = col),
                child: Container(width: 26, height: 26, decoration: BoxDecoration(color: plHex(col), shape: BoxShape.circle, border: Border.all(color: color == col ? YColors.night : Colors.transparent, width: 3))),
              ),
          ]),
        ),
        Field(label: tr('Notes'), child: TextField(controller: notes, maxLines: 3)),
        const SizedBox(height: 8),
        Row(children: [
          if (assignment != null)
            TextButton(
              onPressed: () async {
                if (!await confirm(ctx, tr('Supprimer l\'affectation ?'), '${a['title']}', danger: true, ok: tr('Supprimer'))) return;
                await sync.remove('pl_assignments', '${a['id']}');
                if (ctx.mounted) Navigator.pop(ctx);
              },
              child: Text(tr('Supprimer'), style: TextStyle(color: YColors.danger)),
            ),
          if (assignment != null)
            TextButton(
              onPressed: () async {
                final len = to.difference(from).inDays;
                final nf = to.add(const Duration(days: 1));
                await sync.save('pl_assignments', {
                  for (final k in ['user_id', 'project_id', 'partner_id', 'title', 'hours_per_day', 'status', 'color', 'notes']) k: a[k],
                  'id': newId(),
                  'start_date': _iso(nf),
                  'end_date': _iso(nf.add(Duration(days: len))),
                });
                if (ctx.mounted) {
                  Navigator.pop(ctx);
                  toast(ctx, tr('Affectation prolongée sur la période suivante.'));
                }
              },
              child: Text(tr('Répéter après')),
            ),
          const Spacer(),
          SaveButton(
            onPressed: () async {
              final h = toDouble(hours.text);
              if (user == null) return toast(ctx, tr('Choisissez une personne.'), error: true);
              if (h <= 0 || h > 24) return toast(ctx, tr('Heures par jour entre 0 et 24.'), error: true);
              if (title.text.trim().isEmpty && project == null && partner == null) return toast(ctx, tr('Indiquez un intitulé, un projet ou un client.'), error: true);
              final p = project == null ? null : LocalDb.get('prj_projects', project!);
              final u = users.where((x) => x.id == user).firstOrNull;
              await sync.save('pl_assignments', {
                'id': a['id'] ?? newId(),
                'user_id': user,
                'user_name': u?.name,
                'project_id': project,
                'partner_id': partner,
                'partner_name': partner == null ? null : LocalDb.get('partners', partner!)?['name'],
                'title': title.text.trim().isNotEmpty ? title.text.trim() : (p?['name'] ?? LocalDb.get('partners', partner ?? '')?['name'] ?? ''),
                'start_date': _iso(from),
                'end_date': _iso(to),
                'hours_per_day': h,
                'status': tentative ? 'tentative' : 'confirmed',
                'color': color ?? p?['color'] ?? plColors[(user! + (project ?? partner ?? '').length) % plColors.length],
                'notes': notes.text.trim().isEmpty ? null : notes.text.trim(),
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

/// Semaine type utilisée quand le salarié n'a pas d'horaire de pointage.
Future<void> planningSettings(BuildContext context) async {
  final api = context.read<Session>().api;
  Map<String, dynamic> s;
  try {
    s = asMap((await api.get('planning/settings'))['data']);
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return;
  }
  if (!context.mounted) return;
  final week = TextEditingController(text: '${toDouble(s['week_hours'])}'.replaceAll(RegExp(r'\.0$'), ''));
  final over = TextEditingController(text: '${s['overload_percent'] ?? 100}');
  final days = <int>{for (final d in (s['work_days'] as List? ?? const [1, 2, 3, 4, 5])) (d as num).toInt()};
  const names = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
  await showFormDialog(
    context,
    title: tr('Semaine type'),
    builder: (ctx) => StatefulBuilder(
      builder: (ctx, set) => ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
        Text(tr('Capacité des personnes sans horaire de pointage. Les jours fériés et les congés approuvés sont retirés automatiquement.'), style: TextStyle(color: Theme.of(ctx).hintColor)),
        const SizedBox(height: 12),
        Field(label: tr('Heures par semaine'), child: TextField(controller: week, keyboardType: TextInputType.number)),
        Field(
          label: tr('Jours travaillés'),
          child: Wrap(spacing: 6, children: [
            for (var i = 1; i <= 7; i++) FilterChip(label: Text(tr(names[i - 1])), selected: days.contains(i), onSelected: (v) => set(() => v ? days.add(i) : days.remove(i))),
          ]),
        ),
        Field(label: tr('Surcharge au-delà de (% de la capacité du jour)'), child: TextField(controller: over, keyboardType: TextInputType.number)),
        const SizedBox(height: 8),
        Align(
          alignment: Alignment.centerRight,
          child: SaveButton(
            onPressed: () async {
              try {
                await api.put('planning/settings', {'week_hours': toDouble(week.text), 'work_days': (days.toList()..sort()), 'overload_percent': int.tryParse(over.text) ?? 100});
                if (ctx.mounted) Navigator.pop(ctx);
              } catch (e) {
                if (ctx.mounted) toast(ctx, errorText(e), error: true);
              }
            },
            child: Text(tr('Enregistrer')),
          ),
        ),
      ]),
    ),
  );
}
