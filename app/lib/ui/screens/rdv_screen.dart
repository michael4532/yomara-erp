import 'package:yomara_erp/ui/widgets/adaptive_sheet.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/local_db.dart';
import '../../core/sync_engine.dart';
import '../../state/session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/filter_bar.dart';
import 'portal_screen.dart' show openSalesDocument;
import '../../i18n/i18n.dart';
import '../../core/json.dart';

import 'package:yomara_erp/core/safe_launch.dart';
const rdvStatuses = {'pending': 'À confirmer', 'confirmed': 'Confirmé', 'done': 'Honoré', 'no_show': 'Absent', 'cancelled': 'Annulé'};
const rdvColors = {'pending': YColors.warning, 'confirmed': YColors.blue, 'done': YColors.success, 'no_show': YColors.danger, 'cancelled': YColors.muted};
const rdvLocations = {'onsite': 'Sur place', 'home': 'À domicile', 'online': 'En visio'};
const weekdays = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
const palette = ['#1F6FEB', '#12A150', '#E8A317', '#D93F3F', '#7A4FD6', '#0EA5A5', '#E0569B', '#5B6B85'];

Color hexColor(String? h, [Color fallback = YColors.blue]) {
  if (h == null || h.length < 7) return fallback;
  return Color(int.tryParse('FF${h.substring(1, 7)}', radix: 16) ?? 0xFF1F6FEB);
}

DateTime? _dt(dynamic v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();
DateTime _day(DateTime d) => DateTime(d.year, d.month, d.day);
String _dur(int m) => m >= 60 ? '${m ~/ 60} h${m % 60 == 0 ? '' : ' ${(m % 60).toString().padLeft(2, '0')}'}' : '$m min';

void openAppointment(BuildContext context, String id) => showAdaptiveSheet(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (c) => _AppointmentSheet(id: id),
    );

/// Membres de l'équipe visibles par l'utilisateur (un praticien ne voit que sa colonne).
List<Map<String, dynamic>> visibleStaff(Session s) {
  final all = LocalDb.all('rdv_staff').where((x) => x['active'] != false).toList()
    ..sort((a, b) => ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0) != 0
        ? ((a['sort'] as num?) ?? 0).compareTo((b['sort'] as num?) ?? 0)
        : '${a['name']}'.compareTo('${b['name']}'));
  if (s.can('rendez_vous.view') || s.can('rendez_vous.manage')) return all;
  return all.where((x) => x['user_id'] == s.user['id']).toList();
}

// ======================================================================
// Écran principal
// ======================================================================

class RdvScreen extends StatefulWidget {
  const RdvScreen({super.key});

  @override
  State<RdvScreen> createState() => _RdvScreenState();
}

class _RdvScreenState extends State<RdvScreen> with SingleTickerProviderStateMixin {
  late final bool _manage = context.read<Session>().can('rendez_vous.manage');
  late final TabController _tabs = TabController(length: _manage ? 6 : 2, vsync: this);

  @override
  Widget build(BuildContext context) {
    return Column(children: [
      PageHeader(
        title: tr('Rendez-vous'),
        subtitle: tr('Agenda de l\'équipe, réservation en ligne et rappels'),
        actions: [FilledButton.icon(onPressed: () => editAppointment(context), icon: const Icon(Icons.add), label: Text(tr('Rendez-vous')))],
      ),
      TabBar(controller: _tabs, isScrollable: true, padding: const EdgeInsets.symmetric(horizontal: 8), tabAlignment: TabAlignment.start, tabs: [
        Tab(text: tr('Agenda')),
        Tab(text: tr('Liste')),
        if (_manage) ...[Tab(text: tr('Prestations')), Tab(text: tr('Équipe & horaires')), Tab(text: tr('Page de réservation')), Tab(text: tr('Statistiques'))],
      ]),
      Expanded(
        child: ValueListenableBuilder(
          valueListenable: LocalDb.listen('rdv_appointments'),
          builder: (context, _, _) => ValueListenableBuilder(
            valueListenable: LocalDb.listen('rdv_staff'),
            // Pas de « const » : l'agenda et la liste se reconstruisent à chaque synchronisation
            builder: (context, _, _) => TabBarView(controller: _tabs, physics: const NeverScrollableScrollPhysics(), children: [
              // ignore: prefer_const_constructors
              DayAgenda(),
              // ignore: prefer_const_constructors
              _AppointmentList(),
              if (_manage) ...const [_ServicesTab(), _StaffTab(), _BookingPageTab(), _RdvStatsTab()],
            ]),
          ),
        ),
      ),
    ]);
  }
}

// ======================================================================
// Agenda du jour : une colonne par membre, une ligne par heure
// ======================================================================

class DayAgenda extends StatefulWidget {
  const DayAgenda({super.key});

  @override
  State<DayAgenda> createState() => _DayAgendaState();
}

class _DayAgendaState extends State<DayAgenda> {
  DateTime _date = _day(DateTime.now());
  final _scroll = ScrollController(initialScrollOffset: 60.0 * 1.5);
  static const _hourH = 64.0;
  static const _startH = 7;
  static const _endH = 21;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final staff = visibleStaff(s);
    final ids = staff.map((x) => x['id']).toSet();
    final appts = LocalDb.all('rdv_appointments').where((a) {
      final d = _dt(a['starts_at']);
      return d != null && _day(d) == _date && a['status'] != 'cancelled' && (ids.contains(a['staff_id']) || (a['staff_id'] == null && s.can('rendez_vous.manage')));
    }).toList();
    final unassigned = appts.where((a) => a['staff_id'] == null).toList();
    final closures = LocalDb.all('rdv_closures');
    final today = _day(DateTime.now());
    final hint = Theme.of(context).hintColor;
    final weekStrip = [for (var k = -3; k <= 10; k++) DateTime(_date.year, _date.month, _date.day + k)];

