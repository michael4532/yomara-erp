import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/records_offline.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/records.dart';
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Espace du service développement : tableau kanban, backlog, sprints, versions, applications.
class DevScreen extends StatefulWidget {
  const DevScreen({super.key});

  @override
  State<DevScreen> createState() => _DevScreenState();
}

class _DevScreenState extends State<DevScreen> {
  final _dash = GlobalKey<ModuleDashboardState>();

  @override
  Widget build(BuildContext context) {
    void changed() => _dash.currentState?.reload();
    final tabs = <(String, Widget)>[
      ('Tableau', _Kanban(onChanged: changed)),
      ('Backlog', RecordsView(module: 'dev', resource: 'items', emptyIcon: Icons.bug_report_outlined, newLabel: tr('Nouvelle tâche'), onChanged: changed)),
      ('Sprints', RecordsView(module: 'dev', resource: 'sprints', emptyIcon: Icons.directions_run, newLabel: tr('Nouveau sprint'), onChanged: changed)),
      ('Versions', RecordsView(module: 'dev', resource: 'releases', emptyIcon: Icons.rocket_launch_outlined, newLabel: tr('Nouvelle version'), onChanged: changed, actions: (d, row, reload) => _releaseActions(d, row, reload))),
      ('Applications', RecordsView(module: 'dev', resource: 'apps', emptyIcon: Icons.apps, newLabel: tr('Nouvelle application'), actions: (d, row, reload) => [
            if (context.read<Session>().can('dev.manage'))
              OutlinedButton.icon(
                icon: const Icon(Icons.key_outlined, size: 18),
                label: Text(tr('Nouveau secret Git')),
                onPressed: () async {
                  final api = context.read<Session>().api;
                  if (!await confirm(d, tr('Nouveau secret'), tr('L\'adresse et le secret du webhook changent : mettez-les à jour dans GitHub / GitLab.'), ok: tr('Régénérer'))) return;
                  await api.post('dev/apps/${row['id']}/git-secret');
                  if (d.mounted) Navigator.pop(d, <String, dynamic>{'secret': true});
                  reload();
                },
              ),
          ])),
    ];
    return DefaultTabController(
      length: tabs.length,
      child: Column(children: [
        PageHeader(title: tr('Développement logiciel'), subtitle: tr('Backlog, sprints, revues de code, recette et mises en production')),
        ModuleDashboard(
          key: _dash,
          path: 'dev/dashboard',
          builder: (context, d, _) {
            final sp = asMapOrNull(d['sprint']);
            return StatStrip(tiles: [
              StatTile(label: sp == null ? 'Aucun sprint en cours' : '${sp['title']}', value: sp == null ? '—' : '${sp['progress']}'.split(' (').first, detail: sp == null ? null : 'fin le ${dateFr(sp['end_date'])}', icon: Icons.directions_run),
              StatTile(label: tr('Mes tâches en cours'), value: '${d['mine']}', detail: tr('${d['in_progress']} en cours dans l\'équipe'), icon: Icons.person_outline, color: const Color(0xFF9333EA)),
              StatTile(label: tr('Revue & recette'), value: '${d['review']}', detail: tr('${d['done_week']} terminée(s) cette semaine'), icon: Icons.rate_review_outlined, color: const Color(0xFFD97706)),
              StatTile(label: tr('Bugs ouverts'), value: '${d['bugs']}', detail: tr('${d['critical']} critique(s) · ${d['releases_month']} mise(s) en prod ce mois'), icon: Icons.bug_report_outlined, color: YColors.danger),
            ]);
          },
        ),
        TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [for (final t in tabs) Tab(text: trn(t.$1))]),
        Expanded(child: TabBarView(physics: const NeverScrollableScrollPhysics(), children: [for (final t in tabs) t.$2])),
      ]),
    );
  }

  List<Widget> _releaseActions(BuildContext dialog, Map<String, dynamic> row, VoidCallback reload) {
    final s = dialog.read<Session>();
    if (!s.can('dev.manage')) return const [];
    return [
      OutlinedButton.icon(
        icon: const Icon(Icons.auto_awesome_outlined, size: 18),
        label: Text(tr('Générer les notes')),
        onPressed: () async {
          try {
            await s.api.post('dev/releases/${row['id']}/changelog');
            if (dialog.mounted) {
              Navigator.pop(dialog, <String, dynamic>{'notes': true});
              toast(dialog, tr('Notes de version générées à partir des tâches terminées.'));
            }
            reload();
          } catch (e) {
            if (dialog.mounted) toast(dialog, errorText(e), error: true);
          }
        },
      ),
    ];
  }
}

