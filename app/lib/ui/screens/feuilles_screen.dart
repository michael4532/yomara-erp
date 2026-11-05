import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'helpdesk_screen.dart' show hdLogTime;
import 'prestations_screen.dart' as prest;
import 'projets_screen.dart' as prj;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

/// Feuilles de temps hebdomadaires : chacun envoie sa semaine, le responsable la valide.

const ttStatuses = {'draft': 'Brouillon', 'submitted': 'Envoyée — à valider', 'approved': 'Validée', 'rejected': 'À corriger'};
const ttSourceIcons = {'project': Icons.task_alt, 'pack': Icons.work_history_outlined, 'ticket': Icons.support_agent};
const ttSourceLabels = {'project': 'Projet', 'pack': 'Forfait', 'ticket': 'Ticket'};

Color ttStatusColor(String s) => switch (s) { 'submitted' => YColors.blue, 'approved' => YColors.success, 'rejected' => YColors.danger, _ => YColors.muted };

DateTime ttMonday(DateTime d) => DateTime(d.year, d.month, d.day - (d.weekday - 1));

String ttH(num v) {
  final d = v.toDouble();
  return '${d == d.roundToDouble() ? d.toInt() : d.toStringAsFixed(2).replaceAll(RegExp(r'0$'), '').replaceAll('.', ',')} h';
}

List<Map<String, dynamic>> _all(String e) => LocalDb.hasEntity(e) ? LocalDb.all(e) : <Map<String, dynamic>>[];

/// Temps de la semaine calculé sur l'appareil (mêmes règles que le serveur : temps recopiés exclus).
List<Map<String, dynamic>> ttLocalEntries(int userId, DateTime monday) {
  final from = isoDate(monday), to = isoDate(monday.add(const Duration(days: 6, hours: 12)));
  bool inWeek(dynamic d) => '$d'.compareTo(from) >= 0 && '$d'.substring(0, '$d'.length.clamp(0, 10)).compareTo(to) <= 0;
  final rows = <Map<String, dynamic>>[];
  for (final t in _all('prj_timesheets').where((t) => (t['user_id'] as num?)?.toInt() == userId && inWeek(t['date']))) {
    rows.add({'source': 'project', 'id': t['id'], 'date': '${t['date']}'.substring(0, 10), 'hours': toDouble(t['hours']), 'label': '${LocalDb.get('prj_projects', '${t['project_id']}')?['name'] ?? 'Projet'}', 'detail': t['description'] ?? '', 'billable': t['billable'] != false});
  }
  for (final e in _all('pr_entries').where((e) => (e['user_id'] as num?)?.toInt() == userId && e['timesheet_id'] == null && e['ticket_time_id'] == null && inWeek(e['date']))) {
    final p = LocalDb.get('pr_packs', '${e['pack_id']}');
    rows.add({'source': 'pack', 'id': e['id'], 'date': '${e['date']}'.substring(0, 10), 'hours': toDouble(e['hours']), 'label': p == null ? 'Forfait' : '${p['partner_name']} — ${p['name']}', 'detail': e['description'] ?? '', 'billable': true});
  }
  for (final h in _all('hd_times').where((h) => (h['user_id'] as num?)?.toInt() == userId && inWeek(h['date']))) {
    final t = LocalDb.get('hd_tickets', '${h['ticket_id']}');
    rows.add({'source': 'ticket', 'id': h['id'], 'date': '${h['date']}'.substring(0, 10), 'hours': toDouble(h['minutes']) / 60, 'label': t == null ? 'Ticket' : '${t['number'] ?? ''} — ${t['subject']}', 'detail': h['note'] ?? '', 'billable': h['billable'] != false});
  }
  rows.sort((a, b) => '${a['date']}'.compareTo('${b['date']}'));
  return rows;
}

class FeuillesScreen extends StatelessWidget {
  const FeuillesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final approver = session.can('feuilles_temps.approve');
    final narrow = MediaQuery.of(context).size.width < 700;
    return DefaultTabController(
      length: approver ? 2 : 1,
      child: Column(children: [
        PageHeader(title: tr('Feuilles de temps'), subtitle: tr('Votre semaine (projets, forfaits, tickets) envoyée pour validation')),
        if (approver)
          Padding(
            padding: EdgeInsets.symmetric(horizontal: narrow ? 16 : 24),
            child: Align(alignment: Alignment.centerLeft, child: TabBar(isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [Tab(text: tr('Ma semaine')), Tab(text: tr('À valider'))])),
          ),
        const SizedBox(height: 8),
        Expanded(child: approver ? const TabBarView(children: [_MyWeek(), _Review()]) : const _MyWeek()),
      ]),
    );
  }
}

