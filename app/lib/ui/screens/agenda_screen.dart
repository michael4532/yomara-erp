import 'dart:math' as math;

import 'package:flutter/gestures.dart';
import 'store_screen.dart' show StoreScreen;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config.dart';
import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../files/save_file.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
// ======================================================================
// Outils : dates, récurrence, couleurs
// ======================================================================

const agCategories = {'meeting': 'Réunion', 'appointment': 'Rendez-vous', 'travel': 'Déplacement', 'training': 'Formation', 'personal': 'Personnel', 'other': 'Autre'};
const agCategoryColors = {'meeting': '#1F6FEB', 'appointment': '#12A150', 'travel': '#E67E22', 'training': '#8E44AD', 'personal': '#5B6B85', 'other': '#0E9AA7'};
const agKinds = {'leave': 'Congés', 'interview': 'Recrutement', 'appointment': 'Rendez-vous clients', 'intervention': 'Interventions', 'task': 'Projets', 'document': 'Documents'};
const _days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const _daysShort = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
const _months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

Color agHex(String? h, [Color fallback = YColors.blue]) {
  if (h == null || h.length < 7) return fallback;
  final v = int.tryParse(h.substring(1, 7), radix: 16);
  return v == null ? fallback : Color(0xFF000000 | v);
}

DateTime _day(DateTime d) => DateTime(d.year, d.month, d.day);

/// Ajout de jours calendaires (sans décalage lors des changements d'heure).
DateTime _addDays(DateTime d, int n) => DateTime(d.year, d.month, d.day + n, d.hour, d.minute, d.second);
String _hm(DateTime d) => '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
String _sql(DateTime d) => '${isoDate(d)} ${_hm(d)}:00';
String agDayLabel(DateTime d) => '${_days[d.weekday - 1]} ${d.day == 1 ? '1er' : d.day} ${_months[d.month - 1]}';
DateTime _monday(DateTime d) => _addDays(_day(d), -(d.weekday - 1));

/// Occurrences d'un événement entre [from] et [to] (même règle que le serveur).
List<(DateTime, DateTime)> agOccurrences(Map e, DateTime from, DateTime to) {
  final start = DateTime.tryParse('${e['starts_at']}');
  final end = DateTime.tryParse('${e['ends_at']}');
  if (start == null || end == null) return [];
  final dur = end.difference(start);
  final ex = {...((e['exceptions'] as List?) ?? []).map((x) => '$x')};
  final out = <(DateTime, DateTime)>[];
  void push(DateTime s) {
    if (ex.contains(isoDate(s))) return;
    final f = s.add(dur);
    if (s.isBefore(to) && (f.isAfter(from) || (dur == Duration.zero && !s.isBefore(from)))) out.add((s, f));
  }

  final r = e['recurrence'] is Map ? asMap(e['recurrence']) : null;
  if (r == null || !['daily', 'weekly', 'monthly', 'yearly'].contains(r['freq'])) {
    push(start);
    return out;
  }
  final interval = math.max(1, (r['interval'] as num?)?.toInt() ?? 1);
  final until = DateTime.tryParse('${r['until'] ?? ''}');
  final untilEnd = until == null ? null : DateTime(until.year, until.month, until.day, 23, 59, 59);
  final count = (r['count'] as num?)?.toInt();
  final limit = untilEnd != null && untilEnd.isBefore(to) ? untilEnd : to;
  var n = 0;
  bool emit(DateTime s) {
    if (s.isAfter(limit) || (count != null && n >= count)) return false;
    n++;
    push(s);
    return true;
  }

  var guard = 0;
  if (r['freq'] == 'weekly') {
    var days = ((r['byday'] as List?) ?? []).map((d) => (d as num).toInt()).where((d) => d >= 1 && d <= 7).toSet().toList()..sort();
    if (days.isEmpty) days = [start.weekday];
    var week = _monday(start);
    while (guard++ < 5000) {
      for (final d in days) {
        final day = _addDays(week, d - 1);
        final s = DateTime(day.year, day.month, day.day, start.hour, start.minute, start.second);
        if (s.isBefore(start)) continue;
        if (!emit(s)) return out;
      }
      week = _addDays(week, 7 * interval);
    }
    return out;
  }
  for (var i = 0; guard++ < 5000; i++) {
    final DateTime s;
    if (r['freq'] == 'daily') {
      final d = DateTime(start.year, start.month, start.day + i * interval);
      s = DateTime(d.year, d.month, d.day, start.hour, start.minute, start.second);
    } else if (r['freq'] == 'monthly') {
      final m = start.month - 1 + i * interval;
      final y = start.year + m ~/ 12;
      final mm = m % 12 + 1;
      if (start.day > DateUtils.getDaysInMonth(y, mm)) {
        continue;
      }
      s = DateTime(y, mm, start.day, start.hour, start.minute, start.second);
    } else {
      final y = start.year + i * interval;
      if (start.day > DateUtils.getDaysInMonth(y, start.month)) continue;
      s = DateTime(y, start.month, start.day, start.hour, start.minute, start.second);
    }
    if (!emit(s)) break;
  }
  return out;
}

/// Élément affiché : occurrence d'un événement ou entrée d'un autre module.
class AgItem {
  AgItem({required this.key, required this.title, required this.start, required this.end, required this.allDay, required this.color, this.event, this.overlay, this.declined = false});

  final String key;
  final String title;
  final DateTime start, end;
  final bool allDay;
  final Color color;
  final Map<String, dynamic>? event;
  final Map<String, dynamic>? overlay;
  final bool declined;

  bool get masked => event?['masked'] == true;
  bool coversDay(DateTime d) => !end.isBefore(_day(d)) && start.isBefore(_addDays(_day(d), 1)) && !(end == _day(d) && !allDay && end != start);
}

/// Réponses aux invitations (le serveur renvoie [] quand il n'y en a aucune).
Map<String, dynamic> agResponses(Map e) => e['responses'] is Map ? asMap(e['responses']) : <String, dynamic>{};

Map<int, String> agUserNames() => {for (final u in ((LocalDb.state().get('ag_users') as List?) ?? [])) ((u as Map)['id'] as num).toInt(): '${u['name']}'};

Future<List<Map<String, dynamic>>> agLoadUsers(BuildContext context) async {
  try {
    final r = await context.read<Session>().api.get('agenda/users');
    final list = [for (final u in r['data'] as List) asMap(u)];
    await LocalDb.state().put('ag_users', list);
    return list;
  } catch (_) {
    return [for (final u in ((LocalDb.state().get('ag_users') as List?) ?? [])) asMap(u)];
  }
}

// ======================================================================
// Écran
// ======================================================================

class AgendaScreen extends StatefulWidget {
  const AgendaScreen({super.key});

  @override
  State<AgendaScreen> createState() => _AgendaScreenState();
}

class _AgendaScreenState extends State<AgendaScreen> {
  String _view = 'week';
  DateTime _anchor = _day(DateTime.now());
  String _scope = 'mine';
  final Set<int> _people = {};
  bool _modules = true;
  List<Map<String, dynamic>> _overlay = [];
  String? _overlayKey;

