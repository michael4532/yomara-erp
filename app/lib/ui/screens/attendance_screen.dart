import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/json.dart';
import '../../core/local_db.dart';
import '../../files/save_file.dart';
import '../../i18n/i18n.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Fréquentation d'un salon : inscrits et entrées par jour, par borne, par heure, visiteurs uniques et revenants,
/// provenance (ville, pays, canal, « connu par », réponses), export Excel avec la liste des inscrits.

const _weekdays = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];

String _day(String iso) {
  final d = DateTime.tryParse(iso);
  return d == null ? iso : '${_weekdays[d.weekday - 1]} ${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}';
}

List<Map<String, dynamic>> _list(dynamic v) => [for (final x in (v is List ? v : const [])) asMap(x)];

/// Écran plein (depuis la borne ou la billetterie).
class AttendanceScreen extends StatelessWidget {
  const AttendanceScreen({super.key, required this.eventId});

  final String eventId;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Fréquentation — ${LocalDb.get('ev_events', eventId)?['name'] ?? ''}'))),
        body: AttendanceView(eventId: eventId),
      );
}

class AttendanceView extends StatefulWidget {
  const AttendanceView({super.key, required this.eventId});

  final String eventId;

  @override
  State<AttendanceView> createState() => _AttendanceViewState();
}