    if (staff.isEmpty) {
      return EmptyState(
        icon: Icons.groups_outlined,
        title: tr('Aucun membre dans l\'agenda'),
        message: s.can('rendez_vous.manage') ? 'Ajoutez l\'équipe et ses horaires dans « Équipe & horaires ».' : 'Demandez au responsable de vous ajouter à l\'agenda.',
      );
    }
    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
        child: Row(children: [
          IconButton(onPressed: () => setState(() => _date = DateTime(_date.year, _date.month, _date.day - 1)), icon: const Icon(Icons.chevron_left)),
          TextButton(
            onPressed: () async {
              final d = await pickDate(context, initialDate: _date, firstDate: DateTime(2020), lastDate: DateTime(2100));
              if (d != null) setState(() => _date = d);
            },
            child: Text(tr(toBeginningOfSentenceCase(DateFormat('EEEE d MMMM', I18n.dateLocale).format(_date))), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          ),
          IconButton(onPressed: () => setState(() => _date = DateTime(_date.year, _date.month, _date.day + 1)), icon: const Icon(Icons.chevron_right)),
          if (_date != today) TextButton(onPressed: () => setState(() => _date = today), child: Text(tr('Aujourd\'hui'))),
          const Spacer(),
          StatusChip(tr('${appts.length} rendez-vous'), color: YColors.blue),
          const SizedBox(width: 6),
          if (appts.any((a) => a['status'] == 'pending')) StatusChip(tr('${appts.where((a) => a['status'] == 'pending').length} à confirmer'), color: YColors.warning),
        ]),
      ),
      SizedBox(
        height: 58,
        child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
          for (final d in weekStrip)
            Padding(
              padding: const EdgeInsets.only(right: 6),
              child: InkWell(
                borderRadius: BorderRadius.circular(10),
                onTap: () => setState(() => _date = d),
                child: Container(
                  width: 52,
                  decoration: BoxDecoration(
                    color: d == _date ? YColors.blue : null,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: d == today ? YColors.blue : Theme.of(context).dividerColor.withValues(alpha: .5)),
                  ),
                  child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                    Text(tr(DateFormat('EEE', I18n.dateLocale).format(d)), style: TextStyle(fontSize: 11, color: d == _date ? Colors.white70 : hint)),
                    Text(tr('${d.day}'), style: TextStyle(fontWeight: FontWeight.w800, color: d == _date ? Colors.white : null)),
                    Builder(builder: (_) {
                      final n = LocalDb.all('rdv_appointments').where((a) => _dt(a['starts_at']) != null && _day(_dt(a['starts_at'])!) == d && a['status'] != 'cancelled' && ids.contains(a['staff_id'])).length;
                      return Container(width: 5, height: 5, decoration: BoxDecoration(shape: BoxShape.circle, color: n == 0 ? Colors.transparent : (d == _date ? Colors.white : YColors.blue)));
                    }),
                  ]),
                ),
              ),
            ),
        ]),
      ),
      if (unassigned.isNotEmpty)
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 6, 16, 0),
          child: Wrap(spacing: 6, runSpacing: 6, children: [
            Text(tr('Sans membre : '), style: TextStyle(color: hint)),
            for (final a in unassigned) ActionChip(label: Text(tr('${DateFormat('HH:mm').format(_dt(a['starts_at'])!)} ${a['customer_name'] ?? ''}')), onPressed: () => openAppointment(context, '${a['id']}')),
          ]),
        ),
      const SizedBox(height: 6),
      Expanded(
        child: LayoutBuilder(builder: (context, box) {
          const timeW = 52.0;
          final colW = ((box.maxWidth - timeW - 24) / staff.length).clamp(150.0, 420.0);
          final height = (_endH - _startH) * _hourH;
          return Card(
            margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            clipBehavior: Clip.antiAlias,
            child: Column(children: [
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                physics: const NeverScrollableScrollPhysics(),
                child: Row(children: [
                  const SizedBox(width: timeW),
                  for (final st in staff)
                    Container(
                      width: colW,
                      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                      decoration: BoxDecoration(border: Border(left: BorderSide(color: Theme.of(context).dividerColor.withValues(alpha: .4)))),
                      child: Row(children: [
                        CircleAvatar(radius: 12, backgroundColor: hexColor(st['color'] as String?), child: Text(tr('${st['name']}'.characters.first), style: const TextStyle(color: Colors.white, fontSize: 12))),
                        const SizedBox(width: 8),
                        Expanded(child: Text(tr('${st['name']}'), style: const TextStyle(fontWeight: FontWeight.w700), overflow: TextOverflow.ellipsis)),
                      ]),
                    ),
                ]),
              ),
              const Divider(height: 1),
              Expanded(
                child: SingleChildScrollView(
                  controller: _scroll,
                  child: SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: SizedBox(
                      height: height,
                      width: timeW + colW * staff.length,
                      child: Stack(children: [
                        // Lignes des heures
                        for (var h = _startH; h <= _endH; h++)
                          Positioned(
                            top: (h - _startH) * _hourH,
                            left: 0,
                            right: 0,
                            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              SizedBox(width: timeW, child: Padding(padding: const EdgeInsets.only(left: 8, top: 2), child: Text(tr('${h.toString().padLeft(2, '0')}:00'), style: TextStyle(fontSize: 11, color: hint)))),
                              Expanded(child: Container(height: 1, color: Theme.of(context).dividerColor.withValues(alpha: .35))),
                            ]),
                          ),
                        for (var k = 0; k < staff.length; k++) ...[
                          // Hors horaires : grisé ; clic sur une plage libre = nouveau rendez-vous
                          Positioned(
                            left: timeW + k * colW,
                            top: 0,
                            width: colW,
                            height: height,
                            child: GestureDetector(
                              behavior: HitTestBehavior.opaque,
                              onTapUp: (d) {
                                final minutes = (d.localPosition.dy / _hourH * 60).round();
                                final rounded = (minutes / 15).floor() * 15;
                                final at = DateTime(_date.year, _date.month, _date.day, _startH).add(Duration(minutes: rounded));
                                editAppointment(context, staffId: '${staff[k]['id']}', at: at);
                              },
                              child: CustomPaint(painter: _OffHoursPainter(staff[k], _date, _startH, _hourH, closures, Theme.of(context).colorScheme.surfaceContainerHighest.withValues(alpha: .6))),
                            ),
                          ),
                          for (final a in appts.where((a) => a['staff_id'] == staff[k]['id'])) _block(context, a, timeW + k * colW, colW),
                        ],
                        if (_date == today)
                          Positioned(
                            top: ((DateTime.now().hour + DateTime.now().minute / 60) - _startH) * _hourH,
                            left: timeW,
                            right: 0,
                            child: Container(height: 2, color: YColors.danger),
                          ),
                      ]),
                    ),
                  ),
                ),
              ),
            ]),
          );
        }),
      ),
    ]);
  }

  Widget _block(BuildContext context, Map<String, dynamic> a, double left, double width) {
    final start = _dt(a['starts_at'])!;
    final end = _dt(a['ends_at']) ?? start.add(const Duration(minutes: 30));
    final top = ((start.hour + start.minute / 60) - _startH) * _hourH;
    final h = (end.difference(start).inMinutes / 60 * _hourH).clamp(22.0, 2000.0);
    final service = LocalDb.get('rdv_services', a['service_id'] as String?);
    final color = a['status'] == 'pending' ? YColors.warning : (a['status'] == 'no_show' ? YColors.danger : hexColor(service?['color'] as String?));
    return Positioned(
      left: left + 3,
      top: top + 1,
      width: width - 6,
      height: h - 2,
      child: InkWell(
        onTap: () => openAppointment(context, '${a['id']}'),
        child: Container(
          padding: const EdgeInsets.fromLTRB(8, 4, 6, 4),
          decoration: BoxDecoration(
            color: color.withValues(alpha: a['status'] == 'done' ? .12 : .18),
            borderRadius: BorderRadius.circular(8),
            border: Border(left: BorderSide(color: color, width: 4)),
          ),
          child: ClipRect(
            child: h < 44
                ? Row(children: [
                    Text(tr(DateFormat('HH:mm').format(start)), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
                    const SizedBox(width: 6),
                    Expanded(child: Text(tr('${a['customer_name'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis)),
                    if (a['source'] != 'manual') const Icon(Icons.public, size: 12, color: YColors.muted),
                    if (a['status'] == 'pending') const Icon(Icons.hourglass_top, size: 12, color: YColors.warning),
                  ])
                : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Text(tr('${DateFormat('HH:mm').format(start)}–${DateFormat('HH:mm').format(end)}'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
                const Spacer(),
                if (a['source'] != 'manual') const Icon(Icons.public, size: 12, color: YColors.muted),
                if (a['status'] == 'done') const Icon(Icons.check_circle, size: 12, color: YColors.success),
                if (a['status'] == 'pending') const Icon(Icons.hourglass_top, size: 12, color: YColors.warning),
              ]),
              Text(tr('${a['customer_name'] ?? ''}'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13), maxLines: 1, overflow: TextOverflow.ellipsis),
              if (h > 44) Text(tr('${service?['name'] ?? ''}'), style: const TextStyle(fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis),
            ]),
          ),
        ),
      ),
    );
  }
}

class _OffHoursPainter extends CustomPainter {
  _OffHoursPainter(this.staff, this.date, this.startH, this.hourH, this.closures, this.shade);

  final Map<String, dynamic> staff;
  final DateTime date;
  final int startH;
  final double hourH;
  final List<Map<String, dynamic>> closures;
  final Color shade;

  double _y(int minutesOfDay) => (minutesOfDay / 60 - startH) * hourH;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()..color = shade;
    final ranges = (asMap(staff['schedule'])['${date.weekday}'] as List? ?? const [])
        .map((r) => (r as List).map((t) => '$t'.split(':')).map((x) => int.parse(x[0]) * 60 + int.parse(x[1])).toList())
        .toList()
      ..sort((a, b) => a[0].compareTo(b[0]));
    var cursor = startH * 60;
    for (final r in ranges) {
      if (r[0] > cursor) canvas.drawRect(Rect.fromLTRB(0, _y(cursor), size.width, _y(r[0])), p);
      cursor = r[1] > cursor ? r[1] : cursor;
    }
    canvas.drawRect(Rect.fromLTRB(0, _y(cursor), size.width, size.height), p);
    // Fermetures et absences
    final hatch = Paint()..color = YColors.danger.withValues(alpha: .08);
    for (final c in closures.where((c) => c['staff_id'] == null || c['staff_id'] == staff['id'])) {
      final s = _dt(c['starts_at']);
      final e = _dt(c['ends_at']);
      if (s == null || e == null) continue;
      final dayStart = DateTime(date.year, date.month, date.day);
      final dayEnd = dayStart.add(const Duration(days: 1));
      if (!s.isBefore(dayEnd) || !e.isAfter(dayStart)) continue;
      final from = s.isBefore(dayStart) ? 0 : s.hour * 60 + s.minute;
      final to = e.isAfter(dayEnd) ? 24 * 60 : e.hour * 60 + e.minute;
      canvas.drawRect(Rect.fromLTRB(0, _y(from).clamp(0, size.height), size.width, _y(to).clamp(0, size.height)), hatch);
    }
  }

  @override
  bool shouldRepaint(covariant _OffHoursPainter old) => true;
}

// ======================================================================
// Fiche rendez-vous (feuille)
// ======================================================================

class _AppointmentSheet extends StatelessWidget {
  const _AppointmentSheet({required this.id});

  final String id;

  Future<void> _set(BuildContext context, Map<String, dynamic> data) async {
    try {
      await context.read<SyncEngine>().save('rdv_appointments', {'id': id, ...data});
    } catch (e) {
      if (context.mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rdv_appointments'),
      builder: (context, _, _) {
        final a = LocalDb.get('rdv_appointments', id);
        if (a == null) return const SizedBox(height: 120);
        final start = _dt(a['starts_at'])!;
        final end = _dt(a['ends_at']) ?? start;
        final service = LocalDb.get('rdv_services', a['service_id'] as String?);
        final staff = LocalDb.get('rdv_staff', a['staff_id'] as String?);
        final status = '${a['status']}';
        final phone = '${a['customer_phone'] ?? ''}';
        final hint = Theme.of(context).hintColor;
        final manage = s.can('rendez_vous.manage');
        final link = a['token'] == null ? null : '${s.serverUrl}/rdv/a/${a['token']}';
        return SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(tr('${a['customer_name'] ?? 'Client'}'), style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700))),
              StatusChip(rdvStatuses[status] ?? status, color: rdvColors[status] ?? YColors.muted),
            ]),
            const SizedBox(height: 4),
            Text(
              tr('${toBeginningOfSentenceCase(DateFormat('EEEE d MMMM', I18n.dateLocale).format(start))} · ${DateFormat('HH:mm').format(start)}–${DateFormat('HH:mm').format(end)}'),
              style: const TextStyle(fontWeight: FontWeight.w600),
            ),
            Text(tr([service?['name'], if (staff != null) 'avec ${staff['name']}', a['number'], if (a['source'] == 'online') 'réservé en ligne', if (a['source'] == 'portal') 'depuis le portail client'].where((e) => e != null).join(' · ')),
                style: TextStyle(color: hint)),
            if (toDouble(a['price']) > 0) Padding(padding: const EdgeInsets.only(top: 4), child: Text(tr(money(toDouble(a['price']), s.currency)), style: const TextStyle(fontWeight: FontWeight.w700))),
            if ('${a['notes'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 10), child: Text(tr('Note du client : ${a['notes']}'))),
            if ('${a['internal_notes'] ?? ''}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('Note interne : ${a['internal_notes']}'), style: TextStyle(color: hint))),
            if (status == 'cancelled' && a['cancel_reason'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(tr('${a['cancel_reason']}'), style: const TextStyle(color: YColors.danger))),
            const SizedBox(height: 14),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (status == 'pending') FilledButton.icon(onPressed: () => _set(context, {'status': 'confirmed'}), icon: const Icon(Icons.check), label: Text(tr('Confirmer'))),
              if (['confirmed', 'pending'].contains(status)) ...[
                FilledButton.icon(style: FilledButton.styleFrom(backgroundColor: YColors.success), onPressed: () => _set(context, {'status': 'done'}), icon: const Icon(Icons.task_alt), label: Text(tr('Honoré'))),
                OutlinedButton.icon(onPressed: () => _set(context, {'status': 'no_show'}), icon: const Icon(Icons.person_off_outlined), label: Text(tr('Absent'))),
                OutlinedButton.icon(
                  onPressed: () {
                    Navigator.pop(context);
                    editAppointment(context, appointment: a);
                  },
                  icon: const Icon(Icons.edit_calendar_outlined),
                  label: Text(tr('Modifier / déplacer')),
                ),
                TextButton(
                  onPressed: () async {
                    final ctl = TextEditingController();
                    final ok = await showDialog<bool>(
                      context: context,
                      builder: (c) => YAlertDialog(
                        title: Text(tr('Annuler le rendez-vous')),
                        content: TextField(controller: ctl, decoration: InputDecoration(labelText: tr('Motif (envoyé au client)'))),
                        actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: Text(tr('Retour'))), FilledButton(onPressed: () => Navigator.pop(c, true), child: Text(tr('Annuler le rendez-vous')))],
                      ),
                    );
                    if (ok == true && context.mounted) _set(context, {'status': 'cancelled', 'cancel_reason': ctl.text.trim().isEmpty ? null : ctl.text.trim()});
                  },
                  child: Text(tr('Annuler'), style: TextStyle(color: YColors.danger)),
                ),
              ],
              if (['done', 'no_show', 'cancelled'].contains(status) && manage) TextButton(onPressed: () => _set(context, {'status': 'confirmed'}), child: Text(tr('Remettre à « confirmé »'))),
              if (manage && status == 'done' && a['invoice_id'] == null && s.hasModule('ventes'))
                FilledButton.tonalIcon(
                  onPressed: () async {
                    try {
                      final r = await s.api.post('rendez_vous/appointments/$id/invoice');
                      if (!context.mounted) return;
                      await context.read<SyncEngine>().sync();
                      if (context.mounted) openSalesDocument(context, '${r['invoice']['id']}');
                    } catch (e) {
                      if (context.mounted) toast(context, errorText(e), error: true);
                    }
                  },
                  icon: const Icon(Icons.request_quote_outlined),
                  label: Text(tr('Facturer')),
                ),
              if (a['invoice_id'] != null) OutlinedButton.icon(onPressed: () => openSalesDocument(context, '${a['invoice_id']}'), icon: const Icon(Icons.receipt_long_outlined), label: Text(tr('Facture'))),
            ]),
            const SizedBox(height: 10),
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (phone.isNotEmpty) IconButton.filledTonal(tooltip: tr('Appeler'), onPressed: () => safeLaunchUrl(Uri.parse('tel:$phone')), icon: const Icon(Icons.call)),
              if (phone.isNotEmpty)
                IconButton.filledTonal(
                  tooltip: tr('WhatsApp'),
                  onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/${phone.replaceAll(RegExp(r'\D'), '').replaceFirst(RegExp(r'^0'), '2250')}'), mode: LaunchMode.externalApplication),
                  icon: const Icon(Icons.chat_outlined),
                ),
              if (link != null)
                IconButton.filledTonal(
                  tooltip: tr('Copier le lien du client (gérer / annuler)'),
                  onPressed: () {
                    Clipboard.setData(ClipboardData(text: link));
                    toast(context, tr('Lien copié.'));
                  },
                  icon: const Icon(Icons.link),
                ),
            ]),
          ]),
        );
      },
    );
  }
}