  @override
  void initState() {
    super.initState();
    final cached = LocalDb.state().get('ag_overlay');
    if (cached is Map) _overlay = [for (final x in (cached['items'] as List? ?? [])) asMap(x)];
    agLoadUsers(context).then((_) => mounted ? setState(() {}) : null);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      // Téléphone : la semaine en 7 colonnes est illisible, on ouvre sur la journée
      if (mounted && MediaQuery.sizeOf(context).width < 600 && _view == 'week') setState(() => _view = 'day');
      _loadOverlay();
    });
    agOverlayRefresh.addListener(_reloadOverlay);
  }

  void _reloadOverlay() {
    _overlayKey = null;
    _loadOverlay();
  }

  @override
  void dispose() {
    agOverlayRefresh.removeListener(_reloadOverlay);
    super.dispose();
  }

  (DateTime, DateTime) get _range => switch (_view) {
    'day' => (_anchor, _addDays(_anchor, 1)),
    'week' => (_monday(_anchor), _addDays(_monday(_anchor), 7)),
    'month' => () {
      final first = DateTime(_anchor.year, _anchor.month, 1);
      final s = _monday(first);
      return (s, _addDays(s, 42));
    }(),
    _ => (_anchor, _addDays(_anchor, 60)),
  };

  Future<void> _loadOverlay() async {
    final (from, to) = _range;
    final key = '${isoDate(from)}_${isoDate(to)}';
    if (_overlayKey == key) return;
    try {
      final r = await context.read<Session>().api.get('agenda/overlay', query: {'from': isoDate(from), 'to': isoDate(to)});
      final items = [for (final x in r['data'] as List) asMap(x)];
      await LocalDb.state().put('ag_overlay', {'from': isoDate(from), 'to': isoDate(to), 'items': items});
      if (mounted) setState(() => (_overlay = items, _overlayKey = key));
    } catch (_) {
      // hors ligne : on garde les éléments déjà chargés
    }
  }

  void _move(int dir) {
    setState(() {
      _anchor = switch (_view) {
        'day' => _addDays(_anchor, dir),
        'week' => _addDays(_anchor, 7 * dir),
        'month' => DateTime(_anchor.year, _anchor.month + dir, 1),
        _ => _addDays(_anchor, 30 * dir),
      };
    });
    _loadOverlay();
  }

  String get _label {
    final (from, to) = _range;
    switch (_view) {
      case 'day':
        return '${agDayLabel(_anchor)} ${_anchor.year}';
      case 'week':
        final last = _addDays(to, -(1));
        return from.month == last.month
            ? 'Du ${from.day} au ${last.day} ${_months[last.month - 1]} ${last.year}'
            : 'Du ${from.day} ${_months[from.month - 1]} au ${last.day} ${_months[last.month - 1]} ${last.year}';
      case 'month':
        return '${_months[_anchor.month - 1][0].toUpperCase()}${_months[_anchor.month - 1].substring(1)} ${_anchor.year}';
      default:
        return 'À partir du ${agDayLabel(_anchor)}';
    }
  }

  List<AgItem> _items(DateTime from, DateTime to) {
    final me = (context.read<Session>().user['id'] as num?)?.toInt();
    final people = _scope == 'team' ? (_people.isEmpty ? null : {..._people, ?me}) : {?me};
    bool concerns(Map e) {
      if (people == null) return true;
      final ids = {(e['owner_id'] as num?)?.toInt(), ...((e['attendee_ids'] as List?) ?? []).map((x) => (x as num).toInt())};
      return ids.any(people.contains);
    }

    final out = <AgItem>[];
    for (final e in LocalDb.all('ag_events')) {
      if (e['status'] == 'cancelled' || !concerns(e)) continue;
      final declined = agResponses(e)['$me'] == 'declined';
      if (declined && _scope == 'mine') continue;
      final color = e['masked'] == true ? YColors.muted : agHex('${e['color'] ?? agCategoryColors[e['category']] ?? '#1F6FEB'}');
      for (final (s, f) in agOccurrences(e, from, to)) {
        out.add(AgItem(key: '${e['id']}@${s.toIso8601String()}', title: '${e['title']}', start: s, end: f, allDay: e['all_day'] == true, color: color, event: e, declined: declined));
      }
    }
    if (_modules) {
      for (final o in _overlay) {
        final s = DateTime.tryParse('${o['starts_at']}');
        final f = DateTime.tryParse('${o['ends_at']}');
        if (s == null || f == null || !s.isBefore(to) || !f.isAfter(from)) continue;
        if (people != null) {
          final uid = (o['user_id'] as num?)?.toInt();
          final uids = ((o['user_ids'] as List?) ?? []).map((x) => (x as num).toInt());
          final personal = o.containsKey('user_id') || o.containsKey('user_ids');
          if (personal && !(people.contains(uid) || uids.any(people.contains))) continue;
        }
        out.add(AgItem(key: '${o['id']}', title: '${o['title']}', start: s, end: f, allDay: o['all_day'] == true, color: agHex('${o['color']}'), overlay: o));
      }
    }
    out.sort((a, b) => a.start.compareTo(b.start));
    return out;
  }

  Future<void> _pickPeople() async {
    final users = await agLoadUsers(context);
    if (!mounted) return;
    final sel = {..._people};
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) => YAlertDialog(
          title: Text(tr('Agenda de l\'équipe')),
          content: SizedBox(
            width: 420,
            child: ListView(
              shrinkWrap: true,
              children: [
                Text(tr('Aucune sélection : tous les événements partagés de l\'équipe.'), style: TextStyle(color: Theme.of(d).hintColor, fontSize: 13)),
                for (final u in users)
                  CheckboxListTile(
                    contentPadding: EdgeInsets.zero,
                    value: sel.contains((u['id'] as num).toInt()),
                    onChanged: (v) => set(() => v == true ? sel.add((u['id'] as num).toInt()) : sel.remove((u['id'] as num).toInt())),
                    title: Text(tr('${u['name']}')),
                  ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => set(sel.clear), child: Text(tr('Tout le monde'))),
            FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Afficher'))),
          ],
        ),
      ),
    );
    if (ok == true) {
      setState(
        () => (
          _people
            ..clear()
            ..addAll(sel),
          _scope = 'team',
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final manage = s.can('agenda.manage');
    final wide = MediaQuery.sizeOf(context).width >= 900;
    return ListenableBuilder(
      listenable: Listenable.merge([LocalDb.listen('ag_events'), LocalDb.listen('ag_resources')]),
      builder: (context, _) {
        final (from, to) = _range;
        final items = _items(from, to);
        return Column(
          children: [
            PageHeader(
              title: tr('Agenda'),
              subtitle: tr('Réunions, rendez-vous, congés et échéances de l\'équipe'),
              actions: [
                OutlinedButton.icon(onPressed: () => showFeedDialog(context), icon: const Icon(Icons.sync_alt), label: Text(tr('Google / Outlook'))),
                if (manage) OutlinedButton.icon(onPressed: () => showResourcesDialog(context), icon: const Icon(Icons.meeting_room_outlined), label: Text(tr('Salles & véhicules'))),
                FilledButton.icon(
                  onPressed: () {
                    final now = DateTime.now();
                    final base = _view == 'day' || !_day(now).isBefore(from) && _day(now).isBefore(to)
                        ? DateTime(_anchor.year, _anchor.month, _anchor.day, now.hour + 1)
                        : DateTime(from.year, from.month, from.day, 9);
                    editEvent(context, start: _view == 'day' ? DateTime(_anchor.year, _anchor.month, _anchor.day, now.hour + 1) : base);
                  },
                  icon: const Icon(Icons.add),
                  label: Text(tr('Nouvel événement')),
                ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  OutlinedButton(onPressed: () => setState(() => (_anchor = _day(DateTime.now()), _loadOverlay())), child: Text(tr('Aujourd\'hui'))),
                  IconButton(onPressed: () => _move(-1), icon: const Icon(Icons.chevron_left)),
                  IconButton(onPressed: () => _move(1), icon: const Icon(Icons.chevron_right)),
                  Text(tr(_label), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                  const SizedBox(width: 12),
                  SegmentedButton<String>(
                    showSelectedIcon: false,
                    segments: [
                      ButtonSegment(value: 'day', label: Text(tr('Jour'))),
                      ButtonSegment(value: 'week', label: Text(tr('Semaine'))),
                      ButtonSegment(value: 'month', label: Text(tr('Mois'))),
                      ButtonSegment(value: 'list', label: Text(tr('Liste'))),
                    ],
                    selected: {_view},
                    onSelectionChanged: (v) {
                      setState(() => _view = v.first);
                      _loadOverlay();
                    },
                  ),
                  ChoiceChip(label: Text(tr('Mon agenda')), selected: _scope == 'mine', onSelected: (_) => setState(() => _scope = 'mine')),
                  ChoiceChip(label: Text(tr(_scope == 'team' && _people.isNotEmpty ? 'Équipe (${_people.length})' : 'Équipe')), selected: _scope == 'team', onSelected: (_) => _pickPeople()),
                  FilterChip(label: Text(tr('Congés, RDV, échéances')), selected: _modules, onSelected: (v) => setState(() => _modules = v)),
                ],
              ),
            ),
            Expanded(
              child: switch (_view) {
                'month' => _MonthView(anchor: _anchor, from: from, items: items, onDay: (d) => setState(() => (_anchor = d, _view = 'day'))),
                'list' => _ListView(from: from, to: to, items: items),
                _ => _TimeGrid(days: [for (var d = from; d.isBefore(to); d = _addDays(d, 1)) d], items: items, compact: !wide && _view == 'week'),
              },
            ),
          ],
        );
      },
    );
  }
}

// ---------- Vue mois ----------

class _MonthView extends StatelessWidget {
  const _MonthView({required this.anchor, required this.from, required this.items, required this.onDay});

  final DateTime anchor, from;
  final List<AgItem> items;
  final ValueChanged<DateTime> onDay;

  @override
  Widget build(BuildContext context) {
    final today = _day(DateTime.now());
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      child: Column(
        children: [
          Row(
            children: [
              for (final d in _daysShort)
                Expanded(
                  child: Center(
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 4),
                      child: Text(
                        tr(d),
                        style: TextStyle(color: Theme.of(context).hintColor, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ),
                ),
            ],
          ),
          Expanded(
            child: Column(
              children: [
                for (var w = 0; w < 6; w++)
                  Expanded(
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        for (var i = 0; i < 7; i++)
                          () {
                            final d = _addDays(from, w * 7 + i);
                            final mine = items.where((it) => it.coversDay(d)).toList();
                            return Expanded(
                              child: DragTarget<AgItem>(
                                onWillAcceptWithDetails: (x) => _day(x.data.start) != d,
                                onAcceptWithDetails: (x) {
                                  final it = x.data;
                                  final days = d.difference(_day(it.start)).inDays;
                                  agReschedule(context, it, _addDays(it.start, days), _addDays(it.end, days));
                                },
                                builder: (context, cand, _) => Container(
                                  foregroundDecoration: cand.isNotEmpty
                                      ? BoxDecoration(
                                          color: YColors.blue.withValues(alpha: .08),
                                          border: Border.all(color: YColors.blue, width: 1.5),
                                          borderRadius: BorderRadius.circular(6),
                                        )
                                      : null,
                                  child: InkWell(
                                    onTap: () => onDay(d),
                                    child: Container(
                                      decoration: BoxDecoration(
                                        border: Border.all(color: Theme.of(context).dividerColor.withValues(alpha: 0.5), width: 0.5),
                                        color: d.month != anchor.month ? Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: 0.3) : null,
                                      ),
                                      padding: const EdgeInsets.all(4),
                                      child: LayoutBuilder(
                                        builder: (context, c) {
                                          final fit = math.max(0, ((c.maxHeight - 24) / 19).floor());
                                          return Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              Container(
                                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                                                decoration: d == today ? BoxDecoration(color: YColors.blue, borderRadius: BorderRadius.circular(10)) : null,
                                                child: Text(
                                                  tr('${d.day}'),
                                                  style: TextStyle(
                                                    fontSize: 12,
                                                    fontWeight: FontWeight.w700,
                                                    color: d == today ? Colors.white : (d.month != anchor.month ? Theme.of(context).hintColor : null),
                                                  ),
                                                ),
                                              ),
                                              for (final it in mine.take(mine.length > fit ? math.max(0, fit - 1) : fit))
                                                agCanEdit(context, it)
                                                    ? Draggable<AgItem>(
                                                        data: it,
                                                        feedback: Material(
                                                          color: Colors.transparent,
                                                          child: SizedBox(
                                                            width: c.maxWidth,
                                                            child: Opacity(opacity: .9, child: _Chip(item: it)),
                                                          ),
                                                        ),
                                                        childWhenDragging: Opacity(opacity: .35, child: _Chip(item: it)),
                                                        child: _Chip(item: it),
                                                      )
                                                    : _Chip(item: it),
                                              if (mine.length > fit) Text(tr('+${mine.length - math.max(0, fit - 1)} autre(s)'), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
                                            ],
                                          );
                                        },
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            );
                          }(),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({required this.item});

  final AgItem item;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => showAgItem(context, item),
      child: Container(
        margin: const EdgeInsets.only(top: 2),
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
        decoration: BoxDecoration(
          color: item.color.withValues(alpha: item.allDay ? 0.85 : 0.14),
          borderRadius: BorderRadius.circular(4),
        ),
        child: Text(
          tr('${item.allDay ? '' : '${_hm(item.start)} '}${item.title}'),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(fontSize: 11.5, color: item.allDay ? Colors.white : item.color, fontWeight: FontWeight.w600, decoration: item.declined ? TextDecoration.lineThrough : null),
        ),
      ),
    );
  }
}

// ---------- Vue liste ----------

class _ListView extends StatelessWidget {
  const _ListView({required this.from, required this.to, required this.items});

  final DateTime from, to;
  final List<AgItem> items;

  @override
  Widget build(BuildContext context) {
    final days = <DateTime, List<AgItem>>{};
    for (var d = from; d.isBefore(to); d = _addDays(d, 1)) {
      final l = items.where((it) => it.coversDay(d)).toList();
      if (l.isNotEmpty) days[d] = l;
    }
    if (days.isEmpty) return EmptyState(icon: Icons.event_available, title: tr('Rien de prévu'), message: tr('Aucun événement sur les 60 prochains jours.'));
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
      children: [
        for (final e in days.entries) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 14, 4, 6),
            child: Text(tr('${agDayLabel(e.key)}${e.key.year != DateTime.now().year ? ' ${e.key.year}' : ''}'), style: const TextStyle(fontWeight: FontWeight.w800)),
          ),
          for (final it in e.value)
            Card(
              margin: const EdgeInsets.only(bottom: 6),
              child: ListTile(
                onTap: () => showAgItem(context, it),
                leading: Container(
                  width: 6,
                  height: 36,
                  decoration: BoxDecoration(color: it.color, borderRadius: BorderRadius.circular(3)),
                ),
                title: Text(
                  tr(it.title),
                  style: TextStyle(fontWeight: FontWeight.w600, decoration: it.declined ? TextDecoration.lineThrough : null),
                ),
                subtitle: Text(
                  tr(
                    [
                      it.allDay ? 'Toute la journée' : '${_hm(it.start)} – ${_hm(it.end)}',
                      if (it.overlay != null) agKinds[it.overlay!['kind']] ?? '',
                      if (it.event?['location'] != null) '${it.event!['location']}',
                      if (it.event?['resource_id'] != null) '${LocalDb.get('ag_resources', '${it.event!['resource_id']}')?['name'] ?? ''}',
                      if (it.event?['recurrence'] != null) 'récurrent',
                    ].where((x) => x.isNotEmpty).join(' · '),
                  ),
                ),
              ),
            ),
        ],
      ],
    );
  }
}

// ---------- Vue jour / semaine ----------

class _TimeGrid extends StatefulWidget {
  const _TimeGrid({required this.days, required this.items, this.compact = false});

  final List<DateTime> days;
  final List<AgItem> items;
  final bool compact;

  @override
  State<_TimeGrid> createState() => _TimeGridState();
}

class _TimeGridState extends State<_TimeGrid> {
  static const hourH = 52.0;
  late final _scroll = ScrollController(initialScrollOffset: hourH * 7);

  // Glisser-déposer (comme le Calendrier du Mac) : déplacement vertical = heure, horizontal = jour ; poignée du bas = durée
  String? _dragKey;
  bool _resizing = false;
  Offset _drag = Offset.zero;

  int get _snapMinutes => ((_drag.dy / hourH * 60) / 15).round() * 15;

  void _startDrag(AgItem it, {bool resize = false}) {
    if (!agCanEdit(context, it)) return;
    HapticFeedback.selectionClick();
    setState(() => (_dragKey = it.key, _resizing = resize, _drag = Offset.zero));
  }

  void _updateDrag(AgItem it, Offset delta) {
    if (_dragKey != it.key) return;
    setState(() => _drag += _resizing ? Offset(0, delta.dy) : delta);
  }

  Future<void> _endDrag(AgItem it, double colWidth) async {
    if (_dragKey != it.key) return;
    final minutes = _snapMinutes;
    final days = _resizing ? 0 : (_drag.dx / colWidth).round();
    setState(() => (_dragKey = null, _drag = Offset.zero));
    if (minutes == 0 && days == 0) return;
    if (_resizing) {
      final end = it.end.add(Duration(minutes: minutes));
      if (end.difference(it.start).inMinutes < 15) return;
      await agReschedule(context, it, it.start, end);
    } else {
      final shift = Duration(days: days, minutes: minutes);
      await agReschedule(context, it, _addDays(it.start, days).add(Duration(minutes: minutes)), _addDays(it.end, days).add(Duration(minutes: minutes)));
      if (shift == Duration.zero) return;
    }
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final today = _day(DateTime.now());
    final allDay = [for (final d in widget.days) widget.items.where((it) => (it.allDay || it.end.difference(it.start).inHours >= 24) && it.coversDay(d)).toList()];
    final maxAllDay = allDay.fold<int>(0, (m, l) => math.max(m, l.length));
    const gutter = 52.0;
    return Column(
      children: [
        Row(
          children: [
            const SizedBox(width: gutter),
            for (final d in widget.days)
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Column(
                    children: [
                      Text(
                        tr(widget.compact ? _daysShort[d.weekday - 1].substring(0, 1).toUpperCase() : _daysShort[d.weekday - 1]),
                        style: TextStyle(fontSize: 12, color: Theme.of(context).hintColor),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                        decoration: d == today ? BoxDecoration(color: YColors.blue, borderRadius: BorderRadius.circular(12)) : null,
                        child: Text(
                          tr('${d.day}'),
                          style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: d == today ? Colors.white : null),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
        if (maxAllDay > 0)
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: gutter,
                child: Padding(
                  padding: const EdgeInsets.only(top: 4, right: 6),
                  child: Text(
                    tr('journée'),
                    textAlign: TextAlign.right,
                    style: TextStyle(fontSize: 10, color: Theme.of(context).hintColor),
                  ),
                ),
              ),
              for (final l in allDay)
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 1),
                    child: Column(
                      children: [
                        for (final it in l.take(4)) _Chip(item: it),
                        if (l.length > 4) Text(tr('+${l.length - 4}'), style: const TextStyle(fontSize: 11)),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        const Divider(height: 8),
        Expanded(
          child: SingleChildScrollView(
            controller: _scroll,
            child: SizedBox(
              height: hourH * 24,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: gutter,
                    child: Stack(
                      children: [
                        for (var h = 1; h < 24; h++)
                          Positioned(
                            top: h * hourH - 7,
                            right: 6,
                            child: Text(tr('${h.toString().padLeft(2, '0')}:00'), style: TextStyle(fontSize: 11, color: Theme.of(context).hintColor)),
                          ),
                      ],
                    ),
                  ),
                  for (final d in widget.days) Expanded(child: _dayColumn(context, d, d == today)),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  Widget _dayColumn(BuildContext context, DateTime d, bool isToday) {
    final dayStart = d;
    final dayEnd = _addDays(d, 1);
    final timed = widget.items.where((it) => !it.allDay && it.end.difference(it.start).inHours < 24 && it.start.isBefore(dayEnd) && it.end.isAfter(dayStart)).toList()
      ..sort((a, b) => a.start.compareTo(b.start));
    // Colonnes pour les événements qui se chevauchent
    final cols = <AgItem, int>{};
    final groups = <List<AgItem>>[];
    var current = <AgItem>[];
    DateTime? groupEnd;
    for (final it in timed) {
      if (groupEnd != null && !it.start.isBefore(groupEnd)) {
        groups.add(current);
        current = [];
        groupEnd = null;
      }
      final used = current.where((o) => o.end.isAfter(it.start)).map((o) => cols[o]!).toSet();
      var c = 0;
      while (used.contains(c)) {
        c++;
      }
      cols[it] = c;
      current.add(it);
      groupEnd = groupEnd == null || it.end.isAfter(groupEnd) ? it.end : groupEnd;
    }
    if (current.isNotEmpty) groups.add(current);
    final width = <AgItem, int>{};
    for (final g in groups) {
      final n = g.map((x) => cols[x]!).fold<int>(0, math.max) + 1;
      for (final x in g) {
        width[x] = n;
      }
    }
    return LayoutBuilder(
      builder: (context, c) {
        final now = DateTime.now();
        return GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTapUp: (t) {
            final minutes = (t.localPosition.dy / hourH * 60).floor() ~/ 30 * 30;
            editEvent(context, start: dayStart.add(Duration(minutes: minutes)));
          },
          child: Stack(
            children: [
              for (var h = 0; h < 24; h++)
                Positioned(
                  top: h * hourH,
                  left: 0,
                  right: 0,
                  child: Container(
                    height: hourH,
                    decoration: BoxDecoration(
                      border: Border(
                        top: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: 0.5), width: 0.5),
                        left: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: 0.5), width: 0.5),
                      ),
                    ),
                  ),
                ),
              for (final it in timed)
                () {
                  final s = it.start.isBefore(dayStart) ? dayStart : it.start;
                  final e = it.end.isAfter(dayEnd) ? dayEnd : it.end;
                  // Minutes depuis minuit (insensible aux changements d'heure)
                  final sm = s == dayStart ? 0 : s.hour * 60 + s.minute;
                  final em = e == dayEnd ? 24 * 60 : e.hour * 60 + e.minute;
                  final top = sm / 60 * hourH;
                  final h = math.max(20.0, (em - sm) / 60 * hourH - 2);
                  final n = width[it] ?? 1;
                  final w = c.maxWidth / n;
                  final dragging = _dragKey == it.key;
                  final movable = agCanEdit(context, it);
                  final dTop = dragging && !_resizing ? _snapMinutes / 60 * hourH : 0.0;
                  final dH = dragging && _resizing ? _snapMinutes / 60 * hourH : 0.0;
                  final shownStart = dragging && !_resizing ? it.start.add(Duration(minutes: _snapMinutes)) : it.start;
                  final shownEnd = dragging ? it.end.add(Duration(minutes: _snapMinutes)) : it.end;
                  final block = Container(
                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 3),
                    decoration: BoxDecoration(
                      color: Color.alphaBlend(it.color.withValues(alpha: it.declined ? 0.06 : (dragging ? 0.30 : 0.16)), Theme.of(context).colorScheme.surface),
                      border: Border(left: BorderSide(color: it.color, width: 3)),
                      borderRadius: BorderRadius.circular(6),
                      boxShadow: dragging ? [BoxShadow(color: it.color.withValues(alpha: .35), blurRadius: 14, offset: const Offset(0, 4))] : null,
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          tr(it.title),
                          maxLines: h > 40 ? 2 : 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: it.color, decoration: it.declined ? TextDecoration.lineThrough : null),
                        ),
                        if ((h + dH) > 34 && !widget.compact || dragging)
                          Text(
                            tr('${_hm(shownStart)} – ${_hm(shownEnd)}'),
                            maxLines: 1,
                            style: TextStyle(fontSize: 11, color: it.color, fontWeight: dragging ? FontWeight.w700 : null),
                          ),
                      ],
                    ),
                  );
                  return Positioned(
                    top: top + 1 + dTop,
                    left: (cols[it] ?? 0) * w + 1,
                    width: w - 3,
                    height: math.max(18.0, h + dH),
                    child: Transform.translate(
                      offset: dragging && !_resizing ? Offset(_drag.dx, 0) : Offset.zero,
                      child: MouseRegion(
                        cursor: movable ? (dragging ? SystemMouseCursors.grabbing : SystemMouseCursors.click) : SystemMouseCursors.basic,
                        child: GestureDetector(
                          // Souris / trackpad : glisser directement ; écran tactile : appui long puis glisser
                          onPanStart: movable ? (_) => _startDrag(it) : null,
                          onPanUpdate: movable ? (d) => _updateDrag(it, d.delta) : null,
                          onPanEnd: movable ? (_) => _endDrag(it, c.maxWidth) : null,
                          supportedDevices: const {PointerDeviceKind.mouse, PointerDeviceKind.trackpad, PointerDeviceKind.stylus},
                          child: GestureDetector(
                            onTap: () => showAgItem(context, it),
                            onLongPressStart: movable ? (_) => _startDrag(it) : null,
                            onLongPressMoveUpdate: movable
                                ? (d) {
                                    if (_dragKey == it.key) setState(() => _drag = d.offsetFromOrigin);
                                  }
                                : null,
                            onLongPressEnd: movable ? (_) => _endDrag(it, c.maxWidth) : null,
                            child: Stack(
                              clipBehavior: Clip.none,
                              children: [
                                Positioned.fill(child: block),
                                if (movable && !it.allDay)
                                  Positioned(
                                    left: 0,
                                    right: 0,
                                    bottom: 0,
                                    height: 8,
                                    child: MouseRegion(
                                      cursor: SystemMouseCursors.resizeUpDown,
                                      child: GestureDetector(
                                        behavior: HitTestBehavior.opaque,
                                        onVerticalDragStart: (_) => _startDrag(it, resize: true),
                                        onVerticalDragUpdate: (d) => _updateDrag(it, d.delta),
                                        onVerticalDragEnd: (_) => _endDrag(it, c.maxWidth),
                                        child: Center(
                                          child: Container(
                                            width: 22,
                                            height: 3,
                                            decoration: BoxDecoration(color: it.color.withValues(alpha: .5), borderRadius: BorderRadius.circular(2)),
                                          ),
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
                }(),
              if (isToday)
                Positioned(
                  top: (now.hour * 60 + now.minute) / 60 * hourH,
                  left: 0,
                  right: 0,
                  child: Container(height: 2, color: YColors.danger),
                ),
            ],
          ),
        );
      },
    );
  }
}

// ======================================================================
// Détail d'un élément
// ======================================================================

/// Rechargement des éléments des autres modules après un déplacement depuis l'agenda.
final agOverlayRefresh = ValueNotifier<int>(0);

/// Éléments des autres modules modifiables depuis l'agenda (le serveur vérifie les droits).
const agMovableKinds = {'interview', 'appointment', 'intervention', 'task'};
const agCancellableKinds = {'interview', 'appointment', 'intervention'};

bool agCanEdit(BuildContext context, AgItem it) {
  final s = context.read<Session>();
  if (it.overlay != null) return agMovableKinds.contains(it.overlay!['kind']);
  if (it.masked) return false;
  final me = (s.user['id'] as num?)?.toInt();
  return (it.event?['owner_id'] as num?)?.toInt() == me || s.can('agenda.manage');
}

/// Déplace / redimensionne un élément (glisser-déposer, report, changement d'heure) — comme le Calendrier du Mac.
Future<bool> agReschedule(BuildContext context, AgItem it, DateTime start, DateTime end) async {
  if (!end.isAfter(start)) end = start.add(const Duration(minutes: 30));
  try {
    if (it.overlay != null) {
      final o = it.overlay!;
      final kind = '${o['kind']}';
      if (!agMovableKinds.contains(kind)) {
        toast(context, tr('${agKinds[kind] ?? 'Cet élément'} : à modifier dans son module.'), error: true);
        return false;
      }
      final id = '${o['id']}'.split(':').skip(1).join(':');
      await context.read<Session>().api.put('agenda/overlay/$kind/$id', {'starts_at': _sql(start), 'ends_at': _sql(end)});
      agOverlayRefresh.value++;
      if (context.mounted) toast(context, tr('${it.title} : ${agDayLabel(start)}${it.allDay ? '' : ', ${_hm(start)} – ${_hm(end)}'}'));
      return true;
    }
    final e = it.event!;
    if (!agCanEdit(context, it)) {
      toast(context, tr('Seul l\'organisateur peut déplacer cet événement.'), error: true);
      return false;
    }
    final sync = context.read<SyncEngine>();
    if (e['recurrence'] is Map) {
      final what = await showDialog<String>(
        context: context,
        builder: (d) => YAlertDialog(
          icon: const Icon(Icons.repeat_rounded),
          title: Text(tr('Événement récurrent')),
          content: Text(tr('Déplacer seulement l\'occurrence du ${agDayLabel(it.start)}, ou toute la série ?')),
          actions: [
            TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
            OutlinedButton(onPressed: () => Navigator.pop(d, 'all'), child: Text(tr('Toute la série'))),
            FilledButton(onPressed: () => Navigator.pop(d, 'one'), child: Text(tr('Cette occurrence'))),
          ],
        ),
      );
      if (what == null) return false;
      if (what == 'one') {
        await sync.save('ag_events', {
          'id': e['id'],
          'exceptions': [...((e['exceptions'] as List?) ?? []), isoDate(it.start)],
        });
        final copy = Map<String, dynamic>.from(e)
          ..removeWhere((k, _) => ['id', 'recurrence', 'exceptions', 'version', 'created_at', 'updated_at', 'deleted_at', '_local', 'owner_id', 'company_id', 'masked', 'yomeet'].contains(k));
        await sync.save('ag_events', {...copy, 'id': newId(), 'starts_at': _sql(start), 'ends_at': _sql(end)});
        return true;
      }
      final s0 = DateTime.parse('${e['starts_at']}');
      final shift = start.difference(it.start);
      final ns = s0.add(shift);
      await sync.save('ag_events', {'id': e['id'], 'starts_at': _sql(ns), 'ends_at': _sql(ns.add(end.difference(start)))});
      return true;
    }
    await sync.save('ag_events', {
      'id': e['id'],
      'starts_at': _sql(start),
      'ends_at': _sql(end),
      if (e['yomeet'] is Map) 'yomeet': {'enabled': (e['yomeet'] as Map)['enabled'] == true},
    });
    return true;
  } catch (err) {
    if (context.mounted) toast(context, errorText(err), error: true);
    return false;
  }
}

/// Supprime un événement (ou annule un entretien / rendez-vous / intervention).
Future<bool> agDelete(BuildContext context, AgItem it) async {
  if (it.overlay != null) {
    final kind = '${it.overlay!['kind']}';
    if (!agCancellableKinds.contains(kind)) return false;
    if (!await confirm(context, tr('Annuler « ${it.title} » ?'), tr('L\'élément est annulé dans le module ${agKinds[kind] ?? ''}.'), ok: tr('Annuler l\'élément'), danger: true)) return false;
    if (!context.mounted) return false;
    try {
      await context.read<Session>().api.delete('agenda/overlay/$kind/${'${it.overlay!['id']}'.split(':').skip(1).join(':')}');
      agOverlayRefresh.value++;
      return true;
    } catch (err) {
      if (context.mounted) toast(context, errorText(err), error: true);
      return false;
    }
  }
  final e = it.event!;
  final sync = context.read<SyncEngine>();
  final attendees = (e['attendee_ids'] as List?) ?? [];
  if (e['recurrence'] is Map) {
    final what = await showDialog<String>(
      context: context,
      builder: (d) => YAlertDialog(
        icon: const Icon(Icons.delete_outline_rounded, color: YColors.danger),
        title: Text(tr('Supprimer l\'événement récurrent')),
        content: Text(tr('Supprimer seulement le ${agDayLabel(it.start)}, ou toute la série ?')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
          OutlinedButton(
            onPressed: () => Navigator.pop(d, 'all'),
            child: Text(tr('Toute la série'), style: const TextStyle(color: YColors.danger)),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: YColors.danger),
            onPressed: () => Navigator.pop(d, 'one'),
            child: Text(tr('Cette occurrence')),
          ),
        ],
      ),
    );
    if (what == 'one') {
      await sync.save('ag_events', {
        'id': e['id'],
        'exceptions': [...((e['exceptions'] as List?) ?? []), isoDate(it.start)],
      });
      return true;
    }
    if (what != 'all') return false;
  } else if (!await confirm(
    context,
    tr('Supprimer l\'événement ?'),
    attendees.isEmpty ? '« ${e['title']} » sera supprimé.' : 'Les participants seront prévenus de l\'annulation.',
    danger: true,
    ok: tr('Supprimer'),
  )) {
    return false;
  }
  await sync.remove('ag_events', '${e['id']}');
  return true;
}

/// Choisir une nouvelle plage horaire (date, début, fin).
Future<(DateTime, DateTime)?> agPickTime(BuildContext context, AgItem it) async {
  var day = _day(it.start);
  var from = TimeOfDay.fromDateTime(it.start);
  var to = TimeOfDay.fromDateTime(it.end);
  return showDialog<(DateTime, DateTime)>(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) {
        Widget box(IconData icon, String text, VoidCallback onTap) => InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
            decoration: BoxDecoration(
              border: Border.all(color: Theme.of(d).dividerColor),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(icon, size: 18, color: Theme.of(d).colorScheme.primary),
                const SizedBox(width: 8),
                Text(tr(text), style: const TextStyle(fontWeight: FontWeight.w600)),
              ],
            ),
          ),
        );
        return YAlertDialog(
          icon: const Icon(Icons.schedule_rounded),
          title: Text(tr('Modifier l\'heure')),
          subtitle: it.title,
          content: Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              box(Icons.event_rounded, agDayLabel(day), () async {
                final p = await showDatePicker(context: d, initialDate: day, firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (p != null) set(() => day = p);
              }),
              if (!it.allDay) ...[
                box(Icons.login_rounded, from.format(d), () async {
                  final p = await showTimePicker(context: d, initialTime: from);
                  if (p != null) {
                    final dur = (to.hour * 60 + to.minute) - (from.hour * 60 + from.minute);
                    set(() {
                      from = p;
                      final m = p.hour * 60 + p.minute + (dur > 0 ? dur : 30);
                      to = TimeOfDay(hour: (m ~/ 60).clamp(0, 23), minute: m % 60);
                    });
                  }
                }),
                box(Icons.logout_rounded, to.format(d), () async {
                  final p = await showTimePicker(context: d, initialTime: to);
                  if (p != null) set(() => to = p);
                }),
              ],
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Annuler'))),
            FilledButton(
              onPressed: () {
                if (it.allDay) {
                  Navigator.pop(d, (day, day.add(it.end.difference(it.start))));
                  return;
                }
                final s = DateTime(day.year, day.month, day.day, from.hour, from.minute);
                var e = DateTime(day.year, day.month, day.day, to.hour, to.minute);
                if (!e.isAfter(s)) e = e.add(const Duration(days: 1));
                Navigator.pop(d, (s, e));
              },
              child: Text(tr('Enregistrer')),
            ),
          ],
        );
      },
    ),
  );
}

/// Fenêtre de détail moderne (événement ou élément d'un autre module) avec actions rapides.
Future<void> showAgItem(BuildContext context, AgItem it) => showDialog(
  context: context,
  builder: (_) => _AgPeek(item: it),
);

class _AgPeek extends StatelessWidget {
  const _AgPeek({required this.item});

  final AgItem item;

  @override
  Widget build(BuildContext context) {
    if (item.event != null) {
      return ValueListenableBuilder(
        valueListenable: LocalDb.listen('ag_events'),
        builder: (context, _, _) {
          final e = LocalDb.get('ag_events', '${item.event!['id']}');
          if (e == null) return YAlertDialog(title: Text(tr('Événement supprimé')), content: Text(tr('Cet événement n\'existe plus.')));
          return _body(context, e);
        },
      );
    }
    return _body(context, null);
  }

  Widget _body(BuildContext context, Map<String, dynamic>? e) {
    final s = context.read<Session>();
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final me = (s.user['id'] as num?)?.toInt();
    final o = item.overlay;
    final it = item;
    final color = it.color;
    final canEdit = agCanEdit(context, it);
    final canDelete = o == null ? canEdit : agCancellableKinds.contains(o['kind']);
    final names = agUserNames();
    final attendees = e == null ? <int>[] : ((e['attendee_ids'] as List?) ?? []).map((x) => (x as num).toInt()).toList();
    final responses = e == null ? <String, dynamic>{} : agResponses(e);
    final owner = (e?['owner_id'] as num?)?.toInt();
    final res = e?['resource_id'] == null ? null : LocalDb.get('ag_resources', '${e!['resource_id']}');
    final link = '${e?['online_link'] ?? ''}';
    final yomeet = e?['yomeet'] is Map ? asMap(e!['yomeet']) : null;
    final mine = attendees.contains(me) ? responses['$me'] as String? : null;
    final days = _day(it.start).difference(_day(DateTime.now())).inDays;
    final when = days == 0 ? 'Aujourd\'hui' : (days == 1 ? 'Demain' : (days == -1 ? 'Hier' : (days > 1 && days < 7 ? 'Dans $days jours' : '')));

    Widget line(IconData i, Widget t) => Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(i, size: 19, color: hint),
          const SizedBox(width: 12),
          Expanded(child: t),
        ],
      ),
    );
    Future<void> postpone(Duration d) async {
      final nav = Navigator.of(context);
      if (await agReschedule(context, it, it.start.add(d), it.end.add(d))) nav.pop();
    }

    return Dialog(
      clipBehavior: Clip.antiAlias,
      insetPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 540),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Bandeau couleur
            Container(
              padding: const EdgeInsets.fromLTRB(22, 18, 10, 16),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [color.withValues(alpha: .18), color.withValues(alpha: .05)], begin: Alignment.topLeft, end: Alignment.bottomRight),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 6,
                    height: 44,
                    decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(3)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(tr(it.title), style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800, letterSpacing: -.2)),
                        const SizedBox(height: 4),
                        Wrap(
                          spacing: 6,
                          runSpacing: 4,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            if (when.isNotEmpty) StatusChip(tr(when), color: color),
                            if (o != null) StatusChip(tr(agKinds[o['kind']] ?? 'Module'), color: YColors.muted, icon: Icons.link_rounded),
                            if (e != null && e['masked'] != true) StatusChip(tr(agCategories[e['category']] ?? ''), color: color),
                            if (e?['recurrence'] is Map) const StatusChip('Récurrent', color: YColors.muted, icon: Icons.repeat_rounded),
                            if (yomeet?['enabled'] == true) const StatusChip('Yomeet', color: Color(0xFF7C3AED), icon: Icons.videocam_rounded),
                          ],
                        ),
                      ],
                    ),
                  ),
                  IconButton(tooltip: tr('Fermer'), onPressed: () => Navigator.pop(context), icon: const Icon(Icons.close_rounded)),
                ],
              ),
            ),
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(22, 4, 22, 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    line(
                      Icons.schedule_rounded,
                      Text(
                        tr(
                          it.allDay
                              ? (isoDate(it.start) == isoDate(it.end) || it.end.difference(it.start).inHours < 24
                                    ? '${agDayLabel(it.start)} · toute la journée'
                                    : 'Du ${agDayLabel(it.start)} au ${agDayLabel(it.end)}')
                              : '${agDayLabel(it.start)}\n${_hm(it.start)} – ${isoDate(it.end) != isoDate(it.start) ? '${agDayLabel(it.end)} ' : ''}${_hm(it.end)}${e?['recurrence'] is Map ? '\n${agRecurrenceLabel(asMap(e!['recurrence']))}' : ''}',
                        ),
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ),
                    if (e?['masked'] == true) line(Icons.lock_outline_rounded, Text(tr('Événement privé d\'un collègue.'))),
                    if (res != null) line(Icons.meeting_room_outlined, Text(tr('${res['name']}'))),
                    if ((e?['location'] ?? o?['location'] ?? '').toString().trim().isNotEmpty) line(Icons.place_outlined, Text(tr('${e?['location'] ?? o?['location']}'))),
                    if (o?['staff'] != null) line(Icons.person_outline_rounded, Text(tr('Avec ${o!['staff']}'))),
                    if (link.startsWith('http'))
                      line(
                        Icons.videocam_outlined,
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            FilledButton.icon(
                              onPressed: () => safeLaunchUrl(Uri.parse(link), mode: LaunchMode.externalApplication),
                              style: FilledButton.styleFrom(backgroundColor: const Color(0xFF7C3AED)),
                              icon: const Icon(Icons.videocam_rounded, size: 18),
                              label: Text(tr(yomeet?['enabled'] == true ? 'Rejoindre Yomeet' : 'Rejoindre la réunion')),
                            ),
                            if (yomeet?['code'] != null) ...[
                              const SizedBox(height: 6),
                              SelectableText(
                                'ID ${'${yomeet!['code']}'.toUpperCase()}${yomeet['password'] != null ? ' · code d\'accès ${yomeet['password']}' : ''}',
                                style: TextStyle(color: hint, fontSize: 13),
                              ),
                              TextButton.icon(
                                onPressed: () {
                                  Clipboard.setData(
                                    ClipboardData(
                                      text:
                                          'Réunion Yomeet : ${it.title}\n${agDayLabel(it.start)}, ${_hm(it.start)}\nRejoindre : $link\nID de réunion : ${'${yomeet['code']}'.toUpperCase()}${yomeet['password'] != null ? '\nCode d\'accès : ${yomeet['password']}' : ''}',
                                    ),
                                  );
                                  toast(context, tr('Invitation copiée : collez-la dans un e-mail ou WhatsApp.'));
                                },
                                icon: const Icon(Icons.copy_rounded, size: 16),
                                label: Text(tr('Copier l\'invitation')),
                              ),
                            ] else
                              Padding(
                                padding: const EdgeInsets.only(top: 4),
                                child: SelectableText(link, style: TextStyle(color: hint, fontSize: 12.5)),
                              ),
                          ],
                        ),
                      ),
                    if ((e?['description'] ?? '').toString().trim().isNotEmpty) line(Icons.notes_rounded, Text(tr('${e!['description']}'))),
                    if (owner != null && e?['masked'] != true) line(Icons.person_outline_rounded, Text(tr('Organisé par ${owner == me ? 'vous' : names[owner] ?? '—'}'))),
                    if (attendees.isNotEmpty)
                      line(
                        Icons.group_outlined,
                        Wrap(
                          spacing: 6,
                          runSpacing: 6,
                          children: [
                            for (final a in attendees)
                              Chip(
                                visualDensity: VisualDensity.compact,
                                avatar: Icon(
                                  switch (responses['$a']) {
                                    'accepted' => Icons.check_circle,
                                    'declined' => Icons.cancel,
                                    'tentative' => Icons.help,
                                    _ => Icons.radio_button_unchecked,
                                  },
                                  size: 16,
                                  color: switch (responses['$a']) {
                                    'accepted' => YColors.success,
                                    'declined' => YColors.danger,
                                    'tentative' => YColors.warning,
                                    _ => YColors.muted,
                                  },
                                ),
                                label: Text(tr(a == me ? 'Vous' : names[a] ?? 'Utilisateur $a')),
                              ),
                          ],
                        ),
                      ),
                    if (((e?['guests'] as List?) ?? []).isNotEmpty)
                      line(
                        Icons.person_add_alt_outlined,
                        Text(
                          tr(
                            [
                              for (final g in (e!['guests'] as List)) [g['name'], g['email'] ?? g['phone']].where((x) => x != null && '$x'.isNotEmpty).join(' — '),
                            ].join('\n'),
                          ),
                        ),
                      ),
                    if (e != null && attendees.contains(me)) ...[
                      const SizedBox(height: 14),
                      SegmentedButton<String>(
                        emptySelectionAllowed: true,
                        segments: [
                          ButtonSegment(value: 'accepted', label: Text(tr('J\'y serai'))),
                          ButtonSegment(value: 'tentative', label: Text(tr('Peut-être'))),
                          ButtonSegment(value: 'declined', label: Text(tr('Je décline'))),
                        ],
                        selected: {?mine},
                        onSelectionChanged: (v) {
                          if (v.isEmpty) return;
                          context.read<SyncEngine>().save('ag_events', {
                            'id': e['id'],
                            'responses': {...responses, '$me': v.first},
                          });
                        },
                      ),
                    ],
                    if (o != null && !canEdit)
                      Padding(
                        padding: const EdgeInsets.only(top: 14),
                        child: Text(tr('À modifier dans le module ${agKinds[o['kind']] ?? ''}.'), style: TextStyle(color: hint, fontSize: 13)),
                      ),
                  ],
                ),
              ),
            ),
            // Actions rapides (comme le Calendrier du Mac)
            if (canEdit || canDelete || (e != null && e['masked'] != true))
              Container(
                padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
                decoration: BoxDecoration(
                  color: theme.brightness == Brightness.dark ? Colors.white.withValues(alpha: .03) : const Color(0xFFF7F9FC),
                  border: Border(top: BorderSide(color: theme.dividerColor.withValues(alpha: .4))),
                ),
                child: Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  alignment: WrapAlignment.end,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    if (e != null && e['masked'] != true)
                      IconButton(
                        tooltip: tr('Exporter (.ics)'),
                        onPressed: () async {
                          try {
                            final b = await s.api.download('agenda/events/${e['id']}/ics');
                            final where = await saveFile('${e['title']}.ics', b, mime: 'text/calendar');
                            if (context.mounted) toast(context, tr('Exporté${where.isEmpty ? '' : ' : $where'}'));
                          } catch (err) {
                            if (context.mounted) toast(context, errorText(err), error: true);
                          }
                        },
                        icon: const Icon(Icons.ios_share_rounded, size: 20),
                      ),
                    if (canDelete)
                      TextButton.icon(
                        onPressed: () async {
                          final nav = Navigator.of(context);
                          if (await agDelete(context, it)) nav.pop();
                        },
                        style: TextButton.styleFrom(foregroundColor: YColors.danger),
                        icon: const Icon(Icons.delete_outline_rounded, size: 18),
                        label: Text(tr(o != null ? 'Annuler' : 'Supprimer')),
                      ),
                    if (canEdit)
                      PopupMenuButton<Duration?>(
                        tooltip: tr('Reporter'),
                        onSelected: (d) async {
                          if (d != null) return postpone(d);
                          final nav = Navigator.of(context);
                          final p = await showDatePicker(context: context, initialDate: _addDays(it.start, 1), firstDate: DateTime(2020), lastDate: DateTime(2100));
                          if (p == null || !context.mounted) return;
                          final ns = DateTime(p.year, p.month, p.day, it.start.hour, it.start.minute);
                          if (await agReschedule(context, it, ns, ns.add(it.end.difference(it.start)))) nav.pop();
                        },
                        itemBuilder: (_) => [
                          if (!it.allDay) PopupMenuItem(value: const Duration(minutes: 30), child: Text(tr('+30 minutes'))),
                          if (!it.allDay) PopupMenuItem(value: const Duration(hours: 1), child: Text(tr('+1 heure'))),
                          PopupMenuItem(value: const Duration(days: 1), child: Text(tr('Au lendemain, même heure'))),
                          PopupMenuItem(value: const Duration(days: 7), child: Text(tr('À la semaine prochaine'))),
                          PopupMenuItem(value: null, child: Text(tr('Choisir une date…'))),
                        ],
                        child: IgnorePointer(
                          child: OutlinedButton.icon(onPressed: () {}, icon: const Icon(Icons.update_rounded, size: 18), label: Text(tr('Reporter'))),
                        ),
                      ),
                    if (canEdit)
                      OutlinedButton.icon(
                        onPressed: () async {
                          final nav = Navigator.of(context);
                          final r = await agPickTime(context, it);
                          if (r == null || !context.mounted) return;
                          if (await agReschedule(context, it, r.$1, r.$2)) nav.pop();
                        },
                        icon: const Icon(Icons.schedule_rounded, size: 18),
                        label: Text(tr('Heure')),
                      ),
                    if (canEdit && e != null)
                      FilledButton.icon(
                        onPressed: () {
                          Navigator.pop(context);
                          editEvent(context, event: e);
                        },
                        icon: const Icon(Icons.edit_outlined, size: 18),
                        label: Text(tr('Modifier')),
                      ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

String agReminderLabel(int m) => m == 0 ? 'à l\'heure' : (m < 60 ? '$m min avant' : (m < 1440 ? '${m ~/ 60} h avant' : '${m ~/ 1440} jour(s) avant'));

String agRecurrenceLabel(Map<String, dynamic> r) {
  final i = (r['interval'] as num?)?.toInt() ?? 1;
  var t = switch (r['freq']) {
    'daily' => i > 1 ? 'Tous les $i jours' : 'Tous les jours',
    'weekly' =>
      '${i > 1 ? 'Toutes les $i semaines' : 'Chaque semaine'}${((r['byday'] as List?) ?? []).isNotEmpty ? ' le ${((r['byday'] as List).map((d) => _days[(d as num).toInt() - 1])).join(', ')}' : ''}',
    'monthly' => i > 1 ? 'Tous les $i mois' : 'Chaque mois',
    _ => 'Chaque année',
  };
  if (r['until'] != null) t += ', jusqu\'au ${dateFr(r['until'])}';
  if (r['count'] != null) t += ', ${r['count']} fois';
  return t;
}

// ======================================================================
// Création / modification
// ======================================================================

Future<void> editEvent(BuildContext context, {Map<String, dynamic>? event, DateTime? start}) async {
  final users = await agLoadUsers(context);
  if (!context.mounted) return;
  await showFormDialog(
    context,
    title: event == null ? 'Nouvel événement' : 'Modifier l\'événement',
    icon: Icons.event_rounded,
    maxWidth: 680,
    builder: (_) => _EventForm(event: event, start: start, users: users),
  );
}

class _EventForm extends StatefulWidget {
  const _EventForm({this.event, this.start, required this.users});

  final Map<String, dynamic>? event;
  final DateTime? start;
  final List<Map<String, dynamic>> users;

  @override
  State<_EventForm> createState() => _EventFormState();
}

class _EventFormState extends State<_EventForm> {
  late final Map<String, dynamic>? e = widget.event;
  late final _title = TextEditingController(text: '${e?['title'] ?? ''}');
  late final _location = TextEditingController(text: '${e?['location'] ?? ''}');
  late final _link = TextEditingController(text: '${e?['online_link'] ?? ''}');
  late final _desc = TextEditingController(text: '${e?['description'] ?? ''}');
  late DateTime _start = DateTime.tryParse('${e?['starts_at'] ?? ''}') ?? _defaultStart(widget.start);

  // Bouton « Nouvel événement » sur un jour (minuit) : prochaine heure pleine, ou 9 h pour un autre jour
  static DateTime _defaultStart(DateTime? d) {
    final now = DateTime.now();
    if (d == null) return DateTime(now.year, now.month, now.day, now.hour + 1);
    if (d.hour != 0 || d.minute != 0) return d;
    final today = d.year == now.year && d.month == now.month && d.day == now.day;
    return today && now.hour < 22 ? DateTime(d.year, d.month, d.day, now.hour + 1) : DateTime(d.year, d.month, d.day, 9);
  }
  late DateTime _end = DateTime.tryParse('${e?['ends_at'] ?? ''}') ?? _start.add(const Duration(hours: 1));
  late bool _allDay = e?['all_day'] == true;
  late String _category = '${e?['category'] ?? 'meeting'}';
  late String? _resource = e?['resource_id'] as String?;
  late bool _private = e?['visibility'] == 'private';
  late int? _reminder = e == null ? 15 : (e!['reminder_minutes'] as num?)?.toInt();
  late final Set<int> _attendees = {...((e?['attendee_ids'] as List?) ?? []).map((x) => (x as num).toInt())};
  late final List<Map<String, TextEditingController>> _guests = [
    for (final g in ((e?['guests'] as List?) ?? []))
      {
        for (final k in ['name', 'email', 'phone']) k: TextEditingController(text: '${(g as Map)[k] ?? ''}'),
      },
  ];
  late String _freq = e?['recurrence'] is Map ? '${(e!['recurrence'] as Map)['freq']}' : 'none';
  late int _interval = e?['recurrence'] is Map ? ((e!['recurrence'] as Map)['interval'] as num?)?.toInt() ?? 1 : 1;
  late final Set<int> _byday = {...(e?['recurrence'] is Map ? (((e!['recurrence'] as Map)['byday'] as List?) ?? []) : []).map((x) => (x as num).toInt())};
  late DateTime? _until = e?['recurrence'] is Map ? DateTime.tryParse('${(e!['recurrence'] as Map)['until'] ?? ''}') : null;
  bool _notifyGuests = true;
  // Visio : aucune, Yomeet (intégrée à l'ERP), Teams, Meet ou autre lien
  late final Map<String, dynamic> _yomeet = e?['yomeet'] is Map ? Map<String, dynamic>.from(e!['yomeet'] as Map) : <String, dynamic>{};
  late String _video = _yomeet['enabled'] == true
      ? 'yomeet'
      : switch ('${e?['online_link'] ?? ''}') {
          'teams' => 'teams',
          'meet' => 'meet',
          '' => 'none',
          _ => 'link',
        };
  late final _code = TextEditingController(text: '${_yomeet['password'] ?? (math.Random.secure().nextInt(900000) + 100000)}');
  String _peopleQ = '';

  bool _opt(String k, bool def) => _yomeet[k] is bool ? _yomeet[k] as bool : def;

  Future<void> _pickDate(bool isStart) async {
    final cur = isStart ? _start : _end;
    final d = await showDatePicker(context: context, initialDate: cur, firstDate: DateTime(2000), lastDate: DateTime(2100));
    if (d == null) return;
    setState(() {
      final dur = _end.difference(_start);
      if (isStart) {
        _start = DateTime(d.year, d.month, d.day, _start.hour, _start.minute);
        _end = _start.add(dur);
      } else {
        _end = DateTime(d.year, d.month, d.day, _end.hour, _end.minute);
      }
    });
  }

  Future<void> _pickTime(bool isStart) async {
    final cur = isStart ? _start : _end;
    final t = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(cur));
    if (t == null) return;
    setState(() {
      final dur = _end.difference(_start);
      if (isStart) {
        _start = DateTime(_start.year, _start.month, _start.day, t.hour, t.minute);
        _end = _start.add(dur);
      } else {
        _end = DateTime(_end.year, _end.month, _end.day, t.hour, t.minute);
      }
    });
  }

  Map<String, dynamic>? get _rule => _freq == 'none'
      ? null
      : {
          'freq': _freq,
          if (_interval > 1) 'interval': _interval,
          if (_freq == 'weekly') 'byday': (_byday.isEmpty ? {_start.weekday} : _byday).toList()..sort(),
          if (_until != null) 'until': isoDate(_until!),
        };

  /// Contrôle local de la salle / du véhicule (le serveur refuse aussi).
  String? _resourceConflict() {
    if (_resource == null) return null;
    final s = _allDay ? _day(_start) : _start;
    final f = _allDay ? DateTime(_end.year, _end.month, _end.day, 23, 59, 59) : _end;
    final me = {'starts_at': _sql(s), 'ends_at': _sql(f), 'recurrence': _rule, 'exceptions': e?['exceptions']};
    final horizon = _rule == null ? f : _addDays(s, 366);
    final mine = agOccurrences(me, s, horizon.add(const Duration(seconds: 1)));
    for (final o in LocalDb.all('ag_events')) {
      if (o['id'] == e?['id'] || o['resource_id'] != _resource || o['status'] == 'cancelled') continue;
      for (final (os, oe) in agOccurrences(o, s, horizon.add(const Duration(seconds: 1)))) {
        for (final (ms, me2) in mine) {
          if (ms.isBefore(oe) && me2.isAfter(os)) return 'Déjà réservé le ${dateFr(isoDate(os))} de ${_hm(os)} à ${_hm(oe)} (« ${o['title']} »).';
        }
      }
    }
    return null;
  }

  /// Participants déjà pris sur le créneau (simple avertissement).
  List<String> _busy() {
    final names = {for (final u in widget.users) (u['id'] as num).toInt(): '${u['name']}'};
    final out = <String>{};
    for (final o in LocalDb.all('ag_events')) {
      if (o['id'] == e?['id'] || o['status'] == 'cancelled' || o['all_day'] == true) continue;
      final ids = {(o['owner_id'] as num?)?.toInt(), ...((o['attendee_ids'] as List?) ?? []).map((x) => (x as num).toInt())};
      final hit = _attendees.where(ids.contains);
      if (hit.isEmpty) continue;
      if (agOccurrences(o, _start, _end).isNotEmpty) out.addAll(hit.map((h) => names[h] ?? ''));
    }
    return out.where((x) => x.isNotEmpty).toList();
  }

  Future<void> _save() async {
    if (_title.text.trim().isEmpty) return toast(context, tr('Donnez un titre.'), error: true);
    if (!_allDay && !_end.isAfter(_start)) return toast(context, tr('La fin doit être après le début.'), error: true);
    final conflict = _resourceConflict();
    if (conflict != null) return toast(context, conflict, error: true);
    final guests = [
      for (final g in _guests)
        if (g.values.any((c) => c.text.trim().isNotEmpty))
          {
            for (final k in g.entries)
              if (k.value.text.trim().isNotEmpty) k.key: k.value.text.trim(),
          },
    ];
    String? t(TextEditingController c) => c.text.trim().isEmpty ? null : c.text.trim();
    final s = _allDay ? _day(_start) : _start;
    final f = _allDay ? DateTime(_end.year, _end.month, _end.day, 23, 59, 59) : _end;
    final data = {
      'id': e?['id'] ?? newId(),
      'title': _title.text.trim(),
      'starts_at': _sql(s),
      'ends_at': _sql(f),
      'all_day': _allDay,
      'category': _category,
      'location': t(_location),
      if (_video == 'teams' || _video == 'meet') 'online_link': _video,
      if (_video == 'link') 'online_link': t(_link),
      if (_video == 'none') 'online_link': null,
      if (_video == 'yomeet' && context.read<Session>().hasModule('visio'))
        'yomeet': {
          'enabled': true,
          'password': _code.text.trim().isEmpty ? null : _code.text.trim(),
          for (final k in ['lobby', 'mute_on_entry', 'guests', 'record_auto', 'ai_report']) k: _opt(k, k == 'guests' || k == 'ai_report'),
        }
      else if (_yomeet['enabled'] == true)
        'yomeet': {'enabled': false},
      'description': t(_desc),
      'resource_id': _resource,
      'visibility': _private ? 'private' : 'public',
      'reminder_minutes': _reminder,
      'attendee_ids': _attendees.toList(),
      'guests': guests,
      'recurrence': _rule,
      if (e == null) 'status': 'confirmed',
      if (guests.isNotEmpty && _notifyGuests) 'notify_guests': true,
    };
    await context.read<SyncEngine>().save('ag_events', data);
    if (!mounted) return;
    Navigator.pop(context);
    if (_video == 'yomeet' && _yomeet['code'] == null) toast(context, tr('Réunion Yomeet créée : le lien, l\'ID et le code d\'accès apparaissent dans l\'événement dès la synchronisation.'));
  }

  @override
  Widget build(BuildContext context) {
    final session = context.read<Session>();
    final me = (session.user['id'] as num?)?.toInt();
    final theme = Theme.of(context);
    final hint = theme.hintColor;
    final dark = theme.brightness == Brightness.dark;
    final resources = LocalDb.all('ag_resources').where((r) => r['active'] != false || r['id'] == _resource).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final busy = _busy();
    final catColor = agHex(agCategoryColors[_category]);
    final yomeetOn = session.hasModule('visio');

    Widget section(IconData icon, String title, List<Widget> children, {Widget? trailing}) => Container(
      margin: const EdgeInsets.only(top: 14),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
      decoration: BoxDecoration(
        color: dark ? Colors.white.withValues(alpha: .03) : const Color(0xFFF8FAFD),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: dark ? Colors.white10 : const Color(0xFFEDF1F7)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(icon, size: 18, color: theme.colorScheme.primary),
              const SizedBox(width: 8),
              Expanded(
                child: Text(tr(title), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
              ),
              ?trailing,
            ],
          ),
          const SizedBox(height: 10),
          ...children,
        ],
      ),
    );
    Widget pill(IconData icon, String text, VoidCallback onTap, {bool strong = false}) => Material(
      color: dark ? Colors.white.withValues(alpha: .05) : Colors.white,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: dark ? Colors.white12 : const Color(0xFFE3E8F0)),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 17, color: theme.colorScheme.primary),
              const SizedBox(width: 8),
              Text(tr(text), style: TextStyle(fontWeight: strong ? FontWeight.w700 : FontWeight.w600)),
            ],
          ),
        ),
      ),
    );
    Widget when(bool isStart) => Wrap(
      spacing: 8,
      runSpacing: 8,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SizedBox(
          width: 44,
          child: Text(tr(isStart ? 'Début' : 'Fin'), style: TextStyle(color: hint)),
        ),
        pill(Icons.event_rounded, agDayLabel(isStart ? _start : _end), () => _pickDate(isStart)),
        if (!_allDay) pill(Icons.schedule_rounded, _hm(isStart ? _start : _end), () => _pickTime(isStart), strong: true),
      ],
    );
    final dur = _end.difference(_start);
    final videoChoices = <String, (IconData, String, Color)>{
      'yomeet': (Icons.videocam_rounded, yomeetOn ? 'Yomeet · inclus' : 'Yomeet', const Color(0xFF7C3AED)),
      'none': (Icons.place_outlined, 'Sur place', YColors.muted),
      'teams': (Icons.groups_rounded, 'Teams', const Color(0xFF5B5FC7)),
      'meet': (Icons.video_call_rounded, 'Google Meet', const Color(0xFF00897B)),
      'link': (Icons.link_rounded, 'Autre lien', YColors.blue),
    };
    final users = widget.users.where((u) => (u['id'] as num).toInt() != me && (_peopleQ.isEmpty || '${u['name']}'.toLowerCase().contains(_peopleQ.toLowerCase()))).toList();

    final body = SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(22, 4, 22, 20),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Titre
          Row(
            children: [
              Container(
                width: 6,
                height: 40,
                decoration: BoxDecoration(color: catColor, borderRadius: BorderRadius.circular(3)),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: TextField(
                  controller: _title,
                  autofocus: e == null,
                  style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w800, letterSpacing: -.2),
                  decoration: InputDecoration(hintText: tr('Ajouter un titre'), border: InputBorder.none, enabledBorder: InputBorder.none, focusedBorder: InputBorder.none, filled: false),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final c in agCategories.entries)
                ChoiceChip(
                  avatar: CircleAvatar(radius: 5, backgroundColor: agHex(agCategoryColors[c.key])),
                  label: Text(tr(c.value)),
                  selected: _category == c.key,
                  onSelected: (_) => setState(() => _category = c.key),
                ),
            ],
          ),
          // Quand
          section(Icons.schedule_rounded, 'Quand', [
            when(true),
            const SizedBox(height: 8),
            when(false),
            const SizedBox(height: 4),
            Row(
              children: [
                if (!_allDay && dur.inMinutes > 0)
                  Text(tr('Durée : ${dur.inHours > 0 ? '${dur.inHours} h ' : ''}${dur.inMinutes % 60 > 0 ? '${dur.inMinutes % 60} min' : ''}'), style: TextStyle(color: hint, fontSize: 12.5)),
                const Spacer(),
                Text(tr('Toute la journée')),
                Switch(value: _allDay, onChanged: (v) => setState(() => _allDay = v)),
              ],
            ),
            Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: _freq,
                    decoration: InputDecoration(labelText: tr('Répétition'), isDense: true, prefixIcon: const Icon(Icons.repeat_rounded, size: 18)),
                    items: [
                      DropdownMenuItem(value: 'none', child: Text(tr('Ne se répète pas'))),
                      DropdownMenuItem(value: 'daily', child: Text(tr('Tous les jours'))),
                      DropdownMenuItem(value: 'weekly', child: Text(tr('Chaque semaine'))),
                      DropdownMenuItem(value: 'monthly', child: Text(tr('Chaque mois'))),
                      DropdownMenuItem(value: 'yearly', child: Text(tr('Chaque année'))),
                    ],
                    onChanged: (v) => setState(() => _freq = v!),
                  ),
                ),
                if (_freq != 'none') ...[
                  const SizedBox(width: 8),
                  SizedBox(
                    width: 96,
                    child: DropdownButtonFormField<int>(
                      initialValue: _interval,
                      decoration: InputDecoration(labelText: tr('Toutes les'), isDense: true),
                      items: [for (var i = 1; i <= 6; i++) DropdownMenuItem(value: i, child: Text(tr('$i')))],
                      onChanged: (v) => setState(() => _interval = v!),
                    ),
                  ),
                  const SizedBox(width: 8),
                  pill(Icons.event_busy_rounded, _until == null ? 'Sans fin' : 'Jusqu\'au ${dateFr(isoDate(_until!))}', () async {
                    final d = await showDatePicker(context: context, initialDate: _until ?? _addDays(_start, 90), firstDate: _start, lastDate: DateTime(2100));
                    setState(() => _until = d);
                  }),
                ],
              ],
            ),
            if (_freq == 'weekly')
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Wrap(
                  spacing: 4,
                  runSpacing: 4,
                  children: [
                    for (var d = 1; d <= 7; d++)
                      FilterChip(
                        label: Text(tr(_daysShort[d - 1])),
                        selected: _byday.isEmpty ? d == _start.weekday : _byday.contains(d),
                        onSelected: (v) => setState(() {
                          if (_byday.isEmpty) _byday.add(_start.weekday);
                          v ? _byday.add(d) : _byday.remove(d);
                        }),
                      ),
                  ],
                ),
              ),
          ]),
          // Où et comment
          section(Icons.place_outlined, 'Où et comment', [
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final v in videoChoices.entries)
                  ChoiceChip(
                    avatar: Icon(v.value.$1, size: 17, color: _video == v.key ? v.value.$3 : hint),
                    label: Text(tr(v.value.$2)),
                    selected: _video == v.key,
                    onSelected: (_) => setState(() => _video = v.key),
                  ),
              ],
            ),
            if (_video == 'yomeet' && !yomeetOn) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: const Color(0xFF7C3AED).withValues(alpha: .07), borderRadius: BorderRadius.circular(14), border: Border.all(color: const Color(0xFF7C3AED).withValues(alpha: .25))),
              child: Row(children: [
                const DialogIconBadge(icon: Icon(Icons.lock_outline_rounded), color: Color(0xFF7C3AED), size: 36),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(tr('Yomeet est la visio intégrée à l\'ERP : pas besoin de Teams, Meet ou Zoom. Activez le module « Yomeet : réunions & webinaires » pour créer la réunion ici, avec lien et code d\'accès.'), style: const TextStyle(fontSize: 13)),
                ),
                if (!AppConfig.purchasesHidden) const SizedBox(width: 8),
                if (!AppConfig.purchasesHidden)
                FilledButton(
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => Scaffold(appBar: AppBar(title: Text(tr('Boutique de modules'))), body: const StoreScreen()))),
                  child: Text(tr('Activer')),
                ),
              ]),
            ),
          ],
          if (_video == 'yomeet' && yomeetOn) ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  gradient: LinearGradient(colors: [const Color(0xFF7C3AED).withValues(alpha: .10), const Color(0xFF4F46E5).withValues(alpha: .05)]),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: const Color(0xFF7C3AED).withValues(alpha: .25)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        const DialogIconBadge(icon: Icon(Icons.videocam_rounded), color: Color(0xFF7C3AED), size: 36),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(tr('Yomeet — visio intégrée à l\'ERP'), style: const TextStyle(fontWeight: FontWeight.w800)),
                              Text(tr('Inclus dans votre abonnement : pas besoin de Teams, Meet ou Zoom. Lien, ID de réunion et code d\'accès à partager.'), style: TextStyle(color: hint, fontSize: 12.5)),
                            ],
                          ),
                        ),
                      ],
                    ),
                    if (_yomeet['url'] != null) ...[
                      const SizedBox(height: 10),
                      SelectableText('${_yomeet['url']}\nID ${'${_yomeet['code']}'.toUpperCase()}', style: const TextStyle(fontSize: 13)),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: TextButton.icon(
                          onPressed: () {
                            Clipboard.setData(
                              ClipboardData(
                                text: 'Réunion Yomeet : ${_title.text}\nRejoindre : ${_yomeet['url']}\nID de réunion : ${'${_yomeet['code']}'.toUpperCase()}\nCode d\'accès : ${_code.text}',
                              ),
                            );
                            toast(context, tr('Invitation copiée.'));
                          },
                          icon: const Icon(Icons.copy_rounded, size: 16),
                          label: Text(tr('Copier l\'invitation')),
                        ),
                      ),
                    ],
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        SizedBox(
                          width: 180,
                          child: TextField(
                            controller: _code,
                            keyboardType: TextInputType.number,
                            decoration: InputDecoration(labelText: tr('Code d\'accès'), isDense: true, prefixIcon: const Icon(Icons.lock_outline_rounded, size: 18)),
                          ),
                        ),
                        IconButton(tooltip: tr('Nouveau code'), onPressed: () => setState(() => _code.text = '${math.Random.secure().nextInt(900000) + 100000}'), icon: const Icon(Icons.refresh_rounded)),
                        Expanded(
                          child: Text(tr('Vide : sans code'), style: TextStyle(color: hint, fontSize: 12)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        for (final o in const [
                          ('lobby', 'Salle d\'attente', Icons.meeting_room_outlined, false),
                          ('mute_on_entry', 'Micro coupé à l\'entrée', Icons.mic_off_outlined, false),
                          ('guests', 'Invités externes', Icons.public_rounded, true),
                          ('record_auto', 'Enregistrement auto', Icons.fiber_manual_record_outlined, false),
                          ('ai_report', 'Compte rendu IA', Icons.auto_awesome_outlined, true),
                        ])
                          FilterChip(avatar: Icon(o.$3, size: 16), label: Text(tr(o.$2)), selected: _opt(o.$1, o.$4), onSelected: (v) => setState(() => _yomeet[o.$1] = v)),
                      ],
                    ),
                  ],
                ),
              ),
            ],
            if (_video == 'teams' || _video == 'meet')
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  tr('La réunion ${_video == 'teams' ? 'Teams' : 'Google Meet'} est créée dans votre calendrier ${_video == 'teams' ? 'Outlook' : 'Google'} connecté à la Boîte mail.'),
                  style: TextStyle(color: hint, fontSize: 12.5),
                ),
              ),
            if (_video == 'link') ...[
              const SizedBox(height: 10),
              TextField(
                controller: _link,
                decoration: InputDecoration(labelText: tr('Lien de la réunion (Zoom, Meet, Teams…)'), isDense: true, prefixIcon: const Icon(Icons.link_rounded, size: 18)),
              ),
            ],
            const SizedBox(height: 10),
            TextField(
              controller: _location,
              decoration: InputDecoration(labelText: tr('Lieu'), isDense: true, prefixIcon: const Icon(Icons.place_outlined, size: 18)),
            ),
            if (resources.isNotEmpty) ...[
              const SizedBox(height: 10),
              DropdownButtonFormField<String?>(
                initialValue: _resource,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Salle, véhicule ou matériel'), isDense: true, prefixIcon: const Icon(Icons.meeting_room_outlined, size: 18)),
                items: [
                  DropdownMenuItem<String?>(value: null, child: Text(tr('Aucun'))),
                  for (final r in resources) DropdownMenuItem<String?>(value: '${r['id']}', child: Text(tr('${r['name']}${r['capacity'] != null ? ' (${r['capacity']} pl.)' : ''}'))),
                ],
                onChanged: (v) => setState(() => _resource = v),
              ),
            ],
            if (_resourceConflict() case final c?)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(tr(c), style: const TextStyle(color: YColors.danger, fontSize: 12.5)),
              ),
          ]),
          // Participants
          section(Icons.group_outlined, 'Participants${_attendees.isNotEmpty ? ' (${_attendees.length})' : ''}', [
            if (widget.users.length > 10)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: TextField(
                  decoration: InputDecoration(hintText: tr('Rechercher un collègue'), isDense: true, prefixIcon: const Icon(Icons.search_rounded, size: 18)),
                  onChanged: (v) => setState(() => _peopleQ = v),
                ),
              ),
            if (widget.users.isEmpty) Text(tr('Liste disponible une fois connecté.'), style: TextStyle(color: hint)),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final u in users)
                  FilterChip(
                    avatar: _attendees.contains((u['id'] as num).toInt()) ? null : InitialsAvatar('${u['name']}', size: 22),
                    label: Text(tr('${u['name']}')),
                    selected: _attendees.contains((u['id'] as num).toInt()),
                    onSelected: (v) => setState(() => v ? _attendees.add((u['id'] as num).toInt()) : _attendees.remove((u['id'] as num).toInt())),
                  ),
              ],
            ),
            if (busy.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Row(
                  children: [
                    const Icon(Icons.warning_amber_rounded, size: 16, color: YColors.warning),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(tr('Déjà pris sur ce créneau : ${busy.join(', ')}'), style: const TextStyle(color: YColors.warning, fontSize: 12.5)),
                    ),
                  ],
                ),
              ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: Text(tr('Invités externes'), style: const TextStyle(fontWeight: FontWeight.w700)),
                ),
                TextButton.icon(
                  onPressed: () => setState(
                    () => _guests.add({
                      for (final k in ['name', 'email', 'phone']) k: TextEditingController(),
                    }),
                  ),
                  icon: const Icon(Icons.person_add_alt, size: 18),
                  label: Text(tr('Ajouter')),
                ),
              ],
            ),
            for (final (i, g) in _guests.indexed)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: g['name'],
                        decoration: InputDecoration(labelText: tr('Nom'), isDense: true),
                      ),
                    ),
                    const SizedBox(width: 6),
                    Expanded(
                      child: TextField(
                        controller: g['email'],
                        keyboardType: TextInputType.emailAddress,
                        decoration: InputDecoration(labelText: tr('E-mail'), isDense: true),
                      ),
                    ),
                    const SizedBox(width: 6),
                    Expanded(
                      child: TextField(
                        controller: g['phone'],
                        keyboardType: TextInputType.phone,
                        decoration: InputDecoration(labelText: tr('Téléphone'), isDense: true),
                      ),
                    ),
                    IconButton(onPressed: () => setState(() => _guests.removeAt(i)), icon: const Icon(Icons.close, size: 18)),
                  ],
                ),
              ),
            if (_guests.isNotEmpty)
              CheckboxListTile(
                contentPadding: EdgeInsets.zero,
                dense: true,
                value: _notifyGuests,
                onChanged: (v) => setState(() => _notifyGuests = v ?? true),
                title: Text(tr('Envoyer l\'invitation aux invités (e-mail avec .ics, sinon WhatsApp ou SMS)')),
              ),
          ]),
          // Détails
          section(Icons.notes_rounded, 'Détails', [
            TextField(
              controller: _desc,
              maxLines: 4,
              minLines: 2,
              decoration: InputDecoration(labelText: tr('Description, ordre du jour'), alignLabelWithHint: true),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<int?>(
                    initialValue: _reminder,
                    decoration: InputDecoration(labelText: tr('Rappel'), isDense: true, prefixIcon: const Icon(Icons.notifications_none_rounded, size: 18)),
                    items: [
                      DropdownMenuItem<int?>(value: null, child: Text(tr('Aucun'))),
                      for (final m in [0, 10, 15, 30, 60, 120, 1440]) DropdownMenuItem<int?>(value: m, child: Text(tr(agReminderLabel(m)))),
                    ],
                    onChanged: (v) => setState(() => _reminder = v),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    value: _private,
                    onChanged: (v) => setState(() => _private = v),
                    title: Text(tr('Privé')),
                    subtitle: Text(tr('« Occupé » pour les autres')),
                  ),
                ),
              ],
            ),
          ]),
        ],
      ),
    );

    return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: body),
            Container(
              padding: const EdgeInsets.fromLTRB(20, 12, 20, 14),
              decoration: BoxDecoration(
                color: dark ? Colors.white.withValues(alpha: .03) : const Color(0xFFF7F9FC),
                border: Border(top: BorderSide(color: dark ? Colors.white10 : const Color(0xFFEDF1F7))),
              ),
              child: Row(
                children: [
                  if (e == null && _video == 'yomeet')
                    Expanded(
                      child: Text(tr('Le lien Yomeet est créé à l\'enregistrement.'), style: TextStyle(color: hint, fontSize: 12.5)),
                    )
                  else
                    const Spacer(),
                  TextButton(onPressed: () => Navigator.pop(context), child: Text(tr('Annuler'))),
                  const SizedBox(width: 8),
                  FilledButton.icon(onPressed: _save, icon: const Icon(Icons.check_rounded, size: 18), label: Text(tr('Enregistrer'))),
                ],
              ),
            ),
          ],
        );
  }
}