class _AttendanceViewState extends State<AttendanceView> {
  Map<String, dynamic>? _a;
  Object? _error;
  String? _hoursDay;
  bool _exporting = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await context.read<Session>().api.get('billetterie/events/${widget.eventId}/attendance');
      if (!mounted) return;
      final a = asMap(r['data']);
      final days = _list(a['days']);
      setState(() {
        _a = a;
        _error = null;
        // Heures : le jour en cours s'il fait partie de l'événement, sinon le jour le plus fréquenté
        final today = DateTime.now().toIso8601String().substring(0, 10);
        _hoursDay ??= days.any((d) => d['date'] == today) ? today : (days.isEmpty ? null : (days.toList()..sort((x, y) => toDouble(y['entries']).compareTo(toDouble(x['entries'])))).first['date'] as String?);
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _export() async {
    setState(() => _exporting = true);
    try {
      final bytes = await context.read<Session>().api.download('billetterie/events/${widget.eventId}/attendance/export');
      final name = '${_a?['event']?['name'] ?? 'evenement'}'.replaceAll(RegExp(r'[\\/:*?"<>|]'), '-');
      final where = await saveFile('Fréquentation $name.xlsx', bytes, mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      if (mounted) toast(context, tr('Fichier enregistré : $where'));
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final canExport = session.can('billetterie.manage') || session.can('evenements.manage');
    final actions = Wrap(spacing: 8, runSpacing: 8, children: [
      if (canExport)
        FilledButton.icon(
          onPressed: _exporting || _a == null ? null : _export,
          icon: _exporting ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.table_view_outlined),
          label: Text(tr('Rapport Excel (inscrits, jours, bornes, provenance)')),
        ),
      IconButton(tooltip: tr('Actualiser'), onPressed: _load, icon: const Icon(Icons.refresh)),
    ]);
    if (_error != null && _a == null) {
      return ListView(padding: const EdgeInsets.all(16), children: [actions, const SizedBox(height: 16), OnlineOnlyNotice(error: _error!, onRetry: _load)]);
    }
    final a = _a;
    if (a == null) return const LoadingSkeleton();
    final t = asMap(a['totals']);
    final days = _list(a['days']);
    final kiosks = _list(a['kiosks']);
    final narrow = MediaQuery.sizeOf(context).width < 760;
    final hint = Theme.of(context).hintColor;
    Widget kpi(String label, Object v, IconData icon, Color c, {String? sub}) => KpiBox(width: 210, child: StatTile(label: label, value: '$v', icon: icon, color: c, detail: sub));
    final best = t['best_day'] is Map ? asMap(t['best_day']) : null;
    final hoursRow = days.where((d) => d['date'] == _hoursDay).firstOrNull;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: EdgeInsets.all(narrow ? 12 : 20), children: [
        actions,
        const SizedBox(height: 14),
        KpiWrap(children: [
          kpi('Inscrits', toDouble(t['registrations']).round(), Icons.how_to_reg_outlined, YColors.blue, sub: '${toDouble(t['kiosk_registrations']).round()} aux bornes'),
          kpi('Visiteurs venus', toDouble(t['unique_visitors']).round(), Icons.groups_outlined, YColors.success, sub: 'personnes différentes'),
          kpi('Entrées (cumul des jours)', toDouble(t['entries']).round(), Icons.login, YColors.night, sub: 'moyenne ${t['average_per_day']} / jour'),
          kpi('Venus plusieurs jours', toDouble(t['returning_visitors']).round(), Icons.replay, YColors.warning),
          kpi('Inscrits pas encore venus', toDouble(t['registered_not_entered']).round(), Icons.person_off_outlined, YColors.muted),
          kpi('Avec e-mail', toDouble(t['with_email']).round(), Icons.alternate_email, YColors.blue, sub: '${toDouble(t['consent']).round()} acceptent vos infos'),
        ]),
        if (best != null)
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Text(tr('Journée la plus fréquentée : ${_day('${best['date']}')} avec ${best['entries']} visiteurs.'), style: TextStyle(color: hint)),
          ),
        const SizedBox(height: 14),
        SectionCard(
          title: tr('Par jour'),
          child: Column(children: [
            SizedBox(height: 190, child: _DayChart(days: days)),
            const SizedBox(height: 6),
            Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              _legend(YColors.blue, 'Inscrits'),
              const SizedBox(width: 16),
              _legend(YColors.success, 'Visiteurs entrés'),
            ]),
            const SizedBox(height: 10),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                columnSpacing: 22,
                headingTextStyle: const TextStyle(fontWeight: FontWeight.w800),
                columns: [
                  for (final c in ['Jour', 'Inscrits', 'Bornes', 'En ligne', 'Guichet', 'Entrés', 'Nouveaux', 'Revenants', 'Badges', 'Pointe'])
                    DataColumn(label: Text(tr(c)), numeric: c != 'Jour' && c != 'Pointe'),
                ],
                rows: [
                  for (final d in days)
                    DataRow(cells: [
                      DataCell(Text(tr(_day('${d['date']}')))),
                      for (final k in ['registrations', 'kiosk', 'online', 'box_office', 'entries', 'new_visitors', 'returning', 'badges']) DataCell(Text(tr('${toDouble(d[k]).round()}'), style: TextStyle(fontWeight: k == 'entries' || k == 'registrations' ? FontWeight.w800 : null))),
                      DataCell(Text(tr(d['peak_hour'] == null ? '—' : '${d['peak_hour']} h'))),
                    ]),
                  DataRow(cells: [
                    DataCell(Text(tr('Total'), style: const TextStyle(fontWeight: FontWeight.w900))),
                    DataCell(Text(tr('${toDouble(t['registrations']).round()}'), style: const TextStyle(fontWeight: FontWeight.w900))),
                    for (final k in ['kiosk', 'online', 'box_office']) DataCell(Text(tr('${days.fold<double>(0, (s, d) => s + toDouble(d[k])).round()}'))),
                    DataCell(Text(tr('${toDouble(t['entries']).round()}'), style: const TextStyle(fontWeight: FontWeight.w900))),
                    DataCell(Text(tr('${toDouble(t['unique_visitors']).round()}'))),
                    DataCell(Text(tr('${days.fold<double>(0, (s, d) => s + toDouble(d['returning'])).round()}'))),
                    DataCell(Text(tr('${days.fold<double>(0, (s, d) => s + toDouble(d['badges'])).round()}'))),
                    const DataCell(Text('')),
                  ]),
                ],
              ),
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(tr('Entrés : visiteurs différents passés ce jour-là (un visiteur compte une fois par jour). Nouveaux : première venue. Badges : exposants, organisation, presse non comptés dans les visiteurs.'),
                    style: TextStyle(color: hint, fontSize: 12)),
              ),
            ),
          ]),
        ),
        const SizedBox(height: 12),
        if (days.isNotEmpty)
          SectionCard(
            title: tr('Entrées par heure'),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Wrap(spacing: 6, runSpacing: 6, children: [
                for (final d in days) ChoiceChip(label: Text(tr(_day('${d['date']}'))), selected: _hoursDay == d['date'], onSelected: (_) => setState(() => _hoursDay = '${d['date']}')),
              ]),
              const SizedBox(height: 10),
              SizedBox(height: 150, child: _HourChart(hours: _list(hoursRow?['by_hour']))),
            ]),
          ),
        const SizedBox(height: 12),
        if (kiosks.isNotEmpty)
          SectionCard(
            title: tr('Par borne'),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                columnSpacing: 20,
                headingTextStyle: const TextStyle(fontWeight: FontWeight.w800),
                columns: [
                  DataColumn(label: Text(tr('Borne'))),
                  for (final d in days) DataColumn(label: Text(tr('${_day('${d['date']}')}\ninscr. / entr.')), numeric: true),
                  DataColumn(label: Text(tr('Total')), numeric: true),
                ],
                rows: [
                  for (final k in kiosks)
                    DataRow(cells: [
                      DataCell(Text(tr('${k['name']}'), style: const TextStyle(fontWeight: FontWeight.w700))),
                      for (final d in days)
                        DataCell(Builder(builder: (_) {
                          final x = _list(k['days']).where((r) => r['date'] == d['date']).firstOrNull ?? const {};
                          return Text(tr('${toDouble(x['registrations']).round()} / ${toDouble(x['entries']).round()}'));
                        })),
                      DataCell(Text(tr('${toDouble(k['registrations']).round()} / ${toDouble(k['entries']).round()}'), style: const TextStyle(fontWeight: FontWeight.w800))),
                    ]),
                ],
              ),
            ),
          ),
        const SizedBox(height: 12),
        _grid(narrow, [
          if (_list(a['cities']).isNotEmpty) SectionCard(title: tr('Villes / communes'), child: _Bars(items: _list(a['cities']), color: YColors.blue, total: toDouble(t['registrations']))),
          if (_list(a['countries']).isNotEmpty) SectionCard(title: tr('Pays'), child: _Bars(items: _list(a['countries']), color: YColors.success, total: toDouble(t['registrations']))),
          SectionCard(title: tr('Canal d\'inscription'), child: _Bars(items: _list(a['channels']), color: YColors.night, total: toDouble(t['registrations']))),
          if (_list(a['sources']).isNotEmpty)
            SectionCard(title: tr('Lien partagé (source)'), child: _Bars(items: [for (final s in _list(a['sources'])) {'name': s['source'], 'count': s['count']}], color: YColors.warning, total: toDouble(t['registrations']))),
          for (final q in _list(a['answers']).where((q) => _list(q['distribution']).isNotEmpty))
            SectionCard(title: tr('${q['label']}'), child: _Bars(items: [for (final d in _list(q['distribution'])) {'name': d['value'], 'count': d['count']}], color: YColors.blue, total: toDouble(q['count']))),
          if (_list(a['top_companies']).isNotEmpty) SectionCard(title: tr('Entreprises les plus représentées'), child: _Bars(items: _list(a['top_companies']), color: YColors.nightSoft)),
        ]),
        const SizedBox(height: 24),
      ]),
    );
  }

  Widget _legend(Color c, String l) => Row(mainAxisSize: MainAxisSize.min, children: [Container(width: 12, height: 12, decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(3))), const SizedBox(width: 6), Text(tr(l), style: const TextStyle(fontSize: 12.5))]);

  Widget _grid(bool narrow, List<Widget> cards) {
    if (narrow) return Column(children: [for (final c in cards) Padding(padding: const EdgeInsets.only(bottom: 12), child: c)]);
    final rows = <Widget>[];
    for (var i = 0; i < cards.length; i += 2) {
      rows.add(Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(child: cards[i]), const SizedBox(width: 12), Expanded(child: i + 1 < cards.length ? cards[i + 1] : const SizedBox.shrink())]),
      ));
    }
    return Column(children: rows);
  }
}