// ======================================================================
// Formulaire rendez-vous (créneaux libres proposés en ligne)
// ======================================================================

Future<void> editAppointment(BuildContext context, {Map<String, dynamic>? appointment, String? staffId, DateTime? at}) =>
    showFormDialog(context, title: appointment == null ? 'Nouveau rendez-vous' : 'Rendez-vous ${appointment['number'] ?? ''}', builder: (c) => _AppointmentForm(a: appointment, staffId: staffId, at: at));

class _AppointmentForm extends StatefulWidget {
  const _AppointmentForm({this.a, this.staffId, this.at});

  final Map<String, dynamic>? a;
  final String? staffId;
  final DateTime? at;

  @override
  State<_AppointmentForm> createState() => _AppointmentFormState();
}

class _AppointmentFormState extends State<_AppointmentForm> {
  late String? _service = widget.a?['service_id'] as String? ?? LocalDb.all('rdv_services').where((x) => x['active'] != false).firstOrNull?['id'] as String?;
  late String? _staff = widget.a?['staff_id'] as String? ?? widget.staffId ?? visibleStaff(context.read<Session>()).firstOrNull?['id'] as String?;
  late String? _partner = widget.a?['partner_id'] as String?;
  late DateTime _date = _day(_dt(widget.a?['starts_at']) ?? widget.at ?? DateTime.now());
  late TimeOfDay? _time = () {
    final d = _dt(widget.a?['starts_at']) ?? widget.at;
    return d == null ? null : TimeOfDay(hour: d.hour, minute: d.minute);
  }();
  late final _name = TextEditingController(text: '${widget.a?['customer_name'] ?? ''}');
  late final _phone = TextEditingController(text: '${widget.a?['customer_phone'] ?? ''}');
  late final _email = TextEditingController(text: '${widget.a?['customer_email'] ?? ''}');
  late final _notes = TextEditingController(text: '${widget.a?['notes'] ?? ''}');
  late final _internal = TextEditingController(text: '${widget.a?['internal_notes'] ?? ''}');
  late final _price = TextEditingController(text: widget.a == null ? '' : '${toDouble(widget.a!['price']).round()}');
  late bool _notify = widget.a?['notify'] != false;
  List<Map<String, dynamic>>? _slots;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _loadSlots();
  }

  Future<void> _loadSlots() async {
    if (_service == null) return;
    setState(() => _loading = true);
    try {
      final r = await context.read<Session>().api.get('rendez_vous/slots', query: {
        'service_id': _service!,
        'date': isoDate(_date),
        'staff_id': ?_staff,
        if (widget.a != null) 'ignore': '${widget.a!['id']}',
      });
      if (mounted) setState(() => _slots = [for (final x in (r['data'] as List)) asMap(x)]);
    } catch (_) {
      if (mounted) setState(() => _slots = null); // hors ligne : saisie libre de l'heure
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() async {
    if (_time == null) {
      toast(context, tr('Choisissez l\'heure.'), error: true);
      return;
    }
    if (_name.text.trim().isEmpty && _partner == null) {
      toast(context, tr('Indiquez le client.'), error: true);
      return;
    }
    final service = LocalDb.get('rdv_services', _service);
    final start = DateTime(_date.year, _date.month, _date.day, _time!.hour, _time!.minute);
    final end = start.add(Duration(minutes: ((service?['duration_minutes'] as num?) ?? 30).toInt()));
    final manage = context.read<Session>().can('rendez_vous.manage');
    try {
      await context.read<SyncEngine>().save('rdv_appointments', {
        'id': widget.a?['id'] ?? newId(),
        'service_id': _service,
        'staff_id': _staff,
        'partner_id': _partner,
        'customer_name': _name.text.trim().isEmpty ? null : _name.text.trim(),
        'customer_phone': _phone.text.trim().isEmpty ? null : _phone.text.trim(),
        'customer_email': _email.text.trim().isEmpty ? null : _email.text.trim(),
        'starts_at': start.toUtc().toIso8601String(),
        'ends_at': end.toUtc().toIso8601String(),
        'notes': _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        'internal_notes': _internal.text.trim().isEmpty ? null : _internal.text.trim(),
        if (manage && _price.text.trim().isNotEmpty) 'price': toDouble(_price.text),
        'notify': _notify,
        if (widget.a == null) 'status': 'confirmed',
        if (widget.a == null) 'source': 'manual',
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.read<Session>();
    final services = LocalDb.all('rdv_services').where((x) => x['active'] != false || x['id'] == _service).toList()..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
    final service = LocalDb.get('rdv_services', _service);
    final staff = visibleStaff(s).where((x) => service == null || (service['staff_ids'] as List? ?? const []).isEmpty || (service['staff_ids'] as List).contains(x['id'])).toList();
    final partners = LocalDb.all('partners').where((p) => p['type'] != 'supplier').toList();
    return ListView(shrinkWrap: true, padding: const EdgeInsets.fromLTRB(24, 8, 24, 24), children: [
      Field(
        label: tr('Prestation'),
        child: DropdownButtonFormField<String?>(
          initialValue: services.any((x) => x['id'] == _service) ? _service : null,
          isExpanded: true,
          items: [for (final x in services) DropdownMenuItem<String?>(value: '${x['id']}', child: Text(tr('${x['name']} · ${_dur(((x['duration_minutes'] as num?) ?? 30).toInt())}'), overflow: TextOverflow.ellipsis))],
          onChanged: (v) {
            setState(() {
              _service = v;
              final sv = LocalDb.get('rdv_services', v);
              if (widget.a == null && sv != null) _price.text = '${toDouble(sv['price']).round()}';
            });
            _loadSlots();
          },
        ),
      ),
      Field(
        label: tr('Avec'),
        child: DropdownButtonFormField<String?>(
          key: ValueKey('st-$_service'),
          initialValue: staff.any((x) => x['id'] == _staff) ? _staff : null,
          isExpanded: true,
          items: [for (final x in staff) DropdownMenuItem<String?>(value: '${x['id']}', child: Text(tr('${x['name']}')))],
          onChanged: (v) {
            setState(() => _staff = v);
            _loadSlots();
          },
        ),
      ),
      Row(children: [
        Expanded(
          child: Field(
            label: tr('Date'),
            child: OutlinedButton.icon(
              onPressed: () async {
                final d = await pickDate(context, initialDate: _date, firstDate: DateTime(2020), lastDate: DateTime(2100));
                if (d == null) return;
                setState(() => _date = d);
                _loadSlots();
              },
              icon: const Icon(Icons.event, size: 18),
              label: Text(tr(DateFormat('EEE dd/MM/yyyy', I18n.dateLocale).format(_date))),
            ),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Field(
            label: tr('Heure'),
            child: OutlinedButton.icon(
              onPressed: () async {
                final t = await showTimePicker(context: context, initialTime: _time ?? const TimeOfDay(hour: 9, minute: 0));
                if (t != null) setState(() => _time = t);
              },
              icon: const Icon(Icons.schedule, size: 18),
              label: Text(tr(_time == null ? 'Choisir' : _time!.format(context))),
            ),
          ),
        ),
      ]),
      if (_loading) const LinearProgressIndicator(),
      if (_slots != null)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _slots!.isEmpty
              ? Text(tr('Aucun créneau libre ce jour (vous pouvez saisir une heure).'), style: TextStyle(color: Theme.of(context).hintColor))
              : Wrap(spacing: 6, runSpacing: 6, children: [
                  for (final sl in _slots!)
                    ChoiceChip(
                      label: Text(tr('${sl['time']}')),
                      selected: _time != null && '${_time!.hour.toString().padLeft(2, '0')}:${_time!.minute.toString().padLeft(2, '0')}' == sl['time'],
                      onSelected: (_) => setState(() {
                        final p = '${sl['time']}'.split(':');
                        _time = TimeOfDay(hour: int.parse(p[0]), minute: int.parse(p[1]));
                        if (_staff == null || !(sl['staff_ids'] as List).contains(_staff)) _staff = '${(sl['staff_ids'] as List).first}';
                      }),
                    ),
                ]),
        ),
      Field(
        label: tr('Client'),
        child: Autocomplete<Map<String, dynamic>>(
          initialValue: TextEditingValue(text: _name.text),
          optionsBuilder: (v) => v.text.length < 2 ? const [] : partners.where((p) => '${p['name']} ${p['phone'] ?? ''}'.toLowerCase().contains(v.text.toLowerCase())).take(12),
          displayStringForOption: (p) => '${p['name']}',
          onSelected: (p) => setState(() {
            _partner = '${p['id']}';
            _name.text = '${p['name']}';
            _phone.text = '${p['phone'] ?? ''}';
            _email.text = '${p['email'] ?? ''}';
          }),
          fieldViewBuilder: (c, ctl, focus, _) => TextField(
            controller: ctl,
            focusNode: focus,
            decoration: InputDecoration(hintText: tr('Nom (ou recherche dans vos contacts)')),
            onChanged: (v) {
              _name.text = v;
              _partner = null;
            },
          ),
        ),
      ),
      Row(children: [
        Expanded(child: Field(label: tr('Téléphone'), child: TextField(controller: _phone, keyboardType: TextInputType.phone))),
        const SizedBox(width: 12),
        Expanded(child: Field(label: tr('E-mail'), child: TextField(controller: _email, keyboardType: TextInputType.emailAddress))),
      ]),
      if (s.can('rendez_vous.manage')) Field(label: tr('Prix TTC'), child: TextField(controller: _price, keyboardType: TextInputType.number)),
      Field(label: tr('Note (visible du client)'), child: TextField(controller: _notes, maxLines: 2)),
      Field(label: tr('Note interne'), child: TextField(controller: _internal, maxLines: 2)),
      SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Prévenir le client')), subtitle: Text(tr('Confirmation, rappel et annulation par WhatsApp, SMS ou e-mail')), value: _notify, onChanged: (v) => setState(() => _notify = v)),
      const SizedBox(height: 8),
      Row(children: [
        if (widget.a != null && s.can('rendez_vous.manage') && widget.a!['invoice_id'] == null)
          TextButton(
            onPressed: () async {
              if (!await confirm(context, tr('Supprimer ce rendez-vous ?'), tr('Préférez « Annuler » pour garder l\'historique.'), danger: true, ok: tr('Supprimer'))) return;
              if (!context.mounted) return;
              await context.read<SyncEngine>().remove('rdv_appointments', '${widget.a!['id']}');
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
// Liste
// ======================================================================

class _AppointmentList extends StatefulWidget {
  const _AppointmentList();

  @override
  State<_AppointmentList> createState() => _AppointmentListState();
}

class _AppointmentListState extends State<_AppointmentList> {
  // '' = à venir (vue par défaut), 'all' = tous
  String _filter = '';
  String _q = '';
  int _rk = 0;
  Map<String, String?> _f = {};
  FilterPeriod? _period;

  static const _views = {'': 'À venir', 'pending': 'À confirmer', 'online': 'Réservés en ligne', 'past': 'Passés', 'all': 'Tous'};

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    final ids = visibleStaff(s).map((x) => x['id']).toSet();
    final now = DateTime.now();
    bool inView(Map<String, dynamic> a, String view) {
      final d = _dt(a['starts_at']) ?? now;
      return switch (view) {
        '' => d.isAfter(now.subtract(const Duration(hours: 1))) && ['pending', 'confirmed'].contains(a['status']),
        'pending' => a['status'] == 'pending',
        'online' => a['source'] != 'manual',
        'past' => d.isBefore(now),
        _ => true,
      };
    }

    final visible = LocalDb.all('rdv_appointments').where((a) => ids.contains(a['staff_id']) || (a['staff_id'] == null && s.can('rendez_vous.manage'))).toList();
    final base = visible
        .where((a) => _q.isEmpty || '${a['customer_name']} ${a['customer_phone']} ${a['number']}'.toLowerCase().contains(_q))
        .where((a) => _f['status'] == null || '${a['status']}' == _f['status'])
        .where((a) => _f['service'] == null || '${a['service_id'] ?? ''}' == _f['service'])
        .where((a) => _f['staff'] == null || '${a['staff_id'] ?? ''}' == _f['staff'])
        .where((a) => _f['source'] == null || '${a['source'] ?? 'manual'}' == _f['source'])
        .where((a) => _period == null || _period!.contains(a['starts_at']))
        .toList();
    final rows = base.where((a) => inView(a, _filter)).toList()
      ..sort((a, b) => _filter == 'past' ? '${b['starts_at']}'.compareTo('${a['starts_at']}') : '${a['starts_at']}'.compareTo('${b['starts_at']}'));
    return Column(children: [
      Padding(
        padding: const EdgeInsets.only(top: 12),
        child: FilterBar(
          key: ValueKey(_rk),
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
          onSearch: (v) => setState(() => _q = v.toLowerCase()),
          search: _q,
          searchHint: 'Client, téléphone…',
          statuses: _views,
          status: _filter,
          onStatus: (v) => setState(() => _filter = v),
          statusCounts: {for (final k in _views.keys) k: base.where((a) => inView(a, k)).length},
          filters: [
            const FilterSpec('status', 'Statut', rdvStatuses, icon: Icons.flag_outlined),
            FilterSpec('service', 'Prestation', filterOptions(visible, 'service_id', label: (a) => '${LocalDb.get('rdv_services', a['service_id'] as String?)?['name'] ?? a['service_id']}'), icon: Icons.design_services_outlined),
            FilterSpec('staff', 'Membre', {for (final st in visibleStaff(s)) '${st['id']}': '${st['name']}'}, icon: Icons.person_outline),
            const FilterSpec('source', 'Origine', {'manual': 'Saisi à l\'accueil', 'online': 'En ligne', 'portal': 'Portail client'}, icon: Icons.language),
          ],
          values: _f,
          onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
          showPeriod: true,
          period: _period,
          onPeriod: (p) => setState(() => _period = p),
          count: rows.length,
          countLabel: 'rendez-vous',
          onReset: () => setState(() => (_rk++, _q = '', _filter = '', _f = {}, _period = null)),
        ),
      ),
      Expanded(
        child: rows.isEmpty
            ? EmptyState(icon: Icons.event_available_outlined, title: tr('Aucun rendez-vous'), message: tr('Les rendez-vous pris en ligne ou saisis à l\'accueil apparaissent ici.'))
            : ListView(padding: const EdgeInsets.fromLTRB(16, 0, 16, 16), children: [
                for (final a in rows.take(300))
                  Card(
                    child: ListTile(
                      leading: CircleAvatar(
                        backgroundColor: (rdvColors[a['status']] ?? YColors.muted).withValues(alpha: .15),
                        child: Text(tr(DateFormat('dd').format(_dt(a['starts_at'])!)), style: TextStyle(fontWeight: FontWeight.w800, color: rdvColors[a['status']] ?? YColors.muted)),
                      ),
                      title: Text(tr('${a['customer_name'] ?? ''} · ${LocalDb.get('rdv_services', a['service_id'] as String?)?['name'] ?? ''}')),
                      subtitle: Text(tr([
                        DateFormat('EEE d MMM · HH:mm', I18n.dateLocale).format(_dt(a['starts_at'])!),
                        LocalDb.get('rdv_staff', a['staff_id'] as String?)?['name'],
                        if (a['source'] == 'online') 'en ligne',
                        if (a['source'] == 'portal') 'portail',
                      ].where((e) => e != null).join(' · '))),
                      trailing: StatusChip(rdvStatuses[a['status']] ?? '', color: rdvColors[a['status']] ?? YColors.muted),
                      onTap: () => openAppointment(context, '${a['id']}'),
                    ),
                  ),
              ]),
      ),
    ]);
  }
}

// ======================================================================
// Prestations
// ======================================================================

class _ServicesTab extends StatefulWidget {
  const _ServicesTab();

  @override
  State<_ServicesTab> createState() => _ServicesTabState();
}

class _ServicesTabState extends State<_ServicesTab> {
  String _q = '';
  int _rk = 0;
  String _status = '';
  Map<String, String?> _f = {};

  @override
  Widget build(BuildContext context) {
    final cur = context.read<Session>().currency;
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rdv_services'),
      builder: (context, _, _) {
        final all = LocalDb.all('rdv_services')..sort((a, b) => '${a['category'] ?? ''}${a['name']}'.compareTo('${b['category'] ?? ''}${b['name']}'));
        final q = _q.toLowerCase();
        final base = all
            .where((x) => q.isEmpty || '${x['name'] ?? ''} ${x['category'] ?? ''}'.toLowerCase().contains(q))
            .where((x) => _f['category'] == null || '${x['category'] ?? ''}' == _f['category'])
            .where((x) => _f['location'] == null || '${x['location'] ?? 'onsite'}' == _f['location'])
            .where((x) => _f['online'] == null || (x['online'] != false) == (_f['online'] == 'yes'))
            .where((x) => _f['staff'] == null || (x['staff_ids'] as List? ?? const []).map((e) => '$e').contains(_f['staff']))
            .toList();
        bool st(Map<String, dynamic> x, String k) => k.isEmpty || (k == 'active' ? x['active'] != false : x['active'] == false);
        final rows = base.where((x) => st(x, _status)).toList();
        return ListView(padding: const EdgeInsets.all(16), children: [
          Row(children: [
            Expanded(child: Text(tr('Les prestations « réservables en ligne » apparaissent sur votre page de réservation.'), style: TextStyle(color: Theme.of(context).hintColor))),
            FilledButton.tonalIcon(onPressed: () => _editService(context), icon: const Icon(Icons.add), label: Text(tr('Prestation'))),
          ]),
          const SizedBox(height: 12),
          if (all.isNotEmpty)
            FilterBar(
              key: ValueKey(_rk),
              padding: const EdgeInsets.only(bottom: 12),
              onSearch: (v) => setState(() => _q = v),
              search: _q,
              searchHint: 'Nom, catégorie…',
              statuses: const {'': 'Toutes', 'active': 'Actives', 'inactive': 'Désactivées'},
              status: _status,
              onStatus: (v) => setState(() => _status = v),
              statusCounts: {for (final k in ['', 'active', 'inactive']) k: base.where((x) => st(x, k)).length},
              filters: [
                FilterSpec('category', 'Catégorie', filterOptions(all, 'category'), icon: Icons.category_outlined),
                const FilterSpec('location', 'Lieu', rdvLocations, icon: Icons.place_outlined),
                const FilterSpec('online', 'En ligne', {'yes': 'Réservable en ligne', 'no': 'Pas en ligne'}, icon: Icons.public),
                FilterSpec('staff', 'Membre', {for (final m in LocalDb.all('rdv_staff')) '${m['id']}': '${m['name']}'}, icon: Icons.person_outline),
              ],
              values: _f,
              onFilter: (k, v) => setState(() => _f = {..._f, k: v}),
              count: rows.length,
              countLabel: 'prestation',
              onReset: () => setState(() => (_rk++, _q = '', _status = '', _f = {})),
            ),
          if (all.isEmpty) EmptyState(icon: Icons.design_services_outlined, title: tr('Aucune prestation'), message: tr('Exemples : coupe, consultation, séance, essai, visite…')),
          if (all.isNotEmpty && rows.isEmpty) EmptyState(icon: Icons.filter_alt_off_outlined, title: tr('Aucune prestation ne correspond aux filtres')),
          for (final x in rows)
            Card(
              child: ListTile(
                leading: Container(width: 8, height: 36, decoration: BoxDecoration(color: hexColor(x['color'] as String?), borderRadius: BorderRadius.circular(4))),
                title: Text(tr('${x['name']}'), style: TextStyle(decoration: x['active'] == false ? TextDecoration.lineThrough : null)),
                subtitle: Text(tr([
                  if (x['category'] != null) x['category'],
                  _dur(((x['duration_minutes'] as num?) ?? 30).toInt()),
                  rdvLocations[x['location']] ?? '',
                  if (x['online'] == false) 'pas en ligne',
                  if (x['requires_approval'] == true) 'sur confirmation',
                  if ((x['staff_ids'] as List? ?? const []).isNotEmpty) (x['staff_ids'] as List).map((i) => LocalDb.get('rdv_staff', '$i')?['name']).whereType<String>().join(', '),
                ].join(' · '))),
                trailing: Text(tr(toDouble(x['price']) > 0 ? money(toDouble(x['price']), cur) : '—'), style: const TextStyle(fontWeight: FontWeight.w700)),
                onTap: () => _editService(context, x),
              ),
            ),
        ]);
      },
    );
  }

  Future<void> _editService(BuildContext context, [Map<String, dynamic>? x]) async {
    final sync = context.read<SyncEngine>();
    final name = TextEditingController(text: '${x?['name'] ?? ''}');
    final cat = TextEditingController(text: '${x?['category'] ?? ''}');
    final desc = TextEditingController(text: '${x?['description'] ?? ''}');
    final dur = TextEditingController(text: '${x?['duration_minutes'] ?? 30}');
    final buf = TextEditingController(text: '${x?['buffer_minutes'] ?? 0}');
    final price = TextEditingController(text: x == null ? '' : '${toDouble(x['price']).round()}');
    var location = '${x?['location'] ?? 'onsite'}';
    var online = x?['online'] != false;
    var approval = x?['requires_approval'] == true;
    var active = x?['active'] != false;
    var color = '${x?['color'] ?? palette.first}';
    final staffIds = <String>{for (final i in (x?['staff_ids'] as List? ?? const [])) '$i'};
    final staff = LocalDb.all('rdv_staff');
    await showFormDialog(context, title: x == null ? 'Nouvelle prestation' : 'Prestation', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom (ex. Coupe & brushing)'))),
              TextField(controller: cat, decoration: InputDecoration(labelText: tr('Catégorie (facultatif)'))),
              Row(children: [
                Expanded(child: TextField(controller: dur, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Durée (min)')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: buf, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Battement après (min)')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: price, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: tr('Prix TTC')))),
              ]),
              DropdownButtonFormField<String>(
                initialValue: location,
                decoration: InputDecoration(labelText: tr('Lieu')),
                items: [for (final e in rdvLocations.entries) DropdownMenuItem(value: e.key, child: Text(tr(e.value)))],
                onChanged: (v) => set(() => location = v ?? location),
              ),
              TextField(controller: desc, maxLines: 2, decoration: InputDecoration(labelText: tr('Description (page de réservation)'))),
              const SizedBox(height: 10),
              Text(tr('Assurée par'), style: TextStyle(fontWeight: FontWeight.w600)),
              Wrap(spacing: 6, runSpacing: 6, children: [
                ChoiceChip(label: Text(tr('Toute l\'équipe')), selected: staffIds.isEmpty, onSelected: (_) => set(staffIds.clear)),
                for (final st in staff)
                  FilterChip(label: Text(tr('${st['name']}')), selected: staffIds.contains(st['id']), onSelected: (v) => set(() => v ? staffIds.add('${st['id']}') : staffIds.remove(st['id']))),
              ]),
              const SizedBox(height: 10),
              Wrap(spacing: 6, children: [
                for (final p in palette)
                  InkWell(
                    onTap: () => set(() => color = p),
                    child: Container(
                      width: 28,
                      height: 28,
                      decoration: BoxDecoration(color: hexColor(p), shape: BoxShape.circle, border: Border.all(color: color == p ? YColors.night : Colors.transparent, width: 3)),
                    ),
                  ),
              ]),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Réservable en ligne')), value: online, onChanged: (v) => set(() => online = v)),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Sur confirmation')), subtitle: Text(tr('Les demandes en ligne attendent votre accord')), value: approval, onChanged: (v) => set(() => approval = v)),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Active')), value: active, onChanged: (v) => set(() => active = v)),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                SaveButton(
                  onPressed: () async {
                    if (name.text.trim().isEmpty) return;
                    try {
                      await sync.save('rdv_services', {
                        'id': x?['id'] ?? newId(),
                        'name': name.text.trim(),
                        'category': cat.text.trim().isEmpty ? null : cat.text.trim(),
                        'description': desc.text.trim().isEmpty ? null : desc.text.trim(),
                        'duration_minutes': int.tryParse(dur.text) ?? 30,
                        'buffer_minutes': int.tryParse(buf.text) ?? 0,
                        'price': toDouble(price.text),
                        'location': location,
                        'staff_ids': staffIds.toList(),
                        'online': online,
                        'requires_approval': approval,
                        'active': active,
                        'color': color,
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
}

// ======================================================================
// Équipe, horaires et fermetures
// ======================================================================

class _StaffTab extends StatelessWidget {
  const _StaffTab();

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: LocalDb.listen('rdv_closures'),
      builder: (context, _, _) {
        final staff = LocalDb.all('rdv_staff')..sort((a, b) => '${a['name']}'.compareTo('${b['name']}'));
        final closures = LocalDb.all('rdv_closures').where((c) => (_dt(c['ends_at']) ?? DateTime(2000)).isAfter(DateTime.now())).toList()..sort((a, b) => '${a['starts_at']}'.compareTo('${b['starts_at']}'));
        final hint = Theme.of(context).hintColor;
        return ListView(padding: const EdgeInsets.all(16), children: [
          SectionCard(
            title: tr('Équipe'),
            trailing: TextButton.icon(onPressed: () => _editStaff(context), icon: const Icon(Icons.add, size: 18), label: Text(tr('Membre'))),
            child: staff.isEmpty
                ? Text(tr('Ajoutez chaque personne (ou ressource : salle, cabine, véhicule) qui reçoit des rendez-vous, avec ses horaires.'), style: TextStyle(color: hint))
                : Column(children: [
                    for (final st in staff)
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        leading: CircleAvatar(backgroundColor: hexColor(st['color'] as String?), child: Text(tr('${st['name']}'.characters.first), style: const TextStyle(color: Colors.white))),
                        title: Text(tr('${st['name']}${st['title'] != null ? ' — ${st['title']}' : ''}'), style: TextStyle(decoration: st['active'] == false ? TextDecoration.lineThrough : null)),
                        subtitle: Text(tr(_scheduleSummary(st))),
                        trailing: st['online'] == false ? StatusChip(tr('Pas en ligne')) : null,
                        onTap: () => _editStaff(context, st),
                      ),
                  ]),
          ),
          const SizedBox(height: 12),
          SectionCard(
            title: tr('Fermetures et absences à venir'),
            trailing: TextButton.icon(onPressed: () => _editClosure(context), icon: const Icon(Icons.add, size: 18), label: Text(tr('Fermeture'))),
            child: closures.isEmpty
                ? Text(tr('Jours fériés, congés, formations : aucun créneau ne sera proposé sur ces périodes.'), style: TextStyle(color: hint))
                : Column(children: [
                    for (final c in closures)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: const Icon(Icons.event_busy_outlined, color: YColors.danger),
                        title: Text(tr('${c['reason'] ?? 'Fermeture'} · ${c['staff_id'] == null ? 'tout l\'établissement' : LocalDb.get('rdv_staff', '${c['staff_id']}')?['name'] ?? ''}')),
                        subtitle: Text(tr('${dateTimeFr(c['starts_at'])} › ${dateTimeFr(c['ends_at'])}')),
                        trailing: IconButton(icon: const Icon(Icons.delete_outline), onPressed: () => context.read<SyncEngine>().remove('rdv_closures', '${c['id']}')),
                      ),
                  ]),
          ),
        ]);
      },
    );
  }

  String _scheduleSummary(Map<String, dynamic> st) {
    final sch = asMap(st['schedule']);
    final parts = <String>[];
    for (var d = 1; d <= 7; d++) {
      final r = (sch['$d'] as List? ?? const []);
      if (r.isEmpty) continue;
      parts.add('${weekdays[d - 1].substring(0, 3)} ${r.map((x) => '${(x as List)[0]}-${x[1]}').join(', ')}');
    }
    return parts.isEmpty ? 'Aucun horaire' : parts.join(' · ');
  }

  Future<void> _editStaff(BuildContext context, [Map<String, dynamic>? st]) async {
    final s = context.read<Session>();
    final sync = context.read<SyncEngine>();
    List<Map<String, dynamic>> users = [];
    try {
      final r = await s.api.get('rendez_vous/users');
      users = [for (final u in (r['users'] as List)) asMap(u)];
    } catch (_) {}
    if (!context.mounted) return;
    final name = TextEditingController(text: '${st?['name'] ?? ''}');
    final title = TextEditingController(text: '${st?['title'] ?? ''}');
    int? user = (st?['user_id'] as num?)?.toInt();
    var online = st?['online'] != false;
    var active = st?['active'] != false;
    var color = '${st?['color'] ?? palette[LocalDb.all('rdv_staff').length % palette.length]}';
    final schedule = <String, List<List<String>>>{
      for (var d = 1; d <= 7; d++)
        '$d': [
          for (final r in (((st?['schedule'] as Map?) ?? (d <= 5 ? {'$d': [['08:00', '12:30'], ['14:00', '18:00']]} : (d == 6 ? {'6': [['09:00', '13:00']]} : {})))['$d'] as List? ?? const []))
            [for (final t in (r as List)) '$t'],
        ],
    };
    Future<String?> pick(BuildContext c, String v) async {
      final p = v.split(':');
      final t = await showTimePicker(context: c, initialTime: TimeOfDay(hour: int.parse(p[0]), minute: int.parse(p[1])));
      return t == null ? null : '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';
    }

    await showFormDialog(context, title: st == null ? 'Nouveau membre' : '${st['name']}', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: TextField(controller: name, decoration: InputDecoration(labelText: tr('Nom affiché')))),
                const SizedBox(width: 8),
                Expanded(child: TextField(controller: title, decoration: InputDecoration(labelText: tr('Fonction (facultatif)')))),
              ]),
              DropdownButtonFormField<int?>(
                initialValue: users.any((u) => u['id'] == user) ? user : null,
                isExpanded: true,
                decoration: InputDecoration(labelText: tr('Utilisateur lié (voit et gère son agenda)')),
                items: [DropdownMenuItem<int?>(value: null, child: Text(tr('— Aucun —'))), for (final u in users) DropdownMenuItem<int?>(value: u['id'] as int, child: Text(tr('${u['name']}')))],
                onChanged: (v) => set(() => user = v),
              ),
              const SizedBox(height: 12),
              Text(tr('Horaires de la semaine'), style: TextStyle(fontWeight: FontWeight.w700)),
              for (var d = 1; d <= 7; d++)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
                    SizedBox(width: 90, child: Text(tr(weekdays[d - 1]))),
                    Expanded(
                      child: Wrap(spacing: 6, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                        if (schedule['$d']!.isEmpty) Text(tr('Fermé'), style: TextStyle(color: Theme.of(c).hintColor)),
                        for (var k = 0; k < schedule['$d']!.length; k++)
                          InputChip(
                            label: Text(tr('${schedule['$d']![k][0]} – ${schedule['$d']![k][1]}')),
                            onPressed: () async {
                              final a = await pick(c, schedule['$d']![k][0]);
                              if (a == null || !c.mounted) return;
                              final b = await pick(c, schedule['$d']![k][1]);
                              if (b == null || b.compareTo(a) <= 0) return;
                              set(() => schedule['$d']![k] = [a, b]);
                            },
                            onDeleted: () => set(() => schedule['$d']!.removeAt(k)),
                          ),
                        IconButton(
                          tooltip: tr('Ajouter une plage'),
                          visualDensity: VisualDensity.compact,
                          onPressed: () => set(() => schedule['$d']!.add(schedule['$d']!.isEmpty ? ['08:00', '12:00'] : ['14:00', '18:00'])),
                          icon: const Icon(Icons.add_circle_outline, size: 20),
                        ),
                      ]),
                    ),
                  ]),
                ),
              const SizedBox(height: 8),
              Wrap(spacing: 6, children: [
                for (final p in palette)
                  InkWell(
                    onTap: () => set(() => color = p),
                    child: Container(width: 26, height: 26, decoration: BoxDecoration(color: hexColor(p), shape: BoxShape.circle, border: Border.all(color: color == p ? YColors.night : Colors.transparent, width: 3))),
                  ),
              ]),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Réservable en ligne')), value: online, onChanged: (v) => set(() => online = v)),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Actif')), value: active, onChanged: (v) => set(() => active = v)),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                SaveButton(
                  onPressed: () async {
                    if (name.text.trim().isEmpty) return;
                    try {
                      await sync.save('rdv_staff', {
                        'id': st?['id'] ?? newId(),
                        'name': name.text.trim(),
                        'title': title.text.trim().isEmpty ? null : title.text.trim(),
                        'user_id': user,
                        'schedule': schedule,
                        'color': color,
                        'online': online,
                        'active': active,
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

  Future<void> _editClosure(BuildContext context) async {
    final sync = context.read<SyncEngine>();
    final reason = TextEditingController();
    String? staff;
    var from = _day(DateTime.now()).add(const Duration(days: 1));
    var to = from;
    var allDay = true;
    TimeOfDay t1 = const TimeOfDay(hour: 8, minute: 0), t2 = const TimeOfDay(hour: 12, minute: 0);
    await showFormDialog(context, title: 'Fermeture ou absence', builder: (c) => StatefulBuilder(
        builder: (c, set) => SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Column(mainAxisSize: MainAxisSize.min, children: [
              TextField(controller: reason, decoration: InputDecoration(labelText: tr('Motif (ex. Fête nationale, congé)'))),
              DropdownButtonFormField<String?>(
                initialValue: staff,
                decoration: InputDecoration(labelText: tr('Concerne')),
                items: [DropdownMenuItem<String?>(value: null, child: Text(tr('Tout l\'établissement'))), for (final st in LocalDb.all('rdv_staff')) DropdownMenuItem<String?>(value: '${st['id']}', child: Text(tr('${st['name']}')))],
                onChanged: (v) => set(() => staff = v),
              ),
              const SizedBox(height: 8),
              Row(children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () async {
                      final d = await pickDate(c, initialDate: from, firstDate: DateTime(2020), lastDate: DateTime(2100));
                      if (d != null) set(() => (from = d, to = to.isBefore(d) ? d : to));
                    },
                    child: Text(tr('Du ${DateFormat('dd/MM/yyyy').format(from)}')),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton(
                    onPressed: () async {
                      final d = await pickDate(c, initialDate: to, firstDate: from, lastDate: DateTime(2100));
                      if (d != null) set(() => to = d);
                    },
                    child: Text(tr('Au ${DateFormat('dd/MM/yyyy').format(to)}')),
                  ),
                ),
              ]),
              SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Journée entière')), value: allDay, onChanged: (v) => set(() => allDay = v)),
              if (!allDay)
                Row(children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () async {
                        final t = await showTimePicker(context: c, initialTime: t1);
                        if (t != null) set(() => t1 = t);
                      },
                      child: Text(tr('De ${t1.format(c)}')),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () async {
                        final t = await showTimePicker(context: c, initialTime: t2);
                        if (t != null) set(() => t2 = t);
                      },
                      child: Text(tr('À ${t2.format(c)}')),
                    ),
                  ),
                ]),
            ]),
            const SizedBox(height: 20),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(alignment: WrapAlignment.end, spacing: 10, runSpacing: 8, children: [
                TextButton(onPressed: () => Navigator.pop(c), child: Text(tr('Annuler'))),
                SaveButton(
                  onPressed: () async {
                    final s = allDay ? from : DateTime(from.year, from.month, from.day, t1.hour, t1.minute);
                    final e = allDay ? DateTime(to.year, to.month, to.day + 1) : DateTime(to.year, to.month, to.day, t2.hour, t2.minute);
                    if (!e.isAfter(s)) return;
                    await sync.save('rdv_closures', {'id': newId(), 'staff_id': staff, 'starts_at': s.toUtc().toIso8601String(), 'ends_at': e.toUtc().toIso8601String(), 'reason': reason.text.trim().isEmpty ? null : reason.text.trim()});
                    if (c.mounted) Navigator.pop(c);
                  },
                  child: Text(tr('Enregistrer')),
                ),
              ]),
            ),
          ]),
        ),
      ));
  }
}