// ======================================================================
// Abonnement et ressources
// ======================================================================

Future<void> showFeedDialog(BuildContext context) async {
  final api = context.read<Session>().api;
  String? url;
  try {
    url = '${(await api.get('agenda/feed'))['data']['url']}';
  } catch (e) {
    if (context.mounted) toast(context, errorText(e), error: true);
    return;
  }
  if (!context.mounted) return;
  await showDialog(
    context: context,
    builder: (d) => StatefulBuilder(
      builder: (d, set) => YAlertDialog(
        title: Text(tr('Mon agenda dans Google, Outlook ou le téléphone')),
        content: SizedBox(
          width: 560,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                tr(
                  'Abonnez-vous à ce lien personnel : vos réunions et rendez-vous s\'affichent dans votre agenda habituel et se mettent à jour automatiquement (toutes les quelques heures selon l\'application).',
                ),
              ),
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Theme.of(d).colorScheme.surfaceContainerHighest, borderRadius: BorderRadius.circular(8)),
                child: SelectableText(tr(url!), style: const TextStyle(fontSize: 12.5)),
              ),
              const SizedBox(height: 10),
              Text(
                tr(
                  '• Google Agenda (ordinateur) : « Autres agendas » › « + » › « À partir de l\'URL ».\n• Outlook : « Ajouter un calendrier » › « S\'abonner à partir du web ».\n• iPhone : Réglages › Calendrier › Comptes › « Ajouter un calendrier avec abonnement ».',
                ),
                style: TextStyle(fontSize: 13),
              ),
              const SizedBox(height: 8),
              Text(tr('Ce lien est personnel : ne le partagez pas. Générez-en un nouveau si besoin, l\'ancien cessera de fonctionner.'), style: TextStyle(fontSize: 12, color: Theme.of(d).hintColor)),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () async {
              try {
                final r = await api.post('agenda/feed', {'renew': true});
                set(() => url = '${r['data']['url']}');
              } catch (e) {
                if (d.mounted) toast(d, errorText(e), error: true);
              }
            },
            child: Text(tr('Nouveau lien')),
          ),
          TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Fermer'))),
          FilledButton.icon(
            onPressed: () {
              Clipboard.setData(ClipboardData(text: url!));
              toast(d, tr('Lien copié.'));
            },
            icon: const Icon(Icons.copy, size: 18),
            label: Text(tr('Copier')),
          ),
        ],
      ),
    ),
  );
}