class _Bars extends StatelessWidget {
  const _Bars({required this.items, required this.color, this.total});

  final List<Map<String, dynamic>> items;
  final Color color;
  final double? total;

  @override
  Widget build(BuildContext context) {
    final max = items.fold<double>(0, (m, x) => math.max(m, toDouble(x['count'])));
    final sum = total ?? items.fold<double>(0, (s, x) => s + toDouble(x['count']));
    return Column(children: [
      for (final x in items.take(12))
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(children: [
            SizedBox(width: 140, child: Text(tr('${x['name']}'), maxLines: 1, overflow: TextOverflow.ellipsis)),
            Expanded(
              child: ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(value: max == 0 ? 0 : toDouble(x['count']) / max, minHeight: 10, color: color, backgroundColor: color.withValues(alpha: .12)),
              ),
            ),
            SizedBox(
              width: 80,
              child: Text(tr('${toDouble(x['count']).round()}${sum > 0 ? ' · ${(100 * toDouble(x['count']) / sum).round()} %' : ''}'), textAlign: TextAlign.end, style: const TextStyle(fontSize: 12.5)),
            ),
          ]),
        ),
    ]);
  }
}

/// Deux barres par jour : inscrits (bleu) et visiteurs entrés (vert).
class _DayChart extends StatelessWidget {
  const _DayChart({required this.days});