class _MyWeek extends StatefulWidget {
  const _MyWeek();

  @override
  State<_MyWeek> createState() => _MyWeekState();
}

class _MyWeekState extends State<_MyWeek> {
  DateTime _monday = ttMonday(DateTime.now());

  Future<void> _submit(Map<String, dynamic>? w, String status, int uid) async {
    final sync = context.read<SyncEngine>();
    final name = context.read<Session>().user['name'];
    if (status == 'submitted' && !await confirm(context, tr('Envoyer la semaine ?'), tr('Une fois envoyée, la semaine est verrouillée jusqu\'à la validation (vous pouvez la retirer tant qu\'elle n\'est pas validée).'), ok: tr('Envoyer'))) return;
    await sync.save('tt_weeks', {'id': w?['id'] ?? newId(), 'week_start': isoDate(_monday), 'status': status, 'user_id': uid, 'user_name': name});
    if (mounted) toast(context, status == 'submitted' ? 'Semaine envoyée pour validation.' : 'Semaine remise en brouillon.');
  }

  Future<void> _add() async {
    final session = context.read<Session>();
    final options = <(String, IconData, Future<void> Function())>[
      if (session.hasModule('projets') && (session.can('projets.contribute') || session.can('projets.manage')))
        ('Temps sur un projet', Icons.task_alt, () async {
          final projects = _all('prj_projects').where((p) => p['status'] == 'active' || p['status'] == 'planned').toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
          if (projects.isEmpty) return toast(context, tr('Aucun projet en cours.'), error: true);
          final p = await showDialog<Map<String, dynamic>>(
            context: context,
            builder: (ctx) => SimpleDialog(title: Text(tr('Projet')), children: [for (final p in projects) SimpleDialogOption(onPressed: () => Navigator.pop(ctx, p), child: Text(tr('${p['name']}')))]),
          );
          if (p != null && mounted) await prj.logTime(context, project: p, date: _monday.isAfter(DateTime.now()) ? DateTime.now() : null);
        }),
      if (session.hasModule('prestations') && (session.can('prestations.log') || session.can('prestations.manage'))) ('Temps sur un forfait', Icons.work_history_outlined, () => prest.logTime(context)),
      if (session.hasModule('helpdesk') && (session.can('helpdesk.agent') || session.can('helpdesk.manage'))) ('Temps sur un ticket', Icons.support_agent, () => hdLogTime(context)),
    ];
    if (options.isEmpty) return toast(context, tr('Aucune source de temps disponible pour votre compte.'), error: true);
    final pick = await showModalBottomSheet<int>(
      context: context,
      builder: (ctx) => SafeArea(child: Column(mainAxisSize: MainAxisSize.min, children: [for (var i = 0; i < options.length; i++) ListTile(leading: Icon(options[i].$2), title: Text(tr(options[i].$1)), onTap: () => Navigator.pop(ctx, i))])),
    );
    if (pick != null) await options[pick].$3();
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final uid = (session.user['id'] as num?)?.toInt() ?? 0;
    final narrow = MediaQuery.of(context).size.width < 700;
    final hint = Theme.of(context).hintColor;
    return AnimatedBuilder(
      animation: Listenable.merge([LocalDb.listen('tt_weeks'), LocalDb.listen('prj_timesheets'), LocalDb.listen('pr_entries'), LocalDb.listen('hd_times')]),
      builder: (context, _) {
        final w = _all('tt_weeks').where((x) => (x['user_id'] as num?)?.toInt() == uid && '${x['week_start']}'.startsWith(isoDate(_monday))).firstOrNull;
        final status = '${w?['status'] ?? 'draft'}';
        final rows = ttLocalEntries(uid, _monday);
        final total = rows.fold<double>(0, (s, r) => s + toDouble(r['hours']));
        final billable = rows.where((r) => r['billable'] == true).fold<double>(0, (s, r) => s + toDouble(r['hours']));
        final locked = status == 'submitted' || status == 'approved';
        return ListView(padding: EdgeInsets.fromLTRB(narrow ? 16 : 24, 0, narrow ? 16 : 24, 24), children: [
          Wrap(spacing: 10, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
            IconButton.outlined(onPressed: () => setState(() => _monday = DateTime(_monday.year, _monday.month, _monday.day - 7)), icon: const Icon(Icons.chevron_left)),
            OutlinedButton(onPressed: () => setState(() => _monday = ttMonday(DateTime.now())), child: Text(tr('Cette semaine'))),
            IconButton.outlined(onPressed: () => setState(() => _monday = DateTime(_monday.year, _monday.month, _monday.day + 7)), icon: const Icon(Icons.chevron_right)),
            Text(tr('Semaine du ${DateFormat('d MMMM yyyy', I18n.dateLocale).format(_monday)}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
            StatusChip(ttStatuses[status] ?? status, color: ttStatusColor(status)),
          ]),
          const SizedBox(height: 12),
          if (status == 'rejected' && w?['comment'] != null)
            Card(color: const Color(0xFFFCEAEA), child: ListTile(leading: const Icon(Icons.feedback_outlined, color: YColors.danger), title: Text(tr('À corriger : ${w!['comment']}')), subtitle: Text(tr('Par ${w['decided_by'] ?? ''}')))),
          if (status == 'approved') Card(color: const Color(0xFFE7F6EE), child: ListTile(leading: const Icon(Icons.verified, color: YColors.success), title: Text(tr('Validée par ${w?['decided_by'] ?? ''}')), subtitle: Text(tr('Le temps de cette semaine peut être facturé.')))),
          Builder(builder: (context) {
            final narrow = MediaQuery.sizeOf(context).width < 600;
            final bars = [
              for (var i = 0; i < 7; i++)
                Expanded(
                  child: Builder(builder: (context) {
                    final d = DateTime(_monday.year, _monday.month, _monday.day + i);
                    final h = rows.where((r) => r['date'] == isoDate(d)).fold<double>(0, (s, r) => s + toDouble(r['hours']));
                    final today = isoDate(d) == isoDate(DateTime.now());
                    return Column(children: [
                      Text(tr(DateFormat(narrow ? 'EEEEE' : 'EEE', I18n.dateLocale).format(d)).toUpperCase(), style: TextStyle(fontSize: 11, color: today ? YColors.blue : hint, fontWeight: FontWeight.w700)),
                      Text('${d.day}', style: TextStyle(fontSize: 13, color: today ? YColors.blue : null, fontWeight: today ? FontWeight.w800 : FontWeight.w600)),
                      const SizedBox(height: 6),
                      Container(
                        height: 54,
                        alignment: Alignment.bottomCenter,
                        child: FractionallySizedBox(
                          heightFactor: (h / 10).clamp(0.06, 1).toDouble(),
                          child: Container(width: narrow ? 18 : 22, decoration: BoxDecoration(color: h > 10 ? YColors.danger : (h > 0 ? YColors.blue : YColors.line), borderRadius: BorderRadius.circular(5))),
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(tr(h == 0 ? '—' : ttH(h)), style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5, color: h == 0 ? hint : null)),
                    ]);
                  }),
                ),
            ];
            final totals = Column(crossAxisAlignment: narrow ? CrossAxisAlignment.start : CrossAxisAlignment.end, children: [
              Text(tr(ttH(total)), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w900)),
              Text(tr('dont ${ttH(billable)} facturables'), style: TextStyle(fontSize: 12, color: hint)),
            ]);
            return Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: narrow
                    ? Column(crossAxisAlignment: CrossAxisAlignment.start, children: [totals, const SizedBox(height: 12), Row(children: bars)])
                    : Row(children: [...bars, const SizedBox(width: 10), totals]),
              ),
            );
          }),
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (!locked) OutlinedButton.icon(onPressed: _add, icon: const Icon(Icons.more_time), label: Text(tr('Ajouter du temps'))),
            if (status == 'draft' || status == 'rejected') FilledButton.icon(onPressed: total <= 0 ? null : () => _submit(w, 'submitted', uid), icon: const Icon(Icons.send), label: Text(tr('Envoyer pour validation'))),
            if (status == 'submitted') OutlinedButton.icon(onPressed: () => _submit(w, 'draft', uid), icon: const Icon(Icons.undo), label: Text(tr('Retirer pour modifier'))),
          ]),
          if (locked) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Semaine verrouillée : le temps ne peut plus être ajouté ni modifié.'), style: TextStyle(fontSize: 12, color: hint))),
          const SizedBox(height: 10),
          if (rows.isEmpty)
            EmptyState(icon: Icons.fact_check_outlined, title: tr('Aucun temps cette semaine'), message: tr('Le temps saisi sur les projets, les forfaits et les tickets apparaît ici.'))
          else
            Card(child: Column(children: [for (final r in rows) _EntryTile(r: r)])),
        ]);
      },
    );
  }
}