Future<void> showResourcesDialog(BuildContext context) => showDialog(
  context: context,
  builder: (d) => YAlertDialog(
    title: Text(tr('Salles, véhicules et matériel')),
    content: SizedBox(
      width: 520,
      child: ValueListenableBuilder(
        valueListenable: LocalDb.listen('ag_resources'),
        builder: (context, _, _) {
          final rows = LocalDb.all('ag_resources')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
          return ListView(
            shrinkWrap: true,
            children: [
              if (rows.isEmpty)
                Text(
                  tr('Ajoutez vos salles de réunion, véhicules ou vidéoprojecteurs : ils se réservent depuis un événement, sans double réservation.'),
                  style: TextStyle(color: Theme.of(context).hintColor),
                ),
              for (final r in rows)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(switch (r['kind']) {
                    'vehicle' => Icons.directions_car_outlined,
                    'equipment' => Icons.devices_other_outlined,
                    _ => Icons.meeting_room_outlined,
                  }, color: r['active'] == false ? YColors.muted : agHex('${r['color'] ?? '#1F6FEB'}')),
                  title: Text(tr('${r['name']}')),
                  subtitle: Text(tr([if (r['capacity'] != null) '${r['capacity']} places', if (r['active'] == false) 'désactivé', if (r['notes'] != null) '${r['notes']}'].join(' · '))),
                  onTap: () => _editResource(context, r),
                ),
            ],
          );
        },
      ),
    ),
    actions: [
      TextButton(onPressed: () => Navigator.pop(d), child: Text(tr('Fermer'))),
      FilledButton.icon(onPressed: () => _editResource(d, null), icon: const Icon(Icons.add), label: Text(tr('Ajouter'))),
    ],
  ),
);

Future<void> _editResource(BuildContext context, Map<String, dynamic>? r) async {
  final name = TextEditingController(text: '${r?['name'] ?? ''}');
  final cap = TextEditingController(text: '${r?['capacity'] ?? ''}');
  final notes = TextEditingController(text: '${r?['notes'] ?? ''}');
  var kind = '${r?['kind'] ?? 'room'}';
  var active = r?['active'] != false;
  final ok = await showFormDialog<bool>(context, title: r == null ? 'Nouvelle ressource' : '${r['name']}', builder: (d) => StatefulBuilder(
      builder: (d, set) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: name,
                decoration: InputDecoration(labelText: tr('Nom (ex. Salle de réunion, Toyota Hilux)')),
              ),
              DropdownButtonFormField<String>(
                initialValue: kind,
                decoration: InputDecoration(labelText: tr('Type')),
                items: [
                  DropdownMenuItem(value: 'room', child: Text(tr('Salle'))),
                  DropdownMenuItem(value: 'vehicle', child: Text(tr('Véhicule'))),
                  DropdownMenuItem(value: 'equipment', child: Text(tr('Matériel'))),
                ],
                onChanged: (v) => set(() => kind = v!),
              ),
              TextField(
                controller: cap,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: tr('Places (facultatif)')),
              ),
              TextField(
                controller: notes,
                decoration: InputDecoration(labelText: tr('Remarque (étage, immatriculation…)')),
              ),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Réservable')), value: active, onChanged: (v) => set(() => active = v)),
            ],
          ),
          const SizedBox(height: 20),
          Align(
            alignment: Alignment.centerRight,
            child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
              TextButton(onPressed: () => Navigator.pop(d, false), child: Text(tr('Annuler'))),
              FilledButton(onPressed: () => Navigator.pop(d, true), child: Text(tr('Enregistrer'))),
            ]),
          ),
        ]),
      ),
    ));
  if (ok != true || name.text.trim().isEmpty || !context.mounted) return;
  await context.read<SyncEngine>().save('ag_resources', {
    'id': r?['id'] ?? newId(),
    'name': name.text.trim(),
    'kind': kind,
    'capacity': int.tryParse(cap.text),
    'notes': notes.text.trim().isEmpty ? null : notes.text.trim(),
    'active': active,
  });
}