  final List<Map<String, dynamic>> days;

  @override
  Widget build(BuildContext context) => CustomPaint(painter: _DayPainter(days, Theme.of(context).hintColor), size: Size.infinite);
}

class _DayPainter extends CustomPainter {
  _DayPainter(this.days, this.hint);

  final List<Map<String, dynamic>> days;
  final Color hint;

  @override
  void paint(Canvas canvas, Size size) {
    if (days.isEmpty) return;
    const bottom = 22.0, top = 16.0;
    final h = size.height - bottom - top;
    final maxV = days.fold<double>(1, (m, d) => math.max(m, math.max(toDouble(d['registrations']), toDouble(d['entries']))));
    final w = size.width / days.length;
    final tp = TextPainter(textDirection: TextDirection.ltr);
    final bw = math.min(46.0, w * .32);
    void bar(double x, double v, Color c) {
      final bh = v / maxV * h;
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(x, top + h - bh, bw, bh), const Radius.circular(4)), Paint()..color = c);
      tp.text = TextSpan(text: '${v.round()}', style: TextStyle(color: hint, fontSize: 11, fontWeight: FontWeight.w700));
      tp.layout();
      tp.paint(canvas, Offset(x + bw / 2 - tp.width / 2, top + h - bh - 14));
    }

    for (var i = 0; i < days.length; i++) {
      final cx = i * w + w / 2;
      bar(cx - bw - 2, toDouble(days[i]['registrations']), YColors.blue);
      bar(cx + 2, toDouble(days[i]['entries']), YColors.success);
      tp.text = TextSpan(text: _day('${days[i]['date']}'), style: TextStyle(color: hint, fontSize: 11));
      tp.layout();
      tp.paint(canvas, Offset(cx - tp.width / 2, size.height - bottom + 5));
    }
  }

  @override
  bool shouldRepaint(_DayPainter old) => old.days != days;
}

class _HourChart extends StatelessWidget {
  const _HourChart({required this.hours});

  final List<Map<String, dynamic>> hours;

  @override
  Widget build(BuildContext context) {
    if (hours.isEmpty) return Center(child: Text(tr('Aucune entrée ce jour-là.'), style: TextStyle(color: Theme.of(context).hintColor)));
    // Plage d'ouverture habituelle (8 h – 18 h) au minimum, élargie si des entrées ont lieu en dehors
    final from = math.min(8, hours.map((h) => toDouble(h['hour']).toInt()).reduce(math.min));
    final to = math.max(18, hours.map((h) => toDouble(h['hour']).toInt()).reduce(math.max));
    final byH = {for (final h in hours) toDouble(h['hour']).toInt(): toDouble(h['count'])};
    final maxV = byH.values.fold<double>(1, math.max);
    final hint = Theme.of(context).hintColor;
    return Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
      for (var h = from; h <= to; h++)
        Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 3),
            child: Column(mainAxisAlignment: MainAxisAlignment.end, children: [
              Text(tr('${(byH[h] ?? 0).round()}'), style: TextStyle(fontSize: 11, color: hint)),
              const SizedBox(height: 2),
              Container(height: 100 * (byH[h] ?? 0) / maxV, decoration: BoxDecoration(color: YColors.success.withValues(alpha: .8), borderRadius: BorderRadius.circular(4))),
              const SizedBox(height: 4),
              Text(tr('$h h'), style: TextStyle(fontSize: 11, color: hint)),
            ]),
          ),
        ),
    ]);
  }
}