// ======================================================================
// Page de réservation (réglages)
// ======================================================================

class _BookingPageTab extends StatefulWidget {
  const _BookingPageTab();

  @override
  State<_BookingPageTab> createState() => _BookingPageTabState();
}

class _BookingPageTabState extends State<_BookingPageTab> {
  Map<String, dynamic>? _info;
  Object? _error;
  final _intro = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = Map<String, dynamic>.from(await context.read<Session>().api.get('rendez_vous/info'));
      if (!mounted) return;
      _intro.text = '${context.read<Session>().companySettings['rdv_intro'] ?? ''}';
      setState(() => (_info = r, _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _put(Map<String, dynamic> settings) async {
    final s = context.read<Session>();
    try {
      await s.api.put('company', {'settings': settings});
      await s.refreshBootstrap();
      await _load();
    } catch (e) {
      if (mounted) toast(context, errorText(e), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_info == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final cfg = asMap(_info!['settings']);
    final url = '${_info!['url']}';
    Widget choice(String label, String key, num value, List<num> options, String unit) => ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(tr(label)),
          trailing: DropdownButton<num>(
            value: options.contains(value) ? value : null,
            hint: Text(tr('$value $unit')),
            items: [for (final o in options) DropdownMenuItem(value: o, child: Text(tr('$o $unit')))],
            onChanged: (v) => v == null ? null : _put({key: v}),
          ),
        );
    return ListView(padding: const EdgeInsets.all(16), children: [
      SectionCard(
        title: tr('Votre page de réservation'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SelectableText(tr(url), style: const TextStyle(fontWeight: FontWeight.w700, color: YColors.blue)),
          const SizedBox(height: 8),
          Text(tr('Partagez-la sur WhatsApp, Facebook, Instagram, Google Maps ou votre site : vos clients réservent 24 h/24 parmi les créneaux libres.'), style: TextStyle(color: Theme.of(context).hintColor)),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            FilledButton.tonalIcon(
              onPressed: () {
                Clipboard.setData(ClipboardData(text: url));
                toast(context, tr('Lien copié.'));
              },
              icon: const Icon(Icons.copy),
              label: Text(tr('Copier le lien')),
            ),
            OutlinedButton.icon(onPressed: () => safeLaunchUrl(Uri.parse(url), mode: LaunchMode.externalApplication), icon: const Icon(Icons.open_in_new), label: Text(tr('Ouvrir la page'))),
            OutlinedButton.icon(
              onPressed: () => safeLaunchUrl(Uri.parse('https://wa.me/?text=${Uri.encodeComponent('Prenez rendez-vous en ligne : $url')}'), mode: LaunchMode.externalApplication),
              icon: const Icon(Icons.chat_outlined),
              label: Text(tr('Partager sur WhatsApp')),
            ),
          ]),
        ]),
      ),
      const SizedBox(height: 12),
      SectionCard(
        title: tr('Règles'),
        child: Column(children: [
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Réservation en ligne ouverte')), value: cfg['enabled'] == true, onChanged: (v) => _put({'rdv_enabled': v})),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Confirmer automatiquement')), subtitle: Text(tr('Sinon, chaque demande attend votre accord')), value: cfg['auto_confirm'] == true, onChanged: (v) => _put({'rdv_auto_confirm': v})),
          if (context.read<Session>().hasModule('agenda')) ...[
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Rendez-vous dans l\'Agenda du praticien')), subtitle: Text(tr('Et dans son Outlook ou Google Agenda s\'il a branché sa boîte mail')), value: cfg['agenda_sync'] != false, onChanged: (v) => _put({'rdv_agenda_sync': v})),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: Text(tr('Son agenda bloque les créneaux')), subtitle: Text(tr('Réunions, congés et événements Outlook / Google rendent le praticien indisponible')), value: cfg['agenda_busy'] != false, onChanged: (v) => _put({'rdv_agenda_busy': v})),
          ],
          choice('Pas des créneaux', 'rdv_slot_step', (cfg['step'] as num?) ?? 30, const [10, 15, 20, 30, 45, 60], 'min'),
          choice('Délai minimum avant un rendez-vous', 'rdv_min_notice_hours', (cfg['notice'] as num?) ?? 2, const [0, 1, 2, 4, 12, 24, 48], 'h'),
          choice('Réservation possible jusqu\'à', 'rdv_max_days', (cfg['max_days'] as num?) ?? 60, const [7, 14, 30, 60, 90, 180], 'jours'),
          choice('Annulation en ligne jusqu\'à', 'rdv_cancel_notice_hours', (cfg['cancel_notice'] as num?) ?? 4, const [0, 1, 2, 4, 12, 24, 48], 'h avant'),
          choice('Rappel envoyé', 'rdv_reminder_hours', (cfg['reminder'] as num?) ?? 24, const [0, 2, 4, 12, 24, 48], 'h avant'),
          const SizedBox(height: 8),
          TextField(controller: _intro, maxLines: 3, decoration: InputDecoration(labelText: tr('Message d\'accueil de la page (facultatif)'), hintText: tr('Ex. Paiement Mobile Money accepté. Merci d\'arriver 5 minutes en avance.'))),
          Align(alignment: Alignment.centerRight, child: TextButton(onPressed: () => _put({'rdv_intro': _intro.text.trim().isEmpty ? null : _intro.text.trim()}), child: Text(tr('Enregistrer le message')))),
        ]),
      ),
    ]);
  }
}