/// Tableau kanban : une colonne par statut, glisser une carte pour la faire avancer.
class _Kanban extends StatefulWidget {
  const _Kanban({required this.onChanged});

  final VoidCallback onChanged;

  @override
  State<_Kanban> createState() => _KanbanState();
}

class _KanbanState extends State<_Kanban> {
  static const _cols = ['todo', 'in_progress', 'review', 'testing', 'done'];
  RecordSchema? _s;
  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _sprints = [];
  String? _sprint;
  bool _mine = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final api = context.read<Session>().api;
    try {
      final s = await RecordSchema.load(context, 'dev');
      final sp = await RecordsOffline.get(api, 'dev/records/sprints');
      final sprints = [for (final x in ((sp as Map)['data'] as List)) asMap(x)];
      _sprint ??= sprints.where((x) => x['status'] == 'active').map((x) => '${x['id']}').firstOrNull;
      final r = await RecordsOffline.get(api, 'dev/records/items', query: {'status': _cols.join(','), 'sprint_id': ?_sprint, if (_mine) 'assignee_id': '${s.me}'});
      if (mounted) setState(() => (_s = s, _sprints = sprints, _items = [for (final x in ((r as Map)['data'] as List)) asMap(x)], _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _move(Map<String, dynamic> item, String to) async {
    if (item['status'] == to) return;
    final old = item['status'];
    setState(() => item['status'] = to);
    try {
      await RecordsOffline.send(context.read<Session>().api, 'PUT', 'dev/records/items/${item['id']}', {'status': to}, 'Tâche ${item['number'] ?? ''} ${item['title']}');
      widget.onChanged();
      _load();
    } catch (e) {
      if (mounted) {
        setState(() => item['status'] = old);
        toast(context, errorText(e), error: true);
      }
    }
  }

  Color _c(Map f, dynamic v) {
    final o = recordOption(f, v);
    final h = '${o?['color'] ?? ''}';
    final rgb = h.length == 7 && h.startsWith('#') ? int.tryParse(h.substring(1), radix: 16) : null;
    return Color(0xFF000000 | (rgb ?? 0x64748B));
  }

  @override
  Widget build(BuildContext context) {
    if (_s == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator());
    final st = _s!.field('items', 'status')!;
    final kind = _s!.field('items', 'kind')!;
    final prio = _s!.field('items', 'priority')!;
    final narrow = MediaQuery.of(context).size.width < 700;
    return Column(children: [
      Padding(
        padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 14, 24, 10),
        child: Wrap(spacing: 10, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          DropdownButton<String?>(
            value: _sprint,
            underline: const SizedBox(),
            hint: Text(tr('Toutes les tâches')),
            items: [DropdownMenuItem(value: null, child: Text(tr('Toutes les tâches'))), for (final s in _sprints) DropdownMenuItem(value: '${s['id']}', child: Text(tr('${s['title']}')))],
            onChanged: (v) => setState(() => (_sprint = v, _load())),
          ),
          FilterChip(label: Text(tr('Mes tâches')), selected: _mine, onSelected: (v) => setState(() => (_mine = v, _load()))),
          if (_s!.res('items')?['can_manage'] == true)
            FilledButton.icon(
              onPressed: () async {
                final r = await editRecord(context, _s!, 'items', initial: {'status': 'todo', if (_sprint != null) 'sprint_id': int.parse(_sprint!)});
                if (r != null) {
                  _load();
                  widget.onChanged();
                }
              },
              icon: const Icon(Icons.add, size: 18),
              label: Text(tr('Nouvelle tâche')),
            ),
          Text(tr('Glissez une carte pour changer son statut'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12.5)),
        ]),
      ),
      Expanded(
        child: SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, 24, 16),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            for (final col in _cols)
              DragTarget<Map<String, dynamic>>(
                onWillAcceptWithDetails: (d) => d.data['status'] != col,
                onAcceptWithDetails: (d) => _move(d.data, col),
                builder: (context, cand, _) {
                  final list = _items.where((i) => i['status'] == col).toList();
                  final pts = list.fold<double>(0, (a, i) => a + toDouble(i['points']));
                  return Container(
                    width: 270,
                    margin: const EdgeInsets.only(right: 12),
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: cand.isNotEmpty ? YColors.blue.withValues(alpha: .08) : Theme.of(context).hintColor.withValues(alpha: .05),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: cand.isNotEmpty ? YColors.blue : Colors.transparent),
                    ),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Row(children: [
                        Container(width: 9, height: 9, decoration: BoxDecoration(color: _c(st, col), shape: BoxShape.circle)),
                        const SizedBox(width: 6),
                        Expanded(child: Text(tr('${recordOption(st, col)?['label']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                        Text(tr('${list.length}${pts > 0 ? ' · ${number(pts)} pts' : ''}'), style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                      ]),
                      const SizedBox(height: 8),
                      ConstrainedBox(
                        constraints: BoxConstraints(minHeight: 80, maxHeight: MediaQuery.of(context).size.height - 380),
                        child: ListView(shrinkWrap: true, children: [for (final i in list) _card(i, kind, prio)]),
                      ),
                    ]),
                  );
                },
              ),
          ]),
        ),
      ),
    ]);
  }

  Widget _card(Map<String, dynamic> i, Map kind, Map prio) {
    final card = Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: () async {
          final r = await editRecord(context, _s!, 'items', row: i);
          if (r != null) {
            _load();
            widget.onChanged();
          }
        },
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              StatusChip('${recordOption(kind, i['kind'])?['label'] ?? ''}', color: _c(kind, i['kind'])),
              const Spacer(),
              if (i['priority'] == 'high' || i['priority'] == 'critical') Icon(Icons.priority_high, size: 16, color: _c(prio, i['priority'])),
              Text(tr('${i['number'] ?? ''}'), style: TextStyle(fontSize: 11.5, color: Theme.of(context).hintColor)),
            ]),
            const SizedBox(height: 6),
            Text(tr('${i['title']}'), style: const TextStyle(fontWeight: FontWeight.w600)),
            const SizedBox(height: 6),
            Row(children: [
              if (i['app_id_label'] != null) Flexible(child: Text(tr('${i['app_id_label']}'), overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11.5, color: Theme.of(context).hintColor))),
              const Spacer(),
              if (i['points'] != null)
                Container(
                  margin: const EdgeInsets.only(right: 6),
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                  decoration: BoxDecoration(color: YColors.blue.withValues(alpha: .1), borderRadius: BorderRadius.circular(8)),
                  child: Text(tr(number(toDouble(i['points']), decimals: toDouble(i['points']) % 1 == 0 ? 0 : 1)), style: const TextStyle(fontSize: 11, color: YColors.blue, fontWeight: FontWeight.w700)),
                ),
              if (i['assignee_id_label'] != null) Tooltip(message: '${i['assignee_id_label']}', child: InitialsAvatar('${i['assignee_id_label']}', size: 24)),
            ]),
          ]),
        ),
      ),
    );
    return Draggable<Map<String, dynamic>>(
      data: i,
      feedback: SizedBox(width: 250, child: Material(color: Colors.transparent, child: Opacity(opacity: .9, child: card))),
      childWhenDragging: Opacity(opacity: .3, child: card),
      child: card,
    );
  }
}