class _EntryTile extends StatelessWidget {
  const _EntryTile({required this.r});

  final Map<String, dynamic> r;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      dense: true,
      leading: Icon(ttSourceIcons[r['source']] ?? Icons.schedule, color: YColors.blue),
      title: Text(tr('${r['label']}'), maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text(tr([DateFormat('EEE d MMM', I18n.dateLocale).format(DateTime.parse('${r['date']}')), ttSourceLabels[r['source']] ?? '', if ('${r['detail'] ?? ''}'.isNotEmpty) '${r['detail']}', if (r['billable'] != true) 'non facturable'].join(' · ')),
          maxLines: 2, overflow: TextOverflow.ellipsis),
      trailing: Text(tr(ttH(toDouble(r['hours']))), style: const TextStyle(fontWeight: FontWeight.w800)),
    );
  }
}

/// Feuilles envoyées par l'équipe (détail chargé du serveur).
class _Review extends StatefulWidget {
  const _Review();

  @override
  State<_Review> createState() => _ReviewState();
}

class _ReviewState extends State<_Review> {
  List<Map<String, dynamic>>? _rows;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('feuilles_temps/pending');
      if (mounted) setState(() => _rows = [for (final x in r['data'] as List) asMap(x)]);
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _decide(Map<String, dynamic> w, bool approve) async {
    String? comment;
    if (!approve) {
      final c = TextEditingController();
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => YAlertDialog(
          title: Text(tr('Renvoyer pour correction')),
          content: SizedBox(width: 420, child: TextField(controller: c, autofocus: true, maxLines: 3, decoration: InputDecoration(labelText: tr('Ce qu\'il faut corriger')))),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('Annuler'))), FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim().isNotEmpty), child: Text(tr('Renvoyer')))],
        ),
      );
      if (ok != true) return;
      comment = c.text.trim();
    }
    if (!mounted) return;
    await context.read<SyncEngine>().save('tt_weeks', {'id': w['id'], 'status': approve ? 'approved' : 'rejected', 'comment': ?comment});
    setState(() => _rows!.removeWhere((x) => x['week']['id'] == w['id']));
    if (mounted) toast(context, approve ? 'Feuille validée.' : 'Feuille renvoyée avec votre commentaire.');
  }

  @override
  Widget build(BuildContext context) {
    final hint = Theme.of(context).hintColor;
    if (_rows == null && _error == null) return const Center(child: CircularProgressIndicator());
    if (_rows == null) {
      return Center(child: Column(mainAxisSize: MainAxisSize.min, children: [Text(tr(errorText(_error!))), const SizedBox(height: 8), OutlinedButton(onPressed: _load, child: Text(tr('Réessayer')))]));
    }
    if (_rows!.isEmpty) return EmptyState(icon: Icons.fact_check_outlined, title: tr('Rien à valider'), message: tr('Les feuilles envoyées par l\'équipe apparaissent ici.'));
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.fromLTRB(24, 0, 24, 24), children: [
        for (final d in _rows!)
          Builder(builder: (context) {
            final w = asMap(d['week']);
            final days = asMap(d['days']);
            final entries = [for (final e in d['entries'] as List) asMap(e)];
            return Card(
              child: ExpansionTile(
                shape: const Border(),
                leading: CircleAvatar(child: Text(tr('${w['user_name'] ?? '?'}'.characters.first))),
                title: Text(tr('${w['user_name']} — semaine du ${dateFr(w['week_start'])}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(
                    tr('${ttH(toDouble(d['total']))} dont ${ttH(toDouble(d['billable']))} facturables · ${days.entries.map((e) => '${DateFormat('EEE', I18n.dateLocale).format(DateTime.parse(e.key))} ${toDouble(e.value) == 0 ? '—' : ttH(toDouble(e.value))}').join(' · ')}'),
                    style: TextStyle(color: hint)),
                trailing: Wrap(spacing: 6, children: [
                  OutlinedButton(onPressed: () => _decide(w, false), child: Text(tr('Renvoyer'))),
                  FilledButton.icon(onPressed: () => _decide(w, true), icon: const Icon(Icons.check), label: Text(tr('Valider'))),
                ]),
                children: [for (final e in entries) _EntryTile(r: e)],
              ),
            );
          }),
      ]),
    );
  }
}