// ======================================================================
// Statistiques
// ======================================================================

class _RdvStatsTab extends StatefulWidget {
  const _RdvStatsTab();

  @override
  State<_RdvStatsTab> createState() => _RdvStatsTabState();
}

class _RdvStatsTabState extends State<_RdvStatsTab> {
  Map<String, dynamic>? _d;
  Object? _error;
  late DateTimeRange _range = DateTimeRange(start: DateTime(DateTime.now().year, DateTime.now().month), end: _day(DateTime.now()).add(const Duration(days: 30)));

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await context.read<Session>().api.get('rendez_vous/stats', query: {'from': isoDate(_range.start), 'to': isoDate(_range.end)});
      if (mounted) setState(() => (_d = Map<String, dynamic>.from(d), _error = null));
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_d == null) return _error != null ? OnlineOnlyNotice(error: _error!, onRetry: _load) : const LoadingSkeleton();
    final d = _d!;
    final cur = context.read<Session>().currency;
    Widget kpi(String label, String value, IconData icon, {Color color = YColors.blue}) => KpiBox(width: 220, child: StatTile(label: label, value: value, icon: icon, color: color));
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Row(children: [
          OutlinedButton.icon(
            onPressed: () async {
              final r = await showDateRangePicker(context: context, firstDate: DateTime(2020), lastDate: DateTime(2100), initialDateRange: _range);
              if (r != null) {
                setState(() => _range = r);
                _load();
              }
            },
            icon: const Icon(Icons.date_range),
            label: Text(tr('${DateFormat('dd/MM/yyyy').format(_range.start)} – ${DateFormat('dd/MM/yyyy').format(_range.end)}')),
          ),
        ]),
        const SizedBox(height: 12),
        KpiWrap(children: [
          kpi('Rendez-vous', '${d['total']}', Icons.event_available_outlined),
          kpi('Pris en ligne', '${d['online']}', Icons.public),
          kpi('Honorés', '${d['done']}', Icons.task_alt, color: YColors.success),
          kpi('Absences', d['no_show_rate'] == null ? '${d['no_show']}' : '${d['no_show']} (${d['no_show_rate']} %)', Icons.person_off_outlined, color: YColors.danger),
          kpi('Annulés', '${d['cancelled']}', Icons.event_busy_outlined, color: YColors.muted),
          kpi('Taux d\'occupation', d['occupancy'] == null ? '—' : '${d['occupancy']} %', Icons.donut_large_outlined, color: YColors.warning),
          kpi('Chiffre honoré', money(toDouble(d['revenue']), cur), Icons.payments_outlined, color: YColors.success),
        ]),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Prestations'),
          child: Column(children: [
            for (final x in (d['services'] as List))
              ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${x['name']}')), subtitle: Text(tr('${x['count']} rendez-vous')), trailing: Text(tr(money(toDouble(x['revenue']), cur)), style: const TextStyle(fontWeight: FontWeight.w700))),
          ]),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: tr('Équipe'),
          child: Column(children: [
            for (final x in (d['staff'] as List))
              ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(tr('${x['name']}')), subtitle: Text(tr('${x['count']} rendez-vous · ${x['no_show']} absence(s)'))),
          ]),
        ),
      ]),
    );
  }
}
